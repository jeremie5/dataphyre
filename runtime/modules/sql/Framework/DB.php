<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

final class DB {

	/** @var list<callable(ExecutionTrace): void> */
	private static array $trace_observers=[];
	/** @var list<callable(ExecutionTrace): void> */
	private static array $internal_trace_observers=[];

	/** @var list<ExecutionTrace> */
	private static array $trace_buffer=[];
	private static array $trace_context_stack=[];

	private static int $trace_buffer_limit=256;
	private static bool $kernel_observer_registered=false;
	private static bool $templating_cache_bridge_registered=false;
	private static bool $api_cache_bridge_registered=false;
	private static ?bool $guardrails_enabled=null;

	public static function defaultReadCaching(): array {
		static::bootRuntimeBridges();
		return [true];
	}

	public static function cacheNames(string ...$names): array {
		$normalized=[true];
		foreach($names as $name){
			$name=trim($name);
			if($name===''){
				continue;
			}
			$normalized[]=$name;
		}
		return array_values(array_unique($normalized, SORT_REGULAR));
	}

	public static function invalidationNames(string ...$names): array {
		$normalized=[];
		foreach($names as $name){
			$name=trim($name);
			if($name===''){
				continue;
			}
			$normalized[]=$name;
		}
		return array_values(array_unique($normalized, SORT_REGULAR));
	}

	public static function mergeCacheNames(bool|array|string|null $caching=null, string ...$names): bool|array|string|null {
		static::bootRuntimeBridges();
		if($caching===false){
			return false;
		}
		$merged=[];
		if(is_array($caching)){
			$merged=$caching;
		}elseif($caching!==null){
			$merged=[$caching];
		}else{
			$merged=static::defaultReadCaching();
		}
		foreach($names as $name){
			$name=trim($name);
			if($name===''){
				continue;
			}
			$merged[]=$name;
		}
		if($merged===[]){
			return static::defaultReadCaching();
		}
		return array_values(array_unique($merged, SORT_REGULAR));
	}

	public static function mergeInvalidationNames(bool|array|null $clear_cache=null, string ...$names): bool|array|null {
		static::bootRuntimeBridges();
		$merged=[];
		if(is_array($clear_cache)){
			$merged=$clear_cache;
		}
		foreach($names as $name){
			$name=trim($name);
			if($name===''){
				continue;
			}
			$merged[]=$name;
		}
		$merged=static::invalidationNames(...$merged);
		if($merged===[]){
			return $clear_cache===true ? true : false;
		}
		return $merged;
	}

	public static function connection(?string $cluster=null): ConnectionContext {
		static::bootRuntimeBridges();
		return new ConnectionContext(static::normalizeCluster($cluster));
	}

	public static function table(string|TableSchema $table, ?string $primary_key=null): TableQuery {
		static::bootRuntimeBridges();
		return new TableQuery($table, $primary_key);
	}

	public static function cluster(?string $cluster=null): ConnectionContext {
		return static::connection($cluster);
	}

	public static function defaultCluster(): ?string {
		$config=static::sqlConfig();
		$cluster=trim((string)($config['default_cluster'] ?? ''));
		return $cluster!=='' ? $cluster : null;
	}

	public static function clusters(): array {
		$config=static::sqlConfig();
		$datacenter=static::currentDatacenter();
		$clusters=$config['datacenters'][$datacenter]['dbms_clusters'] ?? [];
		if(!is_array($clusters)){
			return [];
		}
		return array_values(array_filter(array_map(
			static fn(mixed $name): string => trim((string)$name),
			array_keys($clusters)
		), static fn(string $name): bool => $name!==''));
	}

	public static function hasCluster(string $cluster): bool {
		$cluster=trim($cluster);
		if($cluster===''){
			return false;
		}
		return in_array($cluster, static::clusters(), true);
	}

	public static function datacenter(): ?string {
		$datacenter=static::currentDatacenter();
		return $datacenter!=='' ? $datacenter : null;
	}

