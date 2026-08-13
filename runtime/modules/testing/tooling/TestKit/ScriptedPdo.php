<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Throwable;

/**
 * Driver-independent PDO protocol double.
 *
 * Queue semantic row/scalar responses or an explicitly configured statement;
 * every prepare, bind, execute, fetch, and close remains inspectable. This is
 * intentionally a protocol fake, not an in-memory SQL interpreter.
 */
final class ScriptedPdo extends \PDO {

	/** @var list<ScriptedPdoStatement|false|\Throwable> */
	private array $statement_queue=[];
	/** @var list<int|false|\Throwable> */
	private array $exec_queue=[];
	/** @var list<array{operation:string,sql:string,options:array<int|string,mixed>}> */
	private array $prepared=[];
	/** @var list<ScriptedPdoStatement> */
	private array $statements=[];
	/** @var list<array{operation:string,sql:?string}> */
	private array $operations=[];
	private ?\Throwable $driver_failure=null;
	private bool $transaction_active=false;
	private bool $begin_result=true;
	private bool $commit_result=true;
	private bool $rollback_result=true;
	private ?\Throwable $rollback_failure=null;

	public function __construct(private string $driver='sqlite') {}

	public function queueRows(array $rows): self {
		return $this->queueStatement(new ScriptedPdoStatement($rows));
	}

	public function queueScalar(mixed $value): self {
		return $this->queueStatement(new ScriptedPdoStatement([], $value));
	}

	public function queueStatement(ScriptedPdoStatement $statement): self {
		$this->statement_queue[]=$statement;
		return $this;
	}

	public function queuePrepareMiss(): self {
		$this->statement_queue[]=false;
		return $this;
	}

	public function queuePrepareFailure(\Throwable $failure): self {
		$this->statement_queue[]=$failure;
		return $this;
	}

	public function queueExecResult(int|false $result): self {
		$this->exec_queue[]=$result;
		return $this;
	}

	public function queueExecFailure(\Throwable $failure): self {
		$this->exec_queue[]=$failure;
		return $this;
	}

	public function returnBeginResult(bool $result): self {
		$this->begin_result=$result;
		return $this;
	}

	public function returnCommitResult(bool $result): self {
		$this->commit_result=$result;
		return $this;
	}

	public function returnRollbackResult(bool $result): self {
		$this->rollback_result=$result;
		return $this;
	}

	public function markTransactionActive(bool $active=true): self {
		$this->transaction_active=$active;
		return $this;
	}

	public function failRollbackWith(\Throwable $failure): self {
		$this->rollback_failure=$failure;
		return $this;
	}

	public function failDriverWith(\Throwable $failure): self {
		$this->driver_failure=$failure;
		return $this;
	}

	public function getAttribute(int $attribute): mixed {
		if($this->driver_failure!==null){ throw $this->driver_failure; }
		return match($attribute){
			\PDO::ATTR_DRIVER_NAME=>$this->driver,
			\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
			default=>null,
		};
	}

	public function exec(string $statement): int|false {
		$this->operations[]=['operation'=>'exec','sql'=>$statement];
		$result=array_shift($this->exec_queue) ?? 0;
		if($result instanceof \Throwable){ throw $result; }
		return $result;
	}

	public function beginTransaction(): bool {
		$this->operations[]=['operation'=>'begin','sql'=>null];
		if($this->begin_result){ $this->transaction_active=true; }
		return $this->begin_result;
	}

	public function commit(): bool {
		$this->operations[]=['operation'=>'commit','sql'=>null];
		if($this->commit_result){ $this->transaction_active=false; }
		return $this->commit_result;
	}

	public function rollBack(): bool {
		$this->operations[]=['operation'=>'rollback','sql'=>null];
		if($this->rollback_failure!==null){ throw $this->rollback_failure; }
		if($this->rollback_result){ $this->transaction_active=false; }
		return $this->rollback_result;
	}

	public function inTransaction(): bool {
		return $this->transaction_active;
	}

	public function prepare(string $query, array $options=[]): \PDOStatement|false {
		return $this->nextStatement('prepare', $query, $options);
	}

	public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs): \PDOStatement|false {
		$options=$fetchMode===null ? $fetchModeArgs : [$fetchMode, ...$fetchModeArgs];
		return $this->nextStatement('query', $query, $options);
	}

	/** @return list<array{operation:string,sql:string,options:array<int|string,mixed>}> */
	public function prepared(): array {
		return $this->prepared;
	}

	/** @return list<string> */
	public function preparedSql(): array {
		return array_column($this->prepared, 'sql');
	}

	/** @return list<ScriptedPdoStatement> */
	public function statements(): array {
		return $this->statements;
	}

	/** @return list<array{operation:string,sql:?string}> */
	public function operations(): array {
		return $this->operations;
	}

	/** @return list<string> */
	public function operationNames(): array {
		return array_column($this->operations, 'operation');
	}

	public function lastStatement(): ?ScriptedPdoStatement {
		return $this->statements[array_key_last($this->statements)] ?? null;
	}

	/** @param array<int|string,mixed> $options */
	private function nextStatement(string $operation, string $sql, array $options): \PDOStatement|false {
		$this->prepared[]=['operation'=>$operation, 'sql'=>$sql, 'options'=>$options];
		$next=array_shift($this->statement_queue) ?? new ScriptedPdoStatement();
		if($next instanceof \Throwable){ throw $next; }
		if($next instanceof ScriptedPdoStatement){ $this->statements[]=$next; }
		return $next;
	}
}
