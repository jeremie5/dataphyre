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

/**
 * Fluent SQL query builder for a Dataphyre table repository.
 *
 * Repository queries combine repository metadata with predicates, eager
 * relations, hydrators, cache policy, pagination, batching, optimistic locking,
 * and write guardrails.
 */
final class RepositoryQuery extends QuerySpec {

	use TransformsRows;

	private array|string $columns='*';
	private bool|array|string|null $caching=[true];
	private mixed $hydrator=null;
	private bool|array|null $clearCacheOnWrite=false;
	/** @var array<int,array<string,mixed>> */
	private array $writeMoneyMappings=[];
	/** @var array<int,array<string,mixed>> */
	private array $writeStoredMoneyMappings=[];
	/** @var array<int,array<string,mixed>> */
	private array $eagerRelations=[];
	/** @var array<int,array<string,mixed>> */
	private array $eagerCounts=[];
	/** @var array<int,array<string,mixed>> */
	private array $eagerAggregates=[];

	/**
	 * Creates a fluent SQL repository query object.
	 *
	 * Initial state captures repository identity, schema metadata, primary-key
	 * handling, cache defaults, and write invalidation policy.
	 *
	 * @param string $repositoryClass Repository class extending `TableRepository`.
	 */
	public function __construct(
		private readonly string $repositoryClass
	){
		if(!class_exists($this->repositoryClass) || !is_subclass_of($this->repositoryClass, TableRepository::class)){
			throw SqlError::invalidRepositoryClass($this->repositoryClass);
		}
	}

	/**
	 * Returns the repository class that owns this fluent query.
	 *
	 * The class has already been validated as a TableRepository subclass during
	 * construction, so downstream read, write, relation, and hydrator calls can
	 * dispatch static repository APIs.
	 *
	 * @return string Repository class name.
	 */
	public function repositoryClass(): string {
		return $this->repositoryClass;
	}

	/**
	 * Selects the columns or projection used by later read operations.
	 *
	 * The selector is stored until get(), pagination, hydration, eager loading, or
	 * queue dispatch builds the final SQL shape. Write operations keep their
	 * separate field maps.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @return self Current repository query instance.
	 */
	public function select(array|string $columns='*'): self {
		$this->columns=$columns;
		return $this;
	}

	/**
	 * Selects a named repository projection.
	 *
	 * The repository resolves the projection immediately, so invalid projection names fail before the query reaches the SQL kernel.
	 *
	 * @param string $name Repository projection name.
	 * @return self Current repository query instance.
	 */
	public function projection(string $name): self {
		$repository=$this->repositoryClass();
		$this->columns=$repository::projectionNamed($name);
		return $this;
	}

	/**
	 * Replaces the read-cache policy used by this query.
	 *
	 * Cache markers are preserved until read and queue operations delegate to the SQL kernel. Passing false disables read caching, null leaves kernel defaults available, and arrays or strings carry Dataphyre cache scopes.
	 *
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return self Current repository query instance.
	 */
	public function cache(bool|array|string|null $caching=true): self {
		$this->caching=$caching;
		return $this;
	}

	/**
	 * Adds one named read-cache scope to the current cache policy.
	 *
	 * Existing cache policy shape is preserved while DB::mergeCacheNames() appends the namespace used by later SQL reads.
	 *
	 * @param string $name Cache namespace merged into the read policy.
	 * @return self Current repository query instance.
	 */
	public function cacheName(string $name): self {
		$this->caching=DB::mergeCacheNames($this->caching, $name);
		return $this;
	}

	/**
	 * Adds multiple named read-cache scopes to the current cache policy.
	 *
	 * Existing cache policy shape is preserved while DB::mergeCacheNames() appends each namespace used by later SQL reads.
	 *
	 * @param string ...$names Cache namespace names.
	 * @return self Current repository query instance.
	 */
	public function cacheNames(string ...$names): self {
		$this->caching=DB::mergeCacheNames($this->caching, ...$names);
		return $this;
	}

	/**
	 * Disables read caching for later query execution.
	 *
	 * The query keeps all predicates, projections, eager descriptors, and hydrator choices; only the read-cache policy is replaced with false.
	 * @return self Current repository query instance.
	 */
	public function withoutCaching(): self {
		$this->caching=false;
		return $this;
	}

	/**
	 * Replaces the cache invalidation policy used by write operations.
	 *
	 * The policy is retained until create, update, delete, upsert, increment, decrement, and queued write helpers dispatch to the repository.
	 *
	 * @param bool|array $clearCache Dataphyre write invalidation policy.
	 * @return self Current repository query instance.
	 */
	public function invalidateOnWrite(bool|array $clearCache=true): self {
		$this->clearCacheOnWrite=$clearCache;
		return $this;
	}

	/**
	 * Adds one named invalidation scope for later write operations.
	 *
	 * Existing invalidation policy shape is preserved while DB::mergeInvalidationNames() appends the namespace.
	 *
	 * @param string $name Cache namespace invalidated by later writes.
	 * @return self Current repository query instance.
	 */
	public function invalidateCacheName(string $name): self {
		$this->clearCacheOnWrite=DB::mergeInvalidationNames($this->clearCacheOnWrite, $name);
		return $this;
	}

	/**
	 * Adds multiple named invalidation scopes for later write operations.
	 *
	 * Existing invalidation policy shape is preserved while DB::mergeInvalidationNames() appends each namespace.
	 *
	 * @param string ...$names Cache namespace names.
	 * @return self Current repository query instance.
	 */
	public function invalidateCacheNames(string ...$names): self {
		$this->clearCacheOnWrite=DB::mergeInvalidationNames($this->clearCacheOnWrite, ...$names);
		return $this;
	}

	/**
	 * Disables automatic cache invalidation for later write operations.
	 *
	 * This changes only write invalidation policy; read caching and query predicates are left unchanged.
	 * @return self Current repository query instance.
	 */
	public function withoutInvalidation(): self {
		$this->clearCacheOnWrite=false;
		return $this;
	}

	/**
	 * Eager-loads named relations after parent rows are read.
	 *
	 * Relation names are resolved through the repository and stored as
	 * descriptors. The descriptors are replayed after the parent query so related
	 * data is attached without changing the parent-row predicate.
	 *
	 * @param string|array $relations Relation name, descriptor, or relation list.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?callable $constraint Optional relation-query constraint.
	 * @return self Current repository query instance.
	 */
	public function with(string|array $relations, array|string $columns='*', bool|array|string|null $caching=null, ?callable $constraint=null): self {
		foreach($this->normalizeEagerRelationInput($relations, $columns, $caching, $constraint) as $entry){
			$repository=$this->repositoryClass();
			$this->addEagerRelation(
				$entry['name'],
				$repository::relationNamed($entry['name']),
				$entry['columns'],
				false,
				null,
				$entry['caching'],
				$entry['constraint'],
				$entry['name']
			);
		}
		return $this;
	}

	/**
	 * Eager-loads named relations as hydrated records.
	 *
	 * The relation descriptors are replayed after the parent query and forward
	 * the hydrator override to relation record loading, allowing related rows to
	 * become Record objects, custom classes, or callback results.
	 *
	 * @param string|array $relations Relation name, descriptor, or relation list.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?callable $constraint Optional relation-query constraint.
	 * @return self Current repository query instance.
	 */
	public function withRecords(
		string|array $relations,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?callable $constraint=null
	): self {
		foreach($this->normalizeEagerRelationInput($relations, $columns, $caching, $constraint) as $entry){
			$repository=$this->repositoryClass();
			$this->addEagerRelation(
				$entry['name'],
				$repository::relationNamed($entry['name']),
				$entry['columns'],
				true,
				$hydrator,
				$entry['caching'],
				$entry['constraint'],
				$entry['name']
			);
		}
		return $this;
	}

	/**
	 * Eager-loads an explicit Relation instance under a manifest key.
	 *
	 * This bypasses repository relation-name lookup while keeping the same post-parent-query attach lifecycle as with().
	 *
	 * @param string $name Key used when attaching related data to each parent row.
	 * @param Relation $relation Relation object that builds the related query.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?callable $constraint Optional relation-query constraint.
	 * @return self Current repository query instance.
	 */
	public function withRelation(
		string $name,
		Relation $relation,
		array|string $columns='*',
		bool|array|string|null $caching=null,
		?callable $constraint=null
	): self {
		$this->addEagerRelation($name, $relation, $columns, false, null, $caching, $constraint, null);
		return $this;
	}

	/**
	 * Eager-loads an explicit Relation instance as hydrated records.
	 *
	 * This bypasses repository relation-name lookup while forwarding a hydrator override to related record loading.
	 *
	 * @param string $name Key used when attaching related data to each parent row.
	 * @param Relation $relation Relation object that builds the related query.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?callable $constraint Optional relation-query constraint.
	 * @return self Current repository query instance.
	 */
	public function withRelationRecords(
		string $name,
		Relation $relation,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?callable $constraint=null
	): self {
		$this->addEagerRelation($name, $relation, $columns, true, $hydrator, $caching, $constraint, null);
		return $this;
	}

	/**
	 * Eager-loads relation counts after parent rows are read.
	 *
	 * Count descriptors are attached under explicit or default aliases and share the same relation constraint and cache policy shape as relation eager loading.
	 *
	 * @param string|array $relations Relation name, descriptor, or relation list.
	 * @param ?string $as Optional alias for the count value.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?callable $constraint Optional relation-query constraint.
	 * @return self Current repository query instance.
	 */
	public function withCount(string|array $relations, ?string $as=null, bool|array|string|null $caching=null, ?callable $constraint=null): self {
		foreach($this->normalizeEagerCountInput($relations, $as, $caching, $constraint) as $entry){
			$repository=$this->repositoryClass();
			$this->addEagerCount(
				$entry['name'],
				$repository::relationNamed($entry['name']),
				$entry['alias'],
				$entry['caching'],
				$entry['constraint'],
				$entry['name']
			);
		}
		return $this;
	}

	/**
	 * Eager-loads a count for an explicit Relation instance.
	 *
	 * The descriptor is attached after the parent query and defaults its alias from the relation key when none is provided.
	 *
	 * @param string $name Relation key used to derive the default count alias.
	 * @param Relation $relation Relation object that builds the count query.
	 * @param ?string $as Optional alias for the count value.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?callable $constraint Optional relation-query constraint.
	 * @return self Current repository query instance.
	 */
	public function withRelationCount(
		string $name,
		Relation $relation,
		?string $as=null,
		bool|array|string|null $caching=null,
		?callable $constraint=null
	): self {
		$this->addEagerCount($name, $relation, $as ?? $this->defaultCountAlias($name), $caching, $constraint, null);
		return $this;
	}

	/**
	 * Eager-loads relation aggregate values after parent rows are read.
	 *
	 * Aggregate descriptors normalize SQL aggregate names, store the target related column, optional alias, cache policy, distinct flag, and constraint before replay.
	 *
	 * @param string|array $relations Relation name, descriptor, or relation list.
	 * @param string $function Aggregate function name such as SUM, AVG, MIN, or MAX.
	 * @param string $column Related-table column to aggregate.
	 * @param ?string $as Optional alias for the aggregate value.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool $distinct Whether the aggregate should count only distinct column values.
	 * @param ?callable $constraint Optional relation-query constraint.
	 * @return self Current repository query instance.
	 */
	public function withAggregate(
		string|array $relations,
		string $function,
		string $column,
		?string $as=null,
		bool|array|string|null $caching=null,
		bool $distinct=false,
		?callable $constraint=null
	): self {
		foreach($this->normalizeEagerAggregateInput($relations, $function, $column, $as, $caching, $distinct, $constraint) as $entry){
			$repository=$this->repositoryClass();
			$this->addEagerAggregate(
				$entry['name'],
				$repository::relationNamed($entry['name']),
				$entry['function'],
				$entry['column'],
				$entry['alias'],
				$entry['caching'],
				$entry['distinct'],
				$entry['constraint'],
				$entry['name']
			);
		}
		return $this;
	}

