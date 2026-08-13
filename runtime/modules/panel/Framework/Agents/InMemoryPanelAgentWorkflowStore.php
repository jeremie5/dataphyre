<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Process-local reference store with exact checkpoints; production hosts should persist. */
final class InMemoryPanelAgentWorkflowStore implements PanelAgentWorkflowStore, PanelCheckpointableService, \JsonSerializable {
	private int $revision=0;
	private ?\Closure $clock;
	/** @var list<PanelAgentAuditReceipt> */ private array $audit=[];
	/** @var array<string,true> */ private array $cancelled=[];
	/** @var array<string,true> */ private array $nonces=[];
	/** @var array<string,array{id:string,plan_hash:string,scope:string,key_hash:string,request_hash:string,nonces:list<string>,lease_revision:int,lease_expires_at:int,status:string,result:?PanelAgentExecutionResult}> */ private array $reservations=[];
	/** @var array<string,string> */ private array $idempotency=[];

	public function __construct(?callable $clock=null, private readonly int $leaseSeconds=120, private readonly int $maxEntries=4096) {
		if($leaseSeconds<30 || $leaseSeconds>3600){ throw new \InvalidArgumentException('Panel agent execution lease must be between 30 and 3600 seconds.'); }
		if($maxEntries<1 || $maxEntries>100000){ throw new \InvalidArgumentException('Panel agent in-memory store capacity must be between 1 and 100000 entries.'); }
		$this->clock=$clock===null ? null : \Closure::fromCallable($clock);
	}

	public function revision(): int { return $this->revision; }
	public function audit(): array { return $this->audit; }
	public function lastAuditHash(): string { $last=$this->audit[count($this->audit)-1] ?? null; return $last instanceof PanelAgentAuditReceipt ? $last->hash() : ''; }

	public function append(PanelAgentAuditReceipt $receipt, int $expectedRevision): int {
		$this->assertRevision($expectedRevision); $this->assertCapacity(count($this->audit),$this->maxEntries*4,'audit'); $this->assertNextReceipt($receipt);
		$this->audit[]=$receipt; return ++$this->revision;
	}

	public function lookup(string $planHash, string $scopeFingerprint, string $idempotencyKey, string $requestHash): ?PanelAgentExecutionResult {
		$planHash=PanelAgentGuard::digest($planHash,'lookup plan hash'); $scopeFingerprint=PanelAgentGuard::digest($scopeFingerprint,'lookup scope'); $idempotencyKey=PanelAgentGuard::boundedString($idempotencyKey,'idempotency key',256); $requestHash=PanelAgentGuard::digest($requestHash,'request hash');
		$keyHash=hash('sha256',"panel-agent-idempotency-v1\0{$scopeFingerprint}\0{$idempotencyKey}"); $id=$this->idempotency[$keyHash] ?? null;
		if($id===null){ return null; }
		$reservation=$this->reservations[$id];
		if(!hash_equals($reservation['plan_hash'],$planHash) || !hash_equals($reservation['request_hash'],$requestHash)){ throw new PanelAgentException('idempotency_conflict','The Panel agent idempotency key was used for another request.',409); }
		if($reservation['status']!=='completed' || !$reservation['result'] instanceof PanelAgentExecutionResult){ return $this->now()>=$reservation['lease_expires_at'] ? null : throw new PanelAgentException('execution_in_progress','The Panel agent execution is already in progress.',409); }
		return $reservation['result'];
	}

