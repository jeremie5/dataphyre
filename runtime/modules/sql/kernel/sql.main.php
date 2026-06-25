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

/**
 * Kernel SQL bridge for queries, schema hydration, caching, queues, and traces.
 *
 * The class owns process-local observers, last-query failure state, registered
 * table definitions, readonly replay guards, and shutdown cache garbage collection
 * while global helpers provide the legacy snake_case SQL API.
 */
class sql {

	private static array $observers=[];
	private static ?array $last_query_error=null;
	private static array $table_definition_registry=[];
	private static array $loaded_table_definition_files=[];
	private static array $structure_hydration_retrying=[];

	/**
	 * Initializes the SQL kernel instance and registers bounded session-cache garbage collection on shutdown.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $dbms_cluster SQL cluster name used to select the configured DBMS connection.
	 */
	public function __construct(string $dbms_cluster="sql"){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		core::dialback("CALL_SQL_CONSTRUCT",...func_get_args());
		register_shutdown_function(function(){
			try{
				self::session_cache_gc();
			}catch(\Throwable $exception){
				\dataphyre_shutdown_log('Fatal error on Dataphyre SQL session cache garbage collection shutdown callback', $exception);
			}
		});
	}
	
	/**
	 * Prunes session-backed query cache entries by table count, entry count, TTL, time budget, and memory pressure.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @return void SQL trace, cache, transaction, or error state is updated in place.
	 */
	public static function session_cache_gc(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(self::readonly_replay_enabled()===true){
			return;
		}
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

	/**
	 * Builds and emits SQL trace metadata for observers, diagnostics, and readonly replay auditing.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param callable $observer Callable receiving normalized SQL trace events.
	 * @return void SQL trace, cache, transaction, or error state is updated in place.
	 */
	public static function add_observer(callable $observer): void {
		self::$observers[]=$observer;
	}

	/**
	 * Builds and emits SQL trace metadata for observers, diagnostics, and readonly replay auditing.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @return void SQL trace, cache, transaction, or error state is updated in place.
	 */
	public static function clear_observers(): void {
		self::$observers=[];
	}

	/**
	 * Emits a normalized SQL observer event to all registered observers.
	 *
	 * Observer failures are isolated from query execution and logged as warnings
	 * so debugbar or diagnostics listeners cannot break production SQL calls. A
	 * timestamp is added when callers have not already supplied one.
	 *
	 * @param array<string,mixed> $event Observer event payload.
	 * @return void
	 */
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

	/**
	 * Reports whether Flightdeck readonly replay mode is protecting SQL writes.
	 *
	 * Readonly replay lets production requests be inspected without mutating the
	 * database, queues, or session cache.
	 *
	 * @return bool Whether SQL write paths should be blocked.
	 */
	private static function readonly_replay_enabled(): bool {
		return defined('DATAPHYRE_FLIGHTDECK_REPLAY_READONLY') && DATAPHYRE_FLIGHTDECK_REPLAY_READONLY===true;
	}

	/**
	 * Records that a write operation was blocked by readonly replay mode.
	 *
	 * The block count is stored globally for Flightdeck summary reporting, a
	 * warning trace is emitted, and observers receive a synthetic failure event
	 * containing the redacted trace context.
	 *
	 * @param string $operation SQL operation name.
	 * @param string $location Resolved table or query location.
	 * @param array<string,mixed> $context Observer trace context.
	 * @return void
	 */
	private static function readonly_replay_block(string $operation, string $location='', array $context=[]): void {
		$GLOBALS['dataphyre_flightdeck_replay_write_blocks']=(int)($GLOBALS['dataphyre_flightdeck_replay_write_blocks'] ?? 0) + 1;
		self::$last_query_error=[
			'dbms'=>'readonly-replay',
			'cluster'=>'N/A',
			'query'=>(string)($context['query'] ?? $context['statement'] ?? $operation),
			'vars'=>[],
			'exception'=>null,
			'message'=>'Flightdeck read-only replay blocked a SQL write.',
		];
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Flightdeck production replay blocked '.$operation.($location!=='' ? ' on '.$location : ''), $S='warning');
		self::emit_observer_event([
			'event'=>'readonly_block',
			'operation'=>$operation,
			'location'=>$location,
			'queued'=>false,
			'result_ok'=>false,
			'context'=>$context,
		]);
	}

	/**
	 * Normalizes cache names from a read-query caching argument.
	 *
	 * Boolean/null disabled values produce no names, scalar names are promoted to a
	 * list, and the special `lazy` cache mode is excluded from observer labels
	 * because it describes behavior rather than a concrete namespace.
	 *
	 * @param null|bool|array<int|string,mixed>|string $caching Cache argument from a read operation.
	 * @return array<int,string> Unique non-empty cache namespace names.
	 */
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

	/**
	 * Normalizes cache invalidation names from a write operation.
	 *
	 * True means "the current table" and is therefore not reported as a named
	 * namespace here; explicit arrays are filtered to unique non-empty strings.
	 *
	 * @param bool|array<int|string,mixed>|null $clear_cache Cache invalidation argument.
	 * @return array<int,string> Unique explicit invalidation namespace names.
	 */
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

	/**
	 * Normalizes a queue name for observer payloads.
	 *
	 * @param ?string $queue Queue argument from a SQL operation.
	 * @return ?string Trimmed queue name, or null for immediate execution/no queue.
	 */
	private static function trace_queue_name(?string $queue): ?string {
		if($queue===null){
			return null;
		}
		$queue=trim($queue);
		return $queue!=='' ? $queue : null;
	}

	/**
	 * Calculates elapsed query time in milliseconds for trace payloads.
	 *
	 * @param float $started_at microtime(true) value captured before work began.
	 * @return float Non-negative elapsed milliseconds rounded to three decimals.
	 */
	private static function trace_elapsed_ms(float $started_at): float {
		return round(max(0, (microtime(true)-$started_at)*1000), 3);
	}

	/**
	 * Summarizes the result count for observer payloads.
	 *
	 * List arrays count rows, associative arrays represent one row/object payload,
	 * integer driver results are passed through, and false/null represent zero
	 * successful results.
	 *
	 * @param mixed $result SQL driver result or callback result.
	 * @return ?int Result count, or null when the shape is not countable.
	 */
	private static function trace_result_count(mixed $result): ?int {
		if(is_array($result)){
			return array_is_list($result) ? count($result) : 1;
		}
		if(is_int($result)){
			return $result;
		}
		if($result===null || $result===false){
			return 0;
		}
		return null;
	}

	/**
	 * Selects the most useful non-SQL caller frame for a trace event.
	 *
	 * Internal SQL and Flightdeck frames are skipped so observers can attribute a
	 * query to application code. If every frame is internal, the first non-kernel
	 * fallback is returned.
	 *
	 * @return array<string,mixed> Normalized caller frame.
	 */
	private static function trace_caller(): array {
		$frames=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 18);
		$fallback=null;
		foreach($frames as $frame){
			if(empty($frame['file'])){
				continue;
			}
			$normalized=str_replace('\\', '/', (string)$frame['file']);
			if($fallback===null && !str_contains($normalized, '/modules/sql/kernel/sql.main.php')){
				$fallback=$frame;
			}
			if(str_contains($normalized, '/modules/sql/')){
				continue;
			}
			if(str_contains($normalized, '/modules/flightdeck/')){
				continue;
			}
			return self::trace_frame($frame);
		}
		return self::trace_frame($fallback ?? ($frames[0] ?? []));
	}

