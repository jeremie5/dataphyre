<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable server-owned target and protocol policy. The endpoint is never public. */
final class PanelHttpDataSourceDefinition implements \JsonSerializable {
	private readonly string $name;
	private readonly string $endpoint;
	private readonly PanelHttpDataSourceCursorCodec $cursorCodec;
	private readonly int $cursorTtl;
	private readonly int $maxRequestBytes;
	private readonly int $maxResponseBytes;
	private readonly int $timeoutMilliseconds;
	private readonly int $maxAttempts;
	/** @var list<int> */ private readonly array $retryStatuses;
	private readonly int $retryBackoffMilliseconds;
	private readonly int $circuitFailureThreshold;
	private readonly int $circuitOpenMilliseconds;
	private readonly string $fingerprint;

	/** @param array<string,mixed> $options */
	public function __construct(string $name, string $endpoint, private readonly PanelHttpDataSourceCapabilityPin $capabilityPin, array $options){
		$allowed=['cursor_keys','cursor_active_key','cursor_ttl','max_request_bytes','max_response_bytes','timeout_ms','max_attempts','retry_statuses','retry_backoff_ms','circuit_failure_threshold','circuit_open_ms'];
		$unknown=array_values(array_diff(array_keys($options), $allowed));
		if($unknown!==[]){ throw new \InvalidArgumentException('Unknown remote data-source option: '.(string)$unknown[0]); }
		$this->name=PanelHttpDataSourceValue::identifier($name, 'Remote data-source name', 64);
		$this->endpoint=self::normalizeEndpoint($endpoint);
		$keys=$options['cursor_keys'] ?? null;
		if(!is_array($keys)){ throw new \InvalidArgumentException('Remote data sources require cursor_keys.'); }
		$active=$options['cursor_active_key'] ?? null;
		if($active!==null && !is_string($active)){ throw new \InvalidArgumentException('Remote cursor_active_key must be a string.'); }
		$this->cursorCodec=new PanelHttpDataSourceCursorCodec($keys, $active);
		$this->cursorTtl=self::integer($options, 'cursor_ttl', 900, 30, 86400);
		$this->maxRequestBytes=self::integer($options, 'max_request_bytes', 262144, 4096, 1048576);
		$this->maxResponseBytes=self::integer($options, 'max_response_bytes', 1048576, 4096, 4194304);
		$this->timeoutMilliseconds=self::integer($options, 'timeout_ms', 5000, 50, 120000);
		$this->maxAttempts=self::integer($options, 'max_attempts', 1, 1, 3);
		$this->retryBackoffMilliseconds=self::integer($options, 'retry_backoff_ms', 25, 0, 2000);
		$this->circuitFailureThreshold=self::integer($options, 'circuit_failure_threshold', 5, 1, 20);
		$this->circuitOpenMilliseconds=self::integer($options, 'circuit_open_ms', 30000, 100, 300000);
		$statuses=$options['retry_statuses'] ?? [408,425,429,500,502,503,504];
		if(!is_array($statuses) || !array_is_list($statuses) || count($statuses)>7){ throw new \InvalidArgumentException('Remote retry_statuses must be a bounded list.'); }
		$retry=[];
		foreach($statuses as $status){ if(!is_int($status) || !in_array($status, [408,425,429,500,502,503,504], true)){ throw new \InvalidArgumentException('Remote retry_statuses contains an unsupported status.'); } $retry[]=$status; }
		$retry=array_values(array_unique($retry)); sort($retry, SORT_NUMERIC); $this->retryStatuses=$retry;
		$safe=['name'=>$this->name,'capability'=>$capabilityPin->fingerprint(),'record_key'=>$capabilityPin->recordKeyField(),'cursor_ttl'=>$this->cursorTtl,'request'=>$this->maxRequestBytes,'response'=>$this->maxResponseBytes,'timeout'=>$this->timeoutMilliseconds,'attempts'=>$this->maxAttempts,'statuses'=>$this->retryStatuses,'backoff'=>$this->retryBackoffMilliseconds,'circuit_failures'=>$this->circuitFailureThreshold,'circuit_open'=>$this->circuitOpenMilliseconds];
		$this->fingerprint=$this->cursorCodec->bindingFingerprint($this->endpoint."\0".PanelHttpDataSourceValue::canonical($safe));
	}

	public function name(): string { return $this->name; }
	public function endpoint(): string { return $this->endpoint; }
	public function capabilityPin(): PanelHttpDataSourceCapabilityPin { return $this->capabilityPin; }
	public function cursorCodec(): PanelHttpDataSourceCursorCodec { return $this->cursorCodec; }
	public function cursorTtl(): int { return $this->cursorTtl; }
	public function maxRequestBytes(): int { return $this->maxRequestBytes; }
	public function maxResponseBytes(): int { return $this->maxResponseBytes; }
	public function timeoutMilliseconds(): int { return $this->timeoutMilliseconds; }
	public function maxAttempts(): int { return $this->maxAttempts; }
	/** @return list<int> */ public function retryStatuses(): array { return $this->retryStatuses; }
	public function retryBackoffMilliseconds(): int { return $this->retryBackoffMilliseconds; }
	public function circuitFailureThreshold(): int { return $this->circuitFailureThreshold; }
	public function circuitOpenMilliseconds(): int { return $this->circuitOpenMilliseconds; }
	public function fingerprint(): string { return $this->fingerprint; }

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_http_data_source_definition','version'=>1,'name'=>$this->name,'definition_fingerprint'=>$this->fingerprint,
			'endpoint'=>['configured'=>true,'serialized'=>false,'request_selectable'=>false],
			'capability_pin'=>$this->capabilityPin->jsonSerialize(),'record_key_field'=>$this->capabilityPin->recordKeyField(),
			'cursor'=>$this->cursorCodec->jsonSerialize()+['ttl_seconds'=>$this->cursorTtl],
			'bounds'=>['max_request_bytes'=>$this->maxRequestBytes,'max_response_bytes'=>$this->maxResponseBytes,'timeout_ms'=>$this->timeoutMilliseconds],
			'retry'=>['reads_only'=>true,'max_attempts'=>$this->maxAttempts,'statuses'=>$this->retryStatuses,'base_backoff_ms'=>$this->retryBackoffMilliseconds,'sleep_owner'=>'injected_runtime'],
			'circuit'=>['failure_threshold'=>$this->circuitFailureThreshold,'open_ms'=>$this->circuitOpenMilliseconds],
		];
	}

	/** @param array<string,mixed> $options */
	private static function integer(array $options, string $key, int $default, int $minimum, int $maximum): int {
		$value=$options[$key] ?? $default;
		if(!is_int($value) || $value<$minimum || $value>$maximum){ throw new \InvalidArgumentException("Remote {$key} must be between {$minimum} and {$maximum}."); }
		return $value;
	}

	private static function normalizeEndpoint(string $endpoint): string {
		$endpoint=PanelHttpDataSourceValue::text($endpoint, 'Remote endpoint', 2048);
		$parts=parse_url($endpoint);
		if(!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true) || trim((string)($parts['host'] ?? ''))==='' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])){
			throw new \InvalidArgumentException('Remote endpoints must be absolute HTTP(S) URLs without credentials, query, or fragment components.');
		}
		return $endpoint;
	}
}
