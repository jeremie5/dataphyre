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

$_PARAM=[];

if(file_exists($filepath=$rootpath['common_dataphyre']."config/routing.php")){
	require_once($filepath);
}
if(file_exists($filepath=$rootpath['dataphyre']."config/routing.php")){
	require_once($filepath);
}
if(!isset($configurations['dataphyre']['routing'])){
	pre_init_error("DataphyreRouting: No routes available.");
}

routing::not_found();

class routing{
	
	public static $page;
	
	public static $realpage;
	
	public static $route_non_match_count=0;
	
	private static $verbose_non_match=false;
	
	public static function check_route(string $route, string $file): string|bool {
		global $rootpath;
		self::$route_non_match_count++;
		$file=preg_replace('!([^:])(//)!', "$1/", $file);
		if($_REQUEST['uri']==='/router.php'){
			routing::not_found();
		}
		$request="/";
		if(!empty($_REQUEST['uri'])){
			$route=preg_replace("/(^\/)|(\/$)/", "", $route);
			$request=preg_replace("/(^\/)|(\/$)/", "", $_REQUEST['uri']);
		}
		preg_match_all("/(?<={).+?(?=})/", $route, $param_matches);
		$match=function($file) use($route){
			$log=[
				"Route matched after " .(self::$route_non_match_count - 1)." non-matches.",
				"Matched route: $route",
				"Serving file: $file"
			];
			foreach($log as $line){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $line);
			}
			return self::set_page($file);
		};
		if(empty($param_matches[0])){
			if($request===$route){
				return $match($file);
			}
			elseif(self::$verbose_non_match===true){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Route '$route' did not match request '$request'.");
				return false;
			}
		}
		$parameter_match=self::process_format_params($param_matches, $request, $route);
		if($parameter_match['matched']===false){
			if(!empty($parameter_match['verbose'])){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Route '$route' failed parameter matching. Verbose log for non-match:");
				foreach($parameter_match['verbose'] as $line){
					tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, " - $line");
				}
			}
			return false;
		}
		if(!empty($parameter_match['verbose'])){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Verbose log for match:");
			foreach($parameter_match['verbose'] as $line){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, " - $line");
			}
		}
		return $match($file);
	}
	
	public static function not_found(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		self::set_page("/".$configurations['dataphyre']['routing']['not_found_errorpage']);
		if(!empty($configurations['dataphyre']['routing']['not_found_errorpage'])){
			header('Location: https://'.$_SERVER['HTTP_HOST'].'/'.$configurations['dataphyre']['routing']['not_found_errorpage']);
			exit();
		}
		http_response_code(404);
		die("<br><br><center><h1>404</h1></center><center><h2>The page you were looking for doesn't exist.</h2></center>");
	}

	private static function set_page(string $file): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $rootpath;
		self::$realpage="/".str_replace($rootpath['views'], '', substr($file, 0, strrpos($file, ".")));
		self::$page=self::$realpage;
		return $file;
	}

	private static function process_format_params(array $param_matches, string $request, string $route): array {
		global $_PARAM;
		$temp_params=[];
		$param_key=[];
		$verbose=[];
		$is_format=false;
		$prevalidated=[];
		$matched=false;
		foreach($param_matches[0] as $key){
			$param_key[]=$key;
		}
		$uri=explode("/", $route);
		$index_num=array_keys(array_filter($uri, fn($param)=>preg_match("/{.*}/", $param)));
		$request=explode("/", $request);
		foreach($index_num as $key=>$index){
			if(str_starts_with($param_key[$key], "...")){
				$array_name=substr($param_key[$key], 3);
				for($i=$index; $i < count($request); $i++){
					$temp_params[$array_name][]=$request[$i];
				}
				$prevalidated[$key]=true;
				array_splice($request, $index);
				$verbose[]="Parameter '{$array_name}' captured as array.";
				break;
			}
			if(preg_match('/{(.+?)}/', $param_key[$key], $matches)){
				$parts=explode('|', $matches[1]);
				if(!in_array($request[$index] ?? '', $parts)){
					$verbose[]="Parameter '{$param_key[$key]}' failed validation against parts: ".implode(", ", $parts);
					return self::$verbose_non_match ? ['matched'=>false, 'verbose'=>$verbose] : ['matched'=>false];
				}
				$temp_params[array_pop($parts)]=$request[$index];
				$prevalidated[$key]=true;
				$verbose[]="Parameter '{$param_key[$key]}' matched successfully.";
			}
		}
		foreach($index_num as $key=>$index){
			$good_id_format=false;
			if(isset($prevalidated[$key])){
				$good_id_format=true;
				$request[$index]="{.*}";
				continue;
			}
			if(!isset($request[$index])){
				$verbose[]="Request part at index '{$index}' is missing.";
				return self::$verbose_non_match ? ['matched'=>false, 'verbose'=>$verbose] : ['matched'=>false];
			}
			$format_params=explode(',', $param_key[$key]);
			switch($format_params[0]){
				case 'starts_with_and_length_is':
					$is_format=true;
					if(str_starts_with($request[$index], $format_params[1]) && strlen($request[$index])==$format_params[2]){
						$temp_params[$format_params[3]]=$request[$index];
						$good_id_format=true;
					}
					$verbose[]=$good_id_format ? "Parameter '{$param_key[$key]}' matched starts_with_and_length_is." : "Parameter '{$param_key[$key]}' failed starts_with_and_length_is.";
					break;
				case 'character_at_position_is':
					$is_format=true;
					$position=$format_params[1];
					$character=$format_params[2];
					if(isset($request[$index][$position-1]) && $request[$index][$position-1]===$character){
						$temp_params[$format_params[3]]=$request[$index];
						$good_id_format=true;
					}
					$verbose[]=$good_id_format ? "Parameter '{$param_key[$key]}' matched character_at_position_is." : "Parameter '{$param_key[$key]}' failed character_at_position_is.";
					break;
				case 'starts_with':
					$is_format=true;
					if(str_starts_with($request[$index], $format_params[1])){
						$temp_params[$format_params[3]]=$request[$index];
						$good_id_format=true;
					}
					$verbose[]=$good_id_format ? "Parameter '{$param_key[$key]}' matched starts_with." : "Parameter '{$param_key[$key]}' failed starts_with.";
					break;
				case 'ends_with':
					$is_format=true;
					if(str_ends_with($request[$index], $format_params[1])){
						$temp_params[$format_params[3]]=$request[$index];
						$good_id_format=true;
					}
					$verbose[]=$good_id_format ? "Parameter '{$param_key[$key]}' matched ends_with." : "Parameter '{$param_key[$key]}' failed ends_with.";
					break;
				case 'ends_with_and_length_is':
					$is_format=true;
					if(str_ends_with($request[$index], $format_params[1]) && strlen($request[$index])==$format_params[2]){
						$temp_params[$format_params[3]]=$request[$index];
						$good_id_format=true;
					}
					$verbose[]=$good_id_format ? "Parameter '{$param_key[$key]}' matched ends_with_and_length_is." : "Parameter '{$param_key[$key]}' failed ends_with_and_length_is.";
					break;
				case 'length_is':
					$is_format=true;
					if(strlen($request[$index])==$format_params[2]){
						$temp_params[$format_params[3]]=$request[$index];
						$good_id_format=true;
					}
					$verbose[]=$good_id_format ? "Parameter '{$param_key[$key]}' matched length_is." : "Parameter '{$param_key[$key]}' failed length_is.";
					break;
				case 'is_integer':
					$is_format=true;
					if(ctype_digit($request[$index])){
						$temp_params[$format_params[1]] =(int)$request[$index];
						$good_id_format=true;
					}
					$verbose[]=$good_id_format ? "Parameter '{$param_key[$key]}' matched is_integer." : "Parameter '{$param_key[$key]}' failed is_integer.";
					break;
				case 'is_numeric':
					$is_format=true;
					if(is_numeric($request[$index])){
						$temp_params[$format_params[1]]=$request[$index];
						$good_id_format=true;
					}
					$verbose[]=$good_id_format ? "Parameter '{$param_key[$key]}' matched is_numeric." : "Parameter '{$param_key[$key]}' failed is_numeric.";
					break;
				case 'is_string':
					$is_format=true;
					if(is_string($request[$index])){
						$temp_params[$format_params[1]]=$request[$index];
						$good_id_format=true;
					}
					$verbose[]=$good_id_format ? "Parameter '{$param_key[$key]}' matched is_string." : "Parameter '{$param_key[$key]}' failed is_string.";
					break;
				case 'is_urlcoded_json':
					$is_format=true;
					$result=json_decode(urldecode($request[$index]));
					if(json_last_error()===JSON_ERROR_NONE){
						$temp_params[$format_params[1]]=$result;
						$good_id_format=true;
					}
					$verbose[]=$good_id_format ? "Parameter '{$param_key[$key]}' matched is_urlcoded_json." : "Parameter '{$param_key[$key]}' failed is_urlcoded_json.";
					break;
				case 'is_md5':
					$is_format=true;
					if(strlen($request[$index])==32 && ctype_xdigit($request[$index])){
						$temp_params[$format_params[1]]=$request[$index];
						$good_id_format=true;
					}
					$verbose[]=$good_id_format ? "Parameter '{$param_key[$key]}' matched is_md5." : "Parameter '{$param_key[$key]}' failed is_md5.";
					break;
				case 'is_uuid':
					$is_format=true;
					if(preg_match('/^[a-f\d]{8}(-[a-f\d]{4}){3}-[a-f\d]{12}$/i', $request[$index])){
						$temp_params[$format_params[1]]=$request[$index];
						$good_id_format=true;
					}
					$verbose[]=$good_id_format ? "Parameter '{$param_key[$key]}' matched is_uuid." : "Parameter '{$param_key[$key]}' failed is_uuid.";
					break;
				default:
					$temp_params[$format_params[0]]=$request[$index];
					$good_id_format=true;
					$verbose[]="Parameter '{$param_key[$key]}' matched by default rule.";
					break;
			}
			if(!$good_id_format){
				$verbose[]="Parameter '{$param_key[$key]}' failed all rules.";
				return self::$verbose_non_match ? ['matched'=>false, 'verbose'=>$verbose] : ['matched'=>false];
			}
			if(!$is_format){
				$temp_params[$param_key[$key]]=$request[$index];
			}
			$request[$index]="{.*}";
		}
		$request=implode("/", $request);
		$request=str_replace("/", '\\/', $request);
		if(preg_match("/^{$request}$/", $route)){
			$_PARAM=$temp_params;
			$verbose[]="Route matched successfully.";
			return ['matched'=>true, 'verbose'=>$verbose];
		}
		$verbose[]="Route did not match.";
		return self::$verbose_non_match ? ['matched'=>false, 'verbose'=>$verbose] : ['matched'=>false];
	}

}