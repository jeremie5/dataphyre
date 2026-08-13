<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Canonical persisted work-graph state shape. */
final class PanelWorkState {
	private function __construct() {}

	/** @return array<string,mixed> */
	public static function empty(string $tenantId):array {
		$tenantId=PanelOperationsGuard::identifier($tenantId,'work tenant id');
		return['schema'=>'panel_work_graph_state','version'=>1,'tenant_id'=>$tenantId,'sequence'=>0,'anchor_hash'=>str_repeat('0',64),'items'=>[],'events'=>[],'edges'=>[],'receipts'=>[],'receipt_order'=>[]];
	}

	/** @param array<string,mixed> $state */
	public static function assert(array $state,string $tenantId):void {
		$expected=['schema','version','tenant_id','sequence','anchor_hash','items','events','edges','receipts','receipt_order'];$keys=array_keys($state);sort($keys,SORT_STRING);sort($expected,SORT_STRING);
		if($keys!==$expected||$state['schema']!=='panel_work_graph_state'||$state['version']!==1||$state['tenant_id']!==$tenantId||!is_int($state['sequence'])||$state['sequence']<0||!is_string($state['anchor_hash'])||preg_match('/^[a-f0-9]{64}$/D',$state['anchor_hash'])!==1){throw new \UnexpectedValueException('Work graph state envelope is invalid.');}
		foreach(['items','events','edges','receipts']as$key){if(!is_array($state[$key])||($state[$key]!==[]&&array_is_list($state[$key]))){throw new \UnexpectedValueException('Work graph state maps are invalid.');}}
		if(!is_array($state['receipt_order'])||($state['receipt_order']!==[]&&!array_is_list($state['receipt_order']))){throw new \UnexpectedValueException('Work graph receipt order is invalid.');}
		foreach($state['items']as$id=>$payload){if(!is_string($id)||!is_array($payload)||PanelWorkItem::restore($payload)->id()!==$id){throw new \UnexpectedValueException('Work graph item state is invalid.');}}
		$previous=$state['anchor_hash'];$last=0;foreach($state['events']as$id=>$payload){if(!is_string($id)||!is_array($payload)){throw new \UnexpectedValueException('Work graph event state is invalid.');}$event=PanelWorkEvent::restore($payload);if($event->id()!==$id||$event->sequence()<=$last||!hash_equals($previous,$event->previousHash())||!$event->verify()){throw new \UnexpectedValueException('Work graph audit chain is invalid.');}$last=$event->sequence();$previous=$event->hash();}
		if($last>$state['sequence']){throw new \UnexpectedValueException('Work graph sequence is behind its audit chain.');}
	}
}
