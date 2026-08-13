<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/** Process-local snapshot CAS ledger for tests, long-lived workers, and local use. */
final class ReactorInMemorySnapshotVersionStore implements ReactorSnapshotVersionStore {
	private const MAX_RESERVATION_SECONDS=300;
	/** @var array<string,array{scope_hash:string,component:string,version:int,expires_at:int,reservation_id:string,reservation_expires_at:int}> */
	private array $entries=[];

	public function register(string $snapshotId, string $scopeHash, string $component, int $version, int $expiresAt): bool {
		if(!self::valid($snapshotId, $scopeHash, $component, $version, $expiresAt)){ return false; }
		$entry=['scope_hash'=>$scopeHash,'component'=>$component,'version'=>$version,'expires_at'=>$expiresAt,'reservation_id'=>'','reservation_expires_at'=>0];
		if(isset($this->entries[$snapshotId])){ return $this->entries[$snapshotId]===$entry; }
		$this->entries[$snapshotId]=$entry;
		return true;
	}

	public function reserve(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, string $reservationId, int $reservationExpiresAt): string {
		if(!self::valid($snapshotId, $scopeHash, $component, $expectedVersion, $reservationExpiresAt) || !self::validReservation($reservationId)){
			return self::UNAVAILABLE;
		}
		$entry=$this->entries[$snapshotId] ?? null;
		if(!is_array($entry)){ return self::MISSING; }
		if($entry['scope_hash']!==$scopeHash || $entry['component']!==$component){ return self::MISMATCH; }
		$now=time();
		if($entry['expires_at']<=$now){
			unset($this->entries[$snapshotId]);
			return self::EXPIRED;
		}
		if($expectedVersion<$entry['version']){ return self::STALE; }
		if($expectedVersion>$entry['version']){ return self::FUTURE; }
		if($reservationExpiresAt<=$now || $reservationExpiresAt>min($entry['expires_at'], $now+self::MAX_RESERVATION_SECONDS)){ return self::UNAVAILABLE; }
		if($entry['reservation_id']!=='' && $entry['reservation_expires_at']>$now){ return self::BUSY; }
		$this->entries[$snapshotId]['reservation_id']=$reservationId;
		$this->entries[$snapshotId]['reservation_expires_at']=$reservationExpiresAt;
		return self::CLAIMED;
	}

	public function finalize(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, int $nextVersion, int $nextExpiresAt, string $reservationId): string {
		if(!self::valid($snapshotId, $scopeHash, $component, $expectedVersion, $nextExpiresAt) || $nextVersion!==$expectedVersion+1 || !self::validReservation($reservationId)){ return self::UNAVAILABLE; }
		$entry=$this->entries[$snapshotId] ?? null;
		if(!is_array($entry)){ return self::MISSING; }
		if($entry['scope_hash']!==$scopeHash || $entry['component']!==$component){ return self::MISMATCH; }
		if($entry['expires_at']<=time()){
			unset($this->entries[$snapshotId]);
			return self::EXPIRED;
		}
		if($entry['version']!==$expectedVersion){ return $entry['version']>$expectedVersion ? self::STALE : self::FUTURE; }
		if($entry['reservation_id']!==$reservationId){ return self::BUSY; }
		if($entry['reservation_expires_at']<=time()){
			$this->entries[$snapshotId]['reservation_id']='';
			$this->entries[$snapshotId]['reservation_expires_at']=0;
			return self::RESERVATION_EXPIRED;
		}
		$this->entries[$snapshotId]['version']=$nextVersion;
		$this->entries[$snapshotId]['expires_at']=$nextExpiresAt;
		$this->entries[$snapshotId]['reservation_id']='';
		$this->entries[$snapshotId]['reservation_expires_at']=0;
		return self::CLAIMED;
	}

	public function abort(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, string $reservationId): bool {
		$entry=$this->entries[$snapshotId] ?? null;
		if(!is_array($entry) || $entry['scope_hash']!==$scopeHash || $entry['component']!==$component || $entry['version']!==$expectedVersion || $entry['reservation_id']!==$reservationId){ return false; }
		$this->entries[$snapshotId]['reservation_id']='';
		$this->entries[$snapshotId]['reservation_expires_at']=0;
		return true;
	}

	public function revoke(string $snapshotId, string $scopeHash, string $component, int $version): bool {
		$entry=$this->entries[$snapshotId] ?? null;
		if(!is_array($entry) || $entry['scope_hash']!==$scopeHash || $entry['component']!==$component || $entry['version']!==$version || $entry['reservation_id']!==''){ return false; }
		unset($this->entries[$snapshotId]);
		return true;
	}

	public function manifest(): array {
		return [
			'adapter'=>'memory',
			'atomic_compare_and_swap'=>true,
			'atomic_batch_register'=>false,
			'coordination_scope'=>'php_manager_process',
			'production_safe'=>false,
			'persists_component_state'=>false,
			'reservation_finalize_abort'=>true,
			'one_time_claim_guarantee'=>'completed_dispatches_within_this_manager_instance_only',
			'crash_window'=>'expired_reservations_are_retryable; host action idempotency is still required',
			'partial_mount_rollback'=>'best_effort_revoke',
			'expiry_boundary'=>'expires_at_lte_now_is_expired',
			'max_reservation_seconds'=>self::MAX_RESERVATION_SECONDS,
		];
	}

	private static function valid(string $snapshotId, string $scopeHash, string $component, int $version, int $expiresAt): bool {
		return preg_match('/^[a-f0-9]{32}$/D', $snapshotId)===1
			&& preg_match('/^[a-f0-9]{64}$/D', $scopeHash)===1
			&& ReactorName::normalize($component)===$component && $component!==''
			&& $version>=0 && $expiresAt>0;
	}

	private static function validReservation(string $reservationId): bool {
		return preg_match('/^[a-f0-9]{32}$/D', $reservationId)===1;
	}
}
