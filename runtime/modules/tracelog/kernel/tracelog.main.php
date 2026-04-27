<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

dp_define_module_config('tracelog', 'DP_TRACELOG_CFG', [
	'enable_tracelog'=>true,
	'save_to_file'=>false,
	'file_lifespan'=>6,
	'password'=>'',
]);

\heisenconstant('TRID', fn()=>RQID);

register_shutdown_function(function(){
	try{
		if(tracelog::$enable===true){
			tracelog::persist_to_session();
			if(tracelog::$save_to_sql===true){
				tracelog::save_to_database($GLOBALS['tracelog_rqid'] ?? RQID);
			}
		}
	}catch(\Throwable $exception){
		pre_init_error('Exception on Dataphyre Tracelog shutdown callback', $exception);
	}
});

if(defined('TRACELOG_BOOT_ENABLE') || defined('TRACELOG_FORCE_ENABLE')){
	new tracelog();
	tracelog::$enable=true;
	if(defined('TRACELOG_BOOT_ENABLE_PLOTTING') || defined('TRACELOG_BOOT_PLOTTING_ENABLE')){
		tracelog::set_plotting(true);
	}
}

if(RUN_MODE==='diagnostic'){
	require_once(__DIR__.'/tracelog.diagnostic.php');
}

class tracelog {
	
	public static $tracelog='';
	public static $constructed=false;
	public static $enable=false;
	public static $open=false;
    public static $plotting=false;
    public static $dynamic_unit_testing=false;
    public static $defer=true;
    public static $save_to_sql=false;
    private static ?string $last_handoff_token=null;
    
	public function __construct(){
		self::$constructed=true;
		self::set_handler();
	}
	
	public static function save_to_database(string $rqid): void {
		$time=date('Y-m-d H:i:s', strtotime('now'));
		if(false===$log=sql_insert(
			$L="dataphyre.tracelogs", 
			$F=[
				"rqid"=>$rqid,
				"log"=>self::$tracelog,
				"server"=>$_SERVER['SERVER_ADDR'],
				"app"=>APP,
				"date"=>$time
			]
		)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed creating log in database", $S="fatal");
		}
	}

	public static function process_retroactive(): void {
		global $retroactive_tracelog;
		$initial_memory=ini_get("memory_limit");
		ini_set("memory_limit", "256M");
		if(isset($retroactive_tracelog) && is_array($retroactive_tracelog)){
			if(self::$enable===true){
				foreach(array_reverse($retroactive_tracelog) as $log){
					if(is_string($log)){
						self::$tracelog=$log.self::$tracelog;
					}
					else
					{
						$tracelog_overhead=strlen(json_encode($log, JSON_UNESCAPED_UNICODE));
						$log[8]=$log[8]-$tracelog_overhead;
						self::tracelog(...$log);
					}
				}
			}
		}
		ini_set("memory_limit", $initial_memory);
		unset($retroactive_tracelog);
	}

	public static function persist_to_session(): void {
		if(self::$enable!==true){
			return;
		}
		if(self::$defer){
			self::$defer=false;
		}
		self::process_retroactive();
		$_SESSION['tracelog']=self::$tracelog;
		$_SESSION['flightdeck_last_tracelog']=self::$tracelog;
		$_SESSION['flightdeck_last_tracelog_rqid']=defined('RQID') ? (string)RQID : '';
		$_SESSION['flightdeck_last_tracelog_time']=time();
		$handoff_token=self::write_handoff_trace(self::$tracelog);
		if($handoff_token!==null){
			$_SESSION['flightdeck_last_tracelog_handoff']=$handoff_token;
		}
	}

	public static function last_handoff_trace(?string $handoff_token=null): string {
		if($handoff_token!==null && $handoff_token!==''){
			$file=self::handoff_file_from_token($handoff_token);
			if($file!==null && is_file($file)){
				return (string)@file_get_contents($file);
			}
		}
		$files=self::handoff_files();
		$newest_file='';
		$newest_time=0;
		foreach($files as $file){
			if(!is_file($file)){
				continue;
			}
			$mtime=(int)@filemtime($file);
			if($mtime>=$newest_time){
				$newest_file=$file;
				$newest_time=$mtime;
			}
		}
		if($newest_file!==''){
			return (string)@file_get_contents($newest_file);
		}
		foreach(self::recent_handoff_files() as $file){
			return (string)@file_get_contents($file);
		}
		return '';
	}

