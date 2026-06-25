<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Framework entry point for Dataphyre's SQL kernel.
 *
 * `DB` provides typed entry points over the snake_case SQL kernel functions while preserving
 * Dataphyre's cluster selection, table-definition hydration, cache naming, invalidation, and
 * query tracing behavior. Methods here may execute SQL or register runtime bridges, so callers
 * should treat `DB` as an active service rather than a passive query builder.
 */
final class DB {

	/** @var array<int, string>|null */
	private static ?array $lastCacheNamesInput=null;

	/** @var list<true|string>|null */
	private static ?array $lastCacheNamesResult=null;

	/** @var array<int, string>|null */
	private static ?array $previousCacheNamesInput=null;

	/** @var list<true|string>|null */
	private static ?array $previousCacheNamesResult=null;

	/** @var array<int, string>|null */
	private static ?array $lastInvalidationNamesInput=null;

	/** @var list<string>|null */
	private static ?array $lastInvalidationNamesResult=null;

	/** @var array<int, string>|null */
	private static ?array $previousInvalidationNamesInput=null;

	/** @var list<string>|null */
	private static ?array $previousInvalidationNamesResult=null;

	/** @var list<callable(ExecutionTrace): void> */
	private static array $traceObservers=[];
	/** @var list<callable(ExecutionTrace): void> */
	private static array $internalTraceObservers=[];

	/** @var list<ExecutionTrace> */
	private static array $traceBuffer=[];
	private static array $traceContextStack=[];

	private static int $traceBufferLimit=256;
	private static bool $kernelObserverRegistered=false;
	private static bool $templatingCacheBridgeRegistered=false;
	private static bool $apiCacheBridgeRegistered=false;
	private static ?bool $guardrailsEnabled=null;

	/**
	 * Returns the default read-cache policy used by Framework table/query helpers.
	 *
	 * The leading `true` preserves the legacy kernel convention meaning "cache reads using
	 * the table policy unless more specific cache names are appended."
	 *
	 * @return array{0:true} Default cache policy.
	 */
	public static function defaultReadCaching(): array {
		static::bootRuntimeBridges();
		return [true];
	}

	/**
	 * Builds a read-cache policy with normalized cache namespace names.
	 *
	 * Empty names are ignored and duplicates are removed while preserving Dataphyre's required
	 * leading `true` cache-enable marker.
	 *
	 * @param string ...$names Cache namespace names to attach to the read.
	 * @return list<true|string> Cache policy suitable for kernel query calls.
	 */
	public static function cacheNames(string ...$names): array {
		if(self::$lastCacheNamesInput!==null && $names===self::$lastCacheNamesInput){
			return self::$lastCacheNamesResult;
		}
		if(self::$previousCacheNamesInput!==null && $names===self::$previousCacheNamesInput){
			return self::$previousCacheNamesResult;
		}
		$normalized=[true];
		$seen=[];
		foreach($names as $name){
			$name=trim($name);
			if($name==='' || isset($seen[$name])){
				continue;
			}
			$seen[$name]=true;
			$normalized[]=$name;
		}
		self::$previousCacheNamesInput=self::$lastCacheNamesInput;
		self::$previousCacheNamesResult=self::$lastCacheNamesResult;
		self::$lastCacheNamesInput=$names;
		self::$lastCacheNamesResult=$normalized;
		return $normalized;
	}

	/**
	 * Builds a normalized list of cache namespaces to invalidate after a write.
	 *
	 * @param string ...$names Cache namespace names affected by the mutation.
	 * @return list<string> Unique non-empty invalidation names.
	 */
	public static function invalidationNames(string ...$names): array {
		if(self::$lastInvalidationNamesInput!==null && $names===self::$lastInvalidationNamesInput){
			return self::$lastInvalidationNamesResult;
		}
		if(self::$previousInvalidationNamesInput!==null && $names===self::$previousInvalidationNamesInput){
			return self::$previousInvalidationNamesResult;
		}
		$normalized=[];
		$seen=[];
		foreach($names as $name){
			$name=trim($name);
			if($name==='' || isset($seen[$name])){
				continue;
			}
			$seen[$name]=true;
			$normalized[]=$name;
		}
		self::$previousInvalidationNamesInput=self::$lastInvalidationNamesInput;
		self::$previousInvalidationNamesResult=self::$lastInvalidationNamesResult;
		self::$lastInvalidationNamesInput=$names;
		self::$lastInvalidationNamesResult=$normalized;
		return $normalized;
	}