	public function reserve(string $planHash, string $scopeFingerprint, string $idempotencyKey, string $requestHash, array $nonces, int $expectedRevision): PanelAgentStoreReservation {
		$planHash=PanelAgentGuard::digest($planHash, 'reservation plan hash'); $scopeFingerprint=PanelAgentGuard::digest($scopeFingerprint, 'reservation scope');
		$idempotencyKey=PanelAgentGuard::boundedString($idempotencyKey, 'idempotency key', 256); $requestHash=PanelAgentGuard::digest($requestHash, 'request hash');
		if($nonces===[] || count($nonces)>3 || count(array_unique($nonces))!==count($nonces)){ throw new PanelAgentException('nonce_invalid', 'Panel agent execution nonces are invalid.', 409); }
		foreach($nonces as $nonce){ if(!is_string($nonce) || preg_match('/^[a-f0-9]{32}$/D', $nonce)!==1){ throw new PanelAgentException('nonce_invalid', 'Panel agent execution nonce is invalid.', 409); } }
		$now=$this->now(); $reclaimed=[]; $reclaimedReservationId=null;
		$keyHash=hash('sha256', "panel-agent-idempotency-v1\0{$scopeFingerprint}\0{$idempotencyKey}"); $existingId=$this->idempotency[$keyHash] ?? null;
		if($existingId!==null){
			$existing=$this->reservations[$existingId];
			if(!hash_equals($existing['plan_hash'], $planHash) || !hash_equals($existing['request_hash'], $requestHash)){ throw new PanelAgentException('idempotency_conflict', 'The Panel agent idempotency key was used for another request.', 409); }
			if($existing['status']==='completed' && $existing['result'] instanceof PanelAgentExecutionResult){ return PanelAgentStoreReservation::replay($existing['result'], $this->revision); }
			if($now<$existing['lease_expires_at']){ throw new PanelAgentException('execution_in_progress', 'The Panel agent execution is already in progress.', 409); }
			$this->assertRevision($expectedRevision); $expectedNonces=$existing['nonces']; sort($expectedNonces,SORT_STRING); $presentedNonces=$nonces; sort($presentedNonces,SORT_STRING);
			if($expectedNonces!==$presentedNonces){ throw new PanelAgentException('intent_replayed','Expired Panel agent execution leases may only be reclaimed with their original signed intents.',409); }
			$reclaimed=array_fill_keys($existing['nonces'],true); $reclaimedReservationId=$existingId;
		}
		$this->assertRevision($expectedRevision);
		if(isset($this->cancelled[$planHash])){ throw new PanelAgentException('plan_cancelled', 'The Panel agent plan was cancelled.', 409); }
		$this->assertCapacity(count($this->reservations)-($reclaimedReservationId===null ? 0 : 1),$this->maxEntries,'reservation');
		$newNonceCount=0; foreach($nonces as $nonce){ if(isset($this->nonces[$nonce]) && !isset($reclaimed[$nonce])){ throw new PanelAgentException('intent_replayed', 'A Panel agent signed intent was already consumed.', 409); } if(!isset($this->nonces[$nonce])){ $newNonceCount++; } }
		$this->assertCapacity(count($this->nonces)+$newNonceCount,$this->maxEntries*3+1,'nonce');
		$id='agent_reservation_'.bin2hex(random_bytes(12));
		$leaseRevision=$this->revision+1;
		$leaseExpiresAt=$now>PHP_INT_MAX-$this->leaseSeconds ? PHP_INT_MAX : $now+$this->leaseSeconds;
		if($reclaimedReservationId!==null){ unset($this->reservations[$reclaimedReservationId]); }
		$this->reservations[$id]=['id'=>$id,'plan_hash'=>$planHash,'scope'=>$scopeFingerprint,'key_hash'=>$keyHash,'request_hash'=>$requestHash,'nonces'=>$nonces,'lease_revision'=>$leaseRevision,'lease_expires_at'=>$leaseExpiresAt,'status'=>'pending','result'=>null];
		$this->idempotency[$keyHash]=$id; foreach($nonces as $nonce){ $this->nonces[$nonce]=true; }
		$this->revision=$leaseRevision; return PanelAgentStoreReservation::acquired($id, $leaseRevision, $leaseExpiresAt);
	}

	public function renew(string $reservationId, int $expectedLeaseRevision, int $minimumLeaseSeconds): PanelAgentStoreReservation {
		PanelAgentGuard::identifier($reservationId,'reservation id',128);
		if($minimumLeaseSeconds<30 || $minimumLeaseSeconds>3600){ throw new \InvalidArgumentException('Panel agent minimum lease renewal must be between 30 and 3600 seconds.'); }
		$reservation=$this->reservations[$reservationId] ?? null;
		if(!is_array($reservation) || $reservation['status']!=='pending'){ throw new PanelAgentException('reservation_invalid','Panel agent execution reservation is invalid.',409); }
		if($expectedLeaseRevision!==$reservation['lease_revision']){ throw new PanelAgentException('revision_conflict','Panel agent execution lease revision is invalid.',409); }
		$now=$this->now(); $duration=max($this->leaseSeconds,$minimumLeaseSeconds); $expiresAt=$now>PHP_INT_MAX-$duration ? PHP_INT_MAX : $now+$duration; $leaseRevision=$this->revision+1;
		$this->reservations[$reservationId]['lease_revision']=$leaseRevision; $this->reservations[$reservationId]['lease_expires_at']=$expiresAt; $this->revision=$leaseRevision;
		return PanelAgentStoreReservation::acquired($reservationId,$leaseRevision,$expiresAt);
	}

