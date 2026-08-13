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

test('core kernel module registry treats a malformed bootstrap module payload as an empty policy',static function(Context $t): void {
	$t->same(['core'],\dataphyre\module_registry::enabled_modules());
	$t->same([],\dataphyre\module_registry::disabled_modules());
	$t->isTrue(\dataphyre\module_registry::module_enabled('core'));
	$t->isFalse(\dataphyre\module_registry::module_enabled('sql'));
})->tag('core','modules','coverage')->group('framework-coverage');
