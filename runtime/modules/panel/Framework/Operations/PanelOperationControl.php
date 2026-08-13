<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Operator-facing lifecycle controls and stale-worker recovery. */
final class PanelOperationControl {

	public function __construct(private readonly PanelOperationStore $store) {}
	public function store():PanelOperationStore{return$this->store;}

	public function pause(string $id): PanelOperationRecord {
		$at=$this->currentTime(); return $this->store->update($id, static fn(PanelOperationRecord $record): PanelOperationRecord=>$record->requestPause($at));
	}

	public function resume(string $id): PanelOperationRecord {
		$at=$this->currentTime(); return $this->store->update($id, static fn(PanelOperationRecord $record): PanelOperationRecord=>$record->resume($at));
	}

	public function cancel(string $id): PanelOperationRecord {
		$at=$this->currentTime(); return $this->store->update($id, static fn(PanelOperationRecord $record): PanelOperationRecord=>$record->requestCancel($at));
	}

	public function retry(string $id, int $delaySeconds=0): PanelOperationRecord {
		$at=$this->currentTime(); return $this->store->update($id, static function(PanelOperationRecord $record)use($delaySeconds,$at): PanelOperationRecord {
			if($record->status()===PanelOperationStatus::FAILED){ return $record->manualRetry($at); }
			return $record->retry($delaySeconds,$at);
		});
	}

	/** @return list<PanelOperationRecord> */
	public function recoverStale(int $heartbeatTimeoutSeconds=300): array {
		if($this->store instanceof PanelLeasedOperationStore){ return $this->store->recoverExpiredLeases(10000); }
		$heartbeatTimeoutSeconds=max(1, $heartbeatTimeoutSeconds);
		$cutoff=time()-$heartbeatTimeoutSeconds;
		$recovered=[];
		foreach($this->store->all(['status'=>[PanelOperationStatus::RUNNING, PanelOperationStatus::PAUSE_REQUESTED, PanelOperationStatus::CANCEL_REQUESTED]], 10000) as $record){
			$heartbeat=$record->heartbeatAt();
			if($heartbeat!==null && strtotime($heartbeat)>$cutoff){ continue; }
			$recovered[]=$this->store->update($record->id(), static function(PanelOperationRecord $current): PanelOperationRecord {
				if($current->status()===PanelOperationStatus::CANCEL_REQUESTED){ return $current->cancel()->log('warning', 'Stale worker cancellation finalized.'); }
				if($current->status()===PanelOperationStatus::PAUSE_REQUESTED){ return $current->markPaused()->log('warning', 'Stale worker pause finalized.'); }
				if($current->canRetry()){ return $current->log('warning', 'Stale worker lease recovered.')->retry(0); }
				return $current->fail('Worker heartbeat expired and no retry attempts remain.');
			}, $record->revision());
		}
		return $recovered;
	}

	private function currentTime():mixed { return $this->store instanceof PanelLeasedOperationStore ? $this->store->currentTime() : null; }
}
