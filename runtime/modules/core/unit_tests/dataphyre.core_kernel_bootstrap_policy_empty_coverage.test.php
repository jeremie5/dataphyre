<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_BOOTSTRAP_CONFIG')){
	define('DATAPHYRE_BOOTSTRAP_CONFIG',[
		'modules'=>'invalid-policy-shape',
	]);
}

$dp_core_kernel_empty_policy_root=\Dataphyre\Test\dataphyre_path();
require_once $dp_core_kernel_empty_policy_root.'/runtime/modules/core/kernel/module_registry.php';

test('core kernel module registry treats a malformed bootstrap module payload as legacy discovery',static function(Context $t): void {
	$enabled=\dataphyre\module_registry::enabled_modules();
	$t->contains('core',$enabled);
	$t->contains('sql',$enabled);
	$t->same([],\dataphyre\module_registry::disabled_modules());
	$t->isTrue(\dataphyre\module_registry::module_enabled('core'));
	$t->isTrue(\dataphyre\module_registry::module_enabled('sql'));
})->tag('core','modules','coverage')->group('framework-coverage');
