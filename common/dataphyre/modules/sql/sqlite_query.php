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
			sqlite_query_builder::execute_multiquery($queue);
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
		try {
			$conn=new SQLite3($database);
		} catch (Exception $e){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed to connect to SQLite database: ".$e->getMessage(), $S="warning");
			return false;
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="SQLite connection to $database successful");
		return self::$conns[$dbms_cluster]=$conn;
	}
	
	private static function execute_prepared_statements(object $conn, array $prepared_statements, array &$results) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index=0;
		try {
			$conn->exec('BEGIN TRANSACTION');
			foreach($prepared_statements as $statement){
				$stmt=$conn->prepare($statement['query']);
				if(!$stmt){
					throw new Exception("Failed to prepare statement: ".$conn->lastErrorMsg());
				}
				foreach($statement['vars'] as $key => $value){
					$stmt->bindValue($key + 1, $value, SQLITE3_TEXT); // SQLite uses 1-based index for parameters
				}
				$result=$stmt->execute();
				if($result){
					$results[$index]=[];
					while ($row=$result->fetchArray(SQLITE3_ASSOC)){
						$results[$index][]=$row;
					}
					$result->finalize();
				}
				$index++;
				$stmt->close();
			}
			$conn->exec('COMMIT');
		} catch(Exception $e){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query has failed and will be rolled back: ".$e->getMessage(), $S="fatal");
			$conn->exec('ROLLBACK');
			return false;
		}
		return true;
	}
	
	private static function execute_multi_query_string(object $conn, string $multi_query_string, array &$results) : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index=0;
		// Split the multi-query string into individual queries
		$queries=explode(';', $multi_query_string);
		foreach($queries as $query){
			$query=trim($query);
			if(empty($query)){
				continue;
			}
			$result=$conn->query($query);
			if($result){
				$results[$index]=[];
				while ($row=$result->fetchArray(SQLITE3_ASSOC)){
					$results[$index][]=$row;
				}
				$result->finalize();
			}
			$index++;
		}
		$conn->close();
	}
	
	private static function process_results(array $results, array $queries) : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		foreach($results as $index=>$result){
			$associative=isset($queries[$index]['associative'])?$queries[$index]['associative']:null;
			$result=empty($result)?false:($associative===false?$result[0]:$result);
			if($queries[$index]['type']==='count'){
				$result=$result[0]['c'];
			}
			if(is_array($queries[$index]['caching'])){
				if(isset($queries[$index]['hash'])){
					sql::cache_query_result($queries[$index]['location'], $queries[$index]['hash'], $result, $queries[$index]['caching']);
				}
			}
			else
			{
				if($result!==false && isset($queries[$index]['clear_cache']) && $queries[$index]['clear_cache']!==false){
					if($queries[$index]['clear_cache']===true){
						sql::invalidate_cache($queries[$index]['location']);
					}elseif($queries[$index]['clear_cache']!==null){
						sql::invalidate_cache($queries[$index]['clear_cache']);
					}
				}
			}
			if(isset($queries[$index]['callback']) && null!==$callback=$queries[$index]['callback']){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Calling callback");
				$callback($result);
			}
		}
	}
	
	public static function execute_multiquery(string $queue='') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(!isset(self::$queued_queries[$queue])) return false;
		$queued_queries=self::$queued_queries;
		unset(self::$queued_queries[$queue]);
		global $configurations;
		$multipoint=false;
		$queries=[];
		$multi_query_string="";
		$index=0;
		$prepared_statements=[];
		foreach($queued_queries[$queue] as $query_type => $query_info_array){
			foreach($query_info_array as $query_info){
				if($query_type==='select'){
					$query_info['query']="SELECT {$query_info['select']} FROM {$query_info['location']} {$query_info['params']}";
				} elseif($query_type==='insert'){
					$query_info['fields_question_marks']=str_repeat("?,", count(explode(',', $query_info['fields'])));
					$query_info['fields_question_marks']=rtrim($query_info['fields_question_marks'], ',');
					$query_info['query']="INSERT INTO {$query_info['location']} ({$query_info['fields']}) VALUES ({$query_info['fields_question_marks']})";
				} elseif($query_type==='update'){
					$query_info['query']="UPDATE {$query_info['location']} SET {$query_info['fields']} {$query_info['params']}";
				} elseif($query_type==='count'){
					$query_info['query']="SELECT COUNT(*) as c FROM {$query_info['location']} {$query_info['params']}";
				} elseif($query_type==='delete'){
					$query_info['query']="DELETE FROM {$query_info['location']} {$query_info['params']}";
				}
				if(is_array($query_info['vars'])){
					$prepared_statements[$index]=['query' => $query_info['query'], 'vars' => $query_info['vars']];
				} else {
					$multi_query_string .= $query_info['query']."; ";
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
		// Connect to the SQLite database
		$conn=new SQLite3($endpoint);
		if(!empty($prepared_statements)){
			if(!self::execute_prepared_statements($conn, $prepared_statements, $results)) return false;
		} else {
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
				$stmt=$conn->prepare($query);
				if($stmt===false){
					return false;
				}
				foreach($vars as $index => $var){
					$stmt->bindValue($index + 1, $var);
				}
				$result=$stmt->execute();
				$query_result=[];
				if($result){
					while ($row=$result->fetchArray($associative ? SQLITE3_ASSOC : SQLITE3_NUM)){
						$query_result[]=$row;
					}
				}
				$stmt->close();
				return $query_result;
			} else {
				$result=$conn->query($query);
				if($result===false){
					return false;
				}
				$query_result=[];
				while ($row=$result->fetchArray($associative ? SQLITE3_ASSOC : SQLITE3_NUM)){
					$query_result[]=$row;
				}
				return $query_result;
			}
		};
		if($multipoint===true){
			$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
			foreach($endpoints as $endpoint){
				$conn=new SQLite3($endpoint);
				$result=$execute_query($conn);
				$conn->close();
			}
		} else {
			$endpoint=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'][0];
			$conn=new SQLite3($endpoint);
			try {
				$result=$execute_query($conn);
			} catch (Throwable $ex){
				log_error("SQLite exception", $ex); 
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
		if(is_array($vars)){
			$stmt=$conn->prepare("SELECT ".$select." FROM ".$location." ".$params);
			if($stmt===false){
				return false;
			}
			foreach($vars as $index => $var){
				$stmt->bindValue($index + 1, $var);
			}
			$result=$stmt->execute();
			$query_result=[];
			if($result){
				while ($row=$result->fetchArray($associative ? SQLITE3_ASSOC : SQLITE3_NUM)){
					$query_result[]=$row;
				}
			}
			$stmt->close();
		} else {
			$result=$conn->query("SELECT ".$select." FROM ".$location." ".$params);
			if($result===false){
				return false;
			}
			$query_result=[];
			while ($row=$result->fetchArray($associative ? SQLITE3_ASSOC : SQLITE3_NUM)){
				$query_result[]=$row;
			}
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
		if(is_array($vars)){
			$stmt=$conn->prepare("SELECT COUNT(*) as count FROM ".$location." ".$params);
			if($stmt===false){
				return 0; // Return 0 if preparation fails
			}
			foreach($vars as $index => $var){
				$stmt->bindValue($index + 1, $var);
			}
			$result=$stmt->execute();
			if($result===false){
				return 0; // Return 0 if execution fails
			}
			$row=$result->fetchArray(SQLITE3_ASSOC);
			$stmt->close();
			return $row['count'] ?? 0; // Return count or 0 if not found
		}
		$result=$conn->querySingle("SELECT COUNT(*) as count FROM ".$location." ".$params, true);
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
				$stmt=$conn->prepare("UPDATE ".$location." SET ".$fields." ".$params);
				if($stmt===false){
					return false; // Return false if preparation fails
				}
				foreach($vars as $index => $value){
					$stmt->bindValue($index + 1, $value);
				}
				$result=$stmt->execute();
				$stmt->close();
				return $result !== false; // Return true if execution is successful
			} else {
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
			} catch (Throwable $ex){
				log_error("SQLite Exception", $ex);
			}
			if(!$is_multipoint) break;
		}
		if($i >= 1){
			return $i;
		}
		return false;
	}
	
	public static function sqlite_insert(string $dbms_cluster, string $location, string $fields, array $vars) : int|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null !== $early_return=core::dialback("CALL_SQL_SIMPLE_INSERT", ...func_get_args())) return $early_return;
		$fields_question_marks=rtrim(str_repeat('?,', count($vars)), ',');
		$is_multipoint=$configurations['dataphyre']['sql']['tables'][$location]['multipoint_writes'] ?? false;
		$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		$execute_insert=function($conn) use ($location, $fields, $fields_question_marks, $vars){
			$stmt=$conn->prepare("INSERT OR IGNORE INTO ".$location." (".$fields.") VALUES (".$fields_question_marks.")");
			if($stmt===false){
				return false; // Return false if preparation fails
			}
			foreach($vars as $index => $value){
				$stmt->bindValue($index + 1, $value);
			}
			$result=$stmt->execute();
			$result_key=$conn->lastInsertRowID();
			$stmt->close();
			return $result !== false ? $result_key : false;
		};
		$result_key=true;
		foreach($endpoints as $endpoint){
			try {
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint);
				$result_key=$execute_insert($conn);
			} catch (Throwable $ex){
				log_error("SQLite Exception", $ex);
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
			$query="DELETE FROM ".$location." ".$params;
			$stmt=$conn->prepare($query);
			if($stmt===false){
				return false; // Return false if preparation fails
			}
			if(isset($vars)){
				foreach($vars as $index => $value){
					$stmt->bindValue($index + 1, $value);
				}
			}
			$result=$stmt->execute();
			$stmt->close();
			return $result !== false;
		};
		foreach($endpoints as $endpoint){
			try {
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint);
				if($execute_delete($conn)){
					$i++;
				}
			} catch (Throwable $ex){
				log_error("SQLite Exception", $ex);
			}
			if(!$is_multipoint) break;
		}
		return $i >= 1;
	}
	
}