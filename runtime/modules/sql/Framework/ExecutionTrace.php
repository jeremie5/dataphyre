<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Immutable normalized SQL instrumentation event.
 *
 * ExecutionTrace turns raw SQL diagnostics into stable trace data for tests, log
 * adapters, and runtime inspection. It preserves core
 * fields such as source, event, operation, cache status, queue state, result
 * status, timestamp, and cluster/DBMS metadata, while retaining extra
 * instrumentation fields inside context for forward-compatible trace enrichment.
 */
final class ExecutionTrace implements \JsonSerializable {

	private const KNOWN_KEYS=[
		'source'=>true,
		'event'=>true,
		'operation'=>true,
		'message'=>true,
		'reason'=>true,
		'location'=>true,
		'cluster'=>true,
		'dbms'=>true,
		'queue'=>true,
		'queued'=>true,
		'cache_status'=>true,
		'cache_type'=>true,
		'cache_names'=>true,
		'invalidation_names'=>true,
		'result_ok'=>true,
		'context'=>true,
		'timestamp'=>true,
	];

	/**
	 * Stores fully normalized trace data.
	 *
	 * Instances are created through fromArray() so normalization, default values,
	 * context merging, and timestamp fallback stay centralized before the trace
	 * becomes immutable.
	 *
	 * @param array<string, mixed> $payload Normalized trace fields and context data.
	 */
	private function __construct(
		private readonly array $payload
	){}

	/**
	 * Normalizes a raw SQL trace event into an immutable trace value.
	 *
	 * Known top-level keys are normalized into stable trace fields. Unknown keys are
	 * preserved inside context so instrumentation can add diagnostic details without
	 * changing this value object's public API.
	 *
	 * @param array<string, mixed> $payload Raw trace event data.
	 * @return self Normalized execution trace.
	 */
	public static function fromArray(array $payload): self {
		$extraContext=array_diff_key($payload, self::KNOWN_KEYS);
		return new self([
			'source'=>self::normalizeString($payload['source'] ?? 'kernel') ?? 'kernel',
			'event'=>self::normalizeString($payload['event'] ?? 'unknown') ?? 'unknown',
			'operation'=>self::normalizeString($payload['operation'] ?? null),
			'message'=>self::normalizeString($payload['message'] ?? null),
			'reason'=>self::normalizeString($payload['reason'] ?? null),
			'location'=>self::normalizeString($payload['location'] ?? null),
			'cluster'=>self::normalizeString($payload['cluster'] ?? null),
			'dbms'=>self::normalizeString($payload['dbms'] ?? null),
			'queue'=>self::normalizeString($payload['queue'] ?? null),
			'queued'=>(bool)($payload['queued'] ?? false),
			'cache_status'=>self::normalizeString($payload['cache_status'] ?? null),
			'cache_type'=>self::normalizeString($payload['cache_type'] ?? null),
			'cache_names'=>self::normalizeStringList($payload['cache_names'] ?? []),
			'invalidation_names'=>self::normalizeStringList($payload['invalidation_names'] ?? []),
			'result_ok'=>array_key_exists('result_ok', $payload) ? (is_bool($payload['result_ok']) ? $payload['result_ok'] : null) : null,
			'context'=>array_merge(
				is_array($payload['context'] ?? null) ? $payload['context'] : [],
				$extraContext
			),
			'timestamp'=>is_numeric($payload['timestamp'] ?? null) ? (float)$payload['timestamp'] : microtime(true),
		]);
	}

	/**
	 * Returns the subsystem that emitted the trace.
	 *
	 * Missing or blank sources normalize to kernel.
	 *
	 * @return string Trace source label.
	 */
	public function source(): string {
		return $this->payload['source'];
	}

	/**
	 * Returns the trace event type.
	 *
	 * Event values distinguish cache decisions, queued work, guardrail warnings,
	 * executed SQL, binding events, and other SQL instrumentation points.
	 *
	 * @return string Trace event name.
	 */
	public function event(): string {
		return $this->payload['event'];
	}

	/**
	 * Returns the SQL or repository operation name associated with the trace.
	 *
	 * @return string|null Operation label, or null when not supplied.
	 */
	public function operation(): ?string {
		return $this->payload['operation'];
	}

