<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

dp_define_module_config('cdn', 'DP_CDN_CFG', [
	'base_url'=>'',
	'block_storage_url'=>'',
]);

$cache_directory=ROOTPATH['common_dataphyre'].'cache/cdn/';
if(!is_dir($cache_directory)){
	@mkdir($cache_directory, 0775, true);
}
if(!is_writable($cache_directory)){
	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCDN: Missing cache folder write permission.', 'fatal');
}

class cdn{
	
	static $inodes_per_directory_depth=10000;

	private static function config(string $key, mixed $default=null): mixed {
		return DP_CDN_CFG[$key] ?? $default;
	}

	private static function base_url(): string {
		$base_url=trim((string)self::config('base_url', ''));
		if($base_url===''){
			return '';
		}
		return rtrim($base_url, '/').'/';
	}

	private static function block_storage_url(): string {
		$block_storage_url=trim((string)self::config('block_storage_url', ''));
		if($block_storage_url===''){
			return '';
		}
		return rtrim($block_storage_url, '/').'/';
	}

	public static function configured(): bool {
		return self::base_url()!=='' || self::block_storage_url()!=='';
	}

	private static function api_request(string $action, array $fields): array|false {
		$base_url=self::base_url();
		if($base_url===''){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='CDN base_url is not configured.', $S='fatal');
			return false;
		}
		$curl=curl_init();
		curl_setopt($curl, CURLOPT_URL, $base_url."cdn_api?action=".rawurlencode($action));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, ['pvk'=>dpvk()] + $fields);
		$result=curl_exec($curl);
		if($result===false){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed reaching CDN server: '.curl_error($curl), $S='fatal');
			curl_close($curl);
			return false;
		}
		curl_close($curl);
		$decoded_result=json_decode($result, true);
		if(!is_array($decoded_result)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='CDN server returned invalid JSON: '.$result, $S='fatal');
			return false;
		}
		return $decoded_result;
	}

	private static function local_origin_url(string $fileid): string {
		$https=(string)($_SERVER['HTTPS'] ?? '');
		$scheme=($https!=='' && strtolower($https)!=='off') ? 'https' : 'http';
		$host=trim((string)($_SERVER['HTTP_HOST'] ?? ''));
		if($host===''){
			$host=trim((string)($_SERVER['SERVER_ADDR'] ?? '127.0.0.1'));
			$port=(int)($_SERVER['SERVER_PORT'] ?? 0);
			$default_port=($scheme==='https') ? 443 : 80;
			if($port>0 && $port!==$default_port){
				if(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)){
					$host='['.$host.']';
				}
				$host.=':'.$port;
			}
		}
		return $scheme.'://'.$host.'/dataphyre/cdn/'.$fileid;
	}

	private static function asset_identifier(string $blockpath, string $extension=''): string {
		$extension=ltrim(trim($extension), '.');
		if($extension===''){
			return $blockpath;
		}
		if(str_contains($blockpath, '#')){
			[$clean_blockpath, $passkey]=explode('#', $blockpath, 2);
			return $clean_blockpath.'.'.$extension.'#'.$passkey;
		}
		return $blockpath.'.'.$extension;
	}

	public static function block_url(string $encoded_blockpath, array $parameters=[]): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$block_storage_url=self::block_storage_url();
		$url=$block_storage_url!=='' ? $block_storage_url.$encoded_blockpath : ltrim($encoded_blockpath, '/');
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

	public static function asset_url(string $blockpath, string $extension='', array $parameters=[]): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		return self::block_url(self::asset_identifier($blockpath, $extension), $parameters);
	}

	public static function encode_blockpath(string $blockpath): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$paths=explode('/', $blockpath);
		foreach($paths as $id=>$path){
			$paths[$id]=strtoupper(dechex(intval($path)));
		}
		return implode('-', $paths);
	}

	public static function decode_blockpath(string $blockpath): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$paths=explode('-', $blockpath);
		foreach($paths as $id=>$path){
			$paths[$id]=hexdec($path);
		}
		return implode('-', $paths);
	}

	public static function blockpath_to_blockid(string $blockpath): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$path='';
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
				$decoded_result=self::api_request('purge', [
					"blockid" => $blockid,
				]);
				if(is_array($decoded_result) && ($decoded_result['status'] ?? '')==="success"){
					return 0;
				}
			}
		}
		return false;
	}

	public static function ingest_resources(string $html, int $resource_limit=null, array $known_changes=[]): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$block_storage_url=self::block_storage_url();
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
		$url_handler=function($matches)use(&$changes, &$replacements_count, $resource_limit, $known_changes, $block_storage_url){
			if($resource_limit!==null && $replacements_count>=$resource_limit){
				return $matches[0];
			}
			$url=$matches[1];
			if(isset($known_changes[$url])){
				$path_parts=pathinfo($known_changes[$url]);
				$extension=$path_parts['extension']??'';
				$cdnUrl=self::asset_url($known_changes[$url], $extension);
				return str_replace($matches[1], $cdnUrl, $matches[0]);
			}
			if($block_storage_url==='' || !str_starts_with($url, $block_storage_url)){
				$blockpath=self::propagate($url);
				if($blockpath){
					$url_parts=explode('?', $url);
					$clean_url=$url_parts[0];
					$path_parts=pathinfo($clean_url);
					$extension=$path_parts['extension']??'';
					$cdnUrl=self::asset_url($blockpath, $extension);
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
		$fileid=uuid().'.'.pathinfo($file, PATHINFO_EXTENSION);
		$attempts=0;
		$blockpath='';
		$decoded_result=[];
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
			if(!copy($file, ROOTPATH['common_dataphyre'].'cache/cdn/'.$fileid)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed copying file into cache.', $S='fatal');
				return false;
			}
			$origin=self::local_origin_url($fileid);
		}
		else
		{
			$origin=$file;
		}
		while($attempts<10){
			$attempts++;
			$decoded_result=self::api_request('push', [
				"origin" => base64_encode($origin),
				"encryption" => (int)$encryption
			]);
			if(is_array($decoded_result)){
				if(!empty($decoded_result['blockpath'])){
					$blockpath=$decoded_result['blockpath'];
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='CDN: Transmission success: '.json_encode($decoded_result));
					break;
				}
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='CDN server returned a negative result:'.json_encode($decoded_result), $S='fatal');
			}
		}
		if(empty($blockpath)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed transmitting file after 10 attempts.', $S='fatal');
		}
		if(!filter_var($file, FILTER_VALIDATE_URL)){
		//	if(!unlink($file)){
		//		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed deleting origin file.', $S='fatal');
		//	}
		//	if(!unlink(ROOTPATH['common_dataphyre'].'cache/cdn/'.$fileid)){
			//	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed deleting cached file.', $S='fatal');
		//	}
		}
		if(!empty($blockpath)){
			if(!empty($decoded_result['passkey'])){
				return $blockpath.'#'.$decoded_result['passkey'];
			}
			return $blockpath;
		}
		return false;
	}
	
}
