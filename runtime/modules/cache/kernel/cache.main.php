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

/**
 * Provides Dataphyre's optional process-local cache facade.
 *
 * A healthy Memcached service is preferred for shared cache state. If the PHP
 * extension is unavailable, the service fails its health check, or an active
 * backend operation fails, the facade degrades to request-local memory. Cache
 * availability must never make an otherwise healthy application unavailable.
 */
class cache {

	private const MAX_RELATIVE_EXPIRATION=2592000;

	/** @var object|null Active Memcached-compatible client. */
	protected static $memcached=null;

	/** @var array<string, array{value:mixed, expires:int}> */
	protected static array $memory_cache=[];

	/** Whether this request has degraded to the in-memory backend. */
	protected static bool $memory_fallback=false;

	/** Public compatibility flag used by legacy runtime consumers. */
	public static $started=false;

	/**
	 * Selects and health-checks the cache backend once per request.
	 */
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
			if($client->addServer('127.0.0.1', 11211)!==true){
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
	 * Applies bounded connection settings when the extension exposes them.
	 */
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

	/**
	 * @param mixed $versions Memcached version map returned by the extension.
	 */
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

	/**
	 * Permanently selects request-local memory for the remainder of this request.
	 */
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
		return (string)$key;
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

	/**
	 * Fetches a cached value, returning null for a miss.
	 */
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

	/**
	 * Stores a value for an optional Memcached-compatible expiration.
	 */
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

	/**
	 * Deletes a cache key. Missing keys are treated as successfully absent.
	 */
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

	/**
	 * Clears the selected backend.
	 */
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

	/**
	 * Increments a numeric value, creating a missing counter from zero.
	 */
	public static function increment(string|int $key, int $offset=1): int|false {
		return self::counter($key, $offset, false);
	}

	/**
	 * Decrements a numeric value without allowing it to fall below zero.
	 */
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
