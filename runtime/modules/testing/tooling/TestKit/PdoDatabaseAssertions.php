<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\AssertionContext;

use Throwable;

final class PdoDatabaseAssertions {

	private bool $transaction_open=false;

	public function __construct(private \PDO $pdo) {}

	public function begin(): self {
		if(!$this->transaction_open){
			$this->pdo->beginTransaction();
			$this->transaction_open=true;
		}
		return $this;
	}

	public function rollback(): self {
		if($this->transaction_open){
			$this->pdo->rollBack();
			$this->transaction_open=false;
		}
		return $this;
	}

	public function commit(): self {
		if($this->transaction_open){
			$this->pdo->commit();
			$this->transaction_open=false;
		}
		return $this;
	}

	public function transaction(callable $callback): mixed {
		$this->begin();
		try{
			$result=$callback($this);
			$this->rollback();
			return $result;
		}catch(Throwable $throwable){
			$this->rollback();
			throw $throwable;
		}
	}

	public function assertTableHas(AssertionContext $t, string $table, array $expected, string $message=''): void {
		$t->isTrue($this->rowExists($table, $expected), $message!=='' ? $message : 'Expected database table to contain row.');
	}

	public function assertTableMissing(AssertionContext $t, string $table, array $expected, string $message=''): void {
		$t->isFalse($this->rowExists($table, $expected), $message!=='' ? $message : 'Expected database table not to contain row.');
	}

	public function assertTableCount(AssertionContext $t, string $table, int $expected, string $message=''): void {
		$sql='SELECT COUNT(*) AS c FROM '.$this->quoteIdentifier($table);
		$count=(int)$this->pdo->query($sql)->fetchColumn();
		$t->same($expected, $count, $message!=='' ? $message : 'Expected database table count to match.');
	}

	public function assertSchemaHasColumn(AssertionContext $t, string $table, string $column, string $message=''): void {
		$t->contains($column, $this->columns($table), $message!=='' ? $message : 'Expected database schema to contain column.');
	}

	/** @return array<int, string> */
	public function columns(string $table): array {
		$driver=(string)$this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
		if($driver==='sqlite'){
			$statement=$this->pdo->query('PRAGMA table_info('.$this->quoteIdentifier($table).')');
			$columns=[];
			foreach($statement ? $statement->fetchAll(\PDO::FETCH_ASSOC) : [] as $row){
				$columns[]=(string)($row['name'] ?? '');
			}
			return array_values(array_filter($columns, static fn(string $value): bool=>$value!==''));
		}
		$statement=$this->pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_name=:table ORDER BY ordinal_position');
		$statement->execute(['table'=>$table]);
		return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN) ?: []));
	}

	/** @return array<string, array<int, string>> */
	public function schemaSnapshot(array $tables): array {
		$snapshot=[];
		foreach($tables as $table){
			$snapshot[(string)$table]=$this->columns((string)$table);
		}
		return $snapshot;
	}

	/** @param array<int, string> $expected_columns @return array<string, array<int, string>> */
	public function diffSchema(string $table, array $expected_columns): array {
		$actual=$this->columns($table);
		return [
			'missing'=>array_values(array_diff($expected_columns, $actual)),
			'extra'=>array_values(array_diff($actual, $expected_columns)),
		];
	}

	private function rowExists(string $table, array $expected): bool {
		if($expected===[]){
			$sql='SELECT 1 FROM '.$this->quoteIdentifier($table).' LIMIT 1';
			return (bool)$this->pdo->query($sql)->fetchColumn();
		}
		$where=[];
		$bindings=[];
		foreach($expected as $column=>$value){
			$key=':p'.count($bindings);
			$where[]=$this->quoteIdentifier((string)$column).'='.$key;
			$bindings[$key]=$value;
		}
		$sql='SELECT 1 FROM '.$this->quoteIdentifier($table).' WHERE '.implode(' AND ', $where).' LIMIT 1';
		$statement=$this->pdo->prepare($sql);
		$statement->execute($bindings);
		return (bool)$statement->fetchColumn();
	}

	private function quoteIdentifier(string $identifier): string {
		if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)!==1){
			throw new \InvalidArgumentException('Unsafe SQL identifier for test assertion.');
		}
		return '"'.$identifier.'"';
	}
}
