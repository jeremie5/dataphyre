<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Read-capable remote source with a separately pinned, signed, idempotent mutation channel. */
final class PanelHttpMutableDataSource implements PanelMutableDataSource,\JsonSerializable {
	private readonly PanelHttpDataSourceRuntime $runtime;
	private int $requests=0;private int $successes=0;private int $failures=0;private int $attempts=0;private int $retries=0;private int $consecutiveCircuitFailures=0;private int $circuitOpenUntil=0;private ?string $lastErrorCode=null;private float $lastLatencyMilliseconds=0.0;

	public function __construct(
		private readonly PanelHttpDataSource $readSource,
		private readonly PanelHttpDataSourceTransport $mutationTransport,
		private readonly PanelHttpDataMutationDefinition $mutationDefinition,
		private readonly PanelHttpDataMutationScopeMapper $scopeMapper,
		?PanelHttpDataSourceRuntime $runtime=null
	){
		if($readSource->definition()->name()!==$mutationDefinition->name()){throw new \InvalidArgumentException('Remote read and mutation definitions must name the same source.');}
		if($readSource->definition()->capabilityPin()->recordKeyField()!==$mutationDefinition->capabilityPin()->recordKeyField()){throw new \InvalidArgumentException('Remote read and mutation definitions must pin the same record key.');}
		$this->runtime=$runtime??new PanelSystemHttpDataSourceRuntime();
	}

	public function query(PanelDataQuery $query):PanelDataResult{return$this->readSource->query($query);}
	public function find(string|int $id,?PanelDataQuery $scope=null):mixed{return$this->readSource->find($id,$scope);}
	/** @return array<string,mixed> */public function capabilities():array{return array_replace($this->readSource->capabilities(),$this->mutationDefinition->capabilityPin()->capabilities(),['adapter'=>'http_remote_mutable','remote_mutation_protocol'=>1,'mutation_request_signatures'=>true,'mutation_response_signatures'=>true,'mutation_retries_idempotent_only'=>true,'mutation_circuit_breaker'=>true]);}

	public function mutate(PanelDataMutation $mutation):PanelDataMutationReceipt{
		PanelDataMutationCapabilities::fromArray($this->capabilities())->assertSupports($mutation);$result=$this->execute($mutation);if(!$result instanceof PanelDataMutationReceipt){throw PanelHttpDataMutationException::protocolInvalid();}return$result;
	}
	public function mutateBatch(PanelDataMutationBatch $batch):PanelDataMutationBatchResult{
		PanelDataMutationCapabilities::fromArray($this->capabilities())->assertSupports($batch);$result=$this->execute($batch);if(!$result instanceof PanelDataMutationBatchResult){throw PanelHttpDataMutationException::protocolInvalid();}return$result;
	}

