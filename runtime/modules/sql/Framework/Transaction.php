<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Stateful SQL transaction coordinator for one Dataphyre database cluster.
 *
 * Transaction wraps the kernel SQL transaction functions with an explicit
 * lifecycle object. It tracks begin/commit/rollback state, maintains per-cluster
 * nesting depth, maps nested transactions to savepoints, injects Transaction and
 * ConnectionContext arguments into callbacks, and provides retry helpers for
 * transient transaction failures. Each instance represents one transaction attempt.
 */
final class Transaction {

	private static array $activeDepthByCluster=[];

	private bool $active=false;
	private bool $begun=false;
	private bool $committed=false;
	private bool $rolledBack=false;
	private bool $nested=false;
	private ?string $savepointName=null;
	private ?string $cluster;

	/**
	 * Creates a transaction object scoped to an optional SQL cluster.
	 *
	 * Construction does not open a database transaction. begin(), run(), or attempt()
	 * must be called before any SQL transaction control statements are executed.
	 *
	 * @param string|null $cluster Cluster override passed to kernel SQL helpers, or null for default.
	 */
	public function __construct(?string $cluster=null){
		$this->cluster=$cluster!==null ? trim($cluster) : null;
		if($this->cluster===''){
			$this->cluster=null;
		}
	}

	/**
	 * Returns the cluster this transaction will operate against.
	 *
	 * @return string|null Cluster override, or null for the default SQL cluster.
	 */
	public function cluster(): ?string {
		return $this->cluster;
	}

	/**
	/**
	 * Creates a connection context bound to the transaction cluster.
	 *
	 * @return ConnectionContext Context object for SQL calls inside callbacks.
	 */
	public function connection(): ConnectionContext {
		return new ConnectionContext($this->cluster);
	}

	/**
	 * Reports whether this transaction is backed by a SQL savepoint.
	 *
	 * @return bool True after begin() detects an existing active transaction on the cluster.
	 */
	public function isNested(): bool {
		return $this->nested;
	}

	/**
	 * Returns the generated savepoint name for a nested transaction.
	 *
	 * Savepoint names are generated only for nested transactions and are released
	 * during commit() or rollback().
	 *
	 * @return string|null Savepoint identifier, or null for top-level transactions.
	 */
	public function savepointName(): ?string {
		return $this->savepointName;
	}

	/**
	 * Returns the currently active transaction depth for a cluster.
	 *
	 * Depth is tracked in-process so nested Transaction instances can choose
	 * savepoints instead of issuing another top-level BEGIN.
	 *
	 * @param string|null $cluster Cluster to inspect, or null for the default cluster.
	 * @return int Number of active Transaction instances for that cluster.
	 */
	public static function activeDepth(?string $cluster=null): int {
		$key=self::clusterKeyFor($cluster);
		return self::$activeDepthByCluster[$key] ?? 0;
	}

	/**
	 * Reports whether this transaction attempt is currently open.
	 *
	 * @return bool True after begin() and before commit() or rollback().
	 */
	public function isActive(): bool {
		return $this->active;
	}

	/**
	/**
	 * Reports whether begin() succeeded for this attempt.
	 *
	 * @return bool True once a BEGIN or SAVEPOINT has been created.
	 */
	public function begun(): bool {
		return $this->begun;
	}

	/**
	/**
	 * Reports whether this attempt was committed.
	 *
	 * @return bool True after commit() successfully commits or releases a savepoint.
	 */
	public function committed(): bool {
		return $this->committed;
	}

	/**
	/**
	 * Reports whether this attempt was rolled back.
	 *
	 * @return bool True after rollback() successfully rolls back or rewinds a savepoint.
	 */
	public function rolledBack(): bool {
		return $this->rolledBack;
	}

