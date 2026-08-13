<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\TestKitAutoloader;

$autoloader=(string)($argv[1] ?? '');
$sourceRoot=(string)($argv[2] ?? '');
if(!is_file($autoloader) || !is_dir($sourceRoot)){
	throw new RuntimeException('TestKit autoloader probe inputs are unavailable.');
}
require $autoloader;

$before=TestKitAutoloader::path(Context::class);
$unregistered='';
try{
	TestKitAutoloader::sourceRoot();
}catch(LogicException $failure){
	$unregistered=$failure->getMessage();
}
TestKitAutoloader::register($sourceRoot);
TestKitAutoloader::register($sourceRoot);
TestKitAutoloader::load('Vendor\\ForeignType');

echo json_encode([
	'before'=>$before,
	'unregistered'=>$unregistered,
	'root'=>TestKitAutoloader::sourceRoot(),
	'files'=>TestKitAutoloader::sourceFiles(),
	'context_path'=>TestKitAutoloader::path(Context::class),
	'context_class'=>TestKitAutoloader::classForPath($sourceRoot.'/Context.php'),
], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
