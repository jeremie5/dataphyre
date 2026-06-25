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

/**
 * Fluent SQL query builder for a concrete table or table schema.
 *
 * Table queries preserve selected columns, predicates, cache policy, schema
 * hydration retry, row hydration, and write invalidation state until a read or
 * mutation executes.
 */
final class TableQuery extends QuerySpec {

	use TransformsRows;

	private array|string $columns='*';
	private bool|array|string|null $caching=[true];
	private mixed $hydrator=null;
	private string $table;
	private ?TableSchema $schema;
	private ?string $primaryKey;
	private bool|array|null $clearCacheOnWrite=false;
	/** @var array<int,array<string,mixed>> */
	private array $writeMoneyMappings=[];
	/** @var array<int,array<string,mixed>> */
	private array $writeStoredMoneyMappings=[];

	/**
	 * Creates a fluent SQL table query object.
	 *
	 * Initial state captures table identity, optional schema metadata, primary key
	 * handling, read-cache defaults, and write invalidation policy. Table names
	 * and primary-key overrides are normalized before SQL can use them.
	 *
	 * @param string|TableSchema $table Raw table name or schema object that supplies table and primary-key metadata.
	 * @param ?string $primaryKey Optional primary key override for key-based reads and mutations.
	 */
	public function __construct(string|TableSchema $table, ?string $primaryKey=null){
		if($table instanceof TableSchema){
			$this->schema=$table;
			$this->table=$table->table();
			$this->primaryKey=$primaryKey!==null ? $this->normalizeIdentifier($primaryKey) : $table->primaryKey();
			return;
		}
		$this->schema=null;
		$this->table=$this->normalizeIdentifier($table);
		$this->primaryKey=$primaryKey!==null ? $this->normalizeIdentifier($primaryKey) : null;
	}

	/**
	 * Returns the normalized SQL table name targeted by this query.
	 *
	 * @return string SQL table name.
	 */
	public function table(): string {
		return $this->table;
	}

	/**
	 * Returns the schema metadata attached to this query.
	 *
	 * Queries built from a raw table name can still execute reads and writes, but
	 * projection lookup and schema-backed hydration require a schema to be
	 * attached first.
	 *
	 * @return ?TableSchema Table schema metadata, or null when this query was built from a table name.
	 */
	public function schema(): ?TableSchema {
		return $this->schema;
	}

	/**
	 * Returns the primary key column available for key-based operations.
	 *
	 * A null value means whereKey(), find(), versioned updates, and key-based
	 * queued operations fail before dispatching SQL.
	 *
	 * @return ?string Primary key column, or null when unavailable.
	 */
	public function primaryKey(): ?string {
		return $this->primaryKey;
	}

	/**
	 * Attaches schema metadata and retargets the query to that schema's table.
	 *
	 * Existing primary-key overrides are preserved; otherwise the schema primary
	 * key becomes the key used by later key-based reads and mutations.
	 *
	 * @param TableSchema $schema Schema that supplies table, projection, and primary-key metadata.
	 * @return self Current table query instance.
	 */
	public function usingSchema(TableSchema $schema): self {
		$this->schema=$schema;
		$this->table=$schema->table();
		$this->primaryKey ??= $schema->primaryKey();
		return $this;
	}

	/**
	 * Executes an SQL operation with one schema-hydration retry.
	 *
	 * When a read or mutation fails, the SQL module is asked to hydrate missing
	 * table structure from the runtime definition for this table. If hydration
	 * succeeds, the table cache and last query error are cleared before the
	 * original operation is retried once.
	 *
	 * @param callable $operation SQL operation returning a raw helper result.
	 * @return mixed First success, unrepaired failure, or the single retry result.
	 */
	private function withSchemaHydration(callable $operation): mixed {
		\dataphyre\sql::clear_last_query_error();
		$result=$operation();
		if($result!==false){
			return $result;
		}
		if(\dataphyre\sql::hydrate_missing_structure_from_definition($this->table)===false){
			return $result;
		}
		\dataphyre\sql::invalidate_cache($this->table);
		\dataphyre\sql::clear_last_query_error();
		return $operation();
	}

	/**
	 * Replaces the primary key column used by key-based operations.
	 *
	 * When schema metadata is attached, the field is also registered on the schema so later schema-aware writes can see the key column.
	 *
	 * @param string $primaryKey Raw primary key column name normalized as a SQL identifier.
	 * @return self Current table query instance.
	 */
	public function usingPrimaryKey(string $primaryKey): self {
		$primaryKey=$this->normalizeIdentifier($primaryKey);
		if($this->schema!==null){
			$this->schema->fields([$primaryKey=>null]);
		}
		$this->primaryKey=$primaryKey;
		return $this;
	}

	/**
	 * Adds a primary-key equality predicate to this query.
	 *
	 * The predicate is rejected before SQL dispatch when the query has no known primary key, keeping raw table-name queries from silently applying an unsafe key filter.
	 *
	 * @param mixed $id Primary key value compared against the configured key column.
	 * @return self Current table query instance.
	 */
	public function whereKey(mixed $id): self {
		if($this->primaryKey===null){
			throw SqlError::missingPrimaryKeyForTable($this->table, 'perform key-based queries');
		}
		return $this->whereEq($this->primaryKey, $id);
	}

	/**
	 * Selects the columns or projection used by later read operations.
	 *
	 * The selector is stored until get(), pagination, hydration, or queue dispatch
	 * builds the final SQL shape. Write operations keep their own field maps.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @return self Current table query instance.
	 */
	public function select(array|string $columns='*'): self {
		$this->columns=$columns;
		return $this;
	}

	/**
	 * Selects a named projection from the attached table schema.
	 *
	 * Projection lookup requires schema metadata and fails before SQL dispatch when the query was built from only a table name.
	 *
	 * @param string $name Schema projection name.
	 * @return self Current table query instance.
	 */
	public function projection(string $name): self {
		if($this->schema===null){
			throw SqlError::missingSchemaForProjection("table {$this->table}", $name, $this->table);
		}
		$this->columns=$this->schema->projection($name);
		return $this;
	}

	/**
	 * Replaces the read-cache policy used by this query.
	 *
	 * Cache markers are preserved until read and queue operations delegate to the SQL kernel. Passing false disables read caching, null leaves kernel defaults available, and arrays or strings carry Dataphyre cache scopes.
	 *
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
	 */
	public function cacheNames(string ...$names): self {
		$this->caching=DB::mergeCacheNames($this->caching, ...$names);
		return $this;
	}

	/**
	 * Disables read caching for later query execution.
	 *
	 * The query keeps all predicates, projections, hydrator choices, and write policy; only the read-cache policy is replaced with false.
	 * @return self Current table query instance.
	 */
	public function withoutCaching(): self {
		$this->caching=false;
		return $this;
	}

	/**
	 * Replaces the cache invalidation policy used by write operations.
	 *
	 * The policy is retained until create, update, delete, upsert, increment, decrement, and queued write helpers dispatch to the SQL kernel.
	 *
	 * @param bool|array $clearCache Dataphyre write invalidation policy.
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
	 */
	public function invalidateCacheNames(string ...$names): self {
		$this->clearCacheOnWrite=DB::mergeInvalidationNames($this->clearCacheOnWrite, ...$names);
		return $this;
	}

	/**
	 * Disables automatic cache invalidation for later write operations.
	 *
	 * This changes only write invalidation policy; read caching and query predicates are left unchanged.
	 * @return self Current table query instance.
	 */
	public function withoutInvalidation(): self {
		$this->clearCacheOnWrite=false;
		return $this;
	}

	/**
	 * Toggles the safety guard that requires predicates before write operations.
	 *
	 * When enabled, update and delete helpers fail before SQL dispatch unless the inherited QuerySpec carries a where clause. This protects table-wide mutations by default.
	 *
	 * @param bool $required Whether write operations must include a predicate.
	 * @return self Current table query instance.
	 */
	public function requireWhereForWrite(bool $required=true): self {
		$this->requireWhereForWrite($required);
		return $this;
	}

	/**
	 * Allows write operations without a where clause.
	 *
	 * This is an explicit table-wide mutation escape hatch. Callers should only
	 * use it when the write fields and target table are trusted.
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
	 */
	public function lockRaw(string|array $fragment): self {
		$this->lockRaw($fragment);
		return $this;
	}

	/**
	 * Clears any row-locking mode from this query.
	 *
	 * Predicates, projections, cache policy, hydrator state, and write policy are preserved.
	 * @return self Current table query instance.
	 */
	public function withoutLocking(): self {
		$this->clearLocking();
		return $this;
	}

	/**
	 * Replaces the hydrator used for row-to-result conversion.
	 *
	 * Hydrators are consumed by hydrated read helpers and queued hydrated operations. Raw get()/first() calls keep returning array-shaped rows unless they explicitly route through hydration.
	 *
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @return self Current table query instance.
	 */
	public function usingHydrator(mixed $hydrator): self {
		$this->hydrator=$hydrator;
		return $this;
	}

	/**
	 * Hydrates rows as framework Record objects without repository write-back context.
	 *
	 * The hydrator receives the table primary key when one is known, allowing Record identifiers while keeping raw table queries detached from TableRepository helpers.
	 * @return self Current table query instance.
	 */
	public function asRecords(): self {
		$this->hydrator=new RecordObjectHydrator(null, $this->primaryKey);
		return $this;
	}

	/**
	 * Hydrates rows as instances of a custom record class.
	 *
	 * The class name is trimmed and handed to ClassRecordHydrator with table primary-key context, but no repository class.
	 *
	 * @param string $recordClass Record class used by the class hydrator.
	 * @return self Current table query instance.
	 */
	public function usingRecordClass(string $recordClass): self {
		$this->hydrator=new ClassRecordHydrator(trim($recordClass), null, $this->primaryKey);
		return $this;
	}

	/**
	 * Adds a row transformer that maps amount and currency columns into a money value.
	 *
	 * The normalized mapping is also remembered for write paths, letting table writes apply the same money conversion rules before persistence.
	 *
	 * @param string $amountColumn Column containing the original numeric amount.
	 * @param string $currencyColumn Column containing the currency code.
	 * @param ?string $targetColumn Optional destination column for the mapped money value.
	 * @return self Current table query instance.
	 */
	public function asMoney(string $amountColumn, string $currencyColumn='currency', ?string $targetColumn=null): self {
		$mapping=CurrencyBridge::normalizeMoneyMapping(
			$amountColumn,
			$currencyColumn,
			null,
			$targetColumn,
			"table {$this->table}"
		);
		$this->addRowTransformer(
			fn(array $row): array => CurrencyBridge::applyMoneyMapping($row, $mapping, "table {$this->table}")
		);
		$this->writeMoneyMappings[]=$mapping;
		return $this;
	}

