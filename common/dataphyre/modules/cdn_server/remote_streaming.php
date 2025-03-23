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

class remote_streaming{

	public static function stream_remote_content(string $url, ?array $parameters=null, int $max_attempts=10, int $retry_delay_micros=1000) : bool {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$parameters??=[];
		$url=\dataphyre\core::url_updated_querystring($url, $parameters);
		if(!str_contains($url, $_SERVER['SERVER_ADDR'])){
			$attempts=0;
			while($attempts<$max_attempts){
				if(false!==$stream=@fopen($url, 'rb')){
					stream_set_blocking($stream, false);
					while(!feof($stream)){
						if(false===$chunk=fread($stream, 4096))continue;
						echo $chunk;
					}
					fclose($stream);
					return true;
				}
				else
				{
					$attempts++;
					if($attempts<$max_attempts){
						usleep($retry_delay_micros);
					}
				}
			}
		}
		return false;
	}

	public static function stream_to_block(string $origin_url, int $blockid, bool $encryption=false, array &$errors=[]) : bool|array {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$blockpath=utils::blockid_to_blockpath($blockid);
		$filename=str_replace('-', '/', $blockpath);
		$filepath=\dataphyre\cdn_server::$storage_filepath.$filename.'.dt';
		$passkey=($encryption===true)?bin2hex(openssl_random_pseudo_bytes(16)):null;
		if(false!==self::stream_to_file($origin_url, $filepath, ["passkey"=>$passkey], $errors)){
			$hash=hash_file("sha256", $filepath);
			$file_size=filesize($filepath);
			$mime_type=utils::get_mime_type($filepath);
			if(false!==$row=sql_select(
				$S="blockid",
				$L="dataphyre.cdn_blocks",
				$P="WHERE hash=?",
				$V=[$hash], 
				$F=false,
				$C=false
			)){
				if(false===unlink($filepath)){
					tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Failed aborting block assignation", $T="fatal");
					$errors[]=$S;
				}
				return array_filter([
					"blockid"=>$row['blockid'],
					"blockpath"=>utils::blockid_to_blockpath($row['blockid']),
					"hash"=>$hash,
					"abort_by_hash"=>true,
					"file_size"=>$file_size,
					"mime_type"=>$mime_type
				]);
			}
			if(false!==sql_update(
				$L="dataphyre.cdn_blocks",
				$F=[
					"use_count"=>1,
					"hash"=>$hash,
					"filesize"=>$file_size,
					"mime_type"=>$mime_type,
					"genesis_server"=>$_SERVER['SERVER_ADDR']
				],
				$P="WHERE blockid=?",
				$V=[$blockid]
			)){
				return array_filter([
					"blockpath"=>$blockpath,
					"passkey"=>$passkey,
					"hash"=>$hash,
					"file_size"=>$file_size,
					"mime_type"=>$mime_type
				]);
			}
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Failed assigning content to block", $T="fatal");
			$errors[]=$S;
			unlink($filepath);
			return false;
		}
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Failed to save content", $T="fatal");
		$errors[]=$S;
		return false;
	}

	public static function stream_to_file(string $url, string $filepath, ?array $parameters=null, array &$errors=[]) : bool {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		$parameters??=[];
		$url=\dataphyre\core::url_updated_querystring($url, $parameters);
		if(file_exists($filepath)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Content already existed at file location", $T="fatal");
			$errors[]=$S;
			return false;
		}
		if(\dataphyre\cdn_server::can_store_block()===false){
			tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Out of storage space", $T="fatal");
			$errors[]=$S;
			return false;
		}
		$attempts=0;
		while($attempts<5){
			$attempts++;
			$context = stream_context_create(['http' => ['timeout' => 1]]);
			if(false===$stream = @fopen($url, 'rb', false, $context)){
				tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Failed opening stream", $T="fatal");
				$errors[]=$S;
				continue;
			}
			stream_set_blocking($stream, false);
			$temp_filepath=$filepath.'.tmp';
			if(false===\dataphyre\core::file_put_contents_forced($temp_filepath)){
				tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Failed file creation", $T="fatal");
				$errors[]=$S;
				continue;
			}
			if(false===$file=fopen($temp_filepath, 'wb')){
				tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Failed to open file: $temp_filepath", $T="fatal");
				$errors[]=$S;
				continue;
			}
			while(!feof($stream)){
				if(false===$read=fread($stream, 4096)){
					tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Failed to read stream", $T="fatal");
					$errors[]=$S;
					continue;
				}
				if(false===fwrite($file, $read)){
					tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Failed stream write", $T="fatal");
					$errors[]=$S;
					continue;
				}
			}
			fclose($file);
			fclose($stream);
			$file_info=finfo_open(FILEINFO_MIME_TYPE);
			$mime_type=finfo_file($file_info, $temp_filepath);
			finfo_close($file_info);
			if(isset($parameters['passkey'])){
				if(str_starts_with($mime_type, 'image/')){
					if(false===$content=file_get_contents($temp_filepath)){
						tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Failed to read temp file for encryption", $T="fatal");
						$errors[]=$S;
						continue;
					}
					$encrypted_data=openssl_encrypt($content, 'aes-256-cbc', $parameters['passkey'], OPENSSL_RAW_DATA, $iv=random_bytes(16));
					if($encrypted_data===false){
						tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Encryption failed", $T="fatal");
						$errors[]=$S;
						continue;
					}
					\dataphyre\core::file_put_contents_forced($filepath, $iv.$encrypted_data);
				}
				else
				{
					if(false===$encrypted_file=fopen($filepath, 'wb')){
						tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Failed to open file for encrypted writing", $T="fatal");
						$errors[]=$S;
						continue;
					}
					if(false===fwrite($encrypted_file, $iv=random_bytes(16))){
						tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Failed to write IV to file", $T="fatal");
						$errors[]=$S;
						continue;
					}
					if(false===$input=fopen($temp_filepath, 'rb')){
						tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Failed to open temp file for reading", $T="fatal");
						$errors[]=$S;
						fclose($encrypted_file);
						continue;
					}
					while(!feof($input)){
						$chunk=fread($input, $configurations['dataphyre']['cdn_server']['streamed_encryption_chunk_size']);
						if($chunk===false){
							tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Failed to read chunk for encryption", $T="fatal");
							$errors[]=$S;
							continue;
						}
						$encrypted_chunk=openssl_encrypt($chunk, 'aes-256-cbc', $parameters['passkey'], OPENSSL_RAW_DATA, $iv);
						if($encrypted_chunk===false){
							tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Chunk encryption failed", $T="fatal");
							$errors[]=$S;
							fclose($input);
							fclose($encrypted_file);
							continue;
						}
						fwrite($encrypted_file, $encrypted_chunk);
					}
					fclose($input);
					fclose($encrypted_file);
				}
			}
			else
			{
				if(false===rename($temp_filepath, $filepath)){
					tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Failed renaming temp file", $T="fatal");
					$errors[]=$S;
					continue;		
				}
			}
			if(!file_exists($filepath)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Content still does not exist at file location", $T="fatal");
				$errors[]=$S;
				return false;
			}
			return true;
		}
		if($attempts<$max_attempts){
			usleep(pow(2, $attempts) * 1000);
		}
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Unknown error, attempts exhausted", $T="fatal");
		$errors[]=$S;
		return false;
	}

}	