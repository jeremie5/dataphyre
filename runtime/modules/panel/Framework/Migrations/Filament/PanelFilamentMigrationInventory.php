<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable, source-free inventory produced by the Filament analyzer. */
final class PanelFilamentMigrationInventory implements \JsonSerializable {
	/** @var list<array<string,mixed>> */
	private array $files;
	/** @var list<array<string,mixed>> */
	private array $findings;
	private array $composer;
	private string $digest;

	/** @param list<array<string,mixed>> $files @param list<array<string,mixed>> $findings */
	public function __construct(array $composer,array $files,array $findings=[]){
		$this->composer=PanelOperationsGuard::safeMetadata($composer,256);
		$this->files=[];
		foreach($files as$file){
			if(!is_array($file)||!is_string($file['path']??null)||self::relativePath($file['path'])!==$file['path']){
				throw new \InvalidArgumentException('Filament inventory files require canonical relative paths.');
			}
			$this->files[]=PanelOperationsGuard::safeMetadata($file,4096);
		}
		usort($this->files,static fn(array $left,array $right):int=>strcmp((string)$left['path'],(string)$right['path']));
		$this->findings=[];
		foreach($findings as$finding){
			if(!is_array($finding)){throw new \InvalidArgumentException('Filament inventory findings must be maps.');}
			$severity=Resource::normalizeName((string)($finding['severity']??''));
			$code=Resource::normalizeName((string)($finding['code']??''));
			if(!in_array($severity,['info','warning','blocker'],true)||$code===''){
				throw new \InvalidArgumentException('Filament inventory finding severity or code is invalid.');
			}
			$row=['severity'=>$severity,'code'=>$code];
			if(isset($finding['path'])){$row['path']=self::relativePath((string)$finding['path']);}
			if(isset($finding['line'])){$row['line']=max(1,(int)$finding['line']);}
			if(isset($finding['message'])){$row['message']=substr(trim((string)$finding['message']),0,512);}
			$this->findings[]=$row;
		}
		$rank=['blocker'=>0,'warning'=>1,'info'=>2];
		usort($this->findings,static fn(array $left,array $right):int=>[
			$rank[$left['severity']],$left['path']??'',$left['line']??0,$left['code'],
		]<=>[
			$rank[$right['severity']],$right['path']??'',$right['line']??0,$right['code'],
		]);
		$this->digest=PanelOperationsGuard::digest(['composer'=>$this->composer,'files'=>$this->files,'findings'=>$this->findings]);
	}

	public function composer():array{return$this->composer;}
	/** @return list<array<string,mixed>> */
	public function files():array{return$this->files;}
	/** @return list<array<string,mixed>> */
	public function findings():array{return$this->findings;}
	public function digest():string{return$this->digest;}
	public function hasBlockers():bool{foreach($this->findings as$finding){if($finding['severity']==='blocker')return true;}return false;}

	/** @return array<string,mixed> */
	public function jsonSerialize():array{
		$classKinds=[];$componentFamilies=[];$classCount=0;$componentCount=0;
		foreach($this->files as$file){
			foreach((array)($file['classes']??[])as$class){if(!is_array($class))continue;$kind=(string)($class['kind']??'unknown');$classKinds[$kind]=($classKinds[$kind]??0)+1;$classCount++;}
			foreach((array)($file['components']??[])as$component){if(!is_array($component))continue;$family=(string)($component['family']??'unknown');$componentFamilies[$family]=($componentFamilies[$family]??0)+1;$componentCount++;}
		}
		ksort($classKinds,SORT_STRING);ksort($componentFamilies,SORT_STRING);
		return PanelManifestContract::stamp([
			'type'=>'panel_filament_migration_inventory','version'=>1,'digest'=>$this->digest,
			'composer'=>$this->composer,'file_count'=>count($this->files),'class_count'=>$classCount,
			'component_count'=>$componentCount,'class_kinds'=>$classKinds,'component_families'=>$componentFamilies,
			'files'=>$this->files,'findings'=>$this->findings,'blocker_count'=>count(array_filter($this->findings,static fn(array $finding):bool=>$finding['severity']==='blocker')),
			'source_contents_serialized'=>false,'source_root_serialized'=>false,
		]);
	}

	private static function relativePath(string $path):string{
		$path=str_replace('\\','/',trim($path));
		if($path===''||str_starts_with($path,'/')||preg_match('/^[A-Za-z]:\//',$path)===1||str_contains($path,"\0")){throw new \InvalidArgumentException('Filament inventory path is not relative.');}
		$parts=[];foreach(explode('/',$path)as$part){if($part===''||$part==='.')continue;if($part==='..'||preg_match('/[\x00-\x1F\x7F]/',$part)===1){throw new \InvalidArgumentException('Filament inventory path escapes its source root.');}$parts[]=$part;}
		if($parts===[]){throw new \InvalidArgumentException('Filament inventory path is empty.');}
		return implode('/',$parts);
	}
}
