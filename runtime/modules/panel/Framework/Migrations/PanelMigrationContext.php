<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** One bounded migration batch execution context. */
final class PanelMigrationContext implements \JsonSerializable {
	/** @param array<string,mixed> $data @param array<string,mixed> $checkpoint */
	public function __construct(private readonly string $scope,private readonly ?string $tenant,private readonly string $migrationId,private readonly string $direction,private readonly array $data,private readonly string|int|null $cursor,private readonly int $limit,private readonly array $checkpoint,private readonly bool $dryRun,private readonly mixed $actor){
		PanelMigrationIntegrity::identifier($scope,'scope');PanelMigrationIntegrity::tenant($tenant);PanelMigrationIntegrity::identifier($migrationId,'migration id');
		if(!in_array($direction,['up','down'],true)){throw new \InvalidArgumentException('Panel migration direction must be up or down.');}
		if($limit<1||$limit>10000){throw new \InvalidArgumentException('Panel migration batch limits must be between 1 and 10000.');}
	}
	public function scope():string{return$this->scope;} public function tenant():?string{return$this->tenant;} public function migrationId():string{return$this->migrationId;} public function direction():string{return$this->direction;}
	/** @return array<string,mixed> */ public function data():array{return$this->data;} public function cursor():string|int|null{return$this->cursor;} public function limit():int{return$this->limit;}
	/** @return array<string,mixed> */ public function checkpoint():array{return$this->checkpoint;} public function dryRun():bool{return$this->dryRun;} public function actor():mixed{return$this->actor;}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_migration_context','manifest_version'=>1,'scope'=>$this->scope,'tenant'=>$this->tenant,'migration_id'=>$this->migrationId,'direction'=>$this->direction,'cursor'=>$this->cursor,'limit'=>$this->limit,'dry_run'=>$this->dryRun,'checkpoint'=>PanelMigrationIntegrity::redact($this->checkpoint),'data_serialized'=>false,'actor_fingerprint'=>substr(PanelMigrationIntegrity::digest(PanelMigrationIntegrity::redact($this->actor)),0,16)];}
}
