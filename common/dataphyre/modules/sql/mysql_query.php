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
		pre_init_error('Exception on Dataphyre SQL MySQL shutdown callback', $exception);
	}
});

class mysql_query_builder {
	
	public static $conns=[];
	public static $queued_queries=[];
	
	private static function connect_to_cluster(string $dbms_cluster){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		global $configurations;
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
		$username=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms_username'];
		$database=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['database_name'];
		$password=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['password'];
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
	
	private static function execute_prepared_statements(object $conn, array $prepared_statements, array &$results, string $dbms_cluster='n/a') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index=0;
		$has_write=sql::query_has_write(serialize($prepared_statements));
		try{
			if($has_write)$conn->begin_transaction();
			foreach($prepared_statements as $statement){
				$stmt=$conn->prepare($statement['query']);
				$stmt->bind_param(str_repeat('s', count($statement['vars'])), ...$statement['vars']);
				$stmt->execute();
				if(!empty($result_key=$stmt->insert_id)){
					$result=$result_key;
				}
				else
				{
					$result=$stmt->get_result();
				}
				if($result)$results[$index]=$result->fetch_all(MYSQLI_ASSOC);
				$index++;
				$stmt->close();
			}
			if($has_write)$conn->commit();
		}catch(Exception $exception){
			if($has_write)$conn->rollback();
			sql::log_query_error('MySQLi', $dbms_cluster, $statement['query'], $statement['vars'], $exception);
			return false;
		}
		return true;
	}
	
