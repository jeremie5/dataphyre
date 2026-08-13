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
require_once __DIR__.'/permission_access_stub.php';
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['permission']);

test('permission subject resolver falls back to the Access Auth facade',static function(Context $t): void {
	\Dataphyre\Access\Auth::$authenticated=false;
	$t->same(404,SubjectResolver::id(null));
	\Dataphyre\Access\Auth::$authenticated=true;
	$t->same(['id'=>404,'permissions'=>['auth.view']],SubjectResolver::subject(null));
})->tag('permission','coverage')->group('framework-coverage');
