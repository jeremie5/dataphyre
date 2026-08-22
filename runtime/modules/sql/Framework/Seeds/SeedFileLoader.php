<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Seeds;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Discovers deterministic `*.seed.php` definitions from application-owned paths. */
final class SeedFileLoader {
	private const MAXIMUM_ROOTS=16;
	private const MAXIMUM_INSPECTED_ENTRIES=100000;
	private const MAXIMUM_SEED_FILES=4096;
	private const MAXIMUM_SEED_FILE_BYTES=2097152;
	private const MAXIMUM_AGGREGATE_BYTES=33554432;
	private const MAXIMUM_DEFINITIONS=4096;

	/** @param string|list<string> $paths @return list<SeedDefinition> */
	public static function load(string|array $paths,?string $content_root=null): array {
		$paths=is_string($paths) ? [$paths] : $paths;
		if(count($paths)>self::MAXIMUM_ROOTS) throw new RuntimeException('Seed path inventory exceeded its bound.');
		if($content_root!==null){
			$requestedRoot=$content_root;$content_root=realpath($requestedRoot);
			if(!is_string($content_root) || is_link($requestedRoot) || !is_dir($content_root)){
				throw new RuntimeException('Seed content root must be a regular non-symbolic directory.');
			}
			$content_root=str_replace('\\','/',$content_root);
			if($content_root!=='/') $content_root=rtrim($content_root,'/');
		}
		$files=[];$inspected=0;$aggregateBytes=0;
		foreach($paths as $path){
			$path=trim((string)$path);
			if($path===''){
				continue;
			}
			$resolved=realpath($path);
			if($resolved===false){
				throw new RuntimeException('Seed path does not exist: '.$path);
			}
			if(is_link($path)) throw new RuntimeException('Seed paths cannot be symbolic links: '.$path);
			$resolved=str_replace('\\','/',$resolved);
			if($content_root!==null && (!self::isWithinRoot($resolved,$content_root)
				|| !hash_equals(self::lexicalAbsolutePath($path),$resolved))){
				throw new RuntimeException('Seed path escaped its content root.');
			}
			if(is_file($resolved)){
				if(!str_ends_with(strtolower($resolved), '.seed.php')){
					throw new RuntimeException('Seed files must use the .seed.php suffix: '.$path);
				}
				self::appendFile($files,$resolved,$aggregateBytes,$content_root);
				continue;
			}
			if(!is_dir($resolved)) throw new RuntimeException('Seed path must be a regular file or directory: '.$path);
			$root=str_replace('\\','/',$resolved);
			if($root!=='/') $root=rtrim($root,'/');
			$iterator=new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::SELF_FIRST,
			);
			foreach($iterator as $file){
				$inspected++;
				if($inspected>self::MAXIMUM_INSPECTED_ENTRIES){
					throw new RuntimeException('Seed directory inventory exceeded its bound.');
				}
				if($file->isLink()) throw new RuntimeException('Seed directories cannot contain symbolic links.');
				if($file->isFile() && str_ends_with(strtolower($file->getFilename()), '.seed.php')){
					$real=$file->getRealPath();
					$normalized=is_string($real) ? str_replace('\\','/',$real) : '';
					if($normalized==='' || !str_starts_with($normalized,rtrim($root,'/').'/')){
						throw new RuntimeException('Seed file escaped its discovery root.');
					}
					self::appendFile($files,$real,$aggregateBytes,$content_root);
				}
			}
		}
		ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
		$definitions=[];$definitionCount=0;$contentInventory=[];$contentBytes=0;
		foreach($files as $attestation){
			foreach(self::loadFile(
				$attestation,$content_root,$contentInventory,$contentBytes,$definitionCount,
			) as $definition) $definitions[]=$definition;
		}
		self::validateContentInventory($contentInventory);
		return $definitions;
	}

	/** @param array<string,array{bytes:int,sha256:string}> $inventory */
	private static function validateContentInventory(array $inventory): void {
		foreach($inventory as $file=>$expected){
			clearstatcache(true,$file);
			if(is_link($file) || !is_file($file) || !is_readable($file)){
				throw new RuntimeException('Seed content source changed during discovery.');
			}
			$bytes=filesize($file);$checksum=hash_file('sha256',$file);
			if(!is_int($bytes) || $bytes!==$expected['bytes']
				|| !is_string($checksum) || !hash_equals($expected['sha256'],$checksum)){
				throw new RuntimeException('Seed content source changed during discovery.');
			}
		}
	}

	/** @param array<string,array{path:string,bytes:int,sha256:string,device:int,inode:int}> $files */
	private static function appendFile(
		array &$files,string $file,int &$aggregateBytes,?string $contentRoot,
	): void {
		$key=str_replace('\\','/',$file);
		if($contentRoot!==null && !self::isWithinRoot($key,$contentRoot)){
			throw new RuntimeException('Seed definition escaped its content root.');
		}
		if(isset($files[$key])) return;
		$attestation=self::attestFile($key);
		if($attestation['bytes']<1 || $attestation['bytes']>self::MAXIMUM_SEED_FILE_BYTES){
			throw new RuntimeException('Seed definition file exceeded its byte bound.');
		}
		if(count($files)>=self::MAXIMUM_SEED_FILES
			|| $aggregateBytes+$attestation['bytes']>self::MAXIMUM_AGGREGATE_BYTES){
			throw new RuntimeException('Seed definition file inventory exceeded its bound.');
		}
		$files[$key]=$attestation;$aggregateBytes+=$attestation['bytes'];
	}

	private static function isWithinRoot(string $path,string $root): bool {
		return hash_equals($path,$root) || str_starts_with($path,rtrim($root,'/').'/');
	}

	private static function lexicalAbsolutePath(string $path): string {
		$path=str_replace('\\','/',$path);
		if(str_starts_with($path,'//')) throw new RuntimeException('Seed UNC paths are not supported.');
		if(!str_starts_with($path,'/') && preg_match('/^[A-Za-z]:\//D',$path)!==1){
			$working=getcwd();
			if(!is_string($working) || $working==='') throw new RuntimeException('Seed working directory is unavailable.');
			return self::lexicalAbsolutePath(str_replace('\\','/',$working).'/'.$path);
		}
		$prefix='/';
		if(preg_match('/^([A-Za-z]:)\/(.*)$/D',$path,$drive)===1){
			$prefix=$drive[1].'/';$path=$drive[2];
		}else $path=ltrim($path,'/');
		$parts=[];
		foreach(explode('/',$path) as $part){
			if($part==='' || $part==='.') continue;
			if($part==='..'){
				if($parts===[]) throw new RuntimeException('Seed path is invalid.');
				array_pop($parts);
				continue;
			}
			$parts[]=$part;
		}
		return $prefix.implode('/',$parts);
	}

	/** @return array{path:string,bytes:int,sha256:string,device:int,inode:int} */
	private static function attestFile(string $file): array {
		clearstatcache(true,$file);
		$before=lstat($file);
		if(!is_array($before) || is_link($file) || !is_file($file) || !is_readable($file)){
			throw new RuntimeException('Seed definition must be a readable regular non-symbolic file.');
		}
		$checksum=hash_file('sha256',$file);
		clearstatcache(true,$file);
		$after=lstat($file);
		foreach(['size','dev','ino'] as $field){
			if(!is_array($after) || !is_int($before[$field] ?? null)
				|| !is_int($after[$field] ?? null) || $before[$field]!==$after[$field]){
				throw new RuntimeException('Seed definition changed while it was attested.');
			}
		}
		if(!is_string($checksum)) throw new RuntimeException('Unable to checksum seed definition.');
		return [
			'path'=>$file,'bytes'=>$after['size'],'sha256'=>strtolower($checksum),
			'device'=>$after['dev'],'inode'=>$after['ino'],
		];
	}

	/**
	 * @param array{path:string,bytes:int,sha256:string,device:int,inode:int} $expected
	 * @param array{path:string,bytes:int,sha256:string,device:int,inode:int} $actual
	 */
	private static function assertSameAttestation(array $expected,array $actual): void {
		foreach(['path','bytes','sha256','device','inode'] as $field){
			if($expected[$field]!==$actual[$field]){
				throw new RuntimeException('Seed definition changed during discovery.');
			}
		}
	}

	/** @return list<SeedDefinition> */
	private static function loadFile(
		array $expected,
		?string $contentRoot,
		array &$contentInventory,
		int &$contentBytes,
		int &$definitionCount,
	): array {
		$file=$expected['path'];
		self::assertSameAttestation($expected,self::attestFile($file));
		$payload=(static function(string $seed_file): mixed {
			return require $seed_file;
		})($file);
		self::assertSameAttestation($expected,self::attestFile($file));
		if($payload instanceof SeedDefinition){
			$payload=[$payload];
		}elseif(is_array($payload) && array_key_exists('id', $payload)){
			$payload=[$payload];
		}
		if(!is_array($payload) || $payload===[]){
			throw new RuntimeException('Seed file must return a definition or a non-empty list: '.$file);
		}
		if(count($payload)>self::MAXIMUM_DEFINITIONS-$definitionCount){
			throw new RuntimeException('Seed definition inventory exceeded its bound.');
		}
		$definitions=[];
		foreach($payload as $item){
			$definition=$item instanceof SeedDefinition
				? $item
				: (is_array($item) ? SeedDefinition::fromArray($item) : null);
			if(!$definition instanceof SeedDefinition){
				throw new RuntimeException('Seed file contains an invalid definition: '.$file);
			}
			$definitionCount++;
			$definitions[]=$definition->withSource(
				$file,$expected['sha256'],$contentRoot,$contentInventory,$contentBytes,
			);
		}
		return $definitions;
	}
}
