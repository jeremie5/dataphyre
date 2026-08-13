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
 * Crash-safe, cross-process agent workflow store with optimistic revisions,
 * renewable fenced reservations, hashed replay material, and strict state
 * integrity validation.
 *
 * Commits are immutable, atomically-renamed snapshots. Unlike the general
 * notification snapshot feed, the newest committed workflow snapshot never
 * falls back to an older revision when corrupt: replay state must fail closed.
 */
final class PanelAtomicAgentWorkflowStore implements PanelAgentWorkflowStore, \JsonSerializable {
	private const STATE_TYPE='dataphyre_panel_agent_workflow_state';
	private const SNAPSHOT_TYPE='dataphyre_panel_agent_workflow_snapshot';
	private const VERSION=1;
	private const MAX_INTENT_TTL_SECONDS=900;

	private readonly string $directory;
	private readonly \Closure $clock;
	private readonly \Closure $reservationFactory;

	public function __construct(
		string $directory,
		?callable $clock=null,
		private readonly int $leaseSeconds=120,
		private readonly int $maxEntries=4096,
		private readonly int $retentionSeconds=86400,
		private readonly int $retainSnapshots=16,
		private readonly int $maxStateBytes=67108864,
		?callable $reservationFactory=null,
	){
		$directory=rtrim(trim($directory),"\\/");
		if($directory==='' || str_contains($directory,"\0")){ throw new \InvalidArgumentException('Panel agent workflow store directory is invalid.'); }
		if($leaseSeconds<30 || $leaseSeconds>3600){ throw new \InvalidArgumentException('Panel agent execution lease must be between 30 and 3600 seconds.'); }
		if($maxEntries<1 || $maxEntries>100000){ throw new \InvalidArgumentException('Panel agent workflow store capacity must be between 1 and 100000 entries.'); }
		if($retentionSeconds<self::MAX_INTENT_TTL_SECONDS*4 || $retentionSeconds>31536000){ throw new \InvalidArgumentException('Panel agent workflow retention must be between 3600 and 31536000 seconds.'); }
		if($retainSnapshots<2 || $retainSnapshots>256){ throw new \InvalidArgumentException('Panel agent workflow snapshot retention must be between 2 and 256.'); }
		if($maxStateBytes<1048576 || $maxStateBytes>536870912){ throw new \InvalidArgumentException('Panel agent workflow state limit must be between 1 MiB and 512 MiB.'); }
		$this->directory=$directory;
		$this->clock=\Closure::fromCallable($clock ?? static fn():int=>time());
		$this->reservationFactory=\Closure::fromCallable($reservationFactory ?? static fn():string=>'agent_reservation_'.bin2hex(random_bytes(12)));
		$this->ensureDirectory();
	}

	public function revision(): int { return $this->read()['revision']; }

	public function audit(): array {
		return $this->hydrateAudit($this->read()['audit']);
	}

	public function lastAuditHash(): string {
		$audit=$this->read()['audit']; $last=$audit[count($audit)-1] ?? null;
		return is_array($last) ? (string)$last['hash'] : '';
	}

	public function append(PanelAgentAuditReceipt $receipt, int $expectedRevision): int {
		return $this->mutate(function(array &$state) use ($receipt,$expectedRevision): array {
			$this->assertRevision($state,$expectedRevision); $this->assertCapacity(count($state['audit']),$this->maxEntries*4,'audit'); $this->assertDurableReceipt($receipt); $this->assertNextReceipt($state,$receipt);
			$state['audit'][]=$receipt->jsonSerialize(); $state['revision']++;
			return ['changed'=>true,'result'=>$state['revision']];
		});
	}

	public function lookup(string $planHash, string $scopeFingerprint, string $idempotencyKey, string $requestHash): ?PanelAgentExecutionResult {
		$planHash=PanelAgentGuard::digest($planHash,'lookup plan hash'); $scopeFingerprint=PanelAgentGuard::digest($scopeFingerprint,'lookup scope');
		$idempotencyKey=PanelAgentGuard::boundedString($idempotencyKey,'idempotency key',256); $requestHash=PanelAgentGuard::digest($requestHash,'request hash');
		$state=$this->read(); $keyHash=$this->idempotencyHash($scopeFingerprint,$idempotencyKey); $id=$state['idempotency'][$keyHash] ?? null;
		if(!is_string($id)){ return null; }
		$reservation=$state['reservations'][$id];
		if(!hash_equals($reservation['plan_hash'],$planHash) || !hash_equals($reservation['scope'],$scopeFingerprint) || !hash_equals($reservation['request_hash'],$requestHash)){
			throw new PanelAgentException('idempotency_conflict','The Panel agent idempotency key was used for another request.',409);
		}
		if($reservation['status']!=='completed'){
			return $this->now()>=$reservation['lease_expires_at'] ? null : throw new PanelAgentException('execution_in_progress','The Panel agent execution is already in progress.',409);
		}
		return $this->decodeResult($reservation['result'],$this->hydrateAudit($state['audit']),$reservation['plan_hash'],$reservation['scope'],$state['revision']);
	}

