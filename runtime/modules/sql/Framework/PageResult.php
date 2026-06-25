<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Immutable paginated result set returned by SQL framework queries.
 *
 * PageResult carries the current page items together with total-count metadata
 * captured by the query that produced it. It is intentionally side-effect free:
 * helpers reshape the in-memory page, expose navigation metadata, or serialize the
 * result without re-querying the database.
 */
final class PageResult implements Countable, IteratorAggregate, \JsonSerializable {

	private ?array $serialized=null;

	private ?string $pluckColumn=null;

	private ?string $pluckKeyColumn=null;

	private ?array $pluckPayload=null;

	private ?string $keyByColumn=null;

	private ?array $keyByPayload=null;

	private static ?array $lastPluckItems=null;

	private static ?string $lastPluckColumn=null;

	private static ?string $lastPluckKeyColumn=null;

	private static ?array $lastPluckPayload=null;

	/**
	 * Creates a paginated result value.
	 *
	 * Page numbers and item indexes are 1-based for UI and API pagination
	 * conventions. The constructor trusts the query layer to provide consistent
	 * total/page/per-page values.
	 *
	 * @param array<int|string, mixed> $items Items returned for the current page.
	 * @param int $total Total number of rows matching the original query.
	 * @param int $page Current 1-based page number.
	 * @param int $perPage Requested page size.
	 */
	public function __construct(
		private readonly array $items,
		private readonly int $total,
		private readonly int $page,
		private readonly int $perPage
	){}

	/**
	 * Returns the page items using their current keys.
	 *
	 * @return array<int|string, mixed> Items for this page.
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Returns the first item in the page.
	 *
	 * @return mixed First item, or null when the page is empty.
	 */
	public function first(): mixed {
		return $this->items[0] ?? null;
	}

	/**
	 * Returns page items with numeric keys reindexed from zero.
	 *
	 * @return array<int, mixed> Reindexed page items.
	 */
	public function values(): array {
		return array_values($this->items);
	}

	/**
	 * Returns the total number of rows matching the query.
	 *
	 * @return int Total row count across all pages.
	 */
	public function total(): int {
		return $this->total;
	}

	/**
	 * Returns the current page number.
	 *
	 * @return int Current 1-based page number.
	 */
	public function page(): int {
		return $this->page;
	}

	/**
	 * Returns the requested page size.
	 *
	 * @return int Number of rows requested per page.
	 */
	public function perPage(): int {
		return $this->perPage;
	}

	/**
	 * Calculates the last available page number.
	 *
	 * Invalid or zero per-page values collapse to page 1 to keep navigation metadata
	 * safe for callers.
	 *
	 * @return int Last 1-based page number, minimum 1.
	 */
	public function lastPage(): int {
		if($this->perPage<=0){
			return 1;
		}
		return max(1, (int)ceil($this->total / $this->perPage));
	}

	/**
	 * Reports whether a later page exists.
	 *
	 * @return bool True when page() is lower than lastPage().
	 */
	public function hasMorePages(): bool {
		return $this->page < $this->lastPage();
	}

	/**
	 * Reports whether an earlier page exists.
	 *
	 * @return bool True when page() is greater than 1.
	 */
	public function hasPreviousPage(): bool {
		return $this->page > 1;
	}

	/**
	 * Returns the 1-based global index of the first item on this page.
	 *
	 * @return int|null First item index, or null when the page is empty.
	 */
	public function firstItemIndex(): ?int {
		if($this->items===[]){
			return null;
		}
		return (($this->page - 1) * $this->perPage) + 1;
	}

	/**
	 * Returns the 1-based global index of the last item on this page.
	 *
	 * @return int|null Last item index, or null when the page is empty.
	 */
	public function lastItemIndex(): ?int {
		if($this->items===[]){
			return null;
		}
		return (($this->page - 1) * $this->perPage) + count($this->items);
	}

	/**
	 * Counts items present in the current page.
	 *
	 * @return int Number of items in this page, not the total query count.
	 */
	public function count(): int {
		return count($this->items);
	}

	/**
	 * Returns an iterator over the current page items.
	 *
	 * @return Traversable<int|string, mixed> Iterator preserving item keys.
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator($this->items);
	}

	/**
	 * Maps the current page items while preserving pagination metadata.
	 *
	 * @param callable $mapper Function passed to array_map() for each item.
	 * @return self New PageResult containing mapped items and the same pagination totals.
	 */
	public function map(callable $mapper): self {
		return new self(
			array_map($mapper, $this->items),
			$this->total,
			$this->page,
			$this->perPage
		);
	}

