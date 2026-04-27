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

final class PageResult implements Countable, IteratorAggregate, \JsonSerializable {

	public function __construct(
		private readonly array $items,
		private readonly int $total,
		private readonly int $page,
		private readonly int $per_page
	){}

	public function items(): array {
		return $this->items;
	}

	public function first(): mixed {
		return $this->items[0] ?? null;
	}

	public function values(): array {
		return array_values($this->items);
	}

	public function total(): int {
		return $this->total;
	}

	public function page(): int {
		return $this->page;
	}

	public function perPage(): int {
		return $this->per_page;
	}

	public function lastPage(): int {
		if($this->per_page<=0){
			return 1;
		}
		return max(1, (int)ceil($this->total / $this->per_page));
	}

	public function hasMorePages(): bool {
		return $this->page < $this->lastPage();
	}

	public function hasPreviousPage(): bool {
		return $this->page > 1;
	}

	public function firstItemIndex(): ?int {
		if($this->items===[]){
			return null;
		}
		return (($this->page - 1) * $this->per_page) + 1;
	}

	public function lastItemIndex(): ?int {
		if($this->items===[]){
			return null;
		}
		return $this->firstItemIndex() + count($this->items) - 1;
	}

	public function count(): int {
		return count($this->items);
	}

	public function getIterator(): Traversable {
		return new ArrayIterator($this->items);
	}

	public function map(callable $mapper): self {
		return new self(
			array_map($mapper, $this->items),
			$this->total,
			$this->page,
			$this->per_page
		);
	}

	public function pluck(string $column, ?string $key_column=null): array {
		$plucked=[];
		foreach($this->items as $item){
			$value=self::extractValue($item, $column);
			if($key_column===null){
				$plucked[]=$value;
				continue;
			}
			$key=self::extractValue($item, $key_column);
			if($key===null){
				continue;
			}
			$plucked[(string)$key]=$value;
		}
		return $plucked;
	}

	public function keyBy(string $column): array {
		$keyed=[];
		foreach($this->items as $item){
			$key=self::extractValue($item, $column);
			if($key===null){
				continue;
			}
			$keyed[(string)$key]=$item;
		}
		return $keyed;
	}

	public function jsonSerialize(): array {
		return [
			'items'=>$this->items,
			'total'=>$this->total,
			'page'=>$this->page,
			'per_page'=>$this->per_page,
			'last_page'=>$this->lastPage(),
			'has_more_pages'=>$this->hasMorePages(),
			'has_previous_page'=>$this->hasPreviousPage(),
			'first_item_index'=>$this->firstItemIndex(),
			'last_item_index'=>$this->lastItemIndex(),
		];
	}

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
