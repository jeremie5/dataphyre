<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre { const RUN_MODE='diagnostic'; const DP_CORE_CFG=['private_key'=>['unavailable-key'], 'encryption_version'=>0]; }

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;
	require_once __DIR__.'/fixtures/core_functions_unavailable_bootstrap.php';

	test('core functions unavailable diagnostic mode terminates through the host hook', static function(Context $t): void {
		dp_core_unavailable_scenario($t);
		dp_core_unavailable_install_terminator();
		$t->throws(static fn()=>\dataphyre\core::unavailable(__FILE__, '1', __CLASS__, __FUNCTION__, 'Diagnostic unavailable', 'coverage'), RuntimeException::class);
	})->tag('core', 'functions', 'unavailable', 'diagnostic', 'coverage')->group('framework-coverage');
}