	/**
	 * Opens the SQL transaction or nested savepoint.
	 *
	 * Top-level transactions call sql_begin(). Nested transactions create a
	 * savepoint and increment the same per-cluster active depth. Calling begin()
	 * twice on the same instance is rejected to keep lifecycle state unambiguous.
	 *
	 * @return self Current transaction instance.
	 *
	 * @throws SqlError When the transaction is already active or SQL control fails.
	 */
	public function begin(): self {
		if($this->active){
			throw SqlError::transactionException(
				'Cannot begin a transaction that is already active.',
				$this->cluster,
				'Create a new transaction instance, or commit or roll back the current one before beginning again.'
			);
		}
		$key=$this->clusterKey();
		$depth=self::$activeDepthByCluster[$key] ?? 0;
		if($depth>0){
			$this->nested=true;
			$this->savepointName=$this->createSavepointName($depth+1);
			if($this->executeTransactionControl('SAVEPOINT '.$this->savepointName)===false){
				$this->nested=false;
				$this->savepointName=null;
				throw SqlError::transactionException(
					'Failed to create a SQL transaction savepoint.',
					$this->cluster,
					'Check the cluster name, connection health, and SQL logs for the engine-level failure.'
				);
			}
		}
		else
		{
			if(sql_begin($this->cluster)===false){
				throw SqlError::transactionException(
					'Failed to begin the SQL transaction.',
					$this->cluster,
					'Check the cluster name, connection health, and SQL logs for the engine-level failure.'
				);
			}
		}
		$this->active=true;
		$this->begun=true;
		$this->committed=false;
		$this->rolledBack=false;
		self::$activeDepthByCluster[$key]=$depth+1;
		return $this;
	}

	/**
	 * Commits the SQL transaction or releases its savepoint.
	 *
	 * Commit marks the instance inactive, records a committed state, and decrements
	 * the cluster depth only after the underlying SQL operation succeeds.
	 *
	 * @return self Current transaction instance.
	 *
	 * @throws SqlError When the transaction is inactive or SQL commit/release fails.
	 */
	public function commit(): self {
		if(!$this->active){
			throw SqlError::transactionException(
				'Cannot commit an inactive transaction.',
				$this->cluster,
				'Only call commit() after begin(), and only once per transaction.'
			);
		}
		if($this->nested){
			if($this->savepointName===null || $this->executeTransactionControl('RELEASE SAVEPOINT '.$this->savepointName)===false){
				throw SqlError::transactionException(
					'Failed to release the SQL transaction savepoint.',
					$this->cluster,
					'Check the SQL logs for savepoint release failures.'
				);
			}
		}
		else
		{
			if(sql_commit($this->cluster)===false){
				throw SqlError::transactionException(
					'Failed to commit the SQL transaction.',
					$this->cluster,
					'Check the SQL logs for commit-time failures such as connection drops or engine-level transaction errors.'
				);
			}
		}
		$this->active=false;
		$this->committed=true;
		$this->decrementActiveDepth();
		return $this;
	}

	/**
	 * Rolls back the SQL transaction or rewinds its savepoint.
	 *
	 * Nested rollback first rolls back to the savepoint, then releases it. Top-level
	 * rollback delegates to sql_rollback(). Depth is decremented only after SQL
	 * control succeeds, preserving diagnostics for failed rollback attempts.
	 *
	 * @return self Current transaction instance.
	 *
	 * @throws SqlError When the transaction is inactive or SQL rollback/release fails.
	 */
	public function rollback(): self {
		if(!$this->active){
			throw SqlError::transactionException(
				'Cannot roll back an inactive transaction.',
				$this->cluster,
				'Only call rollback() after begin(), and only while the transaction is still active.'
			);
		}
		if($this->nested){
			if($this->savepointName===null || $this->executeTransactionControl('ROLLBACK TO SAVEPOINT '.$this->savepointName)===false){
				throw SqlError::transactionException(
					'Failed to roll back to the SQL transaction savepoint.',
					$this->cluster,
					'Check the SQL logs for savepoint rollback failures.'
				);
			}
			if($this->executeTransactionControl('RELEASE SAVEPOINT '.$this->savepointName)===false){
				throw SqlError::transactionException(
					'Failed to release the SQL transaction savepoint after rollback.',
					$this->cluster,
					'The savepoint rollback succeeded, but the release failed. Check the SQL logs before continuing the outer transaction.'
				);
			}
		}
		else
		{
			if(sql_rollback($this->cluster)===false){
				throw SqlError::transactionException(
					'Failed to roll back the SQL transaction.',
					$this->cluster,
					'Check the SQL logs for rollback-time failures such as lost connections or engine-level transaction errors.'
				);
			}
		}
		$this->active=false;
		$this->rolledBack=true;
		$this->decrementActiveDepth();
		return $this;
	}

