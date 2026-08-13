<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\AssertionContext;

final class FakeStorage {

	/** @var array<string, string> */
	private array $objects=[];

	public function put(string $path, string $contents): void {
		$this->objects[$this->path($path)]=$contents;
	}

	public function write(string $path, string $contents): void {
		$this->put($path, $contents);
	}

	public function get(string $path, ?string $default=null): ?string {
		$path=$this->path($path);
		return $this->objects[$path] ?? $default;
	}

	public function read(string $path, ?string $default=null): ?string {
		return $this->get($path, $default);
	}

	public function exists(string $path): bool {
		return array_key_exists($this->path($path), $this->objects);
	}

	public function has(string $path): bool {
		return $this->exists($path);
	}

	public function delete(string $path): void {
		unset($this->objects[$this->path($path)]);
	}

	public function remove(string $path): void {
		$this->delete($path);
	}

	public function url(string $path): string {
		return 'test-storage://'.$this->path($path);
	}

	/** @return array<int, string> */
	public function files(string $prefix=''): array {
		$prefix=$this->path($prefix);
		$files=[];
		foreach(array_keys($this->objects) as $path){
			if($prefix==='' || str_starts_with($path, $prefix)){
				$files[]=$path;
			}
		}
		sort($files);
		return $files;
	}

	/** @return array<string, string> */
	public function all(): array {
		ksort($this->objects);
		return $this->objects;
	}

	public function assertExists(AssertionContext $t, string $path): void {
		$t->isTrue($this->exists($path), 'Expected fake storage path to exist.');
	}

	public function assertMissing(AssertionContext $t, string $path): void {
		$t->isFalse($this->exists($path), 'Expected fake storage path to be missing.');
	}

	public function assertStored(AssertionContext $t, string $path, ?string $contents=null): void {
		$this->assertExists($t, $path);
		if($contents!==null){
			$t->same($contents, $this->get($path), 'Expected fake storage contents to match.');
		}
	}

	private function path(string $path): string {
		return trim(str_replace('\\', '/', $path), '/');
	}
}
