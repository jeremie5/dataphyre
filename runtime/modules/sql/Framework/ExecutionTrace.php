<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

final class ExecutionTrace implements \JsonSerializable {

	private function __construct(
		private readonly array $payload
	){}

	public static function fromArray(array $payload): self {
		$known_keys=[
			'source',
			'event',
			'operation',
			'message',
			'reason',
			'location',
			'cluster',
			'dbms',
			'queue',
			'queued',
			'cache_status',
			'cache_type',
			'cache_names',
			'invalidation_names',
			'result_ok',
			'context',
			'timestamp',
		];
		$extra_context=array_diff_key($payload, array_flip($known_keys));
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
				$extra_context
			),
			'timestamp'=>is_numeric($payload['timestamp'] ?? null) ? (float)$payload['timestamp'] : microtime(true),
		]);
	}

	public function source(): string {
		return $this->payload['source'];
	}

	public function event(): string {
		return $this->payload['event'];
	}

	public function operation(): ?string {
		return $this->payload['operation'];
	}

	public function message(): ?string {
		return $this->payload['message'];
	}

	public function reason(): ?string {
		return $this->payload['reason'];
	}

	public function location(): ?string {
		return $this->payload['location'];
	}

	public function cluster(): ?string {
		return $this->payload['cluster'];
	}

	public function dbms(): ?string {
		return $this->payload['dbms'];
	}

	public function queue(): ?string {
		return $this->payload['queue'];
	}

	public function queued(): bool {
		return $this->payload['queued'];
	}

	public function immediate(): bool {
		return !$this->queued();
	}

	public function cacheStatus(): ?string {
		return $this->payload['cache_status'];
	}

	public function cacheType(): ?string {
		return $this->payload['cache_type'];
	}

	public function cacheNames(): array {
		return $this->payload['cache_names'];
	}

	public function invalidationNames(): array {
		return $this->payload['invalidation_names'];
	}

	public function resultOk(): ?bool {
		return $this->payload['result_ok'];
	}

	public function context(): array {
		return $this->payload['context'];
	}

	public function contextValue(string $key, mixed $default=null): mixed {
		$key=trim($key);
		if($key===''){
			return $default;
		}
		return array_key_exists($key, $this->payload['context']) ? $this->payload['context'][$key] : $default;
	}

	public function renderTraceId(): ?string {
		$value=$this->contextValue('render_trace_id');
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	public function bindingTraceId(): ?string {
		$value=$this->contextValue('binding_trace_id');
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	public function queryFingerprint(): ?string {
		$value=$this->contextValue('query_fingerprint');
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	public function queryIdentityMode(): ?string {
		$value=$this->contextValue('query_identity_mode');
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	public function queryIdentitySource(): ?string {
		$value=$this->contextValue('query_identity_source');
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	public function queryTargetType(): ?string {
		$value=$this->contextValue('query_target_type');
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	public function queryTarget(): ?string {
		$value=$this->contextValue('query_target');
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	public function queryMode(): ?string {
		$value=$this->contextValue('query_mode');
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	public function timestamp(): float {
		return $this->payload['timestamp'];
	}

	public function isWarning(): bool {
		return $this->event()==='guardrail_warning';
	}

	public function toArray(): array {
		return $this->payload;
	}

	public function jsonSerialize(): array {
		return $this->payload;
	}

	private static function normalizeString(mixed $value): ?string {
		if(is_string($value)===false){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}

	private static function normalizeStringList(mixed $value): array {
		if(is_array($value)===false){
			return [];
		}
		$normalized=[];
		foreach($value as $entry){
			$entry=self::normalizeString($entry);
			if($entry===null){
				continue;
			}
			$normalized[]=$entry;
		}
		return array_values(array_unique($normalized));
	}
}
