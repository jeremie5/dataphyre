<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

$routing_config_loaded=false;
if(file_exists($filepath=ROOTPATH['common_dataphyre']."config/routing.php")){
	$routing_config_loaded=true;
	require_once($filepath);
}
if(file_exists($filepath=ROOTPATH['dataphyre']."config/routing.php")){
	$routing_config_loaded=true;
	require_once($filepath);
}
if($routing_config_loaded!==true){
	pre_init_error("DataphyreRouting: No routes available.");
}

routing::not_found();

/**
 * Legacy request router for Dataphyre view-file routing configuration.
 *
 * The routing kernel loads route config files during module initialization, tracks the first
 * successful route match, publishes path bindings, and either maps a route to a view file or
 * terminates with configured 404 behavior. It is intentionally stateful because legacy view
 * bootstrap code reads the static page, realpage, binding, and debug properties after route
 * checks run.
 */
class routing{
	
	/**
	 * Matched page path exposed to legacy view bootstrap code.
	 */
	public static $page;
	
	/**
	 * Matched page path before downstream code mutates `$page`.
	 */
	public static $realpage;
	
	/**
	 * Route parameter bindings captured from the current request.
	 *
	 * @var array<string, mixed>
	 */
	public static $bindings=[];

	/**
	 * Normalized route pattern that matched the current request.
	 */
	public static ?string $matched_route=null;

	/**
	 * View file returned for the matched route.
	 */
	public static ?string $matched_file=null;

	/**
	 * Normalized request path compared during routing.
	 */
	public static ?string $matched_request=null;

	/**
	 * Verbose routing log lines for the match or latest parameter evaluation.
	 *
	 * @var array<int, string>
	 */
	public static array $matched_verbose=[];

	/**
	 * Unix timestamp with microseconds when the route matched.
	 */
	public static ?float $matched_at=null;

	/**
	 * @var array{not_found_errorpage:string}
	 */
	public static array $config=[
		'not_found_errorpage'=>'',
	];
	
	/**
	 * Number of route checks performed before the current match.
	 */
	public static $route_non_match_count=0;
	
	/**
	 * Whether failed route parameter matches should write verbose tracelog entries.
	 */
	private static $verbose_non_match=true;

	/**
	 * Merges routing runtime configuration.
	 *
	 * @param array<string, mixed> $config Configuration overrides such as `not_found_errorpage`.
	 * @return void
	 */
	public static function configure(array $config): void {
		self::$config=array_replace_recursive(self::$config, $config);
	}

	/**
	 * Returns the configured legacy 404 redirect target.
	 *
	 * @return string Trimmed error page path, or an empty string for inline 404 output.
	 */
	private static function not_found_errorpage(): string {
		return trim((string)(self::$config['not_found_errorpage'] ?? ''));
	}
	
