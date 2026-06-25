<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Cluster-bound SQL execution context.
 *
 * A connection context carries a normalized cluster override into every query, queue, and
 * transaction call without changing global SQL state. It is the preferred Framework object
 * when a workflow must consistently target a non-default cluster.
 */
final class ConnectionContext {

	private ?string $cluster;

	/**
	 * Creates a SQL context for one configured cluster.
	 *
	 * @param ?string $cluster Cluster name, or `null` for the default SQL cluster.
	 * @throws SqlError When a non-empty cluster is not configured for the current datacenter.
	 */
	public function __construct(?string $cluster=null){
		$this->cluster=$cluster!==null ? trim($cluster) : null;
		if($this->cluster===''){
			$this->cluster=null;
		}
		if($this->cluster!==null && trim($this->cluster)!==''){
			if(!DB::hasCluster($this->cluster)){
				throw SqlError::unknownCluster($this->cluster, DB::clusters(), DB::datacenter());
			}
		}
	}

	/**
	 * Returns the cluster override carried by this context.
	 *
	 * @return ?string Cluster name, or `null` when the context uses Dataphyre's default cluster.
	 */
	public function cluster(): ?string {
		return $this->cluster;
	}

	/**
	 * Returns the DBMS driver selected by this context's cluster.
	 *
	 * @return ?string Driver key such as `mysql`, `postgresql`, or `sqlite`.
	 */
	public function dbms(): ?string {
		return DB::clusterDbms($this->cluster);
	}

	/**
	 * Begins a transaction on this context's cluster.
	 *
	 * @return Transaction Active transaction wrapper.
	 */
	public function begin(): Transaction {
		return (new Transaction($this->cluster))->begin();
	}

	/**
	 * Runs a callback inside a transaction bound to this context.
	 *
	 * The callback is executed through `Transaction::run()` with this context supplied, allowing
	 * nested query helpers to stay on the same cluster.
	 *
	 * @param callable $callback Atomic unit of work.
	 * @return mixed caller-supplied value returned after the cluster-bound transaction commits.
	 */
	public function transaction(callable $callback): mixed {
		$transaction=new Transaction($this->cluster);
		return $transaction->run($callback, $this, true);
	}

	/**
	 * Attempts a transaction and returns a result object instead of throwing control-flow details.
	 *
	 * @param callable $callback Atomic unit of work.
	 * @return TransactionResult Success/failure metadata plus callback value when available.
	 */
	public function attemptTransaction(callable $callback): TransactionResult {
		$transaction=new Transaction($this->cluster);
		return $transaction->attempt($callback, $this, true);
	}

	/**
	 * Runs a transaction with retry handling for transient failures.
	 *
	 * @param callable $callback Atomic unit of work.
	 * @param int $attempts Maximum attempt count; values below one are normalized by `Transaction`.
	 * @param ?callable $shouldRetry Predicate receiving the thrown error/result details.
	 * @param int $sleepMs Delay between attempts in milliseconds.
	 * @return mixed caller-supplied value from the retry attempt that commits successfully.
	 */
	public function transactionWithRetries(
		callable $callback,
		int $attempts=3,
		?callable $shouldRetry=null,
		int $sleepMs=0
	): mixed {
		$transaction=new Transaction($this->cluster);
		return $transaction->runWithRetries($callback, $attempts, $shouldRetry, $sleepMs, $this, true);
	}

	/**
	 * Attempts a retried transaction and returns structured outcome metadata.
	 *
	 * @param callable $callback Atomic unit of work.
	 * @param int $attempts Maximum attempt count.
	 * @param ?callable $shouldRetry Predicate used to decide whether an error is retryable.
	 * @param int $sleepMs Delay between attempts in milliseconds.
	 * @return TransactionResult Final transaction outcome.
	 */
	public function attemptTransactionWithRetries(
		callable $callback,
		int $attempts=3,
		?callable $shouldRetry=null,
		int $sleepMs=0
	): TransactionResult {
		$transaction=new Transaction($this->cluster);
		return $transaction->attemptWithRetries($callback, $attempts, $shouldRetry, $sleepMs, $this, true);
	}

