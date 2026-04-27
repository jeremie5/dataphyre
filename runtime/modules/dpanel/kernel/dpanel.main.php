<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

if(!defined('RUN_MODE')){
	define('RUN_MODE', 'diagnostic');
}

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

class dpanel{

	private static $verbose=[];
	private static $catched_tracelog=[];
	private static array $diagnosing_modules=[];
	public static bool $run_unit_tests=true;
	public static bool $load_module_entrypoints=true;
	public static bool $follow_dependency_diagnostics=true;

	public static function add_verbose(?array $verboses): void {
		if(!empty($verboses))self::$verbose=array_merge(self::$verbose, $verboses);
	}

	public static function get_verbose(bool $clear=true) : array {
		$result=self::$verbose;
		if($clear)self::$verbose=[];
		return $result ?? [];
	}

	public static function tracelog_bypass($filename=null, $line=null, $class=null, $function=null, $text=null, $type=null, $arguments=null): void {
		if(is_string($text)){
			self::$catched_tracelog[]=["file"=>$filename, "line"=>$line, "class"=>$class, "function"=>$function, "message"=>$text, "type"=>$type, "arguments"=>$arguments];
		}
	}
	
	public static function get_tracelog(bool $clear=true): array {
		$result=self::$catched_tracelog;
		if($clear)self::$catched_tracelog=[];
		return $result;
	}

	private static function memory_near_limit(): bool {
		$limit=self::memory_limit_bytes();
		if($limit<=0){
			return false;
		}
		$reserve=8 * 1024 * 1024;
		$threshold=max(1024 * 1024, $limit - $reserve);
		return memory_get_usage(true)>=$threshold;
	}

	private static function memory_limit_bytes(): int {
		$value=trim((string)ini_get('memory_limit'));
		if($value==='' || $value==='-1'){
			return -1;
		}
		$unit=strtolower(substr($value, -1));
		$number=(float)$value;
		return match($unit){
			'g'=>(int)($number * 1073741824),
			'm'=>(int)($number * 1048576),
			'k'=>(int)($number * 1024),
			default=>(int)$number,
		};
	}

	private static function memory_label(int $bytes): string {
		if($bytes<0){
			return 'unlimited';
		}
		if($bytes>=1048576){
			return round($bytes / 1048576, 2).' MB';
		}
		if($bytes>=1024){
			return round($bytes / 1024, 2).' KB';
		}
		return $bytes.' B';
	}

	private static function add_memory_skip(string $file, ?array $test_case=null): void {
		$limit=self::memory_limit_bytes();
		self::$verbose[]=[
			'type'=>'unit_test',
			'test_name'=>(string)($test_case['name'] ?? 'Unknown'),
			'file'=>basename($file),
			'message'=>'Skipped remaining unit-test work because diagnostic memory usage is near the active limit: '.self::memory_label(memory_get_usage(true)).' / '.self::memory_label($limit).'.',
			'level'=>'warning',
			'passed'=>false,
		];
	}

	public static function get_type_shape(mixed $value): mixed {
		if(is_array($value)){
			$types=[];
			foreach($value as $item){
				$shape=self::get_type_shape($item);
				$types[]=is_array($shape) ? json_encode($shape) : $shape;
			}
			ksort($types);
			$types=array_values(array_unique($types));
			return ['array', $types];
		}
		if(is_object($value)) return get_class($value);
		if(is_null($value)) return 'null';
		if(is_bool($value)) return json_encode($value); 
		if(is_string($value)) return 'string';
		if(is_int($value)) return 'int';
		if(is_float($value)) return 'float';
		return 'unknown';
	}
	
	public static function get_type_shape_signature(mixed $type_shape): string {
		return md5(serialize($type_shape));
	}
	
