<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

if(function_exists(__NAMESPACE__.'\\tracelog') || function_exists('tracelog')){
	tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, 'Module initialization');
}
if(function_exists(__NAMESPACE__.'\\dp_define_module_config')){
	dp_define_module_config('cache', 'DP_CACHE_CFG');
}

/**
 * Provides Dataphyre's optional cache facade.
 *
 * A healthy Memcached service is preferred for shared cache state. If the PHP
 * extension is unavailable, the service fails its health check, or an active
 * backend operation fails, the facade degrades to request-local memory. Cache
 * availability must never make an otherwise healthy application unavailable.
 */
class cache {

	private const MAX_RELATIVE_EXPIRATION=2592000;
	private const MAX_MEMCACHED_KEY_BYTES=250;
	private const DEFAULT_MEMCACHED_HOST='127.0.0.1';
	private const DEFAULT_MEMCACHED_PORT=11211;

	/** @var object|null Active Memcached-compatible client. */
	protected static $memcached=null;

	/** @var array<string, array{value:mixed, expires:int}> */
	protected static array $memory_cache=[];

	/** Whether this request has degraded to the in-memory backend. */
	protected static bool $memory_fallback=false;

	/** Public compatibility flag used by legacy runtime consumers. */
	public static $started=false;

	/** Selects and health-checks the cache backend once per request. */
	private static function start(): void {
		if(self::$started===true){
			return;
		}
		if(!class_exists('\\Memcached', false)){
			self::use_memory_fallback('PHP Memcached extension is unavailable');
			return;
		}
		try{
			$client=new \Memcached();
			self::configure_client($client);
			[$host, $port]=self::server_address();
			if($client->addServer($host, $port)!==true){
				self::use_memory_fallback('Memcached server registration failed');
				return;
			}
			$versions=$client->getVersion();
			if(!self::versions_are_healthy($versions)){
				self::use_memory_fallback('Memcached health check failed');
				return;
			}
			self::$memcached=$client;
			self::$memory_fallback=false;
			self::$started=true;
		}catch(\Throwable){
			self::use_memory_fallback('Memcached initialization failed');
		}
	}

	/**
	 * Resolves the process-shared endpoint without placing environment values in
	 * traces or generated configuration.
	 *
	 * @return array{0:string,1:int}
	 */
	private static function server_address(): array {
		$config=defined('DP_CACHE_CFG') && is_array(DP_CACHE_CFG)
			? (is_array(DP_CACHE_CFG['memcached'] ?? null) ? DP_CACHE_CFG['memcached'] : DP_CACHE_CFG)
			: [];
		$host=self::environment_value('DATAPHYRE_CACHE_MEMCACHED_HOST')
			?? self::environment_value('MEMCACHED_HOST')
			?? (is_string($config['host'] ?? null) ? trim($config['host']) : '');
		if($host===''){
			$host=self::DEFAULT_MEMCACHED_HOST;
		}
		$port=self::environment_value('DATAPHYRE_CACHE_MEMCACHED_PORT')
			?? self::environment_value('MEMCACHED_PORT')
			?? ($config['port'] ?? self::DEFAULT_MEMCACHED_PORT);
		if(strlen($host)>255 || preg_match('/[\\x00-\\x20\\x7f]/', $host)===1){
			self::trace_warning('Invalid Memcached host configuration; using the loopback default.');
			$host=self::DEFAULT_MEMCACHED_HOST;
		}
		$port=filter_var($port, FILTER_VALIDATE_INT, [
			'options'=>['min_range'=>1, 'max_range'=>65535],
		]);
		if($port===false){
			self::trace_warning('Invalid Memcached port configuration; using the default port.');
			$port=self::DEFAULT_MEMCACHED_PORT;
		}
		return [$host, (int)$port];
	}

	private static function environment_value(string $name): ?string {
		$value=getenv($name);
		if(!is_string($value) || trim($value)===''){
			return null;
		}
		return trim($value);
	}

	/** Applies bounded connection settings when the extension exposes them. */
	private static function configure_client(object $client): void {
		foreach([
			'Memcached::OPT_CONNECT_TIMEOUT'=>250,
			'Memcached::OPT_RETRY_TIMEOUT'=>1,
		] as $constant=>$value){
			if(defined($constant)){
				$client->setOption(constant($constant), $value);
			}
		}
	}

