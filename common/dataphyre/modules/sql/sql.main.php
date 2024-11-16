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

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

if(file_exists($filepath=$rootpath['common_dataphyre']."config/sql.php")){
	require($filepath);
}
if(file_exists($filepath=$rootpath['dataphyre']."config/sql.php")){
	require($filepath);
}
if(!isset($configurations['dataphyre']['sql'])){
	core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreSQL: No configuration.', 'safemode');
}

require(__DIR__."/mysql_query.php");
require(__DIR__."/postgresql_query.php");
require(__DIR__."/sqlite_query.php");

$_SESSION['db_cache_count']=0;
if(!isset($_SESSION['db_cache']) || !is_array($_SESSION['db_cache'])){
	$_SESSION['db_cache']=[];
}
else
{
	while(count($_SESSION['db_cache'])>500){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Limiting amount of tables cached in session variable", $S="warning");
		array_shift($_SESSION['db_cache']);
	}
	foreach($_SESSION['db_cache'] as $location=>$data){
		$_SESSION['db_cache_count']+=count($data);
		if(count($data)>128){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Limiting amount of entries in table \"$location\" cached in session variable", $S="warning");
			array_shift($_SESSION['db_cache'][$location]);
		}
	}
	unset($key, $location);
}

if(file_exists($rootpath['common_dataphyre']."sql_migration/migrating")){
	if(!$is_task){
		core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='Database migration ongoing', 'maintenance');
	}
}
else
{
	if(file_exists($rootpath['common_dataphyre']."sql_migration/run_migrations")){
		file_put_contents($rootpath['common_dataphyre']."sql_migration/migrating", '');
		file_put_contents($rootpath['common_dataphyre']."sql_migration/rootpaths.php", "<?php\n\$rootpath=".var_export($rootpath, true).";\n");
		exec("php ".__DIR__."/migration.php > /dev/null 2> /dev/null &", $process_pid);
		core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='Database migration ongoing', 'maintenance');
	}
}

class sql {

	public function __Construct($dbms_cluster="sql"){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_SQL_CONSTRUCT",...func_get_args())) return $early_return;
		self::migration();
	}
	
