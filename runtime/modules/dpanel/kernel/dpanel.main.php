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

/**
 * Runs Dataphyre module diagnostics and captures diagnostic evidence.
 *
 * Dpanel coordinates verbose findings, tracelog buffering, module entrypoint
 * validation/loading, JSON unit-test execution, dynamic test generation,
 * diagnostic-class hooks, dependency-following scans, and memory safety
 * gates used during local and diagnostic-mode runtime checks.
 */
class dpanel{

	private static $verbose=[];
	private static $catched_tracelog=[];
	private static array $diagnosing_modules=[];
	private static array $unit_test_signatures=[];
	private static int $unit_test_duplicate_count=0;
	private static array $unit_test_duplicate_examples=[];
	private static bool $core_diagnostics_bootstrapped=false;
	public static bool $run_unit_tests=true;
	public static bool $load_module_entrypoints=true;
	public static bool $follow_dependency_diagnostics=true;
	public static bool $bootstrap_core_before_module=true;

	/**
	 * Appends diagnostic findings to the in-memory verbose buffer.
	 *
	 * Empty input is ignored so callers can pass optional finding collections
	 * without clearing existing diagnostics.
	 *
	 * @param ?array<int, array<string, mixed>> $verboses Findings to append.
	 * @return void
	 */
	public static function add_verbose(?array $verboses): void {
		if(!empty($verboses))self::$verbose=array_merge(self::$verbose, $verboses);
	}

	/**
	 * Returns buffered diagnostic findings.
	 *
	 * The buffer is cleared by default after reading, matching the diagnostic
	 * panel's consume-on-render behavior.
	 *
	 * @param bool $clear Whether to clear the verbose buffer after reading.
	 * @return array<int, array<string, mixed>> Buffered diagnostic findings.
	 */
	public static function get_verbose(bool $clear=true) : array {
		$result=self::$verbose;
		if($clear)self::$verbose=[];
		return $result ?? [];
	}

	/**
	 * Captures tracelog events while diagnostics bypass the normal logger.
	 *
	 * Only events with string messages are stored; all context fields are kept as
	 * received so diagnostic output can preserve file, line, class, function, and
	 * argument evidence.
	 *
	 * @param mixed $filename Source filename.
	 * @param mixed $line Source line.
	 * @param mixed $class Source class.
	 * @param mixed $function Source function.
	 * @param mixed $text Log message.
	 * @param mixed $type Log level or type.
	 * @param mixed $arguments Captured call arguments.
	 * @return void
	 */
	public static function tracelog_bypass($filename=null, $line=null, $class=null, $function=null, $text=null, $type=null, $arguments=null): void {
		if(is_string($text)){
			self::$catched_tracelog[]=["file"=>$filename, "line"=>$line, "class"=>$class, "function"=>$function, "message"=>$text, "type"=>$type, "arguments"=>$arguments];
		}
	}
	
	/**
	 * Returns buffered tracelog entries captured during diagnostics.
	 *
	 * @param bool $clear Whether to clear the tracelog buffer after reading.
	 * @return array<int, array<string, mixed>> Captured tracelog entries.
	 */
	public static function get_tracelog(bool $clear=true): array {
		$result=self::$catched_tracelog;
		if($clear)self::$catched_tracelog=[];
		return $result;
	}

	/**
	 * Checks whether diagnostic work is close to the configured memory limit.
	 *
	 * A fixed reserve is held back so diagnostics can report a skip rather than
	 * causing the request to exhaust memory.
	 *
	 * @return bool True when memory usage has reached the safety threshold.
	 */
	private static function memory_near_limit(): bool {
		$limit=self::memory_limit_bytes();
		if($limit<=0){
			return false;
		}
		$reserve=8 * 1024 * 1024;
		$threshold=max(1024 * 1024, $limit - $reserve);
		return memory_get_usage(true)>=$threshold;
	}

	/**
	 * Parses PHP's active memory_limit ini value into bytes.
	 *
	 * @return int Memory limit in bytes, or -1 for unlimited/disabled limits.
	 */
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

	/**
	 * Formats a byte count for diagnostic messages.
	 *
	 * @param int $bytes Byte count, or negative for unlimited memory.
	 * @return string Human-readable memory label.
	 */
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

	/**
	 * Records that unit-test execution was stopped for memory safety.
	 *
	 * @param string $file Unit-test definition file.
	 * @param ?array<string, mixed> $test_case Test case being processed, when available.
	 * @return void
	 */
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

	/**
	 * Resolves declarative values embedded in JSON test arguments.
	 *
	 * A fixture references committed PHP by file and callable name. JSON never
	 * contains executable PHP, while module-owned builders can still produce
	 * dynamic values such as signed challenges.
	 */
	private static function resolve_unit_test_value(mixed $value, string $json_file_path, array $test_case, string $field): mixed {
		if(is_array($value) && array_key_exists('custom_script', $value)){
			throw new \InvalidArgumentException("Dpanel JSON field {$field} uses unsupported executable PHP; use a declarative fixture or assertion.");
		}
		if(is_array($value) && array_key_exists('fixture', $value) && count($value)===1){
			return self::resolve_unit_test_fixture($value['fixture'], $json_file_path, $test_case, $field);
		}
		if(is_array($value)){
			foreach($value as $key=>$child){
				$value[$key]=self::resolve_unit_test_value($child, $json_file_path, $test_case, $field);
			}
		}
		return $value;
	}

