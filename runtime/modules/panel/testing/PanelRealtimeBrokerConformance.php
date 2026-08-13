<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel\Testing;

use Dataphyre\Panel\PanelRealtimeBroker;
use Dataphyre\Panel\PanelRealtimeContext;
use Dataphyre\Panel\PanelRealtimePublisher;
use Dataphyre\Panel\PanelRealtimeSubscription;

/** Reusable minimum ordering, filtering, cursor, and tenant-isolation checks for writable adapters. */
final class PanelRealtimeBrokerConformance {
	/**
	 * The broker must be fresh. The report contains stable violations rather than
	 * throwing so adapters can integrate it into any test framework.
	 *
	 * @return array{type:string,version:int,passed:bool,checks:int,violations:list<string>,observations:array<string,mixed>}
	 */
	public static function verify(PanelRealtimeBroker&PanelRealtimePublisher $broker, PanelRealtimeContext $context, PanelRealtimeContext $otherTenant): array {
		$violations=[]; $checks=0;
		$check=static function(bool $condition, string $code) use (&$violations,&$checks): void { $checks++; if(!$condition){ $violations[]=$code; } };
		$orders=PanelRealtimeSubscription::fromTrusted($context,'orders',['orders.created','orders.updated']);
		$wildcard=PanelRealtimeSubscription::fromTrusted($context,'orders',['*']);
		$paid=PanelRealtimeSubscription::fromTrusted($context,'orders',['*'],['status'=>'paid']);
		$foreign=PanelRealtimeSubscription::fromTrusted($otherTenant,'orders',['*']);
		$one=$broker->publish($context,'orders','orders.created','orders.created',['id'=>1],['status'=>'paid']);
		$two=$broker->publish($context,'orders','audit.logged','audit.logged',['id'=>2],['status'=>'internal']);
		$three=$broker->publish($context,'orders','orders.updated','orders.updated',['id'=>1],['status'=>'paid']);
		$first=$broker->read($orders,0,2); $second=$broker->read($orders,$first->cursor(),2); $all=$broker->read($wildcard,0,10); $filtered=$broker->read($paid,0,10); $isolated=$broker->read($foreign,0,10);
		$check([$one->sequence(),$two->sequence(),$three->sequence()] === [1,2,3], 'publish_sequence');
		$check(array_map(static fn($event): int=>$event->sequence(),$first->events()) === [1], 'topic_filter_first_page');
		$check($first->cursor()===2 && $first->hasMore(), 'scanned_cursor_progress');
		$check(array_map(static fn($event): int=>$event->sequence(),$second->events()) === [3], 'topic_filter_second_page');
		$check($second->cursor()===3 && !$second->hasMore(), 'cursor_reaches_head');
		$check(count($all->events())===3, 'wildcard_topics');
		$check(array_map(static fn($event): int=>$event->sequence(),$filtered->events()) === [1,3], 'metadata_filter');
		$check($isolated->events()===[] && $isolated->head()===0, 'tenant_isolation');
		return ['type'=>'panel_realtime_broker_conformance','version'=>1,'passed'=>$violations===[],'checks'=>$checks,'violations'=>$violations,'observations'=>['head'=>$second->head(),'wildcard_events'=>count($all->events()),'filtered_events'=>count($filtered->events()),'foreign_events'=>count($isolated->events())]];
	}
}