	/**
	 * Eager-loads an aggregate for an explicit Relation instance.
	 *
	 * The descriptor bypasses repository relation-name lookup and defaults its alias from the relation key, aggregate function, and target column.
	 *
	 * @param string $name Relation key used to derive the default aggregate alias.
	 * @param Relation $relation Relation object that builds the aggregate query.
	 * @param string $function Aggregate function name such as SUM, AVG, MIN, or MAX.
	 * @param string $column Related-table column to aggregate.
	 * @param ?string $as Optional alias for the aggregate value.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool $distinct Whether the aggregate should include only distinct column values.
	 * @param ?callable $constraint Optional relation-query constraint.
	 * @return self Current repository query instance.
	 */
	public function withRelationAggregate(
		string $name,
		Relation $relation,
		string $function,
		string $column,
		?string $as=null,
		bool|array|string|null $caching=null,
		bool $distinct=false,
		?callable $constraint=null
	): self {
		$function=$this->normalizeAggregateFunction($function);
		$this->addEagerAggregate(
			$name,
			$relation,
			$function,
			$column,
			$as ?? $this->defaultAggregateAlias($name, $function, $column),
			$caching,
			$distinct,
			$constraint,
			null
		);
		return $this;
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param string|array $relations Relation name, descriptor, or relation list.
	 * @param string $column Related-table column passed to SUM().
	 * @param ?string $as Aggregate alias attached to hydrated parent rows.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?callable $constraint Constraint.
	 * @return self Current repository query instance.
	 */
	public function withSum(string|array $relations, string $column, ?string $as=null, bool|array|string|null $caching=null, ?callable $constraint=null): self {
		return $this->withAggregate($relations, 'SUM', $column, $as, $caching, false, $constraint);
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param string|array $relations Relation name, descriptor, or relation list.
	 * @param string $column Related-table column passed to AVG().
	 * @param ?string $as Aggregate alias attached to hydrated parent rows.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?callable $constraint Constraint.
	 * @return self Current repository query instance.
	 */
	public function withAvg(string|array $relations, string $column, ?string $as=null, bool|array|string|null $caching=null, ?callable $constraint=null): self {
		return $this->withAggregate($relations, 'AVG', $column, $as, $caching, false, $constraint);
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param string|array $relations Relation name, descriptor, or relation list.
	 * @param string $column Related-table column passed to MIN().
	 * @param ?string $as Aggregate alias attached to hydrated parent rows.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?callable $constraint Constraint.
	 * @return self Current repository query instance.
	 */
	public function withMin(string|array $relations, string $column, ?string $as=null, bool|array|string|null $caching=null, ?callable $constraint=null): self {
		return $this->withAggregate($relations, 'MIN', $column, $as, $caching, false, $constraint);
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param string|array $relations Relation name, descriptor, or relation list.
	 * @param string $column Related-table column passed to MAX().
	 * @param ?string $as Aggregate alias attached to hydrated parent rows.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?callable $constraint Constraint.
	 * @return self Current repository query instance.
	 */
	public function withMax(string|array $relations, string $column, ?string $as=null, bool|array|string|null $caching=null, ?callable $constraint=null): self {
		return $this->withAggregate($relations, 'MAX', $column, $as, $caching, false, $constraint);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string $relation Relation name, descriptor, or relation list.
	 * @param ?callable $constraint Constraint.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return self Current repository query instance.
	 */
	public function withWhereHas(
		string $relation,
		?callable $constraint=null,
		array|string $columns='*',
		bool|array|string|null $caching=null
	): self {
		return $this->whereHas($relation, $constraint)->with($relation, $columns, $caching, $constraint);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string|Relation $relation Relation name, descriptor, or relation list.
	 * @param ?callable $constraint Constraint.
	 * @return self Current repository query instance.
	 */
	public function whereHas(string|Relation $relation, ?callable $constraint=null): self {
		return $this->whereRelationExists($relation, $constraint, true);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string|Relation $relation Relation name, descriptor, or relation list.
	 * @param ?callable $constraint Constraint.
	 * @return self Current repository query instance.
	 */
	public function whereDoesntHave(string|Relation $relation, ?callable $constraint=null): self {
		return $this->whereRelationExists($relation, $constraint, false);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param bool $required Whether write operations must include at least one WHERE predicate.
	 * @return self Current repository query instance.
	 */
	public function requireWhereForWrite(bool $required=true): self {
		$this->requireWhereForWrite($required);
		return $this;
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 * @return self Current repository query instance.
	 */
	public function allowUnscopedWrite(): self {
		$this->allowUnscopedWrite();
		return $this;
	}

	/**
	 * Requests an exclusive row lock for later read execution.
	 *
	 * The lock marker is stored on the inherited QuerySpec and is applied only when the final SQL operation supports row locking.
	 *
	 * @return self Current repository query instance.
	 */
	public function forUpdate(): self {
		$this->forUpdate();
		return $this;
	}

	/**
	 * Requests a shared row lock for later read execution.
	 *
	 * The lock marker is stored on the inherited QuerySpec and is applied only when the final SQL operation supports row locking.
	 *
	 * @return self Current repository query instance.
	 */
	public function sharedLock(): self {
		$this->sharedLock();
		return $this;
	}

	/**
	 * Stores a raw lock clause for later SQL execution.
	 *
	 * Raw lock fragments are passed through to the SQL layer, so callers must treat this as a trusted-code boundary and avoid user-controlled lock strings.
	 *
	 * @param string|array $fragment Trusted lock fragment or dialect-specific lock descriptor.
	 * @return self Current repository query instance.
	 */
	public function lockRaw(string|array $fragment): self {
		$this->lockRaw($fragment);
		return $this;
	}

	/**
	 * Clears any row-locking mode from this query.
	 *
	 * Predicates, projections, cache policy, eager descriptors, and hydrator state are preserved.
	 * @return self Current repository query instance.
	 */
	public function withoutLocking(): self {
		$this->clearLocking();
		return $this;
	}

	/**
	 * Replaces the hydrator used for row-to-result conversion.
	 *
	 * Hydrators are consumed by hydrated read helpers and eager relation record loading. Raw get()/first() calls keep returning array-shaped rows unless they explicitly route through hydration.
	 *
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @return self Current repository query instance.
	 */
	public function usingHydrator(mixed $hydrator): self {
		$this->hydrator=$hydrator;
		return $this;
	}

	/**
	 * Hydrates rows as framework Record objects for this repository.
	 *
	 * The hydrator captures the repository class and primary key so records can later resolve repository-backed relations and write-back helpers.
	 * @return self Current repository query instance.
	 */
	public function asRecords(): self {
		$repository=$this->repositoryClass();
		$this->hydrator=new RecordObjectHydrator($this->repositoryClass, $repository::primaryKey());
		return $this;
	}

	/**
	 * Hydrates rows as instances of a custom record class.
	 *
	 * The class name is trimmed and handed to ClassRecordHydrator with repository and primary-key context for downstream relation and persistence helpers.
	 *
	 * @param string $recordClass Record class used by the class hydrator.
	 * @return self Current repository query instance.
	 */
	public function usingRecordClass(string $recordClass): self {
		$repository=$this->repositoryClass();
		$this->hydrator=new ClassRecordHydrator(trim($recordClass), $this->repositoryClass, $repository::primaryKey());
		return $this;
	}

	/**
	 * Adds a row transformer that maps amount and currency columns into a money value.
	 *
	 * The normalized mapping is also remembered for write paths, letting repository writes apply the same money conversion rules before persistence.
	 *
	 * @param string $amountColumn Column containing integer minor units when named `*_minor`, otherwise a decimal major-unit amount.
	 * @param string $currencyColumn Column containing the currency code.
	 * @param ?string $targetColumn Optional destination column for the mapped money value.
	 * @return self Current repository query instance.
	 */
	public function asMoney(string $amountColumn, string $currencyColumn='currency', ?string $targetColumn=null): self {
		$mapping=CurrencyBridge::normalizeMoneyMapping(
			$amountColumn,
			$currencyColumn,
			null,
			$targetColumn,
			$this->repositoryClass
		);
		$this->addRowTransformer(
			fn(array $row): array => CurrencyBridge::applyMoneyMapping($row, $mapping, $this->repositoryClass)
		);
		$this->writeMoneyMappings[]=$mapping;
		return $this;
	}

	/**
	 * Adds a row transformer that maps an amount column using a fixed currency.
	 *
	 * The fixed-currency mapping is also remembered for write paths, keeping read and persistence conversion behavior aligned.
	 *
	 * @param string $amountColumn Column containing integer minor units when named `*_minor`, otherwise a decimal major-unit amount.
	 * @param string $currency Currency code applied to every mapped row.
	 * @param ?string $targetColumn Optional destination column for the mapped money value.
	 * @return self Current repository query instance.
	 */
	public function asMoneyIn(string $amountColumn, string $currency, ?string $targetColumn=null): self {
		$mapping=CurrencyBridge::normalizeMoneyMapping(
			$amountColumn,
			null,
			$currency,
			$targetColumn,
			$this->repositoryClass
		);
		$this->addRowTransformer(
			fn(array $row): array => CurrencyBridge::applyMoneyMapping($row, $mapping, $this->repositoryClass)
		);
		$this->writeMoneyMappings[]=$mapping;
		return $this;
	}

	/**
	 * Adds a row transformer for stored-money mapping definitions.
	 *
	 * The mapping can be passed as a target column plus definition or as a full definition array. Normalized read mappings are retained for matching write-time conversion.
	 *
	 * @param string|array $targetColumn Target column name or full stored-money mapping definition.
	 * @param array<string,mixed> $definition Stored-money mapping definition.
	 * @return self Current repository query instance.
	 */
	public function asStoredMoney(string|array $targetColumn='stored_money', array $definition=[]): self {
		if(is_array($targetColumn)){
			$definition=$targetColumn;
			$targetColumn='stored_money';
		}
		$mapping=CurrencyBridge::normalizeStoredMoneyMapping(
			$definition,
			$targetColumn,
			$this->repositoryClass
		);
		$this->addRowTransformer(
			fn(array $row): array => CurrencyBridge::applyStoredMoneyMapping($row, $mapping, $this->repositoryClass)
		);
		$this->writeStoredMoneyMappings[]=$mapping;
		return $this;
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param mixed $id Primary-key value matched against the repository key column.
	 * @return self Current repository query instance.
	 */
	public function whereKey(mixed $id): self {
		$repository=$this->repositoryClass();
		$primaryKey=$repository::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository($repository, 'perform key-based queries');
		}
		return $this->whereEq($primaryKey, $id);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string $amountColumn Column containing the stored amount.
	 * @param mixed $value Money amount compared after currency bridge normalization.
	 * @param string $currencyColumn Column containing the row currency code.
	 * @return self Current repository query instance.
	 */
	public function whereMoneyEq(string $amountColumn, mixed $value, string $currencyColumn='currency'): self {
		return $this->whereMoneyCompare($amountColumn, $value, '=', $currencyColumn, null);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string $amountColumn Column containing the stored amount.
	 * @param mixed $value Money amount compared after currency bridge normalization.
	 * @param string $currencyColumn Column containing the row currency code.
	 * @return self Current repository query instance.
	 */
	public function whereMoneyGt(string $amountColumn, mixed $value, string $currencyColumn='currency'): self {
		return $this->whereMoneyCompare($amountColumn, $value, '>', $currencyColumn, null);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string $amountColumn Column containing the stored amount.
	 * @param mixed $value Money amount compared after currency bridge normalization.
	 * @param string $currencyColumn Column containing the row currency code.
	 * @return self Current repository query instance.
	 */
	public function whereMoneyGte(string $amountColumn, mixed $value, string $currencyColumn='currency'): self {
		return $this->whereMoneyCompare($amountColumn, $value, '>=', $currencyColumn, null);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string $amountColumn Column containing the stored amount.
	 * @param mixed $value Money amount compared after currency bridge normalization.
	 * @param string $currencyColumn Column containing the row currency code.
	 * @return self Current repository query instance.
	 */
	public function whereMoneyLt(string $amountColumn, mixed $value, string $currencyColumn='currency'): self {
		return $this->whereMoneyCompare($amountColumn, $value, '<', $currencyColumn, null);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string $amountColumn Column containing the stored amount.
	 * @param mixed $value Money amount compared after currency bridge normalization.
	 * @param string $currencyColumn Column containing the row currency code.
	 * @return self Current repository query instance.
	 */
	public function whereMoneyLte(string $amountColumn, mixed $value, string $currencyColumn='currency'): self {
		return $this->whereMoneyCompare($amountColumn, $value, '<=', $currencyColumn, null);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string $amountColumn Column containing the stored amount.
	 * @param mixed $value Money amount compared after currency bridge normalization.
	 * @param string $currency Fixed currency code used instead of a row currency column.
	 * @return self Current repository query instance.
	 */
	public function whereMoneyEqIn(string $amountColumn, mixed $value, string $currency): self {
		return $this->whereMoneyCompare($amountColumn, $value, '=', null, $currency);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string $amountColumn Column containing the stored amount.
	 * @param mixed $value Money amount compared after currency bridge normalization.
	 * @param string $currency Fixed currency code used instead of a row currency column.
	 * @return self Current repository query instance.
	 */
	public function whereMoneyGtIn(string $amountColumn, mixed $value, string $currency): self {
		return $this->whereMoneyCompare($amountColumn, $value, '>', null, $currency);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string $amountColumn Column containing the stored amount.
	 * @param mixed $value Money amount compared after currency bridge normalization.
	 * @param string $currency Fixed currency code used instead of a row currency column.
	 * @return self Current repository query instance.
	 */
	public function whereMoneyGteIn(string $amountColumn, mixed $value, string $currency): self {
		return $this->whereMoneyCompare($amountColumn, $value, '>=', null, $currency);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string $amountColumn Column containing the stored amount.
	 * @param mixed $value Money amount compared after currency bridge normalization.
	 * @param string $currency Fixed currency code used instead of a row currency column.
	 * @return self Current repository query instance.
	 */
	public function whereMoneyLtIn(string $amountColumn, mixed $value, string $currency): self {
		return $this->whereMoneyCompare($amountColumn, $value, '<', null, $currency);
	}

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string $amountColumn Column containing the stored amount.
	 * @param mixed $value Money amount compared after currency bridge normalization.
	 * @param string $currency Fixed currency code used instead of a row currency column.
	 * @return self Current repository query instance.
	 */
	public function whereMoneyLteIn(string $amountColumn, mixed $value, string $currency): self {
		return $this->whereMoneyCompare($amountColumn, $value, '<=', null, $currency);
	}

	/**
	 * Returns an immutable snapshot of the repository query specification.
	 *
	 * @return QuerySpec Immutable query specification snapshot.
	 */
	public function spec(): QuerySpec {
		return clone $this;
	}

	/**
	 * Exports query execution state for cache keys and diagnostics.
	 *
	 * Fingerprint data describes constraints, selected columns, eager descriptors,
	 * hydrators, cache policy, and mutation policy without executing SQL.
	 *
	 * @return array<string,mixed> SQL repository query fingerprint data.
	 */
	public function fingerprintPayload(): array {
		$compiled=(clone $this)->compile();
		return [
			'type'=>'repository_query',
			'repository_class'=>$this->repositoryClass,
			'columns'=>$this->columns,
			'caching'=>$this->caching,
			'hydrator'=>$this->hydratorDescriptor($this->hydrator),
			'eager_relations'=>$this->eagerRelationDescriptors(),
			'eager_counts'=>$this->eagerCountDescriptors(),
			'eager_aggregates'=>$this->eagerAggregateDescriptors(),
			'query'=>[
				'params'=>$compiled['params'],
				'vars'=>$compiled['vars'],
			],
		];
	}

	/**
	 * Exports query execution state for cache keys and diagnostics.
	 *
	 * Fingerprint data describes constraints, selected columns, eager descriptors,
	 * hydrators, cache policy, and mutation policy without executing SQL.
	 * @return string SHA-1 fingerprint hash.
	 */
	public function fingerprint(): string {
		return $this->fingerprintHash($this->fingerprintPayload());
	}

	/**
	 * Serializes the repository query state for queued work, caching, and diagnostics.
	 *
	 * @return array<string,mixed> Serializable state for fromExecutionState().
	 */
	public function executionState(): array {
		$payload=$this->fingerprintPayload();
		$state=$payload;
		unset($state['type']);
		$state['builder_state']=$this->builderState();
		$state['money_mappings']=$this->writeMoneyMappings;
		$state['stored_money_mappings']=$this->writeStoredMoneyMappings;
		$state['fingerprint_payload']=$payload;
		$state['fingerprint']=$this->fingerprintHash($payload);
		return $state;
	}

	/**
	 * Rebuilds a repository query from serialized execution state.
	 *
	 *
	 * @param array<string,mixed> $state Serialized execution state from executionState().
	 * @return self Current repository query instance.
	 */
	public static function fromExecutionState(array $state): self {
		$repositoryClass=trim((string)($state['repository_class'] ?? ''));
		if($repositoryClass===''){
			throw SqlError::invalidRepositoryClass($repositoryClass);
		}
		$query=new self($repositoryClass);
		$query->columns=self::columns($state['columns'] ?? '*');
		$query->caching=$state['caching'] ?? [true];
		$query->hydrator=$state['hydrator'] ?? null;
		$query->applyBuilderState(is_array($state['builder_state'] ?? null) ? $state['builder_state'] : []);
		$query->restoreCompiledTransforms(
			is_array($state['money_mappings'] ?? null) ? $state['money_mappings'] : [],
			is_array($state['stored_money_mappings'] ?? null) ? $state['stored_money_mappings'] : []
		);
		$query->restoreEagerRelations(is_array($state['eager_relations'] ?? null) ? $state['eager_relations'] : []);
		$query->restoreEagerCounts(is_array($state['eager_counts'] ?? null) ? $state['eager_counts'] : []);
		$query->restoreEagerAggregates(is_array($state['eager_aggregates'] ?? null) ? $state['eager_aggregates'] : []);
		return $query;
	}

	/**
	 * Reads rows from the repository table with the current query state.
	 *
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int,array<string,mixed>> Repository rows after transforms, eager attachments, and hydration retry.
	 */
	public function get(array|string|null $columns=null, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		$rows=$this->transformRows(
			$repository::all(
				$this->columnsWithEagerParentKeys($columns ?? $this->columns),
				clone $this,
				$caching ?? $this->caching
			)
		);
		return $this->applyEagerRelations($rows);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
	public function all(array|string|null $columns=null, bool|array|string|null $caching=null): array {
		return $this->get($columns, $caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return ?array First matching row, or null when absent.
	 */
	public function first(array|string|null $columns=null, bool|array|string|null $caching=null): ?array {
		$repository=$this->repositoryClass();
		$row=$repository::first(
			$this->columnsWithEagerParentKeys($columns ?? $this->columns),
			clone $this,
			$caching ?? $this->caching
		);
		if($row===null){
			return null;
		}
		$rows=$this->applyEagerRelations([$this->transformRow($row)]);
		return $rows[0] ?? null;
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
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
			$this->repositoryClass,
			$this->notFoundContext($columns),
			$message
		);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $column Column selected from the first matching row.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
	public function value(string $column, bool|array|string|null $caching=null): mixed {
		$row=$this->first($column, $caching);
		return is_array($row) && array_key_exists($column, $row) ? $row[$column] : null;
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $column Column selected from the first matching row.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
	public function valueOrFail(
		string $column,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		$row=$this->firstOrFail($column, $caching, $message);
		return $row[$column] ?? null;
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $column Column whose values are returned.
	 * @param ?string $keyColumn Optional column used as array keys.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
	public function pluck(
		string $column,
		?string $keyColumn=null,
		bool|array|string|null $caching=null
	): array {
		return $this->pluckRows(
			$this->get($this->pluckColumns($column, $keyColumn), $caching),
			$column,
			$keyColumn
		);
	}

	/**
	 * Returns rows keyed by a selected column.
	 *
	 * The key column is included in the projection before rows are read, then used
	 * to build the returned associative result.
	 *
	 * @param string $keyColumn Column used as each result key.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
	public function keyBy(
		string $keyColumn,
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): array {
		return $this->keyRowsBy(
			$this->get($this->keyColumns($keyColumn, $columns), $caching),
			$keyColumn
		);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
	public function sole(
		array|string|null $columns=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): array {
		$rows=$this->singleResultRows($columns, $caching);
		if($rows===[]){
			throw SqlError::recordNotFound(
				$this->repositoryClass,
				$this->notFoundContext($columns),
				$message,
				'Use first() when zero matches are acceptable, or tighten the query before calling sole().'
			);
		}
		if(count($rows)>1){
			throw SqlError::multipleRecordsFound(
				$this->repositoryClass,
				$this->notFoundContext($columns, ['matched_rows_sample'=>count($rows)]),
				$message,
				'Use get()/all() when multiple matches are expected, or tighten the query until it uniquely identifies a single row.'
			);
		}
		return $rows[0];
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return bool True when at least one matching row exists.
	 */
	public function exists(bool|array|string|null $caching=null): bool {
		$repository=$this->repositoryClass();
		return $repository::exists(clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int|bool|null Affected-row count, boolean status, or null when execution is deferred or unavailable.
	 */
	public function count(bool|array|string|null $caching=null): int|bool|null {
		$repository=$this->repositoryClass();
		return $repository::count(clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $function SQL aggregate function name.
	 * @param string $column Column or `*` selector passed to the aggregate.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
	public function aggregate(
		string $function,
		string $column='*',
		bool|array|string|null $caching=null
	): mixed {
		$repository=$this->repositoryClass();
		return $repository::aggregate($function, $column, clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $column Numeric column passed to SUM().
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
	public function sum(string $column, bool|array|string|null $caching=null): mixed {
		$repository=$this->repositoryClass();
		return $repository::sum($column, clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $column Numeric column passed to AVG().
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
	public function avg(string $column, bool|array|string|null $caching=null): mixed {
		$repository=$this->repositoryClass();
		return $repository::avg($column, clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $column Column passed to MIN().
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
	public function min(string $column, bool|array|string|null $caching=null): mixed {
		$repository=$this->repositoryClass();
		return $repository::min($column, clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $column Column passed to MAX().
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
	public function max(string $column, bool|array|string|null $caching=null): mixed {
		$repository=$this->repositoryClass();
		return $repository::max($column, clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $column Column counted with COUNT(column).
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int|bool|null Affected-row count, boolean status, or null when execution is deferred or unavailable.
	 */
	public function countColumn(string $column, bool|array|string|null $caching=null): int|bool|null {
		$repository=$this->repositoryClass();
		return $repository::countColumn($column, clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $column Column counted with COUNT(DISTINCT column).
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int|bool|null Affected-row count, boolean status, or null when execution is deferred or unavailable.
	 */
	public function countDistinct(string $column, bool|array|string|null $caching=null): int|bool|null {
		$repository=$this->repositoryClass();
		return $repository::countDistinct($column, clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string|array $groupColumns GroupColumns.
	 * @param string $function SQL aggregate function name.
	 * @param string $column Column or `*` selector passed to the aggregate.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool $distinct Whether the aggregate should use DISTINCT values.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
	public function aggregateRowsBy(
		string|array $groupColumns,
		string $function,
		string $column='*',
		bool|array|string|null $caching=null,
		bool $distinct=false
	): array {
		$repository=$this->repositoryClass();
		return $repository::aggregateRowsBy(
			$groupColumns,
			$function,
			$column,
			clone $this,
			$caching ?? $this->caching,
			$distinct
		);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $groupColumn Column used to group result rows.
	 * @param string $column Column or `*` selector counted in each group.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
	public function countBy(string $groupColumn, string $column='*', bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $repository::countBy($groupColumn, $column, clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $groupColumn Column used to group result rows.
	 * @param string $column Column counted with DISTINCT values in each group.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
	public function countDistinctBy(string $groupColumn, string $column, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $repository::countDistinctBy($groupColumn, $column, clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $groupColumn Column used to group result rows.
	 * @param string $column Numeric column summed in each group.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
	public function sumBy(string $groupColumn, string $column, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $repository::sumBy($groupColumn, $column, clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $groupColumn Column used to group result rows.
	 * @param string $column Numeric column averaged in each group.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
	public function avgBy(string $groupColumn, string $column, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $repository::avgBy($groupColumn, $column, clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $groupColumn Column used to group result rows.
	 * @param string $column Column passed to MIN() in each group.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
	public function minBy(string $groupColumn, string $column, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $repository::minBy($groupColumn, $column, clone $this, $caching ?? $this->caching);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $groupColumn Column used to group result rows.
	 * @param string $column Column passed to MAX() in each group.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
	public function maxBy(string $groupColumn, string $column, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $repository::maxBy($groupColumn, $column, clone $this, $caching ?? $this->caching);
	}

	/**
	 * Streams or paginates SQL results in bounded batches.
	 *
	 * Batch helpers repeatedly execute constrained reads and stop when callbacks request termination or result windows are exhausted.
	 *
	 * @param int $page One-based page number requested by the caller.
	 * @param int $perPage Maximum rows requested per page.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return PageResult Paginated repository result set.
	 */
	public function paginate(
		int $page=1,
		int $perPage=50,
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): PageResult {
		$repository=$this->repositoryClass();
		$pageResult=$repository::paginate(
			$this->columnsWithEagerParentKeys($columns ?? $this->columns),
			clone $this,
			$page,
			$perPage,
			$caching ?? $this->caching
		);
		return new PageResult(
			$this->applyEagerRelations($this->transformRows($pageResult->items())),
			$pageResult->total(),
			$pageResult->page(),
			$pageResult->perPage()
		);
	}

	/**
	 * Reads repository rows and converts them through the configured hydrator.
	 *
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
	public function getHydrated(
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): array {
		$repository=$this->repositoryClass();
		return $this->applyEagerRelations(
			$this->hydrateRepositoryRows(
				$this->transformRows(
					$repository::all(
						$this->columnsWithEagerParentKeys($columns ?? $this->columns),
						clone $this,
						$caching ?? $this->caching
					)
				),
				$hydrator ?? $this->hydrator
			)
		);
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
	public function firstHydrated(
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		$repository=$this->repositoryClass();
		$row=$repository::first(
			$this->columnsWithEagerParentKeys($columns ?? $this->columns),
			clone $this,
			$caching ?? $this->caching
		);
		$record=$row!==null ? $this->hydrateRepositoryRow($this->transformRow($row), $hydrator ?? $this->hydrator) : null;
		if($record===null){
			return null;
		}
		$records=$this->applyEagerRelations([$record]);
		return $records[0] ?? null;
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
	public function getRecords(
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): array {
		return $this->getHydrated($columns, $hydrator, $caching);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
	public function firstRecord(
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		return $this->firstHydrated($columns, $hydrator, $caching);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
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
			$this->repositoryClass,
			$this->notFoundContext($columns),
			$message
		);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
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

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $column Column selected from the sole matching row.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
	public function soleValue(
		string $column,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		$row=$this->sole($column, $caching, $message);
		return $row[$column] ?? null;
	}

	/**
	 * Reads the first row matching the repository primary key.
	 *
	 * Active query predicates are preserved on the cloned lookup query.
	 *
	 * @param mixed $id Primary-key value matched against the repository key column.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return ?array First matching row, or null when absent.
	 */
	public function find(
		mixed $id,
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): ?array {
		return (clone $this)->whereKey($id)->first($columns, $caching);
	}

	/**
	 * Reads the first primary-key match or raises a not-found SQL error.
	 *
	 * Active query predicates are preserved, so scoped queries can still reject an
	 * otherwise valid primary key.
	 *
	 * @param mixed $id Primary-key value matched against the repository key column.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return array Repository rows, grouped values, or keyed result data.
	 */
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
			$this->repositoryClass,
			$this->notFoundContext($columns, ['id'=>$id]),
			$message,
			'Use find() when a missing record is acceptable, or verify the primary key and active filters before calling findOrFail().'
		);
	}

	/**
	 * Reads and hydrates the first row matching the repository primary key.
	 *
	 * @param mixed $id Primary-key value matched against the repository key column.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
	public function findHydrated(
		mixed $id,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		return (clone $this)->whereKey($id)->firstHydrated($columns, $hydrator, $caching);
	}

	/**
	 * Reads and hydrates a primary-key match or raises a not-found SQL error.
	 *
	 * @param mixed $id Primary-key value matched against the repository key column.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
	public function findHydratedOrFail(
		mixed $id,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		$result=$this->findHydrated($id, $columns, $hydrator, $caching);
		if($result!==null){
			return $result;
		}
		return throw SqlError::recordNotFound(
			$this->repositoryClass,
			$this->notFoundContext($columns, ['id'=>$id]),
			$message,
			'Use findHydrated() when a missing record is acceptable, or verify the primary key and active filters before calling findHydratedOrFail().'
		);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param mixed $id Primary-key value matched against the repository key column.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
	public function findRecord(
		mixed $id,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		return $this->findHydrated($id, $columns, $hydrator, $caching);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param mixed $id Primary-key value matched against the repository key column.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return mixed Hydrated record, scalar aggregate, or repository value.
	 */
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
			$this->repositoryClass,
			$this->notFoundContext($columns, ['id'=>$id]),
			$message,
			'Use findRecord() when a missing record is acceptable, or verify the primary key and active filters before calling findRecordOrFail().'
		);
	}

	/**
	 * Streams or paginates SQL results in bounded batches.
	 *
	 * Batch helpers repeatedly execute constrained reads and stop when callbacks request termination or result windows are exhausted.
	 *
	 * @param int $page One-based page number requested by the caller.
	 * @param int $perPage Maximum rows requested per page.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return PageResult Paginated repository result set.
	 */
	public function paginateHydrated(
		int $page=1,
		int $perPage=50,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): PageResult {
		$repository=$this->repositoryClass();
		$pageResult=$repository::paginate(
			$this->columnsWithEagerParentKeys($columns ?? $this->columns),
			clone $this,
			$page,
			$perPage,
			$caching ?? $this->caching
		);
		return new PageResult(
			$this->applyEagerRelations(
				$this->hydrateRepositoryRows(
					$this->transformRows($pageResult->items()),
					$hydrator ?? $this->hydrator
				)
			),
			$pageResult->total(),
			$pageResult->page(),
			$pageResult->perPage()
		);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param int $page One-based page number requested by the caller.
	 * @param int $perPage Maximum rows requested per page.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return PageResult Paginated repository result set.
	 */
	public function paginateRecords(
		int $page=1,
		int $perPage=50,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): PageResult {
		return $this->paginateHydrated($page, $perPage, $columns, $hydrator, $caching);
	}

	/**
	 * Streams or paginates SQL results in bounded batches.
	 *
	 * Batch helpers repeatedly execute constrained reads and stop when callbacks request termination or result windows are exhausted.
	 *
	 * @param int $size Maximum rows requested for each chunk.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int Count or aggregate total.
	 */
	public function chunk(
		int $size,
		callable $callback,
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): int {
		$repository=$this->repositoryClass();
		return $repository::chunk(
			$size,
			function(array $rows, int $page, int $processed)use($callback): mixed{
				return $callback($this->applyEagerRelations($this->transformRows($rows)), $page, $processed);
			},
			$this->columnsWithEagerParentKeys($columns ?? $this->columns),
			clone $this,
			$caching ?? $this->caching
		);
	}

	/**
	 * Streams or paginates SQL results in bounded batches.
	 *
	 * Batch helpers repeatedly execute constrained reads and stop when callbacks request termination or result windows are exhausted.
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param int $size Maximum rows requested for each chunk.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int Count or aggregate total.
	 */
	public function each(
		callable $callback,
		int $size=500,
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): int {
		$processed=0;
		$this->chunk(
			$size,
			static function(array $rows, int $page)use($callback, &$processed): bool|null{
				foreach($rows as $index=>$row){
					$processed++;
					if($callback($row, $processed, $page, $index)===false){
						return false;
					}
				}
				return null;
			},
			$columns,
			$caching
		);
		return $processed;
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param int $size Maximum rows requested for each chunk.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int Count or aggregate total.
	 */
	public function chunkRecords(
		int $size,
		callable $callback,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): int {
		$repository=$this->repositoryClass();
		return $repository::chunk(
			$size,
			function(array $rows, int $page, int $processed)use($callback, $hydrator): mixed{
				$records=$this->hydrateRepositoryRows(
					$this->transformRows($rows),
					$hydrator ?? $this->hydrator
				);
				return $callback($this->applyEagerRelations($records), $page, $processed);
			},
			$this->columnsWithEagerParentKeys($columns ?? $this->columns),
			clone $this,
			$caching ?? $this->caching
		);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param int $size Maximum rows requested for each chunk.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int Count or aggregate total.
	 */
	public function eachRecord(
		callable $callback,
		int $size=500,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): int {
		$processed=0;
		$this->chunkRecords(
			$size,
			static function(array $records, int $page)use($callback, &$processed): bool|null{
				foreach($records as $index=>$record){
					$processed++;
					if($callback($record, $processed, $page, $index)===false){
						return false;
					}
				}
				return null;
			},
			$columns,
			$hydrator,
			$caching
		);
		return $processed;
	}

	/**
	 * Streams or paginates SQL results in bounded batches.
	 *
	 * Batch helpers repeatedly execute constrained reads and stop when callbacks request termination or result windows are exhausted.
	 *
	 * @param int $size Maximum rows requested for each chunk.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param ?string $keyColumn Cursor column, or null for the repository primary key.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string $direction Cursor direction passed to chunkById().
	 * @return int Count or aggregate total.
	 */
	public function chunkById(
		int $size,
		callable $callback,
		?string $keyColumn=null,
		array|string|null $columns=null,
		bool|array|string|null $caching=null,
		string $direction='ASC'
	): int {
		$repository=$this->repositoryClass();
		return $repository::chunkById(
			$size,
			function(array $rows, mixed $cursor, int $processed)use($callback): mixed{
				return $callback($this->applyEagerRelations($this->transformRows($rows)), $cursor, $processed);
			},
			$keyColumn,
			$this->columnsWithEagerParentKeys($columns ?? $this->columns),
			clone $this,
			$caching ?? $this->caching,
			$direction
		);
	}

	/**
	 * Streams or paginates SQL results in bounded batches.
	 *
	 * Batch helpers repeatedly execute constrained reads and stop when callbacks request termination or result windows are exhausted.
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param int $size Maximum rows requested for each chunk.
	 * @param ?string $keyColumn Cursor column, or null for the repository primary key.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string $direction Cursor direction passed to chunkById().
	 * @return int Count or aggregate total.
	 */
	public function eachById(
		callable $callback,
		int $size=500,
		?string $keyColumn=null,
		array|string|null $columns=null,
		bool|array|string|null $caching=null,
		string $direction='ASC'
	): int {
		$processed=0;
		$this->chunkById(
			$size,
			static function(array $rows, mixed $cursor)use($callback, &$processed): bool|null{
				foreach($rows as $index=>$row){
					$processed++;
					if($callback($row, $processed, $cursor, $index)===false){
						return false;
					}
				}
				return null;
			},
			$keyColumn,
			$columns,
			$caching,
			$direction
		);
		return $processed;
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param int $size Maximum rows requested for each chunk.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param ?string $keyColumn Cursor column, or null for the repository primary key.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string $direction Cursor direction passed to chunkById().
	 * @return int Count or aggregate total.
	 */
	public function chunkRecordsById(
		int $size,
		callable $callback,
		?string $keyColumn=null,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		string $direction='ASC'
	): int {
		$repository=$this->repositoryClass();
		return $repository::chunkById(
			$size,
			function(array $rows, mixed $cursor, int $processed)use($callback, $hydrator): mixed{
				$records=$this->hydrateRepositoryRows(
					$this->transformRows($rows),
					$hydrator ?? $this->hydrator
				);
				return $callback($this->applyEagerRelations($records), $cursor, $processed);
			},
			$keyColumn,
			$this->columnsWithEagerParentKeys($columns ?? $this->columns),
			clone $this,
			$caching ?? $this->caching,
			$direction
		);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param int $size Maximum rows requested for each chunk.
	 * @param ?string $keyColumn Cursor column, or null for the repository primary key.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string $direction Cursor direction passed to chunkById().
	 * @return int Count or aggregate total.
	 */
	public function eachRecordById(
		callable $callback,
		int $size=500,
		?string $keyColumn=null,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		string $direction='ASC'
	): int {
		$processed=0;
		$this->chunkRecordsById(
			$size,
			static function(array $records, mixed $cursor)use($callback, &$processed): bool|null{
				foreach($records as $index=>$record){
					$processed++;
					if($callback($record, $processed, $cursor, $index)===false){
						return false;
					}
				}
				return null;
			},
			$keyColumn,
			$columns,
			$hydrator,
			$caching,
			$direction
		);
		return $processed;
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param array<string,mixed> $attributes Attributes used to find an existing row.
	 * @param array<string,mixed> $values Write values used only when a row must be created.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return array<string,mixed> Created or existing row after write-field normalization and row transforms.
	 */
	public function firstOrCreate(
		array $attributes,
		array $values=[],
		array|string|null $columns=null,
		bool|array|string|null $caching=null,
		bool|array|null $clearCache=null
	): array {
		if($attributes===[]){
			throw SqlError::invalidFieldPayload($this->repositoryClass, 'Attribute payload cannot be empty.');
		}
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('first_or_create', $resolvedClearCache);
		$row=$repository::firstOrCreate(
			$this->resolvedWriteFields($attributes),
			$values!==[] ? $this->resolvedWriteFields($values) : [],
			$columns ?? $this->columns,
			clone $this,
			$caching ?? $this->caching,
			$resolvedClearCache
		);
		return $this->transformRow($row);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param array<string,mixed> $attributes Attributes used to find an existing row.
	 * @param array<string,mixed> $values Write values used when creating or updating the row.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return array<string,mixed> Created or updated row after write-field normalization and row transforms.
	 */
	public function updateOrCreate(
		array $attributes,
		array $values=[],
		array|string|null $columns=null,
		bool|array|string|null $caching=null,
		bool|array|null $clearCache=null
	): array {
		if($attributes===[]){
			throw SqlError::invalidFieldPayload($this->repositoryClass, 'Attribute payload cannot be empty.');
		}
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('update_or_create', $resolvedClearCache);
		$row=$repository::updateOrCreate(
			$this->resolvedWriteFields($attributes),
			$values!==[] ? $this->resolvedWriteFields($values) : [],
			$columns ?? $this->columns,
			clone $this,
			$caching ?? $this->caching,
			$resolvedClearCache
		);
		return $this->transformRow($row);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Repository mutation result.
	 */
	public function create(array $fields, bool|array|null $clearCache=null): MutationResult {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('create', $resolvedClearCache);
		return $repository::create($this->resolvedWriteFields($fields), $resolvedClearCache);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param array<int,array<string,mixed>> $rows Write rows before money and schema normalization.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationBatchResult Batched repository mutation result.
	 */
	public function createMany(array $rows, bool|array|null $clearCache=null): MutationBatchResult {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('create_many', $resolvedClearCache);
		return $repository::createMany($this->resolvedWriteRows($rows), $resolvedClearCache);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Repository mutation result.
	 */
	public function update(array $fields, bool|array|null $clearCache=null): MutationResult {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('update', $resolvedClearCache);
		return $repository::update($this->resolvedWriteFields($fields), clone $this, $resolvedClearCache);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param int $expectedVersion Optimistic-lock version expected in storage.
	 * @param string $versionColumn Column that stores the optimistic-lock version.
	 * @param int $bump Amount added to the version when the update succeeds.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Repository mutation result.
	 */
	public function updateWithVersion(
		array $fields,
		int $expectedVersion,
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): MutationResult {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('update_with_version', $resolvedClearCache);
		return $repository::updateWithVersion(
			$this->resolvedWriteFields($fields),
			clone $this,
			$expectedVersion,
			$versionColumn,
			$bump,
			$resolvedClearCache
		);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param int $expectedVersion Optimistic-lock version expected in storage.
	 * @param string $versionColumn Column that stores the optimistic-lock version.
	 * @param int $bump Amount added to the version when the update succeeds.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Repository mutation result.
	 */
	public function updateWithVersionOrFail(
		array $fields,
		int $expectedVersion,
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): MutationResult {
		return $this->updateWithVersion($fields, $expectedVersion, $versionColumn, $bump, $clearCache)->throwIfFailedOrStale();
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param string $column Numeric column incremented by the repository.
	 * @param int|float $amount Amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Repository mutation result.
	 */
	public function increment(string $column, int|float $amount=1, bool|array|null $clearCache=null): MutationResult {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('increment', $resolvedClearCache);
		return $repository::increment($column, clone $this, $amount, $resolvedClearCache);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param string $column Numeric column decremented by the repository.
	 * @param int|float $amount Amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Repository mutation result.
	 */
	public function decrement(string $column, int|float $amount=1, bool|array|null $clearCache=null): MutationResult {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('decrement', $resolvedClearCache);
		return $repository::decrement($column, clone $this, $amount, $resolvedClearCache);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Repository mutation result.
	 */
	public function delete(bool|array|null $clearCache=null): MutationResult {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('delete', $resolvedClearCache);
		return $repository::delete(clone $this, $resolvedClearCache);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param string|array|null $updateParams UpdateParams.
	 * @param ?array<string,mixed> $updateVars Bound variables for custom upsert update expressions.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Repository mutation result.
	 */
	public function upsert(
		array $fields,
		string|array|null $updateParams=null,
		?array $updateVars=null,
		bool|array|null $clearCache=null
	): MutationResult {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('upsert', $resolvedClearCache);
		return $repository::upsert($this->resolvedWriteFields($fields), $updateParams, $updateVars, $resolvedClearCache);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param array<int,array<string,mixed>> $rows Write rows before money and schema normalization.
	 * @param string|array|null $updateParams UpdateParams.
	 * @param ?array<string,mixed> $updateVars Bound variables for custom upsert update expressions.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationBatchResult Batched repository mutation result.
	 */
	public function upsertMany(
		array $rows,
		string|array|null $updateParams=null,
		?array $updateVars=null,
		bool|array|null $clearCache=null
	): MutationBatchResult {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('upsert_many', $resolvedClearCache);
		return $repository::upsertMany($this->resolvedWriteRows($rows), $updateParams, $updateVars, $resolvedClearCache);
	}

	/**
	 * Queues a repository read and delivers normalized rows to the callback.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueGet(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueAll(
			$this->columnsWithEagerParentKeys($columns ?? $this->columns),
			clone $this,
			fn(mixed $result): mixed => $callback($this->transformQueuedRepositoryResult($result)),
			$queue,
			$caching ?? $this->caching
		);
	}

	/**
	 * Queues a single-row repository read and delivers the normalized row to the callback.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueFirst(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueFirst(
			$this->columnsWithEagerParentKeys($columns ?? $this->columns),
			clone $this,
			fn(mixed $result): mixed => $callback($this->transformQueuedRepositoryResult($result)),
			$queue,
			$caching ?? $this->caching
		);
	}

	/**
	 * Queues a repository read and hydrates the rows before invoking the callback.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueGetHydrated(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueAll(
			$this->columnsWithEagerParentKeys($columns ?? $this->columns),
			clone $this,
			fn(mixed $result): mixed => $callback($this->hydrateQueuedRepositoryRows($result, $hydrator ?? $this->hydrator)),
			$queue,
			$caching ?? $this->caching
		);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueGetRecords(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queueGetHydrated($callback, $queue, $columns, $hydrator, $caching);
	}

	/**
	 * Queues a single-row repository read and hydrates the row before invoking the callback.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueFirstHydrated(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueFirst(
			$this->columnsWithEagerParentKeys($columns ?? $this->columns),
			clone $this,
			fn(mixed $result): mixed => $callback($this->hydrateQueuedRepositoryRow($result, $hydrator ?? $this->hydrator)),
			$queue,
			$caching ?? $this->caching
		);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueFirstRecord(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queueFirstHydrated($callback, $queue, $columns, $hydrator, $caching);
	}

	/**
	 * Queues a single-row repository read that fails fast when no row is returned.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueFirstOrFail(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return $this->queueFirst(
			function(?array $result)use($callback, $columns, $message): mixed{
				if($result!==null){
					return $callback($result);
				}
				throw SqlError::recordNotFound(
					$this->repositoryClass,
					$this->notFoundContext($columns),
					$message
				);
			},
			$queue,
			$columns,
			$caching
		);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueFirstRecordOrFail(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return $this->queueFirstHydrated(
			function(mixed $record)use($callback, $columns, $message): mixed{
				if($record!==null){
					return $callback($record);
				}
				throw SqlError::recordNotFound(
					$this->repositoryClass,
					$this->notFoundContext($columns),
					$message
				);
			},
			$queue,
			$columns,
			$hydrator,
			$caching
		);
	}

	/**
	 * Queues a primary-key repository lookup and delivers the normalized row to the callback.
	 *
	 *
	 * @param mixed $id Primary-key value matched against the repository key column.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueFind(
		mixed $id,
		callable $callback,
		array|string|null $columns=null,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return (clone $this)->whereKey($id)->queueFirst($callback, $queue, $columns, $caching);
	}

	/**
	 * Queues a primary-key repository lookup that fails fast when no row is returned.
	 *
	 *
	 * @param mixed $id Primary-key value matched against the repository key column.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueFindOrFail(
		mixed $id,
		callable $callback,
		array|string|null $columns=null,
		string $queue='end',
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return $this->queueFind(
			$id,
			function(?array $result)use($callback, $columns, $id, $message): mixed{
				if($result!==null){
					return $callback($result);
				}
				throw SqlError::recordNotFound(
					$this->repositoryClass,
					$this->notFoundContext($columns, ['id'=>$id]),
					$message,
					'Use queueFind() when a missing record is acceptable, or verify the primary key and active filters before calling queueFindOrFail().'
				);
			},
			$columns,
			$queue,
			$caching
		);
	}

	/**
	 * Queues a primary-key repository lookup and hydrates the row before invoking the callback.
	 *
	 *
	 * @param mixed $id Primary-key value matched against the repository key column.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue Repository batch queue name.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueFindHydrated(
		mixed $id,
		callable $callback,
		array|string|null $columns=null,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return (clone $this)->whereKey($id)->queueFirstHydrated($callback, $queue, $columns, $hydrator, $caching);
	}

	/**
	 * Queues a hydrated primary-key repository lookup that fails fast when no row is returned.
	 *
	 *
	 * @param mixed $id Primary-key value matched against the repository key column.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue Repository batch queue name.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueFindHydratedOrFail(
		mixed $id,
		callable $callback,
		array|string|null $columns=null,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return $this->queueFindHydrated(
			$id,
			function(mixed $record)use($callback, $columns, $id, $message): mixed{
				if($record!==null){
					return $callback($record);
				}
				throw SqlError::recordNotFound(
					$this->repositoryClass,
					$this->notFoundContext($columns, ['id'=>$id]),
					$message,
					'Use queueFindHydrated() when a missing record is acceptable, or verify the primary key and active filters before calling queueFindHydratedOrFail().'
				);
			},
			$columns,
			$queue,
			$hydrator,
			$caching
		);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param mixed $id Primary-key value matched against the repository key column.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue Repository batch queue name.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueFindRecord(
		mixed $id,
		callable $callback,
		array|string|null $columns=null,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queueFindHydrated($id, $callback, $columns, $queue, $hydrator, $caching);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param mixed $id Primary-key value matched against the repository key column.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue Repository batch queue name.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueFindRecordOrFail(
		mixed $id,
		callable $callback,
		array|string|null $columns=null,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return $this->queueFindHydratedOrFail($id, $callback, $columns, $queue, $hydrator, $caching, $message);
	}

	/**
	 * Queues a repository column pluck and delivers the collected values to the callback.
	 *
	 *
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param ?string $keyColumn KeyColumn.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queuePluck(
		string $column,
		callable $callback,
		?string $keyColumn=null,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queueGet(
			fn(array $rows): mixed => $callback($this->pluckRows($rows, $column, $keyColumn)),
			$queue,
			$this->pluckColumns($column, $keyColumn),
			$caching
		);
	}

	/**
	 * Queues a repository read keyed by the selected column.
	 *
	 *
	 * @param string $keyColumn Column used as returned array keys.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueKeyBy(
		string $keyColumn,
		callable $callback,
		array|string|null $columns=null,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queueGet(
			fn(array $rows): mixed => $callback($this->keyRowsBy($rows, $keyColumn)),
			$queue,
			$this->keyColumns($keyColumn, $columns),
			$caching
		);
	}

	/**
	 * Queues a repository read that expects exactly one matching row.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueSole(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return (clone $this)->limit(2)->queueGet(
			function(array $rows)use($callback, $columns, $message): mixed{
				if($rows===[]){
					throw SqlError::recordNotFound(
						$this->repositoryClass,
						$this->notFoundContext($columns),
						$message,
						'Use queueFirst() when zero matches are acceptable, or tighten the repository query before calling queueSole().'
					);
				}
				if(count($rows)>1){
					throw SqlError::multipleRecordsFound(
						$this->repositoryClass,
						$this->notFoundContext($columns, ['matched_rows_sample'=>count($rows)]),
						$message,
						'Use queueGet()/queueAll() when multiple matches are expected, or tighten the repository query until it uniquely identifies a single row.'
					);
				}
				return $callback($rows[0]);
			},
			$queue,
			$columns,
			$caching
		);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueSoleRecord(
		callable $callback,
		string $queue='end',
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return (clone $this)->limit(2)->queueGetHydrated(
			function(array $records)use($callback, $columns, $message): mixed{
				if($records===[]){
					throw SqlError::recordNotFound(
						$this->repositoryClass,
						$this->notFoundContext($columns),
						$message,
						'Use queueFirstRecord() when zero matches are acceptable, or tighten the repository query before calling queueSoleRecord().'
					);
				}
				if(count($records)>1){
					throw SqlError::multipleRecordsFound(
						$this->repositoryClass,
						$this->notFoundContext($columns, ['matched_rows_sample'=>count($records)]),
						$message,
						'Use queueGetRecords() when multiple matches are expected, or tighten the repository query until it uniquely identifies a single record.'
					);
				}
				return $callback($records[0]);
			},
			$queue,
			$columns,
			$hydrator,
			$caching
		);
	}

	/**
	 * Queues a scalar repository read that expects exactly one matching value.
	 *
	 *
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueSoleValue(
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return $this->queueSole(
			static fn(array $row): mixed => $callback($row[$column] ?? null),
			$queue,
			$column,
			$caching,
			$message
		);
	}

	/**
	 * Queues a scalar repository read for the selected column.
	 *
	 *
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
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

	/**
	 * Queues a scalar repository read and fails fast when the value is missing.
	 *
	 *
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueValueOrFail(
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return $this->queueFirstOrFail(
			static fn(array $row): mixed => $callback($row[$column] ?? null),
			$queue,
			$column,
			$caching,
			$message
		);
	}

	/**
	 * Queues an existence check for the current repository predicates.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueExists(
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queueCount(
			static fn(mixed $count): mixed => $callback(is_int($count) ? $count > 0 : false),
			$queue,
			$caching
		);
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueCount(
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueCount(clone $this, $callback, $queue, $caching ?? $this->caching);
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param string $function SQL aggregate function name.
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool $distinct Whether the aggregate should use DISTINCT values.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
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

	/**
	 * Queues a SUM aggregate for the selected repository column.
	 *
	 *
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueSum(
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queueAggregate('SUM', $column, $callback, $queue, $caching);
	}

	/**
	 * Queues an AVG aggregate for the selected repository column.
	 *
	 *
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueAvg(
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queueAggregate('AVG', $column, $callback, $queue, $caching);
	}

	/**
	 * Queues a MIN aggregate for the selected repository column.
	 *
	 *
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueMin(
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queueAggregate('MIN', $column, $callback, $queue, $caching);
	}

	/**
	 * Queues a MAX aggregate for the selected repository column.
	 *
	 *
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueMax(
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queueAggregate('MAX', $column, $callback, $queue, $caching);
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueCountColumn(
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queueAggregate(
			'COUNT',
			$column,
			static fn(mixed $value): mixed => $callback(is_numeric($value) ? (int)$value : $value),
			$queue,
			$caching
		);
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueCountDistinct(
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queueAggregate(
			'COUNT',
			$column,
			static fn(mixed $value): mixed => $callback(is_numeric($value) ? (int)$value : $value),
			$queue,
			$caching,
			true
		);
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param string|array $groupColumns GroupColumns.
	 * @param string $function SQL aggregate function name.
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool $distinct Whether the aggregate should use DISTINCT values.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueAggregateRowsBy(
		string|array $groupColumns,
		string $function,
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null,
		bool $distinct=false
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueAggregateRowsBy(
			$groupColumns,
			$function,
			$column,
			clone $this,
			$callback,
			$queue,
			$caching ?? $this->caching,
			$distinct
		);
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param string $groupColumn Column used to group queued aggregate results.
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueCountBy(
		string $groupColumn,
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueCountBy($groupColumn, $column, clone $this, $callback, $queue, $caching ?? $this->caching);
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param string $groupColumn Column used to group queued aggregate results.
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueCountDistinctBy(
		string $groupColumn,
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueCountDistinctBy($groupColumn, $column, clone $this, $callback, $queue, $caching ?? $this->caching);
	}

	/**
	 * Queues grouped SUM aggregates for the selected repository column.
	 *
	 *
	 * @param string $groupColumn Column used to group queued aggregate results.
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueSumBy(
		string $groupColumn,
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueSumBy($groupColumn, $column, clone $this, $callback, $queue, $caching ?? $this->caching);
	}

	/**
	 * Queues grouped AVG aggregates for the selected repository column.
	 *
	 *
	 * @param string $groupColumn Column used to group queued aggregate results.
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueAvgBy(
		string $groupColumn,
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueAvgBy($groupColumn, $column, clone $this, $callback, $queue, $caching ?? $this->caching);
	}

	/**
	 * Queues grouped MIN aggregates for the selected repository column.
	 *
	 *
	 * @param string $groupColumn Column used to group queued aggregate results.
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueMinBy(
		string $groupColumn,
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueMinBy($groupColumn, $column, clone $this, $callback, $queue, $caching ?? $this->caching);
	}

	/**
	 * Queues grouped MAX aggregates for the selected repository column.
	 *
	 *
	 * @param string $groupColumn Column used to group queued aggregate results.
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueMaxBy(
		string $groupColumn,
		string $column,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$repository=$this->repositoryClass();
		return $repository::queueMaxBy($groupColumn, $column, clone $this, $callback, $queue, $caching ?? $this->caching);
	}

	/**
	 * Queues a paginated repository read and count callback.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param int $page One-based page number requested by the caller.
	 * @param int $perPage Maximum rows requested per page.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queuePaginate(
		callable $callback,
		int $page=1,
		int $perPage=50,
		array|string|null $columns=null,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$page=max(1, $page);
		$perPage=max(1, min(500, $perPage));
		$total=0;
		$items=[];
		$haveTotal=false;
		$haveItems=false;
		$emit=function()use(&$haveTotal, &$haveItems, &$total, &$items, $callback, $page, $perPage): void{
			if($haveTotal && $haveItems){
				$callback(new PageResult($items, $total, $page, $perPage));
			}
		};
		$countResult=$this->queueCount(
			static function(mixed $count)use(&$total, &$haveTotal, $emit): void{
				$total=is_int($count) ? max(0, $count) : 0;
				$haveTotal=true;
				$emit();
			},
			$queue,
			$caching
		);
		$itemsResult=(clone $this)
			->forPage($page, $perPage)
			->queueGet(
				static function(array $rows)use(&$items, &$haveItems, $emit): void{
					$items=$rows;
					$haveItems=true;
					$emit();
				},
				$queue,
				$columns,
				$caching
			);
		return $countResult===false || $itemsResult===false ? false : ($itemsResult ?? $countResult);
	}

	/**
	 * Queues a paginated repository read and hydrates each page item.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param int $page One-based page number requested by the caller.
	 * @param int $perPage Maximum rows requested per page.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue Repository batch queue name.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queuePaginateHydrated(
		callable $callback,
		int $page=1,
		int $perPage=50,
		array|string|null $columns=null,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		$page=max(1, $page);
		$perPage=max(1, min(500, $perPage));
		$total=0;
		$items=[];
		$haveTotal=false;
		$haveItems=false;
		$emit=function()use(&$haveTotal, &$haveItems, &$total, &$items, $callback, $page, $perPage): void{
			if($haveTotal && $haveItems){
				$callback(new PageResult($items, $total, $page, $perPage));
			}
		};
		$countResult=$this->queueCount(
			static function(mixed $count)use(&$total, &$haveTotal, $emit): void{
				$total=is_int($count) ? max(0, $count) : 0;
				$haveTotal=true;
				$emit();
			},
			$queue,
			$caching
		);
		$itemsResult=(clone $this)
			->forPage($page, $perPage)
			->queueGetHydrated(
				static function(array $records)use(&$items, &$haveItems, $emit): void{
					$items=$records;
					$haveItems=true;
					$emit();
				},
				$queue,
				$columns,
				$hydrator,
				$caching
			);
		return $countResult===false || $itemsResult===false ? false : ($itemsResult ?? $countResult);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param int $page One-based page number requested by the caller.
	 * @param int $perPage Maximum rows requested per page.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue Repository batch queue name.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queuePaginateRecords(
		callable $callback,
		int $page=1,
		int $perPage=50,
		array|string|null $columns=null,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return $this->queuePaginateHydrated($callback, $page, $perPage, $columns, $queue, $hydrator, $caching);
	}

	/**
	 * Queues a repository insert mutation.
	 *
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueCreate(
		array $fields,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_create', $resolvedClearCache);
		return $repository::queueCreate($this->resolvedWriteFields($fields), $callback, $queue, $resolvedClearCache);
	}

	/**
	 * Queues multiple repository insert mutations.
	 *
	 *
	 * @param array<int,array<string,mixed>> $rows Write rows before money and schema normalization.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueCreateMany(
		array $rows,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_create_many', $resolvedClearCache);
		return $repository::queueCreateMany($this->resolvedWriteRows($rows), $callback, $queue, $resolvedClearCache);
	}

	/**
	 * Queues a repository update mutation for the current predicates.
	 *
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueUpdate(
		array $fields,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_update', $resolvedClearCache);
		return $repository::queueUpdate($this->resolvedWriteFields($fields), clone $this, $callback, $queue, $resolvedClearCache);
	}

	/**
	 * Queues an optimistic-lock repository update.
	 *
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param int $expectedVersion Optimistic-lock version expected in storage.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param string $versionColumn Column that stores the optimistic-lock version.
	 * @param int $bump Amount added to the version when the update succeeds.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueUpdateWithVersion(
		array $fields,
		int $expectedVersion,
		callable $callback,
		string $queue='end',
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): null|bool {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_update_with_version', $resolvedClearCache);
		return $repository::queueUpdateWithVersion(
			$this->resolvedWriteFields($fields),
			clone $this,
			$expectedVersion,
			$callback,
			$queue,
			$versionColumn,
			$bump,
			$resolvedClearCache
		);
	}

	/**
	 * Queues an optimistic-lock repository update that fails when no row changes.
	 *
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param int $expectedVersion Optimistic-lock version expected in storage.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param string $versionColumn Column that stores the optimistic-lock version.
	 * @param int $bump Amount added to the version when the update succeeds.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueUpdateWithVersionOrFail(
		array $fields,
		int $expectedVersion,
		callable $callback,
		string $queue='end',
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): null|bool {
		return $this->queueUpdateWithVersion(
			$fields,
			$expectedVersion,
			static fn(MutationResult $result): mixed => $callback($result->throwIfFailedOrStale()),
			$queue,
			$versionColumn,
			$bump,
			$clearCache
		);
	}

	/**
	 * Queues an atomic repository-column increment.
	 *
	 *
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param int|float $amount Amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueIncrement(
		string $column,
		callable $callback,
		string $queue='end',
		int|float $amount=1,
		bool|array|null $clearCache=null
	): null|bool {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_increment', $resolvedClearCache);
		return $repository::queueIncrement($column, clone $this, $callback, $queue, $amount, $resolvedClearCache);
	}

	/**
	 * Queues an atomic repository-column decrement.
	 *
	 *
	 * @param string $column Repository column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param int|float $amount Amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueDecrement(
		string $column,
		callable $callback,
		string $queue='end',
		int|float $amount=1,
		bool|array|null $clearCache=null
	): null|bool {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_decrement', $resolvedClearCache);
		return $repository::queueDecrement($column, clone $this, $callback, $queue, $amount, $resolvedClearCache);
	}

	/**
	 * Queues a repository delete mutation for the current predicates.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueDelete(
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_delete', $resolvedClearCache);
		return $repository::queueDelete(clone $this, $callback, $queue, $resolvedClearCache);
	}

	/**
	 * Queues a repository upsert mutation.
	 *
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param string|array|null $updateParams UpdateParams.
	 * @param ?array<string,mixed> $updateVars Bound variables for custom upsert update expressions.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueUpsert(
		array $fields,
		callable $callback,
		string $queue='end',
		string|array|null $updateParams=null,
		?array $updateVars=null,
		bool|array|null $clearCache=null
	): null|bool {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_upsert', $resolvedClearCache);
		return $repository::queueUpsert(
			$this->resolvedWriteFields($fields),
			$callback,
			$queue,
			$updateParams,
			$updateVars,
			$resolvedClearCache
		);
	}

	/**
	 * Queues multiple repository upsert mutations.
	 *
	 *
	 * @param array<int,array<string,mixed>> $rows Write rows before money and schema normalization.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue Repository batch queue name.
	 * @param string|array|null $updateParams UpdateParams.
	 * @param ?array<string,mixed> $updateVars Bound variables for custom upsert update expressions.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueUpsertMany(
		array $rows,
		callable $callback,
		string $queue='end',
		string|array|null $updateParams=null,
		?array $updateVars=null,
		bool|array|null $clearCache=null
	): null|bool {
		$repository=$this->repositoryClass();
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_upsert_many', $resolvedClearCache);
		return $repository::queueUpsertMany(
			$this->resolvedWriteRows($rows),
			$callback,
			$queue,
			$updateParams,
			$updateVars,
			$resolvedClearCache
		);
	}

	/**
	 * Reads at most two repository rows for sole-result validation.
	 *
	 * Sole reads need to distinguish zero, one, and many matches without loading
	 * an unbounded result set. Parent key columns required by eager relations are
	 * included before the repository read, then transforms and eager attachments
	 * are applied to match normal read semantics.
	 *
	 * @param array|string|null $columns Optional selected columns.
	 * @param bool|array|string|null $caching Optional read cache policy.
	 * @return list<array<string,mixed>> Up to two transformed rows with eager data attached.
	 */
	private function singleResultRows(array|string|null $columns=null, bool|array|string|null $caching=null): array {
		$repository=$this->repositoryClass();
		return $this->applyEagerRelations(
			$this->transformRows(
				$repository::all(
					$this->columnsWithEagerParentKeys($columns ?? $this->columns),
					(clone $this)->limit(2),
					$caching ?? $this->caching
				)
			)
		);
	}

	/**
	 * Adds a money-aware comparison predicate to the repository query.
	 *
	 * CurrencyBridge normalizes configured amount/currency storage and converts
	 * the supplied value into a comparable amount. A currency predicate is added
	 * when the mapping includes a currency column, then the numeric comparison is
	 * delegated to inherited predicate helpers.
	 *
	 * @param string $amountColumn Amount column or money mapping source.
	 * @param mixed $value Comparable money value.
	 * @param string $operator Comparison operator.
	 * @param ?string $currencyColumn Optional currency column.
	 * @param ?string $currency Fixed currency when no currency column is used.
	 * @return self Query constrained by the money comparison.
	 */
	private function whereMoneyCompare(
		string $amountColumn,
		mixed $value,
		string $operator,
		?string $currencyColumn,
		?string $currency
	): self {
		$mapping=CurrencyBridge::normalizeMoneyMapping(
			$amountColumn,
			$currencyColumn,
			$currency,
			null,
			$this->repositoryClass
		);
		$comparison=CurrencyBridge::normalizeComparableValue(
			$value,
			$mapping['currency'],
			$this->repositoryClass,
			$mapping['amount_column']
		);
		if($mapping['currency_column']!==null){
			$this->whereEq($mapping['currency_column'], $comparison['currency']);
		}
		return match($operator){
			'='=>$this->whereEq($mapping['amount_column'], $comparison['amount']),
			'>'=>$this->whereGt($mapping['amount_column'], $comparison['amount']),
			'>='=>$this->whereGte($mapping['amount_column'], $comparison['amount']),
			'<' =>$this->whereLt($mapping['amount_column'], $comparison['amount']),
			'<='=>$this->whereLte($mapping['amount_column'], $comparison['amount']),
			default=>throw SqlError::invalidMoneyComparison(
				$this->repositoryClass,
				$mapping['amount_column'],
				"Unsupported money comparison operator '{$operator}'."
			),
		};
	}

	/**
	 * Normalizes eager relation input into relation descriptors.
	 *
	 * Callers may pass a single relation name, a list of names, or an associative
	 * map where each relation can override columns, caching, and constraints.
	 * Relation names are validated as repository property names before storage.
	 *
	 * @param string|array<int|string,mixed> $relations Relation name or descriptor map.
	 * @param array|string $columns Default selected relation columns.
	 * @param bool|array|string|null $caching Default relation cache policy.
	 * @param ?callable $constraint Optional default relation query constraint.
	 * @return list<array{name:string,columns:array|string,caching:bool|array|string|null,constraint:?callable}> Normalized eager relation descriptors.
	 */
	private function normalizeEagerRelationInput(string|array $relations, array|string $columns, bool|array|string|null $caching, ?callable $constraint): array {
		if(is_string($relations)){
			return [[
				'name'=>$this->relationPropertyName($relations),
				'columns'=>$columns,
				'caching'=>$caching,
				'constraint'=>$constraint,
			]];
		}
		$normalized=[];
		foreach($relations as $name=>$options){
			if(is_int($name)){
				$normalized[]=[
					'name'=>$this->relationPropertyName((string)$options),
					'columns'=>$columns,
					'caching'=>$caching,
					'constraint'=>$constraint,
				];
				continue;
			}
			$entryColumns=$columns;
			$entryCaching=$caching;
			$entryConstraint=$constraint;
			if(is_array($options)){
				$entryColumns=$options['columns'] ?? $entryColumns;
				$entryCaching=$options['caching'] ?? $entryCaching;
				$entryConstraint=is_callable($options['constraint'] ?? null) ? $options['constraint'] : $entryConstraint;
			}
			$normalized[]=[
				'name'=>$this->relationPropertyName((string)$name),
				'columns'=>$entryColumns,
				'caching'=>$entryCaching,
				'constraint'=>$entryConstraint,
			];
		}
		return $normalized;
	}

	/**
	 * Adds an existence or non-existence predicate for a relation.
	 *
	 * The relation builds the correlated SQL fragment using the parent
	 * repository table and an optional constrained related query. The resulting
	 * fragment is added as a raw predicate with bound variables preserved.
	 *
	 * @param string|Relation $relation Named relation or explicit relation object.
	 * @param ?callable $constraint Optional related-query constraint.
	 * @param bool $exists Whether matching related rows must exist.
	 * @return self Query constrained by the relation existence predicate.
	 */
	private function whereRelationExists(string|Relation $relation, ?callable $constraint, bool $exists): self {
		$repository=$this->repositoryClass();
		$relation=$this->resolveRelationForQuery($relation);
		[$fragment, $vars]=$relation->existsCondition(
			$repository::tableName(),
			$this->relationConstraintSpec($relation, $constraint),
			$exists
		);
		return $this->whereRaw($fragment, $vars);
	}

	/**
	 * Converts a relation constraint callback into a query specification.
	 *
	 * Constraints receive a fresh query for the related repository. They may
	 * return that query, return another `QuerySpec`, or mutate the provided query
	 * in place and return nothing.
	 *
	 * @param Relation $relation Relation being constrained.
	 * @param ?callable $constraint Optional constraint callback.
	 * @return ?QuerySpec Related-query constraint specification.
	 */
	private function relationConstraintSpec(Relation $relation, ?callable $constraint): ?QuerySpec {
		if($constraint===null){
			return null;
		}
		$repository=$relation->relatedRepository();
		$query=$repository::query();
		$result=$constraint($query);
		if($result instanceof QuerySpec){
			return $result;
		}
		return $query;
	}

	/**
	 * Resolves a named or explicit relation for query-time predicates.
	 *
	 * Explicit relation objects are used directly. String names are normalized
	 * and resolved through the repository class so relation metadata stays
	 * centralized on the repository definition.
	 *
	 * @param string|Relation $relation Named relation or relation object.
	 * @return Relation Resolved relation definition.
	 */
	private function resolveRelationForQuery(string|Relation $relation): Relation {
		if($relation instanceof Relation){
			return $relation;
		}
		$repository=$this->repositoryClass();
		return $repository::relationNamed($this->relationPropertyName($relation));
	}

	/**
	 * Normalizes eager count input into count descriptors.
	 *
	 * Single relations use the caller-provided alias or a generated count alias.
	 * Descriptor maps can override aliases, caching, and constraints per
	 * relation while preserving validated property-style names.
	 *
	 * @param string|array<int|string,mixed> $relations Relation name or descriptor map.
	 * @param ?string $as Alias for single-relation count calls.
	 * @param bool|array|string|null $caching Default count cache policy.
	 * @param ?callable $constraint Optional default relation query constraint.
	 * @return list<array{name:string,alias:string,caching:bool|array|string|null,constraint:?callable}> Normalized eager count descriptors.
	 */
	private function normalizeEagerCountInput(string|array $relations, ?string $as, bool|array|string|null $caching, ?callable $constraint): array {
		if(is_string($relations)){
			$name=$this->relationPropertyName($relations);
			return [[
				'name'=>$name,
				'alias'=>$this->relationPropertyName($as ?? $this->defaultCountAlias($name)),
				'caching'=>$caching,
				'constraint'=>$constraint,
			]];
		}
		$normalized=[];
		foreach($relations as $name=>$options){
			if(is_int($name)){
				$relationName=$this->relationPropertyName((string)$options);
				$normalized[]=[
					'name'=>$relationName,
					'alias'=>$this->defaultCountAlias($relationName),
					'caching'=>$caching,
					'constraint'=>$constraint,
				];
				continue;
			}
			$relationName=$this->relationPropertyName((string)$name);
			$alias=$this->defaultCountAlias($relationName);
			$entryCaching=$caching;
			$entryConstraint=$constraint;
			if(is_string($options) && trim($options)!==''){
				$alias=$options;
			}
			elseif(is_array($options)){
				$alias=$options['as'] ?? $alias;
				$entryCaching=$options['caching'] ?? $entryCaching;
				$entryConstraint=is_callable($options['constraint'] ?? null) ? $options['constraint'] : $entryConstraint;
			}
			$normalized[]=[
				'name'=>$relationName,
				'alias'=>$this->relationPropertyName((string)$alias),
				'caching'=>$entryCaching,
				'constraint'=>$entryConstraint,
			];
		}
		return $normalized;
	}

	/**
	 * Normalizes eager aggregate input into aggregate descriptors.
	 *
	 * Aggregate descriptors capture relation name, function, column, alias,
	 * caching, distinct mode, and constraint callback. Array input may override
	 * those values per relation, which lets one query request several aggregate
	 * shapes without losing fingerprintability.
	 *
	 * @param string|array<int|string,mixed> $relations Relation name or descriptor map.
	 * @param string $function Aggregate function.
	 * @param string $column Aggregate column.
	 * @param ?string $as Alias for single-relation aggregate calls.
	 * @param bool|array|string|null $caching Default aggregate cache policy.
	 * @param bool $distinct Whether to aggregate distinct values.
	 * @param ?callable $constraint Optional default relation query constraint.
	 * @return list<array{name:string,function:string,column:string,alias:string,caching:bool|array|string|null,distinct:bool,constraint:?callable}> Normalized eager aggregate descriptors.
	 */
	private function normalizeEagerAggregateInput(
		string|array $relations,
		string $function,
		string $column,
		?string $as,
		bool|array|string|null $caching,
		bool $distinct,
		?callable $constraint
	): array {
		$function=$this->normalizeAggregateFunction($function);
		if(is_string($relations)){
			$name=$this->relationPropertyName($relations);
			return [[
				'name'=>$name,
				'function'=>$function,
				'column'=>$column,
				'alias'=>$this->relationPropertyName($as ?? $this->defaultAggregateAlias($name, $function, $column)),
				'caching'=>$caching,
				'distinct'=>$distinct,
				'constraint'=>$constraint,
			]];
		}
		$normalized=[];
		foreach($relations as $name=>$options){
			if(is_int($name)){
				$relationName=$this->relationPropertyName((string)$options);
				$normalized[]=[
					'name'=>$relationName,
					'function'=>$function,
					'column'=>$column,
					'alias'=>$this->defaultAggregateAlias($relationName, $function, $column),
					'caching'=>$caching,
					'distinct'=>$distinct,
					'constraint'=>$constraint,
				];
				continue;
			}
			$relationName=$this->relationPropertyName((string)$name);
			$entryFunction=$function;
			$entryColumn=$column;
			$entryCaching=$caching;
			$entryDistinct=$distinct;
			$entryConstraint=$constraint;
			$alias=null;
			if(is_string($options) && trim($options)!==''){
				$alias=$options;
			}
			elseif(is_array($options)){
				$entryFunction=$this->normalizeAggregateFunction((string)($options['function'] ?? $entryFunction));
				$entryColumn=(string)($options['column'] ?? $entryColumn);
				$entryCaching=$options['caching'] ?? $entryCaching;
				$entryDistinct=(bool)($options['distinct'] ?? $entryDistinct);
				$entryConstraint=is_callable($options['constraint'] ?? null) ? $options['constraint'] : $entryConstraint;
				$alias=$options['as'] ?? null;
			}
			$normalized[]=[
				'name'=>$relationName,
				'function'=>$entryFunction,
				'column'=>$entryColumn,
				'alias'=>$this->relationPropertyName((string)($alias ?? $this->defaultAggregateAlias($relationName, $entryFunction, $entryColumn))),
				'caching'=>$entryCaching,
				'distinct'=>$entryDistinct,
				'constraint'=>$entryConstraint,
			];
		}
		return $normalized;
	}

	/**
	 * Registers an eager relation attachment for later parent rows.
	 *
	 * The descriptor stores the resolved relation object plus rendering choices
	 * such as selected columns, record hydration, cache policy, constraint, and
	 * original named relation. Attachments are replayed after parent rows are
	 * read so relation data is materialized consistently across read shapes.
	 *
	 * @param string $name Property name attached to parent rows.
	 * @param Relation $relation Relation definition.
	 * @param array|string $columns Relation columns to select.
	 * @param bool $records Whether related rows should be hydrated as records.
	 * @param mixed $hydrator Optional hydrator for related records.
	 * @param bool|array|string|null $caching Relation read cache policy.
	 * @param ?callable $constraint Optional related-query constraint.
	 * @param ?string $namedRelation Repository relation name used for restoration.
	 * @return void
	 */
	private function addEagerRelation(
		string $name,
		Relation $relation,
		array|string $columns,
		bool $records,
		mixed $hydrator,
		bool|array|string|null $caching,
		?callable $constraint,
		?string $namedRelation
	): void {
		$this->eagerRelations[]=[
			'name'=>$this->relationPropertyName($name),
			'relation'=>$relation,
			'columns'=>self::columns($columns),
			'records'=>$records,
			'hydrator'=>$hydrator,
			'caching'=>$caching,
			'constraint'=>$constraint,
			'named_relation'=>$namedRelation,
		];
	}

	/**
	 * Registers an eager count attachment for later parent rows.
	 *
	 * Count descriptors are stored separately from relation records so they can
	 * be attached after relation rows without changing the main selected columns.
	 *
	 * @param string $name Relation property name.
	 * @param Relation $relation Relation definition.
	 * @param string $alias Parent row alias for the count.
	 * @param bool|array|string|null $caching Count read cache policy.
	 * @param ?callable $constraint Optional related-query constraint.
	 * @param ?string $namedRelation Repository relation name used for restoration.
	 * @return void
	 */
	private function addEagerCount(
		string $name,
		Relation $relation,
		string $alias,
		bool|array|string|null $caching,
		?callable $constraint,
		?string $namedRelation
	): void {
		$this->eagerCounts[]=[
			'name'=>$this->relationPropertyName($name),
			'alias'=>$this->relationPropertyName($alias),
			'relation'=>$relation,
			'caching'=>$caching,
			'constraint'=>$constraint,
			'named_relation'=>$namedRelation,
		];
	}

	/**
	 * Registers an eager aggregate attachment for later parent rows.
	 *
	 * Aggregate descriptors retain the normalized SQL function, target column,
	 * alias, cache policy, distinct flag, and constraint callback needed for
	 * relation-level aggregate attachment.
	 *
	 * @param string $name Relation property name.
	 * @param Relation $relation Relation definition.
	 * @param string $function Aggregate function.
	 * @param string $column Aggregate column.
	 * @param string $alias Parent row alias for the aggregate value.
	 * @param bool|array|string|null $caching Aggregate read cache policy.
	 * @param bool $distinct Whether to aggregate distinct values.
	 * @param ?callable $constraint Optional related-query constraint.
	 * @param ?string $namedRelation Repository relation name used for restoration.
	 * @return void
	 */
	private function addEagerAggregate(
		string $name,
		Relation $relation,
		string $function,
		string $column,
		string $alias,
		bool|array|string|null $caching,
		bool $distinct,
		?callable $constraint,
		?string $namedRelation
	): void {
		$this->eagerAggregates[]=[
			'name'=>$this->relationPropertyName($name),
			'alias'=>$this->relationPropertyName($alias),
			'relation'=>$relation,
			'function'=>$this->normalizeAggregateFunction($function),
			'column'=>$column,
			'caching'=>$caching,
			'distinct'=>$distinct,
			'constraint'=>$constraint,
			'named_relation'=>$namedRelation,
		];
	}

	/**
	 * Attaches configured eager relation data to parent rows or records.
	 *
	 * Relation rows, relation records, counts, and aggregates are applied in a
	 * deterministic order after parent retrieval. Invalid restored descriptors
	 * are ignored, keeping queued or serialized query state tolerant of older
	 * descriptor data.
	 *
	 * @param array<int,array<string,mixed>|object|mixed> $parents Parent rows or hydrated records.
	 * @return array<int,array<string,mixed>|object|mixed> Parents with eager data attached.
	 */
	private function applyEagerRelations(array $parents): array {
		if($parents===[]){
			return $parents;
		}
		foreach($this->eagerRelations as $entry){
			$relation=$entry['relation'];
			if(!$relation instanceof Relation){
				continue;
			}
			$parents=$entry['records']
				? $relation->attachRecords($parents, $entry['name'], $entry['columns'], $entry['hydrator'], $entry['caching'], $entry['constraint'])
				: $relation->attach($parents, $entry['name'], $entry['columns'], $entry['caching'], $entry['constraint']);
		}
		foreach($this->eagerCounts as $entry){
			$relation=$entry['relation'];
			if(!$relation instanceof Relation){
				continue;
			}
			$parents=$relation->attachCount($parents, $entry['alias'], $entry['caching'], $entry['constraint']);
		}
		foreach($this->eagerAggregates as $entry){
			$relation=$entry['relation'];
			if(!$relation instanceof Relation){
				continue;
			}
			$parents=$relation->attachAggregate(
				$parents,
				$entry['alias'],
				$entry['function'],
				$entry['column'],
				$entry['caching'],
				$entry['distinct'],
				$entry['constraint']
			);
		}
		return $parents;
	}

	/**
	 * Hydrates repository rows through the repository class.
	 *
	 * The repository owns row-to-record conversion, so query methods delegate
	 * hydration there rather than instantiating record objects directly.
	 *
	 * @param list<array<string,mixed>> $rows Repository rows.
	 * @param mixed $hydrator Optional hydrator override.
	 * @return list<mixed> Hydrated repository records.
	 */
	private function hydrateRepositoryRows(array $rows, mixed $hydrator=null): array {
		$repository=$this->repositoryClass();
		return $repository::hydrateRows($rows, $hydrator);
	}

	/**
	 * Hydrates one repository row through the repository class.
	 *
	 * The optional hydrator override follows the same contract as bulk
	 * repository hydration, allowing callers to swap record classes or callbacks
	 * for a single result.
	 *
	 * @param array<string,mixed> $row Repository row.
	 * @param mixed $hydrator Optional hydrator override.
	 * @return mixed repository-specific record, DTO, array, or callback result produced by the configured hydrator.
	 */
	private function hydrateRepositoryRow(array $row, mixed $hydrator=null): mixed {
		$repository=$this->repositoryClass();
		return $repository::hydrateRow($row, $hydrator);
	}

	/**
	 * Normalizes a queued repository result and applies eager attachments.
	 *
	 * The underlying queued result is first passed through the shared transform
	 * pipeline. List results receive eager data as a batch, single-row results
	 * are wrapped briefly so relation attachment can reuse the same code path,
	 * and non-array values are preserved as queue failure markers.
	 *
	 * @param mixed $result Raw queued repository callback result.
	 * @return mixed Eager-loaded rows, one row with relations, empty list, or failure marker.
	 */
	private function transformQueuedRepositoryResult(mixed $result): mixed {
		$result=$this->transformQueuedResult($result);
		if(!is_array($result)){
			return $result;
		}
		if($result===[]){
			return [];
		}
		if(array_is_list($result)){
			return $this->applyEagerRelations($result);
		}
		$rows=$this->applyEagerRelations([$result]);
		return $rows[0] ?? $result;
	}

	/**
	 * Hydrates all row-shaped records from a queued repository result.
	 *
	 * Queued rows are transformed, filtered to row arrays, hydrated through the
	 * repository, and then passed through eager relation attachment.
	 *
	 * @param mixed $result Raw queued repository callback result.
	 * @param mixed $hydrator Optional hydrator override.
	 * @return list<mixed> Hydrated records with eager data attached.
	 */
	private function hydrateQueuedRepositoryRows(mixed $result, mixed $hydrator=null): array {
		$rows=$this->queuedRowsFromResult($this->transformQueuedResult($result));
		if($rows===[]){
			return [];
		}
		return $this->applyEagerRelations($this->hydrateRepositoryRows($rows, $hydrator));
	}

	/**
	 * Hydrates the first row-shaped record from a queued repository result.
	 *
	 * Null is returned when no row is present. Otherwise the single record is
	 * passed through eager attachment using the same relation path as list reads.
	 *
	 * @param mixed $result Raw queued repository callback result.
	 * @param mixed $hydrator Optional hydrator override.
	 * @return mixed Hydrated record with eager data, or null.
	 */
	private function hydrateQueuedRepositoryRow(mixed $result, mixed $hydrator=null): mixed {
		$row=$this->queuedRowFromResult($this->transformQueuedResult($result));
		if($row===null){
			return null;
		}
		$records=$this->applyEagerRelations([$this->hydrateRepositoryRow($row, $hydrator)]);
		return $records[0] ?? null;
	}

	/**
	 * Extracts row arrays from a queued repository result.
	 *
	 * Non-array and empty results become an empty list. List results are
	 * filtered to array rows, while associative results are treated as a single
	 * row so queued callbacks can handle both SQL helper shapes.
	 *
	 * @param mixed $result Raw queued repository callback result.
	 * @return list<array<string,mixed>> Row-shaped arrays.
	 */
	private function queuedRowsFromResult(mixed $result): array {
		if(!is_array($result) || $result===[]){
			return [];
		}
		if(array_is_list($result)){
			return array_values(array_filter($result, 'is_array'));
		}
		return [$result];
	}

	/**
	 * Extracts the first row array from a queued repository result.
	 *
	 * List results return their first row-shaped entry; associative results are
	 * returned directly; empty or non-row results return null.
	 *
	 * @param mixed $result Raw queued repository callback result.
	 * @return ?array<string,mixed> First row array or null.
	 */
	private function queuedRowFromResult(mixed $result): ?array {
		if(!is_array($result) || $result===[]){
			return null;
		}
		if(array_is_list($result)){
			return isset($result[0]) && is_array($result[0]) ? $result[0] : null;
		}
		return $result;
	}

	/**
	 * Ensures selected parent columns include keys required by eager relations.
	 *
	 * Eager relation attachment needs each parent row's local key. Star
	 * selections already include those keys, but explicit column lists are
	 * augmented with every configured relation parent-key column.
	 *
	 * @param array|string $columns Requested parent columns.
	 * @return array|string Column selector including eager parent keys when needed.
	 */
	private function columnsWithEagerParentKeys(array|string $columns): array|string {
		if($columns==='*' || ($this->eagerRelations===[] && $this->eagerCounts===[] && $this->eagerAggregates===[])){
			return $columns;
		}
		if(is_string($columns)){
			$columns=[$columns];
		}
		foreach($this->eagerRelations as $entry){
			if(($entry['relation'] ?? null) instanceof Relation){
				$columns[]=$entry['relation']->parentKeyColumn();
			}
		}
		foreach($this->eagerCounts as $entry){
			if(($entry['relation'] ?? null) instanceof Relation){
				$columns[]=$entry['relation']->parentKeyColumn();
			}
		}
		foreach($this->eagerAggregates as $entry){
			if(($entry['relation'] ?? null) instanceof Relation){
				$columns[]=$entry['relation']->parentKeyColumn();
			}
		}
		return array_values(array_unique(array_filter(array_map(
			static fn(mixed $column): string => trim((string)$column),
			$columns
		), static fn(string $column): bool => $column!=='')));
	}

	/**
	 * Serializes eager relation state into fingerprint and execution-state data.
	 *
	 * Relation objects are reduced to stable metadata and callbacks/hydrators are
	 * converted to descriptors so query identity can be compared without storing
	 * closures or live objects.
	 *
	 * @return list<array<string,mixed>> Serializable eager relation descriptors.
	 */
	private function eagerRelationDescriptors(): array {
		$descriptors=[];
		foreach($this->eagerRelations as $entry){
			$relation=$entry['relation'];
			if(!$relation instanceof Relation){
				continue;
			}
			$descriptors[]=[
				'name'=>$entry['name'],
				'named_relation'=>$entry['named_relation'],
				'records'=>$entry['records'],
				'columns'=>$entry['columns'],
				'caching'=>$entry['caching'],
				'hydrator'=>$this->hydratorDescriptor($entry['hydrator']),
				'constraint'=>$this->callableDescriptor($entry['constraint']),
				'relation'=>[
					'type'=>$relation->type(),
					'related_repository'=>$relation->relatedRepository(),
					'foreign_key'=>$relation->foreignKey(),
					'local_key'=>$relation->localKey(),
				],
			];
		}
		return $descriptors;
	}

	/**
	 * Serializes eager count state into fingerprint and execution-state data.
	 *
	 * Count descriptors preserve aliases, named relation identity, cache policy,
	 * constraint identity, and relation key metadata needed for diagnostics and
	 * queued query restoration.
	 *
	 * @return list<array<string,mixed>> Serializable eager count descriptors.
	 */
	private function eagerCountDescriptors(): array {
		$descriptors=[];
		foreach($this->eagerCounts as $entry){
			$relation=$entry['relation'];
			if(!$relation instanceof Relation){
				continue;
			}
			$descriptors[]=[
				'name'=>$entry['name'],
				'alias'=>$entry['alias'],
				'named_relation'=>$entry['named_relation'],
				'caching'=>$entry['caching'],
				'constraint'=>$this->callableDescriptor($entry['constraint']),
				'relation'=>[
					'type'=>$relation->type(),
					'related_repository'=>$relation->relatedRepository(),
					'foreign_key'=>$relation->foreignKey(),
					'local_key'=>$relation->localKey(),
				],
			];
		}
		return $descriptors;
	}

	/**
	 * Serializes eager aggregate state into fingerprint and execution-state data.
	 *
	 * Aggregate descriptors include function, column, alias, distinct state, and
	 * relation metadata so aggregate attachments remain visible in query
	 * fingerprinting and serialized execution state.
	 *
	 * @return list<array<string,mixed>> Serializable eager aggregate descriptors.
	 */
	private function eagerAggregateDescriptors(): array {
		$descriptors=[];
		foreach($this->eagerAggregates as $entry){
			$relation=$entry['relation'];
			if(!$relation instanceof Relation){
				continue;
			}
			$descriptors[]=[
				'name'=>$entry['name'],
				'alias'=>$entry['alias'],
				'named_relation'=>$entry['named_relation'],
				'function'=>$entry['function'],
				'column'=>$entry['column'],
				'caching'=>$entry['caching'],
				'distinct'=>$entry['distinct'],
				'constraint'=>$this->callableDescriptor($entry['constraint']),
				'relation'=>[
					'type'=>$relation->type(),
					'related_repository'=>$relation->relatedRepository(),
					'foreign_key'=>$relation->foreignKey(),
					'local_key'=>$relation->localKey(),
				],
			];
		}
		return $descriptors;
	}

	/**
	 * Restores eager relation descriptors from serialized execution state.
	 *
	 * Only named relations can be restored because live relation objects and
	 * callbacks are intentionally not serialized. Invalid or older descriptors
	 * are ignored to keep queued state backward tolerant.
	 *
	 * @param array<int,array<string,mixed>|mixed> $relations Serialized eager relation descriptors.
	 * @return void
	 */
	private function restoreEagerRelations(array $relations): void {
		foreach($relations as $entry){
			if(!is_array($entry) || !is_string($entry['named_relation'] ?? null)){
				continue;
			}
			if(($entry['records'] ?? false)===true){
				$this->withRecords(
					$entry['named_relation'],
					$entry['columns'] ?? '*',
					null,
					$entry['caching'] ?? null
				);
				continue;
			}
			$this->with(
				$entry['named_relation'],
				$entry['columns'] ?? '*',
				$entry['caching'] ?? null
			);
		}
	}

	/**
	 * Restores eager aggregate descriptors from serialized execution state.
	 *
	 * Each valid named descriptor is replayed through `withAggregate()` so
	 * normalization and validation stay identical to fresh query construction.
	 *
	 * @param array<int,array<string,mixed>|mixed> $relations Serialized eager aggregate descriptors.
	 * @return void
	 */
	private function restoreEagerAggregates(array $relations): void {
		foreach($relations as $entry){
			if(!is_array($entry) || !is_string($entry['named_relation'] ?? null)){
				continue;
			}
			$this->withAggregate(
				$entry['named_relation'],
				(string)($entry['function'] ?? 'SUM'),
				(string)($entry['column'] ?? '*'),
				is_string($entry['alias'] ?? null) ? $entry['alias'] : null,
				$entry['caching'] ?? null,
				(bool)($entry['distinct'] ?? false)
			);
		}
	}

	/**
	 * Restores eager count descriptors from serialized execution state.
	 *
	 * Count restoration delegates to `withCount()` to rebuild aliases, relation
	 * lookup, and caching state through the public eager-count path.
	 *
	 * @param array<int,array<string,mixed>|mixed> $relations Serialized eager count descriptors.
	 * @return void
	 */
	private function restoreEagerCounts(array $relations): void {
		foreach($relations as $entry){
			if(!is_array($entry) || !is_string($entry['named_relation'] ?? null)){
				continue;
			}
			$this->withCount(
				$entry['named_relation'],
				is_string($entry['alias'] ?? null) ? $entry['alias'] : null,
				$entry['caching'] ?? null
			);
		}
	}

	/**
	 * Validates a relation property or eager alias name.
	 *
	 * Repository relation data is attached as array keys or object-like
	 * properties, so names are restricted to simple PHP identifier style tokens.
	 *
	 * @param string $name Candidate relation property or alias.
	 * @return string Trimmed validated property name.
	 */
	private function relationPropertyName(string $name): string {
		$name=trim($name);
		if($name==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)!==1){
			throw SqlError::invalidIdentifier('repository relation', $name, $this->repositoryClass);
		}
		return $name;
	}

	/**
	 * Builds the default alias for an eager relation count.
	 *
	 * Count aliases follow the relation property plus `_count` convention used
	 * by repository result rows.
	 *
	 * @param string $name Relation property name.
	 * @return string Default count alias.
	 */
	private function defaultCountAlias(string $name): string {
		return $this->relationPropertyName($name).'_count';
	}

	/**
	 * Builds the default alias for an eager relation aggregate.
	 *
	 * The alias combines relation name, normalized aggregate function, and a
	 * sanitized column token. Star aggregates use `all`, and empty sanitized
	 * columns fall back to `value`.
	 *
	 * @param string $name Relation property name.
	 * @param string $function Aggregate function.
	 * @param string $column Aggregate column.
	 * @return string Default aggregate alias.
	 */
	private function defaultAggregateAlias(string $name, string $function, string $column): string {
		$column=trim($column)==='*' ? 'all' : trim($column);
		$column=(string)preg_replace('/[^A-Za-z0-9_]+/', '_', $column);
		$column=trim($column, '_');
		if($column===''){
			$column='value';
		}
		return $this->relationPropertyName($name).'_'.strtolower($this->normalizeAggregateFunction($function)).'_'.$column;
	}

	/**
	 * Validates and normalizes a supported aggregate function.
	 *
	 * Repository aggregate helpers intentionally share the same compact function
	 * vocabulary as table queries so eager and direct aggregate results remain
	 * predictable.
	 *
	 * @param string $function Requested aggregate function.
	 * @return string Uppercase aggregate function.
	 */
	private function normalizeAggregateFunction(string $function): string {
		$function=strtoupper(trim($function));
		$allowed=['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
		if(!in_array($function, $allowed, true)){
			throw SqlError::invalidAggregateFunction($this->repositoryClass, $function, $allowed);
		}
		return $function;
	}

	/**
	 * Builds the column list needed for repository pluck reads.
	 *
	 * The value column is always selected, and an optional key column is added
	 * once. Empty fragments are removed before the repository read executes.
	 *
	 * @param string $column Value column.
	 * @param ?string $keyColumn Optional key column.
	 * @return list<string> Unique non-empty columns.
	 */
	private function pluckColumns(string $column, ?string $keyColumn=null): array {
		$columns=[trim($column)];
		if($keyColumn!==null && trim($keyColumn)!==''){
			$columns[] = trim($keyColumn);
		}
		return array_values(array_unique(array_filter($columns, static fn(string $value): bool => $value!=='')));
	}

	/**
	 * Converts hydrator state into a fingerprint-safe descriptor.
	 *
	 * Objects become class names, scalar and array values are preserved, and
	 * unusual values fall back to debug type so fingerprints can include
	 * hydration intent without serializing live objects.
	 *
	 * @param mixed $hydrator Hydrator candidate.
	 * @return mixed class name, scalar, array, null, or debug type safe for query fingerprints.
	 */
	private function hydratorDescriptor(mixed $hydrator): mixed {
		if(is_object($hydrator)){
			return $hydrator::class;
		}
		if(is_string($hydrator) || is_array($hydrator) || $hydrator===null || is_bool($hydrator) || is_int($hydrator) || is_float($hydrator)){
			return $hydrator;
		}
		return get_debug_type($hydrator);
	}

	/**
	 * Converts a callable constraint into a fingerprint-safe descriptor.
	 *
	 * Array callables become `Class::method`, string callables are preserved,
	 * closures collapse to `Closure`, and invokable objects become their class
	 * name. Null constraints remain null.
	 *
	 * @param ?callable $callback Constraint callback.
	 * @return ?string Stable callable descriptor where possible.
	 */
	private function callableDescriptor(?callable $callback): ?string {
		if($callback===null){
			return null;
		}
		if(is_array($callback)){
			$class=is_object($callback[0] ?? null) ? $callback[0]::class : (string)($callback[0] ?? '');
			$method=(string)($callback[1] ?? '');
			return trim($class.'::'.$method, ':');
		}
		if(is_string($callback)){
			return $callback;
		}
		if($callback instanceof \Closure){
			return 'Closure';
		}
		if(is_object($callback)){
			return $callback::class;
		}
		return get_debug_type($callback);
	}

	/**
	 * Hashes a repository query fingerprint data deterministically.
	 *
	 * JSON is preferred for stable scalar representation; serialization is used
	 * only if JSON encoding fails. The hash becomes the compact identity for
	 * cache, queue, and diagnostic state.
	 *
	 * @param array<string,mixed> $payload Fingerprint data.
	 * @return string SHA-1 fingerprint hash.
	 */
	private function fingerprintHash(array $payload): string {
		$encoded=json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
		);
		return sha1($encoded!==false ? $encoded : serialize($payload));
	}

	/**
	 * Ensures key-by reads include the key column.
	 *
	 * Star selections are preserved because the key will already be present.
	 * Explicit column lists are normalized and augmented with the requested key.
	 *
	 * @param string $keyColumn Key column.
	 * @param array|string|null $columns Requested columns.
	 * @return array|string Column selector including the key column when needed.
	 */
	private function keyColumns(string $keyColumn, array|string|null $columns=null): array|string {
		$keyColumn=trim($keyColumn);
		if($columns===null || $columns==='*'){
			return '*';
		}
		if(is_string($columns)){
			$columns=[$columns];
		}
		$columns[]=$keyColumn;
		return array_values(array_unique(array_filter(array_map(
			static fn(mixed $value): string => trim((string)$value),
			$columns
		), static fn(string $value): bool => $value!=='')));
	}

	/**
	 * Extracts values from repository rows for pluck-style result shapes.
	 *
	 * Without a key column, values are appended in row order. With a key column,
	 * rows without a non-null key are skipped and values are keyed by the string
	 * form of that column.
	 *
	 * @param array<int,array<string,mixed>|mixed> $rows Source rows.
	 * @param string $column Value column.
	 * @param ?string $keyColumn Optional key column.
	 * @return array<int|string,mixed> Plucked values.
	 */
	private function pluckRows(array $rows, string $column, ?string $keyColumn=null): array {
		$plucked=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$value=$row[$column] ?? null;
			if($keyColumn===null){
				$plucked[]=$value;
				continue;
			}
			if(!array_key_exists($keyColumn, $row) || $row[$keyColumn]===null){
				continue;
			}
			$plucked[(string)$row[$keyColumn]]=$value;
		}
		return $plucked;
	}

	/**
	 * Re-keys repository rows by a selected column.
	 *
	 * Non-row values and rows without a non-null key are skipped. Later rows
	 * with the same string key overwrite earlier rows, matching map semantics.
	 *
	 * @param array<int,array<string,mixed>|mixed> $rows Source rows.
	 * @param string $keyColumn Key column.
	 * @return array<string,array<string,mixed>> Rows keyed by column value.
	 */
	private function keyRowsBy(array $rows, string $keyColumn): array {
		$keyed=[];
		foreach($rows as $row){
			if(!is_array($row) || !array_key_exists($keyColumn, $row) || $row[$keyColumn]===null){
				continue;
			}
			$keyed[(string)$row[$keyColumn]]=$row;
		}
		return $keyed;
	}

	/**
	 * Builds structured context for not-found and multiple-result exceptions.
	 *
	 * The context includes selected columns, inherited query debug state, and
	 * caller-supplied details. Empty values are filtered to keep exception
	 * data compact.
	 *
	 * @param array|string|null $columns Column selector active for the failing read.
	 * @param array<string,mixed> $extra Additional context values.
	 * @return array<string,mixed> Filtered exception context.
	 */
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

	/**
	 * Restores row transforms captured in serialized repository execution state.
	 *
	 * Queued and replayed repository queries persist money mapping descriptors
	 * rather than closures. This method rebuilds equivalent row transformers and
	 * restores mapping arrays used later for write-field expansion.
	 *
	 * @param array<int,array<string,mixed>|mixed> $moneyMappings Money transform descriptors.
	 * @param array<int,array<string,mixed>|mixed> $storedMoneyMappings Stored-money transform descriptors.
	 * @return void
	 */
	private function restoreCompiledTransforms(array $moneyMappings, array $storedMoneyMappings): void {
		foreach($moneyMappings as $mapping){
			if(!is_array($mapping)){
				continue;
			}
			$this->addRowTransformer(
				fn(array $row): array => CurrencyBridge::applyMoneyMapping($row, $mapping, $this->repositoryClass)
			);
			$this->writeMoneyMappings[]=$mapping;
		}
		foreach($storedMoneyMappings as $mapping){
			if(!is_array($mapping)){
				continue;
			}
			$this->addRowTransformer(
				fn(array $row): array => CurrencyBridge::applyStoredMoneyMapping($row, $mapping, $this->repositoryClass)
			);
			$this->writeStoredMoneyMappings[]=$mapping;
		}
	}

	/**
	 * Expands repository write fields through configured money mappings.
	 *
	 * Money and stored-money abstractions are converted into concrete database
	 * columns before the repository create, update, or upsert call receives the
	 * fields. Repository classes remain responsible for their own field-level
	 * validation.
	 *
	 * @param array<string|int,mixed> $fields Write fields before money expansion.
	 * @return array<string|int,mixed> Write fields after money expansion.
	 */
	private function resolvedWriteFields(array $fields): array {
		return CurrencyBridge::expandWriteFields(
			$fields,
			$this->writeMoneyMappings,
			$this->writeStoredMoneyMappings,
			$this->repositoryClass,
			false
		);
	}

	/**
	 * Expands money mappings across a batch of repository write rows.
	 *
	 * Array rows are normalized through `resolvedWriteFields()`, while non-array
	 * entries are preserved so the downstream repository batch method can apply
	 * its own validation or failure handling.
	 *
	 * @param array<int|string,mixed> $rows Candidate write rows.
	 * @return array<int|string,mixed> Rows with array fields money-expanded.
	 */
	private function resolvedWriteRows(array $rows): array {
		$resolved=[];
		foreach($rows as $key=>$row){
			$resolved[$key]=is_array($row) ? $this->resolvedWriteFields($row) : $row;
		}
		return $resolved;
	}

	/**
	 * Reports a guardrail warning when named read caches lack write invalidation.
	 *
	 * The warning is emitted only when this query uses named read cache scopes
	 * and the write path neither clears all caches nor supplies named
	 * invalidation scopes. The mutation still proceeds, but stale-cache risk is
	 * visible through Dataphyre diagnostics.
	 *
	 * @param string $operation Mutation operation name.
	 * @param bool|array|null $clearCache Resolved write invalidation policy.
	 * @return void
	 */
	private function warnIfWriteInvalidationMissing(string $operation, bool|array|null $clearCache): void {
		$cacheNames=$this->namedReadCacheNames();
		if($cacheNames===[] || $clearCache===true || $this->invalidationNamesFromValue($clearCache)!==[]){
			return;
		}
		DB::reportGuardrailWarning(
			'Named read caches are attached to this repository query, but the write path has no invalidation policy.',
			[
				'operation'=>$operation,
				'repository'=>$this->repositoryClass,
				'cache_names'=>$cacheNames,
				'invalidation_names'=>$this->invalidationNamesFromValue($clearCache),
				'columns'=>$this->columns,
				'query'=>$this->debugContext(),
			]
		);
	}

	/**
	 * Extracts named read-cache scopes from the current cache policy.
	 *
	 * Lazy cache markers are ignored because they describe timing rather than a
	 * named invalidation boundary.
	 *
	 * @return list<string> Named read cache scopes.
	 */
	private function namedReadCacheNames(): array {
		return $this->normalizeTraceNames($this->caching, true);
	}

	/**
	 * Extracts named invalidation scopes from a write cache policy.
	 *
	 * Boolean and null policies do not carry scope names. Arrays are normalized
	 * through the same trace-name helper used for read cache names.
	 *
	 * @param bool|array|null $clearCache Write invalidation policy.
	 * @return list<string> Named invalidation scopes.
	 */
	private function invalidationNamesFromValue(bool|array|null $clearCache): array {
		return $this->normalizeTraceNames($clearCache, false);
	}

	/**
	 * Normalizes cache trace names from scalar or array policy values.
	 *
	 * Non-string entries, empty names, and optionally the lazy marker are
	 * removed. The result is de-duplicated while preserving first-seen order.
	 *
	 * @param mixed $value Cache or invalidation policy value.
	 * @param bool $allowLazy Whether the `lazy` marker is valid and should be skipped.
	 * @return list<string> Unique normalized names.
	 */
	private function normalizeTraceNames(mixed $value, bool $allowLazy): array {
		if(is_array($value)===false){
			$value=$value===null ? [] : [$value];
		}
		$normalized=[];
		foreach($value as $name){
			if(is_string($name)===false){
				continue;
			}
			$name=trim($name);
			if($name==='' || ($allowLazy && $name==='lazy')){
				continue;
			}
			$normalized[]=$name;
		}
		return array_values(array_unique($normalized));
	}
}
