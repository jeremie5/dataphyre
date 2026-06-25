<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Mutable SQL clause specification used by Dataphyre database helpers.
 *
 * QuerySpec stores the tail of a SQL statement separately from the table and
 * selected columns: WHERE fragments, positional bindings, GROUP BY, HAVING,
 * ORDER BY, LIMIT/OFFSET, write-scope policy, and optional row-lock clauses.
 * Methods mutate the current instance unless their name starts with
 * `without*`, in which case a cloned instance is returned with that part
 * removed.
 *
 * Compiled output preserves binding order as WHERE variables followed by HAVING
 * variables. Raw fragment methods intentionally trust their caller and should
 * only receive framework-generated SQL or already-reviewed literals.
 */
class QuerySpec {

	/** @var array<int, mixed>|null */
	private static ?array $lastColumnsInput=null;

	private static array|string|null $lastColumnsResult=null;

	/** @var array<int, mixed>|null */
	private static ?array $previousColumnsInput=null;

	private static array|string|null $previousColumnsResult=null;

	private array $where=[];
	private array $vars=[];
	private array $groupBy=[];
	private array $having=[];
	private array $havingVars=[];
	private array $orderBy=[];
	private ?int $limit=null;
	private ?int $offset=null;
	private ?bool $writeRequiresWhere=null;
	private string|array|null $lockClause=null;

	/**
	 * Normalizes a column selection into a stable string or de-duplicated list.
	 *
	 * Empty strings collapse to `*`; array entries are trimmed, blank entries
	 * are removed, and duplicate column names keep their first occurrence.
	 *
	 * @param array<int, mixed>|string $columns Column selector supplied by a repository or table helper.
	 * @return array<int, string>|string `*`, a raw string selector, or a list of column identifiers/expressions.
	 */
	public static function columns(array|string $columns='*'): array|string {
		if(is_string($columns)){
			$columns=trim($columns);
			return $columns==='' ? '*' : $columns;
		}
		if(self::$lastColumnsInput!==null && $columns===self::$lastColumnsInput){
			return self::$lastColumnsResult;
		}
		if(self::$previousColumnsInput!==null && $columns===self::$previousColumnsInput){
			return self::$previousColumnsResult;
		}
		$normalized=[];
		$seen=[];
		foreach($columns as $column){
			$column=trim((string)$column);
			if($column==='' || isset($seen[$column])){
				continue;
			}
			$seen[$column]=true;
			$normalized[]=$column;
		}
		$result=$normalized===[] ? '*' : $normalized;
		self::$previousColumnsInput=self::$lastColumnsInput;
		self::$previousColumnsResult=self::$lastColumnsResult;
		self::$lastColumnsInput=$columns;
		self::$lastColumnsResult=$result;
		return $result;
	}

	/**
	 * Adds an equality predicate using a positional binding.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @param mixed $value Value appended to the WHERE binding list.
	 * @return self Current query specification.
	 */
	public function whereEq(string $column, mixed $value): self {
		return $this->whereCompare($column, '=', $value);
	}

	/**
	 * Adds an inequality predicate using `<>` and a positional binding.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @param mixed $value Value appended to the WHERE binding list.
	 * @return self Current query specification.
	 */
	public function whereNotEq(string $column, mixed $value): self {
		return $this->whereCompare($column, '<>', $value);
	}

	/**
	 * Adds a greater-than predicate using a positional binding.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @param mixed $value Value appended to the WHERE binding list.
	 * @return self Current query specification.
	 */
	public function whereGt(string $column, mixed $value): self {
		return $this->whereCompare($column, '>', $value);
	}

	/**
	 * Adds a greater-than-or-equal predicate using a positional binding.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @param mixed $value Value appended to the WHERE binding list.
	 * @return self Current query specification.
	 */
	public function whereGte(string $column, mixed $value): self {
		return $this->whereCompare($column, '>=', $value);
	}

	/**
	 * Adds a less-than predicate using a positional binding.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @param mixed $value Value appended to the WHERE binding list.
	 * @return self Current query specification.
	 */
	public function whereLt(string $column, mixed $value): self {
		return $this->whereCompare($column, '<', $value);
	}

	/**
	 * Adds a less-than-or-equal predicate using a positional binding.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @param mixed $value Value appended to the WHERE binding list.
	 * @return self Current query specification.
	 */
	public function whereLte(string $column, mixed $value): self {
		return $this->whereCompare($column, '<=', $value);
	}

	/**
	 * Adds an `IN (...)` predicate with one placeholder per value.
	 *
	 * An empty value list compiles to `1 = 0`, making the predicate safely
	 * unsatisfiable without requiring callers to special-case empty filters.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @param array<int, mixed> $values Values appended to the WHERE binding list.
	 * @return self Current query specification.
	 */
	public function whereIn(string $column, array $values): self {
		$column=$this->assertIdentifier($column);
		$values=array_values($values);
		if($values===[]){
			$this->where[]='1 = 0';
			return $this;
		}
		$placeholders=implode(', ', array_fill(0, count($values), '?'));
		$this->where[]=$column.' IN ('.$placeholders.')';
		foreach($values as $value){
			$this->vars[]=$value;
		}
		return $this;
	}

