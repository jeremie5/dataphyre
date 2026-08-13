<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Permission\SubjectResolver;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',['enabled'=>['core'=>true,'permission'=>true],'disabled'=>[],'core_implicit'=>true]);
}
if(!defined('DP_PERMISSION_CFG')){
	define('DP_PERMISSION_CFG',['roles'=>[],'default_roles'=>[],'subject'=>[]]);
}
require_once __DIR__.'/permission_dialback_stub.php';
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['permission']);

test('permission subject resolver uses core permission and role dialbacks',static function(Context $t): void {
	$t->same(['dialback.view'],SubjectResolver::permissions(['id'=>1]));
	$t->same(['dialback-role'],SubjectResolver::roles(['id'=>1]));
})->tag('permission','coverage')->group('framework-coverage');
