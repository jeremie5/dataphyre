<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;
use Mysqli;

register_shutdown_function(function(){
	try{
		do{
			foreach(mysql_query_builder::$queued_queries as $queue=>$queue_data){
				try {
					mysql_query_builder::execute_multiquery($queue);
				} catch (\Throwable $exception) {
					log_error("MySQL Queued Query Execution Error", $exception);
				}
			}
		}while(!empty(mysql_query_builder::$queued_queries));
	}catch(\Throwable $exception){
		\dataphyre_shutdown_log('Exception on Dataphyre SQL MySQL shutdown callback', $exception);
	}
});

/**
 * MySQL queue executor for Dataphyre SQL helper functions.
 *
 * The builder owns process-local MySQLi connections, queued query batches,
 * shutdown-time flushing, prepared-statement execution, multi-query execution,
 * cache hydration/invalidation, and callback delivery for the legacy SQL helper
 * surface in this file.
 */
class mysql_query_builder {
	
	public static $conns=[];
	public static $queued_queries=[];
	
	/**
	 * Opens or reuses the configured MySQL connection for a cluster.
	 *
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @return object Active MySQLi connection.
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
	 * Attempts to connect one MySQL endpoint and cache the successful connection.
	 *
	 * @param string $endpoint Hostname or address from the cluster endpoint list.
	 * @param string $dbms_cluster Cluster key used to read credentials and database name.
	 * @return object|false MySQLi connection on success, or `false` when the endpoint is unavailable.
	 */
	private static function connect_to_endpoint(string $endpoint, string $dbms_cluster='default') : object {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(isset(self::$conns[$dbms_cluster])){
			return	self::$conns[$dbms_cluster];
		}
		if(!sql::is_server_available($endpoint)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="$endpoint is known as being unavailable, using next available server", $S="warning");
			return false;
		}
		if(!$conn=\mysqli_init()){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed mysql init", $S="warning");
			sql::flag_server_unavailable($endpoint);
			return false;
		}
		if(!$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 0.5)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed setting timeout limit", $S="warning");
			sql::flag_server_unavailable($endpoint);
			return false;
		}
		$username=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms_username'];
		$database=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['database_name'];
		$password=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['password'];
		$password??=core::get_password($endpoint);
		if(!$conn->real_connect($endpoint, $username, $password, $database)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed connecting to $endpoint", $S="warning");
			sql::flag_server_unavailable($endpoint);
			return false;
		}
		if(!$conn->set_charset("utf8mb4")){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed setting charset to utf8m4", $S="warning");
			sql::flag_server_unavailable($endpoint);
			return false;
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="MySQL connection to $endpoint successful");
		return self::$conns[$dbms_cluster]=$conn;
	}
	
	/**
	 * Executes queued prepared statements and stores normalized results by queue index.
	 *
	 * Write batches run inside a transaction. Result-set statements return
	 * associative rows, inserts return insert ids when available, and other writes
	 * return affected-row counts.
	 *
	 * @param object $conn Active MySQLi connection.
	 * @param array<int, array{query:string,vars:array}> $prepared_statements Prepared query payloads.
	 * @param array<int, mixed> $results Results written by query index.
	 * @param string $dbms_cluster Cluster key for query-error logging.
	 * @return bool `true` when every prepared statement completed.
	 */
	private static function execute_prepared_statements(object $conn, array $prepared_statements, array &$results, string $dbms_cluster='n/a') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index=0;
		$has_write=sql::query_has_write(serialize($prepared_statements));
		try{
			if($has_write)$conn->begin_transaction();
			foreach($prepared_statements as $statement){
				$stmt=$conn->prepare($statement['query']);
				if($stmt===false){
					throw new \RuntimeException('Query failed: '.$conn->error);
				}
				$stmt->bind_param(str_repeat('s', count($statement['vars'])), ...$statement['vars']);
				$stmt->execute();
				if($stmt->field_count>0){
					$result=$stmt->get_result();
					$results[$index]=$result instanceof \mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
				}
				elseif(!empty($result_key=$stmt->insert_id)){
					$results[$index]=$result_key;
				}
				else
				{
					$results[$index]=max(0, $stmt->affected_rows);
				}
				$index++;
				$stmt->close();
			}
			if($has_write)$conn->commit();
		}catch(\Throwable $exception){
			if($has_write)$conn->rollback();
			sql::log_query_error('MySQLi', $dbms_cluster, $statement['query'], $statement['vars'], $exception);
			return false;
		}
		return true;
	}
	
	/**
	 * Executes a raw multi-query string and stores result sets by sequence index.
	 *
	 * Write batches run inside a transaction. This path is used only for queued
	 * statements that do not provide bound variables.
	 *
	 * @param object $conn Active MySQLi connection.
	 * @param string $multi_query_string Semicolon-delimited SQL batch.
	 * @param array<int, mixed> $results Results written by result-set index.
	 * @param string $dbms_cluster Cluster key for query-error logging.
	 * @return bool `true` when the entire multi-query batch completed.
	 */
	private static function execute_multi_query_string(object $conn, string $multi_query_string, array &$results, string $dbms_cluster='n/a') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index=0;
		$has_write=sql::query_has_write(serialize($multi_query_string));
		try{
			if($has_write)$conn->begin_transaction();
			if(!$conn->multi_query($multi_query_string)){
				throw new \RuntimeException('Query failed: '.$conn->error);
			}
			do{
				$result=$conn->store_result();
				if($result)$results[$index]=$result->fetch_all(MYSQLI_ASSOC);
				if($result)$result->free();
				$index++;
			}while($conn->more_results() && $conn->next_result());
			if($has_write)$conn->commit();
			$conn->close();
		}catch(\Throwable $exception){
			if($has_write)$conn->rollback();
			sql::log_query_error('MySQLi', $dbms_cluster, $multi_query_string, [], $exception);
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
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		$query_list=self::queued_query_list($queries);
		foreach(($results ?? []) as $index=>$result){
			$query=$query_list[$index] ?? null;
			if(!$query){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Skipping invalid query at index $index");
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
	 * Executes and clears one queued MySQL query batch.
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
						$query_info['fields_question_marks']=explode(',', $query_info['fields']);
						$query_info['fields_question_marks']=str_repeat("?,", count($query_info['fields_question_marks']));
						$query_info['fields_question_marks']=rtrim($query_info['fields_question_marks'],',');
						$query_info['query']="INSERT {$query_info['ignore']} INTO {$query_info['location']} ({$query_info['fields']}) VALUES ({$query_info['fields_question_marks']}) RETURNING ".$query_info['returning'];
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
			self::connect_to_cluster($dbms_cluster);
			if(!empty($prepared_statements)){
				if(!self::execute_prepared_statements(self::$conns[$dbms_cluster], $prepared_statements, $results, $dbms_cluster)){
					return self::retry_queue_after_hydration($queue, $queued_queries, $hydration_retry);
				}
			}
			else
			{
				if(!self::execute_multi_query_string(self::$conns[$dbms_cluster], $multi_query_string, $results, $dbms_cluster)){
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
	 * Executes a raw MySQL query immediately.
	*
	 * Bound variables use prepared statements. Unbound SQL may contain multiple
	 * statements; only the first result set is returned. Multipoint reads execute
	 * against each endpoint and use the oddest-result failure-bias selection.
	*
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @param string $query SQL query string.
	 * @param ?array<int, mixed> $vars Bound values, or `null` for raw execution.
	 * @param ?bool $associative `true` for all rows, otherwise first row.
	 * @param ?bool $multipoint Whether to query all endpoints.
	 * @return bool|array `true` for non-row success, row data for reads, or `false` on failure.
	 */
	public static function mysql_query(string $dbms_cluster, string $query, ?array $vars, ?bool $associative, ?bool $multipoint=true) : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_SELECT",...func_get_args())) return $early_return;
		$execute_query=function($conn) use ($query, $vars) {
			if(is_array($vars)){
				$datatypes=str_repeat("s", count($vars));
				$stmt=$conn->prepare($query);
				if($stmt===false){
					throw new \RuntimeException('Query failed: '.$conn->error);
				}
				$stmt->bind_param($datatypes, ...$vars);
				$stmt->execute();
				$result=$stmt->get_result();
				$stmt->close();
				return $result === false ? true : $result;
			}
			else
			{
				if(mysqli_multi_query($conn, $query)){
					do {
						if ($result=mysqli_store_result($conn)) {
							if(!isset($first_result)){
								$first_result=$result;
							}
							else
							{
								mysqli_free_result($result);
							}
						}
					} while (mysqli_next_result($conn));
					return $first_result ?? true;
				}
				throw new \RuntimeException('Query failed: '.mysqli_error($conn));
			}
		};
		if($multipoint === true){
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
			try{
				$result=$execute_query($conn);
			}catch(\Throwable $exception){
				sql::log_query_error('MySQLi', $dbms_cluster, $query, $vars, $exception);
			}
		}
		if($result===true){
			return true;
		}
		if($result === false || !($result instanceof mysqli_result)){
			return false;
		}
		$query_result=[];
		if($associative!==true){
			$query_result=$result->fetch_assoc();
		}
		else
		{
			while($row=$result->fetch_assoc()){
				$query_result[]=$row;
			}
		}
		return $query_result;
	}
	
	/**
	 * Executes an immediate MySQL `SELECT`.
	*
	 * The caller supplies the select list, table/location expression, optional
	 * parameter clause, and optional bound values. Empty result sets return `false`.
	*
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @param string $select SQL select-list fragment.
	 * @param string $location Table or location fragment.
	 * @param ?string $params WHERE/GROUP/ORDER/LIMIT fragment.
	 * @param ?array<int, mixed> $vars Bound values, or `null`.
	 * @param ?bool $associative `true` for all rows, otherwise first row.
	 * @return bool|array Row data, or `false` when no rows or execution fails.
	 */
	public static function mysql_select(string $dbms_cluster, string $select, string $location, ?string $params, ?array $vars, ?bool $associative) : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_SELECT",...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		$query_result=false;
		try{
			if(is_array($vars)){
				$datatypes=str_repeat("s", count($vars));
				$stmt=$conn->prepare($query="SELECT ".$select." FROM ".$location." ".$params);
				if($stmt===false){
					throw new \RuntimeException('Query failed: '.$conn->error);
				}
				$stmt->bind_param($datatypes, ...$vars);
				$stmt->execute();
				$result=$stmt->get_result();
				$stmt->close();
			}
			else
			{
				$result=mysqli_query($conn,$query="SELECT ".$select." FROM ".$location." ".$params);
			}
			if($result===false){
				throw new \RuntimeException('Query failed: '.$conn->error);
			}
			if($result!=false){
				$num_rows=mysqli_num_rows($result);
			}
			if($num_rows==false){
				return false;
			}
			if($associative!==true){
				$query_result=$result->fetch_assoc();
			}
			else
			{
				$row=[];
				while($row=$result->fetch_assoc()){
					$rows[]=$row;
				}
				$query_result=$rows;
			}
		}catch(\Throwable $exception){
			sql::log_query_error('MySQLi', $dbms_cluster, $query, $vars, $exception);
			return false;
		}
		return $query_result;
	}
	
	/**
	 * Executes an immediate MySQL `COUNT(*)`.
	*
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @param string $location Table or location fragment.
	 * @param string $params WHERE/GROUP fragment.
	 * @param ?array<int, mixed> $vars Bound values, or `null`.
	 * @return int|bool Count value, or `false` on failure.
	 */
	public static function mysql_count(string $dbms_cluster, string $location, string $params, ?array $vars) : int|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_COUNT",...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		$query="SELECT COUNT(*) as count FROM ".$location." ".$params;
		if(is_array($vars)){
			$datatypes=str_repeat("s", count($vars));
			$stmt=$conn->prepare($query);
			if($stmt===false){
				sql::log_query_error('MySQLi', $dbms_cluster, $query, $vars, new \RuntimeException('Query failed: '.$conn->error));
				return false;
			}
			$stmt->bind_param($datatypes, ...$vars);
			$stmt->execute();
			$result=$stmt->get_result();
			$result=$result->fetch_assoc();
			$stmt->close();
			return $result['count'];
		}
		try{
			$query_result=mysqli_query($conn,$query);
			if($query_result===false){
				throw new \RuntimeException('Query failed: '.$conn->error);
			}
			$result=mysqli_fetch_assoc($query_result);
		}catch(\Throwable $exception){
			sql::log_query_error('MySQLi', $dbms_cluster, $query, $vars, $exception);
			return false;
		}
		return $result['count'];
	}
	
	/**
	 * Executes an immediate MySQL `UPDATE`.
	*
	 * Tables configured for multipoint writes update every endpoint; otherwise the
	 * first reachable endpoint is used. Boolean vars are converted to integers for
	 * MySQLi binding.
	*
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @param string $location Table or location fragment.
	 * @param string $fields SET-clause field fragment.
	 * @param string $params WHERE/LIMIT fragment.
	 * @param array<int, mixed> $vars Bound values.
	 * @return bool|int Maximum affected-row count across successful endpoints, or `false`.
	 */
	public static function mysql_update(string $dbms_cluster, string $location, string $fields, string $params, array $vars) : bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_UPDATE",...func_get_args())) return $early_return;
		$datatypes='';
		foreach($vars as &$value){
			$datatypes.=is_bool($value)?'i':(is_int($value)?'i':'s');
			if(is_bool($value))$value=(int)$value;
		}
		$succeeded=0;
		$affected_rows=[];
		$is_multipoint=DP_SQL_CFG['tables'][$location]['multipoint_writes']??false;
		$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		foreach($endpoints as $endpoint){
			$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
			$query="UPDATE ".$location." SET ".$fields." ".$params;
			try{
				if(is_array($vars)){
					$stmt=$conn->prepare($query);
					if($stmt===false){
						throw new \RuntimeException('Query failed: '.$conn->error);
					}
					$stmt->bind_param($datatypes, ...$vars);				
					if($stmt->execute()){
						$affected_rows[]=$stmt->affected_rows;
						$succeeded++;
					}
					$stmt->close();
				}
				else
				{
					if(mysqli_query($conn, $query)===false){
						throw new \RuntimeException('Query failed: '.$conn->error);
					}
					$affected_rows[]=mysqli_affected_rows($conn);
					$succeeded++;
				}
			}catch(\Throwable $exception){
				sql::log_query_error('MySQLi', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint)break;
		}
		if($succeeded>=1){
			return max(0, ...$affected_rows);
		}
		return false;
	}
	
	/**
	 * Executes an immediate MySQL `INSERT IGNORE ... RETURNING`.
	*
	 * Tables configured for multipoint writes insert on every endpoint; otherwise
	 * the first reachable endpoint is used. The returned row is fetched from the
	 * final attempted endpoint.
	*
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @param string $location Table or location fragment.
	 * @param string $fields Comma-separated insert field list.
	 * @param array<int, mixed> $vars Bound values.
	 * @param string $returning RETURNING fragment.
	 * @return array|bool Returned row, `true` when no row was available, or `false` on failure.
	 */
	public static function mysql_insert(string $dbms_cluster, string $location, string $fields, array $vars, string $returning='*') : array|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_INSERT",...func_get_args())) return $early_return;
		$datatypes='';
		foreach($vars as &$value){
			$datatypes.=is_bool($value)?'i':(is_int($value)?'i':'s');
			if(is_bool($value))$value=(int)$value;
		}
		$fields_question_marks=rtrim(str_repeat('?,', count(explode(',', $fields))), ',');
		$is_multipoint=DP_SQL_CFG['tables'][$location]['multipoint_writes']??false;
		$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		foreach($endpoints as $endpoint){
			$query="INSERT IGNORE INTO ".$location." (".$fields.") VALUES (".$fields_question_marks.") RETURNING ".$returning;
			try{
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
				$stmt=$conn->prepare($query);
				if($stmt===false){
					throw new \RuntimeException('Query failed: '.$conn->error);
				}
				$stmt->bind_param($datatypes, ...$vars);
				$stmt->execute();
				$result=$stmt->get_result();
				$result_key=$result->fetch_assoc();
				$stmt->close();
			}catch(\Throwable $exception){
				sql::log_query_error('MySQLi', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint)break;
		}
		return $result_key ?? true;
	}
	
	/**
	 * Executes an immediate MySQL `DELETE`.
	*
	 * Tables configured for multipoint writes delete on every endpoint; otherwise
	 * the first reachable endpoint is used.
	*
	 * @param string $dbms_cluster Cluster key from `DP_SQL_CFG`.
	 * @param string $location Table or location fragment.
	 * @param string $params WHERE/LIMIT fragment.
	 * @param ?array<int, mixed> $vars Bound values, or `null`.
	 * @return bool|int Maximum affected-row count across successful endpoints, or `false`.
	 */
	public static function mysql_delete(string $dbms_cluster, string $location, string $params, ?array $vars) : bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_DELETE",...func_get_args())) return $early_return;
		$succeeded=0;
		$affected_rows=[];
		$datatypes='';
		if(isset($vars)){
			foreach($vars as &$value){
				$datatypes.=is_bool($value)?'i':(is_int($value)?'i':'s');
				if(is_bool($value))$value=(int)$value;
			}
		}
		$is_multipoint=DP_SQL_CFG['tables'][$location]['multipoint_writes']??false;
		$endpoints=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		foreach($endpoints as $endpoint){
			$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
			$query="DELETE FROM ".$location." ".$params;
			try{
				if(!empty($vars)){
					$stmt=$conn->prepare($query);
					if($stmt===false){
						throw new \RuntimeException('Query failed: '.$conn->error);
					}
					$stmt->bind_param($datatypes, ...$vars);
					if($stmt->execute()){
						$affected_rows[]=$stmt->affected_rows;
						$succeeded++;
					}
					$stmt->close();
				}
				else
				{
					if(mysqli_query($conn,$query)===false){
						throw new \RuntimeException('Query failed: '.$conn->error);
					}
					$affected_rows[]=mysqli_affected_rows($conn);
					$succeeded++;
				}
			}catch(\Throwable $exception){
				sql::log_query_error('MySQLi', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint)break;
		}
		if($succeeded>=1){
			return max(0, ...$affected_rows);
		}
		return false;
	}
	
}
