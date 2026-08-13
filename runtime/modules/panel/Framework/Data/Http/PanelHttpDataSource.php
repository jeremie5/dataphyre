<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Production read-only PanelDataSource over a closed, exact POST/JSON protocol. */
final class PanelHttpDataSource implements PanelDataSource, \JsonSerializable {
	private int $requests=0;
	private int $successes=0;
	private int $failures=0;
	private int $attempts=0;
	private int $retries=0;
	private int $consecutiveCircuitFailures=0;
	private int $circuitOpenUntil=0;
	private ?string $lastErrorCode=null;
	private float $lastLatencyMilliseconds=0.0;

	public function __construct(
		private readonly PanelHttpDataSourceTransport $transport,
		private readonly PanelHttpDataSourceDefinition $definition,
		private readonly PanelHttpDataSourceScopeMapper $scopeMapper,
		?PanelHttpDataSourceRuntime $runtime=null
	){ $this->runtime=$runtime ?? new PanelSystemHttpDataSourceRuntime(); }

	private readonly PanelHttpDataSourceRuntime $runtime;

	public function query(PanelDataQuery $query): PanelDataResult {
		$this->definition->capabilityPin()->assertSupports($query);
		return $this->execute('query', $query, null);
	}

	public function find(string|int $id, ?PanelDataQuery $scope=null): mixed {
		if(!$this->definition->capabilityPin()->supportsFind()){ throw new PanelUnsupportedQueryException(['find'], $this->capabilities()); }
		self::recordKey($id);
		$query=($scope ?? PanelDataQuery::make())->cursor(null)->offset(0)->limit(1);
		$this->definition->capabilityPin()->assertSupports($query);
		return $this->execute('find', $query, $id)->items()[0] ?? null;
	}

	/** @return array<string,mixed> */
	public function capabilities(): array {
		return array_replace($this->definition->capabilityPin()->capabilities(), [
			'adapter'=>'http_remote','remote_protocol'=>1,
			'capability_version'=>$this->definition->capabilityPin()->version(),
			'capability_fingerprint'=>$this->definition->capabilityPin()->fingerprint(),
			'cursor_opaque'=>true,'cursor_authenticated'=>true,'cursor_scope_bound'=>true,'cursor_query_bound'=>true,
			'post_json_only'=>true,'read_idempotency'=>true,'retries_read_only'=>true,'cancellable_transport'=>true,
			'circuit_breaker'=>true,'health_reporting'=>true,'snapshot_consistent'=>false,
		]);
	}

	/** @return array<string,mixed> */
	public function health(): array {
		try{ $now=$this->runtime->nowMilliseconds(); $runtimeAvailable=$now>=0; }
		catch(\Throwable){ $now=0; $runtimeAvailable=false; }
		$status=!$runtimeAvailable ? 'unavailable' : ($this->circuitOpenUntil>$now ? 'open' : (($this->circuitOpenUntil>0 && $this->consecutiveCircuitFailures>0) ? 'half_open' : 'closed'));
		return [
			'type'=>'panel_http_data_source_health','version'=>1,'source'=>$this->definition->name(),'status'=>$status,
			'requests'=>$this->requests,'successes'=>$this->successes,'failures'=>$this->failures,'attempts'=>$this->attempts,'retries'=>$this->retries,
			'consecutive_circuit_failures'=>$this->consecutiveCircuitFailures,'open_until_unix_ms'=>$status==='open' ? $this->circuitOpenUntil : null,
			'last_error_code'=>$runtimeAvailable ? $this->lastErrorCode : 'remote_runtime_unavailable','last_latency_ms'=>$this->lastLatencyMilliseconds,
			'capability_version'=>$this->definition->capabilityPin()->version(),'capability_fingerprint'=>$this->definition->capabilityPin()->fingerprint(),
			'endpoint_serialized'=>false,'headers_serialized'=>false,'payloads_serialized'=>false,
		];
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		return [
			'type'=>'panel_http_data_source','version'=>1,'name'=>$this->definition->name(),'definition'=>$this->definition->jsonSerialize(),
			'capabilities'=>$this->capabilities(),'health'=>$this->health(),
			'transport'=>['injected'=>true,'credential_policy_owner'=>'transport','network_policy_owner'=>'transport','class_serialized'=>false,'endpoint_serialized'=>false,'headers_serialized'=>false,'payloads_serialized'=>false],
			'scope'=>['mapper_required'=>true,'fail_closed'=>true,'explicit_projection_only'=>true,'raw_query_authorization_serialized'=>false,'mapper_class_serialized'=>false,'callbacks_serialized'=>false],
			'protocol'=>['method'=>'POST','request_content_type'=>'application/json; charset=utf-8','response_content_type'=>'application/json','exact_request_shape'=>true,'exact_response_shape'=>true,'request_supplied_url'=>false,'request_supplied_headers'=>false,'request_supplied_class'=>false],
			'delivery'=>['operations'=>['query','find'],'mutations'=>false,'read_idempotency_key'=>true,'retry_scope'=>'reads_only','exactly_once'=>false,'backoff_owner'=>'injected_runtime'],
			'limitations'=>['snapshot_consistent'=>false,'distributed_transaction'=>false,'streaming'=>false,'subscriptions'=>false,'mutations'=>false,'capability_discovery'=>false,'capability_pin_required'=>true],
		];
	}