	public function reserve(string $planHash, string $scopeFingerprint, string $idempotencyKey, string $requestHash, array $nonces, int $expectedRevision): PanelAgentStoreReservation {
		$planHash=PanelAgentGuard::digest($planHash,'reservation plan hash'); $scopeFingerprint=PanelAgentGuard::digest($scopeFingerprint,'reservation scope');
		$idempotencyKey=PanelAgentGuard::boundedString($idempotencyKey,'idempotency key',256); $requestHash=PanelAgentGuard::digest($requestHash,'request hash');
		$nonceTags=$this->nonceTags($nonces); $now=$this->now();
		return $this->mutate(function(array &$state) use ($planHash,$scopeFingerprint,$idempotencyKey,$requestHash,$nonceTags,$expectedRevision,$now): array {
			$keyHash=$this->idempotencyHash($scopeFingerprint,$idempotencyKey); $existingId=$state['idempotency'][$keyHash] ?? null; $reclaimed=[];
			if(is_string($existingId)){
				$existing=$state['reservations'][$existingId];
				if(!hash_equals($existing['plan_hash'],$planHash) || !hash_equals($existing['scope'],$scopeFingerprint) || !hash_equals($existing['request_hash'],$requestHash)){
					throw new PanelAgentException('idempotency_conflict','The Panel agent idempotency key was used for another request.',409);
				}
				if($existing['status']==='completed'){
					$result=$this->decodeResult($existing['result'],$this->hydrateAudit($state['audit']),$existing['plan_hash'],$existing['scope'],$state['revision']);
					return ['changed'=>false,'result'=>PanelAgentStoreReservation::replay($result,$state['revision'])];
				}
				if($now<$existing['lease_expires_at']){ throw new PanelAgentException('execution_in_progress','The Panel agent execution is already in progress.',409); }
				$this->assertRevision($state,$expectedRevision); $expected=$existing['nonce_tags']; sort($expected,SORT_STRING); $presented=$nonceTags; sort($presented,SORT_STRING);
				if($expected!==$presented){ throw new PanelAgentException('intent_replayed','Expired Panel agent execution leases may only be reclaimed with their original signed intents.',409); }
				$reclaimed=array_fill_keys($existing['nonce_tags'],true); unset($state['reservations'][$existingId]);
			}
			$this->assertRevision($state,$expectedRevision);
			if(isset($state['cancelled'][$planHash])){ throw new PanelAgentException('plan_cancelled','The Panel agent plan was cancelled.',409); }
			$this->assertCapacity(count($state['reservations']),$this->maxEntries,'reservation');
			foreach($nonceTags as $tag){ if(isset($state['nonces'][$tag]) && !isset($reclaimed[$tag])){ throw new PanelAgentException('intent_replayed','A Panel agent signed intent was already consumed.',409); } }
			$id=($this->reservationFactory)();
			if(!is_string($id)){ throw new \UnexpectedValueException('Panel agent reservation factory must return a string.'); }
			try{ $id=PanelAgentGuard::identifier($id,'reservation id',128); }catch(\Throwable $exception){ throw new \UnexpectedValueException('Panel agent reservation factory returned an invalid id.',0,$exception); }
			if(isset($state['reservations'][$id])){ throw new PanelAgentException('reservation_id_collision','Panel agent reservation id allocation failed closed.',503); }
			$revision=$state['revision']+1; $expiresAt=$this->plusSeconds($now,$this->leaseSeconds);
			$state['reservations'][$id]=[
				'id'=>$id,'plan_hash'=>$planHash,'scope'=>$scopeFingerprint,'key_hash'=>$keyHash,'request_hash'=>$requestHash,'nonce_tags'=>$nonceTags,
				'lease_revision'=>$revision,'lease_expires_at'=>$expiresAt,'status'=>'pending','result'=>null,
				'created_at'=>$now,'updated_at'=>$now,'completed_at'=>null,
			];
			$state['idempotency'][$keyHash]=$id; foreach($nonceTags as $tag){ $state['nonces'][$tag]=$id; }
			$state['revision']=$revision;
			return ['changed'=>true,'result'=>PanelAgentStoreReservation::acquired($id,$revision,$expiresAt)];
		});
	}

	public function renew(string $reservationId, int $expectedLeaseRevision, int $minimumLeaseSeconds): PanelAgentStoreReservation {
		$reservationId=PanelAgentGuard::identifier($reservationId,'reservation id',128);
		if($minimumLeaseSeconds<30 || $minimumLeaseSeconds>3600){ throw new \InvalidArgumentException('Panel agent minimum lease renewal must be between 30 and 3600 seconds.'); }
		$now=$this->now();
		return $this->mutate(function(array &$state) use ($reservationId,$expectedLeaseRevision,$minimumLeaseSeconds,$now): array {
			$reservation=$state['reservations'][$reservationId] ?? null;
			if(!is_array($reservation) || $reservation['status']!=='pending'){ throw new PanelAgentException('reservation_invalid','Panel agent execution reservation is invalid.',409); }
			if($expectedLeaseRevision!==$reservation['lease_revision']){ throw new PanelAgentException('revision_conflict','Panel agent execution lease revision is invalid.',409); }
			if($now>=$reservation['lease_expires_at']){ throw new PanelAgentException('reservation_expired','Panel agent execution reservation expired.',409); }
			$revision=$state['revision']+1; $expiresAt=$this->plusSeconds($now,max($this->leaseSeconds,$minimumLeaseSeconds));
			$state['reservations'][$reservationId]['lease_revision']=$revision; $state['reservations'][$reservationId]['lease_expires_at']=$expiresAt; $state['reservations'][$reservationId]['updated_at']=$now; $state['revision']=$revision;
			return ['changed'=>true,'result'=>PanelAgentStoreReservation::acquired($reservationId,$revision,$expiresAt)];
		});
	}

