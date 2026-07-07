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
			foreach(postgresql_query_builder::$queued_queries as $queue=>$queue_data){
				try {
					postgresql_query_builder::execute_multiquery($queue);
				} catch (\Throwable $exception) {
					log_error("PostgreSQL Queued Query Execution Error", $exception);
				}
			}
		}while(!empty(postgresql_query_builder::$queued_queries));
	}catch(\Throwable $exception){
		\dataphyre_shutdown_log('Exception on Dataphyre SQL PostgreSQL shutdown callback', $exception);
	}
});

/**
 * PostgreSQL queue executor for Dataphyre SQL helper functions.
 *
 * The builder owns process-local PostgreSQL connections, queued query batches,
 * shutdown-time flushing, MySQL-style compatibility translation, result value
 * normalization, cache hydration/invalidation, and callback delivery for the
 * legacy PostgreSQL helper surface in this file.
 */
class postgresql_query_builder {
	
	public static $conns=[];
	public static $queued_queries=[];
	
	/**
	 * Translates the shared MySQL-like SQL helper dialect into PostgreSQL syntax.
	 *
	 * @param string $query SQL string using Dataphyre's shared placeholder/function conventions.
	 * @return string PostgreSQL-compatible SQL with numbered placeholders and translated functions.
	 */
	private static function mysql_compatibility_layer(string $query='') : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$index=0;
		$query=preg_replace_callback('/\?/', static function()use(&$index): string{return '$'.(++$index);}, $query);
		$query=preg_replace('/RAND\(\)/i', 'RANDOM()', $query);
		$query=str_ireplace('UNIX_TIMESTAMP()','NOW()', $query);
		$query=str_ireplace('UNIX_TIMESTAMP(','TO_TIMESTAMP(', $query);
		$query=preg_replace('/\bIFNULL\s*\(/i', 'COALESCE(', $query);
		$query=preg_replace('/\bNOW\s*\(\s*\)/i', 'CURRENT_TIMESTAMP', $query);
		$query=preg_replace('/\bLIMIT\s+(\d+)\s*,\s*(\d+)/i', 'LIMIT $2 OFFSET $1', $query);
		$query=preg_replace('/\bFROM_UNIXTIME\s*\(/i', 'TO_TIMESTAMP(', $query);
		$query=preg_replace('/!=/', '<>', $query);
		return $query;
	}
	
	/**
	 * Converts PostgreSQL scalar field values into PHP-friendly values in-place.
	 *
	 * @param array<string, mixed> $query_result Fetched associative row to mutate.
	 * @param object $result PostgreSQL result object used for field type lookup.
	 * @return void
	 */
	private static function normalize_pg_value(array &$query_result, object $result) : void{
		foreach($query_result as $key=>$value){
			$field_type=pg_field_type($result, pg_field_num($result, $key));
			if($field_type==='bool'){
				$query_result[$key]=$value==='t' ? true : false;
			}
			elseif($field_type==='int4' || $field_type==='int8'){
				$query_result[$key]=(int)$value;
			}
		}
	}
	
	/**
	 * Opens or reuses the configured PostgreSQL connection for a cluster.
	 *
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @return mixed Active PostgreSQL connection resource/object, or a dialback override.
	 */
	private static function connect_to_cluster(string $dbms_cluster) : mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_OPEN_MAIN_CONNECTION",...func_get_args())) return $early_return;
		$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		if(isset(self::$conns[$dbms_cluster]) && is_object(self::$conns[$dbms_cluster]))return self::$conns[$dbms_cluster];
		if(empty($endpoints))core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreSQL: No database server available.', $T='safemode');
		shuffle($endpoints);
		foreach($endpoints as $endpoint){
			self::connect_to_endpoint($endpoint, $dbms_cluster);
			if(is_object(self::$conns[$dbms_cluster]))break;
		}
		if(empty(self::$conns[$dbms_cluster]))core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreSQL: Failed initiating any PostgreSQL connection.', $T='safemode');
		return self::$conns[$dbms_cluster];
	}
	
	/**
	 * Attempts to connect one PostgreSQL endpoint and cache the successful connection.
	 *
	 * @param string $endpoint Hostname or address from the cluster endpoint list.
	 * @param ?string $dbms_cluster Cluster key used to read credentials and database name.
	 * @return object|bool PostgreSQL connection on success, or `false` when unavailable.
	 */
	private static function connect_to_endpoint(string $endpoint, ?string $dbms_cluster): object|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$dbms_cluster??=DP_SQL_CFG['default_cluster'];
		if(isset(self::$conns[$dbms_cluster]))return self::$conns[$dbms_cluster];
		if(!sql::is_server_available($endpoint)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="$endpoint is known as being unavailable, using next available server", $S="warning");
			return false;
		}
		$datacenter=DP_CORE_CFG['datacenter'];
		$dbms=DP_SQL_CFG['datacenters'][$datacenter]['dbms_clusters'][$dbms_cluster]['dbms'];
		$username=DP_SQL_CFG['datacenters'][$datacenter]['dbms_clusters'][$dbms_cluster]['dbms_username'];
		$database=DP_SQL_CFG['datacenters'][$datacenter]['dbms_clusters'][$dbms_cluster]['database_name'];
		$port=DP_SQL_CFG['datacenters'][$datacenter]['dbms_clusters'][$dbms_cluster]['dbms_port']??5432;
		$password=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['password'];
		$password??=core::get_password($endpoint);
		$conn_string='host='.self::conninfo_value((string)$endpoint)
			.' port='.self::conninfo_value((string)$port)
			.' dbname='.self::conninfo_value((string)$database)
			.' user='.self::conninfo_value((string)$username)
			.' password='.self::conninfo_value((string)$password)
			.' options='.self::conninfo_value('--client_encoding=UTF8 --timezone=UTC')
			.' connect_timeout=1';
		if(!$conn=pg_connect($conn_string)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed connecting to $endpoint", $S="warning");
			sql::flag_server_unavailable($endpoint);
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="PostgreSQL connection to $endpoint successful");
		return self::$conns[$dbms_cluster]=$conn;
	}

	/**
	 * Escapes one PostgreSQL connection-info value for a libpq connection string.
	 *
	 * @param string $value Raw connection-info value.
	 * @return string Single-quoted and escaped connection-info value.
	 */
	private static function conninfo_value(string $value): string {
		return "'".str_replace(["\\", "'"], ["\\\\", "\\'"], $value)."'";
	}

	/**
	 * Executes queued prepared statements and stores normalized results by queue index.
	 *
	 * Write batches run inside an explicit transaction. Row results are fetched as
	 * associative arrays and normalized through `normalize_pg_value()`; write-only
	 * statements return affected-row counts.
	 *
	 * @param object $conn Active PostgreSQL connection.
	 * @param array<int, array{query:string,vars:array}> $prepared_statements Prepared query payloads.
	 * @param array<int, mixed> $results Results written by query index.
	 * @param string $dbms_cluster Cluster key for query-error logging.
	 * @return bool `true` when every prepared statement completed.
	 */
	private static function execute_prepared_statements(object $conn, array $prepared_statements, array &$results, string $dbms_cluster='n/a'): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$has_write=sql::query_has_write(serialize($prepared_statements));
		try{
			if($has_write && !pg_query($conn, "BEGIN")){
				throw new \Exception("Failed initiating transaction");
			}
			foreach($prepared_statements as $index=>$statement){
				$statement_name='stmt_'.bin2hex(random_bytes(6));
				$query=self::mysql_compatibility_layer($statement['query']);
				if(!pg_prepare($conn, $statement_name, $query)){
					throw new \Exception("Preparation of statement failed: ".pg_last_error($conn));
				}
				if(!$result=pg_execute($conn, $statement_name, $statement['vars'])){
					throw new \Exception("Execution of prepared statement failed: ".pg_last_error($conn));
				}
				if($result instanceof \PgSql\Result){
					if(pg_num_fields($result)===0){
						$results[$index]=max(0, pg_affected_rows($result));
					}
					else
					{
						$rows=pg_fetch_all($result);
						if($rows===false){
							$results[$index]=[];
						}
						else
						{
							foreach($rows as &$row){
								self::normalize_pg_value($row, $result);
							}
							$results[$index]=$rows;
						}
					}
				}
			}
			if($has_write && !pg_query($conn, "COMMIT")){
				throw new \Exception("Failed commiting transaction: ".pg_last_error($conn));
			}
		}catch(\Throwable $exception){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query has failed and will be rolled back", "warning");
			if($has_write && !pg_query($conn, "ROLLBACK")){
				throw new \Exception("Rollback failed: ".pg_last_error($conn));
			}
			sql::log_query_error('PostgreSQL', $dbms_cluster, $query, $statement['vars'] ?? [], $exception);
			return false;
		}
		return true;
	}

	/**
	 * Executes a raw multi-query string as sequential PostgreSQL statements.
	 *
	 * The shared query text is split on semicolons, translated through the
	 * compatibility layer, executed with `pg_send_query()`, and wrapped in a
	 * transaction when any statement appears to write.
	 *
	 * @param object $conn Active PostgreSQL connection.
	 * @param string $multi_query_string Semicolon-delimited SQL batch.
	 * @param array<int, mixed> $results Results written by result sequence.
	 * @param string $dbms_cluster Cluster key for query-error logging.
	 * @return bool `true` when the entire batch completed.
	 */
	private static function execute_multi_query_string(object $conn, string $multi_query_string, array &$results, string $dbms_cluster='n/a'): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$queries=explode(";", $multi_query_string);
		$has_write=sql::query_has_write(serialize($multi_query_string));
		$index=0;
		try{
			if($has_write && !pg_query($conn, "BEGIN")){
				throw new \Exception("Failed initiating transaction");
			}
			foreach($queries as $query){
				$query=trim($query);
				if(empty($query))continue;
				$query=self::mysql_compatibility_layer($query);
				if(!pg_send_query($conn, $query)){
					throw new \Exception("Query failed: ".pg_last_error($conn));
				}
				while($result=pg_get_result($conn)){
					if($result){
						if($error=pg_result_error($result)){
							if(!pg_free_result($result)){
								throw new \Exception("Failed freeing result: ".pg_last_error($conn));
							}
							throw new \Exception("Query failed: ".pg_last_error($conn));
						}
						$fetched_results=pg_fetch_all($result, PGSQL_ASSOC);
						self::normalize_pg_value($fetched_results, $result);
						$results[$index]=$fetched_results?$fetched_results:[];
						pg_free_result($result);
					}
					$index++;
				}
			}
			if($has_write && !pg_query($conn, "COMMIT")){
				throw new \Exception("Failed commiting transaction: ".pg_last_error($conn));
			}
		}catch(\Throwable $exception){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query has failed and will be rolled back", "warning");
			if($has_write && !pg_query($conn, "ROLLBACK")){
				throw new \Exception("Rollback failed: ".pg_last_error($conn));
			}
			sql::log_query_error('PostgreSQL', $dbms_cluster, $query, [], $exception);
			return false;
		}
		return true;
	}
	
	/**
	 * Applies query results to cache and callback side effects.
	 *
	 * Empty row sets normalize to `false`, non-associative selects collapse to the
	 * first row, count queries become integers, cacheable reads hydrate cache, and
	 * successful writes invalidate configured cache regions before callbacks run.
	 *
	 * @param ?array<int, mixed> $results Raw execution results by query index.
	 * @param ?array<string|int, mixed> $queries Queued query metadata in flat or grouped form.
	 * @return void
	 */
	private static function process_results(?array $results, ?array $queries): void {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null);
		$query_list=self::queued_query_list($queries);
		foreach(($results ?? []) as $index=>$result){
			$query=$query_list[$index] ?? null;
			if(!$query){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Skipping invalid query at index $index", $S="warning");
				continue;
			}
			$associative=$query['associative'] ?? null;
			if(is_array($result)){
				$result=$result===[] ? false : ($associative === false ? $result[0] : $result);
			}
			elseif($result!==true && !is_int($result) && !is_string($result)){
				$result=false;
			}
			if($query['type']==='count' && is_array($result) && isset($result[0]['count'])){
				$result=(int)$result[0]['count'];
			}
			if(!empty($query['caching']) && isset($query['hash'])){
				sql::cache_query_result($query['location'], $query['hash'], $result, $query['caching']);
			}
			else
			{
				if($result !== false && !empty($query['clear_cache'])){
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
	 * Flattens queued query metadata into execution order.
	 *
	 * @param ?array<string|int, mixed> $queries Queue metadata, either flat or grouped by query type.
	 * @return array<int, array<string, mixed>> Ordered query metadata.
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
	 * Executes and clears one queued PostgreSQL query batch.
	*
	 * The queue is converted into SQL text, split into prepared statements when
	 * bound variables are present, optionally routed to every configured endpoint
	 * for multipoint writes, and post-processed for cache/callback side effects.
	*
	 * @param string $queue Queue name; empty string is the default queue.
	 * @param bool $hydration_retry Whether this execution is already retrying after cluster hydration.
	 * @return null|bool `null` when the queue does not exist, otherwise execution success.
	 */
	public static function execute_multiquery(string $queue='', bool $hydration_retry=false) : null|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__,$T=null,$S='function_call',$A=null); // Log the function call
		if(!isset(self::$queued_queries[$queue]))return null;
		$queued_queries=self::$queued_queries[$queue];
		unset(self::$queued_queries[$queue]);
		$multipoint=false;
		$queries=[];
		$prepared_statements=[];
		$multi_query_string="";
		$index=0;
		foreach($queued_queries as $query_type=>$query_info_array){
			foreach($query_info_array as $query_info){
				switch($query_type){
					case 'select':
						$query_info['query']="SELECT {$query_info['select']} FROM {$query_info['location']} {$query_info['params']}";
						break;
					case 'insert':
						$placeholders=array_map(fn($i)=>'$'.$i, range(1, substr_count($query_info['fields'], ',')+1));
						$query_info['query']="INSERT INTO {$query_info['location']} ({$query_info['fields']}) VALUES (".implode(", ", $placeholders).") ON CONFLICT DO NOTHING RETURNING ".$query_info['returning'];
						break;
					case 'update':
						$query_info['query']="UPDATE {$query_info['location']} SET {$query_info['fields']} {$query_info['params']}";
						break;
					case 'count':
						$query_info['query']="SELECT COUNT(*) as count FROM {$query_info['location']} {$query_info['params']}";
						break;
					case 'delete':
						$query_info['query']="DELETE FROM {$query_info['location']} {$query_info['params']}";
						break;
				}
				if(isset($query_info['vars']) && is_array($query_info['vars'])){
					$prepared_statements[$index]=['query'=>$query_info['query'], 'vars'=>$query_info['vars']];
				}
				else
				{
					$multi_query_string.=$query_info['query']."; ";
				}
				if(isset($query_info['multipoint']) && $query_info['multipoint']===true)$multipoint=true;
				$query_info['type']=$query_type;
				$queries[$index]=$query_info;
				$index++;
			}
		}
		$results=[];
		$dbms_cluster=DP_SQL_CFG['tables']['raw']['cluster'] ?? DP_SQL_CFG['default_cluster'];
		if($multipoint===true){
			$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
			if(!empty($prepared_statements)){
				foreach($endpoints as $endpoint){
					$conn=self::connect_to_endpoint($endpoint, $dbms_cluster);
					if(!self::execute_prepared_statements($conn, $prepared_statements, $results, $dbms_cluster)){
						return self::retry_queue_after_hydration($queue, $queued_queries, $hydration_retry);
					}
				}
			}
			else
			{
				foreach($endpoints as $endpoint){
					$conn=self::connect_to_endpoint($endpoint, $dbms_cluster);
					if(!self::execute_multi_query_string($conn, $multi_query_string, $results, $dbms_cluster)){
						return self::retry_queue_after_hydration($queue, $queued_queries, $hydration_retry);
					}
				}
			}
		}
		else
		{
			$conn=self::connect_to_cluster($dbms_cluster);
			if(!empty($prepared_statements)){
				if(!self::execute_prepared_statements($conn,$prepared_statements,$results, $dbms_cluster)){
					return self::retry_queue_after_hydration($queue, $queued_queries, $hydration_retry);
				}
			}
			else
			{
				if(!self::execute_multi_query_string($conn,$multi_query_string,$results, $dbms_cluster)){
					return self::retry_queue_after_hydration($queue, $queued_queries, $hydration_retry);
				}
			}
		}
		self::process_results($results, $queries);
		return true;
	}

	/**
	 * Requeues a failed batch after attempting schema hydration once.
	 *
	 * @param string $queue Queue name that failed.
	 * @param array<string, mixed> $queued_queries Original queued query payload.
	 * @param bool $hydration_retry Whether the caller is already in a hydration retry.
	 * @return bool `true` when the retry completed successfully.
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
	 * Executes a raw PostgreSQL query immediately.
	*
	 * The shared helper SQL dialect is translated before execution. Bound values
	 * use prepared statements; unbound SQL uses `pg_query()`. Multipoint reads
	 * execute against each endpoint and use the oddest-result failure-bias
	 * selection.
	*
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @param string $query SQL query string.
	 * @param ?array<int, mixed> $vars Bound values, or `null`.
	 * @param ?bool $associative `true` for all rows, otherwise first row.
	 * @param ?bool $multipoint Whether to query all endpoints.
	 * @return bool|array Row data, empty array for no row payload, or `false` on failure.
	 */
	public static function postgresql_query(string $dbms_cluster, string $query, ?array $vars, ?bool $associative, ?bool $multipoint=true): bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_SELECT", ...func_get_args())) return $early_return;
		$execute_query=function($conn) use ($query, $vars, $associative, $dbms_cluster){
			$result=false;
			try{
				$query=self::mysql_compatibility_layer($query);
				if(is_array($vars)){
					$statement_name='stmt_'.bin2hex(random_bytes(6));
					if(!$stmt=pg_prepare($conn, $statement_name, $query)){
						throw new \Exception("Failed to prepare statement: ".pg_last_error($conn));
					}
					if(!$result=pg_execute($conn, $statement_name, $vars)){
						throw new \Exception("Failed to execute statement: ".pg_last_error($conn));
					}
				}
				else
				{
					if(!$result=pg_query($conn, $query)){
						throw new \Exception("Query failed: ".pg_last_error($conn));
					}
				}
			}catch(\Throwable $exception){
				sql::log_query_error('PostgreSQL', $dbms_cluster, $query, $vars, $exception);
			}
			return $result;
		};
		if($multipoint===true){
			$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
			$results=[];
			foreach($endpoints as $endpoint){
				$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
				$results[]=$execute_query($conn);
			}
			$result=array_values(array_filter($results, fn($r)=>count(array_filter($results, fn($x)=>$x===$r))===1))[0]??$results[0]; // Oddest one out algorithm (failure bias)
		}
		else
		{
			$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
			$result=$execute_query($conn);
		}
		if($result===false){
			return false;
		}
		$query_result=[];
		if($result instanceof \PgSql\Result){
			if($associative!==true){
				if(false!==$row=pg_fetch_assoc($result)){
					self::normalize_pg_value($row, $result);
					$query_result=$row;
				}
			}
			else
			{
				while($row=pg_fetch_assoc($result)){
					self::normalize_pg_value($row, $result);
					$query_result[]=$row;
				}
			}
			pg_free_result($result);
		}
		return $query_result;
	}
	
	/**
	 * Executes an immediate PostgreSQL `SELECT`.
	*
	 * The select list may be a string or column array. Query text is translated
	 * through the compatibility layer and returned rows have PostgreSQL booleans
	 * and integers normalized to PHP values.
	*
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @param string|array<int, string> $select SQL select-list fragment or column list.
	 * @param string $location Table or location fragment.
	 * @param ?string $params WHERE/GROUP/ORDER/LIMIT fragment.
	 * @param ?array<int, mixed> $vars Bound values, or `null`.
	 * @param ?bool $associative `true` for all rows, otherwise first row.
	 * @return bool|array Row data, or `false` when no rows or execution fails.
	 */
	public static function postgresql_select(string $dbms_cluster, string|array $select, string $location, ?string $params, ?array $vars, ?bool $associative): bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_SELECT", ...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		$query_result=[];
		$result=false;
		if(is_array($select)){
			$select=implode(',', array_map(static fn(mixed $column): string => (string)$column, $select));
		}
		$query="SELECT ".$select." FROM ".$location." ".$params;
		try{
			$query=self::mysql_compatibility_layer($query);
			if(is_array($vars) && count($vars)>0){
				$statement_name='stmt_'.bin2hex(random_bytes(6));
				if(!$stmt=pg_prepare($conn, $statement_name, $query)){
					throw new \Exception("Failed to prepare statement: ($query) ".pg_last_error($conn));
				}
				if(!$result=pg_execute($conn, $statement_name, $vars)){
					throw new \Exception("Failed to execute statement: ($query) ".pg_last_error($conn));
				}
			}
			else
			{
				if(!$result=pg_query($conn, $query)){
					throw new \Exception("Query failed: ($query)".pg_last_error($conn));
				}
			}
		}catch(\Throwable $exception){
			sql::log_query_error('PostgreSQL', $dbms_cluster, $query, $vars ?? [], $exception);
		}
		if($result===false){
			return false;
		}
		if($result instanceof \PgSql\Result){
			if($associative!==true){
				if(false!==$query_result=pg_fetch_assoc($result)){
					self::normalize_pg_value($query_result, $result);
				}
			}
			else
			{
				while($row=pg_fetch_assoc($result)){
					self::normalize_pg_value($row, $result);
					$query_result[]=$row;
				}
			}
		}
		return $query_result ?: false;
	}

	/**
	 * Executes an immediate PostgreSQL `COUNT(*)`.
	 *
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @param string $location Table or location fragment.
	 * @param string $params WHERE/GROUP fragment.
	 * @param ?array<int, mixed> $vars Bound values, or `null`.
	 * @return bool|int Count value, or `false` on failure.
	 */
	public static function postgresql_count(string $dbms_cluster, string $location, string $params, ?array $vars): bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_COUNT", ...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		$count=false;
		$result=false;
		$query="SELECT COUNT(*) as count FROM ".$location." ".$params;
		try{
			$query=self::mysql_compatibility_layer($query);
			if(is_array($vars) && count($vars)>0){
				$statement_name='stmt_'.bin2hex(random_bytes(6));
				if(!$stmt=pg_prepare($conn, $statement_name, $query)){
					throw new \Exception("Failed to prepare statement: ".pg_last_error($conn));
				}
				if(!$result=pg_execute($conn, $statement_name, $vars)){
					throw new \Exception("Failed to execute statement: ".pg_last_error($conn));
				}
			}
			else
			{
				if(!$result=pg_query($conn, $query)){
					throw new \Exception("Query failed: ".pg_last_error($conn));
				}
			}
		}catch(\Throwable $exception){
			sql::log_query_error('PostgreSQL', $dbms_cluster, $query, $vars, $exception);
		}
		if($result===false){
			return $count;
		}
		if($result instanceof \PgSql\Result){
			if($row=pg_fetch_assoc($result)){
				$count=$row['count'];
			}
		}
		return $count;
	}
	
	/**
	 * Executes an immediate PostgreSQL `UPDATE`.
	*
	 * Tables configured for multipoint writes update every endpoint; otherwise the
	 * first reachable endpoint is used. SQL fragments are translated before
	 * prepared execution.
	*
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @param string $location Table or location fragment.
	 * @param string $fields SET-clause field fragment.
	 * @param string $params WHERE/LIMIT fragment.
	 * @param array<int, mixed> $vars Bound values.
	 * @return bool|int Maximum affected-row count across successful endpoints, or `false`.
	 */
	public static function postgresql_update(string $dbms_cluster, string $location, string $fields, string $params, array $vars): bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_UPDATE", ...func_get_args())) return $early_return;
		$succeeded=0;
		$affected_rows=[];
		$is_multipoint=DP_SQL_CFG['tables'][$location]['multipoint_writes']??false;
		$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		foreach($endpoints as $endpoint){
			try{
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
				$query="UPDATE ".$location." SET ".$fields." ".$params;
				$query=self::mysql_compatibility_layer($query);
				$statement_name='stmt_'.bin2hex(random_bytes(6));
				if(!$stmt=pg_prepare($conn, $statement_name, $query)){
					throw new \Exception("Failed to prepare statement: ".pg_last_error($conn));
				}
				if(!$result=pg_execute($conn, $statement_name, $vars)){
					throw new \Exception("Failed to execute statement: ".pg_last_error($conn));
				}
				$affected_rows[]=pg_affected_rows($result);
				pg_free_result($result);
				$succeeded++;
			}catch(\Throwable $exception){
				sql::log_query_error('PostgreSQL', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint) break;
		}
		return $succeeded >= 1 ? max(0, ...$affected_rows) : false;
	}
	
	/**
	 * Executes an immediate PostgreSQL `INSERT ... ON CONFLICT DO NOTHING RETURNING`.
	*
	 * Tables configured for multipoint writes insert on every endpoint; otherwise
	 * the first reachable endpoint is used. UUID unique-constraint failures are
	 * retried recursively up to the supplied retry count.
	*
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @param string $location Table or location fragment.
	 * @param string $fields Comma-separated insert field list.
	 * @param array<int, mixed> $vars Bound values.
	 * @param string $returning RETURNING fragment.
	 * @param int $retry_count Remaining retries for UUID collision handling.
	 * @return array|bool Returned row, or `false` on failure/no returned row.
	 */
	public static function postgresql_insert(string $dbms_cluster, string $location, string $fields, array $vars, string $returning='*', int $retry_count=3): array|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_INSERT", ...func_get_args())) return $early_return;
		$is_multipoint=DP_SQL_CFG['tables'][$location]['multipoint_writes']??false;
		$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		$result_key=false;
		foreach($endpoints as $endpoint){
			try{
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
				$placeholders=array_map(function($k){ return '$'.($k+1); }, array_keys($vars));
				$query="INSERT INTO ".$location." (".$fields.") VALUES (".implode(", ", $placeholders).") ON CONFLICT DO NOTHING RETURNING ".$returning;
				$query=self::mysql_compatibility_layer($query);
				$statement_name='stmt_'.bin2hex(random_bytes(6));
				if(!$stmt=pg_prepare($conn, $statement_name, $query)){
					throw new \Exception("Failed to prepare statement: ".pg_last_error($conn));
				}
				if(!$result=pg_execute($conn, $statement_name, $vars)){
					throw new \Exception("Failed to execute statement: ".pg_last_error($conn));
				}
				if($result instanceof \PgSql\Result){
					if($row=pg_fetch_assoc($result)){
						$result_key=$row;
					}
				}
			}catch(\Throwable $exception){
				if($retry_count>0 && strpos($exception->getMessage(), 'unique constraint')!==false && strpos($exception->getMessage(), 'uuid')!==false){
					$retry_count--;
					log_error("Retrying insert due to UUID constraint violation. Retries left: {$retry_count}", $exception);
					return self::postgresql_insert($dbms_cluster, $location, $fields, $vars, $returning, $retry_count);
				}
				sql::log_query_error('PostgreSQL', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint)break;
		}
		return $result_key;
	}
	
	/**
	 * Executes an immediate PostgreSQL `DELETE`.
	*
	 * Tables configured for multipoint writes delete on every endpoint; otherwise
	 * the first reachable endpoint is used. Bound deletes use prepared statements.
	*
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @param string $location Table or location fragment.
	 * @param string $params WHERE/LIMIT fragment.
	 * @param ?array<int, mixed> $vars Bound values, or `null`.
	 * @return bool|int Maximum affected-row count across successful endpoints, or `false`.
	 */
	public static function postgresql_delete(string $dbms_cluster, string $location, string $params, ?array $vars): bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_DELETE", ...func_get_args())) return $early_return;
		$succeeded=0;
		$affected_rows=[];
		$is_multipoint=DP_SQL_CFG['tables'][$location]['multipoint_writes'] ?? false;
		$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		foreach($endpoints as $endpoint){
			try{
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
				$query="DELETE FROM ".$location." ".$params;
				$query=self::mysql_compatibility_layer($query);
				if(!empty($vars)){
					$query=preg_replace_callback('/\?/', function($matches){static $index=0;return'$'.(++$index);}, $query);
					$statement_name='stmt_'.bin2hex(random_bytes(6));
					if(!$stmt=pg_prepare($conn, $statement_name, $query)){
						throw new \Exception("Failed to prepare statement: ".pg_last_error($conn));
					}
					if(!$result=pg_execute($conn, $statement_name, $vars)){
						throw new \Exception("Failed to execute statement: ".pg_last_error($conn));
					}
					$affected_rows[]=pg_affected_rows($result);
					pg_free_result($result);
					$succeeded++;
				}
				else
				{
					if(!$result=pg_query($conn, $query)){
						throw new \Exception("Query failed: ".pg_last_error($conn));
					}
					$affected_rows[]=pg_affected_rows($result);
					pg_free_result($result);
					$succeeded++;
				}
			}catch(\Throwable $exception){
				sql::log_query_error('PostgreSQL', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint) break;
		}
		return $succeeded>=1 ? max(0, ...$affected_rows) : false;
	}
	
}
