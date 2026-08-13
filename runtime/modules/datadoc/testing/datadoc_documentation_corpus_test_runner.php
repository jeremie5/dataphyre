<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

$test=dirname(__DIR__).'/unit_tests/dataphyre.datadoc_documentation_corpus.test.php';
require $test;
$summaries=\Dataphyre\Test\Registry::caseSummaries($test);
$results=\Dataphyre\Test\Registry::runMany(array_column($summaries,'index'),$test);
$failed=array_values(array_filter($results,static fn(array $result):bool=>($result['passed']??false)!==true));
$report=[
	'type'=>'datadoc_documentation_corpus_unit_test_proof',
	'php_version'=>PHP_VERSION,
	'cases'=>count($results),
	'assertions'=>array_sum(array_map(static fn(array $result):int=>(int)($result['assertions']??0),$results)),
	'failures'=>$failed,
];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($failed===[]?0:1);
