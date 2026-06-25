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

/**
 * Immutable framework record for hydrated SQL rows and repository write-back helpers.
 *
 * Record exposes a database row as array-like, iterable, JSON-serializable data
 * while keeping the row snapshot immutable. When schema, repository, and primary
 * key metadata are attached, it can resolve named relations, refresh from storage,
 * perform repository updates or deletes, and enforce optimistic-lock version
 * checks without mutating the current instance. Detached records deliberately stay
 * read-only: persistence helpers fail before any SQL call when repository or
 * primary-key context is missing.
 */
class Record implements ArrayAccess, Countable, IteratorAggregate, \JsonSerializable {

	/** @var array<int, string>|null */
	private ?array $onlyColumnsInput=null;

	/** @var array<string, mixed>|null */
	private ?array $onlyPayload=null;

	/** @var array<int, string>|null */
	private ?array $exceptColumnsInput=null;

	/** @var array<string, mixed>|null */
	private ?array $exceptPayload=null;

	/**
	 * Captures an immutable SQL row with optional repository and schema context.
	 *
	 * The row is stored exactly as hydrated. Schema, repository class, and primary key
	 * metadata enable relation loading and write-back helpers; records without that
	 * context still work as read-only array-like value objects. The constructor does
	 * not validate repository classes or primary-key values; guards perform that work
	 * lazily only when a relation or persistence helper needs it.
	 *
	 * @param array<string, mixed> $row Hydrated database row keyed by column name.
	 * @param TableSchema|null $schema Optional table schema used to infer primary key.
	 * @param string|null $repositoryClass Repository class that can refresh or mutate this row.
	 * @param string|null $primaryKey Explicit primary key column when no schema is attached.
	 */
	public function __construct(
		private readonly array $row,
		private readonly ?TableSchema $schema=null,
		private readonly ?string $repositoryClass=null,
		private readonly ?string $primaryKey=null
	){}

	/**
	 * Returns the repository class attached to this record.
	 *
	 * A null value means the record can be read but cannot resolve named relations,
	 * refresh itself, update itself, or delete itself through repository helpers.
	 *
	 * @return class-string<TableRepository>|null Repository class name for write-back operations.
	 */
	public function repositoryClass(): ?string {
		return $this->repositoryClass;
	}

	/**
	 * Returns the table schema associated with the hydrated row.
	 *
	 * Schema metadata is optional. When present, it supplies the canonical primary
	 * key name used before the constructor-level primary key fallback.
	 *
	 * @return TableSchema|null Schema metadata for the row.
	 */
	public function schema(): ?TableSchema {
		return $this->schema;
	}

	/**
	 * Returns the primary key column name known for this record.
	 *
	 * The schema primary key wins over the explicit constructor fallback so records
	 * hydrated from a typed repository follow table metadata consistently.
	 *
	 * @return string|null Primary key column name, or null when unknown.
	 */
	public function primaryKeyName(): ?string {
		return $this->schema?->primaryKey() ?? $this->primaryKey;
	}

	/**
	 * Returns the current row value for the primary key column.
	 *
	 * Records without a known primary key return null. Missing primary key values also
	 * return null, which makes write-back operations unavailable.
	 *
	 * @return mixed Primary key value from the row, or null when unavailable.
	 */
	public function id(): mixed {
		$primaryKey=$this->primaryKeyName();
		return $primaryKey!==null ? ($this->row[$primaryKey] ?? null) : null;
	}

	/**
	 * Checks whether a column key exists in the hydrated row.
	 *
	 * Null values still count as present because existence is tested with
	 * array_key_exists().
	 *
	 * @param string $column Column name to inspect.
	 * @return bool True when the row contains the column key.
	 */
	public function has(string $column): bool {
		return array_key_exists($column, $this->row);
	}

	/**
	 * Reads a column value from the immutable row.
	 *
	 * Missing columns return the supplied default. Because reads intentionally use
	 * null-coalescing semantics, present null values also return the supplied default;
	 * use has() or toArray() when null must be distinguished from absence.
	 *
	 * @param string $column Column name to read.
	 * @param mixed $default Value returned when the column is absent.
	 * @return mixed Stored column value, or the caller default when the column is absent or null.
	 */
	public function get(string $column, mixed $default=null): mixed {
		return $this->row[$column] ?? $default;
	}

