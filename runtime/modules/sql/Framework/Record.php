<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

class Record implements ArrayAccess, Countable, IteratorAggregate, \JsonSerializable {

	public function __construct(
		private readonly array $row,
		private readonly ?TableSchema $schema=null,
		private readonly ?string $repository_class=null,
		private readonly ?string $primary_key=null
	){}

	public function repositoryClass(): ?string {
		return $this->repository_class;
	}

	public function schema(): ?TableSchema {
		return $this->schema;
	}

	public function primaryKeyName(): ?string {
		return $this->schema?->primaryKey() ?? $this->primary_key;
	}

	public function id(): mixed {
		$primary_key=$this->primaryKeyName();
		return $primary_key!==null ? ($this->row[$primary_key] ?? null) : null;
	}

	public function has(string $column): bool {
		return array_key_exists($column, $this->row);
	}

	public function get(string $column, mixed $default=null): mixed {
		return $this->row[$column] ?? $default;
	}

	public function money(string $amount_column, ?string $currency_column='currency', ?string $currency=null): mixed {
		$owner=$this->repository_class ?? static::class;
		$mapping=CurrencyBridge::normalizeMoneyMapping($amount_column, $currency_column, $currency, $amount_column, $owner);
		$row=CurrencyBridge::applyMoneyMapping($this->row, $mapping, $owner);
		return $row[$mapping['target_column']] ?? null;
	}

	public function storedMoney(string|array $target_column='stored_money', array $definition=[]): mixed {
		$owner=$this->repository_class ?? static::class;
		if(is_array($target_column)){
			$definition=$target_column;
			$target_column='stored_money';
		}
		$mapping=CurrencyBridge::normalizeStoredMoneyMapping($definition, $target_column, $owner);
		$row=CurrencyBridge::applyStoredMoneyMapping($this->row, $mapping, $owner);
		return $row[$mapping['target_column']] ?? null;
	}

	public function only(array $columns): array {
		$selected=[];
		foreach($columns as $column){
			$column=(string)$column;
			if(array_key_exists($column, $this->row)){
				$selected[$column]=$this->row[$column];
			}
		}
		return $selected;
	}

	public function except(array $columns): array {
		$columns=array_fill_keys(array_map(static fn(mixed $column): string => (string)$column, $columns), true);
		return array_filter(
			$this->row,
			static fn(mixed $_value, string $column): bool => !isset($columns[$column]),
			ARRAY_FILTER_USE_BOTH
		);
	}

	public function toArray(): array {
		return $this->row;
	}

	public function count(): int {
		return count($this->row);
	}

	public function getIterator(): Traversable {
		return new ArrayIterator($this->row);
	}

	public function offsetExists(mixed $offset): bool {
		return array_key_exists((string)$offset, $this->row);
	}

	public function offsetGet(mixed $offset): mixed {
		return $this->get((string)$offset);
	}

	public function offsetSet(mixed $offset, mixed $value): void {
		throw new \LogicException('Dataphyre records are immutable.');
	}

	public function offsetUnset(mixed $offset): void {
		throw new \LogicException('Dataphyre records are immutable.');
	}

	public function __get(string $name): mixed {
		return $this->get($name);
	}

	public function __isset(string $name): bool {
		return $this->has($name);
	}

	public function jsonSerialize(): array {
		return $this->row;
	}
}