	public static function clusterDbms(?string $cluster=null): ?string {
		$config=static::sqlConfig();
		$datacenter=static::currentDatacenter();
		$cluster=static::normalizeCluster($cluster) ?? static::defaultCluster();
		if($cluster===null){
			return null;
		}
		$dbms=trim((string)($config['datacenters'][$datacenter]['dbms_clusters'][$cluster]['dbms'] ?? ''));
		return $dbms!=='' ? $dbms : null;
	}

	public static function begin(?string $cluster=null): Transaction {
		return new Transaction(static::normalizeCluster($cluster))->begin();
	}

	public static function transaction(callable $callback, ?string $cluster=null): mixed {
		return new Transaction(static::normalizeCluster($cluster))->run($callback);
	}

	public static function attemptTransaction(callable $callback, ?string $cluster=null): TransactionResult {
		return new Transaction(static::normalizeCluster($cluster))->attempt($callback);
	}

	public static function commit(Transaction $transaction): Transaction {
		return $transaction->commit();
	}

	public static function rollback(Transaction $transaction): Transaction {
		return $transaction->rollback();
	}

	public static function query(
		string|array $query,
		?array $vars=null,
		?bool $associative=false,
		?bool $multipoint=false,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): mixed {
		return static::connection()->query($query, $vars, $associative, $multipoint, $caching, $clear_cache);
	}

	public static function value(
		string|array $query,
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): mixed {
		return static::connection()->value($query, $vars, $caching, $clear_cache);
	}

	public static function row(
		string|array $query,
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): ?array {
		return static::connection()->row($query, $vars, $caching, $clear_cache);
	}

	public static function rows(
		string|array $query,
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): array {
		return static::connection()->rows($query, $vars, $caching, $clear_cache);
	}

	public static function queueQuery(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		?bool $associative=false,
		?bool $multipoint=false,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): null|bool {
		return static::connection()->queueQuery($query, $callback, $queue, $vars, $associative, $multipoint, $caching, $clear_cache);
	}

	public static function queueValue(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): null|bool {
		return static::connection()->queueValue($query, $callback, $queue, $vars, $caching, $clear_cache);
	}

	public static function queueRow(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): null|bool {
		return static::connection()->queueRow($query, $callback, $queue, $vars, $caching, $clear_cache);
	}

	public static function queueRows(
		string|array $query,
		callable $callback,
		string $queue='end',
		?array $vars=null,
		null|bool|array|string $caching=false,
		bool|null|array $clear_cache=false
	): null|bool {
		return static::connection()->queueRows($query, $callback, $queue, $vars, $caching, $clear_cache);
	}

	public static function executeQueue(string $queue='end'): null|bool {
		return \dataphyre\sql::execute_queue($queue);
	}

	public static function invalidateCache(array|string $clear_cache_for): bool {
		static::bootRuntimeBridges();
		return \dataphyre\sql::invalidate_cache($clear_cache_for);
	}

	public static function observe(callable $observer): void {
		if(static::tracingEnabled()!==true){
			return;
		}
		static::bootObservability();
		static::bootTemplatingCacheBridge();
		static::bootApiCacheBridge();
		self::$trace_observers[]=$observer;
	}

	public static function clearObservers(): void {
		self::$trace_observers=[];
		self::$internal_trace_observers=[];
		self::$templating_cache_bridge_registered=false;
		self::$api_cache_bridge_registered=false;
	}

	public static function withTraceContext(array $context, callable $callback): mixed {
		if(static::tracingEnabled()!==true){
			return $callback();
		}
		$context=static::normalizeTraceContext($context);
		if($context===[]){
			return $callback();
		}
		self::$trace_context_stack[]=$context;
		try{
			return $callback();
		} finally {
			array_pop(self::$trace_context_stack);
		}
	}