	/**
	 * Adds a `NOT IN (...)` predicate with one placeholder per value.
	 *
	 * An empty value list is ignored because every row is already outside an
	 * empty set.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @param array<int, mixed> $values Values appended to the WHERE binding list.
	 * @return self Current query specification.
	 */
	public function whereNotIn(string $column, array $values): self {
		$column=$this->assertIdentifier($column);
		$values=array_values($values);
		if($values===[]){
			return $this;
		}
		$placeholders=implode(', ', array_fill(0, count($values), '?'));
		$this->where[]=$column.' NOT IN ('.$placeholders.')';
		foreach($values as $value){
			$this->vars[]=$value;
		}
		return $this;
	}

	/**
	 * Adds a `LIKE` predicate using a caller-supplied pattern.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @param string $value Pattern appended to the WHERE binding list.
	 * @return self Current query specification.
	 */
	public function whereLike(string $column, string $value): self {
		return $this->whereCompare($column, 'LIKE', $value);
	}

	/**
	 * Adds a `NOT LIKE` predicate using a caller-supplied pattern.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @param string $value Pattern appended to the WHERE binding list.
	 * @return self Current query specification.
	 */
	public function whereNotLike(string $column, string $value): self {
		return $this->whereCompare($column, 'NOT LIKE', $value);
	}

	/**
	 * Adds a `BETWEEN ? AND ?` predicate.
	 *
	 * The lower bound is bound before the upper bound.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @param mixed $from Lower bound appended to the WHERE binding list.
	 * @param mixed $to Upper bound appended to the WHERE binding list.
	 * @return self Current query specification.
	 */
	public function whereBetween(string $column, mixed $from, mixed $to): self {
		$column=$this->assertIdentifier($column);
		$this->where[]=$column.' BETWEEN ? AND ?';
		$this->vars[]=$from;
		$this->vars[]=$to;
		return $this;
	}

	/**
	 * Adds an inclusive lower temporal bound.
	 *
	 * DateTimeInterface values are formatted as UTC-compatible SQL timestamps,
	 * integers are treated as Unix timestamps, and non-empty strings pass
	 * through unchanged.
	 *
	 * @param string $column Dot-qualified temporal column identifier.
	 * @param mixed $value Temporal value accepted by {@see self::normalizeTemporalValue()}.
	 * @return self Current query specification.
	 */
	public function whereSince(string $column, mixed $value): self {
		return $this->whereGte($column, $this->normalizeTemporalValue($value));
	}

	/**
	 * Adds an inclusive upper temporal bound.
	 *
	 * @param string $column Dot-qualified temporal column identifier.
	 * @param mixed $value Temporal value accepted by {@see self::normalizeTemporalValue()}.
	 * @return self Current query specification.
	 */
	public function whereUntil(string $column, mixed $value): self {
		return $this->whereLte($column, $this->normalizeTemporalValue($value));
	}

	/**
	 * Adds an exclusive lower temporal bound.
	 *
	 * @param string $column Dot-qualified temporal column identifier.
	 * @param mixed $value Temporal value accepted by {@see self::normalizeTemporalValue()}.
	 * @return self Current query specification.
	 */
	public function whereAfter(string $column, mixed $value): self {
		return $this->whereGt($column, $this->normalizeTemporalValue($value));
	}

	/**
	 * Adds an exclusive upper temporal bound.
	 *
	 * @param string $column Dot-qualified temporal column identifier.
	 * @param mixed $value Temporal value accepted by {@see self::normalizeTemporalValue()}.
	 * @return self Current query specification.
	 */
	public function whereBefore(string $column, mixed $value): self {
		return $this->whereLt($column, $this->normalizeTemporalValue($value));
	}

	/**
	 * Adds an inclusive temporal range predicate.
	 *
	 * Both bounds are normalized before they are bound, so empty strings and
	 * unsupported values fail before SQL is compiled.
	 *
	 * @param string $column Dot-qualified temporal column identifier.
	 * @param mixed $from Lower temporal bound.
	 * @param mixed $to Upper temporal bound.
	 * @return self Current query specification.
	 */
	public function whereWithin(string $column, mixed $from, mixed $to): self {
		return $this->whereBetween(
			$column,
			$this->normalizeTemporalValue($from),
			$this->normalizeTemporalValue($to)
		);
	}

	/**
	 * Filters a temporal column to rows newer than the current UTC time minus N minutes.
	 *
	 * @param string $column Dot-qualified temporal column identifier.
	 * @param int $minutes Positive minute window.
	 * @return self Current query specification.
	 */
	public function inLastMinutes(string $column, int $minutes): self {
		return $this->whereSince($column, $this->relativeTemporalValue('minutes', $minutes));
	}