	/** @return array<string,mixed> */ public function jsonSerialize(): array { return $this->manifest(); }
	public function definition(): PanelHttpDataSourceDefinition { return $this->definition; }
	public function transport(): PanelHttpDataSourceTransport { return $this->transport; }

	private function execute(string $operation, PanelDataQuery $query, string|int|null $recordKey): PanelDataResult {
		$this->requests++;
		try{
			$this->assertCircuit();
			$result=$this->executeRemote($operation, $query, $recordKey);
			$this->successes++; $this->consecutiveCircuitFailures=0; $this->circuitOpenUntil=0; $this->lastErrorCode=null;
			return $result;
		}
		catch(PanelHttpDataSourceException $error){
			$this->failures++; $this->lastErrorCode=$error->publicCode();
			if($error->countsTowardCircuit()){
				$this->consecutiveCircuitFailures++;
				if($this->consecutiveCircuitFailures >= $this->definition->circuitFailureThreshold()){
					$this->circuitOpenUntil=$this->safeNow()+$this->definition->circuitOpenMilliseconds();
				}
			}
			throw $error;
		}
	}

	private function executeRemote(string $operation, PanelDataQuery $query, string|int|null $recordKey): PanelDataResult {
		try{ $scope=$this->scopeMapper->map($query, $this->definition); }
		catch(\Throwable){ throw PanelHttpDataSourceException::accessDenied(); }
		$queryTenant=$query->tenantKey();
		if($queryTenant!==null && ($scope->tenant()===null || (string)$scope->tenant()!==(string)$queryTenant)){ throw PanelHttpDataSourceException::accessDenied(); }
		if($scope->tenant()!==null && $this->definition->capabilityPin()->capabilities()['tenant']!==true){ throw new PanelUnsupportedQueryException(['tenant'], $this->capabilities()); }

		$wireQuery=PanelHttpDataSourceProtocolRequest::sanitizedQuery($query);
		$fingerprintQuery=$wireQuery; unset($fingerprintQuery['offset'], $fingerprintQuery['limit']);
		$queryFingerprint=hash('sha256', PanelHttpDataSourceValue::canonical([
			'definition'=>$this->definition->fingerprint(),'capability'=>$this->definition->capabilityPin()->fingerprint(),
			'operation'=>$operation,'query'=>$fingerprintQuery,'scope'=>$scope->fingerprint(),'record_key'=>$recordKey,
		]));
		$upstreamCursor=null;
		if($query->cursorToken()!==null){
			try{ $upstreamCursor=$this->definition->cursorCodec()->decode($query->cursorToken(), $queryFingerprint, $this->definition->fingerprint(), $this->safeNow()); }
			catch(\Throwable){ throw PanelHttpDataSourceException::cursorInvalid(); }
		}
		try{ $requestId=$this->runtime->requestId(); $started=$this->runtime->nowMilliseconds(); }
		catch(\Throwable){ throw PanelHttpDataSourceException::runtimeUnavailable(); }
		if($started<0){ throw PanelHttpDataSourceException::runtimeUnavailable(); }
		$deadline=$started+$this->definition->timeoutMilliseconds();
		$idempotency=hash('sha256', $requestId."\0".$operation."\0".$queryFingerprint);
		$attemptsUsed=0; $decoded=null;
		for($attempt=1;$attempt<=$this->definition->maxAttempts();$attempt++){
			if($this->runtimeCancelled()){ throw PanelHttpDataSourceException::cancelled(); }
			if($this->safeNow()>=$deadline){ throw PanelHttpDataSourceException::deadline(); }
			$protocol=new PanelHttpDataSourceProtocolRequest($operation,$requestId,$this->definition->name(),$this->definition->fingerprint(),$this->definition->capabilityPin(),$queryFingerprint,$idempotency,$wireQuery,$upstreamCursor,$recordKey,$scope,$deadline,$this->definition->timeoutMilliseconds(),$attempt,$this->definition->maxAttempts());
			try{ $body=$protocol->encode($this->definition->maxRequestBytes()); }
			catch(\LengthException){ throw PanelHttpDataSourceException::requestTooLarge(); }
			$transportRequest=new PanelHttpDataSourceTransportRequest($this->definition->endpoint(),$body,$deadline,$this->definition->timeoutMilliseconds(),$attempt,$this->runtime);
			$this->attempts++; $attemptsUsed++;
			try{ $response=$this->transport->send($transportRequest); }
			catch(PanelHttpDataSourceException $error){ $failure=$error->publicCode()==='remote_request_cancelled' ? $error : PanelHttpDataSourceException::transportUnavailable(); $response=null; }
			catch(\Throwable){ $failure=PanelHttpDataSourceException::transportUnavailable(); $response=null; }
			if($response instanceof PanelHttpDataSourceTransportResponse){
				$this->lastLatencyMilliseconds=$response->elapsedMilliseconds();
				if($this->safeNow()>=$deadline){ throw PanelHttpDataSourceException::deadline(); }
				if(!in_array($response->status(), $this->definition->retryStatuses(), true) || $attempt===$this->definition->maxAttempts()){
					$decoded=PanelHttpDataSourceProtocolResponse::decode($response,$protocol,$this->definition->capabilityPin()->recordKeyField(),$this->definition->maxResponseBytes());
					break;
				}
				$failure=PanelHttpDataSourceException::upstream($response->status());
			}
			if($attempt===$this->definition->maxAttempts()){ throw $failure; }
			$this->retries++;
			$delay=$this->definition->retryBackoffMilliseconds()*(2**($attempt-1));
			if($response instanceof PanelHttpDataSourceTransportResponse && $response->retryAfterMilliseconds()!==null){ $delay=max($delay,$response->retryAfterMilliseconds()); }
			try{ $waited=$this->runtime->waitMilliseconds($delay,$deadline); }
			catch(\Throwable){ throw PanelHttpDataSourceException::runtimeUnavailable(); }
			if(!$waited){ throw $this->runtimeCancelled() ? PanelHttpDataSourceException::cancelled() : PanelHttpDataSourceException::deadline(); }
		}
		if(!$decoded instanceof PanelHttpDataSourceProtocolResponse){ throw PanelHttpDataSourceException::protocolInvalid(); }
		$now=$this->safeNow();
		$next=$decoded->nextCursor()===null ? null : $this->definition->cursorCodec()->encode($decoded->nextCursor(),$queryFingerprint,$this->definition->fingerprint(),$now,$this->definition->cursorTtl());
		$previous=$decoded->previousCursor()===null ? null : $this->definition->cursorCodec()->encode($decoded->previousCursor(),$queryFingerprint,$this->definition->fingerprint(),$now,$this->definition->cursorTtl());
		$page=new PanelDataPage($decoded->offset(),$decoded->limit(),count($decoded->items()),$decoded->total(),$next,$previous);
		$publicQuery=PanelDataQuery::fromArray($query->urlState());
		return new PanelDataResult($decoded->items(),$page,$this->definition->name(),$decoded->aggregates(),$decoded->included(),[
			'adapter'=>'http_remote','protocol_version'=>1,'definition_fingerprint'=>$this->definition->fingerprint(),
			'capability_version'=>$this->definition->capabilityPin()->version(),'capability_fingerprint'=>$this->definition->capabilityPin()->fingerprint(),
			'query_fingerprint'=>$queryFingerprint,'stable_record_key'=>$this->definition->capabilityPin()->recordKeyField(),
			'projection'=>$decoded->projection(),'cursor_opaque'=>true,'cursor_authenticated'=>true,'cursor_scope_bound'=>true,
			'upstream_cursor_serialized'=>false,'scope_serialized'=>false,'raw_authorization_serialized'=>false,
			'deadline_unix_ms'=>$deadline,'attempts'=>$attemptsUsed,'retries'=>max(0,$attemptsUsed-1),'read_idempotency'=>true,
			'snapshot_consistent'=>false,'endpoint_serialized'=>false,'headers_serialized'=>false,'payloads_serialized'=>false,
		],$publicQuery);
	}

	private function assertCircuit(): void {
		$now=$this->safeNow();
		if($this->circuitOpenUntil>$now){ throw PanelHttpDataSourceException::circuitOpen(); }
	}

	private function safeNow(): int {
		try{ $now=$this->runtime->nowMilliseconds(); }
		catch(\Throwable){ throw PanelHttpDataSourceException::runtimeUnavailable(); }
		if($now<0){ throw PanelHttpDataSourceException::runtimeUnavailable(); }
		return $now;
	}

	private function runtimeCancelled(): bool {
		try{ return $this->runtime->cancellationRequested(); }
		catch(\Throwable){ throw PanelHttpDataSourceException::runtimeUnavailable(); }
	}

	private static function recordKey(string|int $id): void {
		if(is_string($id)){ PanelHttpDataSourceValue::text($id, 'Remote record key', 512); }
	}
}
