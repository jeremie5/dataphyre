<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

use Dataphyre\Database\Concerns\TransformsRows;
use Dataphyre\Database\Contracts\RecordHydrator;
use Dataphyre\Database\Hydrators\CallbackRecordHydrator;
use Dataphyre\Database\Hydrators\ClassRecordHydrator;
use Dataphyre\Database\Hydrators\RecordObjectHydrator;

final class TableQuery extends QuerySpec {

	use TransformsRows;

	private array|string $columns='*';
	private bool|array|string|null $caching=[true];
	private mixed $hydrator=null;
	private string $table;
	private ?TableSchema $schema;
	private ?string $primary_key;
	private bool|array|null $clear_cache_on_write=false;
	private array $write_money_mappings=[];
	private array $write_stored_money_mappings=[];

	public function __construct(string|TableSchema $table, ?string $primary_key=null){
		if($table instanceof TableSchema){
			$this->schema=$table;
			$this->table=$table->table();
			$this->primary_key=$primary_key!==null ? $this->normalize_identifier($primary_key) : $table->primaryKey();
			return;
		}
		$this->schema=null;
		$this->table=$this->normalize_identifier($table);
		$this->primary_key=$primary_key!==null ? $this->normalize_identifier($primary_key) : null;
	}

	public function table(): string {
		return $this->table;
	}

	public function schema(): ?TableSchema {
		return $this->schema;
	}

	public function primaryKey(): ?string {
		return $this->primary_key;
	}

	public function usingSchema(TableSchema $schema): self {
		$this->schema=$schema;
		$this->table=$schema->table();
		$this->primary_key ??= $schema->primaryKey();
		return $this;
	}

	public function usingPrimaryKey(string $primary_key): self {
		$primary_key=$this->normalize_identifier($primary_key);
		if($this->schema!==null){
			$this->schema->fields([$primary_key=>null]);
		}
		$this->primary_key=$primary_key;
		return $this;
	}

	public function where_key(mixed $id): self {
		if($this->primary_key===null){
			throw SqlError::missingPrimaryKeyForTable($this->table, 'perform key-based queries');
		}
		return $this->where_eq($this->primary_key, $id);
	}

	public function select(array|string $columns='*'): self {
		$this->columns=$columns;
		return $this;
	}

	public function projection(string $name): self {
		if($this->schema===null){
			throw SqlError::missingSchemaForProjection("table {$this->table}", $name, $this->table);
		}
		$this->columns=$this->schema->projection($name);
		return $this;
	}

	public function cache(bool|array|string|null $caching=true): self {
		$this->caching=$caching;
		return $this;
	}

	public function cacheName(string $name): self {
		$this->caching=DB::mergeCacheNames($this->caching, $name);
		return $this;
	}

	public function cacheNames(string ...$names): self {
		$this->caching=DB::mergeCacheNames($this->caching, ...$names);
		return $this;
	}

	public function withoutCaching(): self {
		$this->caching=false;
		return $this;
	}

	public function invalidateOnWrite(bool|array $clear_cache=true): self {
		$this->clear_cache_on_write=$clear_cache;
		return $this;
	}

	public function invalidateCacheName(string $name): self {
		$this->clear_cache_on_write=DB::mergeInvalidationNames($this->clear_cache_on_write, $name);
		return $this;
	}

	public function invalidateCacheNames(string ...$names): self {
		$this->clear_cache_on_write=DB::mergeInvalidationNames($this->clear_cache_on_write, ...$names);
		return $this;
	}

	public function withoutInvalidation(): self {
		$this->clear_cache_on_write=false;
		return $this;
	}

	public function usingHydrator(mixed $hydrator): self {
		$this->hydrator=$hydrator;
		return $this;
	}

	public function asRecords(): self {
		$this->hydrator=new RecordObjectHydrator(null, $this->primary_key);
		return $this;
	}

	public function usingRecordClass(string $record_class): self {
		$this->hydrator=new ClassRecordHydrator(trim($record_class), null, $this->primary_key);
		return $this;
	}

	public function asMoney(string $amount_column, string $currency_column='currency', ?string $target_column=null): self {
		$mapping=CurrencyBridge::normalizeMoneyMapping(
			$amount_column,
			$currency_column,
			null,
			$target_column,
			"table {$this->table}"
		);
		$this->addRowTransformer(
			fn(array $row): array => CurrencyBridge::applyMoneyMapping($row, $mapping, "table {$this->table}")
		);
		$this->write_money_mappings[]=$mapping;
		return $this;
	}