	/**
	 * Filters a temporal column to rows newer than the current UTC time minus N hours.
	 *
	 * @param string $column Dot-qualified temporal column identifier.
	 * @param int $hours Positive hour window.
	 * @return self Current query specification.
	 */
	public function inLastHours(string $column, int $hours): self {
		return $this->whereSince($column, $this->relativeTemporalValue('hours', $hours));
	}

	/**
	 * Filters a temporal column to rows newer than the current UTC time minus N days.
	 *
	 * @param string $column Dot-qualified temporal column identifier.
	 * @param int $days Positive day window.
	 * @return self Current query specification.
	 */
	public function inLastDays(string $column, int $days): self {
		return $this->whereSince($column, $this->relativeTemporalValue('days', $days));
	}

	/**
	 * Adds an `IS NULL` predicate.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @return self Current query specification.
	 */
	public function whereNull(string $column): self {
		$this->where[]=$this->assertIdentifier($column).' IS NULL';
		return $this;
	}

	/**
	 * Adds an `IS NOT NULL` predicate.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @return self Current query specification.
	 */
	public function whereNotNull(string $column): self {
		$this->where[]=$this->assertIdentifier($column).' IS NOT NULL';
		return $this;
	}

	/**
	 * Appends a raw WHERE fragment and its positional bindings.
	 *
	 * Blank fragments are ignored. The fragment is not escaped or validated;
	 * callers are responsible for providing SQL that matches the target driver.
	 *
	 * @param string $fragment SQL predicate fragment without the `WHERE` keyword.
	 * @param array<int, mixed> $vars Bindings appended after earlier WHERE bindings.
	 * @return self Current query specification.
	 */
	public function whereRaw(string $fragment, array $vars=[]): self {
		$fragment=trim($fragment);
		if($fragment!==''){
			$this->where[]=$fragment;
			foreach($vars as $value){
				$this->vars[]=$value;
			}
		}
		return $this;
	}

	/**
	 * Adds a parenthesized AND group built by a nested QuerySpec callback.
	 *
	 * Empty nested groups are ignored. Bindings produced inside the group are
	 * appended to the parent in callback order.
	 *
	 * @param callable(self): mixed $callback Receives a fresh group builder.
	 * @return self Current query specification.
	 */
	public function whereAll(callable $callback): self {
		return $this->whereGroup('AND', $callback);
	}

	/**
	 * Adds a parenthesized OR group built by a nested QuerySpec callback.
	 *
	 * Empty nested groups are ignored. Bindings produced inside the group are
	 * appended to the parent in callback order.
	 *
	 * @param callable(self): mixed $callback Receives a fresh group builder.
	 * @return self Current query specification.
	 */
	public function whereAny(callable $callback): self {
		return $this->whereGroup('OR', $callback);
	}

	/**
	 * Conditionally applies a query callback.
	 *
	 * When the condition is truthy, the main callback receives this QuerySpec
	 * and the original condition. When the condition is falsy and a default
	 * callback is supplied, the default receives the same arguments. If a
	 * callback returns another QuerySpec instance it becomes the fluent result;
	 * other return values are ignored.
	 *
	 * @param mixed $condition Runtime condition passed through to the selected callback.
	 * @param callable(self, mixed): mixed $callback Callback used for truthy conditions.
	 * @param null|callable(self, mixed): mixed $default Callback used for falsy conditions.
	 * @return self Current or callback-returned query specification.
	 */
	public function when(mixed $condition, callable $callback, ?callable $default=null): self {
		if($condition){
			$result=$callback($this, $condition);
			return $result instanceof self ? $result : $this;
		}
		if($default!==null){
			$result=$default($this, $condition);
			return $result instanceof self ? $result : $this;
		}
		return $this;
	}

	/**
	 * Applies a callback when a condition is falsy.
	 *
	 * This is the inverse of {@see self::when()} and keeps the same callback
	 * return contract.
	 *
	 * @param mixed $condition Runtime condition passed through to the selected callback.
	 * @param callable(self, mixed): mixed $callback Callback used for falsy conditions.
	 * @param null|callable(self, mixed): mixed $default Callback used for truthy conditions.
	 * @return self Current or callback-returned query specification.
	 */
	public function unless(mixed $condition, callable $callback, ?callable $default=null): self {
		return $this->when(!$condition, $callback, $default);
	}