	/** @return array<string,mixed> */
	public function mutationHealth():array{
		try{$now=$this->runtime->nowMilliseconds();$available=$now>=0;}catch(\Throwable){$now=0;$available=false;}
		$status=!$available?'unavailable':($this->circuitOpenUntil>$now?'open':(($this->circuitOpenUntil>0&&$this->consecutiveCircuitFailures>0)?'half_open':'closed'));
		return['type'=>'panel_http_data_mutation_health','version'=>1,'source'=>$this->mutationDefinition->name(),'status'=>$status,'requests'=>$this->requests,'successes'=>$this->successes,'failures'=>$this->failures,'attempts'=>$this->attempts,'retries'=>$this->retries,'consecutive_circuit_failures'=>$this->consecutiveCircuitFailures,'open_until_unix_ms'=>$status==='open'?$this->circuitOpenUntil:null,'last_error_code'=>$available?$this->lastErrorCode:'mutation_remote_runtime_unavailable','last_latency_ms'=>$this->lastLatencyMilliseconds,'capability_version'=>$this->mutationDefinition->capabilityPin()->version(),'capability_fingerprint'=>$this->mutationDefinition->capabilityPin()->fingerprint(),'endpoint_serialized'=>false,'headers_serialized'=>false,'payloads_serialized'=>false];
	}
	/** @return array<string,mixed> */public function manifest():array{return['type'=>'panel_http_mutable_data_source','version'=>1,'name'=>$this->mutationDefinition->name(),'read_source'=>$this->readSource->manifest(),'mutation_definition'=>$this->mutationDefinition->jsonSerialize(),'capabilities'=>$this->capabilities(),'mutation_health'=>$this->mutationHealth(),'scope'=>['mapper_required'=>true,'fail_closed'=>true,'principal_and_tenant_binding'=>true,'batch_scope_must_match'=>true,'raw_mutation_authorization_serialized'=>false,'callbacks_serialized'=>false],'protocol'=>['method'=>'POST','request_content_type'=>'application/json; charset=utf-8','response_content_type'=>'application/json','exact_request_shape'=>true,'exact_response_shape'=>true,'signed_requests'=>true,'signed_responses'=>true,'raw_idempotency_transmitted'=>false,'request_supplied_url'=>false,'request_supplied_headers'=>false],'delivery'=>['operations'=>['create','update','upsert','delete'],'upstream_persistent_idempotency'=>true,'optimistic_concurrency'=>true,'atomic_batch'=>$this->mutationDefinition->capabilityPin()->capabilities()['mutation_atomic_batch'],'retry_scope'=>'explicitly_idempotent_mutations','distributed_transaction'=>false],'security'=>['endpoint_serialized'=>false,'headers_serialized'=>false,'payloads_serialized'=>false,'signing_secrets_serialized'=>false,'transport_class_serialized'=>false]];}
	/** @return array<string,mixed> */public function jsonSerialize():array{return$this->manifest();}
	public function readSource():PanelHttpDataSource{return$this->readSource;}public function mutationDefinition():PanelHttpDataMutationDefinition{return$this->mutationDefinition;}public function mutationTransport():PanelHttpDataSourceTransport{return$this->mutationTransport;}

	private function execute(PanelDataMutation|PanelDataMutationBatch $request):PanelDataMutationReceipt|PanelDataMutationBatchResult{
		$this->requests++;
		try{$this->assertCircuit();$scope=$this->scope($request);$result=$this->executeRemote($request,$scope);$this->successes++;$this->consecutiveCircuitFailures=0;$this->circuitOpenUntil=0;$this->lastErrorCode=null;return$result;}
		catch(PanelDataMutationException $error){$this->failures++;$this->lastErrorCode=$error->publicCode();if($error instanceof PanelHttpDataMutationException&&$error->countsTowardCircuit()){$this->consecutiveCircuitFailures++;if($this->consecutiveCircuitFailures>=$this->mutationDefinition->circuitFailureThreshold()){$this->circuitOpenUntil=$this->safeNow()+$this->mutationDefinition->circuitOpenMilliseconds();}}throw$error;}
	}

