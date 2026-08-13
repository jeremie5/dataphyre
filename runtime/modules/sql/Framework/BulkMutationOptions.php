<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Immutable tuning and conflict metadata for bounded repository mutations.
 *
 * The object deliberately contains no application policy. Insert batches use
 * statement bounds and may declare a stable application-supplied correlation
 * column for exact PostgreSQL RETURNING-row matching when primary keys are
 * database-generated. Upsert batches can additionally declare the exact
 * unique-key columns and the columns updated from the incoming row.
 */
final class BulkMutationOptions {

	/** @var list<string>|null */
	private readonly ?array $conflictColumns;

	/** @var list<string>|null */
	private readonly ?array $updateColumns;

	private readonly ?string $correlationColumn;

	/**
	 * @param array<int,string>|null $conflictColumns Exact upsert conflict target, or null to infer the repository primary key.
	 * @param array<int,string>|null $updateColumns Columns copied from the incoming row after a conflict, or null for every non-conflict input column.
	 * @param ?int $maxRowsPerStatement Optional row ceiling overriding SQL configuration for this call.
	 * @param ?int $maxParameters Optional bind-parameter ceiling overriding the DBMS default for this call.
	 * @param ?string $correlationColumn Stable input column used to correlate PostgreSQL INSERT RETURNING rows when primary keys are database-generated.
	 */
	public function __construct(
		?array $conflictColumns=null,
		?array $updateColumns=null,
		private readonly ?int $maxRowsPerStatement=null,
		private readonly ?int $maxParameters=null,
		?string $correlationColumn=null
	){
		$this->conflictColumns=self::normalizeColumns($conflictColumns, 'conflict');
		$this->updateColumns=self::normalizeColumns($updateColumns, 'update');
		$this->correlationColumn=self::normalizeColumn($correlationColumn, 'correlation');
		if($this->maxRowsPerStatement!==null && $this->maxRowsPerStatement<1){
			throw new \InvalidArgumentException('Bulk mutation maxRowsPerStatement must be a positive integer.');
		}
		if($this->maxParameters!==null && $this->maxParameters<1){
			throw new \InvalidArgumentException('Bulk mutation maxParameters must be a positive integer.');
		}
	}

	/**
	 * Creates insert-only statement-bound options.
	 */
	public static function inserts(
		?int $maxRowsPerStatement=null,
		?int $maxParameters=null,
		?string $correlationColumn=null
	): self {
		return new self(null, null, $maxRowsPerStatement, $maxParameters, $correlationColumn);
	}

	/**
	 * Creates an upsert contract with an explicit conflict target.
	 *
	 * @param array<int,string> $conflictColumns Exact unique or primary-key columns used by ON CONFLICT.
	 * @param array<int,string>|null $updateColumns Incoming columns to update, or null for every non-conflict column.
	 */
	public static function upserts(
		array $conflictColumns,
		?array $updateColumns=null,
		?int $maxRowsPerStatement=null,
		?int $maxParameters=null
	): self {
		if($conflictColumns===[]){
			throw new \InvalidArgumentException('Bulk upsert conflictColumns cannot be empty.');
		}
		return new self($conflictColumns, $updateColumns, $maxRowsPerStatement, $maxParameters);
	}

	/** @return list<string>|null */
	public function conflictColumns(): ?array {
		return $this->conflictColumns;
	}

	/** @return list<string>|null */
	public function updateColumns(): ?array {
		return $this->updateColumns;
	}

	public function maxRowsPerStatement(): ?int {
		return $this->maxRowsPerStatement;
	}

	public function maxParameters(): ?int {
		return $this->maxParameters;
	}

	public function correlationColumn(): ?string {
		return $this->correlationColumn;
	}

	/**
	 * @param array<int,string>|null $columns
	 * @return list<string>|null
	 */
	private static function normalizeColumns(?array $columns, string $role): ?array {
		if($columns===null){
			return null;
		}
		$normalized=[];
		$seen=[];
		foreach($columns as $column){
			$column=trim((string)$column);
			if($column==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)!==1){
				throw new \InvalidArgumentException("Bulk mutation {$role} column is not a safe SQL identifier: {$column}");
			}
			if(isset($seen[$column])){
				continue;
			}
			$seen[$column]=true;
			$normalized[]=$column;
		}
		return $normalized;
	}

	private static function normalizeColumn(?string $column, string $role): ?string {
		if($column===null){
			return null;
		}
		$column=trim($column);
		if($column==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)!==1){
			throw new \InvalidArgumentException("Bulk mutation {$role} column is not a safe SQL identifier: {$column}");
		}
		return $column;
	}
}
