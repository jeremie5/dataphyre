<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Builds a deterministic, gap-free forward chain from an integrity-bound state. */
final class PanelMigrationPlanner {
	public function __construct(private readonly PanelMigrationRegistry $registry){}
	public function registry():PanelMigrationRegistry{return$this->registry;}
	public function plan(PanelMigrationState $state,PanelMigrationVersion $target,mixed $createdAt=null):PanelMigrationPlan {
		if(!$state->version()->before($target)){throw new \InvalidArgumentException('Panel migration target must be newer than current state.');}
		$cursor=$state->version();$selected=[];$guard=0;
		while(!$cursor->equals($target)){
			if(++$guard>10000){throw new \LogicException('Panel migration plan exceeded its bounded chain length.');}
			$candidates=array_values(array_filter($this->registry->all($state->scope()),static fn(PanelMigrationDefinition $definition):bool=>$definition->from()->equals($cursor)&&($definition->to()->equals($target)||$definition->to()->before($target))));
			if($candidates===[]){throw new \LogicException("No Panel migration continues {$state->scope()} from {$cursor} to {$target}.");}
			if(count($candidates)>1){throw new \LogicException("Ambiguous Panel migration branch at {$cursor}; select a registry without competing forward edges.");}
			$definition=$candidates[0];if(!$definition->supportsTenant($state->tenant())){throw new \LogicException("Panel migration '{$definition->id()}' does not support this tenant scope.");}
			$selected[]=$definition;$cursor=$definition->to();
		}
		$available=array_fill_keys($state->applied(),true);
		foreach($selected as$definition){foreach($definition->dependencies()as$dependency){if(!isset($available[$dependency])){if(!$this->registry->has($dependency)){throw new \LogicException("Panel migration '{$definition->id()}' has missing dependency '{$dependency}'.");}throw new \LogicException("Panel migration '{$definition->id()}' dependency '{$dependency}' is not applied before its version edge.");}}$available[$definition->id()]=true;}
		return PanelMigrationPlan::make($state,$target,$selected,$this->registry->digest(),$createdAt);
	}
}
