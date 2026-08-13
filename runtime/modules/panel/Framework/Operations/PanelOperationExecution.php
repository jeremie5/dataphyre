<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Cooperative execution context exposed to operation handlers. */
final class PanelOperationExecution {
	private ?PanelOperationLease $lease;

	public function __construct(private readonly PanelOperationStore $store, private readonly string $id, ?PanelOperationLease $lease=null) {
		if($lease!==null && (!$store instanceof PanelLeasedOperationStore || $lease->operationId()!==$id)){ throw new \InvalidArgumentException('Leased execution requires a matching leased operation store and proof.'); }
		$this->lease=$lease;
	}

	public static function leased(PanelLeasedOperationStore $store,PanelOperationReservation $reservation):self { return new self($store,$reservation->record()->id(),$reservation->lease()); }

	public function id(): string { return $this->id; }
	public function lease():?PanelOperationLease { return $this->lease; }
	public function requireLease():PanelOperationLease { return $this->lease ?? throw new PanelOperationLeaseLost($this->id,'Operation execution no longer owns a worker lease.'); }
	public function record(): PanelOperationRecord {
		if($this->lease!==null){ $reservation=$this->leasedStore()->inspectLease($this->lease); $this->lease=$reservation->lease(); return $reservation->record(); }
		return $this->store->get($this->id) ?? throw new \OutOfBoundsException("Panel operation '{$this->id}' no longer exists.");
	}

	public function heartbeat(): PanelOperationRecord {
		if($this->lease!==null){ $reservation=$this->leasedStore()->renewLease($this->lease); $this->lease=$reservation->lease(); $record=$reservation->record(); }
		else{ $record=$this->store->update($this->id, static fn(PanelOperationRecord $current): PanelOperationRecord=>$current->heartbeat()); }
		$this->throwIfInterrupted($record);
		return $record;
	}

	public function progress(int $processed, ?int $total=null, ?string $message=null, ?int $succeeded=null, ?int $failed=null): PanelOperationRecord {
		$at=$this->leasedTime(); $record=$this->mutate(static fn(PanelOperationRecord $current): PanelOperationRecord=>$current->progress($processed, $total, $message, $succeeded, $failed,$at));
		$this->throwIfInterrupted($record);
		return $record;
	}

	public function advance(int $units=1, bool $succeeded=true, ?string $message=null): PanelOperationRecord {
		$units=max(0, $units);
		$current=$this->record();
		return $this->progress(
			$current->processed()+$units,
			$current->total(),
			$message,
			$current->succeeded()+($succeeded ? $units : 0),
			$current->failed()+($succeeded ? 0 : $units)
		);
	}

	/** @param array<string, mixed> $state */
	public function checkpoint(string $name, array $state=[]): PanelOperationRecord {
		$at=$this->leasedTime(); $record=$this->mutate(static fn(PanelOperationRecord $current): PanelOperationRecord=>$current->checkpoint($name, $state,$at));
		$this->throwIfInterrupted($record);
		return $record;
	}

	/** @param array<string, mixed> $context */
	public function log(string $level, string $message, array $context=[]): PanelOperationRecord {
		$at=$this->leasedTime(); return $this->mutate(static fn(PanelOperationRecord $current): PanelOperationRecord=>$current->log($level, $message, $context,$at));
	}

	/** @param array<string, mixed> $metadata */
	public function artifact(string $name, string $location, string $mime='application/octet-stream', ?int $bytes=null, array $metadata=[]): PanelOperationRecord {
		$at=$this->leasedTime(); return $this->mutate(static fn(PanelOperationRecord $current): PanelOperationRecord=>$current->artifact($name, $location, $mime, $bytes, $metadata,$at));
	}

	public function cancellationRequested(): bool { return $this->record()->status()===PanelOperationStatus::CANCEL_REQUESTED; }
	public function pauseRequested(): bool { return $this->record()->status()===PanelOperationStatus::PAUSE_REQUESTED; }

	public function guard(): PanelOperationRecord {
		$record=$this->record();
		$this->throwIfInterrupted($record);
		return $record;
	}

	private function throwIfInterrupted(PanelOperationRecord $record): void {
		if($record->status()===PanelOperationStatus::CANCEL_REQUESTED){
			if($this->lease!==null){ $at=$this->leasedTime(); $this->leasedStore()->finishLease($this->lease,static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->cancel($at)); $this->lease=null; }
			else{ $this->store->update($this->id, static fn(PanelOperationRecord $current): PanelOperationRecord=>$current->cancel()); }
			throw new PanelOperationInterrupted(PanelOperationStatus::CANCELLED);
		}
		if($record->status()===PanelOperationStatus::PAUSE_REQUESTED){
			if($this->lease!==null){ $at=$this->leasedTime(); $this->leasedStore()->finishLease($this->lease,static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->markPaused($at)); $this->lease=null; }
			else{ $this->store->update($this->id, static fn(PanelOperationRecord $current): PanelOperationRecord=>$current->markPaused()); }
			throw new PanelOperationInterrupted(PanelOperationStatus::PAUSED);
		}
	}

	/** @param callable(PanelOperationRecord):PanelOperationRecord $mutator */
	private function mutate(callable $mutator):PanelOperationRecord {
		if($this->lease===null){ return $this->store->update($this->id,$mutator); }
		$reservation=$this->leasedStore()->mutateLease($this->lease,$mutator); $this->lease=$reservation->lease(); return $reservation->record();
	}

	private function leasedStore():PanelLeasedOperationStore { return $this->store instanceof PanelLeasedOperationStore ? $this->store : throw new \LogicException('Operation execution is not backed by a leased store.'); }
	private function leasedTime():mixed { return $this->lease!==null ? $this->leasedStore()->currentTime() : null; }
}