	/**
	 * Appends cache namespace names to an existing kernel cache policy.
	 *
	 * `false` is preserved as an explicit opt-out. `null` starts from `defaultReadCaching()`,
	 * while strings and arrays are normalized into the list form expected by the SQL kernel.
	 *
	 * @param bool|array|string|null $caching Existing cache policy.
	 * @param string ...$names Additional cache namespaces.
	 * @return false|list<mixed> Normalized policy or explicit cache opt-out.
	 */
	public static function mergeCacheNames(bool|array|string|null $caching=null, string ...$names): bool|array|string|null {
		static::bootRuntimeBridges();
		if($caching===false){
			return false;
		}
		$merged=[];
		if(is_array($caching)){
			$merged=$caching;
		}elseif($caching!==null){
			$merged=[$caching];
		}else{
			$merged=static::defaultReadCaching();
		}
		foreach($names as $name){
			$name=trim($name);
			if($name===''){
				continue;
			}
			$merged[]=$name;
		}
		if($merged===[]){
			return static::defaultReadCaching();
		}
		return array_values(array_unique($merged, SORT_REGULAR));
	}

	/**
	 * Merges invalidation namespaces into an existing clear-cache policy.
	 *
	 * When no names remain, `true` is preserved as "clear by table policy" and all other empty
	 * states collapse to `false` so write calls do not accidentally clear everything.
	 *
	 * @param bool|array|null $clearCache Existing clear-cache policy.
	 * @param string ...$names Additional namespaces to invalidate.
	 * @return bool|list<string> Kernel-compatible clear-cache policy.
	 */
	public static function mergeInvalidationNames(bool|array|null $clearCache=null, string ...$names): bool|array|null {
		static::bootRuntimeBridges();
		$merged=[];
		if(is_array($clearCache)){
			$merged=$clearCache;
		}
		foreach($names as $name){
			$name=trim($name);
			if($name===''){
				continue;
			}
			$merged[]=$name;
		}
		$merged=static::invalidationNames(...$merged);
		if($merged===[]){
			return $clearCache===true ? true : false;
		}
		return $merged;
	}

	/**
	 * Creates a cluster-bound connection context.
	 *
	 * The returned object does not open a connection immediately; it validates and carries the
	 * cluster override so subsequent query, transaction, and queue calls target the same DBMS.
	 *
	 * @param ?string $cluster Cluster name, or `null` to use Dataphyre's default cluster.
	 * @return ConnectionContext Query context bound to the normalized cluster.
	 */
	public static function connection(?string $cluster=null): ConnectionContext {
		static::bootRuntimeBridges();
		return new ConnectionContext(static::normalizeCluster($cluster));
	}

	/**
	 * Starts a fluent table query against a registered table or explicit schema.
	 *
	 * String table names are resolved through runtime table definitions first, allowing the
	 * query object to inherit hydrators, money mappings, cache policy, and primary-key metadata.
	 *
	 * @param string|TableSchema $table Registered table location/name or already-built schema.
	 * @param ?string $primaryKey Primary key override when no schema metadata provides one.
	 * @return TableQuery Fluent query builder for reads and mutations.
	 */
	public static function table(string|TableSchema $table, ?string $primaryKey=null): TableQuery {
		static::bootRuntimeBridges();
		if(is_string($table)){
			$schema=\dataphyre\sql::table_schema($table);
			if($schema!==null){
				return new TableQuery($schema, $primaryKey);
			}
		}
		return new TableQuery($table, $primaryKey);
	}

	/**
	 * Returns the registered table definition for a Dataphyre table location.
	 *
	 * @param string $table Table location such as `module.table` or a kernel-recognized table name.
	 * @return ?TableDefinition Definition metadata, or `null` when the table is unknown.
	 */
	public static function definition(string $table): ?TableDefinition {
		static::bootRuntimeBridges();
		return \dataphyre\sql::table_definition($table);
	}

	/**
	 * Returns the normalized table schema used by Framework repositories and queries.
	 *
	 * @param string $table Table location such as `module.table` or a kernel-recognized table name.
	 * @return ?TableSchema Runtime schema, or `null` when no definition can be resolved.
	 */
	public static function schema(string $table): ?TableSchema {
		static::bootRuntimeBridges();
		return \dataphyre\sql::table_schema($table);
	}

