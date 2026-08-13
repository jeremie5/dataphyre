<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable metadata for a restorable, integrity-checked pre-run backup. */
final class PanelMigrationSnapshot implements \JsonSerializable {
	public function __construct(private readonly string $id,private readonly string $runId,private readonly string $scope,private readonly ?string $tenant,private readonly int $revision,private readonly string $stateDigest,private readonly string $createdAt){PanelMigrationIntegrity::identifier($id,'snapshot id');PanelMigrationIntegrity::identifier($runId,'run id');PanelMigrationIntegrity::identifier($scope,'scope');PanelMigrationIntegrity::tenant($tenant);if($revision<0||preg_match('/^[a-f0-9]{64}$/D',$stateDigest)!==1){throw new \InvalidArgumentException('Invalid Panel migration snapshot metadata.');}try{new \DateTimeImmutable($createdAt);}catch(\Throwable){throw new \InvalidArgumentException('Invalid Panel migration snapshot timestamp.');}}
	public function id():string{return$this->id;}public function runId():string{return$this->runId;}public function scope():string{return$this->scope;}public function tenant():?string{return$this->tenant;}public function revision():int{return$this->revision;}public function stateDigest():string{return$this->stateDigest;}public function createdAt():string{return$this->createdAt;}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_migration_snapshot','manifest_version'=>1,'id'=>$this->id,'run_id'=>$this->runId,'scope'=>$this->scope,'tenant'=>$this->tenant,'revision'=>$this->revision,'state_digest'=>$this->stateDigest,'created_at'=>$this->createdAt,'state_serialized'=>false];}
}
