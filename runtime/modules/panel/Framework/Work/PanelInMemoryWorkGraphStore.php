<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Deterministic optimistic store for tests and embedded work graphs. */
final class PanelInMemoryWorkGraphStore implements PanelWorkGraphStore,\JsonSerializable {
	/** @var array<string,array<string,mixed>> */ private array $states=[];
	/** @var array<string,list<array<string,mixed>>> */ private array $changes=[];

	public function read(string $tenantId):array {$tenantId=PanelOperationsGuard::identifier($tenantId,'work tenant id');$state=$this->states[$tenantId]??PanelWorkState::empty($tenantId);PanelWorkState::assert($state,$tenantId);return$this->copy($state);}

	public function transaction(string $tenantId,callable $mutation,string $type,array $event=[]):mixed {
		$tenantId=PanelOperationsGuard::identifier($tenantId,'work tenant id');$type=PanelOperationsGuard::name($type,'work store event type');$state=$this->read($tenantId);$working=$this->copy($state);$result=$mutation($working);PanelWorkState::assert($working,$tenantId);$this->states[$tenantId]=$this->copy($working);$cursor=count($this->changes[$tenantId]??[])+1;$this->changes[$tenantId][]=PanelOperationsGuard::canonical($event+['cursor'=>$cursor,'type'=>$type]);return$result;
	}

	public function changesSince(string $tenantId,int $cursor=0,int $limit=100):array {
		$tenantId=PanelOperationsGuard::identifier($tenantId,'work tenant id');$cursor=max(0,$cursor);$limit=max(1,min(1000,$limit));$all=$this->changes[$tenantId]??[];$changes=array_slice($all,$cursor,$limit);return['cursor'=>$changes!==[]?(int)$changes[array_key_last($changes)]['cursor']:count($all),'reset_required'=>false,'changes'=>$this->copy($changes),'snapshot'=>null];
	}

	/** @param array<string,mixed> $state */ public function seed(string $tenantId,array $state):void {$tenantId=PanelOperationsGuard::identifier($tenantId,'work tenant id');PanelWorkState::assert($state,$tenantId);$this->states[$tenantId]=$this->copy($state);}
	public function jsonSerialize():array{return['type'=>'panel_in_memory_work_graph_store','tenant_count'=>count($this->states),'atomic_process_local_transactions'=>true,'change_feed'=>true];}
	private function copy(mixed $value):mixed{return unserialize(serialize($value),['allowed_classes'=>false]);}
}