	public function asMoneyIn(string $amount_column, string $currency, ?string $target_column=null): self {
		$mapping=CurrencyBridge::normalizeMoneyMapping(
			$amount_column,
			null,
			$currency,
			$target_column,
			"table {$this->table}"
		);
		$this->addRowTransformer(
			fn(array $row): array => CurrencyBridge::applyMoneyMapping($row, $mapping, "table {$this->table}")
		);
		$this->write_money_mappings[]=$mapping;
		return $this;
	}

	public function asStoredMoney(string|array $target_column='stored_money', array $definition=[]): self {
		if(is_array($target_column)){
			$definition=$target_column;
			$target_column='stored_money';
		}
		$mapping=CurrencyBridge::normalizeStoredMoneyMapping(
			$definition,
			$target_column,
			"table {$this->table}"
		);
		$this->addRowTransformer(
			fn(array $row): array => CurrencyBridge::applyStoredMoneyMapping($row, $mapping, "table {$this->table}")
		);
		$this->write_stored_money_mappings[]=$mapping;
		return $this;
	}

	public function spec(): QuerySpec {
		return clone $this;
	}

	public function fingerprintPayload(): array {
		$compiled=(clone $this)->compile();
		return [
			'type'=>'table_query',
			'table'=>$this->table,
			'primary_key'=>$this->primary_key,
			'columns'=>$this->columns,
			'caching'=>$this->caching,
			'hydrator'=>$this->hydratorDescriptor($this->hydrator),
			'query'=>[
				'params'=>$compiled['params'],
				'vars'=>$compiled['vars'],
			],
		];
	}

	public function fingerprint(): string {
		return $this->fingerprintHash($this->fingerprintPayload());
	}

	public function executionState(): array {
		$payload=$this->fingerprintPayload();
		$state=$payload;
		unset($state['type']);
		$state['builder_state']=$this->builderState();
		$state['money_mappings']=$this->write_money_mappings;
		$state['stored_money_mappings']=$this->write_stored_money_mappings;
		$state['fingerprint_payload']=$payload;
		$state['fingerprint']=$this->fingerprintHash($payload);
		return $state;
	}

	public static function fromExecutionState(array $state): self {
		$table=trim((string)($state['table'] ?? ''));
		if($table===''){
			throw SqlError::invalidIdentifier('table', $table);
		}
		$primary_key=isset($state['primary_key']) && is_string($state['primary_key']) && trim($state['primary_key'])!==''
			? trim($state['primary_key'])
			: null;
		$query=new self($table, $primary_key);
		$query->columns=self::columns($state['columns'] ?? '*');
		$query->caching=$state['caching'] ?? [true];
		$query->hydrator=$state['hydrator'] ?? null;
		$query->applyBuilderState(is_array($state['builder_state'] ?? null) ? $state['builder_state'] : []);
		$query->restoreCompiledTransforms(
			is_array($state['money_mappings'] ?? null) ? $state['money_mappings'] : [],
			is_array($state['stored_money_mappings'] ?? null) ? $state['stored_money_mappings'] : []
		);
		return $query;
	}

	public function whereMoneyEq(string $amount_column, mixed $value, string $currency_column='currency'): self {
		return $this->whereMoneyCompare($amount_column, $value, '=', $currency_column, null);
	}

	public function whereMoneyGt(string $amount_column, mixed $value, string $currency_column='currency'): self {
		return $this->whereMoneyCompare($amount_column, $value, '>', $currency_column, null);
	}

	public function whereMoneyGte(string $amount_column, mixed $value, string $currency_column='currency'): self {
		return $this->whereMoneyCompare($amount_column, $value, '>=', $currency_column, null);
	}

	public function whereMoneyLt(string $amount_column, mixed $value, string $currency_column='currency'): self {
		return $this->whereMoneyCompare($amount_column, $value, '<', $currency_column, null);
	}

	public function whereMoneyLte(string $amount_column, mixed $value, string $currency_column='currency'): self {
		return $this->whereMoneyCompare($amount_column, $value, '<=', $currency_column, null);
	}

	public function whereMoneyEqIn(string $amount_column, mixed $value, string $currency): self {
		return $this->whereMoneyCompare($amount_column, $value, '=', null, $currency);
	}

	public function whereMoneyGtIn(string $amount_column, mixed $value, string $currency): self {
		return $this->whereMoneyCompare($amount_column, $value, '>', null, $currency);
	}

	public function whereMoneyGteIn(string $amount_column, mixed $value, string $currency): self {
		return $this->whereMoneyCompare($amount_column, $value, '>=', null, $currency);
	}

	public function whereMoneyLtIn(string $amount_column, mixed $value, string $currency): self {
		return $this->whereMoneyCompare($amount_column, $value, '<', null, $currency);
	}