	/**
	 * Runs a callback inside this transaction and returns its value.
	 *
	 * The transaction commits after the callback returns while still active. If the
	 * callback throws, run() attempts rollback and rethrows the original exception;
	 * rollback failure is wrapped with the original exception as context.
	 *
	 * @param callable $callback Work to execute inside the transaction.
	 * @param ConnectionContext|null $connection Optional connection context injected into typed callbacks.
	 * @param bool $preferConnection Whether untyped callback arguments receive connection before transaction.
	 * @return mixed caller-supplied value returned after the active transaction commits.
	 */
	public function run(callable $callback, ?ConnectionContext $connection=null, bool $preferConnection=false): mixed {
		$this->begin();
		try{
			$value=$this->invokeCallback($callback, $connection, $preferConnection);
			if($this->active){
				$this->commit();
			}
			return $value;
		}catch(\Throwable $exception){
			if($this->active){
				try{
					$this->rollback();
				}catch(\Throwable $rollbackException){
					throw SqlError::transactionException(
						'Rollback failed after the transaction callback threw an exception: '.$rollbackException->getMessage(),
						$this->cluster,
						'Inspect both the original callback exception and the rollback failure. The SQL logs should contain the engine-level details.',
						$exception
					);
				}
			}
			throw $exception;
		}
	}

	/**
	 * Runs a transactional callback with retry support for transient failures.
	 *
	 * Each retry after the first uses a fresh Transaction instance so lifecycle
	 * flags and active depth remain isolated per attempt.
	 *
	 * @param callable $callback Work to execute inside each transaction attempt.
	 * @param int $attempts Maximum attempts, clamped to at least one.
	 * @param callable|null $shouldRetry Optional predicate receiving exception, attempt, and attempts.
	 * @param int $sleepMs Base delay in milliseconds multiplied by the attempt number.
	 * @param ConnectionContext|null $connection Optional connection context for callback injection.
	 * @param bool $preferConnection Whether untyped callback arguments receive connection first.
	 * @return mixed caller-supplied value from the first transaction attempt that commits successfully.
	 */
	public function runWithRetries(
		callable $callback,
		int $attempts=3,
		?callable $shouldRetry=null,
		int $sleepMs=0,
		?ConnectionContext $connection=null,
		bool $preferConnection=false
	): mixed {
		$attempts=max(1, $attempts);
		for($attempt=1; $attempt<=$attempts; $attempt++){
			$transaction=$this->transactionForAttempt($attempt);
			try{
				return $transaction->run($callback, $connection, $preferConnection);
			}catch(\Throwable $exception){
				if($attempt>=$attempts || !$this->shouldRetry($exception, $attempt, $attempts, $shouldRetry)){
					throw $exception;
				}
				$this->sleepBeforeRetry($sleepMs, $attempt);
			}
		}
		throw SqlError::transactionException(
			'Transaction retry loop exited without returning a value.',
			$this->cluster,
			'This should not happen. Check the transaction retry configuration and callback behavior.'
		);
	}

	/**
	 * Runs a transaction and captures success or failure in a result object.
	 *
	 * Unlike run(), exceptions are not thrown to the caller. The returned result
	 * includes lifecycle flags, the callback value on success, or the exception on
	 * failure.
	 *
	 * @param callable $callback Work to execute inside the transaction.
	 * @param ConnectionContext|null $connection Optional connection context for callback injection.
	 * @param bool $preferConnection Whether untyped callback arguments receive connection first.
	 * @return TransactionResult Captured transaction outcome for one attempt.
	 */
	public function attempt(callable $callback, ?ConnectionContext $connection=null, bool $preferConnection=false): TransactionResult {
		try{
			$value=$this->run($callback, $connection, $preferConnection);
			return TransactionResult::success(
				$this->cluster,
				$this->begun,
				$this->committed,
				$value,
				1
			);
		}catch(\Throwable $exception){
			return TransactionResult::failure(
				$this->cluster,
				$this->begun,
				$this->committed,
				$this->rolledBack,
				$exception,
				1
			);
		}
	}

