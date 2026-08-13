<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Signed exact mutation wire request; its public serialization deliberately redacts values and scope. */
final class PanelHttpDataMutationProtocolRequest implements \JsonSerializable {
	/** @var list<PanelDataMutation> */private readonly array $mutations;
	/** @var array<string,mixed> */private readonly array $wire;
	private readonly string $operation;private readonly string $requestFingerprint;private readonly bool $atomic;

	public function __construct(
		private readonly string $requestId,
		private readonly PanelHttpDataMutationDefinition $definition,
		PanelDataMutation|PanelDataMutationBatch $request,
		private readonly PanelHttpDataSourceScope $scope,
		private readonly int $deadlineUnixMilliseconds,
		private readonly int $timeoutMilliseconds,
		private readonly int $attempt,
		private readonly int $maxAttempts
	){
		if(strlen($requestId)<8||strlen($requestId)>96||preg_match('/^[A-Za-z0-9_-]+$/D',$requestId)!==1){throw new \InvalidArgumentException('Remote mutation protocol request id is invalid.');}
		if($deadlineUnixMilliseconds<0||$timeoutMilliseconds<1||$attempt<1||$attempt>$maxAttempts||$maxAttempts>3){throw new \InvalidArgumentException('Remote mutation execution metadata is invalid.');}
		$definition->capabilityPin()->assertSupports($request);$this->operation=$request instanceof PanelDataMutation?'mutate':'mutate_batch';$this->mutations=$request instanceof PanelDataMutation?[$request]:$request->mutations();$this->atomic=$request instanceof PanelDataMutation?true:$request->atomic();$this->requestFingerprint=$request->fingerprint();
		$payloads=array_map(self::mutationPayload(...),$this->mutations);
		$base=['type'=>'panel_http_data_mutation_request','version'=>1,'operation'=>$this->operation,'request_id'=>$requestId,'source'=>$definition->name(),'definition_fingerprint'=>$definition->fingerprint(),'capability'=>['version'=>$definition->capabilityPin()->version(),'fingerprint'=>$definition->capabilityPin()->fingerprint()],'request_fingerprint'=>$this->requestFingerprint,'scope'=>$scope->jsonSerialize(),'request'=>['atomic'=>$this->atomic,'count'=>count($payloads),'mutations'=>$payloads],'execution'=>['deadline_unix_ms'=>$deadlineUnixMilliseconds,'timeout_ms'=>$timeoutMilliseconds,'attempt'=>$attempt,'max_attempts'=>$maxAttempts,'cancellation_supported'=>true]];
		$this->wire=$definition->authenticator()->seal($base);
	}
	public function encode(int $maxBytes):string{$json=PanelHttpDataSourceValue::encode($this->wire);if(strlen($json)>$maxBytes){throw new \LengthException('Remote mutation request exceeds the configured body limit.');}return$json;}
	public function requestId():string{return$this->requestId;}public function operation():string{return$this->operation;}public function source():string{return$this->definition->name();}public function definitionFingerprint():string{return$this->definition->fingerprint();}public function capabilityPin():PanelHttpDataMutationCapabilityPin{return$this->definition->capabilityPin();}public function requestFingerprint():string{return$this->requestFingerprint;}public function atomic():bool{return$this->atomic;}public function count():int{return count($this->mutations);}/** @return list<PanelDataMutation> */public function mutations():array{return$this->mutations;}
	/** Trusted transport-only envelope; do not log. @return array<string,mixed> */public function wireEnvelope():array{return$this->wire;}
	/** @return array<string,mixed> */public function jsonSerialize():array{return['type'=>'panel_http_data_mutation_request_manifest','version'=>1,'operation'=>$this->operation,'request_id_hash'=>hash('sha256',$this->requestId),'source'=>$this->source(),'definition_fingerprint'=>$this->definitionFingerprint(),'capability_fingerprint'=>$this->capabilityPin()->fingerprint(),'request_fingerprint'=>$this->requestFingerprint,'atomic'=>$this->atomic,'count'=>$this->count(),'scope_fingerprint'=>$this->scope->fingerprint(),'values_serialized'=>false,'scope_serialized'=>false,'raw_idempotency_serialized'=>false,'signed'=>true];}
	/** @return array<string,mixed> */private static function mutationPayload(PanelDataMutation $mutation):array{return['type'=>'panel_data_mutation_transport','version'=>1,'operation'=>$mutation->operation(),'key'=>$mutation->key(),'values'=>$mutation->values(),'idempotency_digest'=>$mutation->idempotencyDigest(),'mutation_fingerprint'=>$mutation->fingerprint(),'expected_revision'=>$mutation->expectedRevision(),'reason'=>$mutation->reason(),'return_record'=>$mutation->returnsRecord(),'metadata'=>$mutation->metadata()];}
}
