<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Atomic snapshot-backed operation store with expiring leases and fencing.
 *
 * The raw bearer token is held only by the worker. Persistent snapshots retain
 * its SHA-256 digest so a copied state file cannot be used as a worker proof.
 */
final class PanelAtomicLeasedOperationStore implements PanelLeasedOperationStore,\JsonSerializable {
	private PanelAtomicSnapshotStore $state;
	private \Closure $clock;
	private \Closure $tokenFactory;

	public function __construct(string $directory,int $retain=512,?callable $clock=null,?callable $tokenFactory=null){
		$this->state=new PanelAtomicSnapshotStore($directory,'dataphyre.panel.operations.leased.v1',['records'=>[],'leases'=>[],'fences'=>[],'idempotency'=>[]],$retain);
		$this->clock=\Closure::fromCallable($clock ?? static fn():string=>gmdate(DATE_ATOM));
		$this->tokenFactory=\Closure::fromCallable($tokenFactory ?? static fn():string=>bin2hex(random_bytes(32)));
	}

	public function create(PanelOperationRecord $record):PanelOperationRecord {
		$transaction=$this->state->transaction(function(array &$payload)use($record):PanelOperationRecord{
			$this->normalize($payload); $id=$record->id(); $key=$record->idempotencyKey();
			if($key!==null && isset($payload['idempotency'][$key])){ $existing=$this->record($payload,(string)$payload['idempotency'][$key]); if($existing!==null){ return $existing; } unset($payload['idempotency'][$key]); }
			if(isset($payload['records'][$id])){ throw new PanelOperationConflict("Panel operation '{$id}' already exists."); }
			if($key!==null){ $this->assertIdempotencyAvailable($payload,$key,$id); }
			$stored=$record->withRevision(1); $payload['records'][$id]=$stored->jsonSerialize(); if($key!==null){ $payload['idempotency'][$key]=$id; }
			return $stored;
		},'operation.created',['operation_id'=>$record->id()]);
		return $transaction['result'];
	}

	public function currentTime():string { return $this->now(); }

	public function get(string $id):?PanelOperationRecord { $payload=$this->payload(); return $this->record($payload,$id); }

	public function save(PanelOperationRecord $record,?int $expectedRevision=null):PanelOperationRecord {
		$transaction=$this->state->transaction(function(array &$payload)use($record,$expectedRevision):PanelOperationRecord{
			$this->normalize($payload); $current=$this->requiredRecord($payload,$record->id());
			$expected=$expectedRevision ?? $record->revision(); $this->assertRevision($current,$expected);
			return $this->persist($payload,$record,$current);
		},'operation.saved',['operation_id'=>$record->id()]);
		return $transaction['result'];
	}

	public function update(string $id,callable $mutator,?int $expectedRevision=null):PanelOperationRecord {
		$transaction=$this->state->transaction(function(array &$payload)use($id,$mutator,$expectedRevision):PanelOperationRecord{
			$this->normalize($payload); $current=$this->requiredRecord($payload,$id); if($expectedRevision!==null){ $this->assertRevision($current,$expectedRevision); }
			$next=$mutator($current); $this->assertMutation($current,$next); return $this->persist($payload,$next,$current);
		},'operation.updated',['operation_id'=>$id]);
		return $transaction['result'];
	}

	public function findByIdempotencyKey(string $key):?PanelOperationRecord {
		$key=trim($key); if($key===''){ return null; } $payload=$this->payload(); $id=$payload['idempotency'][$key]??null;
		return is_string($id) ? $this->record($payload,$id) : null;
	}

	public function all(array $criteria=[],int $limit=100,int $offset=0):array {
		$limit=max(1,min(10000,$limit)); $offset=max(0,$offset); $payload=$this->payload(); $records=[];
		foreach(array_keys($payload['records']) as $id){ $record=$this->record($payload,(string)$id); if($record!==null && $this->matches($record,$criteria)){ $records[]=$record; } }
		usort($records,static fn(PanelOperationRecord $left,PanelOperationRecord $right):int=>[$left->createdAt(),$left->id()]<=>[$right->createdAt(),$right->id()]);
		return array_slice($records,$offset,$limit);
	}