	/**
	 * Alias for `connection()` when the call site reads better in cluster-first workflows.
	 *
	 * @param ?string $cluster Cluster name, or `null` to use the default cluster.
	 * @return ConnectionContext Query context bound to the normalized cluster.
	 */
	public static function cluster(?string $cluster=null): ConnectionContext {
		return static::connection($cluster);
	}

	/**
	 * Returns the configured default SQL cluster for the current Dataphyre datacenter.
	 *
	 * @return ?string Cluster name, or `null` when configuration leaves the default unspecified.
	 */
	public static function defaultCluster(): ?string {
		$config=static::sqlConfig();
		$cluster=trim((string)($config['default_cluster'] ?? ''));
		return $cluster!=='' ? $cluster : null;
	}

	/**
	 * Lists SQL clusters configured for the current Dataphyre datacenter.
	 *
	 * @return list<string> Cluster names available to query contexts and transactions.
	 */
	public static function clusters(): array {
		$config=static::sqlConfig();
		$datacenter=static::currentDatacenter();
		$clusters=$config['datacenters'][$datacenter]['dbms_clusters'] ?? [];
		if(!is_array($clusters)){
			return [];
		}
		return array_values(array_filter(array_map(
			static fn(mixed $name): string => trim((string)$name),
			array_keys($clusters)
		), static fn(string $name): bool => $name!==''));
	}

	/**
	 * Reports whether a cluster name is configured for the current datacenter.
	 *
	 * @param string $cluster Cluster name to validate.
	 * @return bool `true` when the normalized name is present in `clusters()`.
	 */
	public static function hasCluster(string $cluster): bool {
		$cluster=trim($cluster);
		if($cluster===''){
			return false;
		}
		return in_array($cluster, static::clusters(), true);
	}

	/**
	 * Returns the datacenter key currently used for SQL configuration lookup.
	 *
	 * @return ?string Datacenter name, or `null` when no non-empty value is configured.
	 */
	public static function datacenter(): ?string {
		$datacenter=static::currentDatacenter();
		return $datacenter!=='' ? $datacenter : null;
	}

	/**
	 * Resolves the DBMS driver for a cluster in the current datacenter.
	 *
	 * @param ?string $cluster Cluster name; `null` resolves through `defaultCluster()`.
	 * @return ?string DBMS key such as `mysql`, `postgresql`, or `sqlite`.
	 */
	public static function clusterDbms(?string $cluster=null): ?string {
		$config=static::sqlConfig();
		$datacenter=static::currentDatacenter();
		$cluster=static::normalizeCluster($cluster) ?? static::defaultCluster();
		if($cluster===null){
			return null;
		}
		$dbms=trim((string)($config['datacenters'][$datacenter]['dbms_clusters'][$cluster]['dbms'] ?? ''));
		return $dbms!=='' ? $dbms : null;
	}

	/**
	 * Begins a transaction on the selected SQL cluster.
	 *
	 * @param ?string $cluster Cluster name, or `null` for the default cluster.
	 * @return Transaction Active transaction wrapper.
	 */
	public static function begin(?string $cluster=null): Transaction {
		return (new Transaction(static::normalizeCluster($cluster)))->begin();
	}

	/**
	 * Runs a callback inside a transaction and returns the callback result.
	 *
	 * Exceptions bubble after rollback. The callback receives the transaction context from
	 * `Transaction::run()` and may return any value the caller wants to propagate.
	 *
	 * @param callable $callback Work to execute atomically.
	 * @param ?string $cluster Cluster name, or `null` for the default cluster.
	 * @return mixed caller-supplied value returned after the transaction commits.
	 */
	public static function transaction(callable $callback, ?string $cluster=null): mixed {
		return (new Transaction(static::normalizeCluster($cluster)))->run($callback);
	}

	/**
	 * Attempts a transaction and returns structured outcome metadata.
	 *
	 * @param callable $callback Work to execute atomically.
	 * @param ?string $cluster Cluster name, or `null` for the default cluster.
	 * @return TransactionResult Success/failure result without forcing callers into exception flow.
	 */
	public static function attemptTransaction(callable $callback, ?string $cluster=null): TransactionResult {
		return (new Transaction(static::normalizeCluster($cluster)))->attempt($callback);
	}