	/**
	 * Builds a money value from amount and currency columns on this row.
	 *
	 * CurrencyBridge normalizes the mapping and applies framework currency rules.
	 * The returned value is whatever the configured money mapping writes to its
	 * target column, usually a Money object or formatted money value. This is a
	 * pure row transformation: it does not refresh exchange rates, persist computed
	 * values, or mutate the record.
	 *
	 * @param string $amountColumn Column containing the numeric amount.
	 * @param string|null $currencyColumn Column containing the ISO currency code.
	 * @param string|null $currency Fixed currency override when no currency column is used.
	 * @return mixed Money-mapped target value, or null when mapping cannot produce one.
	 */
	public function money(string $amountColumn, ?string $currencyColumn='currency', ?string $currency=null): mixed {
		$owner=$this->repositoryClass ?? static::class;
		$mapping=CurrencyBridge::normalizeMoneyMapping($amountColumn, $currencyColumn, $currency, $amountColumn, $owner);
		$row=CurrencyBridge::applyMoneyMapping($this->row, $mapping, $owner);
		return $row[$mapping['target_column']] ?? null;
	}

	/**
	 * Reads a stored-money mapping from this row.
	 *
	 * The target column can be supplied directly or through a full mapping definition.
	 * CurrencyBridge interprets the definition and returns the generated target value
	 * from the mapped row. The stored row remains unchanged and missing mapping input
	 * resolves to null rather than causing a write-back.
	 *
	 * @param string|array<string,mixed> $targetColumn Target column name or complete mapping definition.
	 * @param array<string,mixed> $definition Stored-money mapping options.
	 * @return mixed Stored money target value, or null when unavailable.
	 */
	public function storedMoney(string|array $targetColumn='stored_money', array $definition=[]): mixed {
		$owner=$this->repositoryClass ?? static::class;
		if(is_array($targetColumn)){
			$definition=$targetColumn;
			$targetColumn='stored_money';
		}
		$mapping=CurrencyBridge::normalizeStoredMoneyMapping($definition, $targetColumn, $owner);
		$row=CurrencyBridge::applyStoredMoneyMapping($this->row, $mapping, $owner);
		return $row[$mapping['target_column']] ?? null;
	}

	/**
	 * Loads a relation for this record using row values as the local side.
	 *
	 * Named relations require an attached repository class. Relation instances can be
	 * passed directly for detached records. The relation decides whether the result is
	 * a row, collection, scalar, or null. The record contributes only its immutable
	 * row values to the relation query; cache hints and SQL failures are owned by the
	 * relation/repository layer.
	 *
	 * @param string|Relation $relation Relation name or relation object.
	 * @param array|string $columns Columns requested from the related side.
	 * @param bool|array|string|null $caching Relation query cache hint.
	 * @return mixed Relation result returned by Relation::get().
	 * @throws SqlError When a named relation cannot be resolved.
	 */
	public function relation(string|Relation $relation, array|string $columns='*', bool|array|string|null $caching=null): mixed {
		return $this->resolveRelation($relation)->get($this, $columns, $caching);
	}

	/**
	 * Loads related rows as Record objects or custom hydrated values.
	 *
	 * Named relation resolution follows relation(). The optional hydrator is forwarded
	 * to Relation::getRecords() so repositories can return typed record collections.
	 *
	 * @param string|Relation $relation Relation name or relation object.
	 * @param array|string $columns Columns requested from the related side.
	 * @param mixed $hydrator Hydrator passed to the relation record loader.
	 * @param bool|array|string|null $caching Relation query cache hint.
	 * @return mixed Related records returned by Relation::getRecords().
	 */
	public function relationRecords(string|Relation $relation, array|string $columns='*', mixed $hydrator=null, bool|array|string|null $caching=null): mixed {
		return $this->resolveRelation($relation)->getRecords($this, $columns, $hydrator, $caching);
	}

