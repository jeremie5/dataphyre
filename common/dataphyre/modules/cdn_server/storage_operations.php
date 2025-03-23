<?php
/*************************************************************************
*  2020-2022 Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd..
*/

namespace dataphyre\cdn_server;

class storage_operations{
	
	public static function add_content(string $origin_url, int $iteration=0, bool $encryption=false) : array {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(!isset($iteration_error)){
			static $iteration_error='';
		}
		if(\dataphyre\cdn_server::can_store_block()===false){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Out of storage space", $T="fatal");
			return [
				"status"=>"failed", 
				"errors"=>"Out of storage space",
				"server"=>$_SERVER['SERVER_ADDR'],
				"time"=>round(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 3),
			];
		}
		if($iteration>\dataphyre\cdn_server::$max_content_addition_attempts){
			if(!empty($blockid)){
				sql_update(
					$L="dataphyre.cdn_blocks",
					$F=["hash"=>$iteration_error],
					$P="WHERE blockid=?",
					$V=array($blockid)
				);
			}
			return [
				"status"=>"failed", 
				"errors"=>$iteration_error,
				"server"=>$_SERVER['SERVER_ADDR'],
				"time"=>round(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 3),
			];
		}
		if(empty($origin_url)){
			return [
				"status"=>"failed", 
				"errors"=>"No origin url",
				"server"=>$_SERVER['SERVER_ADDR'],
				"time"=>round(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 3),
			];
		}
		if(false===$server_step=\dataphyre\cdn_server::get_server_step()){
			return [
				"status"=>"failed", 
				"errors"=>"Failed getting server step", 
				"server"=>$_SERVER['SERVER_ADDR'],
				"time"=>round(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 3),
			];
		}
		$i=0;
		while($i<5){
			$i++;
			if(!is_int($blockid)){
				$blockid=self::assign_block();
			}
			if(is_int($blockid)){
				$stream_errors=[];
				if(false!==$blockpath_data=remote_streaming::stream_to_block($origin_url, $blockid, $encryption, $stream_errors)){
					return array_filter([
						"status"=>"success",
						"origin"=>$origin_url,
						"blockid"=>$blockpath_data['blockid'] ?? $blockid,
						"decoded_blockpath"=>$blockpath_data['blockpath'],
						"blockpath"=>utils::encode_blockpath(str_replace('-', '/', $blockpath_data['blockpath'])),
						"hash"=>$blockpath_data['hash'],
						"abort_by_hash"=>$blockpath_data['abort_by_hash'],
						"file_size"=>$blockpath_data['file_size'],
						"mime_type"=>$blockpath_data['mime_type'],
						"passkey"=>$blockpath_data['passkey'],
						"server"=>$_SERVER['SERVER_ADDR'],
						"time"=>round(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 3),
					]);
				}
				$iteration_error="Failed saving content to block $blockid from $origin_url. Error(s): ".implode(', ', array_unique($stream_errors));
				break;
			}
			$iteration_error="Failed to create block or no blockid returned from assignation";
			break;
		}
		return self::add_content($origin_url, $iteration+1, $encryption);
	}
	
