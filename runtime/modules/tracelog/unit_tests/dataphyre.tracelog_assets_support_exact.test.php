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

test('tracelog asset support remains idempotent when legacy entrypoints include it repeatedly', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$payload=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/tracelog_assets_support_idempotence_probe.php',
		[dirname(__DIR__).'/kernel/assets_support.php'],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->hasPathValues(['second_include'=>1, 'asset_name'=>'viewer.css'], $payload);
})->tag('tracelog','assets','exact-coverage')->group('framework-coverage');
