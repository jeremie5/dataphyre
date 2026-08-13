<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded same-scope mutation batch with explicit atomicity semantics. */
final class PanelDataMutationBatch implements \JsonSerializable {
	/** @var list<PanelDataMutation> */ private readonly array $mutations;
	private readonly string $fingerprint;
	/** @param list<PanelDataMutation> $mutations */
	public function __construct(array $mutations,private readonly bool $atomic=true){
		if($mutations===[]||count($mutations)>100||!array_is_list($mutations)){throw new \InvalidArgumentException('Panel data-mutation batches require a list of 1-100 mutations.');}
		$digests=[];$tenant=null;$actor=null;
		foreach($mutations as$index=>$mutation){if(!$mutation instanceof PanelDataMutation){throw new \InvalidArgumentException('Panel data-mutation batches may contain only mutation envelopes.');}if(isset($digests[$mutation->idempotencyDigest()])){throw new \InvalidArgumentException('Panel data-mutation batches cannot reuse an idempotency key.');}$digests[$mutation->idempotencyDigest()]=true;if($index===0){$tenant=$mutation->tenantKey();$actor=$mutation->actorId();}elseif($tenant!==$mutation->tenantKey()||$actor!==$mutation->actorId()){throw new \InvalidArgumentException('Panel data-mutation batches must share one tenant and actor scope.');}}
		$this->mutations=$mutations;$this->fingerprint=hash('sha256',PanelQueryValue::stableJson(['atomic'=>$atomic,'mutations'=>array_map(static fn(PanelDataMutation $mutation):string=>$mutation->fingerprint(),$mutations)]));
	}
	/** @return list<PanelDataMutation> */public function mutations():array{return$this->mutations;}
	public function atomic():bool{return$this->atomic;}
	public function count():int{return count($this->mutations);}
	public function tenantKey():string|int|null{return$this->mutations[0]->tenantKey();}
	public function actorId():string|int{return$this->mutations[0]->actorId();}
	public function fingerprint():string{return$this->fingerprint;}
	public function jsonSerialize():array{return['type'=>'panel_data_mutation_batch_manifest','version'=>1,'count'=>$this->count(),'atomic'=>$this->atomic,'tenant_hash'=>$this->tenantKey()===null?null:hash('sha256',(string)$this->tenantKey()),'actor_hash'=>hash('sha256',(string)$this->actorId()),'mutation_fingerprints'=>array_map(static fn(PanelDataMutation $mutation):string=>$mutation->fingerprint(),$this->mutations),'fingerprint'=>$this->fingerprint];}
}
