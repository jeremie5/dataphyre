<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Deterministic in-memory activation adapter for tests and ephemeral hosts. */
final class PanelInMemoryDomainActivationStore implements PanelDomainActivationStore,\JsonSerializable {
	/** @var array<string,mixed> */private array $state;private int $cursor=0;/** @var list<array<string,mixed>> */private array $events=[];
	/** @param array<string,mixed>|null $state */public function __construct(?array $state=null,private readonly int $retention=512){if($retention<8||$retention>50000){throw new \InvalidArgumentException('Domain activation in-memory retention is invalid.');}$this->state=PanelDomainActivationState::validate($state??PanelDomainActivationState::initial());}
	public function payload():array{return$this->state;}
	public function transaction(callable $mutation,string $type,array $event=[]):array {$type=trim($type);if($type===''){throw new \InvalidArgumentException('Domain activation event type cannot be empty.');}$next=$this->state;$result=$mutation($next);$next=PanelDomainActivationState::validate($next);$this->state=$next;$change=array_replace(PanelOperationsGuard::safeMetadata($event,256),['cursor'=>++$this->cursor,'type'=>$type,'occurred_at'=>gmdate('c')]);$this->events[]=$change;if(count($this->events)>$this->retention){$this->events=array_slice($this->events,-$this->retention);}return['result'=>$result,'snapshot'=>['schema'=>'panel_domain_activation_state_v1','sequence'=>$this->cursor,'committed_at'=>$change['occurred_at'],'payload'=>$this->state,'event'=>$change]];}
	public function changesSince(int $cursor=0,int $limit=100):array {$cursor=max(0,$cursor);$limit=max(1,min(1000,$limit));$oldest=$this->events===[]?0:(int)$this->events[0]['cursor'];$reset=$cursor>0&&$oldest>0&&$cursor<$oldest-1;$changes=$reset?[]:array_slice(array_values(array_filter($this->events,static fn(array $event):bool=>(int)$event['cursor']>$cursor)),0,$limit);$next=$changes!==[]?(int)$changes[array_key_last($changes)]['cursor']:$this->cursor;return['cursor'=>$next,'oldest_cursor'=>$oldest,'reset_required'=>$reset,'changes'=>$changes,'snapshot'=>$reset?['payload'=>$this->state,'sequence'=>$this->cursor]:null];}
	public function jsonSerialize():array{return['type'=>'panel_in_memory_domain_activation_store','version'=>1,'revision'=>$this->state['revision'],'cursor'=>$this->cursor,'active_count'=>count($this->state['active']),'receipt_count'=>count($this->state['receipts']),'retention'=>$this->retention];}
}