	public function complete(string $reservationId, PanelAgentExecutionResult $result, PanelAgentRequestContext $actor, string $auditEvent, string $auditCode, array $auditDetails, int $occurredAt, int $expectedRevision): PanelAgentExecutionResult {
		$reservationId=PanelAgentGuard::identifier($reservationId,'reservation id',128); $now=$this->now();
		return $this->mutate(function(array &$state) use ($reservationId,$result,$actor,$auditEvent,$auditCode,$auditDetails,$occurredAt,$expectedRevision,$now): array {
			$reservation=$state['reservations'][$reservationId] ?? null;
			if(!is_array($reservation) || $reservation['status']!=='pending'){ throw new PanelAgentException('reservation_invalid','Panel agent execution reservation is invalid.',409); }
			if($expectedRevision!==$reservation['lease_revision']){ throw new PanelAgentException('revision_conflict','Panel agent execution lease revision is invalid.',409); }
			if($now>=$reservation['lease_expires_at']){ throw new PanelAgentException('reservation_expired','Panel agent execution reservation expired.',409); }
			if(!hash_equals($reservation['plan_hash'],$result->planHash()) || $result->receipt()!==null || $result->storeRevision()!==$expectedRevision || !hash_equals($auditCode,$result->code()) || $auditEvent!==($result->ok() ? 'execution_completed' : 'execution_failed')){
				throw new PanelAgentException('reservation_result_invalid','Panel agent execution result does not match its reservation.',409);
			}
			if(!hash_equals($reservation['scope'],$actor->scopeFingerprint())){ throw new PanelAgentException('reservation_scope_mismatch','Panel agent execution actor does not match its reservation.',403); }
			$this->assertCapacity(count($state['audit']),$this->maxEntries*4,'audit'); $revision=$state['revision']+1;
			$receipt=PanelAgentAuditReceipt::create(count($state['audit'])+1,$auditEvent,$actor,$reservation['plan_hash'],$auditCode,$this->durableDetails($auditDetails),$this->lastHash($state),$occurredAt);
			$result=$result->withReceipt($receipt,$revision); $state['audit'][]=$receipt->jsonSerialize();
			$state['reservations'][$reservationId]['status']='completed'; $state['reservations'][$reservationId]['result']=$this->encodeResult($result); $state['reservations'][$reservationId]['updated_at']=$now; $state['reservations'][$reservationId]['completed_at']=$now; $state['revision']=$revision;
			return ['changed'=>true,'result'=>$result];
		});
	}

	public function cancel(string $planHash, PanelAgentAuditReceipt $receipt, int $expectedRevision): int {
		$planHash=PanelAgentGuard::digest($planHash,'cancelled plan hash');
		return $this->mutate(function(array &$state) use ($planHash,$receipt,$expectedRevision): array {
			$this->assertRevision($state,$expectedRevision);
			if(isset($state['cancelled'][$planHash])){ return ['changed'=>false,'result'=>$state['revision']]; }
			if(!hash_equals($receipt->planHash(),$planHash) || $receipt->event()!=='plan_cancelled'){ throw new PanelAgentException('audit_chain_invalid','Panel agent cancellation receipt is invalid.',409); } $this->assertDurableReceipt($receipt);
			$this->assertCapacity(count($state['cancelled']),$this->maxEntries,'cancellation'); $this->assertCapacity(count($state['audit']),$this->maxEntries*4,'audit'); $this->assertNextReceipt($state,$receipt);
			$state['cancelled'][$planHash]=$receipt->occurredAt(); $state['audit'][]=$receipt->jsonSerialize(); $state['revision']++;
			return ['changed'=>true,'result'=>$state['revision']];
		});
	}

	public function cancelled(string $planHash): bool { return isset($this->read()['cancelled'][PanelAgentGuard::digest($planHash,'plan hash')]); }