	public function delete(string $id):bool {
		if($this->get($id)===null){ return false; }
		$transaction=$this->state->transaction(function(array &$payload)use($id):bool{
			$this->normalize($payload); $current=$this->record($payload,$id); if($current===null){ return false; }
			if(isset($payload['leases'][$id])){ throw new PanelOperationConflict("Panel operation '{$id}' has an active worker lease."); }
			unset($payload['records'][$id]); if($current->idempotencyKey()!==null && ($payload['idempotency'][$current->idempotencyKey()]??null)===$id){ unset($payload['idempotency'][$current->idempotencyKey()]); }
			return true;
		},'operation.deleted',['operation_id'=>$id]);
		return $transaction['result'];
	}

	public function acquireLease(string $id,string $worker='worker',int $ttlSeconds=60):?PanelOperationReservation {
		$worker=$this->worker($worker); $ttl=$this->ttl($ttlSeconds); $now=$this->now(); $token=($this->tokenFactory)();
		if(!is_string($token)){ throw new \UnexpectedValueException('Panel operation lease token factory must return a string.'); }
		if(strlen($token)<32 || strlen($token)>512 || str_contains($token,"\0")){ throw new \UnexpectedValueException('Panel operation lease token factory returned an unsafe bearer proof.'); }
		$expires=$this->plusSeconds($now,$ttl);
		$transaction=$this->state->transaction(function(array &$payload)use($id,$worker,$token,$now,$expires):?PanelOperationReservation{
			$this->normalize($payload); $current=$this->record($payload,$id); if($current===null || isset($payload['leases'][$id])){ return null; }
			if(!in_array($current->status(),[PanelOperationStatus::QUEUED,PanelOperationStatus::RETRY_WAIT],true) || strcmp($current->availableAt(),$now)>0){ return null; }
			if(!$current->canRetry() && $current->attempt()>0){ return null; }
			$fence=max(0,(int)($payload['fences'][$id]??0))+1; $lease=PanelOperationLease::make($id,$worker,$token,$fence,$now,$expires);
			$started=$this->persist($payload,$current->start($worker,$now),$current); $payload['fences'][$id]=$fence; $payload['leases'][$id]=$this->leaseState($lease);
			return new PanelOperationReservation($started,$lease);
		},'operation.lease_acquired',['operation_id'=>$id,'worker'=>$worker]);
		return $transaction['result'];
	}

	public function reserveLease(?string $queue=null,string $worker='worker',int $ttlSeconds=60):?PanelOperationReservation {
		$this->recoverExpiredLeases(1000); $criteria=['status'=>[PanelOperationStatus::QUEUED,PanelOperationStatus::RETRY_WAIT]];
		if($queue!==null && trim($queue)!==''){ $criteria['queue']=$this->queue($queue); }
		$now=$this->now(); foreach($this->all($criteria,10000) as $candidate){ if(strcmp($candidate->availableAt(),$now)>0){ continue; } $reservation=$this->acquireLease($candidate->id(),$worker,$ttlSeconds); if($reservation!==null){ return $reservation; } }
		return null;
	}

	public function inspectLease(PanelOperationLease $lease):PanelOperationReservation {
		$payload=$this->payload(); [$record,$state]=$this->validateLease($payload,$lease,$this->now()); return new PanelOperationReservation($record,$this->leaseFromState($lease,$state));
	}

