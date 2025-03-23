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

namespace dataphyre\cdn_server;

class sharded_replication{ 

	private static function replicate_content(int $blockid, string $decoded_blockpath, string $remote_url, ?string $expected_hash=null) : bool {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		$filename=str_replace('-', '/', $decoded_blockpath);
		$filepath=\dataphyre\cdn_server::$storage_filepath.$folder."/".$filename;
		$filepath=\dataphyre\cdn_server::get_filepath($blockid);
		if(false!==cdn_server\remove_streaming::stream_to_file($remote_url, $filepath, ['raw_file'])){
			if(false!==cdn_server\integrity::enforce_block_integrity($blockid, $expected_hash)){
				sql_query(
					$Q="UPDATE dataphyre.cdn_blocks SET replication_count=replication_count+1 WHERE blockid=?;",
					$V=[$blockid]
				);
				return true;
			}
		}
		return false;
	}

	public static function get_servers_for_blockid(int $blockid, ?string $target_datacenter=null) : array {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		$servers=$configurations['dataphyre']['cdn_server']['servers'];
		$datacenter_priority=$configurations['dataphyre']['cdn_server']['datacenter_priority'];
		$hash=crc32((string)$blockid);
		$server_keys=array_keys($servers);
		usort($server_keys, function($a, $b)use($servers, $datacenter_priority){
			$priorityA=$datacenter_priority[$servers[$a]['datacenter']] ?? PHP_INT_MAX;
			$priorityB=$datacenter_priority[$servers[$b]['datacenter']] ?? PHP_INT_MAX;
			return $priorityA<=>$priorityB;
		});
		$total_servers=count($server_keys);
		$selected_servers=[];
		$datacenter_counts=[];
		$datacenter_groups=[];
		foreach($server_keys as $server_key){
			$datacenter=$servers[$server_key]['datacenter'];
			$datacenter_counts[$datacenter]=$datacenter_counts[$datacenter] ?? 0;
			$datacenter_groups[$datacenter][]=$server_key;
		}
		for($i=0; $i<$total_servers; $i++){
			$server_index=($hash+$i)%$total_servers;
			$server_key=$server_keys[$server_index];
			$datacenter=$servers[$server_key]['datacenter'];
			if($target_datacenter!==null && $datacenter!==$target_datacenter){
				continue;
			}
			if($datacenter_counts[$datacenter]<$configurations['dataphyre']['cdn_server']['redundancy_level'] && !in_array($server_key, $selected_servers)){
				$selected_servers[]=$server_key;
				$datacenter_counts[$datacenter]++;
			}
			if(count($selected_servers)>=$configurations['dataphyre']['cdn_server']['redundancy_level']*count($datacenter_counts)){
				break;
			}
		}
		$final_selected_servers=[];
		foreach($datacenter_groups as $datacenter=>$group_servers){
			if($target_datacenter!==null && $datacenter!==$target_datacenter){
				continue;
			}
			shuffle($group_servers);
			foreach($group_servers as $server_key){
				if(in_array($server_key, $selected_servers)){
					$final_selected_servers[]=$server_key;
				}
			}
		}
		return $final_selected_servers;
	}
	
}