<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Owns the Context capabilities described by its name. */
trait ManagesTemporaryFiles {

	public function tempDirectory(string $prefix='dataphyre-test'): string {
		$prefix=preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($prefix)) ?: 'dataphyre-test';
		$path=rtrim(sys_get_temp_dir(), '/\\').'/'.$prefix.'-'.bin2hex(random_bytes(6));
		if(!mkdir($path, 0775, true) && !is_dir($path)){
			throw new \RuntimeException('Unable to create temporary test directory: '.$path);
		}
		$this->cleanup(static fn()=>self::removeTemporaryPath($path));
		return $path;
	}

	/** Creates a cleanup-managed random child beneath an intentional fixture root. */
	public function tempDirectoryIn(string $directory, string $prefix='dataphyre-test'): string {
		$directory=rtrim($directory, '/\\');
		if($directory==='' || is_link($directory) || !is_dir($directory)){
			throw new \InvalidArgumentException('Temporary directory parent must be an existing non-symlink directory.');
		}
		$resolved=realpath($directory);
		if(!is_string($resolved) || $resolved===''){
			throw new \InvalidArgumentException('Temporary directory parent could not be resolved.');
		}
		$prefix=preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($prefix)) ?: 'dataphyre-test';
		$path=rtrim($resolved, '/\\').'/'.$prefix.'-'.bin2hex(random_bytes(6));
		if(!mkdir($path, 0775, true) && !is_dir($path)){
			throw new \RuntimeException('Unable to create temporary test directory: '.$path);
		}
		$this->cleanup(static fn()=>self::removeTemporaryPath($path));
		return $path;
	}

	public function tempFile(string $contents='', string $prefix='dataphyre-test', ?string $directory=null): string {
		$directory=$directory===null ? sys_get_temp_dir() : $directory;
		if(!is_dir($directory)){
			throw new \RuntimeException('Temporary test file directory does not exist: '.$directory);
		}
		$prefix=preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($prefix)) ?: 'dataphyre-test';
		$path=tempnam($directory, $prefix.'-');
		if($path===false){
			throw new \RuntimeException('Unable to create temporary test file in: '.$directory);
		}
		if($contents!=='' && file_put_contents($path, $contents)===false){
			@unlink($path);
			throw new \RuntimeException('Unable to write temporary test file: '.$path);
		}
		$this->cleanup(static fn()=>self::removeTemporaryPath($path));
		return $path;
	}

	public function workspace(string $prefix='dataphyre-workspace'): TempWorkspace {
		return new TempWorkspace($this, $prefix);
	}

	/** Creates a cleanup-managed workspace beneath an intentional fixture root. */
	public function workspaceIn(string $directory, string $prefix='dataphyre-workspace'): TempWorkspace {
		return new TempWorkspace($this, $prefix, $directory);
	}

	/** Opens the runner-owned fixture workspace declared through sandboxesRootpath(). */
	public function rootpathWorkspace(string $key): RootpathWorkspace {
		return new RootpathWorkspace($key);
	}

	/** Converts slash-agnostic fixture notation into this runtime's path form. */
	public function nativePath(string $path): string {
		return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
	}

	/** Converts a runtime path into stable slash notation for portable contracts. */
	public function portablePath(string $path): string {
		return PathSemantics::normalize($path);
	}

	/** Mirrors Dataphyre's case-insensitive Windows filesystem policy. */
	public function usesWindowsPathSemantics(): bool {
		return DIRECTORY_SEPARATOR==='\\';
	}

	private static function removeTemporaryPath(string $path): void {
		if(is_link($path) || is_file($path)){
			if(!@unlink($path) && (is_link($path) || is_file($path))){
				throw new \RuntimeException('Unable to remove temporary test file: '.$path);
			}
			return;
		}
		if(!is_dir($path)){
			return;
		}
		$items=scandir($path);
		if($items===false){
			throw new \RuntimeException('Unable to read temporary test directory: '.$path);
		}
		foreach($items as $item){
			if($item!=='.' && $item!=='..'){
				self::removeTemporaryPath($path.'/'.$item);
			}
		}
		if(!@rmdir($path) && is_dir($path)){
			throw new \RuntimeException('Unable to remove temporary test directory: '.$path);
		}
	}
}