	/**
	 * Adds a row transformer that maps an amount column using a fixed currency.
	 *
	 * The fixed-currency mapping is also remembered for write paths, keeping read and persistence conversion behavior aligned.
	 *
	 * @param string $amountColumn Column containing the original numeric amount.
	 * @param string $currency Currency code applied to every mapped row.
	 * @param ?string $targetColumn Optional destination column for the mapped money value.
	 * @return self Current table query instance.
	 */
	public function asMoneyIn(string $amountColumn, string $currency, ?string $targetColumn=null): self {
		$mapping=CurrencyBridge::normalizeMoneyMapping(
			$amountColumn,
			null,
			$currency,
			$targetColumn,
			"table {$this->table}"
		);
		$this->addRowTransformer(
			fn(array $row): array => CurrencyBridge::applyMoneyMapping($row, $mapping, "table {$this->table}")
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
	 * @return self Current table query instance.
	 */
	public function asStoredMoney(string|array $targetColumn='stored_money', array $definition=[]): self {
		if(is_array($targetColumn)){
			$definition=$targetColumn;
			$targetColumn='stored_money';
		}
		$mapping=CurrencyBridge::normalizeStoredMoneyMapping(
			$definition,
			$targetColumn,
			"table {$this->table}"
		);
		$this->addRowTransformer(
			fn(array $row): array => CurrencyBridge::applyStoredMoneyMapping($row, $mapping, "table {$this->table}")
		);
		$this->writeStoredMoneyMappings[]=$mapping;
		return $this;
	}

	/**
	 * Returns an immutable snapshot of the table query specification.
	 *
	 * @return QuerySpec Immutable query specification snapshot.
	 */
	public function spec(): QuerySpec {
		return clone $this;
	}

	/**
	 * Exports query execution state for cache keys and diagnostics.
	 *
	 * Fingerprint data describes constraints, selected columns, hydrators, cache
	 * policy, and mutation policy without executing SQL.
	 *
	 * @return array<string,mixed> SQL table query fingerprint data.
	 */
	public function fingerprintPayload(): array {
		$compiled=(clone $this)->compile();
		return [
			'type'=>'table_query',
			'table'=>$this->table,
			'primary_key'=>$this->primaryKey,
			'columns'=>$this->columns,
			'caching'=>$this->caching,
			'hydrator'=>$this->hydratorDescriptor($this->hydrator),
			'query'=>[
				'params'=>$compiled['params'],
				'vars'=>$compiled['vars'],
			],
		];
	}

	/**
	 * Exports query execution state for cache keys and diagnostics.
	 *
	 * Fingerprint data describes constraints, selected columns, hydrators, cache
	 * policy, and mutation policy without executing SQL.
	 * @return string SHA-1 fingerprint hash.
	 */
	public function fingerprint(): string {
		return $this->fingerprintHash($this->fingerprintPayload());
	}

	/**
	 * Serializes the table query state for queued work, caching, and diagnostics.
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
	 * Rebuilds a table query from serialized execution state.
	 *
	 *
	 * @param array<string,mixed> $state Serialized execution state from executionState().
	 * @return self Current table query instance.
	 */
	public static function fromExecutionState(array $state): self {
		$table=trim((string)($state['table'] ?? ''));
		if($table===''){
			throw SqlError::invalidIdentifier('table', $table);
		}
		$primaryKey=isset($state['primary_key']) && is_string($state['primary_key']) && trim($state['primary_key'])!==''
			? trim($state['primary_key'])
			: null;
		$query=new self($table, $primaryKey);
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

	/**
	 * Adds SQL constraints to this fluent query.
	 *
	 * Constraints are stored on the inherited `QuerySpec` so later reads, counts, mutations, and relation queries share the same predicate state.
	 *
	 * @param string $amountColumn Column containing the stored amount.
	 * @param mixed $value Money amount compared after currency bridge normalization.
	 * @param string $currencyColumn Column containing the row currency code.
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
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
	 * @return self Current table query instance.
	 */
	public function whereMoneyLteIn(string $amountColumn, mixed $value, string $currency): self {
		return $this->whereMoneyCompare($amountColumn, $value, '<=', null, $currency);
	}

	/**
	 * Reads rows from the table with the current query state.
	 *
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Table rows, grouped values, or keyed result data.
	 */
	public function get(array|string|null $columns=null, bool|array|string|null $caching=null): array {
		$compiled=(clone $this)->compile();
		$result=$this->withSchemaHydration(fn(): mixed => sql_select(
				$this->resolvedColumns($columns ?? $this->columns),
				$this->table,
				$compiled['params'],
				$compiled['vars'],
				true,
				$caching ?? $this->caching
			)
		);
		return is_array($result) ? $this->transformQueryRows($result) : [];
	}

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema
	 * hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Table rows, grouped values, or keyed result data.
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
		$spec=clone $this;
		$compiled=$spec->limit(1)->compile();
		$result=$this->withSchemaHydration(fn(): mixed => sql_select(
				$this->resolvedColumns($columns ?? $this->columns),
				$this->table,
				$compiled['params'],
				$compiled['vars'],
				false,
				$caching ?? $this->caching
			)
		);
		return is_array($result) ? $this->transformQueryRow($result) : null;
	}

	/**
	 * Returns the first matching row or throws when no row exists.
	 *
	 * The lookup shares first() result shaping, cache policy, schema hydration retry, and row transformers before applying the fail-fast not-found guard.
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Optional not-found exception message.
	 * @return array First matching transformed row.
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
			"table {$this->table}",
			$this->notFoundContext($columns),
			$message
		);
	}

	/**
	 * Reads one column from the first matching row.
	 *
	 * The method selects only the requested column and returns null when no row is found or the transformed row does not contain that column key.
	 *
	 * @param string $column Column whose value should be returned.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Column value from the first row, or null when absent.
	 */
	public function value(string $column, bool|array|string|null $caching=null): mixed {
		$row=$this->first($column, $caching);
		return is_array($row) && array_key_exists($column, $row) ? $row[$column] : null;
	}

	/**
	 * Reads one column from the first matching row or throws when absent.
	 *
	 * Not-found handling is delegated to firstOrFail(); if the row exists but the transformed column is missing, null is returned.
	 *
	 * @param string $column Column whose value should be returned.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Optional not-found exception message.
	 * @return mixed Column value from the first row, or null when the row lacks the key.
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
	 * Returns a list or map of values from matching rows.
	 *
	 * Only the value column and optional key column are selected. Row transformers run before values are extracted, so mapped money or stored-money columns can be plucked like ordinary columns.
	 *
	 * @param string $column Column whose values should be returned.
	 * @param ?string $keyColumn Optional column used as array keys.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string,mixed> Plucked values, optionally keyed by row column.
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
	 * Returns matching rows keyed by a selected column.
	 *
	 * The key column is included in the selected projection when needed, then rows are transformed before the final keyed map is built.
	 *
	 * @param string $keyColumn Column whose value becomes the result array key.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string,array<string,mixed>> Rows keyed by the requested column.
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
	 * @return array Table rows, grouped values, or keyed result data.
	 */
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

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return bool True when at least one matching row exists.
	 */
	public function exists(bool|array|string|null $caching=null): bool {
		$count=$this->count($caching);
		return is_int($count) ? $count > 0 : false;
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
		$spec=(clone $this)->withoutOrdering()->withoutPaging();
		$compiled=$spec->compile(false);
		return $this->withSchemaHydration(fn(): mixed => sql_count(
				$this->table,
				$compiled['params'],
				$compiled['vars'],
				$caching ?? $this->caching
			)
		);
	}

	/**
	 * Executes a scalar aggregate over the current predicate.
	 *
	 * Ordering and pagination are ignored by the aggregate helper, while predicates and bound variables are preserved.
	 *
	 * @param string $function Aggregate function name such as COUNT, SUM, AVG, MIN, or MAX.
	 * @param string $column Column expression or `*` selector accepted by the aggregate helper.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed database aggregate value after COUNT/SUM/AVG numeric normalization and MIN/MAX passthrough.
	 */
	public function aggregate(
		string $function,
		string $column='*',
		bool|array|string|null $caching=null
	): mixed {
		return $this->aggregateValue($function, $column, $caching);
	}

	/**
	 * Sums a column over the current predicate.
	 *
	 * @param string $column Numeric column to sum.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed numeric sum normalized to int or float when the database returns a numeric value.
	 */
	public function sum(string $column, bool|array|string|null $caching=null): mixed {
		return $this->aggregateValue('SUM', $column, $caching);
	}

	/**
	 * Averages a column over the current predicate.
	 *
	 * @param string $column Numeric column to average.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed numeric average normalized to int or float when the database returns a numeric value.
	 */
	public function avg(string $column, bool|array|string|null $caching=null): mixed {
		return $this->aggregateValue('AVG', $column, $caching);
	}

	/**
	 * Reads the minimum value for a column over the current predicate.
	 *
	 * @param string $column Column to aggregate.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed minimum column value as returned by the database, including null or false failure markers.
	 */
	public function min(string $column, bool|array|string|null $caching=null): mixed {
		return $this->aggregateValue('MIN', $column, $caching);
	}

	/**
	 * Reads the maximum value for a column over the current predicate.
	 *
	 * @param string $column Column to aggregate.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed maximum column value as returned by the database, including null or false failure markers.
	 */
	public function max(string $column, bool|array|string|null $caching=null): mixed {
		return $this->aggregateValue('MAX', $column, $caching);
	}

	/**
	 * Counts non-null values for one column over the current predicate.
	 *
	 * @param string $column Column to count.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int|bool|null Affected-row count, boolean status, or null when execution is deferred or unavailable.
	 */
	public function countColumn(string $column, bool|array|string|null $caching=null): int|bool|null {
		$value=$this->aggregateValue('COUNT', $column, $caching);
		if($value===false || $value===null){
			return $value;
		}
		return is_numeric($value) ? (int)$value : null;
	}

	/**
	 * Counts distinct non-null values for one column over the current predicate.
	 *
	 * @param string $column Column to count distinctly.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int|bool|null Affected-row count, boolean status, or null when execution is deferred or unavailable.
	 */
	public function countDistinct(string $column, bool|array|string|null $caching=null): int|bool|null {
		$value=$this->aggregateValue('COUNT', $column, $caching, true);
		if($value===false || $value===null){
			return $value;
		}
		return is_numeric($value) ? (int)$value : null;
	}

	/**
	 * Returns grouped aggregate rows for one or more group columns.
	 *
	 * Ordering and pagination are ignored while predicates are preserved. The result contains group columns plus an aggregate_value key normalized from the SQL result.
	 *
	 * @param string|array $groupColumns Group-by column or column list.
	 * @param string $function Aggregate function name such as COUNT, SUM, AVG, MIN, or MAX.
	 * @param string $column Column expression or `*` selector accepted by the aggregate helper.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool $distinct Whether the aggregate should include only distinct column values.
	 * @return array<int,array<string,mixed>> Group rows with aggregate_value.
	 */
	public function aggregateRowsBy(
		string|array $groupColumns,
		string $function,
		string $column='*',
		bool|array|string|null $caching=null,
		bool $distinct=false
	): array {
		$function=$this->normalizeAggregateFunction($function);
		$groupColumns=$this->groupColumns($groupColumns);
		$column=$this->aggregateColumn($column, $function, $function==='COUNT');
		$compiled=(clone $this)
			->withoutOrdering()
			->withoutPaging()
			->compile(false);
		$result=$this->withSchemaHydration(fn(): mixed => sql_select(
				implode(', ', $groupColumns).', '.$function.'('.($distinct ? 'DISTINCT ' : '').$column.') AS aggregate_value',
				$this->table,
				$this->appendClause($compiled['params'], 'GROUP BY '.implode(', ', $groupColumns)),
				$compiled['vars'],
				true,
				$caching ?? $this->caching
			)
		);
		if(!is_array($result)){
			return [];
		}
		return $this->normalizeAggregateRows($result, $function);
	}

	/**
	 * Counts matching rows for each value of a group column.
	 *
	 * @param string $groupColumn Column whose values become result keys.
	 * @param string $column Column to count, or `*` for row count.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string,mixed> Count values keyed by group column value.
	 */
	public function countBy(
		string $groupColumn,
		string $column='*',
		bool|array|string|null $caching=null
	): array {
		return $this->groupedAggregateMap(
			$groupColumn,
			$this->aggregateRowsBy($groupColumn, 'COUNT', $column, $caching)
		);
	}

	/**
	 * Counts distinct column values for each value of a group column.
	 *
	 * @param string $groupColumn Column whose values become result keys.
	 * @param string $column Column to count distinctly.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string,mixed> Distinct count values keyed by group column value.
	 */
	public function countDistinctBy(
		string $groupColumn,
		string $column,
		bool|array|string|null $caching=null
	): array {
		return $this->groupedAggregateMap(
			$groupColumn,
			$this->aggregateRowsBy($groupColumn, 'COUNT', $column, $caching, true)
		);
	}

	/**
	 * Sums a column for each value of a group column.
	 *
	 * @param string $groupColumn Column whose values become result keys.
	 * @param string $column Numeric column to sum.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string,mixed> Sum values keyed by group column value.
	 */
	public function sumBy(
		string $groupColumn,
		string $column,
		bool|array|string|null $caching=null
	): array {
		return $this->groupedAggregateMap(
			$groupColumn,
			$this->aggregateRowsBy($groupColumn, 'SUM', $column, $caching)
		);
	}

	/**
	 * Averages a column for each value of a group column.
	 *
	 * @param string $groupColumn Column whose values become result keys.
	 * @param string $column Numeric column to average.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string,mixed> Average values keyed by group column value.
	 */
	public function avgBy(
		string $groupColumn,
		string $column,
		bool|array|string|null $caching=null
	): array {
		return $this->groupedAggregateMap(
			$groupColumn,
			$this->aggregateRowsBy($groupColumn, 'AVG', $column, $caching)
		);
	}

	/**
	 * Reads the minimum column value for each value of a group column.
	 *
	 * @param string $groupColumn Column whose values become result keys.
	 * @param string $column Column to aggregate.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string,mixed> Minimum values keyed by group column value.
	 */
	public function minBy(
		string $groupColumn,
		string $column,
		bool|array|string|null $caching=null
	): array {
		return $this->groupedAggregateMap(
			$groupColumn,
			$this->aggregateRowsBy($groupColumn, 'MIN', $column, $caching)
		);
	}

	/**
	 * Reads the maximum column value for each value of a group column.
	 *
	 * @param string $groupColumn Column whose values become result keys.
	 * @param string $column Column to aggregate.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string,mixed> Maximum values keyed by group column value.
	 */
	public function maxBy(
		string $groupColumn,
		string $column,
		bool|array|string|null $caching=null
	): array {
		return $this->groupedAggregateMap(
			$groupColumn,
			$this->aggregateRowsBy($groupColumn, 'MAX', $column, $caching)
		);
	}

	/**
	 * Streams or paginates SQL results in bounded batches.
	 *
	 * Batch helpers repeatedly execute constrained reads and stop when callbacks request termination or result windows are exhausted.
	 *
	 * @param int $page One-based page number, clamped to at least 1.
	 * @param int $perPage Page size clamped between 1 and 500.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return PageResult Paginated table result set.
	 */
	public function paginate(
		int $page=1,
		int $perPage=50,
		array|string|null $columns=null,
		bool|array|string|null $caching=null
	): PageResult {
		$page=max(1, $page);
		$perPage=max(1, min(500, $perPage));
		$total=$this->count($caching ?? $this->caching);
		$items=(clone $this)->forPage($page, $perPage)->get($columns, $caching);
		return new PageResult(
			$items,
			is_int($total) ? max(0, $total) : 0,
			$page,
			$perPage
		);
	}

	/**
	 * Reads table rows and converts them through the configured hydrator.
	 *
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Table rows, grouped values, or keyed result data.
	 */
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

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated row, scalar aggregate, or table value.
	 */
	public function firstHydrated(
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		$row=$this->first($columns, $caching);
		return $row!==null ? $this->resolvedHydrator($hydrator)->hydrate($row, $this->schema) : null;
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array Table rows, grouped values, or keyed result data.
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
	 * @return mixed Hydrated row, scalar aggregate, or table value.
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
	 * @return mixed Hydrated row, scalar aggregate, or table value.
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
			"table {$this->table}",
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
	 * @return mixed Hydrated row, scalar aggregate, or table value.
	 */
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

	/**
	 * Executes a read operation through this query.
	 *
	 * Read methods apply selected columns, predicates, cache policy, schema hydration retry, row transforms, and result-shape normalization.
	 *
	 * @param string $column Table column used by this operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return mixed Hydrated row, scalar aggregate, or table value.
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
	 * Streams or paginates SQL results in bounded batches.
	 *
	 * Batch helpers repeatedly execute constrained reads and stop when callbacks request termination or result windows are exhausted.
	 *
	 * @param int $page One-based page number requested by the caller.
	 * @param int $perPage Maximum rows requested per page.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return PageResult Paginated table result set.
	 */
	public function paginateHydrated(
		int $page=1,
		int $perPage=50,
		array|string|null $columns=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): PageResult {
		return $this->paginate($page, $perPage, $columns, $caching)
			->map(fn(array $row): mixed => $this->resolvedHydrator($hydrator)->hydrate($row, $this->schema));
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
	 * @return PageResult Paginated table result set.
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
		$size=max(1, min(1000, $size));
		$page=1;
		$processed=0;
		while(true){
			$rows=(clone $this)->forPage($page, $size)->get($columns, $caching);
			if($rows===[]){
				break;
			}
			$processed+=count($rows);
			if($callback($rows, $page, $processed)===false){
				break;
			}
			if(count($rows)<$size){
				break;
			}
			$page++;
		}
		return $processed;
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
		$size=max(1, min(1000, $size));
		$page=1;
		$processed=0;
		while(true){
			$records=(clone $this)->forPage($page, $size)->getRecords($columns, $hydrator, $caching);
			if($records===[]){
				break;
			}
			$processed+=count($records);
			if($callback($records, $page, $processed)===false){
				break;
			}
			if(count($records)<$size){
				break;
			}
			$page++;
		}
		return $processed;
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
	 * @param ?string $keyColumn KeyColumn.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string $direction Cursor direction passed to keyset chunking.
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
		$keyColumn=$this->resolvedKeyColumn($keyColumn);
		$direction=$this->normalizedKeysetDirection($direction);
		$size=max(1, min(1000, $size));
		$lastKey=null;
		$processed=0;
		while(true){
			$query=(clone $this)->withoutOrdering()->orderBy($keyColumn, $direction)->limit($size);
			if($lastKey!==null){
				$direction==='DESC'
					? $query->whereLt($keyColumn, $lastKey)
					: $query->whereGt($keyColumn, $lastKey);
			}
			$rows=$query->get($this->keyColumns($keyColumn, $columns), $caching);
			if($rows===[]){
				break;
			}
			$processed+=count($rows);
			$nextKey=$this->lastKeyFromRows($rows, $keyColumn);
			if($callback($rows, $nextKey, $processed)===false){
				break;
			}
			$lastKey=$nextKey;
			if(count($rows)<$size){
				break;
			}
		}
		return $processed;
	}

	/**
	 * Streams or paginates SQL results in bounded batches.
	 *
	 * Batch helpers repeatedly execute constrained reads and stop when callbacks request termination or result windows are exhausted.
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param int $size Maximum rows requested for each chunk.
	 * @param ?string $keyColumn KeyColumn.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string $direction Cursor direction passed to keyset chunking.
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
	 * @param ?string $keyColumn KeyColumn.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string $direction Cursor direction passed to keyset chunking.
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
		$keyColumn=$this->resolvedKeyColumn($keyColumn);
		$direction=$this->normalizedKeysetDirection($direction);
		$size=max(1, min(1000, $size));
		$lastKey=null;
		$processed=0;
		while(true){
			$query=(clone $this)->withoutOrdering()->orderBy($keyColumn, $direction)->limit($size);
			if($lastKey!==null){
				$direction==='DESC'
					? $query->whereLt($keyColumn, $lastKey)
					: $query->whereGt($keyColumn, $lastKey);
			}
			$rows=$query->get($this->keyColumns($keyColumn, $columns), $caching);
			if($rows===[]){
				break;
			}
			$nextKey=$this->lastKeyFromRows($rows, $keyColumn);
			$records=[];
			foreach($rows as $index=>$row){
				if(is_array($row)){
					$records[$index]=$this->resolvedHydrator($hydrator)->hydrate($row, $this->schema);
				}
			}
			$processed+=count($records);
			if($callback($records, $nextKey, $processed)===false){
				break;
			}
			$lastKey=$nextKey;
			if(count($rows)<$size){
				break;
			}
		}
		return $processed;
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param int $size Maximum rows requested for each chunk.
	 * @param ?string $keyColumn KeyColumn.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string $direction Cursor direction passed to keyset chunking.
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
	 * Reads the first table row matching the primary key.
	 *
	 *
	 * @param mixed $id Primary-key value matched against the table key column.
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
	 * Reads a primary-key table row and throws when it is absent.
	 *
	 *
	 * @param mixed $id Primary-key value matched against the table key column.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return array Table rows, grouped values, or keyed result data.
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
			"table {$this->table}",
			$this->notFoundContext($columns, ['id'=>$id]),
			$message,
			'Use find() when a missing row is acceptable, or verify the primary key and active filters before calling findOrFail().'
		);
	}

	/**
	 * Reads a primary-key table row and hydrates the result when present.
	 *
	 *
	 * @param mixed $id Primary-key value matched against the table key column.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated row, scalar aggregate, or table value.
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
	 * Reads and hydrates a primary-key table row, then throws when it is absent.
	 *
	 *
	 * @param mixed $id Primary-key value matched against the table key column.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return mixed Hydrated row, scalar aggregate, or table value.
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
			"table {$this->table}",
			$this->notFoundContext($columns, ['id'=>$id]),
			$message,
			'Use findHydrated() when a missing row is acceptable, or verify the primary key and active filters before calling findHydratedOrFail().'
		);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param mixed $id Primary-key value matched against the table key column.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated row, scalar aggregate, or table value.
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
	 * @param mixed $id Primary-key value matched against the table key column.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param ?string $message Message.
	 * @return mixed Hydrated row, scalar aggregate, or table value.
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
			"table {$this->table}",
			$this->notFoundContext($columns, ['id'=>$id]),
			$message,
			'Use findRecord() when a missing row is acceptable, or verify the primary key and active filters before calling findRecordOrFail().'
		);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Table mutation result.
	 */
	public function create(array $fields, bool|array|null $clearCache=null): MutationResult {
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('create', $resolvedClearCache);
		return $this->insertMutationResult($fields, $resolvedClearCache);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param array<int,array<string,mixed>> $rows Write rows before money and schema normalization.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationBatchResult Batched table mutation result.
	 */
	public function createMany(array $rows, bool|array|null $clearCache=null): MutationBatchResult {
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('create_many', $resolvedClearCache);
		$results=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$results[]=$this->insertMutationResult($row, $resolvedClearCache);
		}
		return new MutationBatchResult('insert', $results, count($rows));
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
		$attributes=$this->resolvedFields($attributes);
		$values=$values!==[] ? $this->resolvedFields($values) : [];
		$lookup=$this->queryForAttributes($attributes);
		$existing=$lookup->first($columns, $caching);
		if($existing!==null){
			return $existing;
		}
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('first_or_create', $resolvedClearCache);
		$this->assertMutationSucceeded($lookup->insertMutationResult($attributes + $values, $resolvedClearCache));
		$created=$lookup->first($columns, false);
		if($created!==null){
			return $created;
		}
		throw SqlError::recordNotFound(
			"table {$this->table}",
			$lookup->notFoundContext($columns),
			'The row was created, but could not be loaded from the lookup attributes.'
		);
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
		$attributes=$this->resolvedFields($attributes);
		$values=$values!==[] ? $this->resolvedFields($values) : [];
		$lookup=$this->queryForAttributes($attributes);
		$existing=$lookup->first($columns, $caching);
		if($existing===null){
			$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
			$this->warnIfWriteInvalidationMissing('update_or_create', $resolvedClearCache);
			$this->assertMutationSucceeded($lookup->insertMutationResult($attributes + $values, $resolvedClearCache));
			$created=$lookup->first($columns, false);
			if($created!==null){
				return $created;
			}
			throw SqlError::recordNotFound(
				"table {$this->table}",
				$lookup->notFoundContext($columns),
				'The row was created, but could not be loaded from the lookup attributes.'
			);
		}
		$updateFields=array_diff_key($values, $attributes);
		if($updateFields===[]){
			return $existing;
		}
		$this->assertMutationSucceeded($lookup->update($updateFields, $clearCache));
		$updated=$lookup->first($columns, false);
		if($updated!==null){
			return $updated;
		}
		throw SqlError::recordNotFound(
			"table {$this->table}",
			$lookup->notFoundContext($columns),
			'The row was updated, but could not be loaded from the lookup attributes.'
		);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Table mutation result.
	 */
	public function update(array $fields, bool|array|null $clearCache=null): MutationResult {
		$this->assertScopedForWrite("table {$this->table}", 'update');
		$compiled=(clone $this)->compile(false);
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('update', $resolvedClearCache);
		return MutationResult::fromRaw(
			'update',
			$this->withSchemaHydration(fn(): mixed => sql_update(
					$this->table,
					$this->resolvedFields($fields),
					$compiled['params'],
					$compiled['vars'],
					$resolvedClearCache
				)
			),
			$this->mutationContext()
		);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param string $column Table column used by this operation.
	 * @param int|float $amount Amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Table mutation result.
	 */
	public function increment(string $column, int|float $amount=1, bool|array|null $clearCache=null): MutationResult {
		return $this->counterMutation('increment', $column, '+', $amount, $clearCache);
	}

	/**
	 * Executes a write mutation through this query.
	 *
	 * Mutations combine current constraints, normalized write fields, money expansion, optimistic locking, guardrails, and cache invalidation policy.
	 *
	 * @param string $column Table column used by this operation.
	 * @param int|float $amount Amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Table mutation result.
	 */
	public function decrement(string $column, int|float $amount=1, bool|array|null $clearCache=null): MutationResult {
		return $this->counterMutation('decrement', $column, '-', $amount, $clearCache);
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
	 * @return MutationResult Table mutation result.
	 */
	public function updateWithVersion(
		array $fields,
		int $expectedVersion,
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): MutationResult {
		$versionColumn=$this->resolvedCounterColumn($versionColumn);
		$expectedVersion=$this->resolvedExpectedVersion($versionColumn, $expectedVersion);
		$bump=$this->resolvedVersionBump($versionColumn, $bump);
		$fields=$fields!==[] ? $this->resolvedFields($fields) : [];
		$this->assertVersionColumnNotInFields($fields, $versionColumn);
		$query=(clone $this)->whereEq($versionColumn, $expectedVersion);
		$query->assertScopedForWrite("table {$this->table}", 'update_with_version');
		$compiled=$query->compile(false);
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('update_with_version', $resolvedClearCache);
		return MutationResult::fromRaw(
			'update_with_version',
			$this->withSchemaHydration(fn(): mixed => sql_update(
					$this->table,
					$this->versionedUpdateFields($fields, $versionColumn),
					$compiled['params'],
					array_merge(array_values($fields), [$bump], $compiled['vars']),
					$resolvedClearCache
				)
			),
			array_merge($this->mutationContext(), [
				'version_column'=>$versionColumn,
				'expected_version'=>$expectedVersion,
				'next_version'=>$expectedVersion+$bump,
				'version_bump'=>$bump,
			])
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
	 * @return MutationResult Table mutation result.
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
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Table mutation result.
	 */
	public function delete(bool|array|null $clearCache=null): MutationResult {
		$this->assertScopedForWrite("table {$this->table}", 'delete');
		$compiled=(clone $this)->compile(false);
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('delete', $resolvedClearCache);
		return MutationResult::fromRaw(
			'delete',
			$this->withSchemaHydration(fn(): mixed => sql_delete(
					$this->table,
					$compiled['params'],
					$compiled['vars'],
					$resolvedClearCache
				)
			),
			$this->mutationContext()
		);
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
	 * @return MutationResult Table mutation result.
	 */
	public function upsert(
		array $fields,
		string|array|null $updateParams=null,
		?array $updateVars=null,
		bool|array|null $clearCache=null
	): MutationResult {
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('upsert', $resolvedClearCache);
		return MutationResult::fromRaw(
			'upsert',
			$this->withSchemaHydration(fn(): mixed => sql_upsert(
					$this->table,
					$this->resolvedFields($fields),
					$updateParams,
					$updateVars,
					$resolvedClearCache
				)
			),
			$this->mutationContext()
		);
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
	 * @return MutationBatchResult Batched table mutation result.
	 */
	public function upsertMany(
		array $rows,
		string|array|null $updateParams=null,
		?array $updateVars=null,
		bool|array|null $clearCache=null
	): MutationBatchResult {
		$results=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$results[]=$this->upsert($row, $updateParams, $updateVars, $clearCache);
		}
		return new MutationBatchResult('upsert', $results, count($rows));
	}

	/**
	 * Queues a table read and delivers normalized rows to the callback.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		return sql_select(
			$this->resolvedColumns($columns ?? $this->columns),
			$this->table,
			(clone $this)->compile()['params'],
			(clone $this)->compile()['vars'],
			true,
			$caching ?? $this->caching,
			$queue,
			fn(mixed $result): mixed => $callback($this->queuedAllQueryResult($result))
		);
	}

	/**
	 * Queues a single-row table read and delivers the normalized row to the callback.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		$spec=clone $this;
		$queuedSpec=$spec->limit(1);
		return sql_select(
			$this->resolvedColumns($columns ?? $this->columns),
			$this->table,
			$queuedSpec->compile()['params'],
			$queuedSpec->compile()['vars'],
			false,
			$caching ?? $this->caching,
			$queue,
			fn(mixed $result): mixed => $callback($this->queuedFirstQueryResult($result))
		);
	}

	/**
	 * Queues a table read and hydrates the rows before invoking the callback.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		return $this->queueGet(
			fn(mixed $result): mixed => $callback($this->hydrateQueuedRows($result, $hydrator)),
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
	 * @param string $queue SQL batch queue name.
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
	 * Queues a single-row table read and hydrates the row before invoking the callback.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		return $this->queueFirst(
			fn(mixed $result): mixed => $callback($this->hydrateQueuedRow($result, $hydrator)),
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
	 * @param string $queue SQL batch queue name.
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
	 * Queues a single-row table read that fails fast when no row is returned.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
					"table {$this->table}",
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
	 * @param string $queue SQL batch queue name.
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
		return $this->queueFirstOrFail(
			function(array $row)use($callback, $hydrator): mixed{
				return $callback($this->resolvedHydrator($hydrator)->hydrate($row, $this->schema));
			},
			$queue,
			$columns,
			$caching,
			$message
		);
	}

	/**
	 * Queues a primary-key table lookup and delivers the normalized row to the callback.
	 *
	 *
	 * @param mixed $id Primary-key value matched against the table key column.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue SQL batch queue name.
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
	 * Queues a primary-key table lookup that fails fast when no row is returned.
	 *
	 *
	 * @param mixed $id Primary-key value matched against the table key column.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue SQL batch queue name.
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
					"table {$this->table}",
					$this->notFoundContext($columns, ['id'=>$id]),
					$message,
					'Use queueFind() when a missing row is acceptable, or verify the primary key and active filters before calling queueFindOrFail().'
				);
			},
			$columns,
			$queue,
			$caching
		);
	}

	/**
	 * Queues a primary-key table lookup and hydrates the row before invoking the callback.
	 *
	 *
	 * @param mixed $id Primary-key value matched against the table key column.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue SQL batch queue name.
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
	 * Queues a hydrated primary-key lookup that fails fast when no row is returned.
	 *
	 *
	 * @param mixed $id Primary-key value matched against the table key column.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue SQL batch queue name.
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
		return $this->queueFindOrFail(
			$id,
			function(array $row)use($callback, $hydrator): mixed{
				return $callback($this->resolvedHydrator($hydrator)->hydrate($row, $this->schema));
			},
			$columns,
			$queue,
			$caching,
			$message
		);
	}

	/**
	 * Configures row hydration and transformation for query results.
	 *
	 * Hydration transforms raw arrays into repository records, class instances, or callback results while preserving queued-result normalization.
	 *
	 * @param mixed $id Primary-key value matched against the table key column.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue SQL batch queue name.
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
	 * @param mixed $id Primary-key value matched against the table key column.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue SQL batch queue name.
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
	 * Queues a column pluck and delivers the collected values to the callback.
	 *
	 *
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param ?string $keyColumn KeyColumn.
	 * @param string $queue SQL batch queue name.
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
	 * Queues a table read keyed by the selected column.
	 *
	 *
	 * @param string $keyColumn Column used as returned array keys or keyset cursor.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue SQL batch queue name.
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
	 * Queues a table read that expects exactly one matching row.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
						"table {$this->table}",
						$this->notFoundContext($columns),
						$message,
						'Use queueFirst() when zero matches are acceptable, or tighten the query before calling queueSole().'
					);
				}
				if(count($rows)>1){
					throw SqlError::multipleRecordsFound(
						"table {$this->table}",
						$this->notFoundContext($columns, ['matched_rows_sample'=>count($rows)]),
						$message,
						'Use queueGet()/queueAll() when multiple matches are expected, or tighten the query until it uniquely identifies a single row.'
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
	 * @param string $queue SQL batch queue name.
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
		return $this->queueSole(
			function(array $row)use($callback, $hydrator): mixed{
				return $callback($this->resolvedHydrator($hydrator)->hydrate($row, $this->schema));
			},
			$queue,
			$columns,
			$caching,
			$message
		);
	}

	/**
	 * Queues a scalar read that expects exactly one matching value.
	 *
	 *
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
	 * Queues a scalar read for the selected column.
	 *
	 *
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
	 * Queues a scalar read and fails fast when the value is missing.
	 *
	 *
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
	 * Queues an existence check for the current table predicates.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
	 * @param string $queue SQL batch queue name.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueCount(
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$compiled=(clone $this)->withoutOrdering()->withoutPaging()->compile(false);
		return sql_count(
			$this->table,
			$compiled['params'],
			$compiled['vars'],
			$caching ?? $this->caching,
			$queue,
			$callback
		);
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param string $function SQL aggregate function name.
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		$function=$this->normalizeAggregateFunction($function);
		$column=$this->aggregateColumn($column, $function, $function==='COUNT');
		$compiled=(clone $this)->withoutOrdering()->withoutPaging()->compile(false);
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

	/**
	 * Queues a SUM aggregate for the selected table column.
	 *
	 *
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
	 * Queues an AVG aggregate for the selected table column.
	 *
	 *
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
	 * Queues a MIN aggregate for the selected table column.
	 *
	 *
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
	 * Queues a MAX aggregate for the selected table column.
	 *
	 *
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		$function=$this->normalizeAggregateFunction($function);
		$groupColumns=$this->groupColumns($groupColumns);
		$column=$this->aggregateColumn($column, $function, $function==='COUNT');
		$compiled=(clone $this)
			->withoutOrdering()
			->withoutPaging()
			->compile(false);
		return sql_select(
			implode(', ', $groupColumns).', '.$function.'('.($distinct ? 'DISTINCT ' : '').$column.') AS aggregate_value',
			$this->table,
			$this->appendClause($compiled['params'], 'GROUP BY '.implode(', ', $groupColumns)),
			$compiled['vars'],
			true,
			$caching ?? $this->caching,
			$queue,
			function(mixed $result)use($callback, $function): void{
				$callback($this->normalizeAggregateRows($this->queuedAllQueryResult($result), $function));
			}
		);
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param string $groupColumn Column used to group queued aggregate results.
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		return $this->queueAggregateRowsBy(
			$groupColumn,
			'COUNT',
			$column,
			fn(array $rows): mixed => $callback($this->groupedAggregateMap($groupColumn, $rows)),
			$queue,
			$caching
		);
	}

	/**
	 * Configures relation eager loading or aggregate metadata.
	 *
	 * Eager descriptors are replayed after parent rows are read so related records, counts, and aggregates are attached consistently.
	 *
	 * @param string $groupColumn Column used to group queued aggregate results.
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		return $this->queueAggregateRowsBy(
			$groupColumn,
			'COUNT',
			$column,
			fn(array $rows): mixed => $callback($this->groupedAggregateMap($groupColumn, $rows)),
			$queue,
			$caching,
			true
		);
	}

	/**
	 * Queues grouped SUM aggregates for the selected table column.
	 *
	 *
	 * @param string $groupColumn Column used to group queued aggregate results.
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		return $this->queueAggregateRowsBy(
			$groupColumn,
			'SUM',
			$column,
			fn(array $rows): mixed => $callback($this->groupedAggregateMap($groupColumn, $rows)),
			$queue,
			$caching
		);
	}

	/**
	 * Queues grouped AVG aggregates for the selected table column.
	 *
	 *
	 * @param string $groupColumn Column used to group queued aggregate results.
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		return $this->queueAggregateRowsBy(
			$groupColumn,
			'AVG',
			$column,
			fn(array $rows): mixed => $callback($this->groupedAggregateMap($groupColumn, $rows)),
			$queue,
			$caching
		);
	}

	/**
	 * Queues grouped MIN aggregates for the selected table column.
	 *
	 *
	 * @param string $groupColumn Column used to group queued aggregate results.
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		return $this->queueAggregateRowsBy(
			$groupColumn,
			'MIN',
			$column,
			fn(array $rows): mixed => $callback($this->groupedAggregateMap($groupColumn, $rows)),
			$queue,
			$caching
		);
	}

	/**
	 * Queues grouped MAX aggregates for the selected table column.
	 *
	 *
	 * @param string $groupColumn Column used to group queued aggregate results.
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		return $this->queueAggregateRowsBy(
			$groupColumn,
			'MAX',
			$column,
			fn(array $rows): mixed => $callback($this->groupedAggregateMap($groupColumn, $rows)),
			$queue,
			$caching
		);
	}

	/**
	 * Queues a paginated table read and count callback.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param int $page One-based page number requested by the caller.
	 * @param int $perPage Maximum rows requested per page.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue SQL batch queue name.
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
	 * Queues a paginated table read and hydrates each page item.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param int $page One-based page number requested by the caller.
	 * @param int $perPage Maximum rows requested per page.
	 * @param array|string|null $columns Column list, projection, or `*` selector.
	 * @param string $queue SQL batch queue name.
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
		return $this->queuePaginate(
			function(PageResult $pageResult)use($callback, $hydrator): void{
				$records=[];
				$resolvedHydrator=$this->resolvedHydrator($hydrator);
				foreach($pageResult->items() as $key=>$row){
					if(is_array($row)){
						$records[$key]=$resolvedHydrator->hydrate($row, $this->schema);
					}
				}
				$callback(new PageResult($records, $pageResult->total(), $pageResult->page(), $pageResult->perPage()));
			},
			$page,
			$perPage,
			$columns,
			$queue,
			$caching
		);
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
	 * @param string $queue SQL batch queue name.
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
	 * Queues a table insert mutation.
	 *
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueCreate(
		array $fields,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_create', $resolvedClearCache);
		return sql_insert(
			$this->table,
			$this->resolvedFields($fields),
			null,
			$resolvedClearCache,
			$queue,
			$callback
		);
	}

	/**
	 * Queues multiple table insert mutations.
	 *
	 *
	 * @param array<int,array<string,mixed>> $rows Write rows before money and schema normalization.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueCreateMany(
		array $rows,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		return $this->queueMutationBatch(
			'insert',
			$rows,
			$callback,
			fn(array $row, callable $rowCallback): null|bool => $this->queueCreate($row, $rowCallback, $queue, $clearCache)
		);
	}

	/**
	 * Queues a table update mutation for the current predicates.
	 *
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueUpdate(
		array $fields,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		$this->assertScopedForWrite("table {$this->table}", 'queue_update');
		$compiled=(clone $this)->compile(false);
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_update', $resolvedClearCache);
		return sql_update(
			$this->table,
			$this->resolvedFields($fields),
			$compiled['params'],
			$compiled['vars'],
			$resolvedClearCache,
			$queue,
			$callback
		);
	}

	/**
	 * Queues an optimistic-lock table update.
	 *
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param int $expectedVersion Optimistic-lock version expected in storage.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		$versionColumn=$this->resolvedCounterColumn($versionColumn);
		$expectedVersion=$this->resolvedExpectedVersion($versionColumn, $expectedVersion);
		$bump=$this->resolvedVersionBump($versionColumn, $bump);
		$fields=$fields!==[] ? $this->resolvedFields($fields) : [];
		$this->assertVersionColumnNotInFields($fields, $versionColumn);
		$query=(clone $this)->whereEq($versionColumn, $expectedVersion);
		$query->assertScopedForWrite("table {$this->table}", 'queue_update_with_version');
		$compiled=$query->compile(false);
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_update_with_version', $resolvedClearCache);
		$context=array_merge($this->mutationContext(), [
			'version_column'=>$versionColumn,
			'expected_version'=>$expectedVersion,
			'next_version'=>$expectedVersion+$bump,
			'version_bump'=>$bump,
		]);
		return sql_update(
			$this->table,
			$this->versionedUpdateFields($fields, $versionColumn),
			$compiled['params'],
			array_merge(array_values($fields), [$bump], $compiled['vars']),
			$resolvedClearCache,
			$queue,
			static fn(mixed $result): mixed => $callback(MutationResult::fromRaw('update_with_version', $result, $context))
		);
	}

	/**
	 * Queues an optimistic-lock table update that fails when no row changes.
	 *
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param int $expectedVersion Optimistic-lock version expected in storage.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
	 * Queues an atomic table-column increment.
	 *
	 *
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		return $this->queueCounterMutation('queue_increment', $column, '+', $amount, $callback, $queue, $clearCache);
	}

	/**
	 * Queues an atomic table-column decrement.
	 *
	 *
	 * @param string $column Table column used by this operation.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		return $this->queueCounterMutation('queue_decrement', $column, '-', $amount, $callback, $queue, $clearCache);
	}

	/**
	 * Queues a table delete mutation for the current predicates.
	 *
	 *
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Null when queued for batch execution, false if queueing failed.
	 */
	public function queueDelete(
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		$this->assertScopedForWrite("table {$this->table}", 'queue_delete');
		$compiled=(clone $this)->compile(false);
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_delete', $resolvedClearCache);
		return sql_delete(
			$this->table,
			$compiled['params'],
			$compiled['vars'],
			$resolvedClearCache,
			$queue,
			$callback
		);
	}

	/**
	 * Queues a table upsert mutation.
	 *
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing('queue_upsert', $resolvedClearCache);
		return sql_upsert(
			$this->table,
			$this->resolvedFields($fields),
			$updateParams,
			$updateVars,
			$resolvedClearCache,
			$queue,
			$callback
		);
	}

	/**
	 * Queues multiple table upsert mutations.
	 *
	 *
	 * @param array<int,array<string,mixed>> $rows Write rows before money and schema normalization.
	 * @param callable $callback Callback invoked with the normalized result or batch.
	 * @param string $queue SQL batch queue name.
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
		return $this->queueMutationBatch(
			'upsert',
			$rows,
			$callback,
			fn(array $row, callable $rowCallback): null|bool => $this->queueUpsert(
				$row,
				$rowCallback,
				$queue,
				$updateParams,
				$updateVars,
				$clearCache
			)
		);
	}

	/**
	 * Resolves selected columns through schema metadata when available.
	 *
	 * Schema-backed queries may expand projections, aliases, or validated column
	 * sets; schema-less queries fall back to the base `QuerySpec` column
	 * normalizer. The returned value is passed directly to SQL read helpers.
	 *
	 * @param array|string $columns Requested column selector.
	 * @return array|string SQL-ready column selector.
	 */
	private function resolvedColumns(array|string $columns): array|string {
		if($this->schema!==null){
			return $this->schema->columns($columns);
		}
		return QuerySpec::columns($columns);
	}

	/**
	 * Applies schema casts and configured row transforms to one result row.
	 *
	 * Schema casting runs before custom transforms so user callbacks and money
	 * mappings see normalized PHP values rather than raw database strings.
	 *
	 * @param array<string,mixed> $row Raw SQL row.
	 * @return array<string,mixed> Cast and transformed row.
	 */
	private function transformQueryRow(array $row): array {
		if($this->schema!==null){
			$row=$this->schema->castRow($row);
		}
		return $this->transformRow($row);
	}

	/**
	 * Applies schema casts and configured row transforms to many result rows.
	 *
	 * Bulk schema casting is used when possible, then the shared row-transform
	 * pipeline from `TransformsRows` is applied to preserve money and custom
	 * mapping behavior across read shapes.
	 *
	 * @param list<array<string,mixed>> $rows Raw SQL rows.
	 * @return list<array<string,mixed>> Cast and transformed rows.
	 */
	private function transformQueryRows(array $rows): array {
		if($this->schema!==null){
			$rows=$this->schema->castRows($rows);
		}
		return $this->transformRows($rows);
	}

	/**
	 * Normalizes a queued SQL callback result through the row transform layer.
	 *
	 * Queued SQL helpers may return a list of rows, a single row, an empty
	 * array, or a non-array failure marker. This method preserves non-array
	 * values while transforming row-shaped arrays consistently with immediate
	 * reads.
	 *
	 * @param mixed $result Raw queued SQL callback result.
	 * @return mixed Queued value with row arrays transformed, or the original marker.
	 */
	private function transformQueuedQueryResult(mixed $result): mixed {
		if(!is_array($result)){
			return $result;
		}
		if($result===[]){
			return [];
		}
		if(array_is_list($result) && isset($result[0]) && is_array($result[0])){
			return $this->transformQueryRows($result);
		}
		return $this->transformQueryRow($result);
	}

	/**
	 * Converts a queued callback result into a list of transformed rows.
	 *
	 * Single-row results become a one-item list, list results are filtered to
	 * row arrays, and non-array failure values become an empty list. This keeps
	 * queued `get()` callbacks aligned with immediate `get()` semantics.
	 *
	 * @param mixed $result Raw queued SQL callback result.
	 * @return list<array<string,mixed>> Transformed rows.
	 */
	private function queuedAllQueryResult(mixed $result): array {
		if(!is_array($result) || $result===[]){
			return [];
		}
		if(array_is_list($result)){
			return $this->transformQueryRows(array_values(array_filter($result, 'is_array')));
		}
		return [$this->transformQueryRow($result)];
	}

	/**
	 * Converts a queued callback result into the first transformed row.
	 *
	 * List results return their first row-shaped element, single-row results are
	 * transformed directly, and empty or non-array results become null.
	 *
	 * @param mixed $result Raw queued SQL callback result.
	 * @return ?array<string,mixed> First transformed row, or null when absent.
	 */
	private function queuedFirstQueryResult(mixed $result): ?array {
		if(!is_array($result) || $result===[]){
			return null;
		}
		if(array_is_list($result)){
			$row=$result[0] ?? null;
			return is_array($row) ? $this->transformQueryRow($row) : null;
		}
		return $this->transformQueryRow($result);
	}

	/**
	 * Hydrates all row-shaped records from a queued callback result.
	 *
	 * Raw queued rows are normalized without transforms first, then the resolved
	 * hydrator receives each row and the optional schema. Keys from the queued
	 * row list are preserved for callers that rely on callback ordering.
	 *
	 * @param mixed $result Raw queued SQL callback result.
	 * @param mixed $hydrator Optional hydrator override.
	 * @return array<int|string,mixed> Hydrated records keyed like the queued rows.
	 */
	private function hydrateQueuedRows(mixed $result, mixed $hydrator=null): array {
		$rows=$this->queuedRowsFromResult($result);
		if($rows===[]){
			return [];
		}
		$resolved=[];
		$hydrator=$this->resolvedHydrator($hydrator);
		foreach($rows as $key=>$row){
			$resolved[$key]=$hydrator->hydrate($row, $this->schema);
		}
		return $resolved;
	}

	/**
	 * Hydrates the first row-shaped record from a queued callback result.
	 *
	 * Null is returned when the callback result contains no row. Otherwise the
	 * resolved hydrator receives the row and the current schema metadata.
	 *
	 * @param mixed $result Raw queued SQL callback result.
	 * @param mixed $hydrator Optional hydrator override.
	 * @return mixed Hydrated queued row, or null when the callback result has no row.
	 */
	private function hydrateQueuedRow(mixed $result, mixed $hydrator=null): mixed {
		$row=$this->queuedRowFromResult($result);
		if($row===null){
			return null;
		}
		return $this->resolvedHydrator($hydrator)->hydrate($row, $this->schema);
	}

	/**
	 * Extracts row arrays from a queued SQL result.
	 *
	 * The helper distinguishes a list of rows from a single associative row.
	 * Non-array and empty results return an empty list so queued hydration
	 * callbacks can be written without defensive result-shape checks.
	 *
	 * @param mixed $result Raw queued SQL callback result.
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
	 * Extracts the first row array from a queued SQL result.
	 *
	 * List results return the first row-shaped element, associative results are
	 * treated as a single row, and non-row results return null.
	 *
	 * @param mixed $result Raw queued SQL callback result.
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
	 * Restores row transforms captured in a serialized execution state.
	 *
	 * Queued and replayed table queries persist money mapping descriptors rather
	 * than closures. This method rebuilds the equivalent row transformers and
	 * restores the mapping arrays used later for write-field expansion.
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
				fn(array $row): array => CurrencyBridge::applyMoneyMapping($row, $mapping, "table {$this->table}")
			);
			$this->writeMoneyMappings[]=$mapping;
		}
		foreach($storedMoneyMappings as $mapping){
			if(!is_array($mapping)){
				continue;
			}
			$this->addRowTransformer(
				fn(array $row): array => CurrencyBridge::applyStoredMoneyMapping($row, $mapping, "table {$this->table}")
			);
			$this->writeStoredMoneyMappings[]=$mapping;
		}
	}

	/**
	 * Normalizes mutation fields before insert, update, or upsert execution.
	 *
	 * Empty and numeric-keyed field maps are rejected, money mappings are expanded
	 * into database columns, and schema-backed queries validate/cast fields
	 * through `TableSchema`. Schema-less queries still validate identifiers.
	 *
	 * @param array<string|int,mixed> $fields User-supplied write fields.
	 * @return array<string,mixed> SQL-ready associative field map.
	 */
	private function resolvedFields(array $fields): array {
		if($fields===[]){
			throw SqlError::invalidFieldPayload("table {$this->table}", 'Field payload cannot be empty.');
		}
		$fields=CurrencyBridge::expandWriteFields(
			$fields,
			$this->writeMoneyMappings,
			$this->writeStoredMoneyMappings,
			"table {$this->table}"
		);
		if($this->schema!==null){
			return $this->schema->fields($fields);
		}
		foreach($fields as $column=>$_value){
			if(is_int($column)){
				throw SqlError::invalidFieldPayload("table {$this->table}", 'Field payload must be an associative array.');
			}
			$this->normalizeIdentifier((string)$column);
		}
		return $fields;
	}

	/**
	 * Resolves the effective record hydrator for hydrated result methods.
	 *
	 * Explicit hydrators override the query default, callables are wrapped,
	 * record-hydrator classes are instantiated directly, and ordinary class
	 * names hydrate into instances through `ClassRecordHydrator`.
	 *
	 * @param mixed $hydrator Optional hydrator override.
	 * @return RecordHydrator Hydrator ready to receive rows and schema metadata.
	 */
	private function resolvedHydrator(mixed $hydrator=null): RecordHydrator {
		$source=$hydrator ?? $this->hydrator ?? new RecordObjectHydrator(null, $this->primaryKey);
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
			return new ClassRecordHydrator($source, null, $this->primaryKey);
		}
		throw SqlError::invalidHydrator("table {$this->table}", $source);
	}

	/**
	 * Validates a table-query identifier fragment.
	 *
	 * Identifiers may include dotted qualification but must remain simple
	 * alphanumeric SQL identifiers. Invalid identifiers raise a structured SQL
	 * error before they can be interpolated into query fragments.
	 *
	 * @param string $identifier Candidate column, table, or qualified identifier.
	 * @return string Trimmed identifier accepted for SQL composition.
	 */
	private function normalizeIdentifier(string $identifier): string {
		$identifier=trim($identifier);
		if($identifier==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $identifier)!==1){
			throw SqlError::invalidIdentifier('table query', $identifier, $this->table);
		}
		return $identifier;
	}

	/**
	 * Builds the common diagnostic context for mutation results.
	 *
	 * Mutation results and exceptions include the target table and primary key
	 * so callers can log or display failures without reverse-engineering the
	 * query object state.
	 *
	 * @return array{table:string,primary_key:?string} Mutation diagnostic context.
	 */
	private function mutationContext(): array {
		return [
			'table'=>$this->table,
			'primary_key'=>$this->primaryKey,
		];
	}

	/**
	 * Queues a batch of row mutations and aggregates their callback results.
	 *
	 * Each valid row is queued independently, then callback results are wrapped
	 * into `MutationResult` objects. Once all queued callbacks have fired, the
	 * caller receives a `MutationBatchResult` in the original row order.
	 *
	 * @param string $operation Mutation operation name.
	 * @param array<int,array<string,mixed>|mixed> $rows Candidate mutation rows.
	 * @param callable $callback Receives the completed `MutationBatchResult`.
	 * @param callable $queueRow Queues one row and accepts the row plus row callback.
	 * @return null|bool Null when queued, false when at least one row failed to queue.
	 */
	private function queueMutationBatch(
		string $operation,
		array $rows,
		callable $callback,
		callable $queueRow
	): null|bool {
		$requested=count($rows);
		$results=[];
		$pending=0;
		$setupComplete=false;
		$queueFailed=false;
		$emit=function()use(&$pending, &$setupComplete, &$results, $requested, $operation, $callback): void{
			if($setupComplete && $pending===0){
				ksort($results);
				$callback(new MutationBatchResult($operation, $results, $requested));
			}
		};
		foreach($rows as $index=>$row){
			if(!is_array($row)){
				continue;
			}
			$pending++;
			$result=$queueRow(
				$row,
				function(mixed $rawResult)use(&$results, &$pending, $index, $operation, $emit): void{
					$results[$index]=MutationResult::fromRaw($operation, $rawResult, $this->mutationContext());
					$pending--;
					$emit();
				}
			);
			if($result===false){
				$results[$index]=MutationResult::fromRaw(
					$operation,
					false,
					$this->mutationContext(),
					"Queueing {$operation} failed before execution."
				);
				$pending--;
				$queueFailed=true;
			}
		}
		$setupComplete=true;
		$emit();
		return $queueFailed ? false : null;
	}

	/**
	 * Executes an immediate increment or decrement mutation.
	 *
	 * The method enforces write scoping, validates the counter column and
	 * amount, compiles the inherited predicates, warns on missing named-cache
	 * invalidation, and wraps the raw SQL update result in a mutation object.
	 *
	 * @param string $operation Logical operation name.
	 * @param string $column Counter column.
	 * @param string $operator SQL arithmetic operator.
	 * @param int|float $amount Non-negative counter amount.
	 * @param bool|array|null $clearCache Optional write invalidation policy.
	 * @return MutationResult Immediate mutation result.
	 */
	private function counterMutation(
		string $operation,
		string $column,
		string $operator,
		int|float $amount,
		bool|array|null $clearCache=null
	): MutationResult {
		$this->assertScopedForWrite("table {$this->table}", $operation);
		$column=$this->resolvedCounterColumn($column);
		$amount=$this->resolvedCounterAmount($column, $amount);
		$compiled=(clone $this)->compile(false);
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing($operation, $resolvedClearCache);
		return MutationResult::fromRaw(
			$operation,
			$this->withSchemaHydration(fn(): mixed => sql_update(
					$this->table,
					$this->counterFields($column, $operator),
					$compiled['params'],
					array_merge([$amount], $compiled['vars']),
					$resolvedClearCache
				)
			),
			array_merge($this->mutationContext(), ['column'=>$column, 'amount'=>$amount])
		);
	}

	/**
	 * Queues an increment or decrement mutation.
	 *
	 * The queued path mirrors immediate counter mutation validation but delegates
	 * execution to `sql_update()` with the requested queue position and callback.
	 *
	 * @param string $operation Logical operation name.
	 * @param string $column Counter column.
	 * @param string $operator SQL arithmetic operator.
	 * @param int|float $amount Non-negative counter amount.
	 * @param callable $callback Receives the raw queued SQL result.
	 * @param string $queue Queue placement token understood by SQL helpers.
	 * @param bool|array|null $clearCache Optional write invalidation policy.
	 * @return null|bool SQL queue result.
	 */
	private function queueCounterMutation(
		string $operation,
		string $column,
		string $operator,
		int|float $amount,
		callable $callback,
		string $queue,
		bool|array|null $clearCache=null
	): null|bool {
		$this->assertScopedForWrite("table {$this->table}", $operation);
		$column=$this->resolvedCounterColumn($column);
		$amount=$this->resolvedCounterAmount($column, $amount);
		$compiled=(clone $this)->compile(false);
		$resolvedClearCache=$clearCache===null ? $this->clearCacheOnWrite : $clearCache;
		$this->warnIfWriteInvalidationMissing($operation, $resolvedClearCache);
		return sql_update(
			$this->table,
			$this->counterFields($column, $operator),
			$compiled['params'],
			array_merge([$amount], $compiled['vars']),
			$resolvedClearCache,
			$queue,
			$callback
		);
	}

	/**
	 * Resolves a counter column through schema metadata or identifier validation.
	 *
	 * Schema-backed queries can map aliases or validate fields. Schema-less
	 * queries allow only simple identifiers accepted by `normalizeIdentifier()`.
	 *
	 * @param string $column Requested counter column.
	 * @return string SQL-ready counter column.
	 */
	private function resolvedCounterColumn(string $column): string {
		if($this->schema!==null){
			return $this->schema->columns($column);
		}
		return $this->normalizeIdentifier($column);
	}

	/**
	 * Validates a counter mutation amount.
	 *
	 * Counter amounts must be finite and non-negative; decrement operations pass
	 * the sign through the SQL operator rather than accepting negative amounts.
	 *
	 * @param string $column Counter column used for diagnostics.
	 * @param int|float $amount Requested amount.
	 * @return int|float Validated amount.
	 */
	private function resolvedCounterAmount(string $column, int|float $amount): int|float {
		if(!is_finite((float)$amount) || $amount<0){
			throw SqlError::invalidCounterAmount("table {$this->table}", $column, $amount);
		}
		return $amount;
	}

	/**
	 * Builds DBMS-specific counter assignment fragments.
	 *
	 * SQL helpers choose the fragment for the active backend, allowing one
	 * mutation call to support MySQL, PostgreSQL, and SQLite quoting rules.
	 *
	 * @param string $column SQL-ready counter column.
	 * @param string $operator SQL arithmetic operator.
	 * @return array{mysql:string,postgresql:string,sqlite:string} Counter assignments by DBMS.
	 */
	private function counterFields(string $column, string $operator): array {
		return [
			'mysql'=>$this->counterFieldForDbms($column, $operator, 'mysql'),
			'postgresql'=>$this->counterFieldForDbms($column, $operator, 'postgresql'),
			'sqlite'=>$this->counterFieldForDbms($column, $operator, 'sqlite'),
		];
	}

	/**
	 * Builds DBMS-specific optimistic-lock update assignment fragments.
	 *
	 * User fields are assigned from bound values, then the version column is
	 * incremented by a bound bump value in the same SQL update statement.
	 *
	 * @param array<string,mixed> $fields Validated update fields.
	 * @param string $versionColumn Version column managed by the optimistic lock.
	 * @return array{mysql:string,postgresql:string,sqlite:string} Assignment fragments by DBMS.
	 */
	private function versionedUpdateFields(array $fields, string $versionColumn): array {
		return [
			'mysql'=>$this->versionedUpdateFieldsForDbms($fields, $versionColumn, 'mysql'),
			'postgresql'=>$this->versionedUpdateFieldsForDbms($fields, $versionColumn, 'postgresql'),
			'sqlite'=>$this->versionedUpdateFieldsForDbms($fields, $versionColumn, 'sqlite'),
		];
	}

	/**
	 * Builds an optimistic-lock assignment fragment for one DBMS.
	 *
	 * Identifiers are quoted per backend and values remain parameterized. The
	 * version assignment is appended after user fields so bound variables follow
	 * the update method's field-values-then-bump order.
	 *
	 * @param array<string,mixed> $fields Validated update fields.
	 * @param string $versionColumn Version column managed by the optimistic lock.
	 * @param string $dbms SQL backend key.
	 * @return string Comma-separated assignment fragment.
	 */
	private function versionedUpdateFieldsForDbms(array $fields, string $versionColumn, string $dbms): string {
		$assignments=[];
		foreach(array_keys($fields) as $column){
			$assignments[]=$this->quoteCounterIdentifier((string)$column, $dbms).'=?';
		}
		$version=$this->quoteCounterIdentifier($versionColumn, $dbms);
		$assignments[]=$version.'='.$version.'+?';
		return implode(',', $assignments);
	}

	/**
	 * Validates the optimistic-lock version bump.
	 *
	 * Version bumps must advance the stored version; zero or negative bumps
	 * would make stale writes indistinguishable from successful writes.
	 *
	 * @param string $versionColumn Version column used for diagnostics.
	 * @param int $bump Requested version increment.
	 * @return int Validated positive bump.
	 */
	private function resolvedVersionBump(string $versionColumn, int $bump): int {
		if($bump<=0){
			throw SqlError::invalidFieldPayload("table {$this->table}", "Version bump for '{$versionColumn}' must be greater than zero.");
		}
		return $bump;
	}

	/**
	 * Validates the expected version used by optimistic-lock updates.
	 *
	 * Expected versions may start at zero but cannot be negative because the
	 * value is compared directly against persisted row state.
	 *
	 * @param string $versionColumn Version column used for diagnostics.
	 * @param int $expectedVersion Expected current version.
	 * @return int Validated expected version.
	 */
	private function resolvedExpectedVersion(string $versionColumn, int $expectedVersion): int {
		if($expectedVersion<0){
			throw SqlError::invalidFieldPayload("table {$this->table}", "Expected version for '{$versionColumn}' must be greater than or equal to zero.");
		}
		return $expectedVersion;
	}

	/**
	 * Ensures callers do not manually write the managed version column.
	 *
	 * `updateWithVersion()` owns version advancement so the compare-and-bump
	 * operation remains atomic and predictable.
	 *
	 * @param array<string,mixed> $fields Validated update fields.
	 * @param string $versionColumn Managed version column.
	 * @return void
	 */
	private function assertVersionColumnNotInFields(array $fields, string $versionColumn): void {
		if(array_key_exists($versionColumn, $fields)){
			throw SqlError::invalidFieldPayload("table {$this->table}", "Version column '{$versionColumn}' is managed by updateWithVersion().");
		}
	}

	/**
	 * Builds one DBMS-specific counter assignment.
	 *
	 * The counter column appears on both sides of the assignment and the amount
	 * remains a bound parameter, preserving arithmetic updates without exposing
	 * raw values in SQL fragments.
	 *
	 * @param string $column SQL-ready counter column.
	 * @param string $operator SQL arithmetic operator.
	 * @param string $dbms SQL backend key.
	 * @return string Counter assignment fragment.
	 */
	private function counterFieldForDbms(string $column, string $operator, string $dbms): string {
		$quoted=$this->quoteCounterIdentifier($column, $dbms);
		return $quoted.'='.$quoted.$operator.'?';
	}

	/**
	 * Quotes a dotted identifier for counter and versioned update fragments.
	 *
	 * MySQL uses backticks while PostgreSQL and SQLite use double quotes. Each
	 * dotted identifier part is escaped independently so qualified columns keep
	 * their structure.
	 *
	 * @param string $identifier SQL identifier or dotted identifier.
	 * @param string $dbms SQL backend key.
	 * @return string Quoted identifier.
	 */
	private function quoteCounterIdentifier(string $identifier, string $dbms): string {
		$quote=$dbms==='mysql' ? '`' : '"';
		$escaped=$quote.$quote;
		$parts=explode('.', $identifier);
		foreach($parts as $index=>$part){
			$parts[$index]=$quote.str_replace($quote, $escaped, $part).$quote;
		}
		return implode('.', $parts);
	}

	/**
	 * Executes an insert and wraps the raw SQL result.
	 *
	 * Field normalization includes schema validation and money expansion before
	 * the insert helper runs. Missing structure can be hydrated and retried by
	 * `withSchemaHydration()`.
	 *
	 * @param array<string,mixed> $fields Write fields.
	 * @param bool|array|null $clearCache Write invalidation policy.
	 * @return MutationResult Insert mutation result.
	 */
	private function insertMutationResult(array $fields, bool|array|null $clearCache): MutationResult {
		return MutationResult::fromRaw(
			'insert',
			$this->withSchemaHydration(fn(): mixed => sql_insert($this->table, $this->resolvedFields($fields), null, $clearCache)),
			$this->mutationContext()
		);
	}

	/**
	 * Clones the query and constrains it by an attribute map.
	 *
	 * This powers first-or-create and update-or-create flows where a lookup
	 * query must use the same table state while adding equality predicates for
	 * the caller's unique attributes.
	 *
	 * @param array<string,mixed> $attributes Attribute equality predicates.
	 * @return self Cloned query constrained by the attributes.
	 */
	private function queryForAttributes(array $attributes): self {
		$query=clone $this;
		foreach($attributes as $column=>$value){
			$query->whereEq((string)$column, $value);
		}
		return $query;
	}

	/**
	 * Throws when a mutation result represents failure.
	 *
	 * Failure messages prefer the structured result error, then fall back to the
	 * SQL error formatter using the operation and context stored on the result.
	 *
	 * @param MutationResult $result Mutation result to validate.
	 * @return void
	 */
	private function assertMutationSucceeded(MutationResult $result): void {
		if($result->failed()){
			throw new \RuntimeException($result->errorMessage() ?? SqlError::mutationErrorMessage($result->operation(), $result->context()));
		}
	}

	/**
	 * Reads at most two rows for sole-result enforcement.
	 *
	 * Sole reads need to distinguish no rows, exactly one row, and multiple
	 * rows. Limiting to two keeps the check cheap while preserving enough
	 * information to raise the correct exception.
	 *
	 * @param array|string|null $columns Optional selected columns.
	 * @param bool|array|string|null $caching Optional read cache policy.
	 * @return list<array<string,mixed>> Up to two transformed rows.
	 */
	private function singleResultRows(array|string|null $columns=null, bool|array|string|null $caching=null): array {
		return (clone $this)->limit(2)->get($columns, $caching);
	}

	/**
	 * Adds a money-aware comparison predicate.
	 *
	 * CurrencyBridge normalizes money mappings and comparable values, then the
	 * query applies a currency predicate when the amount is paired with a stored
	 * currency column. Amount comparisons are delegated to the inherited
	 * predicate helpers.
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
			"table {$this->table}"
		);
		$comparison=CurrencyBridge::normalizeComparableValue(
			$value,
			$mapping['currency'],
			"table {$this->table}",
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
				"table {$this->table}",
				$mapping['amount_column'],
				"Unsupported money comparison operator '{$operator}'."
			),
		};
	}

	/**
	 * Converts hydrator state into a fingerprint-safe descriptor.
	 *
	 * Objects become class names, scalar and array values are preserved, and
	 * unusual values fall back to their debug type so query fingerprints can
	 * include hydration intent without serializing closures or objects.
	 *
	 * @param mixed $hydrator Hydrator candidate.
	 * @return mixed class name, scalar, array, null, or debug type suitable for query fingerprint data.
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
	 * Hashes a query fingerprint data deterministically.
	 *
	 * JSON is preferred for stable scalar representation; serialization is used
	 * only when JSON encoding fails. The resulting SHA-1 is a compact identity
	 * for cache keys, queue state, and diagnostics.
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
	 * Builds the column list needed for a pluck operation.
	 *
	 * The value column is always selected, and an optional key column is added
	 * once. Empty fragments are removed before the read is executed.
	 *
	 * @param string $column Value column.
	 * @param ?string $keyColumn Optional key column.
	 * @return list<string> Unique non-empty columns.
	 */
	private function pluckColumns(string $column, ?string $keyColumn=null): array {
		$columns=[trim($column)];
		if($keyColumn!==null && trim($keyColumn)!==''){
			$columns[]=trim($keyColumn);
		}
		return array_values(array_unique(array_filter($columns, static fn(string $value): bool => $value!=='')));
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
	 * Resolves the key column used for keyset chunking.
	 *
	 * A provided key column takes precedence; otherwise the table primary key is
	 * required. Schema-backed queries resolve the key through schema metadata so
	 * aliases and validation stay consistent with normal reads.
	 *
	 * @param ?string $keyColumn Optional key column override.
	 * @return string SQL-ready key column.
	 */
	private function resolvedKeyColumn(?string $keyColumn=null): string {
		$keyColumn=$keyColumn!==null && trim($keyColumn)!=='' ? trim($keyColumn) : $this->primaryKey;
		if($keyColumn===null || trim($keyColumn)===''){
			throw SqlError::missingPrimaryKeyForTable($this->table, 'perform keyset chunking');
		}
		return $this->resolvedColumns($keyColumn);
	}

	/**
	 * Normalizes keyset chunking direction.
	 *
	 * Only an explicit `DESC` request produces descending order; every other
	 * value defaults to ascending to keep chunk callers predictable.
	 *
	 * @param string $direction Requested direction.
	 * @return string `ASC` or `DESC`.
	 */
	private function normalizedKeysetDirection(string $direction): string {
		return strtoupper(trim($direction))==='DESC' ? 'DESC' : 'ASC';
	}

	/**
	 * Reads the final key value from a chunk of rows.
	 *
	 * Keyset pagination advances from the last row in the current chunk. A
	 * missing key column is treated as a runtime error because continuing would
	 * risk repeating or skipping records.
	 *
	 * @param list<array<string,mixed>> $rows Current chunk rows.
	 * @param string $keyColumn Key column selected by the chunk query.
	 * @return mixed value from the selected key column on the final chunk row.
	 */
	private function lastKeyFromRows(array $rows, string $keyColumn): mixed {
		$lastRow=end($rows);
		if(!is_array($lastRow) || !array_key_exists($keyColumn, $lastRow)){
			throw new \RuntimeException("Keyset chunking could not read key column '{$keyColumn}' from the selected rows.");
		}
		return $lastRow[$keyColumn];
	}

	/**
	 * Extracts values from rows for pluck-style result shapes.
	 *
	 * Without a key column, values are appended in row order. With a key column,
	 * rows missing a non-null key are skipped and values are keyed by the string
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
	 * Re-keys row arrays by a selected column.
	 *
	 * Non-row values and rows without a non-null key are skipped. Later rows
	 * with the same string key overwrite earlier rows, matching common map
	 * semantics for key-by operations.
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
	 * The context includes table identity, primary key, selected columns, query
	 * debug state, and caller-supplied details. Empty values are removed to keep
	 * exception data compact.
	 *
	 * @param array|string|null $columns Column selector active for the failing read.
	 * @param array<string,mixed> $extra Additional context values.
	 * @return array<string,mixed> Filtered exception context.
	 */
	private function notFoundContext(array|string|null $columns=null, array $extra=[]): array {
		$context=array_merge(
			[
				'table'=>$this->table,
				'primary_key'=>$this->primaryKey,
				'columns'=>$columns ?? $this->columns,
			],
			$this->debugContext(),
			$extra
		);
		return array_filter($context, static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	/**
	 * Reports a guardrail warning when named read caches lack write invalidation.
	 *
	 * The warning is emitted only when the query has named read cache scopes and
	 * the write path is not clearing all caches or any matching named scopes.
	 * This catches stale-cache risks without blocking the mutation.
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
			'Named read caches are attached to this table query, but the write path has no invalidation policy.',
			[
				'operation'=>$operation,
				'table'=>$this->table,
				'primary_key'=>$this->primaryKey,
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
	 * removed. The result is de-duplicated while preserving the first-seen order.
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

	/**
	 * Executes a scalar aggregate query.
	 *
	 * Ordering and paging are removed before compiling predicates because scalar
	 * aggregates operate on the full constrained set. The aggregate column and
	 * function are validated before the SQL helper receives the fragment.
	 *
	 * @param string $function Aggregate function name.
	 * @param string $column Aggregate column or `*` where permitted.
	 * @param bool|array|string|null $caching Optional read cache policy.
	 * @param bool $distinct Whether to aggregate distinct values.
	 * @return mixed Normalized aggregate value, false on SQL failure, or null when absent.
	 */
	private function aggregateValue(
		string $function,
		string $column='*',
		bool|array|string|null $caching=null,
		bool $distinct=false
	): mixed {
		$function=$this->normalizeAggregateFunction($function);
		$column=$this->aggregateColumn($column, $function, $function==='COUNT');
		$compiled=(clone $this)
			->withoutOrdering()
			->withoutPaging()
			->compile(false);
		$result=$this->withSchemaHydration(fn(): mixed => sql_select(
				$function.'('.($distinct ? 'DISTINCT ' : '').$column.') AS aggregate_value',
				$this->table,
				$compiled['params'],
				$compiled['vars'],
				false,
				$caching ?? $this->caching
			)
		);
		if($result===false){
			return false;
		}
		if(!is_array($result) || !array_key_exists('aggregate_value', $result)){
			return null;
		}
		return $this->normalizeAggregateResult($function, $result['aggregate_value']);
	}

	/**
	 * Validates and normalizes a supported aggregate function.
	 *
	 * Aggregate helpers intentionally accept only the small function set that
	 * TableQuery knows how to normalize across scalar and grouped results.
	 *
	 * @param string $function Requested aggregate function.
	 * @return string Uppercase aggregate function.
	 */
	private function normalizeAggregateFunction(string $function): string {
		$function=strtoupper(trim($function));
		$allowed=['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
		if(!in_array($function, $allowed, true)){
			throw SqlError::invalidAggregateFunction("table {$this->table}", $function, $allowed);
		}
		return $function;
	}

	/**
	 * Resolves and validates an aggregate column.
	 *
	 * Star is accepted only for aggregate calls that explicitly allow it, such
	 * as count. Schema-backed queries resolve the column through schema metadata;
	 * schema-less queries require a simple validated identifier.
	 *
	 * @param string $column Requested aggregate column.
	 * @param string $function Aggregate function used for diagnostics.
	 * @param bool $allowStar Whether `*` is valid for this aggregate.
	 * @return string SQL-ready aggregate column.
	 */
	private function aggregateColumn(string $column, string $function, bool $allowStar=false): string {
		$column=trim($column);
		if($column===''){
			throw SqlError::invalidAggregateColumn("table {$this->table}", $function, $column, $allowStar);
		}
		if($column==='*'){
			if($allowStar){
				return '*';
			}
			throw SqlError::invalidAggregateColumn("table {$this->table}", $function, $column, false);
		}
		if($this->schema!==null){
			$resolved=$this->schema->columns($column);
			return is_string($resolved) ? $resolved : (string)($resolved[0] ?? '');
		}
		return $this->normalizeIdentifier($column);
	}

	/**
	 * Casts aggregate results into predictable PHP scalar shapes.
	 *
	 * Counts become integers when numeric, sums and averages become integers or
	 * floats based on their textual representation, and min/max values are left
	 * as returned by the database because their column type may be non-numeric.
	 *
	 * @param string $function Normalized aggregate function.
	 * @param mixed $value Raw aggregate value.
	 * @return mixed count as int, numeric SUM/AVG as int or float, MIN/MAX unchanged, or null/false markers.
	 */
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

	/**
	 * Normalizes group-by columns for grouped aggregate queries.
	 *
	 * Empty group columns are rejected. Schema-backed queries resolve each
	 * column through schema metadata, while schema-less queries validate simple
	 * identifiers before the group clause is built.
	 *
	 * @param string|array<int,string|mixed> $groupColumns Requested group columns.
	 * @return list<string> Unique SQL-ready group columns.
	 */
	private function groupColumns(string|array $groupColumns): array {
		if(is_string($groupColumns)){
			$groupColumns=[$groupColumns];
		}
		$normalized=[];
		foreach($groupColumns as $groupColumn){
			$groupColumn=trim((string)$groupColumn);
			if($groupColumn===''){
				throw SqlError::invalidIdentifier('group by', $groupColumn, $this->table);
			}
			if($this->schema!==null){
				$resolved=$this->schema->columns($groupColumn);
				$normalized[]=is_string($resolved) ? $resolved : (string)($resolved[0] ?? '');
				continue;
			}
			$normalized[]=$this->normalizeIdentifier($groupColumn);
		}
		return array_values(array_unique(array_filter($normalized, static fn(string $value): bool => $value!=='')));
	}

	/**
	 * Appends a SQL clause to compiled parameter fragments.
	 *
	 * The helper preserves the indentation expected by Dataphyre SQL helpers
	 * when grouped aggregate queries add a `GROUP BY` clause after inherited
	 * where/order fragments.
	 *
	 * @param string $params Existing compiled SQL parameter fragment.
	 * @param string $clause Clause to append.
	 * @return string Combined parameter fragment.
	 */
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

	/**
	 * Normalizes aggregate values inside grouped aggregate rows.
	 *
	 * Rows without an `aggregate_value` key are preserved unchanged so callers
	 * can inspect unexpected backend rows, while valid aggregate cells are
	 * cast with the same rules as scalar aggregate reads.
	 *
	 * @param array<int,array<string,mixed>|mixed> $rows Grouped aggregate rows.
	 * @param string $function Normalized aggregate function.
	 * @return array<int,array<string,mixed>|mixed> Rows with normalized aggregate values.
	 */
	private function normalizeAggregateRows(array $rows, string $function): array {
		foreach($rows as $index=>$row){
			if(!is_array($row) || !array_key_exists('aggregate_value', $row)){
				continue;
			}
			$rows[$index]['aggregate_value']=$this->normalizeAggregateResult($function, $row['aggregate_value']);
		}
		return $rows;
	}

	/**
	 * Converts grouped aggregate rows into a map keyed by one group column.
	 *
	 * Rows missing the requested group column are skipped. Values are taken from
	 * `aggregate_value`, which should already have been normalized by
	 * `normalizeAggregateRows()`.
	 *
	 * @param string $groupColumn Group column used as the map key.
	 * @param array<int,array<string,mixed>|mixed> $rows Grouped aggregate rows.
	 * @return array<string,mixed> Aggregate values keyed by group value.
	 */
	private function groupedAggregateMap(string $groupColumn, array $rows): array {
		$groupColumn=trim($groupColumn);
		$mapped=[];
		foreach($rows as $row){
			if(!is_array($row) || !array_key_exists($groupColumn, $row) || $row[$groupColumn]===null){
				continue;
			}
			$mapped[(string)$row[$groupColumn]]=$row['aggregate_value'] ?? null;
		}
		return $mapped;
	}
}
