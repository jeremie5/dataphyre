<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(PHP_SAPI!=='cli'){
	fwrite(STDERR, "Dataphyre code unit-test worker must be run from the command line.\n");
	exit(1);
}

$payload_path=(string)($argv[1] ?? '');
$started_at=microtime(true);
$result_written=false;
$payload=[];
$coverage_enabled=false;
$included_before=[];
$xdebug_coverage=false;

$finish=function(bool $passed, array $trace, array $extra=[])use(&$result_written, &$payload, $started_at, &$coverage_enabled, &$included_before, &$xdebug_coverage): never {
	if($result_written===true){
		exit($passed ? 0 : 1);
	}
	$result_written=true;
	if($coverage_enabled===true){
		$extra['coverage']=dataphyre_code_worker_coverage($included_before, $xdebug_coverage);
	}
	$result=[
		'passed'=>$passed,
		'trace'=>$trace,
		'duration_seconds'=>microtime(true)-$started_at,
	]+$extra;
	$output_path=(string)($payload['output_path'] ?? '');
	if($output_path!==''){
		@file_put_contents($output_path, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	}
	else
	{
		echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
	}
	exit($passed ? 0 : 1);
};

register_shutdown_function(function()use(&$result_written, &$payload, $started_at): void {
	if($result_written===true){
		return;
	}
	$error=error_get_last();
	$fatal_types=[E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
	$trace=[[
		'type'=>'code_unit_test_worker',
		'level'=>'error',
		'message'=>'Code unit-test worker exited before returning a final result.',
		'passed'=>false,
	]];
	if(is_array($error) && in_array((int)($error['type'] ?? 0), $fatal_types, true)){
		$trace[0]['message']=(string)($error['message'] ?? 'Worker terminated with a fatal PHP error.');
		$trace[0]['file']=(string)($error['file'] ?? '');
		$trace[0]['line']=(int)($error['line'] ?? 0);
	}
	$result=[
		'passed'=>false,
		'trace'=>$trace,
		'duration_seconds'=>microtime(true)-$started_at,
		'output'=>substr((string)ob_get_contents(), -8192),
	];
	$output_path=(string)($payload['output_path'] ?? '');
	if($output_path!==''){
		@file_put_contents($output_path, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	}
	else
	{
		echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
	}
});

ob_start();

if($payload_path==='' || !is_file($payload_path)){
	$finish(false, [[
		'type'=>'code_unit_test_worker',
		'message'=>'Worker payload is missing or unreadable.',
		'passed'=>false,
	]]);
}

$payload=json_decode((string)file_get_contents($payload_path), true);
if(!is_array($payload)){
	$payload=[];
	$finish(false, [[
		'type'=>'code_unit_test_worker',
		'message'=>'Worker payload JSON is invalid.',
		'passed'=>false,
	]]);
}

$timeout=(int)($payload['timeout_seconds'] ?? 12);
if($timeout>0){
	@set_time_limit($timeout + 2);
}
$memory_limit=(string)($payload['memory_limit'] ?? '256M');
if($memory_limit!==''){
	@ini_set('memory_limit', $memory_limit);
}
$coverage_enabled=filter_var($payload['coverage'] ?? false, FILTER_VALIDATE_BOOL);
$included_before=get_included_files();
if($coverage_enabled && function_exists('xdebug_start_code_coverage')){
	$flags=defined('XDEBUG_CC_UNUSED') ? XDEBUG_CC_UNUSED : 0;
	$flags|=defined('XDEBUG_CC_DEAD_CODE') ? XDEBUG_CC_DEAD_CODE : 0;
	@xdebug_start_code_coverage($flags);
	$xdebug_coverage=true;
}

$rootpath=$payload['rootpath'] ?? [];
if(!is_array($rootpath)){
	$finish(false, [[
		'type'=>'code_unit_test_worker',
		'message'=>'Worker payload did not include a rootpath map.',
		'passed'=>false,
	]]);
}
if(!defined('ROOTPATH')){
	define('ROOTPATH', $rootpath);
}
if(!defined('RUN_MODE')){
	define('RUN_MODE', 'ci');
}
if(!defined('BS_VERSION')){
	define('BS_VERSION', '2.0.1');
}
if(!defined('IS_PRODUCTION')){
	define('IS_PRODUCTION', false);
}
if(!isset($_SESSION) || !is_array($_SESSION)){
	$_SESSION=[];
}
$_SERVER['REQUEST_METHOD']=$_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI']=$_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['HTTP_HOST']=$_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REMOTE_ADDR']=$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$testkit_path=__DIR__.'/TestKit.php';
if(!is_file($testkit_path)){
	$finish(false, [[
		'type'=>'code_unit_test_worker',
		'message'=>'Dataphyre test kit is missing.',
		'passed'=>false,
	]]);
}
require_once $testkit_path;

$test_file=dataphyre_code_worker_resolve_file((string)($payload['test_file'] ?? $payload['manifest_path'] ?? ''));
if($test_file==='' || !is_file($test_file)){
	$finish(false, [[
		'type'=>'code_unit_test_worker',
		'message'=>'Code unit-test file is missing or unreadable.',
		'file'=>$test_file,
		'passed'=>false,
	]]);
}

\Dataphyre\Test\Registry::reset();
try{
	require $test_file;
}catch(Throwable $throwable){
	$finish(false, [[
		'type'=>'code_unit_test_worker',
		'test_name'=>basename($test_file),
		'file'=>$test_file,
		'message'=>$throwable->getMessage(),
		'exception'=>$throwable::class,
		'line'=>$throwable->getLine(),
		'passed'=>false,
	]], ['output'=>substr((string)ob_get_contents(), -8192)]);
}

$mode=(string)($payload['mode'] ?? 'run');
$cases=\Dataphyre\Test\Registry::caseSummaries($test_file);
if($mode==='list'){
	$finish(true, [[
		'type'=>'code_unit_test_list',
		'test_name'=>basename($test_file),
		'file'=>$test_file,
		'cases'=>count($cases),
		'message'=>'Code-defined unit-test cases discovered.',
		'passed'=>true,
	]], [
		'cases'=>$cases,
		'output'=>substr((string)ob_get_contents(), -8192),
	]);
}

$case_index=(int)($payload['case_index'] ?? 0);
$result=\Dataphyre\Test\Registry::run($case_index, $test_file);
$passed=($result['passed'] ?? false)===true;
$finish($passed, [$result], [
	'cases'=>$cases,
	'output'=>substr((string)ob_get_contents(), -8192),
]);

function dataphyre_code_worker_resolve_file(string $file): string {
	$normalized=str_replace('\\', '/', trim($file));
	if($normalized===''){
		return '';
	}
	foreach([
		'common/dataphyre/runtime/'=>'common_dataphyre_runtime',
		'common/dataphyre/'=>'common_dataphyre',
		'common/'=>'common_root',
		'applications/'=>'applications',
	] as $prefix=>$root_key){
		if(str_starts_with($normalized, $prefix) && !empty(ROOTPATH[$root_key])){
			$relative=substr($normalized, strlen($prefix));
			if($prefix==='common/'){
				$relative='common/'.$relative;
			}
			return rtrim((string)ROOTPATH[$root_key], '/\\').'/'.$relative;
		}
	}
	if(preg_match('#^[A-Za-z]:/#', $normalized)===1 || str_starts_with($normalized, '//')){
		return $file;
	}
	return rtrim((string)(ROOTPATH['root'] ?? ''), '/\\').'/'.ltrim($normalized, '/');
}

function dataphyre_code_worker_coverage(array $included_before, bool $xdebug_coverage): array {
	$root=str_replace('\\', '/', rtrim((string)(ROOTPATH['common_root'] ?? ROOTPATH['root'] ?? ''), '/\\')).'/';
	$files=[];
	foreach(array_diff(get_included_files(), $included_before) as $file){
		$normalized=str_replace('\\', '/', $file);
		$files[]=str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $normalized;
	}
	sort($files);
	$result=[
		'engine'=>'included_files',
		'files'=>array_values(array_unique($files)),
	];
	if($xdebug_coverage && function_exists('xdebug_get_code_coverage')){
		$line_files=[];
		foreach(xdebug_get_code_coverage() ?: [] as $file=>$lines){
			$normalized=str_replace('\\', '/', (string)$file);
			$key=str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $normalized;
			$line_files[$key]=[
				'executable'=>count($lines),
				'covered'=>count(array_filter($lines, static fn(int $hit): bool=>$hit>0)),
			];
		}
		@xdebug_stop_code_coverage(false);
		ksort($line_files);
		$result=[
			'engine'=>'xdebug',
			'files'=>$line_files,
		];
	}
	return $result;
}