	/**
	 * Alias for relation() kept for fluent record reads.
	 *
	 * @param string|Relation $relation Relation name or relation object.
	 * @param array|string $columns Columns requested from the related side.
	 * @param bool|array|string|null $caching Relation query cache hint.
	 * @return mixed Relation result returned by relation().
	 */
	public function related(string|Relation $relation, array|string $columns='*', bool|array|string|null $caching=null): mixed {
		return $this->relation($relation, $columns, $caching);
	}

	/**
	 * Alias for relationRecords() kept for fluent record reads.
	 *
	 * @param string|Relation $relation Relation name or relation object.
	 * @param array|string $columns Columns requested from the related side.
	 * @param mixed $hydrator Hydrator passed to the relation record loader.
	 * @param bool|array|string|null $caching Relation query cache hint.
	 * @return mixed Related records returned by relationRecords().
	 */
	public function relatedRecords(string|Relation $relation, array|string $columns='*', mixed $hydrator=null, bool|array|string|null $caching=null): mixed {
		return $this->relationRecords($relation, $columns, $hydrator, $caching);
	}

	/**
	 * Returns a cloned record with additional or replaced row values.
	 *
	 * The original record remains immutable. Field names are validated as SQL-style
	 * identifiers before they are written into the cloned row.
	 *
	 * @param string|array $column Column name or associative column/value map.
	 * @param mixed $value Value used when $column is a string.
	 * @return static New record instance containing the modified row.
	 * @throws SqlError When any field name is invalid.
	 */
	public function with(string|array $column, mixed $value=null): static {
		$row=$this->row;
		if(is_array($column)){
			foreach($column as $name=>$entryValue){
				if(is_int($name)){
					throw SqlError::invalidIdentifier('record field', (string)$name);
				}
				$row[$this->assertRecordFieldName((string)$name)]=$entryValue;
			}
			return $this->replicate($row);
		}
		$row[$this->assertRecordFieldName($column)]=$value;
		return $this->replicate($row);
	}

	/**
	 * Returns a cloned record with resolved relation data stored under a row key.
	 *
	 * This is a semantic alias for with() used by eager-loading code that attaches
	 * already-resolved relation data to a record value object. The relation name is
	 * validated with the same identifier rules as ordinary row overlays.
	 *
	 * @param string $name Relation data key.
	 * @param mixed $value Resolved relation data.
	 * @return static New record instance containing the relation data.
	 * @throws SqlError When the relation data key is not a valid record field name.
	 */
	public function withRelation(string $name, mixed $value): static {
		return $this->with($name, $value);
	}

	/**
	 * Reloads this record from its attached repository by primary key.
	 *
	 * Refresh requires a valid repository class, known primary key column, and current
	 * primary key value. A missing row returns null. A repository that returns an
	 * incompatible record class raises a record-operation error. Refresh does not
	 * merge data into this instance; callers receive a fresh snapshot or null.
	 *
	 * @param array|string $columns Columns requested from the repository.
	 * @param bool|array|string|null $caching Repository cache hint for the lookup.
	 * @return static|null Fresh record instance or null when the row no longer exists.
	 * @throws SqlError When repository or primary-key context is missing or invalid.
	 */
	public function refresh(array|string $columns='*', bool|array|string|null $caching=false): ?static {
		$repository=$this->repositoryForRecordOperation('refresh');
		$id=$this->idForRecordOperation('refresh');
		$record=$repository::findRecord($id, $columns, static::class, $caching);
		if($record===null){
			return null;
		}
		if(!$record instanceof static){
			throw SqlError::recordOperationUnavailable(static::class, 'refresh', 'The repository returned a record that is not compatible with the current record class.');
		}
		return $record;
	}

