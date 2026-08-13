<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\RuntimeContext;

/** Managed temporary filesystem tree with traversal-safe, intention-named APIs. */
final class TempWorkspace {
	/** @var list<string> Complete dependency closure of the executable code worker. */
	private const CODE_WORKER_TOOLING=[
		'bootstrap.php',
		'PhpRuntime.php',
		'TypeInventory.php',
		'PathSemantics.php',
		'CoverageLineNormalizer.php',
		'PhpdbgLineMap.php',
		'WorkerCoverage.php',
		'CoverageSubprocess.php',
		'code_worker.php',
	];

	private string $root;

	public function __construct(RuntimeContext $context, string $prefix='dataphyre-workspace', ?string $directory=null) {
		$this->root=$directory===null
			? $context->tempDirectory($prefix)
			: $context->tempDirectoryIn($directory, $prefix);
	}

	public function root(): string {
		return $this->root;
	}

	public function path(string $relative=''): string {
		$relative=str_replace('\\', '/', trim($relative));
		if($relative===''){
			return $this->root;
		}
		if(str_starts_with($relative, '/') || preg_match('#^[A-Za-z]:#', $relative)===1 || str_contains($relative, "\0")){
			throw new \InvalidArgumentException('Temporary workspace paths must be relative.');
		}
		$segments=[];
		foreach(explode('/', $relative) as $segment){
			if($segment==='' || $segment==='.'){
				continue;
			}
			if($segment==='..'){
				if($segments===[]){
					throw new \InvalidArgumentException('Temporary workspace path cannot escape its root.');
				}
				array_pop($segments);
				continue;
			}
			$segments[]=$segment;
		}
		return $segments===[] ? $this->root : $this->root.'/'.implode('/', $segments);
	}

	public function directory(string $relative): string {
		$path=$this->path($relative);
		if(!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)){
			throw new \RuntimeException('Unable to create temporary workspace directory: '.$path);
		}
		return $path;
	}

	public function file(string $relative, string $contents=''): string {
		$path=$this->path($relative);
		$this->directory(dirname(str_replace('\\', '/', $relative)));
		if(file_put_contents($path, $contents)===false){
			throw new \RuntimeException('Unable to write temporary workspace file: '.$path);
		}
		return $path;
	}

	/**
	 * Advances one fixture file's modification time without sleeping.
	 *
	 * This is the deterministic vocabulary for cache-invalidation scenarios:
	 * tests describe the timestamp transition they need while the workspace owns
	 * path safety, existence checks, clock granularity, and stat-cache cleanup.
	 */
	public function advanceMtime(string $relative, int $seconds=2): string {
		$path=$this->path($relative);
		if(!is_file($path)){
			throw new \RuntimeException('Temporary workspace file is unavailable: '.$path);
		}
		$current=filemtime($path);
		if(!is_int($current)){
			throw new \RuntimeException('Unable to read temporary workspace file modification time: '.$path);
		}
		$target=max(time(), $current)+max(1, $seconds);
		if(!touch($path, $target)){
			throw new \RuntimeException('Unable to advance temporary workspace file modification time: '.$path);
		}
		clearstatcache(true, $path);
		return $path;
	}

	public function copy(string $source, string $relative): string {
		if(!is_file($source)){
			throw new \RuntimeException('Temporary workspace source file is unavailable: '.$source);
		}
		$contents=file_get_contents($source);
		if(!is_string($contents)){
			throw new \RuntimeException('Unable to read temporary workspace source file: '.$source);
		}
		return $this->file($relative, $contents);
	}

	/** Installs a runnable code-worker dependency closure into a fixture repo. */
	public function installCodeWorkerTooling(?string $sourceDirectory=null): self {
		$sourceDirectory=rtrim($sourceDirectory ?? dirname(__DIR__), '/\\');
		foreach(self::CODE_WORKER_TOOLING as $file){
			$this->copy($sourceDirectory.'/'.$file, 'runtime/modules/testing/tooling/'.$file);
		}
		$testKitSource=$sourceDirectory.'/TestKit';
		if(!is_dir($testKitSource)){
			throw new \RuntimeException('TestKit source directory is unavailable: '.$testKitSource);
		}
		foreach(TestKitAutoloader::sourceFiles($testKitSource) as $source){
			$relative=substr($source, strlen(str_replace('\\', '/', $testKitSource))+1);
			$this->copy($source, 'runtime/modules/testing/tooling/TestKit/'.$relative);
		}
		return $this;
	}
}
