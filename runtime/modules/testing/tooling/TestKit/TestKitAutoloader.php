<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Loads TestKit types on demand from their canonical module-owned source. */
final class TestKitAutoloader {

	private const PREFIX='Dataphyre\\Test\\';
	private static bool $registered=false;
	private static string $source_root='';

	public static function register(string $source_root): void {
		$source_root=str_replace('\\', '/', rtrim($source_root, '/\\'));
		if($source_root==='' || !is_dir($source_root)){
			throw new \InvalidArgumentException('TestKit autoload source must be an existing directory.');
		}
		if(self::$registered){
			if(self::$source_root!==$source_root){
				throw new \LogicException('TestKit autoloading is already bound to a different source directory.');
			}
			return;
		}
		self::$source_root=$source_root;
		spl_autoload_register([self::class, 'load'], true, true);
		self::$registered=true;
	}

	public static function load(string $class): void {
		$path=self::path($class);
		if($path!==null && is_file($path)){
			require_once $path;
		}
	}

	public static function path(string $class): ?string {
		if(!self::$registered || !str_starts_with($class, self::PREFIX)){
			return null;
		}
		$relative=substr($class, strlen(self::PREFIX));
		if($relative==='' || preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/', $relative)!==1){
			return null;
		}
		return self::$source_root.'/'.str_replace('\\', '/', $relative).'.php';
	}

	public static function sourceRoot(): string {
		if(!self::$registered){
			throw new \LogicException('TestKit autoloading has not been registered.');
		}
		return self::$source_root;
	}

	/** @return list<string> */
	public static function sourceFiles(?string $source_root=null): array {
		$source_root=str_replace('\\', '/', rtrim($source_root ?? self::sourceRoot(), '/\\'));
		if(!is_dir($source_root)){
			throw new \InvalidArgumentException('TestKit source inventory requires an existing directory.');
		}
		$files=[];
		$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source_root, \FilesystemIterator::SKIP_DOTS));
		foreach($iterator as $entry){
			if($entry->isFile() && str_ends_with(strtolower($entry->getFilename()), '.php')){
				$files[]=str_replace('\\', '/', $entry->getPathname());
			}
		}
		sort($files, SORT_STRING);
		return $files;
	}

	public static function classForPath(string $path): ?string {
		$path=str_replace('\\', '/', $path);
		$prefix=self::sourceRoot().'/';
		if(!str_starts_with($path, $prefix) || !str_ends_with(strtolower($path), '.php')){
			return null;
		}
		$relative=substr($path, strlen($prefix), -4);
		if($relative==='functions'){
			return null;
		}
		$class=self::PREFIX.str_replace('/', '\\', $relative);
		return self::path($class)===$path ? $class : null;
	}
}
