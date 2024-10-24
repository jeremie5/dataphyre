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
namespace dataphyre;

$_PARAM=[];

if(file_exists($filepath=$rootpath['common_dataphyre']."config/routing.php")){
	require_once($filepath);
}
if(file_exists($filepath=$rootpath['dataphyre']."config/routing.php")){
	require_once($filepath);
}
if(!isset($configurations['dataphyre']['routing'])){
	//core::unavailable("MOD_ROUTING_NO_CONFIG", "safemode");
}

routing::not_found();

class routing{
	
	public static $page;
	private static $realpage;
	
	public static function not_found(){
		global $configurations;
		self::set_page("/".$configurations['dataphyre']['routing']['not_found_errorpage']);
		if(!empty($configurations['dataphyre']['routing']['not_found_errorpage'])){
			header('Location: https://'.$_SERVER['HTTP_HOST'].'/'.$configurations['dataphyre']['routing']['not_found_errorpage']);
			exit();
		}
		http_response_code(404);
		die("<br><br><center><h1>404</h1></center><center><h2>The page you were looking for doesn't exist.</h2></center>");
	}

	private static function set_page($file) {
		global $rootpath;
		self::$realpage="/".str_replace($rootpath['views'], '', substr($file, 0, strrpos($file, ".")));
		self::$page=self::$realpage;
		return $file;
	}

	public static function check_route(string $route, string $file){
		global $rootpath;
		$file=preg_replace('!([^:])(//)!', "$1/", $file);
		if($_REQUEST['uri']==='/router.php')routing::not_found();
		$request="/";
		if(!empty($_REQUEST['uri'])){
			$route=preg_replace("/(^\/)|(\/$)/", "", $route);
			$request=preg_replace("/(^\/)|(\/$)/", "", $_REQUEST['uri']);
		}
		preg_match_all("/(?<={).+?(?=})/", $route, $param_matches);
		if(empty($param_matches[0]))return($request===$route)?self::set_page($file):false;
		if(!self::process_format_params($param_matches, $request, $route))return false;
		return self::set_page($file);
	}

	private static function process_format_params($param_matches, $request, $route){
		global $_PARAM;
		$temp_params=[];
		$param_key=[];
		$is_format=false;
		foreach($param_matches[0] as $key)$param_key[]=$key;
		$uri=explode("/", $route);
		$index_num=array_keys(array_filter($uri, fn($param)=>preg_match("/{.*}/", $param)));
		$request=explode("/", $request);
		foreach($index_num as $key=>$index){
			if(str_starts_with($param_key[$key], "...")){
				$array_name=substr($param_key[$key], 3);
				for($i=$index; $i<count($request); $i++)$temp_params[$array_name][]=$request[$i];
				$prevalidated[$key]=true;
				array_splice($request, $index);
				break;
			}
			if(preg_match('/{(.+?)}/', $param_key[$key], $matches)){
				$parts=explode('|', $matches[1]);
				if(!in_array($request[$index] ?? '', $parts))return false;
				$temp_params[array_pop($parts)]=$request[$index];
				$prevalidated[$key]=true;
			}
		}
		foreach($index_num as $key=>$index){
			$good_id_format=false;
			if(isset($prevalidated[$key])){
				$good_id_format=true;
				$request[$index]="{.*}";
				continue;
			}
			if(!isset($request[$index]))return false;
			$format_params=explode(',', $param_key[$key]);
			if($format_params[0]==='starts_with_and_length_is'){
				$is_format=true;
				if(str_starts_with($request[$index], $format_params[1])){
					if(strlen($request[$index])==$format_params[2]){
						$temp_params[$format_params[3]]=$request[$index];
						$good_id_format=true;
					}
				}
			}
			elseif($format_params[0]==='character_at_position_is'){
				$is_format=true;
				$position=$format_params[1];
				$character=$format_params[2];
				if(isset($request[$index][$position - 1]) && $request[$index][$position - 1] === $character){
					$temp_params[$format_params[3]]=$request[$index];
					$good_id_format=true;
				}
			}
			elseif($format_params[0]==='starts_with'){
				$is_format=true;
				if(str_starts_with($request[$index], $format_params[1])){
					$temp_params[$format_params[3]]=$request[$index];
					$good_id_format=true;
				}
			}
			elseif($format_params[0]==='ends_with'){
				$is_format=true;
				if(str_ends_with($request[$index], $format_params[1])){
					$temp_params[$format_params[3]]=$request[$index];
					$good_id_format=true;
				}
			}
			elseif($format_params[0]==='ends_with_and_length_is'){
				$is_format=true;
				if(str_ends_with($request[$index], $format_params[1])){
					if(strlen($request[$index])==$format_params[2]){
						$temp_params[$format_params[3]]=$request[$index];
						$good_id_format=true;
					}
				}
			}
			elseif($format_params[0]==='length_is'){
				$is_format=true;
				if(strlen($request[$index])==$format_params[2]){
					$temp_params[$format_params[3]]=$request[$index];
					$good_id_format=true;
				}
			}
			elseif($format_params[0]==='is_integer'){
				$is_format=true;
				if(is_integer($request[$index])===true){
					$temp_params[$format_params[1]]=$request[$index];
					$good_id_format=true;
				}
			}
			elseif($format_params[0]==='is_numeric'){
				$is_format=true;
				if(is_numeric($request[$index])===true){
					$temp_params[$format_params[1]]=$request[$index];
					$good_id_format=true;
				}
			}
			elseif($format_params[0]==='is_string'){
				$is_format=true;
				if(is_string($request[$index])===true){
					$temp_params[$format_params[1]]=$request[$index];
					$good_id_format=true;
				}
			}
			elseif($format_params[0]==='is_urlcoded_json'){
				$is_format=true;
				$result=json_decode(urldecode($request[$index]));
				if(json_last_error()===JSON_ERROR_NONE){
					$temp_params[$format_params[1]]=$request[$index];
					$good_id_format=true;
				}
			}
			elseif($format_params[0]==='is_md5'){
				$is_format=true;
				if(strlen($request[$index])==32 && ctype_xdigit($request[$index])){
					$temp_params[$format_params[1]]=$request[$index];
					$good_id_format=true;
				}
			}
			elseif($format_params[0]==='is_uuid'){
				$is_format=true;
				if(preg_match('/^(\{)?[a-f\d]{8}(-[a-f\d]{4}){4}[a-f\d]{8}(?(1)\})$/i', $request[$index])){
					$temp_params[$format_params[1]]=$request[$index];
					$good_id_format=true;
				}
			}
			else
			{
				$temp_params[$format_params[0]]=$request[$index];
				$good_id_format=true;
			}
			if($good_id_format===false)break;
			if($is_format===false)$temp_params[$param_key[$key]]=$request[$index];
			$request[$index]="{.*}";
		}
		$request=implode("/",$request);
		$request=str_replace("/", '\\/', $request);
		if(preg_match("/^{$request}$/", $route)){
			if($is_format===true && $good_id_format===false)return false;
			$_PARAM=$temp_params;
			return true;
		}
	}
}