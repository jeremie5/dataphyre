<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

test('sanitation framework bootstrap publishes one idempotent lifecycle marker',static function(Context $t): void {
	$t->phpBootstrap(dirname(__DIR__).'/Framework/Bootstrap.php')
		->defines('DATAPHYRE_SANITATION_FRAMEWORK_BOOTSTRAPPED',true)
		->reloadsSafely();
})->tag('sanitation','framework-bootstrap','lifecycle')->group('framework-coverage');