	public function mutateLease(PanelOperationLease $lease,callable $mutator,?int $renewSeconds=null):PanelOperationReservation {
		$now=$this->now(); $ttl=$renewSeconds===null ? null : $this->ttl($renewSeconds);
		$transaction=$this->state->transaction(function(array &$payload)use($lease,$mutator,$now,$ttl):PanelOperationReservation{
			$this->normalize($payload); [$current,$state]=$this->validateLease($payload,$lease,$now); $next=$mutator($current); $this->assertMutation($current,$next);
			if(!PanelOperationStatus::active($next->status()) || $next->worker()!==$lease->worker()){ throw new \LogicException('Leased mutations must retain the active worker; use finishLease for lifecycle completion.'); }
			$stored=$this->persist($payload,$next,$current);
			if($ttl!==null){ $state['renewed_at']=$now; $state['expires_at']=$this->plusSeconds($now,$ttl); $payload['leases'][$lease->operationId()]=$state; }
			return new PanelOperationReservation($stored,$this->leaseFromState($lease,$state));
		},'operation.lease_mutated',['operation_id'=>$lease->operationId(),'worker'=>$lease->worker(),'fence'=>$lease->fence()]);
		return $transaction['result'];
	}

	public function renewLease(PanelOperationLease $lease,int $ttlSeconds=60):PanelOperationReservation {
		$now=$this->now(); return $this->mutateLease($lease,static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->heartbeat($now),$ttlSeconds);
	}

	public function finishLease(PanelOperationLease $lease,callable $mutator):PanelOperationRecord {
		$now=$this->now(); $transaction=$this->state->transaction(function(array &$payload)use($lease,$mutator,$now):PanelOperationRecord{
			$this->normalize($payload); [$current]=$this->validateLease($payload,$lease,$now); $next=$mutator($current); $this->assertMutation($current,$next);
			if(PanelOperationStatus::active($next->status()) || $next->worker()!==null){ throw new \LogicException('Finishing a worker lease must leave a non-active record without a worker.'); }
			$stored=$this->persist($payload,$next,$current); unset($payload['leases'][$lease->operationId()]); return $stored;
		},'operation.lease_finished',['operation_id'=>$lease->operationId(),'worker'=>$lease->worker(),'fence'=>$lease->fence()]);
		return $transaction['result'];
	}

	public function releaseLease(PanelOperationLease $lease,?int $delaySeconds=null):PanelOperationRecord {
		$now=$this->now(); return $this->finishLease($lease,static function(PanelOperationRecord $current)use($delaySeconds,$now):PanelOperationRecord{
			if($current->status()===PanelOperationStatus::CANCEL_REQUESTED){ return $current->cancel($now); }
			if($current->status()===PanelOperationStatus::PAUSE_REQUESTED){ return $current->markPaused($now); }
			return $current->canRetry() ? $current->retry($delaySeconds,$now) : $current->fail('Worker released its final attempt.',$now);
		});
	}

	public function recoverExpiredLeases(int $limit=100):array {
		$limit=max(1,min(10000,$limit)); $now=$this->now(); $payload=$this->payload(); $expired=[];
		foreach($payload['leases'] as $id=>$state){ if(is_array($state) && isset($state['expires_at']) && strcmp((string)$state['expires_at'],$now)<=0){ $expired[]=(string)$id; } }
		sort($expired,SORT_STRING); $recovered=[];
		foreach(array_slice($expired,0,$limit) as $id){
			$transaction=$this->state->transaction(function(array &$payload)use($id,$now):?PanelOperationRecord{
				$this->normalize($payload); $state=$payload['leases'][$id]??null; if(!is_array($state) || strcmp((string)($state['expires_at']??''),$now)>0){ return null; }
				$current=$this->record($payload,$id); unset($payload['leases'][$id]); if($current===null || !PanelOperationStatus::active($current->status())){ return null; }
				if($current->status()===PanelOperationStatus::CANCEL_REQUESTED){ $next=$current->cancel($now); }
				elseif($current->status()===PanelOperationStatus::PAUSE_REQUESTED){ $next=$current->markPaused($now); }
				elseif($current->canRetry()){ $next=$current->retry(0,$now); }
				else{ $next=$current->fail('Worker lease expired and no retry attempts remain.',$now); }
				return $this->persist($payload,$next,$current);
			},'operation.lease_recovered',['operation_id'=>$id]);
			if($transaction['result'] instanceof PanelOperationRecord){ $recovered[]=$transaction['result']; }
		}
		return $recovered;
	}

