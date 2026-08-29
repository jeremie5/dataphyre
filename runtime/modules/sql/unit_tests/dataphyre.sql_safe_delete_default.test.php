<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['sql']);

test('Omitted safe-delete configuration keeps unbounded deletes blocked without warnings', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$probe=__DIR__.'/fixtures/sql_safe_delete_default_probe.php';
	$result=$t->processSucceeded($t->phpFixture($probe, [$root], working_directory:$root));
	$payload=$result->json();
	$t->same(false, $payload['safe_delete_present'] ?? null);
	$t->same(false, $payload['delete_result'] ?? null);
	$t->same([], $payload['warnings'] ?? null);
	$t->same('sqlite', $payload['delete_error']['dbms'] ?? null);
	$t->contains('safe_delete', (string)($payload['delete_error']['message'] ?? ''));
})->tag('sql', 'configuration', 'safe-delete', 'fail-closed')->group('framework-coverage')->maxMillis(10000);