	/**
	 * Executes a raw or DBMS-specific query through this context's cluster.
	 *
	 * Array queries may already contain per-DBMS SQL strings; the context injects
	 * `dbms_cluster_override` so the snake_case kernel targets this cluster.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param ?array $vars Bound parameter values.
	 * @param ?bool $associative Whether to return associative rows.
	 * @param ?bool $multipoint Whether multiple rows/result sets are expected.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return mixed Kernel query result, cached value, or `false` on query failure.
	 */
	public function query(
		string|array $query,
		?array $vars=null,
		?bool $associative=false,
		?bool $multipoint=false,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): mixed {
		return sql_query(
			$this->clusterAwareQuery($query),
			$vars,
			$associative,
			$multipoint,
			$caching,
			$clearCache
		);
	}

	/**
	 * Executes a query expected to return one scalar or first-cell value.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param ?array $vars Bound parameter values.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return mixed first-cell query value, cached scalar, `null`, or `false` on kernel query failure.
	 */
	public function value(
		string|array $query,
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): mixed {
		return $this->query($query, $vars, false, false, $caching, $clearCache);
	}

	/**
	 * Executes a query expected to return one row.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param ?array $vars Bound parameter values.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return ?array First row, or `null` when the kernel result is not an array.
	 */
	public function row(
		string|array $query,
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): ?array {
		$result=$this->query($query, $vars, false, false, $caching, $clearCache);
		return is_array($result) ? $result : null;
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
	public function rows(
		string|array $query,
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): array {
		$result=$this->query($query, $vars, true, true, $caching, $clearCache);
		return is_array($result) ? $result : [];
	}

	/**
	 * Queues a query for deferred SQL execution on this context's cluster.
	 *
	 * The callback receives the normalized kernel result when `execute_queue()` drains the queue.
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
	public function queueQuery(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		?bool $associative=false,
		?bool $multipoint=false,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): null|bool {
		return sql_query(
			$this->clusterAwareQuery($query),
			$vars,
			$associative,
			$multipoint,
			$caching,
			$clearCache,
			$queue,
			$callback
		);
	}

	/**
	 * Queues a scalar-value query.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param callable $callback Result callback invoked after queue execution.
	 * @param string $queue Queue name.
	 * @param ?array $vars Bound parameter values.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public function queueValue(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): null|bool {
		return $this->queueQuery($query, $callback, $queue, $vars, false, false, $caching, $clearCache);
	}

	/**
	 * Queues a single-row query.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param callable $callback Result callback invoked after queue execution.
	 * @param string $queue Queue name.
	 * @param ?array $vars Bound parameter values.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public function queueRow(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): null|bool {
		return $this->queueQuery($query, $callback, $queue, $vars, true, false, $caching, $clearCache);
	}

	/**
	 * Queues a multi-row query.
	 *
	 * @param string|array $query SQL string or DBMS-keyed query array.
	 * @param callable $callback Result callback invoked after queue execution.
	 * @param string $queue Queue name.
	 * @param ?array $vars Bound parameter values.
	 * @param null|bool|array|string $caching Read-cache policy.
	 * @param bool|null|array $clearCache Cache invalidation policy.
	 * @return null|bool Queue registration result from the SQL kernel.
	 */
	public function queueRows(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clearCache=false
	): null|bool {
		return $this->queueQuery($query, $callback, $queue, $vars, true, true, $caching, $clearCache);
	}

	/**
	 * Applies the context cluster override to SQL submitted to the kernel.
	 *
	 * String queries are expanded into DBMS-keyed arrays when a cluster is scoped on
	 * this context, preserving the original SQL for each supported engine while
	 * adding dbms_cluster_override. Existing query arrays are mutated only by
	 * adding the override key. Contexts without a cluster return the query unchanged.
	 *
	 * @param string|array $query SQL string or DBMS-keyed SQL array.
	 * @return string|array Query string or array with this context's cluster override applied.
	 */
	private function clusterAwareQuery(string|array $query): string|array {
		if($this->cluster===null || $this->cluster===''){
			return $query;
		}
		if(is_array($query)){
			$query['dbms_cluster_override']=$this->cluster;
			return $query;
		}
		return [
			'mysql'=>$query,
			'postgresql'=>$query,
			'sqlite'=>$query,
			'dbms_cluster_override'=>$this->cluster,
		];
	}
}
