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

require_once(__DIR__."/../../config/cdn.php");

if(file_exists($filepath=$rootpath['common_dataphyre']."config/cdn.php")){
	require_once($filepath);
}
if(file_exists($filepath=$rootpath['dataphyre']."config/cdn.php")){
	require_once($filepath);
}
if(!isset($configurations['dataphyre']['cdn'])){
	//core::unavailable("MOD_ASYNC_NO_CONFIG", "safemode");
}

if(!is_writable(__DIR__."/cache/")){
	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCDN: Missing cache folder write permission.', 'fatal');
}

class cdn{
	
	static $inodes_per_directory_depth=10000;

	public static function block_url(string $encoded_blockpath, array $parameters=[]): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		$url=$configurations['dataphyre']['cdn']['block_storage_url'].$encoded_blockpath;
		if(str_contains($url, "#")){
			$broken_up_url=explode('#', $url);
			$parameters["passkey"]=$broken_up_url[1];
			$url=$broken_up_url[0];
		}
		if(!empty($parameters)){
			$url=\dataphyre\core::url_updated_querystring($url, $parameters);
		}
		return $url;
	}

	public static function encode_blockpath(string $blockpath): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$paths=explode('/', $blockpath);
		foreach($paths as $id=>$path){
			$paths[$id]=strtoupper(dechex($path));
		}
		return implode('-', $paths);
	}

	public static function decode_blockpath(string $blockpath): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$paths=explode('-', $blockpath);
		foreach($paths as $id=>$path){
			$paths[$id]=hexdec($path);
		}
		return implode('-', $paths);
	}

	public static function blockpath_to_blockid(string $blockpath): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$blockid=0;
		$pathParts=explode('-', $blockpath);
		foreach($pathParts as $part){
			if($part!==''){
				$blockid=$blockid*self::$inodes_per_directory_depth+intval($part);
			}
		}
		if(!is_int($blockid))return 0;
		return $blockid;
	}

	public static function blockid_to_blockpath(int $blockid): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		while($blockid>0){
			$remainder=$blockid%self::$inodes_per_directory_depth;
			$path='-'.$remainder.$path;
			$temp=$blockid-$remainder;
			$blockid=$temp/self::$inodes_per_directory_depth;
		}
		return ltrim($path, '-');
	}

	public static function update_use_count(string $blockpath, int $amount) : bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		$decoded_blockpath=self::decode_blockpath($blockpath);
		$blockid=self::blockpath_to_blockid($decoded_blockpath);
		if(false!==$block=sql_select(
			$S="use_count",
			$L="dataphyre.cdn_blocks",
			$P="WHERE blockid=?",
			$V=array($blockid)
		)){
			$new_count=$block['use_count']+$amount;
			if($new_count>0){
				if(false!==sql_update(
					$L="dataphyre.cdn_blocks",
					$F="use_count=?",
					$P="WHERE blockid=?",
					$V=array($new_count, $blockid)
				)){
					return $new_count;
				}
			}
			else
			{
				$curl=curl_init($configurations['dataphyre']['cdn']['block_storage_url']."cdn_api/purge");
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_URL, $configurations['dataphyre']['cdn']['base_url'] . "cdn_api/push");
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, [
					"pvk" => dpvk(),
					"blockid" => $blockid,
				]);
				if(false!==$result=curl_exec($curl)){
					if(null!==$decoded_result=json_decode($result, true)){
						curl_close($curl);
						if($decoded_result['status']==="success"){
							return 0;
						}
					}
				}
				curl_close($curl);
			}
		}
		return false;
	}

	public static function ingest_resources(string $html, int $resource_limit=null, array $known_changes=[]): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		$patterns=[
			'img'=>'/<img\s[^>]*?src=["\']([^"\']+)["\']/i',
			'video'=>'/<source\s[^>]*?src=["\']([^"\']+)["\']/i',
			'script'=>'/<script\s[^>]*?src=["\']([^"\']+)["\']/i',
			'style'=>'/<link\s[^>]*?href=["\']([^"\']+)["\'][^>]*?rel=["\']stylesheet["\']/i',
			'audio'=>'/<audio\s[^>]*?src=["\']([^"\']+)["\']/i',
			'iframe'=>'/<iframe\s[^>]*?src=["\']([^"\']+)["\']/i',
			'css_bg'=>'/url\((["\']?)([^"\')]+)\1\)/i',
			'favicon'=>'/<link\s[^>]*?href=["\']([^"\']+)["\'][^>]*?rel=["\'](icon|shortcut icon)["\']/i',
			'font'=>'/@font-face\s*{[^}]*?url\(["\']?([^)"\']+)\)?["\']?[^}]*?}/i',
			'source_in_picture'=>'/<source\s[^>]*?srcset=["\']([^"\']+)["\'][^>]*?>/i',
			'pdf_object'=>'/<object\s[^>]*?type=["\']application\/pdf["\'][^>]*?data=["\']([^"\']+)["\'][^>]*?>/i',
			'svg_img'=>'/<img\s[^>]*?src=["\']([^"\']+?\.svg)["\']/i',
			'pdf_link'=>'/<a\s[^>]*?href=["\']([^"\']+?\.pdf)["\']/i'
		];
		$changes=[];
		$replacements_count=0;
		$url_handler=function($matches)use(&$changes, &$replacements_count, $resource_limit, $known_changes){
			if($resource_limit!==null && $replacements_count>=$resource_limit){
				return $matches[0];
			}
			$url=$matches[1];
			if(isset($known_changes[$url])){
				$path_parts=pathinfo($known_changes[$url]);
				$extension=$path_parts['extension']??'';
				$cdnUrl=$configurations['dataphyre']['cdn']['block_storage_url'].$known_changes[$url].'.'.$extension;
				return str_replace($matches[1], $cdnUrl, $matches[0]);
			}
			if(!str_starts_with($url, $configurations['dataphyre']['cdn']['block_storage_url'])){
				$blockpath=self::propagate($url);
				if($blockpath){
					$url_parts=explode('?', $url);
					$clean_url=$url_parts[0];
					$path_parts=pathinfo($clean_url);
					$extension=$path_parts['extension']??'';
					$cdnUrl=$configurations['dataphyre']['cdn']['block_storage_url'].$blockpath.'.'.$extension;
					$changes[$url]=$blockpath;
					$replacements_count++;
					$result=str_replace($matches[1], $cdnUrl, $matches[0]);
					return $result;
				}
				else
				{
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Invalid blockpath', $S='fatal');
				}
			}
			return $matches[0];
		};
		foreach($patterns as $tag=>$pattern){
			$html=preg_replace_callback($pattern, $url_handler, $html);
			if($resource_limit !== null && $replacements_count>=$resource_limit){
				break;
			}
		}
		return[
			'new_html'=>$html, 
			'changes'=>$changes
		];
	}

	public static function propagate(string $file, bool $encryption=false) : bool|string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CDN_PROPAGATE",...func_get_args())) return $early_return;
		global $configurations;
		$fileid=uuid().'.'.pathinfo($file, PATHINFO_EXTENSION);
		if(!filter_var($file, FILTER_VALIDATE_URL)){
			if(!file_exists($file)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='File does not exist', $S='fatal');
				return false;
			}
			if($encryption===false){
				$hash=hash_file('sha256', $file);
				if(false!==$row=sql_select(
					$S="*", 
					$L="dataphyre.cdn_blocks", 
					$P="WHERE hash=?", 
					$V=[$hash], 
					$F=false, 
					$C=false
				)){
					$blockpath=self::blockid_to_blockpath($row['blockid']);
					$blockpath=str_replace('-', '/', $blockpath);
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='CDN: File hash was already known', $S='fatal');
					return self::encode_blockpath($blockpath);
				}
			}
			if(!copy($file, __DIR__."/cache/".$fileid)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed copying file into cache.', $S='fatal');
				return false;
			}
			$origin="http://".$_SERVER['SERVER_ADDR']."/dataphyre/cdn/".$fileid;
		}
		else
		{
			$origin=$file;
		}
		while($attempts<10){
			$attempts++;
			$curl=curl_init();
			curl_setopt($curl, CURLOPT_URL, $configurations['dataphyre']['cdn']['base_url'] . "cdn_api?action=push");
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, [
				"pvk" => dpvk(),
				"origin" => base64_encode($origin),
				"encryption" => (int)$encryption
			]);
			if(false!==$result=curl_exec($curl)){
				curl_close($curl);
				if(null!==$decoded_result=json_decode($result, true)){
					if(!empty($decoded_result['blockpath'])){
						$blockpath=$decoded_result['blockpath'];
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='CDN: Transmission success: '.$result);
						break;
					}
					else
					{
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='CDN server returned a negative result:'.$result, $S='fatal');
					}
				}
				else
				{
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='CDN server returned invalid JSON:'.$result, $S='fatal');
				}
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed reaching CDN server: '.curl_error($curl), $S='fatal');
			}
		}
		if(empty($blockpath)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed transmitting file after 10 attempts.', $S='fatal');
		}
		if(!filter_var($file, FILTER_VALIDATE_URL)){
			if(!unlink($file)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed deleting origin file.', $S='fatal');
			}
			if(!unlink(__DIR__."/cache/".$fileid)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed deleting cached file.', $S='fatal');
			}
		}
		curl_close($curl);
		if(!empty($blockpath)){
			if(!empty($decoded_result['passkey'])){
				return $blockpath.'#'.$decoded_result['passkey'];
			}
			return $blockpath;
		}
		return false;
	}
	
}