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

define("RUN_MODE", "diagnostic");

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

class dpanel{ 

	private static $verbose=[];
	private static $catched_tracelog=[];

	public static function add_verbose(?array $verboses) : void {
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

	public static function unit_test(string $json_file_path): bool {
		
		$all_passed=true;
		if(!is_readable($json_file_path) || false===$json_content=file_get_contents($json_file_path) || empty($json_content)){
			self::$verbose[]=[
				'type'=>'unit_test',
				'file'=>$json_file_path,
				'fail_string'=>"JSON file unreadable.",
				'passed'=>false,
			];
			return false;
		}
		$test_definitions=json_decode($json_content, true);
		if(null===$test_definitions || json_last_error() !== JSON_ERROR_NONE){
			self::$verbose[]=[
				'type'=>'unit_test',
				'file'=>$json_file_path,
				'fail_string'=>"Invalid JSON format : ".json_last_error_msg().PHP_EOL.$json_file_path,
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
			try{
				if(!isset($test_case['function'], $test_case['args'], $test_case['expected'])){
					self::$verbose[]=[
						'type'=>'unit_test',
						'function'=>$function,
						'test_name'=>$test_case['name'],
						'fail_string'=>"Invalid test case structure.",
						'passed'=>false,
					];
					$all_passed=false;
					continue;
				}
				$function=$test_case['function'];
				$args=$test_case['args'];
				$expected_outcomes=is_array($test_case['expected']) ? $test_case['expected'] : [$test_case['expected']];
				foreach([
					"function"=>"function_exists", 
					"class"=>"class_exists", 
					"constant"=>function($const) { return defined($const); },
					"global_variable"=>function($var) { return isset($GLOBALS[$var]); }
					] as $dependency=>$dependency_function
				){		
					if(is_array($test_case['dependencies'][$dependency])){
						foreach($test_case['dependencies'][$dependency] as $dependency_element){
							if(is_array($dependency_element)){
								$dependency_element=array_keys($dependency_element)[0];
								$fail_string=array_values($dependency_element)[0];
							}
							else
							{
								$fail_string="Function for unit test $function is dependant upon the $dependency $dependency_element which is not initialized.";
							}
							if(!call_user_func($dependency_function, $dependency_element)){
								self::$verbose[]=[
									'type'=>'unit_test',
									'test_name'=>$test_case['name'],
									'fail_string'=>$fail_string,
									'passed'=>false,
								];
								$failed_dependencies=true;
								break;
							}
						}
					}
				}
				if($failed_dependencies===true){
					$all_passed=false;
					continue;
				}
				$test_case_file=isset($test_case['file']) ? $roothpath['root'].$test_case['file'] : (isset($test_case['file_dynamic']) ? eval($test_case['file_dynamic']) : null);
				if(!$test_case_file || !is_readable($test_case_file)){
					self::$verbose[]=[
						'type'=>'unit_test',
						'test_name'=>$test_case['name'],
						'test_case_file'=>$test_case_file,
						'fail_string'=>"Test case file not found or unreadable: $test_case_file.",
						'passed'=>false,
					];
					$all_passed=false;
					continue;
				}
				include_once($test_case_file);
				if(isset($test_case['class'])){
					$class_name=$test_case['class'];
					if(!class_exists($class_name)){
						self::$verbose[]=[
							'type'=>'unit_test',
							'function'=>$function,
							'test_name'=>$test_case['name'],
							'test_case_file'=>$test_case_file,
							'fail_string'=>"Class $class_name does not exist in $test_case_file",
							'input'=>json_encode($args),
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
						$instance=new $class_name();
						$callable=[$instance, $function];
					}
					if(!is_callable($callable)){
						self::$verbose[]=[
							'type'=>'unit_test',
							'function'=>$function,
							'test_name'=>$test_case['name'],
							'test_case_file'=>$test_case_file,
							'fail_string'=>"Method $function does not exist in class $class_name",
							'input'=>json_encode($args),
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
							'fail_string'=>"Function $function does not exist in $test_case_file",
							'input'=>json_encode($args),
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
                        'warning_string'=>"Execution time exceeded max_millis threshold: {$execution_time}s",
						'execution_time'=>$execution_time,
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
						'fail_string'=>"Unit test ".$test_case['name']."expected one of ".json_encode($expected_outcomes)." but got ".json_encode($result),
						'execution_time'=>$execution_time,
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
						'passed'=>true,
					];
				}
			}catch(\Throwable $e){
				self::$verbose[]=[
					'type'=>'unit_test',
					'function'=>$function ?? 'Unknown',
					'test_name'=>$test_case['name'] ?? 'Unknown',
					'fail_string'=>$e->getMessage(),
					'line'=>$e->getLine(),
					'error'=>'unexpected_error',
					'passed'=>false,
				];
				$all_passed=false;
				continue;
			}
		}
		return $all_passed;
	}

	public static function diagnose_module(string $module): bool {
	
		$procedure=function(string $module, string $module_path, array ROOTPATH){
			if(false===$content=file_get_contents($module_path)){
				self::$verbose[]=[
					'type'=>'file_missing', 
					'module'=>$module, 
					'file'=>$module_path, 
				];
				return false;
			}
			if(false===$validation=self::validate_php($content)){
				self::$verbose[]=[
					'type'=>'php_validation_error', 
					'module'=>$module, 
					'error'=>$validation, 
				];
				return false;
			}
			try{
				require_once($module_path);
				if(!empty($tracelog=self::get_tracelog())){
					self::$verbose[]=[
						'type'=>'tracelog', 
						'module'=>$module, 
						'tracelog'=>$tracelog,
					];
				}
				$unit_test_dir=dirname($module_path).'/unit_tests';
				if(is_dir($unit_test_dir)){
					$test_files=glob($unit_test_dir . '/*.json');
					$all_tests_passed=true;
					foreach($test_files as $json_file){
						$passed=self::unit_test($json_file);
						if(!$passed){
							$all_tests_passed=false;
						}
					}
					if(!$all_tests_passed){
						self::$verbose[]=[
							'type'=>'unit_test_failed', 
							'module'=>$module, 
						];
						return false;
					}
				}
				else
				{
					self::$verbose[]=[
						'type'=>'unit_test_skipped', 
						'reason'=>'unit_tests folder missing', 
						'folder'=>$unit_test_dir, 
						'module'=>$module, 
					];
				}
				return true;
			}catch(\Throwable $exception){
				self::$verbose[]=[
					'type'=>'php_exception', 
					'module'=>$module, 
					'exception'=>$exception, 
				];
				return false;
			}
		};
		if(!defined("DP_CORE_LOADED")){
			if(!$procedure('core', ROOTPATH['common_dataphyre'].'modules/core/core.main.php', ROOTPATH)){
				return false;
			}
		}
		if($module_path=dp_module_present($module)[0]){
			if(!in_array($module_path, get_included_files())){
				if($procedure($module, $module_path, ROOTPATH)){
					return true;
				}
			}
		}
		else
		{
			self::$verbose[]=[
				'type'=>'module_missing', 
				'module'=>$module, 
			];	
		}
		return false;
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