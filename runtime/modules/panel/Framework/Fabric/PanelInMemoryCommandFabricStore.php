<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

final class PanelInMemoryCommandFabricStore implements PanelCommandFabricStore,\JsonSerializable {
	/** @var array<string,mixed> */private array $state;private int $cursor=0;/** @var list<array<string,mixed>> */private array $changes=[];
	/** @param array<string,mixed>|null $state */public function __construct(?array $state=null,private readonly int $retention=1024){if($retention<8||$retention>100000){throw new \InvalidArgumentException('Command fabric in-memory retention is invalid.');}$this->state=PanelCommandFabricState::validate($state??PanelCommandFabricState::initial());}
	public function payload():array{return$this->state;}public function transaction(callable $mutation,string $type,array $event=[]):array {$next=$this->state;$result=$mutation($next);$next=PanelCommandFabricState::validate($next);$this->state=$next;$change=array_replace(PanelOperationsGuard::safeMetadata($event,256),['cursor'=>++$this->cursor,'type'=>PanelOperationsGuard::name($type,'command fabric change type',160),'occurred_at'=>gmdate('c')]);$this->changes[]=$change;if(count($this->changes)>$this->retention){$this->changes=array_slice($this->changes,-$this->retention);}return['result'=>$result,'snapshot'=>['sequence'=>$this->cursor,'payload'=>$this->state,'event'=>$change]];}public function changesSince(int $cursor=0,int $limit=100):array {$oldest=$this->changes===[]?0:(int)$this->changes[0]['cursor'];$reset=$cursor>0&&$oldest>0&&$cursor<$oldest-1;$changes=$reset?[]:array_slice(array_values(array_filter($this->changes,static fn(array $item):bool=>(int)$item['cursor']>$cursor)),0,max(1,min(1000,$limit)));return['cursor'=>$changes!==[]?(int)$changes[array_key_last($changes)]['cursor']:$this->cursor,'oldest_cursor'=>$oldest,'reset_required'=>$reset,'changes'=>$changes,'snapshot'=>$reset?['payload'=>$this->state,'sequence'=>$this->cursor]:null];}public function jsonSerialize():array{return['type'=>'panel_in_memory_command_fabric_store','version'=>1,'revision'=>$this->state['revision'],'sequence'=>$this->state['sequence'],'cursor'=>$this->cursor];}
}