	/**
	 * Runs retryable transaction attempts and captures the final outcome.
	 *
	 * Successful attempts return immediately. Failed attempts continue only when
	 * the retry predicate accepts the exception and the maximum attempt count has
	 * not been reached.
	 *
	 * @param callable $callback Work to execute inside each transaction attempt.
	 * @param int $attempts Maximum attempts, clamped to at least one.
	 * @param callable|null $shouldRetry Optional predicate receiving exception, attempt, and attempts.
	 * @param int $sleepMs Base delay in milliseconds multiplied by the attempt number.
	 * @param ConnectionContext|null $connection Optional connection context for callback injection.
	 * @param bool $preferConnection Whether untyped callback arguments receive connection first.
	 * @return TransactionResult Captured outcome from the first success or final failure.
	 */
	public function attemptWithRetries(
		callable $callback,
		int $attempts=3,
		?callable $shouldRetry=null,
		int $sleepMs=0,
		?ConnectionContext $connection=null,
		bool $preferConnection=false
	): TransactionResult {
		$attempts=max(1, $attempts);
		for($attempt=1; $attempt<=$attempts; $attempt++){
			$transaction=$this->transactionForAttempt($attempt);
			try{
				$value=$transaction->run($callback, $connection, $preferConnection);
				return TransactionResult::success(
					$this->cluster,
					$transaction->begun(),
					$transaction->committed(),
					$value,
					$attempt
				);
			}catch(\Throwable $exception){
				if($attempt>=$attempts || !$this->shouldRetry($exception, $attempt, $attempts, $shouldRetry)){
					return TransactionResult::failure(
						$this->cluster,
						$transaction->begun(),
						$transaction->committed(),
						$transaction->rolledBack(),
						$exception,
						$attempt
					);
				}
				$this->sleepBeforeRetry($sleepMs, $attempt);
			}
		}
		return TransactionResult::failure(
			$this->cluster,
			$this->begun,
			$this->committed,
			$this->rolledBack,
			SqlError::transactionException('Transaction retry loop exited without returning a result.', $this->cluster),
			$attempts
		);
	}

	/**
	 * Invokes a transaction callback with typed or positional helper arguments.
	 *
	 * Typed Transaction and ConnectionContext parameters are matched first. Untyped
	 * parameters receive transaction/connection fallbacks, with the order controlled
	 * by preferConnection.
	 *
	 * @param callable $callback Callback supplied to run() or attempt().
	 * @param ConnectionContext|null $connection Connection context to inject, or null to create one.
	 * @param bool $preferConnection Whether untyped arguments receive connection before transaction.
	 * @return mixed caller-supplied value produced after typed Transaction/ConnectionContext arguments are injected.
	 */
	private function invokeCallback(callable $callback, ?ConnectionContext $connection=null, bool $preferConnection=false): mixed {
		$reflection=$this->callbackReflection($callback);
		$parameters=$reflection->getParameters();
		if($parameters===[]){
			return $callback();
		}
		$connection ??= $this->connection();
		$fallback=$preferConnection ? [$connection, $this] : [$this, $connection];
		$fallbackIndex=0;
		$args=[];
		foreach($parameters as $parameter){
			$typedArgument=$this->typedCallbackArgument($parameter, $connection);
			if($typedArgument['matched']){
				$args[]=$typedArgument['value'];
				continue;
			}
			if($parameter->isVariadic()){
				while($fallbackIndex<count($fallback)){
					$args[]=$fallback[$fallbackIndex++];
				}
				break;
			}
			if($fallbackIndex<count($fallback)){
				$args[]=$fallback[$fallbackIndex++];
				continue;
			}
			if($parameter->isDefaultValueAvailable()){
				break;
			}
			if($parameter->allowsNull()){
				$args[]=null;
				continue;
			}
			break;
		}
		return $callback(...$args);
	}

	/**
	 * Creates reflection metadata for any supported callable form.
	 *
	 * @param callable $callback Closure, function name, object method, static method, or invokable object.
	 * @return \ReflectionFunctionAbstract Reflection object used for argument injection.
	 */
	private function callbackReflection(callable $callback): \ReflectionFunctionAbstract {
		if($callback instanceof \Closure){
			return new \ReflectionFunction($callback);
		}
		if(is_array($callback)){
			return new \ReflectionMethod($callback[0], $callback[1]);
		}
		if(is_string($callback) && str_contains($callback, '::')){
			[$class, $method]=explode('::', $callback, 2);
			return new \ReflectionMethod($class, $method);
		}
		if(is_object($callback)){
			return new \ReflectionMethod($callback, '__invoke');
		}
		return new \ReflectionFunction($callback);
	}

