<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Executes planner output through Dataphyre's traced raw-query boundary.
 *
 * Unsupported environments return null before issuing SQL so repositories can
 * retain their legacy per-row implementation. A failed multi-row statement is
 * replayed one row at a time only outside a Framework transaction. PostgreSQL
 * leaves a transaction in an aborted state after a statement error, so an
 * in-transaction failure is reported for every row and left for the owning
 * transaction to roll back.
 */
final class BulkMutationExecutor {

	/**
	 * @param array<int,array<string,mixed>> $rows Normalized rows keyed by original batch index.
	 * @param callable(array<string,mixed>,list<mixed>,bool|array|null):mixed $execute Runs a planned query payload.
	 * @param callable(array<string,mixed>):MutationResult $fallback Runs one legacy insert after a rejected statement.
	 * @param array<string,mixed> $context Mutation context copied to every result.
	 * @return array<int,MutationResult>|null Results keyed by original input index, or null when the fast path is unsupported.
	 */
	public static function inserts(
		string $table,
		array $rows,
		?string $primaryKey,
		bool|array|null $clearCache,
		?BulkMutationOptions $options,
		callable $execute,
		callable $fallback,
		array $context
	): ?array {
		$environment=self::environment($table, $options);
		if($environment===null){
			return null;
		}
		$plan=BulkMutationPlanner::inserts(
			$environment['dbms'],
			$environment['table'],
			$rows,
			$primaryKey,
			$environment['max_rows'],
			$environment['max_parameters'],
			$options?->correlationColumn()
		);
		if($plan===null){
			return null;
		}
		$results=[];
		foreach($plan as $statement){
			$query=self::queryPayload($environment, $statement['sql']);
			$statementInvalidation=$clearCache===true ? [$environment['table']] : $clearCache;
			$raw=DB::withTraceContext([
				'framework_operation'=>'create_many',
				'bulk_mutation'=>true,
				'bulk_rows'=>count($statement['indexes']),
			], static fn(): mixed=>$execute($query, $statement['vars'], $statementInvalidation));
			if($raw===false || $raw===null){
				if(self::hasActiveFrameworkTransaction()){
					self::markStatementFailed(
						$results,
						$statement['indexes'],
						'insert',
						$context,
						'Bulk insert statement failed inside an active Framework transaction; roll back the transaction before retrying.'
					);
					continue;
				}
				foreach($statement['rows'] as $index=>$row){
					$results[$index]=DB::withTraceContext([
						'framework_operation'=>'create_many',
						'bulk_mutation_fallback'=>true,
						'bulk_rows'=>1,
					], static fn(): MutationResult=>$fallback($row));
				}
				continue;
			}
			if($environment['dbms']==='postgresql'){
				self::mapPostgresqlInsertResults($results, $statement, $raw, $context);
				continue;
			}
			foreach($statement['indexes'] as $index){
				$results[$index]=MutationResult::fromRaw('insert', true, $context);
			}
		}
		return $results;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Normalized rows keyed by original batch index.
	 * @param list<string> $conflictColumns
	 * @param list<string>|null $updateColumns
	 * @param callable(array<string,mixed>,list<mixed>,bool|array|null):mixed $execute
	 * @param array<string,mixed> $context
	 * @return array<int,MutationResult>|null
	 */
	public static function upserts(
		string $table,
		array $rows,
		array $conflictColumns,
		?array $updateColumns,
		bool|array|null $clearCache,
		?BulkMutationOptions $options,
		callable $execute,
		array $context
	): ?array {
		$environment=self::environment($table, $options);
		if($environment===null){
			return null;
		}
		if(!in_array($environment['dbms'], ['postgresql', 'sqlite'], true)){
			if($options?->conflictColumns()!==null){
				throw new \LogicException("Bulk upsert conflict targets are not representable by the configured {$environment['dbms']} driver.");
			}
			return null;
		}
		$plan=BulkMutationPlanner::upserts(
			$environment['dbms'],
			$environment['table'],
			$rows,
			$conflictColumns,
			$updateColumns,
			$environment['max_rows'],
			$environment['max_parameters']
		);
		if($plan===null){
			return null;
		}
		$results=[];
		foreach($plan as $statement){
			$query=self::queryPayload($environment, $statement['sql']);
			$statementInvalidation=$clearCache===true ? [$environment['table']] : $clearCache;
			$raw=DB::withTraceContext([
				'framework_operation'=>'upsert_many',
				'bulk_mutation'=>true,
				'bulk_rows'=>count($statement['indexes']),
			], static fn(): mixed=>$execute($query, $statement['vars'], $statementInvalidation));
			if($raw===false || $raw===null){
				if(self::hasActiveFrameworkTransaction()){
					self::markStatementFailed(
						$results,
						$statement['indexes'],
						'upsert',
						$context,
						'Bulk upsert statement failed inside an active Framework transaction; roll back the transaction before retrying.'
					);
					continue;
				}
				self::retryUpsertsIndividually(
					$results,
					$statement['rows'],
					$environment,
					$conflictColumns,
					$updateColumns,
					$clearCache,
					$execute,
					$context
				);
				continue;
			}
			foreach($statement['indexes'] as $index){
				$results[$index]=MutationResult::fromRaw('upsert', true, $context);
			}
		}
		return $results;
	}

	private static function hasActiveFrameworkTransaction(): bool {
		return class_exists(Transaction::class) && Transaction::hasActiveTransaction();
	}

	/**
	 * @param array<int,MutationResult> $results
	 * @param list<int> $indexes
	 * @param array<string,mixed> $context
	 */
	private static function markStatementFailed(
		array &$results,
		array $indexes,
		string $operation,
		array $context,
		string $message
	): void {
		foreach($indexes as $index){
			$results[$index]=MutationResult::fromRaw($operation, false, $context, $message);
		}
	}

	/**
	 * Replays an atomically rejected multi-row upsert as isolated statements.
	 *
	 * @param array<int,MutationResult> $results
	 * @param array<int,array<string,mixed>> $rows
	 * @param array{dbms:string,cluster:string,table:string,max_rows:int,max_parameters:int} $environment
	 * @param list<string> $conflictColumns
	 * @param list<string>|null $updateColumns
	 * @param callable(array<string,mixed>,list<mixed>,bool|array|null):mixed $execute
	 * @param array<string,mixed> $context
	 */
	private static function retryUpsertsIndividually(
		array &$results,
		array $rows,
		array $environment,
		array $conflictColumns,
		?array $updateColumns,
		bool|array|null $clearCache,
		callable $execute,
		array $context
	): void {
		foreach($rows as $index=>$row){
			$singlePlan=BulkMutationPlanner::upserts(
				$environment['dbms'],
				$environment['table'],
				[$index=>$row],
				$conflictColumns,
				$updateColumns,
				1,
				$environment['max_parameters']
			);
			if($singlePlan===null || $singlePlan===[]){
				$results[$index]=MutationResult::fromRaw('upsert', false, $context);
				continue;
			}
			$single=$singlePlan[0];
			$query=self::queryPayload($environment, $single['sql']);
			$statementInvalidation=$clearCache===true ? [$environment['table']] : $clearCache;
			$raw=DB::withTraceContext([
				'framework_operation'=>'upsert_many',
				'bulk_mutation_fallback'=>true,
				'bulk_rows'=>1,
			], static fn(): mixed=>$execute($query, $single['vars'], $statementInvalidation));
			$results[$index]=MutationResult::fromRaw(
				'upsert',
				$raw===false || $raw===null ? false : true,
				$context
			);
		}
	}

	/**
	 * @param array<int,MutationResult> $results
	 * @param array{indexes:list<int>,rows:array<int,array<string,mixed>>,primary_key:?string,correlation_column:?string} $statement
	 * @param array<string,mixed>|array<int,array<string,mixed>> $raw
	 * @param array<string,mixed> $context
	 */
	private static function mapPostgresqlInsertResults(array &$results, array $statement, array $raw, array $context): void {
		$correlationColumn=$statement['correlation_column'];
		if($correlationColumn===null){
			throw new \RuntimeException('PostgreSQL bulk INSERT plan did not include a result correlation column.');
		}
		$returned=array_is_list($raw) ? $raw : [$raw];
		$byCorrelation=[];
		foreach($returned as $row){
			if(!is_array($row) || !array_key_exists($correlationColumn, $row)){
				throw new \RuntimeException('PostgreSQL bulk INSERT RETURNING did not include the configured correlation column.');
			}
			$key=self::identityKey($row[$correlationColumn]);
			$byCorrelation[$key][]=$row;
		}
		foreach($statement['rows'] as $index=>$input){
			$key=self::identityKey($input[$correlationColumn]);
			$row=null;
			if(isset($byCorrelation[$key])){
				$row=array_shift($byCorrelation[$key]);
			}
			if(isset($byCorrelation[$key]) && $byCorrelation[$key]===[]){
				unset($byCorrelation[$key]);
			}
			$results[$index]=MutationResult::fromRaw('insert', $row ?? false, $context);
		}
	}

	private static function identityKey(mixed $value): string {
		return (string)$value;
	}

	/**
	 * @return array{dbms:string,cluster:string,table:string,max_rows:int,max_parameters:int}|null
	 */
	private static function environment(string $table, ?BulkMutationOptions $options): ?array {
		if(!defined('DP_SQL_CFG') || !defined('DP_CORE_CFG') || !class_exists('dataphyre\\sql')){
			return null;
		}
		if(!method_exists('dataphyre\\sql', 'table') || !method_exists('dataphyre\\sql', 'resolve_cluster')){
			return null;
		}
		$config=constant('DP_SQL_CFG');
		$core=constant('DP_CORE_CFG');
		if(!is_array($config) || !is_array($core)){
			return null;
		}
		$queryDbms=null;
		$physical=\dataphyre\sql::table($table, $queryDbms);
		$cluster=\dataphyre\sql::resolve_cluster($config['tables'][$physical]['cluster'] ?? $config['tables'][$table]['cluster'] ?? $config['default_cluster'] ?? null);
		if(class_exists(Transaction::class)){
			$activeCluster=Transaction::activeCluster();
			if(is_string($activeCluster) && trim($activeCluster)!==''){
				$cluster=trim($activeCluster);
			}
		}
		$datacenter=(string)($core['datacenter'] ?? '');
		$dbms=strtolower(trim((string)($config['datacenters'][$datacenter]['dbms_clusters'][$cluster]['dbms'] ?? '')));
		if($dbms==='' || ($queryDbms!==null && strtolower($queryDbms)!==$dbms)){
			return null;
		}
		$global=is_array($config['bulk_mutations'] ?? null) ? $config['bulk_mutations'] : [];
		$tableMutationConfig=$config['tables'][$physical]['bulk_mutations']
			?? $config['tables'][$table]['bulk_mutations']
			?? null;
		$tableConfig=is_array($tableMutationConfig) ? $tableMutationConfig : [];
		if((bool)($config['tables'][$physical]['multipoint_writes'] ?? $config['tables'][$table]['multipoint_writes'] ?? false)){
			return null;
		}
		if(($tableConfig['enabled'] ?? $global['enabled'] ?? true)!==true){
			return null;
		}
		$defaultParameters=$dbms==='sqlite'
			? BulkMutationPlanner::DEFAULT_SQLITE_PARAMETERS
			: BulkMutationPlanner::DEFAULT_POSTGRESQL_PARAMETERS;
		$configuredLimits=is_array($global['parameter_limits'] ?? null) ? $global['parameter_limits'] : [];
		$maxRows=$options?->maxRowsPerStatement()
			?? self::positiveInt($tableConfig['max_rows_per_statement'] ?? $global['max_rows_per_statement'] ?? null)
			?? BulkMutationPlanner::DEFAULT_MAX_ROWS;
		$maxParameters=$options?->maxParameters()
			?? self::positiveInt($tableConfig['max_parameters'] ?? $configuredLimits[$dbms] ?? null)
			?? $defaultParameters;
		return [
			'dbms'=>$dbms,
			'cluster'=>$cluster,
			'table'=>$physical,
			'max_rows'=>$maxRows,
			'max_parameters'=>$maxParameters,
		];
	}

	/** @return array<string,mixed> */
	private static function queryPayload(array $environment, string $sql): array {
		return [
			$environment['dbms']=>$sql,
			'dbms_cluster_override'=>$environment['cluster'],
		];
	}

	private static function positiveInt(mixed $value): ?int {
		if(!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)===1)){
			return null;
		}
		$value=(int)$value;
		return $value>0 ? $value : null;
	}
}
