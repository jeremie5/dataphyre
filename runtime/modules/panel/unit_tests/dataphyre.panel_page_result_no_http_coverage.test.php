<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>['core'=>true,'panel'=>true],
		'disabled'=>['http'=>true],
		'core_implicit'=>true,
	]);
}
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['panel']);

test('panel page result returns itself when the http response module is unavailable',static function(Context $t): void {
	$result=PanelPageResult::html('<p>Standalone</p>');
	$t->same($result,$result->toResponse());
})->tag('panel','page-result','coverage')->group('framework-coverage');