	/**
	 * Applies a callback only when a value is not null.
	 *
	 * The callback receives the non-null value as its second argument, which is
	 * useful for optional filters that still allow `0`, `false`, or empty
	 * strings.
	 *
	 * @param mixed $value Candidate value for an optional predicate.
	 * @param callable(self, mixed): mixed $callback Callback used when the value is not null.
	 * @param null|callable(self, mixed): mixed $default Callback used when the value is null.
	 * @return self Current or callback-returned query specification.
	 */
	public function whenNotNull(mixed $value, callable $callback, ?callable $default=null): self {
		if($value!==null){
			$result=$callback($this, $value);
			return $result instanceof self ? $result : $this;
		}
		if($default!==null){
			$result=$default($this, $value);
			return $result instanceof self ? $result : $this;
		}
		return $this;
	}

	/**
	 * Applies a callback when a value is meaningfully filled.
	 *
	 * Null, blank strings, and empty arrays are treated as unfilled; other
	 * falsy values such as `0` and `false` still run the callback.
	 *
	 * @param mixed $value Candidate value for an optional predicate.
	 * @param callable(self, mixed): mixed $callback Callback used when the value is filled.
	 * @param null|callable(self, mixed): mixed $default Callback used when the value is empty.
	 * @return self Current or callback-returned query specification.
	 */
	public function whenFilled(mixed $value, callable $callback, ?callable $default=null): self {
		$isFilled=!($value===null || (is_string($value) && trim($value)==='') || (is_array($value) && $value===[]));
		if($isFilled){
			$result=$callback($this, $value);
			return $result instanceof self ? $result : $this;
		}
		if($default!==null){
			$result=$default($this, $value);
			return $result instanceof self ? $result : $this;
		}
		return $this;
	}

	/**
	 * Gives external code a chance to inspect or mutate the current specification.
	 *
	 * Returning another QuerySpec replaces the fluent result; any other return
	 * value keeps the current instance.
	 *
	 * @param callable(self): mixed $callback Inspection or mutation callback.
	 * @return self Current or callback-returned query specification.
	 */
	public function tap(callable $callback): self {
		$result=$callback($this);
		return $result instanceof self ? $result : $this;
	}

	/**
	 * Checks whether the specification currently contains at least one WHERE fragment.
	 *
	 * @return bool True when a scoped predicate has been added.
	 */
	public function hasWhere(): bool {
		return $this->where!==[];
	}

	/**
	 * Sets the write-safety policy for UPDATE or DELETE callers.
	 *
	 * When enabled, {@see self::assertScopedForWrite()} rejects write
	 * operations unless at least one WHERE fragment exists.
	 *
	 * @param bool $required Whether writes using this specification must be WHERE-scoped.
	 * @return self Current query specification.
	 */
	public function requireWhereForWrite(bool $required=true): self {
		$this->writeRequiresWhere=$required;
		return $this;
	}

	/**
	 * Explicitly allows write callers to run without a WHERE clause.
	 *
	 * Use this when a full-table mutation is intentional and should bypass
	 * repository-level default guards.
	 *
	 * @return self Current query specification.
	 */
	public function allowUnscopedWrite(): self {
		$this->writeRequiresWhere=false;
		return $this;
	}

	/**
	 * Resolves the effective write-safety policy.
	 *
	 * Instance-level policy wins over the caller-supplied default. A null
	 * default resolves to false.
	 *
	 * @param ?bool $default Repository or operation default when no policy is set here.
	 * @return bool True when write operations must have a WHERE clause.
	 */
	public function writeRequiresWhere(?bool $default=null): bool {
		return $this->writeRequiresWhere ?? (bool)$default;
	}

	/**
	 * Throws when a guarded write would execute without a WHERE clause.
	 *
	 * The owner and operation are passed through to SqlError so diagnostics can
	 * name the repository/table helper and mutation being rejected.
	 *
	 * @param string $owner Component responsible for the write.
	 * @param string $operation Mutation name such as `update` or `delete`.
	 * @param bool $defaultRequired Default guard when this specification has no explicit policy.
	 */
	public function assertScopedForWrite(string $owner, string $operation, bool $defaultRequired=false): void {
		if($this->writeRequiresWhere($defaultRequired) && !$this->hasWhere()){
			throw SqlError::unscopedMutation($owner, $operation);
		}
	}

	/**
	 * Requests an exclusive row lock when the compiled SQL is used inside a transaction.
	 *
	 * MySQL and PostgreSQL compile to `FOR UPDATE`; SQLite receives no lock
	 * suffix because the driver handles locking at database/transaction level.
	 *
	 * @return self Current query specification.
	 */
	public function forUpdate(): self {
		$this->lockClause=[
			'mysql'=>'FOR UPDATE',
			'postgresql'=>'FOR UPDATE',
			'sqlite'=>'',
		];
		return $this;
	}

	/**
	 * Requests a shared/read row lock where the driver supports it.
	 *
	 * MySQL compiles to `LOCK IN SHARE MODE`, PostgreSQL to `FOR SHARE`, and
	 * SQLite receives no lock suffix.
	 *
	 * @return self Current query specification.
	 */
	public function sharedLock(): self {
		$this->lockClause=[
			'mysql'=>'LOCK IN SHARE MODE',
			'postgresql'=>'FOR SHARE',
			'sqlite'=>'',
		];
		return $this;
	}