	/**
	 * Updates this row through the attached repository and primary key value.
	 *
	 * The record object itself remains unchanged. The returned MutationResult reports
	 * affected rows, stale state, validation errors, or SQL/cache failures from the
	 * repository layer. Field validation, cache invalidation, and transaction behavior
	 * are delegated to the attached repository.
	 *
	 * @param array<string, mixed> $fields Field values to update.
	 * @param bool|array|null $clearCache Cache invalidation instruction for the repository.
	 * @return MutationResult Repository mutation result.
	 * @throws SqlError When repository or primary-key context is missing or invalid.
	 */
	public function update(array $fields, bool|array|null $clearCache=null): MutationResult {
		$repository=$this->repositoryForRecordOperation('update');
		return $repository::updateById($this->idForRecordOperation('update'), $fields, $clearCache);
	}

	/**
	 * Returns the optimistic-lock version stored on this record.
	 *
	 * The version column must exist and contain a non-negative integer or integer
	 * string. Invalid values make versioned write operations unavailable instead of
	 * silently issuing an unguarded update.
	 *
	 * @param string $versionColumn Column that stores the lock version.
	 * @return int Current non-negative version value.
	 * @throws SqlError When the version column name or value is invalid.
	 */
	public function currentVersion(string $versionColumn='lock_version'): int {
		return $this->versionForRecordOperation('currentVersion', $versionColumn);
	}

	/**
	 * Runs an optimistic-lock update through the attached repository.
	 *
	 * The repository updates by primary key only when the stored version matches the
	 * expected version, then bumps the version column by the requested amount. A
	 * zero-row versioned update is represented as a stale MutationResult rather than
	 * mutating this snapshot.
	 *
	 * @param array<string, mixed> $fields Field values to update.
	 * @param int $expectedVersion Version expected to be present in storage.
	 * @param string $versionColumn Column that stores the lock version.
	 * @param int $bump Amount added to the version on success.
	 * @param bool|array|null $clearCache Cache invalidation instruction for the repository.
	 * @return MutationResult Repository mutation result, including stale status.
	 * @throws SqlError When repository or primary-key context is missing or invalid.
	 */
	public function updateWithVersion(
		array $fields,
		int $expectedVersion,
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): MutationResult {
		$repository=$this->repositoryForRecordOperation('updateWithVersion');
		return $repository::updateByIdWithVersion(
			$this->idForRecordOperation('updateWithVersion'),
			$fields,
			$expectedVersion,
			$versionColumn,
			$bump,
			$clearCache
		);
	}

	/**
	 * Runs an optimistic-lock update and throws on failure or stale version.
	 *
	 * This is the fail-fast companion to updateWithVersion() for callers that prefer
	 * exceptions over inspecting MutationResult. SQL/kernel failures are reported
	 * before optimistic-lock conflicts.
	 *
	 * @param array<string, mixed> $fields Field values to update.
	 * @param int $expectedVersion Version expected to be present in storage.
	 * @param string $versionColumn Column that stores the lock version.
	 * @param int $bump Amount added to the version on success.
	 * @param bool|array|null $clearCache Cache invalidation instruction for the repository.
	 * @return MutationResult Successful mutation result.
	 * @throws \RuntimeException When the repository mutation fails.
	 * @throws OptimisticLockException When the versioned update is stale.
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
	 * Runs an optimistic-lock update using this record's current version value.
	 *
	 * The expected version is read from the hydrated row before dispatch. The record
	 * remains unchanged after the repository mutation.
	 *
	 * @param array<string, mixed> $fields Field values to update.
	 * @param string $versionColumn Column that stores the lock version.
	 * @param int $bump Amount added to the version on success.
	 * @param bool|array|null $clearCache Cache invalidation instruction for the repository.
	 * @return MutationResult Repository mutation result, including stale status.
	 */
	public function updateWithCurrentVersion(
		array $fields,
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): MutationResult {
		return $this->updateWithVersion(
			$fields,
			$this->versionForRecordOperation('updateWithCurrentVersion', $versionColumn),
			$versionColumn,
			$bump,
			$clearCache
		);
	}

