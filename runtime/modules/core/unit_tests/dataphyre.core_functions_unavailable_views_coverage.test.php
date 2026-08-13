<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre { const RUN_MODE='request'; const DP_CORE_CFG=['private_key'=>['unavailable-key'], 'encryption_version'=>0, 'core'=>['unavailable'=>[]]]; }

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;
	define('dataphyre\\ROOTPATH', array_replace(ROOTPATH, ['dataphyre'=>'', 'views'=>__DIR__.'/fixtures/core_functions_unavailable_views/']));
	require_once __DIR__.'/fixtures/core_functions_unavailable_bootstrap.php';

	test('core functions unavailable request falls back to the configured views root', static function(Context $t): void {
		$state=dp_core_unavailable_scenario($t);
		dp_core_unavailable_install_terminator();
		$t->throws(static fn()=>\dataphyre\core::unavailable(__FILE__, '1', __CLASS__, __FUNCTION__, 'Views unavailable', 'coverage'), RuntimeException::class);
		$t->isTrue($state->get('view_loaded'));
	})->tag('core', 'functions', 'unavailable', 'views', 'coverage')->group('framework-coverage');
}
