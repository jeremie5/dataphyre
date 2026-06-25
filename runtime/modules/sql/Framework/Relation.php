<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Repository relation descriptor for belongs-to, has-one, and has-many SQL relationships.
 *
 * A relation records the related repository class and the key columns required to build lookup,
 * eager-loading, count, aggregate, existence, and attachment queries. It validates identifiers early
 * so repository relation definitions fail before generating SQL.
 */
final class Relation {

	/**
	 * Creates a validated relation descriptor.
	 *
	 * Related repositories must exist and extend TableRepository before any SQL is
	 * generated, and key identifiers are validated by the factory methods.
	 *
	 * @param string $type Relation cardinality marker.
	 * @param string $relatedRepository Related TableRepository class.
	 * @param string $foreignKey Foreign-key column.
	 * @param string $localKey Local or owner-key column.
	 */
	private function __construct(
		private readonly string $type,
		private readonly string $relatedRepository,
		private readonly string $foreignKey,
		private readonly string $localKey
	){
		if(!class_exists($this->relatedRepository) || !is_subclass_of($this->relatedRepository, TableRepository::class)){
			throw SqlError::invalidRepositoryClass($this->relatedRepository);
		}
	}

	/**
	 * Defines an inverse relation where the parent row stores the foreign key.
	 *
	 * For belongs-to relations, the parent key column is the local foreign key and the related
	 * lookup column is the owner key on the related repository.
	 *
	 * @param string $relatedRepository TableRepository class for the owner side.
	 * @param string $foreignKey Column on the parent row that points to the owner.
	 * @param ?string $ownerKey Owner key on the related repository, defaulting to its primary key.
	 * @return self Relation descriptor.
	 */
	public static function belongsTo(string $relatedRepository, string $foreignKey, ?string $ownerKey=null): self {
		$ownerKey=$ownerKey ?? $relatedRepository::primaryKey();
		if($ownerKey===null){
			throw SqlError::missingPrimaryKeyForRepository($relatedRepository, 'define belongsTo(...) relation owner key');
		}
		return new self('belongs_to', $relatedRepository, self::identifier($foreignKey), self::identifier($ownerKey));
	}

	/**
	 * Defines a one-to-one relation where the related row stores the foreign key.
	 *
	 * @param string $relatedRepository TableRepository class for the related side.
	 * @param string $foreignKey Column on the related row that points back to the parent.
	 * @param string $localKey Column on the parent row used for the lookup.
	 * @return self Relation descriptor.
	 */
	public static function hasOne(string $relatedRepository, string $foreignKey, string $localKey): self {
		return new self('has_one', $relatedRepository, self::identifier($foreignKey), self::identifier($localKey));
	}

	/**
	 * Defines a one-to-many relation where related rows store the foreign key.
	 *
	 * @param string $relatedRepository TableRepository class for the related side.
	 * @param string $foreignKey Column on related rows that points back to the parent.
	 * @param string $localKey Column on the parent row used for the lookup.
	 * @return self Relation descriptor.
	 */
	public static function hasMany(string $relatedRepository, string $foreignKey, string $localKey): self {
		return new self('has_many', $relatedRepository, self::identifier($foreignKey), self::identifier($localKey));
	}

	/**
	 * Returns the relation cardinality/type marker.
	 *
	 * @return string One of `belongs_to`, `has_one`, or `has_many`.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Returns the related repository class name.
	 *
	 * @return class-string<TableRepository> Related repository class.
	 */
	public function relatedRepository(): string {
		return $this->relatedRepository;
	}

	/**
	 * Returns the foreign key column stored by the child side of the relation.
	 *
	 * @return string Validated SQL identifier.
	 */
	public function foreignKey(): string {
		return $this->foreignKey;
	}

	/**
	 * Returns the local/owner key column used to match related rows.
	 *
	 * @return string Validated SQL identifier.
	 */
	public function localKey(): string {
		return $this->localKey;
	}

	/**
	 * Returns the column read from parent records before loading related rows.
	 *
	 * @return string Foreign key for belongs-to, local key for has-one/has-many.
	 */
	public function parentKeyColumn(): string {
		return $this->type==='belongs_to' ? $this->foreignKey : $this->localKey;
	}