	/**
	 * Extracts one column from each page item.
	 *
	 * Items may be arrays, ArrayAccess values, or objects. When keyColumn is
	 * supplied, items without that key are skipped.
	 *
	 * @param string $column Column/property to extract as the value.
	 * @param string|null $keyColumn Optional column/property used as the output key.
	 * @return array<int|string, mixed> Extracted values, optionally keyed by keyColumn.
	 */
	public function pluck(string $column, ?string $keyColumn=null): array {
		if($this->pluckColumn===$column && $this->pluckKeyColumn===$keyColumn && $this->pluckPayload!==null){
			return $this->pluckPayload;
		}
		if(
			self::$lastPluckPayload!==null &&
			self::$lastPluckColumn===$column &&
			self::$lastPluckKeyColumn===$keyColumn &&
			self::$lastPluckItems===$this->items
		){
			return self::$lastPluckPayload;
		}
		$plucked=[];
		$arrayOnly=true;
		foreach($this->items as $item){
			if(!is_array($item)){
				$plucked=[];
				$arrayOnly=false;
				break;
			}
			$value=$item[$column] ?? null;
			if($keyColumn===null){
				$plucked[]=$value;
				continue;
			}
			$key=$item[$keyColumn] ?? null;
			if($key===null){
				continue;
			}
			$plucked[(string)$key]=$value;
		}
		if($arrayOnly){
			$this->pluckColumn=$column;
			$this->pluckKeyColumn=$keyColumn;
			$this->pluckPayload=$plucked;
			self::$lastPluckItems=$this->items;
			self::$lastPluckColumn=$column;
			self::$lastPluckKeyColumn=$keyColumn;
			return self::$lastPluckPayload=$plucked;
		}
		foreach($this->items as $item){
			$value=self::extractValue($item, $column);
			if($keyColumn===null){
				$plucked[]=$value;
				continue;
			}
			$key=self::extractValue($item, $keyColumn);
			if($key===null){
				continue;
			}
			$plucked[(string)$key]=$value;
		}
		$this->pluckColumn=$column;
		$this->pluckKeyColumn=$keyColumn;
		return $this->pluckPayload=$plucked;
	}

	/**
	 * Re-keys page items by a column or property value.
	 *
	 * Items without the requested key value are skipped. Keys are cast to strings to
	 * match PHP array-key behavior across arrays, ArrayAccess, and objects.
	 *
	 * @param string $column Column/property used as the output key.
	 * @return array<string, mixed> Original items keyed by extracted value.
	 */
	public function keyBy(string $column): array {
		if($this->keyByColumn===$column && $this->keyByPayload!==null){
			return $this->keyByPayload;
		}
		$keyed=[];
		$arrayOnly=true;
		foreach($this->items as $item){
			if(!is_array($item)){
				$keyed=[];
				$arrayOnly=false;
				break;
			}
			$key=$item[$column] ?? null;
			if($key===null){
				continue;
			}
			$keyed[(string)$key]=$item;
		}
		if($arrayOnly){
			$this->keyByColumn=$column;
			return $this->keyByPayload=$keyed;
		}
		foreach($this->items as $item){
			$key=self::extractValue($item, $column);
			if($key===null){
				continue;
			}
			$keyed[(string)$key]=$item;
		}
		$this->keyByColumn=$column;
		return $this->keyByPayload=$keyed;
	}

	/**
	 * Serializes items and pagination metadata for JSON APIs.
	 *
	 * @return array<string, mixed> Items plus total, page, per_page, navigation, and item-index metadata.
	 */
	public function jsonSerialize(): array {
		if($this->serialized!==null){
			return $this->serialized;
		}
		$itemCount=count($this->items);
		$lastPage=$this->perPage<=0 ? 1 : max(1, (int)ceil($this->total / $this->perPage));
		$firstItemIndex=$itemCount===0 ? null : (($this->page - 1) * $this->perPage) + 1;
		return $this->serialized=[
			'items'=>$this->items,
			'total'=>$this->total,
			'page'=>$this->page,
			'per_page'=>$this->perPage,
			'last_page'=>$lastPage,
			'has_more_pages'=>$this->page < $lastPage,
			'has_previous_page'=>$this->page > 1,
			'first_item_index'=>$firstItemIndex,
			'last_item_index'=>$firstItemIndex===null ? null : $firstItemIndex + $itemCount - 1,
		];
	}

	/**
	 * Extracts a named value from supported item shapes.
	 *
	 * @param mixed $item Array, ArrayAccess value, object, or unsupported value.
	 * @param string $column Column/property name to extract.
	 * @return mixed Extracted value, or null when unavailable.
	 */
	private static function extractValue(mixed $item, string $column): mixed {
		if(is_array($item)){
			return $item[$column] ?? null;
		}
		if($item instanceof \ArrayAccess){
			return $item[$column] ?? null;
		}
		if(is_object($item)){
			return $item->{$column} ?? null;
		}
		return null;
	}
}
