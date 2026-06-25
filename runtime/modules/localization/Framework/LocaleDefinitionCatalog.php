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
 * Holds a filtered page of locale definitions.
 *
 * LocaleDefinitionCatalog is the read-model returned by localization discovery
 * and maintenance queries. It preserves the filters, limit, and offset that
 * produced the entries so admin UI, tests, and diagnostics can explain both the
 * catalog contents and the query window used to fetch them.
 */
final class LocaleDefinitionCatalog implements Countable, IteratorAggregate, \JsonSerializable {

	/** @var array<int, LocaleDefinition> Ordered locale definitions in the current page. */
	private readonly array $entries;

	/** @var array{filters:array,limit:int,offset:int,entries:array<int,array>}|null */
	private ?array $serialized=null;

	/**
	 * Stores query metadata and locale definitions.
	 *
	 * Entries are expected to already be LocaleDefinition instances when passed
	 * directly. Use fromArray() when rehydrating manifest or transport payloads.
	 *
	 * @param array<string, mixed> $filters Filters applied to the discovery query.
	 * @param int $limit Maximum number of locale definitions requested.
	 * @param int $offset Number of matching definitions skipped before this page.
	 * @param array<int, LocaleDefinition> $entries Definitions included in this catalog page.
	 */
	public function __construct(
		private readonly array $filters=[],
		private readonly int $limit=250,
		private readonly int $offset=0,
		array $entries=[]
	){
		$this->entries=$entries;
	}

	/**
	 * Rehydrates a catalog from locale definition arrays.
	 *
	 * Non-array entries are ignored because only array payloads can be safely
	 * normalized into LocaleDefinition objects without guessing caller intent.
	 *
	 * @param array<int, mixed> $entries Serialized locale definitions.
	 * @param array<string, mixed> $filters Filters associated with the query result.
	 * @param int $limit Maximum number of definitions requested.
	 * @param int $offset Number of matching definitions skipped before this page.
	 * @return self Catalog containing normalized LocaleDefinition entries.
	 */
	public static function fromArray(array $entries, array $filters=[], int $limit=250, int $offset=0): self {
		$normalizedEntries=[];
		foreach($entries as $entry){
			if(is_array($entry)){
				$normalizedEntries[]=LocaleDefinition::fromArray($entry);
			}
		}
		return new self($filters, $limit, $offset, $normalizedEntries);
	}

	/**
	 * Returns the filters that produced this catalog page.
	 *
	 * @return array<string, mixed> Query filters preserved for diagnostics and pagination UIs.
	 */
	public function filters(): array {
		return $this->filters;
	}

	/**
	 * Returns the requested page size.
	 *
	 * @return int Maximum number of entries requested for this catalog.
	 */
	public function limit(): int {
		return $this->limit;
	}

	/**
	 * Returns the query offset.
	 *
	 * @return int Number of matching definitions skipped before this catalog page.
	 */
	public function offset(): int {
		return $this->offset;
	}

	/**
	 * Returns every locale definition in this catalog page.
	 *
	 * @return array<int, LocaleDefinition> Ordered locale definitions.
	 */
	public function all(): array {
		return $this->entries;
	}

	/**
	 * Returns the first locale definition in the current page.
	 *
	 * @return ?LocaleDefinition First definition, or null when the catalog is empty.
	 */
	public function first(): ?LocaleDefinition {
		foreach($this->entries as $entry){
			return $entry;
		}
		return null;
	}

	/**
	 * Counts the definitions in the current page.
	 *
	 * @return int Number of LocaleDefinition entries stored in this catalog.
	 */
	public function count(): int {
		return count($this->entries);
	}

	/**
	 * Iterates over the locale definitions in page order.
	 *
	 * @return Traversable<int, LocaleDefinition> Iterator for foreach consumers.
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator($this->entries);
	}

	/**
	 * Serializes query metadata and locale definitions.
	 *
	 * @return array{filters:array,limit:int,offset:int,entries:array<int,array>}
	 */
	public function jsonSerialize(): array {
		if($this->serialized!==null){
			return $this->serialized;
		}
		$entries=[];
		foreach($this->entries as $entry){
			$entries[]=$entry->jsonSerialize();
		}
		return $this->serialized=[
			'filters'=>$this->filters,
			'limit'=>$this->limit,
			'offset'=>$this->offset,
			'entries'=>$entries,
		];
	}
}
