<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** At-least-once worker runner whose every handler mutation is lease-fenced. */
final class PanelLeasedOperationRunner implements PanelOperationRunner,PanelOperationRuntimeGraph {
	private int $ttlSeconds;

	public function __construct(private readonly PanelLeasedOperationStore $store,private readonly PanelOperationHandlerRegistry $handlers,int $ttlSeconds=60){ $this->ttlSeconds=max(5,min(3600,$ttlSeconds)); }
	public function store():PanelLeasedOperationStore { return $this->store; }
	public function handlers():PanelOperationHandlerRegistry { return $this->handlers; }
	public function ttlSeconds():int { return $this->ttlSeconds; }

	public function submit(string $type,string $name='operation',mixed $payload=[],array $options=[]):PanelOperationRecord { $options['payload']=$payload; return $this->store->create(PanelOperationRecord::make($type,$name,$options)); }

	public function run(string $id):PanelOperationRecord {
		$this->store->recoverExpiredLeases(10000);
		$current=$this->store->get($id) ?? throw new \OutOfBoundsException("Panel operation '{$id}' does not exist.");
		if($current->terminal() || $current->status()===PanelOperationStatus::PAUSED || PanelOperationStatus::active($current->status())){ return $current; }
		$reservation=$this->store->acquireLease($id,'direct',$this->ttlSeconds); return $reservation===null ? ($this->store->get($id) ?? $current) : $this->runReservation($reservation);
	}

	public function work(?string $queue=null,int $maxJobs=1,string $worker='worker'):array {
		$maxJobs=max(1,min(10000,$maxJobs)); $completed=[];
		for($index=0;$index<$maxJobs;$index++){ $reservation=$this->store->reserveLease($queue,$worker,$this->ttlSeconds); if($reservation===null){ break; } $completed[]=$this->runReservation($reservation); }
		return $completed;
	}

	public function runReservation(PanelOperationReservation $reservation):PanelOperationRecord {
		try{ $handler=$this->handlers->resolve($reservation->record()->type()); }
		catch(\Throwable $error){ return $this->failUnresolvable($reservation,$error); }
		$execution=PanelOperationExecution::leased($this->store,$reservation);
		try{
			$record=$execution->guard(); $result=$handler($record->payload(),$execution,$record); $execution->guard();
			$status=is_array($result) && ($result['status']??null)===PanelOperationStatus::COMPLETED_WITH_FAILURES ? PanelOperationStatus::COMPLETED_WITH_FAILURES : PanelOperationStatus::COMPLETED;
			$now=$this->store->currentTime(); return $this->store->finishLease($execution->requireLease(),static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->complete($result,$status,$now));
		}catch(PanelOperationInterrupted){ return $this->store->get($reservation->record()->id()) ?? $reservation->record(); }
		catch(PanelOperationLeaseLost){ return $this->store->get($reservation->record()->id()) ?? $reservation->record(); }
		catch(\Throwable $error){
			try{
				$lease=$execution->requireLease(); $now=$this->store->currentTime(); [$message,$context,$failure]=$this->safeFailure($error);
				return $this->store->finishLease($lease,static function(PanelOperationRecord $current)use($message,$context,$failure,$now):PanelOperationRecord{
					$current=$current->log('error',$message,$context,$now);
					if($current->status()===PanelOperationStatus::CANCEL_REQUESTED){ return $current->cancel($now); }
					if($current->status()===PanelOperationStatus::PAUSE_REQUESTED){ return $current->markPaused($now); }
					return $current->canRetry() ? $current->retry(null,$now) : $current->fail($failure,$now);
				});
			}catch(PanelOperationLeaseLost){ return $this->store->get($reservation->record()->id()) ?? $reservation->record(); }
		}
	}

	private function failUnresolvable(PanelOperationReservation $reservation,\Throwable $error):PanelOperationRecord {
		$now=$this->store->currentTime(); [, $context,$failure]=$this->safeFailure($error);
		try{ return $this->store->finishLease($reservation->lease(),static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->log('critical','Operation handler could not be resolved.',$context,$now)->fail($failure,$now)); }
		catch(PanelOperationLeaseLost){ return $this->store->get($reservation->record()->id()) ?? $reservation->record(); }
	}

	/** @return array{0:string,1:array<string,mixed>,2:\RuntimeException} */
	private function safeFailure(\Throwable $error):array {
		$safe=PanelSensitiveDataSanitizer::sanitize(['message'=>$error->getMessage(),'exception'=>$error::class,'code'=>$error->getCode()],['max_depth'=>4,'max_items'=>20,'max_string_bytes'=>1000]);
		$message=is_array($safe) && is_string($safe['message']??null) && trim($safe['message'])!=='' ? $safe['message'] : 'Operation handler failed.';
		$context=is_array($safe) ? $safe : ['message'=>$message]; $code=is_int($error->getCode()) ? $error->getCode() : 0;
		return [$message,$context,new \RuntimeException($message,$code)];
	}
}
