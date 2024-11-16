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
		foreach(postgresql_query_builder::$queued_queries as $queue=>$queue_data){
			postgresql_query_builder::execute_multiquery($queue);
		}
	}while(!empty(postgresql_query_builder::$queued_queries));
	ob_end_clean();
});

class postgresql_query_builder {
	
	public static $conns=[];
	public static $queued_queries=[];
	
	private static function connect_to_cluster(string $dbms_cluster) : mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_OPEN_MAIN_CONNECTION",...func_get_args())) return $early_return;
		global $configurations;
		$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
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
	
	private static function connect_to_endpoint(string $endpoint, ?string $dbms_cluster): object|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		$dbms_cluster??=$configurations['dataphyre']['sql']['default_cluster'];
		if(isset(self::$conns[$dbms_cluster]))return self::$conns[$dbms_cluster];
		if(!sql::is_server_available($endpoint)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="$endpoint is known as being unavailable, using next available server", $S="warning");
			return false;
		}
		$datacenter=$configurations['dataphyre']['datacenter'];
		$dbms=$configurations['dataphyre']['sql']['datacenters'][$datacenter]['dbms_clusters'][$dbms_cluster]['dbms'];
		$username=$configurations['dataphyre']['sql']['datacenters'][$datacenter]['dbms_clusters'][$dbms_cluster]['dbms_username'];
		$database=$configurations['dataphyre']['sql']['datacenters'][$datacenter]['dbms_clusters'][$dbms_cluster]['database_name'];
		$port=$configurations['dataphyre']['sql']['datacenters'][$datacenter]['dbms_clusters'][$dbms_cluster]['dbms_port']??5432;
		$password=core::get_password($dbms_cluster);
		$conn_string="host=$endpoint port=$port dbname=$database user=$username password=$password options='--client_encoding=UTF8' connect_timeout=1";
		if(!$conn=pg_connect($conn_string)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed connecting to $endpoint", $S="warning");
			sql::flag_server_unavailable($endpoint);
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="PostgreSQL connection to $endpoint successful");
		return self::$conns[$dbms_cluster]=$conn;
	}

	private static function execute_prepared_statements(object $conn, array $prepared_statements, array &$results): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		try{
			if(!pg_query($conn, "BEGIN")){
				throw new \Exception("Failed initiating transaction");
			}
			foreach($prepared_statements as $index=>$statement){
				$query=preg_replace_callback('/\?/', function($matches){static $index=0;return'$'.(++$index);}, $statement['query']);
				$query=preg_replace('/RAND\(\)/i', 'RANDOM()', $query);
				$query=str_ireplace("UNIX_TIMESTAMP()","NOW()", $query);
				$query=str_ireplace("UNIX_TIMESTAMP(","TO_TIMESTAMP(", $query);
				if(!pg_prepare($conn, "stmt".$index, $query)){
					throw new \Exception("Preparation of statement failed: ".pg_last_error($conn));
				}
				if(!$result=pg_execute($conn, "stmt".$index, $statement['vars'])){
					throw new \Exception("Execution of prepared statement failed: ".pg_last_error($conn));
				}
				foreach($result as $key=>$value){
					$fieldType=pg_field_type($result, pg_field_num($result, $key));
					if($fieldType==='bool'){
						$result[$key]=$value==='t'?true:false;
					}
					elseif($fieldType==='int4' || $fieldType==='int8'){
						$result[$key]=(int)$value;
					}
				}
				$results[$index]=pg_fetch_all($result);
			}
			if(!pg_query($conn, "COMMIT")){
				throw new \Exception("Failed commiting transaction: ".pg_last_error($conn));
			}
		}catch(Throwable $e){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query has failed and will be rolled back", "fatal");
			if(!pg_query($conn, "ROLLBACK")){
				throw new \Exception("Rollback failed: ".pg_last_error($conn));
			}
			return false;
		}
		return true;
	}

	private static function execute_multi_query_string(object $conn, string $multi_query_string, array &$results): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$queries=explode(";", $multi_query_string); // Split the multi-query string into individual queries based on a delimiter
		$index=0;
		foreach($queries as $query){
			$query=trim($query);
			if(empty($query))continue;
			// Start: Basic MySQL compatibility layer
			$query=preg_replace_callback('/\?/', function($matches){static $index=0;return'$'.(++$index);}, $query);
			$query=preg_replace('/RAND\(\)/i', 'RANDOM()', $query);
			$query=str_ireplace("UNIX_TIMESTAMP()","NOW()", $query);
			$query=str_ireplace("UNIX_TIMESTAMP(","TO_TIMESTAMP(", $query);
			// End: Basic MySQL compatibility layer
			try{
				if(!pg_send_query($conn, $query)){
					throw new \Exception("Query failed: ".pg_last_error($conn));
				}
				while($result=pg_get_result($conn)){
					if($result){
						foreach($result as $key=>$value){
							$fieldType=pg_field_type($result, pg_field_num($result, $key));
							if($fieldType==='bool'){
								$result[$key]=$value==='t'?true:false;
							}
							elseif($fieldType==='int4' || $fieldType==='int8'){
								$result[$key]=(int)$value;
							}
						}
						if($error=pg_result_error($result)){
							throw new \Exception("Query failed: ".pg_last_error($conn));
							$results[$index]=['error'=>$error];
							if(!pg_free_result($result)){
								throw new \Exception("Failed freeing result");
							}
							continue;
						}
						$fetchedResults=pg_fetch_all($result, PGSQL_ASSOC);
						$results[$index]=$fetchedResults?$fetchedResults:[];
						pg_free_result($result);
					}
					$index++;
				}
			}catch(Throwable $e){
				log_error("PgSQL exception", $ex);
			}
		}
	}
	
	private static function process_results(?array $results, ?array $queries) : void {
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
					}
					elseif($queries[$index]['clear_cache']!==null){
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
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__,$T=null,$S='function_call',$A=func_get_args()); // Log the function call
        if(!isset(self::$queued_queries[$queue]))return false;
        $queued_queries=self::$queued_queries[$queue];
        unset(self::$queued_queries[$queue]);
        global $configurations;
        $multipoint=false;
        $prepared_statements=[];
        $multi_query_string="";
        $index=1;
        foreach($queued_queries as $query_type=>$query_info_array){
            foreach($query_info_array as $query_info){
                switch($query_type){
                    case 'select':
                        $query_info['query']="SELECT {$query_info['select']} FROM {$query_info['location']} {$query_info['params']}";
                        break;
                    case 'insert':
                        $placeholders=array_fill(0,count(explode(',',$query_info['fields'])),'$'.$index++);
                        $query_info['query']="INSERT INTO {$query_info['location']} ({$query_info['fields']}) VALUES (".implode(',',$placeholders).")";
                        break;
                    case 'update':
                        $fields=explode(',',$query_info['fields']);
                        $query_info['query']="UPDATE {$query_info['location']} SET ".implode(',',array_map(function($field)use(&$index){return trim($field).'=$'.($index++);},$fields))." {$query_info['params']}";
                        break;
                    case 'count':
                        $query_info['query']="SELECT COUNT(*) as c FROM {$query_info['location']} {$query_info['params']}";
                        break;
                    case 'delete':
                        $query_info['query']="DELETE FROM {$query_info['location']} {$query_info['params']}";
                        break;
                }
                if(isset($query_info['vars'])&&is_array($query_info['vars'])){
                    $prepared_statements[]=['query'=>$query_info['query'],'vars'=>$query_info['vars']];
                }
				else
				{
                    $multi_query_string.=$query_info['query']."; ";
                }
                if(isset($query_info['multipoint'])&&$query_info['multipoint']) $multipoint=true;
            }
        }
        $results=[];
        $dbms_cluster=$configurations['dataphyre']['sql']['tables']['raw']['cluster']??$configurations['dataphyre']['sql']['default_cluster'];
        if($multipoint){
			$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
			if(!empty($prepared_statements)){
				foreach($endpoints as $endpoint){
					$conn=self::connect_to_endpoint($endpoint);
					if(!self::execute_prepared_statements($conn, $prepared_statements, $results))return false;
				}
			}
			else
			{
				foreach($endpoints as $endpoint){
					$conn=self::connect_to_endpoint($endpoint);
					self::execute_multi_query_string($conn, $multi_query_string, $results);
				}
			}
        }
		else
		{
            $conn=self::connect_to_cluster($dbms_cluster);
            if(!empty($prepared_statements)){
                if(!self::execute_prepared_statements($conn,$prepared_statements,$results))return false;
            }
			else
			{
                self::execute_multi_query_string($conn,$multi_query_string,$results);
            }
        }
        self::process_results($results, $queued_queries);
        return true;
    }
	
	public static function postgresql_query(string $dbms_cluster, string $query, array|null $vars, bool|null $associative, bool|null $multipoint=true): bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_SELECT", ...func_get_args())) return $early_return;
		$execute_query=function($conn) use ($query, $vars, $associative){
			try{
				if(is_array($vars)){
					// Start: Basic MySQL compatibility layer
					$query=preg_replace_callback('/\?/', function($matches){static $index=0;return'$'.(++$index);}, $query);
					$query=preg_replace('/RAND\(\)/i', 'RANDOM()', $query);
					$query=str_ireplace("UNIX_TIMESTAMP()","NOW()", $query);
					$query=str_ireplace("UNIX_TIMESTAMP(","TO_TIMESTAMP(", $query);
					// End: Basic MySQL compatibility layer
					if(!$stmt=pg_prepare($conn, "", $query)){
						throw new \Exception("Failed to prepare statement: ".pg_last_error($conn));
					}
					if(!$result=pg_execute($conn, "", $vars)){
						throw new \Exception("Failed to execute statement: ".pg_last_error($conn));
					}
				}
				else
				{
					if(!$result=pg_query($conn, $query)){
						throw new \Exception("Query failed: ".pg_last_error($conn));
					}
				}
			}catch(Throwable $ex){
				log_error("PgSQL Exception", $ex);
			}
			if($result===false){
				return false;
			}
			return $result;
		};
		if($multipoint===true){
			$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
			foreach($endpoints as $endpoint){
				$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, 'pgsql');
				$result=$execute_query($conn);
			}
		}
		else
		{
			$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster, 'pgsql');
			try{
				$result=$execute_query($conn);
			}catch(Throwable $ex){
				log_error("PgSQL exception", $ex);
			}
		}
		if($result===false){
			return false;
		}
		$query_result=[];
		if($associative!==true){
			while($row=pg_fetch_assoc($result)){
				foreach($row as $key=>$value)if(pg_field_type($result, pg_field_num($result, $key))==='bool')$row[$key]=$value==='t'?true:false; // Hack to convert pg's stringed booleans to true booleans
				$query_result[]=$row;
				break;
			}
		}
		else
		{
			while($row=pg_fetch_assoc($result)){
				foreach($row as $key=>$value)if(pg_field_type($result, pg_field_num($result, $key))==='bool')$row[$key]=$value==='t'?true:false; // Hack to convert pg's stringed booleans to true booleans
				$query_result[]=$row;
			}
		}
		pg_free_result($result);
		return $query_result;
	}
	
	public static function postgresql_select(string $dbms_cluster, string $select, string $location, string|null $params, array|null $vars, bool|null $associative): bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_SELECT", ...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		$query_result=[];
		$query="SELECT ".$select." FROM ".$location." ".$params;
		try{
			// Start: Basic MySQL compatibility layer
			$query=preg_replace_callback('/\?/', function($matches){static $index=0;return'$'.(++$index);}, $query);
			$query=preg_replace('/RAND\(\)/i', 'RANDOM()', $query);
			$query=str_ireplace("UNIX_TIMESTAMP()","NOW()", $query);
			$query=str_ireplace("UNIX_TIMESTAMP(","TO_TIMESTAMP(", $query);
			// End: Basic MySQL compatibility layer
			if(is_array($vars) && count($vars)>0){
				if(!$stmt=pg_prepare($conn, "", $query)){
					throw new \Exception("Failed to prepare statement: ($query) ".pg_last_error($conn));
				}
				if(!$result=pg_execute($conn, "", $vars)){
					throw new \Exception("Failed to execute statement: ($query) ".pg_last_error($conn));
				}
			}
			else
			{
				if(!$result=pg_query($conn, $query)){
					throw new \Exception("Query failed: ($query)".pg_last_error($conn));
				}
			}
		}catch(Throwable $ex){
			log_error("PgSQL Exception", $ex);
		}
		if($result===false){
			return false;
		}
		if($associative!==true){
			$query_result=pg_fetch_assoc($result);
			foreach($query_result as $key=>$value){
				$fieldType=pg_field_type($result, pg_field_num($result, $key));
				if ($fieldType==='bool'){
					$query_result[$key]=$value === 't' ? true : false;
				}
				elseif($fieldType==='int4' || $fieldType==='int8'){
					$query_result[$key]=(int)$value;
				}
			}
		}
		else
		{
			while($row=pg_fetch_assoc($result)){
				foreach($row as $key=>$value){
					$fieldType=pg_field_type($result, pg_field_num($result, $key));
					if ($fieldType==='bool'){
						$row[$key]=$value === 't' ? true : false;
					}
					elseif($fieldType==='int4' || $fieldType==='int8'){
						$row[$key]=(int)$value;
					}
				}
				$query_result[]=$row;
			}
		}
		return $query_result ?: false;
	}

	public static function postgresql_count(string $dbms_cluster, string $location, string $params, ?array $vars): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_COUNT", ...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		$count=0;
		$query="SELECT COUNT(*) as count FROM ".$location." ".$params;
		try{
			if(is_array($vars) && count($vars)>0){
				// Start: Basic MySQL compatibility layer
				$query=preg_replace_callback('/\?/', function($matches){static $index=0;return'$'.(++$index);}, $query);
				$query=str_ireplace("UNIX_TIMESTAMP()","NOW()", $query);
				$query=str_ireplace("UNIX_TIMESTAMP(","TO_TIMESTAMP(", $query);
				// End: Basic MySQL compatibility layer
				if(!$stmt=pg_prepare($conn, "", $query)){
					throw new \Exception("Failed to prepare statement: ".pg_last_error($conn));
				}
				if(!$result=pg_execute($conn, "", $vars)){
					throw new \Exception("Failed to execute statement: ".pg_last_error($conn));
				}
			}
			else
			{
				if(!$result=pg_query($conn, $query)){
					throw new \Exception("Query failed: ".pg_last_error($conn));
				}
			}
		}catch(Throwable $ex){
			log_error("PgSQL Exception", $ex);
		}
		if($result===false){
			return $count;
		}
		if($row=pg_fetch_assoc($result)){
			$count=$row['count'];
		}
		return (int)$count;
	}
	
	public static function postgresql_update(string $dbms_cluster, string $location, string $fields, string $params, array $vars): bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_UPDATE", ...func_get_args())) return $early_return;
		$i=0;
		$is_multipoint=$configurations['dataphyre']['sql']['tables'][$location]['multipoint_writes']??false;
		$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		foreach($endpoints as $endpoint){
			try{
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
				$query="UPDATE ".$location." SET ".$fields." ".$params;
				// Start: Basic MySQL compatibility layer
				$query=preg_replace_callback('/\?/', function($matches){static $index=0;return'$'.(++$index);}, $query);
				$query=str_ireplace("UNIX_TIMESTAMP()","NOW()", $query);
				$query=str_ireplace("UNIX_TIMESTAMP(","TO_TIMESTAMP(", $query);
				// Start: End MySQL compatibility layer
				if(!$stmt=pg_prepare($conn, "", $query)){
					throw new \Exception("Failed to prepare statement: ".pg_last_error($conn));
				}
				if(!$result=pg_execute($conn, "", $vars)){
					throw new \Exception("Failed to execute statement: ".pg_last_error($conn));
				}
				$i++;
			}catch(Throwable $ex){
				log_error("PgSQL Exception", $ex);
			}
			if(!$is_multipoint) break;
		}
		return $i >= 1 ? $i : false;
	}
	
	public static function postgresql_insert(string $dbms_cluster, string $location, string $fields, array $vars, int $retry_count=3): mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_INSERT", ...func_get_args())) return $early_return;
		$is_multipoint=$configurations['dataphyre']['sql']['tables'][$location]['multipoint_writes']??false;
		$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		$result_key=false;
		foreach($endpoints as $endpoint){
			try{
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
				$placeholders=array_map(function($k){ return '$'.($k+1); }, array_keys($vars));
				$return_column=$configurations['dataphyre']['sql']['tables'][$location]['primary_column']??null;
				if(isset($return_column)){
					$query="INSERT INTO ".$location." (".$fields.") VALUES (".implode(", ", $placeholders).") ON CONFLICT DO NOTHING RETURNING ".$return_column;
				}
				else
				{
					$query="INSERT INTO ".$location." (".$fields.") VALUES (".implode(", ", $placeholders).") ON CONFLICT DO NOTHING";
				}
				// Start: Basic MySQL compatibility layer
				$query=str_ireplace("UNIX_TIMESTAMP()","NOW()", $query);
				$query=str_ireplace("UNIX_TIMESTAMP(","TO_TIMESTAMP(", $query);
				// End: Basic MySQL compatibility layer
				if(!$stmt=pg_prepare($conn, "", $query)){
					throw new \Exception("Failed to prepare statement: ".pg_last_error($conn));
				}
				if(!$result=pg_execute($conn, "", $vars)){
					throw new \Exception("Failed to execute statement: ".pg_last_error($conn));
				}
				$row=pg_fetch_assoc($result);
				$result_key=true;
				if(isset($return_column)){
					$result_key=$row[$return_column];
				}
			}catch(Throwable $ex){
				log_error("PgSQL Exception", $ex);
				if($retry_count>0 && strpos($ex->getMessage(), 'unique constraint')!==false && strpos($ex->getMessage(), 'uuid')!==false){
					$retry_count--;
					log_error("Retrying insert due to UUID constraint violation. Retries left: {$retry_count}", $ex);
					return self::postgresql_insert($dbms_cluster, $location, $fields, $vars, $retry_count);
				}
			}
			if(!$is_multipoint)break;
		}
		return $result_key;
	}
	
	public static function postgresql_delete(string $dbms_cluster, string $location, string $params, ?array $vars): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_DELETE", ...func_get_args())) return $early_return;
		$i=0;
		$is_multipoint=$configurations['dataphyre']['sql']['tables'][$location]['multipoint_writes'] ?? false;
		$endpoints=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['endpoints'];
		shuffle($endpoints);
		foreach($endpoints as $endpoint){
			try{
				$conn=(!$is_multipoint && isset(self::$conns[$dbms_cluster])) ? self::$conns[$dbms_cluster] : self::connect_to_endpoint($endpoint, $dbms_cluster);
				$query="DELETE FROM ".$location." ".$params;
				// Start: Basic MySQL compatibility layer
				$query=str_ireplace("UNIX_TIMESTAMP()","NOW()", $query);
				$query=str_ireplace("UNIX_TIMESTAMP(","TO_TIMESTAMP(", $query);
				// End: Basic MySQL compatibility layer
				if(!empty($vars)){
					$query=preg_replace_callback('/\?/', function($matches){static $index=0;return'$'.(++$index);}, $query);
					if(!$stmt=pg_prepare($conn, "", $query)){
						throw new \Exception("Failed to prepare statement: ".pg_last_error($conn));
					}
					if(!$result=pg_execute($conn, "", $vars)){
						throw new \Exception("Failed to execute statement: ".pg_last_error($conn));
					}
					$i++;
				}
				else
				{
					if(!$result=pg_query($conn, $query)){
						throw new \Exception("Query failed: ".pg_last_error($conn));
					}
					$i++;
				}
			}catch(Throwable $ex){
				log_error("PgSQL Exception", $ex);
			}
			if(!$is_multipoint) break;
		}
		return $i>=1;
	}
	
}