	public static function generate_dynamic_unit_test(?string $file=null, ?string $line=null, ?string $class=null, ?string $function=null, ?array $arguments=null, mixed $return_value=null) : void {
		if($return_value===null){
			try{
				$callable=!empty($class) ? [$class, $function] : $function;
				if(is_callable($callable)){
					$return_value=call_user_func_array($callable, $arguments);
				}
			}catch(\Throwable $e){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Failed to call $function for return value inference: ".$e->getMessage(), "warning");
				return;
			}
		}
		if(empty($function) || !isset($arguments) || $return_value===null) return;
		$type_shape=self::get_type_shape($return_value);
		$signature=self::get_type_shape_signature($type_shape);
		$filepath=ROOTPATH['dataphyre']."unit_tests/dynamic/{$class}.{$function}/{$signature}.json";
		$test=[
			'name'=>"Dynamic_{$signature}",
			'class'=>$class,
			'function'=>$function,
			'args'=>$arguments,
			'expected'=>$type_shape,
			'auto'=>true,
			'line'=>$line,
			'file'=>$file,
		];
		$meta_path=ROOTPATH['dataphyre']."unit_tests/dynamic/{$class}.{$function}/{$signature}.meta.json";
		$meta=is_file($meta_path) ? json_decode(file_get_contents($meta_path), true) : [];
		$meta['calls']=($meta['calls'] ?? 0)+1;
		$meta['last_called_at']=date('c');
		core::file_put_contents_forced($meta_path, json_encode($meta, JSON_PRETTY_PRINT));
		if(file_exists($filepath)) return;
		core::file_put_contents_forced($filepath, json_encode([$test], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	}

	public static function unit_test(string $json_file_path): bool {
		if(self::$run_unit_tests!==true){
			self::$verbose[]=[
				'type'=>'unit_test',
				'file'=>basename($json_file_path),
				'message'=>'Unit test skipped because this diagnostic scan disabled unit-test execution.',
				'level'=>'info',
				'passed'=>true,
			];
			return true;
		}
		$all_passed=true;
		if(false===$json_content=file_get_contents($json_file_path)){
			self::$verbose[]=[
				'type'=>'unit_test',
				'file'=>$json_file_path,
				'message'=>"JSON file unreadable.",
				'level'=>'error',
				'passed'=>false,
			];
			return false;
		}
		if(empty($json_content)) return false;
		$test_definitions=json_decode($json_content, true);
		if(null===$test_definitions || json_last_error() !== JSON_ERROR_NONE){
			self::$verbose[]=[
				'type'=>'unit_test',
				'file'=>$json_file_path,
				'message'=>"Invalid JSON format : ".json_last_error_msg().PHP_EOL.$json_file_path,
				'level'=>'error',
				'passed'=>false,
			];
			return false;
		}
		$validate_array_structure=function($array, $structure)use(&$validate_array_structure){
			if(!is_array($array) || !is_array($structure) || $structure[0] !== 'array'){
				return false;
			}
			$element_types=$structure[1];
			foreach($array as $element){
				$element_matched=false;
				foreach($element_types as $type){
					if(is_array($type) && is_array($element)){
						if($validate_array_structure($element, $type)){
							$element_matched=true;
							break;
						}
					}
					elseif(gettype($element)===$type ||(is_object($element) && $element instanceof $type)){
						$element_matched=true;
						break;
					}
				}
				if(!$element_matched)return false;
			}
			return true;
		};
		$matches_expected=function($result, $expected)use($validate_array_structure){
			if(is_array($expected) && isset($expected['custom_script'])){
				return eval($expected['custom_script']);
			}
			if(is_array($expected) && isset($expected['min'], $expected['max']) && is_numeric($result)){
				return $result>=$expected['min'] && $result<=$expected['max'];
			}
			if(is_string($expected) && strpos($expected, 'regex:')===0){
				$regex=substr($expected, 6);
				return is_string($result) && preg_match($regex, $result);
			}
			if(is_string($expected)){
				if(in_array($expected, ['int', 'float', 'string', 'bool', 'array', 'object'])){
					return gettype($result)===$expected;
				}
				elseif(class_exists($expected)){
					return $result instanceof $expected;
				}
			}
			if(is_array($expected) && count($expected)>1 && $expected[0]==='array'){
				return is_array($result) && $validate_array_structure($result, $expected);
			}
			return $result===$expected;
		};
		foreach($test_definitions as $test_case){
			if(self::memory_near_limit()){
				self::add_memory_skip($json_file_path, is_array($test_case) ? $test_case : null);
				$all_passed=false;
				break;
			}
			try{
				if(!isset($test_case['function'], $test_case['args']) || (!isset($test_case['expected']) && empty($test_case['auto'])===false)){
					self::$verbose[]=[
						'type'=>'unit_test',
						'function'=>$function,
						'test_name'=>$test_case['name'],
						'message'=>"Invalid test case structure.",
						'file'=>basename($json_file_path),
						'level'=>'error',
						'passed'=>false,
					];
					$all_passed=false;
					continue;
				}
				$function=$test_case['function'];
				$args=$test_case['args'];
				$expected_outcomes=isset($test_case['expected']) ? (is_array($test_case['expected']) ? $test_case['expected'] : [$test_case['expected']]) : [];
				$failed_dependencies=false;
				foreach([
					"function"=>"function_exists", 
					"class"=>"class_exists", 
					"constant"=>fn($const)=>defined($const),
					"global_variable"=>fn($var)=>isset($GLOBALS[$var])
				] as $dependency=>$dependency_function){
					if(isset($test_case['dependencies'][$dependency]) && is_array($test_case['dependencies'][$dependency])){
						foreach($test_case['dependencies'][$dependency] as $dependency_element){
							if(is_array($dependency_element)){
								$keys=array_keys($dependency_element);
								$dependency_element_name=$keys[0];
								$fail_string=$dependency_element[$dependency_element_name];
							}
							else
							{
								$dependency_element_name=$dependency_element;
								$fail_string="Function for unit test \"$function\" is dependant upon the $dependency \"$dependency_element_name\" which is not initialized.";
							}
							if(!call_user_func($dependency_function, $dependency_element_name)){
								self::$verbose[]=[
									'type'=>'unit_test',
									'test_name'=>$test_case['name'],
									'level'=>'error',
									'file'=>basename($json_file_path),
									'message'=>$fail_string,
									'passed'=>false,
								];
								$failed_dependencies=true;
								break 2; // break both loops since one failure is enough
							}
						}
					}
				}
				if($failed_dependencies===true){
					$all_passed=false;
					continue;
				}
				$test_case_file=isset($test_case['file']) ? ROOTPATH['root'].$test_case['file'] :(isset($test_case['file_dynamic']) ? eval($test_case['file_dynamic']) : null);
				if(!empty($test_case_file)){
					if(!$test_case_file || !is_string($test_case_file) || !is_readable($test_case_file)){
						self::$verbose[]=[
							'type'=>'unit_test',
							'test_name'=>$test_case['name'],
							'test_case_file'=>(string)$test_case_file,
							'message'=>"Test case file not found or unreadable: $test_case_file.",
							'level'=>'error',
							'file'=>basename($json_file_path),
							'passed'=>false,
						];
						$all_passed=false;
						continue;
					}
					include_once($test_case_file);
				}
				if(isset($test_case['class'])){
					$class_name=$test_case['class'];
					if(!class_exists($class_name)){
						self::$verbose[]=[
							'type'=>'unit_test',
							'function'=>$function,
							'test_name'=>$test_case['name'],
							'test_case_file'=>$test_case_file,
							'message'=>"Class $class_name does not exist in $test_case_file",
							'level'=>'error',
							'input'=>json_encode($args),
							'file'=>basename($json_file_path),
							'passed'=>false,
						];
						$all_passed=false;
						continue;
					}
					$instance=null;
					if($test_case['static_method'] ?? false){
						$callable=[$class_name, $function];
					}
					else
					{
						if(self::memory_near_limit()){
							self::add_memory_skip($json_file_path, $test_case);
							$all_passed=false;
							break;
						}
						$instance=new $class_name();
						$callable=[$instance, $function];
					}
					if(!is_callable($callable)){
						self::$verbose[]=[
							'type'=>'unit_test',
							'function'=>$function,
							'test_name'=>$test_case['name'],
							'test_case_file'=>$test_case_file,
							'message'=>"Method $function does not exist in class $class_name",
							'level'=>'error',
							'input'=>json_encode($args),
							'file'=>basename($json_file_path),
							'passed'=>false,
						];
						$all_passed=false;
						continue;
					}
					$start_time=microtime(true);
					$result=call_user_func_array($callable, $args);
					$execution_time=microtime(true)-$start_time;
				}
				else
				{
					if(!function_exists($function)){
						self::$verbose[]=[
							'type'=>'unit_test',
							'function'=>$function,
							'test_name'=>$test_case['name'],
							'test_case_file'=>$test_case_file,
							'message'=>"Function $function does not exist in $test_case_file",
							'level'=>'error',
							'input'=>json_encode($args),
							'file'=>basename($json_file_path),
							'passed'=>false,
						];
						$all_passed=false;
						continue;
					}
					$start_time=microtime(true);
					$result=call_user_func_array($function, $args);
					$execution_time=microtime(true)-$start_time;
				}
                if(isset($test_case['max_millis']) && $execution_time>($test_case['max_millis']/1000)){
                    self::$verbose[]=[
                        'type'=>'performance_warning',
                        'test_name'=>$test_case['name'],
						'test_case_file'=>$test_case_file,
                        'message'=>"Execution time exceeded max_millis threshold: {$execution_time}s",
						'level'=>'error',
						'execution_time'=>$execution_time,
						'file'=>basename($json_file_path),
						'passed'=>false
                    ];
					$all_passed=false;
                }
				$matched=false;
				foreach($expected_outcomes as $expected){
					if($matches_expected($result, $expected)){
						$matched=true;
						break;
					}
				}
				if(!$matched){
					self::$verbose[]=[
						'type'=>'unit_test',
						'function'=>$function,
						'test_name'=>$test_case['name'],
						'test_case_file'=>$test_case_file,
						'message'=>"Unit test \"".$test_case['name']."\" expected one of ".json_encode($expected_outcomes)." but got ".json_encode($result),
						'level'=>'error',
						'execution_time'=>$execution_time,
						'file'=>basename($json_file_path),
						'passed'=>false,
					];
					$all_passed=false;
					continue;
				}
				else
				{
					self::$verbose[]=[
						'type'=>'unit_test',
						'function'=>$function,
						'test_name'=>$test_case['name'],
						'test_case_file'=>$test_case_file,
						'execution_time'=>$execution_time,
						'file'=>basename($json_file_path),
						'message'=>'Unit test "'.$test_case['name'].'" for function "'.$function.'" passed in '.number_format($execution_time, 8).'s',
						'passed'=>true,
					];
				}
			}catch(\Throwable $e){
				self::$verbose[]=[
					'type'=>'unit_test',
					'function'=>$function ?? 'Unknown',
					'test_name'=>$test_case['name'] ?? 'Unknown',
					'message'=>$e->getMessage(),
					'line'=>$e->getLine(),
					'file'=>basename($json_file_path),
					'level'=>'error',
					'passed'=>false,
				];
				$all_passed=false;
				continue;
			}
		}
		return $all_passed;
	}

	public static function diagnose_module(string $module): bool {
		if(isset(self::$diagnosing_modules[$module])){
			return true;
		}
		self::$diagnosing_modules[$module]=true;
		try{
		$procedure=function(string $module, string $module_path){
			if(false===$content=file_get_contents($module_path)){
				self::$verbose[]=[
					'type'=>'file_missing', 
					'level'=>'error',
					'module'=>$module, 
					'file'=>$module_path, 
				];
				return false;
			}
			if(false===$validation=self::validate_php($content)){
				self::$verbose[]=[
					'type'=>'php_validation_error', 
					'level'=>'error',
					'module'=>$module, 
					'error'=>$validation, 
				];
				return false;
			}
			try{
				if(self::$load_module_entrypoints!==true){
					return true;
				}
				require_once($module_path);
				if(!empty($tracelog=self::get_tracelog())){
					self::$verbose[]=[
						'type'=>'tracelog', 
						'module'=>$module, 
						'tracelog'=>$tracelog,
					];
				}
				if(self::$run_unit_tests!==true){
					return true;
				}
				$test_files=[];
				$unit_test_dir=dirname($module_path).'/unit_tests';
				if(is_dir($unit_test_dir)){
					$test_files=array_merge($test_files, glob($unit_test_dir . '/*.json'));
				}
				$dynamic_test_dir=ROOTPATH['dataphyre'].'unit_tests/dynamic/';
				$dynamic_test_files=glob($dynamic_test_dir.'dataphyre\\'.$module.'.*/*.json');
				$test_files=array_merge($test_files, $dynamic_test_files);
				if(empty($test_files)) return true;
				$all_tests_passed=true;
				usort($test_files, function($a, $b){
					$a_has_construct=stripos($a, 'construct')!==false;
					$b_has_construct=stripos($b, 'construct')!==false;
					return $a_has_construct===$b_has_construct ? 0 : ($a_has_construct ? -1 : 1);
				});
				foreach($test_files as $json_file){
					if(false===$passed=self::unit_test($json_file)){
						$all_tests_passed=false;
					}
				}
				if(!$all_tests_passed){
					self::$verbose[]=[
						'type'=>'unit_test', 
						'module'=>$module, 
						'message'=>'Unit tests failed for module '.$module, 
						'level'=>'error',
					];
					return false;
				}
				else
				{
					self::$verbose[]=[
						'message'=>'Unit tests passed for module '.$module, 
						'module'=>$module, 
					];
				}
				return true;
			}catch(\Throwable $exception){
				self::$verbose[]=[
					'type'=>'php_exception', 
					'level'=>'error',
					'module'=>$module, 
					'exception'=>$exception, 
				];
				return false;
			}
		};
		if(!defined("DP_CORE_LOADED")){
			if(!$procedure('core', ROOTPATH['common_dataphyre_runtime'].'modules/core/kernel/core.main.php')){
				return false;
			}
		}
		if(self::ensure_module_helper_functions()!==true){
			self::$verbose[]=[
				'type'=>'module_lookup_unavailable',
				'level'=>'error',
				'module'=>$module,
				'message'=>'Core module helper functions are unavailable.',
			];
			return false;
		}
		$module_present=\dp_module_present($module);
		if(is_array($module_present) && !empty($module_present[0])){
			$module_path=(string)$module_present[0];
			$passed=self::$load_module_entrypoints!==true ? $procedure($module, $module_path) : true;
			if(self::$load_module_entrypoints===true && !in_array($module_path, get_included_files(), true)){
				$passed=$procedure($module, $module_path);
			}
			self::run_module_diagnostics($module, $module_path);
			return $passed;
		}
		else
		{
			self::$verbose[]=[
				'type'=>'module_missing', 
				'level'=>'error',
				'module'=>$module, 
			];	
		}
		return false;
		}
		finally{
			unset(self::$diagnosing_modules[$module]);
		}
	}

	private static function run_module_diagnostics(string $module, string $module_path): void {
		$diagnostic_path=dirname($module_path).'/'.str_replace('.main.php', '.diagnostic.php', basename($module_path));
		if(!is_file($diagnostic_path)){
			return;
		}
		$diagnostic_class='\\dataphyre\\'.$module.'\\diagnostic';
		try{
			if(class_exists($diagnostic_class, false)){
				self::run_diagnostic_class($diagnostic_class, $module);
				return;
			}
			require_once($diagnostic_path);
			if($module==='core' && class_exists($diagnostic_class, false)){
				self::run_diagnostic_class($diagnostic_class, $module);
			}
		}catch(\Throwable $exception){
			self::$verbose[]=[
				'type'=>'diagnostic_exception',
				'level'=>'error',
				'module'=>$module,
				'file'=>$diagnostic_path,
				'exception'=>$exception,
				'passed'=>false,
			];
		}
	}

	private static function run_diagnostic_class(string $diagnostic_class, string $module): void {
		if($module==='core'){
			if(method_exists($diagnostic_class, 'pre_tests')){
				$diagnostic_class::pre_tests();
			}
			if(method_exists($diagnostic_class, 'post_tests')){
				$diagnostic_class::post_tests();
			}
			return;
		}
		if(method_exists($diagnostic_class, 'tests')){
			$diagnostic_class::tests();
		}
	}

	public static function validate_php(string $code): bool|string {
		$old=ini_set('display_errors', 1);
		try{
			token_get_all("\n$code", TOKEN_PARSE);
		}
		catch(\Throwable $ex){
			$error=$ex->getMessage();
			$line=$ex->getLine()-1;
			$error="Line $line:\n\n$error";
		}
		finally{
			ini_set('display_errors', $old);
		}
		if(!empty($error)){
			return $error;
		}
		return true;
	}

	private static function ensure_module_helper_functions(): bool {
		if(function_exists('\dp_module_present')){
			return true;
		}
		$helper_path=ROOTPATH['common_dataphyre_runtime'].'modules/core/kernel/helper_functions.php';
		if(is_file($helper_path)){
			require_once($helper_path);
		}
		return function_exists('\dp_module_present');
	}

	public static function diagnose_modules_in_folder(string $folder): void {
		$iterator=new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)
		);
		foreach($iterator as $file){
			$path=$file->getPathname();
			if(substr($path, -9)==='.main.php'){
				$relativePath=substr($path, strlen($folder) + 1); // remove base folder
				$relativePath=str_replace(['/', '\\'], '/', $relativePath); // unify slashes
				$moduleName=basename($relativePath, '.main.php'); // get filename without .main.php
				self::diagnose_module($moduleName);
			}
		}
	}

}