	/** Executes a committed fixture factory declared by a JSON argument. */
	private static function resolve_unit_test_fixture(mixed $definition, string $json_file_path, array $test_case, string $field): mixed {
		if(is_string($definition)){
			$definition=['call'=>$definition];
		}
		if(!is_array($definition) || trim((string)($definition['call'] ?? ''))===''){
			throw new \InvalidArgumentException("Dpanel JSON field {$field} contains an invalid fixture definition.");
		}
		$allowed=['call', 'args', 'file'];
		$unknown=array_diff(array_keys($definition), $allowed);
		if($unknown!==[]){
			throw new \InvalidArgumentException('Unknown Dpanel fixture option: '.implode(', ', $unknown).'.');
		}
		$file=$definition['file'] ?? ($test_case['file'] ?? null);
		if($file!==null){
			$fixture_file=self::resolve_unit_test_file_definition($file);
			if($fixture_file==='' || !is_readable($fixture_file)){
				throw new \RuntimeException('Dpanel fixture file is unavailable: '.(is_scalar($file) ? (string)$file : json_encode($file)).'.');
			}
			include_once $fixture_file;
		}
		$call=(string)$definition['call'];
		if(!is_callable($call)){
			throw new \RuntimeException("Dpanel fixture callable is unavailable: {$call}.");
		}
		$args=self::resolve_unit_test_value($definition['args'] ?? [], $json_file_path, $test_case, $field.'.fixture.args');
		if(!is_array($args) || !self::array_is_list_compatible($args)){
			throw new \InvalidArgumentException('Dpanel fixture args must be a JSON list.');
		}
		return call_user_func_array($call, $args);
	}

	/** Resolves a static, runtime-relative, or module-entrypoint file definition. */
	private static function resolve_unit_test_file_definition(mixed $definition): string {
		if(is_string($definition)){
			return self::resolve_unit_test_case_file($definition);
		}
		if(!is_array($definition) || count($definition)!==1){
			throw new \InvalidArgumentException('Dpanel test file must be a path or one declarative resolver.');
		}
		if(array_key_exists('module', $definition)){
			$module=strtolower(trim((string)$definition['module']));
			if($module==='' || preg_match('/^[a-z][a-z0-9_]*$/', $module)!==1){
				throw new \InvalidArgumentException('Dpanel module file resolver contains an invalid module name.');
			}
			$files=dp_module_present($module);
			$file=is_array($files) ? reset($files) : null;
			return is_string($file) ? self::resolve_unit_test_case_file($file) : '';
		}
		if(array_key_exists('runtime', $definition)){
			$relative=ltrim(str_replace('\\', '/', trim((string)$definition['runtime'])), '/');
			if($relative==='' || preg_match('#(^|/)\.\.(/|$)#', $relative)===1 || str_contains($relative, "\0")){
				throw new \InvalidArgumentException('Dpanel runtime file resolver contains an unsafe path.');
			}
			$root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\');
			return $root==='' ? '' : self::resolve_unit_test_case_file($root.'/'.$relative);
		}
		throw new \InvalidArgumentException('Unknown Dpanel test file resolver: '.implode(', ', array_keys($definition)).'.');
	}