	public function activeLeaseManifests():array {
		$payload=$this->payload(); $now=$this->now(); $out=[]; foreach($payload['leases'] as $id=>$state){ if(!is_array($state) || strcmp((string)($state['expires_at']??''),$now)<=0){ continue; } $record=$this->record($payload,(string)$id); if($record===null || !PanelOperationStatus::active($record->status())){ continue; }
			$out[]=['operation_id'=>(string)$id,'worker'=>(string)($state['worker']??''),'fence'=>(int)($state['fence']??0),'acquired_at'=>(string)($state['acquired_at']??''),'renewed_at'=>(string)($state['renewed_at']??''),'expires_at'=>(string)($state['expires_at']??''),'record_revision'=>$record->revision()]; }
		usort($out,static fn(array $left,array $right):int=>[$left['operation_id'],$left['fence']]<=>[$right['operation_id'],$right['fence']]); return $out;
	}

	/** @return array<string,mixed> */ public function changesSince(int $cursor=0,int $limit=100):array { return $this->state->changesSince($cursor,$limit); }
	/** @return array<string,mixed> */ public function manifest():array { $payload=$this->payload(); return ['type'=>'panel_atomic_leased_operation_store','schema_version'=>1,'records'=>count($payload['records']),'active_leases'=>count($this->activeLeaseManifests()),'cursor'=>$this->state->cursor(),'capabilities'=>['atomic_snapshot'=>true,'cross_process_lock'=>true,'leases'=>true,'lease_renewal'=>true,'expiry_recovery'=>true,'fencing'=>true,'token_digest_at_rest'=>true,'change_feed'=>true]]; }
	/** @return array<string,mixed> */ public function jsonSerialize():array { return $this->manifest(); }

	/** @return array<string,mixed> */ private function payload():array { $payload=$this->state->payload(); $this->normalize($payload); return $payload; }
	/** @param array<string,mixed> $payload */ private function normalize(array &$payload):void { foreach(['records','leases','fences','idempotency'] as $key){ if(!isset($payload[$key])){ $payload[$key]=[]; } if(!is_array($payload[$key])){ throw new \RuntimeException("Panel leased operation state has an invalid {$key} map."); } } }
	/** @param array<string,mixed> $payload */ private function record(array $payload,string $id):?PanelOperationRecord { $data=$payload['records'][$id]??null; return is_array($data) ? PanelOperationRecord::fromArray($data) : null; }
	/** @param array<string,mixed> $payload */ private function requiredRecord(array $payload,string $id):PanelOperationRecord { return $this->record($payload,$id) ?? throw new \OutOfBoundsException("Panel operation '{$id}' does not exist."); }
	private function assertRevision(PanelOperationRecord $record,int $expected):void { if($record->revision()!==$expected){ throw new PanelOperationConflict("Panel operation '{$record->id()}' revision conflict: expected {$expected}, found {$record->revision()}."); } }
	private function assertMutation(PanelOperationRecord $current,mixed $next):void { if(!$next instanceof PanelOperationRecord){ throw new \UnexpectedValueException('Panel operation mutator must return PanelOperationRecord.'); } if($next->id()!==$current->id()){ throw new \LogicException('Panel operation mutator cannot change the record id.'); } }

	/** @param array<string,mixed> $payload */
	private function persist(array &$payload,PanelOperationRecord $next,PanelOperationRecord $current):PanelOperationRecord {
		$oldKey=$current->idempotencyKey(); $newKey=$next->idempotencyKey(); if($newKey!==null){ $this->assertIdempotencyAvailable($payload,$newKey,$current->id()); }
		if($oldKey!==null && $oldKey!==$newKey && ($payload['idempotency'][$oldKey]??null)===$current->id()){ unset($payload['idempotency'][$oldKey]); }
		$stored=$next->withRevision($current->revision()+1); $payload['records'][$current->id()]=$stored->jsonSerialize(); if($newKey!==null){ $payload['idempotency'][$newKey]=$current->id(); } return $stored;
	}