	public static function currentTraceContext(): array {
		if(static::tracingEnabled()!==true){
			return [];
		}
		$merged=[];
		foreach(self::$trace_context_stack as $context){
			$merged=array_replace($merged, $context);
		}
		return $merged;
	}

	public static function lastTrace(): ?ExecutionTrace {
		if(static::tracingEnabled()!==true){
			return null;
		}
		static::bootObservability();
		if(self::$trace_buffer===[]){
			return null;
		}
		return self::$trace_buffer[array_key_last(self::$trace_buffer)] ?? null;
	}

	/** @return list<ExecutionTrace> */
	public static function recentTraces(int $limit=50): array {
		if(static::tracingEnabled()!==true){
			return [];
		}
		static::bootObservability();
		$limit=max(1, $limit);
		return array_slice(self::$trace_buffer, -$limit);
	}

	/** @return list<ExecutionTrace> */
	public static function recentTracesByContext(array $context, int $limit=50): array {
		if(static::tracingEnabled()!==true){
			return [];
		}
		static::bootObservability();
		$context=static::normalizeTraceContext($context);
		if($context===[]){
			return static::recentTraces($limit);
		}
		$limit=max(1, $limit);
		$matched=[];
		for($index=count(self::$trace_buffer)-1; $index>=0; $index--){
			$trace=self::$trace_buffer[$index] ?? null;
			if(!$trace instanceof ExecutionTrace){
				continue;
			}
			if(!static::traceMatchesContext($trace, $context)){
				continue;
			}
			$matched[]=$trace;
			if(count($matched)>=$limit){
				break;
			}
		}
		return array_reverse($matched);
	}

	public static function clearTraceBuffer(): void {
		self::$trace_buffer=[];
	}

	public static function setTraceBufferLimit(int $limit=256): void {
		self::$trace_buffer_limit=max(1, $limit);
		if(count(self::$trace_buffer)>self::$trace_buffer_limit){
			self::$trace_buffer=array_slice(self::$trace_buffer, -self::$trace_buffer_limit);
		}
	}

	public static function enableGuardrails(bool $enabled=true): void {
		self::$guardrails_enabled=$enabled;
		if($enabled){
			static::bootObservability();
		}
	}

	public static function disableGuardrails(): void {
		self::$guardrails_enabled=false;
	}

	public static function guardrailsEnabled(): bool {
		if(self::$guardrails_enabled!==null){
			return self::$guardrails_enabled;
		}
		return defined('RUN_MODE') && RUN_MODE==='diagnostic';
	}

	public static function reportGuardrailWarning(string $message, array $context=[]): void {
		if(static::guardrailsEnabled()===false){
			return;
		}
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Dataphyre SQL guardrail: '.$message, $S='warning');
		static::recordTrace([
			'source'=>'framework',
			'event'=>'guardrail_warning',
			'operation'=>$context['operation'] ?? null,
			'message'=>$message,
			'context'=>$context,
		]);
	}

	public static function recordKernelTrace(array $event): void {
		static::recordTrace($event+['source'=>'kernel']);
	}

	private static function sqlConfig(): array {
		return is_array(DP_SQL_CFG ?? null)
			? DP_SQL_CFG
			: [];
	}

	private static function currentDatacenter(): string {
		return trim((string)(DP_CORE_CFG['datacenter'] ?? ''));
	}

	private static function normalizeCluster(?string $cluster): ?string {
		if($cluster===null){
			return null;
		}
		$cluster=trim($cluster);
		return $cluster!=='' ? $cluster : null;
	}

	private static function bootObservability(): void {
		if(self::$kernel_observer_registered){
			return;
		}
		\dataphyre\sql::add_observer([static::class, 'recordKernelTrace']);
		self::$kernel_observer_registered=true;
	}

	public static function bootRuntimeBridges(): void {
		if(
			!class_exists('Dataphyre\\Templating\\Templating', false)
			&& !class_exists('Dataphyre\\Api\\Api', false)
		){
			return;
		}
		static::bootObservability();
		static::bootTemplatingCacheBridge();
		static::bootApiCacheBridge();
	}

