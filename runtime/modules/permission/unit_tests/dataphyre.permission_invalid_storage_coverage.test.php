<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

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
		'subject'=>['permission_keys'=>['permissions'],'role_keys'=>['roles']],
		'storage'=>[
			'auto_hydrate'=>false,
			'assignments_table'=>'invalid table',
			'roles_table'=>'invalid table',
			'role_permissions_table'=>'invalid table',
		],
	]);
}
require_once __DIR__.'/permission_coverage_helpers.php';
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['permission']);

test('permission repository fails closed for invalid configured storage identifiers',static function(Context $t): void {
	PermissionRepository::flush();
	$repository=PermissionRepository::instance();
	$t->isFalse($repository->defineRole('invalid',['invalid.view']));
	$t->same([],$repository->roleDefinitions());
	$t->same([],$repository->rolesWithPermissions());
	$t->same([],$repository->assignments());
	$t->isFalse($repository->saveAssignmentFromPanel(['subject_id'=>'1','value'=>'x'])['saved']);
	$t->isFalse($repository->assignPermission(['id'=>1],'x'));
})->tag('permission','coverage')->group('framework-coverage');