	/**
	 * Returns the column constrained on the related repository query.
	 *
	 * @return string Owner key for belongs-to, foreign key for has-one/has-many.
	 */
	public function relatedLookupColumn(): string {
		return $this->type==='belongs_to' ? $this->localKey : $this->foreignKey;
	}

	/**
	 * Loads related row arrays for one parent record.
	 *
	 * Missing parent key values return null for singular relations and an empty list for has-many.
	 *
	 * @param array|object $parent Parent row, Record, or object carrying the parent key column.
	 * @param array|string $columns Columns to select from the related repository.
	 * @param bool|array|string|null $caching Repository query cache hint.
	 * @param ?callable $constraint Optional callback that may further constrain the RepositoryQuery.
	 * @return mixed Single row/null for belongs-to and has-one, or list of rows for has-many.
	 */
	public function get(array|object $parent, array|string $columns='*', bool|array|string|null $caching=null, ?callable $constraint=null): mixed {
		$keyValue=$this->parentKeyValue($parent);
		if($keyValue===null || $keyValue===''){
			return $this->type==='has_many' ? [] : null;
		}
		$query=$this->relatedQueryForValues([$keyValue], $constraint);
		if($this->type==='has_many'){
			return $query->get($columns, $caching);
		}
		return $query->first($columns, $caching);
	}

	/**
	 * Loads hydrated related records for one parent record.
	 *
	 * @param array|object $parent Parent row, Record, or object carrying the parent key column.
	 * @param array|string $columns Columns to select from the related repository.
	 * @param mixed $hydrator Hydrator forwarded to the related repository.
	 * @param bool|array|string|null $caching Repository query cache hint.
	 * @param ?callable $constraint Optional callback that may further constrain the RepositoryQuery.
	 * @return mixed Hydrated record/null for singular relations, or list of hydrated records for has-many.
	 */
	public function getRecords(array|object $parent, array|string $columns='*', mixed $hydrator=null, bool|array|string|null $caching=null, ?callable $constraint=null): mixed {
		$keyValue=$this->parentKeyValue($parent);
		if($keyValue===null || $keyValue===''){
			return $this->type==='has_many' ? [] : null;
		}
		$query=$this->relatedQueryForValues([$keyValue], $constraint);
		if($this->type==='has_many'){
			return $query->getHydrated($columns, $hydrator, $caching);
		}
		return $query->firstHydrated($columns, $hydrator, $caching);
	}

	/**
	 * Eager-loads related row arrays for a parent collection.
	 *
	 * The returned map preserves parent indexes. Singular relations map to row/null; has-many maps
	 * to a list of rows for each parent.
	 *
	 * @param array<int|string,array|object> $parents Parent rows, Records, or objects.
	 * @param array|string $columns Columns to select from the related repository.
	 * @param bool|array|string|null $caching Repository query cache hint.
	 * @param ?callable $constraint Optional callback that may further constrain the RepositoryQuery.
	 * @return array<int|string,mixed> Related rows mapped by parent index.
	 */
	public function eager(array $parents, array|string $columns='*', bool|array|string|null $caching=null, ?callable $constraint=null): array {
		$values=[];
		foreach($parents as $parent){
			if(is_array($parent) || is_object($parent)){
				$value=$this->parentKeyValue($parent);
				if($value!==null && $value!==''){
					$values[(string)$value]=$value;
				}
			}
		}
		if($values===[]){
			return $this->emptyEagerMap($parents);
		}
		$rows=$this->relatedQueryForValues(array_values($values), $constraint)
			->get($this->relationColumns($columns), $caching);
		return $this->mapRowsToParents($parents, $rows);
	}

