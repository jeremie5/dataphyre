<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

$payload_path=(string)($argv[1] ?? '');
$result_written=false;
$started_at=microtime(true);

/**
 * Writes the worker result once.
 *
 * @param array<string,mixed> $payload Result payload.
 * @return void
 */
$write_result=function(array $payload)use(&$result_written, $payload_path, $started_at): void {
	if($result_written===true){
		return;
	}
	$result_written=true;
	$payload['duration_seconds']=microtime(true)-$started_at;
	$output_path='';
	if($payload_path!=='' && is_file($payload_path)){
		$input=json_decode((string)file_get_contents($payload_path), true);
		if(is_array($input)){
			$output_path=(string)($input['output_path'] ?? '');
			$module=(string)($input['module'] ?? '');
			$manifest_path=(string)($input['manifest_path'] ?? '');
			$case_index=isset($input['case_index']) ? (int)$input['case_index'] : -1;
			if($module!=='' && isset($payload['trace']) && is_array($payload['trace'])){
				foreach($payload['trace'] as $index=>$entry){
					if(is_array($entry) && !isset($entry['module'])){
						$payload['trace'][$index]['module']=$module;
					}
					if(is_array($entry) && $manifest_path!=='' && $case_index>=0){
						$payload['trace'][$index]['manifest']=$payload['trace'][$index]['manifest'] ?? basename($manifest_path);
						$payload['trace'][$index]['case_index']=$payload['trace'][$index]['case_index'] ?? $case_index;
					}
				}
			}
		}
	}
	if($output_path!==''){
		@file_put_contents($output_path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	}
	else
	{
		echo json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
	}
};

register_shutdown_function(function()use(&$result_written, $write_result): void {
	if($result_written===true){
		return;
	}
	$error=error_get_last();
	$fatal_types=[E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
	if(is_array($error) && in_array((int)($error['type'] ?? 0), $fatal_types, true)){
		$write_result([
			'passed'=>false,
			'trace'=>[[
				'type'=>'unit_test_worker',
				'level'=>'error',
				'message'=>(string)($error['message'] ?? 'Worker terminated with a fatal PHP error.'),
				'file'=>(string)($error['file'] ?? ''),
				'line'=>(int)($error['line'] ?? 0),
				'passed'=>false,
			]],
			'output'=>substr((string)ob_get_contents(), -8192),
		]);
		return;
	}
	$trace=[];
	if(class_exists('\dataphyre\dpanel', false)){
		try{
			$trace=\dataphyre\dpanel::get_verbose(false);
		}catch(\Throwable){
			$trace=[];
		}
	}
	$trace=is_array($trace) ? dataphyre_dpanel_worker_sanitize($trace) : [];
	$trace[]=[
		'type'=>'unit_test_worker',
		'level'=>'error',
		'message'=>'Worker exited before returning a final diagnostic result. A module entrypoint or unit test likely ended the process.',
		'passed'=>false,
	];
	$write_result([
		'passed'=>false,
		'trace'=>$trace,
		'output'=>substr((string)ob_get_contents(), -8192),
	]);
});

ob_start();

if($payload_path==='' || !is_file($payload_path)){
	$write_result([
		'passed'=>false,
		'trace'=>[[
			'type'=>'unit_test_worker',
			'level'=>'error',
			'message'=>'Worker payload is missing or unreadable.',
			'passed'=>false,
		]],
	]);
	exit(1);
}

$payload=json_decode((string)file_get_contents($payload_path), true);
if(!is_array($payload)){
	$write_result([
		'passed'=>false,
		'trace'=>[[
			'type'=>'unit_test_worker',
			'level'=>'error',
			'message'=>'Worker payload JSON is invalid.',
			'passed'=>false,
		]],
	]);
	exit(1);
}

$timeout=(int)($payload['timeout_seconds'] ?? 8);
if($timeout>0){
	@set_time_limit($timeout + 2);
}
$memory_limit=(string)($payload['memory_limit'] ?? '256M');
if($memory_limit!==''){
	@ini_set('memory_limit', $memory_limit);
	putenv('DATAPHYRE_MEMORY_LIMIT='.$memory_limit);
}

$rootpath=$payload['rootpath'] ?? [];
if(!is_array($rootpath)){
	$write_result([
		'passed'=>false,
		'trace'=>[[
			'type'=>'unit_test_worker',
			'level'=>'error',
			'message'=>'Worker payload did not include a rootpath map.',
			'passed'=>false,
		]],
	]);
	exit(1);
}
if(!defined('ROOTPATH')){
	define('ROOTPATH', $rootpath);
}
if(!defined('RUN_MODE')){
	define('RUN_MODE', 'diagnostic');
}
if(!defined('BS_VERSION')){
	define('BS_VERSION', '2.0');
}
if(!defined('IS_PRODUCTION')){
	define('IS_PRODUCTION', false);
}
if(!defined('CPU_USAGE')){
	define('CPU_USAGE', 0);
}
if(!defined('INITIAL_MEMORY_USAGE')){
	define('INITIAL_MEMORY_USAGE', memory_get_usage());
}
if(!isset($_SESSION) || !is_array($_SESSION)){
	$_SESSION=[];
}
if(!isset($GLOBALS['configurations']) || !is_array($GLOBALS['configurations'])){
	$GLOBALS['configurations']=[];
}
$_SERVER['REQUEST_METHOD']=$_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI']=$_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['SCRIPT_NAME']=$_SERVER['SCRIPT_NAME'] ?? '/index.php';
$_SERVER['PHP_SELF']=$_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME']=$_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
$_SERVER['HTTP_HOST']=$_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_ADDR']=$_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
$_SERVER['REMOTE_ADDR']=$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['HTTP_USER_AGENT']=$_SERVER['HTTP_USER_AGENT'] ?? 'Dataphyre diagnostic worker';
if(!function_exists('tracelog')){
	function tracelog(...$arguments): void {}
}
if(!function_exists('mb_strtoupper')){
	function mb_strtoupper(string $string, ?string $encoding=null): string {
		return strtoupper($string);
	}
}
if(!function_exists('mb_strtolower')){
	function mb_strtolower(string $string, ?string $encoding=null): string {
		return strtolower($string);
	}
}
if(!function_exists('mb_substr')){
	function mb_substr(string $string, int $start, ?int $length=null, ?string $encoding=null): string {
		return $length===null ? substr($string, $start) : substr($string, $start, $length);
	}
}
if(!defined('MB_CASE_UPPER')){
	define('MB_CASE_UPPER', 0);
}
if(!defined('MB_CASE_LOWER')){
	define('MB_CASE_LOWER', 1);
}
if(!defined('MB_CASE_TITLE')){
	define('MB_CASE_TITLE', 2);
}
if(!function_exists('mb_convert_case')){
	function mb_convert_case(string $string, int $mode, ?string $encoding=null): string {
		return match($mode){
			MB_CASE_UPPER => strtoupper($string),
			MB_CASE_LOWER => strtolower($string),
			default => ucwords(strtolower($string)),
		};
	}
}
if(!function_exists('locale')){
	function locale(string $key, ?string $default=null): string {
		return $default ?? $key;
	}
}
if(!function_exists('openssl_random_pseudo_bytes')){
	function openssl_random_pseudo_bytes(int $length, &$strong_result=null): string {
		$strong_result=true;
		return random_bytes($length);
	}
}
if(!function_exists('openssl_encrypt')){
	function openssl_encrypt(string $data, string $cipher_algo, string $passphrase, int $options=0, string $iv='', ?string &$tag=null, string $aad='', int $tag_length=16): string|false {
		$signature=hash('sha256', $cipher_algo.'|'.$passphrase.'|'.$iv);
		return base64_encode($signature.'|'.$data);
	}
}
if(!function_exists('openssl_decrypt')){
	function openssl_decrypt(string $data, string $cipher_algo, string $passphrase, int $options=0, string $iv='', ?string $tag=null, string $aad=''): string|false {
		$decoded=base64_decode($data, true);
		if($decoded===false || !str_contains($decoded, '|')){
			return false;
		}
		[$signature, $payload]=explode('|', $decoded, 2);
		if(!hash_equals(hash('sha256', $cipher_algo.'|'.$passphrase.'|'.$iv), $signature)){
			return false;
		}
		return $payload;
	}
}
if(!defined('DATAPHYRE_DPANEL_WORKER_CONSTANT_LOADED')){
	define('DATAPHYRE_DPANEL_WORKER_CONSTANT_LOADED', true);
	class dataphyre_dpanel_worker_constant implements \ArrayAccess {
		private mixed $value=null;
		private bool $evaluated=false;

		public function __construct(private mixed $source) {}

		private function evaluate(): void {
			if($this->evaluated!==true){
				$this->value=$this->source instanceof \Closure ? ($this->source)() : $this->source;
				$this->evaluated=true;
			}
		}

		public function &raw(): mixed {
			$this->evaluate();
			return $this->value;
		}

		public function reset(): void {
			$this->value=null;
			$this->evaluated=false;
		}

		public function offsetExists(mixed $offset): bool {
			$this->evaluate();
			return is_array($this->value) && array_key_exists($offset, $this->value);
		}

		public function offsetGet(mixed $offset): mixed {
			$this->evaluate();
			return is_array($this->value) ? ($this->value[$offset] ?? null) : null;
		}

		public function offsetSet(mixed $offset, mixed $value): void {
			$this->evaluate();
			if(!is_array($this->value)){
				$this->value=[];
			}
			if($offset===null){
				$this->value[]=$value;
				return;
			}
			$this->value[$offset]=$value;
		}

		public function offsetUnset(mixed $offset): void {
			$this->evaluate();
			if(is_array($this->value)){
				unset($this->value[$offset]);
			}
		}
	}
}
if(!function_exists('heisenconstant')){
	function heisenconstant(string $name, mixed $value): void {
		if(defined($name)){
			return;
		}
		define($name, $value instanceof \Closure ? new \dataphyre_dpanel_worker_constant($value) : $value);
	}
}
if(!function_exists('pre_init_error')){
	function pre_init_error(string $message, ?\Throwable $exception=null): void {
		throw new \RuntimeException($exception!==null ? $message.': '.$exception->getMessage() : $message, 0, $exception);
	}
}
$worker_module_for_stubs=(string)($payload['module'] ?? '');
if($worker_module_for_stubs!=='sql' && !function_exists('sql_insert')){
	function sql_insert(...$arguments): mixed {
		if(is_callable($GLOBALS['dp_unit_sql_insert'] ?? null)){
			return $GLOBALS['dp_unit_sql_insert'](...$arguments);
		}
		$fields=is_array($arguments[1] ?? null) ? $arguments[1] : [];
		if(($fields['userid'] ?? null)===999){
			return false;
		}
		return ['unit_insert'=>true];
	}
}
if($worker_module_for_stubs!=='sql' && !function_exists('sql_select')){
	function sql_select(...$arguments): mixed {
		if(is_callable($GLOBALS['dp_unit_sql_select'] ?? null)){
			return $GLOBALS['dp_unit_sql_select'](...$arguments);
		}
		return $GLOBALS['dp_unit_sql_select_result'] ?? false;
	}
}
if($worker_module_for_stubs!=='sql' && !function_exists('sql_update')){
	function sql_update(...$arguments): mixed {
		return is_callable($GLOBALS['dp_unit_sql_update'] ?? null)
			? $GLOBALS['dp_unit_sql_update'](...$arguments)
			: true;
	}
}
if($worker_module_for_stubs!=='sql' && !function_exists('sql_delete')){
	function sql_delete(...$arguments): mixed {
		return is_callable($GLOBALS['dp_unit_sql_delete'] ?? null)
			? $GLOBALS['dp_unit_sql_delete'](...$arguments)
			: true;
	}
}

$module=(string)($payload['module'] ?? '');
$manifest_path=(string)($payload['manifest_path'] ?? '');
$case_index=isset($payload['case_index']) ? (int)$payload['case_index'] : -1;
$dpanel_path=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/dpanel/kernel/dpanel.main.php';
if(($module==='' && $manifest_path==='') || !is_file($dpanel_path)){
	$write_result([
		'passed'=>false,
		'trace'=>[[
			'type'=>'unit_test_worker',
			'level'=>'error',
			'module'=>$module,
			'message'=>'Worker cannot start because the module name or Dpanel path is unavailable.',
			'passed'=>false,
		]],
	]);
	exit(1);
}

require_once($dpanel_path);

$helper_path=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core/kernel/helper_functions.php';
if(is_file($helper_path)){
	require_once($helper_path);
}

\dataphyre\dpanel::$run_unit_tests=true;
\dataphyre\dpanel::$load_module_entrypoints=true;
\dataphyre\dpanel::$follow_dependency_diagnostics=false;
\dataphyre\dpanel::$allow_eval_unit_tests=true;
\dataphyre\dpanel::$bootstrap_core_before_module=$module==='core';
\dataphyre\dpanel::get_verbose();

if($module!=='core'){
	\dataphyre\dpanel::$run_unit_tests=false;
	\dataphyre\dpanel::$bootstrap_core_before_module=true;
	\dataphyre\dpanel::diagnose_module('core');
	\dataphyre\dpanel::get_verbose();
	\dataphyre\dpanel::$run_unit_tests=true;
	\dataphyre\dpanel::$bootstrap_core_before_module=false;
}

if($manifest_path!==''){
	if($module!=='' && !in_array($module, ['dynamic', 'unscoped', 'manifest'], true)){
		\dataphyre\dpanel::$run_unit_tests=false;
		\dataphyre\dpanel::diagnose_module($module);
		\dataphyre\dpanel::get_verbose();
		\dataphyre\dpanel::$run_unit_tests=true;
	}
	$allowed_roots=array_filter([
		(string)(ROOTPATH['dataphyre'] ?? ''),
		(string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),
	], static fn(string $root): bool=>$root!=='');
	$manifest_real=realpath($manifest_path);
	$allowed=false;
	if(is_string($manifest_real)){
		foreach($allowed_roots as $root){
			$root_real=realpath($root);
			if(is_string($root_real) && str_starts_with(str_replace('\\', '/', $manifest_real), rtrim(str_replace('\\', '/', $root_real), '/').'/')){
				$allowed=true;
				break;
			}
		}
	}
	if($allowed!==true || !is_readable($manifest_path)){
		$passed=false;
		\dataphyre\dpanel::add_verbose([[
			'type'=>'unit_test_worker',
			'level'=>'error',
			'module'=>$module,
			'manifest'=>basename($manifest_path),
			'message'=>'Manifest worker cannot read the requested unit-test manifest from a configured root.',
			'passed'=>false,
		]]);
	}
	else
	{
		$unit_test_path=$manifest_path;
		if($case_index>=0){
			$manifest=json_decode((string)file_get_contents($manifest_path), true);
			$cases=is_array($manifest) && array_is_list($manifest) ? $manifest : [$manifest];
			if(!isset($cases[$case_index]) || !is_array($cases[$case_index])){
				$passed=false;
				\dataphyre\dpanel::add_verbose([[
					'type'=>'unit_test_worker',
					'level'=>'error',
					'module'=>$module,
					'manifest'=>basename($manifest_path),
					'message'=>'Manifest worker cannot read the requested test case from the unit-test manifest.',
					'passed'=>false,
				]]);
			}
			else
			{
				$case_file=tempnam(dirname((string)($payload['output_path'] ?? $manifest_path)), 'dpanel_case_');
				if(is_string($case_file)){
					$case_json_file=$case_file.'.json';
					@unlink($case_file);
					file_put_contents($case_json_file, json_encode([$cases[$case_index]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
					$unit_test_path=$case_json_file;
				}
				else
				{
					$passed=false;
					\dataphyre\dpanel::add_verbose([[
						'type'=>'unit_test_worker',
						'level'=>'error',
						'module'=>$module,
						'manifest'=>basename($manifest_path),
						'message'=>'Manifest worker could not create an isolated unit-test case file.',
						'passed'=>false,
					]]);
				}
			}
		}
		if(!isset($passed)){
			$passed=\dataphyre\dpanel::unit_test($unit_test_path);
			if(isset($case_json_file) && is_string($case_json_file)){
				@unlink($case_json_file);
			}
		}
	}
}
else
{
	$passed=\dataphyre\dpanel::diagnose_module($module);
}
$trace=dataphyre_dpanel_worker_sanitize(\dataphyre\dpanel::get_verbose());
if($manifest_path!=='' && $case_index>=0 && is_array($trace)){
	foreach($trace as $index=>$entry){
		if(!is_array($entry)){
			continue;
		}
		$trace[$index]['manifest']=basename($manifest_path);
		$trace[$index]['case_index']=$case_index;
		if(isset($entry['file']) && isset($case_json_file) && basename((string)$entry['file'])===basename($case_json_file)){
			$trace[$index]['file']=basename($manifest_path);
		}
	}
}
$captured_output=trim((string)ob_get_clean());

$write_result([
	'passed'=>$passed,
	'module'=>$module,
	'manifest_path'=>$manifest_path,
	'case_index'=>$case_index,
	'trace'=>$trace,
	'output'=>$captured_output!=='' ? substr($captured_output, -8192) : '',
]);
exit($passed ? 0 : 1);

/**
 * Converts diagnostic payloads into JSON-safe values.
 *
 * @param mixed $value Raw diagnostic value.
 * @return mixed JSON-safe diagnostic value.
 */
function dataphyre_dpanel_worker_sanitize(mixed $value): mixed {
	if($value instanceof \Throwable){
		return [
			'__type'=>'throwable',
			'class'=>get_class($value),
			'message'=>$value->getMessage(),
			'file'=>$value->getFile(),
			'line'=>$value->getLine(),
			'trace'=>$value->getTraceAsString(),
		];
	}
	if(is_array($value)){
		foreach($value as $key=>$child){
			$value[$key]=dataphyre_dpanel_worker_sanitize($child);
		}
		return $value;
	}
	if(is_object($value)){
		return [
			'__type'=>'object',
			'class'=>get_class($value),
		];
	}
	return $value;
}
