<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Discovers process variants, transitions, bottlenecks, and conformance from work events. */
final class PanelProcessIntelligence implements \JsonSerializable {
	/** @param list<PanelWorkEvent> $events @param list<array{from:string,to:string}> $expectedTransitions @return array<string,mixed> */
	public function analyze(array $events,array $expectedTransitions=[]):array {$cases=[];foreach($events as$event){if(!$event instanceof PanelWorkEvent){throw new \InvalidArgumentException('Process intelligence requires PanelWorkEvent values.');}$cases[$event->itemId()][]=$event;}ksort($cases,SORT_STRING);$variants=[];$transitions=[];$durations=[];$violations=[];$allowed=[];foreach($expectedTransitions as$transition){if(!is_array($transition)||!isset($transition['from'],$transition['to'])){throw new \InvalidArgumentException('Expected process transitions are invalid.');}$allowed[(string)$transition['from'].'>'.(string)$transition['to']]=true;}
		foreach($cases as$itemId=>$caseEvents){usort($caseEvents,static fn(PanelWorkEvent $a,PanelWorkEvent $b):int=>$a->sequence()<=>$b->sequence());$operations=array_map(static fn(PanelWorkEvent $event):string=>$event->operation(),$caseEvents);$variant=implode(' > ',$operations);$variants[$variant]=($variants[$variant]??0)+1;for($index=1;$index<count($caseEvents);$index++){$from=$caseEvents[$index-1];$to=$caseEvents[$index];$key=$from->operation().'>'.$to->operation();$seconds=max(0.0,self::epochMicros($to->occurredAt())-self::epochMicros($from->occurredAt()));$transitions[$key]=($transitions[$key]??0)+1;$durations[$key][]=$seconds;if($allowed!==[]&&!isset($allowed[$key])){$violations[]=['item_id'=>$itemId,'from'=>$from->operation(),'to'=>$to->operation(),'sequence'=>$to->sequence()];}}}
		arsort($variants,SORT_NUMERIC);ksort($transitions,SORT_STRING);$bottlenecks=[];foreach($durations as$key=>$values){sort($values,SORT_NUMERIC);$count=count($values);$bottlenecks[]=['transition'=>$key,'count'=>$count,'average_seconds'=>array_sum($values)/$count,'p95_seconds'=>$values[max(0,(int)ceil($count*.95)-1)],'maximum_seconds'=>max($values)];}usort($bottlenecks,static fn(array $a,array $b):int=>[$b['average_seconds'],$b['count'],$a['transition']]<=>[$a['average_seconds'],$a['count'],$b['transition']]);return PanelManifestContract::stamp(['type'=>'panel_process_intelligence_report_manifest','version'=>1,'case_count'=>count($cases),'event_count'=>count($events),'variant_count'=>count($variants),'variants'=>$variants,'transitions'=>$transitions,'bottlenecks'=>$bottlenecks,'conformance'=>['expected_transition_count'=>count($allowed),'violation_count'=>count($violations),'violations'=>$violations],'fingerprint'=>PanelOperationsGuard::digest(array_map(static fn(PanelWorkEvent $event):string=>$event->hash(),$events))]);}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_process_intelligence_manifest','version'=>1,'capabilities'=>['variant_discovery'=>true,'transition_frequency'=>true,'cycle_visibility'=>true,'bottleneck_duration'=>true,'p95_duration'=>true,'conformance_checking'=>true,'work_graph_native'=>true]]);}
	private static function epochMicros(string $instant):float {return(float)(new \DateTimeImmutable($instant))->format('U.u');}
}
