<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\CaseDiscoveryCache;

/** Content-addressed discovery cache that never loads an unrelated manifest. */
final class ShardedCaseDiscoveryCache implements CaseDiscoveryCache {
	private const VERSION=1;
	private string $indexPath;

	public function __construct(string $indexPath) {
		$indexPath=str_replace('\\','/',trim($indexPath));
		if($indexPath===''){
			throw new \InvalidArgumentException('Case discovery cache index path cannot be blank.');
		}
		$this->indexPath=$indexPath;
	}

	public function directory(): string {
		$directory=str_replace('\\','/',dirname($this->indexPath));
		$name=basename($this->indexPath);
		if(str_ends_with(strtolower($name),'.json')){
			$name=substr($name,0,-5);
		}
		return rtrim($directory,'/').'/'.$name.'.d';
	}

	public function path(string $key): string {
		return $this->directory().'/'.hash('sha256',$this->key($key)).'.json';
	}

	public function find(string $key): ?CaseDiscoveryCacheEntry {
		$key=$this->key($key);
		$path=$this->path($key);
		if(!is_file($path)){
			return null;
		}
		$decoded=json_decode((string)@file_get_contents($path),true);
		if(
			!is_array($decoded)
			|| ($decoded['version'] ?? null)!==self::VERSION
			|| ($decoded['key'] ?? null)!==$key
			|| !is_string($decoded['fingerprint'] ?? null)
			|| trim($decoded['fingerprint'])===''
			|| !is_array($decoded['cases'] ?? null)
		){
			return null;
		}
		try{
			return new CaseDiscoveryCacheEntry($decoded['fingerprint'],$decoded['cases']);
		}catch(\InvalidArgumentException){
			return null;
		}
	}

	public function store(string $key, CaseDiscoveryCacheEntry $entry): bool {
		$key=$this->key($key);
		$directory=$this->directory();
		if(!is_dir($directory) && !@mkdir($directory,0775,true) && !is_dir($directory)){
			return false;
		}
		$payload=json_encode([
			'version'=>self::VERSION,
			'key'=>$key,
			'fingerprint'=>$entry->fingerprint(),
			'cases'=>$entry->cases(),
		],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
		return file_put_contents($this->path($key),$payload,LOCK_EX)!==false;
	}

	private function key(string $key): string {
		$key=trim($key);
		if($key===''){
			throw new \InvalidArgumentException('Case discovery cache keys cannot be blank.');
		}
		return $key;
	}
}
