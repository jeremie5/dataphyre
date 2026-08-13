<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Typed, inspectable factory that keeps preference workspaces on the registered store. */
final class PanelWorkspacePreferencesFactory implements \JsonSerializable {
	public function __construct(private readonly PanelPreferenceStore $store){}
	public function store():PanelPreferenceStore{return$this->store;}
	public function __invoke(string $userId,string $profile='default',?string $device=null):PanelWorkspacePreferences{return new PanelWorkspacePreferences($this->store,$userId,$profile,$device);}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_workspace_preferences_factory','version'=>1,'store_type'=>$this->store::class,'dependency_identity_exposed'=>true];}
}