	/** @param array<string,mixed> $payload */ private function assertIdempotencyAvailable(array $payload,string $key,string $id):void { $owner=$payload['idempotency'][$key]??null; if(is_string($owner) && $owner!==$id && isset($payload['records'][$owner])){ throw new PanelOperationConflict("Panel operation idempotency key already belongs to {$owner}."); } }
	/** @param array<string,mixed> $criteria */ private function matches(PanelOperationRecord $record,array $criteria):bool { foreach($criteria as $key=>$expected){ $actual=match($key){'id'=>$record->id(),'type'=>$record->type(),'queue'=>$record->queue(),'status'=>$record->status(),'idempotency_key'=>$record->idempotencyKey(),'worker'=>$record->worker(),default=>throw new \InvalidArgumentException("Unsupported operation criterion '{$key}'.")}; if(is_array($expected)){ if(!in_array($actual,$expected,true)){ return false; } }elseif($actual!==$expected){ return false; } } return true; }

	/** @return array{0:PanelOperationRecord,1:array<string,mixed>} @param array<string,mixed> $payload */
	private function validateLease(array $payload,PanelOperationLease $lease,string $now):array {
		$state=$payload['leases'][$lease->operationId()]??null; if(!is_array($state)){ throw new PanelOperationLeaseLost($lease->operationId()); }
		$matches=(string)($state['worker']??'')===$lease->worker() && (int)($state['fence']??0)===$lease->fence() && isset($state['token_hash']) && hash_equals((string)$state['token_hash'],hash('sha256',$lease->token()));
		if(!$matches){ throw new PanelOperationLeaseLost($lease->operationId(),'Operation lease was superseded by another worker.'); }
		if(strcmp((string)($state['expires_at']??''),$now)<=0){ throw new PanelOperationLeaseLost($lease->operationId(),'Operation lease expired.'); }
		$record=$this->requiredRecord($payload,$lease->operationId()); if(!PanelOperationStatus::active($record->status()) || $record->worker()!==$lease->worker()){ throw new PanelOperationLeaseLost($lease->operationId(),'Operation record no longer belongs to this worker.'); }
		return [$record,$state];
	}

	/** @return array<string,mixed> */ private function leaseState(PanelOperationLease $lease):array { return ['worker'=>$lease->worker(),'token_hash'=>hash('sha256',$lease->token()),'fence'=>$lease->fence(),'acquired_at'=>$lease->acquiredAt(),'renewed_at'=>$lease->renewedAt(),'expires_at'=>$lease->expiresAt()]; }
	/** @param array<string,mixed> $state */ private function leaseFromState(PanelOperationLease $lease,array $state):PanelOperationLease { return PanelOperationLease::make($lease->operationId(),$lease->worker(),$lease->token(),$lease->fence(),(string)$state['acquired_at'],(string)$state['expires_at'],(string)$state['renewed_at']); }
	private function now():string { $value=($this->clock)(); try{ if($value instanceof \DateTimeInterface){ $date=\DateTimeImmutable::createFromInterface($value); } elseif(is_int($value)){ $date=new \DateTimeImmutable('@'.$value); } else{ $date=new \DateTimeImmutable((string)$value); } return $date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM); }catch(\Throwable){ throw new \UnexpectedValueException('Panel operation lease clock returned an invalid time.'); } }
	private function plusSeconds(string $time,int $seconds):string { return (new \DateTimeImmutable($time))->modify('+'.$seconds.' seconds')->format(DATE_ATOM); }
	private function ttl(int $seconds):int { return max(5,min(3600,$seconds)); }
	private function worker(string $worker):string { $worker=trim($worker); if($worker==='' || strlen($worker)>190 || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D',$worker)!==1){ throw new \InvalidArgumentException('Panel operation worker id must be a safe identifier.'); } return $worker; }
	private function queue(string $queue):string { $queue=strtolower(trim($queue)); $queue=preg_replace('/[^a-z0-9]+/','_',$queue)??''; return trim($queue,'_')?:'default'; }
}
