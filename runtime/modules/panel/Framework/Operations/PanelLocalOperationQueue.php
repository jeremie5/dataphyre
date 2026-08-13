<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Store-backed local queue suitable for CLI workers and synchronous application dispatch. */
final class PanelLocalOperationQueue implements PanelOperationQueue,PanelOperationQueueGraph {

	public function __construct(private readonly PanelOperationStore $store) {}
	public function store():PanelOperationStore{return$this->store;}

	public function enqueue(PanelOperationRecord $record): PanelOperationRecord {
		return $this->store->create($record);
	}

	public function reserve(?string $queue=null, string $worker='local'): ?PanelOperationRecord {
		$criteria=['status'=>[PanelOperationStatus::QUEUED, PanelOperationStatus::RETRY_WAIT]];
		if($queue!==null && trim($queue)!==''){ $criteria['queue']=$this->queueName($queue); }
		$now=gmdate(DATE_ATOM);
		foreach($this->store->all($criteria, 1000) as $candidate){
			if(strcmp($candidate->availableAt(), $now)>0){ continue; }
			try{
				return $this->store->update($candidate->id(), static fn(PanelOperationRecord $current): PanelOperationRecord=>$current->start($worker, $now), $candidate->revision());
			}catch(PanelOperationConflict|\LogicException){
				continue;
			}
		}
		return null;
	}

	public function release(PanelOperationRecord $record, ?int $delaySeconds=null): PanelOperationRecord {
		return $this->store->update($record->id(), static fn(PanelOperationRecord $current): PanelOperationRecord=>$current->retry($delaySeconds));
	}

	public function acknowledge(PanelOperationRecord $record): PanelOperationRecord {
		return $this->store->get($record->id()) ?? $record;
	}

	public function size(?string $queue=null): int {
		$criteria=['status'=>[PanelOperationStatus::QUEUED, PanelOperationStatus::RETRY_WAIT]];
		if($queue!==null && trim($queue)!==''){ $criteria['queue']=$this->queueName($queue); }
		return count($this->store->all($criteria, 10000));
	}

	private function queueName(string $queue): string {
		$queue=strtolower(trim($queue));
		$queue=preg_replace('/[^a-z0-9]+/', '_', $queue) ?? '';
		return trim($queue, '_') ?: 'default';
	}
}
