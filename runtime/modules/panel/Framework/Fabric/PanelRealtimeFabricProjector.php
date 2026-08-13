<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Projects signed fabric events into an existing realtime publisher. */
final class PanelRealtimeFabricProjector implements \JsonSerializable {
	public function __construct(private readonly PanelRealtimePublisher $publisher,private readonly string $panel='panel'){
		PanelOperationsGuard::identifier($panel,'fabric realtime panel',96);
	}

	public function __invoke(PanelEventEnvelope $event):void {
		$context=PanelRealtimeContext::fromTrusted($this->panel,[
			'tenant_id'=>$event->tenantId(),'actor_id'=>$event->actorId(),'correlation_id'=>$event->correlationId()??'',
		]);
		$this->publisher->publish(
			$context,'fabric',$event->aggregateType(),$event->eventType(),
			[
				'id'=>$event->id(),'sequence'=>$event->sequence(),'aggregate_type'=>$event->aggregateType(),
				'aggregate_id'=>$event->aggregateId(),'payload'=>$event->payload(),'occurred_at'=>$event->occurredAt(),
			],
			['fabric_event_hash'=>$event->hash(),'command_fingerprint'=>$event->commandFingerprint()],$event->occurredAt(),
		);
	}

	public function jsonSerialize():array{return ['type'=>'panel_realtime_fabric_projector','version'=>1,'panel'=>$this->panel,'delivery'=>'at_least_once','publisher'=>$this->publisher];}
}
