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
* forbidden unless prior written permission is obtained from Shopiro Ltd.
*/

if(!function_exists("current_datetime")){
	function current_datetime(): string {
		return date('Y-m-d H:i:s', time());
	}
}

if(!function_exists("array_replace_values")){
	function array_replace_values(array $array, mixed $old_value, mixed $new_value): array {
		$result=[];
		foreach($array as $index=>$value){
			if($value===$old_value){
				$result[$index]=$new_value;
			}
		}
		return $result;
	}
}

if(!function_exists("prefix_array_keys")){
	function prefix_array_keys(array $array, string $prefix, int $start_at=0): array {
		$result=[];
		foreach($array as $index=>$value){
			$result["{$prefix}".($index+$start_at)]=$value;
		}
		return $result;
	}
}

if(!function_exists("is_cli")){
	function is_cli(): bool {
		return defined('STDIN') || php_sapi_name() === 'cli' || array_key_exists('SHELL', $_ENV) || 
			   (empty($_SERVER['REMOTE_ADDR']) && !isset($_SERVER['HTTP_USER_AGENT']) && count($_SERVER['argv']) > 0) || 
			   !array_key_exists('REQUEST_METHOD', $_SERVER);
	}
}

if(!function_exists("uuid")){
	function uuid(): string {
		$data=random_bytes(16);
		$data[6]=chr(ord($data[6])&0x0f|0x40);
		$data[8]=chr(ord($data[8])&0x3f|0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}

if(!function_exists("is_uuid")){
	function is_uuid(string $string): bool {
		return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $string) === 1;
	}
}

if(!function_exists("is_base64")){
	function is_base64(string $string): bool {
		if(!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $string)) return false;
		$decoded=base64_decode($string, true);
		if(false===$decoded)return false;
		if(base64_encode($decoded)!==$string)return false;
		if(is_null($string))return false;
		return true;
	}
}

if(!function_exists("is_timestamp")){
	function is_timestamp(int $timestamp): bool {
		$date=date('m-d-Y', $timestamp);
		list($month, $day, $year)=explode('-', $date);
		return checkdate($month, $day, $year);
	}
}

if(!function_exists("ellipsis")){
	function ellipsis(string $string, int $length, string $direction='right'): string {
		if(mb_strlen($string)<= $length){
			return $string;
		}
		switch($direction){
			case 'left':
				return '...'.mb_substr($string, -$length);
			case 'center':
				$half=floor($length / 2);
				return mb_substr($string, 0, $half).'...'.mb_substr($string, -$half);
			case 'right':
			default:
				return mb_substr($string, 0, $length).'...';
		}
	}
}

if(!function_exists("array_average")){
	function array_average(array $array) : int {
		return array_sum($array)/count($array);
	}
}

if(!function_exists("array_shuffle")){
	function array_shuffle(array $array): array {
		$keys=array_keys($array);
		shuffle($keys);
		foreach($keys as $key){
			$new[$key]=$array[$key];
		}
		$array=$new;
		return $array; 
	}
}

if(!function_exists("array_count")){
	function array_count(mixed $array): int {
		if($array===false || is_null($array) || !is_array($array)){
			return 0;
		}
		else
		{
			return count($array);
		}
	}
}

if(!function_exists("copy_folder")){
	function copy_folder(string $src, string $dst) : void {
		$dir=opendir($src);
		@mkdir($dst);
		while(false!==$file=readdir($dir)){
			if(($file!='.') && ($file!='..' )){
				if(is_dir($src.'/'.$file)){
					core::copy_folder($src.'/'.$file, $dst.'/'.$file);
				}
				else
				{
					copy($src.'/'.$file, $dst.'/'.$file);
				}
			}
		}
		closedir($dir);
	}
}