	/**
	 * Returns the human-readable trace message.
	 *
	 * Messages are optional and are intended for diagnostics rather than control flow.
	 *
	 * @return string|null Trace message text.
	 */
	public function message(): ?string {
		return $this->payload['message'];
	}

	/**
	 * Returns the machine-oriented reason associated with the trace.
	 *
	 * Reasons explain why a guardrail, cache decision, queue action, or failure path
	 * occurred when the emitter supplied that detail.
	 *
	 * @return string|null Trace reason code or description.
	 */
	public function reason(): ?string {
		return $this->payload['reason'];
	}

	/**
	 * Returns the source location reported by the trace emitter.
	 *
	 * @return string|null File, line, or logical location string.
	 */
	public function location(): ?string {
		return $this->payload['location'];
	}

	/**
	 * Returns the database cluster targeted by the traced operation.
	 *
	 * @return string|null Cluster name, or null when not applicable.
	 */
	public function cluster(): ?string {
		return $this->payload['cluster'];
	}

	/**
	 * Returns the DBMS family associated with the traced operation.
	 *
	 * @return string|null DBMS label such as mysql or postgresql.
	 */
	public function dbms(): ?string {
		return $this->payload['dbms'];
	}

	/**
	 * Returns the queue name associated with deferred SQL work.
	 *
	 * @return string|null Queue identifier, or null for immediate operations.
	 */
	public function queue(): ?string {
		return $this->payload['queue'];
	}

	/**
	 * Indicates whether the trace represents queued rather than immediate execution.
	 *
	 * @return bool True when trace data marked the operation as queued.
	 */
	public function queued(): bool {
		return $this->payload['queued'];
	}

	/**
	 * Indicates whether the traced operation executed immediately.
	 *
	 * This is the inverse of queued().
	 *
	 * @return bool True when the operation was not queued.
	 */
	public function immediate(): bool {
		return !$this->queued();
	}

	/**
	 * Returns the cache decision or cache result recorded for the trace.
	 *
	 * Example statuses may include hit, miss, bypass, write, or invalidated depending
	 * on the SQL path that emitted the trace.
	 *
	 * @return string|null Cache status label.
	 */
	public function cacheStatus(): ?string {
		return $this->payload['cache_status'];
	}

	/**
	 * Returns the cache backend or cache category associated with the trace.
	 *
	 * @return string|null Cache type label.
	 */
	public function cacheType(): ?string {
		return $this->payload['cache_type'];
	}

	/**
	 * Returns cache names read or written by the traced operation.
	 *
	 * Values are normalized to unique non-empty strings.
	 *
	 * @return array<int, string> Cache names associated with the trace.
	 */
	public function cacheNames(): array {
		return $this->payload['cache_names'];
	}

	/**
	 * Returns cache names invalidated by the traced operation.
	 *
	 * Values are normalized to unique non-empty strings.
	 *
	 * @return array<int, string> Invalidated cache names.
	 */
	public function invalidationNames(): array {
		return $this->payload['invalidation_names'];
	}

	/**
	 * Returns the optional success flag emitted with the trace.
	 *
	 * Null means the emitter did not report a boolean result or supplied a non-boolean
	 * value.
	 *
	 * @return bool|null Reported result flag, or null when unknown.
	 */
	public function resultOk(): ?bool {
		return $this->payload['result_ok'];
	}

	/**
	 * Returns additional trace context not represented by first-class accessors.
	 *
	 * Context includes the supplied context array plus unknown top-level trace keys.
	 * It commonly stores render trace IDs, binding trace IDs, query fingerprints,
	 * query identity details, and target metadata.
	 *
	 * @return array<string, mixed> Additional trace context.
	 */
	public function context(): array {
		return $this->payload['context'];
	}

	/**
	 * Reads one value from the trace context.
	 *
	 * Blank keys return the default. Existing null context values are returned as null
	 * because lookup uses array_key_exists().
	 *
	 * @param string $key Context key to read.
	 * @param mixed $default Value returned when the key is absent or blank.
	 * @return mixed trace context value for the key, or the caller default when the key is blank or absent.
	 */
	public function contextValue(string $key, mixed $default=null): mixed {
		$key=trim($key);
		if($key===''){
			return $default;
		}
		return array_key_exists($key, $this->payload['context']) ? $this->payload['context'][$key] : $default;
	}

