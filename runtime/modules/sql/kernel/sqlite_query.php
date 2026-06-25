<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

register_shutdown_function(function(){
	try{
		do{
			foreach(sqlite_query_builder::$queued_queries as $queue=>$queue_data){
				try {
					sqlite_query_builder::execute_multiquery($queue);
				} catch (\Throwable $exception) {
					log_error("SQLite Queued Query Execution Error", $exception);
				}
			}
		}while(!empty(sqlite_query_builder::$queued_queries));
	}catch(\Throwable $exception){
		\dataphyre_shutdown_log('Exception on Dataphyre SQL SQLite shutdown callback', $exception);
	}
});

/**
 * SQLite-backed SQL queue executor for Dataphyre's SQL kernel.
 *
 * The builder translates normalized SQL queue entries into SQLite statements,
 * opens file-backed SQLite3 handles from the active datacenter configuration,
 * and reconciles statement results with Dataphyre cache invalidation,
 * callback dispatch, and schema-hydration retry behavior. Unlike the
 * client/server SQL drivers, endpoints here are configuration routes to
 * SQLite database files; persistence and locking semantics are therefore
 * governed by SQLite3 and the filesystem that hosts the database.
 */
class sqlite_query_builder {

	/** @var array<string, object> Cached SQLite3 handles keyed by DBMS cluster name. */
	public static $conns=[];

	/** @var array<string, array<string, array<int, array<string, mixed>>>> Pending normalized SQL queue entries flushed at shutdown or on demand. */
	public static $queued_queries=[];

	/**
	 * Opens or reuses the main SQLite connection for a configured cluster.
	 *
	 * The cluster is resolved from `DP_SQL_CFG` for the active datacenter and
	 * may be intercepted by `CALL_SQL_OPEN_MAIN_CONNECTION`. When no endpoint
	 * can produce a connection, the SQL layer marks the runtime unavailable in
	 * safemode rather than returning a partially initialized handle.
	 *
	 * @param string $dbms_cluster SQL cluster key from `DP_SQL_CFG['datacenters'][...]['dbms_clusters']`.
	 * @return object Cached SQLite3-compatible connection for the requested cluster.
	 */
	private static function connect_to_cluster(string $dbms_cluster){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_OPEN_MAIN_CONNECTION",...func_get_args())) return $early_return;
		$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		if(!isset(self::$conns[$dbms_cluster]) || isset(self::$conns[$dbms_cluster]) && !is_object(self::$conns[$dbms_cluster])){
			if(!count($endpoints)){
				core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreSQL: No database server available.', 'safemode');
			}
			else
			{
				shuffle($endpoints);
				foreach($endpoints as $endpoint){
					self::connect_to_endpoint($endpoint, $dbms_cluster);
				}
			}
			if(empty(self::$conns[$dbms_cluster])){
				core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreSQL: Failed initiating any SQL connection.', 'safemode');
			}
		}
		return self::$conns[$dbms_cluster];
	}
	
	/**
	 * Opens the SQLite database file associated with a configured endpoint.
	 *
	 * The endpoint value is used as the selected route through the configured
	 * cluster, but the actual `SQLite3` constructor receives the cluster
	 * `database_name`. The resulting handle is cached per cluster so later
	 * calls share the same file-backed connection until explicitly closed by
	 * raw multi-query execution or the PHP process ends.
	 *
	 * @param string $endpoint Configured endpoint route being attempted.
	 * @param string $dbms_cluster Cluster whose `database_name` points at the SQLite file.
	 * @return object SQLite3 connection cached for the cluster; legacy failure paths return false before the caller can use it.
	 */
	private static function connect_to_endpoint(string $endpoint, string $dbms_cluster='default') : object {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(isset(self::$conns[$dbms_cluster])){
			return self::$conns[$dbms_cluster];
		}
		$database=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['database_name'];
		try{
			$conn=new SQLite3($database);
		}catch (Exception $e){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed to connect to SQLite database: ".$e->getMessage(), $S="fatal");
			return false;
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="SQLite connection to $database successful");
		return self::$conns[$dbms_cluster]=$conn;
	}
	