	/**
	 * Explicitly removes completed and long-abandoned reservation replay state.
	 * Cancellation tombstones are permanent unless the host explicitly opts in.
	 * Audit receipts are never compacted because the public chain is contiguous.
	 *
	 * @return array<string,int|bool>
	 */
	public function collectGarbage(int $limit=1000, bool $pruneCancellations=false): array {
		if($limit<1 || $limit>100000){ throw new \InvalidArgumentException('Panel agent garbage collection limit must be between 1 and 100000.'); }
		$now=$this->now(); $threshold=max(0,$now-$this->retentionSeconds);
		return $this->mutate(function(array &$state) use ($limit,$pruneCancellations,$threshold): array {
			$candidates=[];
			foreach($state['reservations'] as $id=>$reservation){
				$eligible=$reservation['status']==='completed' ? $reservation['completed_at']<=$threshold : $reservation['lease_expires_at']<=$threshold;
				if($eligible){ $candidates[]=['at'=>$reservation['status']==='completed' ? $reservation['completed_at'] : $reservation['lease_expires_at'],'id'=>$id,'status'=>$reservation['status']]; }
			}
			usort($candidates,static fn(array $left,array $right):int=>[$left['at'],$left['id']]<=>[$right['at'],$right['id']]);
			$completed=0; $abandoned=0; $nonces=0; $removed=0;
			foreach(array_slice($candidates,0,$limit) as $candidate){
				$id=$candidate['id']; $reservation=$state['reservations'][$id]; unset($state['reservations'][$id]);
				if(($state['idempotency'][$reservation['key_hash']] ?? null)===$id){ unset($state['idempotency'][$reservation['key_hash']]); }
				foreach($reservation['nonce_tags'] as $tag){ if(($state['nonces'][$tag] ?? null)===$id){ unset($state['nonces'][$tag]); $nonces++; } }
				$candidate['status']==='completed' ? $completed++ : $abandoned++; $removed++;
			}
			$cancellations=0;
			if($pruneCancellations && $removed<$limit){
				$activePlans=[]; foreach($state['reservations'] as $reservation){ $activePlans[$reservation['plan_hash']]=true; }
				$cancelCandidates=[]; foreach($state['cancelled'] as $plan=>$at){ if($at<=$threshold && !isset($activePlans[$plan])){ $cancelCandidates[]=['at'=>$at,'plan'=>$plan]; } }
				usort($cancelCandidates,static fn(array $left,array $right):int=>[$left['at'],$left['plan']]<=>[$right['at'],$right['plan']]);
				foreach(array_slice($cancelCandidates,0,$limit-$removed) as $candidate){ unset($state['cancelled'][$candidate['plan']]); $cancellations++; $removed++; }
			}
			if($removed>0){ $state['revision']++; }
			$report=['changed'=>$removed>0,'revision'=>$state['revision'],'completed_reservations'=>$completed,'abandoned_reservations'=>$abandoned,'nonce_tombstones'=>$nonces,'cancellations'=>$cancellations,'audit_receipts_retained'=>count($state['audit'])];
			return ['changed'=>$removed>0,'result'=>$report];
		});
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		$base=[
			'type'=>'panel_atomic_agent_workflow_store','version'=>1,'durable'=>true,'bounded'=>true,'directory_exposed'=>false,
			'callbacks_serialized'=>false,'raw_idempotency_keys_stored'=>false,'raw_intent_nonces_stored'=>false,
			'lease_seconds'=>$this->leaseSeconds,'max_entries'=>$this->maxEntries,'retention_seconds'=>$this->retentionSeconds,'snapshot_retention'=>$this->retainSnapshots,
			'capabilities'=>[
				'atomic_optimistic_revisions'=>true,'cross_process_locking'=>true,'crash_safe_immutable_snapshots'=>true,'snapshot_file_hash_chain'=>true,'corruption_fails_closed'=>true,
				'renewable_fenced_reservations'=>true,'expired_reclaim'=>true,'late_owner_rejection'=>true,'scope_bound_idempotency'=>true,
				'durable_result_lookup'=>true,'durable_cancellation'=>true,'audit_hash_chain'=>true,'replay_material_redacted'=>true,'explicit_gc'=>true,'adapter_callbacks_invoked'=>false,
			],
		];
		try{
			$state=$this->read();
			return $base+['integrity'=>'verified','revision'=>$state['revision'],'counts'=>['audit_receipts'=>count($state['audit']),'cancelled_plans'=>count($state['cancelled']),'nonce_tombstones'=>count($state['nonces']),'reservations'=>count($state['reservations'])]];
		}catch(\Throwable){ return $base+['integrity'=>'failed_closed','revision'=>null,'counts'=>null]; }
	}

	public function jsonSerialize(): array { return $this->manifest(); }

	/** @return array<string,mixed> */
	private function read(): array { return $this->withLock(LOCK_SH,fn():array=>$this->loadUnlocked()['state']); }

	/** @template T @param callable(array<string,mixed>&):array{changed:bool,result:T} $callback @return T */
	private function mutate(callable $callback): mixed {
		return $this->withLock(LOCK_EX,function() use ($callback): mixed {
			$loaded=$this->loadUnlocked(); $state=$loaded['state']; $before=$state; $revision=$state['revision']; $out=$callback($state);
			if(!is_array($out) || array_keys($out)!==['changed','result'] || !is_bool($out['changed'])){ throw new \LogicException('Panel agent workflow mutation returned an invalid transaction result.'); }
			if($out['changed']){
				if($state['revision']!==$revision+1){ throw new \LogicException('Panel agent workflow mutations must increment revision exactly once.'); }
				$this->assertState($state); $this->writeUnlocked($state,$loaded['hash']); $this->pruneUnlocked();
			}elseif(!hash_equals(PanelAgentGuard::canonicalJson($before),PanelAgentGuard::canonicalJson($state))){
				throw new \LogicException('Panel agent workflow no-op mutation changed state.');
			}
			return $out['result'];
		});
	}

