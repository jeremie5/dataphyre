<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelRoute;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>['core'=>true,'panel'=>true],
		'disabled'=>['routing'=>true,'mvc'=>true],
		'core_implicit'=>true,
	]);
}
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['panel']);

test('panel route reports missing standalone routing dependency',static function(Context $t): void {
	$t->throws(static fn()=>PanelRoute::routing(),RuntimeException::class);
	$t->throws(static fn()=>PanelRoute::assetRouting(),RuntimeException::class);
	$t->throws(static fn()=>PanelRoute::uploadRouting(),RuntimeException::class);
})->tag('panel','route','coverage')->group('framework-coverage');
