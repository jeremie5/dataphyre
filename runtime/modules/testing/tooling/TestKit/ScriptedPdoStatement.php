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

/** Inspectable statement used by ScriptedPdo response plans. */
final class ScriptedPdoStatement extends \PDOStatement {

	/** @var array<string|int,array{value:mixed,type:int}> */
	private array $bindings=[];
	/** @var list<array<int|string,mixed>|null> */
	private array $executions=[];
	private bool $execute_result=true;
	private bool $closed=false;
	private ?\Throwable $bind_failure=null;
	private ?\Throwable $execute_failure=null;
	private ?\Throwable $rows_failure=null;
	private ?\Throwable $scalar_failure=null;
	private ?\Throwable $close_failure=null;
	private int $fetch_index=0;
	private ?int $row_count=null;

	public function __construct(private array $rows=[], private mixed $column=false) {}

	public function returnExecuteResult(bool $result): self {
		$this->execute_result=$result;
		return $this;
	}

	public function returnRowCount(int $count): self {
		$this->row_count=$count;
		return $this;
	}

	public function failBindWith(\Throwable $failure): self {
		$this->bind_failure=$failure;
		return $this;
	}

	public function failExecuteWith(\Throwable $failure): self {
		$this->execute_failure=$failure;
		return $this;
	}

	public function failRowsWith(\Throwable $failure): self {
		$this->rows_failure=$failure;
		return $this;
	}

	public function failScalarWith(\Throwable $failure): self {
		$this->scalar_failure=$failure;
		return $this;
	}

	public function failCloseWith(\Throwable $failure): self {
		$this->close_failure=$failure;
		return $this;
	}

	public function bindValue(string|int $param, mixed $value, int $type=\PDO::PARAM_STR): bool {
		if($this->bind_failure!==null){ throw $this->bind_failure; }
		$this->bindings[$param]=['value'=>$value, 'type'=>$type];
		return true;
	}

	public function execute(?array $params=null): bool {
		$this->executions[]=$params;
		if($this->execute_failure!==null){ throw $this->execute_failure; }
		return $this->execute_result;
	}

	public function fetch(int $mode=\PDO::FETCH_DEFAULT, int $cursorOrientation=\PDO::FETCH_ORI_NEXT, int $cursorOffset=0): mixed {
		$row=$this->rows[$this->fetch_index] ?? false;
		$this->fetch_index++;
		return $row;
	}

	public function fetchAll(int $mode=\PDO::FETCH_DEFAULT, mixed ...$args): array {
		if($this->rows_failure!==null){ throw $this->rows_failure; }
		return $this->rows;
	}

	public function fetchColumn(int $column=0): mixed {
		if($this->scalar_failure!==null){ throw $this->scalar_failure; }
		return $this->column;
	}

	public function rowCount(): int {
		return $this->row_count ?? count($this->rows);
	}

	public function closeCursor(): bool {
		$this->closed=true;
		if($this->close_failure!==null){ throw $this->close_failure; }
		return true;
	}

	/** @return array<string|int,array{value:mixed,type:int}> */
	public function bindings(): array {
		return $this->bindings;
	}

	/** @return array<string|int,mixed> */
	public function bindingValues(): array {
		return array_map(static fn(array $binding):mixed=>$binding['value'], $this->bindings);
	}

	/** @return array<string|int,int> */
	public function bindingTypes(): array {
		return array_map(static fn(array $binding):int=>$binding['type'], $this->bindings);
	}

	/** @return list<array<int|string,mixed>|null> */
	public function executions(): array {
		return $this->executions;
	}

	public function closed(): bool {
		return $this->closed;
	}
}