	/** @return array{state:array<string,mixed>,hash:string} */
	private function loadUnlocked(): array {
		$files=$this->snapshotFilesUnlocked();
		if($files===[]){ return ['state'=>$this->initialState(),'hash'=>'']; }
		$file=$files[array_key_last($files)];
		if(is_link($file)){ throw new \UnexpectedValueException('Panel agent workflow snapshot failed integrity validation.'); }
		$size=@filesize($file); if(!is_int($size) || $size<1 || $size>$this->maxStateBytes){ throw new \UnexpectedValueException('Panel agent workflow snapshot failed integrity validation.'); }
		$raw=@file_get_contents($file); if(!is_string($raw) || strlen($raw)!==$size){ throw new \UnexpectedValueException('Panel agent workflow snapshot failed integrity validation.'); }
		try{ $snapshot=json_decode($raw,true,64,JSON_THROW_ON_ERROR); }catch(\Throwable $exception){ throw new \UnexpectedValueException('Panel agent workflow snapshot failed integrity validation.',0,$exception); }
		$keys=is_array($snapshot) ? array_keys($snapshot) : []; sort($keys,SORT_STRING);
		if($keys!==['committed_at','hash','previous_hash','sequence','state','type','version'] || $snapshot['type']!==self::SNAPSHOT_TYPE || $snapshot['version']!==self::VERSION || !is_int($snapshot['sequence']) || $snapshot['sequence']<1 || !is_int($snapshot['committed_at']) || $snapshot['committed_at']<0 || !is_array($snapshot['state'])){
			throw new \UnexpectedValueException('Panel agent workflow snapshot failed integrity validation.');
		}
		$expectedFile='agent-'.sprintf('%020d',$snapshot['sequence']).'.json';
		if(basename($file)!==$expectedFile){ throw new \UnexpectedValueException('Panel agent workflow snapshot sequence is invalid.'); }
		$hash=PanelAgentGuard::digest(is_string($snapshot['hash']) ? $snapshot['hash'] : '','snapshot hash'); $previous=$snapshot['previous_hash'];
		if($snapshot['sequence']===1){ if($previous!==''){ throw new \UnexpectedValueException('Panel agent workflow snapshot chain is invalid.'); } }
		else{ $previous=PanelAgentGuard::digest(is_string($previous) ? $previous : '','snapshot previous hash'); if($previous!==$snapshot['previous_hash']){ throw new \UnexpectedValueException('Panel agent workflow snapshot chain is not canonical.'); } }
		$canonical=$snapshot; unset($canonical['hash']);
		if(!hash_equals($hash,hash('sha256',PanelAgentGuard::canonicalJson($canonical)))){ throw new \UnexpectedValueException('Panel agent workflow snapshot hash is invalid.'); }
		if($snapshot['sequence']>1){
			$index=array_key_last($files); if(!is_int($index) || $index<1){ throw new \UnexpectedValueException('Panel agent workflow snapshot predecessor is missing.'); } $predecessor=$files[$index-1];
			if(basename($predecessor)!=='agent-'.sprintf('%020d',$snapshot['sequence']-1).'.json' || is_link($predecessor)){ throw new \UnexpectedValueException('Panel agent workflow snapshot predecessor is invalid.'); }
			$predecessorSize=@filesize($predecessor); $predecessorHash=@hash_file('sha256',$predecessor);
			if(!is_int($predecessorSize) || $predecessorSize<1 || $predecessorSize>$this->maxStateBytes || !is_string($predecessorHash) || !hash_equals($previous,$predecessorHash)){ throw new \UnexpectedValueException('Panel agent workflow snapshot chain failed integrity validation.'); }
		}
		if($snapshot['state']['revision']!==$snapshot['sequence']){ throw new \UnexpectedValueException('Panel agent workflow snapshot revision is invalid.'); }
		$this->assertState($snapshot['state']);
		return ['state'=>$snapshot['state'],'hash'=>hash('sha256',$raw)];
	}

	/** @param array<string,mixed> $state */
	private function writeUnlocked(array $state, string $previousHash): void {
		$sequence=$state['revision']; $snapshot=['type'=>self::SNAPSHOT_TYPE,'version'=>self::VERSION,'sequence'=>$sequence,'committed_at'=>$this->now(),'previous_hash'=>$previousHash,'state'=>$state];
		$snapshot['hash']=hash('sha256',PanelAgentGuard::canonicalJson($snapshot)); $json=json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
		if(strlen($json)>$this->maxStateBytes){ throw new PanelAgentException('store_capacity_exceeded','Panel agent durable state exceeded its byte capacity.',503); }
		$final=$this->directory.DIRECTORY_SEPARATOR.'agent-'.sprintf('%020d',$sequence).'.json';
		if(file_exists($final) || is_link($final)){ throw new \RuntimeException('Panel agent workflow snapshot sequence already exists.'); }
		$temp=$this->directory.DIRECTORY_SEPARATOR.'.agent-'.sprintf('%020d',$sequence).'.'.bin2hex(random_bytes(8)).'.tmp'; $handle=@fopen($temp,'xb');
		if(!is_resource($handle)){ throw new \RuntimeException('Unable to create Panel agent workflow snapshot.'); }
		@chmod($temp,0600);
		try{
			$offset=0; $length=strlen($json);
			while($offset<$length){ $written=fwrite($handle,substr($json,$offset)); if($written===false || $written===0){ throw new \RuntimeException('Unable to write Panel agent workflow snapshot.'); } $offset+=$written; }
			if(!fflush($handle)){ throw new \RuntimeException('Unable to flush Panel agent workflow snapshot.'); }
			if(function_exists('fsync')){ @fsync($handle); }
		}finally{ @fclose($handle); }
		if(!@rename($temp,$final)){ @unlink($temp); throw new \RuntimeException('Unable to atomically commit Panel agent workflow snapshot.'); }
	}

