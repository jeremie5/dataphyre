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

if(file_exists($filepath=ROOTPATH['common_dataphyre']."config/tracelog.php")){
	require_once($filepath);
}
if(file_exists($filepath=ROOTPATH['dataphyre']."config/tracelog.php")){
	require_once($filepath);
}

register_shutdown_function(function(){
	if(tracelog::$defer){
		tracelog::$defer=false;
		tracelog::process_retroactive();
	}
	$_SESSION['tracelog']=tracelog::$tracelog;
});

new tracelog();

if(defined('TRACELOG_BOOT_ENABLE')){
	tracelog::$enable=true;	
	if(defined('TRACELOG_BOOT_ENABLE_PLOTTING')){
		tracelog::setPlotting(true);
	}
	tracelog::process_retroactive();
}

class tracelog {
	
	public static $tracelog='';
	public static $constructed=false;
	public static $enable=false;
	public static $open=false;
	public static $file=false;
	public static $profiling=false;
    public static $plotting=false;
    public static $defer=true;
    
	public function __construct(){
		self::$constructed=true;
		self::set_handler();
	}
	
	public static function process_retroactive(): void {
		global $retroactive_tracelog;
		if(isset($retroactive_tracelog) && is_array($retroactive_tracelog)){
			if(self::$enable===true){
				foreach(array_reverse($retroactive_tracelog) as $log){
					if(is_string($log)){
						self::$tracelog=$log.self::$tracelog;
					}
					else
					{
						self::tracelog(...$log);
					}
				}
			}
		}
		unset($retroactive_tracelog);
	}
	
	public static function buffer_callback(mixed $buffer): mixed {
		if(self::$open===true){
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
				return $buffer."<script>window.open('".core::url_self()."dataphyre/tracelog/plotter', '_blank', 'width=1000, height=1000');</script>";
			}
			return $buffer."<script>window.open('".core::url_self()."dataphyre/tracelog?log=".tracelog::$file."', '_blank', 'width=1000, height=1000');</script>";
		}
		return $buffer;
	}
	
    public static function setPlotting($value){
        if(self::$plotting!==$value){
            self::$plotting=$value;
            if($value) @unlink(ROOTPATH['dataphyre'].'tracelog/plotting.dat');
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
	  * Save tracelog to session variable and or file
	  *
	  * @version 	1.0.6
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  *
	  * @param ?string $directory
	  * @param ?string $filename_full
	  * @param ?string $line
	  * @param ?string $class
	  * @param ?string $function
	  * @param ?string $text
	  * @param ?string $type
	  * @param ?array $arguments
	  * @param ?float $time
	  * @return bool											True on success, false on failure
	  */
	public static function tracelog(?string $filename_full, ?string $line, ?string $class, ?string $function, ?string $text, ?string $type="info", ?array $arguments=null, ?float $retroactive_time=null, ?int $retroactive_memory=null) : bool {
		if(self::$enable===false) return false;
		if(self::$defer===true){
			$GLOBALS['retroactive_tracelog'][]=[$filename_full, $line, $class, $function, $text, $type, $arguments, microtime(true), memory_get_usage()];
			return true;
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
				$text='<span style="color:#85f1ff;">Function call:</span> <span style="color:'.$function_colors[$function].'">'.$function.'('.$text.')</span>';
			}
			else
			{
				$pre='<span style="color:'.$function_colors[$function].'">'.$function.'():</span> ';
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
		$log='<br><b>'.$tracelog_time.'ms, '.core::convert_storage_unit($memory).' ▸ </b> <i><span title="'.$filename_full.'">'.basename($filename_full).'</span>:'.$line.':</i> > <b>'.$text.'</b>';
		if(is_null($retroactive_time)){
			self::$tracelog.=$log;
		}
		else
		{
			self::$tracelog=$log.self::$tracelog;
		}
		if(self::$file!==false){
			if(self::$file===true){
				foreach(glob(ROOTPATH['dataphyre']."logs/*", GLOB_ONLYDIR) as $folder){
					if(strtotime(basename($folder))<=strtotime('-'.$GLOBALS['configurations']['dataphyre']['tracelog']['file_lifespan'].' hours')){
						core::force_rmdir(ROOTPATH['dataphyre']."logs/".basename($folder));
					}
				}
				if(false===realpath($tracelog_folder=ROOTPATH['dataphyre']."logs/".date("Y-m-d H:00", time()))){
					mkdir($tracelog_folder);
				}
				self::$file=$tracelog_folder."/".date("H:i:s", time())."_".$type.".html";
				fwrite($file=fopen(self::$file, "w"), self::$tracelog.PHP_EOL);
				fclose($file);
				return true;
			}
			fwrite($file=fopen(self::$file, "a"), $log.PHP_EOL);
			fclose($file);
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
			$file_path=ROOTPATH['dataphyre'].'tracelog/plotting.dat';
			$json_trace=json_encode($processed_trace);
			core::file_put_contents_forced($file_path, $json_trace . PHP_EOL, FILE_APPEND);
		}
		if(self::$profiling===true){
			static $timings=[];
			$function_string=$function;
			if(empty($function))$function_string='global';
			$signature=null;
			if(!empty($arguments))$signature=md5(json_encode($arguments));
			if($last_function_signature!==$signature){
				$last_function_signature=$signature;
				$timings[$function_string][$signature]=$time;
			}
			$current_date=date('Y-m-d H:i');
			$dir_path=ROOTPATH['dataphyre'].'tracelog/profiling/'.$function_string.'/';
			if(!is_dir($dir_path))mkdir($dir_path, 0777, true);
			$events_file_path=$dir_path.'/'.$current_date.'.json';
			$log_entry=[
				'type'=>$type,
				'sig'=>$signature,
				'text'=>$text
			];
			if(isset($timings[$function][$signature]))$log_entry['timing']=$time-$timings[$function][$signature];
			core::file_put_contents_forced($events_file_path, json_encode($log_entry).PHP_EOL, FILE_APPEND);
		}
		return true;
	}
	
}