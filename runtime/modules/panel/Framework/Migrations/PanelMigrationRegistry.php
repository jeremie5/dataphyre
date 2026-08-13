<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Instance-owned immutable-definition registry with a stable catalogue digest. */

final class PanelMigrationRegistry implements PanelCheckpointableService, \JsonSerializable {
	private const MAX_DEFINITIONS=4096;
	/** @var array<string,PanelMigrationDefinition> */ private array $definitions=[];
	private int $revision=0;
	private readonly string $checkpointOwner;
	/** @param iterable<PanelMigrationDefinition> $definitions */ public function __construct(iterable $definitions=[]){$this->checkpointOwner=bin2hex(random_bytes(16));foreach($definitions as$definition){$this->register($definition);}}
	public function register(PanelMigrationDefinition $definition,bool $replace=false):self{$id=$definition->id();if(!$replace&&isset($this->definitions[$id])){throw new \LogicException("Panel migration '{$id}' is already registered.");}if(in_array($id,$definition->dependencies(),true)){throw new \LogicException("Panel migration '{$id}' cannot depend on itself.");}if(!isset($this->definitions[$id])&&count($this->definitions)>=self::MAX_DEFINITIONS){throw new \OverflowException('Panel migration registry capacity is exhausted.');}$this->definitions[$id]=$definition;$this->revision++;return$this;}
	public function has(string $id):bool{return isset($this->definitions[PanelMigrationIntegrity::identifier($id,'migration id')]);}
	public function get(string $id):PanelMigrationDefinition{$id=PanelMigrationIntegrity::identifier($id,'migration id');return$this->definitions[$id]??throw new \OutOfBoundsException("Panel migration '{$id}' is not registered.");}
	/** @return list<PanelMigrationDefinition> */ public function all(?string $scope=null):array{$items=array_values($this->definitions);if($scope!==null){$scope=PanelMigrationIntegrity::identifier($scope,'scope');$items=array_values(array_filter($items,static fn(PanelMigrationDefinition $definition):bool=>$definition->scope()===$scope));}usort($items,static fn(PanelMigrationDefinition $left,PanelMigrationDefinition $right):int=>[$left->scope(),$left->from()->semantic(),$left->from()->schema(),$left->id()]<=>[$right->scope(),$right->from()->semantic(),$right->from()->schema(),$right->id()]);return$items;}
	public function revision():int{return$this->revision;}
	public function checkpointType():string{return'panel_migration_registry_v1';}
	/** @return array{owner:string,definitions:array<string,PanelMigrationDefinition>,revision:int,digest:string} */ public function checkpoint():array{return['owner'=>$this->checkpointOwner,'definitions'=>$this->definitions,'revision'=>$this->revision,'digest'=>$this->checkpointDigest($this->definitions,$this->revision)];}
	/** @param array<string,mixed> $checkpoint */ public function restore(array $checkpoint):self{if(array_keys($checkpoint)!==['owner','definitions','revision','digest']||!is_string($checkpoint['owner'])||!hash_equals($this->checkpointOwner,$checkpoint['owner'])||!is_array($checkpoint['definitions'])||count($checkpoint['definitions'])>self::MAX_DEFINITIONS||!is_int($checkpoint['revision'])||$checkpoint['revision']<0||!is_string($checkpoint['digest'])){throw new \InvalidArgumentException('Invalid Panel migration registry checkpoint.');}foreach($checkpoint['definitions']as$id=>$definition){if(!is_string($id)||!$definition instanceof PanelMigrationDefinition||$definition->id()!==$id||PanelMigrationIntegrity::identifier($id,'migration id')!==$id||in_array($id,$definition->dependencies(),true)){throw new \InvalidArgumentException('Invalid Panel migration registry checkpoint.');}}if(!hash_equals($this->checkpointDigest($checkpoint['definitions'],$checkpoint['revision']),$checkpoint['digest'])){throw new \InvalidArgumentException('Invalid Panel migration registry checkpoint.');}$this->definitions=$checkpoint['definitions'];$this->revision=$checkpoint['revision'];return$this;}
	/** @param array<string,PanelMigrationDefinition> $definitions */ private function checkpointDigest(array $definitions,int $revision):string{$identities=[];foreach($definitions as$id=>$definition){$identities[$id]=spl_object_id($definition);}return hash('sha256',json_encode(['owner'=>$this->checkpointOwner,'definitions'=>$identities,'revision'=>$revision],JSON_THROW_ON_ERROR));}
	public function digest():string{return PanelMigrationIntegrity::digest(array_map(static fn(PanelMigrationDefinition $definition):array=>$definition->jsonSerialize(),$this->all()));}
	/** @return array<string,mixed> */ public function jsonSerialize():array{$definitions=array_map(static fn(PanelMigrationDefinition $definition):array=>$definition->jsonSerialize(),$this->all());return['type'=>'panel_migration_registry','manifest_version'=>1,'definitions'=>$definitions,'count'=>count($definitions),'revision'=>$this->revision,'digest'=>PanelMigrationIntegrity::digest($definitions),'callbacks_serialized'=>false];}
}