	private function ensureDirectory(): void {
		if(is_link($this->directory)){ throw new \RuntimeException('Panel agent workflow store directory cannot be a symbolic link.'); }
		if(!is_dir($this->directory) && !@mkdir($this->directory,0770,true) && !is_dir($this->directory)){ throw new \RuntimeException('Unable to create Panel agent workflow store directory.'); }
		if(!is_writable($this->directory)){ throw new \RuntimeException('Panel agent workflow store directory is not writable.'); }
	}

	/** @template T @param callable():T $callback @return T */
	private function withLock(int $mode, callable $callback): mixed {
		$this->ensureDirectory(); $file=$this->directory.DIRECTORY_SEPARATOR.'.agent-workflow.lock';
		if(is_link($file)){ throw new \RuntimeException('Panel agent workflow lock cannot be a symbolic link.'); }
		$handle=@fopen($file,'c+b'); if(!is_resource($handle)){ throw new \RuntimeException('Unable to open Panel agent workflow lock.'); }
		try{ if(!flock($handle,$mode)){ throw new \RuntimeException('Unable to acquire Panel agent workflow lock.'); } return $callback(); }
		finally{ @flock($handle,LOCK_UN); @fclose($handle); }
	}

	/** @return list<string> */
	private function snapshotFilesUnlocked(): array {
		$files=glob($this->directory.DIRECTORY_SEPARATOR.'agent-*.json') ?: [];
		$files=array_values(array_filter($files,static fn(string $file):bool=>preg_match('/^agent-[0-9]{20}\.json$/D',basename($file))===1)); sort($files,SORT_STRING); return $files;
	}

	private function pruneUnlocked(): void {
		$files=$this->snapshotFilesUnlocked(); $remove=max(0,count($files)-$this->retainSnapshots);
		for($index=0;$index<$remove;$index++){ @unlink($files[$index]); }
	}

	/** @return array<string,mixed> */
	private function initialState(): array { return ['type'=>self::STATE_TYPE,'version'=>self::VERSION,'revision'=>0,'audit'=>[],'cancelled'=>[],'nonces'=>[],'reservations'=>[],'idempotency'=>[]]; }

	/** @param array<string,mixed> $state */
	private function assertState(array $state): void {
		$keys=array_keys($state); sort($keys,SORT_STRING);
		if($keys!==['audit','cancelled','idempotency','nonces','reservations','revision','type','version'] || $state['type']!==self::STATE_TYPE || $state['version']!==self::VERSION || !is_int($state['revision']) || $state['revision']<0 || !is_array($state['audit']) || !array_is_list($state['audit']) || !is_array($state['cancelled']) || !is_array($state['nonces']) || !is_array($state['reservations']) || !is_array($state['idempotency'])){
			throw new \UnexpectedValueException('Panel agent workflow state failed integrity validation.');
		}
		if(count($state['audit'])>$state['revision'] || count($state['audit'])>$this->maxEntries*4 || count($state['cancelled'])>$this->maxEntries || count($state['reservations'])>$this->maxEntries || count($state['idempotency'])!==count($state['reservations']) || count($state['nonces'])>$this->maxEntries*3){ throw new \UnexpectedValueException('Panel agent workflow state exceeds configured capacity.'); }
		$audit=$this->hydrateAudit($state['audit']); $cancelledReceipts=[];
		foreach($audit as $receipt){ if($receipt->event()==='plan_cancelled'){ $cancelledReceipts[$receipt->planHash()]=$receipt->occurredAt(); } }
		foreach($state['cancelled'] as $plan=>$at){ PanelAgentGuard::digest((string)$plan,'cancelled plan hash'); if(!is_int($at) || $at<0 || ($cancelledReceipts[$plan] ?? null)!==$at){ throw new \UnexpectedValueException('Panel agent cancellation state failed integrity validation.'); } }
		$nonceCount=0;
		foreach($state['reservations'] as $id=>$reservation){
			if(!is_string($id) || !is_array($reservation)){ throw new \UnexpectedValueException('Panel agent reservation state failed integrity validation.'); }
			$this->assertReservation($id,$reservation,$state,$audit); $nonceCount+=count($reservation['nonce_tags']);
		}
		if($nonceCount!==count($state['nonces'])){ throw new \UnexpectedValueException('Panel agent nonce state failed integrity validation.'); }
		foreach($state['nonces'] as $tag=>$id){ PanelAgentGuard::digest((string)$tag,'nonce tag'); if(!is_string($id) || !isset($state['reservations'][$id]) || !in_array($tag,$state['reservations'][$id]['nonce_tags'],true)){ throw new \UnexpectedValueException('Panel agent nonce state failed integrity validation.'); } }
		foreach($state['idempotency'] as $hash=>$id){ PanelAgentGuard::digest((string)$hash,'idempotency hash'); if(!is_string($id) || !isset($state['reservations'][$id]) || !hash_equals((string)$hash,$state['reservations'][$id]['key_hash'])){ throw new \UnexpectedValueException('Panel agent idempotency state failed integrity validation.'); } }
	}

