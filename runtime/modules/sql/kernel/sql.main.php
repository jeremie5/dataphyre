<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

dp_define_module_config('sql', 'DP_SQL_CFG', [
	'default_cluster'=>'',
	'default_database_location'=>'',
	'safe_delete'=>true,
	'caching'=>[
		'rolling_db_cache_size'=>256,
		'default_policy'=>[
			'type'=>'session',
			'max_lifespan'=>'30 minute',
			'hash_type'=>'md5',
		],
	],
	'datacenters'=>[],
	'tables'=>[],
]);

require(__DIR__."/sql.global.php");
require(__DIR__."/mysql_query.php");
require(__DIR__."/postgresql_query.php");
require(__DIR__."/sqlite_query.php");
require(__DIR__."/migration.php");

if(RUN_MODE==='diagnostic'){
	require_once(__DIR__.'/sql.diagnostic.php');
}

class sql {

	private static array $observers=[];

	public function __construct(string $dbms_cluster="sql"){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		core::dialback("CALL_SQL_CONSTRUCT",...func_get_args());
		register_shutdown_function(function(){
			try{
				self::session_cache_gc();
			}catch(\Throwable $exception){
				pre_init_error('Fatal error on Dataphyre SQL seesion cache garbage collection shutdown callback', $exception);
			}
		});
	}
	