	private static function recordTrace(array $payload): void {
		$tracing_enabled=static::tracingEnabled()===true;
		if($tracing_enabled!==true && self::$internal_trace_observers===[]){
			return;
		}
		$payload['context']=array_replace(
			static::currentTraceContext(),
			is_array($payload['context'] ?? null) ? $payload['context'] : []
		);
		$trace=ExecutionTrace::fromArray($payload);
		foreach(self::$internal_trace_observers as $observer){
			try{
				$observer($trace);
			}catch(\Throwable $exception){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Dataphyre SQL trace observer failed: '.$exception->getMessage(), $S='warning');
			}
		}
		if($tracing_enabled!==true){
			return;
		}
		self::$trace_buffer[]=$trace;
		if(count(self::$trace_buffer)>self::$trace_buffer_limit){
			array_shift(self::$trace_buffer);
		}
		foreach(self::$trace_observers as $observer){
			try{
				$observer($trace);
			}catch(\Throwable $exception){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Dataphyre SQL trace observer failed: '.$exception->getMessage(), $S='warning');
			}
		}
	}

	private static function bootTemplatingCacheBridge(): void {
		if(self::$templating_cache_bridge_registered){
			return;
		}
		if(!class_exists('Dataphyre\\Templating\\Templating', false)){
			return;
		}
		self::$internal_trace_observers[]=[static::class, 'syncTemplatingBindingCaches'];
		self::$templating_cache_bridge_registered=true;
	}

	private static function syncTemplatingBindingCaches(ExecutionTrace $trace): void {
		if($trace->event()!=='cache_invalidate'){
			return;
		}
		$names=$trace->invalidationNames();
		if($names===[]){
			return;
		}
		try{
			\Dataphyre\Templating\Templating::clearBindingCache(...$names);
		}catch(\Throwable $exception){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Dataphyre templating cache bridge failed: '.$exception->getMessage(), $S='warning');
		}
	}

	private static function bootApiCacheBridge(): void {
		if(self::$api_cache_bridge_registered){
			return;
		}
		if(!class_exists('Dataphyre\\Api\\Api', false)){
			return;
		}
		self::$internal_trace_observers[]=[static::class, 'syncApiEndpointCaches'];
		self::$api_cache_bridge_registered=true;
	}

	private static function tracingEnabled(): bool {
		if(class_exists('Dataphyre\\Runtime', false) && method_exists('Dataphyre\\Runtime', 'tracingEnabled')){
			return \Dataphyre\Runtime::tracingEnabled();
		}
		return !(defined('IS_PRODUCTION') && IS_PRODUCTION===true);
	}

	private static function syncApiEndpointCaches(ExecutionTrace $trace): void {
		if($trace->event()!=='cache_invalidate'){
			return;
		}
		$names=$trace->invalidationNames();
		if($names===[]){
			return;
		}
		try{
			\Dataphyre\Api\Api::clearEndpointCache(...$names);
		}catch(\Throwable $exception){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Dataphyre API cache bridge failed: '.$exception->getMessage(), $S='warning');
		}
	}

	private static function normalizeTraceContext(array $context): array {
		$normalized=[];
		foreach($context as $key=>$value){
			$key=trim((string)$key);
			if($key===''){
				continue;
			}
			if(is_scalar($value) || $value===null){
				$normalized[$key]=$value;
				continue;
			}
			if(is_object($value) && method_exists($value, '__toString')){
				$normalized[$key]=(string)$value;
			}
		}
		return $normalized;
	}

	private static function traceMatchesContext(ExecutionTrace $trace, array $context): bool {
		$trace_context=$trace->context();
		foreach($context as $key=>$value){
			if(!array_key_exists($key, $trace_context) || $trace_context[$key]!==$value){
				return false;
			}
		}
		return true;
	}
}
