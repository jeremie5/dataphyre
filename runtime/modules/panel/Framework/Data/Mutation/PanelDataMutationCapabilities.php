<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Validated write-side capability negotiation for universal data adapters. */
final class PanelDataMutationCapabilities implements \JsonSerializable {
	/** @param list<string> $operations */
	private function __construct(
		private readonly string $adapter,
		private readonly bool $enabled,
		private readonly array $operations,
		private readonly bool $batch,
		private readonly bool $atomicBatch,
		private readonly int $maxBatch,
		private readonly bool $optimisticConcurrency,
		private readonly bool $idempotency,
		private readonly string $idempotencyScope,
		private readonly bool $tenant,
		private readonly bool $authorization,
		private readonly bool $returning,
		private readonly bool $changeFeed
	){}

	/** @param array<string,mixed> $capabilities */
	public static function fromArray(array $capabilities):self{
		$adapter=self::text($capabilities['adapter']??'unknown','adapter');$enabled=($capabilities['mutations']??false)===true;
		$operations=$capabilities['mutation_operations']??[];if(!is_array($operations)||!array_is_list($operations)){throw new \UnexpectedValueException('Panel mutation_operations must be a string list.');}
		$clean=[];foreach($operations as$operation){if(!is_string($operation)||!in_array($operation,PanelDataMutation::OPERATIONS,true)){throw new \UnexpectedValueException('Panel mutation operation capability is invalid.');}$clean[$operation]=true;}$operations=array_keys($clean);
		$batch=self::flag($capabilities,'mutation_batch');$atomic=self::flag($capabilities,'mutation_atomic_batch');if($atomic&&!$batch){throw new \UnexpectedValueException('Panel atomic mutation batches require batch support.');}
		$max=$capabilities['mutation_max_batch']??($batch?100:1);if(!is_int($max)||$max<1||$max>10000||(!$batch&&$max!==1)){throw new \UnexpectedValueException('Panel mutation batch limit is invalid.');}
		$optimistic=self::flag($capabilities,'mutation_optimistic_concurrency');$idempotency=self::flag($capabilities,'mutation_idempotency');
		$scope=self::text($capabilities['mutation_idempotency_scope']??'none','idempotency scope');if($idempotency&&$scope==='none'){throw new \UnexpectedValueException('Panel idempotent mutation adapters must declare their idempotency scope.');}
		if($enabled&&($operations===[]||!$idempotency)){throw new \UnexpectedValueException('Writable Panel adapters require operations and idempotency.');}
		return new self($adapter,$enabled,$operations,$batch,$atomic,$max,$optimistic,$idempotency,$scope,self::flag($capabilities,'mutation_tenant'),self::flag($capabilities,'mutation_authorization'),self::flag($capabilities,'mutation_returning'),self::flag($capabilities,'change_feed'));
	}

	public function assertSupports(PanelDataMutation|PanelDataMutationBatch $request):void{
		$missing=[];if(!$this->enabled){$missing[]='mutations';}
		$mutations=$request instanceof PanelDataMutationBatch?$request->mutations():[$request];
		if($request instanceof PanelDataMutationBatch){if(!$this->batch){$missing[]='mutation_batch';}if($request->atomic()&&!$this->atomicBatch){$missing[]='mutation_atomic_batch';}if($request->count()>$this->maxBatch){$missing[]='mutation_max_batch';}}
		foreach($mutations as$mutation){if(!in_array($mutation->operation(),$this->operations,true)){$missing[]='operation:'.$mutation->operation();}if($mutation->tenantKey()!==null&&!$this->tenant){$missing[]='tenant';}if($mutation->authorizationMetadata()!==[]&&!$this->authorization){$missing[]='authorization';}if($mutation->returnsRecord()&&!$this->returning){$missing[]='mutation_returning';}if($mutation->expectedRevision()!==null&&!$this->optimisticConcurrency){$missing[]='mutation_optimistic_concurrency';}}
		$missing=array_values(array_unique($missing));if($missing!==[]){throw new PanelDataMutationUnsupported($missing);}
	}

	public function enabled():bool{return$this->enabled;}/** @return list<string> */public function operations():array{return$this->operations;}public function batch():bool{return$this->batch;}public function atomicBatch():bool{return$this->atomicBatch;}public function maxBatch():int{return$this->maxBatch;}
	public function jsonSerialize():array{return['type'=>'panel_data_mutation_capabilities','version'=>1,'adapter'=>$this->adapter,'mutations'=>$this->enabled,'operations'=>$this->operations,'batch'=>$this->batch,'atomic_batch'=>$this->atomicBatch,'max_batch'=>$this->maxBatch,'optimistic_concurrency'=>$this->optimisticConcurrency,'idempotency'=>$this->idempotency,'idempotency_scope'=>$this->idempotencyScope,'tenant'=>$this->tenant,'authorization'=>$this->authorization,'returning'=>$this->returning,'change_feed'=>$this->changeFeed];}
	private static function flag(array $capabilities,string $key):bool{$value=$capabilities[$key]??false;if(!is_bool($value)){throw new \UnexpectedValueException("Panel {$key} capability must be boolean.");}return$value;}
	private static function text(mixed $value,string $label):string{if(!is_string($value)){throw new \UnexpectedValueException("Panel mutation {$label} must be a string.");}$value=strtolower(trim($value));if($value===''||strlen($value)>128||preg_match('/^[a-z0-9_.-]+$/D',$value)!==1){throw new \UnexpectedValueException("Panel mutation {$label} is invalid.");}return$value;}
}