	/**
	 * Runs a current-version optimistic update and throws on failure or staleness.
	 *
	 * The current version is read from this record immediately before dispatch.
	 *
	 * @param array<string, mixed> $fields Field values to update.
	 * @param string $versionColumn Column that stores the lock version.
	 * @param int $bump Amount added to the version on success.
	 * @param bool|array|null $clearCache Cache invalidation instruction for the repository.
	 * @return MutationResult Successful mutation result.
	 */
	public function updateWithCurrentVersionOrFail(
		array $fields,
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null
	): MutationResult {
		return $this->updateWithCurrentVersion($fields, $versionColumn, $bump, $clearCache)->throwIfFailedOrStale();
	}

	/**
	 * Updates this record and reloads it after a successful mutation.
	 *
	 * Mutation failure raises an exception before refresh, preventing a read after a
	 * failed write. If the row disappears after a successful update, refresh() returns
	 * null. The original instance remains a pre-update snapshot.
	 *
	 * @param array<string, mixed> $fields Field values to update.
	 * @param array|string $columns Columns requested during refresh.
	 * @param bool|array|null $clearCache Cache invalidation instruction for update.
	 * @param bool|array|string|null $caching Repository cache hint for refresh.
	 * @return static|null Fresh record after update, or null when no row is found.
	 * @throws \RuntimeException When the repository mutation fails.
	 * @throws SqlError When repository or primary-key context is missing or invalid.
	 */
	public function updateAndRefresh(
		array $fields,
		array|string $columns='*',
		bool|array|null $clearCache=null,
		bool|array|string|null $caching=false
	): ?static {
		$result=$this->update($fields, $clearCache);
		$this->assertMutationSucceeded($result);
		return $this->refresh($columns, $caching);
	}

	/**
	 * Runs a versioned update, fails on stale state, then reloads the record.
	 *
	 * This combines optimistic locking with a fresh post-mutation read, preserving the
	 * original immutable record as a pre-update snapshot.
	 *
	 * @param array<string, mixed> $fields Field values to update.
	 * @param int $expectedVersion Version expected to be present in storage.
	 * @param array|string $columns Columns requested during refresh.
	 * @param string $versionColumn Column that stores the lock version.
	 * @param int $bump Amount added to the version on success.
	 * @param bool|array|null $clearCache Cache invalidation instruction for update.
	 * @param bool|array|string|null $caching Repository cache hint for refresh.
	 * @return static|null Fresh record after update, or null when no row is found.
	 */
	public function updateWithVersionAndRefresh(
		array $fields,
		int $expectedVersion,
		array|string $columns='*',
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null,
		bool|array|string|null $caching=false
	): ?static {
		$result=$this->updateWithVersionOrFail($fields, $expectedVersion, $versionColumn, $bump, $clearCache);
		$this->assertMutationSucceeded($result);
		return $this->refresh($columns, $caching);
	}

	/**
	 * Uses the record's current version for a versioned update, then reloads it.
	 *
	 * Failure or stale state is raised before refresh. The original record remains a
	 * snapshot of the pre-update row.
	 *
	 * @param array<string, mixed> $fields Field values to update.
	 * @param array|string $columns Columns requested during refresh.
	 * @param string $versionColumn Column that stores the lock version.
	 * @param int $bump Amount added to the version on success.
	 * @param bool|array|null $clearCache Cache invalidation instruction for update.
	 * @param bool|array|string|null $caching Repository cache hint for refresh.
	 * @return static|null Fresh record after update, or null when no row is found.
	 */
	public function updateWithCurrentVersionAndRefresh(
		array $fields,
		array|string $columns='*',
		string $versionColumn='lock_version',
		int $bump=1,
		bool|array|null $clearCache=null,
		bool|array|string|null $caching=false
	): ?static {
		$result=$this->updateWithCurrentVersionOrFail($fields, $versionColumn, $bump, $clearCache);
		$this->assertMutationSucceeded($result);
		return $this->refresh($columns, $caching);
	}

	/**
	 * Deletes this row through the attached repository and primary key value.
	 *
	 * The record object remains readable after deletion because it is an immutable
	 * snapshot. The result describes whether the repository removed a row and whether
	 * cache invalidation or SQL execution failed.
	 *
	 * @param bool|array|null $clearCache Cache invalidation instruction for the repository.
	 * @return MutationResult Repository delete result.
	 * @throws SqlError When repository or primary-key context is missing or invalid.
	 */
	public function delete(bool|array|null $clearCache=null): MutationResult {
		$repository=$this->repositoryForRecordOperation('delete');
		return $repository::deleteById($this->idForRecordOperation('delete'), $clearCache);
	}