	public function whereMoneyLteIn(string $amount_column, mixed $value, string $currency): self {
		return $this->whereMoneyCompare($amount_column, $value, '<=', null, $currency);
	}

	public function get(array|string|null $columns=null, bool|array|string|null $caching=null): array {
		$compiled=(clone $this)->compile();
		$result=sql_select(
			$this->resolvedColumns($columns ?? $this->columns),
			$this->table,
			$compiled['params'],
			$compiled['vars'],
			true,
			$caching ?? $this->caching
		);
		return is_array($result) ? $this->transformRows($result) : [];
	}

	public function all(array|string|null $columns=null, bool|array|string|null $caching=null): array {
		return $this->get($columns, $caching);
	}

	public function first(array|string|null $columns=null, bool|array|string|null $caching=null): ?array {
		$spec=clone $this;
		$result=sql_select(
			$this->resolvedColumns($columns ?? $this->columns),
			$this->table,
			$spec->limit(1)->compile()['params'],
			$spec->compile()['vars'],
			false,
			$caching ?? $this->caching
		);
		return is_array($result) ? $this->transformRow($result) : null;
	}

	public function firstOrFail(
		array|string|null $columns=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): array {
		$result=$this->first($columns, $caching);
		if($result!==null){
			return $result;
		}
		throw SqlError::recordNotFound(
			"table {$this->table}",
			$this->notFoundContext($columns),
			$message
		);
	}

	public function value(string $column, bool|array|string|null $caching=null): mixed {
		$row=$this->first($column, $caching);
		return is_array($row) && array_key_exists($column, $row) ? $row[$column] : null;
	}

	public function valueOrFail(
		string $column,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		$row=$this->firstOrFail($column, $caching, $message);
		return $row[$column] ?? null;
	}

	public function pluck(
		string $column,
		?string $key_column=null,
		bool|array|string|null $caching=null
	): array {
		return $this->pluckRows(
			$this->get($this->pluckColumns($column, $key_column), $caching),
			$column,
			$key_column
		);
	}

	public function keyBy(
		string $key_column,
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): array {
		return $this->keyRowsBy(
			$this->get($this->keyColumns($key_column, $columns), $caching),
			$key_column
		);
	}

	public function sole(
		array|string|null $columns=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): array {
		$rows=$this->singleResultRows($columns, $caching);
		if($rows===[]){
			throw SqlError::recordNotFound(
				"table {$this->table}",
				$this->notFoundContext($columns),
				$message,
				'Use first() when zero matches are acceptable, or tighten the query before calling sole().'
			);
		}
		if(count($rows)>1){
			throw SqlError::multipleRecordsFound(
				"table {$this->table}",
				$this->notFoundContext($columns, ['matched_rows_sample'=>count($rows)]),
				$message,
				'Use get()/all() when multiple matches are expected, or tighten the query until it uniquely identifies a single row.'
			);
		}
		return $rows[0];
	}

	public function exists(bool|array|string|null $caching=null): bool {
		$count=$this->count($caching);
		return is_int($count) ? $count > 0 : false;
	}

	public function count(bool|array|string|null $caching=null): int|bool|null {
		$spec=(clone $this)->without_ordering()->without_paging();
		$compiled=$spec->compile();
		return sql_count(
			$this->table,
			$compiled['params'],
			$compiled['vars'],
			$caching ?? $this->caching
		);
	}

	public function aggregate(
		string $function,
		string $column='*',
		bool|array|string|null $caching=null
	): mixed {
		return $this->aggregateValue($function, $column, $caching);
	}

	public function sum(string $column, bool|array|string|null $caching=null): mixed {
		return $this->aggregateValue('SUM', $column, $caching);
	}

	public function avg(string $column, bool|array|string|null $caching=null): mixed {
		return $this->aggregateValue('AVG', $column, $caching);
	}

	public function min(string $column, bool|array|string|null $caching=null): mixed {
		return $this->aggregateValue('MIN', $column, $caching);
	}

	public function max(string $column, bool|array|string|null $caching=null): mixed {
		return $this->aggregateValue('MAX', $column, $caching);
	}

	public function countColumn(string $column, bool|array|string|null $caching=null): int|bool|null {
		$value=$this->aggregateValue('COUNT', $column, $caching);
		if($value===false || $value===null){
			return $value;
		}
		return is_numeric($value) ? (int)$value : null;
	}

	public function countDistinct(string $column, bool|array|string|null $caching=null): int|bool|null {
		$value=$this->aggregateValue('COUNT', $column, $caching, true);
		if($value===false || $value===null){
			return $value;
		}
		return is_numeric($value) ? (int)$value : null;
	}

