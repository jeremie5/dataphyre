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

if(RUN_MODE==='dpanel'){
	require(__DIR__.'/dpanel.diagnostic.php');
}

class dpanel{ 

	public static $catched_tracelog=['errors'=>'', 'info'=>''];
	private static $core_module_path;
	public static $external_verbose;

	function __construct(){
		global $rootpath;
		define("RUN_MODE", "diagnostic");
		require_once('tracelog_override.php');
		self::$core_module_path=$rootpath['common_dataphyre'].'/modules/core/core.main.php';
	}

	public static function unit_test(string $json_file_path, array &$verbose=[]): bool {
		global $roothpath;
		$all_passed=true;
		if(!file_exists($json_file_path)){
			throw new Exception("JSON file not found: $json_file_path");
		}
		$json_content=file_get_contents($json_file_path);
		$test_definitions=json_decode($json_content, true);
		if(json_last_error() !== JSON_ERROR_NONE){
			throw new Exception("Invalid JSON format: ".json_last_error_msg());
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
					$verbose[]=[
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
								$verbose[]=[
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
					$verbose[]=[
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
						$verbose[]=[
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
						$verbose[]=[
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
						$verbose[]=[
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
                    $verbose[]=[
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
					$verbose[]=[
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
					$verbose[]=[
						'type'=>'unit_test',
						'function'=>$function,
						'test_name'=>$test_case['name'],
						'test_case_file'=>$test_case_file,
						'execution_time'=>$execution_time,
						'passed'=>true,
					];
				}
			}catch(Exception $e){
				$verbose[]=[
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

	public static function diagnose_module(string $module, array &$verbose=[]): bool {
		global $rootpath;
		$procedure=function(string $module, string $module_path, array &$verbose, array $rootpath){
			if(false===$content=file_get_contents($module_path)){
				$verbose[]=['type'=>'file_missing', 'module'=>$module, 'file'=>$module_path, 'time'=>time()];
				return false;
			}
			if(false===$validation=self::validate_php($content)){
				$verbose[]=['type'=>'php_validation_error', 'module'=>$module, 'error'=>$validation, 'time'=>time()];
				return false;
			}
			try{
				require_once($module_path);
				$verbose=array_merge($verbose, $external_verbose);
				$verbose[]=['type'=>'tracelog', 'module'=>$module, 'tracelog'=>self::catch_tracelog(), 'time'=>time()];
				return true;
			}catch(\Throwable $exception){
				$verbose[]=['type'=>'php_exception', 'module'=>$module, 'exception'=>$exception, 'time'=>time()];
				return false;
			}
		};
		if(DP_CORE_LOADED){
			if(!$procedure('core', self::$core_module_path, $verbose, $rootpath)){
				return false;
			}
		}
		if($module_path=dp_module_present($module)[0]){
			if($procedure($module, $module_path, $verbose, $rootpath)){
				return true;
			}
		}
		else
		{
			$verbose[]=['type'=>'module_missing', 'module'=>$module, 'time'=>time()];	
		}
		return false;
	}

	public static function validate_php(string $code): bool|string {
		$old=ini_set('display_errors', 1);
		try{
			token_get_all("\n$code", TOKEN_PARSE);
		}
		catch(Throwable $ex){
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

	public static function catch_tracelog(bool $clear=true): string {
		$result=self::$catched_tracelog;
		if($clear){
			self::$catched_tracelog['errors']='';
			self::$catched_tracelog['info']='';
		}
		return $result;
	}

}