	/**
	 * Returns the render trace identifier carried in context.
	 *
	 * @return string|null Non-empty render_trace_id context value.
	 */
	public function renderTraceId(): ?string {
		return $this->contextStringValue('render_trace_id');
	}

	/**
	 * Returns the binding trace identifier carried in context.
	 *
	 * @return string|null Non-empty binding_trace_id context value.
	 */
	public function bindingTraceId(): ?string {
		return $this->contextStringValue('binding_trace_id');
	}

	/**
	 * Returns the query fingerprint carried in context.
	 *
	 * Fingerprints identify logically equivalent queries without exposing complete SQL
	 * text in every trace consumer.
	 *
	 * @return string|null Non-empty query_fingerprint context value.
	 */
	public function queryFingerprint(): ?string {
		return $this->contextStringValue('query_fingerprint');
	}

	/**
	 * Returns the query identity mode carried in context.
	 *
	 * @return string|null Non-empty query_identity_mode context value.
	 */
	public function queryIdentityMode(): ?string {
		return $this->contextStringValue('query_identity_mode');
	}

	/**
	 * Returns the query identity source carried in context.
	 *
	 * @return string|null Non-empty query_identity_source context value.
	 */
	public function queryIdentitySource(): ?string {
		return $this->contextStringValue('query_identity_source');
	}

	/**
	 * Returns the target type described by query context.
	 *
	 * @return string|null Non-empty query_target_type context value.
	 */
	public function queryTargetType(): ?string {
		return $this->contextStringValue('query_target_type');
	}

	/**
	 * Returns the specific target described by query context.
	 *
	 * @return string|null Non-empty query_target context value.
	 */
	public function queryTarget(): ?string {
		return $this->contextStringValue('query_target');
	}

	/**
	 * Returns the query mode carried in context.
	 *
	 * @return string|null Non-empty query_mode context value.
	 */
	public function queryMode(): ?string {
		return $this->contextStringValue('query_mode');
	}

	/**
	 * Returns the trace timestamp as Unix epoch seconds with microsecond precision.
	 *
	 * Missing or non-numeric timestamps are normalized to microtime(true).
	 *
	 * @return float Trace timestamp.
	 */
	public function timestamp(): float {
		return $this->payload['timestamp'];
	}

	/**
	 * Indicates whether this trace is a guardrail warning event.
	 *
	 * @return bool True when event() equals guardrail_warning.
	 */
	public function isWarning(): bool {
		return $this->event()==='guardrail_warning';
	}

	/**
	 * Returns normalized trace fields and context.
	 *
	 * @return array<string, mixed> Normalized trace fields and context.
	 */
	public function toArray(): array {
		return $this->payload;
	}

	/**
	 * Serializes the normalized trace fields and context.
	 *
	 * @return array<string, mixed> Normalized trace fields and context.
	 */
	public function jsonSerialize(): array {
		return $this->payload;
	}

	/**
	 * Normalizes optional string trace fields.
	 *
	 * Non-string, blank, and whitespace-only values collapse to null so accessors
	 * can distinguish absent metadata from meaningful trace labels.
	 *
	 * @param mixed $value Raw trace value.
	 * @return string|null Trimmed non-empty string, or null when unusable.
	 */
	private static function normalizeString(mixed $value): ?string {
		if(is_string($value)===false){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}

	/**
	 * Reads a non-empty string context value for fixed internal context keys.
	 *
	 * @param string $key Known context key.
	 * @return string|null Trimmed string value, or null when absent or blank.
	 */
	private function contextStringValue(string $key): ?string {
		$value=$this->payload['context'][$key] ?? null;
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}

	/**
	 * Normalizes a list of trace names such as cache or invalidation identifiers.
	 *
	 * Only non-empty strings survive; duplicates are removed after trimming while
	 * preserving first-seen order for stable diagnostics.
	 *
	 * @param mixed $value Raw list value from trace data.
	 * @return array<int, string> Unique non-empty strings.
	 */
	private static function normalizeStringList(mixed $value): array {
		if(is_array($value)===false){
			return [];
		}
		$normalized=[];
		$seen=[];
		foreach($value as $entry){
			$entry=self::normalizeString($entry);
			if($entry===null || isset($seen[$entry])){
				continue;
			}
			$seen[$entry]=true;
			$normalized[]=$entry;
		}
		return $normalized;
	}
}