	/**
	 * Runs a transaction with retry handling for transient SQL failures.
	 *
	 * @param callable $callback Work to execute atomically.
	 * @param ?string $cluster Cluster name, or `null` for the default cluster.
	 * @param int $attempts Maximum attempts.
	 * @param ?callable $shouldRetry Predicate used to decide whether a failed attempt should retry.
	 * @param int $sleepMs Delay between attempts in milliseconds.
	 * @return mixed caller-supplied value from the attempt that commits successfully.
	 */
	public static function transactionWithRetries(
		callable $callback,
		?string $cluster=null,
		int $attempts=3,
		?callable $shouldRetry=null,
		int $sleepMs=0
	): mixed {
		return (new Transaction(static::normalizeCluster($cluster)))->runWithRetries(
			$callback,
			$attempts,
			$shouldRetry,
			$sleepMs
		);
	}

	/**
	 * Attempts a retried transaction and returns the final structured outcome.
	 *
	 * @param callable $callback Work to execute atomically.
	 * @param ?string $cluster Cluster name, or `null` for the default cluster.
	 * @param int $attempts Maximum attempts.
	 * @param ?callable $shouldRetry Predicate used to decide whether a failed attempt should retry.
	 * @param int $sleepMs Delay between attempts in milliseconds.
	 * @return TransactionResult Final transaction result.
	 */
	public static function attemptTransactionWithRetries(
		callable $callback,
		?string $cluster=null,
		int $attempts=3,
		?callable $shouldRetry=null,
		int $sleepMs=0
	): TransactionResult {
		return (new Transaction(static::normalizeCluster($cluster)))->attemptWithRetries(
			$callback,
			$attempts,
			$shouldRetry,
			$sleepMs
		);
	}

	/**
	 * Commits an active transaction wrapper.
	 *
	 * @param Transaction $transaction Transaction returned by `begin()`.
	 * @return Transaction The same transaction after commit handling.
	 */
	public static function commit(Transaction $transaction): Transaction {
		return $transaction->commit();
	}

	/**
	 * Rolls back an active transaction wrapper.
	 *
	 * @param Transaction $transaction Transaction returned by `begin()`.
	 * @return Transaction The same transaction after rollback handling.
	 */
	public static function rollback(Transaction $transaction): Transaction {
		return $transaction->rollback();
	}

	/**
	 * Executes a raw or DBMS-specific query through the default connection context.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param ?array $vars Bound parameter values.
	 * @param ?bool $associative Whether to return associative rows.
	 * @param ?bool $multipoint Whether multiple rows/result sets are expected.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return mixed Kernel query result, cached value, or `false` on query failure.
	 */
	public static function query(
		string|array $query,
		?array $vars=null,
		?bool $associative=false,
		?bool $multipoint=false,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): mixed {
		return static::connection()->query($query, $vars, $associative, $multipoint, $caching, $clearCache);
	}

	/**
	 * Executes a query expected to return one scalar or first-cell value.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param ?array $vars Bound parameter values.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return mixed First-cell value from the result set, cached scalar, `null`, or `false` on query failure.
	 */
	public static function value(
		string|array $query,
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): mixed {
		return static::connection()->value($query, $vars, $caching, $clearCache);
	}

	/**
	 * Executes a query expected to return one row.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param ?array $vars Bound parameter values.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return ?array First row, or `null` when the kernel result is not row-shaped.
	 */
	public static function row(
		string|array $query,
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): ?array {
		return static::connection()->row($query, $vars, $caching, $clearCache);
	}

	/**
	 * Executes a query expected to return a list of rows.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param ?array $vars Bound parameter values.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return array Query rows, or an empty array when the kernel result is not row-shaped.
	 */
	public static function rows(
		string|array $query,
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): array {
		return static::connection()->rows($query, $vars, $caching, $clearCache);
	}