	public function complete(string $reservationId, PanelAgentExecutionResult $result, PanelAgentRequestContext $actor, string $auditEvent, string $auditCode, array $auditDetails, int $occurredAt, int $expectedRevision): PanelAgentExecutionResult {
		PanelAgentGuard::identifier($reservationId, 'reservation id', 128); $reservation=$this->reservations[$reservationId] ?? null;
		if(!is_array($reservation) || $reservation['status']!=='pending'){ throw new PanelAgentException('reservation_invalid', 'Panel agent execution reservation is invalid.', 409); }
		if($expectedRevision!==$reservation['lease_revision']){ throw new PanelAgentException('revision_conflict','Panel agent execution lease revision is invalid.',409); }
		if(!hash_equals($reservation['plan_hash'],$result->planHash()) || $result->receipt()!==null || !hash_equals($auditCode,$result->code()) || $auditEvent!==($result->ok() ? 'execution_completed' : 'execution_failed')){ throw new PanelAgentException('reservation_result_invalid','Panel agent execution result does not match its reservation.',409); }
		if(!hash_equals($reservation['scope'],$actor->scopeFingerprint())){ throw new PanelAgentException('reservation_scope_mismatch','Panel agent execution actor does not match its reservation.',403); }
		$this->assertCapacity(count($this->audit),$this->maxEntries*4,'audit'); $revision=$this->revision+1;
		$receipt=PanelAgentAuditReceipt::create(count($this->audit)+1,$auditEvent,$actor,$reservation['plan_hash'],$auditCode,$auditDetails,$this->lastAuditHash(),$occurredAt);
		$result=$result->withReceipt($receipt,$revision);
		$this->reservations[$reservationId]['status']='completed'; $this->reservations[$reservationId]['result']=$result; $this->audit[]=$receipt; $this->revision=$revision;
		return $result;
	}

	public function cancel(string $planHash, PanelAgentAuditReceipt $receipt, int $expectedRevision): int {
		$this->assertRevision($expectedRevision); $planHash=PanelAgentGuard::digest($planHash, 'cancelled plan hash');
		if(isset($this->cancelled[$planHash])){ return $this->revision; }
		$this->assertCapacity(count($this->cancelled),$this->maxEntries,'cancellation'); $this->assertCapacity(count($this->audit),$this->maxEntries*4,'audit'); $this->assertNextReceipt($receipt); $this->cancelled[$planHash]=true; $this->audit[]=$receipt;
		return ++$this->revision;
	}
	public function cancelled(string $planHash): bool { return isset($this->cancelled[PanelAgentGuard::digest($planHash, 'plan hash')]); }

