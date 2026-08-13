<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/localization_kernel_testkit.php';

suite('Localization diagnostics without SQL')
	->contract('localization.diagnostics-without-sql', 1)
	->layer('integration')
	->risk('medium')
	->watches('module:localization')
	->through('isolated diagnostic entrypoint', 'dependency reporting', 'SQL-optional scan')
	->isolation('case')
	->tag('localization', 'diagnostic')
	->group('framework-coverage');

test('diagnostic entrypoint remains inspectable when SQL helpers are intentionally absent', static function(Context $t): void {
	$result=$t->processSucceeded((new LocalizationKernelProcessScenario($t))->diagnosticsWithoutSql());
	$diagnostic=$result->json();
	$t->isFalse($diagnostic['sql_query_present']);
	$t->contains(['localization', 'sql'], $diagnostic['required_modules']);
	$t->contains('SQL-backed Localization table checks were skipped', $diagnostic['message_text']);
});