	public function aggregateRowsBy(
		string|array $group_columns,
		string $function,
		string $column='*',
		bool|array|string|null $caching=null,
		bool $distinct=false
	): array {
		$function=$this->normalizeAggregateFunction($function);
		$group_columns=$this->groupColumns($group_columns);
		$column=$this->aggregateColumn($column, $function, $function==='COUNT');
		$compiled=(clone $this)
			->without_ordering()
			->without_paging()
			->compile();
		$result=sql_select(
			implode(', ', $group_columns).', '.$function.'('.($distinct ? 'DISTINCT ' : '').$column.') AS aggregate_value',
			$this->table,
			$this->appendClause($compiled['params'], 'GROUP BY '.implode(', ', $group_columns)),
			$compiled['vars'],
			true,
			$caching ?? $this->caching
		);
		if(!is_array($result)){
			return [];
		}
		return $this->normalizeAggregateRows($result, $function);
	}

	public function countBy(
		string $group_column,
		string $column='*',
		bool|array|string|null $caching=null
	): array {
		return $this->groupedAggregateMap(
			$group_column,
			$this->aggregateRowsBy($group_column, 'COUNT', $column, $caching)
		);
	}

	public function countDistinctBy(
		string $group_column,
		string $column,
		bool|array|string|null $caching=null
	): array {
		return $this->groupedAggregateMap(
			$group_column,
			$this->aggregateRowsBy($group_column, 'COUNT', $column, $caching, true)
		);
	}

	public function sumBy(
		string $group_column,
		string $column,
		bool|array|string|null $caching=null
	): array {
		return $this->groupedAggregateMap(
			$group_column,
			$this->aggregateRowsBy($group_column, 'SUM', $column, $caching)
		);
	}

	public function avgBy(
		string $group_column,
		string $column,
		bool|array|string|null $caching=null
	): array {
		return $this->groupedAggregateMap(
			$group_column,
			$this->aggregateRowsBy($group_column, 'AVG', $column, $caching)
		);
	}

	public function minBy(
		string $group_column,
		string $column,
		bool|array|string|null $caching=null
	): array {
		return $this->groupedAggregateMap(
			$group_column,
			$this->aggregateRowsBy($group_column, 'MIN', $column, $caching)
		);
	}

	public function maxBy(
		string $group_column,
		string $column,
		bool|array|string|null $caching=null
	): array {
		return $this->groupedAggregateMap(
			$group_column,
			$this->aggregateRowsBy($group_column, 'MAX', $column, $caching)
		);
	}

	public function paginate(
		int $page=1,
		int $per_page=50,
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): PageResult {
		$page=max(1, $page);
		$per_page=max(1, min(500, $per_page));
		$total=$this->count($caching ?? $this->caching);
		$items=(clone $this)->for_page($page, $per_page)->get($columns, $caching);
		return new PageResult(
			$items,
			is_int($total) ? max(0, $total) : 0,
			$page,
			$per_page
		);
	}

	public function getHydrated(
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): array {
		$resolved=[];
		foreach($this->get($columns, $caching) as $key=>$row){
			if(!is_array($row)){
				continue;
			}
			$resolved[$key]=$this->resolvedHydrator($hydrator)->hydrate($row, $this->schema);
		}
		return $resolved;
	}

	public function firstHydrated(
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		$row=$this->first($columns, $caching);
		return $row!==null ? $this->resolvedHydrator($hydrator)->hydrate($row, $this->schema) : null;
	}

	public function getRecords(
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): array {
		return $this->getHydrated($columns, $hydrator, $caching);
	}

	public function firstRecord(
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		return $this->firstHydrated($columns, $hydrator, $caching);
	}

	public function firstRecordOrFail(
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		$result=$this->firstRecord($columns, $hydrator, $caching);
		if($result!==null){
			return $result;
		}
		throw SqlError::recordNotFound(
			"table {$this->table}",
			$this->notFoundContext($columns),
			$message
		);
	}

	public function soleRecord(
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		return $this->resolvedHydrator($hydrator)->hydrate(
			$this->sole($columns, $caching, $message),
			$this->schema
		);
	}

	public function soleValue(
		string $column,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		$row=$this->sole($column, $caching, $message);
		return $row[$column] ?? null;
	}

	public function paginateHydrated(
		int $page=1,
		int $per_page=50,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): PageResult {
		return $this->paginate($page, $per_page, $columns, $caching)
			->map(fn(array $row): mixed => $this->resolvedHydrator($hydrator)->hydrate($row, $this->schema));
	}

	public function paginateRecords(
		int $page=1,
		int $per_page=50,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): PageResult {
		return $this->paginateHydrated($page, $per_page, $columns, $hydrator, $caching);
	}

	public function find(
		mixed $id,
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): ?array {
		return (clone $this)->where_key($id)->first($columns, $caching);
	}

