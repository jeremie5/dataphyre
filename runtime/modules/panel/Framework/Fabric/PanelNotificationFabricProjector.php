<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Projects explicit notification directives from signed events into an existing adapter. */
final class PanelNotificationFabricProjector implements \JsonSerializable {
	public function __construct(private readonly PanelNotificationAdapter $notifications){}

	public function __invoke(PanelEventEnvelope $event):void {
		$definition=$event->payload()['notification']??$event->metadata()['notification']??null;
		if($definition===null){return;}
		if(!is_array($definition)||($definition!==[]&&array_is_list($definition))){throw new \UnexpectedValueException('Fabric notification directives must be object-like maps.');}
		$safe=PanelSensitiveDataSanitizer::sanitize($definition,['max_depth'=>12,'max_items'=>256,'max_string_bytes'=>16384]);
		if(!is_array($safe)){throw new \UnexpectedValueException('Fabric notification directive is invalid.');}
		$recipient=$safe['recipient']??null;if($recipient!==null&&!is_string($recipient)){throw new \UnexpectedValueException('Fabric notification recipient is invalid.');}
		$deliver=($safe['deliver']??false)===true;$channels=$safe['channels']??null;
		if($channels!==null&&!is_string($channels)&&!is_array($channels)){throw new \UnexpectedValueException('Fabric notification channels are invalid.');}
		unset($safe['deliver']);
		$safe['id']=$safe['id']??'fabric_'.$event->id();$safe['created_at']=$safe['created_at']??$event->occurredAt();
		$meta=is_array($safe['meta']??null)?$safe['meta']:[];
		$meta=array_replace($meta,[
			'fabric_event_id'=>$event->id(),'fabric_event_type'=>$event->eventType(),'fabric_event_hash'=>$event->hash(),
			'fabric_tenant'=>$event->tenantId(),'delivery_semantics'=>'at_least_once',
		]);
		$safe['meta']=PanelOperationsGuard::safeMetadata($meta,256);
		$item=$this->notifications->store($safe,$recipient,$safe['meta']);
		if($deliver){$this->notifications->deliver($item,$channels);}
	}

	public function jsonSerialize():array{return [
		'type'=>'panel_notification_fabric_projector','version'=>1,'directive'=>'notification',
		'explicit_only'=>true,'deterministic_notification_ids'=>true,'delivery'=>'at_least_once','adapter'=>$this->notifications->manifest(),
	];}
}