	/**
	 * Stores a custom lock clause.
	 *
	 * A string applies to every driver. An array may provide driver-specific
	 * clauses for `mysql`, `postgresql`, and `sqlite`; blank clauses are removed.
	 *
	 * @param string|array<string,string> $fragment Lock suffix without surrounding statement text.
	 * @return self Current query specification.
	 */
	public function lockRaw(string|array $fragment): self {
		$this->lockClause=$this->normalizeLockClause($fragment);
		return $this;
	}

	/**
	 * Removes any configured lock clause from the current instance.
	 *
	 * @return self Current query specification.
	 */
	public function clearLocking(): self {
		$this->lockClause=null;
		return $this;
	}

	/**
	 * Returns a clone without lock clauses, preserving the original instance.
	 *
	 * @return self Cloned query specification without locking.
	 */
	public function withoutLocking(): self {
		$clone=clone $this;
		return $clone->clearLocking();
	}

	/**
	 * Adds an ORDER BY clause for a validated identifier.
	 *
	 * Any direction other than case-insensitive `DESC` is normalized to `ASC`.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @param string $direction Sort direction; only `DESC` changes the default ascending order.
	 * @return self Current query specification.
	 */
	public function orderBy(string $column, string $direction='ASC'): self {
		$direction=strtoupper(trim($direction));
		$direction=$direction==='DESC' ? 'DESC' : 'ASC';
		$this->orderBy[]=$this->assertIdentifier($column).' '.$direction;
		return $this;
	}

	/**
	 * Appends a raw ORDER BY expression.
	 *
	 * Blank fragments are ignored. The expression is not escaped or validated.
	 *
	 * @param string $fragment SQL ordering expression without the `ORDER BY` keyword.
	 * @return self Current query specification.
	 */
	public function orderByRaw(string $fragment): self {
		$fragment=trim($fragment);
		if($fragment!==''){
			$this->orderBy[]=$fragment;
		}
		return $this;
	}

	/**
	 * Adds an ascending ORDER BY clause.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @return self Current query specification.
	 */
	public function orderByAsc(string $column): self {
		return $this->orderBy($column, 'ASC');
	}

	/**
	 * Adds a descending ORDER BY clause.
	 *
	 * @param string $column Dot-qualified SQL identifier accepted by {@see self::assertIdentifier()}.
	 * @return self Current query specification.
	 */
	public function orderByDesc(string $column): self {
		return $this->orderBy($column, 'DESC');
	}

	/**
	 * Orders newest records first using the supplied timestamp column.
	 *
	 * @param string $column Dot-qualified timestamp column; defaults to `created_at`.
	 * @return self Current query specification.
	 */
	public function latest(string $column='created_at'): self {
		return $this->orderByDesc($column);
	}

	/**
	 * Orders oldest records first using the supplied timestamp column.
	 *
	 * @param string $column Dot-qualified timestamp column; defaults to `created_at`.
	 * @return self Current query specification.
	 */
	public function oldest(string $column='created_at'): self {
		return $this->orderByAsc($column);
	}

	/**
	 * Adds one or more validated GROUP BY identifiers.
	 *
	 * Duplicate identifiers are removed after the new identifiers are appended.
	 *
	 * @param string|array<int, string> $columns Dot-qualified grouping identifiers.
	 * @return self Current query specification.
	 */
	public function groupBy(string|array $columns): self {
		$columns=is_string($columns) ? [$columns] : $columns;
		foreach($columns as $column){
			$this->groupBy[]=$this->assertIdentifier((string)$column);
		}
		$unique=[];
		$seen=[];
		foreach($this->groupBy as $column){
			if(isset($seen[$column])){
				continue;
			}
			$seen[$column]=true;
			$unique[]=$column;
		}
		$this->groupBy=$unique;
		return $this;
	}

	/**
	 * Appends a raw GROUP BY expression.
	 *
	 * Blank fragments are ignored. The expression is not escaped or validated.
	 *
	 * @param string $fragment SQL grouping expression without the `GROUP BY` keyword.
	 * @return self Current query specification.
	 */
	public function groupByRaw(string $fragment): self {
		$fragment=trim($fragment);
		if($fragment!==''){
			$this->groupBy[]=$fragment;
		}
		return $this;
	}

	/**
	 * Appends a raw HAVING predicate and its positional bindings.
	 *
	 * HAVING bindings compile after all WHERE bindings.
	 *
	 * @param string $fragment SQL predicate fragment without the `HAVING` keyword.
	 * @param array<int, mixed> $vars Bindings appended after earlier HAVING bindings.
	 * @return self Current query specification.
	 */
	public function havingRaw(string $fragment, array $vars=[]): self {
		$fragment=trim($fragment);
		if($fragment!==''){
			$this->having[]=$fragment;
			foreach($vars as $value){
				$this->havingVars[]=$value;
			}
		}
		return $this;
	}