	/**
	 * Matches one result against the closed JSON assertion vocabulary.
	 *
	 * Every key is conjunctive. Nested `paths` assertions use slash-separated
	 * segments, so keys containing dots remain unambiguous.
	 *
	 * @param array<string,mixed> $assertion
	 */
	private static function unit_test_assertion_matches(mixed $actual, array $assertion, bool $exists=true): bool {
		$allowed=['exists', 'type', 'same', 'not_same', 'not_empty', 'matches', 'contains', 'not_contains', 'count', 'keys', 'paths', 'some', 'compare'];
		$unknown=array_diff(array_keys($assertion), $allowed);
		if($unknown!==[]){
			throw new \InvalidArgumentException('Unknown Dpanel assertion operator: '.implode(', ', $unknown).'.');
		}
		if(array_key_exists('exists', $assertion) && $exists!==((bool)$assertion['exists'])){
			return false;
		}
		if(!$exists){
			return array_diff(array_keys($assertion), ['exists'])===[];
		}
		if(array_key_exists('type', $assertion) && !self::unit_test_assertion_type_matches($actual, (string)$assertion['type'])){
			return false;
		}
		if(array_key_exists('same', $assertion) && $actual!==$assertion['same']){
			return false;
		}
		if(array_key_exists('not_same', $assertion) && $actual===$assertion['not_same']){
			return false;
		}
		if(($assertion['not_empty'] ?? false)===true && empty($actual)){
			return false;
		}
		if(array_key_exists('matches', $assertion) && (!is_string($actual) || @preg_match((string)$assertion['matches'], $actual)!==1)){
			return false;
		}
		foreach(['contains'=>true, 'not_contains'=>false] as $operator=>$should_contain){
			if(!array_key_exists($operator, $assertion)){
				continue;
			}
			$needles=is_array($assertion[$operator]) && self::array_is_list_compatible($assertion[$operator]) ? $assertion[$operator] : [$assertion[$operator]];
			foreach($needles as $needle){
				$contains=is_array($actual) ? in_array($needle, $actual, true) : (is_string($actual) && is_string($needle) && str_contains($actual, $needle));
				if($contains!==$should_contain){
					return false;
				}
			}
		}
		if(array_key_exists('count', $assertion)){
			if(!is_countable($actual)){
				return false;
			}
			$count=count($actual);
			$constraint=$assertion['count'];
			if(is_int($constraint) && $count!==$constraint){
				return false;
			}
			if(is_array($constraint)){
				$unknown_count_options=array_diff(array_keys($constraint), ['min', 'max', 'same']);
				if($constraint===[] || $unknown_count_options!==[]){
					throw new \InvalidArgumentException('Dpanel count assertion accepts only min, max, and same.');
				}
				if(isset($constraint['min']) && $count<(int)$constraint['min']){
					return false;
				}
				if(isset($constraint['max']) && $count>(int)$constraint['max']){
					return false;
				}
				if(isset($constraint['same']) && $count!==(int)$constraint['same']){
					return false;
				}
			}
			elseif(!is_int($constraint)){
				throw new \InvalidArgumentException('Dpanel count assertion must be an integer or min/max/same map.');
			}
		}
		if(array_key_exists('keys', $assertion) && (!is_array($actual) || !is_array($assertion['keys']) || array_keys($actual)!==array_values($assertion['keys']))){
			return false;
		}
		if(array_key_exists('paths', $assertion)){
			if(!is_array($assertion['paths'])){
				throw new \InvalidArgumentException('Dpanel paths assertion must be a map.');
			}
			foreach($assertion['paths'] as $path=>$child){
				if(!is_array($child)){
					throw new \InvalidArgumentException('Each Dpanel path assertion must be an operator map.');
				}
				$path_exists=false;
				$value=self::unit_test_assertion_path($actual, (string)$path, $path_exists);
				if(!self::unit_test_assertion_matches($value, $child, $path_exists)){
					return false;
				}
			}
		}
		if(array_key_exists('some', $assertion)){
			if(!is_array($actual) || !is_array($assertion['some'])){
				return false;
			}
			$matched=false;
			foreach($actual as $item){
				if(self::unit_test_assertion_matches($item, $assertion['some'])){
					$matched=true;
					break;
				}
			}
			if(!$matched){
				return false;
			}
		}
		if(array_key_exists('compare', $assertion) && !self::unit_test_assertion_comparisons_match($actual, $assertion['compare'])){
			return false;
		}
		return true;
	}

	private static function unit_test_assertion_type_matches(mixed $actual, string $type): bool {
		$type=trim($type);
		$aliases=['int'=>'integer', 'float'=>'double', 'bool'=>'boolean'];
		$type=$aliases[$type] ?? $type;
		if(in_array($type, ['integer', 'double', 'boolean', 'string', 'array', 'object', 'NULL', 'resource'], true)){
			return gettype($actual)===$type;
		}
		return $type!=='' && class_exists($type) && $actual instanceof $type;
	}

	private static function unit_test_assertion_path(mixed $actual, string $path, bool &$exists): mixed {
		$exists=true;
		if($path==='' || $path==='$'){
			return $actual;
		}
		foreach(explode('/', trim($path, '/')) as $segment){
			$segment=str_replace(['~1', '~0'], ['/', '~'], $segment);
			if(is_array($actual) && array_key_exists($segment, $actual)){
				$actual=$actual[$segment];
				continue;
			}
			if(is_object($actual) && property_exists($actual, $segment)){
				$actual=$actual->{$segment};
				continue;
			}
			$exists=false;
			return null;
		}
		return $actual;
	}

	private static function unit_test_assertion_comparisons_match(mixed $actual, mixed $comparisons): bool {
		if(!is_array($comparisons)){
			throw new \InvalidArgumentException('Dpanel compare assertion must be a comparison or list of comparisons.');
		}
		if(!self::array_is_list_compatible($comparisons)){
			$comparisons=[$comparisons];
		}
		foreach($comparisons as $comparison){
			if(!is_array($comparison) || !isset($comparison['left'], $comparison['operator'], $comparison['right'])){
				throw new \InvalidArgumentException('Dpanel comparison requires left, operator, and right paths.');
			}
			$left_exists=$right_exists=false;
			$left=self::unit_test_assertion_path($actual, (string)$comparison['left'], $left_exists);
			$right=self::unit_test_assertion_path($actual, (string)$comparison['right'], $right_exists);
			if(!$left_exists || !$right_exists){
				return false;
			}
			$matches=match((string)$comparison['operator']){
				'greater_than'=>$left>$right,
				'greater_than_or_equal'=>$left>=$right,
				'less_than'=>$left<$right,
				'less_than_or_equal'=>$left<=$right,
				'same'=>$left===$right,
				'not_same'=>$left!==$right,
				default=>throw new \InvalidArgumentException('Unknown Dpanel comparison operator: '.(string)$comparison['operator'].'.'),
			};
			if(!$matches){
				return false;
			}
		}
		return true;
	}

