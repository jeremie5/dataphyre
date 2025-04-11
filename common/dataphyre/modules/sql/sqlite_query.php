<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */

namespace dataphyre;

register_shutdown_function(function(){
	ob_start();
	ini_set('display_errors', 0);
	ini_set('display_startup_errors', 0);
	error_reporting(0);
		do{
			foreach(sqlite_query_builder::$queued_queries as $queue=>$queue_data){
				try {
					sqlite_query_builder::execute_multiquery($queue);
				} catch (\Throwable $exception) {
					log_error("SQLite Queued Query Execution Error", $exception);
				}
			}
		}while(!empty(sqlite_query_builder::$queued_queries));
	ob_end_clean();
});

class sqlite_query_builder {
	
	public static $conns=[];
	public static $queued_queries=[];
	
	private static function connect_to_cluster(string $dbms_cluster){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_OPEN_MAIN_CONNECTION",...func_get_args())) return $early_return;
		global $configurations;
		$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
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
	
	private static function connect_to_endpoint(string $endpoint, string $dbms_cluster='default') : object {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(isset(self::$conns[$dbms_cluster])){
			return self::$conns[$dbms_cluster];
		}
		$database=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['database_name'];
		try{
			$conn=new SQLite3($database);
		}catch (Exception $e){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed to connect to SQLite database: ".$e->getMessage(), $S="fatal");
			return false;
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="SQLite connection to $database successful");
		return self::$conns[$dbms_cluster]=$conn;
	}
	
	private static function execute_prepared_statements(object $conn, array $prepared_statements, array &$results, string $dbms_cluster='n/a') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index=0;
		$has_write=sql::query_has_write(serialize($prepared_statements));
		try{
			if($has_write)$conn->exec('BEGIN TRANSACTION');
			foreach($prepared_statements as $statement){
				$stmt=$conn->prepare($statement['query']);
				if(!$stmt){
					throw new Exception("Failed to prepare statement: ".$conn->lastErrorMsg());
				}
				foreach($statement['vars'] as $key=>$value){
					$stmt->bindValue($key+1, $value, SQLITE3_TEXT); // SQLite uses 1-based index for parameters
				}
				$result=$stmt->execute();
				if($result){
					$results[$index]=[];
					while($row=$result->fetchArray(SQLITE3_ASSOC)){
						$results[$index][]=$row;
					}
					$result->finalize();
				}
				$index++;
				$stmt->close();
			}
			if($has_write)$conn->exec('COMMIT');
		}catch(Exception $exception){
			if($has_write)$conn->exec('ROLLBACK');
			sql::log_query_error('SQLite', $dbms_cluster, $statement['query'], $statement['vars'], $exception);
			return false;
		}
		return true;
	}
	