	/**
	 * Sets the LIMIT value, clamped to zero or greater.
	 *
	 * @param int $limit Maximum row count requested by the caller.
	 * @return self Current query specification.
	 */
	public function limit(int $limit): self {
		$this->limit=max(0, $limit);
		return $this;
	}

	/**
	 * Sets the OFFSET value, clamped to zero or greater.
	 *
	 * A zero offset is stored but omitted from compiled SQL because it does not
	 * change the result set.
	 *
	 * @param int $offset Number of rows to skip.
	 * @return self Current query specification.
	 */
	public function offset(int $offset): self {
		$this->offset=max(0, $offset);
		return $this;
	}

	/**
	 * Applies page-number based LIMIT/OFFSET values.
	 *
	 * Page and per-page values are clamped to at least one before the offset is
	 * calculated.
	 *
	 * @param int $page One-based page number.
	 * @param int $perPage Positive number of rows per page.
	 * @return self Current query specification.
	 */
	public function forPage(int $page, int $perPage): self {
		$page=max(1, $page);
		$perPage=max(1, $perPage);
		return $this->limit($perPage)->offset(($page - 1) * $perPage);
	}

	/**
	 * Removes ORDER BY clauses from the current instance.
	 *
	 * @return self Current query specification.
	 */
	public function clearOrdering(): self {
		$this->orderBy=[];
		return $this;
	}

	/**
	 * Removes GROUP BY, HAVING, and HAVING bindings from the current instance.
	 *
	 * @return self Current query specification.
	 */
	public function clearGrouping(): self {
		$this->groupBy=[];
		$this->having=[];
		$this->havingVars=[];
		return $this;
	}

	/**
	 * Removes the LIMIT clause from the current instance.
	 *
	 * @return self Current query specification.
	 */
	public function clearLimit(): self {
		$this->limit=null;
		return $this;
	}

	/**
	 * Removes the OFFSET clause from the current instance.
	 *
	 * @return self Current query specification.
	 */
	public function clearOffset(): self {
		$this->offset=null;
		return $this;
	}

	/**
	 * Removes both LIMIT and OFFSET from the current instance.
	 *
	 * @return self Current query specification.
	 */
	public function clearPaging(): self {
		$this->limit=null;
		$this->offset=null;
		return $this;
	}

	/**
	 * Returns a clone without ORDER BY clauses, preserving the original instance.
	 *
	 * @return self Cloned query specification without ordering.
	 */
	public function withoutOrdering(): self {
		$clone=clone $this;
		return $clone->clearOrdering();
	}

	/**
	 * Returns a clone without GROUP BY or HAVING state, preserving the original instance.
	 *
	 * @return self Cloned query specification without grouping.
	 */
	public function withoutGrouping(): self {
		$clone=clone $this;
		return $clone->clearGrouping();
	}

	/**
	 * Returns a clone without LIMIT or OFFSET, preserving the original instance.
	 *
	 * @return self Cloned query specification without paging.
	 */
	public function withoutPaging(): self {
		$clone=clone $this;
		return $clone->clearPaging();
	}

	/**
	 * Compiles the stored clauses into SQL tail text and positional bindings.
	 *
	 * The `params` value is a formatted string by default. When a driver-specific
	 * lock clause is configured and included, `params` becomes an array keyed by
	 * `mysql`, `postgresql`, and `sqlite` so callers can select the right suffix.
	 *
	 * @param bool $includeLock Whether to append the configured lock clause.
	 * @return array{params:string|array<string,string>,vars:array<int,mixed>} SQL tail and binding list.
	 */
	public function compile(bool $includeLock=true): array {
		$params=[];
		if($this->where!==[]){
			$params[]='WHERE '.implode(' AND ', $this->where);
		}
		if($this->groupBy!==[]){
			$params[]='GROUP BY '.implode(', ', $this->groupBy);
		}
		if($this->having!==[]){
			$params[]='HAVING '.implode(' AND ', $this->having);
		}
		if($this->orderBy!==[]){
			$params[]='ORDER BY '.implode(', ', $this->orderBy);
		}
		if($this->limit!==null){
			$params[]='LIMIT '.$this->limit;
		}
		if($this->offset!==null && $this->offset>0){
			$params[]='OFFSET '.$this->offset;
		}
		$compiledParams=$this->formatParams($params);
		if($includeLock && $this->lockClause!==null){
			$compiledParams=$this->appendLockClause($compiledParams);
		}
		$vars=$this->havingVars===[] ? $this->vars : ($this->vars===[] ? $this->havingVars : array_merge($this->vars, $this->havingVars));
		return [
			'params'=>$compiledParams,
			'vars'=>$vars,
		];
	}