	/**
	 * Queues a query for deferred SQL execution on the default connection context.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param callable $callback Result callback invoked after queue execution.
	 * @param string $queue Queue name; `end` matches Dataphyre's default deferred queue.
	 * @param ?array $vars Bound parameter values.
	 * @param ?bool $associative Whether to return associative rows.
	 * @param ?bool $multipoint Whether multiple rows/result sets are expected.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueQuery(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		?bool $associative=false,
		?bool $multipoint=false,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): null|bool {
		return static::connection()->queueQuery($query, $callback, $queue, $vars, $associative, $multipoint, $caching, $clearCache);
	}

	/**
	 * Queues a scalar-value query on the default connection context.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param callable $callback Result callback invoked after queue execution.
	 * @param string $queue Queue name.
	 * @param ?array $vars Bound parameter values.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueValue(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): null|bool {
		return static::connection()->queueValue($query, $callback, $queue, $vars, $caching, $clearCache);
	}

	/**
	 * Queues a single-row query on the default connection context.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param callable $callback Result callback invoked after queue execution.
	 * @param string $queue Queue name.
	 * @param ?array $vars Bound parameter values.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueRow(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): null|bool {
		return static::connection()->queueRow($query, $callback, $queue, $vars, $caching, $clearCache);
	}

	/**
	 * Queues a multi-row query on the default connection context.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param callable $callback Result callback invoked after queue execution.
	 * @param string $queue Queue name.
	 * @param ?array $vars Bound parameter values.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public static function queueRows(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): null|bool {
		return static::connection()->queueRows($query, $callback, $queue, $vars, $caching, $clearCache);
	}

	/**
	 * Executes a named SQL queue and dispatches queued callbacks.
	 *
	 * @param string $queue Queue name to drain.
	 * @return null|bool Kernel queue execution result.
	 */
	public static function executeQueue(string $queue='end'): null|bool {
		return \dataphyre\sql::execute_queue($queue);
	}

	/**
	 * Invalidates SQL cache entries using Dataphyre cache policy syntax.
	 *
	 * @param array|string $clearCacheFor Cache namespace, table policy, or kernel invalidation request.
	 * @return bool `true` when the kernel accepted the invalidation request.
	 */
	public static function invalidateCache(array|string $clearCacheFor): bool {
		static::bootRuntimeBridges();
		return \dataphyre\sql::invalidate_cache($clearCacheFor);
	}

	/**
	 * Registers a public trace observer for SQL execution events.
	 *
	 * Observers receive `ExecutionTrace` objects after kernel trace data is normalized. When
	 * tracing is disabled, registration is ignored so production builds can keep the call sites.
	 *
	 * @param callable(ExecutionTrace):void $observer Observer callback.
	 * @return void
	 */
	public static function observe(callable $observer): void {
		if(static::tracingEnabled()!==true){
			return;
		}
		static::bootObservability();
		static::bootTemplatingCacheBridge();
		static::bootApiCacheBridge();
		self::$traceObservers[]=$observer;
	}

	/**
	 * Clears public and internal trace observers and bridge registration flags.
	 *
	 * @return void
	 */
	public static function clearObservers(): void {
		self::$traceObservers=[];
		self::$internalTraceObservers=[];
		self::$templatingCacheBridgeRegistered=false;
		self::$apiCacheBridgeRegistered=false;
	}

	/**
	 * Runs a callback with additional trace context applied to all nested SQL events.
	 *
	 * Context frames merge from oldest to newest; inner keys override outer keys. When tracing is
	 * disabled or the normalized context is empty, the callback runs without stack mutation.
	 *
	 * @param array<string,mixed> $context Trace metadata to merge into nested SQL events.
	 * @param callable $callback Work to execute under the context frame.
	 * @return mixed caller-supplied value produced while the trace context frame is active.
	 */
	public static function withTraceContext(array $context, callable $callback): mixed {
		if(static::tracingEnabled()!==true){
			return $callback();
		}
		$context=static::normalizeTraceContext($context);
		if($context===[]){
			return $callback();
		}
		self::$traceContextStack[]=$context;
		try{
			return $callback();
		} finally {
			array_pop(self::$traceContextStack);
		}
	}

	/**
	 * Returns the merged trace context currently active on the stack.
	 *
	 * @return array<string,mixed> Context metadata attached to new SQL traces.
	 */
	public static function currentTraceContext(): array {
		if(static::tracingEnabled()!==true){
			return [];
		}
		$merged=[];
		foreach(self::$traceContextStack as $context){
			$merged=array_replace($merged, $context);
		}
		return $merged;
	}

	/**
	 * Returns the most recent SQL execution trace retained in memory.
	 *
	 * @return ?ExecutionTrace Last buffered trace, or `null` when tracing is disabled or empty.
	 */
	public static function lastTrace(): ?ExecutionTrace {
		if(static::tracingEnabled()!==true){
			return null;
		}
		static::bootObservability();
		if(self::$traceBuffer===[]){
			return null;
		}
		return self::$traceBuffer[array_key_last(self::$traceBuffer)] ?? null;
	}

