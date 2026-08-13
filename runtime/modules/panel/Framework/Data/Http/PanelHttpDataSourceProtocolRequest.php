<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Exact versioned wire request. It never serializes Panel authorization or metadata wholesale. */
final class PanelHttpDataSourceProtocolRequest implements \JsonSerializable {
	/** @param array<string,mixed> $query */
	public function __construct(
		private readonly string $operation,
		private readonly string $requestId,
		private readonly string $source,
		private readonly string $definitionFingerprint,
		private readonly PanelHttpDataSourceCapabilityPin $capabilityPin,
		private readonly string $queryFingerprint,
		private readonly string $readIdempotencyKey,
		private readonly array $query,
		private readonly ?string $upstreamCursor,
		private readonly string|int|null $recordKey,
		private readonly PanelHttpDataSourceScope $scope,
		private readonly int $deadlineUnixMilliseconds,
		private readonly int $timeoutMilliseconds,
		private readonly int $attempt,
		private readonly int $maxAttempts
	){
		if(!in_array($operation, ['query','find'], true)){ throw new \InvalidArgumentException('Remote protocol operation is invalid.'); }
		if(strlen($requestId)<8 || strlen($requestId)>96 || preg_match('/^[A-Za-z0-9_-]+$/D', $requestId)!==1){ throw new \InvalidArgumentException('Remote protocol request id is invalid.'); }
		PanelHttpDataSourceValue::identifier($source, 'Remote protocol source', 64);
		self::fingerprint($definitionFingerprint); self::fingerprint($queryFingerprint);
		if(preg_match('/^[a-f0-9]{64}$/D', $readIdempotencyKey)!==1){ throw new \InvalidArgumentException('Remote read idempotency key is invalid.'); }
		PanelHttpDataSourceValue::exactKeys($query, ['type','version','filters','expression','sorts','sort_nodes','search','select','include','offset','limit','aggregates'], 'Remote protocol query');
		if($upstreamCursor!==null){ PanelHttpDataSourceValue::text($upstreamCursor, 'Upstream cursor', 2048); }
		if($operation==='query' && $recordKey!==null){ throw new \InvalidArgumentException('Remote query requests cannot carry a record key.'); }
		if($operation==='find'){ self::validateRecordKey($recordKey); }
		if($deadlineUnixMilliseconds<0 || $timeoutMilliseconds<1 || $attempt<1 || $attempt>$maxAttempts || $maxAttempts>3){ throw new \InvalidArgumentException('Remote protocol execution metadata is invalid.'); }
	}

	/** @return array<string,mixed> */
	public static function sanitizedQuery(PanelDataQuery $query): array {
		return [
			'type'=>'panel_data_query','version'=>2,'filters'=>$query->filterList(),
			'expression'=>$query->expression()?->jsonSerialize(),'sorts'=>$query->sortList(),
			'sort_nodes'=>array_map(static fn(PanelQuerySort $sort): array=>$sort->jsonSerialize(), $query->sortNodes()),
			'search'=>$query->searchTerm()===null ? null : ['term'=>$query->searchTerm(),'fields'=>$query->searchFields()],
			'select'=>$query->selectedFields(),'include'=>$query->includes(),'offset'=>$query->offsetValue(),
			'limit'=>$query->limitValue(),'aggregates'=>$query->aggregateList(),
		];
	}

	public function encode(int $maxBytes): string {
		$json=PanelHttpDataSourceValue::encode($this->jsonSerialize());
		if(strlen($json)>$maxBytes){ throw new \LengthException('Remote protocol request exceeds the configured body limit.'); }
		return $json;
	}

	public function operation(): string { return $this->operation; }
	public function requestId(): string { return $this->requestId; }
	public function source(): string { return $this->source; }
	public function definitionFingerprint(): string { return $this->definitionFingerprint; }
	public function capabilityPin(): PanelHttpDataSourceCapabilityPin { return $this->capabilityPin; }
	public function queryFingerprint(): string { return $this->queryFingerprint; }
	/** @return array<string,mixed> */ public function queryPayload(): array { return $this->query; }
	public function recordKey(): string|int|null { return $this->recordKey; }

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_http_data_request','version'=>1,'operation'=>$this->operation,'request_id'=>$this->requestId,
			'source'=>$this->source,'definition_fingerprint'=>$this->definitionFingerprint,
			'capability'=>['version'=>$this->capabilityPin->version(),'fingerprint'=>$this->capabilityPin->fingerprint()],
			'query_fingerprint'=>$this->queryFingerprint,'read_idempotency_key'=>$this->readIdempotencyKey,
			'query'=>$this->query,'cursor'=>$this->upstreamCursor,'record_key'=>$this->recordKey,'scope'=>$this->scope->jsonSerialize(),
			'execution'=>['deadline_unix_ms'=>$this->deadlineUnixMilliseconds,'timeout_ms'=>$this->timeoutMilliseconds,'attempt'=>$this->attempt,'max_attempts'=>$this->maxAttempts,'cancellation_supported'=>true],
		];
	}

	private static function fingerprint(string $value): void { if(preg_match('/^[a-f0-9]{64}$/D', $value)!==1){ throw new \InvalidArgumentException('Remote protocol fingerprint is invalid.'); } }
	private static function validateRecordKey(string|int|null $value): void {
		if(is_int($value)){ return; }
		if(!is_string($value)){ throw new \InvalidArgumentException('Remote find requests require a scalar record key.'); }
		PanelHttpDataSourceValue::text($value, 'Remote record key', 512);
	}
}
