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
 * Immutable catalog of localization keys that could not be resolved.
 *
 * Unknown-locale catalogs let maintenance tools and diagnostics expose missing
 * translation entries as structured objects keyed by normalized name. The catalog preserves `UnknownLocaleEntry` payloads and offers
 * countable, iterable, lookup, and JSON surfaces.
 */
final class UnknownLocaleCatalog implements Countable, IteratorAggregate, \JsonSerializable {

	/** @var array<string, UnknownLocaleEntry> Unknown locale entries keyed by normalized uppercase name. */
	private readonly array $entries;

	/**
	 * Stores normalized unknown locale entries.
	 *
	 * @param array<string, UnknownLocaleEntry> $entries Entries keyed by normalized name.
	 */
	public function __construct(array $entries=[]) {
		$this->entries=$entries;
	}

	/**
	 * Creates a catalog from raw unknown-locale entry payloads.
	 *
	 * Non-array entries are ignored. Each array payload is normalized through
	 * `UnknownLocaleEntry::fromArray()` and keyed by the entry's normalized name.
	 *
	 * @param array<string, mixed> $entries Raw entries keyed by locale name.
	 * @return self Catalog containing normalized unknown locale entries.
	 */
	public static function fromArray(array $entries): self {
		$normalizedEntries=[];
		foreach($entries as $name=>$entry){
			if(is_array($entry)){
				$normalizedEntry=UnknownLocaleEntry::fromArray((string)$name, $entry);
				$normalizedEntries[$normalizedEntry->name()]=$normalizedEntry;
			}
		}
		return new self($normalizedEntries);
	}

	/**
	 * Returns every unknown locale entry keyed by normalized name.
	 *
	 * @return array<string, UnknownLocaleEntry> Entry map.
	 */
	public function all(): array {
		return $this->entries;
	}

	/**
	 * Returns normalized unknown locale names.
	 *
	 * @return array<int, string> Entry names in catalog order.
	 */
	public function names(): array {
		return array_keys($this->entries);
	}

	/**
	 * Returns the first unknown locale entry.
	 *
	 * @return ?UnknownLocaleEntry First entry, or `null` when the catalog is empty.
	 */
	public function first(): ?UnknownLocaleEntry {
		foreach($this->entries as $entry){
			return $entry;
		}
		return null;
	}

	/**
	 * Looks up an unknown locale entry by case-insensitive name.
	 *
	 * @param string $name Locale key or entry name.
	 * @return ?UnknownLocaleEntry Matching entry, or `null` when absent.
	 */
	public function get(string $name): ?UnknownLocaleEntry {
		return $this->entries[$this->normalizeName($name)] ?? null;
	}

	/**
	 * Checks whether an unknown locale entry exists.
	 *
	 * @param string $name Locale key or entry name.
	 * @return bool `true` when the normalized name is present.
	 */
	public function has(string $name): bool {
		return array_key_exists($this->normalizeName($name), $this->entries);
	}

	/**
	 * Counts unknown locale entries.
	 *
	 * @return int Number of entries in the catalog.
	 */
	public function count(): int {
		return count($this->entries);
	}

	/**
	 * Iterates over entries keyed by normalized name.
	 *
	 * @return Traversable<string, UnknownLocaleEntry> Catalog iterator.
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator($this->entries);
	}

	/**
	 * Serializes unknown locale entries for diagnostics and APIs.
	 *
	 * @return array<string, mixed> Entry payloads keyed by normalized name.
	 */
	public function jsonSerialize(): array {
		$entries=[];
		foreach($this->entries as $name=>$entry){
			$entries[$name]=$entry->jsonSerialize();
		}
		return $entries;
	}

	/**
	 * Normalizes lookup names.
	 *
	 * @param string $name Raw locale entry name.
	 * @return string Trimmed uppercase lookup key.
	 */
	private function normalizeName(string $name): string {
		return strtoupper(trim($name));
	}
}