	public function findOrFail(
		mixed $id,
		array|string|null $columns=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): array {
		$result=$this->find($id, $columns, $caching);
		if($result!==null){
			return $result;
		}
		return throw SqlError::recordNotFound(
			"table {$this->table}",
			$this->notFoundContext($columns, ['id'=>$id]),
			$message,
			'Use find() when a missing row is acceptable, or verify the primary key and active filters before calling findOrFail().'
		);
	}

	public function findRecord(
		mixed $id,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		return (clone $this)->where_key($id)->firstRecord($columns, $hydrator, $caching);
	}

	public function findRecordOrFail(
		mixed $id,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		$result=$this->findRecord($id, $columns, $hydrator, $caching);
		if($result!==null){
			return $result;
		}
		return throw SqlError::recordNotFound(
			"table {$this->table}",
			$this->notFoundContext($columns, ['id'=>$id]),
			$message,
			'Use findRecord() when a missing row is acceptable, or verify the primary key and active filters before calling findRecordOrFail().'
		);
	}

	public function create(array $fields, bool|array|null $clear_cache=null): MutationResult {
		$resolved_clear_cache=$clear_cache===null ? $this->clear_cache_on_write : $clear_cache;
		$this->warnIfWriteInvalidationMissing('create', $resolved_clear_cache);
		return $this->insertMutationResult($fields, $resolved_clear_cache);
	}

