<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Crash-safe, process-safe activation store built on immutable atomic snapshots. */
final class PanelFilesystemDomainActivationStore implements PanelDomainActivationStore,\JsonSerializable {
	private readonly PanelAtomicSnapshotStore $store;
	public function __construct(string $directory,int $retention=2048){$this->store=new PanelAtomicSnapshotStore($directory,'panel_domain_activation_state_v1',PanelDomainActivationState::initial(),$retention);PanelDomainActivationState::validate($this->store->payload());}
	public function payload():array{return PanelDomainActivationState::validate($this->store->payload());}
	public function transaction(callable $mutation,string $type,array $event=[]):array {return$this->store->transaction(function(array &$state)use($mutation):mixed{$result=$mutation($state);$state=PanelDomainActivationState::validate($state);return$result;},$type,$event);}
	public function changesSince(int $cursor=0,int $limit=100):array{return$this->store->changesSince($cursor,$limit);}
	public function jsonSerialize():array{return['type'=>'panel_filesystem_domain_activation_store','version'=>1,'store'=>$this->store->manifest(),'state_revision'=>$this->payload()['revision']];}
}