	/** @param array<string,mixed> $reservation @param array<string,mixed> $state @param list<PanelAgentAuditReceipt> $audit */
	private function assertReservation(string $id, array $reservation, array $state, array $audit): void {
		$keys=array_keys($reservation); sort($keys,SORT_STRING);
		if($keys!==['completed_at','created_at','id','key_hash','lease_expires_at','lease_revision','nonce_tags','plan_hash','request_hash','result','scope','status','updated_at'] || $reservation['id']!==$id || !is_array($reservation['nonce_tags']) || !array_is_list($reservation['nonce_tags']) || $reservation['nonce_tags']===[] || count($reservation['nonce_tags'])>3 || count(array_unique($reservation['nonce_tags']))!==count($reservation['nonce_tags']) || !is_int($reservation['lease_revision']) || $reservation['lease_revision']<1 || $reservation['lease_revision']>$state['revision'] || !is_int($reservation['lease_expires_at']) || $reservation['lease_expires_at']<1 || !is_int($reservation['created_at']) || $reservation['created_at']<0 || !is_int($reservation['updated_at']) || $reservation['updated_at']<$reservation['created_at'] || !in_array($reservation['status'],['pending','completed'],true)){
			throw new \UnexpectedValueException('Panel agent reservation state failed integrity validation.');
		}
		PanelAgentGuard::identifier($id,'reservation id',128); PanelAgentGuard::digest((string)$reservation['plan_hash'],'reservation plan hash'); PanelAgentGuard::digest((string)$reservation['scope'],'reservation scope'); PanelAgentGuard::digest((string)$reservation['key_hash'],'idempotency hash'); PanelAgentGuard::digest((string)$reservation['request_hash'],'request hash');
		foreach($reservation['nonce_tags'] as $tag){ PanelAgentGuard::digest(is_string($tag) ? $tag : '','nonce tag'); if(($state['nonces'][$tag] ?? null)!==$id){ throw new \UnexpectedValueException('Panel agent reservation nonce state failed integrity validation.'); } }
		if(($state['idempotency'][$reservation['key_hash']] ?? null)!==$id){ throw new \UnexpectedValueException('Panel agent reservation idempotency state failed integrity validation.'); }
		if($reservation['status']==='pending'){
			if($reservation['result']!==null || $reservation['completed_at']!==null){ throw new \UnexpectedValueException('Panel agent pending reservation state failed integrity validation.'); }
		}else{
			if(!is_array($reservation['result']) || !is_int($reservation['completed_at']) || $reservation['completed_at']<$reservation['created_at'] || $reservation['updated_at']!==$reservation['completed_at']){ throw new \UnexpectedValueException('Panel agent completed reservation state failed integrity validation.'); }
			$result=$this->decodeResult($reservation['result'],$audit,$reservation['plan_hash'],$reservation['scope'],$state['revision']);
			if($result->storeRevision()<=$reservation['lease_revision']){ throw new \UnexpectedValueException('Panel agent completed reservation revision failed integrity validation.'); }
		}
	}

	/** @param list<array<string,mixed>> $raw @return list<PanelAgentAuditReceipt> */
	private function hydrateAudit(array $raw): array {
		$audit=[]; $previous='';
		foreach($raw as $index=>$payload){
			if(!is_array($payload)){ throw new \UnexpectedValueException('Panel agent audit state failed integrity validation.'); }
			try{ $receipt=PanelAgentAuditReceipt::fromArray($payload); $this->assertDurableReceipt($receipt); }catch(\Throwable $exception){ throw new \UnexpectedValueException('Panel agent audit state failed integrity validation.',0,$exception); }
			if($receipt->sequence()!==$index+1 || !$receipt->verify($previous)){ throw new \UnexpectedValueException('Panel agent audit chain failed integrity validation.'); }
			$audit[]=$receipt; $previous=$receipt->hash();
		}
		return $audit;
	}