	private static function write_handoff_trace(string $trace): ?string {
		if($trace===''){
			return null;
		}
		$first_token=null;
		foreach(self::handoff_files() as $file){
			$id=pathinfo($file, PATHINFO_FILENAME);
			$first_token??=self::sign_handoff_id($id);
			if(class_exists('\dataphyre\core', false)){
				core::file_put_contents_forced($file, $trace);
				continue;
			}
			$directory=dirname($file);
			if(!is_dir($directory)){
				@mkdir($directory, 0775, true);
			}
			@file_put_contents($file, $trace, LOCK_EX);
		}
		self::$last_handoff_token=$first_token;
		return $first_token;
	}

	private static function handoff_url(string $route): string {
		$token=self::$last_handoff_token ?? self::sign_primary_handoff_id();
		$query=$token!==null ? '?'.http_build_query(['handoff'=>$token]) : '';
		return core::url_self().$route.$query;
	}

	private static function handoff_files(): array {
		$base=self::handoff_directory();
		if($base===''){
			return [];
		}
		$keys=array_filter([
			session_id(),
			(string)($_COOKIE[session_name()] ?? ''),
			(string)($_COOKIE['dataphyre_flightdeck'] ?? ''),
		], static fn($key)=>is_string($key) && $key!=='');
		$files=[];
		foreach(array_unique($keys) as $key){
			$files[]=$base.'/'.sha1((string)$key).'.dat';
		}
		return $files;
	}

