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
				try {
					postgresql_query_builder::execute_multiquery($queue);
				} catch (\Throwable $exception) {
					log_error("PostgreSQL Queued Query Execution Error", $exception);
				}
			}
		}while(!empty(postgresql_query_builder::$queued_queries));
	ob_end_clean();
});

class postgresql_query_builder {
	
	public static $conns=[];
	public static $queued_queries=[];
	
	private static function mysql_compatibility_layer(string $query='') : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$query=preg_replace_callback('/\?/', function($matches){static $index=0;return'$'.(++$index);}, $query);
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
		$password=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['password'];
		$password??=core::get_password($endpoint);
		$conn_string="host=$endpoint port=$port dbname=$database user=$username password=$password options='--client_encoding=UTF8 --timezone=UTC' connect_timeout=1";
		if(!$conn=pg_connect($conn_string)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed connecting to $endpoint", $S="warning");
			sql::flag_server_unavailable($endpoint);
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="PostgreSQL connection to $endpoint successful");
		return self::$conns[$dbms_cluster]=$conn;
	}

	private static function execute_prepared_statements(object $conn, array $prepared_statements, array &$results, string $dbms_cluster='n/a'): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$has_write=sql::query_has_write(serialize($prepared_statements));
		try{
			if($has_write && !pg_query($conn, "BEGIN")){
				throw new \Exception("Failed initiating transaction");
			}
			foreach($prepared_statements as $index=>$statement){
				$statementid=uuid();
				$query=self::mysql_compatibility_layer($statement['query']);
				if(!pg_prepare($conn, "stmt".$statementid, $query)){
					throw new \Exception("Preparation of statement failed: ".pg_last_error($conn));
				}
				if(!$result=pg_execute($conn, "stmt".$statementid, $statement['vars'])){
					throw new \Exception("Execution of prepared statement failed: ".pg_last_error($conn));
				}
				if($result instanceof \PgSql\Result){
					if(pg_num_fields($result)===0){
						$results[$index]=true;
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

	private static function execute_multi_query_string(object $conn, string $multi_query_string, array &$results, string $dbms_cluster='n/a'): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Skipping invalid query at index $index", $S="warning");
				continue;
			}
			$associative=$query['associative'] ?? null;
			if(is_array($result)){
				$result=empty($result) || !is_array($result) ? false : ($associative === false ? $result[0] : $result);
			}
			if($query['type']==='count' && isset($result[0]['count'])){
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
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Calling callback".$result);
				$query['callback']($result);
			}
		}
	}
	
    public static function execute_multiquery(string $queue='') : null|bool {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__,$T=null,$S='function_call',$A=func_get_args()); // Log the function call
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
                        break;
                    case 'insert':
						$placeholders=array_map(fn($i)=>'$'.$i, range(1, substr_count($query_info['fields'], ',')+1));
						$query_info['query']="INSERT INTO {$query_info['location']} ({$query_info['fields']}) VALUES (".implode(", ", $placeholders).") ON CONFLICT DO NOTHING RETURNING ".$query_info['returning'];
                        break;
                    case 'update':
                        $fields=explode(',',$query_info['fields']);
                        $query_info['query']="UPDATE {$query_info['location']} SET ".implode(',',array_map(function($field)use(&$index2){return trim($field).'=$'.($index2++);},$fields))." {$query_info['params']}";
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
            $conn=self::connect_to_cluster($dbms_cluster);
            if(!empty($prepared_statements)){
                if(!self::execute_prepared_statements($conn,$prepared_statements,$results, $dbms_cluster))return false;
            }
			else
			{
                self::execute_multi_query_string($conn,$multi_query_string,$results, $dbms_cluster);
            }
        }
        self::process_results($results, $queued_queries);
        return true;
    }
	
	public static function postgresql_query(string $dbms_cluster, string $query, ?array $vars, ?bool $associative, bool $multipoint=true): bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_SELECT", ...func_get_args())) return $early_return;
		$execute_query=function($conn) use ($query, $vars, $associative, $dbms_cluster){
			try{
				if(is_array($vars)){
					$query=self::mysql_compatibility_layer($query);
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
			}catch(\Throwable $exception){
				sql::log_query_error('PostgreSQL', $dbms_cluster, $query, $vars, $exception);
			}
			return $result;
		};
		if($multipoint===true){
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
	
	public static function postgresql_select(string $dbms_cluster, string $select, string $location, ?string $params, ?array $vars, ?bool $associative): bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_SELECT", ...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		$query_result=[];
		$query="SELECT ".$select." FROM ".$location." ".$params;
		try{
			$query=self::mysql_compatibility_layer($query);
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

	public static function postgresql_count(string $dbms_cluster, string $location, string $params, ?array $vars): bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_POSTGRESQL_SIMPLE_COUNT", ...func_get_args())) return $early_return;
		$conn=isset(self::$conns[$dbms_cluster]) ? self::$conns[$dbms_cluster] : self::connect_to_cluster($dbms_cluster);
		$count=false;
		$query="SELECT COUNT(*) as count FROM ".$location." ".$params;
		try{
			if(is_array($vars) && count($vars)>0){
				$query=self::mysql_compatibility_layer($query);
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
				$query=self::mysql_compatibility_layer($query);
				if(!$stmt=pg_prepare($conn, "", $query)){
					throw new \Exception("Failed to prepare statement: ".pg_last_error($conn));
				}
				if(!$result=pg_execute($conn, "", $vars)){
					throw new \Exception("Failed to execute statement: ".pg_last_error($conn));
				}
				$i++;
			}catch(\Throwable $exception){
				sql::log_query_error('PostgreSQL', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint) break;
		}
		return $i >= 1 ? $i : false;
	}
	
	public static function postgresql_insert(string $dbms_cluster, string $location, string $fields, array $vars, string $returning='*', int $retry_count=3): array|bool {
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
				$query="INSERT INTO ".$location." (".$fields.") VALUES (".implode(", ", $placeholders).") ON CONFLICT DO NOTHING RETURNING ".$returning;
				$query=self::mysql_compatibility_layer($query);
				if(!$stmt=pg_prepare($conn, "", $query)){
					throw new \Exception("Failed to prepare statement: ".pg_last_error($conn));
				}
				if(!$result=pg_execute($conn, "", $vars)){
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
				$query=self::mysql_compatibility_layer($query);
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
			}catch(\Throwable $exception){
				sql::log_query_error('PostgreSQL', $dbms_cluster, $query, $vars, $exception);
			}
			if(!$is_multipoint) break;
		}
		return $i>=1;
	}
	
}