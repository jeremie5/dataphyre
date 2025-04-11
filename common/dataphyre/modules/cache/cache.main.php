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

class cache{
	
    protected static $memcached;
    public static $started=false;
	
	private static function start(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(!is_object(self::$memcached)){
			self::$memcached=new \Memcached();
			if(false===self::$memcached->addServer('localhost', 11211)){
				core::unavailable(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $D='DataphyreCache: Failed initiating memcached connection to localhost on port 11211', 'safemode');
			}
			$versions=self::$memcached->getVersion();
			if(!is_array($versions) || empty($versions)){
				//core::unavailable(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $D='DataphyreCache: Unable to retrieve Memcached version.', 'safemode');
			}
			if(is_array($versions)){
				foreach($versions as $server=>$version){
					if(!is_string($version) || version_compare($version, '1.4.0') < 0){
						//core::unavailable(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $D="DataphyreCache: Memcached version($version) on server [$server] is too old.", 'safemode');
					}
				}
			}
		}
		self::$started=true;
	}
	
	public static function get($key){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$started===false)self::start();
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

    public static function set(string $key, mixed $value, int $expiration=0){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$started===false)self::start();
		if(false===$result=self::$memcached->set($key, serialize($value), $expiration)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Operation failed: ".self::$memcached->getResultCode().' / '.self::$memcached->getResultMessage(), $S="warning");
		}
        return $result;
    }

    public static function delete($key){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$started===false)self::start();
		if(false===$result=self::$memcached->delete($key)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Operation failed: ".self::$memcached->getResultCode().' / '.self::$memcached->getResultMessage(), $S="warning");
		}
        return $result;
    }

    public static function flush(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$started===false)self::start();
		if(false===$result=self::$memcached->flush()){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Operation failed: ".self::$memcached->getResultCode().' / '.self::$memcached->getResultMessage(), $S="warning");
		}
        return $result;
    }

    public static function increment($key, $offset=1){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$started===false)self::start();
		if(false===$result=self::$memcached->increment($key, $offset)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Operation failed: ".self::$memcached->getResultCode().' / '.self::$memcached->getResultMessage(), $S="warning");
		}
        return $result;
    }

    public static function decrement($key, $offset=1){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$started===false)self::start();
		if(false===$result=self::$memcached->decrement($key, $offset)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Operation failed: ".self::$memcached->getResultCode().' / '.self::$memcached->getResultMessage(), $S="warning");
		}
        return $result;
    }
}