	/**
	 * Exposes a safe diagnostic snapshot of the current query specification.
	 *
	 * Bind values are intentionally not returned; only the aggregate binding
	 * count is exposed so traces can describe query shape without leaking data.
	 *
	 * @return array{
	 *     where_fragments: array<int, string>,
	 *     vars_count: int,
	 *     group_by: array<int, string>,
	 *     having: array<int, string>,
	 *     order_by: array<int, string>,
	 *     write_requires_where?: bool,
	 *     lock_clause?: string|array<string, string>,
	 *     limit?: int,
	 *     offset?: int
	 * }
	 */
	public function debugContext(): array {
		$context=[
			'where_fragments'=>$this->where,
			'vars_count'=>count($this->vars) + count($this->havingVars),
			'group_by'=>$this->groupBy,
			'having'=>$this->having,
			'order_by'=>$this->orderBy,
		];
		if($this->writeRequiresWhere!==null){
			$context['write_requires_where']=$this->writeRequiresWhere;
		}
		if($this->lockClause!==null){
			$context['lock_clause']=$this->lockClause;
		}
		if($this->limit!==null){
			$context['limit']=$this->limit;
		}
		if($this->offset!==null){
			$context['offset']=$this->offset;
		}
		return $context;
	}

	/**
	 * Serializes the complete mutable builder state for subclasses.
	 *
	 * This is intentionally fuller than {@see self::debugContext()} and includes
	 * binding values so a subclass can snapshot, clone, or transfer builder state.
	 *
	 * @return array{
	 *     where: array<int, string>,
	 *     vars: array<int, mixed>,
	 *     group_by: array<int, string>,
	 *     having: array<int, string>,
	 *     having_vars: array<int, mixed>,
	 *     order_by: array<int, string>,
	 *     limit: ?int,
	 *     offset: ?int,
	 *     write_requires_where: ?bool,
	 *     lock_clause: string|array<string, string>|null
	 * }
	 */
	protected function builderState(): array {
		return [
			'where'=>$this->where,
			'vars'=>$this->vars,
			'group_by'=>$this->groupBy,
			'having'=>$this->having,
			'having_vars'=>$this->havingVars,
			'order_by'=>$this->orderBy,
			'limit'=>$this->limit,
			'offset'=>$this->offset,
			'write_requires_where'=>$this->writeRequiresWhere,
			'lock_clause'=>$this->lockClause,
		];
	}

	/**
	 * Restores mutable builder state from a subclass snapshot.
	 *
	 * Missing or wrongly typed entries fall back to the same empty/null defaults
	 * used by a new QuerySpec instance. Lock clauses are normalized during
	 * restore so stale blank driver entries cannot survive.
	 *
	 * @param array<string, mixed> $state State produced by {@see self::builderState()} or a compatible subclass snapshot.
	 */
	protected function applyBuilderState(array $state): void {
		$this->where=is_array($state['where'] ?? null) ? array_values($state['where']) : [];
		$this->vars=is_array($state['vars'] ?? null) ? array_values($state['vars']) : [];
		$this->groupBy=is_array($state['group_by'] ?? null) ? array_values($state['group_by']) : [];
		$this->having=is_array($state['having'] ?? null) ? array_values($state['having']) : [];
		$this->havingVars=is_array($state['having_vars'] ?? null) ? array_values($state['having_vars']) : [];
		$this->orderBy=is_array($state['order_by'] ?? null) ? array_values($state['order_by']) : [];
		$this->limit=is_int($state['limit'] ?? null) ? $state['limit'] : null;
		$this->offset=is_int($state['offset'] ?? null) ? $state['offset'] : null;
		$this->writeRequiresWhere=is_bool($state['write_requires_where'] ?? null) ? $state['write_requires_where'] : null;
		$this->lockClause=$this->normalizeLockClause($state['lock_clause'] ?? null);
	}

	/**
	 * Formats ordered SQL clause fragments using Dataphyre's indented tail layout.
	 *
	 * @param array<int, string> $params Clause fragments in compile order.
	 * @return string Empty string when no clauses exist; otherwise newline-wrapped SQL tail text.
	 */
	private function formatParams(array $params): string {
		return $params===[] ? '' : "\n\t\t\t".implode("\n\t\t\t", $params)."\n\t\t";
	}

	/**
	 * Appends the configured lock clause to compiled SQL params.
	 *
	 * Driver-specific lock arrays produce driver-specific params arrays; string
	 * locks produce a single SQL tail string.
	 *
	 * @param string $params Formatted SQL tail produced by {@see self::formatParams()}.
	 * @return string|array{mysql: string, postgresql: string, sqlite: string} Lock-adjusted SQL tail.
	 */
	private function appendLockClause(string $params): string|array {
		if(is_array($this->lockClause)){
			$compiled=[];
			foreach(['mysql', 'postgresql', 'sqlite'] as $dbms){
				$compiled[$dbms]=$this->appendSingleLockClause($params, $this->lockClause[$dbms] ?? '');
			}
			return $compiled;
		}
		return $this->appendSingleLockClause($params, $this->lockClause);
	}

