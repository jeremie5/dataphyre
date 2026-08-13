<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Pull-driven SSE connection state with bounded replay, backpressure, and cancellation. */
final class PanelRealtimeStreamSession implements \JsonSerializable {
	private bool $closed=false;
	private bool $preambleSent=false;
	private int $startedAt;
	private int $lastEmissionAt;
	private ?string $closeReason=null;
	private ?\Closure $clock;
	private PanelRealtimeSseEncoder $encoder;
	private PanelRealtimeCancellationToken $operationCancellation;

	public function __construct(
		private readonly PanelRealtimeBroker $broker,
		private readonly PanelRealtimeIntentSigner $signer,
		private readonly PanelRealtimeSubscription $subscription,
		private int $cursor,
		private readonly PanelRealtimeStreamOptions $options,
		private readonly PanelRealtimeTelemetry $telemetry,
		private readonly ?PanelRealtimeCancellation $cancellation=null,
		?callable $clock=null
	){
		if($cursor<0){ throw new \InvalidArgumentException('Panel realtime session cursor cannot be negative.'); }
		$this->clock=$clock===null ? null : \Closure::fromCallable($clock);
		$this->startedAt=$this->lastEmissionAt=$this->now();
		$probe=$cancellation===null ? null : static fn(): bool=>$cancellation->isCancellationRequested();
		$this->operationCancellation=new PanelRealtimeCancellationToken($this->startedAt+$this->options->connectionSeconds(),$probe,$clock);
		$this->encoder=new PanelRealtimeSseEncoder();
	}

	/** Returns an immediately available frame batch, an empty string when idle, or null after close. */
	public function nextChunk(): ?string {
		if($this->closed){ return null; }
		$now=$this->now();
		if($this->externalCancelled()){
			$this->telemetry->increment('cancellations'); $this->finish('cancelled'); return null;
		}
		if($now-$this->startedAt>=$this->options->connectionSeconds()){
			$this->telemetry->increment('deadlines'); $this->finish('deadline'); return null;
		}
		$output='';
		if(!$this->preambleSent){ $output=$this->encoder->retry($this->options->retryMilliseconds()); $this->preambleSent=true; }
		try{
			$result=$this->broker->read($this->subscription,$this->cursor,$this->options->batchEvents(),$this->operationCancellation);
			$postReadNow=$this->now();
			if($postReadNow-$this->startedAt>=$this->options->connectionSeconds()){ $this->telemetry->increment('deadlines'); return $this->finishInterrupted($output,'deadline',$postReadNow); }
			if($this->externalCancelled()){ $this->telemetry->increment('cancellations'); return $this->finishInterrupted($output,'cancelled',$postReadNow); }
			if(count($result->events())>$this->options->batchEvents()){ return $this->finishWithBrokerError($output,'broker_contract_violation','Panel realtime broker exceeded the requested event count.'); }
			if($result->resetReason()!==null){ return $this->finishWithReset($output,$result->resetReason(),$result->head()); }
			if($result->lag()>$this->options->pendingEvents()){ return $this->finishWithReset($output,'slow_consumer',$result->head()); }
			$payloadBytes=0; $emitted=0; $truncated=false;
			foreach($result->events() as $event){
				if(!$this->subscription->accepts($event) || !hash_equals($this->subscription->channel(),$event->channel())){ return $this->finishWithBrokerError($output,'broker_scope_violation','Panel realtime broker returned an event outside the authorized subscription.'); }
				$resume=$this->signer->issueResume($this->subscription,$event->sequence(),$this->options->resumeTtlSeconds())->token();
				$frame=$this->encoder->event($event->type(),$event->jsonSerialize(),$resume); $frameBytes=strlen($frame);
				if($frameBytes>$this->options->batchBytes()){ return $this->finishWithReset($output,'event_too_large',$result->head()); }
				if($emitted>0 && $payloadBytes+$frameBytes>$this->options->batchBytes()){ $truncated=true; break; }
				$output.=$frame; $payloadBytes+=$frameBytes; $emitted++; $this->cursor=$event->sequence();
			}
			if(!$truncated && $result->cursor()>$this->cursor){
				$resume=$this->signer->issueResume($this->subscription,$result->cursor(),$this->options->resumeTtlSeconds())->token();
				$frame=$this->encoder->event('panel.cursor',['schema_version'=>1,'type'=>'panel.cursor','cursor_advanced'=>true],$resume);
				if($payloadBytes+strlen($frame)<=$this->options->batchBytes()){
					$output.=$frame; $payloadBytes+=strlen($frame); $this->cursor=$result->cursor(); $this->telemetry->increment('cursor_events_emitted');
				}
			}
			if($emitted>0){ $this->telemetry->increment('events_emitted',$emitted); }
			if($payloadBytes===0 && $now-$this->lastEmissionAt>=$this->options->heartbeatSeconds()){
				$output.=$this->encoder->heartbeat($now); $this->telemetry->increment('heartbeats_emitted');
			}
		}
		catch(PanelRealtimeException $exception){
			if($exception->publicCode()==='read_cancelled'){
				$interruptedAt=$this->now(); if($interruptedAt-$this->startedAt>=$this->options->connectionSeconds()){ $this->telemetry->increment('deadlines'); return $this->finishInterrupted($output,'deadline',$interruptedAt); }
				$this->telemetry->increment('cancellations'); return $this->finishInterrupted($output,'cancelled',$interruptedAt);
			}
			else{ $this->telemetry->increment('broker_failures'); $output.=$this->encoder->event('panel.error',['schema_version'=>1,'type'=>'panel.error','code'=>'stream_unavailable','message'=>'Panel realtime stream is unavailable.','retryable'=>true]); $this->finish('stream_unavailable'); }
		}
		catch(\Throwable){
			$this->telemetry->increment('broker_failures');
			$output.=$this->encoder->event('panel.error',['schema_version'=>1,'type'=>'panel.error','code'=>'stream_unavailable','message'=>'Panel realtime stream is unavailable.','retryable'=>true]);
			$this->finish('stream_unavailable');
		}
		if($output!==''){ $this->lastEmissionAt=$now; $this->telemetry->increment('bytes_emitted',strlen($output)); }
		return $output;
	}

