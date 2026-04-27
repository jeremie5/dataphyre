<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

use Dataphyre\Database\Concerns\TransformsRows;
use Dataphyre\Database\Hydrators\ClassRecordHydrator;
use Dataphyre\Database\Hydrators\RecordObjectHydrator;

final class RepositoryQuery extends QuerySpec {

	use TransformsRows;

	private array|string $columns='*';
	private bool|array|string|null $caching=[true];
	private mixed $hydrator=null;
	private bool|array|null $clear_cache_on_write=false;
	private array $write_money_mappings=[];
	private array $write_stored_money_mappings=[];

	public function __construct(
		private readonly string $repository_class
	){
		if(!class_exists($this->repository_class) || !is_subclass_of($this->repository_class, TableRepository::class)){
			throw SqlError::invalidRepositoryClass($this->repository_class);
		}
	}

	public function repositoryClass(): string {
		return $this->repository_class;
	}

	public function select(array|string $columns='*'): self {
		$this->columns=$columns;
		return $this;
	}

	public function projection(string $name): self {
		$repository=$this->repositoryClass();
		$this->columns=$repository::projectionNamed($name);
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
		$repository=$this->repositoryClass();
		$this->hydrator=new RecordObjectHydrator($this->repository_class, $repository::primaryKey());
		return $this;
	}

	public function usingRecordClass(string $record_class): self {
		$repository=$this->repositoryClass();
		$this->hydrator=new ClassRecordHydrator(trim($record_class), $this->repository_class, $repository::primaryKey());
		return $this;
	}

	public function asMoney(string $amount_column, string $currency_column='currency', ?string $target_column=null): self {
		$mapping=CurrencyBridge::normalizeMoneyMapping(
			$amount_column,
			$currency_column,
			null,
			$target_column,
			$this->repository_class
		);
		$this->addRowTransformer(
			fn(array $row): array => CurrencyBridge::applyMoneyMapping($row, $mapping, $this->repository_class)
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
			$this->repository_class
		);
		$this->addRowTransformer(
			fn(array $row): array => CurrencyBridge::applyMoneyMapping($row, $mapping, $this->repository_class)
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
			$this->repository_class
		);
		$this->addRowTransformer(
			fn(array $row): array => CurrencyBridge::applyStoredMoneyMapping($row, $mapping, $this->repository_class)
		);
		$this->write_stored_money_mappings[]=$mapping;
		return $this;
	}