	/**
	 * Appends one lock suffix while preserving the surrounding SQL tail whitespace.
	 *
	 * @param string $params Existing formatted SQL tail.
	 * @param ?string $clause Driver lock clause or blank value for no lock.
	 * @return string SQL tail with the clause appended when present.
	 */
	private function appendSingleLockClause(string $params, ?string $clause): string {
		$clause=trim((string)$clause);
		if($clause===''){
			return $params;
		}
		if(trim($params)===''){
			return "\n\t\t\t".$clause."\n\t\t";
		}
		return rtrim($params)."\n\t\t\t".$clause."\n\t\t";
	}

	/**
	 * Normalizes a raw lock declaration into the stored lock shape.
	 *
	 * Blank strings and all-blank driver maps collapse to null.
	 *
	 * @param mixed $fragment String lock suffix, driver map, or unsupported value.
	 * @return string|array{mysql?: string, postgresql?: string, sqlite?: string}|null Normalized lock clause.
	 */
	private function normalizeLockClause(mixed $fragment): string|array|null {
		if(is_array($fragment)){
			$normalized=[];
			$hasValue=false;
			foreach(['mysql', 'postgresql', 'sqlite'] as $dbms){
				$value=trim((string)($fragment[$dbms] ?? ''));
				$normalized[$dbms]=$value;
				if($value!==''){
					$hasValue=true;
				}
			}
			return $hasValue ? $normalized : null;
		}
		if(is_string($fragment)){
			$fragment=trim($fragment);
			return $fragment!=='' ? $fragment : null;
		}
		return null;
	}

	/**
	 * Validates a simple SQL identifier used by safe builder methods.
	 *
	 * Identifiers may contain dots for table-qualified columns but may not
	 * contain quoting, functions, aliases, or other SQL expressions. Use raw
	 * methods for framework-owned expressions that intentionally need those.
	 *
	 * @param string $identifier Candidate column or grouping identifier.
	 * @return string Trimmed identifier.
	 */
	private function assertIdentifier(string $identifier): string {
		$identifier=trim($identifier);
		if($identifier==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $identifier)!==1){
			throw SqlError::invalidIdentifier('query', $identifier);
		}
		return $identifier;
	}

	/**
	 * Appends a binary comparison predicate and one binding.
	 *
	 * @param string $column Dot-qualified SQL identifier.
	 * @param string $operator SQL comparison operator supplied by the public helper.
	 * @param mixed $value Value appended to the WHERE binding list.
	 * @return self Current query specification.
	 */
	private function whereCompare(string $column, string $operator, mixed $value): self {
		$this->where[]=$this->assertIdentifier($column).' '.$operator.' ?';
		$this->vars[]=$value;
		return $this;
	}

	/**
	 * Builds a nested WHERE group and merges its fragments into the parent.
	 *
	 * @param string $glue Boolean glue used between nested fragments, usually `AND` or `OR`.
	 * @param callable(self): mixed $callback Callback that mutates a fresh group builder.
	 * @return self Current query specification.
	 */
	private function whereGroup(string $glue, callable $callback): self {
		$group=new self();
		$callback($group);
		if($group->where===[]){
			return $this;
		}
		$this->where[]='('.implode(' '.$glue.' ', $group->where).')';
		foreach($group->vars as $value){
			$this->vars[]=$value;
		}
		return $this;
	}

	/**
	 * Converts supported temporal values into SQL timestamp strings.
	 *
	 * @param mixed $value DateTimeInterface, Unix timestamp, or non-empty string.
	 * @return string SQL-compatible temporal string.
	 */
	private function normalizeTemporalValue(mixed $value): string {
		if($value instanceof \DateTimeInterface){
			return $value->format('Y-m-d H:i:s');
		}
		if(is_int($value)){
			return gmdate('Y-m-d H:i:s', $value);
		}
		if(is_string($value)){
			$value=trim($value);
			if($value!==''){
				return $value;
			}
		}
		throw SqlError::invalidTemporalValue('query', $value);
	}

	/**
	 * Produces a UTC timestamp for a relative time window.
	 *
	 * @param string $unit DateTime modifier unit such as `minutes`, `hours`, or `days`.
	 * @param int $amount Positive amount to subtract from the current UTC time.
	 * @return string SQL timestamp for the lower bound.
	 */
	private function relativeTemporalValue(string $unit, int $amount): string {
		if($amount<=0){
			throw SqlError::invalidTemporalWindow('query', $unit, $amount);
		}
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
			->modify('-'.$amount.' '.$unit)
			->format('Y-m-d H:i:s');
	}
}
