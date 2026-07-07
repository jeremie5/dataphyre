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

/**
 * Base class for Dataphyre table repositories.
 *
 * Repositories bind a table location to schema metadata, default query
 * constraints, row hydration, money mappings, relations, cache policy, and
 * mutation guardrails. Static methods keep repository use cheap in request
 * lifecycles while still centralizing validation before SQL kernel calls are made.
 */
abstract class TableRepository {

	/**
	 * Per-repository normalized money mapping cache.
	 *
	 * The raw hook payload is retained so dynamic subclasses can change their
	 * mapping definitions without stale normalized output.
	 *
	 * @var array<class-string, array{raw: array<int|string, mixed>, resolved: array<int, array<string, mixed>>}>
	 */
	private static array $resolvedMoneyColumnsCache=[];

	/**
	 * Per-repository normalized stored-money mapping cache.
	 *
	 * @var array<class-string, array{raw: array<int|string, mixed>, resolved: array<int, array<string, mixed>>}>
	 */
	private static array $resolvedStoredMoneyColumnsCache=[];

	/**
	 * Returns the SQL table identifier owned by this repository.
	 *
	 * The value is passed to the Dataphyre SQL kernel and schema helpers, so
	 * subclasses should return a stable table name or qualified table name rather
	 * than user-controlled input.
	 *
	 * @return string Repository table name used for reads, writes, cache invalidation, and diagnostics.
	 */
	abstract protected static function table(): string;

	/**
	 * Declares optional schema metadata for projections, fields, and primary keys.
	 *
	 * A null schema keeps the repository usable with raw column lists, but disables
	 * schema-backed projections, primary-key inference, and optional structure
	 * hydration.
	 *
	 * @return TableSchema|null Schema contract for the repository table, or null for untyped tables.
	 */
	protected static function schema(): ?TableSchema {
		return null;
	}

	/**
	 * Builds the default query specification for repository reads and writes.
	 *
	 * Subclasses can return tenant scopes, soft-delete filters, ordering, or other
	 * repository-wide constraints. Callers may still merge additional QuerySpec
	 * values for individual operations.
	 *
	 * @return QuerySpec Default repository query constraints.
	 */
	protected static function spec(): QuerySpec {
		return new QuerySpec();
	}

	/**
	 * Declares an optional row hydrator override.
	 *
	 * Supported values are null, a RecordHydrator instance, a callable row mapper, a
	 * RecordHydrator class, or a record class name. Invalid strings fail during
	 * resolvedHydrator() before rows are transformed.
	 *
	 * @return mixed Hydrator declaration used by resolvedHydrator().
	 */
	protected static function hydrator(): mixed {
		return null;
	}

	/**
	 * Declares the explicit record class for default hydration.
	 *
	 * When present, the class is instantiated by ClassRecordHydrator with repository
	 * and primary-key metadata so returned records can resolve relations and perform
	 * write-back helpers.
	 *
	 * @return class-string|null Record class name, or null to infer from repository naming.
	 */
	protected static function recordClass(): ?string {
		return null;
	}

	/**
	 * Declares transient money mappings applied to repository rows.
	 *
	 * Mappings are normalized by CurrencyBridge and applied after SQL rows are read.
	 * They expose money-shaped values without changing the stored amount/currency
	 * columns.
	 *
	 * @return array<int|string, mixed> Money mapping definitions.
	 */
	protected static function moneyColumns(): array {
		return [];
	}

	/**
	 * Declares stored-money mappings applied to repository rows.
	 *
	 * Stored-money mappings read persisted amount, currency, rate, source, and
	 * timestamp columns into richer values while preserving the raw row contract for
	 * write operations.
	 *
	 * @return array<int|string, mixed> Stored-money mapping definitions.
	 */
	protected static function storedMoneyColumns(): array {
		return [];
	}

	/**
	 * Infers a record class from the repository class name.
	 *
	 * `App\Repository\UserRepository` first maps to `App\Record\UserRecord` when
	 * the namespace follows the repository/record convention, then falls back to a
	 * sibling `UserRecord`. Missing classes return null so hydration can fall back to
	 * generic Record objects.
	 *
	 * @return class-string|null Inferred record class, or null when no convention match exists.
	 */
	protected static function inferredRecordClass(): ?string {
		$repositoryClass=static::class;
		$repositoryShortName=substr($repositoryClass, (int)strrpos($repositoryClass, '\\')+1);
		if(!str_ends_with($repositoryShortName, 'Repository')){
			return null;
		}
		$baseName=substr($repositoryShortName, 0, -10);
		if($baseName===''){
			return null;
		}
		$candidates=[];
		if(str_contains($repositoryClass, '\\Repository\\')){
			$candidates[]=preg_replace(
				'/\\\\Repository\\\\([^\\\\]+)Repository$/',
				'\\\\Record\\\\$1Record',
				$repositoryClass
			);
		}
		$namespace=substr($repositoryClass, 0, (int)strrpos($repositoryClass, '\\'));
		$candidates[]=$namespace.'\\'.$baseName.'Record';
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

	/**
	 * Starts a fluent repository query for this repository class.
	 *
	 * The query object keeps the repository boundary attached so table name,
	 * defaults, hydration, cache policy, and write guards remain centralized.
	 *
	 * @return RepositoryQuery Query builder bound to this repository.
	 */
	public static function query(): RepositoryQuery {
		return new RepositoryQuery(static::class);
	}

	/**
	 * Exposes the repository table name to callers that need metadata.
	 *
	 * This is the public wrapper around table(); it does not validate or quote the
	 * identifier and should be treated as repository-owned configuration.
	 *
	 * @return string Repository table name.
	 */
	public static function tableName(): string {
		return static::table();
	}

	/**
	 * Defines an inverse relation from this repository to a parent repository.
	 *
	 * The foreign key lives on this repository's table. The optional owner key
	 * defaults according to Relation::belongsTo(), normally the related repository's
	 * primary key.
	 *
	 * @param class-string<TableRepository> $relatedRepository Related repository class.
	 * @param string $foreignKey Column on this repository table.
	 * @param string|null $ownerKey Column on the related repository table.
	 * @return Relation Belongs-to relation definition.
	 */
	protected static function belongsTo(string $relatedRepository, string $foreignKey, ?string $ownerKey=null): Relation {
		return Relation::belongsTo($relatedRepository, $foreignKey, $ownerKey);
	}

	/**
	 * Defines a one-to-one relation from this repository to a related repository.
	 *
	 * The related table owns the foreign key. When no local key is supplied, this
	 * repository must expose a primary key through schema metadata or the relation
	 * fails before query construction.
	 *
	 * @param class-string<TableRepository> $relatedRepository Related repository class.
	 * @param string $foreignKey Column on the related repository table.
	 * @param string|null $localKey Column on this repository table.
	 * @return Relation Has-one relation definition.
	 * @throws SqlError When no local key is provided and the repository has no primary key.
	 */
	protected static function hasOne(string $relatedRepository, string $foreignKey, ?string $localKey=null): Relation {
		$localKey ??= static::primaryKey();
		if($localKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'define hasOne(...) relation local key');
		}
		return Relation::hasOne($relatedRepository, $foreignKey, $localKey);
	}

