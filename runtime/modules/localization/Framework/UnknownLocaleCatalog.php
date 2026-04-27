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

final class UnknownLocaleCatalog implements Countable, IteratorAggregate, \JsonSerializable {

	/** @var array<string, UnknownLocaleEntry> */
	private readonly array $entries;

	public function __construct(array $entries=[]) {
		$this->entries=$entries;
	}

	public static function fromArray(array $entries): self {
		$normalized_entries=[];
		foreach($entries as $name=>$entry){
			if(is_array($entry)){
				$normalized_entry=UnknownLocaleEntry::fromArray((string)$name, $entry);
				$normalized_entries[$normalized_entry->name()]=$normalized_entry;
			}
		}
		return new self($normalized_entries);
	}

	public function all(): array {
		return $this->entries;
	}

	public function names(): array {
		return array_keys($this->entries);
	}

	public function first(): ?UnknownLocaleEntry {
		foreach($this->entries as $entry){
			return $entry;
		}
		return null;
	}

	public function get(string $name): ?UnknownLocaleEntry {
		return $this->entries[$this->normalizeName($name)] ?? null;
	}

	public function has(string $name): bool {
		return array_key_exists($this->normalizeName($name), $this->entries);
	}

	public function count(): int {
		return count($this->entries);
	}

	public function getIterator(): Traversable {
		return new ArrayIterator($this->entries);
	}

	public function jsonSerialize(): array {
		$entries=[];
		foreach($this->entries as $name=>$entry){
			$entries[$name]=$entry->jsonSerialize();
		}
		return $entries;
	}

	private function normalizeName(string $name): string {
		return strtoupper(trim($name));
	}
}
