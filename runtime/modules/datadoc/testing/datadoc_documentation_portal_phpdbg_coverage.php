<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(PHP_SAPI!=='phpdbg'||!function_exists('phpdbg_start_oplog')){
	fwrite(STDERR,"Run this proof with phpdbg -d opcache.enable_cli=0 -qrr.\n");
	exit(2);
}
if(filter_var(ini_get('opcache.enable_cli'),FILTER_VALIDATE_BOOLEAN)){
	fwrite(STDERR,"CLI OPcache changes phpdbg's executable-line map; rerun with -d opcache.enable_cli=0.\n");
	exit(2);
}

$module=dirname(__DIR__);
$tests=[
	$module.'/unit_tests/dataphyre.datadoc_documentation_portal_scorched_earth.test.php',
	$module.'/unit_tests/dataphyre.datadoc_documentation_corpus.test.php',
];
$targets=[
	$module.'/Framework/DocumentationCorpus.php',
	$module.'/Framework/DocumentationPortal.php',
	$module.'/Framework/DocumentationPortalBuild.php',
	$module.'/Framework/DocumentationPortalPublication.php',
	$module.'/Framework/DocumentationPortalWriteResult.php',
];
$normalize=static function(string $path):string {
	$real=realpath($path);
	return strtolower(str_replace('\\','/',$real===false?$path:$real));
};

phpdbg_start_oplog();
$results=[];
foreach($tests as $test){
	require $test;
	$testPath=realpath($test)?:$test;
	$summaries=array_values(array_filter(
		\Dataphyre\Test\Registry::caseSummaries(),
		static fn(array $summary):bool=>(realpath((string)($summary['file']??''))?:(string)($summary['file']??''))===$testPath,
	));
	$results=array_merge($results,\Dataphyre\Test\Registry::runMany(array_column($summaries,'index'),$test));
}
$executed=phpdbg_end_oplog();
$executable=phpdbg_get_executable();
$files=[];
$allMissed=[];
$totalExecutable=0;
$totalCovered=0;
foreach($targets as $target){
	$key=$normalize($target);
	$executedLines=[];
	foreach($executed as $file=>$lines){ if($normalize((string)$file)===$key){ $executedLines=array_map('intval',array_keys((array)$lines)); } }
	$executableLines=[];
	foreach($executable as $file=>$lines){ if($normalize((string)$file)===$key){ $executableLines=array_map('intval',array_keys((array)$lines)); } }
	$executableLines=array_values(array_unique($executableLines));
	$executedLines=array_values(array_unique($executedLines));
	sort($executableLines,SORT_NUMERIC);
	sort($executedLines,SORT_NUMERIC);
	$covered=array_values(array_intersect($executableLines,$executedLines));
	$missed=array_values(array_diff($executableLines,$covered));
	$totalExecutable+=count($executableLines);
	$totalCovered+=count($covered);
	foreach($missed as $line){ $allMissed[]=basename($target).':'.$line; }
	$files[]=[
		'path'=>$target,
		'executable_lines'=>count($executableLines),
		'executed_lines'=>count($covered),
		'coverage_percent'=>$executableLines===[]?100.0:round(count($covered)*100/count($executableLines),2),
		'missed_lines'=>$missed,
	];
}
$failed=array_values(array_filter($results,static fn(array $result):bool=>($result['passed']??false)!==true));
$report=[
	'type'=>'datadoc_documentation_portal_phpdbg_exact_coverage',
	'php_version'=>PHP_VERSION,
	'tests'=>count($results),
	'assertions'=>array_sum(array_map(static fn(array $result):int=>(int)($result['assertions']??0),$results)),
	'failures'=>count($failed),
	'executable_lines'=>$totalExecutable,
	'executed_lines'=>$totalCovered,
	'coverage_percent'=>$totalExecutable===0?100.0:round($totalCovered*100/$totalExecutable,2),
	'missed_lines'=>$allMissed,
	'files'=>$files,
];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($failed===[]&&$totalExecutable>0&&$allMissed===[]?0:1);