	/**
	 * Returns the newest SQL execution traces retained in memory.
	 *
	 * @param int $limit Maximum number of traces to return.
	 * @return list<ExecutionTrace> Buffered traces in chronological order.
	 */
	public static function recentTraces(int $limit=50): array {
		if(static::tracingEnabled()!==true){
			return [];
		}
		static::bootObservability();
		$limit=max(1, $limit);
		return array_slice(self::$traceBuffer, -$limit);
	}

	/**
	 * Returns recent SQL traces whose context contains the supplied key/value pairs.
	 *
	 * @param array<string,mixed> $context Required trace context subset.
	 * @param int $limit Maximum number of traces to return.
	 * @return list<ExecutionTrace> Matching traces in chronological order.
	 */
	public static function recentTracesByContext(array $context, int $limit=50): array {
		if(static::tracingEnabled()!==true){
			return [];
		}
		static::bootObservability();
		$context=static::normalizeTraceContext($context);
		if($context===[]){
			return static::recentTraces($limit);
		}
		$limit=max(1, $limit);
		$matched=[];
		for($index=count(self::$traceBuffer)-1; $index>=0; $index--){
			$trace=self::$traceBuffer[$index] ?? null;
			if(!$trace instanceof ExecutionTrace){
				continue;
			}
			if(!static::traceMatchesContext($trace, $context)){
				continue;
			}
			$matched[]=$trace;
			if(count($matched)>=$limit){
				break;
			}
		}
		return array_reverse($matched);
	}

	/**
	 * Clears the in-memory SQL trace buffer.
	 *
	 * @return void
	 */
	public static function clearTraceBuffer(): void {
		self::$traceBuffer=[];
	}

	/**
	 * Sets the maximum number of SQL traces retained in memory.
	 *
	 * Existing traces are trimmed immediately when the new limit is lower than the buffer size.
	 *
	 * @param int $limit Retention limit; normalized to at least one.
	 * @return void
	 */
	public static function setTraceBufferLimit(int $limit=256): void {
		self::$traceBufferLimit=max(1, $limit);
		if(count(self::$traceBuffer)>self::$traceBufferLimit){
			self::$traceBuffer=array_slice(self::$traceBuffer, -self::$traceBufferLimit);
		}
	}

	/**
	 * Enables or disables SQL guardrail warnings at runtime.
	 *
	 * Guardrails emit traces/warnings for suspicious SQL behavior without changing query results.
	 *
	 * @param bool $enabled Whether guardrails should be active.
	 * @return void
	 */
	public static function enableGuardrails(bool $enabled=true): void {
		self::$guardrailsEnabled=$enabled;
		if($enabled){
			static::bootObservability();
		}
	}

	/**
	 * Disables SQL guardrail warnings for the current process.
	 *
	 * @return void
	 */
	public static function disableGuardrails(): void {
		self::$guardrailsEnabled=false;
	}

	/**
	 * Reports whether SQL guardrail warnings are currently active.
	 *
	 * Explicit runtime configuration wins; otherwise diagnostic run mode enables guardrails.
	 *
	 * @return bool `true` when guardrail warnings should be emitted.
	 */
	public static function guardrailsEnabled(): bool {
		if(self::$guardrailsEnabled!==null){
			return self::$guardrailsEnabled;
		}
		return defined('RUN_MODE') && RUN_MODE==='diagnostic';
	}

	/**
	 * Emits a guardrail warning trace with optional SQL context metadata.
	 *
	 * @param string $message Human-readable warning.
	 * @param array<string,mixed> $context Additional trace context such as operation, table, or caller metadata.
	 * @return void
	 */
	public static function reportGuardrailWarning(string $message, array $context=[]): void {
		if(static::guardrailsEnabled()===false){
			return;
		}
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Dataphyre SQL guardrail: '.$message, $S='warning');
		static::recordTrace([
			'source'=>'framework',
			'event'=>'guardrail_warning',
			'operation'=>$context['operation'] ?? null,
			'message'=>$message,
			'context'=>$context,
		]);
	}

	/**
	 * Receives a raw SQL kernel trace event and records it through the Framework trace pipeline.
	 *
	 * @param array<string,mixed> $event Kernel trace event data.
	 * @return void
	 */
	public static function recordKernelTrace(array $event): void {
		static::recordTrace($event+['source'=>'kernel']);
	}