	/**
	 * Builds a bounded stack of non-internal caller frames for SQL diagnostics.
	 *
	 * The stack omits this SQL kernel and Flightdeck debug frames, then caps output
	 * to eight entries to keep observer events small.
	 *
	 * @return array<int,array<string,mixed>> Normalized trace frames.
	 */
	private static function trace_stack(): array {
		$frames=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 18);
		$stack=[];
		foreach($frames as $frame){
			if(empty($frame['file'])){
				continue;
			}
			$normalized=str_replace('\\', '/', (string)$frame['file']);
			if(str_contains($normalized, '/modules/sql/kernel/sql.main.php')){
				continue;
			}
			if(str_contains($normalized, '/modules/flightdeck/')){
				continue;
			}
			$stack[]=self::trace_frame($frame);
			if(count($stack)>=8){
				break;
			}
		}
		return $stack;
	}

	/**
	 * Normalizes one PHP backtrace frame for observer payloads.
	 *
	 * @param array<string,mixed> $frame Raw debug_backtrace() frame.
	 * @return array{file:string,line:int,class:string,function:string,call:string,label:string} Trace frame summary.
	 */
	private static function trace_frame(array $frame): array {
		$file=(string)($frame['file'] ?? '');
		$line=(int)($frame['line'] ?? 0);
		$class=(string)($frame['class'] ?? '');
		$type=(string)($frame['type'] ?? '');
		$function=(string)($frame['function'] ?? '');
		$call=trim($class.$type.$function);
		$file_label=$file!=='' ? basename($file).($line>0 ? ':'.$line : '') : '';
		return [
			'file'=>$file,
			'line'=>$line,
			'class'=>$class,
			'function'=>$function,
			'call'=>$call,
			'label'=>trim($file_label.($call!=='' ? ' '.$call : '')),
		];
	}

	/**
	 * Creates a human-readable SQL statement preview for trace payloads.
	 *
	 * The preview preserves operation, table, selected fields, and parameter
	 * clauses without interpolating bound values into SQL text.
	 *
	 * @param string $operation SQL operation name.
	 * @param string $location Resolved table or query location.
	 * @param mixed $primary Select list, field list, or raw query text.
	 * @param mixed $params WHERE/parameter clause text.
	 * @return string Statement preview for observers.
	 */
	private static function trace_statement(string $operation, string $location, mixed $primary=null, mixed $params=null): string {
		$location=trim($location);
		$params=is_string($params) ? trim($params) : '';
		$primary=is_string($primary) ? trim($primary) : '';
		return match($operation){
			'select'=>$params!=='' ? "SELECT {$primary} FROM {$location} {$params}" : "SELECT {$primary} FROM {$location}",
			'count'=>$params!=='' ? "SELECT COUNT(*) FROM {$location} {$params}" : "SELECT COUNT(*) FROM {$location}",
			'insert'=>"INSERT INTO {$location} ({$primary}) VALUES (...)",
			'update'=>$params!=='' ? "UPDATE {$location} SET {$primary} {$params}" : "UPDATE {$location} SET {$primary}",
			'delete'=>$params!=='' ? "DELETE FROM {$location} {$params}" : "DELETE FROM {$location}",
			default=>$primary,
		};
	}

	/**
	 * Builds redacted observer context for a SQL operation.
	 *
	 * Context is only assembled when observers exist, avoiding debug_backtrace()
	 * overhead on ordinary requests. Bound values are sanitized, and caller/stack
	 * metadata is added unless the caller already supplied it.
	 *
	 * @param string $operation SQL operation name.
	 * @param string $location Resolved table or query location.
	 * @param array<string,mixed> $context Partial trace context.
	 * @return array<string,mixed> Observer context payload.
	 */
	private static function trace_payload(string $operation, string $location, array $context=[]): array {
		if(self::$observers===[]){
			return [];
		}
		$context['statement'] ??= self::trace_statement(
			$operation,
			$location,
			$context['select'] ?? $context['fields'] ?? $context['query'] ?? null,
			$context['params'] ?? null
		);
		if(isset($context['vars']) && is_array($context['vars'])){
			$context['vars']=self::trace_values($context['vars']);
		}
		if(!isset($context['caller'])){
			$context['caller']=self::trace_caller();
		}
		if(!isset($context['stack'])){
			$context['stack']=self::trace_stack();
		}
		return $context;
	}

	/**
	 * Redacts and bounds bound-variable values before observer emission.
	 *
	 * Sensitive keys are replaced with `[redacted]`, nested arrays are traversed,
	 * objects are reduced to their debug type, and very long strings are truncated
	 * so SQL traces remain safe to render in debug tools.
	 *
	 * @param array<string|int,mixed> $vars Bound variable map or list.
	 * @return array<string|int,mixed> Sanitized variable payload.
	 */
	private static function trace_values(array $vars): array {
		$resolved=[];
		foreach($vars as $key=>$value){
			if(is_string($key) && preg_match('/password|passwd|secret|token|csrf|api[_-]?key|authorization|cookie/i', $key)===1){
				$resolved[$key]='[redacted]';
				continue;
			}
			if(is_array($value)){
				$resolved[$key]=self::trace_values($value);
				continue;
			}
			if(is_object($value)){
				$resolved[$key]='[object '.get_debug_type($value).']';
				continue;
			}
			if(is_string($value) && strlen($value)>1000){
				$value=substr($value, 0, 1000).'...';
			}
			$resolved[$key]=$value;
		}
		return $resolved;
	}
	
	/**
	 * Records, clears, or inspects the last SQL failure for missing table/column recovery.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $dbms Resolved DBMS driver name.
	 * @param string $cluster SQL cluster name used to select the configured DBMS connection.
	 * @param string $query SQL string or queued query payload.
	 * @param ?array $vars Bound parameter values used by prepared SQL execution.
	 * @param Throwable $exception Throwable captured while logging a query failure.
	 * @return void SQL trace, cache, transaction, or error state is updated in place.
	 */
	public static function log_query_error(string $dbms, string $cluster, string $query, ?array $vars=[], ?\Throwable $exception=null): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		self::$last_query_error=[
			'dbms'=>$dbms,
			'cluster'=>$cluster,
			'query'=>$query,
			'vars'=>$vars ?? [],
			'exception'=>$exception,
			'message'=>$exception ? $exception->getMessage() : 'Unknown error',
		];
		if(self::last_query_failed_because_table_missing()===true && self::registered_table_from_last_query_error()!==null){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Recoverable missing table detected for '.$dbms.' query on cluster '.$cluster, $S='warning');
			return;
		}
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

	/**
	 * Records, clears, or inspects the last SQL failure for missing table/column recovery.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @return void SQL trace, cache, transaction, or error state is updated in place.
	 */
	public static function clear_last_query_error(): void {
		self::$last_query_error=null;
	}

	/**
	 * Records, clears, or inspects the last SQL failure for missing table/column recovery.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @return ?array Structured SQL trace, cache, definition, manifest, or diagnostic payload.
	 */
	public static function last_query_error(): ?array {
		return self::$last_query_error;
	}

	/**
	 * Records, clears, or inspects the last SQL failure for missing table/column recovery.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param ?string $table Logical or physical table name checked against the last query failure.
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
	public static function last_query_failed_because_table_missing(?string $table=null): bool {
		if(self::$last_query_error===null){
			return false;
		}
		$message=strtolower((string)(self::$last_query_error['message'] ?? ''));
		$query=strtolower((string)(self::$last_query_error['query'] ?? ''));
		$missing_table=str_contains($message, 'base table or view not found')
			|| str_contains($message, "doesn't exist")
			|| str_contains($message, 'undefined table')
			|| str_contains($message, 'undefined_table')
			|| str_contains($message, 'no such table')
			|| preg_match('/\brelation\s+"?[^"]+"?\s+does not exist\b/', $message)===1
			|| str_contains($message, 'sqlstate[42s02]')
			|| str_contains($message, 'sqlstate[42p01]')
			|| preg_match('/\b1146\b/', $message)===1
			|| preg_match('/\b42s02\b/', $message)===1
			|| preg_match('/\b42p01\b/', $message)===1;
		if($missing_table===false){
			return false;
		}
		if($table===null || trim($table)===''){
			return true;
		}
		$table=strtolower(trim($table, " \t\n\r\0\x0B`\"'"));
		$normalized_message=str_replace(['`', '"', "'"], '', $message);
		$normalized_query=str_replace(['`', '"', "'"], '', $query);
		return str_contains($normalized_message, $table) || str_contains($normalized_query, $table);
	}

	/**
	 * Records, clears, or inspects the last SQL failure for missing table/column recovery.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param ?string $table Logical or physical table name checked against the last query failure.
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
	public static function last_query_failed_because_column_missing(?string $table=null): bool {
		if(self::$last_query_error===null){
			return false;
		}
		$message=strtolower((string)(self::$last_query_error['message'] ?? ''));
		$missing_column=str_contains($message, 'unknown column')
			|| str_contains($message, 'undefined column')
			|| str_contains($message, 'undefined_column')
			|| str_contains($message, 'no such column')
			|| preg_match('/\bcolumn\s+"?[^"]+"?(?:\s+of\s+relation\s+"?[^"]+"?)?\s+does not exist\b/', $message)===1
			|| str_contains($message, 'sqlstate[42s22]')
			|| str_contains($message, 'sqlstate[42703]')
			|| preg_match('/\b1054\b/', $message)===1
			|| preg_match('/\b42s22\b/', $message)===1
			|| preg_match('/\b42703\b/', $message)===1;
		if($missing_column===false){
			return false;
		}
		if($table===null || trim($table)===''){
			return true;
		}
		return self::last_query_mentions_table($table);
	}

	/**
	 * Registers, loads, resolves, or hydrates SQL table definitions and missing schema structures.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $table Logical or physical table name checked against the last query failure.
	 * @param string $definition_file PHP file containing TableDefinition declarations.
	 * @param ?string $definition_id Optional id used to select one definition from a definition file.
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
	public static function define_table(string $table, string $definition_file, ?string $definition_id=null): bool {
		$table=self::table($table);
		$definition_file=trim($definition_file);
		if($definition_file===''){
			return false;
		}
		self::$table_definition_registry[$table]=[
			'file'=>$definition_file,
			'definition_id'=>$definition_id,
		];
		return true;
	}

	/**
	 * Registers, loads, resolves, or hydrates SQL table definitions and missing schema structures.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param ?string $location Table location or logical table key resolved by table().
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
	public static function hydrate_missing_table_from_definition(?string $location=null): bool {
		return self::hydrate_missing_structure_from_definition($location);
	}

	/**
	 * Registers, loads, resolves, or hydrates SQL table definitions and missing schema structures.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param ?string $location Table location or logical table key resolved by table().
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
	public static function hydrate_missing_structure_from_definition(?string $location=null): bool {
		$table_missing=self::last_query_failed_because_table_missing($location);
		$column_missing=self::last_query_failed_because_column_missing($location);
		if($table_missing===false && $column_missing===false){
			return false;
		}
		if($location===null || trim($location)===''){
			$location=self::registered_table_from_last_query_error();
			if($location===null){
				return false;
			}
		}
		if($column_missing===true && $table_missing===false){
			$column=self::missing_column_from_last_query_error();
			if($column===null){
				return false;
			}
			$definition=self::table_definition_for($location);
			if($definition===null){
				return false;
			}
			try{
				$result=$definition->hydrateColumn($column);
				if($result===true){
					self::clear_last_query_error();
				}
				return $result;
			}catch(\Throwable $exception){
				self::log_query_error('unknown', 'N/A', 'hydrate table column '.$location.'.'.$column, [], $exception);
				return false;
			}
		}
		return self::hydrate_table_definition($location);
	}

	/**
	 * Registers, loads, resolves, or hydrates SQL table definitions and missing schema structures.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $location Table location or logical table key resolved by table().
	 * @param mixed $select SELECT clause or structured select payload.
	 * @param mixed $params WHERE clause, parameter array, or action params depending on helper context.
	 * @param mixed $fields INSERT/UPDATE field map or SQL field fragment.
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
	public static function hydrate_missing_table_from_call(string $location, mixed $select=null, mixed $params=null, mixed $fields=null): bool {
		return self::hydrate_missing_table_from_definition($location);
	}

	/**
	 * Registers, loads, resolves, or hydrates SQL table definitions and missing schema structures.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $location Table location or logical table key resolved by table().
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
	public static function hydrate_table_definition(string $location): bool {
		$location=self::table($location);
		$definition=self::table_definition_for($location);
		if($definition===null){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='No Dataphyre table definition registered for '.$location, $S='warning');
			return false;
		}
		try{
			$result=$definition->hydrate();
			if($result===true){
				self::clear_last_query_error();
			}
			return $result;
		}catch(\Throwable $exception){
			self::log_query_error('unknown', 'N/A', 'hydrate table definition '.$location, [], $exception);
			return false;
		}
	}

	/**
	 * Registers, loads, resolves, or hydrates SQL table definitions and missing schema structures.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $location Table location or logical table key resolved by table().
	 * @return ?\Dataphyre\Database\TableDefinition Resolved table definition, or null when no registered definition matches.
	 */
	public static function table_definition(string $location): ?\Dataphyre\Database\TableDefinition {
		return self::table_definition_for($location);
	}

	/**
	 * Supports SQL kernel execution, table metadata, cache policy, queues, transactions, or diagnostics.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $location Table location or logical table key resolved by table().
	 * @return ?\Dataphyre\Database\TableSchema Resolved table schema, or null when metadata is unavailable.
	 */
	public static function table_schema(string $location): ?\Dataphyre\Database\TableSchema {
		return self::table_definition_for($location)?->schema();
	}

	/**
	 * Resolves the TableDefinition registered for a table location.
	 *
	 * Deferred definitions and the runtime manifest are consulted lazily before a
	 * definition file is required. The SQL framework must be available before a
	 * TableDefinition instance can be returned.
	 *
	 * @param string $location Logical or physical table location.
	 * @return ?\Dataphyre\Database\TableDefinition Resolved table definition, or null.
	 */
	private static function table_definition_for(string $location): ?\Dataphyre\Database\TableDefinition {
		$location=self::table($location);
		$entry=self::$table_definition_registry[$location] ?? null;
		if($entry===null && \function_exists('dp_sql_run_deferred_table_definitions')){
			\dp_sql_run_deferred_table_definitions();
			$entry=self::$table_definition_registry[$location] ?? null;
		}
		if($entry===null){
			self::register_runtime_table_definition($location);
			$entry=self::$table_definition_registry[$location] ?? null;
		}
		if($entry===null){
			return null;
		}
		if(core::load_framework_module('sql')===false || class_exists(\Dataphyre\Database\TableDefinition::class)===false){
			return null;
		}
		$definitions=self::load_table_definition_file((string)$entry['file']);
		if($definitions===null){
			return null;
		}
		return self::resolve_table_definition($definitions, $location, $entry['definition_id'] ?? null);
	}

	/**
	 * Loads and memoizes a PHP table-definition file.
	 *
	 * Files may return a single TableDefinition, an array of definitions/callables,
	 * or a callable factory. Missing files are cached as null to prevent repeated
	 * filesystem checks during recovery loops.
	 *
	 * @param string $definition_file Absolute table-definition file path.
	 * @return mixed Definition payload returned by the file, or null.
	 */
	private static function load_table_definition_file(string $definition_file): mixed {
		$definition_file=str_replace('\\', '/', $definition_file);
		$cache_key=realpath($definition_file) ?: $definition_file;
		if(array_key_exists($cache_key, self::$loaded_table_definition_files)){
			return self::$loaded_table_definition_files[$cache_key];
		}
		if(is_file($definition_file)===false){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Dataphyre table definition file not found: '.$definition_file, $S='warning');
			self::$loaded_table_definition_files[$cache_key]=null;
			return null;
		}
		$definitions=require($definition_file);
		self::$loaded_table_definition_files[$cache_key]=$definitions;
		return $definitions;
	}

	/**
	 * Chooses a TableDefinition from a loaded definition payload.
	 *
	 * Resolution tries explicit definition id, table location, and then every
	 * array entry. Callable definitions are invoked with the table location and id.
	 *
	 * @param mixed $definitions Loaded definition payload.
	 * @param string $location Resolved table location.
	 * @param ?string $definition_id Optional definition id registered for the table.
	 * @return ?\Dataphyre\Database\TableDefinition Resolved definition, or null.
	 */
	private static function resolve_table_definition(mixed $definitions, string $location, ?string $definition_id): ?\Dataphyre\Database\TableDefinition {
		if(is_callable($definitions)){
			$definitions=$definitions($location, $definition_id);
		}
		if($definitions instanceof \Dataphyre\Database\TableDefinition){
			return $definitions;
		}
		if(is_array($definitions)===false){
			return null;
		}
		$candidates=[];
		if($definition_id!==null && array_key_exists($definition_id, $definitions)){
			$candidates[]=$definitions[$definition_id];
		}
		if(array_key_exists($location, $definitions)){
			$candidates[]=$definitions[$location];
		}
		foreach($definitions as $definition){
			$candidates[]=$definition;
		}
		foreach($candidates as $candidate){
			if(is_callable($candidate)){
				$candidate=$candidate($location, $definition_id);
			}
			if($candidate instanceof \Dataphyre\Database\TableDefinition){
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Finds a registered table definition related to the last SQL error.
	 *
	 * The method runs deferred registrations, derives candidate table names from
	 * the failed SQL/error text, attempts runtime manifest registration for those
	 * candidates, and then matches the normalized error haystack against registered
	 * table locations and basenames.
	 *
	 * @return ?string Registered table location related to the last error.
	 */
	private static function registered_table_from_last_query_error(): ?string {
		if(self::$last_query_error===null){
			return null;
		}
		if(\function_exists('dp_sql_run_deferred_table_definitions')){
			\dp_sql_run_deferred_table_definitions();
		}
		foreach(self::last_query_table_candidates() as $candidate){
			self::register_runtime_table_definition($candidate);
		}
		$haystack=strtolower(str_replace(
			['`', '"', "'", '[', ']'],
			'',
			(string)(self::$last_query_error['message'] ?? '').' '.(string)(self::$last_query_error['query'] ?? '')
		));
		foreach(array_keys(self::$table_definition_registry) as $location){
			$location=strtolower($location);
			$parts=explode('.', $location);
			$table=(string)end($parts);
			if(str_contains($haystack, $location) || preg_match('/(^|[^a-z0-9_])'.preg_quote($table, '/').'([^a-z0-9_]|$)/', $haystack)===1){
				return $location;
			}
		}
		return null;
	}

	/**
	 * Extracts likely table names from the last SQL query error.
	 *
	 * Patterns cover PostgreSQL relation errors and common FROM/INTO/UPDATE/JOIN
	 * clauses. Candidates are normalized to lowercase and restricted to simple
	 * optional-schema table names.
	 *
	 * @return array<int,string> Candidate table locations.
	 */
	private static function last_query_table_candidates(): array {
		if(self::$last_query_error===null){
			return [];
		}
		$haystack=(string)(self::$last_query_error['message'] ?? '').' '.(string)(self::$last_query_error['query'] ?? '');
		$candidates=[];
		$patterns=[
			'/\brelation\s+"([^"]+)"/i',
			'/\b(?:from|into|update|join|table)\s+((?:"?[A-Za-z_][A-Za-z0-9_]*"?\.)?"?[A-Za-z_][A-Za-z0-9_]*"?)/i',
		];
		foreach($patterns as $pattern){
			if(preg_match_all($pattern, $haystack, $matches)){
				foreach($matches[1] as $candidate){
					$candidate=strtolower(str_replace(['`', '"', "'"], '', trim((string)$candidate)));
					if(preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $candidate)===1){
						$candidates[]=$candidate;
					}
				}
			}
		}
		return array_values(array_unique($candidates));
	}

	/**
	 * Registers a table definition from Dataphyre's built-in runtime manifest.
	 *
	 * Runtime definitions let core module tables hydrate themselves after a missing
	 * table/column error even when application code did not register the definition
	 * explicitly.
	 *
	 * @param string $location Logical or physical table location.
	 * @return bool Whether a runtime definition was registered or already existed.
	 */
	private static function register_runtime_table_definition(string $location): bool {
		$location=self::table($location);
		if(isset(self::$table_definition_registry[$location])){
			return true;
		}
		$manifest=self::runtime_table_definition_manifest();
		$entry=$manifest[$location] ?? null;
		if($entry===null){
			return false;
		}
		$file=self::runtime_module_table_file((string)$entry['file']);
		if($file===null){
			return false;
		}
		self::$table_definition_registry[$location]=[
			'file'=>$file,
			'definition_id'=>$entry['definition_id'] ?? null,
		];
		return true;
	}

	/**
	 * Lists built-in Dataphyre runtime table definitions available for recovery.
	 *
	 * Entries map physical table locations to module-local table definition files
	 * and definition ids.
	 *
	 * @return array<string,array{file:string,definition_id:string}> Runtime table definition manifest.
	 */
	private static function runtime_table_definition_manifest(): array {
		return [
			'dataphyre.sessions'=>['file'=>'access/kernel/access.tables.php', 'definition_id'=>'sessions'],
			'dataphyre.access_tokens'=>['file'=>'access/kernel/access.tables.php', 'definition_id'=>'tokens'],
			'dataphyre.aceit_engine_experiments'=>['file'=>'aceit_engine/aceit_engine.tables.php', 'definition_id'=>'experiments'],
			'dataphyre.vestra_objects'=>['file'=>'vestra/kernel/vestra.tables.php', 'definition_id'=>'objects'],
			'dataphyre.exchange_rates'=>['file'=>'currency/kernel/currency.tables.php', 'definition_id'=>'exchange_rates'],
			'datadoc.projects'=>['file'=>'datadoc/kernel/datadoc.tables.php', 'definition_id'=>'projects'],
			'dataphyre.datadoc_data'=>['file'=>'datadoc/kernel/datadoc.tables.php', 'definition_id'=>'data'],
			'dataphyre.datadoc_files'=>['file'=>'datadoc/kernel/datadoc.tables.php', 'definition_id'=>'files'],
			'dataphyre.captcha_blocks'=>['file'=>'firewall/kernel/firewall.tables.php', 'definition_id'=>'captcha_blocks'],
			'dataphyre.mailer_outbox'=>['file'=>'mailer/kernel/mailer.tables.php', 'definition_id'=>'outbox'],
			'dataphyre.mailer_events'=>['file'=>'mailer/kernel/mailer.tables.php', 'definition_id'=>'events'],
			'dataphyre.postal_codes_regex'=>['file'=>'geoposition/kernel/geoposition.tables.php', 'definition_id'=>'postal_codes_regex'],
			'dataphyre.postal_codes'=>['file'=>'geoposition/kernel/geoposition.tables.php', 'definition_id'=>'postal_codes'],
			'issues'=>['file'=>'issue/kernel/issue.tables.php', 'definition_id'=>'issues'],
			'locales'=>['file'=>'localization/kernel/localization.tables.php', 'definition_id'=>'locales'],
			'dataphyre.internal_events'=>['file'=>'sentinel/kernel/sentinel.tables.php', 'definition_id'=>'events'],
			'stripe_payment_methods'=>['file'=>'stripe/kernel/stripe.tables.php', 'definition_id'=>'payment_methods'],
			'dataphyre.user_changes'=>['file'=>'time_machine/kernel/time_machine.tables.php', 'definition_id'=>'user_changes'],
			'dataphyre.tracelogs'=>['file'=>'tracelog/kernel/tracelog.tables.php', 'definition_id'=>'tracelogs'],
		];
	}

	/**
	 * Resolves a module-local table definition file against runtime module roots.
	 *
	 * ROOTPATH is preferred when available; dirname fallbacks keep diagnostics and
	 * standalone runtime contexts able to locate module table definitions.
	 *
	 * @param string $relative_file Module-relative table definition path.
	 * @return ?string Absolute file path, or null when unavailable.
	 */
	private static function runtime_module_table_file(string $relative_file): ?string {
		$relative_file=str_replace('\\', '/', trim($relative_file, '/\\'));
		$roots=[];
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre_runtime'])){
			$roots[]=rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules';
		}
		$roots[]=rtrim(dirname(__DIR__, 2), '/\\');
		foreach(array_values(array_unique($roots)) as $root){
			$file=$root.'/'.$relative_file;
			if(is_file($file)){
				return $file;
			}
		}
		return null;
	}

	/**
	 * Checks whether the last failed query text mentions a table.
	 *
	 * Both fully qualified table locations and unqualified basenames are matched
	 * against quote-normalized SQL text.
	 *
	 * @param string $table Table location or basename.
	 * @return bool Whether the query appears to reference the table.
	 */
	private static function last_query_mentions_table(string $table): bool {
		$table=strtolower(trim($table, " \t\n\r\0\x0B`\"'"));
		$normalized_query=strtolower(str_replace(['`', '"', "'"], '', (string)(self::$last_query_error['query'] ?? '')));
		$parts=explode('.', $table);
		$table_name=(string)end($parts);
		return str_contains($normalized_query, $table)
			|| preg_match('/(^|[^a-z0-9_])'.preg_quote($table_name, '/').'([^a-z0-9_]|$)/', $normalized_query)===1;
	}

	/**
	 * Extracts a missing column name from the last SQL error message.
	 *
	 * Driver-specific MySQL, PostgreSQL, and SQLite phrases are supported. Qualified
	 * names are reduced to the final column segment and unsafe identifiers are
	 * rejected.
	 *
	 * @return ?string Missing column name, or null when the error is not specific.
	 */
	private static function missing_column_from_last_query_error(): ?string {
		if(self::$last_query_error===null){
			return null;
		}
		$message=(string)(self::$last_query_error['message'] ?? '');
		$patterns=[
			"/unknown column\\s+'([^']+)'/i",
			'/column\\s+"([^"]+)"\\s+does not exist/i',
			'/column\\s+"([^"]+)"\\s+of\\s+relation\\s+"[^"]+"\\s+does not exist/i',
			'/no such column:\\s+([A-Za-z_][A-Za-z0-9_\\.]*)/i',
			'/undefined column:\\s+7\\s+ERROR:\\s+column\\s+"([^"]+)"/i',
		];
		foreach($patterns as $pattern){
			if(preg_match($pattern, $message, $matches)===1){
				$column=(string)$matches[1];
				if(str_contains($column, '.')){
					$parts=explode('.', $column);
					$column=(string)end($parts);
				}
				$column=trim($column, "`\"'");
				if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)===1){
					return $column;
				}
			}
		}
		return null;
	}

	/**
	 * Retries a failed operation once after schema hydration from table definitions.
	 *
	 * A per-operation/table guard prevents recursive retry loops. On successful
	 * hydration, related caches and last error state are cleared before the caller's
	 * retry closure is executed.
	 *
	 * @param string $operation SQL operation name.
	 * @param string $location Logical or physical table location.
	 * @param callable $retry Closure that re-runs the original SQL operation.
	 * @return mixed Retry result, or false when hydration/retry is not allowed.
	 */
	private static function retry_operation_after_structure_hydration(string $operation, string $location, callable $retry): mixed {
		$location=self::table($location);
		$key=$operation.':'.$location;
		if((self::$structure_hydration_retrying[$key] ?? false)===true){
			return false;
		}
		if(self::hydrate_missing_structure_from_definition($location)===false){
			return false;
		}
		self::invalidate_cache($location);
		self::clear_last_query_error();
		self::$structure_hydration_retrying[$key]=true;
		try{
			return $retry();
		}finally{
			unset(self::$structure_hydration_retrying[$key]);
		}
	}
	
	/**
	 * Supports SQL kernel execution, table metadata, cache policy, queues, transactions, or diagnostics.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $query SQL string or queued query payload.
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
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
	
	/**
	 * Reads, writes, prunes, or invalidates query cache entries according to table cache policy.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $location Table location or logical table key resolved by table().
	 * @return array|bool Structured SQL trace, cache, definition, manifest, or diagnostic payload.
	 */
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
	
	/**
	 * Executes queued SQL operations while preserving queue names and callback behavior.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $queue Queue name; end queues for shutdown execution while null executes immediately.
	 * @return null|bool Affected-row count, true/null queue status, or false when execution fails.
	 */
	public static function execute_queue(string $queue='end') : null|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$trace_started_at=microtime(true);
		if(self::readonly_replay_enabled()===true){
			self::readonly_replay_block('queue_execute', (string)$queue, [
				'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
			]);
			return null;
		}
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
				'context'=>[
					'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
					'result_count'=>self::trace_result_count($return),
				],
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
				'context'=>[
					'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
					'result_count'=>self::trace_result_count($return),
				],
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
				'context'=>[
					'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
					'result_count'=>self::trace_result_count($return),
				],
			]);
			return $return;
		}
		self::emit_observer_event([
			'event'=>'queue_execute_end',
			'operation'=>'queue_execute',
			'queue'=>self::trace_queue_name($queue),
			'queued'=>true,
			'result_ok'=>null,
			'context'=>[
				'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
				'result_count'=>0,
			],
		]);
		return null;
	}
	
	/**
	 * Tracks temporary database server availability for failover decisions.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $serverip Database server IP or host marked unavailable/available.
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
	public static function flag_server_unavailable(string $serverip) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_FLAG_SERVER_UNAVAILABLE",...func_get_args())) return $early_return;
		$_SESSION['unavailable_servers'][$serverip]=microtime();
		return true;
	}

	/**
	 * Tracks temporary database server availability for failover decisions.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $serverip Database server IP or host marked unavailable/available.
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
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
	
	/**
	 * Reads, writes, prunes, or invalidates query cache entries according to table cache policy.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $location Table location or logical table key resolved by table().
	 * @param string $hash Stable cache key for the SQL text and bound values.
	 * @param array|bool|null $cache_policy Resolved cache policy from table metadata or caller override.
	 * @return mixed Query result, cached payload, callback result, or false/null failure value from the driver.
	 */
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
	
	/**
	 * Reads, writes, prunes, or invalidates query cache entries according to table cache policy.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $location Table location or logical table key resolved by table().
	 * @param string $hash Stable cache key for the SQL text and bound values.
	 * @param mixed $query_result Result payload being stored in the query cache.
	 * @param list<bool|string>|array<string,bool|string> $caching Cache flag, cache namespace, or cache namespace list for read operations.
	 * @param array|bool|null $cache_policy Resolved cache policy from table metadata or caller override.
	 * @return mixed Query result, cached payload, callback result, or false/null failure value from the driver.
	 */
	public static function cache_query_result(string $location, string $hash, mixed $query_result, array $caching=[true], array|bool|null $cache_policy=null) : mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::readonly_replay_enabled()===true){
			self::readonly_replay_block('cache_store', $location, [
				'hash'=>$hash,
				'cache_names'=>self::trace_cache_names($caching),
			]);
			return true;
		}
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
	
	/**
	 * Reads, writes, prunes, or invalidates query cache entries according to table cache policy.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param array|string $clear_cache_for Cache invalidation flag or namespace list for write operations.
	 * @param array|bool|null $cache_policy Resolved cache policy from table metadata or caller override.
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
	public static function invalidate_cache(array|string $clear_cache_for, array|bool|null $cache_policy=null) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::readonly_replay_enabled()===true){
			self::readonly_replay_block('cache_invalidate', is_string($clear_cache_for) ? $clear_cache_for : '', [
				'invalidation_names'=>is_array($clear_cache_for) ? self::trace_invalidation_names($clear_cache_for) : [$clear_cache_for],
			]);
			return true;
		}
		if($cache_policy===null){
			if(is_string($clear_cache_for)){
				$cache_policy=self::get_table_cache_policy($clear_cache_for);
			}elseif(is_array($clear_cache_for)){
				$cache_policy=false;
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
						cache::delete($invalidation_cache[1].'_'.$invalidation_cache[2]);
					}
					elseif($invalidation_cache[0]==='session'){
						unset($_SESSION['db_cache'][$invalidation_cache[1]][$invalidation_cache[2]]);
					}
					elseif($invalidation_cache[0]==='fs'){
						$cache_file=__DIR__."/../../cache/sql/".$invalidation_cache[1]."/".$invalidation_cache[2];
						if(is_file($cache_file)){
							unlink($cache_file);
						}
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

	/**
	 * Resolves a logical table location to the physical table name and DBMS selected for the query.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $location Table location or logical table key resolved by table().
	 * @param ?string $query_dbms Output parameter receiving the DBMS chosen for a table location.
	 * @return string Resolved SQL statement, table name, queue name, or trace string.
	 */
	public static function table(string $location, ?string &$query_dbms=null): string {
		list($query_dbms, $location)=strpos($location, ':')!==false?explode(':', $location, 2):[null, $location];
		$default_database_location=trim((string)DP_SQL_CFG['default_database_location']);
		if(str_contains($location, '.')===false && $default_database_location!=='')$location=$default_database_location.".".$location;
		return $location;
	}

	/**
	 * Logs a SQL assertion failure while returning the original result for call-site compatibility.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param mixed $result Query result or callback result being counted, asserted, or merged.
	 * @param string $msg Assertion failure message logged when a SQL operation returns false.
	 * @return mixed Query result, cached payload, callback result, or false/null failure value from the driver.
	 */
	public static function assert(mixed $result, string $msg): mixed {
		if($result===false){
			throw new \RuntimeException($msg);
		}
		return $result;
	}
	
	/**
	 * Executes or controls a transaction on the selected SQL cluster.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param callable $fn Transactional callback executed between begin and commit.
	 * @param ?string $cluster SQL cluster name used to select the configured DBMS connection.
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
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
	
	/**
	 * Executes or controls a transaction on the selected SQL cluster.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param ?string $cluster SQL cluster name used to select the configured DBMS connection.
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
	public static function begin(?string $cluster=null): bool {
		return false!==self::query([
			'mysql'=>'START TRANSACTION',
			'postgresql'=>'BEGIN',
			'sqlite'=>'BEGIN TRANSACTION',
			'dbms_cluster_override'=>$cluster
		]);
	}

	/**
	 * Executes or controls a transaction on the selected SQL cluster.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param ?string $cluster SQL cluster name used to select the configured DBMS connection.
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
	public static function commit(?string $cluster=null): bool {
		return false!==self::query([
			'mysql'=>'COMMIT',
			'postgresql'=>'COMMIT',
			'sqlite'=>'COMMIT',
			'dbms_cluster_override'=>$cluster
		]);
	}

	/**
	 * Executes or controls a transaction on the selected SQL cluster.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param ?string $cluster SQL cluster name used to select the configured DBMS connection.
	 * @return bool True when the SQL metadata, transaction, cache, or write operation succeeds.
	 */
	public static function rollback(?string $cluster=null): bool {
		return false!==self::query([
			'mysql'=>'ROLLBACK',
			'postgresql'=>'ROLLBACK',
			'sqlite'=>'ROLLBACK',
			'dbms_cluster_override'=>$cluster
		]);
	}
	
	/**
	 * Executes raw SQL or queued SQL payloads with binding, caching, invalidation, callbacks, and observer traces.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string|array $query SQL string or queued query payload.
	 * @param ?array $vars Bound parameter values used by prepared SQL execution.
	 * @param ?bool $associative Whether result rows should be returned as associative arrays.
	 * @param ?bool $multipoint Whether a query array should be executed as a multi-statement operation.
	 * @param null|bool|array|string $caching Cache flag, cache namespace, or cache namespace list for read operations.
	 * @param bool|null|array $clear_cache Cache invalidation flag or namespace list for write operations.
	 * @param ?string $queue Queue name; end queues for shutdown execution while null executes immediately.
	 * @param ?callable $callback Optional callback invoked with the query result or queued operation result.
	 * @return mixed Query result, cached payload, callback result, or false/null failure value from the driver.
	 */
	public static function query(string|array $query, ?array $vars=null, ?bool $associative=false, ?bool $multipoint=false, null|bool|array|string $caching=[false], bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null) : mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_SELECT",...func_get_args())) return $early_return;
		$trace_started_at=microtime(true);
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
		if(self::readonly_replay_enabled()===true && self::query_has_write((string)$query)===true){
			self::readonly_replay_block('query', $location, self::trace_payload('query', $location, [
				'query'=>(string)$query,
				'vars'=>$vars ?? [],
				'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
				'cluster'=>$dbms_cluster,
				'dbms'=>$dbms,
			]));
			return false;
		}
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
						'context'=>self::trace_payload('query', $location, [
							'query'=>$query,
							'vars'=>$vars ?? [],
							'associative'=>$associative,
							'multipoint'=>$multipoint,
						]),
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
						'context'=>self::trace_payload('query', $location, [
							'query'=>$query,
							'vars'=>$vars ?? [],
							'associative'=>$associative,
							'multipoint'=>$multipoint,
						]),
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
						'context'=>self::trace_payload('query', $location, [
							'query'=>$query,
							'vars'=>$vars ?? [],
							'associative'=>$associative,
							'multipoint'=>$multipoint,
						]),
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
			'context'=>self::trace_payload('query', $location, [
				'query'=>$query,
				'vars'=>$vars ?? [],
				'associative'=>$associative,
				'multipoint'=>$multipoint,
				'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
				'result_count'=>self::trace_result_count($query_result),
			]),
		]);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Raw query finished, returning result");
		return $query_result;
    }
	
	/**
	 * Builds and executes a SELECT query with table resolution, caching, queueing, and hydration retry support.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string|array $select SELECT clause or structured select payload.
	 * @param string $location Table location or logical table key resolved by table().
	 * @param array|string|null $params WHERE clause, parameter array, or action params depending on helper context.
	 * @param ?array $vars Bound parameter values used by prepared SQL execution.
	 * @param ?bool $associative Whether result rows should be returned as associative arrays.
	 * @param null|bool|array|string $caching Cache flag, cache namespace, or cache namespace list for read operations.
	 * @param ?string $queue Queue name; end queues for shutdown execution while null executes immediately.
	 * @param ?callable $callback Optional callback invoked with the query result or queued operation result.
	 * @return mixed Query result, cached payload, callback result, or false/null failure value from the driver.
	 */
	public static function select(string|array $select, string $location, array|string|null $params=null, ?array $vars=null, ?bool $associative=false, null|bool|array|string $caching=[true], ?string $queue='end', ?callable $callback=null) : mixed { //bool|array|null
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_SELECT",...func_get_args())) return $early_return;
		$original_select=$select;
		$original_location=$location;
		$original_params=$params;
		$original_vars=$vars;
		$original_associative=$associative;
		$original_caching=$caching;
		$trace_started_at=microtime(true);
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
						'context'=>self::trace_payload('select', $location, [
							'select'=>$select,
							'params'=>$params,
							'vars'=>$vars ?? [],
							'associative'=>$associative,
						]),
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
						'context'=>self::trace_payload('select', $location, [
							'select'=>$select,
							'params'=>$params,
							'vars'=>$vars ?? [],
							'associative'=>$associative,
						]),
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
						'context'=>self::trace_payload('select', $location, [
							'select'=>$select,
							'params'=>$params,
							'vars'=>$vars ?? [],
							'associative'=>$associative,
						]),
					]);
					return null;
				}
				$query_result=sqlite_query_builder::sqlite_select($dbms_cluster, $select, $location, $params, $vars, $associative);
				break;
		}
		if($query_result===false){
			$retried=self::retry_operation_after_structure_hydration('select', $location, fn()=>self::select($original_select, $original_location, $original_params, $original_vars, $original_associative, $original_caching, $queue, $callback));
			if($retried!==false){
				return $retried;
			}
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
			'context'=>self::trace_payload('select', $location, [
				'select'=>$select,
				'params'=>$params,
				'vars'=>$vars ?? [],
				'associative'=>$associative,
				'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
				'result_count'=>self::trace_result_count($query_result),
			]),
		]);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Select query finished, returning result");
		return $query_result;
	}
	
	/**
	 * Builds and executes a COUNT query with cache and queue behavior matching select().
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $location Table location or logical table key resolved by table().
	 * @param array|string|null $params WHERE clause, parameter array, or action params depending on helper context.
	 * @param ?array $vars Bound parameter values used by prepared SQL execution.
	 * @param null|bool|array|string $caching Cache flag, cache namespace, or cache namespace list for read operations.
	 * @param ?string $queue Queue name; end queues for shutdown execution while null executes immediately.
	 * @param ?callable $callback Optional callback invoked with the query result or queued operation result.
	 * @return int|bool|null Affected-row count, true/null queue status, or false when execution fails.
	 */
	public static function count(string $location, array|string|null $params=null, ?array $vars=null, null|bool|array|string $caching=[true], ?string $queue='end', ?callable $callback=null) : int|bool|null {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_COUNT",...func_get_args())) return $early_return;
		$original_location=$location;
		$original_params=$params;
		$original_vars=$vars;
		$original_caching=$caching;
		$trace_started_at=microtime(true);
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
						'context'=>self::trace_payload('count', $location, [
							'params'=>$params,
							'vars'=>$vars ?? [],
						]),
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
						'context'=>self::trace_payload('count', $location, [
							'params'=>$params,
							'vars'=>$vars ?? [],
						]),
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
						'context'=>self::trace_payload('count', $location, [
							'params'=>$params,
							'vars'=>$vars ?? [],
						]),
					]);
					return null;
				}
				$query_result=sqlite_query_builder::sqlite_count($dbms_cluster, $location, $params, $vars);
				break;
		}
		if($query_result===false){
			$retried=self::retry_operation_after_structure_hydration('count', $location, fn()=>self::count($original_location, $original_params, $original_vars, $original_caching, $queue, $callback));
			if($retried!==false){
				return $retried;
			}
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
			'context'=>self::trace_payload('count', $location, [
				'params'=>$params,
				'vars'=>$vars ?? [],
				'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
				'result_count'=>self::trace_result_count($query_result),
			]),
		]);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Count query finished, returning result");
		return $query_result;
	}

	/**
	 * Builds and executes an INSERT operation with cache invalidation, queueing, and hydration retry support.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $location Table location or logical table key resolved by table().
	 * @param string|array $fields INSERT/UPDATE field map or SQL field fragment.
	 * @param ?array $vars Bound parameter values used by prepared SQL execution.
	 * @param bool|null|array $clear_cache Cache invalidation flag or namespace list for write operations.
	 * @param ?string $queue Queue name; end queues for shutdown execution while null executes immediately.
	 * @param ?callable $callback Optional callback invoked with the query result or queued operation result.
	 * @return mixed Query result, cached payload, callback result, or false/null failure value from the driver.
	 */
	public static function insert(string $location, string|array $fields, ?array $vars=null, bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null) : mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_INSERT",...func_get_args())) return $early_return;
		$original_location=$location;
		$original_fields=$fields;
		$original_vars=$vars;
		$original_clear_cache=$clear_cache;
		$trace_started_at=microtime(true);
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
		if(self::readonly_replay_enabled()===true){
			self::readonly_replay_block('insert', $location, self::trace_payload('insert', $location, [
				'fields'=>$fields,
				'vars'=>$vars ?? [],
				'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
				'cluster'=>$dbms_cluster,
				'dbms'=>$dbms,
			]));
			return $callback ? null : false;
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
						'context'=>self::trace_payload('insert', $location, [
							'fields'=>$fields,
							'vars'=>$vars ?? [],
						]),
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
						'context'=>self::trace_payload('insert', $location, [
							'fields'=>$fields,
							'vars'=>$vars ?? [],
						]),
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
						'context'=>self::trace_payload('insert', $location, [
							'fields'=>$fields,
							'vars'=>$vars ?? [],
						]),
					]);
					return null;
				}
				$query_result=sqlite_query_builder::sqlite_insert($dbms_cluster, $location, $fields, $vars, $returning);
				break;
		}
		if($query_result===false){
			$retried=self::retry_operation_after_structure_hydration('insert', $location, fn()=>self::insert($original_location, $original_fields, $original_vars, $original_clear_cache, $queue, $callback));
			if($retried!==false){
				return $retried;
			}
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
			'context'=>self::trace_payload('insert', $location, [
				'fields'=>$fields,
				'vars'=>$vars ?? [],
				'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
				'result_count'=>self::trace_result_count($query_result),
			]),
		]);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Insert query finished, returning result");
		return $query_result;
	}

	/**
	 * Builds and executes an UPDATE operation with safe parameter handling and cache invalidation.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $location Table location or logical table key resolved by table().
	 * @param string|array $fields INSERT/UPDATE field map or SQL field fragment.
	 * @param null|string|array $params WHERE clause, parameter array, or action params depending on helper context.
	 * @param ?array $vars Bound parameter values used by prepared SQL execution.
	 * @param bool|null|array $clear_cache Cache invalidation flag or namespace list for write operations.
	 * @param ?string $queue Queue name; end queues for shutdown execution while null executes immediately.
	 * @param ?callable $callback Optional callback invoked with the query result or queued operation result.
	 * @return int|bool|null Affected-row count, true/null queue status, or false when execution fails.
	 */
	public static function update(string $location, string|array $fields, null|string|array $params, ?array $vars=null, bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null) : int|bool|null {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_UPDATE",...func_get_args())) return $early_return;
		$original_location=$location;
		$original_fields=$fields;
		$original_params=$params;
		$original_vars=$vars;
		$original_clear_cache=$clear_cache;
		$trace_started_at=microtime(true);
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
		if(self::readonly_replay_enabled()===true){
			self::readonly_replay_block('update', $location, self::trace_payload('update', $location, [
				'fields'=>$fields,
				'params'=>$params,
				'vars'=>$vars ?? [],
				'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
				'cluster'=>$dbms_cluster,
				'dbms'=>$dbms,
			]));
			return $callback ? null : 0;
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
						'context'=>self::trace_payload('update', $location, [
							'fields'=>$fields,
							'params'=>$params,
							'vars'=>$vars ?? [],
						]),
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
						'context'=>self::trace_payload('update', $location, [
							'fields'=>$fields,
							'params'=>$params,
							'vars'=>$vars ?? [],
						]),
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
						'context'=>self::trace_payload('update', $location, [
							'fields'=>$fields,
							'params'=>$params,
							'vars'=>$vars ?? [],
						]),
					]);
					return null;
				}
				$query_result=sqlite_query_builder::sqlite_update($dbms_cluster, $location, $fields, $params, $vars);
				break;
		}
		if($query_result===false){
			$retried=self::retry_operation_after_structure_hydration('update', $location, fn()=>self::update($original_location, $original_fields, $original_params, $original_vars, $original_clear_cache, $queue, $callback));
			if($retried!==false){
				return $retried;
			}
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
			'context'=>self::trace_payload('update', $location, [
				'fields'=>$fields,
				'params'=>$params,
				'vars'=>$vars ?? [],
				'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
				'result_count'=>self::trace_result_count($query_result),
			]),
		]);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Update query finished, returning result");
		return $query_result;
	}

	/**
	 * Builds and executes a DELETE operation with safe-delete protection and cache invalidation.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $location Table location or logical table key resolved by table().
	 * @param array|string|null $params WHERE clause, parameter array, or action params depending on helper context.
	 * @param ?array $vars Bound parameter values used by prepared SQL execution.
	 * @param bool|null|array $clear_cache Cache invalidation flag or namespace list for write operations.
	 * @param ?string $queue Queue name; end queues for shutdown execution while null executes immediately.
	 * @param ?callable $callback Optional callback invoked with the query result or queued operation result.
	 * @return int|bool|null Affected-row count, true/null queue status, or false when execution fails.
	 */
	public static function delete(string $location, array|string|null $params=null, ?array $vars=null, bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null) : int|bool|null {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SQL_DB_DELETE",...func_get_args())) return $early_return;
		$original_location=$location;
		$original_params=$params;
		$original_vars=$vars;
		$original_clear_cache=$clear_cache;
		$trace_started_at=microtime(true);
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
		if(self::readonly_replay_enabled()===true){
			self::readonly_replay_block('delete', $location, self::trace_payload('delete', $location, [
				'params'=>$params,
				'vars'=>$vars ?? [],
				'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
				'cluster'=>$dbms_cluster,
				'dbms'=>$dbms,
			]));
			return $callback ? null : 0;
		}
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
						'context'=>self::trace_payload('delete', $location, [
							'params'=>$params,
							'vars'=>$vars ?? [],
						]),
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
						'context'=>self::trace_payload('delete', $location, [
							'params'=>$params,
							'vars'=>$vars ?? [],
						]),
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
						'context'=>self::trace_payload('delete', $location, [
							'params'=>$params,
							'vars'=>$vars ?? [],
						]),
					]);
					return null;
				}
				$query_result=sqlite_query_builder::sqlite_delete($dbms_cluster, $location, $params, $vars);
				break;
		}
		if($query_result===false){
			$retried=self::retry_operation_after_structure_hydration('delete', $location, fn()=>self::delete($original_location, $original_params, $original_vars, $original_clear_cache, $queue, $callback));
			if($retried!==false){
				return $retried;
			}
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
			'context'=>self::trace_payload('delete', $location, [
				'params'=>$params,
				'vars'=>$vars ?? [],
				'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
				'result_count'=>self::trace_result_count($query_result),
			]),
		]);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Delete query finished, returning result");
		return $query_result;
	}
	
	/**
	 * Executes insert-or-update behavior using table metadata, update criteria, and cache invalidation.
	 *
	 * Prepared values remain separate from SQL text, write operations can invalidate caches, missing schema can hydrate from definitions, and failures are recorded through last_query_error().
	 *
	 * @param string $location Table location or logical table key resolved by table().
	 * @param array<string,mixed> $fields INSERT/UPDATE field map or DBMS-specific SQL field fragment.
	 * @param string|array|null $update_params WHERE clause or key map used to decide whether an upsert updates an existing row.
	 * @param ?array $update_vars Bound values for the upsert update condition.
	 * @param bool|null|array $clear_cache Cache invalidation flag or namespace list for write operations.
	 * @param ?string $queue Queue name; end queues for shutdown execution while null executes immediately.
	 * @param ?callable $callback Optional callback invoked with the query result or queued operation result.
	 * @return int|bool|null Affected-row count, true/null queue status, or false when execution fails.
	 */
	public static function upsert(string $location, array $fields, string|array|null $update_params=null, ?array $update_vars=null, bool|null|array $clear_cache=false, ?string $queue='end', ?callable $callback=null): int|bool|null {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args());
		if(null !== $early_return=core::dialback("CALL_SQL_DB_UPSERT", ...func_get_args())) return $early_return;
		$original_location=$location;
		$original_fields=$fields;
		$original_update_params=$update_params;
		$original_update_vars=$update_vars;
		$original_clear_cache=$clear_cache;
		$trace_started_at=microtime(true);
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
		if(self::readonly_replay_enabled()===true){
			self::readonly_replay_block('upsert', $location, self::trace_payload('upsert', $location, [
				'fields'=>$fields,
				'params'=>$update_params,
				'vars'=>$update_vars ?? [],
				'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
				'cluster'=>$dbms_cluster,
				'dbms'=>$dbms,
			]));
			return $callback ? null : false;
		}
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
							'context'=>self::trace_payload('upsert', $location, [
								'query'=>$sql,
								'vars'=>$vars,
							]),
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
							'context'=>self::trace_payload('upsert', $location, [
								'query'=>$sql,
								'vars'=>$vars,
							]),
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
							'context'=>self::trace_payload('upsert', $location, [
								'query'=>$sql,
								'vars'=>$vars,
							]),
						]);
						return null;
					}
					$query_result=sqlite_query_builder::sqlite_query($dbms_cluster, $sql, $vars, false, false);
					break;
			}
			if($query_result!==false && $clear_cache!==false){
				self::invalidate_cache($clear_cache===true ? $location : $clear_cache);
			}
			if($query_result===false){
				$retried=self::retry_operation_after_structure_hydration('upsert', $location, fn()=>self::upsert($original_location, $original_fields, $original_update_params, $original_update_vars, $original_clear_cache, $queue, $callback));
				if($retried!==false){
					return $retried;
				}
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
				'context'=>self::trace_payload('upsert', $location, [
					'query'=>$sql ?? '',
					'vars'=>$vars ?? [],
					'duration_ms'=>self::trace_elapsed_ms($trace_started_at),
					'result_count'=>self::trace_result_count($query_result),
				]),
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

if(!empty($GLOBALS['dataphyre_flightdeck_debugbar_active']) && class_exists('dataphyre_flightdeck_debugbar', false)){
	\dataphyre_flightdeck_debugbar::attach_sql_observer();
}
