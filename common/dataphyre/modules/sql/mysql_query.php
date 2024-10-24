<?php
/*************************************************************************
*  2020-2022 Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd..
*/

namespace dataphyre;
use Mysqli;

register_shutdown_function(function(){
	ob_start();
	ini_set('display_errors', 0);
	ini_set('display_startup_errors', 0);
	error_reporting(0);
	do{
		foreach(mysql_query_builder::$queued_queries as $queue=>$queue_data){
			mysql_query_builder::execute_multiquery($queue);
		}
	}while(!empty(mysql_query_builder::$queued_queries));
	ob_end_clean();
});

class mysql_query_builder {
	
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
			return	self::$conns[$dbms_cluster];
		}
		if(!sql::is_server_available($endpoint)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="$endpoint is known as being unavailable, using next available server", $S="warning");
			return false;
		}
		if(!$conn=mysqli_init()){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed mysql init", $S="warning");
			sql::flag_server_unavailable($endpoint);
			return false;
		}
		if(!$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 0.5)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed setting timeout limit", $S="warning");
			sql::flag_server_unavailable($endpoint);
			return false;
		}
		$username=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms_username'];
		$database=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['database_name'];
		if(!$conn->real_connect($endpoint, $username, core::get_password($endpoint), $database)){
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
	
	private static function execute_prepared_statements(object $conn, array $prepared_statements, array &$results) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index=0;
		try{
			$conn->begin_transaction();
			foreach($prepared_statements as $statement){
				$stmt=$conn->prepare($statement['query']);
				$stmt->bind_param(str_repeat('s', count($statement['vars'])), ...$statement['vars']);
				$stmt->execute();
				$result=$stmt->get_result();
				if($result)$results[$index]=$result->fetch_all(MYSQLI_ASSOC);
				$index++;
				$stmt->close();
			}
			$conn->commit();
		}catch(Exception $e){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query has failed and will be rolled back", "fatal");
			$conn->rollback();
			return false;
		}
		return true;
	}
	
	private static function execute_multi_query_string(object $conn, string $multi_query_string, array &$results) : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index=0;
		if($conn->multi_query($multi_query_string)){
			do{
				$result=$conn->store_result();
				if($result)$results[$index]=$result->fetch_all(MYSQLI_ASSOC);
				if($result)$result->free();
				$index++;
			}while($conn->more_results() && $conn->next_result());
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
		foreach($queued_queries[$queue] as $query_type=>$query_info_array){
			foreach($query_info_array as $query_info){
				$query="";
				if($query_type==='select'){
					$query_info['query']="SELECT {$query_info['select']} FROM {$query_info['location']} {$query_info['params']}";
				}
				elseif($query_type==='insert'){
					$query_info['fields_question_marks']=explode(',', $query_info['fields']);
					$query_info['fields_question_marks']=str_repeat("?,", count($query_info['fields_question_marks']));
					$query_info['fields_question_marks']=rtrim($query_info['fields_question_marks'],',');
					$query_info['query']="INSERT {$query_info['ignore']} INTO {$query_info['location']} ({$query_info['fields']}) VALUES ({$query_info['fields_question_marks']})";
				}
				elseif($query_type==='update'){
					$query_info['query']="UPDATE {$query_info['location']} SET {$query_info['fields']} {$query_info['params']}";
				}
				elseif($query_type==='count'){
					$query_info['query']="SELECT COUNT(*) as c FROM {$query_info['location']} {$query_info['params']}";
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
				if(isset($query_info['multipoint']) && $query_info['multipoint']===true)$multipoint=true;
				$query_info['type']=$query_type;
				$queries[$index]=$query_info;
				$index++;
			}
		}
		$results=[];
		$dbms_cluster=$configurations['dataphyre']['sql']['tables']['raw']['cluster']??$configurations['dataphyre']['sql']['default_cluster'];
		if($multipoint===true){
			$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
			if(!empty($prepared_statements)){
				foreach($endpoints as $endpoint){
					$conn=self::connect_to_endpoint($endpoint, $dbms_cluster);
					if(!self::execute_prepared_statements($conn, $prepared_statements, $results))return false;
				}
			}
			else
			{
				foreach($endpoints as $endpoint){
					$conn=self::connect_to_endpoint($endpoint, $dbms_cluster);
					self::execute_multi_query_string($conn, $multi_query_string, $results);
				}
			}
		}
		else
		{
			self::connect_to_cluster($dbms_cluster);
			if(!empty($prepared_statements)){
				if(!self::execute_prepared_statements(self::$conns[$dbms_cluster], $prepared_statements, $results))return false;
			}
			else
			{
				self::execute_multi_query_string(self::$conns[$dbms_cluster], $multi_query_string, $results);
			}
		}
		self::process_results($results, $queries);
		return true;
	}
	
	public static function mysql_query(string $dbms_cluster, string $query, array|null $vars, bool|null $associative, bool|null $multipoint=true) : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_SELECT",...func_get_args())) return $early_return;
		$execute_query = function($conn) use ($query, $vars) {
			if(is_array($vars)){
				$datatypes = str_repeat("s", count($vars));
				$stmt = $conn->prepare($query);
				$stmt->bind_param($datatypes, ...$vars);
				$stmt->execute();
				$result = $stmt->get_result();
				$stmt->close();
				return $result;
			}
			else
			{
				if(mysqli_multi_query($conn, $query)){
					do {
						if ($result = mysqli_store_result($conn)) {
							$firstResult = $result;
							mysqli_free_result($result);
						}
					} while (mysqli_next_result($conn));
					return $firstResult ?? false;
				}
				return false;
			}
		};
		if($multipoint === true){
			$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
			foreach($endpoints as $endpoint){
				$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
				$result = $execute_query($conn);
			}
		}
		else
		{
			$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
			try{
				$result=$execute_query($conn);
			}catch(Throwable $ex){
				log_error("MySQLi exception", $exception);	
			}
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
	
	public static function mysql_select(string $dbms_cluster, string $select, string $location, string|null $params, array|null $vars, bool|null $associative) : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_SELECT",...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		if(is_array($vars)){
			$datatypes=str_repeat("s", count($vars));
			$stmt=$conn->prepare("SELECT ".$select." FROM ".$location." ".$params);
			$stmt->bind_param($datatypes, ...$vars);
			$stmt->execute();
			$result=$stmt->get_result();
			$stmt->close();
		}
		else
		{
			$result=mysqli_query($conn,"SELECT ".$select." FROM ".$location." ".$params);
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
		return $query_result;
	}
	
	public static function mysql_count(string $dbms_cluster, string $location, string $params, ?array $vars) : int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_COUNT",...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		if(is_array($vars)){
			$datatypes=str_repeat("s", count($vars));
			$stmt=$conn->prepare("SELECT COUNT(*) as count FROM ".$location." ".$params);
			$stmt->bind_param($datatypes, ...$vars);
			$stmt->execute();
			$result=$stmt->get_result();
			$result=$result->fetch_assoc();
			$stmt->close();
			return $result['count'];
		}
		try{
			$result=mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as count FROM ".$location." ".$params));
		}catch(Throwable $ex){
			log_error("MySQLi Exception", $ex);
		}
		return $result['count'];
	}
	
	public static function mysql_update(string $dbms_cluster, string $location, string $fields, string $params, array $vars) : bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_UPDATE",...func_get_args())) return $early_return;
		$datatypes='';
		foreach($vars as &$value){
			$datatypes.=is_bool($value)?'i':(is_int($value)?'i':'s');
			if(is_bool($value))$value=(int)$value;
		}
		$i=0;
		$is_multipoint=$configurations['dataphyre']['sql']['tables'][$location]['multipoint_writes']??false;
		$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		foreach($endpoints as $endpoint){
			$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
			try{
				if(is_array($vars)){
					$stmt=$conn->prepare("UPDATE ".$location." SET ".$fields." ".$params);
					$stmt->bind_param($datatypes, ...$vars);				
					if($stmt->execute()){
						$i++;
					}
					$stmt->close();
				}
				else
				{
					mysqli_query($conn,"UPDATE ".$location." SET ".$fields." ".$params);
					$i++;
				}
			}catch(Throwable $ex){
				log_error("MySQLi Exception", $ex);
			}
			if(!$is_multipoint)break;
		}
		if($i>=1){
			return $i;
		}
		return false;
	}
	
	public static function mysql_insert(string $dbms_cluster, string $location, string $fields, array $vars) : int|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_INSERT",...func_get_args())) return $early_return;
		$datatypes='';
		foreach($vars as &$value){
			$datatypes.=is_bool($value)?'i':(is_int($value)?'i':'s');
			if(is_bool($value))$value=(int)$value;
		}
		$fields_question_marks = rtrim(str_repeat('?,', count(explode(',', $fields))), ',');
		$is_multipoint=$configurations['dataphyre']['sql']['tables'][$location]['multipoint_writes']??false;
		$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		foreach($endpoints as $endpoint){
			try{
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
				$stmt=$conn->prepare("INSERT IGNORE INTO ".$location." (".$fields.") VALUES (".$fields_question_marks.")");
				$stmt->bind_param($datatypes, ...$vars);
				$stmt->execute();
				$result_key=$stmt->insert_id;
				$stmt->close();
			}catch(Throwable $ex){
				log_error("MySQLi Exception", $ex);
			}
			if(!$is_multipoint)break;
		}
		return $result_key ?? true;
	}
	
	public static function mysql_delete(string $dbms_cluster, string $location, string $params, ?array $vars) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_DELETE",...func_get_args())) return $early_return;
		$i=0;
		$datatypes='';
		if(isset($vars)){
			foreach($vars as &$value){
				$datatypes.=is_bool($value)?'i':(is_int($value)?'i':'s');
				if(is_bool($value))$value=(int)$value;
			}
		}
		$is_multipoint=$configurations['dataphyre']['sql']['tables'][$location]['multipoint_writes']??false;
		$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		foreach($endpoints as $endpoint){
			$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
			try{
				if(!empty($vars)){
					$stmt=$conn->prepare("DELETE FROM ".$location." ".$params);
					$stmt->bind_param($datatypes, ...$vars);
					if($stmt->execute()){
						$i++;
					}
					$stmt->close();
				}
				else
				{
					mysqli_query($conn,"DELETE FROM ".$location." ".$params);
					$i++;
				}
			}catch(Throwable $ex){
				log_error("MySQLi Exception", $ex);
			}
			if(!$is_multipoint)break;
		}
		if($i>=1){
			return true;
		}
		return false;
	}
	
}