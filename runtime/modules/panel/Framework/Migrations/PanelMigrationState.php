<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Integrity-checked state envelope; JSON exposes metadata, never state data. */
final class PanelMigrationState implements \JsonSerializable {
	/** @param array<string,mixed> $data @param list<string> $applied */
	private function __construct(private readonly string $scope,private readonly ?string $tenant,private readonly PanelMigrationVersion $version,private readonly array $data,private readonly array $applied,private readonly int $revision,private readonly string $digest){}
	/** @param array<string,mixed> $data @param list<string> $applied */
	public static function make(string $scope,?string $tenant,PanelMigrationVersion $version,array $data=[],array $applied=[],int $revision=0,?string $expectedDigest=null):self {
		$scope=PanelMigrationIntegrity::identifier($scope,'scope');$tenant=PanelMigrationIntegrity::tenant($tenant);
		if($revision<0){throw new \InvalidArgumentException('Panel migration state revisions cannot be negative.');}
		$normalized=[];foreach($applied as$id){$normalized[]=PanelMigrationIntegrity::identifier((string)$id,'applied migration id');}$normalized=array_values(array_unique($normalized));sort($normalized,SORT_STRING);
		$digest=self::calculateDigest($scope,$tenant,$version,$data,$normalized,$revision);
		if($expectedDigest!==null&&!hash_equals($expectedDigest,$digest)){throw new \RuntimeException('Panel migration state integrity digest mismatch.');}
		return new self($scope,$tenant,$version,$data,$normalized,$revision,$digest);
	}
	/** @param array<string,mixed> $stored */ public static function fromStored(array $stored):self{$digest=$stored['digest']??null;if(!is_string($digest)||preg_match('/^[a-f0-9]{64}$/D',$digest)!==1){throw new \RuntimeException('Panel migration state is missing its integrity digest.');}return self::make((string)($stored['scope']??''),isset($stored['tenant'])?(string)$stored['tenant']:null,PanelMigrationVersion::fromArray(is_array($stored['version']??null)?$stored['version']:[]),is_array($stored['data']??null)?$stored['data']:[],is_array($stored['applied']??null)?array_values($stored['applied']):[],(int)($stored['revision']??0),$digest);}
	public function scope():string{return$this->scope;} public function tenant():?string{return$this->tenant;} public function version():PanelMigrationVersion{return$this->version;}
	/** @return array<string,mixed> */ public function data():array{return$this->data;} /** @return list<string> */ public function applied():array{return$this->applied;}
	public function revision():int{return$this->revision;} public function digest():string{return$this->digest;}
	/** @param array<string,mixed> $data @param list<string> $applied */ public function evolved(PanelMigrationVersion $version,array $data,array $applied):self{return self::make($this->scope,$this->tenant,$version,$data,$applied,$this->revision+1);}
	/** @return array<string,mixed> */ public function stored():array{return['scope'=>$this->scope,'tenant'=>$this->tenant,'version'=>$this->version->jsonSerialize(),'data'=>$this->data,'applied'=>$this->applied,'revision'=>$this->revision,'digest'=>$this->digest];}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_migration_state','manifest_version'=>1,'scope'=>$this->scope,'tenant'=>$this->tenant,'version'=>$this->version->jsonSerialize(),'revision'=>$this->revision,'digest'=>$this->digest,'applied'=>$this->applied,'data_serialized'=>false];}
	/** @param array<string,mixed> $data @param list<string> $applied */ private static function calculateDigest(string $scope,?string $tenant,PanelMigrationVersion $version,array $data,array $applied,int $revision):string{return PanelMigrationIntegrity::digest(['scope'=>$scope,'tenant'=>$tenant,'version'=>$version->jsonSerialize(),'data'=>$data,'applied'=>$applied,'revision'=>$revision]);}
}