	/**
	 * Eager-loads hydrated related records for a parent collection.
	 *
	 * @param array<int|string,array|object> $parents Parent rows, Records, or objects.
	 * @param array|string $columns Columns to select from the related repository.
	 * @param mixed $hydrator Hydrator forwarded to the related repository.
	 * @param bool|array|string|null $caching Repository query cache hint.
	 * @param ?callable $constraint Optional callback that may further constrain the RepositoryQuery.
	 * @return array<int|string,mixed> Hydrated related values mapped by parent index.
	 */
	public function eagerRecords(array $parents, array|string $columns='*', mixed $hydrator=null, bool|array|string|null $caching=null, ?callable $constraint=null): array {
		$values=[];
		foreach($parents as $parent){
			if(is_array($parent) || is_object($parent)){
				$value=$this->parentKeyValue($parent);
				if($value!==null && $value!==''){
					$values[(string)$value]=$value;
				}
			}
		}
		if($values===[]){
			return $this->emptyEagerMap($parents);
		}
		$repository=$this->relatedRepository;
		$rows=$this->relatedQueryForValues(array_values($values), $constraint)
			->get($this->relationColumns($columns), $caching);
		$mapped=$this->mapRowsToParents($parents, $rows);
		foreach($mapped as $index=>$value){
			if($this->type==='has_many'){
				$mapped[$index]=array_map(
					static fn(array $row): mixed => $repository::hydrateRow($row, $hydrator),
					array_filter($value, 'is_array')
				);
				continue;
			}
			$mapped[$index]=is_array($value) ? $repository::hydrateRow($value, $hydrator) : null;
		}
		return $mapped;
	}

	/**
	 * Counts related rows for each parent in one grouped query.
	 *
	 * @param array<int|string,array|object> $parents Parent rows, Records, or objects.
	 * @param bool|array|string|null $caching Repository query cache hint.
	 * @param ?callable $constraint Optional callback that may further constrain the RepositoryQuery.
	 * @return array<int|string,int> Related row counts mapped by parent index.
	 */
	public function eagerCount(array $parents, bool|array|string|null $caching=null, ?callable $constraint=null): array {
		$values=[];
		foreach($parents as $parent){
			if(is_array($parent) || is_object($parent)){
				$value=$this->parentKeyValue($parent);
				if($value!==null && $value!==''){
					$values[(string)$value]=$value;
				}
			}
		}
		if($values===[]){
			return $this->emptyCountMap($parents);
		}
		$counts=$this->relatedQueryForValues(array_values($values), $constraint)
			->countBy($this->relatedLookupColumn(), '*', $caching);
		$mapped=[];
		foreach($parents as $index=>$parent){
			$value=is_array($parent) || is_object($parent) ? $this->parentKeyValue($parent) : null;
			$mapped[$index]=$value!==null && $value!=='' ? (int)($counts[(string)$value] ?? 0) : 0;
		}
		return $mapped;
	}

	/**
	 * Counts related rows for one parent.
	 *
	 * @param array|object $parent Parent row, Record, or object.
	 * @param bool|array|string|null $caching Repository query cache hint.
	 * @param ?callable $constraint Optional callback that may further constrain the RepositoryQuery.
	 * @return int Related row count.
	 */
	public function count(array|object $parent, bool|array|string|null $caching=null, ?callable $constraint=null): int {
		$counts=$this->eagerCount([$parent], $caching, $constraint);
		return (int)($counts[0] ?? 0);
	}