	/** @param array<string,mixed> $payload @param list<PanelAgentAuditReceipt> $audit */
	private function decodeResult(array $payload, array $audit, string $planHash, string $scope, int $stateRevision): PanelAgentExecutionResult {
		$keys=array_keys($payload); sort($keys,SORT_STRING);
		if($keys!==['code','metadata','ok','plan_hash','receipt','replayed','steps','store_revision','type','version'] || $payload['type']!=='panel_agent_execution_result' || $payload['version']!==1 || !is_bool($payload['ok']) || !is_string($payload['code']) || !is_string($payload['plan_hash']) || !is_array($payload['steps']) || !array_is_list($payload['steps']) || $payload['replayed']!==false || !is_int($payload['store_revision']) || $payload['store_revision']<1 || $payload['store_revision']>$stateRevision || !is_array($payload['receipt']) || !is_array($payload['metadata']) || ($payload['metadata']!==[] && array_is_list($payload['metadata']))){
			throw new \UnexpectedValueException('Panel agent execution result state failed integrity validation.');
		}
		try{ $receipt=PanelAgentAuditReceipt::fromArray($payload['receipt']); }catch(\Throwable $exception){ throw new \UnexpectedValueException('Panel agent execution receipt state failed integrity validation.',0,$exception); }
		$storedReceipt=$audit[$receipt->sequence()-1] ?? null;
		if(!$storedReceipt instanceof PanelAgentAuditReceipt || !hash_equals($storedReceipt->hash(),$receipt->hash()) || !hash_equals($planHash,$payload['plan_hash']) || !hash_equals($planHash,$receipt->planHash()) || !hash_equals($scope,$receipt->scopeFingerprint()) || !hash_equals($payload['code'],$receipt->code()) || $receipt->event()!==($payload['ok'] ? 'execution_completed' : 'execution_failed')){ throw new \UnexpectedValueException('Panel agent execution result binding failed integrity validation.'); }
		try{ $result=PanelAgentExecutionResult::make($payload['ok'],$payload['code'],$payload['plan_hash'],$payload['steps'],$payload['store_revision'],null,$payload['metadata'])->withReceipt($receipt,$payload['store_revision']); }
		catch(\Throwable $exception){ throw new \UnexpectedValueException('Panel agent execution result state failed integrity validation.',0,$exception); }
		if(!hash_equals(PanelAgentGuard::canonicalJson($payload),PanelAgentGuard::canonicalJson($this->encodeResult($result)))){ throw new \UnexpectedValueException('Panel agent execution result is not canonical.'); }
		return $result;
	}

	/** @return array<string,mixed> */
	private function encodeResult(PanelAgentExecutionResult $result): array {
		$payload=$result->jsonSerialize(); $receipt=$result->receipt(); $payload['receipt']=$receipt?->jsonSerialize(); PanelAgentGuard::assertJson($payload,1179648); return $payload;
	}

	/** @param array<string,mixed> $details @return array<string,mixed> */
	private function durableDetails(array $details): array {
		$details=PanelAgentGuard::redact($details);
		foreach($details as $key=>$value){
			$normalized=is_string($key) ? strtolower(trim(preg_replace('/[^a-z0-9]+/i','_',preg_replace('/([a-z0-9])([A-Z])/','$1_$2',$key) ?? $key) ?? '','_')) : '';
			if(in_array($normalized,['idempotency','idempotency_key','nonce','nonces','intent','signed_intent','plan_intent','approval_intent','approval_intents','confirmation_evidence','bearer_proof','lease_token'],true)){ $details[$key]=PanelSensitiveDataSanitizer::REDACTED; }
			elseif(is_array($value)){ $details[$key]=$this->durableDetails($value); }
		}
		return $details;
	}

	private function assertDurableReceipt(PanelAgentAuditReceipt $receipt): void {
		if(!hash_equals(PanelAgentGuard::canonicalJson($receipt->details()),PanelAgentGuard::canonicalJson($this->durableDetails($receipt->details())))){ throw new PanelAgentException('audit_details_unsafe','Panel agent audit receipt contains unsafe replay material.',409); }
	}

	/** @param list<string> $nonces @return list<string> */
	private function nonceTags(array $nonces): array {
		if($nonces===[] || count($nonces)>3 || count(array_unique($nonces))!==count($nonces)){ throw new PanelAgentException('nonce_invalid','Panel agent execution nonces are invalid.',409); }
		$tags=[]; foreach($nonces as $nonce){ if(!is_string($nonce) || preg_match('/^[a-f0-9]{32}$/D',$nonce)!==1){ throw new PanelAgentException('nonce_invalid','Panel agent execution nonce is invalid.',409); } $tags[]=hash('sha256',"panel-agent-nonce-v1\0{$nonce}"); }
		return $tags;
	}

	private function idempotencyHash(string $scope, string $key): string { return hash('sha256',"panel-agent-idempotency-v1\0{$scope}\0{$key}"); }
	/** @param array<string,mixed> $state */ private function assertRevision(array $state,int $expected): void { if($state['revision']!==$expected){ throw new PanelAgentException('revision_conflict','Panel agent store revision is stale.',409); } }
	/** @param array<string,mixed> $state */ private function assertNextReceipt(array $state,PanelAgentAuditReceipt $receipt): void { if($receipt->sequence()!==count($state['audit'])+1 || !$receipt->verify($this->lastHash($state))){ throw new PanelAgentException('audit_chain_invalid','Panel agent audit receipt does not extend the current chain.',409); } }
	/** @param array<string,mixed> $state */ private function lastHash(array $state): string { $last=$state['audit'][count($state['audit'])-1] ?? null; return is_array($last) ? (string)$last['hash'] : ''; }
	private function assertCapacity(int $count,int $limit,string $kind): void { if($count>=$limit){ throw new PanelAgentException('store_capacity_exceeded',"Panel agent durable {$kind} capacity was exhausted.",503); } }
	private function now(): int { $value=($this->clock)(); if(!is_int($value) || $value<0){ throw new \UnexpectedValueException('Panel agent workflow store clock must return a non-negative integer timestamp.'); } return $value; }
	private function plusSeconds(int $timestamp,int $seconds): int { return $timestamp>PHP_INT_MAX-$seconds ? PHP_INT_MAX : $timestamp+$seconds; }
}