	/**
	 * Returns a subset of row values for columns that exist on this record.
	 *
	 * Requested columns that are absent from the row are ignored.
	 *
	 * @param array<int, string> $columns Column names to include.
	 * @return array<string, mixed> Selected row values keyed by column name.
	 */
	public function only(array $columns): array {
		if($this->onlyColumnsInput!==null && $columns===$this->onlyColumnsInput){
			return $this->onlyPayload;
		}
		$selectedKeys=[];
		foreach($columns as $column){
			$selectedKeys[(string)$column]=true;
		}
		$selected=array_intersect_key($this->row, $selectedKeys);
		$this->onlyColumnsInput=$columns;
		$this->onlyPayload=$selected;
		return $selected;
	}

	/**
	 * Returns row values excluding the supplied column names.
	 *
	 * Column names are string-cast before comparison.
	 *
	 * @param array<int, string> $columns Column names to remove.
	 * @return array<string, mixed> Row values not excluded by the input list.
	 */
	public function except(array $columns): array {
		if($this->exceptColumnsInput!==null && $columns===$this->exceptColumnsInput){
			return $this->exceptPayload;
		}
		$excluded=[];
		foreach($columns as $column){
			$excluded[(string)$column]=true;
		}
		$remaining=array_diff_key($this->row, $excluded);
		$this->exceptColumnsInput=$columns;
		$this->exceptPayload=$remaining;
		return $remaining;
	}

	/**
	 * Returns the raw hydrated row as an associative array.
	 *
	 * The returned array is a copy; mutating it does not change the record instance.
	 *
	 * @return array<string, mixed> Raw row values keyed by column name.
	 */
	public function toArray(): array {
		return $this->row;
	}

	/**
	 * Counts the number of column keys in the hydrated row.
	 *
	 * @return int Number of values stored on the record.
	 */
	public function count(): int {
		return count($this->row);
	}

	/**
	 * Returns an iterator over the raw row values.
	 *
	 * Iteration preserves the row's associative keys and current value order.
	 *
	 * @return Traversable<string, mixed> Iterator over row columns and values.
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator($this->row);
	}

	/**
	 * Checks whether an array-access offset exists as a row column.
	 *
	 * Offsets are string-cast before lookup, matching offsetGet().
	 *
	 * @param mixed $offset Column name offset.
	 * @return bool True when the row contains the offset key.
	 */
	public function offsetExists(mixed $offset): bool {
		return array_key_exists((string)$offset, $this->row);
	}

	/**
	 * Reads a row column through ArrayAccess syntax.
	 *
	 * Missing offsets return null because offset access delegates to get() without a
	 * custom default.
	 *
	 * @param mixed $offset Column name offset.
	 * @return mixed Stored column value, or null when the offset is absent or stores null.
	 */
	public function offsetGet(mixed $offset): mixed {
		return $this->get((string)$offset);
	}

	/**
	 * Rejects ArrayAccess writes because records are immutable snapshots.
	 *
	 * Use with() to create a cloned record with different in-memory values, or update()
	 * to persist changes through the repository.
	 *
	 * @param mixed $offset Ignored attempted column offset.
	 * @param mixed $value Ignored attempted value.
	 * @return void
	 * @throws \LogicException Always thrown.
	 */
	public function offsetSet(mixed $offset, mixed $value): void {
		throw new \LogicException('Dataphyre records are immutable.');
	}

	/**
	 * Rejects ArrayAccess unsets because records are immutable snapshots.
	 *
	 * Use except() to produce an array without a column, or with() to create a cloned
	 * record with explicit replacement values.
	 *
	 * @param mixed $offset Ignored attempted column offset.
	 * @return void
	 * @throws \LogicException Always thrown.
	 */
	public function offsetUnset(mixed $offset): void {
		throw new \LogicException('Dataphyre records are immutable.');
	}