	/**
	 * Defines a one-to-many relation from this repository to a related repository.
	 *
	 * The related table owns the foreign key. When no local key is supplied, this
	 * repository must expose a primary key through schema metadata or the relation
	 * fails before query construction.
	 *
	 * @param class-string<TableRepository> $relatedRepository Related repository class.
	 * @param string $foreignKey Column on the related repository table.
	 * @param string|null $localKey Column on this repository table.
	 * @return Relation Has-many relation definition.
	 * @throws SqlError When no local key is provided and the repository has no primary key.
	 */
	protected static function hasMany(string $relatedRepository, string $foreignKey, ?string $localKey=null): Relation {
		$localKey ??= static::primaryKey();
		if($localKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'define hasMany(...) relation local key');
		}
		return Relation::hasMany($relatedRepository, $foreignKey, $localKey);
	}

	/**
	 * Returns the schema-backed primary key name when available.
	 *
	 * Repositories without schema metadata may still run ad hoc queries, but helpers
	 * that infer local keys, ids, or record write-back context will require explicit
	 * keys.
	 *
	 * @return string|null Primary key column name, or null when no schema is attached.
	 */
	public static function primaryKey(): ?string {
		return static::schema()?->primaryKey();
	}

	/**
	 * Resolves a named schema projection through the public repository boundary.
	 *
	 * Projection names are delegated to the TableSchema. Repositories without schema
	 * metadata cannot expose named projections and fail closed.
	 *
	 * @param string $name Projection name defined by the schema.
	 * @return array<int, string> Projection column list.
	 * @throws SqlError When the repository has no schema or the projection is unknown.
	 */
	public static function projectionNamed(string $name): array {
		return static::projection($name);
	}

	/**
	 * Resolves a named relation method into a Relation object.
	 *
	 * Names must be simple framework identifiers. The target method must be public,
	 * static, require no parameters, and return a Relation, preventing user-provided
	 * relation names from becoming arbitrary method calls.
	 *
	 * @param string $name Relation method name.
	 * @return Relation Resolved relation definition.
	 * @throws SqlError When the name is invalid, the method is not a relation surface, or the return value is not a Relation.
	 */
	public static function relationNamed(string $name): Relation {
		$name=trim($name);
		if($name==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)!==1){
			throw SqlError::invalidIdentifier('repository relation', $name, static::class);
		}
		if(!method_exists(static::class, $name)){
			throw SqlError::invalidRelation(static::class, $name, "Repository relation method '{$name}' was not found.");
		}
		$method=new \ReflectionMethod(static::class, $name);
		if(!$method->isPublic() || !$method->isStatic() || $method->getNumberOfRequiredParameters()>0){
			throw SqlError::invalidRelation(static::class, $name, "Repository relation method '{$name}' must be public, static, and require no parameters.");
		}
		$relation=$method->invoke(null);
		if(!$relation instanceof Relation){
			throw SqlError::invalidRelation(static::class, $name, "Repository relation method '{$name}' did not return a Relation.");
		}
		return $relation;
	}

	/**
	 * Builds the default row hydrator for this repository.
	 *
	 * Explicit recordClass() wins over naming inference. If neither is available,
	 * rows hydrate to generic Record objects with repository and primary-key metadata
	 * attached for relation and write-back helpers.
	 *
	 * @return RecordHydrator Default row-to-record hydrator.
	 */
	protected static function defaultHydrator(): RecordHydrator {
		$recordClass=static::recordClass() ?? static::inferredRecordClass();
		if($recordClass!==null && trim($recordClass)!==''){
			return new ClassRecordHydrator(trim($recordClass), static::class, static::primaryKey());
		}
		return new RecordObjectHydrator(static::class, static::primaryKey());
	}

	/**
	 * Resolves a caller or repository hydrator declaration.
	 *
	 * The method accepts runtime overrides before falling back to hydrator(). It
	 * wraps callables, instantiates hydrator classes, treats other class strings as
	 * record classes, and rejects empty, missing, or unsupported hydrator values
	 * before row data is touched.
	 *
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @return RecordHydrator Concrete hydrator used by read helpers.
	 * @throws SqlError When the hydrator declaration is invalid or references a missing class.
	 */
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

	/**
	 * Normalizes read column selectors through schema metadata when present.
	 *
	 * Schema-backed repositories can use named projections and validated column
	 * lists. Untyped repositories fall back to QuerySpec column normalization so raw
	 * `*` and explicit columns still work.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @return string|array Normalized SQL column selector.
	 */
	protected static function columns(array|string $columns='*'): string|array {
		$schema=static::schema();
		if($schema!==null){
			return $schema->columns($columns);
		}
		return QuerySpec::columns($columns);
	}

	/**
	 * Normalizes write fields before insert, update, upsert, and counters.
	 *
	 * Money mappings run before schema field validation so value objects can expand
	 * into storage columns. Untyped repositories require associative payloads and
	 * conservative identifier names, failing before any mutation is sent to SQL.
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @return array<string, mixed> Normalized write payload.
	 * @throws SqlError When the payload is empty, non-associative, or contains invalid field names.
	 */
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

	/**
	 * Resolves a named projection from the repository schema.
	 *
	 * This protected helper is the single failure point for schema-less projection
	 * access, keeping public projectionNamed() and internal reads consistent.
	 *
	 * @param string $name Projection name defined by the schema.
	 * @return array<int, string> Projection column list.
	 * @throws SqlError When schema metadata is missing or the schema rejects the projection.
	 */
	protected static function projection(string $name): array {
		$schema=static::schema();
		if($schema===null){
			throw SqlError::missingSchemaForProjection(static::class, $name, static::table());
		}
		return $schema->projection($name);
	}

	/**
	 * Reports whether missing table structure may be hydrated from schema metadata.
	 *
	 * This is opt-in through TableSchema. When enabled, selected write/read helpers
	 * can retry after asking the SQL kernel to create or repair missing structure.
	 *
	 * @return bool True when schema-driven table hydration is enabled.
	 */
	protected static function hydrateTable(): bool {
		return static::schema()?->hydrateTable() ?? false;
	}

	/**
	 * Runs an SQL operation with one schema-hydration retry.
	 *
	 * The first failure is preserved unless the SQL kernel can hydrate missing table
	 * structure from the repository schema. On successful hydration, table caches and
	 * last-query errors are cleared before the operation is attempted exactly once
	 * more.
	 *
	 * @param callable $operation SQL operation callback.
	 * @return mixed First successful operation result, false when the original failure cannot be repaired, or the single retry result after schema hydration.
	 */
	protected static function withSchemaHydration(callable $operation): mixed {
		\dataphyre\sql::clear_last_query_error();
		$result=$operation();
		if($result!==false){
			return $result;
		}
		if(\dataphyre\sql::hydrate_missing_structure_from_definition(static::table())===false){
			return $result;
		}
		\dataphyre\sql::invalidate_cache(static::table());
		\dataphyre\sql::clear_last_query_error();
		return $operation();
	}

	/**
	 * Returns the repository's default read-cache policy.
	 *
	 * The default delegates to DB so repository reads share the runtime cache policy
	 * unless subclasses override it for a table-specific cache strategy.
	 *
	 * @return array<string,mixed> Dataphyre read-cache policy marker.
	 */
	protected static function defaultReadCaching(): array {
		return DB::defaultReadCaching();
	}

	/**
	 * Resolves the cache policy for a read helper.
	 *
	 * Explicit null means "use the repository default"; false, strings, and arrays
	 * are passed through to the SQL kernel unchanged.
	 *
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return bool|array|string|null Resolved read-cache policy.
	 */
	protected static function resolveReadCaching(bool|array|string|null $caching=null): bool|array|string|null {
		return $caching ?? static::defaultReadCaching();
	}

	/**
	 * Returns the repository's default write invalidation policy.
	 *
	 * False keeps write helpers from invalidating caches unless callers or subclasses
	 * opt in. Arrays are accepted by resolveWriteInvalidation() for table or tag
	 * invalidation payloads.
	 *
	 * @return bool|array|null Default write invalidation policy.
	 */
	protected static function defaultWriteInvalidation(): bool|array|null {
		return false;
	}

	/**
	 * Reports whether broad write helpers must include a WHERE scope.
	 *
	 * Subclasses can opt in when accidental table-wide update/delete operations are
	 * too risky for the domain. The guard is enforced by assertWriteScope() before
	 * the mutation reaches the SQL kernel.
	 *
	 * @return bool True when update/delete helpers require compiled WHERE parameters.
	 */
	protected static function requireWriteWhere(): bool {
		return false;
	}

	/**
	 * Exposes the repository write-scope policy publicly.
	 *
	 * Runtime callers can use this to understand whether table-wide writes are
	 * blocked by the repository contract.
	 *
	 * @return bool True when update/delete helpers require WHERE constraints.
	 */
	public static function requiresWriteWhere(): bool {
		return static::requireWriteWhere();
	}

	/**
	 * Expands repository write fields through configured money mappings.
	 *
	 * Money value objects are converted to their storage-column payloads before
	 * schema validation or raw identifier validation. The method is pure with
	 * respect to persistence; it only prepares a field array for the caller's
	 * mutation.
	 *
	 * @param array<string,mixed> $fields Write fields before money and schema normalization.
	 * @return array<string, mixed> Field payload after money and stored-money expansion.
	 */
	protected static function normalizedWriteFields(array $fields): array {
		return CurrencyBridge::expandWriteFields(
			$fields,
			static::resolvedMoneyColumns(),
			static::resolvedStoredMoneyColumns(),
			static::class
		);
	}

	/**
	 * Resolves cache invalidation for a write helper.
	 *
	 * Runtime bridges are booted before returning the policy so currency/cache
	 * integration has its side effects registered before the mutation is dispatched.
	 * Explicit null means "use the repository default"; false and arrays pass
	 * through unchanged.
	 *
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return bool|array|null Resolved cache invalidation policy.
	 */
	protected static function resolveWriteInvalidation(bool|array|null $clearCache=null): bool|array|null {
		DB::bootRuntimeBridges();
		return $clearCache ?? static::defaultWriteInvalidation();
	}

	/**
	 * Reads multiple raw rows through the repository query layer.
	 *
	 * The helper compiles the supplied spec or repository default, normalizes
	 * columns, applies read caching, and wraps the SQL call in the optional
	 * schema-hydration retry. It returns raw SQL-kernel rows; public hydrated helpers
	 * perform row-to-record conversion separately.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int,array<string,mixed>>|false Raw SQL rows from the kernel, or false when selection fails.
	 */
	protected static function selectMany(array|string $columns='*', ?QuerySpec $spec=null, bool|array|string|null $caching=null): mixed {
		$spec ??= static::spec();
		$compiled=$spec->compile();
		return static::withSchemaHydration(static fn(): mixed => sql_select(
				static::columns($columns),
				static::table(),
				$compiled['params'],
				$compiled['vars'],
				true,
				static::resolveReadCaching($caching)
			)
		);
	}

	/**
	 * Reads one raw row through the repository query layer.
	 *
	 * This mirrors selectMany() but requests a single row from the SQL kernel.
	 * Missing rows and SQL failures preserve the lower-level return contract for
	 * higher-level first/sole/orFail helpers to interpret.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<string,mixed>|false|null Raw SQL row from the kernel, false on SQL failure, or null when no row is available.
	 */
	protected static function selectOne(array|string $columns='*', ?QuerySpec $spec=null, bool|array|string|null $caching=null): mixed {
		$spec ??= static::spec();
		$compiled=$spec->compile();
		return static::withSchemaHydration(static fn(): mixed => sql_select(
				static::columns($columns),
				static::table(),
				$compiled['params'],
				$compiled['vars'],
				false,
				static::resolveReadCaching($caching)
			)
		);
	}

	/**
	 * Counts rows through the repository query layer.
	 *
	 * Ordering and paging are stripped before compilation so the count reflects the
	 * filtered set rather than the current page. The SQL kernel result is preserved:
	 * integer counts are returned on success, while false/null indicate lower-level
	 * query failure.
	 *
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int|bool|null Row count from the SQL kernel, or false/null on failure.
	 */
	protected static function countWhere(?QuerySpec $spec=null, bool|array|string|null $caching=null): int|bool|null {
		$spec=$spec!==null ? (clone $spec) : static::spec();
		$spec=$spec->withoutOrdering()->withoutPaging();
		$compiled=$spec->compile(false);
		return static::withSchemaHydration(static fn(): mixed => sql_count(
				static::table(),
				$compiled['params'],
				$compiled['vars'],
				static::resolveReadCaching($caching)
			)
		);
	}

	/**
	 * Inserts one normalized row through the SQL kernel.
	 *
	 * Callers are expected to run fields() and resolveWriteInvalidation() before
	 * entering this lower-level helper. Schema hydration may retry once when the
	 * table definition can repair missing structure.
	 *
	 * @param array<string, mixed> $fields Normalized insert fields.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return mixed Raw insert result, usually an inserted id, true-ish driver value, false, or null.
	 */
	protected static function insertOne(array $fields, bool|array|null $clearCache=false): mixed {
		return static::withSchemaHydration(static fn(): mixed => sql_insert(static::table(), $fields, null, $clearCache));
	}

	/**
	 * Updates rows matched by a compiled repository scope.
	 *
	 * This helper assumes fields and write-scope guards have already been applied by
	 * the public mutation surface. It preserves the SQL kernel affected-row/failure
	 * contract for MutationResult normalization by callers.
	 *
	 * @param array<string, mixed> $fields Normalized update fields.
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return int|bool|null Affected-row count from the SQL kernel, or false/null on failure.
	 */
	protected static function updateWhere(array $fields, QuerySpec $spec, bool|array|null $clearCache=false): int|bool|null {
		$compiled=$spec->compile(false);
		return static::withSchemaHydration(static fn(): mixed => sql_update(static::table(), $fields, $compiled['params'], $compiled['vars'], $clearCache));
	}

	/**
	 * Applies an atomic counter update to rows matched by a query scope.
	 *
	 * The counter expression is generated by counterFields(), while the amount is
	 * bound as the first SQL variable before the compiled scope variables. Column and
	 * amount validation happens in counterMutation().
	 *
	 * @param string $column Counter column name.
	 * @param string $operator Counter operator, normally + or -.
	 * @param int|float $amount Amount bound into the counter expression.
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return int|bool|null Affected-row count from the SQL kernel, or false/null on failure.
	 */
	protected static function updateCounterWhere(
		string $column,
		string $operator,
		int|float $amount,
		QuerySpec $spec,
		bool|array|null $clearCache=false
	): int|bool|null {
		$compiled=$spec->compile(false);
		return static::withSchemaHydration(static fn(): mixed => sql_update(
				static::table(),
				static::counterFields($column, $operator),
				$compiled['params'],
				array_merge([$amount], $compiled['vars']),
				$clearCache
			)
		);
	}

	/**
	 * Updates rows and increments an optimistic-lock version column.
	 *
	 * Callers include the expected-version predicate in the QuerySpec before this
	 * helper is reached. Field values, version bump, and scope variables are bound in
	 * the order required by versionedUpdateFields().
	 *
	 * @param array<string, mixed> $fields Normalized update fields excluding the version column.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param int $bump Positive version increment.
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return int|bool|null Affected-row count; zero represents a stale version when normalized by callers.
	 */
	protected static function updateVersionWhere(
		array $fields,
		string $versionColumn,
		int $bump,
		QuerySpec $spec,
		bool|array|null $clearCache=false
	): int|bool|null {
		$compiled=$spec->compile(false);
		return static::withSchemaHydration(static fn(): mixed => sql_update(
				static::table(),
				static::versionedUpdateFields($fields, $versionColumn),
				$compiled['params'],
				array_merge(array_values($fields), [$bump], $compiled['vars']),
				$clearCache
			)
		);
	}

	/**
	 * Deletes rows matched by a compiled repository scope.
	 *
	 * Public delete helpers are responsible for enforcing requireWriteWhere() before
	 * this low-level call. The SQL kernel return is preserved for MutationResult
	 * normalization.
	 *
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return int|bool|null Affected-row count from the SQL kernel, or false/null on failure.
	 */
	protected static function deleteWhere(QuerySpec $spec, bool|array|null $clearCache=false): int|bool|null {
		$compiled=$spec->compile(false);
		return static::withSchemaHydration(static fn(): mixed => sql_delete(static::table(), $compiled['params'], $compiled['vars'], $clearCache));
	}

	/**
	 * Returns all matching rows as repository-cast arrays.
	 *
	 * SQL failures and non-array kernel results normalize to an empty list for this
	 * convenience helper. Money mappings are applied by castRepositoryRows().
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int, array<string, mixed>> Repository-cast rows, or an empty list.
	 */
	public static function all(
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		$result=static::selectMany($columns, $spec, $caching);
		return is_array($result) ? static::castRepositoryRows($result) : [];
	}

	/**
	 * Queues a multi-row repository read and normalizes callback payloads.
	 *
	 * The callback always receives an array of repository-cast rows; failed or
	 * non-array SQL results become an empty list through queuedAllRowsResult().
	 * Unlike selectMany(), queued reads do not perform schema-hydration retry.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with normalized repository rows.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
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
			static fn(mixed $result): mixed => $callback(static::queuedAllRowsResult($result))
		);
	}

	/**
	 * Returns the first matching row as a repository-cast array.
	 *
	 * The supplied spec is cloned before adding a one-row limit so caller-owned query
	 * objects are not mutated. Missing rows and SQL failures normalize to null.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<string, mixed>|null First repository-cast row, or null when unavailable.
	 */
	public static function first(
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): ?array {
		$spec=$spec!==null ? (clone $spec) : static::spec();
		$result=static::selectOne($columns, $spec->limit(1), $caching);
		return is_array($result) ? static::castRepositoryRow($result) : null;
	}

	/**
	 * Queues a single-row repository read and normalizes callback payloads.
	 *
	 * The supplied spec is cloned before limit(1) is applied. The callback receives a
	 * repository-cast row or null; failed SQL results do not leak raw driver payloads
	 * into the callback.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with one row or null.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
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
			static fn(mixed $result): mixed => $callback(static::queuedFirstRowResult($result))
		);
	}

	/**
	 * Queues a multi-row read and hydrates rows before invoking the callback.
	 *
	 * Hydration runs inside the queued callback after raw rows have been normalized
	 * and repository-cast. Invalid hydrator declarations fail when the callback is
	 * executed.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with hydrated row values.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueAllHydrated(
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAll(
			$columns,
			$spec,
			static fn(array $result): mixed => $callback(static::hydrateRows($result, $hydrator)),
			$queue,
			$caching
		);
	}

	/**
	 * Queues a multi-row read and returns record objects to the callback.
	 *
	 * This is a semantic alias for queueAllHydrated(); the default hydrator produces
	 * Record instances with repository metadata unless an override is supplied.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with hydrated record values.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueAllRecords(
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAllHydrated($columns, $spec, $callback, $queue, $hydrator, $caching);
	}

	/**
	 * Queues a single-row read and hydrates the row before invoking the callback.
	 *
	 * Missing rows and failed SQL results become null. Hydrator errors surface when
	 * the queued callback executes.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with a hydrated row or null.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueFirstHydrated(
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueFirst(
			$columns,
			$spec,
			static fn(?array $result): mixed => $callback($result!==null ? static::hydrateRow($result, $hydrator) : null),
			$queue,
			$caching
		);
	}

	/**
	 * Queues a single-row read and returns a record object to the callback.
	 *
	 * This is a semantic alias for queueFirstHydrated(); the default hydrator
	 * preserves repository and primary-key metadata for record helper methods.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with a hydrated record or null.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueFirstRecord(
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueFirstHydrated($columns, $spec, $callback, $queue, $hydrator, $caching);
	}

	/**
	 * Queues a first-row read that throws from the callback path when no row exists.
	 *
	 * Successful reads pass the normalized row to the caller callback. Null results
	 * are converted to the same not-found exception shape used by firstOrFail().
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the normalized row.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueFirstOrFail(
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return static::queueFirst(
			$columns,
			$spec,
			static function(?array $result)use($callback, $columns, $spec, $message): mixed{
				if($result!==null){
					return $callback($result);
				}
				throw SqlError::recordNotFound(static::class, static::notFoundContext($columns, $spec), $message);
			},
			$queue,
			$caching
		);
	}

	/**
	 * Returns the first matching row or throws a repository not-found error.
	 *
	 * This is the fail-fast companion to first(). SQL failures and missing rows both
	 * surface as a not-found exception with the selected columns and query context.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return array<string, mixed> First repository-cast row.
	 * @throws RecordNotFoundException When no row is available.
	 */
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

	/**
	 * Reads one column from the first matching row.
	 *
	 * The query requests only the target column. Missing rows, SQL failures, or rows
	 * that do not contain the requested column return null.
	 *
	 * @param string $column Column to select and extract.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Column value from the first row, or null when unavailable.
	 */
	public static function value(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): mixed {
		$row=static::first($column, $spec, $caching);
		return is_array($row) && array_key_exists($column, $row) ? $row[$column] : null;
	}

	/**
	 * Reads one column from the first matching row or throws when no row exists.
	 *
	 * The row lookup is fail-fast through firstOrFail(). A found row that lacks the
	 * requested column still returns null, matching value()'s extraction semantics.
	 *
	 * @param string $column Column to select and extract.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return mixed Column value, or null when the row lacks the column.
	 * @throws RecordNotFoundException When no row is available.
	 */
	public static function valueOrFail(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		$row=static::firstOrFail($column, $spec, $caching, $message);
		return $row[$column] ?? null;
	}

	/**
	 * Queues a fail-fast first-row value lookup.
	 *
	 * The queued callback receives only the requested column value. Missing rows
	 * throw from the queued callback path through queueFirstOrFail(); missing columns
	 * on a found row are passed to the callback as null.
	 *
	 * @param string $column Column to select and extract.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the extracted value.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueValueOrFail(
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return static::queueFirstOrFail(
			$column,
			$spec,
			static fn(array $row): mixed => $callback($row[$column] ?? null),
			$queue,
			$caching,
			$message
		);
	}

	/**
	 * Returns one column from each matching row.
	 *
	 * When a key column is supplied, returned values are keyed by that column;
	 * otherwise a zero-based list is returned. Missing value columns are represented
	 * as null by pluckRows(), while missing key columns fall back to append behavior.
	 *
	 * @param string $column Column to extract from each row.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param string|null $keyColumn Optional column used as the output key.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string, mixed> Extracted values keyed by row order or key column.
	 */
	public static function pluck(
		string $column,
		?QuerySpec $spec=null,
		?string $keyColumn=null,
		bool|array|string|null $caching=null
	): array {
		return static::pluckRows(
			static::all(static::pluckColumns($column, $keyColumn), $spec, $caching),
			$column,
			$keyColumn
		);
	}

	/**
	 * Queues a pluck query and passes the extracted values to the callback.
	 *
	 * Raw queued rows are normalized through queueAll(), then shaped by pluckRows()
	 * before the caller callback runs.
	 *
	 * @param string $column Column to extract from each row.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with extracted values.
	 * @param string|null $keyColumn Optional column used as the output key.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queuePluck(
		string $column,
		?QuerySpec $spec,
		callable $callback,
		?string $keyColumn=null,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAll(
			static::pluckColumns($column, $keyColumn),
			$spec,
			static fn(array $rows): mixed => $callback(static::pluckRows($rows, $column, $keyColumn)),
			$queue,
			$caching
		);
	}

	/**
	 * Returns matching rows keyed by a column value.
	 *
	 * The key column is automatically included in the select list. Later rows with
	 * the same key replace earlier rows, matching PHP associative-array semantics.
	 *
	 * @param string $keyColumn Column used as the output array key.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string, array<string, mixed>> Repository-cast rows keyed by the requested column.
	 */
	public static function keyBy(
		string $keyColumn,
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::keyRowsBy(
			static::all(static::keyColumns($keyColumn, $columns), $spec, $caching),
			$keyColumn
		);
	}

	/**
	 * Queues a keyed-row read and passes the keyed map to the callback.
	 *
	 * The key column is included in the queued select list before rows are
	 * normalized through queueAll().
	 *
	 * @param string $keyColumn Column used as the output array key.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with keyed repository rows.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueKeyBy(
		string $keyColumn,
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAll(
			static::keyColumns($keyColumn, $columns),
			$spec,
			static fn(array $rows): mixed => $callback(static::keyRowsBy($rows, $keyColumn)),
			$queue,
			$caching
		);
	}

	/**
	 * Returns exactly one matching row.
	 *
	 * The helper reads at most two rows so it can distinguish not-found from
	 * non-unique matches without scanning more data than needed. Zero matches throw
	 * RecordNotFoundException; multiple matches throw MultipleRecordsFoundException.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional exception message.
	 * @return array<string, mixed> The single repository-cast row.
	 * @throws RecordNotFoundException When no row matches.
	 * @throws MultipleRecordsFoundException When more than one row matches.
	 */
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

	/**
	 * Queues a read that must resolve to exactly one row.
	 *
	 * The queued query is limited to two rows to detect non-unique matches. The
	 * callback receives the single normalized row; zero or multiple rows throw from
	 * the queued callback path.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the single row.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional exception message.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueSole(
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		$soleSpec=($spec!==null ? clone $spec : static::spec())->limit(2);
		return static::queueAll(
			$columns,
			$soleSpec,
			static function(array $rows)use($callback, $columns, $spec, $message): mixed{
				if($rows===[]){
					throw SqlError::recordNotFound(
						static::class,
						static::notFoundContext($columns, $spec),
						$message,
						'Use queueFirst() when zero matches are acceptable, or tighten the repository query before calling queueSole().'
					);
				}
				if(count($rows)>1){
					throw SqlError::multipleRecordsFound(
						static::class,
						static::notFoundContext($columns, $spec, ['matched_rows_sample'=>count($rows)]),
						$message,
						'Use queueAll()/queueGet() when multiple matches are expected, or tighten the repository query until it uniquely identifies a single row.'
					);
				}
				return $callback($rows[0]);
			},
			$queue,
			$caching
		);
	}

	/**
	 * Checks whether the repository query matches at least one row.
	 *
	 * Count failures are treated as false, keeping this convenience helper boolean
	 * and side-effect free from the caller's perspective.
	 *
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return bool True when countWhere() returns a positive integer.
	 */
	public static function exists(
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): bool {
		$count=static::countWhere($spec, $caching);
		return is_int($count) ? $count > 0 : false;
	}

	/**
	 * Queues an existence check.
	 *
	 * The callback receives a boolean, with failed or non-integer count results
	 * normalized to false.
	 *
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the existence boolean.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueExists(
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueCount(
			$spec,
			static fn(mixed $count): mixed => $callback(is_int($count) ? $count > 0 : false),
			$queue,
			$caching
		);
	}

	/**
	 * Counts rows matching the repository query.
	 *
	 * This public wrapper preserves countWhere()'s lower-level return shape so
	 * callers can distinguish numeric counts from SQL failure values.
	 *
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int|bool|null Row count, false, or null from the SQL kernel path.
	 */
	public static function count(
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): int|bool|null {
		return static::countWhere($spec, $caching);
	}

	/**
	 * Queues a row-count query.
	 *
	 * Ordering and paging are removed from the cloned spec before compilation. The
	 * queued callback receives the raw SQL count result so callers can decide how to
	 * handle false/null failures.
	 *
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the raw count result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueCount(
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$spec=$spec!==null ? (clone $spec) : static::spec();
		$spec=$spec->withoutOrdering()->withoutPaging();
		$compiled=$spec->compile(false);
		return sql_count(
			static::table(),
			$compiled['params'],
			$compiled['vars'],
			static::resolveReadCaching($caching),
			$queue,
			$callback
		);
	}

	/**
	 * Runs a scalar aggregate query.
	 *
	 * Supported functions are COUNT, SUM, AVG, MIN, and MAX. Ordering and paging are
	 * removed before execution, the aggregate column is schema/identifier validated,
	 * and the raw SQL result is normalized by aggregateValue().
	 *
	 * @param string $function Aggregate function name.
	 * @param string $column Column to aggregate, or `*` for COUNT.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Normalized aggregate value, false on SQL failure, or null when no aggregate value is returned.
	 * @throws SqlError When the aggregate function or column is invalid.
	 */
	public static function aggregate(
		string $function,
		string $column='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::aggregateValue($function, $column, $spec, $caching);
	}

	/**
	 * Queues a scalar aggregate query.
	 *
	 * The queued callback receives the same normalized aggregate shape as
	 * aggregateValue(). Ordering and paging are removed from the cloned query spec
	 * before the SQL job is registered.
	 *
	 * @param string $function Aggregate function name.
	 * @param string $column Column to aggregate, or `*` for COUNT.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the normalized aggregate value.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool $distinct Whether to apply DISTINCT inside the aggregate call.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When the aggregate function or column is invalid.
	 */
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
			->withoutOrdering()
			->withoutPaging()
			->compile(false);
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

	/**
	 * Queues a SUM aggregate for a column.
	 *
	 * The callback receives the normalized SUM value from queueAggregate(), or null
	 * when the SQL result does not expose an aggregate value.
	 *
	 * @param string $column Column to sum.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the normalized SUM value.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueSum(
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAggregate('SUM', $column, $spec, $callback, $queue, $caching);
	}

	/**
	 * Queues an AVG aggregate for a column.
	 *
	 * The callback receives the normalized AVG value from queueAggregate(), preserving
	 * null when the SQL layer returns no aggregate value.
	 *
	 * @param string $column Column to average.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the normalized AVG value.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueAvg(
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAggregate('AVG', $column, $spec, $callback, $queue, $caching);
	}

	/**
	 * Queues a MIN aggregate for a column.
	 *
	 * The callback receives the normalized MIN value from queueAggregate().
	 *
	 * @param string $column Column to inspect.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the normalized MIN value.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueMin(
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAggregate('MIN', $column, $spec, $callback, $queue, $caching);
	}

	/**
	 * Queues a MAX aggregate for a column.
	 *
	 * The callback receives the normalized MAX value from queueAggregate().
	 *
	 * @param string $column Column to inspect.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the normalized MAX value.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueMax(
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAggregate('MAX', $column, $spec, $callback, $queue, $caching);
	}

	/**
	 * Queues a COUNT aggregate for a column.
	 *
	 * Numeric COUNT results are cast to int before the caller callback runs; false
	 * and null are preserved when the SQL layer cannot provide a count.
	 *
	 * @param string $column Column to count, or `*`.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the normalized count.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueCountColumn(
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAggregate(
			'COUNT',
			$column,
			$spec,
			static fn(mixed $value): mixed => $callback(is_numeric($value) ? (int)$value : $value),
			$queue,
			$caching
		);
	}

	/**
	 * Queues a COUNT DISTINCT aggregate for a column.
	 *
	 * Numeric results are cast to int before the callback runs. The column is still
	 * validated through aggregateColumn() before queue registration.
	 *
	 * @param string $column Column to count distinctly.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the normalized distinct count.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueCountDistinct(
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAggregate(
			'COUNT',
			$column,
			$spec,
			static fn(mixed $value): mixed => $callback(is_numeric($value) ? (int)$value : $value),
			$queue,
			$caching,
			true
		);
	}

	/**
	 * Returns the SUM aggregate for a column.
	 *
	 * This is a named wrapper around aggregateValue('SUM', ...).
	 *
	 * @param string $column Column to sum.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Normalized SUM value, false on SQL failure, or null when unavailable.
	 */
	public static function sum(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::aggregateValue('SUM', $column, $spec, $caching);
	}

	/**
	 * Returns the AVG aggregate for a column.
	 *
	 * This is a named wrapper around aggregateValue('AVG', ...).
	 *
	 * @param string $column Column to average.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Normalized AVG value, false on SQL failure, or null when unavailable.
	 */
	public static function avg(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::aggregateValue('AVG', $column, $spec, $caching);
	}

	/**
	 * Returns the MIN aggregate for a column.
	 *
	 * This is a named wrapper around aggregateValue('MIN', ...).
	 *
	 * @param string $column Column to inspect.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Normalized MIN value, false on SQL failure, or null when unavailable.
	 */
	public static function min(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::aggregateValue('MIN', $column, $spec, $caching);
	}

	/**
	 * Returns the MAX aggregate for a column.
	 *
	 * This is a named wrapper around aggregateValue('MAX', ...).
	 *
	 * @param string $column Column to inspect.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Normalized MAX value, false on SQL failure, or null when unavailable.
	 */
	public static function max(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::aggregateValue('MAX', $column, $spec, $caching);
	}

	/**
	 * Counts non-null values for a column.
	 *
	 * Numeric COUNT results are cast to int. False/null from aggregateValue() are
	 * preserved, and non-numeric aggregate payloads normalize to null.
	 *
	 * @param string $column Column to count, or `*`.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int|bool|null Integer count, false on SQL failure, or null when unavailable.
	 */
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

	/**
	 * Counts distinct non-null values for a column.
	 *
	 * Numeric COUNT DISTINCT results are cast to int. False/null from aggregateValue()
	 * are preserved, and non-numeric aggregate payloads normalize to null.
	 *
	 * @param string $column Column to count distinctly.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int|bool|null Integer count, false on SQL failure, or null when unavailable.
	 */
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

	/**
	 * Returns aggregate rows grouped by one or more columns.
	 *
	 * Group and aggregate columns are schema/identifier validated. Ordering and
	 * paging are removed before the grouped query is compiled, and SQL failures
	 * normalize to an empty row list.
	 *
	 * @param string|array $groupColumns One group column or a list of group columns.
	 * @param string $function Aggregate function name.
	 * @param string $column Column to aggregate, or `*` for COUNT.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool $distinct Whether to apply DISTINCT inside the aggregate call.
	 * @return array<int, array<string, mixed>> Group rows with normalized `aggregate_value`.
	 * @throws SqlError When aggregate or group columns are invalid.
	 */
	public static function aggregateRowsBy(
		string|array $groupColumns,
		string $function,
		string $column='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		bool $distinct=false
	): array {
		$function=static::normalizeAggregateFunction($function);
		$groupColumns=static::groupColumns($groupColumns);
		$column=static::aggregateColumn($column, $function, $function==='COUNT');
		$compiled=($spec!==null ? clone $spec : static::spec())
			->withoutOrdering()
			->withoutPaging()
			->compile(false);
		$result=static::withSchemaHydration(static fn(): mixed => sql_select(
				implode(', ', $groupColumns).', '.$function.'('.($distinct ? 'DISTINCT ' : '').$column.') AS aggregate_value',
				static::table(),
				static::appendClause($compiled['params'], 'GROUP BY '.implode(', ', $groupColumns)),
				$compiled['vars'],
				true,
				static::resolveReadCaching($caching)
			)
		);
		if(!is_array($result)){
			return [];
		}
		return static::normalizeAggregateRows($result, $function);
	}

	/**
	 * Queues a grouped aggregate query.
	 *
	 * The callback receives normalized aggregate rows. Failed or non-array queued
	 * results become an empty list through queuedAllRowsResult().
	 *
	 * @param string|array $groupColumns One group column or a list of group columns.
	 * @param string $function Aggregate function name.
	 * @param string $column Column to aggregate, or `*` for COUNT.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with normalized group rows.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool $distinct Whether to apply DISTINCT inside the aggregate call.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When aggregate or group columns are invalid.
	 */
	public static function queueAggregateRowsBy(
		string|array $groupColumns,
		string $function,
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null,
		bool $distinct=false
	): null|bool {
		$function=static::normalizeAggregateFunction($function);
		$groupColumns=static::groupColumns($groupColumns);
		$column=static::aggregateColumn($column, $function, $function==='COUNT');
		$compiled=($spec!==null ? clone $spec : static::spec())
			->withoutOrdering()
			->withoutPaging()
			->compile(false);
		return sql_select(
			implode(', ', $groupColumns).', '.$function.'('.($distinct ? 'DISTINCT ' : '').$column.') AS aggregate_value',
			static::table(),
			static::appendClause($compiled['params'], 'GROUP BY '.implode(', ', $groupColumns)),
			$compiled['vars'],
			true,
			static::resolveReadCaching($caching),
			$queue,
			static function(mixed $result)use($callback, $function): void{
				$rows=static::queuedAllRowsResult($result);
				$callback(static::normalizeAggregateRows($rows, $function));
			}
		);
	}

	/**
	 * Returns counts keyed by a group column.
	 *
	 * The result maps each non-null group value to its normalized COUNT value.
	 * Duplicate group keys use the last row returned by the SQL layer.
	 *
	 * @param string $groupColumn Column used as the output key.
	 * @param string $column Column to count, or `*`.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<string, mixed> Count values keyed by group column.
	 */
	public static function countBy(
		string $groupColumn,
		string $column='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::groupedAggregateMap(
			$groupColumn,
			static::aggregateRowsBy($groupColumn, 'COUNT', $column, $spec, $caching)
		);
	}

	/**
	 * Queues counts keyed by a group column.
	 *
	 * The callback receives the grouped aggregate map produced from normalized
	 * queued rows.
	 *
	 * @param string $groupColumn Column used as the output key.
	 * @param string $column Column to count, or `*`.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with a group-to-count map.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueCountBy(
		string $groupColumn,
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAggregateRowsBy(
			$groupColumn,
			'COUNT',
			$column,
			$spec,
			static fn(array $rows): mixed => $callback(static::groupedAggregateMap($groupColumn, $rows)),
			$queue,
			$caching
		);
	}

	/**
	 * Returns distinct counts keyed by a group column.
	 *
	 * COUNT DISTINCT is applied inside each group. The result maps each non-null
	 * group value to its normalized aggregate value.
	 *
	 * @param string $groupColumn Column used as the output key.
	 * @param string $column Column to count distinctly.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<string, mixed> Distinct count values keyed by group column.
	 */
	public static function countDistinctBy(
		string $groupColumn,
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::groupedAggregateMap(
			$groupColumn,
			static::aggregateRowsBy($groupColumn, 'COUNT', $column, $spec, $caching, true)
		);
	}

	/**
	 * Queues distinct counts keyed by a group column.
	 *
	 * The callback receives a map built from normalized grouped COUNT DISTINCT rows.
	 *
	 * @param string $groupColumn Column used as the output key.
	 * @param string $column Column to count distinctly.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with a group-to-count map.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueCountDistinctBy(
		string $groupColumn,
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAggregateRowsBy(
			$groupColumn,
			'COUNT',
			$column,
			$spec,
			static fn(array $rows): mixed => $callback(static::groupedAggregateMap($groupColumn, $rows)),
			$queue,
			$caching,
			true
		);
	}

	/**
	 * Returns SUM values keyed by a group column.
	 *
	 * The result maps each non-null group value to its normalized SUM aggregate.
	 * Missing or failed grouped queries normalize to an empty map.
	 *
	 * @param string $groupColumn Column used as the output key.
	 * @param string $column Column to sum.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<string, mixed> SUM values keyed by group column.
	 */
	public static function sumBy(
		string $groupColumn,
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::groupedAggregateMap(
			$groupColumn,
			static::aggregateRowsBy($groupColumn, 'SUM', $column, $spec, $caching)
		);
	}

	/**
	 * Queues SUM values keyed by a group column.
	 *
	 * The callback receives the grouped aggregate map produced from normalized
	 * queued rows.
	 *
	 * @param string $groupColumn Column used as the output key.
	 * @param string $column Column to sum.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with a group-to-sum map.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueSumBy(
		string $groupColumn,
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAggregateRowsBy(
			$groupColumn,
			'SUM',
			$column,
			$spec,
			static fn(array $rows): mixed => $callback(static::groupedAggregateMap($groupColumn, $rows)),
			$queue,
			$caching
		);
	}

	/**
	 * Returns AVG values keyed by a group column.
	 *
	 * The result maps each non-null group value to its normalized AVG aggregate.
	 *
	 * @param string $groupColumn Column used as the output key.
	 * @param string $column Column to average.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<string, mixed> AVG values keyed by group column.
	 */
	public static function avgBy(
		string $groupColumn,
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::groupedAggregateMap(
			$groupColumn,
			static::aggregateRowsBy($groupColumn, 'AVG', $column, $spec, $caching)
		);
	}

	/**
	 * Queues AVG values keyed by a group column.
	 *
	 * The callback receives a group-to-average map built from normalized grouped
	 * aggregate rows.
	 *
	 * @param string $groupColumn Column used as the output key.
	 * @param string $column Column to average.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with a group-to-average map.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueAvgBy(
		string $groupColumn,
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAggregateRowsBy(
			$groupColumn,
			'AVG',
			$column,
			$spec,
			static fn(array $rows): mixed => $callback(static::groupedAggregateMap($groupColumn, $rows)),
			$queue,
			$caching
		);
	}

	/**
	 * Returns MIN values keyed by a group column.
	 *
	 * The result maps each non-null group value to its normalized MIN aggregate.
	 *
	 * @param string $groupColumn Column used as the output key.
	 * @param string $column Column to inspect.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<string, mixed> MIN values keyed by group column.
	 */
	public static function minBy(
		string $groupColumn,
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::groupedAggregateMap(
			$groupColumn,
			static::aggregateRowsBy($groupColumn, 'MIN', $column, $spec, $caching)
		);
	}

	/**
	 * Queues MIN values keyed by a group column.
	 *
	 * The callback receives a group-to-minimum map built from normalized grouped
	 * aggregate rows.
	 *
	 * @param string $groupColumn Column used as the output key.
	 * @param string $column Column to inspect.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with a group-to-minimum map.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueMinBy(
		string $groupColumn,
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAggregateRowsBy(
			$groupColumn,
			'MIN',
			$column,
			$spec,
			static fn(array $rows): mixed => $callback(static::groupedAggregateMap($groupColumn, $rows)),
			$queue,
			$caching
		);
	}

	/**
	 * Returns MAX values keyed by a group column.
	 *
	 * The result maps each non-null group value to its normalized MAX aggregate.
	 *
	 * @param string $groupColumn Column used as the output key.
	 * @param string $column Column to inspect.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<string, mixed> MAX values keyed by group column.
	 */
	public static function maxBy(
		string $groupColumn,
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::groupedAggregateMap(
			$groupColumn,
			static::aggregateRowsBy($groupColumn, 'MAX', $column, $spec, $caching)
		);
	}

	/**
	 * Queues MAX values keyed by a group column.
	 *
	 * The callback receives a group-to-maximum map built from normalized grouped
	 * aggregate rows.
	 *
	 * @param string $groupColumn Column used as the output key.
	 * @param string $column Column to inspect.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with a group-to-maximum map.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueMaxBy(
		string $groupColumn,
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAggregateRowsBy(
			$groupColumn,
			'MAX',
			$column,
			$spec,
			static fn(array $rows): mixed => $callback(static::groupedAggregateMap($groupColumn, $rows)),
			$queue,
			$caching
		);
	}

	/**
	 * Returns a paginated page of repository-cast rows.
	 *
	 * Page is clamped to at least 1 and per-page is clamped to 1..500 before the
	 * count and item queries run. Count failures normalize total to zero while item
	 * failures normalize to an empty list through all().
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param int $page Requested 1-based page number.
	 * @param int $perPage Requested page size, clamped to 1..500.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return PageResult Page containing repository-cast rows and total metadata.
	 */
	public static function paginate(
		array|string $columns='*',
		?QuerySpec $spec=null,
		int $page=1,
		int $perPage=50,
		bool|array|string|null $caching=null
	): PageResult {
		$page=max(1, $page);
		$perPage=max(1, min(500, $perPage));
		$baseSpec=$spec ?? static::spec();
		$total=static::countWhere($baseSpec, $caching);
		$items=static::all($columns, (clone $baseSpec)->forPage($page, $perPage), $caching);
		return new PageResult(
			$items,
			is_int($total) ? max(0, $total) : 0,
			$page,
			$perPage
		);
	}

	/**
	 * Queues the count and item queries needed to build a PageResult.
	 *
	 * The callback runs only after both queued operations have returned. Count
	 * failures normalize total to zero, item failures normalize to an empty list, and
	 * a failed queue registration returns false.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the PageResult.
	 * @param int $page Requested 1-based page number.
	 * @param int $perPage Requested page size, clamped to 1..500.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queuePaginate(
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		int $page=1,
		int $perPage=50,
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$page=max(1, $page);
		$perPage=max(1, min(500, $perPage));
		$baseSpec=$spec!==null ? clone $spec : static::spec();
		$total=0;
		$items=[];
		$haveTotal=false;
		$haveItems=false;
		$emit=static function()use(&$haveTotal, &$haveItems, &$total, &$items, $callback, $page, $perPage): void{
			if($haveTotal && $haveItems){
				$callback(new PageResult($items, $total, $page, $perPage));
			}
		};
		$countResult=static::queueCount(
			clone $baseSpec,
			static function(mixed $count)use(&$total, &$haveTotal, $emit): void{
				$total=is_int($count) ? max(0, $count) : 0;
				$haveTotal=true;
				$emit();
			},
			$queue,
			$caching
		);
		$itemsResult=static::queueAll(
			$columns,
			(clone $baseSpec)->forPage($page, $perPage),
			static function(array $rows)use(&$items, &$haveItems, $emit): void{
				$items=$rows;
				$haveItems=true;
				$emit();
			},
			$queue,
			$caching
		);
		return $countResult===false || $itemsResult===false ? false : ($itemsResult ?? $countResult);
	}

	/**
	 * Hydrates one raw row through repository casting, money mapping, and hydrator resolution.
	 *
	 * The row is first cast through schema metadata, then money/stored-money mappings
	 * are applied, then the resolved hydrator creates the final value. Invalid
	 * hydrator declarations fail before the row is returned.
	 *
	 * @param array<string, mixed> $row Raw SQL row.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @return mixed Hydrated record, value object, array, or custom hydrator result.
	 * @throws SqlError When the hydrator declaration is invalid.
	 */
	public static function hydrateRow(array $row, mixed $hydrator=null): mixed {
		$row=static::applyRepositoryMoneyColumns(static::castRepositoryRow($row));
		return static::resolvedHydrator($hydrator)->hydrate($row, static::schema());
	}

	/**
	 * Hydrates a list of raw rows while preserving input keys.
	 *
	 * Non-array entries are skipped. Each valid row flows through hydrateRow(), so
	 * schema casts, money mappings, and hydrator validation are applied consistently.
	 *
	 * @param array<int|string, mixed> $rows Raw row list.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @return array<int|string, mixed> Hydrated rows keyed like the input.
	 */
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

	/**
	 * Returns all matching rows after repository hydration.
	 *
	 * Raw SQL rows are first normalized by all(), then each row is hydrated with the
	 * default or caller-supplied hydrator.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string, mixed> Hydrated repository rows.
	 */
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

	/**
	 * Returns the first matching row after repository hydration.
	 *
	 * Missing rows and SQL failures return null before hydration is attempted.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated first row, or null when no row is available.
	 */
	public static function firstHydrated(
		array|string $columns='*',
		?QuerySpec $spec=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		$row=static::first($columns, $spec, $caching);
		return $row!==null ? static::hydrateRow($row, $hydrator) : null;
	}

	/**
	 * Returns all matching rows as record-style hydrated values.
	 *
	 * This is a semantic alias for allHydrated(). Without a hydrator override, the
	 * default hydrator returns the configured or inferred record class, falling back
	 * to generic Record instances.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string, mixed> Hydrated record values.
	 */
	public static function allRecords(
		array|string $columns='*',
		?QuerySpec $spec=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): array {
		return static::allHydrated($columns, $spec, $hydrator, $caching);
	}

	/**
	 * Returns the first matching row as a record-style hydrated value.
	 *
	 * This is a semantic alias for firstHydrated(). Missing rows and SQL failures
	 * return null.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated record value, or null when unavailable.
	 */
	public static function firstRecord(
		array|string $columns='*',
		?QuerySpec $spec=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::firstHydrated($columns, $spec, $hydrator, $caching);
	}

	/**
	 * Returns the first matching record-style value or throws when none exists.
	 *
	 * Hydration happens only after a row is found. Missing rows and SQL failures
	 * produce the repository not-found exception shape.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return mixed non-null record object or mapped value produced by the repository hydrator.
	 * @throws RecordNotFoundException When no row is available.
	 */
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

	/**
	 * Queues a fail-fast first-record lookup.
	 *
	 * The queued row is hydrated before the caller callback runs. Missing rows throw
	 * from the queued callback path through queueFirstOrFail().
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the hydrated record value.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueFirstRecordOrFail(
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return static::queueFirstOrFail(
			$columns,
			$spec,
			static fn(array $row): mixed => $callback(static::hydrateRow($row, $hydrator)),
			$queue,
			$caching,
			$message
		);
	}

	/**
	 * Returns exactly one matching row after hydration.
	 *
	 * The underlying sole() call enforces the exact-one invariant before hydration,
	 * so missing and non-unique matches are reported without invoking the hydrator.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional exception message.
	 * @return mixed record object or mapped value for the single row that satisfies the exact-one check.
	 * @throws RecordNotFoundException When no row matches.
	 * @throws MultipleRecordsFoundException When more than one row matches.
	 */
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

	/**
	 * Queues an exact-one row lookup and hydrates the result.
	 *
	 * The queued callback receives a hydrated value only after queueSole() confirms
	 * that exactly one normalized row matched. Missing or non-unique matches throw
	 * from the queued callback path.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the hydrated single record.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional exception message.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueSoleRecord(
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return static::queueSole(
			$columns,
			$spec,
			static fn(array $row): mixed => $callback(static::hydrateRow($row, $hydrator)),
			$queue,
			$caching,
			$message
		);
	}

	/**
	 * Returns one column from exactly one matching row.
	 *
	 * The exact-one invariant is enforced by sole(). A matching row that lacks the
	 * requested column returns null, matching value-style extraction semantics.
	 *
	 * @param string $column Column to select and extract.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional exception message.
	 * @return mixed Column value from the sole row, or null when the row lacks the column.
	 * @throws RecordNotFoundException When no row matches.
	 * @throws MultipleRecordsFoundException When more than one row matches.
	 */
	public static function soleValue(
		string $column,
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		$row=static::sole($column, $spec, $caching, $message);
		return $row[$column] ?? null;
	}

	/**
	 * Queues an exact-one row lookup and extracts one column for the callback.
	 *
	 * Missing or non-unique matches throw from queueSole(); a found row missing the
	 * selected column passes null to the callback.
	 *
	 * @param string $column Column to select and extract.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the extracted value.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional exception message.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueSoleValue(
		string $column,
		?QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return static::queueSole(
			$column,
			$spec,
			static fn(array $row): mixed => $callback($row[$column] ?? null),
			$queue,
			$caching,
			$message
		);
	}

	/**
	 * Finds the first row matching one column equality and hydrates it.
	 *
	 * The lookup is built with whereEq() on a fresh repository query. Missing rows
	 * and SQL failures return null before hydration is attempted.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated row value, or null when no row is available.
	 */
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

	/**
	 * Finds and hydrates one row by column equality, throwing when absent.
	 *
	 * The not-found context includes the lookup column and value. Hydration happens
	 * only after findOneByOrFail() returns a row.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return mixed row mapped by the repository hydrator after the equality lookup succeeds.
	 * @throws RecordNotFoundException When no row matches the lookup.
	 */
	public static function findOneHydratedByOrFail(
		string $column,
		mixed $value,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		return static::hydrateRow(static::findOneByOrFail($column, $value, $columns, $caching, $message), $hydrator);
	}

	/**
	 * Queues a nullable one-row equality lookup and hydrates callback payloads.
	 *
	 * The callback receives a hydrated value or null. Hydration is skipped for null
	 * lookup results.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param callable $callback Callback invoked with the hydrated value or null.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueFindOneHydratedBy(
		string $column,
		mixed $value,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueFindOneBy(
			$column,
			$value,
			static fn(?array $row): mixed => $callback($row!==null ? static::hydrateRow($row, $hydrator) : null),
			$columns,
			$queue,
			$caching
		);
	}

	/**
	 * Queues a fail-fast one-row equality lookup and hydrates callback payloads.
	 *
	 * Missing rows throw from the queued callback path. Found rows are hydrated
	 * before the caller callback receives them.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param callable $callback Callback invoked with the hydrated row.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueFindOneHydratedByOrFail(
		string $column,
		mixed $value,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return static::queueFindOneByOrFail(
			$column,
			$value,
			static fn(array $row): mixed => $callback(static::hydrateRow($row, $hydrator)),
			$columns,
			$queue,
			$caching,
			$message
		);
	}

	/**
	 * Finds the first record-style hydrated value matching one column equality.
	 *
	 * This is a semantic alias for findOneHydratedBy(). Without a hydrator override,
	 * the default record hydrator preserves repository and primary-key metadata.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated record value, or null when no row is available.
	 */
	public static function findOneRecordBy(
		string $column,
		mixed $value,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::findOneHydratedBy($column, $value, $columns, $hydrator, $caching);
	}

	/**
	 * Finds one record-style value by equality and throws when absent.
	 *
	 * This is a semantic alias for findOneHydratedByOrFail().
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return mixed record object or mapped value produced after the equality lookup finds a row.
	 * @throws RecordNotFoundException When no row matches the lookup.
	 */
	public static function findOneRecordByOrFail(
		string $column,
		mixed $value,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		return static::findOneHydratedByOrFail($column, $value, $columns, $hydrator, $caching, $message);
	}

	/**
	 * Queues a nullable one-row record lookup by column equality.
	 *
	 * This is a semantic alias for queueFindOneHydratedBy().
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param callable $callback Callback invoked with the hydrated record or null.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueFindOneRecordBy(
		string $column,
		mixed $value,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueFindOneHydratedBy($column, $value, $callback, $columns, $queue, $hydrator, $caching);
	}

	/**
	 * Queues a fail-fast one-row record lookup by column equality.
	 *
	 * This is a semantic alias for queueFindOneHydratedByOrFail().
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param callable $callback Callback invoked with the hydrated record.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueFindOneRecordByOrFail(
		string $column,
		mixed $value,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return static::queueFindOneHydratedByOrFail($column, $value, $callback, $columns, $queue, $hydrator, $caching, $message);
	}

	/**
	 * Finds all rows matching one column equality and hydrates them.
	 *
	 * The lookup uses whereEq() and normalizes SQL failures to an empty list before
	 * hydration. Non-array rows are skipped by hydrateRows().
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string, mixed> Hydrated matching rows.
	 */
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

	/**
	 * Queues a multi-row equality lookup and hydrates callback payloads.
	 *
	 * Queued SQL failures normalize to an empty row list before hydration, matching
	 * queueAll() semantics.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param callable $callback Callback invoked with hydrated matching rows.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueFindManyHydratedBy(
		string $column,
		mixed $value,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueFindManyBy(
			$column,
			$value,
			static fn(array $rows): mixed => $callback(static::hydrateRows($rows, $hydrator)),
			$columns,
			$queue,
			$caching
		);
	}

	/**
	 * Finds the first cast row matching one column equality.
	 *
	 * The lookup is built on a fresh repository query with whereEq(). Missing rows
	 * and SQL failures return null.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<string, mixed>|null First matching repository-cast row, or null.
	 */
	public static function findOneBy(
		string $column,
		mixed $value,
		array|string $columns='*',
		bool|array|string|null $caching=null
	): ?array {
		return static::query()->whereEq($column, $value)->first($columns, $caching);
	}

	/**
	 * Finds one cast row by column equality or throws when absent.
	 *
	 * The not-found context includes the lookup column and value so diagnostics can
	 * distinguish lookup misses from generic firstOrFail() misses.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return array<string, mixed> Matching repository-cast row.
	 * @throws RecordNotFoundException When no row matches the lookup.
	 */
	public static function findOneByOrFail(
		string $column,
		mixed $value,
		array|string $columns='*',
		bool|array|string|null $caching=null,
		?string $message=null
	): array {
		$result=static::findOneBy($column, $value, $columns, $caching);
		if($result!==null){
			return $result;
		}
		return throw SqlError::recordNotFound(
			static::class,
			static::notFoundContext($columns, static::spec()->whereEq($column, $value), [
				'lookup_column'=>$column,
				'lookup_value'=>$value,
			]),
			$message,
			'Use findOneBy() when a missing record is acceptable, or verify the lookup column and value before calling findOneByOrFail().'
		);
	}

	/**
	 * Queues a nullable one-row equality lookup.
	 *
	 * The callback receives a repository-cast row or null from queueFirst().
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param callable $callback Callback invoked with a row or null.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueFindOneBy(
		string $column,
		mixed $value,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueFirst(
			$columns,
			static::spec()->whereEq($column, $value),
			$callback,
			$queue,
			$caching
		);
	}

	/**
	 * Queues a fail-fast one-row equality lookup.
	 *
	 * Missing rows throw from the queued callback path through queueFirstOrFail().
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param callable $callback Callback invoked with the matching row.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueFindOneByOrFail(
		string $column,
		mixed $value,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return static::queueFirstOrFail(
			$columns,
			static::spec()->whereEq($column, $value),
			$callback,
			$queue,
			$caching,
			$message
		);
	}

	/**
	 * Finds all cast rows matching one column equality.
	 *
	 * The lookup uses a fresh repository query with whereEq(); SQL failures
	 * normalize to an empty list through RepositoryQuery::get().
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int, array<string, mixed>> Matching repository-cast rows.
	 */
	public static function findManyBy(
		string $column,
		mixed $value,
		array|string $columns='*',
		bool|array|string|null $caching=null
	): array {
		return static::query()->whereEq($column, $value)->get($columns, $caching);
	}

	/**
	 * Queues a multi-row equality lookup.
	 *
	 * The callback receives normalized repository-cast rows from queueAll().
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param callable $callback Callback invoked with matching rows.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueFindManyBy(
		string $column,
		mixed $value,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueAll(
			$columns,
			static::spec()->whereEq($column, $value),
			$callback,
			$queue,
			$caching
		);
	}

	/**
	 * Finds cast rows whose primary-key-style column is in a normalized ID list.
	 *
	 * Empty, null, and duplicate IDs are removed by normalizedFinderIds(). An empty
	 * normalized list short-circuits to an empty result without hitting SQL.
	 *
	 * @param array<int, mixed> $ids Candidate IDs to match.
	 * @param string $primaryKeyColumn Column used for the IN predicate.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int, array<string, mixed>> Repository-cast rows, or an empty list.
	 */
	public static function findManyByIds(
		array $ids,
		string $primaryKeyColumn,
		array|string $columns='*',
		bool|array|string|null $caching=null
	): array {
		$ids=static::normalizedFinderIds($ids);
		if($ids===[]){
			return [];
		}
		$spec=static::spec()->whereIn($primaryKeyColumn, $ids);
		$rows=static::selectMany($columns, $spec, $caching);
		return is_array($rows) ? static::castRepositoryRows($rows) : [];
	}

	/**
	 * Queues a multi-row ID-list lookup.
	 *
	 * Empty normalized ID lists invoke the callback immediately with an empty list
	 * and return null. Otherwise the callback receives normalized repository rows
	 * from queueAll().
	 *
	 * @param array<int, mixed> $ids Candidate IDs to match.
	 * @param string $primaryKeyColumn Column used for the IN predicate.
	 * @param callable $callback Callback invoked with matching rows.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result, or null when no IDs remain after normalization.
	 */
	public static function queueFindManyByIds(
		array $ids,
		string $primaryKeyColumn,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$ids=static::normalizedFinderIds($ids);
		if($ids===[]){
			$callback([]);
			return null;
		}
		return static::queueAll(
			$columns,
			static::spec()->whereIn($primaryKeyColumn, $ids),
			$callback,
			$queue,
			$caching
		);
	}

	/**
	 * Finds rows by IDs and keys the result by the ID column.
	 *
	 * Rows missing the key column are skipped. Duplicate key values overwrite earlier
	 * rows after string-casting, matching PHP array-key behavior.
	 *
	 * @param array<int, mixed> $ids Candidate IDs to match.
	 * @param string $primaryKeyColumn Column used for lookup and output keys.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<string, array<string, mixed>> Repository-cast rows keyed by ID.
	 */
	public static function findKeyedByIds(
		array $ids,
		string $primaryKeyColumn,
		array|string $columns='*',
		bool|array|string|null $caching=null
	): array {
		$rows=static::findManyByIds($ids, $primaryKeyColumn, $columns, $caching);
		$keyed=[];
		foreach($rows as $row){
			if(!is_array($row) || !array_key_exists($primaryKeyColumn, $row)){
				continue;
			}
			$keyed[(string)$row[$primaryKeyColumn]]=$row;
		}
		return $keyed;
	}

	/**
	 * Queues an ID-list lookup and keys rows by the ID column.
	 *
	 * The callback receives the same keyed shape as keyRowsBy(), after queueFindManyByIds()
	 * has normalized empty ID lists and queued row payloads.
	 *
	 * @param array<int, mixed> $ids Candidate IDs to match.
	 * @param string $primaryKeyColumn Column used for lookup and output keys.
	 * @param callable $callback Callback invoked with rows keyed by ID.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result, or null when no IDs remain after normalization.
	 */
	public static function queueFindKeyedByIds(
		array $ids,
		string $primaryKeyColumn,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueFindManyByIds(
			$ids,
			$primaryKeyColumn,
			static fn(array $rows): mixed => $callback(static::keyRowsBy($rows, $primaryKeyColumn)),
			$columns,
			$queue,
			$caching
		);
	}

	/**
	 * Finds rows by IDs and hydrates each matching row.
	 *
	 * Empty ID lists and SQL failures normalize to an empty list before hydration.
	 * Input keys from the raw row list are preserved by hydrateRows().
	 *
	 * @param array<int, mixed> $ids Candidate IDs to match.
	 * @param string $primaryKeyColumn Column used for the IN predicate.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int|string, mixed> Hydrated matching rows.
	 */
	public static function findManyHydratedByIds(
		array $ids,
		string $primaryKeyColumn,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): array {
		return static::hydrateRows(
			static::findManyByIds($ids, $primaryKeyColumn, $columns, $caching),
			$hydrator
		);
	}

	/**
	 * Queues an ID-list lookup and hydrates rows before invoking the callback.
	 *
	 * Empty normalized ID lists still call the callback with an empty hydrated list
	 * through queueFindManyByIds().
	 *
	 * @param array<int, mixed> $ids Candidate IDs to match.
	 * @param string $primaryKeyColumn Column used for the IN predicate.
	 * @param callable $callback Callback invoked with hydrated matching rows.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result, or null when no IDs remain after normalization.
	 */
	public static function queueFindManyHydratedByIds(
		array $ids,
		string $primaryKeyColumn,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueFindManyByIds(
			$ids,
			$primaryKeyColumn,
			static fn(array $rows): mixed => $callback(static::hydrateRows($rows, $hydrator)),
			$columns,
			$queue,
			$caching
		);
	}

	/**
	 * Finds rows by IDs, keys them by ID, and hydrates each row.
	 *
	 * Keyed rows missing the key column are skipped before hydration. Output keys are
	 * preserved after hydration.
	 *
	 * @param array<int, mixed> $ids Candidate IDs to match.
	 * @param string $primaryKeyColumn Column used for lookup and output keys.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<string, mixed> Hydrated rows keyed by ID.
	 */
	public static function findKeyedHydratedByIds(
		array $ids,
		string $primaryKeyColumn,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): array {
		$rows=static::findKeyedByIds($ids, $primaryKeyColumn, $columns, $caching);
		$resolved=[];
		foreach($rows as $key=>$row){
			if(!is_array($row)){
				continue;
			}
			$resolved[$key]=static::hydrateRow($row, $hydrator);
		}
		return $resolved;
	}

	/**
	 * Queues an ID-list lookup, keys rows by ID, and hydrates each row.
	 *
	 * The caller callback receives hydrated values keyed by the ID column; non-array
	 * rows are ignored during hydration.
	 *
	 * @param array<int, mixed> $ids Candidate IDs to match.
	 * @param string $primaryKeyColumn Column used for lookup and output keys.
	 * @param callable $callback Callback invoked with hydrated rows keyed by ID.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result, or null when no IDs remain after normalization.
	 */
	public static function queueFindKeyedHydratedByIds(
		array $ids,
		string $primaryKeyColumn,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueFindKeyedByIds(
			$ids,
			$primaryKeyColumn,
			static function(array $rows)use($callback, $hydrator): mixed{
				$resolved=[];
				foreach($rows as $key=>$row){
					if(is_array($row)){
						$resolved[$key]=static::hydrateRow($row, $hydrator);
					}
				}
				return $callback($resolved);
			},
			$columns,
			$queue,
			$caching
		);
	}

	/**
	 * Finds one cast row by the repository primary key.
	 *
	 * The repository must expose a schema-backed primary key. Missing rows and SQL
	 * failures return null.
	 *
	 * @param mixed $id Primary-key value.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<string, mixed>|null Repository-cast row, or null when unavailable.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function find(
		mixed $id,
		array|string $columns='*',
		bool|array|string|null $caching=null
	): ?array {
		$primaryKey=static::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform find(...)');
		}
		return static::findOneBy($primaryKey, $id, $columns, $caching);
	}

	/**
	 * Queues a nullable primary-key lookup.
	 *
	 * The repository must expose a schema-backed primary key before queue
	 * registration. The callback receives a repository-cast row or null.
	 *
	 * @param mixed $id Primary-key value.
	 * @param callable $callback Callback invoked with a row or null.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function queueFind(
		mixed $id,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		bool|array|string|null $caching=null
	): null|bool {
		$primaryKey=static::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform queueFind(...)');
		}
		return static::queueFirst(
			$columns,
			static::spec()->whereEq($primaryKey, $id),
			$callback,
			$queue,
			$caching
		);
	}

	/**
	 * Queues a fail-fast primary-key lookup.
	 *
	 * Missing rows throw from the queued callback path. Repositories without primary
	 * key metadata fail before queue registration.
	 *
	 * @param mixed $id Primary-key value.
	 * @param callable $callback Callback invoked with the matching row.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function queueFindOrFail(
		mixed $id,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		$primaryKey=static::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform queueFindOrFail(...)');
		}
		return static::queueFirstOrFail(
			$columns,
			static::spec()->whereEq($primaryKey, $id),
			$callback,
			$queue,
			$caching,
			$message
		);
	}

	/**
	 * Finds one cast row by primary key or throws when absent.
	 *
	 * The not-found context includes the primary key name and requested value.
	 *
	 * @param mixed $id Primary-key value.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return array<string, mixed> Repository-cast row.
	 * @throws SqlError When the repository has no primary key.
	 * @throws RecordNotFoundException When no row matches the primary key.
	 */
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

	/**
	 * Finds one primary-key row and hydrates it.
	 *
	 * Missing rows and SQL failures return null before hydration. Repositories
	 * without primary-key metadata fail through find().
	 *
	 * @param mixed $id Primary-key value.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated row value, or null when unavailable.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function findHydrated(
		mixed $id,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		$row=static::find($id, $columns, $caching);
		return $row!==null ? static::hydrateRow($row, $hydrator) : null;
	}

	/**
	 * Finds one primary-key row, throws when absent, and hydrates it.
	 *
	 * Hydration happens only after findOrFail() returns a row.
	 *
	 * @param mixed $id Primary-key value.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return mixed row mapped by the repository hydrator after the primary-key lookup succeeds.
	 * @throws SqlError When the repository has no primary key.
	 * @throws RecordNotFoundException When no row matches the primary key.
	 */
	public static function findHydratedOrFail(
		mixed $id,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): mixed {
		return static::hydrateRow(static::findOrFail($id, $columns, $caching, $message), $hydrator);
	}

	/**
	 * Queues a nullable primary-key lookup and hydrates callback payloads.
	 *
	 * The callback receives a hydrated value or null. Hydration is skipped for null
	 * lookup results.
	 *
	 * @param mixed $id Primary-key value.
	 * @param callable $callback Callback invoked with the hydrated value or null.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function queueFindHydrated(
		mixed $id,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueFind(
			$id,
			static fn(?array $row): mixed => $callback($row!==null ? static::hydrateRow($row, $hydrator) : null),
			$columns,
			$queue,
			$caching
		);
	}

	/**
	 * Queues a fail-fast primary-key lookup and hydrates callback payloads.
	 *
	 * Missing rows throw from the queued callback path through queueFindOrFail().
	 *
	 * @param mixed $id Primary-key value.
	 * @param callable $callback Callback invoked with the hydrated row.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function queueFindHydratedOrFail(
		mixed $id,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return static::queueFindOrFail(
			$id,
			static fn(array $row): mixed => $callback(static::hydrateRow($row, $hydrator)),
			$columns,
			$queue,
			$caching,
			$message
		);
	}

	/**
	 * Finds one primary-key row as a record-style hydrated value.
	 *
	 * This is a semantic alias for findHydrated(). Without a hydrator override, the
	 * default hydrator returns configured/inferred record objects or generic Record.
	 *
	 * @param mixed $id Primary-key value.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return mixed Hydrated record value, or null when unavailable.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function findRecord(
		mixed $id,
		array|string $columns='*',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): mixed {
		return static::findHydrated($id, $columns, $hydrator, $caching);
	}

	/**
	 * Queues a nullable primary-key record lookup.
	 *
	 * This is a semantic alias for queueFindHydrated().
	 *
	 * @param mixed $id Primary-key value.
	 * @param callable $callback Callback invoked with the hydrated record or null.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function queueFindRecord(
		mixed $id,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return static::queueFindHydrated($id, $callback, $columns, $queue, $hydrator, $caching);
	}

	/**
	 * Queues a fail-fast primary-key record lookup.
	 *
	 * This is a semantic alias for queueFindHydratedOrFail().
	 *
	 * @param mixed $id Primary-key value.
	 * @param callable $callback Callback invoked with the hydrated record.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function queueFindRecordOrFail(
		mixed $id,
		callable $callback,
		array|string $columns='*',
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		?string $message=null
	): null|bool {
		return static::queueFindHydratedOrFail($id, $callback, $columns, $queue, $hydrator, $caching, $message);
	}

	/**
	 * Finds one primary-key record-style value or throws when absent.
	 *
	 * Missing rows and SQL failures produce the repository not-found exception shape
	 * after findRecord() returns null.
	 *
	 * @param mixed $id Primary-key value.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string|null $message Optional not-found exception message.
	 * @return mixed record object or mapped value produced after the primary-key lookup finds a row.
	 * @throws SqlError When the repository has no primary key.
	 * @throws RecordNotFoundException When no row matches the primary key.
	 */
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

	/**
	 * Returns a paginated page whose items are hydrated values.
	 *
	 * Pagination metadata comes from paginate(); only the current page items are
	 * mapped through hydrateRow(). Count and page-size clamping semantics are
	 * identical to the raw paginated helper.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param int $page Requested 1-based page number.
	 * @param int $perPage Requested page size, clamped to 1..500.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return PageResult PageResult containing hydrated item values.
	 */
	public static function paginateHydrated(
		array|string $columns='*',
		?QuerySpec $spec=null,
		int $page=1,
		int $perPage=50,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): PageResult {
		return static::paginate($columns, $spec, $page, $perPage, $caching)
			->map(static fn(array $row): mixed => static::hydrateRow($row, $hydrator));
	}

	/**
	 * Queues a paginated read and hydrates page items before invoking the callback.
	 *
	 * The queued callback receives a new PageResult with hydrated items and the same
	 * total, page, and per-page metadata produced by queuePaginate().
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with a hydrated PageResult.
	 * @param int $page Requested 1-based page number.
	 * @param int $perPage Requested page size, clamped to 1..500.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queuePaginateHydrated(
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		int $page=1,
		int $perPage=50,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return static::queuePaginate(
			$columns,
			$spec,
			static function(PageResult $pageResult)use($callback, $hydrator): void{
				$callback(new PageResult(
					static::hydrateRows($pageResult->items(), $hydrator),
					$pageResult->total(),
					$pageResult->page(),
					$pageResult->perPage()
				));
			},
			$page,
			$perPage,
			$queue,
			$caching
		);
	}

	/**
	 * Returns a paginated page of record-style hydrated values.
	 *
	 * This is a semantic alias for paginateHydrated(); the default hydrator returns
	 * configured/inferred record objects or generic Record instances.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param int $page Requested 1-based page number.
	 * @param int $perPage Requested page size, clamped to 1..500.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return PageResult PageResult containing record-style hydrated values.
	 */
	public static function paginateRecords(
		array|string $columns='*',
		?QuerySpec $spec=null,
		int $page=1,
		int $perPage=50,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): PageResult {
		return static::paginateHydrated($columns, $spec, $page, $perPage, $hydrator, $caching);
	}

	/**
	 * Queues a paginated read of record-style hydrated values.
	 *
	 * This is a semantic alias for queuePaginateHydrated().
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with a record PageResult.
	 * @param int $page Requested 1-based page number.
	 * @param int $perPage Requested page size, clamped to 1..500.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queuePaginateRecords(
		array|string $columns,
		?QuerySpec $spec,
		callable $callback,
		int $page=1,
		int $perPage=50,
		string $queue='end',
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): null|bool {
		return static::queuePaginateHydrated($columns, $spec, $callback, $page, $perPage, $queue, $hydrator, $caching);
	}

	/**
	 * Iterates repository rows in offset-paginated chunks.
	 *
	 * Chunk size is clamped to 1..1000. The callback receives rows, the 1-based page
	 * number, and the cumulative processed count; returning false stops iteration
	 * after the current chunk.
	 *
	 * @param int $size Requested chunk size, clamped to 1..1000.
	 * @param callable $callback Callback receiving rows, page, and processed count.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int Number of rows fetched before exhaustion or early stop.
	 */
	public static function chunk(
		int $size,
		callable $callback,
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): int {
		$size=max(1, min(1000, $size));
		$baseSpec=$spec!==null ? clone $spec : static::spec();
		$page=1;
		$processed=0;
		while(true){
			$rows=static::all($columns, (clone $baseSpec)->forPage($page, $size), $caching);
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
	 * Iterates repository rows one at a time using chunk().
	 *
	 * The callback receives row, 1-based processed count, chunk page, and row index
	 * within the chunk. Returning false stops iteration immediately.
	 *
	 * @param callable $callback Callback receiving row, processed count, page, and chunk index.
	 * @param int $size Requested chunk size, clamped to 1..1000.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int Number of rows passed to the callback.
	 */
	public static function each(
		callable $callback,
		int $size=500,
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): int {
		$processed=0;
		static::chunk(
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
			$spec,
			$caching
		);
		return $processed;
	}

	/**
	 * Iterates hydrated record values in offset-paginated chunks.
	 *
	 * This mirrors chunk() but hydrates each page before invoking the callback.
	 * Returning false stops iteration after the current hydrated chunk.
	 *
	 * @param int $size Requested chunk size, clamped to 1..1000.
	 * @param callable $callback Callback receiving records, page, and processed count.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int Number of hydrated records fetched before exhaustion or early stop.
	 */
	public static function chunkRecords(
		int $size,
		callable $callback,
		array|string $columns='*',
		?QuerySpec $spec=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): int {
		$size=max(1, min(1000, $size));
		$baseSpec=$spec!==null ? clone $spec : static::spec();
		$page=1;
		$processed=0;
		while(true){
			$records=static::allRecords($columns, (clone $baseSpec)->forPage($page, $size), $hydrator, $caching);
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
	 * Iterates hydrated record values one at a time using chunkRecords().
	 *
	 * The callback receives record, 1-based processed count, chunk page, and record
	 * index within the chunk. Returning false stops iteration immediately.
	 *
	 * @param callable $callback Callback receiving record, processed count, page, and chunk index.
	 * @param int $size Requested chunk size, clamped to 1..1000.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return int Number of records passed to the callback.
	 */
	public static function eachRecord(
		callable $callback,
		int $size=500,
		array|string $columns='*',
		?QuerySpec $spec=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null
	): int {
		$processed=0;
		static::chunkRecords(
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
			$spec,
			$hydrator,
			$caching
		);
		return $processed;
	}

	/**
	 * Iterates rows in keyset chunks using a sortable key column.
	 *
	 * The key column defaults to the repository primary key and is forced into the
	 * select list. Each query removes caller ordering, orders by the key column, and
	 * advances with greater-than or less-than predicates based on direction. Returning
	 * false stops after the current chunk.
	 *
	 * @param int $size Requested chunk size, clamped to 1..1000.
	 * @param callable $callback Callback receiving rows, next cursor, and processed count.
	 * @param string|null $keyColumn Cursor column, or null for repository primary key.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string $direction ASC or DESC keyset direction.
	 * @return int Number of rows fetched before exhaustion or early stop.
	 * @throws SqlError When no key column can be resolved or direction is invalid.
	 */
	public static function chunkById(
		int $size,
		callable $callback,
		?string $keyColumn=null,
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		string $direction='ASC'
	): int {
		$keyColumn=static::resolvedKeyColumn($keyColumn);
		$direction=static::normalizedKeysetDirection($direction);
		$size=max(1, min(1000, $size));
		$baseSpec=$spec!==null ? clone $spec : static::spec();
		$lastKey=null;
		$processed=0;
		while(true){
			$query=(clone $baseSpec)->withoutOrdering()->orderBy($keyColumn, $direction)->limit($size);
			if($lastKey!==null){
				$direction==='DESC'
					? $query->whereLt($keyColumn, $lastKey)
					: $query->whereGt($keyColumn, $lastKey);
			}
			$rows=static::all(static::keyColumns($keyColumn, $columns), $query, $caching);
			if($rows===[]){
				break;
			}
			$processed+=count($rows);
			$nextKey=static::lastKeyFromRows($rows, $keyColumn);
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
	 * Iterates rows one at a time using keyset chunking.
	 *
	 * The callback receives row, 1-based processed count, current cursor, and index
	 * within the chunk. Returning false stops iteration immediately.
	 *
	 * @param callable $callback Callback receiving row, processed count, cursor, and chunk index.
	 * @param int $size Requested chunk size, clamped to 1..1000.
	 * @param string|null $keyColumn Cursor column, or null for repository primary key.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string $direction ASC or DESC keyset direction.
	 * @return int Number of rows passed to the callback.
	 * @throws SqlError When no key column can be resolved or direction is invalid.
	 */
	public static function eachById(
		callable $callback,
		int $size=500,
		?string $keyColumn=null,
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		string $direction='ASC'
	): int {
		$processed=0;
		static::chunkById(
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
			$spec,
			$caching,
			$direction
		);
		return $processed;
	}

	/**
	 * Iterates hydrated record values in keyset chunks.
	 *
	 * Cursor movement is calculated from the raw rows so hydration can return custom
	 * values without losing the keyset cursor. Returning false stops after the
	 * current hydrated chunk.
	 *
	 * @param int $size Requested chunk size, clamped to 1..1000.
	 * @param callable $callback Callback receiving records, next cursor, and processed count.
	 * @param string|null $keyColumn Cursor column, or null for repository primary key.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string $direction ASC or DESC keyset direction.
	 * @return int Number of hydrated records fetched before exhaustion or early stop.
	 * @throws SqlError When no key column can be resolved or direction is invalid.
	 */
	public static function chunkRecordsById(
		int $size,
		callable $callback,
		?string $keyColumn=null,
		array|string $columns='*',
		?QuerySpec $spec=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		string $direction='ASC'
	): int {
		$keyColumn=static::resolvedKeyColumn($keyColumn);
		$direction=static::normalizedKeysetDirection($direction);
		$size=max(1, min(1000, $size));
		$baseSpec=$spec!==null ? clone $spec : static::spec();
		$lastKey=null;
		$processed=0;
		while(true){
			$query=(clone $baseSpec)->withoutOrdering()->orderBy($keyColumn, $direction)->limit($size);
			if($lastKey!==null){
				$direction==='DESC'
					? $query->whereLt($keyColumn, $lastKey)
					: $query->whereGt($keyColumn, $lastKey);
			}
			$rows=static::all(static::keyColumns($keyColumn, $columns), $query, $caching);
			if($rows===[]){
				break;
			}
			$records=static::hydrateRows($rows, $hydrator);
			$processed+=count($records);
			$nextKey=static::lastKeyFromRows($rows, $keyColumn);
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
	 * Iterates hydrated record values one at a time using keyset chunking.
	 *
	 * The callback receives record, 1-based processed count, current cursor, and
	 * index within the chunk. Returning false stops iteration immediately.
	 *
	 * @param callable $callback Callback receiving record, processed count, cursor, and chunk index.
	 * @param int $size Requested chunk size, clamped to 1..1000.
	 * @param string|null $keyColumn Cursor column, or null for repository primary key.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param mixed $hydrator Hydrator override for row-to-record conversion.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param string $direction ASC or DESC keyset direction.
	 * @return int Number of records passed to the callback.
	 * @throws SqlError When no key column can be resolved or direction is invalid.
	 */
	public static function eachRecordById(
		callable $callback,
		int $size=500,
		?string $keyColumn=null,
		array|string $columns='*',
		?QuerySpec $spec=null,
		mixed $hydrator=null,
		bool|array|string|null $caching=null,
		string $direction='ASC'
	): int {
		$processed=0;
		static::chunkRecordsById(
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
			$spec,
			$hydrator,
			$caching,
			$direction
		);
		return $processed;
	}

	/**
	 * Inserts one row and normalizes the SQL result.
	 *
	 * Fields pass through money expansion and schema/identifier validation before
	 * insertion. The raw SQL result is wrapped in a MutationResult with repository
	 * context for diagnostics and inserted-id extraction.
	 *
	 * @param array<string, mixed> $fields Write fields before money and schema normalization.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized insert result.
	 * @throws SqlError When fields are empty, invalid, or rejected by schema metadata.
	 */
	public static function create(
		array $fields,
		bool|array|null $clearCache=null
	): MutationResult {
		return MutationResult::fromRaw(
			'insert',
			static::insertOne(static::fields($fields), static::resolveWriteInvalidation($clearCache)),
			static::mutationContext()
		);
	}

	/**
	 * Queues one insert through the SQL kernel.
	 *
	 * Field normalization and write invalidation resolution happen before queue
	 * registration. The callback receives the raw queued insert result, not a
	 * MutationResult wrapper.
	 *
	 * @param array<string, mixed> $fields Write fields before money and schema normalization.
	 * @param callable $callback Callback invoked with the raw insert result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When fields are empty, invalid, or rejected by schema metadata.
	 */
	public static function queueCreate(
		array $fields,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		return sql_insert(
			static::table(),
			static::fields($fields),
			null,
			static::resolveWriteInvalidation($clearCache),
			$queue,
			$callback
		);
	}

	/**
	 * Queues a batch of inserts and reports them as a MutationBatchResult.
	 *
	 * Each array row is queued through queueCreate(). The batch helper records how
	 * many rows were requested and invokes the caller callback once child results
	 * have been collected.
	 *
	 * @param array<int, mixed> $rows Candidate insert rows; non-arrays count as requested but are not queued.
	 * @param callable $callback Callback invoked with the MutationBatchResult.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the batch coordinator.
	 */
	public static function queueCreateMany(
		array $rows,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		return static::queueMutationBatch(
			'insert',
			$rows,
			$callback,
			static fn(array $row, callable $rowCallback): null|bool => static::queueCreate($row, $rowCallback, $queue, $clearCache)
		);
	}

	/**
	 * Inserts many rows and aggregates per-row MutationResult values.
	 *
	 * Non-array entries are skipped during processing but still counted as requested
	 * in the returned batch result, making incomplete input visible to callers.
	 *
	 * @param array<int, mixed> $rows Candidate insert rows.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationBatchResult Batch insert accounting and child results.
	 */
	public static function createMany(
		array $rows,
		bool|array|null $clearCache=null
	): MutationBatchResult {
		$results=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$results[]=static::create($row, $clearCache);
		}
		return new MutationBatchResult('insert', $results, count($rows));
	}

	/**
	 * Finds the first row by attributes or creates it.
	 *
	 * Attribute and value payloads are normalized through fields(). Existing rows are
	 * returned without mutation. Newly created rows are reloaded from the attribute
	 * lookup with caching disabled so callers receive storage state after insert.
	 *
	 * @param array<string, mixed> $attributes Lookup fields and insert base fields.
	 * @param array<string, mixed> $values Additional insert fields when no row exists.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return array<string, mixed> Existing or newly loaded row.
	 * @throws \RuntimeException When the insert mutation fails.
	 * @throws RecordNotFoundException When a successful insert cannot be reloaded.
	 */
	public static function firstOrCreate(
		array $attributes,
		array $values=[],
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		bool|array|null $clearCache=null
	): array {
		$attributes=static::fields($attributes);
		$values=$values!==[] ? static::fields($values) : [];
		$lookup=static::specForAttributes($attributes, $spec);
		$existing=static::first($columns, $lookup, $caching);
		if($existing!==null){
			return $existing;
		}
		$result=static::create($attributes + $values, $clearCache);
		static::assertMutationSucceeded($result);
		$created=static::first($columns, $lookup, false);
		if($created!==null){
			return $created;
		}
		throw SqlError::recordNotFound(
			static::class,
			static::notFoundContext($columns, $lookup),
			'The row was created, but could not be loaded from the lookup attributes.'
		);
	}

	/**
	 * Finds a row by attributes, then updates or creates it.
	 *
	 * Existing rows are updated only with value fields that are not part of the
	 * lookup attributes. When no value fields remain, the existing row is returned
	 * unchanged. Successful writes are reloaded with caching disabled.
	 *
	 * @param array<string, mixed> $attributes Lookup fields and insert base fields.
	 * @param array<string, mixed> $values Fields to insert or update.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return array<string, mixed> Existing, created, or reloaded updated row.
	 * @throws \RuntimeException When the insert or update mutation fails.
	 * @throws RecordNotFoundException When a successful write cannot be reloaded.
	 */
	public static function updateOrCreate(
		array $attributes,
		array $values=[],
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		bool|array|null $clearCache=null
	): array {
		$attributes=static::fields($attributes);
		$values=$values!==[] ? static::fields($values) : [];
		$lookup=static::specForAttributes($attributes, $spec);
		$existing=static::first($columns, $lookup, $caching);
		if($existing===null){
			$result=static::create($attributes + $values, $clearCache);
			static::assertMutationSucceeded($result);
			$created=static::first($columns, $lookup, false);
			if($created!==null){
				return $created;
			}
			throw SqlError::recordNotFound(
				static::class,
				static::notFoundContext($columns, $lookup),
				'The row was created, but could not be loaded from the lookup attributes.'
			);
		}
		$updateFields=array_diff_key($values, $attributes);
		if($updateFields===[]){
			return $existing;
		}
		static::assertMutationSucceeded(static::update($updateFields, $lookup, $clearCache));
		$updated=static::first($columns, $lookup, false);
		if($updated!==null){
			return $updated;
		}
		throw SqlError::recordNotFound(
			static::class,
			static::notFoundContext($columns, $lookup),
			'The row was updated, but could not be loaded from the lookup attributes.'
		);
	}

	/**
	 * Updates rows matched by a scoped QuerySpec.
	 *
	 * Field payloads are normalized before dispatch. Repositories that require write
	 * scopes fail before SQL when the compiled spec has no WHERE constraints.
	 *
	 * @param array<string, mixed> $fields Write fields before money and schema normalization.
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized update result.
	 * @throws SqlError When fields are invalid or the repository write-scope policy is violated.
	 */
	public static function update(
		array $fields,
		QuerySpec $spec,
		bool|array|null $clearCache=null
	): MutationResult {
		static::assertWriteScope($spec, 'update');
		return MutationResult::fromRaw(
			'update',
			static::updateWhere(static::fields($fields), $spec, static::resolveWriteInvalidation($clearCache)),
			static::mutationContext()
		);
	}

	/**
	 * Increments a numeric column for rows matched by a scoped QuerySpec.
	 *
	 * Counter columns are validated as repository field identifiers and the amount is
	 * bound into the SQL expression. The mutation returns affected-row accounting.
	 *
	 * @param string $column Counter column to update.
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param int|float $amount Positive or negative amount to add.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized counter update result.
	 * @throws SqlError When the column, amount, or write scope is invalid.
	 */
	public static function increment(
		string $column,
		QuerySpec $spec,
		int|float $amount=1,
		bool|array|null $clearCache=null
	): MutationResult {
		return static::counterMutation('increment', $column, '+', $amount, $spec, $clearCache);
	}

	/**
	 * Decrements a numeric column for rows matched by a scoped QuerySpec.
	 *
	 * This delegates to the shared counter mutation path with a subtraction operator.
	 *
	 * @param string $column Counter column to update.
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param int|float $amount Positive or negative amount to subtract.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized counter update result.
	 * @throws SqlError When the column, amount, or write scope is invalid.
	 */
	public static function decrement(
		string $column,
		QuerySpec $spec,
		int|float $amount=1,
		bool|array|null $clearCache=null
	): MutationResult {
		return static::counterMutation('decrement', $column, '-', $amount, $spec, $clearCache);
	}

	/**
	 * Updates rows matched by a scoped QuerySpec with optimistic locking.
	 *
	 * The expected version predicate is added to a cloned spec, the version column is
	 * managed by the helper, and zero affected rows are represented as a stale
	 * MutationResult. The supplied field payload must not contain the version column.
	 *
	 * @param array<string, mixed> $fields Write fields excluding the version column.
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param int $expectedVersion Current version expected in storage.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param int $bump Positive version increment applied on success.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized versioned update result, including stale state.
	 * @throws SqlError When version metadata, fields, or write scope are invalid.
	 */
	public static function updateWithVersion(
		array $fields,
		QuerySpec $spec,
		int $expectedVersion,
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): MutationResult {
		$versionColumn=static::resolvedCounterColumn($versionColumn);
		$expectedVersion=static::resolvedExpectedVersion($versionColumn, $expectedVersion);
		$bump=static::resolvedVersionBump($versionColumn, $bump);
		$fields=$fields!==[] ? static::fields($fields) : [];
		static::assertVersionColumnNotInFields($fields, $versionColumn);
		$spec=(clone $spec)->whereEq($versionColumn, $expectedVersion);
		static::assertWriteScope($spec, 'update_with_version');
		return MutationResult::fromRaw(
			'update_with_version',
			static::updateVersionWhere(
				$fields,
				$versionColumn,
				$bump,
				$spec,
				static::resolveWriteInvalidation($clearCache)
			),
			array_merge(static::mutationContext(), [
				'version_column'=>$versionColumn,
				'expected_version'=>$expectedVersion,
				'next_version'=>$expectedVersion+$bump,
				'version_bump'=>$bump,
			])
		);
	}

	/**
	 * Updates rows with optimistic locking and throws on failure or staleness.
	 *
	 * SQL/kernel failures are raised before stale version conflicts.
	 *
	 * @param array<string, mixed> $fields Write fields excluding the version column.
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param int $expectedVersion Current version expected in storage.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param int $bump Positive version increment applied on success.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Successful non-stale mutation result.
	 * @throws \RuntimeException When the SQL mutation fails.
	 * @throws OptimisticLockException When no row matched the expected version.
	 */
	public static function updateWithVersionOrFail(
		array $fields,
		QuerySpec $spec,
		int $expectedVersion,
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): MutationResult {
		return static::updateWithVersion($fields, $spec, $expectedVersion, $versionColumn, $bump, $clearCache)->throwIfFailedOrStale();
	}

	/**
	 * Queues an optimistic-lock update and wraps callback results.
	 *
	 * Validation mirrors updateWithVersion(). The callback receives a MutationResult
	 * built from the queued raw SQL result and version context.
	 *
	 * @param array<string, mixed> $fields Write fields excluding the version column.
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param int $expectedVersion Current version expected in storage.
	 * @param callable $callback Callback invoked with a MutationResult.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param int $bump Positive version increment applied on success.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When version metadata, fields, or write scope are invalid.
	 */
	public static function queueUpdateWithVersion(
		array $fields,
		QuerySpec $spec,
		int $expectedVersion,
		callable $callback,
		string $queue='end',
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): null|bool {
		$versionColumn=static::resolvedCounterColumn($versionColumn);
		$expectedVersion=static::resolvedExpectedVersion($versionColumn, $expectedVersion);
		$bump=static::resolvedVersionBump($versionColumn, $bump);
		$fields=$fields!==[] ? static::fields($fields) : [];
		static::assertVersionColumnNotInFields($fields, $versionColumn);
		$spec=(clone $spec)->whereEq($versionColumn, $expectedVersion);
		static::assertWriteScope($spec, 'queue_update_with_version');
		$compiled=$spec->compile(false);
		$context=array_merge(static::mutationContext(), [
			'version_column'=>$versionColumn,
			'expected_version'=>$expectedVersion,
			'next_version'=>$expectedVersion+$bump,
			'version_bump'=>$bump,
		]);
		return sql_update(
			static::table(),
			static::versionedUpdateFields($fields, $versionColumn),
			$compiled['params'],
			array_merge(array_values($fields), [$bump], $compiled['vars']),
			static::resolveWriteInvalidation($clearCache),
			$queue,
			static fn(mixed $result): mixed => $callback(MutationResult::fromRaw('update_with_version', $result, $context))
		);
	}

	/**
	 * Queues an optimistic-lock update that throws from the callback on failure.
	 *
	 * The caller callback receives only a successful non-stale MutationResult.
	 *
	 * @param array<string, mixed> $fields Write fields excluding the version column.
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param int $expectedVersion Current version expected in storage.
	 * @param callable $callback Callback invoked with a successful MutationResult.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param int $bump Positive version increment applied on success.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueUpdateWithVersionOrFail(
		array $fields,
		QuerySpec $spec,
		int $expectedVersion,
		callable $callback,
		string $queue='end',
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): null|bool {
		return static::queueUpdateWithVersion(
			$fields,
			$spec,
			$expectedVersion,
			static fn(MutationResult $result): mixed => $callback($result->throwIfFailedOrStale()),
			$queue,
			$versionColumn,
			$bump,
			$clearCache
		);
	}

	/**
	 * Queues a scoped update through the SQL kernel.
	 *
	 * The write-scope guard runs before compilation. The queued callback receives the
	 * raw SQL update result, not a MutationResult wrapper.
	 *
	 * @param array<string, mixed> $fields Write fields before money and schema normalization.
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the raw update result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When fields are invalid or the repository write-scope policy is violated.
	 */
	public static function queueUpdate(
		array $fields,
		QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		static::assertWriteScope($spec, 'queue_update');
		$compiled=$spec->compile(false);
		return sql_update(
			static::table(),
			static::fields($fields),
			$compiled['params'],
			$compiled['vars'],
			static::resolveWriteInvalidation($clearCache),
			$queue,
			$callback
		);
	}

	/**
	 * Queues an atomic increment mutation.
	 *
	 * The queued callback receives the raw SQL update result from the counter update.
	 *
	 * @param string $column Counter column to update.
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the raw update result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param int|float $amount Positive or negative amount to add.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueIncrement(
		string $column,
		QuerySpec $spec,
		callable $callback,
		string $queue='end',
		int|float $amount=1,
		bool|array|null $clearCache=null
	): null|bool {
		return static::queueCounterMutation('queue_increment', $column, '+', $amount, $spec, $callback, $queue, $clearCache);
	}

	/**
	 * Queues an atomic decrement mutation.
	 *
	 * The queued callback receives the raw SQL update result from the counter update.
	 *
	 * @param string $column Counter column to update.
	 * @param QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param callable $callback Callback invoked with the raw update result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param int|float $amount Positive or negative amount to subtract.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueDecrement(
		string $column,
		QuerySpec $spec,
		callable $callback,
		string $queue='end',
		int|float $amount=1,
		bool|array|null $clearCache=null
	): null|bool {
		return static::queueCounterMutation('queue_decrement', $column, '-', $amount, $spec, $callback, $queue, $clearCache);
	}

	/**
	 * Updates rows matching one equality predicate.
	 *
	 * This is a convenience wrapper around update() with a fresh whereEq() spec.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array<string, mixed> $fields Write fields before money and schema normalization.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized update result.
	 */
	public static function updateBy(
		string $column,
		mixed $value,
		array $fields,
		bool|array|null $clearCache=null
	): MutationResult {
		return static::update($fields, static::spec()->whereEq($column, $value), $clearCache);
	}

	/**
	 * Queues an update for rows matching one equality predicate.
	 *
	 * This is a convenience wrapper around queueUpdate() with a fresh whereEq() spec.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array<string, mixed> $fields Write fields before money and schema normalization.
	 * @param callable $callback Callback invoked with the raw update result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueUpdateBy(
		string $column,
		mixed $value,
		array $fields,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		return static::queueUpdate($fields, static::spec()->whereEq($column, $value), $callback, $queue, $clearCache);
	}

	/**
	 * Updates rows matching the repository primary key.
	 *
	 * The repository must expose a schema-backed primary key before the equality
	 * update wrapper can build its scoped QuerySpec.
	 *
	 * @param mixed $id Primary-key value.
	 * @param array<string, mixed> $fields Write fields before money and schema normalization.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized update result.
	 * @throws SqlError When the repository has no primary key or fields are invalid.
	 */
	public static function updateById(
		mixed $id,
		array $fields,
		bool|array|null $clearCache=null
	): MutationResult {
		$primaryKey=static::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform updateById(...)');
		}
		return static::updateBy($primaryKey, $id, $fields, $clearCache);
	}

	/**
	 * Queues an update for rows matching the repository primary key.
	 *
	 * The queued callback receives the raw SQL update result from queueUpdateBy().
	 *
	 * @param mixed $id Primary-key value.
	 * @param array<string, mixed> $fields Write fields before money and schema normalization.
	 * @param callable $callback Callback invoked with the raw update result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When the repository has no primary key or fields are invalid.
	 */
	public static function queueUpdateById(
		mixed $id,
		array $fields,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		$primaryKey=static::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform queueUpdateById(...)');
		}
		return static::queueUpdateBy($primaryKey, $id, $fields, $callback, $queue, $clearCache);
	}

	/**
	 * Updates rows matching one equality predicate with optimistic locking.
	 *
	 * This wraps updateWithVersion() with a fresh whereEq() lookup scope.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array<string, mixed> $fields Write fields excluding the version column.
	 * @param int $expectedVersion Current version expected in storage.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param int $bump Positive version increment applied on success.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized versioned update result.
	 */
	public static function updateByWithVersion(
		string $column,
		mixed $value,
		array $fields,
		int $expectedVersion,
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): MutationResult {
		return static::updateWithVersion(
			$fields,
			static::spec()->whereEq($column, $value),
			$expectedVersion,
			$versionColumn,
			$bump,
			$clearCache
		);
	}

	/**
	 * Queues an optimistic-lock update for rows matching one equality predicate.
	 *
	 * The callback receives a MutationResult wrapper from queueUpdateWithVersion().
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array<string, mixed> $fields Write fields excluding the version column.
	 * @param int $expectedVersion Current version expected in storage.
	 * @param callable $callback Callback invoked with a MutationResult.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param int $bump Positive version increment applied on success.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueUpdateByWithVersion(
		string $column,
		mixed $value,
		array $fields,
		int $expectedVersion,
		callable $callback,
		string $queue='end',
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): null|bool {
		return static::queueUpdateWithVersion(
			$fields,
			static::spec()->whereEq($column, $value),
			$expectedVersion,
			$callback,
			$queue,
			$versionColumn,
			$bump,
			$clearCache
		);
	}

	/**
	 * Updates equality-scoped rows with optimistic locking and throws on failure or staleness.
	 *
	 * SQL/kernel failures are raised before stale version conflicts.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array<string, mixed> $fields Write fields excluding the version column.
	 * @param int $expectedVersion Current version expected in storage.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param int $bump Positive version increment applied on success.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Successful non-stale mutation result.
	 * @throws \RuntimeException When the SQL mutation fails.
	 * @throws OptimisticLockException When no row matched the expected version.
	 */
	public static function updateByWithVersionOrFail(
		string $column,
		mixed $value,
		array $fields,
		int $expectedVersion,
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): MutationResult {
		return static::updateByWithVersion($column, $value, $fields, $expectedVersion, $versionColumn, $bump, $clearCache)->throwIfFailedOrStale();
	}

	/**
	 * Queues a fail-fast equality-scoped optimistic-lock update.
	 *
	 * The caller callback receives only a successful non-stale MutationResult.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param array<string, mixed> $fields Write fields excluding the version column.
	 * @param int $expectedVersion Current version expected in storage.
	 * @param callable $callback Callback invoked with a successful MutationResult.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param int $bump Positive version increment applied on success.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueUpdateByWithVersionOrFail(
		string $column,
		mixed $value,
		array $fields,
		int $expectedVersion,
		callable $callback,
		string $queue='end',
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): null|bool {
		return static::queueUpdateByWithVersion(
			$column,
			$value,
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
	 * Updates the repository primary-key row with optimistic locking.
	 *
	 * The repository must expose a schema-backed primary key before the versioned
	 * update scope can be built.
	 *
	 * @param mixed $id Primary-key value.
	 * @param array<string, mixed> $fields Write fields excluding the version column.
	 * @param int $expectedVersion Current version expected in storage.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param int $bump Positive version increment applied on success.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized versioned update result.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function updateByIdWithVersion(
		mixed $id,
		array $fields,
		int $expectedVersion,
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): MutationResult {
		$primaryKey=static::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform updateByIdWithVersion(...)');
		}
		return static::updateByWithVersion($primaryKey, $id, $fields, $expectedVersion, $versionColumn, $bump, $clearCache);
	}

	/**
	 * Queues an optimistic-lock update for the repository primary key.
	 *
	 * The callback receives a MutationResult wrapper from queueUpdateByWithVersion().
	 *
	 * @param mixed $id Primary-key value.
	 * @param array<string, mixed> $fields Write fields excluding the version column.
	 * @param int $expectedVersion Current version expected in storage.
	 * @param callable $callback Callback invoked with a MutationResult.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param int $bump Positive version increment applied on success.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function queueUpdateByIdWithVersion(
		mixed $id,
		array $fields,
		int $expectedVersion,
		callable $callback,
		string $queue='end',
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): null|bool {
		$primaryKey=static::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform queueUpdateByIdWithVersion(...)');
		}
		return static::queueUpdateByWithVersion(
			$primaryKey,
			$id,
			$fields,
			$expectedVersion,
			$callback,
			$queue,
			$versionColumn,
			$bump,
			$clearCache
		);
	}

	/**
	 * Performs a primary-key optimistic-lock update and throws on failure or staleness.
	 *
	 * This is the fail-fast companion to updateByIdWithVersion().
	 *
	 * @param mixed $id Primary-key value.
	 * @param array<string, mixed> $fields Write fields excluding the version column.
	 * @param int $expectedVersion Current version expected in storage.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param int $bump Positive version increment applied on success.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Successful non-stale mutation result.
	 * @throws SqlError When the repository has no primary key.
	 * @throws \RuntimeException When the SQL mutation fails.
	 * @throws OptimisticLockException When no row matched the expected version.
	 */
	public static function updateByIdWithVersionOrFail(
		mixed $id,
		array $fields,
		int $expectedVersion,
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): MutationResult {
		return static::updateByIdWithVersion($id, $fields, $expectedVersion, $versionColumn, $bump, $clearCache)->throwIfFailedOrStale();
	}

	/**
	 * Queues a fail-fast primary-key optimistic-lock update.
	 *
	 * The caller callback receives only a successful non-stale MutationResult.
	 *
	 * @param mixed $id Primary-key value.
	 * @param array<string, mixed> $fields Write fields excluding the version column.
	 * @param int $expectedVersion Current version expected in storage.
	 * @param callable $callback Callback invoked with a successful MutationResult.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param int $bump Positive version increment applied on success.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueUpdateByIdWithVersionOrFail(
		mixed $id,
		array $fields,
		int $expectedVersion,
		callable $callback,
		string $queue='end',
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): null|bool {
		return static::queueUpdateByIdWithVersion(
			$id,
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
	 * Increments a counter on rows matching one equality predicate.
	 *
	 * Counter column names are resolved through schema metadata when available and
	 * otherwise pass the repository identifier guard before reaching the SQL kernel.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param string $counterColumn Numeric column to increment.
	 * @param int|float $amount Non-negative increment amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized counter mutation result.
	 */
	public static function incrementBy(
		string $column,
		mixed $value,
		string $counterColumn,
		int|float $amount=1,
		bool|array|null $clearCache=null
	): MutationResult {
		return static::increment($counterColumn, static::spec()->whereEq($column, $value), $amount, $clearCache);
	}

	/**
	 * Queues an increment for rows matching one equality predicate.
	 *
	 * The callback receives the raw SQL update result emitted by the kernel; callers
	 * that need a wrapper should use the synchronous MutationResult path.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param string $counterColumn Numeric column to increment.
	 * @param callable $callback Callback invoked with the raw update result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param int|float $amount Non-negative increment amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueIncrementBy(
		string $column,
		mixed $value,
		string $counterColumn,
		callable $callback,
		string $queue='end',
		int|float $amount=1,
		bool|array|null $clearCache=null
	): null|bool {
		return static::queueIncrement($counterColumn, static::spec()->whereEq($column, $value), $callback, $queue, $amount, $clearCache);
	}

	/**
	 * Increments a counter on the row matching the repository primary key.
	 *
	 * The repository must expose a schema-backed primary key before the equality
	 * counter scope can be built.
	 *
	 * @param mixed $id Primary-key value.
	 * @param string $counterColumn Numeric column to increment.
	 * @param int|float $amount Non-negative increment amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized counter mutation result.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function incrementById(
		mixed $id,
		string $counterColumn,
		int|float $amount=1,
		bool|array|null $clearCache=null
	): MutationResult {
		$primaryKey=static::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform incrementById(...)');
		}
		return static::incrementBy($primaryKey, $id, $counterColumn, $amount, $clearCache);
	}

	/**
	 * Queues an increment for the row matching the repository primary key.
	 *
	 * The callback receives the raw SQL update result from queueIncrementBy().
	 *
	 * @param mixed $id Primary-key value.
	 * @param string $counterColumn Numeric column to increment.
	 * @param callable $callback Callback invoked with the raw update result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param int|float $amount Non-negative increment amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function queueIncrementById(
		mixed $id,
		string $counterColumn,
		callable $callback,
		string $queue='end',
		int|float $amount=1,
		bool|array|null $clearCache=null
	): null|bool {
		$primaryKey=static::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform queueIncrementById(...)');
		}
		return static::queueIncrementBy($primaryKey, $id, $counterColumn, $callback, $queue, $amount, $clearCache);
	}

	/**
	 * Decrements a counter on rows matching one equality predicate.
	 *
	 * Amounts are validated as non-negative numbers; the decrement operator supplies
	 * the direction of change.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param string $counterColumn Numeric column to decrement.
	 * @param int|float $amount Non-negative decrement amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized counter mutation result.
	 */
	public static function decrementBy(
		string $column,
		mixed $value,
		string $counterColumn,
		int|float $amount=1,
		bool|array|null $clearCache=null
	): MutationResult {
		return static::decrement($counterColumn, static::spec()->whereEq($column, $value), $amount, $clearCache);
	}

	/**
	 * Queues a decrement for rows matching one equality predicate.
	 *
	 * The callback receives the raw SQL update result emitted by the queued kernel call.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param string $counterColumn Numeric column to decrement.
	 * @param callable $callback Callback invoked with the raw update result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param int|float $amount Non-negative decrement amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueDecrementBy(
		string $column,
		mixed $value,
		string $counterColumn,
		callable $callback,
		string $queue='end',
		int|float $amount=1,
		bool|array|null $clearCache=null
	): null|bool {
		return static::queueDecrement($counterColumn, static::spec()->whereEq($column, $value), $callback, $queue, $amount, $clearCache);
	}

	/**
	 * Decrements a counter on the row matching the repository primary key.
	 *
	 * The repository must expose a schema-backed primary key before the equality
	 * counter scope can be built.
	 *
	 * @param mixed $id Primary-key value.
	 * @param string $counterColumn Numeric column to decrement.
	 * @param int|float $amount Non-negative decrement amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized counter mutation result.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function decrementById(
		mixed $id,
		string $counterColumn,
		int|float $amount=1,
		bool|array|null $clearCache=null
	): MutationResult {
		$primaryKey=static::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform decrementById(...)');
		}
		return static::decrementBy($primaryKey, $id, $counterColumn, $amount, $clearCache);
	}

	/**
	 * Queues a decrement for the row matching the repository primary key.
	 *
	 * The callback receives the raw SQL update result from queueDecrementBy().
	 *
	 * @param mixed $id Primary-key value.
	 * @param string $counterColumn Numeric column to decrement.
	 * @param callable $callback Callback invoked with the raw update result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param int|float $amount Non-negative decrement amount.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function queueDecrementById(
		mixed $id,
		string $counterColumn,
		callable $callback,
		string $queue='end',
		int|float $amount=1,
		bool|array|null $clearCache=null
	): null|bool {
		$primaryKey=static::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform queueDecrementById(...)');
		}
		return static::queueDecrementBy($primaryKey, $id, $counterColumn, $callback, $queue, $amount, $clearCache);
	}

	/**
	 * Deletes rows matching a scoped QuerySpec.
	 *
	 * The write-scope guard runs before SQL compilation so repositories that require
	 * WHERE clauses cannot accidentally perform table-wide deletes.
	 *
	 * @param QuerySpec $spec Delete constraints to compile for the SQL kernel.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized delete result.
	 */
	public static function delete(
		QuerySpec $spec,
		bool|array|null $clearCache=null
	): MutationResult {
		static::assertWriteScope($spec, 'delete');
		return MutationResult::fromRaw(
			'delete',
			static::deleteWhere($spec, static::resolveWriteInvalidation($clearCache)),
			static::mutationContext()
		);
	}

	/**
	 * Queues a delete for rows matching a scoped QuerySpec.
	 *
	 * The callback receives the raw SQL delete result; this method does not wrap it
	 * in MutationResult because the kernel owns queued callback invocation.
	 *
	 * @param QuerySpec $spec Delete constraints to compile for the SQL kernel.
	 * @param callable $callback Callback invoked with the raw delete result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueDelete(
		QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		static::assertWriteScope($spec, 'queue_delete');
		$compiled=$spec->compile(false);
		return sql_delete(
			static::table(),
			$compiled['params'],
			$compiled['vars'],
			static::resolveWriteInvalidation($clearCache),
			$queue,
			$callback
		);
	}

	/**
	 * Deletes rows matching one equality predicate.
	 *
	 * This is a convenience wrapper around delete() with a fresh whereEq() spec.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized delete result.
	 */
	public static function deleteBy(
		string $column,
		mixed $value,
		bool|array|null $clearCache=null
	): MutationResult {
		return static::delete(static::spec()->whereEq($column, $value), $clearCache);
	}

	/**
	 * Queues a delete for rows matching one equality predicate.
	 *
	 * This is a convenience wrapper around queueDelete() with a fresh whereEq() spec.
	 *
	 * @param string $column Lookup column.
	 * @param mixed $value Lookup value bound by QuerySpec.
	 * @param callable $callback Callback invoked with the raw delete result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueDeleteBy(
		string $column,
		mixed $value,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		return static::queueDelete(static::spec()->whereEq($column, $value), $callback, $queue, $clearCache);
	}

	/**
	 * Deletes the row matching the repository primary key.
	 *
	 * The repository must expose a schema-backed primary key before the equality
	 * delete scope can be built.
	 *
	 * @param mixed $id Primary-key value.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized delete result.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function deleteById(
		mixed $id,
		bool|array|null $clearCache=null
	): MutationResult {
		$primaryKey=static::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform deleteById(...)');
		}
		return static::deleteBy($primaryKey, $id, $clearCache);
	}

	/**
	 * Queues a delete for the row matching the repository primary key.
	 *
	 * The callback receives the raw SQL delete result from queueDeleteBy().
	 *
	 * @param mixed $id Primary-key value.
	 * @param callable $callback Callback invoked with the raw delete result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 * @throws SqlError When the repository has no primary key.
	 */
	public static function queueDeleteById(
		mixed $id,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		$primaryKey=static::primaryKey();
		if($primaryKey===null){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform queueDeleteById(...)');
		}
		return static::queueDeleteBy($primaryKey, $id, $callback, $queue, $clearCache);
	}

	/**
	 * Inserts or updates one row through the SQL kernel.
	 *
	 * Field payloads are normalized before sql_upsert() runs inside the schema
	 * hydration retry wrapper, then the raw kernel payload is wrapped as an upsert
	 * MutationResult.
	 *
	 * @param array<string, mixed> $fields Write fields before money and schema normalization.
	 * @param string|array|null $updateParams DBMS-specific update expression or parameter map.
	 * @param ?array $updateVars Variables bound to the update expression.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized upsert result.
	 */
	public static function upsert(
		array $fields,
		string|array|null $updateParams=null,
		?array $updateVars=null,
		bool|array|null $clearCache=null
	): MutationResult {
		return MutationResult::fromRaw(
			'upsert',
			static::withSchemaHydration(static fn(): mixed => sql_upsert(
					static::table(),
					static::fields($fields),
					$updateParams,
					$updateVars,
					static::resolveWriteInvalidation($clearCache)
				)
			),
			static::mutationContext()
		);
	}

	/**
	 * Queues an insert-or-update operation for one row.
	 *
	 * The callback receives the raw SQL upsert result emitted by the kernel.
	 *
	 * @param array<string, mixed> $fields Write fields before money and schema normalization.
	 * @param callable $callback Callback invoked with the raw upsert result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param string|array|null $updateParams DBMS-specific update expression or parameter map.
	 * @param ?array $updateVars Variables bound to the update expression.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueUpsert(
		array $fields,
		callable $callback,
		string $queue='end',
		string|array|null $updateParams=null,
		?array $updateVars=null,
		bool|array|null $clearCache=null
	): null|bool {
		return sql_upsert(
			static::table(),
			static::fields($fields),
			$updateParams,
			$updateVars,
			static::resolveWriteInvalidation($clearCache),
			$queue,
			$callback
		);
	}

	/**
	 * Queues an upsert batch and reports aggregate mutation state.
	 *
	 * Each array row is queued individually; non-array rows are skipped during
	 * processing but still included in requested batch accounting.
	 *
	 * @param array<int, mixed> $rows Candidate row payloads.
	 * @param callable $callback Callback invoked with a MutationBatchResult.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param string|array|null $updateParams DBMS-specific update expression or parameter map.
	 * @param ?array $updateVars Variables bound to the update expression.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result, or false when any row failed to queue.
	 */
	public static function queueUpsertMany(
		array $rows,
		callable $callback,
		string $queue='end',
		string|array|null $updateParams=null,
		?array $updateVars=null,
		bool|array|null $clearCache=null
	): null|bool {
		return static::queueMutationBatch(
			'upsert',
			$rows,
			$callback,
			static fn(array $row, callable $rowCallback): null|bool => static::queueUpsert(
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
	 * Runs an upsert batch synchronously.
	 *
	 * Non-array rows are ignored while executing, but requested() on the returned
	 * MutationBatchResult preserves the caller-supplied row count.
	 *
	 * @param array<int, mixed> $rows Candidate row payloads.
	 * @param string|array|null $updateParams DBMS-specific update expression or parameter map.
	 * @param ?array $updateVars Variables bound to the update expression.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationBatchResult Batch result preserving requested and processed counts.
	 */
	public static function upsertMany(
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
			$results[]=static::upsert($row, $updateParams, $updateVars, $clearCache);
		}
		return new MutationBatchResult('upsert', $results, count($rows));
	}

	/**
	 * Builds metadata attached to normalized mutation results.
	 *
	 * The context identifies the physical table, concrete repository class, and
	 * primary-key metadata visible when the mutation result was produced.
	 *
	 * @return array{table:string, repository:class-string, primary_key:?string} Mutation context payload.
	 */
	protected static function mutationContext(): array {
		return [
			'table'=>static::table(),
			'repository'=>static::class,
			'primary_key'=>static::primaryKey(),
		];
	}

	/**
	 * Coordinates queued row mutations into a batch callback.
	 *
	 * Array rows are queued individually and wrapped as MutationResult objects as
	 * their callbacks arrive. Non-array rows remain part of requested accounting but
	 * are skipped, so missing processed results make the batch fail.
	 *
	 * @param string $operation Mutation operation name stored on child results.
	 * @param array<int, mixed> $rows Candidate row payloads.
	 * @param callable $callback Callback invoked once with a MutationBatchResult.
	 * @param callable $queueRow Function that queues one array row and accepts a raw-result callback.
	 * @return null|bool False when any row failed to queue; otherwise null.
	 */
	protected static function queueMutationBatch(
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
		$emit=static function()use(&$pending, &$setupComplete, &$results, $requested, $operation, $callback): void{
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
				static function(mixed $rawResult)use(&$results, &$pending, $index, $operation, $emit): void{
					$results[$index]=MutationResult::fromRaw($operation, $rawResult, static::mutationContext());
					$pending--;
					$emit();
				}
			);
			if($result===false){
				$results[$index]=MutationResult::fromRaw(
					$operation,
					false,
					static::mutationContext(),
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
	 * Applies repository write-scope policy to a mutation spec.
	 *
	 * Repositories can require write WHERE clauses through requireWriteWhere();
	 * QuerySpec owns the final assertion and error shape.
	 *
	 * @param QuerySpec $spec Mutation constraints to validate.
	 * @param string $operation Operation name used in diagnostics.
	 * @return void
	 */
	protected static function assertWriteScope(QuerySpec $spec, string $operation): void {
		$spec->assertScopedForWrite(static::class, $operation, static::requireWriteWhere());
	}

	/**
	 * Executes a counter update and wraps the mutation result.
	 *
	 * The method enforces write scoping, schema/identifier validation, and
	 * non-negative amounts before delegating to the raw counter update helper.
	 *
	 * @param string $operation Mutation operation label, typically increment or decrement.
	 * @param string $column Counter column before schema resolution.
	 * @param string $operator SQL arithmetic operator to apply.
	 * @param int|float $amount Non-negative counter amount.
	 * @param QuerySpec $spec Mutation constraints to compile.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return MutationResult Normalized counter mutation result.
	 */
	protected static function counterMutation(
		string $operation,
		string $column,
		string $operator,
		int|float $amount,
		QuerySpec $spec,
		bool|array|null $clearCache=null
	): MutationResult {
		static::assertWriteScope($spec, $operation);
		$column=static::resolvedCounterColumn($column);
		$amount=static::resolvedCounterAmount($column, $amount);
		return MutationResult::fromRaw(
			$operation,
			static::updateCounterWhere($column, $operator, $amount, $spec, static::resolveWriteInvalidation($clearCache)),
			array_merge(static::mutationContext(), ['column'=>$column, 'amount'=>$amount])
		);
	}

	/**
	 * Queues a counter update through the SQL kernel.
	 *
	 * The queued callback receives the raw SQL update result. Counter identifiers,
	 * amounts, and write scope are validated before the queue registration happens.
	 *
	 * @param string $operation Mutation operation label, typically increment or decrement.
	 * @param string $column Counter column before schema resolution.
	 * @param string $operator SQL arithmetic operator to apply.
	 * @param int|float $amount Non-negative counter amount.
	 * @param QuerySpec $spec Mutation constraints to compile.
	 * @param callable $callback Callback invoked with the raw update result.
	 * @param string $queue Queue timing marker accepted by the SQL kernel.
	 * @param bool|array|null $clearCache Dataphyre write invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	protected static function queueCounterMutation(
		string $operation,
		string $column,
		string $operator,
		int|float $amount,
		QuerySpec $spec,
		callable $callback,
		string $queue='end',
		bool|array|null $clearCache=null
	): null|bool {
		static::assertWriteScope($spec, $operation);
		$column=static::resolvedCounterColumn($column);
		$amount=static::resolvedCounterAmount($column, $amount);
		$compiled=$spec->compile(false);
		return sql_update(
			static::table(),
			static::counterFields($column, $operator),
			$compiled['params'],
			array_merge([$amount], $compiled['vars']),
			static::resolveWriteInvalidation($clearCache),
			$queue,
			$callback
		);
	}

	/**
	 * Resolves and validates a counter column identifier.
	 *
	 * Schema-backed repositories use schema column resolution. Repositories without
	 * schema metadata accept only simple dotted SQL identifiers.
	 *
	 * @param string $column Counter column name supplied by the caller.
	 * @return string SQL-ready counter column identifier.
	 * @throws SqlError When the identifier is empty or unsafe.
	 */
	protected static function resolvedCounterColumn(string $column): string {
		$schema=static::schema();
		if($schema!==null){
			return $schema->columns($column);
		}
		$column=trim($column);
		if($column==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $column)!==1){
			throw SqlError::invalidIdentifier('repository counter', $column, static::table());
		}
		return $column;
	}

	/**
	 * Validates a counter mutation amount.
	 *
	 * The amount is treated as magnitude only; increment/decrement direction is
	 * supplied by the SQL operator.
	 *
	 * @param string $column Counter column used in diagnostics.
	 * @param int|float $amount Candidate non-negative finite amount.
	 * @return int|float Validated amount.
	 * @throws SqlError When the amount is negative or not finite.
	 */
	protected static function resolvedCounterAmount(string $column, int|float $amount): int|float {
		if(!is_finite((float)$amount) || $amount<0){
			throw SqlError::invalidCounterAmount(static::class, $column, $amount);
		}
		return $amount;
	}

	/**
	 * Builds DBMS-specific counter assignment expressions.
	 *
	 * The returned map is passed to the SQL kernel so each supported DBMS can quote
	 * the identifier with its native quoting style.
	 *
	 * @param string $column Resolved counter column identifier.
	 * @param string $operator SQL arithmetic operator to apply.
	 * @return array{mysql:string, postgresql:string, sqlite:string} Assignment expressions by DBMS.
	 */
	protected static function counterFields(string $column, string $operator): array {
		return [
			'mysql'=>static::counterFieldForDbms($column, $operator, 'mysql'),
			'postgresql'=>static::counterFieldForDbms($column, $operator, 'postgresql'),
			'sqlite'=>static::counterFieldForDbms($column, $operator, 'sqlite'),
		];
	}

	/**
	 * Builds DBMS-specific optimistic-lock update expressions.
	 *
	 * Each expression assigns caller fields and increments the version column using
	 * the bound bump value appended by updateWithVersion().
	 *
	 * @param array<string, mixed> $fields Normalized update fields excluding the version column.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @return array{mysql:string, postgresql:string, sqlite:string} Assignment expressions by DBMS.
	 */
	protected static function versionedUpdateFields(array $fields, string $versionColumn): array {
		return [
			'mysql'=>static::versionedUpdateFieldsForDbms($fields, $versionColumn, 'mysql'),
			'postgresql'=>static::versionedUpdateFieldsForDbms($fields, $versionColumn, 'postgresql'),
			'sqlite'=>static::versionedUpdateFieldsForDbms($fields, $versionColumn, 'sqlite'),
		];
	}

	/**
	 * Builds one optimistic-lock update expression for a DBMS.
	 *
	 * Field identifiers and the version column are quoted with the DBMS-specific
	 * identifier delimiter before being joined into a raw assignment string.
	 *
	 * @param array<string, mixed> $fields Normalized update fields excluding the version column.
	 * @param string $versionColumn Optimistic-lock version column.
	 * @param string $dbms SQL kernel DBMS key.
	 * @return string Comma-separated assignment expression.
	 */
	protected static function versionedUpdateFieldsForDbms(array $fields, string $versionColumn, string $dbms): string {
		$assignments=[];
		foreach(array_keys($fields) as $column){
			$assignments[]=static::quoteCounterIdentifier((string)$column, $dbms).'=?';
		}
		$version=static::quoteCounterIdentifier($versionColumn, $dbms);
		$assignments[]=$version.'='.$version.'+?';
		return implode(',', $assignments);
	}

	/**
	 * Validates the optimistic-lock version bump.
	 *
	 * Version updates always advance by a positive integer to preserve monotonic
	 * optimistic-lock state.
	 *
	 * @param string $versionColumn Version column used in diagnostics.
	 * @param int $bump Candidate positive version increment.
	 * @return int Validated bump amount.
	 * @throws SqlError When the bump is zero or negative.
	 */
	protected static function resolvedVersionBump(string $versionColumn, int $bump): int {
		if($bump<=0){
			throw SqlError::invalidFieldPayload(static::class, "Version bump for '{$versionColumn}' must be greater than zero.");
		}
		return $bump;
	}

	/**
	 * Validates the expected optimistic-lock version.
	 *
	 * Expected versions are allowed to start at zero but cannot be negative.
	 *
	 * @param string $versionColumn Version column used in diagnostics.
	 * @param int $expectedVersion Candidate stored version.
	 * @return int Validated expected version.
	 * @throws SqlError When the version is negative.
	 */
	protected static function resolvedExpectedVersion(string $versionColumn, int $expectedVersion): int {
		if($expectedVersion<0){
			throw SqlError::invalidFieldPayload(static::class, "Expected version for '{$versionColumn}' must be greater than or equal to zero.");
		}
		return $expectedVersion;
	}

	/**
	 * Rejects caller writes to the managed optimistic-lock column.
	 *
	 * updateWithVersion() owns version changes so callers cannot override or skip
	 * the automatic bump expression.
	 *
	 * @param array<string, mixed> $fields Normalized update fields.
	 * @param string $versionColumn Managed optimistic-lock version column.
	 * @return void
	 * @throws SqlError When the version column is present in fields.
	 */
	protected static function assertVersionColumnNotInFields(array $fields, string $versionColumn): void {
		if(array_key_exists($versionColumn, $fields)){
			throw SqlError::invalidFieldPayload(static::class, "Version column '{$versionColumn}' is managed by updateWithVersion().");
		}
	}

	/**
	 * Builds one DBMS-specific counter assignment expression.
	 *
	 * The expression uses one placeholder for the counter amount and quotes dotted
	 * identifiers segment by segment.
	 *
	 * @param string $column Resolved counter column identifier.
	 * @param string $operator SQL arithmetic operator to apply.
	 * @param string $dbms SQL kernel DBMS key.
	 * @return string Assignment expression such as `count`=`count`+?.
	 */
	protected static function counterFieldForDbms(string $column, string $operator, string $dbms): string {
		$quoted=static::quoteCounterIdentifier($column, $dbms);
		return $quoted.'='.$quoted.$operator.'?';
	}

	/**
	 * Quotes a counter/version identifier for a supported DBMS.
	 *
	 * Dotted identifiers are quoted per segment. Existing quote characters inside
	 * each segment are escaped by doubling them.
	 *
	 * @param string $identifier Identifier after repository/schema validation.
	 * @param string $dbms SQL kernel DBMS key.
	 * @return string Quoted identifier.
	 */
	protected static function quoteCounterIdentifier(string $identifier, string $dbms): string {
		$quote=$dbms==='mysql' ? '`' : '"';
		$escaped=$quote.$quote;
		$parts=explode('.', $identifier);
		foreach($parts as $index=>$part){
			$parts[$index]=$quote.str_replace($quote, $escaped, $part).$quote;
		}
		return implode('.', $parts);
	}

	/**
	 * Reads up to two rows for single-result assertions.
	 *
	 * sole() style helpers use the second row to distinguish missing, single, and
	 * too-many result states without fetching the entire match set.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @return array<int, array<string, mixed>> Up to two rows.
	 */
	protected static function singleResultRows(
		array|string $columns='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null
	): array {
		return static::all($columns, ($spec!==null ? clone $spec : static::spec())->limit(2), $caching);
	}

	/**
	 * Builds an equality QuerySpec from attribute pairs.
	 *
	 * firstOrCreate() and updateOrCreate() use this helper to align lookup
	 * attributes with normal repository spec defaults.
	 *
	 * @param array<string, mixed> $attributes Attribute equality predicates.
	 * @param ?QuerySpec $spec Optional base spec to clone before adding predicates.
	 * @return QuerySpec Spec containing all attribute predicates.
	 */
	protected static function specForAttributes(array $attributes, ?QuerySpec $spec=null): QuerySpec {
		$query=$spec!==null ? clone $spec : static::spec();
		foreach($attributes as $column=>$value){
			$query->whereEq((string)$column, $value);
		}
		return $query;
	}

	/**
	 * Throws when a normalized mutation result failed.
	 *
	 * The result's kernel error message is preferred, with a repository mutation
	 * diagnostic fallback when the kernel payload did not include one.
	 *
	 * @param MutationResult $result Mutation outcome to assert.
	 * @return void
	 * @throws \RuntimeException When the mutation failed.
	 */
	protected static function assertMutationSucceeded(MutationResult $result): void {
		if($result->failed()){
			throw new \RuntimeException($result->errorMessage() ?? SqlError::mutationErrorMessage($result->operation(), $result->context()));
		}
	}

	/**
	 * Returns the minimum projection required for pluck().
	 *
	 * Empty column names are discarded and duplicates are removed while preserving
	 * the requested value column before the optional key column.
	 *
	 * @param string $column Value column.
	 * @param ?string $keyColumn Optional key column.
	 * @return array<int, string> Projection columns for pluck().
	 */
	protected static function pluckColumns(string $column, ?string $keyColumn=null): array {
		$columns=[trim($column)];
		if($keyColumn!==null && trim($keyColumn)!==''){
			$columns[]=trim($keyColumn);
		}
		return array_values(array_unique(array_filter($columns, static fn(string $value): bool => $value!=='')));
	}

	/**
	 * Ensures keyBy() projections include the key column.
	 *
	 * Wildcard projections are left untouched; explicit projections are normalized,
	 * de-duplicated, and extended with the key column.
	 *
	 * @param string $keyColumn Column used as the result key.
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @return array<int, string>|string Projection including the key column, or `*`.
	 */
	protected static function keyColumns(string $keyColumn, array|string $columns='*'): array|string {
		$keyColumn=trim($keyColumn);
		if($columns==='*'){
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
	 * Normalizes ID lists for finder helpers.
	 *
	 * IDs are cast to strings, trimmed, and empty values are removed before being
	 * passed into IN-list query specs.
	 *
	 * @param array<int, mixed> $ids Candidate identifier values.
	 * @return array<int, string> Non-empty normalized identifiers.
	 */
	protected static function normalizedFinderIds(array $ids): array {
		return array_values(array_filter(array_map(
			static fn(mixed $id): string => trim((string)$id),
			$ids
		), static fn(string $id): bool => $id!==''));
	}

	/**
	 * Resolves the keyset pagination column.
	 *
	 * A caller-supplied key wins; otherwise the repository primary key is required.
	 * Schema-backed repositories resolve aliases, while schema-less repositories
	 * validate a safe dotted identifier.
	 *
	 * @param ?string $keyColumn Optional keyset column.
	 * @return string SQL-ready keyset column.
	 * @throws SqlError When no key column is available or the identifier is unsafe.
	 */
	protected static function resolvedKeyColumn(?string $keyColumn=null): string {
		$keyColumn=$keyColumn!==null && trim($keyColumn)!=='' ? trim($keyColumn) : static::primaryKey();
		if($keyColumn===null || trim($keyColumn)===''){
			throw SqlError::missingPrimaryKeyForRepository(static::class, 'perform keyset chunking');
		}
		$schema=static::schema();
		if($schema!==null){
			$resolved=$schema->columns($keyColumn);
			return is_string($resolved) ? $resolved : (string)($resolved[0] ?? '');
		}
		if(preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $keyColumn)!==1){
			throw SqlError::invalidIdentifier('keyset column', $keyColumn, static::table());
		}
		return $keyColumn;
	}

	/**
	 * Normalizes keyset iteration direction.
	 *
	 * Only an explicit case-insensitive DESC selects descending order; every other
	 * value falls back to ASC.
	 *
	 * @param string $direction Requested direction.
	 * @return 'ASC'|'DESC' Normalized direction.
	 */
	protected static function normalizedKeysetDirection(string $direction): string {
		return strtoupper(trim($direction))==='DESC' ? 'DESC' : 'ASC';
	}

	/**
	 * Extracts the final keyset value from a chunk.
	 *
	 * Keyset iteration cannot advance unless the selected rows include the key
	 * column, so missing keys are surfaced immediately.
	 *
	 * @param array<int, mixed> $rows Rows returned by the current chunk query.
	 * @param string $keyColumn Keyset column expected on the final row.
	 * @return mixed value read from the keyset column on the final row of the current chunk.
	 * @throws \RuntimeException When the final row is missing the key column.
	 */
	protected static function lastKeyFromRows(array $rows, string $keyColumn): mixed {
		$lastRow=end($rows);
		if(!is_array($lastRow) || !array_key_exists($keyColumn, $lastRow)){
			throw new \RuntimeException("Keyset chunking could not read key column '{$keyColumn}' from the selected rows.");
		}
		return $lastRow[$keyColumn];
	}

	/**
	 * Extracts scalar values from result rows.
	 *
	 * When a key column is supplied, rows missing a non-null key are skipped so the
	 * returned map never contains ambiguous empty-string keys.
	 *
	 * @param array<int, mixed> $rows Query result rows.
	 * @param string $column Value column.
	 * @param ?string $keyColumn Optional key column for associative output.
	 * @return array<int|string, mixed> Plucked values keyed by index or key column.
	 */
	protected static function pluckRows(array $rows, string $column, ?string $keyColumn=null): array {
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
	 * Re-keys result rows by a non-null column value.
	 *
	 * Rows that are not arrays or do not contain the key column are ignored rather
	 * than producing unstable numeric keys.
	 *
	 * @param array<int, mixed> $rows Query result rows.
	 * @param string $keyColumn Column used as the result key.
	 * @return array<string, array<string, mixed>> Rows keyed by string-cast column values.
	 */
	protected static function keyRowsBy(array $rows, string $keyColumn): array {
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
	 * Builds diagnostic context for missing-row exceptions.
	 *
	 * Empty and null values are removed so exception payloads focus on the table,
	 * repository, projection, query debug context, and caller-provided details.
	 *
	 * @param array|string $columns Column list, projection, or `*` selector.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param array<string, mixed> $extra Additional diagnostic fields.
	 * @return array<string, mixed> Filtered exception context.
	 */
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

	/**
	 * Reads one scalar aggregate value.
	 *
	 * Ordering and paging are stripped from the spec before the aggregate query is
	 * compiled so counts and sums represent the full scoped dataset.
	 *
	 * @param string $function Aggregate function name.
	 * @param string $column Aggregate column or `*` for COUNT.
	 * @param ?QuerySpec $spec Additional query constraints to merge into the operation.
	 * @param bool|array|string|null $caching Dataphyre read-cache policy.
	 * @param bool $distinct Whether to prefix the aggregate argument with DISTINCT.
	 * @return mixed Normalized aggregate value, null for missing payloads, or false on SQL failure.
	 */
	protected static function aggregateValue(
		string $function,
		string $column='*',
		?QuerySpec $spec=null,
		bool|array|string|null $caching=null,
		bool $distinct=false
	): mixed {
		$function=static::normalizeAggregateFunction($function);
		$column=static::aggregateColumn($column, $function, $function==='COUNT');
		$distinctSql=$distinct ? 'DISTINCT ' : '';
		$compiled=($spec!==null ? clone $spec : static::spec())
			->withoutOrdering()
			->withoutPaging()
			->compile(false);
		$result=static::withSchemaHydration(static fn(): mixed => sql_select(
				$function.'('.$distinctSql.$column.') AS aggregate_value',
				static::table(),
				$compiled['params'],
				$compiled['vars'],
				false,
				static::resolveReadCaching($caching)
			)
		);
		if($result===false){
			return false;
		}
		if(!is_array($result) || !array_key_exists('aggregate_value', $result)){
			return null;
		}
		return static::normalizeAggregateResult($function, $result['aggregate_value']);
	}

	/**
	 * Normalizes and validates an aggregate function name.
	 *
	 * Only the SQL functions with explicit repository wrappers are accepted.
	 *
	 * @param string $function Candidate aggregate function.
	 * @return 'COUNT'|'SUM'|'AVG'|'MIN'|'MAX' Uppercase function name.
	 * @throws SqlError When the function is unsupported.
	 */
	protected static function normalizeAggregateFunction(string $function): string {
		$function=strtoupper(trim($function));
		$allowed=['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
		if(!in_array($function, $allowed, true)){
			throw SqlError::invalidAggregateFunction(static::class, $function, $allowed);
		}
		return $function;
	}

	/**
	 * Resolves and validates an aggregate column.
	 *
	 * COUNT may opt into `*`; every other aggregate requires a schema-resolved or
	 * identifier-safe column.
	 *
	 * @param string $column Candidate aggregate column.
	 * @param string $function Aggregate function used in diagnostics.
	 * @param bool $allowStar Whether `*` is legal for this aggregate.
	 * @return string SQL-ready aggregate column.
	 * @throws SqlError When the column is empty, unsafe, or an illegal `*`.
	 */
	protected static function aggregateColumn(string $column, string $function, bool $allowStar=false): string {
		$column=trim($column);
		if($column===''){
			throw SqlError::invalidAggregateColumn(static::class, $function, $column, $allowStar);
		}
		if($column==='*'){
			if($allowStar){
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
			throw SqlError::invalidAggregateColumn(static::class, $function, $column, $allowStar);
		}
		return $column;
	}

	/**
	 * Casts aggregate payloads into repository-friendly scalar values.
	 *
	 * COUNT becomes an integer when numeric; SUM and AVG preserve fractional or
	 * exponent notation as floats and whole numeric strings as integers.
	 *
	 * @param string $function Normalized aggregate function name.
	 * @param mixed $value Raw aggregate payload from the SQL kernel.
	 * @return int|float|string|bool|null COUNT/SUM/AVG value cast when numeric, MIN/MAX scalar as returned by the SQL layer, false on SQL failure, or null when absent.
	 */
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

	/**
	 * Resolves GROUP BY columns for grouped aggregate queries.
	 *
	 * Schema-backed repositories resolve aliases; schema-less repositories accept
	 * only safe dotted identifiers. Duplicate and empty resolved values are removed.
	 *
	 * @param string|array<int, mixed> $groupColumns Candidate group columns.
	 * @return array<int, string> SQL-ready group columns.
	 * @throws SqlError When any group column is empty or unsafe.
	 */
	protected static function groupColumns(string|array $groupColumns): array {
		if(is_string($groupColumns)){
			$groupColumns=[$groupColumns];
		}
		$schema=static::schema();
		$normalized=[];
		foreach($groupColumns as $groupColumn){
			$groupColumn=trim((string)$groupColumn);
			if($groupColumn===''){
				throw SqlError::invalidIdentifier('group by', $groupColumn, static::table());
			}
			if($schema!==null){
				$resolved=$schema->columns($groupColumn);
				$normalized[]=is_string($resolved) ? $resolved : (string)($resolved[0] ?? '');
				continue;
			}
			if(preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $groupColumn)!==1){
				throw SqlError::invalidIdentifier('group by', $groupColumn, static::table());
			}
			$normalized[]=$groupColumn;
		}
		return array_values(array_unique(array_filter($normalized, static fn(string $value): bool => $value!=='')));
	}

	/**
	 * Appends a SQL fragment to an existing params clause.
	 *
	 * Blank fragments are ignored; non-empty fragments are formatted with the
	 * indentation expected by sql_select() params strings.
	 *
	 * @param string $params Existing SQL params fragment.
	 * @param string $clause Clause to append.
	 * @return string Combined params fragment.
	 */
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

	/**
	 * Normalizes aggregate payloads inside grouped result rows.
	 *
	 * Rows without an aggregate_value key are left untouched so callers can preserve
	 * diagnostic or database-specific fields.
	 *
	 * @param array<int, mixed> $rows Grouped aggregate rows.
	 * @param string $function Normalized aggregate function name.
	 * @return array<int, mixed> Rows with aggregate_value cast where present.
	 */
	protected static function normalizeAggregateRows(array $rows, string $function): array {
		foreach($rows as $index=>$row){
			if(!is_array($row) || !array_key_exists('aggregate_value', $row)){
				continue;
			}
			$rows[$index]['aggregate_value']=static::normalizeAggregateResult($function, $row['aggregate_value']);
		}
		return $rows;
	}

	/**
	 * Converts grouped aggregate rows into a key/value map.
	 *
	 * Rows missing a non-null group value are skipped; aggregate values default to
	 * null when the SQL payload omitted aggregate_value.
	 *
	 * @param string $groupColumn Group column to use as the result key.
	 * @param array<int, mixed> $rows Grouped aggregate rows.
	 * @return array<string, mixed> Aggregate values keyed by group value.
	 */
	protected static function groupedAggregateMap(string $groupColumn, array $rows): array {
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

	/**
	 * Applies schema casts to one repository row.
	 *
	 * Schema-less repositories return the row unchanged.
	 *
	 * @param array<string, mixed> $row Raw SQL row.
	 * @return array<string, mixed> Cast row.
	 */
	protected static function castRepositoryRow(array $row): array {
		return static::schema()?->castRow($row) ?? $row;
	}

	/**
	 * Applies schema casts to a row list.
	 *
	 * Schema-less repositories return the rows unchanged.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw SQL rows.
	 * @return array<int, array<string, mixed>> Cast rows.
	 */
	protected static function castRepositoryRows(array $rows): array {
		return static::schema()?->castRows($rows) ?? $rows;
	}

	/**
	 * Normalizes queued select payloads into a row list.
	 *
	 * The SQL queue may return either a list of rows or a single row. Non-array list
	 * entries are discarded before schema casts are applied.
	 *
	 * @param mixed $result Raw queued select result.
	 * @return array<int, array<string, mixed>> Cast row list.
	 */
	protected static function queuedAllRowsResult(mixed $result): array {
		if(!is_array($result) || $result===[]){
			return [];
		}
		if(array_is_list($result)){
			return static::castRepositoryRows(array_values(array_filter($result, 'is_array')));
		}
		return [static::castRepositoryRow($result)];
	}

	/**
	 * Normalizes queued select payloads into one row or null.
	 *
	 * List payloads use their first array element; associative payloads are treated
	 * as the single selected row.
	 *
	 * @param mixed $result Raw queued select result.
	 * @return array<string, mixed>|null First cast row or null when unavailable.
	 */
	protected static function queuedFirstRowResult(mixed $result): ?array {
		if(!is_array($result) || $result===[]){
			return null;
		}
		if(array_is_list($result)){
			$row=$result[0] ?? null;
			return is_array($row) ? static::castRepositoryRow($row) : null;
		}
		return static::castRepositoryRow($result);
	}

	/**
	 * Applies configured money-column transformations to one repository row.
	 *
	 * Money mappings let repositories expose value objects while the SQL kernel stores amount, currency, rate, source, and timestamp columns.
	 *
	 * @param array<string, mixed> $row Row after schema casts.
	 * @return array<string, mixed> Row with configured money value objects applied.
	 */
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

	/**
	 * Resolves live money-column mappings for this repository.
	 *
	 *
	 * @return array<int, array<string, mixed>> Normalized amount/currency mapping definitions.
	 * @throws SqlError When moneyColumns() returns an invalid definition.
	 */
	protected static function resolvedMoneyColumns(): array {
		$raw=static::moneyColumns();
		$class=static::class;
		if(isset(self::$resolvedMoneyColumnsCache[$class]) && self::$resolvedMoneyColumnsCache[$class]['raw']===$raw){
			return self::$resolvedMoneyColumnsCache[$class]['resolved'];
		}
		$resolved=[];
		foreach($raw as $amountColumn=>$definition){
			if(is_int($amountColumn)){
				if(!is_array($definition) || !isset($definition['amount_column'])){
					throw SqlError::invalidMoneyDefinition(
						static::class,
						'Numeric moneyColumns() entries must provide an amount_column definition.'
					);
				}
				$amountColumn=(string)$definition['amount_column'];
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
				(string)$amountColumn,
				isset($definition['currency_column']) ? (string)$definition['currency_column'] : null,
				isset($definition['currency']) ? (string)$definition['currency'] : null,
				isset($definition['target_column'])
					? (string)$definition['target_column']
					: (isset($definition['target']) ? (string)$definition['target'] : null),
				static::class
			);
		}
		self::$resolvedMoneyColumnsCache[$class]=[
			'raw'=>$raw,
			'resolved'=>$resolved,
		];
		return $resolved;
	}

	/**
	 * Resolves stored-money column mappings for this repository.
	 *
	 * Money mappings let repositories expose value objects while the SQL kernel stores amount, currency, rate, source, and timestamp columns.
	 *
	 * @return array<int, array<string, mixed>> Normalized stored-money mapping definitions.
	 * @throws SqlError When storedMoneyColumns() returns an invalid definition.
	 */
	protected static function resolvedStoredMoneyColumns(): array {
		$raw=static::storedMoneyColumns();
		$class=static::class;
		if(isset(self::$resolvedStoredMoneyColumnsCache[$class]) && self::$resolvedStoredMoneyColumnsCache[$class]['raw']===$raw){
			return self::$resolvedStoredMoneyColumnsCache[$class]['resolved'];
		}
		$resolved=[];
		foreach($raw as $targetColumn=>$definition){
			if(is_int($targetColumn)){
				if(!is_array($definition)){
					throw SqlError::invalidMoneyDefinition(
						static::class,
						'Numeric storedMoneyColumns() entries must provide a configuration array with a target column.'
					);
				}
				$targetColumn=$definition['target_column'] ?? $definition['target'] ?? null;
				if(!is_string($targetColumn) || trim($targetColumn)===''){
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
				(string)$targetColumn,
				static::class
			);
		}
		self::$resolvedStoredMoneyColumnsCache[$class]=[
			'raw'=>$raw,
			'resolved'=>$resolved,
		];
		return $resolved;
	}
}