	private static function execute_multi_query_string(object $conn, string $multi_query_string, array &$results) : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index=0;
		$has_write=sql::query_has_write(serialize($prepared_statements));
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
			$conn->close();
			if($has_write)$conn->exec('COMMIT');
		}catch(Exception $exception){
			if($has_write)$conn->exec('ROLLBACK');
			sql::log_query_error('SQLite', $dbms_cluster, $query, [], $exception);
		}
	}
	
	private static function process_results(?array $results, ?array $queries): void {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args());
		$query_list=[];
		foreach($queries as $query_type=>$query_group){
			foreach($query_group as $query){
				$query['type']=$query_type;
				$query_list[]=$query;
			}
		}
		foreach($results as $index=>$result){
			$query=$query_list[$index] ?? null;
			if(!$query){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Skipping invalid query at index $index");
				continue;
			}
			$associative = $query['associative'] ?? null;
			$result = empty($result) || !is_array($result) ? false : ($associative === false ? $result[0] : $result);
			if($query['type']==='count' && isset($result[0]['count'])){
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
	
	public static function execute_multiquery(string $queue='') : null|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(!isset(self::$queued_queries[$queue]))return null;
		$queued_queries=self::$queued_queries[$queue];
		unset(self::$queued_queries[$queue]);
		global $configurations;
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
		$dbms_cluster=$configurations['dataphyre']['sql']['tables']['raw']['cluster'] ?? $configurations['dataphyre']['sql']['default_cluster'];
		$endpoint=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'][0];
		self::connect_to_endpoint($endpoint);
		if(!empty($prepared_statements)){
			if(!self::execute_prepared_statements($conn, $prepared_statements, $results)) return false;
		}
		else
		{
			self::execute_multi_query_string($conn, $multi_query_string, $results);
		}
		self::process_results($results, $queries);
		return true;
	}
	
	public static function sqlite_query(string $dbms_cluster, string $query, array|null $vars, bool|null $associative, bool|null $multipoint=true) : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null !== $early_return=core::dialback("CALL_SQL_SIMPLE_SELECT", ...func_get_args())) return $early_return;
		$execute_query=function($conn) use ($query, $vars){
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
			$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
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
			$endpoint=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'][0];
			$conn=new SQLite3($endpoint);
			try {
				$result=$execute_query($conn);
			} catch (\Throwable $exception){
				sql::log_query_error('SQLite', $dbms_cluster, $query, $vars, $exception);
			} finally {
				$conn->close();
			}
		}
		return $result===false ? false : $result;
	}
	
	public static function sqlite_select(string $dbms_cluster, string $select, string $location, string|null $params, array|null $vars, bool|null $associative) : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
					return false;
				}
				$query_result=[];
				while ($row=$result->fetchArray($associative ? SQLITE3_ASSOC : SQLITE3_NUM)){
					$query_result[]=$row;
				}
			}
		} catch (\Throwable $exception){
			sql::log_query_error('SQLite', $dbms_cluster, $stmt, $vars, $exception);
		}
		if(empty($query_result)){
			return false;
		}
		return $associative !== true ? $query_result[0] : $query_result;
	}
	
	public static function sqlite_count(string $dbms_cluster, string $location, string $params, ?array $vars) : int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null !== $early_return=core::dialback("CALL_SQL_SIMPLE_COUNT", ...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		try{
			if(is_array($vars)){
				if(false===$stmt=$conn->prepare($query="SELECT COUNT(*) as count FROM ".$location." ".$params)){
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
			$result=$conn->querySingle("SELECT COUNT(*) as count FROM ".$location." ".$params, true);
		} catch (\Throwable $exception){
			sql::log_query_error('SQLite', $dbms_cluster, $query, $vars, $exception);
		}
		if($result===false){
			return 0; // Return 0 if query fails
		}
		return $result['count'] ?? 0; // Return count or 0 if not found
	}
	
	public static function sqlite_update(string $dbms_cluster, string $location, string $fields, string $params, array $vars) : bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null !== $early_return=core::dialback("CALL_SQL_SIMPLE_UPDATE", ...func_get_args())) return $early_return;
		$is_multipoint=$configurations['dataphyre']['sql']['tables'][$location]['multipoint_writes'] ?? false;
		$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		$execute_update=function($conn) use ($location, $fields, $params, $vars){
			if(is_array($vars)){
				if(false===$stmt=$conn->prepare($query="UPDATE ".$location." SET ".$fields." ".$params)){
					throw new \Exception('Query preparation failed: '.$conn->lastErrorMsg());
				}
				foreach($vars as $index=>$value){
					$stmt->bindValue($index + 1, $value);
				}
				if(false===$result=$stmt->execute()){
					throw new \Exception('Query execution failed: '.$conn->lastErrorMsg());
				}
				$stmt->close();
				return $result !== false; // Return true if execution is successful
			}
			else
			{
				return $conn->exec("UPDATE ".$location." SET ".$fields." ".$params) !== false;
			}
		};
		$i=0;
		foreach($endpoints as $endpoint){
			$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint);
			try {
				if($execute_update($conn)){
					$i++;
				}
			} catch (\Throwable $exception){
				sql::log_query_error('SQLite', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint) break;
		}
		if($i >= 1){
			return $i;
		}
		return false;
	}
	
	public static function sqlite_insert(string $dbms_cluster, string $location, string $fields, array $vars) : array|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null !== $early_return=core::dialback("CALL_SQL_SIMPLE_INSERT", ...func_get_args())) return $early_return;
		$fields_question_marks=rtrim(str_repeat('?,', count($vars)), ',');
		$is_multipoint=$configurations['dataphyre']['sql']['tables'][$location]['multipoint_writes'] ?? false;
		$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		$execute_insert=function($conn) use ($location, $fields, $fields_question_marks, $vars){
			if(false===$stmt=$conn->prepare($query="INSERT OR IGNORE INTO ".$location." (".$fields.") VALUES (".$fields_question_marks.")")){
				throw new \Exception('Query preparation failed');
			}
			foreach($vars as $index=>$value){
				$stmt->bindValue($index+1, $value);
			}
			if(false===$result=$stmt->execute()){
				throw new \Exception('Query execution failed: '.$conn->lastErrorMsg());
			}
			while($row=$result->fetchArray($associative ? SQLITE3_ASSOC : SQLITE3_NUM)){
				$result_key=$row;
			}
			$stmt->close();
			return $result !== false ? $result_key : false;
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
	
	public static function sqlite_delete(string $dbms_cluster, string $location, string $params, ?array $vars) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null !== $early_return=core::dialback("CALL_SQL_SIMPLE_DELETE", ...func_get_args())) return $early_return;
		$i=0;
		$is_multipoint=$configurations['dataphyre']['sql']['tables'][$location]['multipoint_writes'] ?? false;
		$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		$execute_delete=function($conn) use ($location, $params, $vars){
			if(false===$stmt=$conn->prepare($query="DELETE FROM ".$location." ".$params)){
				throw new \Exception('Query preparation failed');
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
			return $result !== false;
		};
		foreach($endpoints as $endpoint){
			try {
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint);
				if($execute_delete($conn)){
					$i++;
				}
			} catch (\Throwable $exception){
				sql::log_query_error('SQLite', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint) break;
		}
		return $i >= 1;
	}
	
}