	/**
	 * Converts a value into the structural type shape used by dynamic tests.
	 *
	 * Arrays are represented as `['array', unique_element_shapes]`; objects use
	 * their class name; scalars use stable primitive labels. This lets generated
	 * tests assert return shapes without storing full runtime values.
	 *
	 * @param mixed $value Runtime value to describe.
	 * @return mixed scalar type label, object class name, null label, boolean literal, or nested array shape.
	 */
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
	
	/**
	 * Builds the stable signature used to store a dynamic unit-test shape.
	 *
	 * @param mixed $type_shape Type-shape descriptor from `get_type_shape()`.
	 * @return string MD5 signature of the serialized shape.
	 */
	public static function get_type_shape_signature(mixed $type_shape): string {
		return md5(serialize($type_shape));
	}
	
	/**
	 * Persists a dynamic unit-test definition from an observed function call.
	 *
	 * When no return value is supplied, the callable is invoked to infer the
	 * return shape. A per-shape JSON file is written once and a companion metadata
	 * file tracks call count and last observation time.
	 *
	 * @param ?string $file Source file where the call was observed.
	 * @param ?string $line Source line where the call was observed.
	 * @param ?string $class Class name for method calls.
	 * @param ?string $function Function or method name.
	 * @param ?array<int, mixed> $arguments Arguments used for the observed call.
	 * @param mixed $return_value Optional return value to shape without invoking the callable.
	 * @return void
	 */
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

