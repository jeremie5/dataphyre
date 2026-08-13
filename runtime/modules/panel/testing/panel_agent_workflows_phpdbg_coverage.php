<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(PHP_SAPI!=='phpdbg' || !function_exists('phpdbg_start_oplog')){
	fwrite(STDERR, "Run this proof with phpdbg -d opcache.enable_cli=0 -qrr.\n");
	exit(2);
}
if(filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN)){
	fwrite(STDERR, "CLI OPcache changes phpdbg's executable-line map; rerun with -d opcache.enable_cli=0.\n");
	exit(2);
}

$module=dirname(__DIR__);
$tests=[
	$module.'/unit_tests/dataphyre.panel_agent_safe_workflows_scorched_earth.test.php',
	$module.'/unit_tests/dataphyre.panel_atomic_agent_workflow_store_scorched_earth.test.php',
];
$targets=glob($module.'/Framework/Agents/*.php') ?: [];
sort($targets, SORT_STRING);
$normalize=static function(string $path): string {
	$real=realpath($path);
	return strtolower(str_replace('\\', '/', $real===false ? $path : $real));
};

phpdbg_start_oplog();
if(!defined('ROOTPATH')){ define('ROOTPATH', ['common_dataphyre_runtime'=>dirname($module, 2)]); }
require_once dirname($module).'/testing/tooling/bootstrap.php';
$results=[];
foreach($tests as $test){
	require $test;
	$testPath=realpath($test) ?: $test;
	$summaries=array_values(array_filter(
		\Dataphyre\Test\Registry::caseSummaries(),
		static fn(array $summary): bool=>(realpath((string)($summary['file'] ?? '')) ?: (string)($summary['file'] ?? ''))===$testPath
	));
	$results=array_merge($results, \Dataphyre\Test\Registry::runMany(array_column($summaries, 'index'), $test));
}
$executed=phpdbg_end_oplog();
$executable=phpdbg_get_executable();

$executedByFile=[];
foreach($executed as $file=>$lines){ $executedByFile[$normalize((string)$file)]=array_map('intval', array_keys((array)$lines)); }
$executableByFile=[];
foreach($executable as $file=>$lines){ $executableByFile[$normalize((string)$file)]=array_map('intval', array_keys((array)$lines)); }

$files=[];
$totalExecutable=0;
$totalExecuted=0;
foreach($targets as $target){
	$key=$normalize($target);
	$possible=array_values(array_unique($executableByFile[$key] ?? []));
	$hit=array_values(array_unique($executedByFile[$key] ?? []));
	sort($possible, SORT_NUMERIC);
	sort($hit, SORT_NUMERIC);
	$covered=array_values(array_intersect($possible, $hit));
	$missed=array_values(array_diff($possible, $covered));
	$totalExecutable+=count($possible);
	$totalExecuted+=count($covered);
	$files[basename($target)]=[
		'executable'=>count($possible),
		'executed'=>count($covered),
		'coverage_percent'=>$possible===[] ? 100.0 : round(count($covered)*100/count($possible), 2),
		'missed_lines'=>$missed,
	];
}
ksort($files, SORT_STRING);
$failed=array_values(array_filter($results, static fn(array $result): bool=>($result['passed'] ?? false)!==true));
$report=[
	'type'=>'panel_agent_workflows_phpdbg_exact_coverage',
	'php_version'=>PHP_VERSION,
	'tests'=>count($results),
	'assertions'=>array_sum(array_map(static fn(array $result): int=>(int)($result['assertions'] ?? 0), $results)),
	'failures'=>count($failed),
	'source_files'=>count($targets),
	'executable_lines'=>$totalExecutable,
	'executed_lines'=>$totalExecuted,
	'coverage_percent'=>$totalExecutable===0 ? 100.0 : round($totalExecuted*100/$totalExecutable, 2),
	'files'=>$files,
];
echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), "\n";
exit($failed===[] && $totalExecutable>0 && $totalExecutable===$totalExecuted ? 0 : 1);
