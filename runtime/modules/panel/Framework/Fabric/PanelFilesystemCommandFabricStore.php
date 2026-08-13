<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

final class PanelFilesystemCommandFabricStore implements PanelCommandFabricStore,\JsonSerializable {
	private readonly PanelAtomicSnapshotStore $store;public function __construct(string $directory,int $retention=4096){$this->store=new PanelAtomicSnapshotStore($directory,'panel_command_fabric_state_v1',PanelCommandFabricState::initial(),$retention);PanelCommandFabricState::validate($this->store->payload());}public function payload():array{return PanelCommandFabricState::validate($this->store->payload());}public function transaction(callable $mutation,string $type,array $event=[]):array{return$this->store->transaction(function(array &$state)use($mutation):mixed{$result=$mutation($state);$state=PanelCommandFabricState::validate($state);return$result;},$type,$event);}public function changesSince(int $cursor=0,int $limit=100):array{return$this->store->changesSince($cursor,$limit);}public function jsonSerialize():array{return['type'=>'panel_filesystem_command_fabric_store','version'=>1,'state_revision'=>$this->payload()['revision'],'store'=>$this->store->manifest()];}
}
