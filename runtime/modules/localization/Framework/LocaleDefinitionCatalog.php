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

final class LocaleDefinitionCatalog implements Countable, IteratorAggregate, \JsonSerializable {

	/** @var array<int, LocaleDefinition> */
	private readonly array $entries;

	public function __construct(
		private readonly array $filters=[],
		private readonly int $limit=250,
		private readonly int $offset=0,
		array $entries=[]
	){
		$this->entries=$entries;
	}

	public static function fromArray(array $entries, array $filters=[], int $limit=250, int $offset=0): self {
		$normalized_entries=[];
		foreach($entries as $entry){
			if(is_array($entry)){
				$normalized_entries[]=LocaleDefinition::fromArray($entry);
			}
		}
		return new self($filters, $limit, $offset, $normalized_entries);
	}

	public function filters(): array {
		return $this->filters;
	}

	public function limit(): int {
		return $this->limit;
	}

	public function offset(): int {
		return $this->offset;
	}

	public function all(): array {
		return $this->entries;
	}

	public function first(): ?LocaleDefinition {
		foreach($this->entries as $entry){
			return $entry;
		}
		return null;
	}

	public function count(): int {
		return count($this->entries);
	}

	public function getIterator(): Traversable {
		return new ArrayIterator($this->entries);
	}

	public function jsonSerialize(): array {
		return [
			'filters'=>$this->filters,
			'limit'=>$this->limit,
			'offset'=>$this->offset,
			'entries'=>array_map(
				static fn(LocaleDefinition $entry): array => $entry->jsonSerialize(),
				$this->entries
			),
		];
	}
}