	public function checkpointType(): string { return 'panel_agent_in_memory_store_v2'; }
	public function checkpoint(): array { return ['type'=>$this->checkpointType(),'revision'=>$this->revision,'audit'=>$this->audit,'cancelled'=>$this->cancelled,'nonces'=>$this->nonces,'reservations'=>$this->reservations,'idempotency'=>$this->idempotency]; }
	public function restore(array $checkpoint): PanelCheckpointableService {
		if(array_keys($checkpoint)!==['type','revision','audit','cancelled','nonces','reservations','idempotency'] || $checkpoint['type']!==$this->checkpointType() || !is_int($checkpoint['revision']) || $checkpoint['revision']<0 || !is_array($checkpoint['audit']) || !array_is_list($checkpoint['audit']) || count($checkpoint['audit'])>$checkpoint['revision'] || count($checkpoint['audit'])>$this->maxEntries*4 || !is_array($checkpoint['cancelled']) || count($checkpoint['cancelled'])>$this->maxEntries || !is_array($checkpoint['nonces']) || count($checkpoint['nonces'])>$this->maxEntries*3 || !is_array($checkpoint['reservations']) || count($checkpoint['reservations'])>$this->maxEntries || !is_array($checkpoint['idempotency']) || count($checkpoint['idempotency'])>$this->maxEntries || count($checkpoint['idempotency'])!==count($checkpoint['reservations'])){
			throw new \InvalidArgumentException('Panel agent store checkpoint is invalid.');
		}
		$previous='';
		foreach($checkpoint['audit'] as $index=>$receipt){ if(!$receipt instanceof PanelAgentAuditReceipt || $receipt->sequence()!==$index+1 || !$receipt->verify($previous)){ throw new \InvalidArgumentException('Panel agent store audit checkpoint is invalid.'); } $previous=$receipt->hash(); }
		foreach($checkpoint['cancelled'] as $hash=>$true){ PanelAgentGuard::digest((string)$hash, 'cancelled plan hash'); if($true!==true){ throw new \InvalidArgumentException('Panel agent store cancellation checkpoint is invalid.'); } }
		foreach($checkpoint['nonces'] as $nonce=>$true){ if(preg_match('/^[a-f0-9]{32}$/D', (string)$nonce)!==1 || $true!==true){ throw new \InvalidArgumentException('Panel agent store nonce checkpoint is invalid.'); } }
		foreach($checkpoint['reservations'] as $id=>$reservation){
			if(!is_array($reservation) || array_keys($reservation)!==['id','plan_hash','scope','key_hash','request_hash','nonces','lease_revision','lease_expires_at','status','result'] || $reservation['id']!==$id || !is_array($reservation['nonces']) || !array_is_list($reservation['nonces']) || $reservation['nonces']===[] || count($reservation['nonces'])>3 || count(array_unique($reservation['nonces']))!==count($reservation['nonces']) || !is_int($reservation['lease_revision']) || $reservation['lease_revision']<1 || $reservation['lease_revision']>$checkpoint['revision'] || !is_int($reservation['lease_expires_at']) || $reservation['lease_expires_at']<1 || !in_array($reservation['status'], ['pending','completed'], true) || ($reservation['status']==='completed' && !$reservation['result'] instanceof PanelAgentExecutionResult) || ($reservation['status']==='pending' && $reservation['result']!==null) || ($reservation['result'] instanceof PanelAgentExecutionResult && $reservation['result']->storeRevision()>$checkpoint['revision'])){
				throw new \InvalidArgumentException('Panel agent store reservation checkpoint is invalid.');
			}
			PanelAgentGuard::identifier((string)$id, 'reservation id', 128); PanelAgentGuard::digest((string)$reservation['plan_hash'], 'reservation plan hash'); PanelAgentGuard::digest((string)$reservation['scope'], 'reservation scope'); PanelAgentGuard::digest((string)$reservation['key_hash'], 'idempotency hash'); PanelAgentGuard::digest((string)$reservation['request_hash'], 'request hash');
			if(($checkpoint['idempotency'][$reservation['key_hash']] ?? null)!==$id){ throw new \InvalidArgumentException('Panel agent store reservation idempotency checkpoint is invalid.'); }
			foreach($reservation['nonces'] as $nonce){ if(!is_string($nonce) || !isset($checkpoint['nonces'][$nonce])){ throw new \InvalidArgumentException('Panel agent store reservation nonce checkpoint is invalid.'); } }
			if($reservation['result'] instanceof PanelAgentExecutionResult && (!hash_equals($reservation['plan_hash'],$reservation['result']->planHash()) || !$reservation['result']->receipt() instanceof PanelAgentAuditReceipt || !hash_equals($reservation['scope'],$reservation['result']->receipt()->scopeFingerprint()) || !hash_equals($reservation['plan_hash'],$reservation['result']->receipt()->planHash()) || $reservation['result']->receipt()->sequence()>count($checkpoint['audit']) || !hash_equals($checkpoint['audit'][$reservation['result']->receipt()->sequence()-1]->hash(),$reservation['result']->receipt()->hash()))){ throw new \InvalidArgumentException('Panel agent store completed reservation checkpoint is invalid.'); }
		}
		foreach($checkpoint['idempotency'] as $hash=>$id){ if(!is_string($id) || !isset($checkpoint['reservations'][$id]) || !hash_equals((string)$hash, $checkpoint['reservations'][$id]['key_hash'])){ throw new \InvalidArgumentException('Panel agent store idempotency checkpoint is invalid.'); } }
		$this->revision=$checkpoint['revision']; $this->audit=$checkpoint['audit']; $this->cancelled=$checkpoint['cancelled']; $this->nonces=$checkpoint['nonces']; $this->reservations=$checkpoint['reservations']; $this->idempotency=$checkpoint['idempotency'];
		return $this;
	}

	public function jsonSerialize(): array { return ['type'=>'panel_agent_in_memory_store','version'=>2,'revision'=>$this->revision,'audit_receipts'=>count($this->audit),'cancelled_plans'=>count($this->cancelled),'consumed_nonces'=>count($this->nonces),'execution_reservations'=>count($this->reservations),'lease_seconds'=>$this->leaseSeconds,'max_entries'=>$this->maxEntries,'bounded'=>true,'durable'=>false,'identifiers_exposed'=>false]; }

	private function assertRevision(int $expected): void { if($expected!==$this->revision){ throw new PanelAgentException('revision_conflict', 'Panel agent store revision is stale.', 409); } }
	private function assertNextReceipt(PanelAgentAuditReceipt $receipt): void { if($receipt->sequence()!==count($this->audit)+1 || !$receipt->verify($this->lastAuditHash())){ throw new PanelAgentException('audit_chain_invalid', 'Panel agent audit receipt does not extend the current chain.', 409); } }
	private function assertCapacity(int $count, int $limit, string $kind): void { if($count>=$limit){ throw new PanelAgentException('store_capacity_exceeded',"Panel agent in-memory {$kind} capacity was exhausted.",503); } }
	private function now(): int { $value=$this->clock===null ? time() : ($this->clock)(); if(!is_int($value) || $value<0){ throw new \UnexpectedValueException('Panel agent store clock must return a non-negative integer timestamp.'); } return $value; }
}