	/**
	 * Checks one legacy route pattern against the current request path.
	 *
	 * Route checks normalize both route and request paths, capture `{parameter}` bindings, support
	 * legacy formatted parameter rules, and update static match state on success. When `$file` is
	 * supplied, success returns the normalized view file path; otherwise it returns `true`.
	 *
	 * @param string $route Legacy route pattern, optionally containing formatted placeholders.
	 * @param string $file Optional view file path to expose through `set_page()`.
	 * @return string|bool View file on file-backed match, `true` on route-only match, or `false` when the route does not match.
	 */
	public static function check_route(string $route, string $file=''): string|bool {
		self::$route_non_match_count++;
		$file=preg_replace('!([^:])(//)!', "$1/", $file);
		$request="/";
		$request_uri=(string)($_GET['uri'] ?? '');
		if($request_uri===''){
			$request_uri=(string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
		}
		if($request_uri!=='' && $request_uri!=='/'){
			$route=preg_replace("/(^\/)|(\/$)/", "", $route);
			$request=preg_replace("/(^\/)|(\/$)/", "", rawurldecode($request_uri));
		}
		self::$matched_request="/".trim((string)$request, "/");
		preg_match_all("/(?<={).+?(?=})/", $route, $param_matches);
		$match=function($file) use($route, $request){
			$log[]="Route match after " .(self::$route_non_match_count - 1)." non-matches.";
			$log[]="Matched route: $route";
			if(!empty($file))$log[]="Returning file: $file";
			foreach($log as $line){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $line);
			}
			self::$matched_route="/".trim((string)$route, "/");
			self::$matched_file=!empty($file) ? $file : null;
			self::$matched_request="/".trim((string)$request, "/");
			self::$matched_verbose=$log;
			self::$matched_at=microtime(true);
			if(!empty($file))return self::set_page($file);
			return true;
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
	
	/**
	 * Terminates the request with configured legacy not-found behavior.
	 *
	 * A configured error page redirects to that path using the detected scheme and host. Without
	 * an error page, the method emits a 404 status and a minimal legacy HTML message.
	 *
	 * @return never
	 */
	public static function not_found(): never {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$not_found_errorpage=self::not_found_errorpage();
		self::set_page("/".$not_found_errorpage);
		if($not_found_errorpage!==''){
			$scheme=(string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
			if($scheme!=='https'){
				$scheme=((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off') || (($_SERVER['REQUEST_SCHEME'] ?? '')==='https')) ? 'https' : 'http';
			}
			$host=(string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
			header('Location: '.$scheme.'://'.$host.'/'.ltrim($not_found_errorpage, '/'));
			exit();
		}
		http_response_code(404);
		die("<br><br><center><h1>404</h1></center><center><h2>The page you were looking for doesn't exist.</h2></center>");
	}

	/**
	 * Returns a diagnostic snapshot of the most recent routing decision.
	 *
	 * @return array{request_path:string, normalized_request:string, method:string, matched_route:?string, matched_file:?string, page:mixed, realpage:mixed, bindings:array<string, mixed>, non_match_count:int, not_found_errorpage:string, verbose:array<int, string>, matched_at:?float} Routing state for Flightdeck and diagnostics.
	 */
	public static function debug_snapshot(): array {
		$request_path=(string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
		return [
			'request_path'=>$request_path,
			'normalized_request'=>self::$matched_request ?? $request_path,
			'method'=>(string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
			'matched_route'=>self::$matched_route,
			'matched_file'=>self::$matched_file,
			'page'=>self::$page,
			'realpage'=>self::$realpage,
			'bindings'=>self::$bindings,
			'non_match_count'=>max(0, (int)self::$route_non_match_count - (self::$matched_route!==null ? 1 : 0)),
			'not_found_errorpage'=>self::not_found_errorpage(),
			'verbose'=>self::$matched_verbose,
			'matched_at'=>self::$matched_at,
		];
	}

	/**
	 * Records the matched view file as legacy page state.
	 *
	 * @param string $file Matched view file path.
	 * @return string The same file path so `check_route()` can return it directly.
	 */
	private static function set_page(string $file): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		self::$realpage="/".str_replace(ROOTPATH['views'], '', substr($file, 0, strrpos($file, ".")));
		self::$page=self::$realpage;
		return $file;
	}

	/**
	 * Evaluates legacy placeholder and formatted route parameters.
	 *
	 * Supported placeholder shapes include greedy captures (`{...name}`), discarded greedy
	 * captures (`{...void}`), fixed alternatives, named captures, and formatted checks such as
	 * integer, numeric, UUID, MD5, URL-encoded JSON, prefix/suffix, length, and explicit
	 * `is_either` lists. Successful matches publish captured values to `self::$bindings`.
	 *
	 * @param array<int, array<int, string>> $param_matches Placeholder matches from the route pattern.
	 * @param string $request Normalized request path without leading/trailing slash.
	 * @param string $route Normalized route pattern without leading/trailing slash.
	 * @return array{matched:bool, verbose?:array<int, string>} Parameter match result and optional verbose trace lines.
	 */
	private static function process_format_params(array $param_matches, string $request, string $route): array {
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
				if('void'!==$array_name=substr($param_key[$key], 3)){
					for($i=$index; $i < count($request); $i++){
						$temp_params[$array_name][]=$request[$i];
					}
					$verbose[]="Parameter '{$array_name}' captured as array.";
				}
				else
				{
					$verbose[]="Parameter 'void' discarded.";
				}
				$prevalidated[$key]=true;
				array_splice($request, $index);
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
				case 'is_either':
					$is_format=true;
					$param_parts=explode(',', $param_key[$key]); 
					$param_name=$param_parts[1];
					$allowed_values=array_slice($param_parts, 2);
					if(in_array($request[$index], $allowed_values, true)){
						$temp_params[$param_name]=$request[$index];
						$good_id_format=true;
					}
					$verbose[]=$good_id_format ? "Parameter '{$param_name}' matched is_either (" . implode(", ", $allowed_values) . ")." : "Parameter '{$param_name}' failed is_either (" . implode(", ", $allowed_values) . ").";
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
			$request[$index]="{.*}";
		}
		$request=implode("/", $request);
		$request=str_replace("/", '\\/', $request);
		if(preg_match("/^{$request}$/", $route)){
			self::$bindings=$temp_params;
			$verbose[]="Route matched successfully.";
			return ['matched'=>true, 'verbose'=>$verbose];
		}
		$verbose[]="Route did not match.";
		return self::$verbose_non_match ? ['matched'=>false, 'verbose'=>$verbose] : ['matched'=>false];
	}

}
