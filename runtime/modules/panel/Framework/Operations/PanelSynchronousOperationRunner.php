<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Reference synchronous runner for web requests, CLI commands, and local workers. */
final class PanelSynchronousOperationRunner implements PanelOperationRunner,PanelQueuedOperationRuntimeGraph {

	private PanelOperationQueue $queue;

	public function __construct(
		private readonly PanelOperationStore $store,
		private readonly PanelOperationHandlerRegistry $handlers,
		?PanelOperationQueue $queue=null
	){
		$this->queue=$queue ?? new PanelLocalOperationQueue($store);
	}

	public function store(): PanelOperationStore { return $this->store; }
	public function handlers(): PanelOperationHandlerRegistry { return $this->handlers; }
	public function queue(): PanelOperationQueue { return $this->queue; }

	public function submit(string $type, string $name='operation', mixed $payload=[], array $options=[]): PanelOperationRecord {
		$options['payload']=$payload;
		return $this->queue->enqueue(PanelOperationRecord::make($type, $name, $options));
	}

	public function run(string $id): PanelOperationRecord {
		$record=$this->store->get($id) ?? throw new \OutOfBoundsException("Panel operation '{$id}' does not exist.");
		if($record->terminal() || $record->status()===PanelOperationStatus::PAUSED){ return $record; }
		try{ $handler=$this->handlers->resolve($record->type()); }
		catch(\Throwable $error){ return $this->failUnresolvable($record, $error); }
		return $this->runWith($id, $handler);
	}

	public function runWith(string $id, callable $handler): PanelOperationRecord {
		$record=$this->store->get($id) ?? throw new \OutOfBoundsException("Panel operation '{$id}' does not exist.");
		if($record->terminal() || $record->status()===PanelOperationStatus::PAUSED){ return $record; }
		if($record->status()===PanelOperationStatus::CANCEL_REQUESTED){
			return $this->store->update($id, static fn(PanelOperationRecord $current): PanelOperationRecord=>$current->cancel());
		}
		if(in_array($record->status(), [PanelOperationStatus::QUEUED, PanelOperationStatus::RETRY_WAIT], true)){
			if(strcmp($record->availableAt(), gmdate(DATE_ATOM))>0){ return $record; }
			$record=$this->store->update($id, static fn(PanelOperationRecord $current): PanelOperationRecord=>$current->start('synchronous'));
		}
		if($record->status()!==PanelOperationStatus::RUNNING){
			throw new \LogicException("Panel operation '{$id}' is not executable from status '{$record->status()}'.");
		}
		$execution=new PanelOperationExecution($this->store, $id);
		$handler=\Closure::fromCallable($handler);
		try{
			$execution->guard();
			$result=$handler($record->payload(), $execution, $record);
			$execution->guard();
			$status=is_array($result) && ($result['status'] ?? null)===PanelOperationStatus::COMPLETED_WITH_FAILURES
				? PanelOperationStatus::COMPLETED_WITH_FAILURES
				: PanelOperationStatus::COMPLETED;
			return $this->store->update($id, static fn(PanelOperationRecord $current): PanelOperationRecord=>$current->complete($result, $status));
		}catch(PanelOperationInterrupted){
			return $this->store->get($id) ?? $record;
		}catch(\Throwable $error){
			$current=$this->store->update($id, static fn(PanelOperationRecord $state): PanelOperationRecord=>$state->log('error', $error->getMessage(), ['exception'=>$error::class, 'code'=>$error->getCode()]));
			if($current->status()===PanelOperationStatus::CANCEL_REQUESTED){ return $this->store->update($id, static fn(PanelOperationRecord $state): PanelOperationRecord=>$state->cancel()); }
			if($current->status()===PanelOperationStatus::PAUSE_REQUESTED){ return $this->store->update($id, static fn(PanelOperationRecord $state): PanelOperationRecord=>$state->markPaused()); }
			if($current->canRetry()){
				return $this->store->update($id, static fn(PanelOperationRecord $state): PanelOperationRecord=>$state->retry());
			}
			return $this->store->update($id, static fn(PanelOperationRecord $state): PanelOperationRecord=>$state->fail($error));
		}
	}

	public function work(?string $queue=null, int $maxJobs=1, string $worker='local'): array {
		$maxJobs=max(1, min(10000, $maxJobs));
		$completed=[];
		for($index=0; $index<$maxJobs; $index++){
			$record=$this->queue->reserve($queue, $worker);
			if($record===null){ break; }
			try{ $handler=$this->handlers->resolve($record->type()); }
			catch(\Throwable $error){ $completed[]=$this->failUnresolvable($record, $error); continue; }
			$completed[]=$this->runWith($record->id(), $handler);
		}
		return $completed;
	}

	private function failUnresolvable(PanelOperationRecord $record, \Throwable $error): PanelOperationRecord {
		if($record->status()===PanelOperationStatus::RETRY_WAIT && strcmp($record->availableAt(), gmdate(DATE_ATOM))>0){ return $record; }
		if(in_array($record->status(), [PanelOperationStatus::QUEUED, PanelOperationStatus::RETRY_WAIT], true)){
			$record=$this->store->update($record->id(), static fn(PanelOperationRecord $state): PanelOperationRecord=>$state->start('synchronous'));
		}
		if($record->status()===PanelOperationStatus::CANCEL_REQUESTED){ return $this->store->update($record->id(), static fn(PanelOperationRecord $state): PanelOperationRecord=>$state->cancel()); }
		if($record->status()===PanelOperationStatus::PAUSE_REQUESTED){ return $this->store->update($record->id(), static fn(PanelOperationRecord $state): PanelOperationRecord=>$state->markPaused()); }
		return $this->store->update($record->id(), static fn(PanelOperationRecord $state): PanelOperationRecord=>$state
			->log('critical', 'Operation handler could not be resolved.', ['exception'=>$error::class, 'message'=>$error->getMessage()])
			->fail($error));
	}
}
