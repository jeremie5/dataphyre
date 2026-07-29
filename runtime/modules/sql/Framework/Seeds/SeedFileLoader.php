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
	/** @param string|list<string> $paths @return list<SeedDefinition> */
	public static function load(string|array $paths): array {
		$paths=is_string($paths) ? [$paths] : $paths;
		$files=[];
		foreach($paths as $path){
			$path=trim((string)$path);
			if($path===''){
				continue;
			}
			$resolved=realpath($path);
			if($resolved===false){
				throw new RuntimeException('Seed path does not exist: '.$path);
			}
			if(is_file($resolved)){
				if(!str_ends_with(strtolower($resolved), '.seed.php')){
					throw new RuntimeException('Seed files must use the .seed.php suffix: '.$path);
				}
				$files[str_replace('\\', '/', $resolved)]=$resolved;
				continue;
			}
			$iterator=new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS)
			);
			foreach($iterator as $file){
				if($file->isFile() && str_ends_with(strtolower($file->getFilename()), '.seed.php')){
					$real=$file->getRealPath();
					if($real!==false){
						$files[str_replace('\\', '/', $real)]=$real;
					}
				}
			}
		}
		ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
		$definitions=[];
		foreach($files as $file){
			foreach(self::loadFile($file) as $definition){
				$definitions[]=$definition;
			}
		}
		return $definitions;
	}

	/** @return list<SeedDefinition> */
	private static function loadFile(string $file): array {
		$checksum_before=hash_file('sha256', $file);
		if(!is_string($checksum_before)){
			throw new RuntimeException('Unable to checksum seed file before loading: '.$file);
		}
		$payload=(static function(string $seed_file): mixed {
			return require $seed_file;
		})($file);
		$checksum_after=hash_file('sha256', $file);
		if(!is_string($checksum_after) || !hash_equals($checksum_before, $checksum_after)){
			throw new RuntimeException('Seed file changed while it was being loaded: '.$file);
		}
		if($payload instanceof SeedDefinition){
			$payload=[$payload];
		}elseif(is_array($payload) && array_key_exists('id', $payload)){
			$payload=[$payload];
		}
		if(!is_array($payload) || $payload===[]){
			throw new RuntimeException('Seed file must return a definition or a non-empty list: '.$file);
		}
		$definitions=[];
		foreach($payload as $item){
			$definition=$item instanceof SeedDefinition
				? $item
				: (is_array($item) ? SeedDefinition::fromArray($item) : null);
			if(!$definition instanceof SeedDefinition){
				throw new RuntimeException('Seed file contains an invalid definition: '.$file);
			}
			$definitions[]=$definition->withSource($file, $checksum_before);
		}
		return $definitions;
	}
}
