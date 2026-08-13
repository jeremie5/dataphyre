<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Crash-safe and process-safe work graph persistence using immutable snapshots. */
final class PanelFilesystemWorkGraphStore implements PanelWorkGraphStore,\JsonSerializable {
	/** @var array<string,PanelAtomicSnapshotStore> */ private array $stores=[];
	private readonly string $root;

	public function __construct(string $root,private readonly int $retention=2048){$root=rtrim(trim($root),'\\/');if($root===''||str_contains($root,"\0")||is_link($root)){throw new \InvalidArgumentException('Work graph root must be a safe non-symlink path.');}$this->root=$root;if(!is_dir($root)&&!@mkdir($root,0770,true)&&!is_dir($root)){throw new \RuntimeException('Unable to create work graph root.');}}

	public function read(string $tenantId):array {$tenantId=PanelOperationsGuard::identifier($tenantId,'work tenant id');$state=$this->store($tenantId)->payload();PanelWorkState::assert($state,$tenantId);return$state;}
	public function transaction(string $tenantId,callable $mutation,string $type,array $event=[]):mixed {$tenantId=PanelOperationsGuard::identifier($tenantId,'work tenant id');$type=PanelOperationsGuard::name($type,'work store event type');$result=$this->store($tenantId)->transaction(function(array &$state)use($tenantId,$mutation):mixed{PanelWorkState::assert($state,$tenantId);$result=$mutation($state);PanelWorkState::assert($state,$tenantId);return$result;},$type,$event);return$result['result'];}
	public function changesSince(string $tenantId,int $cursor=0,int $limit=100):array {$tenantId=PanelOperationsGuard::identifier($tenantId,'work tenant id');$result=$this->store($tenantId)->changesSince($cursor,$limit);return['cursor'=>$result['cursor'],'reset_required'=>$result['reset_required'],'changes'=>$result['changes'],'snapshot'=>is_array($result['snapshot']??null)?$result['snapshot']['payload']:null];}
	public function jsonSerialize():array{return['type'=>'panel_filesystem_work_graph_store','root'=>$this->root,'retention'=>max(8,$this->retention),'atomic_commits'=>true,'cross_process_locking'=>true,'change_feed'=>true];}
	private function store(string $tenantId):PanelAtomicSnapshotStore{return$this->stores[$tenantId]??=new PanelAtomicSnapshotStore($this->root.'/'.$tenantId,'panel.work-graph.v1',PanelWorkState::empty($tenantId),max(8,$this->retention));}
}
