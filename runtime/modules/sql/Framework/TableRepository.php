<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

use Dataphyre\Database\Contracts\RecordHydrator;
use Dataphyre\Database\Hydrators\CallbackRecordHydrator;
use Dataphyre\Database\Hydrators\ClassRecordHydrator;
use Dataphyre\Database\Hydrators\RecordObjectHydrator;

abstract class TableRepository {

	abstract protected static function table(): string;

	protected static function schema(): ?TableSchema {
		return null;
	}

	protected static function spec(): QuerySpec {
		return new QuerySpec();
	}

	protected static function hydrator(): mixed {
		return null;
	}

	protected static function recordClass(): ?string {
		return null;
	}

	protected static function moneyColumns(): array {
		return [];
	}

	protected static function storedMoneyColumns(): array {
		return [];
	}

	protected static function inferredRecordClass(): ?string {
		$repository_class=static::class;
		$repository_short_name=substr($repository_class, (int)strrpos($repository_class, '\\')+1);
		if(!str_ends_with($repository_short_name, 'Repository')){
			return null;
		}
		$base_name=substr($repository_short_name, 0, -10);
		if($base_name===''){
			return null;
		}
		$candidates=[];
		if(str_contains($repository_class, '\\Repository\\')){
			$candidates[]=preg_replace(
				'/\\\\Repository\\\\([^\\\\]+)Repository$/',
				'\\\\Record\\\\$1Record',
				$repository_class
			);
		}
		$namespace=substr($repository_class, 0, (int)strrpos($repository_class, '\\'));
		$candidates[]=$namespace.'\\'.$base_name.'Record';
		foreach($candidates as $candidate){
			if(!is_string($candidate) || trim($candidate)===''){
				continue;
			}
			if(class_exists($candidate)){
				return $candidate;
			}
		}
		return null;
	}

	public static function query(): RepositoryQuery {
		return new RepositoryQuery(static::class);
	}

	public static function primaryKey(): ?string {
		return static::schema()?->primaryKey();
	}

	public static function projectionNamed(string $name): array {
		return static::projection($name);
	}

	protected static function defaultHydrator(): RecordHydrator {
		$record_class=static::recordClass() ?? static::inferredRecordClass();
		if($record_class!==null && trim($record_class)!==''){
			return new ClassRecordHydrator(trim($record_class), static::class, static::primaryKey());
		}
		return new RecordObjectHydrator(static::class, static::primaryKey());
	}

	protected static function resolvedHydrator(mixed $hydrator=null): RecordHydrator {
		$source=$hydrator ?? static::hydrator();
		if($source===null){
			return static::defaultHydrator();
		}
		if($source instanceof RecordHydrator){
			return $source;
		}
		if(is_callable($source)){
			return new CallbackRecordHydrator($source);
		}
		if(is_string($source)){
			$source=trim($source);
			if($source===''){
				throw SqlError::invalidHydrator(static::class, $source);
			}
			if(!class_exists($source)){
				throw SqlError::missingHydratorClass(static::class, $source);
			}
			if(is_subclass_of($source, RecordHydrator::class)){
				return new $source();
			}
			return new ClassRecordHydrator($source, static::class, static::primaryKey());
		}
		throw SqlError::invalidHydrator(static::class, $source);
	}

	protected static function columns(array|string $columns='*'): string|array {
		$schema=static::schema();
		if($schema!==null){
			return $schema->columns($columns);
		}
		return QuerySpec::columns($columns);
	}

