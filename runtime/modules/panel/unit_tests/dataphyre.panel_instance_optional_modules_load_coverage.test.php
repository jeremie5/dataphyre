<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelInstance;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',['enabled'=>['core'=>true,'panel'=>true,'access'=>true,'permission'=>true],'disabled'=>[],'core_implicit'=>true]);
}
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
require_once $modulesRoot.'/core/kernel/core_functions.php';
require_once $modulesRoot.'/core/kernel/helper_functions.php';
if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; function tracelog(mixed ...$arguments): void {}');
}
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['panel']);

suite('Panel optional-module loading contracts')
	->contract('panel.optional-module-loading', 1)
	->layer('integration')
	->risk('high')
	->watches('module:panel', 'module:permission', 'module:access')
	->through('autoloader', 'module-registry', 'panel-instance')
	->isolation('case')
	->tag('panel', 'modules')
	->group('framework-coverage');

test('panel instance optional modules load through the core framework loader',static function(Context $t): void {
	$t->isFalse(class_exists(\Dataphyre\Access\PanelAuth::class,false));
	$t->isFalse(class_exists(\Dataphyre\Permission\PermissionPanel::class,false));
	$panel=PanelInstance::make('optional-load');
	$t->same($panel,$panel->accessAuth(['protect'=>false]));
	$t->same($panel,$panel->accessPermissions([]));
	$t->same($panel,$panel->permissionAdmin(['catalog_page'=>false]));
})->tag('panel','instance','coverage')->group('framework-coverage');

test('panel instance optional modules load permission admin directly',static function(Context $t): void {
	$t->isFalse(class_exists(\Dataphyre\Permission\PermissionPanel::class,false));
	$panel=PanelInstance::make('optional-admin-load');
	$t->same($panel,$panel->permissionAdmin(['catalog_page'=>false]));
})->tag('panel','instance','coverage')->group('framework-coverage');
