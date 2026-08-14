<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\WorkerCoverage;

require_once __DIR__.'/WorkerCoverage.php';

/**
 * Auto-prepend bootstrap for exact child-process coverage.
 *
 * Environment configuration keeps the target command untouched. Exact phpdbg
 * targets must return normally: phpdbg skips PHP shutdown callbacks after an
 * exit language construct, and the parent deliberately fails if no part is
 * produced instead of certifying missing evidence.
 */
function dataphyre_test_coverage_subprocess_bootstrap(?array $environment=null): bool {
	$environment ??=[
		'part'=>(string)(getenv('DATAPHYRE_TEST_COVERAGE_PART') ?: ''),
		'framework_root'=>(string)(getenv('DATAPHYRE_TEST_COVERAGE_FRAMEWORK_ROOT') ?: ''),
		'result_root'=>(string)(getenv('DATAPHYRE_TEST_COVERAGE_RESULT_ROOT') ?: ''),
	];
	$part=trim((string)($environment['part'] ?? ''));
	$frameworkRoot=trim((string)($environment['framework_root'] ?? ''));
	$resultRoot=trim((string)($environment['result_root'] ?? $frameworkRoot));
	if($part==='' || $frameworkRoot===''){return false;}
	$coverage=WorkerCoverage::start([
		'common_dataphyre'=>$frameworkRoot,
		'common_root'=>$resultRoot!=='' ? $resultRoot : $frameworkRoot,
	],true);
	$written=false;
	$write=static function()use($coverage,$part,&$written): void {
		if($written){return;}
		$written=true;
		try{
			$result=$coverage->finish();
			if(!is_array($result)){return;}
			$directory=dirname($part);
			if(!is_dir($directory) && !@mkdir($directory,0775,true) && !is_dir($directory)){return;}
			@file_put_contents($part,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
		}catch(Throwable $failure){
			// A coverage writer must never replace the target process's own result.
			@file_put_contents($part.'.error',$failure::class.': '.$failure->getMessage());
		}
	};
	register_shutdown_function($write);
	if((string)(getenv('DATAPHYRE_TEST_COVERAGE_SANITIZE_ENVIRONMENT') ?: '')==='1'){
		foreach([
			'DATAPHYRE_TEST_COVERAGE_PART','DATAPHYRE_TEST_COVERAGE_FRAMEWORK_ROOT',
			'DATAPHYRE_TEST_COVERAGE_RESULT_ROOT','DATAPHYRE_TEST_COVERAGE_SANITIZE_ENVIRONMENT',
			'XDEBUG_MODE','PHP_INI_SCAN_DIR',
		] as $name){
			@putenv($name);unset($_ENV[$name],$_SERVER[$name]);
		}
	}
	return true;
}

$direct=isset($_SERVER['SCRIPT_FILENAME'])
	&& realpath((string)$_SERVER['SCRIPT_FILENAME'])===realpath(__FILE__);
if(!$direct){
	dataphyre_test_coverage_subprocess_bootstrap();
	return;
}

dataphyre_test_coverage_subprocess_bootstrap();
$target=(string)($argv[1] ?? '');
if($target==='' || !is_file($target)){
	fwrite(STDERR,"Covered subprocess target is missing or unreadable.\n");
	exit(2);
}
$targetArguments=array_slice($argv,2);
$argv=[$target,...$targetArguments];
$argc=count($argv);
$_SERVER['argv']=$argv;
$_SERVER['argc']=$argc;
$_SERVER['SCRIPT_FILENAME']=$target;
require $target;
