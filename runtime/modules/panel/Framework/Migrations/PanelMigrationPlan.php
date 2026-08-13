<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable plan bound to one exact state revision and registry digest. */
final class PanelMigrationPlan implements \JsonSerializable {
	/** @param list<array<string,mixed>> $steps @param list<string> $capabilities */
	private function __construct(private readonly string $scope,private readonly ?string $tenant,private readonly PanelMigrationVersion $source,private readonly PanelMigrationVersion $target,private readonly array $steps,private readonly array $capabilities,private readonly string $registryDigest,private readonly int $stateRevision,private readonly string $stateDigest,private readonly string $createdAt,private readonly string $digest){}
	/** @param list<PanelMigrationDefinition> $definitions */
	public static function make(PanelMigrationState $state,PanelMigrationVersion $target,array $definitions,string $registryDigest,mixed $createdAt=null):self {
		if(!$state->version()->before($target)){throw new \InvalidArgumentException('Panel migration plans require a target newer than their source.');}
		if(preg_match('/^[a-f0-9]{64}$/D',$registryDigest)!==1){throw new \InvalidArgumentException('Panel migration plans require a SHA-256 registry digest.');}
		if($definitions===[]){throw new \InvalidArgumentException('Panel migration plans require at least one version edge.');}$steps=[];$capabilities=[];$cursor=$state->version();foreach($definitions as$index=>$definition){if(!$definition instanceof PanelMigrationDefinition){throw new \InvalidArgumentException('Panel migration plans only accept migration definitions.');}if($definition->scope()!==$state->scope()||!$definition->supportsTenant($state->tenant())||!$definition->from()->equals($cursor)){throw new \InvalidArgumentException('Panel migration plan definitions must form one contiguous scope-compatible chain.');}$steps[]=['position'=>$index,'id'=>$definition->id(),'definition_digest'=>$definition->digest(),'from'=>$definition->from()->jsonSerialize(),'to'=>$definition->to()->jsonSerialize(),'batch_size'=>$definition->batchSize(),'reversible'=>$definition->reversible(),'idempotent'=>$definition->idempotent()];$capabilities=array_merge($capabilities,$definition->capabilities());$cursor=$definition->to();}if(!$cursor->equals($target)){throw new \InvalidArgumentException('Panel migration plan chain does not reach its target.');}
		$capabilities=array_values(array_unique($capabilities));sort($capabilities,SORT_STRING);$created=self::time($createdAt);
		$payload=['scope'=>$state->scope(),'tenant'=>$state->tenant(),'source'=>$state->version()->jsonSerialize(),'target'=>$target->jsonSerialize(),'steps'=>$steps,'required_capabilities'=>$capabilities,'registry_digest'=>$registryDigest,'state_revision'=>$state->revision(),'state_digest'=>$state->digest()];$digest=PanelMigrationIntegrity::digest($payload);
		return new self($state->scope(),$state->tenant(),$state->version(),$target,$steps,$capabilities,$registryDigest,$state->revision(),$state->digest(),$created,$digest);
	}
	public function scope():string{return$this->scope;}public function tenant():?string{return$this->tenant;}public function source():PanelMigrationVersion{return$this->source;}public function target():PanelMigrationVersion{return$this->target;}
	/** @return list<array<string,mixed>> */ public function steps():array{return$this->steps;} /** @return list<string> */ public function migrationIds():array{return array_map(static fn(array $step):string=>(string)$step['id'],$this->steps);} /** @return list<string> */ public function capabilities():array{return$this->capabilities;}
	public function registryDigest():string{return$this->registryDigest;}public function stateRevision():int{return$this->stateRevision;}public function stateDigest():string{return$this->stateDigest;}public function createdAt():string{return$this->createdAt;}public function digest():string{return$this->digest;}public function idempotencyKey():string{return'panel-migration:'.$this->digest;}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_migration_plan','manifest_version'=>1,'scope'=>$this->scope,'tenant'=>$this->tenant,'source'=>$this->source->jsonSerialize(),'target'=>$this->target->jsonSerialize(),'steps'=>$this->steps,'required_capabilities'=>$this->capabilities,'registry_digest'=>$this->registryDigest,'state_revision'=>$this->stateRevision,'state_digest'=>$this->stateDigest,'created_at'=>$this->createdAt,'digest'=>$this->digest,'idempotency_key'=>$this->idempotencyKey()];}
	private static function time(mixed $value):string{try{$date=$value instanceof \DateTimeInterface?\DateTimeImmutable::createFromInterface($value):new \DateTimeImmutable($value===null?'now':(string)$value);return$date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);}catch(\Throwable){throw new \InvalidArgumentException('Invalid Panel migration plan timestamp.');}}
}
