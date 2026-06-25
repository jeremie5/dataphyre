<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

/**
 * Provides Dataphyre's process-wide key/value cache facade.
 *
 * The cache kernel prefers the PHP Memcached extension connected to localhost, serializing all
 * values before transport so callers can store arbitrary PHP payloads. Development and
 * non-production runtimes without the extension fall back to a request-local in-memory store;
 * production treats a missing or unusable Memcached service as an unavailable dependency.
 *
 * @internal Kernel facade around Memcached; keys are shared across all callers in the process.
 */
class cache{
	
	/**
	 * Active Memcached client after lazy initialization.
	 *
	 * @var \Memcached|null
	 */
    protected static $memcached;

	/**
	 * Request-local fallback entries keyed by cache key.
	 *
	 * @var array<string, array{value:mixed, expires:int}>
	 */
	protected static array $memory_cache=[];

	/**
	 * Whether reads and writes should use the request-local fallback store.
	 */
	protected static bool $memory_fallback=false;

	/**
	 * Whether the backend selection and connection checks have completed.
	 */
    public static $started=false;
	
	/**
	 * Lazily selects the cache backend and verifies Memcached health.
	 *
	 * Startup is one-way for the lifetime of the request: once Memcached is confirmed or the
	 * memory fallback is selected, subsequent cache operations reuse that decision. Version
	 * checks guard against unsupported Memcached servers before application code relies on them.
	 *
	 * @return void
	 */
	private static function start(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(!class_exists('\Memcached')){
			if(defined('IS_PRODUCTION') && IS_PRODUCTION===true){
				core::unavailable(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $D='DataphyreCache: PHP Memcached extension is not installed.', 'safemode');
			}
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='PHP Memcached extension is not installed; using request-local memory cache fallback.', $S='warning');
			self::$memory_fallback=true;
			self::$started=true;
			return;
		}
		if(!is_object(self::$memcached)){
			self::$memcached=new \Memcached();
			if(false===self::$memcached->addServer('localhost', 11211)){
				core::unavailable(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $D='DataphyreCache: Failed initiating memcached connection to localhost on port 11211', 'safemode');
			}
			$versions=self::$memcached->getVersion();
			if(!is_array($versions) || empty($versions)){
				core::unavailable(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $D='DataphyreCache: Unable to retrieve Memcached version.', 'safemode');
			}
			if(is_array($versions)){
				foreach($versions as $server=>$version){
					if(!is_string($version) || version_compare($version, '1.4.0') < 0){
						core::unavailable(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $D="DataphyreCache: Memcached version($version) on server [$server] is too old.", 'safemode');
					}
				}
			}
		}
		self::$started=true;
	}
	
	/**
	 * Fetches and unserializes a cached value by key.
	 *
	 * Missing entries, expired request-local fallback entries, and Memcached failures are all
	 * exposed as `null` to callers, while backend errors are still written to tracelog for
	 * diagnostics. Values that were explicitly cached as `false` also read back as `null`
	 * because the Memcached API uses `false` as its failure sentinel.
	 *
	 * @param string|int $key Cache key accepted by Memcached and the fallback array store.
	 * @return mixed Cached payload, or `null` when no usable value exists.
	 */
	public static function get($key){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(self::$started===false)self::start();
		if(self::$memory_fallback){
			if(!array_key_exists($key, self::$memory_cache)){
				return null;
			}
			$entry=self::$memory_cache[$key];
			if(($entry['expires'] ?? 0)>0 && ($entry['expires'] ?? 0)<time()){
				unset(self::$memory_cache[$key]);
				return null;
			}
			return $entry['value'] ?? null;
		}
		if(false===$value=self::$memcached->get($key)){
			$result_code=self::$memcached->getResultCode();
			if($result_code===16){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Key not found");
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Operation failed: ".self::$memcached->getResultCode().' / '.self::$memcached->getResultMessage(), $S="warning");
			}
		}
		if($value===false)return null;
		return unserialize($value);
	}

    /**
     * Stores a value under a key for an optional time-to-live.
     *
     * Memcached-backed writes serialize the payload before storage. Fallback writes keep the raw
     * PHP value in memory and translate `$expiration` from seconds into an absolute request-local
     * expiry timestamp.
     *
     * @param string $key Cache key.
     * @param mixed $value Serializable payload to cache.
     * @param int $expiration Time-to-live in seconds, or `0` for backend default/no expiry.
     * @return bool Whether the backend accepted the write.
     */
    public static function set(string $key, mixed $value, int $expiration=0){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$started===false)self::start();
		if(self::$memory_fallback){
			self::$memory_cache[$key]=[
				'value'=>$value,
				'expires'=>$expiration>0 ? time()+$expiration : 0,
			];
			return true;
		}
		if(false===$result=self::$memcached->set($key, serialize($value), $expiration)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Operation failed: ".self::$memcached->getResultCode().' / '.self::$memcached->getResultMessage(), $S="warning");
		}
        return $result;
    }

    /**
     * Removes a cache entry from the active backend.
     *
     * The request-local fallback treats deleting a missing key as success. Memcached failures are
     * logged with the backend result code and surfaced to the caller as `false`.
     *
     * @param string|int $key Cache key to remove.
     * @return bool Whether the delete operation completed successfully.
     */
    public static function delete($key){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$started===false)self::start();
		if(self::$memory_fallback){
			unset(self::$memory_cache[$key]);
			return true;
		}
		if(false===$result=self::$memcached->delete($key)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Operation failed: ".self::$memcached->getResultCode().' / '.self::$memcached->getResultMessage(), $S="warning");
		}
        return $result;
    }

    /**
     * Clears every entry from the active cache backend.
     *
     * This is a global flush for Memcached and a full array reset for the fallback store, so
     * callers should reserve it for administrative invalidation rather than targeted cache
     * maintenance.
     *
     * @return bool Whether the backend accepted the flush.
     */
    public static function flush(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$started===false)self::start();
		if(self::$memory_fallback){
			self::$memory_cache=[];
			return true;
		}
		if(false===$result=self::$memcached->flush()){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Operation failed: ".self::$memcached->getResultCode().' / '.self::$memcached->getResultMessage(), $S="warning");
		}
        return $result;
    }

    /**
     * Atomically increments a numeric cache value when the backend supports it.
     *
     * Memcached performs the increment server-side. The fallback store emulates the operation by
     * reading the current value, casting it to an integer, adding the offset, and writing the new
     * value without a TTL.
     *
     * @param string|int $key Cache key containing an integer-compatible value.
     * @param int|numeric-string $offset Amount to add.
     * @return int|false New value on success, or `false` when Memcached rejects the operation.
     */
    public static function increment($key, $offset=1){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$started===false)self::start();
		if(self::$memory_fallback){
			$current=(int)(self::get($key) ?? 0);
			$value=$current+(int)$offset;
			self::set((string)$key, $value);
			return $value;
		}
		if(false===$result=self::$memcached->increment($key, $offset)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Operation failed: ".self::$memcached->getResultCode().' / '.self::$memcached->getResultMessage(), $S="warning");
		}
        return $result;
    }

    /**
     * Atomically decrements a numeric cache value when the backend supports it.
     *
     * Memcached performs the decrement server-side and clamps according to its native semantics.
     * The fallback store mirrors the non-negative behavior by never writing values below zero.
     *
     * @param string|int $key Cache key containing an integer-compatible value.
     * @param int|numeric-string $offset Amount to subtract.
     * @return int|false New value on success, or `false` when Memcached rejects the operation.
     */
    public static function decrement($key, $offset=1){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$started===false)self::start();
		if(self::$memory_fallback){
			$current=(int)(self::get($key) ?? 0);
			$value=max(0, $current-(int)$offset);
			self::set((string)$key, $value);
			return $value;
		}
		if(false===$result=self::$memcached->decrement($key, $offset)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Operation failed: ".self::$memcached->getResultCode().' / '.self::$memcached->getResultMessage(), $S="warning");
		}
        return $result;
    }
}