	/**
	 * Reads the active SQL module configuration.
	 *
	 * this is the SQL Framework's single access point for DP_SQL_CFG so cluster,
	 * datacenter, cache, and transaction helpers all share the same defensive
	 * fallback when configuration is absent or malformed during partial bootstrap.
	 */
	private static function sqlConfig(): array {
		return is_array(DP_SQL_CFG ?? null)
			? DP_SQL_CFG
			: [];
	}

	/**
	 * Resolves the current Dataphyre datacenter key for SQL lookups.
	 *
	 * SQL cluster discovery is scoped by the core datacenter setting. The
	 * value is trimmed and may be empty, allowing callers to distinguish an unset
	 * datacenter from a configured datacenter without inventing a default here.
	 */
	private static function currentDatacenter(): string {
		return trim((string)(DP_CORE_CFG['datacenter'] ?? ''));
	}

	/**
	 * Normalizes optional cluster names before creating SQL contexts.
	 *
	 * blank strings collapse to null so transaction and connection helpers
	 * can consistently fall back to default-cluster behavior instead of carrying an
	 * invalid empty cluster identifier into the SQL kernel.
	 */
	private static function normalizeCluster(?string $cluster): ?string {
		if($cluster===null){
			return null;
		}
		$cluster=trim($cluster);
		return $cluster!=='' ? $cluster : null;
	}

	/**
	 * Registers the SQL kernel trace observer once per process.
	 *
	 * observability bridges Framework tracing to the snake_case SQL kernel
	 * by subscribing recordKernelTrace as the kernel observer. The guard flag makes
	 * repeated public calls idempotent and prevents duplicate trace emission.
	 */
	private static function bootObservability(): void {
		if(self::$kernelObserverRegistered){
			return;
		}
		\dataphyre\sql::add_observer([static::class, 'recordKernelTrace']);
		self::$kernelObserverRegistered=true;
	}

	/**
	 * Registers optional SQL bridges for modules that are already loaded in this process.
	 *
	 * The method avoids autoloading optional modules; it only connects trace/cache observers for
	 * Templating and API when their classes are present.
	 *
	 * @return void
	 */
	public static function bootRuntimeBridges(): void {
		if(
			!class_exists('Dataphyre\\Templating\\Templating', false)
			&& !class_exists('Dataphyre\\Api\\Api', false)
		){
			return;
		}
		static::bootObservability();
		static::bootTemplatingCacheBridge();
		static::bootApiCacheBridge();
	}

	/**
	 * Normalizes and dispatches SQL execution trace data.
	 *
	 * raw framework or kernel trace data is merged with the current trace
	 * context, converted to ExecutionTrace objects, delivered to internal cache
	 * bridges regardless of public tracing state, buffered when tracing is enabled,
	 * and finally sent to public observers with observer failures contained.
	 */
	private static function recordTrace(array $payload): void {
		$tracingEnabled=static::tracingEnabled()===true;
		if($tracingEnabled!==true && self::$internalTraceObservers===[]){
			return;
		}
		$payload['context']=array_replace(
			static::currentTraceContext(),
			is_array($payload['context'] ?? null) ? $payload['context'] : []
		);
		$trace=ExecutionTrace::fromArray($payload);
		foreach(self::$internalTraceObservers as $observer){
			try{
				$observer($trace);
			}catch(\Throwable $exception){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Dataphyre SQL trace observer failed: '.$exception->getMessage(), $S='warning');
			}
		}
		if($tracingEnabled!==true){
			return;
		}
		self::$traceBuffer[]=$trace;
		if(count(self::$traceBuffer)>self::$traceBufferLimit){
			array_shift(self::$traceBuffer);
		}
		foreach(self::$traceObservers as $observer){
			try{
				$observer($trace);
			}catch(\Throwable $exception){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Dataphyre SQL trace observer failed: '.$exception->getMessage(), $S='warning');
			}
		}
	}

	/**
	 * Connects SQL cache invalidation traces to templating binding-cache cleanup.
	 *
	 * the bridge is registered only when the Templating class is already
	 * loaded, preserving optional-module boundaries while still letting SQL writes
	 * invalidate renderer binding caches in processes that use both modules.
	 */
	private static function bootTemplatingCacheBridge(): void {
		if(self::$templatingCacheBridgeRegistered){
			return;
		}
		if(!class_exists('Dataphyre\\Templating\\Templating', false)){
			return;
		}
		self::$internalTraceObservers[]=[static::class, 'syncTemplatingBindingCaches'];
		self::$templatingCacheBridgeRegistered=true;
	}

