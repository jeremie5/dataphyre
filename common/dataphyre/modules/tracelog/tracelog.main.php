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

if(file_exists($filepath=$rootpath['common_dataphyre']."config/tracelog.php")){
	require_once($filepath);
}
if(file_exists($filepath=$rootpath['dataphyre']."config/tracelog.php")){
	require_once($filepath);
}
if(!isset($configurations['dataphyre']['tracelog'])){
	core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='Dataphyre: Tracelog: No configuration available', 'safemode');
}

register_shutdown_function(function(){
	$_SESSION['tracelog']=tracelog::$tracelog;
});

class tracelog {
	
	static $tracelog;
	static $enable=false;
	static $open=false;
	static $file=false;
	static $profiling=false;

    private static $plotting=false;
    
    public static function setPlotting($value){
        if(self::$plotting!==$value){
            global $rootpath;
            self::$plotting=$value;
            if($value){
                @unlink($rootpath['dataphyre'].'tracelog/plotting.dat');
            }
        }
    }

    public static function getPlotting(){
        return self::$plotting;
    }

	public function __construct(){
		if(session_status()===PHP_SESSION_NONE){
			session_start();
		}
		self::set_handler();
	}
	
	/**
	  * Catch PHP errors
	  *
	  * @version 	1.0.0
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  */
	private static function set_handler(){
		if(null!==$early_return=core::dialback('CALL_TRACELOG_SET_HANDLER',...func_get_args())) return $early_return;
		$handler=function($errno, $errstr, $errfile, $errline){
			if(null!==$early_return=core::dialback('CALL_TRACELOG_ERROR_FOUND',...func_get_args())) return $early_return;
			if($errno===E_ERROR || $errno===E_USER_ERROR){
				if(stristr($errstr, 'Allowed memory size')!==false){
					core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreTracelog: Maximum script memory limit exceeded.', 'safemode');
				}
				core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreTracelog: Fatal error during execution.', 'safemode');
				log_error(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreTracelog: Fatal error during execution : '.json_encode(func_get_args()));
			}
			if(self::$enable===true){
				$errstr=htmlspecialchars($errstr);
				self::$tracelog??='';
				self::$tracelog.='<br><table style="border: 1px solid white;"><tr><th style="color:red">Error</th><th style="color:red">File</th><th style="color:red">Line</th></tr><tr><td style="border: 1px solid white;">'.$errstr.'</td><td style="border: 1px solid white;">'.$errfile.'</td> <td style="border: 1px solid white;">'.$errline.'</td></tr></table>';
			}
			return true;
		};
		set_error_handler($handler, E_ALL);
	}
	
	/**
	  * Save tracelog to session variable and or file
	  *
	  * @version 	1.0.4
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  *
	  * @param string|null $directory
	  * @param string|null $filename_full
	  * @param string|null $line
	  * @param string|null $class
	  * @param string|null $function
	  * @param string|null $text
	  * @param string|null $type
	  * @param string|null $arguments
	  * @return bool											True on success, false on failure
	  */
	public static function tracelog(string|null $filename_full, string|null $line, string|null $class, string|null $function, string|null $text, string|null $type="info", array|null $arguments=null) : bool {
		global $rootpath;
		global $configurations;
		static $last_function_signature=null;
		static $timings=[];
		static $function_colors=[];
		if(self::$enable===false)return false;
		if(!empty($class))$function=$class.'::'.$function;
		$tracelog_time=number_format((microtime(true)-$_SERVER["REQUEST_TIME_FLOAT"])*1000, 3, '.');
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
			$filePath=$rootpath['dataphyre'].'tracelog/plotting.dat';
			$jsonTrace=json_encode($processed_trace);
			file_put_contents($filePath, $jsonTrace . PHP_EOL, FILE_APPEND);
		}
		if(self::$profiling===true){
			$function_string=$function;
			if(empty($function))$function_string='global';
			$signature=null;
			if(!empty($arguments))$signature=md5(json_encode($arguments));
			if($last_function_signature!==$signature){
				$last_function_signature=$signature;
				$timings[$function_string][$signature]=microtime(true);
			}
			$current_date=date('Y-m-d H:i');
			$dir_path=$rootpath['dataphyre'].'tracelog/profiling/'.$function_string.'/';
			if(!is_dir($dir_path))mkdir($dir_path, 0777, true);
			$events_file_path=$dir_path.'/'.$current_date.'.json';
			$log_entry=array('type'=>$type,'sig'=>$signature,'text'=>$text);
			if(isset($timings[$function][$signature]))$log_entry['timing']=microtime(true)-$timings[$function][$signature];
			file_put_contents($events_file_path, json_encode($log_entry).PHP_EOL, FILE_APPEND);
		}
		$pre=null;
		if(!empty($function)){
			if(is_array($arguments)){
				foreach($arguments as $key=>$value){
					if(is_array($value)){
						$arguments[$key]='Array';
					}
					elseif($value===true){
						$arguments[$key]='True';
					}
					elseif($value===false){
						$arguments[$key]='False';
					}
					elseif(is_null($value)){
						$arguments[$key]='Null';
					}
					elseif(is_string($value)){
						$arguments[$key]='"'.$value.'"';
					}
					elseif(is_object($value)){
						$arguments[$key]='Object';
					}
					elseif(is_callable($value)){
						$arguments[$key]='Callable';
					}
					elseif(is_integer($value)){
						$arguments[$key]='Integer('.$value.')';
					}
					else
					{
						$arguments[$key]='N/A';
					}
				}
				$text=implode(',', $arguments);
				$text=htmlentities($text);
			}
			if(!isset($function_colors[$function]))$function_colors[$function]=core::random_hex_color();
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
		elseif($type==='fatal'){
			log_error('Tracelog fatal: '.$class.'/'.$function.'(): '.$text);
			$text=$pre.'<span style="color:red">'.$text.'</span>';
		}
		$filename=basename($filename_full);
		$log='<br><b>'.$tracelog_time.'ms ▸ </b> <i><span title="'.$filename_full.'">'.$filename.'</span>:'.$line.':</i> > <b>'.$text.'</b>';
		self::$tracelog??='';
		self::$tracelog.=$log;
		if(self::$file!==false){
			if(self::$file===true){
				foreach(glob($rootpath['dataphyre']."logs/*", GLOB_ONLYDIR) as $folder){
					if(strtotime(basename($folder))<=strtotime('-'.$configurations['dataphyre']['tracelog']['file_lifespan'].' hours')){
						core::force_rmdir($rootpath['dataphyre']."logs/".basename($folder));
					}
				}
				$tracelog_folder=$rootpath['dataphyre']."logs/".date("Y-m-d H:00", time());
				if(false===realpath($tracelog_folder))mkdir($tracelog_folder);
				self::$file=$tracelog_folder."/".date("H:i:s", time())."_".$type.".html";
				$file=fopen(self::$file, "w");
				fwrite($file, self::$tracelog.PHP_EOL);
				fclose($file);
				return true;
			}
			$file=fopen(self::$file, "a");
			fwrite($file, $log.PHP_EOL);
			fclose($file);
		}
		return true;
	}
	
}