	protected static function fields(array $fields): array {
		if($fields===[]){
			throw SqlError::invalidFieldPayload(static::class, 'Field payload cannot be empty.');
		}
		$fields=static::normalizedWriteFields($fields);
		$schema=static::schema();
		if($schema!==null){
			return $schema->fields($fields);
		}
		foreach($fields as $column=>$_value){
			if(is_int($column)){
				throw SqlError::invalidFieldPayload(static::class, 'Field payload must be an associative array.');
			}
			$column=trim((string)$column);
			if($column==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $column)!==1){
				throw SqlError::invalidIdentifier('repository field', (string)$column, static::table());
			}
		}
		return $fields;
	}

	protected static function projection(string $name): array {
		$schema=static::schema();
		if($schema===null){
			throw SqlError::missingSchemaForProjection(static::class, $name, static::table());
		}
		return $schema->projection($name);
	}

	protected static function defaultReadCaching(): array {
		return DB::defaultReadCaching();
	}

	protected static function resolveReadCaching(bool|array|string|null $caching=null): bool|array|string|null {
		return $caching ?? static::defaultReadCaching();
	}

	protected static function defaultWriteInvalidation(): bool|array|null {
		return false;
	}

	protected static function normalizedWriteFields(array $fields): array {
		return CurrencyBridge::expandWriteFields(
			$fields,
			static::resolvedMoneyColumns(),
			static::resolvedStoredMoneyColumns(),
			static::class
		);
	}

	protected static function resolveWriteInvalidation(bool|array|null $clear_cache=null): bool|array|null {
		DB::bootRuntimeBridges();
		return $clear_cache ?? static::defaultWriteInvalidation();
	}

	protected static function select_many(array|string $columns='*', ?QuerySpec $spec=null, bool|array|string|null $caching=null): mixed {
		$spec ??= static::spec();
		$compiled=$spec->compile();
		return sql_select(
			static::columns($columns),
			static::table(),
			$compiled['params'],
			$compiled['vars'],
			true,
			static::resolveReadCaching($caching)
		);
	}

	protected static function select_one(array|string $columns='*', ?QuerySpec $spec=null, bool|array|string|null $caching=null): mixed {
		$spec ??= static::spec();
		$compiled=$spec->compile();
		return sql_select(
			static::columns($columns),
			static::table(),
			$compiled['params'],
			$compiled['vars'],
			false,
			static::resolveReadCaching($caching)
		);
	}

	protected static function count_where(?QuerySpec $spec=null, bool|array|string|null $caching=null): int|bool|null {
		$spec=$spec!==null ? (clone $spec) : static::spec();
		$spec=$spec->without_ordering()->without_paging();
		$compiled=$spec->compile();
		return sql_count(
			static::table(),
			$compiled['params'],
			$compiled['vars'],
			static::resolveReadCaching($caching)
		);
	}

	protected static function insert_one(array $fields, bool|array|null $clear_cache=false): mixed {
		return sql_insert(static::table(), $fields, null, $clear_cache);
	}

	protected static function update_where(array $fields, QuerySpec $spec, bool|array|null $clear_cache=false): int|bool|null {
		$compiled=$spec->compile();
		return sql_update(static::table(), $fields, $compiled['params'], $compiled['vars'], $clear_cache);
	}

	protected static function delete_where(QuerySpec $spec, bool|array|null $clear_cache=false): bool|null {
		$compiled=$spec->compile();
		return sql_delete(static::table(), $compiled['params'], $compiled['vars'], $clear_cache);
	}

	public static function all(
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		$result=static::select_many($columns, $spec, $caching);
		return is_array($result) ? $result : [];
	}

	public static function queueAll(
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$spec ??= static::spec();
		$compiled=$spec->compile();
		return sql_select(
			static::columns($columns),
			static::table(),
			$compiled['params'],
			$compiled['vars'],
			true,
			static::resolveReadCaching($caching),
			$queue,
			$callback
		);
	}

	public static function first(
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): ?array {
		$spec=$spec!==null ? (clone $spec) : static::spec();
		$result=static::select_one($columns, $spec->limit(1), $caching);
		return is_array($result) ? $result : null;
	}

	public static function queueFirst(
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$spec=$spec!==null ? (clone $spec) : static::spec();
		$compiled=$spec->limit(1)->compile();
		return sql_select(
			static::columns($columns),
			static::table(),
			$compiled['params'],
			$compiled['vars'],
			false,
			static::resolveReadCaching($caching),
			$queue,
			$callback
		);
	}

	public static function firstOrFail(
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): array {
		$result=static::first($columns, $spec, $caching);
		if($result!==null){
			return $result;
		}
		throw SqlError::recordNotFound(static::class, static::notFoundContext($columns, $spec), $message);
	}

	public static function value(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): mixed {
		$row=static::first($column, $spec, $caching);
		return is_array($row) && array_key_exists($column, $row) ? $row[$column] : null;
	}

	public static function valueOrFail(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		$row=static::firstOrFail($column, $spec, $caching, $message);
		return $row[$column] ?? null;
	}

	public static function pluck(
		string $column,
		?QuerySpec $spec=null,
		?string $key_column=null,
		bool|array|string|null $caching=null
	): array {
		return static::pluckRows(
			static::all(static::pluckColumns($column, $key_column), $spec, $caching),
			$column,
			$key_column
		);
	}

	public static function keyBy(
		string $key_column,
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::keyRowsBy(
			static::all(static::keyColumns($key_column, $columns), $spec, $caching),
			$key_column
		);
	}

	public static function sole(
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): array {
		$rows=static::singleResultRows($columns, $spec, $caching);
		if($rows===[]){
			throw SqlError::recordNotFound(
				static::class,
				static::notFoundContext($columns, $spec),
				$message,
				'Use first() when zero matches are acceptable, or tighten the repository query before calling sole().'
			);
		}
		if(count($rows)>1){
			throw SqlError::multipleRecordsFound(
				static::class,
				static::notFoundContext($columns, $spec, ['matched_rows_sample'=>count($rows)]),
				$message,
				'Use all()/get() when multiple matches are expected, or tighten the repository query until it uniquely identifies a single row.'
			);
		}
		return $rows[0];
	}

	public static function exists(
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): bool {
		$count=static::count_where($spec, $caching);
		return is_int($count) ? $count > 0 : false;
	}

	public static function count(
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): int|bool|null {
		return static::count_where($spec, $caching);
	}

	public static function queueCount(
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$spec=$spec!==null ? (clone $spec) : static::spec();
		$spec=$spec->without_ordering()->without_paging();
		$compiled=$spec->compile();
		return sql_count(
			static::table(),
			$compiled['params'],
			$compiled['vars'],
			static::resolveReadCaching($caching),
			$queue,
			$callback
		);
	}

	public static function aggregate(
		string $function,
		string $column='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::aggregateValue($function, $column, $spec, $caching);
	}

	public static function queueAggregate(
		string $function,
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null,
		bool $distinct=false
	): null|bool {
		$function=static::normalizeAggregateFunction($function);
		$column=static::aggregateColumn($column, $function, $function==='COUNT');
		$compiled=($spec!==null ? clone $spec : static::spec())
			->without_ordering()
			->without_paging()
			->compile();
		return sql_select(
			$function.'('.($distinct ? 'DISTINCT ' : '').$column.') AS aggregate_value',
			static::table(),
			$compiled['params'],
			$compiled['vars'],
			false,
			static::resolveReadCaching($caching),
			$queue,
			static function(mixed $result)use($callback, $function): void{
				$value=is_array($result) ? ($result['aggregate_value'] ?? null) : null;
				$callback(static::normalizeAggregateResult($function, $value));
			}
		);
	}

	public static function sum(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::aggregateValue('SUM', $column, $spec, $caching);
	}

	public static function avg(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::aggregateValue('AVG', $column, $spec, $caching);
	}

	public static function min(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::aggregateValue('MIN', $column, $spec, $caching);
	}

	public static function max(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::aggregateValue('MAX', $column, $spec, $caching);
	}

	public static function countColumn(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): int|bool|null {
		$value=static::aggregateValue('COUNT', $column, $spec, $caching);
		if($value===false || $value===null){
			return $value;
		}
		return is_numeric($value) ? (int)$value : null;
	}

	public static function countDistinct(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): int|bool|null {
		$value=static::aggregateValue('COUNT', $column, $spec, $caching, true);
		if($value===false || $value===null){
			return $value;
		}
		return is_numeric($value) ? (int)$value : null;
	}

	public static function aggregateRowsBy(
		string|array $group_columns,
		string $function,
		string $column='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		bool $distinct=false
	): array {
		$function=static::normalizeAggregateFunction($function);
		$group_columns=static::groupColumns($group_columns);
		$column=static::aggregateColumn($column, $function, $function==='COUNT');
		$compiled=($spec!==null ? clone $spec : static::spec())
			->without_ordering()
			->without_paging()
			->compile();
		$result=sql_select(
			implode(', ', $group_columns).', '.$function.'('.($distinct ? 'DISTINCT ' : '').$column.') AS aggregate_value',
			static::table(),
			static::appendClause($compiled['params'], 'GROUP BY '.implode(', ', $group_columns)),
			$compiled['vars'],
			true,
			static::resolveReadCaching($caching)
		);
		if(!is_array($result)){
			return [];
		}
		return static::normalizeAggregateRows($result, $function);
	}

	public static function countBy(
		string $group_column,
		string $column='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::groupedAggregateMap(
			$group_column,
			static::aggregateRowsBy($group_column, 'COUNT', $column, $spec, $caching)
		);
	}

	public static function countDistinctBy(
		string $group_column,
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::groupedAggregateMap(
			$group_column,
			static::aggregateRowsBy($group_column, 'COUNT', $column, $spec, $caching, true)
		);
	}

	public static function sumBy(
		string $group_column,
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::groupedAggregateMap(
			$group_column,
			static::aggregateRowsBy($group_column, 'SUM', $column, $spec, $caching)
		);
	}

	public static function avgBy(
		string $group_column,
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::groupedAggregateMap(
			$group_column,
			static::aggregateRowsBy($group_column, 'AVG', $column, $spec, $caching)
		);
	}

	public static function minBy(
		string $group_column,
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::groupedAggregateMap(
			$group_column,
			static::aggregateRowsBy($group_column, 'MIN', $column, $spec, $caching)
		);
	}

	public static function maxBy(
		string $group_column,
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::groupedAggregateMap(
			$group_column,
			static::aggregateRowsBy($group_column, 'MAX', $column, $spec, $caching)
		);
	}

	public static function paginate(
		array|string $columns='*',
		?QuerySpec $spec=null,
		int $page=1,
		int $per_page=50,
		bool|array|string|null $caching=null
	): PageResult {
		$page=max(1, $page);
		$per_page=max(1, min(500, $per_page));
		$base_spec=$spec ?? static::spec();
		$total=static::count_where($base_spec, $caching);
		$items=static::all($columns, (clone $base_spec)->for_page($page, $per_page), $caching);
		return new PageResult(
			$items,
			is_int($total) ? max(0, $total) : 0,
			$page,
			$per_page
		);
	}

	public static function hydrateRow(array $row, mixed $hydrator=null): mixed {
		$row=static::applyRepositoryMoneyColumns($row);
		return static::resolvedHydrator($hydrator)->hydrate($row, static::schema());
	}

	public static function hydrateRows(array $rows, mixed $hydrator=null): array {
		$resolved=[];
		foreach($rows as $key=>$row){
			if(!is_array($row)){
				continue;
			}
			$resolved[$key]=static::hydrateRow($row, $hydrator);
		}
		return $resolved;
	}

	public static function allHydrated(
		array|string $columns='*',
		?QuerySpec $spec=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): array {
		return static::hydrateRows(
			static::all($columns, $spec, $caching),
			$hydrator
		);
	}

	public static function firstHydrated(
		array|string $columns='*',
		?QuerySpec $spec=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		$row=static::first($columns, $spec, $caching);
		return $row!==null ? static::hydrateRow($row, $hydrator) : null;
	}

	public static function allRecords(
		array|string $columns='*',
		?QuerySpec $spec=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): array {
		return static::allHydrated($columns, $spec, $hydrator, $caching);
	}

	public static function firstRecord(
		array|string $columns='*',
		?QuerySpec $spec=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::firstHydrated($columns, $spec, $hydrator, $caching);
	}

	public static function firstRecordOrFail(
		array|string $columns='*',
		?QuerySpec $spec=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		$result=static::firstRecord($columns, $spec, $hydrator, $caching);
		if($result!==null){
			return $result;
		}
		throw SqlError::recordNotFound(static::class, static::notFoundContext($columns, $spec), $message);
	}

	public static function soleRecord(
		array|string $columns='*',
		?QuerySpec $spec=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		return static::hydrateRow(
			static::sole($columns, $spec, $caching, $message),
			$hydrator
		);
	}

	public static function soleValue(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		$row=static::sole($column, $spec, $caching, $message);
		return $row[$column] ?? null;
	}

	public static function findOneHydratedBy(
		string $column,
		mixed $value,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		$row=static::findOneBy($column, $value, $columns, $caching);
		return $row!==null ? static::hydrateRow($row, $hydrator) : null;
	}

	public static function findManyHydratedBy(
		string $column,
		mixed $value,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): array {
		return static::hydrateRows(
			static::findManyBy($column, $value, $columns, $caching),
			$hydrator
		);
	}

	public static function findOneBy(
		string $column,
		mixed $value,
		array|string $columns='*',
		bool|array|string|null $caching=null
	): ?array {
		return static::query()->where_eq($column, $value)->first($columns, $caching);
	}

	public static function findManyBy(
		string $column,
		mixed $value,
		array|string $columns='*',
		bool|array|string|null $caching=null
	): array {
		return static::query()->where_eq($column, $value)->get($columns, $caching);
	}

	public static function findManyByIds(
		array $ids,
		string $primary_key_column,
		array|string $columns='*',
		bool|array|string|null $caching=null
	): array {
		$ids=array_values(array_filter(array_map(
			static fn(mixed $id): string => trim((string)$id),
			$ids
		), static fn(string $id): bool => $id!==''));
		if($ids===[]){
			return [];
		}
		$spec=static::spec()->where_in($primary_key_column, $ids);
		$rows=static::select_many($columns, $spec, $caching);
		return is_array($rows) ? $rows : [];
	}

	public static function findKeyedByIds(
		array $ids,
		string $primary_key_column,
		array|string $columns='*',
		bool|array|string|null $caching=null
	): array {
		$rows=static::findManyByIds($ids, $primary_key_column, $columns, $caching);
		$keyed=[];
		foreach($rows as $row){
			if(!is_array($row) || !array_key_exists($primary_key_column, $row)){
				continue;
			}
			$keyed[(string)$row[$primary_key_column]]=$row;
		}
		return $keyed;
	}

	public static function findManyHydratedByIds(
		array $ids,
		string $primary_key_column,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): array {
		return static::hydrateRows(
			static::findManyByIds($ids, $primary_key_column, $columns, $caching),
			$hydrator
		);
	}

	public static function findKeyedHydratedByIds(
		array $ids,
		string $primary_key_column,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): array {
		$rows=static::findKeyedByIds($ids, $primary_key_column, $columns, $caching);
		$resolved=[];
		foreach($rows as $key=>$row){
			if(!is_array($row)){
				continue;
			}
			$resolved[$key]=static::hydrateRow($row, $hydrator);
		}
		return $resolved;
	}

	public static function find(
		mixed $id,
		array|string $columns='*',
		bool|array|string|null $caching=null
	): ?array {
		$primary_key=static::primaryKey();
		if($primary_key===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform find(...)');
		}
		return static::findOneBy($primary_key, $id, $columns, $caching);
	}

	public static function findOrFail(
		mixed $id,
		array|string $columns='*',
		bool|array|string|null $caching=null,
		?string $message=null
	): array {
		$result=static::find($id, $columns, $caching);
		if($result!==null){
			return $result;
		}
		return throw SqlError::recordNotFound(
			static::class,
			static::notFoundContext($columns, null, ['id'=>$id]),
			$message,
			'Use find() when a missing record is acceptable, or verify the repository primary key and identifier before calling findOrFail().'
		);
	}

	public static function findHydrated(
		mixed $id,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		$row=static::find($id, $columns, $caching);
		return $row!==null ? static::hydrateRow($row, $hydrator) : null;
	}

	public static function findRecord(
		mixed $id,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::findHydrated($id, $columns, $hydrator, $caching);
	}

	public static function findRecordOrFail(
		mixed $id,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		$result=static::findRecord($id, $columns, $hydrator, $caching);
		if($result!==null){
			return $result;
		}
		return throw SqlError::recordNotFound(
			static::class,
			static::notFoundContext($columns, null, ['id'=>$id]),
			$message,
			'Use findRecord() when a missing record is acceptable, or verify the repository primary key and identifier before calling findRecordOrFail().'
		);
	}

	public static function paginateHydrated(
		array|string $columns='*',
		?QuerySpec $spec=null,
		int $page=1,
		int $per_page=50,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): PageResult {
		return static::paginate($columns, $spec, $page, $per_page, $caching)
			->map(static fn(array $row): mixed => static::hydrateRow($row, $hydrator));
	}

	public static function paginateRecords(
		array|string $columns='*',
		?QuerySpec $spec=null,
		int $page=1,
		int $per_page=50,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): PageResult {
		return static::paginateHydrated($columns, $spec, $page, $per_page, $hydrator, $caching);
	}

	public static function create(
		array $fields,
		bool|array|null $clear_cache=null
	): MutationResult {
		return MutationResult::fromRaw(
			'insert',
			static::insert_one(static::fields($fields), static::resolveWriteInvalidation($clear_cache)),
			static::mutationContext()
		);
	}

	public static function queueCreate(
		array $fields,
		callable $callback,
		string $queue='end',
		bool|array|null $clear_cache=null
	): null|bool {
		return sql_insert(
			static::table(),
			static::fields($fields),
			null,
			static::resolveWriteInvalidation($clear_cache),
			$queue,
			$callback
		);
	}

	public static function createMany(
		array $rows,
		bool|array|null $clear_cache=null
	): MutationBatchResult {
		$results=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$results[]=static::create($row, $clear_cache);
		}
		return new MutationBatchResult('insert', $results, count($rows));
	}

	public static function update(
		array $fields,
		QuerySpec $spec,
		bool|array|null $clear_cache=null
	): MutationResult {
		return MutationResult::fromRaw(
			'update',
			static::update_where(static::fields($fields), $spec, static::resolveWriteInvalidation($clear_cache)),
			static::mutationContext()
		);
	}

	public static function queueUpdate(
		array $fields,
		QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|null $clear_cache=null
	): null|bool {
		$compiled=$spec->compile();
		return sql_update(
			static::table(),
			static::fields($fields),
			$compiled['params'],
			$compiled['vars'],
			static::resolveWriteInvalidation($clear_cache),
			$queue,
			$callback
		);
	}

	public static function updateBy(
		string $column,
		mixed $value,
		array $fields,
		bool|array|null $clear_cache=null
	): MutationResult {
		return static::query()->where_eq($column, $value)->update($fields, $clear_cache);
	}

	public static function updateById(
		mixed $id,
		array $fields,
		bool|array|null $clear_cache=null
	): MutationResult {
		$primary_key=static::primaryKey();
		if($primary_key===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform updateById(...)');
		}
		return static::updateBy($primary_key, $id, $fields, $clear_cache);
	}

	public static function delete(
		QuerySpec $spec,
		bool|array|null $clear_cache=null
	): MutationResult {
		return MutationResult::fromRaw(
			'delete',
			static::delete_where($spec, static::resolveWriteInvalidation($clear_cache)),
			static::mutationContext()
		);
	}

	public static function queueDelete(
		QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|null $clear_cache=null
	): null|bool {
		$compiled=$spec->compile();
		return sql_delete(
			static::table(),
			$compiled['params'],
			$compiled['vars'],
			static::resolveWriteInvalidation($clear_cache),
			$queue,
			$callback
		);
	}

	public static function deleteBy(
		string $column,
		mixed $value,
		bool|array|null $clear_cache=null
	): MutationResult {
		return static::query()->where_eq($column, $value)->delete($clear_cache);
	}

	public static function deleteById(
		mixed $id,
		bool|array|null $clear_cache=null
	): MutationResult {
		$primary_key=static::primaryKey();
		if($primary_key===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform deleteById(...)');
		}
		return static::deleteBy($primary_key, $id, $clear_cache);
	}

	public static function upsert(
		array $fields,
		string|array|null $update_params=null,
		?array $update_vars=null,
		bool|array|null $clear_cache=null
	): MutationResult {
		return MutationResult::fromRaw(
			'upsert',
			sql_upsert(
				static::table(),
				static::fields($fields),
				$update_params,
				$update_vars,
				static::resolveWriteInvalidation($clear_cache)
			),
			static::mutationContext()
		);
	}

	public static function queueUpsert(
		array $fields,
		callable $callback,
		string $queue='end',
		string|array|null $update_params=null,
		?array $update_vars=null,
		bool|array|null $clear_cache=null
	): null|bool {
		return sql_upsert(
			static::table(),
			static::fields($fields),
			$update_params,
			$update_vars,
			static::resolveWriteInvalidation($clear_cache),
			$queue,
			$callback
		);
	}

	public static function upsertMany(
		array $rows,
		string|array|null $update_params=null,
		?array $update_vars=null,
		bool|array|null $clear_cache=null
	): MutationBatchResult {
		$results=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$results[]=static::upsert($row, $update_params, $update_vars, $clear_cache);
		}
		return new MutationBatchResult('upsert', $results, count($rows));
	}

	protected static function mutationContext(): array {
		return [
			'table'=>static::table(),
			'repository'=>static::class,
			'primary_key'=>static::primaryKey(),
		];
	}

	protected static function singleResultRows(
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::all($columns, ($spec!==null ? clone $spec : static::spec())->limit(2), $caching);
	}

	protected static function pluckColumns(string $column, ?string $key_column=null): array {
		$columns=[trim($column)];
		if($key_column!==null && trim($key_column)!==''){
			$columns[]=trim($key_column);
		}
		return array_values(array_unique(array_filter($columns, static fn(string $value): bool => $value!=='')));
	}

	protected static function keyColumns(string $key_column, array|string $columns='*'): array|string {
		$key_column=trim($key_column);
		if($columns==='*'){
			return '*';
		}
		if(is_string($columns)){
			$columns=[$columns];
		}
		$columns[]=$key_column;
		return array_values(array_unique(array_filter(array_map(
			static fn(mixed $value): string => trim((string)$value),
			$columns
		), static fn(string $value): bool => $value!=='')));
	}

	protected static function pluckRows(array $rows, string $column, ?string $key_column=null): array {
		$plucked=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$value=$row[$column] ?? null;
			if($key_column===null){
				$plucked[]=$value;
				continue;
			}
			if(!array_key_exists($key_column, $row) || $row[$key_column]===null){
				continue;
			}
			$plucked[(string)$row[$key_column]]=$value;
		}
		return $plucked;
	}

	protected static function keyRowsBy(array $rows, string $key_column): array {
		$keyed=[];
		foreach($rows as $row){
			if(!is_array($row) || !array_key_exists($key_column, $row) || $row[$key_column]===null){
				continue;
			}
			$keyed[(string)$row[$key_column]]=$row;
		}
		return $keyed;
	}

	protected static function notFoundContext(array|string $columns='*', ?QuerySpec $spec=null, array $extra=[]): array {
		$context=array_merge(
			[
				'table'=>static::table(),
				'repository'=>static::class,
				'primary_key'=>static::primaryKey(),
				'columns'=>$columns,
			],
			$spec?->debugContext() ?? [],
			$extra
		);
		return array_filter($context, static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	protected static function aggregateValue(
		string $function,
		string $column='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		bool $distinct=false
	): mixed {
		$function=static::normalizeAggregateFunction($function);
		$column=static::aggregateColumn($column, $function, $function==='COUNT');
		$distinct_sql=$distinct ? 'DISTINCT ' : '';
		$compiled=($spec!==null ? clone $spec : static::spec())
			->without_ordering()
			->without_paging()
			->compile();
		$result=sql_select(
			$function.'('.$distinct_sql.$column.') AS aggregate_value',
			static::table(),
			$compiled['params'],
			$compiled['vars'],
			false,
			static::resolveReadCaching($caching)
		);
		if($result===false){
			return false;
		}
		if(!is_array($result) || !array_key_exists('aggregate_value', $result)){
			return null;
		}
		return static::normalizeAggregateResult($function, $result['aggregate_value']);
	}

	protected static function normalizeAggregateFunction(string $function): string {
		$function=strtoupper(trim($function));
		$allowed=['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
		if(!in_array($function, $allowed, true)){
			throw SqlError::invalidAggregateFunction(static::class, $function, $allowed);
		}
		return $function;
	}

	protected static function aggregateColumn(string $column, string $function, bool $allow_star=false): string {
		$column=trim($column);
		if($column===''){
			throw SqlError::invalidAggregateColumn(static::class, $function, $column, $allow_star);
		}
		if($column==='*'){
			if($allow_star){
				return '*';
			}
			throw SqlError::invalidAggregateColumn(static::class, $function, $column, false);
		}
		$schema=static::schema();
		if($schema!==null){
			$resolved=$schema->columns($column);
			return is_string($resolved) ? $resolved : (string)($resolved[0] ?? '');
		}
		if(preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $column)!==1){
			throw SqlError::invalidAggregateColumn(static::class, $function, $column, $allow_star);
		}
		return $column;
	}

	protected static function normalizeAggregateResult(string $function, mixed $value): mixed {
		if($value===null || $value===false){
			return $value;
		}
		if($function==='COUNT'){
			return is_numeric($value) ? (int)$value : $value;
		}
		if(($function==='SUM' || $function==='AVG') && is_numeric($value)){
			$numeric=(string)$value;
			return str_contains($numeric, '.') || str_contains($numeric, 'e') || str_contains($numeric, 'E')
				? (float)$value
				: (int)$value;
		}
		return $value;
	}

	protected static function groupColumns(string|array $group_columns): array {
		if(is_string($group_columns)){
			$group_columns=[$group_columns];
		}
		$schema=static::schema();
		$normalized=[];
		foreach($group_columns as $group_column){
			$group_column=trim((string)$group_column);
			if($group_column===''){
				throw SqlError::invalidIdentifier('group by', $group_column, static::table());
			}
			if($schema!==null){
				$resolved=$schema->columns($group_column);
				$normalized[]=is_string($resolved) ? $resolved : (string)($resolved[0] ?? '');
				continue;
			}
			if(preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $group_column)!==1){
				throw SqlError::invalidIdentifier('group by', $group_column, static::table());
			}
			$normalized[]=$group_column;
		}
		return array_values(array_unique(array_filter($normalized, static fn(string $value): bool => $value!=='')));
	}

	protected static function appendClause(string $params, string $clause): string {
		$clauses=[];
		$trimmed=trim($params);
		if($trimmed!==''){
			$clauses[]=$trimmed;
		}
		$clause=trim($clause);
		if($clause!==''){
			$clauses[]=$clause;
		}
		return $clauses===[] ? '' : "\n\t\t\t".implode("\n\t\t\t", $clauses)."\n\t\t";
	}

	protected static function normalizeAggregateRows(array $rows, string $function): array {
		foreach($rows as $index=>$row){
			if(!is_array($row) || !array_key_exists('aggregate_value', $row)){
				continue;
			}
			$rows[$index]['aggregate_value']=static::normalizeAggregateResult($function, $row['aggregate_value']);
		}
		return $rows;
	}

	protected static function groupedAggregateMap(string $group_column, array $rows): array {
		$group_column=trim($group_column);
		$mapped=[];
		foreach($rows as $row){
			if(!is_array($row) || !array_key_exists($group_column, $row) || $row[$group_column]===null){
				continue;
			}
			$mapped[(string)$row[$group_column]]=$row['aggregate_value'] ?? null;
		}
		return $mapped;
	}

	protected static function applyRepositoryMoneyColumns(array $row): array {
		foreach(static::resolvedStoredMoneyColumns() as $mapping){
			if(!array_key_exists($mapping['target_column'], $row)
			&& !array_key_exists($mapping['original_amount_column'], $row)
			&& !array_key_exists($mapping['base_amount_column'], $row)){
				continue;
			}
			$row=CurrencyBridge::applyStoredMoneyMapping($row, $mapping, static::class);
		}
		foreach(static::resolvedMoneyColumns() as $mapping){
			if(!array_key_exists($mapping['amount_column'], $row)){
				continue;
			}
			$row=CurrencyBridge::applyMoneyMapping($row, $mapping, static::class);
		}
		return $row;
	}

	protected static function resolvedMoneyColumns(): array {
		$resolved=[];
		foreach(static::moneyColumns() as $amount_column=>$definition){
			if(is_int($amount_column)){
				if(!is_array($definition) || !isset($definition['amount_column'])){
					throw SqlError::invalidMoneyDefinition(
						static::class,
						'Numeric moneyColumns() entries must provide an amount_column definition.'
					);
				}
				$amount_column=(string)$definition['amount_column'];
			}
			if(is_string($definition)){
				$definition=['currency_column'=>$definition];
			}
			if(!is_array($definition)){
				throw SqlError::invalidMoneyDefinition(
					static::class,
					'moneyColumns() entries must be a currency column string or a configuration array.'
				);
			}
			$resolved[]=CurrencyBridge::normalizeMoneyMapping(
				(string)$amount_column,
				isset($definition['currency_column']) ? (string)$definition['currency_column'] : null,
				isset($definition['currency']) ? (string)$definition['currency'] : null,
				isset($definition['target_column'])
					? (string)$definition['target_column']
					: (isset($definition['target']) ? (string)$definition['target'] : null),
				static::class
			);
		}
		return $resolved;
	}

	protected static function resolvedStoredMoneyColumns(): array {
		$resolved=[];
		foreach(static::storedMoneyColumns() as $target_column=>$definition){
			if(is_int($target_column)){
				if(!is_array($definition)){
					throw SqlError::invalidMoneyDefinition(
						static::class,
						'Numeric storedMoneyColumns() entries must provide a configuration array with a target column.'
					);
				}
				$target_column=$definition['target_column'] ?? $definition['target'] ?? null;
				if(!is_string($target_column) || trim($target_column)===''){
					throw SqlError::invalidMoneyDefinition(
						static::class,
						'Numeric storedMoneyColumns() entries must provide target_column or target.'
					);
				}
			}
			if(!is_array($definition)){
				throw SqlError::invalidMoneyDefinition(
					static::class,
					'storedMoneyColumns() entries must be configuration arrays.'
				);
			}
			$resolved[]=CurrencyBridge::normalizeStoredMoneyMapping(
				$definition,
				(string)$target_column,
				static::class
			);
		}
		return $resolved;
	}
}
