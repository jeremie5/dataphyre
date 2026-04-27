<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

final class LocaleCatalog implements Countable, IteratorAggregate {

	public function __construct(
		private readonly string $scope,
		private readonly string $path,
		private readonly string $language,
		private readonly array $entries
	){}

	public function scope(): string {
		return $this->scope;
	}

	public function path(): string {
		return $this->path;
	}

	public function language(): string {
		return $this->language;
	}

	public function all(): array {
		return $this->entries;
	}

	public function get(string $key, mixed $default=null): mixed {
		return $this->entries[$key] ?? $default;
	}

	public function has(string $key): bool {
		return array_key_exists($key, $this->entries);
	}

	public function count(): int {
		return count($this->entries);
	}

	public function getIterator(): Traversable {
		return new ArrayIterator($this->entries);
	}
}
