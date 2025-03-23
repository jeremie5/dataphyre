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

class remote_content{

	public static function display_remote_file_content(string $blockpath, ?array $parameters=null) : void { 
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		$mime_type=utils::get_mime_type($blockpath);
		$parameters['proxy_bounces']??=0;
		$parameters['proxy_bounces']++;
		$file_no_extension=pathinfo($blockpath, PATHINFO_FILENAME);
		$filepath=\dataphyre\cdn_server::get_folder($blockpath);
		$decoded_blockpath=utils::decode_blockpath($file_no_extension);
		$blockid=utils::blockpath_to_blockid($decoded_blockpath);
		$remote_servers=sharded_replication::get_servers_for_blockid($blockid);
		$cache_compression_level=$configurations['dataphyre']['cdn_server']['cache_compression_level'];
		if(!in_array($_SERVER['SERVER_ADDR'], $remote_servers)){
			if(str_contains($mime_type, 'image')){
				$remote_content_cache_key=$cache_compression_level.hash("sha256", implode($parameters));
			}
			if(isset($remote_content_cache_key)){
				if(null!==$cached_content=cache::get($remote_content_cache_key)){
					if($cache_compression_level>0){
						echo gzinflate($cached_content);
					}
					else
					{
						echo $cached_content;
					}
					flush();
					ob_flush();
					fastcgi_finish_request();
					cache::set($remote_content_cache_key, $cached_content, \dataphyre\cdn_server::$remote_image_cache_lifespan);
					exit();
				}
			}
		}
		if($parameters['proxy_bounces']>count($remote_servers)+1){
			error_display::cannot_display_content("Too many attempts within zone", 502);
		}
		$proxy_path=array_filter(explode(',',base64_decode($parameters['proxy_path'])));
		$filtered_remote_servers=array_filter($remote_servers, function($server) use ($proxy_path){
			return $server!==$_SERVER['SERVER_ADDR'] && !in_array($server, $proxy_path);
		});
		if(!empty($filtered_remote_servers)){
			$remote_server=reset($filtered_remote_servers);
			if(false===$server_info=\dataphyre\cdn_server::get_server_info($remote_server)){
				error_display::cannot_display_content("Candidate remote server is undefined", 502);
			}
			$proxy_path[]=$_SERVER['SERVER_ADDR'];
			$parameters['proxy_path']=base64_encode(implode(',', $proxy_path));
			$remote_url=$server_info['protocol']."://{$remote_server}:".$server_info['port']."/vault/{$blockpath}";
			ob_start();
			if(false!==\dataphyre\cdn_server::stream_remote_content($remote_url, $parameters)){
				$remote_content=ob_get_contents();
				flush();
				ob_flush();
				fastcgi_finish_request();
				if(in_array($_SERVER['SERVER_ADDR'], $remote_servers)){
					$remote_url=$server_info['protocol']."://{$remote_server}:".$server_info['port']."/direct_storage/storage/{$filepath}";
					\dataphyre\cdn_server::replicate_content($blockid, $decoded_blockpath, $remote_url);
				}
				else
				{
					if(isset($remote_content_cache_key)){
						if($cache_compression_level>0){
							$remote_content_deflated=gzdeflate($remote_content, $cache_compression_level);
							\dataphyre\cache::set($remote_content_cache_key, $remote_content_deflated, \dataphyre\cdn_server::$remote_image_cache_lifespan);
						}
						else
						{
							\dataphyre\cache::set($remote_content_cache_key, $remote_content, \dataphyre\cdn_server::$remote_image_cache_lifespan);
						}
					}
				}
				exit();
			}
			ob_end_clean();
		}
		if(empty($parameters['genesis_server']) || empty($parameters['expected_hash'])){
			if(false!==$block=sql_select(
				$S="*",
				$L="dataphyre.cdn_blocks",
				$P="WHERE blockid=?",
				$V=[$blockid],
				$F=false,
				$C=false
			)){
				if($block['genesis_server']==0){
					error_display::cannot_display_content("Zone server list has been exhausted and genesis server is unknown for block ".$blockid.".", 502);
				}
				$parameters['genesis_server']=$block['genesis_server'];
				//header("hash: ".$block['hash']);
				//header("genesis_server: ".$block['genesis_server']);
				//$parameters['expected_hash']=$block['hash']; Breaking
			}
		}
		if(!empty($parameters['genesis_server'])){
			if(!in_array($parameters['genesis_server'], $proxy_path)){
				if($parameters['genesis_server']!==$_SERVER['SERVER_ADDR']){
					$proxy_path[]=$_SERVER['SERVER_ADDR'];
					if(false===$server_info=\dataphyre\cdn_server::get_server_info($parameters['genesis_server'])){
						error_display::cannot_display_content("Candidate remote genesis server is undefined", 502);
					}
					$parameters['proxy_path']=base64_encode(implode(',', $proxy_path));
					$remote_url=$server_info['protocol']."://{$parameters['genesis_server']}".$server_info['port']."/vault/{$blockpath}";
					if(false!==remote_streaming::stream_remote_content($remote_url, $parameters)){
						ob_flush();
						flush();
						fastcgi_finish_request();
						if(in_array($_SERVER['SERVER_ADDR'], $remote_servers)){
							$remote_url=$server_info['protocol']."://{$parameters['genesis_server']}".$server_info['port']."/direct_storage/storage/{$filepath}";
							if(false!==sharded_replication::replicate_content($blockid, $decoded_blockpath, $remote_url, $parameters['expected_hash'])){
								if(!in_array($parameters['genesis_server'], $remote_servers)){
									\dataphyre\cdn_server::cdn_api_action($parameters['genesis_server'], ["action"=>"discard", "blockid"=>$blockid]);
									sql_query(
										$Q="UPDATE dataphyre.cdn_blocks SET genesis_server=? WHERE blockid=?;",
										$V=[$_SERVER['SERVER_ADDR'], $blockid]
									);
								}
							}
						}
						exit();
					}
				}
			}
		}
		error_display::cannot_display_content("No server left to attempt getting content from for block ".$blockid.".", 502);
	}

}	