	public function close(): void { $this->finish('host_closed'); }
	public function closed(): bool { return $this->closed; }
	public function cursor(): int { return $this->cursor; }
	public function closeReason(): ?string { return $this->closeReason; }
	public function jsonSerialize(): array { return ['type'=>'panel_realtime_stream_session','version'=>1,'subscription'=>$this->subscription,'cursor'=>$this->cursor,'closed'=>$this->closed,'close_reason'=>$this->closeReason,'started_at'=>$this->startedAt,'credential_exposed'=>false]; }

	private function finishWithReset(string $prefix, string $reason, int $head): string {
		$this->cursor=max(0,$head); $resume=$this->signer->issueResume($this->subscription,$this->cursor,$this->options->resumeTtlSeconds())->token();
		$frame=$this->encoder->event('panel.reset',['schema_version'=>1,'type'=>'panel.reset','reason'=>$reason,'action'=>'rehydrate','cursor_position'=>$this->cursor,'delivery'=>'at_least_once_across_reconnect'],$resume);
		$output=$prefix.$frame; $this->telemetry->reset($reason); $this->telemetry->increment('bytes_emitted',strlen($output)); $this->lastEmissionAt=$this->now(); $this->finish('reset_'.$reason);
		return $output;
	}
	private function finishWithBrokerError(string $prefix, string $code, string $message): string {
		$output=$prefix.$this->encoder->event('panel.error',['schema_version'=>1,'type'=>'panel.error','code'=>$code,'message'=>$message,'retryable'=>false]);
		$this->telemetry->increment('broker_failures'); $this->telemetry->increment('bytes_emitted',strlen($output)); $this->lastEmissionAt=$this->now(); $this->finish($code);
		return $output;
	}
	private function finishInterrupted(string $prefix, string $reason, int $at): string { $this->finish($reason); if($prefix!==''){ $this->lastEmissionAt=$at; $this->telemetry->increment('bytes_emitted',strlen($prefix)); } return $prefix; }
	private function finish(string $reason): void { if(!$this->closed){ $this->closed=true; $this->closeReason=$reason; } }
	private function externalCancelled(): bool { if($this->cancellation===null){ return false; } try{ return $this->cancellation->isCancellationRequested(); }catch(\Throwable){ return true; } }
	private function now(): int { $value=$this->clock===null ? time() : ($this->clock)(); if(!is_int($value) || $value<0){ throw new \UnexpectedValueException('Panel realtime session clock must return a non-negative integer timestamp.'); } return $value; }
}