	/** @param mixed $versions Memcached version map returned by the extension. */
	private static function versions_are_healthy(mixed $versions): bool {
		if(!is_array($versions) || $versions===[]){
			return false;
		}
		foreach($versions as $version){
			if(!is_string($version) || version_compare($version, '1.4.0', '<')){
				return false;
			}
		}
		return true;
	}

	/** Permanently selects request-local memory for the remainder of this request. */
	private static function use_memory_fallback(string $reason): void {
		self::$memcached=null;
		self::$memory_fallback=true;
		self::$started=true;
		self::trace_warning($reason.'; using request-local memory cache.');
	}

	private static function trace_warning(string $message): void {
		if(function_exists(__NAMESPACE__.'\\tracelog') || function_exists('tracelog')){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $message, 'warning');
		}
	}

	private static function key(string|int $key): string {
		$key=(string)$key;
		if(
			$key!==''
			&& strlen($key)<=self::MAX_MEMCACHED_KEY_BYTES
			&& preg_match('/[\\x00-\\x20\\x7f]/', $key)!==1
		){
			return $key;
		}
		return 'dataphyre:sha256:'.hash('sha256', $key);
	}

	/**
	 * Mirrors Memcached expiration semantics: up to 30 days is relative, while
	 * larger values are absolute Unix timestamps.
	 */
	private static function expires_at(int $expiration): int {
		if($expiration<=0){
			return 0;
		}
		if($expiration>self::MAX_RELATIVE_EXPIRATION){
			return $expiration;
		}
		return time()+$expiration;
	}

	private static function memory_get(string $key): mixed {
		if(!array_key_exists($key, self::$memory_cache)){
			return null;
		}
		$entry=self::$memory_cache[$key];
		if(($entry['expires'] ?? 0)>0 && ($entry['expires'] ?? 0)<=time()){
			unset(self::$memory_cache[$key]);
			return null;
		}
		return $entry['value'] ?? null;
	}

	private static function memory_set(string $key, mixed $value, int $expiration=0): bool {
		self::$memory_cache[$key]=[
			'value'=>$value,
			'expires'=>self::expires_at($expiration),
		];
		return true;
	}

	private static function memory_counter(string $key, int $offset, bool $decrement): int {
		$current=self::memory_get($key);
		$expires=self::$memory_cache[$key]['expires'] ?? 0;
		$value=is_numeric($current) ? (int)$current : 0;
		$value=$decrement ? max(0, $value-$offset) : $value+$offset;
		self::$memory_cache[$key]=[
			'value'=>$value,
			'expires'=>$expires,
		];
		return $value;
	}

	private static function result_code(): ?int {
		try{
			if(is_object(self::$memcached) && method_exists(self::$memcached, 'getResultCode')){
				return (int)self::$memcached->getResultCode();
			}
		}catch(\Throwable){
		}
		return null;
	}

	private static function result_is(string $constant, int $fallback): bool {
		$expected=defined($constant) ? (int)constant($constant) : $fallback;
		return self::result_code()===$expected;
	}

	private static function result_is_success(): bool {
		return self::result_is('Memcached::RES_SUCCESS', 0);
	}

	private static function result_is_not_found(): bool {
		return self::result_is('Memcached::RES_NOTFOUND', 16);
	}

	private static function result_is_data_exists(): bool {
		return self::result_is('Memcached::RES_DATA_EXISTS', 12);
	}

	private static function result_is_not_stored(): bool {
		return self::result_is('Memcached::RES_NOTSTORED', 14);
	}

	/**
	 * Health-checks the selected backend and reports whether it is process-shared.
	 *
	 * This must be true before a caller treats this facade as a shared cache. The
	 * fail-open memory backend intentionally returns false.
	 */
	public static function isShared(): bool {
		if(self::$started===false){
			self::start();
		}
		return self::$memory_fallback===false && is_object(self::$memcached);
	}

	/** Fetches a cached value, returning null for a miss. */
	public static function get(string|int $key): mixed {
		if(self::$started===false){
			self::start();
		}
		$key=self::key($key);
		if(self::$memory_fallback){
			return self::memory_get($key);
		}
		try{
			$value=self::$memcached->get($key);
			if($value!==false || self::result_is_success()){
				return $value;
			}
			if(self::result_is_not_found()){
				return null;
			}
		}catch(\Throwable){
		}
		self::use_memory_fallback('Memcached read failed');
		return self::memory_get($key);
	}

	/** Stores a value for an optional Memcached-compatible expiration. */
	public static function set(string|int $key, mixed $value, int $expiration=0): bool {
		if(self::$started===false){
			self::start();
		}
		$key=self::key($key);
		if(self::$memory_fallback){
			return self::memory_set($key, $value, $expiration);
		}
		try{
			if(self::$memcached->set($key, $value, $expiration)===true){
				return true;
			}
		}catch(\Throwable){
		}
		self::use_memory_fallback('Memcached write failed');
		return self::memory_set($key, $value, $expiration);
	}

	/** Deletes a cache key. Missing keys are treated as successfully absent. */
	public static function delete(string|int $key): bool {
		if(self::$started===false){
			self::start();
		}
		$key=self::key($key);
		if(self::$memory_fallback){
			unset(self::$memory_cache[$key]);
			return true;
		}
		try{
			if(self::$memcached->delete($key)===true || self::result_is_not_found()){
				return true;
			}
		}catch(\Throwable){
		}
		self::use_memory_fallback('Memcached delete failed');
		unset(self::$memory_cache[$key]);
		return true;
	}

	/** Clears the selected backend. */
	public static function flush(): bool {
		if(self::$started===false){
			self::start();
		}
		if(self::$memory_fallback){
			self::$memory_cache=[];
			return true;
		}
		try{
			if(self::$memcached->flush()===true){
				return true;
			}
		}catch(\Throwable){
		}
		self::use_memory_fallback('Memcached flush failed');
		self::$memory_cache=[];
		return true;
	}

	/** Increments a numeric value, creating a missing counter from zero. */
	public static function increment(string|int $key, int $offset=1): int|false {
		return self::counter($key, $offset, false);
	}

	/**
	 * Atomically increments a counter only when a process-shared backend is active.
	 *
	 * Unlike increment(), this security-policy primitive never degrades the
	 * operation to request-local memory. A missing counter is created at the
	 * requested offset with the supplied expiration; concurrent creators use
	 * Memcached add semantics so every successful caller contributes exactly one
	 * increment and the first writer establishes the lifetime.
	 *
	 * @param string|int $key Counter key.
	 * @param int $offset Non-negative amount to add.
	 * @param int $expiration Memcached-compatible expiration for a newly created counter.
	 * @return int|false Updated shared count, or false when shared state is unavailable.
	 */
	public static function incrementShared(string|int $key, int $offset=1, int $expiration=0): int|false {
		if(self::$started===false){
			self::start();
		}
		if(self::$memory_fallback || !is_object(self::$memcached)){
			return false;
		}
		$key=self::key($key);
		$offset=max(0, $offset);
		$expiration=max(0, $expiration);
		try{
			$result=self::$memcached->increment($key, $offset);
			if($result!==false){
				return (int)$result;
			}
			if(self::result_is_not_found()){
				if(self::$memcached->add($key, $offset, $expiration)===true){
					return $offset;
				}
				if(self::result_is_data_exists() || self::result_is_not_stored()){
					$result=self::$memcached->increment($key, $offset);
					if($result!==false){
						return (int)$result;
					}
				}
			}
		}catch(\Throwable){
		}
		self::use_memory_fallback('Memcached shared counter operation failed');
		return false;
	}

	/** Decrements a numeric value without allowing it to fall below zero. */
	public static function decrement(string|int $key, int $offset=1): int|false {
		return self::counter($key, $offset, true);
	}

	private static function counter(string|int $key, int $offset, bool $decrement): int|false {
		if(self::$started===false){
			self::start();
		}
		$key=self::key($key);
		$offset=max(0, $offset);
		if(self::$memory_fallback){
			return self::memory_counter($key, $offset, $decrement);
		}
		try{
			$result=$decrement
				? self::$memcached->decrement($key, $offset)
				: self::$memcached->increment($key, $offset);
			if($result!==false){
				return (int)$result;
			}
			if(self::result_is_not_found()){
				$added=self::$memcached->add($key, 0);
				if($added===true || self::result_is_data_exists()){
					$result=$decrement
						? self::$memcached->decrement($key, $offset)
						: self::$memcached->increment($key, $offset);
					if($result!==false){
						return (int)$result;
					}
				}
			}
		}catch(\Throwable){
		}
		self::use_memory_fallback('Memcached counter operation failed');
		return self::memory_counter($key, $offset, $decrement);
	}
}
