<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Permission\Permission;
use Dataphyre\Permission\PermissionEngine;
use Dataphyre\Permission\PermissionRepository;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>['core'=>true,'permission'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
if(!defined('DP_PERMISSION_CFG')){
	define('DP_PERMISSION_CFG',[
		'roles'=>[],
		'default_roles'=>[],
		'cache'=>['enabled'=>true,'max_subjects'=>4],
		'storage'=>['auto_hydrate'=>true],
		'subject'=>['permission_keys'=>['permissions'],'role_keys'=>['roles']],
	]);
}
require_once __DIR__.'/permission_coverage_helpers.php';
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['permission']);

test('permission engine auto hydrates stored direct role and role definition rules',static function(Context $t): void {
	\Dataphyre\Permission\dp_permission_sql_reset($t);
	Permission::flush();
	$subject=['id'=>91,'permissions'=>['inline.view'],'roles'=>[]];
	$repository=PermissionRepository::instance();
	$t->isTrue($repository->defineRole('stored-role',['stored.role.view']));
	$t->isTrue($repository->assignPermission($subject,'stored.direct.view'));
	$t->isTrue($repository->assignRole($subject,'stored-role'));
	$engine=PermissionEngine::fromConfig();
	$rules=$engine->rulesFor($subject);
	$t->contains('inline.view',$rules['permissions']);
	$t->contains('stored.direct.view',$rules['permissions']);
	$t->contains('stored-role',$rules['roles']);
	$t->isTrue($engine->setFor($subject)->allows('stored.role.view'));
})->tag('permission','coverage')->group('framework-coverage');