	/**
	 * Executes a Dpanel JSON unit-test definition file.
	 *
	 * Test cases may target functions, static methods, or instance methods,
	 * declare dependencies, include optional fixture files, assert exact values,
	 * primitive types, class instances, regexes, ranges, generated type shapes,
	 * or closed declarative assertions, and enforce max execution time. Dynamic
	 * arguments use committed fixture callables rather than embedded PHP.
	 *
	 * @param string $json_file_path Unit-test JSON definition path.
	 * @return bool True when every runnable test case passes.
	 */
	public static function unit_test(string $json_file_path): bool {
		if(!is_readable($json_file_path)){
			$json_file_path=self::resolve_unit_test_case_file($json_file_path);
		}
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
		$validate_array_structure=function(array $array, array $structure)use(&$validate_array_structure){
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
		$matches_expected=function($result, $expected, array $test_case=[])use($validate_array_structure, $json_file_path){
			if(is_array($expected) && array_key_exists('custom_script', $expected)){
				throw new \InvalidArgumentException('Dpanel JSON custom_script assertions are no longer supported; use the declarative assert vocabulary.');
			}
			if(is_array($expected) && array_key_exists('assert', $expected)){
				if(count($expected)!==1 || !is_array($expected['assert'])){
					throw new \InvalidArgumentException('A Dpanel declarative assertion must contain only an assert operator map.');
				}
				return self::unit_test_assertion_matches($result, $expected['assert']);
			}
			if(is_array($expected) && isset($expected['min'], $expected['max']) && is_numeric($result)){
				return $result>=$expected['min'] && $result<=$expected['max'];
			}
			if(is_string($expected) && strpos($expected, 'regex:')===0){
				$regex=substr($expected, 6);
				return is_string($result) && preg_match($regex, $result);
			}
			if(is_string($expected)){
				$type_aliases=[
					'int'=>'integer',
					'float'=>'double',
					'bool'=>'boolean',
					'string'=>'string',
					'array'=>'array',
					'object'=>'object',
				];
				if(isset($type_aliases[$expected])){
					return gettype($result)===$type_aliases[$expected];
				}
				if(in_array($expected, ['true', 'false', 'null'], true)){
					return self::get_type_shape($result)===$expected;
				}
				elseif(class_exists($expected)){
					return $result instanceof $expected;
				}
			}
			if(is_array($expected) && self::array_is_list_compatible($expected) && count($expected)>1 && $expected[0]==='array'){
				return is_array($result) && $validate_array_structure($result, $expected);
			}
			if((is_int($expected) || is_float($expected)) && (is_int($result) || is_float($result))){
				return (float)$result===(float)$expected;
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
				$args=self::resolve_unit_test_value($args, $json_file_path, $test_case, 'args');
				if(isset($test_case['server']) && is_array($test_case['server'])){
					foreach($test_case['server'] as $key=>$value){
						$_SERVER[(string)$key]=$value;
					}
					if(defined('REQUEST_USER_AGENT') && is_object(REQUEST_USER_AGENT) && method_exists(REQUEST_USER_AGENT, 'reset')){
						REQUEST_USER_AGENT->reset();
					}
					if(defined('REQUEST_IP_ADDRESS') && is_object(REQUEST_IP_ADDRESS) && method_exists(REQUEST_IP_ADDRESS, 'reset')){
						REQUEST_IP_ADDRESS->reset();
					}
				}
				if(isset($test_case['session']) && is_array($test_case['session'])){
					$_SESSION=array_replace_recursive($_SESSION ?? [], $test_case['session']);
				}
				if(isset($test_case['globals']) && is_array($test_case['globals'])){
					foreach($test_case['globals'] as $key=>$value){
						$GLOBALS[(string)$key]=$value;
					}
				}
				$test_case_file=null;
				if(isset($test_case['file'])){
					$test_case_file=self::resolve_unit_test_file_definition($test_case['file']);
				}
				elseif(array_key_exists('file_dynamic', $test_case)){
					throw new \InvalidArgumentException('Dpanel JSON file_dynamic is no longer supported; use file.module, file.runtime, or a static path.');
				}
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
				$expected_outcomes=[];
				if(isset($test_case['expected'])){
					$expected=$test_case['expected'];
					$is_array_shape=is_array($expected)
						&& self::array_is_list_compatible($expected)
						&& count($expected)>1
						&& $expected[0]==='array';
					$is_record_list=is_array($expected)
						&& self::array_is_list_compatible($expected)
						&& $expected!==[]
						&& count($expected)>1
						&& count(array_filter($expected, static fn($item): bool=>is_array($item) && !self::array_is_list_compatible($item) && !isset($item['assert']) && !(isset($item['min']) && isset($item['max']))))===count($expected);
					$expected_outcomes=is_array($expected) && self::array_is_list_compatible($expected) && $expected!==[] && $is_record_list!==true && $is_array_shape!==true
						? $expected
						: [$expected];
				}
				$failed_dependencies=false;
				foreach([
					"function"=>function(string $function)use($test_case): bool {
						self::ensure_unit_test_dependency('function', $function);
						if(function_exists($function)){
							return true;
						}
						if(str_contains($function, '::')){
							return self::unit_test_class_method_dependency_exists($function);
						}
						$class_name=(string)($test_case['class'] ?? '');
						if($class_name!=='' && is_callable([$class_name, $function])){
							return true;
						}
						return false;
					},
					"class"=>function(string $class): bool {
						self::ensure_unit_test_dependency('class', $class);
						return class_exists($class);
					},
					"constant"=>function(string $const): bool {
						self::ensure_unit_test_dependency('constant', $const);
						return defined($const);
					},
					"global_variable"=>function(string $var): bool {
						self::ensure_unit_test_dependency('global_variable', $var);
						return isset($GLOBALS[$var]) || defined($var);
					}
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
									'type'=>'unit_test_skip',
									'test_name'=>$test_case['name'],
									'level'=>'warning',
									'file'=>basename($json_file_path),
									'message'=>$fail_string,
									'passed'=>true,
								];
								$failed_dependencies=true;
								break 2; // break both loops since one failure is enough
							}
						}
					}
				}
				if($failed_dependencies===true){
					continue;
				}
				if(self::unit_test_seen($json_file_path, $test_case, $test_case_file)){
					continue;
				}
				if(isset($test_case['class']) && trim((string)$test_case['class'])!==''){
					$class_name=(string)$test_case['class'];
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
					if($function==='__construct'){
						$result=$instance ?? new $class_name();
						$execution_time=0.0;
						goto unit_test_result_ready;
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
					if(str_contains((string)$function, '::')){
						$callable=explode('::', (string)$function, 2);
						if(!is_callable($callable)){
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
						$result=call_user_func_array($callable, $args);
						$execution_time=microtime(true)-$start_time;
					}
					elseif(!function_exists($function)){
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
				unit_test_result_ready:
				$performance_grace_millis=PHP_OS_FAMILY==='Windows' ? 100.0 : 0.0;
				if(defined('DATAPHYRE_DPANEL_PERFORMANCE_GRACE_MILLIS')){
					$performance_grace_millis=max(0.0, min(1000.0, (float)DATAPHYRE_DPANEL_PERFORMANCE_GRACE_MILLIS));
				}
				$performance_limit_millis=isset($test_case['max_millis'])
					? max(0.0, (float)$test_case['max_millis']) + $performance_grace_millis
					: null;
				if($performance_limit_millis!==null && $execution_time>($performance_limit_millis/1000)){
					self::$verbose[]=[
                        'type'=>'performance_warning',
                        'test_name'=>$test_case['name'],
						'test_case_file'=>$test_case_file,
						'message'=>"Execution time exceeded max_millis threshold: {$execution_time}s (declared ".(float)$test_case['max_millis']."ms + {$performance_grace_millis}ms scheduler grace)",
						'level'=>'error',
						'execution_time'=>$execution_time,
						'file'=>basename($json_file_path),
						'passed'=>false
                    ];
					$all_passed=false;
                }
				$matched=false;
				foreach($expected_outcomes as $expected){
					if($matches_expected($result, $expected, $test_case)){
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

	/**
	 * Checks and records the canonical identity for a unit-test case.
	 *
	 * @param string $json_file_path Unit-test definition file.
	 * @param array<string, mixed> $test_case Test case being processed.
	 * @param mixed $test_case_file Resolved include file, when present.
	 * @return bool True when an equivalent test already ran during this scan.
	 */
	private static function unit_test_seen(string $json_file_path, array $test_case, mixed $test_case_file): bool {
		$signature=self::unit_test_signature($test_case, $test_case_file);
		if(isset(self::$unit_test_signatures[$signature])){
			self::$unit_test_duplicate_count++;
			if(count(self::$unit_test_duplicate_examples)<5){
				self::$unit_test_duplicate_examples[]=[
					'test_name'=>(string)($test_case['name'] ?? 'Unnamed test'),
					'file'=>basename($json_file_path),
					'first_file'=>self::$unit_test_signatures[$signature]['file'],
					'function'=>(string)($test_case['function'] ?? ''),
				];
			}
			return true;
		}
		self::$unit_test_signatures[$signature]=[
			'file'=>basename($json_file_path),
			'test_name'=>(string)($test_case['name'] ?? 'Unnamed test'),
		];
		return false;
	}

	/**
	 * Attempts to load the Dataphyre module that provides a declared dependency.
	 *
	 * The dependency declaration remains authoritative: if the symbol is still
	 * missing after this best-effort load, the test is skipped as before.
	 *
	 * @param string $type Dependency bucket from the unit-test manifest.
	 * @param string $name Declared dependency symbol.
	 * @return void
	 */
	private static function ensure_unit_test_dependency(string $type, string $name): void {
		if(self::$load_module_entrypoints!==true){
			return;
		}
		$module='';
		$normalized=ltrim(strtolower(str_replace('/', '\\', $name)), '\\');
		if($type==='function' && ($normalized==='locale' || str_starts_with($normalized, 'dataphyre\\localization'))){
			$module='localization';
		}
		if($module===''){
			return;
		}
		$previous_run_unit_tests=self::$run_unit_tests;
		try{
			self::$run_unit_tests=false;
			self::diagnose_module($module);
			self::get_verbose();
		}
		catch(\Throwable){
		}
		finally{
			self::$run_unit_tests=$previous_run_unit_tests;
		}
	}

	private static function unit_test_class_method_dependency_exists(string $name): bool {
		[$class, $method]=explode('::', $name, 2);
		$legacy_sql_globals=[
			'db_insert'=>'sql_insert',
			'db_select'=>'sql_select',
			'db_update'=>'sql_update',
			'db_delete'=>'sql_delete',
		];
		if(strtolower($class)==='sql' && isset($legacy_sql_globals[$method])){
			return function_exists($legacy_sql_globals[$method]);
		}
		foreach([$class, __NAMESPACE__.'\\'.ltrim($class, '\\')] as $candidate){
			if(is_callable([$candidate, $method])){
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds a stable identity for a unit test's behavior.
	 *
	 * The display name and manifest filename are intentionally excluded so
	 * duplicate generated tests collapse when they exercise the same callable
	 * with the same fixture, arguments, expectations, and dependency gates.
	 *
	 * @param array<string, mixed> $test_case Test case being processed.
	 * @param mixed $test_case_file Resolved include file, when present.
	 * @return string Stable hash for duplicate detection.
	 */
	private static function unit_test_signature(array $test_case, mixed $test_case_file): string {
		$identity=[
			'function'=>$test_case['function'] ?? null,
			'class'=>$test_case['class'] ?? null,
			'static_method'=>(bool)($test_case['static_method'] ?? false),
			'args'=>$test_case['args'] ?? [],
			'expected'=>$test_case['expected'] ?? null,
			'auto'=>(bool)($test_case['auto'] ?? false),
			'dependencies'=>$test_case['dependencies'] ?? [],
			'file'=>is_string($test_case_file) ? str_replace('\\', '/', $test_case_file) : null,
		];
		return hash('sha256', (string)json_encode(self::canonicalize_unit_test_value($identity), JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Sorts associative arrays so semantically identical JSON hashes equally.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed Canonical value.
	 */
	private static function canonicalize_unit_test_value(mixed $value): mixed {
		if(!is_array($value)){
			return $value;
		}
		foreach($value as $key=>$child){
			$value[$key]=self::canonicalize_unit_test_value($child);
		}
		if(self::array_is_list_compatible($value)){
			return $value;
		}
		ksort($value);
		return $value;
	}

	/**
	 * Compatibility wrapper for PHP runtimes without `array_is_list()`.
	 *
	 * @param array<mixed> $value Array to inspect.
	 * @return bool True when keys are a zero-based sequence.
	 */
	private static function array_is_list_compatible(array $value): bool {
		if(function_exists('array_is_list')){
			return array_is_list($value);
		}
		$index=0;
		foreach($value as $key=>$_){
			if($key!==$index++){
				return false;
			}
		}
		return true;
	}

	/**
	 * Clears per-scan duplicate tracking.
	 *
	 * @return void
	 */
	private static function reset_unit_test_dedupe(): void {
		self::$unit_test_signatures=[];
		self::$unit_test_duplicate_count=0;
		self::$unit_test_duplicate_examples=[];
	}

	/**
	 * Emits a duplicate-collapse summary for the current scan.
	 *
	 * @param string $module Module whose tests were scanned.
	 * @return void
	 */
	private static function report_unit_test_dedupe(string $module): void {
		if(self::$unit_test_duplicate_count<=0){
			return;
		}
		self::$verbose[]=[
			'type'=>'unit_test',
			'level'=>'info',
			'module'=>$module,
			'message'=>'Collapsed '.self::$unit_test_duplicate_count.' duplicate unit test'.(self::$unit_test_duplicate_count===1 ? '' : 's').' for module '.$module.'.',
			'duplicates'=>self::$unit_test_duplicate_count,
			'examples'=>self::$unit_test_duplicate_examples,
			'passed'=>true,
		];
	}

	/**
	 * Resolves a JSON unit-test fixture include path against the correct root.
	 *
	 * Runtime manifests use app-root paths for application fixtures and
	 * rootpaths-defined shared paths for fixtures such as `/dataphyre/...`.
	 * `ROOTPATH['root']` may point at the active application, so shared paths
	 * resolve through their dedicated ROOTPATH entries instead.
	 *
	 * @param string $file Unit-test manifest file value.
	 * @return string Resolved include path.
	 */
	private static function resolve_unit_test_case_file(string $file): string {
		$normalized=str_replace('\\', '/', trim($file));
		if($normalized===''){
			return $normalized;
		}
		foreach([
			'dataphyre/runtime/'=>'common_dataphyre_runtime',
			'dataphyre/'=>'common_dataphyre',
			'common/dataphyre/runtime/'=>'common_dataphyre_runtime',
			'common/dataphyre/'=>'common_dataphyre',
			'common/'=>'common_root',
			'applications/'=>'applications',
		] as $prefix=>$root_key){
			$marker='/'.$prefix;
			$position=strpos($normalized, $marker);
			if($position!==false && !empty(ROOTPATH[$root_key])){
				$relative=substr($normalized, $position + strlen($marker));
				if($prefix==='common/'){
					$relative='common/'.$relative;
				}
				return rtrim((string)ROOTPATH[$root_key], '/\\').'/'.$relative;
			}
		}
		if(preg_match('#^[A-Za-z]:/#', $normalized)===1 || str_starts_with($normalized, '//')){
			return $file;
		}
		$relative=ltrim($normalized, '/');
		if(str_starts_with($relative, 'dataphyre/runtime/')){
			return rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/'.substr($relative, strlen('dataphyre/runtime/'));
		}
		if(str_starts_with($relative, 'dataphyre/')){
			return rtrim((string)(ROOTPATH['common_dataphyre'] ?? ''), '/\\').'/'.substr($relative, strlen('dataphyre/'));
		}
		if(str_starts_with($relative, 'common/dataphyre/runtime/')){
			return rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/'.substr($relative, strlen('common/dataphyre/runtime/'));
		}
		if(str_starts_with($relative, 'common/dataphyre/')){
			return rtrim((string)(ROOTPATH['common_dataphyre'] ?? ''), '/\\').'/'.substr($relative, strlen('common/dataphyre/'));
		}
		if(str_starts_with($relative, 'common/') && !empty(ROOTPATH['common_root'])){
			return rtrim((string)ROOTPATH['common_root'], '/\\').'/'.$relative;
		}
		if(str_starts_with($relative, 'applications/') && !empty(ROOTPATH['applications'])){
			return rtrim((string)ROOTPATH['applications'], '/\\').'/'.substr($relative, strlen('applications/'));
		}
		return rtrim((string)(ROOTPATH['root'] ?? ''), '/\\').'/'.ltrim($normalized, '/');
	}

	/**
	 * Diagnoses one Dataphyre module entrypoint and its optional diagnostics.
	 *
	 * The diagnostic flow validates PHP syntax, optionally loads the module,
	 * captures tracelog output, runs static and dynamic JSON unit tests, follows
	 * core dependency diagnostics, and invokes module diagnostic classes. A
	 * reentrancy guard prevents dependency cycles from recursing forever.
	 *
	 * @param string $module Module name to diagnose.
	 * @return bool True when the module is present and all required diagnostics pass.
	 */
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
			if(true!==$validation=self::validate_php($content)){
				self::$verbose[]=[
					'type'=>'php_validation_error', 
					'level'=>'error',
					'module'=>$module, 
					'error'=>$validation, 
				];
				return false;
			}
			try{
				if(self::$load_module_entrypoints===true){
					require_once($module_path);
					if(!empty($tracelog=self::get_tracelog())){
						self::$verbose[]=[
							'type'=>'tracelog',
							'module'=>$module,
							'tracelog'=>$tracelog,
						];
					}
				}
				if(self::$run_unit_tests!==true){
					return true;
				}
				$test_files=[];
				$module_root=self::module_root_from_entrypoint($module_path);
				$unit_test_dir=$module_root.'/unit_tests';
				if(is_dir($unit_test_dir)){
					$test_files=array_merge($test_files, glob($unit_test_dir . '/*.json'));
				}
				$test_files=array_values(array_filter($test_files, static function(string $file): bool {
					$basename=basename($file);
					return !str_starts_with($basename, 'dpanel_mock_') && $basename!=='unit_test.json';
				}));
				$dynamic_test_dir=ROOTPATH['dataphyre'].'unit_tests/dynamic/';
				$dynamic_test_files=array_values(array_filter(
					glob($dynamic_test_dir.'dataphyre\\'.$module.'.*/*.json') ?: [],
					static fn($file)=>!str_ends_with((string)$file, '.meta.json')
				));
				$test_files=array_merge($test_files, $dynamic_test_files);
				if(empty($test_files)) return true;
				$all_tests_passed=true;
				self::reset_unit_test_dedupe();
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
				self::report_unit_test_dedupe($module);
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
		if(self::$bootstrap_core_before_module===true && !defined("DP_CORE_LOADED") && class_exists(__NAMESPACE__.'\core', false)!==true && self::$load_module_entrypoints===true && self::$core_diagnostics_bootstrapped!==true){
			if(!$procedure('core', ROOTPATH['common_dataphyre_runtime'].'modules/core/kernel/core.main.php')){
				return false;
			}
			self::$core_diagnostics_bootstrapped=true;
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
		if(!is_array($module_present)){
			$module_present=self::resolve_module_entrypoint($module);
		}
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

	/**
	 * Loads and executes a module's `.diagnostic.php` companion file when present.
	 *
	 * @param string $module Module name.
	 * @param string $module_path Resolved module entrypoint path.
	 * @return void
	 */
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

	/**
	 * Resolves a module root directory from a module entrypoint path.
	 *
	 * @param string $module_path Module entrypoint path.
	 * @return string Module root directory.
	 */
	private static function module_root_from_entrypoint(string $module_path): string {
		$directory=dirname($module_path);
		return basename($directory)==='kernel' ? dirname($directory) : $directory;
	}

	/**
	 * Resolves a module entrypoint without relying on helper-function cache state.
	 *
	 * Application modules are checked before common runtime modules, and both
	 * kernel and root-level `.main.php` layouts are supported.
	 *
	 * @param string $module Module name.
	 * @return array{0: string, 1: string}|false Entrypoint path and version, or false when not found.
	 */
	private static function resolve_module_entrypoint(string $module): array|bool {
		if(!defined('ROOTPATH')){
			return false;
		}
		foreach(['dataphyre', 'common_dataphyre_runtime'] as $root_key){
			$root=(string)(ROOTPATH[$root_key] ?? '');
			if($root===''){
				continue;
			}
			$module_root=rtrim($root, '/\\').'/modules/'.$module;
			$candidates=[
				$module_root.'/kernel/'.$module.'.main.php',
				$module_root.'/'.$module.'.main.php',
			];
			foreach($candidates as $candidate){
				if(is_file($candidate)){
					$version_file=$module_root.'/version';
					return [$candidate, is_file($version_file) ? trim((string)file_get_contents($version_file)) : '1.0'];
				}
			}
		}
		return false;
	}

	/**
	 * Invokes the diagnostic hooks exposed by a module diagnostic class.
	 *
	 * Core diagnostics use `pre_tests()` and `post_tests()` hooks. Other modules
	 * use a single `tests()` hook when present.
	 *
	 * @param string $diagnostic_class Fully qualified diagnostic class name.
	 * @param string $module Module name.
	 * @return void
	 */
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

	/**
	 * Validates PHP source with the tokenizer parser.
	 *
	 * Display errors are enabled only during parsing so syntax exceptions include
	 * useful messages, then the previous ini value is restored.
	 *
	 * @param string $code PHP source code.
	 * @return bool|string True when parsing succeeds, or a line-adjusted parser error.
	 */
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

	/**
	 * Ensures core module helper functions are available for diagnostics.
	 *
	 * @return bool True when `dp_module_present()` is callable after loading helpers.
	 */
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

	/**
	 * Recursively diagnoses every `.main.php` module found under a folder.
	 *
	 * Module names are inferred from entrypoint filenames after normalizing path
	 * separators relative to the scanned root.
	 *
	 * @param string $folder Folder containing module trees.
	 * @return void
	 */
	public static function diagnose_modules_in_folder(string $folder): void {
		$iterator=new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)
		);
		foreach($iterator as $file){
			$path=$file->getPathname();
			if(substr($path, -9)==='.main.php'){
				$relative_path=substr($path, strlen($folder) + 1); // remove base folder
				$relative_path=str_replace(['/', '\\'], '/', $relative_path); // unify slashes
				$module_name=basename($relative_path, '.main.php'); // get filename without .main.php
				self::diagnose_module($module_name);
			}
		}
	}

}
