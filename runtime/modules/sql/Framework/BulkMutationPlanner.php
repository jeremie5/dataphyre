<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Compiles bounded, order-preserving multi-row mutation statements.
 *
 * Planning is pure: it never opens a connection or mutates cache state. Rows
 * are grouped only while consecutive payloads have the same column set, which
 * preserves database-visible mutation and identity order.
 */
final class BulkMutationPlanner {

	public const DEFAULT_MAX_ROWS=128;
	public const DEFAULT_POSTGRESQL_PARAMETERS=32000;
	public const DEFAULT_SQLITE_PARAMETERS=900;

	/**
	 * @param array<int,array<string,mixed>> $rows Normalized rows keyed by original batch index.
	 * @param ?string $correlationColumn Stable input column used to correlate PostgreSQL RETURNING rows, or null to use the primary key.
	 * @return list<array{sql:string,vars:list<mixed>,indexes:list<int>,rows:array<int,array<string,mixed>>,primary_key:?string,correlation_column:?string}>|null
	 */
	public static function inserts(
		string $dbms,
		string $table,
		array $rows,
		?string $primaryKey,
		int $maxRows,
		int $maxParameters,
		?string $correlationColumn=null
	): ?array {
		$dbms=strtolower(trim($dbms));
		if(!in_array($dbms, ['postgresql', 'sqlite'], true)){
			return null;
		}
		if($dbms==='postgresql'){
			$correlationColumn=$correlationColumn ?? $primaryKey;
			if($correlationColumn===null || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $correlationColumn)!==1){
				return null;
			}
			if($primaryKey!==null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $primaryKey)!==1){
				return null;
			}
			$seen=[];
			foreach($rows as $row){
				$value=$row[$correlationColumn] ?? null;
				if(!is_int($value) && !is_string($value)){
					return null;
				}
				$key=(string)$value;
				if(isset($seen[$key])){
					return null;
				}
				$seen[$key]=true;
			}
		}
		return self::plan(
			$dbms,
			$table,
			$rows,
			$maxRows,
			$maxParameters,
			static function(string $dbms, string $quotedTable, array $columns, string $values)use($primaryKey, $correlationColumn): string{
				$quotedColumns=implode(', ', array_map(static fn(string $column): string=>self::quoteIdentifier($dbms, $column), $columns));
				if($dbms==='postgresql'){
					$returningColumns=array_values(array_unique(array_filter(
						[$primaryKey, $correlationColumn],
						static fn(?string $column): bool=>$column!==null
					)));
					$quotedReturning=implode(', ', array_map(
						static fn(string $column): string=>self::quoteIdentifier($dbms, $column),
						$returningColumns
					));
					return "INSERT INTO {$quotedTable} ({$quotedColumns}) VALUES {$values} ON CONFLICT DO NOTHING RETURNING {$quotedReturning}";
				}
				return "INSERT OR IGNORE INTO {$quotedTable} ({$quotedColumns}) VALUES {$values}";
			},
			$primaryKey,
			$correlationColumn
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Normalized rows keyed by original batch index.
	 * @param list<string> $conflictColumns
	 * @param list<string>|null $updateColumns
	 * @return list<array{sql:string,vars:list<mixed>,indexes:list<int>,rows:array<int,array<string,mixed>>,primary_key:?string,correlation_column:?string}>|null
	 */
	public static function upserts(
		string $dbms,
		string $table,
		array $rows,
		array $conflictColumns,
		?array $updateColumns,
		int $maxRows,
		int $maxParameters
	): ?array {
		$dbms=strtolower(trim($dbms));
		if(!in_array($dbms, ['postgresql', 'sqlite'], true) || $conflictColumns===[]){
			return null;
		}
		foreach($conflictColumns as $column){
			if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)!==1){
				return null;
			}
		foreach($rows as $row){
				if(!array_key_exists($column, $row)){
					return null;
				}
			}
		}
		if($updateColumns!==null){
			foreach($updateColumns as $column){
				if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)!==1){
					return null;
				}
				foreach($rows as $row){
					if(!array_key_exists($column, $row)){
						return null;
					}
				}
			}
		}
		return self::plan(
			$dbms,
			$table,
			$rows,
			$maxRows,
			$maxParameters,
			static function(string $dbms, string $quotedTable, array $columns, string $values)use($conflictColumns, $updateColumns): string{
				$quotedColumns=implode(', ', array_map(static fn(string $column): string=>self::quoteIdentifier($dbms, $column), $columns));
				$quotedConflict=implode(', ', array_map(static fn(string $column): string=>self::quoteIdentifier($dbms, $column), $conflictColumns));
				$updates=$updateColumns ?? array_values(array_diff($columns, $conflictColumns));
				if($updates===[]){
					$action='DO NOTHING';
				}else{
					$assignments=[];
					foreach($updates as $column){
						$quoted=self::quoteIdentifier($dbms, $column);
						$assignments[]="{$quoted}=excluded.{$quoted}";
					}
					$action='DO UPDATE SET '.implode(', ', $assignments);
				}
				return "INSERT INTO {$quotedTable} ({$quotedColumns}) VALUES {$values} ON CONFLICT ({$quotedConflict}) {$action}";
			},
			null,
			null
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param callable(string,string,list<string>,string):string $compileSql
	 * @return list<array{sql:string,vars:list<mixed>,indexes:list<int>,rows:array<int,array<string,mixed>>,primary_key:?string,correlation_column:?string}>|null
	 */
	private static function plan(
		string $dbms,
		string $table,
		array $rows,
		int $maxRows,
		int $maxParameters,
		callable $compileSql,
		?string $primaryKey,
		?string $correlationColumn
	): ?array {
		if($rows===[]){
			return [];
		}
		$quotedTable=self::quoteQualifiedIdentifier($dbms, $table);
		if($quotedTable===null || $maxRows<1 || $maxParameters<1){
			return null;
		}
		$groups=[];
		$currentSignature=null;
		foreach($rows as $index=>$row){
			$normalized=self::canonicalRow($row);
			if($normalized===null){
				return null;
			}
			$signature=implode("\0", array_keys($normalized));
			if($currentSignature===null || $signature!==$currentSignature){
				$groups[]=[];
				$currentSignature=$signature;
			}
			$groups[array_key_last($groups)][(int)$index]=$normalized;
		}

		$statements=[];
		foreach($groups as $group){
			$first=reset($group);
			$columns=array_keys($first);
			$columnCount=count($columns);
			$rowBound=min($maxRows, intdiv($maxParameters, $columnCount));
			if($rowBound<1){
				return null;
			}
			foreach(array_chunk($group, $rowBound, true) as $chunk){
				$vars=[];
				$valueGroups=[];
				foreach($chunk as $row){
					$valueGroups[]='('.implode(', ', array_fill(0, $columnCount, '?')).')';
					foreach($columns as $column){
						$vars[]=self::normalizeValue($dbms, $row[$column]);
					}
				}
				$statements[]=[
					'sql'=>$compileSql($dbms, $quotedTable, $columns, implode(', ', $valueGroups)),
					'vars'=>$vars,
					'indexes'=>array_map('intval', array_keys($chunk)),
					'rows'=>$chunk,
					'primary_key'=>$primaryKey,
					'correlation_column'=>$correlationColumn,
				];
			}
		}
		return $statements;
	}

	/** @return array<string,mixed>|null */
	private static function canonicalRow(array $row): ?array {
		if($row===[] || array_is_list($row)){
			return null;
		}
		foreach(array_keys($row) as $column){
			if(!is_string($column) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)!==1){
				return null;
			}
		}
		ksort($row, SORT_STRING);
		return $row;
	}

	private static function quoteQualifiedIdentifier(string $dbms, string $identifier): ?string {
		$parts=explode('.', $identifier);
		$quoted=[];
		foreach($parts as $part){
			if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)!==1){
				return null;
			}
			$quoted[]=self::quoteIdentifier($dbms, $part);
		}
		return implode('.', $quoted);
	}

	private static function quoteIdentifier(string $dbms, string $identifier): string {
		return $dbms==='mysql' ? "`{$identifier}`" : "\"{$identifier}\"";
	}

	private static function normalizeValue(string $dbms, mixed $value): mixed {
		if(is_bool($value)){
			return $dbms==='postgresql' ? ($value ? 't' : 'f') : (int)$value;
		}
		if(is_array($value)){
			return json_encode($value);
		}
		return $value;
	}
}