	private static function handoff_directory(): string {
		if(defined('ROOTPATH') && !empty(ROOTPATH['dataphyre'])){
			return rtrim((string)ROOTPATH['dataphyre'], '/\\').'/cache/tracelog_handoff';
		}
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre'])){
			return rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/cache/tracelog_handoff';
		}
		return '';
	}

	private static function recent_handoff_files(): array {
		$directory=self::handoff_directory();
		if($directory==='' || !is_dir($directory)){
			return [];
		}
		$files=glob($directory.'/*.dat') ?: [];
		usort($files, static fn($a, $b)=>(int)@filemtime($b) <=> (int)@filemtime($a));
		return array_slice($files, 0, 3);
	}

	private static function sign_primary_handoff_id(): ?string {
		$files=self::handoff_files();
		if($files===[]){
			return null;
		}
		return self::sign_handoff_id(pathinfo($files[0], PATHINFO_FILENAME));
	}

	private static function sign_handoff_id(string $id): string {
		return $id.'.'.hash_hmac('sha256', $id, self::handoff_secret());
	}

	private static function handoff_file_from_token(string $token): ?string {
		$parts=explode('.', $token, 2);
		if(count($parts)!==2){
			return null;
		}
		[$id, $signature]=$parts;
		if(!preg_match('/^[a-f0-9]{40}$/', $id)){
			return null;
		}
		$expected=hash_hmac('sha256', $id, self::handoff_secret());
		if(hash_equals($expected, $signature)!==true){
			return null;
		}
		$directory=self::handoff_directory();
		return $directory!=='' ? $directory.'/'.$id.'.dat' : null;
	}

	private static function handoff_secret(): string {
		return hash('sha256', implode('|', [
			defined('LICENSE') && is_array(LICENSE) ? (string)(LICENSE['key'] ?? '') : '',
			defined('APP') ? (string)APP : '',
			defined('DATAPHYRE_PROJECT_ROOT') ? (string)DATAPHYRE_PROJECT_ROOT : '',
			defined('ROOTPATH') && !empty(ROOTPATH['root']) ? (string)ROOTPATH['root'] : '',
		]));
	}
	
	public static function buffer_callback(mixed $buffer): mixed {
		if(self::$enable===true){
			if(self::$open===true){
				self::persist_to_session();
				$all_defined_functions=function(){
					$global_functions=get_defined_functions()['user'];
					$class_methods=[];
					$classes=get_declared_classes();
					foreach($classes as $class){
						$reflection=new \ReflectionClass($class);
						if($reflection->isUserDefined()){
							$methods=get_class_methods($class);
							$class_methods=array_merge($class_methods, $methods);
						}
					}
					return count($global_functions)+count($class_methods);
				};
				$_SESSION['runtime_memory_used']=INITIAL_MEMORY_USAGE;
				$_SESSION['memory_used']=memory_get_usage()-INITIAL_MEMORY_USAGE;
				$_SESSION['memory_used_peak']=memory_get_peak_usage()-INITIAL_MEMORY_USAGE;
				if(is_int($function_count=$all_defined_functions())){
					$_SESSION['defined_user_function_count']=$function_count;
				}
				$_SESSION['exec_time']=microtime(true)-$_SERVER["REQUEST_TIME_FLOAT"];
				$_SESSION['included_files']=count(get_included_files());
				if(self::$plotting===true){
					return $buffer."<script>window.open('".self::handoff_url('dataphyre/tracelog/plotter')."', '_blank', 'width=1000, height=1000');</script>";
				}
				return $buffer."<script>window.open('".self::handoff_url('dataphyre/tracelog')."', '_blank', 'width=1000, height=1000');</script>";
			}
		}
		return $buffer;
	}
	
    public static function set_plotting($value){
        if(self::$plotting!==$value){
            self::$plotting=$value;
            if($value) @unlink(ROOTPATH['dataphyre'].'cache/tracelog_plotting.dat');
        }
    }

	
	/**
	  * Catch PHP errors
	  *
	  * @version 	1.0.0
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  */
	private static function set_handler(){
		if(null!==$early_return=core::dialback('CALL_TRACELOG_SET_HANDLER',...func_get_args())) return $early_return;
		set_error_handler(function($errno, $errstr, $errfile, $errline){
			if(null!==$early_return=core::dialback('CALL_TRACELOG_ERROR_FOUND',...func_get_args())) return $early_return;
			if($errno===E_ERROR || $errno===E_USER_ERROR){
				core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreTracelog: Fatal error during execution.', 'safemode');
			}
			if(self::$enable===true){
				$log='<br><table style="border: 1px solid white;"><tr><th style="color:red">Error</th><th style="color:red">File</th><th style="color:red">Line</th></tr><tr><td style="border: 1px solid white;">'.htmlspecialchars($errstr).'</td><td style="border: 1px solid white;">'.$errfile.'</td> <td style="border: 1px solid white;">'.$errline.'</td></tr></table>';
				if(self::$defer===true){
					$GLOBALS['retroactive_tracelog'][]=$log;
				}
				else
				{
					self::$tracelog.=$log;
				}
			}
			return true;
		});
	}

	/**
	  * Save tracelog to session variable
	  *
	  * @version 	1.0.6
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  *
	  * @param ?string $directory
	  * @param ?string $file
	  * @param ?string $line
	  * @param ?string $class
	  * @param ?string $function
	  * @param ?string $text
	  * @param ?string $type
	  * @param ?array $arguments
	  * @param ?float $time
	  * @return bool											True on success, false on failure
	  */
	public static function tracelog(?string $file, ?string $line, ?string $class, ?string $function, ?string $text, ?string $type='info', ?array $arguments=null, ?float $retroactive_time=null, ?int $retroactive_memory=null) : bool {
		if(self::$enable===false) return false;
		if(self::$defer===true){
			$GLOBALS['retroactive_tracelog'][]=[$file, $line, $class, $function, $text, $type, $arguments, microtime(true), memory_get_usage()];
			return true;
		}
		if($type==='function_call_with_test'){
			if(class_exists('dataphyre\dpanel') || $dpanel=dp_module_present('dpanel')){
				if(isset($dpanel) && is_array($dpanel)){
					require_once($dpanel[0]);
				}
				\dataphyre\dpanel::generate_dynamic_unit_test($file, $line, $class, $function, $arguments);
			}
		}
		static $last_function_signature=null;
		static $function_colors=[];
		if(!empty($class))$function=$class.'::'.$function;
		$time=$retroactive_time ?? microtime(true);
		$memory=($retroactive_memory ?? memory_get_usage())-INITIAL_MEMORY_USAGE;
		$tracelog_time=number_format(($time-$_SERVER["REQUEST_TIME_FLOAT"])*1000, 3, '.');
		$pre='';
		if(!empty($function)){
			if(is_array($arguments)){
				foreach($arguments as $key=>$value){
					if(is_string($value)){
						$arguments[$key]='"'.$value.'"';
					}
					elseif(is_array($value)){
						$arguments[$key]='Array';
					}
					elseif($value===true){
						$arguments[$key]='True';
					}
					elseif($value===false){
						$arguments[$key]='False';
					}
					elseif(is_integer($value)){
						$arguments[$key]='Integer('.$value.')';
					}
					elseif(is_null($value)){
						$arguments[$key]='Null';
					}
					elseif(is_object($value)){
						$arguments[$key]='Object';
					}
					elseif(is_callable($value)){
						$arguments[$key]='Callable';
					}
					else
					{
						$arguments[$key]='N/A';
					}
				}
				$text=implode(',', $arguments);
				$text=htmlentities($text);
			}
			$function_colors[$function]??=core::random_hex_color();
			if($type==='function_call'){
				$text='<span style="color:#85f1ff;">FC:</span> <span style="color:'.$function_colors[$function].'">'.$function.'('.$text.')</span>';
			}
			elseif($type==='function_call_with_test'){
				$text='<span style="color:#84b3ff;" title="Function Call with dynamic unit Test generation">FCwT:</span> <span style="color:'.$function_colors[$function].'">'.$function.'('.$text.')</span>';
			}
			else
			{
				$pre='<span style="color:'.$function_colors[$function].'">FC: '.$function.'():</span> ';
			}
		}
		if(empty($type) || $type==='info'){
			$type='info';
			$text=$pre.'<span style="color:#28cc49">'.$text.'</span>';
		}
		elseif($type==='warning'){
			$text=$pre.'<span style="color:orange">'.$text.'</span>';
		}
		elseif($type==='error'){
			$text=$pre.'<span style="color:pink">'.$text.'</span>';
		}
		elseif($type==='fatal'){
			log_error('Tracelog fatal: '.$class.'/'.$function.'(): '.$text);
			$text=$pre.'<span style="color:red">'.$text.'</span>';
		}
		self::$tracelog??='';
		$log='<br><b>'.$tracelog_time.'ms, '.core::convert_storage_unit($memory).' ▸ </b> <i><span title="'.$file.'">'.basename($file).'</span>:'.$line.':</i> > <b>'.$text.'</b>';
		if(is_null($retroactive_time)){
			self::$tracelog.=$log;
		}
		else
		{
			self::$tracelog=$log.self::$tracelog;
		}
		if(self::$plotting===true){
			$backtrace=debug_backtrace();
			$filtered_trace=array_filter($backtrace, function($trace){
				return !in_array($trace['function'], [
					'tracelog', 
					'dataphyre\tracelog::tracelog', 
					'include', 
					'include_once', 
					'require', 
					'require_once'
				]);
			});
			$processed_trace=[];
			foreach($filtered_trace as $trace){
				$entry=[
					'file'=>$trace['file']??'N/A',
					'line'=>$trace['line']??'N/A',
					'function'=>$trace['function']??'N/A',
					'class'=>$trace['class']??'N/A',
					'type'=>$trace['type']??'N/A',
					'args'=>$trace['args']??[],
					'time'=>$tracelog_time
				];
				array_walk($entry['args'], function(&$arg){
					if(is_array($arg))$arg='Array';
					elseif(is_object($arg))$arg=get_class($arg);
					elseif(is_null($arg))$arg='NULL';
					elseif(is_bool($arg))$arg=$arg?'TRUE':'FALSE';
					else $arg=(string)$arg;
				});
				$processed_trace[]=$entry;
			}
			$file_path=ROOTPATH['dataphyre'].'cache/tracelog_plotting.dat';
			$json_trace=json_encode($processed_trace);
			core::file_put_contents_forced($file_path, $json_trace . PHP_EOL, FILE_APPEND);
		}
		return true;
	}
	
}
