<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Api\ApiManager;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>['api'=>true,'core'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_api_runtime_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $dp_api_runtime_modules_root.'/core/Framework/Runtime.php';
require_once $dp_api_runtime_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_api_runtime_modules_root);
\dataphyre\autoloader::register_framework_modules(['api']);

test('api manager modern runtime tracing switch is honored',static function(Context $t): void {
	$private=$t->nonPublic(ApiManager::instance());
	$t->isTrue($private->invoke('tracingEnabled'));
})->tag('api','manager','coverage')->group('framework-coverage');
