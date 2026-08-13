<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)){
	fwrite(STDERR, "Dataphyre code unit-test worker must be run from the command line.\n");
	exit(1);
}

require_once __DIR__.'/CoverageLineNormalizer.php';
require_once __DIR__.'/PathSemantics.php';
require_once __DIR__.'/PhpdbgLineMap.php';

$payload_path=(string)($argv[1] ?? '');
$started_at=microtime(true);
$result_written=false;
$payload=[];
$coverage_enabled=false;
$included_before=[];
$xdebug_coverage=false;
$phpdbg_coverage=false;
$coverage_roots=[];

$finish=function(bool $passed, array $trace, array $extra=[])use(&$result_written, &$payload, $started_at, &$coverage_enabled, &$included_before, &$xdebug_coverage, &$phpdbg_coverage, &$coverage_roots): never {
	if($result_written===true){
		exit($passed ? 0 : 1);
	}
	$result_written=true;
	if($coverage_enabled===true){
		$extra['coverage']=dataphyre_code_worker_coverage($included_before, $xdebug_coverage, $phpdbg_coverage, ['coverage_roots'=>$coverage_roots]);
	}
	if(class_exists(\Dataphyre\Test\CoverageParts::class,false)){
		$parts=\Dataphyre\Test\CoverageParts::all();
		if($parts!==[]){$extra['coverage_parts']=array_merge(is_array($extra['coverage_parts'] ?? null) ? $extra['coverage_parts'] : [],$parts);}
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
$coverage_roots=is_array($payload['coverage_roots'] ?? null)
	? array_values(array_filter(array_map('strval', $payload['coverage_roots']), static fn(string $root): bool=>trim($root)!==''))
	: [];
$included_before=get_included_files();
if($coverage_enabled && function_exists('xdebug_start_code_coverage')){
	$flags=defined('XDEBUG_CC_UNUSED') ? XDEBUG_CC_UNUSED : 0;
	$flags|=defined('XDEBUG_CC_DEAD_CODE') ? XDEBUG_CC_DEAD_CODE : 0;
	@xdebug_start_code_coverage($flags);
	$xdebug_coverage=true;
}
elseif($coverage_enabled && function_exists('phpdbg_start_oplog') && function_exists('phpdbg_end_oplog') && function_exists('phpdbg_get_executable')){
	@phpdbg_start_oplog();
	$phpdbg_coverage=true;
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
	define('BS_VERSION', '2.0.3');
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

$testkit_path=__DIR__.'/bootstrap.php';
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
	$bootstrap_files=$payload['bootstrap_files'] ?? [];
	if(!is_array($bootstrap_files)){
		throw new \UnexpectedValueException('Code unit-test bootstrap_files must be a list.');
	}
	foreach($bootstrap_files as $bootstrap_file){
		$bootstrap_file=dataphyre_code_worker_resolve_file((string)$bootstrap_file);
		if($bootstrap_file==='' || !is_file($bootstrap_file)){
			throw new \RuntimeException('Module unit-test bootstrap is missing or unreadable: '.$bootstrap_file);
		}
		require_once $bootstrap_file;
	}
	require $test_file;
}catch(Throwable $throwable){
	$finish(false, [[
		'type'=>'code_unit_test_worker',
		'test_name'=>basename($test_file),
		'test_file'=>$test_file,
		'file'=>$throwable->getFile(),
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

$case_indexes=$payload['case_indexes'] ?? [$payload['case_index'] ?? 0];
if(!is_array($case_indexes)){
	$case_indexes=[$case_indexes];
}
$case_indexes=array_values(array_unique(array_map('intval', $case_indexes)));
$results=[];
if(method_exists(\Dataphyre\Test\Registry::class, 'runMany')){
	$results=\Dataphyre\Test\Registry::runMany($case_indexes, $test_file);
}
else
{
	foreach($case_indexes as $case_index){
		$results[]=\Dataphyre\Test\Registry::run($case_index, $test_file);
	}
}
$passed=$results!==[];
foreach($results as $result){
	if(!is_array($result) || ($result['passed'] ?? false)!==true){
		$passed=false;
	}
}
$finish($passed, $results, [
	'cases'=>$cases,
	'output'=>substr((string)ob_get_contents(), -8192),
]);

function dataphyre_code_worker_resolve_file(string $file): string {
	$normalized=\Dataphyre\Test\PathSemantics::normalize($file);
	if($normalized===''){
		return '';
	}
	foreach([
		'dataphyre/runtime/'=>'common_dataphyre_runtime',
		'dataphyre/'=>'common_dataphyre',
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
	if(\Dataphyre\Test\PathSemantics::isAbsolute($normalized)){
		return $normalized;
	}
	return \Dataphyre\Test\PathSemantics::resolve((string)(ROOTPATH['root'] ?? ''), $normalized);
}

/** @param array<string,mixed> $runtime Injectable readers make every transport branch contract-testable. */
function dataphyre_code_worker_coverage(array $included_before, bool $xdebug_coverage, bool $phpdbg_coverage=false, array $runtime=[]): array {
	$root=str_replace('\\', '/', rtrim((string)($runtime['result_root'] ?? ROOTPATH['common_root'] ?? ROOTPATH['root'] ?? ''), '/\\')).'/';
	$in_scope=$runtime['file_in_scope'] ?? 'dataphyre_code_worker_coverage_file_in_scope';
	$coverage_roots=is_array($runtime['coverage_roots'] ?? null) ? $runtime['coverage_roots'] : [];
	$files=[];
	$included_reader=$runtime['included_files'] ?? 'get_included_files';
	$included_now=is_callable($included_reader) ? $included_reader() : [];
	foreach(array_diff(is_array($included_now) ? $included_now : [], $included_before) as $file){
		$normalized=str_replace('\\', '/', $file);
		if(!is_callable($in_scope) || !$in_scope($normalized, $coverage_roots)){
			continue;
		}
		$files[]=str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $normalized;
	}
	sort($files);
	$result=[
		'engine'=>'included_files',
		'files'=>array_values(array_unique($files)),
	];
	$xdebug_reader=$runtime['xdebug_get'] ?? (function_exists('xdebug_get_code_coverage') ? 'xdebug_get_code_coverage' : null);
	if($xdebug_coverage && is_callable($xdebug_reader)){
		$line_files=[];
		$coverage=$xdebug_reader() ?: [];
		foreach(is_array($coverage) ? $coverage : [] as $file=>$lines){
			$normalized=str_replace('\\', '/', (string)$file);
			if(!is_callable($in_scope) || !$in_scope($normalized, $coverage_roots)){
				continue;
			}
			$key=str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $normalized;
			$executable_lines=[];
			$covered_lines=[];
			foreach($lines as $line=>$hit){
				$line=(int)$line;
				// Xdebug reports statically unreachable/non-executable lines as -2
				// when dead-code analysis is enabled. They are useful diagnostics,
				// but cannot belong to the executable coverage denominator.
				if((int)$hit===-2){
					continue;
				}
				$executable_lines[]=$line;
				if((int)$hit>0){
					$covered_lines[]=$line;
				}
			}
			$line_files[$key]=[
				'executable'=>count($executable_lines),
				'covered'=>count($covered_lines),
				'executable_ranges'=>dataphyre_code_worker_line_ranges($executable_lines),
				'covered_ranges'=>dataphyre_code_worker_line_ranges($covered_lines),
			];
		}
		$xdebug_stop=$runtime['xdebug_stop'] ?? (function_exists('xdebug_stop_code_coverage') ? 'xdebug_stop_code_coverage' : null);
		if(is_callable($xdebug_stop)){@$xdebug_stop(false);}
		ksort($line_files);
		$result=[
			'engine'=>'xdebug',
			'files'=>$line_files,
			'included_files'=>$files,
		];
	}
	else{
		$phpdbg_end=$runtime['phpdbg_end'] ?? (function_exists('phpdbg_end_oplog') ? 'phpdbg_end_oplog' : null);
		$phpdbg_get=$runtime['phpdbg_get'] ?? (function_exists('phpdbg_get_executable') ? 'phpdbg_get_executable' : null);
		if(!$phpdbg_coverage || !is_callable($phpdbg_end) || !is_callable($phpdbg_get)){
			return $result;
		}
		$executable=\Dataphyre\Test\PhpdbgLineMap::detach(@$phpdbg_get());
		$oplog=\Dataphyre\Test\PhpdbgLineMap::detach(@$phpdbg_end());
		$line_files=[];
		foreach($executable as $file=>$lines){
			$normalized=str_replace('\\', '/', (string)$file);
			if(!is_callable($in_scope) || !$in_scope($normalized, $coverage_roots) || !is_array($lines)){
				continue;
			}
			$key=str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $normalized;
			$executable_lines=[];
			foreach(array_keys($lines) as $line){
				$line=(int)$line;
				if($line>0){$executable_lines[]=$line;}
			}
			$covered_lines=[];
			$hits=$oplog[$file] ?? $oplog[str_replace('/', '\\', (string)$file)] ?? [];
			if(is_array($hits)){
				foreach(array_keys($hits) as $line){
					$line=(int)$line;
					if($line>0){$covered_lines[]=$line;}
				}
			}
			$normalized_lines=\Dataphyre\Test\CoverageLineNormalizer::phpdbg($normalized,$executable_lines,$covered_lines);
			$ignored_reasons=[];
			foreach($normalized_lines['ignored_by_reason'] as $reason=>$ignored_lines){
				$ignored_reasons[$reason]=dataphyre_code_worker_line_ranges($ignored_lines);
			}
			$line_files[$key]=[
				'raw_executable'=>count($normalized_lines['raw_executable_lines']),
				'executable'=>count($normalized_lines['executable_lines']),
				'covered'=>count($normalized_lines['covered_lines']),
				'ignored'=>count($normalized_lines['ignored_lines']),
				'raw_executable_ranges'=>dataphyre_code_worker_line_ranges($normalized_lines['raw_executable_lines']),
				'executable_ranges'=>dataphyre_code_worker_line_ranges($normalized_lines['executable_lines']),
				'covered_ranges'=>dataphyre_code_worker_line_ranges($normalized_lines['covered_lines']),
				'ignored_ranges'=>dataphyre_code_worker_line_ranges($normalized_lines['ignored_lines']),
				'ignored_reasons'=>$ignored_reasons,
			];
		}
		ksort($line_files);
		$result=[
			'engine'=>'phpdbg',
			'files'=>$line_files,
			'included_files'=>$files,
		];
	}
	return $result;
}

/**
 * Keeps coverage focused on repository source rather than the test harness.
 *
 * Test definitions, fixtures, generated/eval sources, and this worker cannot be
 * meaningful coverage targets: the worker must snapshot coverage before it can
 * serialize that same snapshot. Restricting the report here gives every worker
 * the same source boundary before their results are aggregated.
 */
/** @param list<string> $coverage_roots */
function dataphyre_code_worker_coverage_file_in_scope(string $file, array $coverage_roots=[]): bool {
	$file=str_replace('\\', '/', $file);
	if(str_contains(strtolower($file), "eval()'d code")){
		return false;
	}
	$resolved_file=realpath($file);
	if(is_string($resolved_file) && $resolved_file!==''){
		$file=str_replace('\\', '/', $resolved_file);
	}
	$relative='';
	if($coverage_roots!==[]){
		$accepted=false;
		$file_compare=strtolower(rtrim($file, '/'));
		foreach($coverage_roots as $coverage_root){
			$coverage_root=str_replace('\\', '/', trim((string)$coverage_root));
			$resolved_root=realpath($coverage_root);
			if(is_string($resolved_root) && $resolved_root!==''){
				$coverage_root=str_replace('\\', '/', $resolved_root);
			}
			$coverage_root=rtrim($coverage_root, '/');
			if($coverage_root===''){continue;}
			$root_compare=strtolower($coverage_root);
			if(is_file($coverage_root) || str_ends_with(strtolower($coverage_root), '.php')){
				if($file_compare===$root_compare){$accepted=true;break;}
				continue;
			}
			if($file_compare===$root_compare || str_starts_with($file_compare, $root_compare.'/')){
				$accepted=true;
				break;
			}
		}
		if(!$accepted){return false;}
	}else{
		$rootpath=defined('ROOTPATH') ? constant('ROOTPATH') : [];
		$project_root=is_array($rootpath) ? trim((string)($rootpath['common_dataphyre'] ?? '')) : '';
		if($project_root==='' && is_array($rootpath) && !empty($rootpath['common_dataphyre_runtime'])){
			$project_root=dirname(rtrim((string)$rootpath['common_dataphyre_runtime'], '/\\'));
		}
		$project_root=$project_root!=='' ? $project_root : dirname(__DIR__, 4);
		$resolved_project_root=realpath($project_root);
		if(is_string($resolved_project_root) && $resolved_project_root!==''){
			$project_root=$resolved_project_root;
		}
		$project_root=str_replace('\\', '/', rtrim($project_root, '/\\')).'/';
		if(!str_starts_with($file, $project_root)){
			return false;
		}
		$relative=substr($file, strlen($project_root));
	}
	foreach([
		'runtime/modules/testing/tooling/code_worker.php',
		'runtime/modules/testing/tooling/WorkerCoverage.php',
		'runtime/modules/testing/tooling/CoverageSubprocess.php',
		'runtime/modules/dpanel/kernel/dpanel.worker.php',
	] as $transport){
		if($relative===$transport || str_ends_with($file, '/'.$transport)){return false;}
	}
	if(str_contains('/'.$file, '/unit_tests/')){
		return false;
	}
	return !str_contains($file, '.test.php');
}

/** @param array<int,int> $lines */
function dataphyre_code_worker_line_ranges(array $lines): string {
	if($lines===[]){
		return '';
	}
	$lines=array_values(array_unique(array_map('intval', $lines)));
	sort($lines, SORT_NUMERIC);
	$ranges=[];
	$start=$lines[0];
	$end=$start;
	foreach(array_slice($lines, 1) as $line){
		if($line===$end + 1){
			$end=$line;
			continue;
		}
		$ranges[]=$start===$end ? (string)$start : $start.'-'.$end;
		$start=$end=$line;
	}
	$ranges[]=$start===$end ? (string)$start : $start.'-'.$end;
	return implode(',', $ranges);
}