	public static function assign_block(string $method_on_new="random") : int|bool {
		if(false!==$result=sql_query(
			$Q=[
				"mysql"=>"
					START TRANSACTION;
					SELECT blockid INTO @blockid 
					FROM dataphyre.cdn_blocks 
					WHERE use_count = 0 AND time <= DATE_SUB(NOW(), INTERVAL 5 MINUTE) 
					ORDER BY time ASC 
					LIMIT 1 
					FOR UPDATE;
					UPDATE dataphyre.cdn_blocks 
					SET time = NOW() 
					WHERE blockid = @blockid;
					COMMIT;
				",
				"postgresql"=>"
					WITH cte AS (
						SELECT blockid FROM dataphyre.cdn_blocks  
						WHERE use_count = 0 AND time <= (NOW() - INTERVAL '5 minutes') 
						ORDER BY time ASC 
						LIMIT 1 
						FOR UPDATE
					)
					UPDATE dataphyre.cdn_blocks 
					SET time = NOW() 
					WHERE blockid IN (SELECT blockid FROM cte) 
					RETURNING blockid;
				"
			],
			$V=null, 
			$M=false, 
			$C=false, 
			$CC=false, 
			$Q=false
		)){
			$blockid=$result['blockid'];
		}
		if(empty($blockid)){
			if($method_on_new==='random'){
				$maxint=(int)file_get_contents(__DIR__."/maxint");
				$maxint=min($maxint, PHP_INT_MAX);
				$maxint=(int)max($maxint, \dataphyre\cdn_server::$inodes_per_directory_depth);
				$attempts=0;
				while($attempts<10){
					$insert_blockid=random_int(1, $maxint);
					if(false!==sql_insert(
						$L="dataphyre.cdn_blocks",
						$F=["blockid"=>$insert_blockid]
					)){
						$blockid=$insert_blockid;
						break;
					}
					$attempts++; 
				}
				if(!is_int($blockid)){
					if(false!==$count_in_maxint_range=sql_count(
						$L="dataphyre.cdn_blocks",
						$P="WHERE blockid<? AND blockid>?",
						$V=[$maxint, $maxint/10],
						$C=false
					)){
						if($count_in_maxint_range/($maxint-($maxint/10))>0.75){ // If more than 75% of blocks are assigned in this range move to next one
							file_put_contents(__DIR__."/maxint", $maxint*10);
							return self::assign_block();
						}
					}
				}
			}
		}
		if(is_numeric($blockid)){
			return (int)$blockid;
		}
		return false;
	}

	public static function discard_content(int $blockid) : array {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$filename=utils::blockid_to_blockpath($blockid);
		$filename=str_replace('-', '/', $filename);
		$filepath=\dataphyre\cdn_server::$storage_filepath.$filename.'.dt';
		if(file_exists($filepath)){
			if(unlink($filepath)){
				sql_update(
					$L="dataphyre.cdn_blocks",
					$F="replication_count=replication_count-1",
					$P="WHERE blockid=?",
					$V=[$blockid]
				);
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Content discarded");
				return [
					"status"=>"success",
					"errors"=>"Content discarded successfully.",
					"server"=>$_SERVER['SERVER_ADDR']
				];
			}
			else
			{
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Failed to discard content");
				return [
					"status"=>"failed", 
					"errors"=>"Failed to discard content from storage.",
					"server"=>$_SERVER['SERVER_ADDR']
				];
			}
		}
		else
		{
			return [
				"status"=>"failed", 
				"errors"=>"No content found at specified block location.",
				"server"=>$_SERVER['SERVER_ADDR']
			];
		}
	}

	public static function purge_content(int $blockid) : array {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$filename=utils::blockid_to_blockpath($blockid);
		$filename=str_replace('-', '/', $filename);
		$filepath=\dataphyre\cdn_server::$storage_filepath.$filename.'.dt';
		if(file_exists($filepath)){
			if(unlink($filepath)){
				sql_update(
					$L="dataphyre.cdn_blocks", 
					$F=["use_count"=>0],
					$P="WHERE blockid=?", 
					$V=[$blockid], 
					$CC=true
				);
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Content purged");
				return [
					"status"=>"success", 
					"errors"=>"Content purged successfully.",
					"server"=>$_SERVER['SERVER_ADDR']
				];
			}
			else
			{
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Failed to purge content");
				return [
					"status"=>"failed", 
					"errors"=>"Failed to purge content from storage.",
					"server"=>$_SERVER['SERVER_ADDR']
				];
			}
		}
		else
		{
			return [
				"status"=>"failed", 
				"errors"=>"No content found at specified block location.",
				"server"=>$_SERVER['SERVER_ADDR']
			];
		}
	}
	
}