	/**
	 * Clears templating binding caches named by a SQL invalidation trace.
	 *
	 * only cache_invalidate events participate in the bridge. Named SQL
	 * invalidations are forwarded to Templating::clearBindingCache, and bridge
	 * exceptions are logged as warnings so SQL writes are not rolled back by cache
	 * cleanup failures.
	 */
	private static function syncTemplatingBindingCaches(ExecutionTrace $trace): void {
		if($trace->event()!=='cache_invalidate'){
			return;
		}
		$names=$trace->invalidationNames();
		if($names===[]){
			return;
		}
		try{
			\Dataphyre\Templating\Templating::clearBindingCache(...$names);
		}catch(\Throwable $exception){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Dataphyre templating cache bridge failed: '.$exception->getMessage(), $S='warning');
		}
	}

	/**
	 * Connects SQL cache invalidation traces to API endpoint-cache cleanup.
	 *
	 * the bridge is opt-in by class presence and is registered only once,
	 * keeping API cache synchronization available in combined runtimes without
	 * forcing API module autoloading in database-only processes.
	 */
	private static function bootApiCacheBridge(): void {
		if(self::$apiCacheBridgeRegistered){
			return;
		}
		if(!class_exists('Dataphyre\\Api\\Api', false)){
			return;
		}
		self::$internalTraceObservers[]=[static::class, 'syncApiEndpointCaches'];
		self::$apiCacheBridgeRegistered=true;
	}

	/**
	 * Reports whether SQL trace buffering and public observers should run.
	 *
	 * Runtime::tracingEnabled owns the process-wide tracing policy when
	 * the Runtime class is loaded. During early bootstrap, the SQL Framework falls
	 * back to non-production tracing so diagnostics remain available before the
	 * full runtime surface exists.
	 */
	private static function tracingEnabled(): bool {
		if(class_exists('Dataphyre\\Runtime', false) && method_exists('Dataphyre\\Runtime', 'tracingEnabled')){
			return \Dataphyre\Runtime::tracingEnabled();
		}
		return !(defined('IS_PRODUCTION') && IS_PRODUCTION===true);
	}

	/**
	 * Clears API endpoint caches named by a SQL invalidation trace.
	 *
	 * cache invalidation names emitted by SQL writes are propagated to
	 * Api::clearEndpointCache when the API module is active. Failures are isolated
	 * to warning traces so persistence mutations do not depend on cache cleanup
	 * success.
	 */
	private static function syncApiEndpointCaches(ExecutionTrace $trace): void {
		if($trace->event()!=='cache_invalidate'){
			return;
		}
		$names=$trace->invalidationNames();
		if($names===[]){
			return;
		}
		try{
			\Dataphyre\Api\Api::clearEndpointCache(...$names);
		}catch(\Throwable $exception){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Dataphyre API cache bridge failed: '.$exception->getMessage(), $S='warning');
		}
	}

	/**
	 * Filters trace context metadata into scalar, comparable values.
	 *
	 * trace contexts are merged into every nested SQL event and later used
	 * for exact matching. Empty keys are dropped, scalar/null values are preserved,
	 * and stringable objects are flattened while arrays and opaque objects are
	 * intentionally excluded from trace metadata.
	 */
	private static function normalizeTraceContext(array $context): array {
		$normalized=[];
		foreach($context as $key=>$value){
			$key=trim((string)$key);
			if($key===''){
				continue;
			}
			if(is_scalar($value) || $value===null){
				$normalized[$key]=$value;
				continue;
			}
			if(is_object($value) && method_exists($value, '__toString')){
				$normalized[$key]=(string)$value;
			}
		}
		return $normalized;
	}

	/**
	 * Tests whether a trace contains all requested context key/value pairs.
	 *
	 * recentTracesByContext uses strict subset matching so diagnostics can
	 * retrieve traces for a specific request, job, resource, or module without
	 * accidentally matching stringified or loosely equivalent values.
	 */
	private static function traceMatchesContext(ExecutionTrace $trace, array $context): bool {
		$traceContext=$trace->context();
		foreach($context as $key=>$value){
			if(!array_key_exists($key, $traceContext) || $traceContext[$key]!==$value){
				return false;
			}
		}
		return true;
	}
}