	/**
	 * Reads a row column through property syntax.
	 *
	 * This is a convenience wrapper over get() and returns null for missing columns.
	 *
	 * @param string $name Column name to read.
	 * @return mixed Stored column value, or null when the property-style column is absent or stores null.
	 */
	public function __get(string $name): mixed {
		return $this->get($name);
	}

	/**
	 * Checks whether a property-style column exists in the row.
	 *
	 * Null values still count as set because the check delegates to has().
	 *
	 * @param string $name Column name to inspect.
	 * @return bool True when the row contains the column key.
	 */
	public function __isset(string $name): bool {
		return $this->has($name);
	}

	/**
	 * Serializes the raw hydrated row for JSON output.
	 *
	 * @return array<string, mixed> Raw row values keyed by column name.
	 */
	public function jsonSerialize(): array {
		return $this->row;
	}

	/**
	 * Resolves a relation object for record-level relation reads.
	 *
	 * Detached records may still use explicit Relation instances, but named
	 * relations cross the repository boundary and therefore require a non-empty,
	 * loadable TableRepository subclass. Relation names are validated as framework
	 * identifiers before dispatch so user-controlled names cannot become arbitrary
	 * method or class lookups.
	 *
	 * @param string|Relation $relation Relation name or prebuilt relation object.
	 * @return Relation Resolved relation object.
	 * @throws SqlError When the name is invalid, the record is detached, or the repository class is invalid.
	 */
	private function resolveRelation(string|Relation $relation): Relation {
		if($relation instanceof Relation){
			return $relation;
		}
		$relation=trim($relation);
		if($relation==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $relation)!==1){
			throw SqlError::invalidIdentifier('record relation', $relation);
		}
		$repository=$this->repositoryClass;
		if($repository===null || trim($repository)===''){
			throw SqlError::invalidRelation(static::class, $relation, 'This record cannot resolve named relations because it is not attached to a repository.');
		}
		if(!class_exists($repository) || !is_subclass_of($repository, TableRepository::class)){
			throw SqlError::invalidRepositoryClass($repository);
		}
		return $repository::relationNamed($relation);
	}

	/**
	 * Returns the repository class required for a record write-back operation.
	 *
	 * Refresh, update, versioned update, and delete are available only when
	 * the hydrated snapshot carries repository metadata. The guard fails before any
	 * persistence call when the repository is missing or is not a TableRepository
	 * subclass, keeping detached records read-only.
	 *
	 * @param string $operation Operation name used in diagnostics.
	 * @return class-string<TableRepository> Repository class attached to this record.
	 * @throws SqlError When no repository is attached or the class is not a valid repository.
	 */
	private function repositoryForRecordOperation(string $operation): string {
		$repository=$this->repositoryClass;
		if($repository===null || trim($repository)===''){
			throw SqlError::recordOperationUnavailable(static::class, $operation, 'This record is not attached to a repository.');
		}
		if(!class_exists($repository) || !is_subclass_of($repository, TableRepository::class)){
			throw SqlError::invalidRepositoryClass($repository);
		}
		return $repository;
	}

	/**
	 * Returns the current primary key value required for record persistence helpers.
	 *
	 * Write-back operations must identify exactly one row. The guard first
	 * resolves the primary key name from schema or constructor metadata, then rejects
	 * null and empty-string row values before repository mutation or refresh calls
	 * are attempted.
	 *
	 * @param string $operation Operation name used in diagnostics.
	 * @return mixed Non-null, non-empty primary key value from the hydrated row.
	 * @throws SqlError When the primary key column or value is unavailable.
	 */
	private function idForRecordOperation(string $operation): mixed {
		$primaryKey=$this->primaryKeyName();
		if($primaryKey===null || trim($primaryKey)===''){
			throw SqlError::recordOperationUnavailable(static::class, $operation, 'This record does not know its primary key column.');
		}
		$id=$this->id();
		if($id===null || $id===''){
			throw SqlError::recordOperationUnavailable(static::class, $operation, "This record has no value for primary key '{$primaryKey}'.");
		}
		return $id;
	}

	/**
	 * Reads and validates the optimistic-lock version for versioned operations.
	 *
	 * Version columns share the same identifier rules as record fields.
	 * Only non-negative integers and integer strings are accepted, because the value
	 * is later compared by the repository as the expected storage version. Invalid
	 * or missing values make the versioned operation unavailable instead of silently
	 * downgrading to an unsafe update.
	 *
	 * @param string $operation Operation name used in diagnostics.
	 * @param string $versionColumn Version column to read from the hydrated row.
	 * @return int Current non-negative optimistic-lock version.
	 * @throws SqlError When the version column is invalid, absent, negative, or non-integer.
	 */
	private function versionForRecordOperation(string $operation, string $versionColumn): int {
		$versionColumn=$this->assertRecordFieldName($versionColumn);
		if(!array_key_exists($versionColumn, $this->row)){
			throw SqlError::recordOperationUnavailable(static::class, $operation, "This record has no value for version column '{$versionColumn}'.");
		}
		$value=$this->row[$versionColumn];
		if(is_int($value)){
			$version=$value;
		}
		elseif(is_string($value) && preg_match('/^\d+$/', $value)===1){
			$version=(int)$value;
		}
		else
		{
			throw SqlError::recordOperationUnavailable(static::class, $operation, "Version column '{$versionColumn}' must contain an integer value.");
		}
		if($version<0){
			throw SqlError::recordOperationUnavailable(static::class, $operation, "Version column '{$versionColumn}' must be greater than or equal to zero.");
		}
		return $version;
	}

	/**
	 * Converts failed repository mutation results into exceptions.
	 *
	 * Refresh-after-write helpers use this guard after repository updates
	 * so callers never refresh from storage after a failed mutation. Repository
	 * error messages are preserved when present; otherwise the shared SqlError
	 * mutation formatter builds a contextual failure message.
	 *
	 * @param MutationResult $result Repository mutation result to inspect.
	 * @return void
	 * @throws \RuntimeException When the mutation result is failed.
	 */
	private function assertMutationSucceeded(MutationResult $result): void {
		if($result->failed()){
			throw new \RuntimeException($result->errorMessage() ?? SqlError::mutationErrorMessage($result->operation(), $result->context()));
		}
	}

	/**
	 * Validates a row field name before it is attached to a cloned record.
	 *
	 * In-memory row overlays and version column lookups use the same narrow
	 * SQL-style identifier rules as relation names. Trimming happens before
	 * validation, and invalid names fail closed through SqlError instead of being
	 * stored on the record snapshot.
	 *
	 * @param string $name Candidate row field name.
	 * @return string Trimmed, validated field name.
	 * @throws SqlError When the field name is empty or not a framework identifier.
	 */
	private function assertRecordFieldName(string $name): string {
		$name=trim($name);
		if($name==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)!==1){
			throw SqlError::invalidIdentifier('record field', $name);
		}
		return $name;
	}

	/**
	 * Creates a new immutable record instance for an adjusted row snapshot.
	 *
	 * Subclasses can preserve custom record construction by exposing a
	 * public static fromRow(...) factory. The factory must return an instance of the
	 * concrete record class; otherwise replication fails loudly to prevent helper
	 * methods from returning a downgraded or incompatible record type.
	 *
	 * @param array<string, mixed> $row Hydrated row values for the cloned snapshot.
	 * @return static New record instance with the same schema, repository, and primary key metadata.
	 * @throws \LogicException When a subclass factory returns an incompatible object.
	 */
	private function replicate(array $row): static {
		$class=static::class;
		if($class!==self::class && method_exists($class, 'fromRow')){
			$method=new \ReflectionMethod($class, 'fromRow');
			if($method->isPublic() && $method->isStatic()){
				$record=$class::fromRow($row, $this->schema, $this->repositoryClass, $this->primaryKey);
				if($record instanceof $class){
					return $record;
				}
				throw new \LogicException('Record fromRow(...) must return an instance of '.$class.'.');
			}
		}
		return new static($row, $this->schema, $this->repositoryClass, $this->primaryKey);
	}
}
