<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\TestArchitectureAudit;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/tooling/TestArchitecture.php';

/**
 * Changed-run dependency metadata for this repository-wide sentinel. The
 * portable wildcard resolves against the modules present in this distribution.
 * @dataphyre-changed-run-sentinel framework('*');
 */

suite('Dataphyre test architecture')
	->tag('testing', 'architecture', 'portability')
	->group('framework-coverage')
	->watches('module:*');

test('all test architecture violations are reported together', static function(Context $t): void {
	$audit=TestArchitectureAudit::forModulesRoot(dirname(__DIR__, 2));

	$t->same([], $audit->violationData(), $audit->report());
})->maxMillis(30000);

test('the changed-run sentinel names every framework module it protects', static function(Context $t): void {
	$source=file_get_contents(__FILE__);
	$t->isTrue(is_string($source));
	if(!is_string($source)){
		return;
	}
	$audit=TestArchitectureAudit::forModulesRoot(dirname(__DIR__, 2));

	$t->same($audit->moduleNames(), TestArchitectureAudit::changedRunSentinelModules($source, $audit->moduleNames()));
});
