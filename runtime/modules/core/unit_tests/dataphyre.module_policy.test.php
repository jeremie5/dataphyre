<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

$dataphyre_test_root=\Dataphyre\Test\dataphyre_path();
if(!defined('ROOTPATH')){
	define('ROOTPATH', [
		'root'=>$dataphyre_test_root.'/',
		'common_root'=>$dataphyre_test_root.'/',
		'common_dataphyre'=>$dataphyre_test_root.'/',
		'common_dataphyre_runtime'=>$dataphyre_test_root.'/runtime/',
		'dataphyre'=>$dataphyre_test_root.'/runtime/modules/core/unit_tests/fixtures/module-policy-app/',
	]);
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>[
			'core'=>true,
			'sql'=>true,
		],
		'disabled'=>[
			'access'=>true,
		],
		'core_implicit'=>true,
	]);
}

require_once $dataphyre_test_root.'/runtime/modules/core/kernel/module_registry.php';
require_once $dataphyre_test_root.'/runtime/modules/core/kernel/autoloader.php';
require_once $dataphyre_test_root.'/runtime/modules/core/kernel/helper_functions.php';
require_once $dataphyre_test_root.'/runtime/modules/core/kernel/core_functions.php';

test('flight sheet policy gates module presence before filesystem resolution', static function(Context $t): void {
	$t->isTrue(\dataphyre\module_registry::module_enabled('sql'));
	$t->isFalse(\dataphyre\module_registry::module_enabled('access'));
	$t->isFalse(\dataphyre\module_registry::module_enabled('storage'));
	$t->type('array', \dataphyre\module_registry::kernel_module_present('sql'));
	$t->isFalse(\dataphyre\module_registry::kernel_module_present('access'));
	$t->isFalse(\dataphyre\module_registry::kernel_module_present('storage'));
	$t->isFalse(\dataphyre\module_registry::framework_module_present('access'));
	$t->isFalse(\dataphyre\module_registry::framework_module_present('storage'));
	$t->type('array', dp_module_present('sql'));
	$t->isFalse(dp_module_present('access'));
	$t->isFalse(dp_module_present('storage'));
})->tag('modules', 'bootstrap');

test('disabled Framework directories never register autoload prefixes', static function(Context $t): void {
	\dataphyre\autoloader::register(ROOTPATH['common_dataphyre_runtime'].'modules');
	$t->same([], \dataphyre\autoloader::register_framework_modules('access'));
	$t->isFalse(\dataphyre\core::load_framework_module('access'));

	$prefixes=$t->nonPublic(\dataphyre\autoloader::class)->readProperty('prefix_map');
	$t->isFalse(array_key_exists('Dataphyre\\Access\\', $prefixes));
	$t->isFalse(class_exists('Dataphyre\\Access\\PanelAuth'));
})->tag('modules', 'autoload');

test('enabled modules resolve kernel and Framework surfaces normally', static function(Context $t): void {
	$t->type('array', \dataphyre\module_registry::kernel_module_present('sql'));
	$t->isTrue(\dataphyre\core::load_framework_module('sql'));
	$t->isTrue(\dataphyre\autoloader::framework_module_available('sql'));
})->tag('modules', 'autoload');
