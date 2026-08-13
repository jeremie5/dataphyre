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
		'modules'=>[
			'enabled'=>[
				' SQL '=>true,
				'ignored'=>false,
			],
			'disabled'=>[
				'access'=>true,
				'core'=>true,
			],
		],
	]);
}

$dp_core_kernel_fallback_root=\Dataphyre\Test\dataphyre_path();
require_once $dp_core_kernel_fallback_root.'/runtime/modules/core/kernel/module_registry.php';

test('core kernel module registry falls back to normalized bootstrap configuration policy',static function(Context $t): void {
	$t->same(['core','sql'],\dataphyre\module_registry::enabled_modules());
	$t->same(['access'],\dataphyre\module_registry::disabled_modules());
	$t->isTrue(\dataphyre\module_registry::module_enabled('sql'));
	$t->isFalse(\dataphyre\module_registry::module_enabled('access'));
})->tag('core','modules','coverage')->group('framework-coverage');