	public function where_key(mixed $id): self {
		$repository=$this->repositoryClass();
		$primary_key=$repository::primaryKey();
		if($primary_key===null){
			throw SqlError::missingPrimaryKeyForRepository($repository, 'perform key-based queries');
		}
		return $this->where_eq($primary_key, $id);
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

	public function spec(): QuerySpec {
		return clone $this;
	}

	public function fingerprintPayload(): array {
		$compiled=(clone $this)->compile();
		return [
			'type'=>'repository_query',
			'repository_class'=>$this->repository_class,
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
		$repository_class=trim((string)($state['repository_class'] ?? ''));
		if($repository_class===''){
			throw SqlError::invalidRepositoryClass($repository_class);
		}
		$query=new self($repository_class);
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

	public function get(array|string|null $columns=null, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $this->transformRows(
			$repository::all($columns ?? $this->columns, clone $this, $caching ?? $this->caching)
		);
	}

	public function all(array|string|null $columns=null, bool|array|string|null $caching=null): array {
		return $this->get($columns, $caching);
	}

	public function first(array|string|null $columns=null, bool|array|string|null $caching=null): ?array {
		$repository=$this->repositoryClass();
		$row=$repository::first($columns ?? $this->columns, clone $this, $caching ?? $this->caching);
		return $row!==null ? $this->transformRow($row) : null;
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
			$this->repository_class,
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
				$this->repository_class,
				$this->notFoundContext($columns),
				$message,
				'Use first() when zero matches are acceptable, or tighten the query before calling sole().'
			);
		}
		if(count($rows)>1){
			throw SqlError::multipleRecordsFound(
				$this->repository_class,
				$this->notFoundContext($columns, ['matched_rows_sample'=>count($rows)]),
				$message,
				'Use get()/all() when multiple matches are expected, or tighten the query until it uniquely identifies a single row.'
			);
		}
		return $rows[0];
	}

	public function exists(bool|array|string|null $caching=null): bool {
		$repository=$this->repositoryClass();
		return $repository::exists(clone $this, $caching ?? $this->caching);
	}

	public function count(bool|array|string|null $caching=null): int|bool|null {
		$repository=$this->repositoryClass();
		return $repository::count(clone $this, $caching ?? $this->caching);
	}

	public function aggregate(
		string $function,
		string $column='*',
		bool|array|string|null $caching=null
	): mixed {
		$repository=$this->repositoryClass();
		return $repository::aggregate($function, $column, clone $this, $caching ?? $this->caching);
	}

	public function sum(string $column, bool|array|string|null $caching=null): mixed {
		$repository=$this->repositoryClass();
		return $repository::sum($column, clone $this, $caching ?? $this->caching);
	}

	public function avg(string $column, bool|array|string|null $caching=null): mixed {
		$repository=$this->repositoryClass();
		return $repository::avg($column, clone $this, $caching ?? $this->caching);
	}

	public function min(string $column, bool|array|string|null $caching=null): mixed {
		$repository=$this->repositoryClass();
		return $repository::min($column, clone $this, $caching ?? $this->caching);
	}

	public function max(string $column, bool|array|string|null $caching=null): mixed {
		$repository=$this->repositoryClass();
		return $repository::max($column, clone $this, $caching ?? $this->caching);
	}

	public function countColumn(string $column, bool|array|string|null $caching=null): int|bool|null {
		$repository=$this->repositoryClass();
		return $repository::countColumn($column, clone $this, $caching ?? $this->caching);
	}

	public function countDistinct(string $column, bool|array|string|null $caching=null): int|bool|null {
		$repository=$this->repositoryClass();
		return $repository::countDistinct($column, clone $this, $caching ?? $this->caching);
	}

	public function aggregateRowsBy(
		string|array $group_columns,
		string $function,
		string $column='*',
		bool|array|string|null $caching=null,
		bool $distinct=false
	): array {
		$repository=$this->repositoryClass();
		return $repository::aggregateRowsBy(
			$group_columns,
			$function,
			$column,
			clone $this,
			$caching ?? $this->caching,
			$distinct
		);
	}

	public function countBy(string $group_column, string $column='*', bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $repository::countBy($group_column, $column, clone $this, $caching ?? $this->caching);
	}

	public function countDistinctBy(string $group_column, string $column, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $repository::countDistinctBy($group_column, $column, clone $this, $caching ?? $this->caching);
	}

	public function sumBy(string $group_column, string $column, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $repository::sumBy($group_column, $column, clone $this, $caching ?? $this->caching);
	}

	public function avgBy(string $group_column, string $column, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $repository::avgBy($group_column, $column, clone $this, $caching ?? $this->caching);
	}

	public function minBy(string $group_column, string $column, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $repository::minBy($group_column, $column, clone $this, $caching ?? $this->caching);
	}

	public function maxBy(string $group_column, string $column, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $repository::maxBy($group_column, $column, clone $this, $caching ?? $this->caching);
	}

	public function paginate(
		int $page=1,
		int $per_page=50,
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): PageResult {
		$repository=$this->repositoryClass();
		return $repository::paginate(
			$columns ?? $this->columns,
			clone $this,
			$page,
			$per_page,
			$caching ?? $this->caching
		);
	}

	public function getHydrated(
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): array {
		$repository=$this->repositoryClass();
		return $repository::allHydrated(
			$columns ?? $this->columns,
			clone $this,
			$hydrator ?? $this->hydrator,
			$caching ?? $this->caching
		);
	}

	public function firstHydrated(
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		$repository=$this->repositoryClass();
		return $repository::firstHydrated(
			$columns ?? $this->columns,
			clone $this,
			$hydrator ?? $this->hydrator,
			$caching ?? $this->caching
		);
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
			$this->repository_class,
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
		$repository=$this->repositoryClass();
		return $repository::hydrateRow(
			$this->sole($columns, $caching, $message),
			$hydrator ?? $this->hydrator
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
			$this->repository_class,
			$this->notFoundContext($columns, ['id'=>$id]),
			$message,
			'Use find() when a missing record is acceptable, or verify the primary key and active filters before calling findOrFail().'
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
			$this->repository_class,
			$this->notFoundContext($columns, ['id'=>$id]),
			$message,
			'Use findRecord() when a missing record is acceptable, or verify the primary key and active filters before calling findRecordOrFail().'
		);
	}

	public function paginateHydrated(
		int $page=1,
		int $per_page=50,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): PageResult {
		$repository=$this->repositoryClass();
		return $repository::paginateHydrated(
			$columns ?? $this->columns,
			clone $this,
			$page,
			$per_page,
			$hydrator ?? $this->hydrator,
			$caching ?? $this->caching
		);
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

	public function update(array $fields, bool|array|null $clear_cache=null): MutationResult {
		$repository=$this->repositoryClass();
		$resolved_clear_cache=$clear_cache===null ? $this->clear_cache_on_write : $clear_cache;
		$this->warnIfWriteInvalidationMissing('update', $resolved_clear_cache);
		return $repository::update($this->resolvedWriteFields($fields), clone $this, $resolved_clear_cache);
	}

	public function delete(bool|array|null $clear_cache=null): MutationResult {
		$repository=$this->repositoryClass();
		$resolved_clear_cache=$clear_cache===null ? $this->clear_cache_on_write : $clear_cache;
		$this->warnIfWriteInvalidationMissing('delete', $resolved_clear_cache);
		return $repository::delete(clone $this, $resolved_clear_cache);
	}

	public function queueGet(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueAll(
			$columns ?? $this->columns,
			clone $this,
			fn(mixed $result): mixed => $callback($this->transformQueuedResult($result)),
			$queue,
			$caching ?? $this->caching
		);
	}

	public function queueFirst(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueFirst(
			$columns ?? $this->columns,
			clone $this,
			fn(mixed $result): mixed => $callback($this->transformQueuedResult($result)),
			$queue,
			$caching ?? $this->caching
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
		$repository=$this->repositoryClass();
		return $repository::queueCount(clone $this, $callback, $queue, $caching ?? $this->caching);
	}

	public function queueAggregate(
		string $function,
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null,
		bool $distinct=false
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueAggregate(
			$function,
			$column,
			clone $this,
			$callback,
			$queue,
			$caching ?? $this->caching,
			$distinct
		);
	}

	public function queueUpdate(
		array $fields,
		callable $callback,
		string $queue='end',
		bool|array|null $clear_cache=null
	): null|bool {
		$repository=$this->repositoryClass();
		$resolved_clear_cache=$clear_cache===null ? $this->clear_cache_on_write : $clear_cache;
		$this->warnIfWriteInvalidationMissing('queue_update', $resolved_clear_cache);
		return $repository::queueUpdate($this->resolvedWriteFields($fields), clone $this, $callback, $queue, $resolved_clear_cache);
	}

	public function queueDelete(
		callable $callback,
		string $queue='end',
		bool|array|null $clear_cache=null
	): null|bool {
		$repository=$this->repositoryClass();
		$resolved_clear_cache=$clear_cache===null ? $this->clear_cache_on_write : $clear_cache;
		$this->warnIfWriteInvalidationMissing('queue_delete', $resolved_clear_cache);
		return $repository::queueDelete(clone $this, $callback, $queue, $resolved_clear_cache);
	}

	private function singleResultRows(array|string|null $columns=null, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $this->transformRows(
			$repository::all($columns ?? $this->columns, (clone $this)->limit(2), $caching ?? $this->caching)
		);
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
			$this->repository_class
		);
		$comparison=CurrencyBridge::normalizeComparableValue(
			$value,
			$mapping['currency'],
			$this->repository_class,
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
				$this->repository_class,
				$mapping['amount_column'],
				"Unsupported money comparison operator '{$operator}'."
			),
		};
	}

	private function pluckColumns(string $column, ?string $key_column=null): array {
		$columns=[trim($column)];
		if($key_column!==null && trim($key_column)!==''){
			$columns[] = trim($key_column);
		}
		return array_values(array_unique(array_filter($columns, static fn(string $value): bool => $value!=='')));
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
				'columns'=>$columns ?? $this->columns,
			],
			$this->debugContext(),
			$extra
		);
		return array_filter($context, static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	private function restoreCompiledTransforms(array $money_mappings, array $stored_money_mappings): void {
		foreach($money_mappings as $mapping){
			if(!is_array($mapping)){
				continue;
			}
			$this->addRowTransformer(
				fn(array $row): array => CurrencyBridge::applyMoneyMapping($row, $mapping, $this->repository_class)
			);
			$this->write_money_mappings[]=$mapping;
		}
		foreach($stored_money_mappings as $mapping){
			if(!is_array($mapping)){
				continue;
			}
			$this->addRowTransformer(
				fn(array $row): array => CurrencyBridge::applyStoredMoneyMapping($row, $mapping, $this->repository_class)
			);
			$this->write_stored_money_mappings[]=$mapping;
		}
	}

	private function resolvedWriteFields(array $fields): array {
		return CurrencyBridge::expandWriteFields(
			$fields,
			$this->write_money_mappings,
			$this->write_stored_money_mappings,
			$this->repository_class,
			false
		);
	}

	private function warnIfWriteInvalidationMissing(string $operation, bool|array|null $clear_cache): void {
		$cache_names=$this->namedReadCacheNames();
		if($cache_names===[] || $clear_cache===true || $this->invalidationNamesFromValue($clear_cache)!==[]){
			return;
		}
		DB::reportGuardrailWarning(
			'Named read caches are attached to this repository query, but the write path has no invalidation policy.',
			[
				'operation'=>$operation,
				'repository'=>$this->repository_class,
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
}
