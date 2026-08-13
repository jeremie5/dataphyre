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

final class FakeDatabase {

	/** @var array<string, array<string, mixed>> */
	private array $schema=[];
	/** @var array<string, array<int, array<string, mixed>>> */
	private array $tables=[];
	/** @var array<int, array{schema:array<string, array<string, mixed>>, tables:array<string, array<int, array<string, mixed>>>}> */
	private array $transactions=[];

	public function __construct(array $schema=[]) {
		foreach($schema as $table=>$columns){
			$this->createTable((string)$table, is_array($columns) ? $columns : []);
		}
	}

	public function createTable(string $table, array $columns=[]): self {
		$table=$this->tableName($table);
		$this->schema[$table]=$columns;
		$this->tables[$table]=$this->tables[$table] ?? [];
		return $this;
	}

	public function begin(): self {
		$this->transactions[]=[
			'schema'=>$this->schema,
			'tables'=>$this->tables,
		];
		return $this;
	}

	public function commit(): self {
		array_pop($this->transactions);
		return $this;
	}

	public function rollback(): self {
		$snapshot=array_pop($this->transactions);
		if(is_array($snapshot)){
			$this->schema=$snapshot['schema'];
			$this->tables=$snapshot['tables'];
		}
		return $this;
	}

	public function transaction(callable $callback): mixed {
		$this->begin();
		try{
			$result=$callback($this);
			$this->commit();
			return $result;
		}catch(Throwable $throwable){
			$this->rollback();
			throw $throwable;
		}
	}

	public function insert(string $table, array $row): self {
		$table=$this->tableName($table);
		$this->tables[$table]=$this->tables[$table] ?? [];
		$this->tables[$table][]=$row;
		return $this;
	}

	public function update(string $table, array $where, array $values): int {
		$count=0;
		$table=$this->tableName($table);
		foreach($this->tables[$table] ?? [] as $index=>$row){
			if($this->rowMatches($row, $where)){
				$this->tables[$table][$index]=$values+$row;
				$count++;
			}
		}
		return $count;
	}

	public function delete(string $table, array $where): int {
		$count=0;
		$table=$this->tableName($table);
		$rows=[];
		foreach($this->tables[$table] ?? [] as $row){
			if($this->rowMatches($row, $where)){
				$count++;
				continue;
			}
			$rows[]=$row;
		}
		$this->tables[$table]=$rows;
		return $count;
	}

	/** @return array<int, array<string, mixed>> */
	public function rows(string $table): array {
		return array_values($this->tables[$this->tableName($table)] ?? []);
	}

	/** @return array<string, mixed> */
	public function schema(string $table): array {
		return $this->schema[$this->tableName($table)] ?? [];
	}

	public function assertTableHas(AssertionContext $t, string $table, array $expected, string $message=''): void {
		$found=false;
		foreach($this->rows($table) as $row){
			try{
				$t->subset($expected, $row);
				$found=true;
				break;
			}catch(AssertionFailed){
			}
		}
		if($found===false){
			$t->fail($message!=='' ? $message : 'Expected fake database table to contain row.', [$table=>$expected], $this->rows($table));
		}
	}

	public function assertTableMissing(AssertionContext $t, string $table, array $expected, string $message=''): void {
		foreach($this->rows($table) as $row){
			try{
				$t->subset($expected, $row);
				$t->fail($message!=='' ? $message : 'Expected fake database table not to contain row.', 'missing row', $row);
			}catch(AssertionFailed $failure){
				if($failure->getMessage()!==($message!=='' ? $message : 'Expected fake database table not to contain row.')){
					continue;
				}
				throw $failure;
			}
		}
		$t->isTrue(true, 'Fake database row was absent.');
	}

	public function assertTableCount(AssertionContext $t, string $table, int $expected, string $message=''): void {
		$t->same($expected, count($this->rows($table)), $message!=='' ? $message : 'Expected fake database table count to match.');
	}

	public function assertSchemaHasColumn(AssertionContext $t, string $table, string $column, string $message=''): void {
		$t->contains($column, array_keys($this->schema($table)), $message!=='' ? $message : 'Expected fake database schema to contain column.');
	}

	public function diffSchema(string $table, array $expected): array {
		$actual=$this->schema($table);
		return [
			'missing'=>array_values(array_diff(array_keys($expected), array_keys($actual))),
			'extra'=>array_values(array_diff(array_keys($actual), array_keys($expected))),
			'changed'=>array_values(array_filter(array_keys($expected), static fn(string $column): bool=>array_key_exists($column, $actual) && $actual[$column]!==$expected[$column])),
		];
	}

	private function rowMatches(array $row, array $expected): bool {
		foreach($expected as $key=>$value){
			if(!array_key_exists($key, $row) || $row[$key]!==$value){
				return false;
			}
		}
		return true;
	}

	private function tableName(string $table): string {
		$table=trim(str_replace('\\', '/', $table));
		if($table===''){
			throw new \InvalidArgumentException('Table name cannot be blank.');
		}
		return $table;
	}
}