	private static function execute_multi_query_string(object $conn, string $multi_query_string, array &$results, string $dbms_cluster='n/a') : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index=0;
		$has_write=sql::query_has_write(serialize($multi_query_string));
		try{
			if($has_write)$conn->begin_transaction();
			if($conn->multi_query($multi_query_string)){
				do{
					$result=$conn->store_result();
					if($result)$results[$index]=$result->fetch_all(MYSQLI_ASSOC);
					if($result)$result->free();
					$index++;
				}while($conn->more_results() && $conn->next_result());
			}
			$conn->close();
			if($has_write)$conn->commit();
		}catch(Exception $exception){
			if($has_write)$conn->rollback();
			sql::log_query_error('MySQLi', $dbms_cluster, $multi_query_string, [], $exception);
		}
	}
	
	private static function process_results(?array $results, ?array $queries): void {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
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
			$associative=$query['associative'] ?? null;
			$result=empty($result) || !is_array($result) ? false : ($associative === false ? $result[0] : $result);
			if($query['type']==='count' && isset($result[0]['count'])){
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
	
	public static function execute_multiquery(string $queue='') : null|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(!isset(self::$queued_queries[$queue]))return null;
		$queued_queries=self::$queued_queries[$queue];
		unset(self::$queued_queries[$queue]);
		global $configurations;
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
					case 'insert':
						$query_info['fields_question_marks']=explode(',', $query_info['fields']);
						$query_info['fields_question_marks']=str_repeat("?,", count($query_info['fields_question_marks']));
						$query_info['fields_question_marks']=rtrim($query_info['fields_question_marks'],',');
						$query_info['query']="INSERT {$query_info['ignore']} INTO {$query_info['location']} ({$query_info['fields']}) VALUES ({$query_info['fields_question_marks']}) RETURNING ".$query_info['returning'];
					case 'update':
						$query_info['query']="UPDATE {$query_info['location']} SET {$query_info['fields']} {$query_info['params']}";
					case 'count':
						$query_info['query']="SELECT COUNT(*) as count FROM {$query_info['location']} {$query_info['params']}";
					case 'delete':
						$query_info['query']="DELETE FROM {$query_info['location']} {$query_info['params']}";
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
		$dbms_cluster=$configurations['dataphyre']['sql']['tables']['raw']['cluster'] ?? $configurations['dataphyre']['sql']['default_cluster'];
		if($multipoint===true){
			$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
			if(!empty($prepared_statements)){
				foreach($endpoints as $endpoint){
					$conn=self::connect_to_endpoint($endpoint, $dbms_cluster);
					if(!self::execute_prepared_statements($conn, $prepared_statements, $results, $dbms_cluster))return false;
				}
			}
			else
			{
				foreach($endpoints as $endpoint){
					$conn=self::connect_to_endpoint($endpoint, $dbms_cluster);
					self::execute_multi_query_string($conn, $multi_query_string, $results, $dbms_cluster);
				}
			}
		}
		else
		{
			self::connect_to_cluster($dbms_cluster);
			if(!empty($prepared_statements)){
				if(!self::execute_prepared_statements(self::$conns[$dbms_cluster], $prepared_statements, $results, $dbms_cluster))return false;
			}
			else
			{
				self::execute_multi_query_string(self::$conns[$dbms_cluster], $multi_query_string, $results, $dbms_cluster);
			}
		}
		self::process_results($results, $queries);
		return true;
	}
	
	public static function mysql_query(string $dbms_cluster, string $query, ?array $vars, ?bool $associative, ?bool $multipoint=true) : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_SELECT",...func_get_args())) return $early_return;
		$execute_query=function($conn) use ($query, $vars) {
			if(is_array($vars)){
				$datatypes=str_repeat("s", count($vars));
				$stmt=$conn->prepare($query);
				$stmt->bind_param($datatypes, ...$vars);
				$stmt->execute();
				$result=$stmt->get_result();
				$stmt->close();
				return $result;
			}
			else
			{
				if(mysqli_multi_query($conn, $query)){
					do {
						if ($result=mysqli_store_result($conn)) {
							$firstResult=$result;
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
	
	public static function mysql_select(string $dbms_cluster, string $select, string $location, ?string $params, ?array $vars, ?bool $associative) : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_SELECT",...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		try{
			if(is_array($vars)){
				$datatypes=str_repeat("s", count($vars));
				$stmt=$conn->prepare($query="SELECT ".$select." FROM ".$location." ".$params);
				$stmt->bind_param($datatypes, ...$vars);
				$stmt->execute();
				$result=$stmt->get_result();
				$stmt->close();
			}
			else
			{
				$result=mysqli_query($conn,$query="SELECT ".$select." FROM ".$location." ".$params);
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
		}
		return $query_result;
	}
	
	public static function mysql_count(string $dbms_cluster, string $location, string $params, ?array $vars) : int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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
		}catch(\Throwable $exception){
			sql::log_query_error('MySQLi', $dbms_cluster, $query, $vars, $exception);
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
					$stmt=$conn->prepare($query="UPDATE ".$location." SET ".$fields." ".$params);
					$stmt->bind_param($datatypes, ...$vars);				
					if($stmt->execute()){
						$i++;
					}
					$stmt->close();
				}
				else
				{
					mysqli_query($conn, $query="UPDATE ".$location." SET ".$fields." ".$params);
					$i++;
				}
			}catch(\Throwable $exception){
				sql::log_query_error('MySQLi', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint)break;
		}
		if($i>=1){
			return $i;
		}
		return false;
	}
	
	public static function mysql_insert(string $dbms_cluster, string $location, string $fields, array $vars, string $returning='*') : array|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_SQL_SIMPLE_INSERT",...func_get_args())) return $early_return;
		$datatypes='';
		foreach($vars as &$value){
			$datatypes.=is_bool($value)?'i':(is_int($value)?'i':'s');
			if(is_bool($value))$value=(int)$value;
		}
		$fields_question_marks=rtrim(str_repeat('?,', count(explode(',', $fields))), ',');
		$is_multipoint=$configurations['dataphyre']['sql']['tables'][$location]['multipoint_writes']??false;
		$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		foreach($endpoints as $endpoint){
			try{
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
				$stmt=$conn->prepare($query="INSERT IGNORE INTO ".$location." (".$fields.") VALUES (".$fields_question_marks.") RETURNING ".$returning);
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
					$stmt=$conn->prepare($query="DELETE FROM ".$location." ".$params);
					$stmt->bind_param($datatypes, ...$vars);
					if($stmt->execute()){
						$i++;
					}
					$stmt->close();
				}
				else
				{
					mysqli_query($conn,$query="DELETE FROM ".$location." ".$params);
					$i++;
				}
			}catch(\Throwable $exception){
				sql::log_query_error('MySQLi', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint)break;
		}
		if($i>=1){
			return true;
		}
		return false;
	}
	
}