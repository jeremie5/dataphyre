<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Traversal-safe fixture builder rooted in a runner-owned ROOTPATH sandbox. */
final class RootpathWorkspace {

	public function __construct(private string $key) {
		RootpathSandbox::normalizeDeclaredKeys([$key]);
	}

	/** Removes prior fixture contents while preserving the runner ownership marker. */
	public function reset(): self {
		RootpathSandbox::reset($this->key);
		return $this;
	}

	/** Returns the verified sandbox root without a trailing separator. */
	public function root(): string {
		return rtrim(RootpathSandbox::root($this->key), '/');
	}

	/** Resolves one traversal-safe fixture path beneath the sandbox. */
	public function path(string $relative=''): string {
		return rtrim(RootpathSandbox::path($this->key, $relative), '/');
	}

	/** Creates a fixture directory beneath the sandbox. */
	public function directory(string $relative): string {
		$path=$this->path($relative);
		if($path===$this->root()){
			return $path;
		}
		if(!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)){
			throw new \RuntimeException('Unable to create ROOTPATH workspace directory: '.$path);
		}
		return $path;
	}

	/** Writes one fixture file beneath the sandbox, creating parents as needed. */
	public function file(string $relative, string $contents=''): string {
		$relative=str_replace('\\', '/', trim($relative));
		if($relative==='' || basename($relative)===RootpathSandbox::MARKER){
			throw new \InvalidArgumentException('ROOTPATH workspace files require a non-marker relative path.');
		}
		$path=$this->path($relative);
		$parent=dirname($relative);
		if($parent!=='.'){
			$this->directory($parent);
		}
		if(file_put_contents($path, $contents)===false){
			throw new \RuntimeException('Unable to write ROOTPATH workspace file: '.$path);
		}
		return $path;
	}
}