	/**
	 * Computes grouped aggregate values for each parent.
	 *
	 * Empty parent keys receive the aggregate default: zero for COUNT and null for other functions.
	 *
	 * @param array<int|string,array|object> $parents Parent rows, Records, or objects.
	 * @param string $function SQL aggregate function such as COUNT, SUM, AVG, MIN, or MAX.
	 * @param string $column Related column to aggregate.
	 * @param bool|array|string|null $caching Repository query cache hint.
	 * @param bool $distinct Whether the aggregate should use DISTINCT values.
	 * @param ?callable $constraint Optional callback that may further constrain the RepositoryQuery.
	 * @return array<int|string,mixed> Aggregate values mapped by parent index.
	 */
	public function eagerAggregate(
		array $parents,
		string $function,
		string $column,
		bool|array|string|null $caching=null,
		bool $distinct=false,
		?callable $constraint=null
	): array {
		$values=[];
		foreach($parents as $parent){
			if(is_array($parent) || is_object($parent)){
				$value=$this->parentKeyValue($parent);
				if($value!==null && $value!==''){
					$values[(string)$value]=$value;
				}
			}
		}
		if($values===[]){
			return $this->emptyAggregateMap($parents, $function);
		}
		$rows=$this->relatedQueryForValues(array_values($values), $constraint)
			->aggregateRowsBy($this->relatedLookupColumn(), $function, $column, $caching, $distinct);
		$aggregates=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$value=$this->valueFrom($row, $this->relatedLookupColumn());
			if($value===null || $value===''){
				continue;
			}
			$aggregates[(string)$value]=$row['aggregate_value'] ?? $this->defaultAggregateValue($function);
		}
		$mapped=[];
		foreach($parents as $index=>$parent){
			$value=is_array($parent) || is_object($parent) ? $this->parentKeyValue($parent) : null;
			$mapped[$index]=$value!==null && $value!=='' ? ($aggregates[(string)$value] ?? $this->defaultAggregateValue($function)) : $this->defaultAggregateValue($function);
		}
		return $mapped;
	}

	/**
	 * Computes one aggregate value for one parent.
	 *
	 * @param array|object $parent Parent row, Record, or object.
	 * @param string $function SQL aggregate function such as COUNT, SUM, AVG, MIN, or MAX.
	 * @param string $column Related column to aggregate.
	 * @param bool|array|string|null $caching Repository query cache hint.
	 * @param bool $distinct Whether the aggregate should use DISTINCT values.
	 * @param ?callable $constraint Optional callback that may further constrain the RepositoryQuery.
	 * @return mixed Aggregate value, zero for empty COUNT, or null for empty non-COUNT aggregates.
	 */
	public function aggregate(
		array|object $parent,
		string $function,
		string $column,
		bool|array|string|null $caching=null,
		bool $distinct=false,
		?callable $constraint=null
	): mixed {
		$values=$this->eagerAggregate([$parent], $function, $column, $caching, $distinct, $constraint);
		return $values[0] ?? $this->defaultAggregateValue($function);
	}

	/**
	 * Builds an EXISTS or NOT EXISTS SQL fragment for relation-aware parent filtering.
	 *
	 * Constraint QuerySpec instances are cloned, stripped of ordering/paging, compiled without
	 * selection, and appended to the related-table subquery.
	 *
	 * @param string $parentTable Parent table name used to qualify the parent key column.
	 * @param ?QuerySpec $constraint Optional related-query constraint.
	 * @param bool $exists True for EXISTS, false for NOT EXISTS.
	 * @return array{0:string,1:array} SQL fragment and bound values.
	 */
	public function existsCondition(string $parentTable, ?QuerySpec $constraint=null, bool $exists=true): array {
		$parentTable=self::identifier($parentTable);
		$relatedRepository=$this->relatedRepository;
		$relatedTable=self::identifier($relatedRepository::tableName());
		$compiled=$constraint!==null
			? (clone $constraint)->withoutOrdering()->withoutPaging()->compile(false)
			: ['params'=>'', 'vars'=>[]];
		$join=$this->qualifiedColumn($relatedTable, $this->relatedLookupColumn())
			.' = '
			.$this->qualifiedColumn($parentTable, $this->parentKeyColumn());
		$fragment=($exists ? 'EXISTS' : 'NOT EXISTS')
			.' (SELECT 1 FROM '.$relatedTable.' WHERE '.$join.$this->constraintSuffix((string)$compiled['params']).')';
		return [$fragment, is_array($compiled['vars'] ?? null) ? $compiled['vars'] : []];
	}

	/**
	 * Eager-loads related row arrays and attaches them to parent values.
	 *
	 * Record parents receive withRelation(), arrays receive a new key, and objects are cloned before
	 * the relation property is assigned when possible.
	 *
	 * @param array<int|string,array|object> $parents Parent rows, Records, or objects.
	 * @param string $name Relation property/key to attach.
	 * @param array|string $columns Columns to select from the related repository.
	 * @param bool|array|string|null $caching Repository query cache hint.
	 * @param ?callable $constraint Optional callback that may further constrain the RepositoryQuery.
	 * @return array<int|string,mixed> Parent values with the relation attached.
	 */
	public function attach(array $parents, string $name, array|string $columns='*', bool|array|string|null $caching=null, ?callable $constraint=null): array {
		return $this->attachMap($parents, $name, $this->eager($parents, $columns, $caching, $constraint));
	}

	/**
	 * Eager-loads hydrated related records and attaches them to parent values.
	 *
	 * @param array<int|string,array|object> $parents Parent rows, Records, or objects.
	 * @param string $name Relation property/key to attach.
	 * @param array|string $columns Columns to select from the related repository.
	 * @param mixed $hydrator Hydrator forwarded to the related repository.
	 * @param bool|array|string|null $caching Repository query cache hint.
	 * @param ?callable $constraint Optional callback that may further constrain the RepositoryQuery.
	 * @return array<int|string,mixed> Parent values with the hydrated relation attached.
	 */
	public function attachRecords(array $parents, string $name, array|string $columns='*', mixed $hydrator=null, bool|array|string|null $caching=null, ?callable $constraint=null): array {
		return $this->attachMap($parents, $name, $this->eagerRecords($parents, $columns, $hydrator, $caching, $constraint));
	}

	/**
	 * Counts related rows and attaches the count to each parent value.
	 *
	 * @param array<int|string,array|object> $parents Parent rows, Records, or objects.
	 * @param string $name Relation property/key to attach.
	 * @param bool|array|string|null $caching Repository query cache hint.
	 * @param ?callable $constraint Optional callback that may further constrain the RepositoryQuery.
	 * @return array<int|string,mixed> Parent values with count attached.
	 */
	public function attachCount(array $parents, string $name, bool|array|string|null $caching=null, ?callable $constraint=null): array {
		return $this->attachMap($parents, $name, $this->eagerCount($parents, $caching, $constraint));
	}

	/**
	 * Computes relation aggregates and attaches each aggregate to its parent value.
	 *
	 * @param array<int|string,array|object> $parents Parent rows, Records, or objects.
	 * @param string $name Relation property/key to attach.
	 * @param string $function SQL aggregate function such as COUNT, SUM, AVG, MIN, or MAX.
	 * @param string $column Related column to aggregate.
	 * @param bool|array|string|null $caching Repository query cache hint.
	 * @param bool $distinct Whether the aggregate should use DISTINCT values.
	 * @param ?callable $constraint Optional callback that may further constrain the RepositoryQuery.
	 * @return array<int|string,mixed> Parent values with aggregate attached.
	 */
	public function attachAggregate(
		array $parents,
		string $name,
		string $function,
		string $column,
		bool|array|string|null $caching=null,
		bool $distinct=false,
		?callable $constraint=null
	): array {
		return $this->attachMap($parents, $name, $this->eagerAggregate($parents, $function, $column, $caching, $distinct, $constraint));
	}

	/**
	 * Reads the parent-side key used to load related rows.
	 *
	 * belongs-to relations read the parent foreign key; has-one and has-many
	 * relations read the local key used by related rows.
	 *
	 * @param array|object $parent Parent row, Record, or object.
	 * @return mixed belongs-to foreign key value or local key value used to fetch related rows.
	 */
	private function parentKeyValue(array|object $parent): mixed {
		return $this->valueFrom($parent, $this->type==='belongs_to' ? $this->foreignKey : $this->localKey);
	}

	/**
	 * Groups related rows and maps them back to parent indexes.
	 *
	 * The returned map preserves the input parent indexes. has-many parents receive
	 * lists, while singular relations receive the first matching row or null.
	 *
	 * @param array<int|string,array|object> $parents Parent rows, Records, or objects.
	 * @param array<int,mixed> $rows Related rows fetched from the repository.
	 * @return array<int|string,mixed> Related values keyed by parent index.
	 */
	private function mapRowsToParents(array $parents, array $rows): array {
		$lookupColumn=$this->relatedLookupColumn();
		$grouped=[];
		foreach($rows as $row){
			$value=$this->valueFrom($row, $lookupColumn);
			if($value===null || $value===''){
				continue;
			}
			$key=(string)$value;
			if($this->type==='has_many'){
				$grouped[$key][]=$row;
				continue;
			}
			$grouped[$key] ??= $row;
		}
		$mapped=[];
		foreach($parents as $index=>$parent){
			$value=$this->parentKeyValue($parent);
			if($value===null || $value===''){
				$mapped[$index]=$this->type==='has_many' ? [] : null;
				continue;
			}
			$mapped[$index]=$grouped[(string)$value] ?? ($this->type==='has_many' ? [] : null);
		}
		return $mapped;
	}

	/**
	 * Ensures eager-load selects include the relation lookup column.
	 *
	 * The mapper needs the related lookup column even when callers request a narrow
	 * projection. Wildcard selects are left untouched.
	 *
	 * @param array|string $columns Requested related columns.
	 * @return array|string Columns including the lookup column, or wildcard.
	 */
	private function relationColumns(array|string $columns): array|string {
		if($columns==='*'){
			return '*';
		}
		if(is_string($columns)){
			$columns=[$columns];
		}
		$columns[]=$this->relatedLookupColumn();
		return array_values(array_unique(array_filter(array_map(
			static fn(mixed $column): string => trim((string)$column),
			$columns
		), static fn(string $column): bool => $column!=='')));
	}

	/**
	 * Builds the related repository query for a set of parent key values.
	 *
	 * The base query constrains the related lookup column with whereIn(). Optional
	 * constraints may mutate or replace the RepositoryQuery.
	 *
	 * @param array<int,mixed> $values Parent key values.
	 * @param ?callable $constraint Optional related-query constraint.
	 * @return RepositoryQuery Related repository query.
	 */
	private function relatedQueryForValues(array $values, ?callable $constraint=null): RepositoryQuery {
		$repository=$this->relatedRepository;
		$query=$repository::query()->whereIn($this->relatedLookupColumn(), $values);
		if($constraint===null){
			return $query;
		}
		$result=$constraint($query);
		return $result instanceof RepositoryQuery ? $result : $query;
	}

	/**
	 * Attaches mapped relation values to each parent.
	 *
	 * Missing map entries use cardinality-appropriate defaults so has-many
	 * relations attach empty lists and singular relations attach null.
	 *
	 * @param array<int|string,mixed> $parents Parent values.
	 * @param string $name Relation property/key.
	 * @param array<int|string,mixed> $mapped Related values keyed by parent index.
	 * @return array<int|string,mixed> Parent values with relation attached.
	 */
	private function attachMap(array $parents, string $name, array $mapped): array {
		$name=self::relationProperty($name);
		$attached=[];
		foreach($parents as $index=>$parent){
			$value=array_key_exists($index, $mapped) ? $mapped[$index] : ($this->type==='has_many' ? [] : null);
			$attached[$index]=$this->attachValue($parent, $name, $value);
		}
		return $attached;
	}

	/**
	 * Attaches one relation value to one parent value.
	 *
	 * Record instances use withRelation(), arrays receive a new key, and objects
	 * are cloned before assignment when they can accept the property.
	 *
	 * @param mixed $parent Parent value.
	 * @param string $name Validated relation property/key.
	 * @param mixed $value Relation value to attach.
	 * @return mixed cloned Record/object or array with relation value attached, or the original parent when attachment is unsupported.
	 */
	private function attachValue(mixed $parent, string $name, mixed $value): mixed {
		if($parent instanceof Record){
			return $parent->withRelation($name, $value);
		}
		if(is_array($parent)){
			$parent[$name]=$value;
			return $parent;
		}
		if(is_object($parent)){
			$copy=clone $parent;
			if($copy instanceof \stdClass || property_exists($copy, $name) || method_exists($copy, '__set')){
				$copy->{$name}=$value;
			}
			return $copy;
		}
		return $parent;
	}

	/**
	 * Builds an empty eager-load map that preserves parent indexes.
	 *
	 * Cardinality is preserved even when no parent keys can be loaded.
	 *
	 * @param array<int|string,mixed> $parents Parent values.
	 * @return array<int|string,mixed> Empty relation values by parent index.
	 */
	private function emptyEagerMap(array $parents): array {
		$mapped=[];
		foreach($parents as $index=>$_parent){
			$mapped[$index]=$this->type==='has_many' ? [] : null;
		}
		return $mapped;
	}

	/**
	 * Builds an empty count map that preserves parent indexes.
	 *
	 * @param array<int|string,mixed> $parents Parent values.
	 * @return array<int|string,int> Zero counts by parent index.
	 */
	private function emptyCountMap(array $parents): array {
		$mapped=[];
		foreach($parents as $index=>$_parent){
			$mapped[$index]=0;
		}
		return $mapped;
	}

	/**
	 * Builds an empty aggregate map that preserves parent indexes.
	 *
	 * COUNT aggregates default to zero and other aggregate functions default to
	 * null, matching SQL aggregate semantics for no related rows.
	 *
	 * @param array<int|string,mixed> $parents Parent values.
	 * @param string $function SQL aggregate function.
	 * @return array<int|string,mixed> Default aggregate values by parent index.
	 */
	private function emptyAggregateMap(array $parents, string $function): array {
		$mapped=[];
		foreach($parents as $index=>$_parent){
			$mapped[$index]=$this->defaultAggregateValue($function);
		}
		return $mapped;
	}

	/**
	 * Returns the empty-result default for a relation aggregate.
	 *
	 * @param string $function SQL aggregate function.
	 * @return mixed Zero for COUNT, null for other aggregate functions.
	 */
	private function defaultAggregateValue(string $function): mixed {
		return strtoupper(trim($function))==='COUNT' ? 0 : null;
	}

	/**
	 * Builds a validated table-qualified column reference.
	 *
	 * Both table and column names pass through identifier validation before the SQL
	 * fragment is returned.
	 *
	 * @param string $table Table identifier.
	 * @param string $column Column identifier.
	 * @return string Qualified SQL column.
	 */
	private function qualifiedColumn(string $table, string $column): string {
		return self::identifier($table).'.'.self::identifier($column);
	}

	/**
	 * Converts compiled constraint SQL into an EXISTS subquery suffix.
	 *
	 * Leading WHERE clauses become AND clauses because the relation join predicate
	 * already owns the subquery WHERE.
	 *
	 * @param string $params Compiled constraint SQL parameters fragment.
	 * @return string Constraint suffix for relation EXISTS SQL.
	 */
	private function constraintSuffix(string $params): string {
		$params=trim($params);
		if($params===''){
			return '';
		}
		if(str_starts_with(strtoupper($params), 'WHERE ')){
			return ' AND '.trim(substr($params, 6));
		}
		return ' '.$params;
	}

	/**
	 * Reads a column value from a Record, array, or object row.
	 *
	 * This is the common accessor used for parent keys and related lookup keys
	 * during eager mapping.
	 *
	 * @param array|object $source Source row.
	 * @param string $column Column name.
	 * @return mixed column value from Record, array, or object property, or null when unavailable.
	 */
	private function valueFrom(array|object $source, string $column): mixed {
		if($source instanceof Record){
			return $source->get($column);
		}
		if(is_array($source)){
			return $source[$column] ?? null;
		}
		return $source->{$column} ?? null;
	}

	/**
	 * Validates a SQL identifier used by relation SQL generation.
	 *
	 * Empty values and unsafe characters are rejected before they can become table,
	 * column, or qualified identifier fragments.
	 *
	 * @param string $identifier Raw SQL identifier.
	 * @return string Validated identifier.
	 */
	private static function identifier(string $identifier): string {
		$identifier=trim($identifier);
		if($identifier==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $identifier)!==1){
			throw SqlError::invalidIdentifier('relation', $identifier);
		}
		return $identifier;
	}

	/**
	 * Validates a PHP relation property/key name.
	 *
	 * Relation attachment uses property assignment for objects, so the accepted
	 * shape is stricter than SQL identifiers and does not allow dotted names.
	 *
	 * @param string $name Raw relation property name.
	 * @return string Validated relation property name.
	 */
	private static function relationProperty(string $name): string {
		$name=trim($name);
		if($name==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)!==1){
			throw SqlError::invalidIdentifier('relation property', $name);
		}
		return $name;
	}
}
