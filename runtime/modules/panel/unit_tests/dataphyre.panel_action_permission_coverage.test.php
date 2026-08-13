<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Action;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

if(!defined('DP_PANEL_CFG')){
	define('DP_PANEL_CFG',['permission'=>true]);
}
framework(['panel']);
if(!class_exists('Dataphyre\Permission\Permission',false)){
	\Dataphyre\Test\define_test_symbols('namespace Dataphyre\Permission; final class Permission { public static function any(array $permissions,mixed $user=null,array $context=[]): bool { return false; } }');
}

test('panel action configured permission bridge can deny resource actions',static function(Context $t): void {
	$t->isFalse(Action::make('delete')->can(['id'=>1],['id'=>2],Resource::make('orders')));
})->tag('panel','action','permission','coverage')->group('framework-coverage');
