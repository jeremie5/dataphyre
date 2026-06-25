<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\Database\DB;
use Dataphyre\Database\TableQuery;

/**
 * Minimal active-record style base for MVC data objects.
 *
 * Model provides table-name inference, a TableQuery factory, a simple primary
 * key lookup helper, and an in-memory attribute bag. It does not persist
 * itself; database reads happen through the SQL module and writes remain the
 * responsibility of callers or richer model subclasses.
 */
abstract class Model {

	/** Explicit table name override; null enables class-name inference. */
	protected static ?string $table=null;

	/** @var array<string, mixed> Runtime attributes assigned to this model instance. */
	protected array $attributes=[];

	/**
	 * Creates a model and fills its attribute bag.
	 *
	 *
	 * @param array<string, mixed> $attributes Initial attributes keyed by field name.
	 */
	public function __construct(array $attributes=[]){
		$this->fill($attributes);
	}

	/**
	 * Returns the SQL table name for the model class.
	 *
	 * Subclasses may set static::$table. Otherwise the short class name is
	 * converted from PascalCase to snake_case and pluralized by appending "s".
	 *
	 * @return string Table name used by query() and find().
	 */
	public static function table(): string {
		if(static::$table!==null && trim(static::$table)!==''){
			return static::$table;
		}
		$short=(new \ReflectionClass(static::class))->getShortName();
		return strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $short)).'s';
	}

	/**
	 * Creates a SQL table query for this model's table.
	 *
	 *
	 * @return TableQuery Query builder targeting the model table.
	 */
	public static function query(): TableQuery {
		return DB::table(static::table());
	}

	/**
	 * Finds one record by key and returns it as an array.
	 *
	 * This helper intentionally returns the raw record array rather than a model
	 * instance, keeping the base class lightweight and avoiding implicit
	 * hydration semantics.
	 *
	 * @param mixed $id Value to compare against the lookup key.
	 * @param string $key Column name to filter on, defaulting to id.
	 * @return ?array<string, mixed> First matching record, or null when not found.
	 */
	public static function find(mixed $id, string $key='id'): ?array {
		$record=static::query()->where($key, '=', $id)->first();
		return is_array($record) ? $record : null;
	}

	/**
	 * Merges string-keyed attributes into the model.
	 *
	 * Numeric keys are ignored so list payloads cannot accidentally become
	 * attributes. Existing keys are overwritten by later values.
	 *
	 * @param array<string|int, mixed> $attributes Attribute payload to merge.
	 * @return static Same model instance for fluent setup.
	 */
	public function fill(array $attributes): static {
		foreach($attributes as $key=>$value){
			if(is_string($key)){
				$this->attributes[$key]=$value;
			}
		}
		return $this;
	}

	/**
	 * Reads one attribute from the model.
	 *
	 *
	 * @param string $key Attribute name.
	 * @param mixed $default Value returned when the attribute is absent.
	 * @return mixed stored attribute value, or the caller default when absent or null.
	 */
	public function get(string $key, mixed $default=null): mixed {
		return $this->attributes[$key] ?? $default;
	}

	/**
	 * Sets one attribute value.
	 *
	 *
	 * @param string $key Attribute name.
	 * @param mixed $value Attribute value.
	 * @return static Same model instance for fluent mutation.
	 */
	public function set(string $key, mixed $value): static {
		$this->attributes[$key]=$value;
		return $this;
	}

	/**
	 * Returns the model's current attributes.
	 *
	 * @return array<string, mixed> Attribute bag suitable for JSON, views, or diagnostics.
	 */
	public function toArray(): array {
		return $this->attributes;
	}
}