	public static function session_cache_gc(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		$_SESSION['db_cache_count']=0;
		if(!isset($_SESSION['db_cache']) || !is_array($_SESSION['db_cache'])){
			$_SESSION['db_cache']=[];
			return;
		}
		$start=microtime(true);
		$time_budget=0.02; // 20ms budget
		$max_tables=500;
		$max_entries_per_table=128;
		$ttl_entry=600; // 10 minutes
		$memory_soft_limit=12*1024*1024; // 12 MB
		if(count($_SESSION['db_cache'])>$max_tables){
			uasort($_SESSION['db_cache'], function($a, $b){
				$at=reset($a)[1] ?? PHP_INT_MAX;
				$bt=reset($b)[1] ?? PHP_INT_MAX;
				return $at<=>$bt;
			});
			while(count($_SESSION['db_cache'])>$max_tables){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Limiting amount of tables cached in session variable", $S="warning");
				array_shift($_SESSION['db_cache']);
				if(microtime(true)-$start>$time_budget) return;
			}
		}
		foreach($_SESSION['db_cache'] as $location=>&$entries){
			foreach($entries as $hash=>$entry){
				if(time()-($entry[1] ?? 0)>$ttl_entry){
					unset($entries[$hash]);
					if(microtime(true)-$start>$time_budget) return;
				}
			}
			if(count($entries)>$max_entries_per_table){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Limiting amount of entries in table \"$location\" cached in session variable", $S="warning");
				uasort($entries, fn($a, $b)=>($a[1] ?? 0)<=>($b[1] ?? 0));
				while(count($entries)>$max_entries_per_table){
					array_shift($entries);
					if(microtime(true)-$start>$time_budget) return;
				}
			}
			$_SESSION['db_cache_count']+=count($entries);
		}
		unset($entries);
		if(memory_get_usage()>$memory_soft_limit){
			$all=[];
			foreach($_SESSION['db_cache'] as $location=>$entries){
				foreach($entries as $hash=>$entry){
					$all[]=[$location, $hash, $entry[1] ?? 0];
				}
			}
			usort($all, fn($a, $b)=>$a[2]<=>$b[2]);
			foreach($all as [$location, $hash, $_]){
				unset($_SESSION['db_cache'][$location][$hash]);
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Emergency GC: freeing memory from cache[$location][$hash]", $S="warning");
				if(memory_get_usage()<$memory_soft_limit*0.9) break;
				if(microtime(true)-$start>$time_budget) return;
			}
		}
	}

	public static function add_observer(callable $observer): void {
		self::$observers[]=$observer;
	}

	public static function clear_observers(): void {
		self::$observers=[];
	}

	private static function emit_observer_event(array $event): void {
		if(self::$observers===[]){
			return;
		}
		$event['timestamp']??=microtime(true);
		foreach(self::$observers as $observer){
			try{
				$observer($event);
			}catch(\Throwable $exception){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='SQL observer failed: '.$exception->getMessage(), $S='warning');
			}
		}
	}

	private static function trace_cache_names(null|bool|array|string $caching): array {
		if($caching===false || $caching===null){
			return [];
		}
		if(is_array($caching)===false){
			$caching=[$caching];
		}
		$names=[];
		foreach($caching as $cache_name){
			if(is_string($cache_name)===false){
				continue;
			}
			$cache_name=trim($cache_name);
			if($cache_name==='' || $cache_name==='lazy'){
				continue;
			}
			$names[]=$cache_name;
		}
		return array_values(array_unique($names));
	}

	private static function trace_invalidation_names(bool|array|null $clear_cache): array {
		if($clear_cache===false || $clear_cache===null || $clear_cache===true){
			return [];
		}
		$names=[];
		foreach($clear_cache as $cache_name){
			if(is_string($cache_name)===false){
				continue;
			}
			$cache_name=trim($cache_name);
			if($cache_name===''){
				continue;
			}
			$names[]=$cache_name;
		}
		return array_values(array_unique($names));
	}

	private static function trace_queue_name(?string $queue): ?string {
		if($queue===null){
			return null;
		}
		$queue=trim($queue);
		return $queue!=='' ? $queue : null;
	}
	
	public static function log_query_error(string $dbms, string $cluster, string $query, ?array $vars=[], ?\Throwable $exception=null): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Error with '.$dbms.' query on cluster '.$cluster, $S='error');
		$error_message=htmlspecialchars($exception ? $exception->getMessage() : "Unknown error", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$error_trace=$exception ? htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : "No stack trace available";
		$formatted_dbms=htmlspecialchars($dbms, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$formatted_cluster=htmlspecialchars($cluster, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$formatted_query=htmlspecialchars($query);
		$formatted_vars=!empty($vars) ? json_encode($vars, JSON_PRETTY_PRINT) : "None";
		$formatted_vars=ellipsis($formatted_vars, 512);
		$formatted_vars=htmlspecialchars($formatted_vars, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$error='
		<div class="alert alert-danger" role="alert">
			<h4 class="alert-heading">Dataphyre mod_SQL: '.$formatted_dbms.' query error on cluster '.$formatted_cluster.'</h4>
			<p><strong>Query:</strong> <code>'.$formatted_query.'</code></p>
			<p><strong>Bound Variables:</strong> <pre>'.$formatted_vars.'</pre></p>
			<p><strong>Error Message:</strong> '.$error_message.'</p>
			<hr>
			<p class="mb-0"><strong>Stack Trace:</strong></p>
			<pre class="dp-sql-stack-trace" style="background: #fff1f2; color:#111827; padding: 8px 10px; border-radius: 5px; line-height: 1.18; white-space: pre-wrap;">'.$error_trace.'</pre>
		</div>';
		log_error($error);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=$error, $S='warning');
	}
	
	public static function query_has_write(string $query) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		static $write_ops=null;
		if($write_ops===null){
			$write_ops=array_flip([
				'INSERT','UPDATE','DELETE','REPLACE','CREATE','DROP','ALTER',
				'VACUUM','PRAGMA','TRUNCATE','GRANT','REVOKE','SET','ANALYZE','EXECUTE','MERGE'
			]);
		}
		$query=preg_replace(['@--.*@', '@/\*.*?\*/@s'], '', $query);
		$trimmed=strtoupper(ltrim($query));
		$first_word=strtok($trimmed, " \t\n\r(");
		if($first_word==='WITH'){
			$pattern='/\b(SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|VACUUM|PRAGMA|TRUNCATE|GRANT|REVOKE|SET|ANALYZE|EXECUTE|MERGE)\b/i';
			if(preg_match($pattern, $trimmed, $matches)){
				$first_word=strtoupper($matches[1]);
			}
		}
		return isset($write_ops[$first_word]);
	}
	
	public static function get_table_cache_policy(string $location) : array|bool{
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_GET_TABLE_CACHE_POLICY",...func_get_args())) return $early_return;
		$default_cache_policy=defined('DP_SQL_DEFAULT_CACHE_POLICY_OVERRIDE')
			? DP_SQL_DEFAULT_CACHE_POLICY_OVERRIDE
			: DP_SQL_CFG['caching']['default_policy'];
		if(isset(DP_SQL_CFG['tables'][$location]['caching'])){
			$cache_policy=DP_SQL_CFG['tables'][$location]['caching'];
			if($cache_policy===false)return false;
		}
		if(isset($cache_policy) && $cache_policy['type']==='session' && RUN_MODE!=='request'){
			return $default_cache_policy;
		}
		if(!empty($cache_policy['type'])){
			return $cache_policy;
		}
		return $default_cache_policy;
	}
	
	public static function execute_queue(string $queue='end') : null|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		self::emit_observer_event([
			'event'=>'queue_execute_start',
			'operation'=>'queue_execute',
			'queue'=>self::trace_queue_name($queue),
			'queued'=>true,
		]);
		if(null!==$return=mysql_query_builder::execute_multiquery($queue)){
			self::emit_observer_event([
				'event'=>'queue_execute_end',
				'operation'=>'queue_execute',
				'queue'=>self::trace_queue_name($queue),
				'queued'=>true,
				'result_ok'=>$return!==false,
			]);
			return $return;
		}
		if(null!==$return=postgresql_query_builder::execute_multiquery($queue)){
			self::emit_observer_event([
				'event'=>'queue_execute_end',
				'operation'=>'queue_execute',
				'queue'=>self::trace_queue_name($queue),
				'queued'=>true,
				'result_ok'=>$return!==false,
			]);
			return $return;
		}
		if(null!==$return=sqlite_query_builder::execute_multiquery($queue)){
			self::emit_observer_event([
				'event'=>'queue_execute_end',
				'operation'=>'queue_execute',
				'queue'=>self::trace_queue_name($queue),
				'queued'=>true,
				'result_ok'=>$return!==false,
			]);
			return $return;
		}
		self::emit_observer_event([
			'event'=>'queue_execute_end',
			'operation'=>'queue_execute',
			'queue'=>self::trace_queue_name($queue),
			'queued'=>true,
			'result_ok'=>null,
		]);
		return null;
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if($cache_policy===null){
			$cache_policy=self::get_table_cache_policy($location);
		}
		if($cache_policy!==false){
			if($cache_policy['type']==="shared_cache"){
				if(dp_module_present('cache')){
					$table_cache_version=(int)cache::get('table_version_'.$location) ?? 0;
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Shared cache table version for $location: $table_cache_version)");
					if(is_array($shared_cache_result=cache::get($key=$location.'_'.$hash))){
						if($shared_cache_result[0]===$table_cache_version){
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Read from shared cache (".$key.")");
							$_SESSION['queries_retrieved_from_cache']??=0;
							$_SESSION['queries_retrieved_from_cache']++;
							self::emit_observer_event([
								'event'=>'cache_hit',
								'operation'=>'read',
								'location'=>$location,
								'cache_status'=>'hit',
								'cache_type'=>'shared_cache',
								'reason'=>'found',
							]);
							if($shared_cache_result[1]==="false")return false;
							return $shared_cache_result[1];
						}
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Table version mismatch", $S="warning");
						self::emit_observer_event([
							'event'=>'cache_miss',
							'operation'=>'read',
							'location'=>$location,
							'cache_status'=>'miss',
							'cache_type'=>'shared_cache',
							'reason'=>'table_version_mismatch',
						]);
						return null;
					}
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query not cached in shared cache (".$key.")", $S="warning");
					self::emit_observer_event([
						'event'=>'cache_miss',
						'operation'=>'read',
						'location'=>$location,
						'cache_status'=>'miss',
						'cache_type'=>'shared_cache',
						'reason'=>'not_found',
					]);
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
						self::emit_observer_event([
							'event'=>'cache_hit',
							'operation'=>'read',
							'location'=>$location,
							'cache_status'=>'hit',
							'cache_type'=>'session',
							'reason'=>'found',
						]);
						return $cached_result;
					}
					unset($_SESSION['db_cache'][$location][$hash]);
				}
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query not cached in session", $S="warning");
				self::emit_observer_event([
					'event'=>'cache_miss',
					'operation'=>'read',
					'location'=>$location,
					'cache_status'=>'miss',
					'cache_type'=>'session',
					'reason'=>'not_found',
				]);
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
							self::emit_observer_event([
								'event'=>'cache_hit',
								'operation'=>'read',
								'location'=>$location,
								'cache_status'=>'hit',
								'cache_type'=>'fs',
								'reason'=>'found',
							]);
							return $cached_result;
						}
						unlink($cache_file);
					}
				}
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Query not cached in filesystem", $S="warning");
				self::emit_observer_event([
					'event'=>'cache_miss',
					'operation'=>'read',
					'location'=>$location,
					'cache_status'=>'miss',
					'cache_type'=>'fs',
					'reason'=>'not_found',
				]);
				return null;
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Location $location is not cachable", $S="warning");
		self::emit_observer_event([
			'event'=>'cache_miss',
			'operation'=>'read',
			'location'=>$location,
			'cache_status'=>'miss',
			'cache_type'=>null,
			'reason'=>'not_cachable',
		]);
		return null;
	}
	
	public static function cache_query_result(string $location, string $hash, mixed $query_result, array $caching=[true], array|bool|null $cache_policy=null) : mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if($cache_policy===null){
			$cache_policy=self::get_table_cache_policy($location);
		}
		if($cache_policy!==false){
			if(empty($location)){
				self::log_query_error('N/A', 'N/A', json_encode(func_get_args()), [], new \Exception("Invalid cache location"));
				return false;
			}
			if(empty($hash)){
				self::log_query_error('N/A', 'N/A', json_encode(func_get_args()), [], new \Exception("Invalid cache hash"));
				return false;
			}
			if($cache_policy['type']==='shared_cache'){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Caching in shared cache");
				if($query_result===false)$query_result='false';
				$table_cache_version=(int)cache::get('table_version_'.$location) ?? 0;
				cache::set($location.'_'.$hash, array($table_cache_version, $query_result), strtotime($cache_policy['max_lifespan']));
			}
			elseif($cache_policy['type']==='session'){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Caching in session");
				$_SESSION['db_cache'][$location][$hash]=array($query_result, time());
				if($_SESSION['db_cache_count']>=DP_SQL_CFG['caching']['rolling_db_cache_size']){
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
				self::log_query_error('N/A', 'N/A', json_encode(func_get_args()), [], new \Exception("Unknown cache policy type for table $location"));
				return false;
			}
			foreach($caching as $cache_index){
				if(is_bool($cache_index)===false){
					$_SESSION['db_cache_invalidation_index'][$cache_index][]=[$cache_policy['type'],$location,$hash];
				}
			}
			self::emit_observer_event([
				'event'=>'cache_store',
				'operation'=>'read',
				'location'=>$location,
				'cache_status'=>'stored',
				'cache_type'=>$cache_policy['type'],
				'cache_names'=>self::trace_cache_names($caching),
			]);
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
					self::log_query_error('N/A', 'N/A', json_encode(func_get_args()), [], new \Exception("Unknown cache policy type for table"));
					return false;
				}
				self::emit_observer_event([
					'event'=>'cache_invalidate',
					'operation'=>'write',
					'location'=>$clear_cache_for,
					'cache_status'=>'invalidated',
					'cache_type'=>$cache_policy['type'],
					'invalidation_names'=>[$clear_cache_for],
					'scope'=>'table',
				]);
			}
			else
			{
				self::log_query_error('N/A', 'N/A', json_encode(func_get_args()), [], new \Exception("clear_cache_for parameter must be a string if valid cache policy parameter is given"));
				return false;
			}
		}
		else
		{
			foreach($clear_cache_for as $clear_cache_index){
				$invalidated_count=0;
				foreach($_SESSION['db_cache_invalidation_index'][$clear_cache_index] ?? [] as $invalidation_cache){
					if($invalidation_cache[0]==='shared_cache'){
						cache::delete($invalidation_cache[0].'_'.$invalidation_cache[1]);
					}
					elseif($invalidation_cache[0]==='session'){
						unset($_SESSION['db_cache'][$invalidation_cache[0]][$invalidation_cache[1]]);
					}
					elseif($invalidation_cache[0]==='fs'){
						unlink(__DIR__."/../../cache/sql/".$invalidation_cache[0]."/".$invalidation_cache[1]);
					}
					$_SESSION['db_cache_count']--;
					$invalidated_count++;
				}
				unset($_SESSION['db_cache_invalidation_index'][$clear_cache_index]);
				self::emit_observer_event([
					'event'=>'cache_invalidate',
					'operation'=>'write',
					'location'=>null,
					'cache_status'=>'invalidated',
					'cache_type'=>null,
					'invalidation_names'=>[$clear_cache_index],
					'scope'=>'named_index',
					'affected_entries'=>$invalidated_count,
				]);
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Cleared cache for invalidation index $clear_cache_index ($invalidated_count entr".($invalidated_count===1 ? 'y' : 'ies').")");
			}
		}
		return true;
	}

	public static function table(string $location, ?string &$query_dbms=null): string {
		list($query_dbms, $location)=strpos($location, ':')!==false?explode(':', $location, 2):[null, $location];
		if(str_contains($location, '.')===false)$location=DP_SQL_CFG['default_database_location'].".".$location;
		return $location;
	}

	public static function assert(mixed $result, string $msg): mixed {
		if($result===false){
			throw new \RuntimeException($msg);
		}
		return $result;
	}
	
	public static function transaction(callable $fn, ?string $cluster=null): bool {
		self::begin($cluster);
		try{
			$fn();
			self::commit($cluster);
			return true;
		}catch(\Throwable $e){
			self::rollback($cluster);
			return false;
		}
	}
	
	public static function begin(?string $cluster=null): bool {
		return false!==self::query([
			'mysql'=>'START TRANSACTION',
			'postgresql'=>'BEGIN',
			'sqlite'=>'BEGIN TRANSACTION',
			'dbms_cluster_override'=>$cluster
		]);
	}

	public static function commit(?string $cluster=null): bool {
		return false!==self::query([
			'mysql'=>'COMMIT',
			'postgresql'=>'COMMIT',
			'sqlite'=>'COMMIT',
			'dbms_cluster_override'=>$cluster
		]);
	}

	public static function rollback(?string $cluster=null): bool {
		return false!==self::query([
			'mysql'=>'ROLLBACK',
			'postgresql'=>'ROLLBACK',
			'sqlite'=>'ROLLBACK',
			'dbms_cluster_override'=>$cluster
		]);
	}
	
	public static function query(string|array $query, ?array $vars=null, ?bool $associative=false, ?bool $multipoint=false, null|bool|array|string $caching=[false], bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null) : mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_SELECT",...func_get_args())) return $early_return;
		$location='raw';
		$hash=null;
		if($caching!==false){
			if(is_array($caching)===false)$caching=[$caching];
			if(false!==$cache_policy=self::get_table_cache_policy($location)){
				if($cache_policy['hash_type']==='sha256'){
					$hash=hash('sha256', json_encode($query).json_encode($vars).intval($associative).intval($multipoint));
				}
				else
				{
					$hash=md5(json_encode($query).json_encode($vars).intval($associative).intval($multipoint));
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
		$dbms_cluster=DP_SQL_CFG['tables'][$location]['cluster']??DP_SQL_CFG['default_cluster'];
		if(isset($query['dbms_cluster_override']))$dbms_cluster=$query['dbms_cluster_override'];
		$dbms=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms'];
		if(is_array($query)){
			if(!isset($query[$dbms])){
				self::log_query_error($dbms, 'N/A', json_encode(func_get_args()), [], new \Exception("Query has no compatibility for DBMS ($dbms) for location $location."));
				return false;
			}
			$query=$query[$dbms];
		}
		if(is_array($vars) && isset($vars[$dbms]))$vars=$vars[$dbms];
		if($callback){
			$query_queue=function($vars)use($location, $query, $associative, $caching, $multipoint, $clear_cache, $callback, $hash){
				return [
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
			};
		}
		switch($dbms){
			case"mysql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					mysql_query_builder::$queued_queries[$queue]['raw'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'query',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'cache_names'=>self::trace_cache_names($caching),
						'invalidation_names'=>self::trace_invalidation_names($clear_cache),
					]);
					return null;
				}
				$query_result=mysql_query_builder::mysql_query($dbms_cluster, $query, $vars, $associative, $multipoint);
				break;
			case"postgresql":
				if($callback){
					postgresql_query_builder::$queued_queries[$queue]['raw'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'query',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'cache_names'=>self::trace_cache_names($caching),
						'invalidation_names'=>self::trace_invalidation_names($clear_cache),
					]);
					return null;
				}
				$query_result=postgresql_query_builder::postgresql_query($dbms_cluster, $query, $vars, $associative, $multipoint);
				break;
			case"sqlite":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					sqlite_query_builder::$queued_queries[$queue]['raw'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'query',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'cache_names'=>self::trace_cache_names($caching),
						'invalidation_names'=>self::trace_invalidation_names($clear_cache),
					]);
					return null;
				}
				$query_result=sqlite_query_builder::sqlite_query($dbms_cluster, $query, $vars, $associative, $multipoint);
				break;
		}
		if($caching!==false && $caching!=='lazy' && $cache_policy!==false){
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
		self::emit_observer_event([
			'event'=>'execute',
			'operation'=>'query',
			'location'=>$location,
			'cluster'=>$dbms_cluster,
			'dbms'=>$dbms,
			'queued'=>false,
			'cache_names'=>self::trace_cache_names($caching),
			'invalidation_names'=>self::trace_invalidation_names($clear_cache),
			'result_ok'=>$query_result!==false && $query_result!==null,
		]);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Raw query finished, returning result");
		return $query_result;
    }
	
	public static function select(string|array $select, string $location, array|string|null $params=null, ?array $vars=null, ?bool $associative=false, null|bool|array|string $caching=[true], ?string $queue='end', ?callable $callback=null) : mixed { //bool|array|null
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_SELECT",...func_get_args())) return $early_return;
		$location=self::table($location, $query_dbms);
		$hash=null;
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
							self::log_query_error($dbms, 'N/A', json_encode(func_get_args()), [], new \Exception("Unexpected cached query result, possible hash collision. Returning false."));
							return false;
						}
						if($associative===true && is_array($cache)){
							foreach($cache as $item){
								if(!is_array($item)){
									self::log_query_error($dbms, 'N/A', json_encode(func_get_args()), [], new \Exception("Cached query result is not a multidimensional array as expected, possible hash collision. Returning false."));
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
		$dbms_cluster=DP_SQL_CFG['tables'][$location]['cluster']??DP_SQL_CFG['default_cluster'];
		$dbms=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms'];
		if(is_array($select)){
			if(!isset($select[$dbms])){
				self::log_query_error($dbms, 'N/A', json_encode(func_get_args()), [], new \Exception("Query's selection has no compatibility for DBMS ($dbms) for location $location."));
				return false;
			}
			$select=$select[$dbms];
		}
		if(is_array($params)){
			if(!isset($params[$dbms])){
				self::log_query_error($dbms, 'N/A', json_encode(func_get_args()), [], new \Exception("Query's parameters has no compatibility for DBMS ($dbms) for location $location."));
				return false;
			}
			$params=$params[$dbms];
		}
		if(is_array($vars) && isset($vars[$dbms]))$vars=$vars[$dbms];
		if($associative!==true && stripos($params, 'limit')===false && !is_null($params))$params.=' LIMIT 1'; 
		if($callback){
			$query_queue=function($vars)use($select, $location, $params, $associative, $caching, $callback, $hash){
				return [
					'select'=>$select, 
					'location'=>$location,
					'params'=>$params, 
					'vars'=>$vars, 
					'associative'=>$associative, 
					'caching'=>$caching,
					'callback'=>$callback,
					'hash'=>$hash
				];
			};
		}
		switch($dbms){
			case"mysql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					mysql_query_builder::$queued_queries[$queue]['select'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'select',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'cache_names'=>self::trace_cache_names($caching),
					]);
					return null;
				}
				$query_result=mysql_query_builder::mysql_select($dbms_cluster, $select, $location, $params, $vars, $associative);
				break;
			case"postgresql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=$value?'t':'f';}}} // Turn booleans into strings
				if($callback){
					postgresql_query_builder::$queued_queries[$queue]['select'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'select',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'cache_names'=>self::trace_cache_names($caching),
					]);
					return null;
				}
				$query_result=postgresql_query_builder::postgresql_select($dbms_cluster, $select, $location, $params, $vars, $associative);
				break;
			case"sqlite":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					sqlite_query_builder::$queued_queries[$queue]['select'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'select',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'cache_names'=>self::trace_cache_names($caching),
					]);
					return null;
				}
				$query_result=sqlite_query_builder::sqlite_select($dbms_cluster, $select, $location, $params, $vars, $associative);
				break;
		}
		if($caching!==false && $caching!=='lazy' && $cache_policy!==false){
			self::cache_query_result($location, $hash, $query_result, $caching, $cache_policy);
		}
		self::emit_observer_event([
			'event'=>'execute',
			'operation'=>'select',
			'location'=>$location,
			'cluster'=>$dbms_cluster,
			'dbms'=>$dbms,
			'queued'=>false,
			'cache_names'=>self::trace_cache_names($caching),
			'result_ok'=>$query_result!==false && $query_result!==null,
		]);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Select query finished, returning result");
		return $query_result;
	}
	
	public static function count(string $location, array|string|null $params=null, ?array $vars=null, null|bool|array|string $caching=[true], ?string $queue='end', ?callable $callback=null) : int|bool|null {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_COUNT",...func_get_args())) return $early_return;
		$location=self::table($location, $query_dbms);
		$hash=null;
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
						self::log_query_error($dbms, 'N/A', json_encode(func_get_args()), [], new \Exception("Unexpected cached query result, possible hash collision. Returning false."));
						return false;
					}
					if(null!==$callback)$callback($cache);
					return $cache;
				}
			}
		}
		$dbms_cluster=DP_SQL_CFG['tables'][$location]['cluster']??DP_SQL_CFG['default_cluster'];
		$dbms=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms'];
		if($query_dbms && $dbms!==$query_dbms){
			self::log_query_error($dbms, 'N/A', json_encode(func_get_args()), [], new \Exception("Query has explicit DBMS compatibility flag $query_dbms that is not compatible with DBMS ($dbms) for location $location."));
			return false;
		}
		if(is_array($params)){
			if(!isset($params[$dbms])){
				self::log_query_error($dbms, 'N/A', json_encode(func_get_args()), [], new \Exception("Query's parameters have no compatibility for DBMS ($dbms) for location $location."));
				return false;
			}
			$params=$params[$dbms];
		}
		if(is_array($vars) && isset($vars[$dbms]))$vars=$vars[$dbms];
		if($callback){
			$query_queue=function($vars)use($location, $params, $caching, $callback, $hash){
				return [
					'location'=>$location, 
					'params'=>$params,
					'vars'=>$vars, 
					'caching'=>$caching, 
					'callback'=>$callback,
					'hash'=>$hash
				];
			};
		}
		switch($dbms){
			case"mysql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					mysql_query_builder::$queued_queries[$queue]['count'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'count',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'cache_names'=>self::trace_cache_names($caching),
					]);
					return null;
				}
				$query_result=mysql_query_builder::mysql_count($dbms_cluster, $location, $params, $vars);
				break;
			case"postgresql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=$value?'t':'f';}}} // Turn booleans into strings
				if($callback){
					postgresql_query_builder::$queued_queries[$queue]['count'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'count',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'cache_names'=>self::trace_cache_names($caching),
					]);
					return null;
				}
				$query_result=postgresql_query_builder::postgresql_count($dbms_cluster, $location, $params, $vars);
				break;
			case"sqlite":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					sqlite_query_builder::$queued_queries[$queue]['count'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'count',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'cache_names'=>self::trace_cache_names($caching),
					]);
					return null;
				}
				$query_result=sqlite_query_builder::sqlite_count($dbms_cluster, $location, $params, $vars);
				break;
		}
		if($caching!==false && $caching!=='lazy' && $cache_policy!==false){
			self::cache_query_result($location, $hash, $query_result, $caching, $cache_policy);
		}
		self::emit_observer_event([
			'event'=>'execute',
			'operation'=>'count',
			'location'=>$location,
			'cluster'=>$dbms_cluster,
			'dbms'=>$dbms,
			'queued'=>false,
			'cache_names'=>self::trace_cache_names($caching),
			'result_ok'=>$query_result!==false && $query_result!==null,
		]);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Count query finished, returning result");
		return $query_result;
	}

	public static function insert(string $location, string|array $fields, ?array $vars=null, bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null) : mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_INSERT",...func_get_args())) return $early_return;
		if(is_array($fields)){
			if(!empty($vars)){
				self::log_query_error($dbms, 'N/A', json_encode(func_get_args()), [], new \Exception("Variables has to be empty when fields is of type array."));
				return false;
			}
			$vars=array_values($fields);
			$fields=implode(',', array_keys($fields));
		}
		if($clear_cache===null)$clear_cache=false;
		$location=self::table($location, $query_dbms);
		$dbms_cluster=DP_SQL_CFG['tables'][$location]['cluster']??DP_SQL_CFG['default_cluster'];
		$dbms=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms'];
		if(is_array($vars)){
			if(isset($vars[$dbms])){
				$vars=$vars[$dbms];
			}
		}
		$returning='*';
		if($callback){
			$query_queue=function($vars)use($location, $fields, $clear_cache, $callback, $returning){
				return [
					'location'=>$location, 
					'ignore'=>'IGNORE',
					'fields'=>$fields, 
					'vars'=>$vars, 
					'clear_cache'=>$clear_cache, 
					'callback'=>$callback,
					'multipoint'=>true,
					'associative'=>false,
					'returning'=>$returning
				];
			};
		}
		switch($dbms){
			case"mysql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}if(is_array($value)){$vars[$id]=json_encode($value);}}} // Turn booleans into integer value, arrays into json
				if($callback){
					mysql_query_builder::$queued_queries[$queue]['insert'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'insert',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'invalidation_names'=>self::trace_invalidation_names($clear_cache),
					]);
					return null;
				}
				$query_result=mysql_query_builder::mysql_insert($dbms_cluster, $location, $fields, $vars, $returning);
				break;
			case"postgresql":
			if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=$value?'t':'f';}if(is_array($value)){$vars[$id]=json_encode($value);}}} // Turn booleans into strings, arrays into json
				if($callback){
					postgresql_query_builder::$queued_queries[$queue]['insert'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'insert',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'invalidation_names'=>self::trace_invalidation_names($clear_cache),
					]);
					return null;
				}
				$query_result=postgresql_query_builder::postgresql_insert($dbms_cluster, $location, $fields, $vars, $returning);
				break;
			case"sqlite":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}if(is_array($value)){$vars[$id]=json_encode($value);}}} // Turn booleans into integer value, arrays into json
				if($callback){
					sqlite_query_builder::$queued_queries[$queue]['insert'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'insert',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'invalidation_names'=>self::trace_invalidation_names($clear_cache),
					]);
					return null;
				}
				$query_result=sqlite_query_builder::sqlite_insert($dbms_cluster, $location, $fields, $vars, $returning);
				break;
		}
		if($query_result!==false && $clear_cache!==false){
			self::invalidate_cache($clear_cache===true ? $location : $clear_cache);
		}
		self::emit_observer_event([
			'event'=>'execute',
			'operation'=>'insert',
			'location'=>$location,
			'cluster'=>$dbms_cluster,
			'dbms'=>$dbms,
			'queued'=>false,
			'invalidation_names'=>self::trace_invalidation_names($clear_cache),
			'result_ok'=>$query_result!==false && $query_result!==null,
		]);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Insert query finished, returning result");
		return $query_result;
	}

	public static function update(string $location, string|array $fields, null|string|array $params, ?array $vars=null, bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null) : int|bool|null {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_UPDATE",...func_get_args())) return $early_return;
		$vars??=[];
		if($clear_cache===null)$clear_cache=false;
		$location=self::table($location, $query_dbms);
		$dbms_cluster=DP_SQL_CFG['tables'][$location]['cluster']??DP_SQL_CFG['default_cluster'];
		$dbms=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms'];
		if(is_array($fields) && isset($fields[$dbms]))$fields=$fields[$dbms];
		if(is_array($vars) && isset($vars[$dbms]))$vars=$vars[$dbms];
		if(is_array($params)){
			if(!isset($params[$dbms])){
				self::log_query_error($dbms, 'N/A', json_encode(func_get_args()), [], new \Exception("Query's parameters have no compatibility for DBMS ($dbms) for location $location."));
				return false;
			}
			$params=$params[$dbms];
		}
		if($callback){
			$query_queue=function($vars)use($location, $fields, $params, $callback, $clear_cache){
				return [
					'location'=>$location,
					'fields'=>$fields, 
					'params'=>$params,
					'vars'=>$vars,
					'clear_cache'=>$clear_cache,
					'callback'=>$callback,
					'multipoint'=>true
				];
			};
		}
		switch($dbms){
			case"mysql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}if(is_array($value)){$vars[$id]=json_encode($value);}}} // Turn booleans into integer value, arrays into json
				if(is_array($fields)){
					$vars??=[];
					$vars=array_values(array_merge(array_values($fields), $vars));
					if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}if(is_array($value)){$vars[$id]=json_encode($value);}}} // Turn booleans into integer value, arrays into json
					$fields=implode('=?,', array_keys($fields)).'=?';
				}
				if($callback){
					mysql_query_builder::$queued_queries[$queue]['update'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'update',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'invalidation_names'=>self::trace_invalidation_names($clear_cache),
					]);
					return null;
				}
				$query_result=mysql_query_builder::mysql_update($dbms_cluster, $location, $fields, $params, $vars);
				break;
			case"postgresql":
				if(is_array($fields)){
					$vars??=[];
					$vars=array_values(array_merge(array_values($fields), $vars));
					$fields=implode('=?,', array_keys($fields)).'=?';
				}
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=$value?'t':'f';}if(is_array($value)){$vars[$id]=json_encode($value);}}} // Turn booleans into strings, arrays into json
				if($callback){
					postgresql_query_builder::$queued_queries[$queue]['update'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'update',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'invalidation_names'=>self::trace_invalidation_names($clear_cache),
					]);
					return null;
				}
				$query_result=postgresql_query_builder::postgresql_update($dbms_cluster, $location, $fields, $params, $vars);
				break;
			case"sqlite":
				if(is_array($fields)){
					$vars??=[];
					$vars=array_values(array_merge(array_values($fields), $vars));
					$fields=implode('=?,', array_keys($fields)).'=?';
				}
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}if(is_array($value)){$vars[$id]=json_encode($value);}}} // Turn booleans into integer value, arrays into json
				if($callback){
					sqlite_query_builder::$queued_queries[$queue]['update'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'update',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'invalidation_names'=>self::trace_invalidation_names($clear_cache),
					]);
					return null;
				}
				$query_result=sqlite_query_builder::sqlite_update($dbms_cluster, $location, $fields, $params, $vars);
				break;
		}
		if($query_result!==false && $clear_cache!==false){
			self::invalidate_cache($clear_cache===true ? $location : $clear_cache);
		}
		self::emit_observer_event([
			'event'=>'execute',
			'operation'=>'update',
			'location'=>$location,
			'cluster'=>$dbms_cluster,
			'dbms'=>$dbms,
			'queued'=>false,
			'invalidation_names'=>self::trace_invalidation_names($clear_cache),
			'result_ok'=>$query_result!==false && $query_result!==null,
		]);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Update query finished, returning result");
		return $query_result;
	}

	public static function delete(string $location, array|string|null $params=null, ?array $vars=null, bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null) : bool|null {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_DELETE",...func_get_args())) return $early_return;
		$location=self::table($location, $query_dbms);
		$dbms_cluster=DP_SQL_CFG['tables'][$location]['cluster']??DP_SQL_CFG['default_cluster'];
		$dbms=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms'];
		if(is_array($params)){
			if(!isset($params[$dbms])){
				self::log_query_error($dbms, 'N/A', json_encode(func_get_args()), [], new \Exception("Query has no compatibility with DBMS ($dbms) for location $location."));
				return false;
			}
			$params=$params[$dbms];
		}
		if(is_array($vars) && isset($vars[$dbms]))$vars=$vars[$dbms];
		if(!isset($clear_cache))$clear_cache=false;
		if($callback){
			$query_queue=function($vars)use($location, $params, $clear_cache, $callback){
				return [
					'location'=>$location, 
					'params'=>$params,
					'vars'=>$vars,
					'clear_cache'=>$clear_cache,
					'callback'=>$callback,
					'multipoint'=>true
				];
			};
		}
		if(stripos($params, 'WHERE')!==false){
			if(DP_SQL_CFG['safe_delete']===false){
				self::log_query_error($dbms, 'N/A', json_encode(func_get_args()), [], new \Exception("Query attempted to delete all rows of a table but safe_delete is not false."));
				return false;
			}
		}
		switch($dbms){
			case"mysql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					mysql_query_builder::$queued_queries[$queue]['delete'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'delete',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'invalidation_names'=>self::trace_invalidation_names($clear_cache),
					]);
					return null;
				}
				$query_result=mysql_query_builder::mysql_delete($dbms_cluster, $location, $params, $vars);
				break;
			case"postgresql":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=$value?'t':'f';}}} // Turn booleans into strings
				if($callback){
					postgresql_query_builder::$queued_queries[$queue]['delete'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'delete',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'invalidation_names'=>self::trace_invalidation_names($clear_cache),
					]);
					return null;
				}
				$query_result=postgresql_query_builder::postgresql_delete($dbms_cluster, $location, $params, $vars);
				break;
			case"sqlite":
				if(is_array($vars)){foreach($vars as $id=>$value){if(is_bool($value)){$vars[$id]=(int)$value;}}} // Turn booleans into integer value
				if($callback){
					sqlite_query_builder::$queued_queries[$queue]['delete'][]=$query_queue($vars);
					self::emit_observer_event([
						'event'=>'queue_push',
						'operation'=>'delete',
						'location'=>$location,
						'cluster'=>$dbms_cluster,
						'dbms'=>$dbms,
						'queue'=>self::trace_queue_name($queue),
						'queued'=>true,
						'invalidation_names'=>self::trace_invalidation_names($clear_cache),
					]);
					return null;
				}
				$query_result=sqlite_query_builder::sqlite_delete($dbms_cluster, $location, $params, $vars);
				break;
		}
		if($query_result!==false && $clear_cache!==false){
			self::invalidate_cache($clear_cache===true ? $location : $clear_cache);
		}
		self::emit_observer_event([
			'event'=>'execute',
			'operation'=>'delete',
			'location'=>$location,
			'cluster'=>$dbms_cluster,
			'dbms'=>$dbms,
			'queued'=>false,
			'invalidation_names'=>self::trace_invalidation_names($clear_cache),
			'result_ok'=>$query_result!==false && $query_result!==null,
		]);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Delete query finished, returning result");
		return $query_result;
	}
	
	public static function upsert(string $location, array $fields, string|array|null $update_params=null, ?array $update_vars=null, bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null): int|bool|null {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args());
		if(null !== $early_return=core::dialback("CALL_SQL_DB_UPSERT", ...func_get_args())) return $early_return;
		$update_vars ??=[];
		if($clear_cache===null) $clear_cache=false;
		$location=self::table($location, $query_dbms);
		$dbms_cluster=DP_SQL_CFG['tables'][$location]['cluster'] ?? DP_SQL_CFG['default_cluster'];
		$dbms=DP_SQL_CFG['datacenters'][DP_CORE_CFG['datacenter']]['dbms_clusters'][$dbms_cluster]['dbms'];
		if(is_array($fields[$dbms] ?? null)){
			if($dbms==='postgresql'){
				if(!empty($fields[$dbms]['columns']) && !empty($fields[$dbms]['conflict_keys'])){
					$conflict_keys=$fields[$dbms]['conflict_keys'] ?? ['id'];
					$fields=$fields[$dbms]['columns'];
				}
				else
				{
					self::log_query_error($dbms, 'N/A', json_encode(func_get_args()), [], new \Exception('Key conflict scope unknown for postgresql'));
					return false;
				}
			}
			else
			{
				$fields=$fields[$dbms];
			}
		}
		if(is_array($update_vars[$dbms] ?? null)) $update_vars=$update_vars[$dbms];
		foreach($fields as $id=>&$value){
			if(is_bool($value)){
				$value=($dbms==='postgresql') ? ($value ? 't' : 'f') : (int)$value;
			}
			elseif(is_array($value)){
				$value=json_encode($value);
			}
		}
		unset($value);
		$columns=array_keys($fields);
		$vars=array_values($fields);
        $quoted_location=implode('.', array_map(fn($part)=>'"'.$part.'"', explode('.', $location)));
        $quoted_columns=implode('","', array_keys($fields));
		if(!$update_params){
			$query_result=false;
			switch($dbms){
				case "mysql":
					$placeholders=implode(",", array_fill(0, count($fields), "?"));
					$updates=implode(",", array_map(fn($k)=>"`$k`=VALUES(`$k`)", $columns));
					$sql="INSERT INTO `$location` (`".implode("`,`", $columns)."`) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates";
					if($callback){
						$query_queue=function($vars)use($location, $sql, $clear_cache, $callback){
							return [
								'location'=>$location,
								'query'=>$sql,
								'vars'=>$vars,
								'associative'=>false,
								'caching'=>false,
								'multipoint'=>false,
								'clear_cache'=>$clear_cache,
								'callback'=>$callback
							];
						};
						mysql_query_builder::$queued_queries[$queue]['raw'][]=$query_queue($vars);
						self::emit_observer_event([
							'event'=>'queue_push',
							'operation'=>'upsert',
							'location'=>$location,
							'cluster'=>$dbms_cluster,
							'dbms'=>$dbms,
							'queue'=>self::trace_queue_name($queue),
							'queued'=>true,
							'invalidation_names'=>self::trace_invalidation_names($clear_cache),
						]);
						return null;
					}
					$query_result=mysql_query_builder::mysql_query($dbms_cluster, $sql, $vars, false, false);
					break;
				case "postgresql":
					$placeholders=implode(",", array_map(fn($i)=>'$'.$i, range(1, count($fields))));
					$conflict_target='('.implode(',', array_map(fn($k)=>"\"$k\"", $conflict_keys)).')';
					$updates=implode(',', array_map(fn($k)=>"\"$k\"=EXCLUDED.\"$k\"", array_keys($fields)));
					$sql="INSERT INTO $quoted_location (\"$quoted_columns\") VALUES ($placeholders) ON CONFLICT $conflict_target DO UPDATE SET $updates";
					if($callback){
						$query_queue=function($vars)use($location, $sql, $clear_cache, $callback){
							return [
								'location'=>$location,
								'query'=>$sql,
								'vars'=>$vars,
								'associative'=>false,
								'caching'=>false,
								'multipoint'=>false,
								'clear_cache'=>$clear_cache,
								'callback'=>$callback
							];
						};
						postgresql_query_builder::$queued_queries[$queue]['raw'][]=$query_queue($vars);
						self::emit_observer_event([
							'event'=>'queue_push',
							'operation'=>'upsert',
							'location'=>$location,
							'cluster'=>$dbms_cluster,
							'dbms'=>$dbms,
							'queue'=>self::trace_queue_name($queue),
							'queued'=>true,
							'invalidation_names'=>self::trace_invalidation_names($clear_cache),
						]);
						return null;
					}
					$query_result=postgresql_query_builder::postgresql_query($dbms_cluster, $sql, $vars, false, false);
					break;
				case "sqlite":
					$placeholders=implode(",", array_fill(0, count($fields), "?"));
					$updates=implode(",", array_map(fn($k)=>"\"$k\"=excluded.\"$k\"", $columns));
					$sql="INSERT INTO $quoted_location (\"".implode('","', $columns)."\") VALUES ($placeholders) ON CONFLICT DO UPDATE SET $updates";
					if($callback){
						$query_queue=function($vars)use($location, $sql, $clear_cache, $callback){
							return [
								'location'=>$location,
								'query'=>$sql,
								'vars'=>$vars,
								'associative'=>false,
								'caching'=>false,
								'multipoint'=>false,
								'clear_cache'=>$clear_cache,
								'callback'=>$callback
							];
						};
						sqlite_query_builder::$queued_queries[$queue]['raw'][]=$query_queue($vars);
						self::emit_observer_event([
							'event'=>'queue_push',
							'operation'=>'upsert',
							'location'=>$location,
							'cluster'=>$dbms_cluster,
							'dbms'=>$dbms,
							'queue'=>self::trace_queue_name($queue),
							'queued'=>true,
							'invalidation_names'=>self::trace_invalidation_names($clear_cache),
						]);
						return null;
					}
					$query_result=sqlite_query_builder::sqlite_query($dbms_cluster, $sql, $vars, false, false);
					break;
			}
			if($query_result!==false && $clear_cache!==false){
				self::invalidate_cache($clear_cache===true ? $location : $clear_cache);
			}
			self::emit_observer_event([
				'event'=>'execute',
				'operation'=>'upsert',
				'location'=>$location,
				'cluster'=>$dbms_cluster,
				'dbms'=>$dbms,
				'queued'=>false,
				'invalidation_names'=>self::trace_invalidation_names($clear_cache),
				'result_ok'=>$query_result!==false && $query_result!==null,
			]);
			return $query_result===false ? false : true;
		}
		$updated=self::update($location, $fields, $update_params, $update_vars, $clear_cache, $queue, $callback);
		if($updated===0){
			$inserted=self::insert($location, $fields, null, $clear_cache, $queue, $callback);
			return $inserted===false ? false : true;
		}
		return $updated;
	}

}
