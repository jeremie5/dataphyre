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
 * Process-local reference broker with bounded streams and retained replay.
 * It is deliberately not durable, distributed, or an exactly-once transport.
 */
final class PanelInMemoryRealtimeBroker implements PanelRealtimeBroker, PanelRealtimePublisher {
	/** @var array<string,array{head:int,events:list<PanelRealtimeEvent>}> */ private array $streams=[];
	private ?\Closure $clock;

	public function __construct(private readonly int $retainedEvents=1024, private readonly int $maximumStreams=256, private readonly int $maximumEventBytes=196608, ?callable $clock=null){
		if($retainedEvents<1 || $retainedEvents>100000 || $maximumStreams<1 || $maximumStreams>10000 || $maximumEventBytes<1024 || $maximumEventBytes>1048576){ throw new \InvalidArgumentException('Panel in-memory realtime broker bounds are invalid.'); }
		$this->clock=$clock===null ? null : \Closure::fromCallable($clock);
	}

	public function publish(PanelRealtimeContext $context, string $channel, string $topic, string $type, mixed $payload, array $metadata=[], ?string $occurredAt=null): PanelRealtimeEvent {
		$channel=PanelRealtimeGuard::identifier($channel, 'channel', 96); $topic=PanelRealtimeGuard::identifier($topic, 'topic', 96); $type=PanelRealtimeGuard::identifier($type, 'event type', 96);
		$streamKey=$context->streamKey($channel);
		if(!isset($this->streams[$streamKey])){
			if(count($this->streams)>=$this->maximumStreams){ throw new PanelRealtimeException('broker_capacity', 503, 'Panel realtime broker capacity is exhausted.', true); }
			$this->streams[$streamKey]=['head'=>0,'events'=>[]];
		}
		$sequence=$this->streams[$streamKey]['head']+1;
		$occurredAt=$occurredAt ?? gmdate('Y-m-d\TH:i:s\Z', $this->now());
		$event=new PanelRealtimeEvent($sequence,$streamKey,$channel,$topic,$type,$occurredAt,$payload,$metadata);
		if($event->wireBytes()>$this->maximumEventBytes){ throw new PanelRealtimeException('event_too_large', 422, 'Panel realtime event exceeds the broker byte bound.'); }
		$this->streams[$streamKey]['head']=$sequence; $this->streams[$streamKey]['events'][]=$event;
		if(count($this->streams[$streamKey]['events'])>$this->retainedEvents){ array_shift($this->streams[$streamKey]['events']); }
		return $event;
	}

	public function read(PanelRealtimeSubscription $subscription, int $afterSequence, int $limit, ?PanelRealtimeCancellation $cancellation=null): PanelRealtimeReadResult {
		if($afterSequence<0 || $limit<1 || $limit>1000){ throw new \InvalidArgumentException('Panel realtime broker read bounds are invalid.'); }
		if($cancellation?->isCancellationRequested()){ throw new PanelRealtimeException('read_cancelled',408,'Panel realtime broker read was cancelled.'); }
		$stream=$this->streams[$subscription->streamKey()] ?? null;
		if($stream===null){ return $afterSequence===0 ? new PanelRealtimeReadResult(0,[],0,0,1) : new PanelRealtimeReadResult($afterSequence,[],0,0,1,false,'source_reset'); }
		$head=$stream['head']; $earliest=$stream['events']===[] ? $head+1 : $stream['events'][0]->sequence();
		if($afterSequence>$head){ return new PanelRealtimeReadResult($afterSequence,[],$head,$head,$earliest,false,'source_reset'); }
		if($afterSequence<$earliest-1){ return new PanelRealtimeReadResult($afterSequence,[],$head,$head,$earliest,false,'retention_gap'); }
		$events=[]; $cursor=$afterSequence; $scanned=0;
		foreach($stream['events'] as $event){
			if($cancellation?->isCancellationRequested()){ throw new PanelRealtimeException('read_cancelled',408,'Panel realtime broker read was cancelled.'); }
			if($event->sequence()<=$afterSequence){ continue; }
			$cursor=$event->sequence(); $scanned++;
			if($subscription->accepts($event)){ $events[]=$event; }
			if($scanned>=$limit){ break; }
		}
		return new PanelRealtimeReadResult($afterSequence,$events,$cursor,$head,$earliest,$cursor<$head);
	}

	public function jsonSerialize(): array { return ['type'=>'panel_realtime_broker','version'=>1,'adapter'=>'memory','durable'=>false,'distributed'=>false,'cross_process'=>false,'retained_events_per_stream'=>$this->retainedEvents,'maximum_streams'=>$this->maximumStreams,'maximum_event_bytes'=>$this->maximumEventBytes,'active_streams'=>count($this->streams),'ordered_per_stream'=>true,'replay'=>true,'retention_gap_detection'=>true,'delivery'=>'at_least_once_across_reconnect','exactly_once'=>false]; }
	private function now(): int { $value=$this->clock===null ? time() : ($this->clock)(); if(!is_int($value) || $value<0){ throw new \UnexpectedValueException('Panel realtime broker clock must return a non-negative integer timestamp.'); } return $value; }
}
