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

/**
 * Immutable catalog of localized entries for a scope, path, and language.
 *
 * A catalog represents one resolved locale source after loading: its logical
 * scope, source path, language code, and key/value entries. It is countable
 * and iterable so callers can inspect translation keys without knowing how the
 * locale source was discovered or hydrated.
 */
final class LocaleCatalog implements Countable, IteratorAggregate {

	/**
	 * Stores the locale catalog identity and entries.
	 *
	 * The constructor does not normalize keys or values. Callers are expected to
	 * pass the exact entry map loaded for the source so diagnostics can reflect
	 * the underlying catalog faithfully.
	 *
	 * @param string $scope Logical localization scope such as app, module, or package.
	 * @param string $path Source path or catalog identifier.
	 * @param string $language Language code represented by the entries.
	 * @param array<string, mixed> $entries Localized values keyed by translation key.
	 */
	public function __construct(
		private readonly string $scope,
		private readonly string $path,
		private readonly string $language,
		private readonly array $entries
	){}

	/**
	 * Returns the logical scope for this catalog.
	 *
	 * @return string Scope used to group or resolve this locale source.
	 */
	public function scope(): string {
		return $this->scope;
	}

	/**
	 * Returns the source path or identifier for this catalog.
	 *
	 * @return string Path or catalog identifier associated with the loaded entries.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Returns the language code represented by this catalog.
	 *
	 * @return string Language code used when loading the entries.
	 */
	public function language(): string {
		return $this->language;
	}

	/**
	 * Returns the complete translation entry map.
	 *
	 * @return array<string, mixed> Localized values keyed by translation key.
	 */
	public function all(): array {
		return $this->entries;
	}

	/**
	 * Returns a localized entry or caller-provided fallback.
	 *
	 * Lookup uses exact key matching and does not apply fallback languages,
	 * interpolation, or pluralization. Those policies belong to higher-level
	 * localization services.
	 *
	 * @param string $key Translation key to read.
	 * @param mixed $default Fallback returned when the key is not present.
	 * @return mixed Stored entry value or `$default`.
	 */
	public function get(string $key, mixed $default=null): mixed {
		return $this->entries[$key] ?? $default;
	}

	/**
	 * Checks whether the catalog explicitly contains a key.
	 *
	 * `array_key_exists()` is used so keys with `null` values still count as
	 * present.
	 *
	 * @param string $key Translation key to test.
	 * @return bool `true` when the key exists in the catalog.
	 */
	public function has(string $key): bool {
		return array_key_exists($key, $this->entries);
	}

	/**
	 * Counts entries in this catalog.
	 *
	 * @return int Number of translation keys in the catalog.
	 */
	public function count(): int {
		return count($this->entries);
	}

	/**
	 * Iterates over translation entries in their stored order.
	 *
	 *
	 * @return Traversable<string, mixed> Iterator keyed by translation key.
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator($this->entries);
	}
}