	/**
	 * Executes queued prepared SQLite statements and stores normalized results.
	 *
	 * Statements are executed sequentially against the supplied connection.
	 * Parameters are bound with SQLite's one-based positional indexes and
	 * `SQLITE3_TEXT`, which keeps queue execution deterministic across scalar
	 * values but leaves richer type coercion to SQLite. Write batches are
	 * wrapped in a transaction; any thrown error rolls back the batch, records
	 * the SQL failure through the shared SQL logger, and leaves `$results`
	 * containing only work completed before the failure.
	 *
	 * @param object $conn Active SQLite3 connection.
	 * @param array<int, array{query:string, vars:array<int, mixed>}> $prepared_statements Ordered SQL statements with positional bind values.
	 * @param array<int, array<int, array<string, mixed>>|int> $results Output result slots keyed by queue order.
	 * @param string $dbms_cluster Cluster name used only for SQL error reporting.
	 * @return bool `true` after every prepared statement commits or reads successfully; `false` after rollback-worthy failure.
	 */
	private static function execute_prepared_statements(object $conn, array $prepared_statements, array &$results, string $dbms_cluster='n/a') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index=0;
		$has_write=sql::query_has_write(serialize($prepared_statements));
		try{
			if($has_write)$conn->exec('BEGIN TRANSACTION');
			foreach($prepared_statements as $statement){
				$stmt=$conn->prepare($statement['query']);
				if(!$stmt){
					throw new \Exception("Failed to prepare statement: ".$conn->lastErrorMsg());
				}
				foreach($statement['vars'] as $key=>$value){
					$stmt->bindValue($key+1, $value, SQLITE3_TEXT); // SQLite uses 1-based index for parameters
				}
				$result=$stmt->execute();
				if($result){
					if($result->numColumns()>0){
						$results[$index]=[];
						while($row=$result->fetchArray(SQLITE3_ASSOC)){
							$results[$index][]=$row;
						}
					}
					else
					{
						$results[$index]=max(0, $conn->changes());
					}
					$result->finalize();
				}
				$index++;
				$stmt->close();
			}
			if($has_write)$conn->exec('COMMIT');
		}catch(\Throwable $exception){
			if($has_write)$conn->exec('ROLLBACK');
			sql::log_query_error('SQLite', $dbms_cluster, $statement['query'], $statement['vars'], $exception);
			return false;
		}
		return true;
	}
	
	/**
	 * Executes an unprepared SQLite statement batch produced by the queue.
	 *
	 * The batch is split on semicolons and each non-empty fragment is issued in
	 * order. Write batches receive an explicit transaction, read results are
	 * collected as associative rows, and the connection is closed after a
	 * successful raw batch. Because this path cannot bind values, callers must
	 * only send SQL that has already been built by trusted kernel helpers.
	 *
	 * @param object $conn Active SQLite3 connection.
	 * @param string $multi_query_string Semicolon-delimited SQL batch.
	 * @param array<int, array<int, array<string, mixed>>> $results Output rows keyed by queue order.
	 * @param string $dbms_cluster Cluster name used in SQL error logs.
	 * @return bool `true` when all fragments execute; `false` when execution fails and any write transaction is rolled back.
	 */
	private static function execute_multi_query_string(object $conn, string $multi_query_string, array &$results, string $dbms_cluster='n/a') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index=0;
		$has_write=sql::query_has_write($multi_query_string);
		try{
			if($has_write)$conn->exec('BEGIN TRANSACTION');
			$queries=explode(';', $multi_query_string);
			foreach($queries as $query){
				$query=trim($query);
				if(empty($query)){
					continue;
				}
				if(false===$result=$conn->query($query)){
					throw new \Exception('Query execution failed: '.$conn->lastErrorMsg());
				}
				if($result){
					$results[$index]=[];
					while($row=$result->fetchArray(SQLITE3_ASSOC)){
						$results[$index][]=$row;
					}
					$result->finalize();
				}
				$index++;
			}
			if($has_write)$conn->exec('COMMIT');
			$conn->close();
		}catch(\Throwable $exception){
			if($has_write)$conn->exec('ROLLBACK');
			sql::log_query_error('SQLite', $dbms_cluster, $query, [], $exception);
			return false;
		}
		return true;
	}
	
	/**
	 * Applies queue result semantics after SQLite execution.
	 *
	 * Raw SQLite rows are collapsed into the value shape expected by the
	 * queued operation: empty reads become `false`, non-associative reads return
	 * their first row, and count queries become integers. The method is also
	 * the side-effect boundary for query caching, cache invalidation, and
	 * user-supplied callbacks, so callback consumers observe post-cache values.
	 *
	 * @param ?array<int, mixed> $results SQLite execution output keyed by queue order.
	 * @param ?array<int|string, mixed> $queries Queue metadata in flat or grouped form.
	 * @return void Results are delivered through cache writes, invalidation, and callbacks.
	 */
	private static function process_results(?array $results, ?array $queries): void {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		$query_list=self::queued_query_list($queries);
		foreach(($results ?? []) as $index=>$result){
			$query=$query_list[$index] ?? null;
			if(!$query){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Skipping invalid query at index $index");
				continue;
			}
			$associative = $query['associative'] ?? null;
			if(is_array($result)){
				$result = $result===[] ? false : ($associative === false ? $result[0] : $result);
			}
			elseif($result!==true && !is_int($result) && !is_string($result)){
				$result=false;
			}
			if($query['type']==='count' && is_array($result) && isset($result[0]['count'])){
				$result=(int) $result[0]['count'];
			}
			if(!empty($query['caching']) && isset($query['hash'])){
				sql::cache_query_result($query['location'], $query['hash'], $result, $query['caching']);
			}
			else
			{
				if($result!==false && !empty($query['clear_cache'])){
					sql::invalidate_cache($query['clear_cache']===true ? $query['location'] : $query['clear_cache']);
				}
			}
			if(!empty($query['callback']) && is_callable($query['callback'])){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Calling callback");
				$query['callback']($result);
			}
		}
	}

	/**
	 * Flattens grouped queue metadata into execution order.
	 *
	 * Queue data may already be indexed by execution order or grouped by query
	 * type. This helper normalizes both shapes so result processing can match
	 * SQLite output indexes to the original operation metadata and preserve the
	 * generated query type for cache/callback decisions.
	 *
	 * @param ?array<int|string, mixed> $queries Queue metadata produced by `execute_multiquery()`.
	 * @return array<int, array<string, mixed>> Ordered query metadata records with a `type` key.
	 */
	private static function queued_query_list(?array $queries): array {
		if($queries===null || $queries===[]){
			return [];
		}
		$first=$queries[array_key_first($queries)] ?? null;
		if(is_array($first) && array_key_exists('type', $first)){
			return array_values($queries);
		}
		$query_list=[];
		foreach($queries as $query_type=>$query_group){
			if(is_array($query_group)===false){
				continue;
			}
			foreach($query_group as $query){
				if(is_array($query)===false){
					continue;
				}
				$query['type']=$query['type'] ?? $query_type;
				$query_list[]=$query;
			}
		}
		return $query_list;
	}
	
	/**
	 * Flushes a named SQLite queue through the configured database file.
	 *
	 * Normalized queue entries are converted into concrete SQLite SQL for
	 * select, insert, update, count, and delete operations. Entries with bind
	 * variables use prepared statements; raw entries are appended to a
	 * semicolon-delimited batch. On structural SQL failure the queue can be
	 * restored once, Dataphyre can hydrate missing schema from definitions, and
	 * the same queue is retried without adding a compatibility shim.
	 *
	 * @param string $queue Queue name inside `self::$queued_queries`; the empty string is the default shutdown queue.
	 * @param bool $hydration_retry Internal guard that prevents recursive schema-hydration retries.
	 * @return null|bool `null` when the queue does not exist, `true` after successful execution and result processing, or `false` after execution and hydration recovery fail.
	 */
	public static function execute_multiquery(string $queue='', bool $hydration_retry=false) : null|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(!isset(self::$queued_queries[$queue]))return null;
		$queued_queries=self::$queued_queries[$queue];
		unset(self::$queued_queries[$queue]);
		$multipoint=false;
		$queries=[];
		$multi_query_string="";
		$index=0;
		$prepared_statements=[];
		foreach($queued_queries as $query_type=>$query_info_array){
			foreach($query_info_array as $query_info){
				if($query_type==='select'){
					$query_info['query']="SELECT {$query_info['select']} FROM {$query_info['location']} {$query_info['params']}";
				}
				elseif($query_type==='insert'){
					$query_info['fields_question_marks']=str_repeat("?,", count(explode(',', $query_info['fields'])));
					$query_info['fields_question_marks']=rtrim($query_info['fields_question_marks'], ',');
					$query_info['query']="INSERT INTO {$query_info['location']} ({$query_info['fields']}) VALUES ({$query_info['fields_question_marks']}) RETURNING ".$query_info['returning'];
				}
				elseif($query_type==='update'){
					$query_info['query']="UPDATE {$query_info['location']} SET {$query_info['fields']} {$query_info['params']}";
				}
				elseif($query_type==='count'){
					$query_info['query']="SELECT COUNT(*) as count FROM {$query_info['location']} {$query_info['params']}";
				}
				elseif($query_type==='delete'){
					$query_info['query']="DELETE FROM {$query_info['location']} {$query_info['params']}";
				}
				if(is_array($query_info['vars'])){
					$prepared_statements[$index]=['query'=>$query_info['query'], 'vars'=>$query_info['vars']];
				}
				else
				{
					$multi_query_string.=$query_info['query']."; ";
				}
				if(isset($query_info['multipoint']) && $query_info['multipoint']===true) $multipoint=true;
				$query_info['type']=$query_type;
				$queries[$index]=$query_info;
				$index++;
			}
		}
		$results=[];
		$dbms_cluster=DP_SQL_CFG['tables']['raw']['cluster'] ?? DP_SQL_CFG['default_cluster'];
		$endpoint=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'][0];
		$conn=self::connect_to_endpoint($endpoint, $dbms_cluster);
		if(!empty($prepared_statements)){
			if(!self::execute_prepared_statements($conn, $prepared_statements, $results, $dbms_cluster)){
				return self::retry_queue_after_hydration($queue, $queued_queries, $hydration_retry);
			}
		}
		else
		{
			if(!self::execute_multi_query_string($conn, $multi_query_string, $results, $dbms_cluster)){
				return self::retry_queue_after_hydration($queue, $queued_queries, $hydration_retry);
			}
		}
		self::process_results($results, $queries);
		return true;
	}

	/**
	 * Restores a failed queue and retries it after definition-driven hydration.
	 *
	 * This is the SQLite queue recovery path for missing tables or columns that
	 * Dataphyre can create from table definitions. The method clears the last
	 * SQL error before retrying so callers see the retried execution outcome
	 * rather than the initial structural failure.
	 *
	 * @param string $queue Queue name that failed execution.
	 * @param array<string, array<int, array<string, mixed>>> $queued_queries Original grouped queue payload.
	 * @param bool $hydration_retry Whether the current call is already the retry attempt.
	 * @return bool `true` only when hydration runs and the restored queue succeeds on its guarded retry.
	 */
	private static function retry_queue_after_hydration(string $queue, array $queued_queries, bool $hydration_retry): bool {
		if($hydration_retry===true || sql::hydrate_missing_structure_from_definition()===false){
			return false;
		}
		self::$queued_queries[$queue]=$queued_queries;
		sql::clear_last_query_error();
		return self::execute_multiquery($queue, true) === true;
	}
	
	/**
	 * Executes an immediate SQLite query outside the queue system.
	 *
	 * The query may use positional bind variables or run as raw SQL. Multipoint
	 * mode opens each configured endpoint as a SQLite file, compares result
	 * sets, and returns the odd result when a single endpoint disagrees;
	 * single-point mode logs execution failures and returns `false`. The
	 * `CALL_SQL_SIMPLE_SELECT` dialback can override the whole operation.
	 *
	 * @param string $dbms_cluster Cluster whose endpoints identify SQLite files for immediate reads.
	 * @param string $query Complete SQL query text.
	 * @param ?array<int, mixed> $vars Positional bind values, or `null` for raw execution.
	 * @param ?bool $associative `true` for associative rows, `false` for numeric rows.
	 * @param ?bool $multipoint Whether to compare all endpoints instead of reading the first endpoint.
	 * @return bool|array SQLite rows on success, or `false` when single-point execution fails.
	 */
	public static function sqlite_query(string $dbms_cluster, string $query, ?array $vars, ?bool $associative, ?bool $multipoint=true) : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null !== $early_return=core::dialback("CALL_SQL_SIMPLE_SELECT", ...func_get_args())) return $early_return;
		$execute_query=function($conn) use ($query, $vars, $associative){
			if(is_array($vars)){
				if(false===$stmt=$conn->prepare($query)){
					throw new \Exception('Query preparation failed: '.$conn->lastErrorMsg());
				}
				foreach($vars as $index=>$var){
					$stmt->bindValue($index + 1, $var);
				}
				if(false===$result=$stmt->execute()){
					throw new \Exception('Query execution failed: '.$conn->lastErrorMsg());
				}
				$query_result=[];
				if($result){
					while($row=$result->fetchArray($associative ? SQLITE3_ASSOC : SQLITE3_NUM)){
						$query_result[]=$row;
					}
				}
				$stmt->close();
				return $query_result;
			}
			else
			{
				if(false===$result=$conn->query($query)){
					throw new \Exception('Query execution failed: '.$conn->lastErrorMsg());
				}
				$query_result=[];
				while($row=$result->fetchArray($associative ? SQLITE3_ASSOC : SQLITE3_NUM)){
					$query_result[]=$row;
				}
				return $query_result;
			}
		};
		if($multipoint===true){
			$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
			$results=[];
			foreach($endpoints as $endpoint){
				$conn=new SQLite3($endpoint);
				$results[]=$execute_query($conn);
				$conn->close();
			}
			$result=array_values(array_filter($results, fn($r)=>count(array_filter($results, fn($x)=>$x===$r))===1))[0]??$results[0]; // Oddest one out algorithm (failure bias)
		}
		else
		{
			$endpoint=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'][0];
			$conn=new SQLite3($endpoint);
			try {
				$result=$execute_query($conn);
			} catch (\Throwable $exception){
				sql::log_query_error('SQLite', $dbms_cluster, $query, $vars, $exception);
				$result=false;
			} finally {
				$conn->close();
			}
		}
		return $result===false ? false : $result;
	}
	
	/**
	 * Runs a direct SQLite `SELECT` against the cluster's cached connection.
	 *
	 * The caller supplies the projection, table or view location, and trailing
	 * SQL clause separately so the shared SQL facade can assemble driver-specific
	 * reads. Empty result sets return `false`; non-associative reads collapse to
	 * the first numeric row; associative reads preserve the full row list.
	 *
	 * @param string $dbms_cluster Cluster whose cached SQLite3 handle should serve the read.
	 * @param string $select Projection expression placed after `SELECT`.
	 * @param string $location Table, view, or query location placed after `FROM`.
	 * @param ?string $params Optional trailing SQL such as `WHERE`, `ORDER BY`, or `LIMIT`.
	 * @param ?array<int, mixed> $vars Positional bind values for prepared reads, or `null` for raw reads.
	 * @param ?bool $associative `true` for associative rows; any other value returns the first numeric row.
	 * @return bool|array Selected row data in the requested shape, or `false` for empty or failed reads.
	 */
	public static function sqlite_select(string $dbms_cluster, string $select, string $location, ?string $params, ?array $vars, ?bool $associative) : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null !== $early_return=core::dialback("CALL_SQL_SIMPLE_SELECT", ...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		try{
			if(is_array($vars)){
				if(false===$stmt=$conn->prepare($query="SELECT ".$select." FROM ".$location." ".$params)){
					throw new \Exception('Query preparation failed: '.$conn->lastErrorMsg());
				}
				foreach($vars as $index=>$var){
					$stmt->bindValue($index+1, $var);
				}
				if(false===$result=$stmt->execute()){
					throw new \Exception('Query execution failed: '.$conn->lastErrorMsg());
				}
				$query_result=[];
				if($result){
					while($row=$result->fetchArray($associative ? SQLITE3_ASSOC : SQLITE3_NUM)){
						$query_result[]=$row;
					}
				}
				$stmt->close();
			}
			else
			{
				$result=$conn->query($query="SELECT ".$select." FROM ".$location." ".$params);
				if($result===false){
					throw new \Exception('Query execution failed: '.$conn->lastErrorMsg());
				}
				$query_result=[];
				while ($row=$result->fetchArray($associative ? SQLITE3_ASSOC : SQLITE3_NUM)){
					$query_result[]=$row;
				}
			}
		}catch(\Throwable $exception){
			sql::log_query_error('SQLite', $dbms_cluster, $query ?? "SELECT ".$select." FROM ".$location." ".$params, $vars, $exception);
			return false;
		}
		if(empty($query_result)){
			return false;
		}
		return $associative !== true ? $query_result[0] : $query_result;
	}
	
	/**
	 * Counts rows through a direct SQLite `COUNT(*)` query.
	 *
	 * The method always aliases the aggregate as `count`, supports positional
	 * binds for filtered counts, and treats a successful empty aggregate row as
	 * zero. SQL preparation or execution failures are logged with the cluster,
	 * generated query, and bind values before returning `false`.
	 *
	 * @param string $dbms_cluster Cluster whose cached SQLite3 handle should serve the count.
	 * @param string $location Table, view, or query location placed after `FROM`.
	 * @param string $params Optional trailing SQL filters and clauses.
	 * @param ?array<int, mixed> $vars Positional bind values, or `null` for raw count execution.
	 * @return int|bool Row count on successful execution, or `false` when SQLite reports a failure.
	 */
	public static function sqlite_count(string $dbms_cluster, string $location, string $params, ?array $vars) : int|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null !== $early_return=core::dialback("CALL_SQL_SIMPLE_COUNT", ...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		$query="SELECT COUNT(*) as count FROM ".$location." ".$params;
		try{
			if(is_array($vars)){
				if(false===$stmt=$conn->prepare($query)){
					throw new \Exception('Query preparation failed: '.$conn->lastErrorMsg());
				}
				foreach($vars as $index=>$var){
					$stmt->bindValue($index + 1, $var);
				}
				if(false===$result=$stmt->execute()){
					throw new \Exception('Query execution failed: '.$conn->lastErrorMsg());
				}
				$row=$result->fetchArray(SQLITE3_ASSOC);
				$stmt->close();
				return $row['count'] ?? 0; // Return count or 0 if not found
			}
			$result=$conn->querySingle($query, true);
		} catch (\Throwable $exception){
			sql::log_query_error('SQLite', $dbms_cluster, $query, $vars, $exception);
			return false;
		}
		if($result===false){
			sql::log_query_error('SQLite', $dbms_cluster, $query, $vars, new \RuntimeException('Query failed: '.$conn->lastErrorMsg()));
			return false;
		}
		return $result['count'] ?? 0; // Return count or 0 if not found
	}
	
	/**
	 * Applies a direct SQLite `UPDATE` and reports the affected row count.
	 *
	 * Table configuration decides whether the write fans out to every endpoint
	 * or stops after the first successful endpoint. Each endpoint failure is
	 * logged and the method succeeds when at least one endpoint applies the
	 * update, returning the highest affected-row count observed.
	 *
	 * @param string $dbms_cluster Cluster whose configured endpoints receive the write.
	 * @param string $location Table or update target.
	 * @param string $fields Assignment expression placed after `SET`.
	 * @param string $params Optional trailing SQL filters and clauses.
	 * @param array<int, mixed> $vars Positional bind values for the update statement.
	 * @return bool|int Maximum affected-row count from successful endpoints, or `false` when every endpoint fails.
	 */
	public static function sqlite_update(string $dbms_cluster, string $location, string $fields, string $params, array $vars) : bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null !== $early_return=core::dialback("CALL_SQL_SIMPLE_UPDATE", ...func_get_args())) return $early_return;
		$is_multipoint=DP_SQL_CFG['tables'][$location]['multipoint_writes'] ?? false;
		$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		$query="UPDATE ".$location." SET ".$fields." ".$params;
		$execute_update=function($conn) use ($query, $vars): int {
			if(is_array($vars)){
				if(false===$stmt=$conn->prepare($query)){
					throw new \Exception('Query preparation failed: '.$conn->lastErrorMsg());
				}
				foreach($vars as $index=>$value){
					$stmt->bindValue($index + 1, $value);
				}
				if(false===$result=$stmt->execute()){
					throw new \Exception('Query execution failed: '.$conn->lastErrorMsg());
				}
				$stmt->close();
				return max(0, $conn->changes());
			}
			else
			{
				if($conn->exec($query)===false){
					throw new \Exception('Query execution failed: '.$conn->lastErrorMsg());
				}
				return max(0, $conn->changes());
			}
		};
		$succeeded=0;
		$affected_rows=[];
		foreach($endpoints as $endpoint){
			$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint);
			try {
				$affected_rows[]=$execute_update($conn);
				$succeeded++;
			} catch (\Throwable $exception){
				sql::log_query_error('SQLite', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint) break;
		}
		if($succeeded >= 1){
			return max(0, ...$affected_rows);
		}
		return false;
	}
	
	/**
	 * Applies a direct SQLite `INSERT OR IGNORE` through configured endpoints.
	 *
	 * The helper builds a positional placeholder list from the provided values,
	 * binds them in field order, and returns whether SQLite accepted execution.
	 * Multipoint writes are controlled by table configuration; non-multipoint
	 * writes stop after the first attempted endpoint and reuse the cached
	 * cluster connection when available.
	 *
	 * @param string $dbms_cluster Cluster whose configured endpoints receive the insert.
	 * @param string $location Table receiving the inserted row.
	 * @param string $fields Comma-separated field list matching `$vars` order.
	 * @param array<int, mixed> $vars Positional values to bind into the insert.
	 * @return array|bool Legacy return contract allows arrays, but this implementation returns boolean execution status unless a dialback overrides it.
	 */
	public static function sqlite_insert(string $dbms_cluster, string $location, string $fields, array $vars) : array|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null !== $early_return=core::dialback("CALL_SQL_SIMPLE_INSERT", ...func_get_args())) return $early_return;
		$fields_question_marks=rtrim(str_repeat('?,', count($vars)), ',');
		$is_multipoint=DP_SQL_CFG['tables'][$location]['multipoint_writes'] ?? false;
		$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		$query="INSERT OR IGNORE INTO ".$location." (".$fields.") VALUES (".$fields_question_marks.")";
		$execute_insert=function($conn) use ($query, $vars){
			if(false===$stmt=$conn->prepare($query)){
				throw new \Exception('Query preparation failed: '.$conn->lastErrorMsg());
			}
			foreach($vars as $index=>$value){
				$stmt->bindValue($index+1, $value);
			}
			if(false===$result=$stmt->execute()){
				throw new \Exception('Query execution failed: '.$conn->lastErrorMsg());
			}
			$stmt->close();
			return $result !== false;
		};
		$result_key=true;
		foreach($endpoints as $endpoint){
			try{
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint);
				$result_key=$execute_insert($conn);
			}catch(\Throwable $exception){
				sql::log_query_error('SQLite', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint) break;
		}
		return $result_key;
	}
	
	/**
	 * Applies a direct SQLite `DELETE` and reports affected rows.
	 *
	 * Deletes are prepared even when no bind values are supplied, keeping error
	 * handling consistent with update and insert helpers. Table configuration
	 * controls multipoint fan-out, and success requires at least one endpoint to
	 * execute the delete; failed endpoints are logged with SQL context.
	 *
	 * @param string $dbms_cluster Cluster whose configured endpoints receive the delete.
	 * @param string $location Table or delete target.
	 * @param string $params Optional trailing SQL filters and clauses.
	 * @param ?array<int, mixed> $vars Positional bind values, or `null` when the delete has no placeholders.
	 * @return bool|int Maximum affected-row count from successful endpoints, or `false` when every endpoint fails.
	 */
	public static function sqlite_delete(string $dbms_cluster, string $location, string $params, ?array $vars) : bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null !== $early_return=core::dialback("CALL_SQL_SIMPLE_DELETE", ...func_get_args())) return $early_return;
		$succeeded=0;
		$affected_rows=[];
		$is_multipoint=DP_SQL_CFG['tables'][$location]['multipoint_writes'] ?? false;
		$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		$query="DELETE FROM ".$location." ".$params;
		$execute_delete=function($conn) use ($query, $vars): int {
			if(false===$stmt=$conn->prepare($query)){
				throw new \Exception('Query preparation failed: '.$conn->lastErrorMsg());
			}
			if(isset($vars)){
				foreach($vars as $index=>$value){
					$stmt->bindValue($index + 1, $value);
				}
			}
			if(false===$result=$stmt->execute()){
				throw new \Exception('Query execution failed: '.$conn->lastErrorMsg());
			}
			$stmt->close();
			return max(0, $conn->changes());
		};
		foreach($endpoints as $endpoint){
			try {
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint);
				$affected_rows[]=$execute_delete($conn);
				$succeeded++;
			} catch (\Throwable $exception){
				sql::log_query_error('SQLite', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint) break;
		}
		return $succeeded >= 1 ? max(0, ...$affected_rows) : false;
	}
	
}
