<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

/**
 * In-memory trace buffer and aggregate counters for permission checks.
 *
 * Tracing is opt-in through runtime configuration or explicit enablement. Events
 * are sanitized before storage, bounded by `trace.max_entries`, and summarized
 * into counters for authorization checks, cache behavior, total duration, and the
 * slowest recorded event.
 */
final class PermissionTrace {

	private static ?bool $enabled=null;
	private static array $entries=[];
	private static array $stats=[
		'events'=>0,
		'checks'=>0,
		'allowed'=>0,
		'denied'=>0,
		'cache_hits'=>0,
		'cache_misses'=>0,
		'total_ms'=>0.0,
		'slowest'=>null,
	];

	/**
	 * Enables or disables permission tracing for the current PHP process.
	 *
	 * @param bool $enabled Whether new trace events should be recorded.
	 * @return void
	 */
	public static function enable(bool $enabled=true): void {
		self::$enabled=$enabled;
	}

	/**
	 * Disables permission tracing for the current PHP process.
	 *
	 * @return void
	 */
	public static function disable(): void {
		self::$enabled=false;
	}

	/**
	 * Returns whether permission tracing is currently active.
	 *
	 * The first call reads `DP_PERMISSION_CFG['trace']['enabled']` unless the
	 * process has already been explicitly enabled or disabled.
	 *
	 * @return bool `true` when calls to `record()` will store entries.
	 */
	public static function enabled(): bool {
		if(self::$enabled!==null){
			return self::$enabled;
		}
		$config=defined('\DP_PERMISSION_CFG') && is_array(\DP_PERMISSION_CFG) ? \DP_PERMISSION_CFG : [];
		$trace=is_array($config['trace'] ?? null) ? $config['trace'] : [];
		return self::$enabled=($trace['enabled'] ?? false)===true;
	}

	/**
	 * Clears trace entries and resets aggregate counters.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$entries=[];
		self::$stats=[
			'events'=>0,
			'checks'=>0,
			'allowed'=>0,
			'denied'=>0,
			'cache_hits'=>0,
			'cache_misses'=>0,
			'total_ms'=>0.0,
			'slowest'=>null,
		];
	}

	/**
	 * Records one permission trace event when tracing is enabled.
	 *
	 * Event fields are sanitized to scalars, arrays, object class/id pairs, or
	 * debug types. Context data is omitted unless `trace.include_context` is
	 * enabled. The oldest entries are discarded when the configured bound is
	 * exceeded.
	 *
	 * @param string $event Event name such as `check.all`, `decisions`, or `explain`.
	 * @param array<string, mixed> $payload Event fields.
	 * @return void
	 */
	public static function record(string $event, array $payload=[]): void {
		if(!self::enabled()){
			return;
		}
		$entry=self::entry($event, $payload);
		self::$entries[]=$entry;
		$max=self::maxEntries();
		if(count(self::$entries)>$max){
			array_splice(self::$entries, 0, count(self::$entries)-$max);
		}
		self::aggregate($entry);
	}

	/**
	 * Returns the bounded trace entry buffer.
	 *
	 * @return array<int, array<string, mixed>> Sanitized trace entries in recording order.
	 */
	public static function entries(): array {
		return self::$entries;
	}

	/**
	 * Returns aggregate counters for recorded trace entries.
	 *
	 * @return array{events:int,checks:int,allowed:int,denied:int,cache_hits:int,cache_misses:int,total_ms:float,slowest:?array}
	 */
	public static function stats(): array {
		$stats=self::$stats;
		$stats['total_ms']=round((float)$stats['total_ms'], 3);
		return $stats;
	}

	/**
	 * Returns the full trace summary used by diagnostics and audit views.
	 *
	 * @return array{enabled:bool,entry_count:int,stats:array,entries:array}
	 */
	public static function summary(): array {
		return [
			'enabled'=>self::enabled(),
			'entry_count'=>count(self::$entries),
			'stats'=>self::stats(),
			'entries'=>self::entries(),
		];
	}

	/**
	 * Builds one sanitized trace entry.
	 *
	 * @param string $event Event name.
	 * @param array<string, mixed> $payload Raw event fields.
	 * @return array<string, mixed> Sanitized trace entry with timestamp and event name.
	 */
	private static function entry(string $event, array $payload): array {
		$duration=isset($payload['duration_ms']) ? round((float)$payload['duration_ms'], 3) : null;
		$entry=[
			'time'=>microtime(true),
			'event'=>PermissionRule::normalize($event) ?: $event,
		];
		foreach($payload as $key=>$value){
			if($key==='context' && !self::includeContext()){
				continue;
			}
			$entry[(string)$key]=self::safe($value);
		}
		if($duration!==null){
			$entry['duration_ms']=$duration;
		}
		return $entry;
	}

	/**
	 * Updates aggregate counters from one sanitized trace entry.
	 *
	 * @param array<string, mixed> $entry Sanitized trace entry.
	 * @return void
	 */
	private static function aggregate(array $entry): void {
		self::$stats['events']++;
		$event=(string)($entry['event'] ?? '');
		if(in_array($event, ['check.all', 'check.any', 'decisions', 'explain'], true)){
			self::$stats['checks']++;
			if(($entry['allowed'] ?? null)===true){
				self::$stats['allowed']++;
			}
			elseif(($entry['allowed'] ?? null)===false){
				self::$stats['denied']++;
			}
		}
		if(($entry['cache_hit'] ?? null)===true){
			self::$stats['cache_hits']++;
		}
		elseif(($entry['cache_hit'] ?? null)===false){
			self::$stats['cache_misses']++;
		}
		$duration=(float)($entry['duration_ms'] ?? 0.0);
		self::$stats['total_ms']+=$duration;
		if($duration>0.0 && (self::$stats['slowest']===null || $duration>(float)(self::$stats['slowest']['duration_ms'] ?? 0.0))){
			self::$stats['slowest']=$entry;
		}
	}

	/**
	 * Converts arbitrary event values into trace-safe data.
	 *
	 * @param mixed $value Raw event value.
	 * @return mixed Scalar, array, object summary, or debug type string.
	 */
	private static function safe(mixed $value): mixed {
		if(is_scalar($value) || $value===null){
			return $value;
		}
		if(is_array($value)){
			$result=[];
			foreach($value as $key=>$item){
				$result[(string)$key]=self::safe($item);
			}
			return $result;
		}
		if(is_object($value)){
			return [
				'class'=>$value::class,
				'id'=>SubjectResolver::id($value),
			];
		}
		return get_debug_type($value);
	}

	/**
	 * Returns the configured maximum number of trace entries to keep.
	 *
	 * @return int Entry bound, never lower than 16.
	 */
	private static function maxEntries(): int {
		$config=defined('\DP_PERMISSION_CFG') && is_array(\DP_PERMISSION_CFG) ? \DP_PERMISSION_CFG : [];
		$trace=is_array($config['trace'] ?? null) ? $config['trace'] : [];
		return max(16, (int)($trace['max_entries'] ?? 256));
	}

	/**
	 * Returns whether trace entries may include caller context.
	 *
	 * @return bool `true` when `trace.include_context` is enabled.
	 */
	private static function includeContext(): bool {
		$config=defined('\DP_PERMISSION_CFG') && is_array(\DP_PERMISSION_CFG) ? \DP_PERMISSION_CFG : [];
		$trace=is_array($config['trace'] ?? null) ? $config['trace'] : [];
		return ($trace['include_context'] ?? false)===true;
	}
}
