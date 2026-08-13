<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Storage\Contracts\StorageDriver;
use Dataphyre\Storage\Drivers\MemoryDriver;
use Dataphyre\Storage\StorageManager;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', ['enabled'=>['core'=>true, 'storage'=>true], 'disabled'=>[], 'core_implicit'=>true]);
}
if(!defined('DP_STORAGE_CFG')){
	define('DP_STORAGE_CFG', ['default_disk'=>'local', 'disks'=>[]]);
}
$dp_storage_manager_empty_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_storage_manager_empty_modules_root.'/core/kernel/autoloader.php';
require_once $dp_storage_manager_empty_modules_root.'/core/kernel/core_functions.php';
\dataphyre\autoloader::register($dp_storage_manager_empty_modules_root);
\dataphyre\autoloader::register_framework_modules(['storage']);

test('storage manager diagnostics fall back to the default disk when no disks are configured', static function(Context $t): void {
	$manager=new StorageManager();
	$manager->extend('local', static fn(array $config, string $name, StorageManager $owner): StorageDriver=>new MemoryDriver($config));
	$report=$manager->diagnostics(null, ['write'=>false]);
	$t->same(1, $report['checked']);
	$t->isTrue($report['disks']['local']['checks']['instantiate']);
})->tag('storage', 'manager', 'empty-config', 'coverage')->group('framework-coverage');