	private function executeRemote(PanelDataMutation|PanelDataMutationBatch $request,PanelHttpDataSourceScope $scope):PanelDataMutationReceipt|PanelDataMutationBatchResult{
		try{$requestId=$this->runtime->requestId();$started=$this->runtime->nowMilliseconds();}catch(\Throwable $error){throw PanelHttpDataMutationException::runtimeUnavailable($error);}if($started<0){throw PanelHttpDataMutationException::runtimeUnavailable();}$deadline=$started+$this->mutationDefinition->timeoutMilliseconds();
		for($attempt=1;$attempt<=$this->mutationDefinition->maxAttempts();$attempt++){
			if($this->cancelled()){throw PanelHttpDataMutationException::cancelled();}if($this->safeNow()>=$deadline){throw PanelHttpDataMutationException::deadline();}
			$protocol=new PanelHttpDataMutationProtocolRequest($requestId,$this->mutationDefinition,$request,$scope,$deadline,$this->mutationDefinition->timeoutMilliseconds(),$attempt,$this->mutationDefinition->maxAttempts());
			try{$body=$protocol->encode($this->mutationDefinition->maxRequestBytes());}catch(\LengthException){throw PanelHttpDataMutationException::requestTooLarge();}
			$transportRequest=new PanelHttpDataSourceTransportRequest($this->mutationDefinition->endpoint(),$body,$deadline,$this->mutationDefinition->timeoutMilliseconds(),$attempt,$this->runtime);$this->attempts++;$response=null;
			try{$response=$this->mutationTransport->send($transportRequest);$this->lastLatencyMilliseconds=$response->elapsedMilliseconds();if($this->safeNow()>=$deadline){throw PanelHttpDataMutationException::deadline();}$result=PanelHttpDataMutationProtocolResponse::decode($response,$protocol,$this->mutationDefinition);return$result;}
			catch(PanelDataMutationException $error){$failure=$error;}
			catch(\Throwable $error){$failure=PanelHttpDataMutationException::transportUnavailable($error);}
			$retryStatus=$response?->status();$retryable=$failure->retryable()&&($response===null||($retryStatus!==null&&in_array($retryStatus,$this->mutationDefinition->retryStatuses(),true)));
			if(!$retryable||$attempt===$this->mutationDefinition->maxAttempts()){throw$failure;}
			$this->retries++;$delay=$this->mutationDefinition->retryBackoffMilliseconds()*(2**($attempt-1));if($response?->retryAfterMilliseconds()!==null){$delay=max($delay,(int)$response->retryAfterMilliseconds());}
			try{$waited=$this->runtime->waitMilliseconds($delay,$deadline);}catch(\Throwable $error){throw PanelHttpDataMutationException::runtimeUnavailable($error);}if(!$waited){throw$this->cancelled()?PanelHttpDataMutationException::cancelled():PanelHttpDataMutationException::deadline();}
		}
	}

	private function scope(PanelDataMutation|PanelDataMutationBatch $request):PanelHttpDataSourceScope{
		$mutations=$request instanceof PanelDataMutation?[$request]:$request->mutations();$scope=null;
		foreach($mutations as$mutation){try{$mapped=$this->scopeMapper->map($mutation,$this->mutationDefinition);}catch(\Throwable $error){throw new PanelDataMutationAccessDenied('mutation_remote_scope_denied','The remote mutation scope is not authorized.',$error);}
			if((string)$mapped->principal()!==(string)$mutation->actorId()||(string)($mapped->tenant()??'')!==(string)($mutation->tenantKey()??'')){throw new PanelDataMutationAccessDenied('mutation_remote_scope_mismatch','The remote mutation scope does not match its actor and tenant.');}
			if($mapped->tenant()!==null&&!$this->mutationDefinition->capabilityPin()->capabilities()['mutation_tenant']){throw new PanelDataMutationUnsupported(['tenant']);}
			if($scope!==null&&!hash_equals($scope->fingerprint(),$mapped->fingerprint())){throw new PanelDataMutationAccessDenied('mutation_remote_batch_scope_mismatch','Every remote batch mutation must resolve to the same approved scope.');}$scope=$mapped;
		}
		return$scope??throw new \LogicException('Remote mutation request has no mutations.');
	}
	private function assertCircuit():void{if($this->circuitOpenUntil>$this->safeNow()){throw PanelHttpDataMutationException::circuitOpen();}}
	private function safeNow():int{try{$now=$this->runtime->nowMilliseconds();}catch(\Throwable $error){throw PanelHttpDataMutationException::runtimeUnavailable($error);}if($now<0){throw PanelHttpDataMutationException::runtimeUnavailable();}return$now;}
	private function cancelled():bool{try{return$this->runtime->cancellationRequested();}catch(\Throwable $error){throw PanelHttpDataMutationException::runtimeUnavailable($error);}}
}