	/**
	 * Resolves a typed callback parameter to a transaction helper argument.
	 *
	 * @param \ReflectionParameter $parameter Parameter being inspected.
	 * @param ConnectionContext $connection Connection context available for injection.
	 * @return array{matched: bool, value: mixed} Match flag and injected value.
	 */
	private function typedCallbackArgument(\ReflectionParameter $parameter, ConnectionContext $connection): array {
		$type=$parameter->getType();
		$types=$type instanceof \ReflectionUnionType ? $type->getTypes() : ($type!==null ? [$type] : []);
		foreach($types as $candidate){
			if(!$candidate instanceof \ReflectionNamedType || $candidate->isBuiltin()){
				continue;
			}
			$name=ltrim($candidate->getName(), '\\');
			if($name===self::class || $name===Transaction::class){
				return ['matched'=>true, 'value'=>$this];
			}
			if($name===ConnectionContext::class){
				return ['matched'=>true, 'value'=>$connection];
			}
		}
		return ['matched'=>false, 'value'=>null];
	}

	/**
	 * Returns the Transaction instance used for a retry attempt.
	 *
	 * @param int $attempt One-based attempt number.
	 * @return self Current transaction for the first attempt, otherwise a fresh clone by cluster.
	 */
	private function transactionForAttempt(int $attempt): self {
		return $attempt===1 ? $this : new self($this->cluster);
	}

	/**
	 * Determines whether a failed attempt should be retried.
	 *
	 * @param \Throwable $exception Exception raised by the failed attempt.
	 * @param int $attempt One-based attempt number that just failed.
	 * @param int $attempts Maximum allowed attempts.
	 * @param callable|null $shouldRetry Optional custom retry predicate.
	 * @return bool True when another attempt should be made.
	 */
	private function shouldRetry(\Throwable $exception, int $attempt, int $attempts, ?callable $shouldRetry): bool {
		if($shouldRetry!==null){
			return (bool)$shouldRetry($exception, $attempt, $attempts);
		}
		return SqlError::isTransientTransactionException($exception);
	}

	/**
	 * Applies linear backoff before the next retry attempt.
	 *
	 * @param int $sleepMs Base delay in milliseconds.
	 * @param int $attempt One-based failed attempt number.
	 * @return void
	 */
	private function sleepBeforeRetry(int $sleepMs, int $attempt): void {
		if($sleepMs<=0){
			return;
		}
		usleep(max(0, $sleepMs)*1000*$attempt);
	}

	/**
	 * Executes a raw transaction control statement on the configured cluster.
	 *
	 * @param string $sql SQL control statement such as SAVEPOINT or RELEASE SAVEPOINT.
	 * @return bool True when sql_query() reports success.
	 */
	private function executeTransactionControl(string $sql): bool {
		return false!==sql_query([
			'mysql'=>$sql,
			'postgresql'=>$sql,
			'sqlite'=>$sql,
			'dbms_cluster_override'=>$this->cluster,
		], null, false, false, false, false);
	}

	/**
	 * Creates a unique savepoint name for a nested transaction depth.
	 *
	 * @param int $depth One-based nested depth for diagnostics.
	 * @return string SQL-safe savepoint identifier.
	 */
	private function createSavepointName(int $depth): string {
		return 'dataphyre_tx_'.$depth.'_'.bin2hex(random_bytes(6));
	}

	/**
	 * Decrements active transaction depth for this transaction's cluster.
	 *
	 * @return void
	 */
	private function decrementActiveDepth(): void {
		$key=$this->clusterKey();
		$depth=max(0, (self::$activeDepthByCluster[$key] ?? 1)-1);
		if($depth===0){
			unset(self::$activeDepthByCluster[$key]);
			return;
		}
		self::$activeDepthByCluster[$key]=$depth;
	}

	/**
	 * Returns the normalized cluster key for this transaction.
	 *
	 * @return string Internal key used by active depth tracking.
	 */
	private function clusterKey(): string {
		return self::clusterKeyFor($this->cluster);
	}

	/**
	 * Normalizes nullable cluster names for static depth tracking.
	 *
	 * @param string|null $cluster Cluster name or null for the default cluster.
	 * @return string Non-empty internal map key.
	 */
	private static function clusterKeyFor(?string $cluster): string {
		$cluster=$cluster!==null ? trim($cluster) : null;
		return $cluster!==null && $cluster!=='' ? $cluster : '__default__';
	}
}