	public static function migration(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $rootpath;
		global $is_task;
		if(file_exists($rootpath['common_dataphyre']."sql_migration/migrating")){
			if(!$is_task){
				core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='Database migration ongoing', 'maintenance');
			}
		}
		else
		{
			if(file_exists($rootpath['common_dataphyre']."sql_migration/run_migrations")){
				file_put_contents($rootpath['common_dataphyre']."sql_migration/migrating", '');
				file_put_contents($rootpath['common_dataphyre']."sql_migration/rootpaths.php", "<?php\n\$rootpath=".var_export($rootpath, true).";\n");
				exec("php ".__DIR__."/migration.php > /dev/null 2> /dev/null &", $process_pid);
				core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='Database migration ongoing', 'maintenance');
			}
		}
	}
	
	public static function get_table_cache_policy(string $location):array|bool{
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_GET_TABLE_CACHE_POLICY",...func_get_args())) return $early_return;
		global $configurations;
		if(isset($configurations['dataphyre']['sql']['tables'][$location]['caching'])){
			$cache_policy=$configurations['dataphyre']['sql']['tables'][$location]['caching'];
			if($cache_policy===false)return false;
		}
		if(isset($cache_policy) && $cache_policy['type']==='session' && RUN_MODE!=='request'){
			return $configurations['dataphyre']['sql']['caching']['default_policy'];
		}
		if(!empty($cache_policy['type'])){
			return $cache_policy;
		}
		return $configurations['dataphyre']['sql']['caching']['default_policy'];
	}
	
	public static function execute_queue(string $queue='end') : void {
		mysql_query_builder::execute_multiquery($queue);
		postgresql_query_builder::execute_multiquery($queue);
	}
	
	public static function flag_server_unavailable(string $serverip) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_FLAG_SERVER_UNAVAILABLE",...func_get_args())) return $early_return;
		$_SESSION['unavailable_servers'][$serverip]=microtime();
		return true;
	}

	public static function is_server_available(string $serverip) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_IS_SERVER_AVAILABLE",...func_get_args())) return $early_return;
		$_SESSION['unavailable_servers']??=[];
		if(isset($_SESSION['unavailable_servers'][$serverip])){
			if(strtotime($_SESSION['unavailable_servers'][$serverip])>strtotime("-5 seconds")){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Server ".$serverip." is known as not being available", $S="warning");
				return false;
			}
		}
		return true;
	}
	
	public static function get_query_cached_result(string $location, string $hash, array|bool|null $cache_policy=null) : mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if($cache_policy===null){
			$cache_policy=self::get_table_cache_policy($location);
		}
		if($cache_policy!==false){
			if($cache_policy['type']==="shared_cache"){
				if(dp_module_present('cache')){
					$table_cache_version=(int)cache::get('table_version_'.$location);
					if($table_cache_version>2147483600)cache::set('table_version_'.$location, $table_cache_version=0); // Prevent integer overflow by resetting table version
					if(is_array($shared_cache_result=cache::get($key=$location.'_'.$hash))){
						if($shared_cache_result[0]===$table_cache_version){
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Read from shared cache (".$key.")");
							$_SESSION['queries_retrieved_from_cache']??=0;
							$_SESSION['queries_retrieved_from_cache']++;
							if($shared_cache_result[1]==="false")return false;
							return $shared_cache_result[1];
						}
					}
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query not cached in shared cache (".$key.")", $S="warning");
					return null;
				}
				\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Shared cache requires dataphyre's cache module", $S="safemode");
				return null;
			}
			elseif($cache_policy['type']==="session"){
				if(isset($_SESSION['db_cache'][$location][$hash])){
					if($_SESSION['db_cache'][$location][$hash][1]>=strtotime("-".$cache_policy['max_lifespan'])){
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Read from session cache");
						$cached_result=$_SESSION['db_cache'][$location][$hash][0];
						$_SESSION['queries_retrieved_from_cache']??=0;
						$_SESSION['queries_retrieved_from_cache']++;
						return $cached_result;
					}
					unset($_SESSION['db_cache'][$location][$hash]);
				}
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query not cached in session", $S="warning");
				return null;
			}
			elseif($cache_policy['type']==="fs"){
				$cache_file=__DIR__."/../../cache/sql/".$location."/".$hash;
				if(file_exists($cache_file)){
					if(false!==$fs_cache=file_get_contents($cache_file)){
						$fs_cache=json_decode($fs_cache, true);
						if($fs_cache[1]>=strtotime("-".$cache_policy['max_lifespan'])){
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Read from filesystem cache");
							$cached_result=$fs_cache[0];
							$_SESSION['queries_retrieved_from_cache']??=0;
							$_SESSION['queries_retrieved_from_cache']++;
							return $cached_result;
						}
						unlink($cache_file);
					}
				}
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query not cached in filesystem", $S="warning");
				return null;
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Location $location is not cachable", $S="warning");
		return null;
	}
	
	public static function cache_query_result(string $location, string $hash, mixed $query_result, array $caching=[true], array|bool|null $cache_policy=null) : mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if($cache_policy===null){
			$cache_policy=self::get_table_cache_policy($location);
		}
		if($cache_policy!==false){
			if($cache_policy['type']==='shared_cache'){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Caching in shared cache");
				if($query_result===false)$query_result='false';
				$table_cache_version=(int)cache::get('table_version_'.$location);
				if($table_cache_version>2147483600)cache::set('table_version_'.$location, $table_cache_version=0); // Prevent integer overflow by resetting table version
				cache::set($location.'_'.$hash, array($table_cache_version,$query_result), strtotime('+'.$cache_policy['max_lifespan']));
			}
			elseif($cache_policy['type']==='session'){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Caching in session");
				$_SESSION['db_cache'][$location][$hash]=array($query_result, time());
				if($_SESSION['db_cache_count']>=$configurations['dataphyre']['sql']['caching']['rolling_db_cache_size']){
					array_shift($_SESSION['db_cache']);
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Rolled session cache");
				}
				else
				{
					$_SESSION['db_cache_count']++;
				}
			}
			elseif($cache_policy['type']==="fs"){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Caching in filesystem");
				$cache_file=__DIR__."/../../cache/sql/".$location."/".$hash;
				core::file_put_contents_forced($cache_file, json_encode(array($query_result, time()), JSON_UNESCAPED_UNICODE));
				$_SESSION['db_cache_count']++;
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown cache policy type for table $location", $S="fatal");
				return false;
			}
			foreach($caching as $cache_index){
				if(is_bool($cache_index)===false){
					$_SESSION['db_cache_invalidation_index'][$cache_index][]=[$cache_policy['type'],$location,$hash];
				}
			}
			return true;
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Location $location is not cachable", $S="warning");
		return false;
	}
	
	public static function invalidate_cache(array|string $clear_cache_for, array|bool|null $cache_policy=null) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if($cache_policy===null){
			if(is_string($clear_cache_for)){
				$cache_policy=self::get_table_cache_policy($clear_cache_for);
			}
		}
		if($cache_policy!==false){
			if(is_string($clear_cache_for)){
				if($cache_policy['type']==="shared_cache"){
					cache::increment('table_version_'.$clear_cache_for);
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Cleared shared cache for table $clear_cache_for");
				}
				elseif($cache_policy['type']==="session"){
					$_SESSION['db_cache'][$clear_cache_for]??=[];
					$_SESSION['db_cache_count']-=count($_SESSION['db_cache'][$clear_cache_for]);
					unset($_SESSION['db_cache'][$clear_cache_for]);
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Cleared session cache for table $clear_cache_for");
				}
				elseif($cache_policy['type']==="fs"){
					core::force_rmdir(__DIR__."/../../cache/sql/".$clear_cache_for."/");
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Cleared filesystem cache for table $clear_cache_for");
				}
				else
				{
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown cache policy type for table", $S="fatal");
					return false;
				}
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="clear_cache_for parameter must be a string if valid cache policy parameter is given", $S="fatal");
				return false;
			}
		}
		else
		{
			foreach($clear_cache_for as $clear_cache_index){
				foreach($_SESSION['db_cache_invalidation_index'][$clear_cache_index] as $invalidation_cache){
					if($invalidation_cache[0]==='shared_cache'){
						unset($_SESSION['db_cache'][$invalidation_cache[0]][$invalidation_cache[1]]);
					}
					elseif($invalidation_cache[0]==='session'){
						cache::delete($invalidation_cache[0].'_'.$invalidation_cache[1]);
					}
					elseif($invalidation_cache[0]==='fs'){
						unlink(__DIR__."/../../cache/sql/".$invalidation_cache[0]."/".$invalidation_cache[1]);
					}
					$_SESSION['db_cache_count']--;
				}
				unset($_SESSION['db_cache_invalidation_index'][$clear_cache_index]);
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Cleared shared cache for invalidation index $clear_cache_index");
			}
		}
		return true;
	}
	
    public static function db_query(string|array $query, ?array $vars, ?bool $associative=false, $multipoint=false, null|bool|array|string $caching=[true], bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null) : mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_SELECT",...func_get_args())) return $early_return;
		global $configurations;
		$location='raw';
		if($caching!==false){
			if(is_array($caching)===false)$caching=[$caching];
			if(false!==$cache_policy=self::get_table_cache_policy($location)){
				if($cache_policy['hash_type']==='sha256'){
					$hash=hash('sha256', $query.json_encode($vars).intval($associative).intval($multipoint));
				}
				else
				{
					$hash=md5($query.json_encode($vars).intval($associative).intval($multipoint));
				}
				if(null!==$cache=self::get_query_cached_result($location, $hash, $cache_policy)){
					if(null!==$callback)$callback($cache);
					return $cache;
				}
			}
		}
		if($clear_cache===null)$clear_cache=false;
		if($clear_cache!==false){
			if(is_array($clear_cache)===false)$clear_cache=[$clear_cache];
		}
		$dbms_cluster=$configurations['dataphyre']['sql']['tables'][$location]['cluster']??$configurations['dataphyre']['sql']['default_cluster'];
		$dbms=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms'];
		if(is_array($query)){
			if(!isset($query[$dbms])){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query has no compatibility for DBMS ($dbms) for location $location. Stack trace:\n".json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)), $S="fatal");
				return false;
			}
			$query=$query[$dbms];
		}
		if(is_array($vars)){
			if(isset($vars[$dbms])){
				$vars=$vars[$dbms];
			}
		}
		switch($dbms){
			case"mysql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					mysql_query_builder::$queued_queries[$queue]['raw'][]=[
						'location'=>$location, 
						'query'=>$query,
						'vars'=>$vars,
						'associative'=>$associative, 
						'caching'=>$caching,
						'multipoint'=>$multipoint,
						'clear_cache'=>$clear_cache,
						'callback'=>$callback,
						'hash'=>$hash
					];
					return null;
				}
				$query_result=mysql_query_builder::simple_query($dbms_cluster, $query, $vars, $associative, $multipoint);
				break;
			case"postgresql":
				if($callback){
					postgresql_query_builder::$queued_queries[$queue]['raw'][]=[
						'location'=>$location, 
						'query'=>$query,
						'vars'=>$vars,
						'associative'=>$associative, 
						'caching'=>$caching,
						'multipoint'=>$multipoint,
						'clear_cache'=>$clear_cache,
						'callback'=>$callback,
						'hash'=>$hash
					];
					return null;
				}
				$query_result=postgresql_query_builder::postgresql_query($dbms_cluster, $query, $vars, $associative, $multipoint);
				break;
			case"sqlite":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					sqlite_query_builder::$queued_queries[$queue]['raw'][]=[
						'location'=>$location, 
						'query'=>$query,
						'vars'=>$vars,
						'associative'=>$associative, 
						'caching'=>$caching,
						'multipoint'=>$multipoint,
						'clear_cache'=>$clear_cache,
						'callback'=>$callback,
						'hash'=>$hash
					];
					return null;
				}
				$query_result=sqlite_query_builder::simple_query($dbms_cluster, $query, $vars, $associative, $multipoint);
				break;
		}
		if($caching!==false && $cache_policy!==false){
			self::cache_query_result($location, $hash, $query_result, $caching, $cache_policy);
		}
		if($query_result!==false){
			if($clear_cache===true){
				self::invalidate_cache($location, $cache_policy);
			}
			else
			{
				self::invalidate_cache($clear_cache);
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Raw query finished, returning result");
		return $query_result;
    }
	
	public static function db_select(string|array $select, string $location, array|string|null $params=null, ?array $vars=null, ?bool $associative=false, null|bool|array|string $caching=[true], ?string $queue='end', ?callable $callback=null) : mixed { //bool|array|null
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_SELECT",...func_get_args())) return $early_return;
		global $configurations;
		list($query_dbms, $location)=strpos($location, ':')!==false?explode(':', $location, 2):[null, $location];
		if(str_contains($location, '.')===false)$location=$configurations['dataphyre']['sql']['default_database_location'].".".$location;
		if($caching!==false){
			if(!is_array($caching))$caching=[$caching];
			if(false!==$cache_policy=self::get_table_cache_policy($location)){
				$cache_checks=[];
				if(is_string($select) && !str_contains($select, '(') && $select!=='*')$cache_checks[]='*';
				$cache_checks[]=$select;
				foreach($cache_checks as $select_alt){
					if($cache_policy['hash_type']==='sha256'){
						$hash=hash('sha256', json_encode($select_alt).$location.json_encode($params).json_encode($vars).intval($associative));
					}
					else
					{
						$hash=md5(json_encode($select_alt).$location.json_encode($params).json_encode($vars).intval($associative));
					}
					if(null!==$cache=self::get_query_cached_result($location, $hash, $cache_policy)){
						if(is_integer($cache)){
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unexpected cached query result, possible hash collision. Returning false.", $S="fatal");
							return false;
						}
						if($associative===true && is_array($cache)){
							foreach($cache as $item){
								if(!is_array($item)){
									tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Cached query result is not a multidimensional array as expected, possible hash collision. Returning false.", $S="fatal");
									return false;
								}
							}
						}
						if(null!==$callback)$callback($cache);
						return $cache;
					}
				}
			}
		}
		$dbms_cluster=$configurations['dataphyre']['sql']['tables'][$location]['cluster']??$configurations['dataphyre']['sql']['default_cluster'];
		$dbms=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms'];
		if(is_array($select)){
			if(!isset($select[$dbms])){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query's selection has no compatibility for DBMS ($dbms) for location $location. Stack trace:\n".json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)), $S="fatal");
				return false;
			}
			$select=$select[$dbms];
		}
		if(is_array($params)){
			if(!isset($params[$dbms])){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query's parameters has no compatibility for DBMS ($dbms) for location $location. Stack trace:\n".json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)), $S="fatal");
				return false;
			}
			$params=$params[$dbms];
		}
		if(is_array($vars)){
			if(isset($vars[$dbms])){
				$vars=$vars[$dbms];
			}
		}
		if($associative!==true && stripos($params, 'limit')===false && !is_null($params))$params.=' LIMIT 1'; 
		switch($dbms){
			case"mysql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					mysql_query_builder::$queued_queries[$queue]['select'][]=[
						'select'=>$select, 
						'location'=>$location,
						'params'=>$params, 
						'vars'=>$vars, 
						'associative'=>$associative, 
						'caching'=>$caching,
						'callback'=>$callback,
						'hash'=>$hash
					];
					return null;
				}
				$query_result=mysql_query_builder::mysql_select($dbms_cluster, $select, $location, $params, $vars, $associative);
				break;
			case"postgresql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=$value?'t':'f';}}} // Turn booleans into strings
				if($callback){
					postgresql_query_builder::$queued_queries[$queue]['select'][]=[
						'select'=>$select, 
						'location'=>$location,
						'params'=>$params, 
						'vars'=>$vars, 
						'associative'=>$associative, 
						'caching'=>$caching,
						'callback'=>$callback,
						'hash'=>$hash
					];
					return null;
				}
				$query_result=postgresql_query_builder::postgresql_select($dbms_cluster, $select, $location, $params, $vars, $associative);
				break;
			case"sqlite":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					sqlite_query_builder::$queued_queries[$queue]['select'][]=[
						'select'=>$select, 
						'location'=>$location,
						'params'=>$params, 
						'vars'=>$vars, 
						'associative'=>$associative, 
						'caching'=>$caching,
						'callback'=>$callback,
						'hash'=>$hash
					];
					return null;
				}
				$query_result=sqlite_query_builder::mysql_select($dbms_cluster, $select, $location, $params, $vars, $associative);
				break;
		}
		if($caching!==false && $cache_policy!==false){
			self::cache_query_result($location, $hash, $query_result, $caching, $cache_policy);
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Select query finished, returning result");
		return $query_result;
	}
	
	public static function db_count(string $location, array|string|null $params=null, ?array $vars=null, ?bool $caching=true, ?string $queue='end', ?callable $callback=null) : int|bool|null {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_COUNT",...func_get_args())) return $early_return;
		global $configurations;
		list($query_dbms, $location)=strpos($location, ':')!==false?explode(':', $location, 2):[null, $location];
		if(str_contains($location, '.')===false)$location=$configurations['dataphyre']['sql']['default_database_location'].".".$location;
		if($caching!==false){
			if(is_array($caching)===false)$caching=[$caching];
			if(false!==$cache_policy=self::get_table_cache_policy($location)){
				if($cache_policy['hash_type']==='sha256'){
					$hash=hash('sha256', $location.json_encode($params).json_encode($vars));
				}
				else
				{
					$hash=md5($location.json_encode($params).json_encode($vars));
				}
				if(null!==$cache=self::get_query_cached_result($location, $hash, $cache_policy)){
					if(is_integer($cache)===false){
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unexpected cached query result, possible hash collision. Returning false.", $S="fatal");
						return false;
					}
					if(null!==$callback)$callback($cache);
					return $cache;
				}
			}
		}
		$dbms_cluster=$configurations['dataphyre']['sql']['tables'][$location]['cluster']??$configurations['dataphyre']['sql']['default_cluster'];
		$dbms=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms'];
		if($query_dbms && $dbms!==$query_dbms){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query has explicit DBMS compatibility flag $query_dbms that is not compatible with DBMS ($dbms) for location $location. Stack trace:\n".json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)), $S="fatal");
			return false;
		}
		if(is_array($params)){
			if(!isset($params[$dbms])){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query's parameters have no compatibility for DBMS ($dbms) for location $location. Stack trace:\n".json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)), $S="fatal");
				return false;
			}
			$params=$params[$dbms];
		}
		if(is_array($vars)){
			if(isset($vars[$dbms])){
				$vars=$vars[$dbms];
			}
		}
		switch($dbms){
			case"mysql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					mysql_query_builder::$queued_queries[$queue]['count'][]=[
						'location'=>$location, 
						'params'=>$params,
						'vars'=>$vars, 
						'caching'=>$caching, 
						'callback'=>$callback,
						'hash'=>$hash
					];
					return null;
				}
				$query_result=mysql_query_builder::mysql_count($dbms_cluster, $location, $params, $vars);
				break;
			case"postgresql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=$value?'t':'f';}}} // Turn booleans into strings
				if($callback){
					postgresql_query_builder::$queued_queries[$queue]['count'][]=[
						'location'=>$location, 
						'params'=>$params,
						'vars'=>$vars, 
						'caching'=>$caching, 
						'callback'=>$callback,
						'hash'=>$hash
					];
					return null;
				}
				$query_result=postgresql_query_builder::postgresql_count($dbms_cluster, $location, $params, $vars);
				break;
			case"sqlite":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					sqlite_query_builder::$queued_queries[$queue]['count'][]=[
						'location'=>$location, 
						'params'=>$params,
						'vars'=>$vars, 
						'caching'=>$caching, 
						'callback'=>$callback,
						'hash'=>$hash
					];
					return null;
				}
				$query_result=sqlite_query_builder::mysql_count($dbms_cluster, $location, $params, $vars);
				break;
		}
		if($caching!==false && $cache_policy!==false){
			self::cache_query_result($location, $hash, $query_result, $caching, $cache_policy);
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Count query finished, returning result");
		return $query_result;
	}

	public static function db_insert(string $location, string|array $fields, ?array $vars=null, bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null) : mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_INSERT",...func_get_args())) return $early_return;
		global $configurations;
		if(is_array($fields)){
			if(!empty($vars)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Variables has to be empty when fields is of type array", $S="fatal");
				return false;
			}
			$vars=array_values($fields);
			$fields=implode(',', array_keys($fields));
		}
		if(is_array($vars)){
			if(isset($vars[$dbms])){
				$vars=$vars[$dbms];
			}
		}
		if($clear_cache===null)$clear_cache=false;
		if(str_contains($location, '.')===false)$location=$configurations['dataphyre']['sql']['default_database_location'].".".$location;
		$dbms_cluster=$configurations['dataphyre']['sql']['tables'][$location]['cluster']??$configurations['dataphyre']['sql']['default_cluster'];
		$dbms=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms'];
		switch($dbms){
			case"mysql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					mysql_query_builder::$queued_queries[$queue]['insert'][]=[
						'location'=>$location, 
						'ignore'=>'IGNORE',
						'fields'=>$fields, 
						'vars'=>$vars, 
						'clear_cache'=>$clear_cache, 
						'callback'=>$callback,
						'multipoint'=>true
					];
					return null;
				}
				$query_result=mysql_query_builder::mysql_insert($dbms_cluster, $location, $fields, $vars);
				break;
			case"postgresql":
			if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=$value?'t':'f';}}} // Turn booleans into strings
				if($callback){
					postgresql_query_builder::$queued_queries[$queue]['insert'][]=[
						'location'=>$location, 
						'ignore'=>'IGNORE',
						'fields'=>$fields, 
						'vars'=>$vars, 
						'clear_cache'=>$clear_cache, 
						'callback'=>$callback,
						'multipoint'=>true
					];
					return null;
				}
				$query_result=postgresql_query_builder::postgresql_insert($dbms_cluster, $location, $fields, $vars);
				break;
			case"sqlite":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					sqlite_query_builder::$queued_queries[$queue]['insert'][]=[
						'location'=>$location, 
						'ignore'=>'IGNORE',
						'fields'=>$fields, 
						'vars'=>$vars, 
						'clear_cache'=>$clear_cache, 
						'callback'=>$callback,
						'multipoint'=>true
					];
					return null;
				}
				$query_result=sqlite_query_builder::mysql_insert($dbms_cluster, $location, $fields, $vars);
				break;
		}
		if($query_result!==false && $clear_cache!==false)self::invalidate_cache($clear_cache===true?$location:$clear_cache);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Insert query finished, returning result");
		return $query_result;
	}

	public static function db_update(string $location, string|array $fields, string|array $params, ?array $vars=null, bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null) : int|bool|null {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_UPDATE",...func_get_args())) return $early_return;
		global $configurations;
		$vars??=[];
		if($clear_cache===null)$clear_cache=false;
		if(str_contains($location, '.')===false)$location=$configurations['dataphyre']['sql']['default_database_location'].".".$location;
		$dbms_cluster=$configurations['dataphyre']['sql']['tables'][$location]['cluster']??$configurations['dataphyre']['sql']['default_cluster'];
		$dbms=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms'];
		if(is_array($fields)){
			if(isset($fields[$dbms])){
				$fields=$fields[$dbms];
			}
		}
		if(is_array($vars)){
			if(isset($vars[$dbms])){
				$vars=$vars[$dbms];
			}
		}
		if(is_array($params)){
			if(!isset($params[$dbms])){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query's parameters have no compatibility for DBMS ($dbms) for location $location. Stack trace:\n".json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)), $S="fatal");
				return false;
			}
			$params=$params[$dbms];
		}
		switch($dbms){
			case"mysql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if(is_array($fields)){
					$vars??=[];
					$vars=array_values(array_merge(array_values($fields), $vars));
					$fields=implode('=?,', array_keys($fields)).'=?';
				}
				if($callback){
					mysql_query_builder::$queued_queries[$queue]['update'][]=[
						'location'=>$location,
						'fields'=>$fields, 
						'params'=>$params,
						'vars'=>$vars,
						'clear_cache'=>$clear_cache,
						'callback'=>$callback,
						'multipoint'=>true
					];
					return null;
				}
				$query_result=mysql_query_builder::mysql_update($dbms_cluster, $location, $fields, $params, $vars);
				break;
			case"postgresql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=$value?'t':'f';}}} // Turn booleans into strings
				if(is_array($fields)){
					$vars??=[];
					$vars=array_values(array_merge(array_values($fields), $vars));
					$fields=implode('=?,', array_keys($fields)).'=?';
				}
				if($callback){
					postgresql_query_builder::$queued_queries[$queue]['update'][]=[
						'location'=>$location,
						'fields'=>$fields, 
						'params'=>$params,
						'vars'=>$vars,
						'clear_cache'=>$clear_cache,
						'callback'=>$callback,
						'multipoint'=>true
					];
					return null;
				}
				$query_result=postgresql_query_builder::postgresql_update($dbms_cluster, $location, $fields, $params, $vars);
				break;
			case"sqlite":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if(is_array($fields)){
					$vars??=[];
					$vars=array_values(array_merge(array_values($fields), $vars));
					$fields=implode('=?,', array_keys($fields)).'=?';
				}
				if($callback){
					sqlite_query_builder::$queued_queries[$queue]['update'][]=[
						'location'=>$location,
						'fields'=>$fields, 
						'params'=>$params,
						'vars'=>$vars,
						'clear_cache'=>$clear_cache,
						'callback'=>$callback,
						'multipoint'=>true
					];
					return null;
				}
				$query_result=sqlite_query_builder::mysql_update($dbms_cluster, $location, $fields, $params, $vars);
				break;
		}
		if($query_result!==false && $clear_cache!==false)self::invalidate_cache($clear_cache===true?$location:$clear_cache);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Update query finished, returning result");
		return $query_result;
	}

	public static function db_delete(string $location, array|string $params=null, ?array $vars=null, bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null) : bool|null {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_DELETE",...func_get_args())) return $early_return;
		global $configurations;
		if(str_contains($location, '.')===false)$location=$configurations['dataphyre']['sql']['default_database_location'].".".$location;
		$dbms_cluster=$configurations['dataphyre']['sql']['tables'][$location]['cluster']??$configurations['dataphyre']['sql']['default_cluster'];
		$dbms=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms'];
		if(is_array($params)){
			if(!isset($params[$dbms])){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query has no compatibility with DBMS ($dbms) for location $location. Stack trace:\n".json_encode(debug_backtrace()), $S="fatal");
				return false;
			}
			$params=$params[$dbms];
		}
		if(is_array($vars)){
			if(isset($vars[$dbms])){
				$vars=$vars[$dbms];
			}
		}
		if(!isset($clear_cache))$clear_cache=false;
		switch($dbms){
			case"mysql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					mysql_query_builder::$queued_queries[$queue]['delete'][]=[
						'location'=>$location, 
						'params'=>$params,
						'vars'=>$vars,
						'clear_cache'=>$clear_cache,
						'callback'=>$callback,
						'multipoint'=>true
					];
					return null;
				}
				$query_result=mysql_query_builder::mysql_delete($dbms_cluster, $location, $params, $vars);
				break;
			case"postgresql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=$value?'t':'f';}}} // Turn booleans into strings
				if($callback){
					postgresql_query_builder::$queued_queries[$queue]['delete'][]=[
						'location'=>$location, 
						'params'=>$params,
						'vars'=>$vars,
						'clear_cache'=>$clear_cache,
						'callback'=>$callback,
						'multipoint'=>true
					];
					return null;
				}
				$query_result=postgresql_query_builder::postgresql_delete($dbms_cluster, $location, $params, $vars);
				break;
			case"sqlite":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					sqlite_query_builder::$queued_queries[$queue]['delete'][]=[
						'location'=>$location, 
						'params'=>$params,
						'vars'=>$vars,
						'clear_cache'=>$clear_cache,
						'callback'=>$callback,
						'multipoint'=>true
					];
					return null;
				}
				$query_result=sqlite_query_builder::mysql_delete($dbms_cluster, $location, $params, $vars);
				break;
		}
		if($query_result!==false && $clear_cache!==false)self::invalidate_cache($clear_cache===true?$location:$clear_cache);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Delete query finished, returning result");
		return $query_result;
	}

}