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
 * Read-only bridge for an existing Panel change feed that the host has already
 * scoped to one tenant. Generic Panel data subscriptions do not expose a
 * retention head, so this adapter cannot claim gap detection.
 */
final class PanelDataSubscriptionRealtimeBroker implements PanelRealtimeBroker {
	private ?\Closure $projector;
	private function __construct(private readonly PanelSubscribableDataSource $source, private readonly PanelRealtimeContext $context, private readonly string $channel, ?callable $projector){ $this->projector=$projector===null?null:\Closure::fromCallable($projector); }

	/** @param null|callable(PanelDataChange,PanelRealtimeContext):(null|array{payload:mixed,metadata?:array<string,mixed>}) $projector */
	public static function fromTrustedTenantSource(PanelSubscribableDataSource $source, PanelRealtimeContext $context, string $channel, ?callable $projector=null): self {
		return new self($source,$context,PanelRealtimeGuard::identifier($channel, 'channel', 96),$projector);
	}

	public function read(PanelRealtimeSubscription $subscription, int $afterSequence, int $limit, ?PanelRealtimeCancellation $cancellation=null): PanelRealtimeReadResult {
		if($afterSequence<0 || $limit<1 || $limit>1000){ throw new \InvalidArgumentException('Panel data subscription realtime read bounds are invalid.'); }
		if($this->projector===null){ throw new PanelRealtimeException('projection_required',503,'Panel realtime data projection is required.'); }
		if($cancellation?->isCancellationRequested()){ throw new PanelRealtimeException('read_cancelled',408,'Panel data subscription realtime read was cancelled.'); }
		if(!$subscription->belongsTo($this->context) || !hash_equals($subscription->streamKey(), $this->context->streamKey($this->channel))){ throw new PanelRealtimeException('subscription_scope_invalid', 403, 'Panel realtime subscription scope is invalid.'); }
		$sourceSubscription=$this->source->subscribe($afterSequence);
		try{ $changes=$sourceSubscription->poll($limit); $cursor=$sourceSubscription->cursor(); if(count($changes)>$limit){ throw new PanelRealtimeException('broker_contract_violation',502,'Panel realtime data source exceeded the requested read bound.'); } if($cancellation?->isCancellationRequested()){ throw new PanelRealtimeException('read_cancelled',408,'Panel data subscription realtime read was cancelled.'); } }
		finally{ $sourceSubscription->close(); }
		$events=[];
		foreach($changes as $change){
			if(!$change instanceof PanelDataChange){ throw new \UnexpectedValueException('Panel data subscription returned an invalid change entry.'); }
			$wire=$change->jsonSerialize(); $operation=PanelRealtimeGuard::identifier((string)$wire['operation'], 'data change operation', 32);
			try{ $projected=($this->projector)($change,$this->context); }
			catch(\Throwable){ throw new PanelRealtimeException('projection_unavailable',503,'Panel realtime data projection is unavailable.',true); }
			if($projected===null){ continue; }
			if(!is_array($projected) || array_is_list($projected) || !array_key_exists('payload',$projected) || array_diff(array_keys($projected),['payload','metadata'])!==[] || (array_key_exists('metadata',$projected) && (!is_array($projected['metadata']) || ($projected['metadata']!==[] && array_is_list($projected['metadata']))))){ throw new PanelRealtimeException('projection_invalid',500,'Panel realtime data projection returned an invalid envelope.'); }
			$event=new PanelRealtimeEvent($change->sequence(),$subscription->streamKey(),$this->channel,'data.'.$operation,'data.'.$operation,(string)$wire['occurred_at'],$projected['payload'],$projected['metadata'] ?? []);
			if($subscription->accepts($event)){ $events[]=$event; }
		}
		return new PanelRealtimeReadResult($afterSequence,$events,$cursor,$cursor,1,false);
	}

	public function jsonSerialize(): array { return ['type'=>'panel_realtime_broker','version'=>1,'adapter'=>'panel_data_subscription','read_only'=>true,'host_tenant_scope_required'=>true,'principal_projection_required'=>true,'field_projection_required'=>true,'projection_configured'=>$this->projector!==null,'durable'=>'source_defined','distributed'=>'source_defined','ordered_per_stream'=>true,'replay'=>'source_defined','retention_gap_detection'=>false,'interruptible_read'=>'source_defined','delivery'=>'at_least_once_across_reconnect','exactly_once'=>false,'channel'=>$this->channel,'scope_values_exposed'=>false]; }
}
