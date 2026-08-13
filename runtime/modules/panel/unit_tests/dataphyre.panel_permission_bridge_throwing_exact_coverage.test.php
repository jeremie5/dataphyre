<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelPermissionBridge;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

if(!defined('DP_PANEL_CFG')){
	define('DP_PANEL_CFG',['permission'=>['permission_prefix'=>'admin','resource_prefix'=>'resources','super_permission'=>'admin.*']]);
}
if(!defined('DP_PERMISSION_CFG')){
	define('DP_PERMISSION_CFG',['panel'=>['permission_prefix'=>'global','allow_guest_pages'=>['Public Page']]]);
}
if(!class_exists('Dataphyre\\Permission\\Permission',false)){
	\Dataphyre\Test\define_test_symbols('namespace Dataphyre\\Permission; final class Permission { public static bool $throw=true; public static function any(array $permissions, mixed $user=null, array $context=[]): bool { if(self::$throw){ throw new \\RuntimeException("permission failed"); } return true; } }');
}
framework(['panel']);
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

test('panel permission bridge merges options maps aliases and backend failures',static function(Context $t): void {
	$options=PanelPermissionBridge::options();
	$t->same('admin',$options['permission_prefix']);
	$t->same('resources',$options['resource_prefix']);
	$t->same('admin.resources.orders.view',PanelPermissionBridge::name('orders','view',$options));
	$t->same('admin.resources.orders.relation.items.view',PanelPermissionBridge::relationName('orders','items','index',$options));
	$t->same('admin.resources.orders.relation.items.update',PanelPermissionBridge::relationName('orders','items','attach',$options));
	$t->same('admin.resources.dashboard.view',PanelPermissionBridge::pageName('dashboard','show',$options));
	$t->same('admin.resources.dashboard.create',PanelPermissionBridge::pageName('dashboard','store',$options));
	$t->same('admin.resources.dashboard.update',PanelPermissionBridge::pageName('dashboard','edit',$options));
	$t->isTrue(PanelPermissionBridge::allowsGuestPage('Public Page',$options));
	$t->isFalse(PanelPermissionBridge::allows('admin.resources.orders.view',7,[], $options));
	\Dataphyre\Permission\Permission::$throw=false;
	$t->isTrue(PanelPermissionBridge::allows('admin.resources.orders.view',7,[], $options));
})->tag('panel','panel-permission-throwing-exact')->group('framework-coverage');
