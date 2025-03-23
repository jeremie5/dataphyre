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

dp_module_required('cdn_server', 'sql');
dp_module_required('cdn_server', 'cache');
dp_module_required('cdn_server', 'scheduling');

if(file_exists($filepath=$rootpath['common_dataphyre']."config/cdn_server.php")){
	require_once($filepath);
}
if(file_exists($filepath=$rootpath['dataphyre']."config/cdn_server.php")){
	require_once($filepath);
}

cdn_server::$storage_filepath=$rootpath['dataphyre']."cdn_content/store/".$_SERVER['SERVER_ADDR']."/";
cdn_server::$cdn_server_name=$configurations['dataphyre']['cdn_server']['servers'][$_SERVER['SERVER_ADDR']]['name'];

require_once __DIR__.'/sharded_replication.php';
require_once __DIR__.'/content_streaming.php';
require_once __DIR__.'/content_display.php';
require_once __DIR__.'/image_processing.php';
require_once __DIR__.'/integrity.php';
require_once __DIR__.'/node.php';
require_once __DIR__.'/remote_content.php';
require_once __DIR__.'/remote_streaming.php';
require_once __DIR__.'/storage_operations.php';
require_once __DIR__.'/utils.php';
require_once __DIR__.'/error_display.php';
require_once __DIR__.'/containerization.php';

class cdn_server{ 
	
	static $cdn_server_name='';
	
	static $storage_filepath='';
	
	static $inodes_per_directory_depth=10000;
	
	static $modified_image_cache_lifespan=7200;
	
	static $remote_image_cache_lifespan=7200;
	
	static $max_content_addition_attempts=10;
	
	static $container_block_count=1000;
	
	static $container_uncontainerize_threshold=10;
	
	public static function get_server_info(string $ip_address) : bool|array {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(isset($configurations['dataphyre']['cdn_server']['servers'][$ip_address])){
			$server=$configurations['dataphyre']['cdn_server']['servers'][$ip_address];
			return [
				'name'=>$server['name'],
				'datacenter'=>$server['datacenter'],
				'port'=>$server['port'] ?? $configurations['dataphyre']['cdn_server']['default_port'],
				'protocol'=>$server['protocol'] ?? $configurations['dataphyre']['cdn_server']['default_protocol'],
			];
		}
		return false;
	}
	
	public static function get_server_step() : int {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		return array_search($_SERVER['SERVER_ADDR'], array_keys($configurations['dataphyre']['cdn_server']['servers']));
	}
	
	public static function cdn_api_action(string $ip_address, array $data) : bool {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		if(false!==$server_info=self::get_server_info($ip_address)){
			$query_params=['pvk'=>$configurations['dataphyre']['private_key']];
			foreach($data as $key=>$value){
				$query_params[$key]=$value;
			}
			$query_string=http_build_query($query_params);
			$url=$server_info['protocol']."://".$ip_address.$server_info['port']."/cdn_api?".$query_string;
			file_get_contents($url);
		}
        return false;
    }
	
	public static function can_store_block() : bool {
		$totalSpace=disk_total_space("/");
		$freeSpace=disk_free_space("/");
		$usedSpacePercentage=(1-$freeSpace/$totalSpace)*100;
		return $usedSpacePercentage<90;
	}

	public static function get_folder(string $blockpath) : string {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$result=str_replace('-', '/', $blockpath);
		return $result;
	}
	
	public static function get_filepath(string $blockpath) : string {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $rootpath;
		$filepath=self::get_folder($blockpath);
		$filepath=cdn_server::$storage_filepath.$filepath.'.dt';
		return $filepath;
	}
	
}