	public function createMany(array $rows, bool|array|null $clear_cache=null): MutationBatchResult {
		$resolved_clear_cache=$clear_cache===null ? $this->clear_cache_on_write : $clear_cache;
		$this->warnIfWriteInvalidationMissing('create_many', $resolved_clear_cache);
		$results=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$results[]=$this->insertMutationResult($row, $resolved_clear_cache);
		}
		return new MutationBatchResult('insert', $results, count($rows));
	}

	public function update(array $fields, bool|array|null $clear_cache=null): MutationResult {
		$compiled=(clone $this)->compile();
		$resolved_clear_cache=$clear_cache===null ? $this->clear_cache_on_write : $clear_cache;
		$this->warnIfWriteInvalidationMissing('update', $resolved_clear_cache);
		return MutationResult::fromRaw(
			'update',
			sql_update(
				$this->table,
				$this->resolvedFields($fields),
				$compiled['params'],
				$compiled['vars'],
				$resolved_clear_cache
			),
			$this->mutationContext()
		);
	}

	public function delete(bool|array|null $clear_cache=null): MutationResult {
		$compiled=(clone $this)->compile();
		$resolved_clear_cache=$clear_cache===null ? $this->clear_cache_on_write : $clear_cache;
		$this->warnIfWriteInvalidationMissing('delete', $resolved_clear_cache);
		return MutationResult::fromRaw(
			'delete',
			sql_delete(
				$this->table,
				$compiled['params'],
				$compiled['vars'],
				$resolved_clear_cache
			),
			$this->mutationContext()
		);
	}

	public function upsert(
		array $fields,
		string|array|null $update_params=null,
		?array $update_vars=null,
		bool|array|null $clear_cache=null
	): MutationResult {
		$resolved_clear_cache=$clear_cache===null ? $this->clear_cache_on_write : $clear_cache;
		$this->warnIfWriteInvalidationMissing('upsert', $resolved_clear_cache);
		return MutationResult::fromRaw(
			'upsert',
			sql_upsert(
				$this->table,
				$this->resolvedFields($fields),
				$update_params,
				$update_vars,
				$resolved_clear_cache
			),
			$this->mutationContext()
		);
	}

	public function queueGet(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): null|bool {
		return sql_select(
			$this->resolvedColumns($columns ?? $this->columns),
			$this->table,
			(clone $this)->compile()['params'],
			(clone $this)->compile()['vars'],
			true,
			$caching ?? $this->caching,
			$queue,
			fn(mixed $result): mixed => $callback($this->transformQueuedResult($result))
		);
	}

	public function queueFirst(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): null|bool {
		$spec=clone $this;
		$queued_spec=$spec->limit(1);
		return sql_select(
			$this->resolvedColumns($columns ?? $this->columns),
			$this->table,
			$queued_spec->compile()['params'],
			$queued_spec->compile()['vars'],
			false,
			$caching ?? $this->caching,
			$queue,
			fn(mixed $result): mixed => $callback($this->transformQueuedResult($result))
		);
	}

	public function queueValue(
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queueFirst(
			static function(mixed $result)use($callback, $column): void{
				$callback(is_array($result) ? ($result[$column] ?? null) : null);
			},
			$queue,
			$column,
			$caching
		);
	}

	public function queueCount(
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$compiled=(clone $this)->without_ordering()->without_paging()->compile();
		return sql_count(
			$this->table,
			$compiled['params'],
			$compiled['vars'],
			$caching ?? $this->caching,
			$queue,
			$callback
		);
	}

	public function queueAggregate(
		string $function,
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null,
		bool $distinct=false
	): null|bool {
		$function=$this->normalizeAggregateFunction($function);
		$column=$this->aggregateColumn($column, $function, $function==='COUNT');
		$compiled=(clone $this)->without_ordering()->without_paging()->compile();
		return sql_select(
			$function.'('.($distinct ? 'DISTINCT ' : '').$column.') AS aggregate_value',
			$this->table,
			$compiled['params'],
			$compiled['vars'],
			false,
			$caching ?? $this->caching,
			$queue,
			function(mixed $result)use($callback, $function): void{
				$value=is_array($result) ? ($result['aggregate_value'] ?? null) : null;
				$callback($this->normalizeAggregateResult($function, $value));
			}
		);
	}

	public function queueCreate(
		array $fields,
		callable $callback,
		string $queue='end',
		bool|array|null $clear_cache=null
	): null|bool {
		$resolved_clear_cache=$clear_cache===null ? $this->clear_cache_on_write : $clear_cache;
		$this->warnIfWriteInvalidationMissing('queue_create', $resolved_clear_cache);
		return sql_insert(
			$this->table,
			$this->resolvedFields($fields),
			null,
			$resolved_clear_cache,
			$queue,
			$callback
		);
	}

	public function queueUpdate(
		array $fields,
		callable $callback,
		string $queue='end',
		bool|array|null $clear_cache=null
	): null|bool {
		$compiled=(clone $this)->compile();
		$resolved_clear_cache=$clear_cache===null ? $this->clear_cache_on_write : $clear_cache;
		$this->warnIfWriteInvalidationMissing('queue_update', $resolved_clear_cache);
		return sql_update(
			$this->table,
			$this->resolvedFields($fields),
			$compiled['params'],
			$compiled['vars'],
			$resolved_clear_cache,
			$queue,
			$callback
		);
	}

	public function queueDelete(
		callable $callback,
		string $queue='end',
		bool|array|null $clear_cache=null
	): null|bool {
		$compiled=(clone $this)->compile();
		$resolved_clear_cache=$clear_cache===null ? $this->clear_cache_on_write : $clear_cache;
		$this->warnIfWriteInvalidationMissing('queue_delete', $resolved_clear_cache);
		return sql_delete(
			$this->table,
			$compiled['params'],
			$compiled['vars'],
			$resolved_clear_cache,
			$queue,
			$callback
		);
	}

	public function queueUpsert(
		array $fields,
		callable $callback,
		string $queue='end',
		string|array|null $update_params=null,
		?array $update_vars=null,
		bool|array|null $clear_cache=null
	): null|bool {
		$resolved_clear_cache=$clear_cache===null ? $this->clear_cache_on_write : $clear_cache;
		$this->warnIfWriteInvalidationMissing('queue_upsert', $resolved_clear_cache);
		return sql_upsert(
			$this->table,
			$this->resolvedFields($fields),
			$update_params,
			$update_vars,
			$resolved_clear_cache,
			$queue,
			$callback
		);
	}

	private function resolvedColumns(array|string $columns): array|string {
		if($this->schema!==null){
			return $this->schema->columns($columns);
		}
		return QuerySpec::columns($columns);
	}

	private function restoreCompiledTransforms(array $money_mappings, array $stored_money_mappings): void {
		foreach($money_mappings as $mapping){
			if(!is_array($mapping)){
				continue;
			}
			$this->addRowTransformer(
				fn(array $row): array => CurrencyBridge::applyMoneyMapping($row, $mapping, "table {$this->table}")
			);
			$this->write_money_mappings[]=$mapping;
		}
		foreach($stored_money_mappings as $mapping){
			if(!is_array($mapping)){
				continue;
			}
			$this->addRowTransformer(
				fn(array $row): array => CurrencyBridge::applyStoredMoneyMapping($row, $mapping, "table {$this->table}")
			);
			$this->write_stored_money_mappings[]=$mapping;
		}
	}

	private function resolvedFields(array $fields): array {
		if($fields===[]){
			throw SqlError::invalidFieldPayload("table {$this->table}", 'Field payload cannot be empty.');
		}
		$fields=CurrencyBridge::expandWriteFields(
			$fields,
			$this->write_money_mappings,
			$this->write_stored_money_mappings,
			"table {$this->table}"
		);
		if($this->schema!==null){
			return $this->schema->fields($fields);
		}
		foreach($fields as $column=>$_value){
			if(is_int($column)){
				throw SqlError::invalidFieldPayload("table {$this->table}", 'Field payload must be an associative array.');
			}
			$this->normalize_identifier((string)$column);
		}
		return $fields;
	}

	private function resolvedHydrator(mixed $hydrator=null): RecordHydrator {
		$source=$hydrator ?? $this->hydrator ?? new RecordObjectHydrator(null, $this->primary_key);
		if($source instanceof RecordHydrator){
			return $source;
		}
		if(is_callable($source)){
			return new CallbackRecordHydrator($source);
		}
		if(is_string($source)){
			$source=trim($source);
			if($source===''){
				throw SqlError::invalidHydrator("table {$this->table}", $source);
			}
			if(!class_exists($source)){
				throw SqlError::missingHydratorClass("table {$this->table}", $source);
			}
			if(is_subclass_of($source, RecordHydrator::class)){
				return new $source();
			}
			return new ClassRecordHydrator($source, null, $this->primary_key);
		}
		throw SqlError::invalidHydrator("table {$this->table}", $source);
	}

	private function normalize_identifier(string $identifier): string {
		$identifier=trim($identifier);
		if($identifier==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $identifier)!==1){
			throw SqlError::invalidIdentifier('table query', $identifier, $this->table);
		}
		return $identifier;
	}

	private function mutationContext(): array {
		return [
			'table'=>$this->table,
			'primary_key'=>$this->primary_key,
		];
	}

	private function insertMutationResult(array $fields, bool|array|null $clear_cache): MutationResult {
		return MutationResult::fromRaw(
			'insert',
			sql_insert($this->table, $this->resolvedFields($fields), null, $clear_cache),
			$this->mutationContext()
		);
	}

	private function singleResultRows(array|string|null $columns=null, bool|array|string|null $caching=null): array {
		return $this->transformRows((clone $this)->limit(2)->get($columns, $caching));
	}

	private function whereMoneyCompare(
		string $amount_column,
		mixed $value,
		string $operator,
		?string $currency_column,
		?string $currency
	): self {
		$mapping=CurrencyBridge::normalizeMoneyMapping(
			$amount_column,
			$currency_column,
			$currency,
			null,
			"table {$this->table}"
		);
		$comparison=CurrencyBridge::normalizeComparableValue(
			$value,
			$mapping['currency'],
			"table {$this->table}",
			$mapping['amount_column']
		);
		if($mapping['currency_column']!==null){
			$this->where_eq($mapping['currency_column'], $comparison['currency']);
		}
		return match($operator){
			'='=>$this->where_eq($mapping['amount_column'], $comparison['amount']),
			'>'=>$this->where_gt($mapping['amount_column'], $comparison['amount']),
			'>='=>$this->where_gte($mapping['amount_column'], $comparison['amount']),
			'<' =>$this->where_lt($mapping['amount_column'], $comparison['amount']),
			'<='=>$this->where_lte($mapping['amount_column'], $comparison['amount']),
			default=>throw SqlError::invalidMoneyComparison(
				"table {$this->table}",
				$mapping['amount_column'],
				"Unsupported money comparison operator '{$operator}'."
			),
		};
	}

	private function hydratorDescriptor(mixed $hydrator): mixed {
		if(is_object($hydrator)){
			return $hydrator::class;
		}
		if(is_string($hydrator) || is_array($hydrator) || $hydrator===null || is_bool($hydrator) || is_int($hydrator) || is_float($hydrator)){
			return $hydrator;
		}
		return get_debug_type($hydrator);
	}

	private function fingerprintHash(array $payload): string {
		$encoded=json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
		);
		return sha1($encoded!==false ? $encoded : serialize($payload));
	}

	private function pluckColumns(string $column, ?string $key_column=null): array {
		$columns=[trim($column)];
		if($key_column!==null && trim($key_column)!==''){
			$columns[]=trim($key_column);
		}
		return array_values(array_unique(array_filter($columns, static fn(string $value): bool => $value!=='')));
	}

	private function keyColumns(string $key_column, array|string|null $columns=null): array|string {
		$key_column=trim($key_column);
		if($columns===null || $columns==='*'){
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

	private function pluckRows(array $rows, string $column, ?string $key_column=null): array {
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

	private function keyRowsBy(array $rows, string $key_column): array {
		$keyed=[];
		foreach($rows as $row){
			if(!is_array($row) || !array_key_exists($key_column, $row) || $row[$key_column]===null){
				continue;
			}
			$keyed[(string)$row[$key_column]]=$row;
		}
		return $keyed;
	}

	private function notFoundContext(array|string|null $columns=null, array $extra=[]): array {
		$context=array_merge(
			[
				'table'=>$this->table,
				'primary_key'=>$this->primary_key,
				'columns'=>$columns ?? $this->columns,
			],
			$this->debugContext(),
			$extra
		);
		return array_filter($context, static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	private function warnIfWriteInvalidationMissing(string $operation, bool|array|null $clear_cache): void {
		$cache_names=$this->namedReadCacheNames();
		if($cache_names===[] || $clear_cache===true || $this->invalidationNamesFromValue($clear_cache)!==[]){
			return;
		}
		DB::reportGuardrailWarning(
			'Named read caches are attached to this table query, but the write path has no invalidation policy.',
			[
				'operation'=>$operation,
				'table'=>$this->table,
				'primary_key'=>$this->primary_key,
				'cache_names'=>$cache_names,
				'invalidation_names'=>$this->invalidationNamesFromValue($clear_cache),
				'columns'=>$this->columns,
				'query'=>$this->debugContext(),
			]
		);
	}

	private function namedReadCacheNames(): array {
		return $this->normalizeTraceNames($this->caching, true);
	}

	private function invalidationNamesFromValue(bool|array|null $clear_cache): array {
		return $this->normalizeTraceNames($clear_cache, false);
	}

	private function normalizeTraceNames(mixed $value, bool $allow_lazy): array {
		if(is_array($value)===false){
			$value=$value===null ? [] : [$value];
		}
		$normalized=[];
		foreach($value as $name){
			if(is_string($name)===false){
				continue;
			}
			$name=trim($name);
			if($name==='' || ($allow_lazy && $name==='lazy')){
				continue;
			}
			$normalized[]=$name;
		}
		return array_values(array_unique($normalized));
	}

	private function aggregateValue(
		string $function,
		string $column='*',
		bool|array|string|null $caching=null,
		bool $distinct=false
	): mixed {
		$function=$this->normalizeAggregateFunction($function);
		$column=$this->aggregateColumn($column, $function, $function==='COUNT');
		$compiled=(clone $this)
			->without_ordering()
			->without_paging()
			->compile();
		$result=sql_select(
			$function.'('.($distinct ? 'DISTINCT ' : '').$column.') AS aggregate_value',
			$this->table,
			$compiled['params'],
			$compiled['vars'],
			false,
			$caching ?? $this->caching
		);
		if($result===false){
			return false;
		}
		if(!is_array($result) || !array_key_exists('aggregate_value', $result)){
			return null;
		}
		return $this->normalizeAggregateResult($function, $result['aggregate_value']);
	}

	private function normalizeAggregateFunction(string $function): string {
		$function=strtoupper(trim($function));
		$allowed=['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
		if(!in_array($function, $allowed, true)){
			throw SqlError::invalidAggregateFunction("table {$this->table}", $function, $allowed);
		}
		return $function;
	}

	private function aggregateColumn(string $column, string $function, bool $allow_star=false): string {
		$column=trim($column);
		if($column===''){
			throw SqlError::invalidAggregateColumn("table {$this->table}", $function, $column, $allow_star);
		}
		if($column==='*'){
			if($allow_star){
				return '*';
			}
			throw SqlError::invalidAggregateColumn("table {$this->table}", $function, $column, false);
		}
		if($this->schema!==null){
			$resolved=$this->schema->columns($column);
			return is_string($resolved) ? $resolved : (string)($resolved[0] ?? '');
		}
		return $this->normalize_identifier($column);
	}

	private function normalizeAggregateResult(string $function, mixed $value): mixed {
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

	private function groupColumns(string|array $group_columns): array {
		if(is_string($group_columns)){
			$group_columns=[$group_columns];
		}
		$normalized=[];
		foreach($group_columns as $group_column){
			$group_column=trim((string)$group_column);
			if($group_column===''){
				throw SqlError::invalidIdentifier('group by', $group_column, $this->table);
			}
			if($this->schema!==null){
				$resolved=$this->schema->columns($group_column);
				$normalized[]=is_string($resolved) ? $resolved : (string)($resolved[0] ?? '');
				continue;
			}
			$normalized[]=$this->normalize_identifier($group_column);
		}
		return array_values(array_unique(array_filter($normalized, static fn(string $value): bool => $value!=='')));
	}

	private function appendClause(string $params, string $clause): string {
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

	private function normalizeAggregateRows(array $rows, string $function): array {
		foreach($rows as $index=>$row){
			if(!is_array($row) || !array_key_exists('aggregate_value', $row)){
				continue;
			}
			$rows[$index]['aggregate_value']=$this->normalizeAggregateResult($function, $row['aggregate_value']);
		}
		return $rows;
	}

	private function groupedAggregateMap(string $group_column, array $rows): array {
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
}
