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

suite('Localization production safeguards')
	->contract('localization.production-safeguards', 1)
	->layer('integration')
	->risk('high')
	->watches('module:localization')
	->through('isolated production bootstrap', 'missing translation fallback', 'learning queue guard')
	->isolation('case')
	->tag('localization', 'production-guard')
	->group('framework-coverage');

test('production mode never records an unresolved fallback in the translator learning queue', static function(Context $t): void {
	$result=$t->processSucceeded((new LocalizationKernelProcessScenario($t))->productionFallback());
	$t->subset([
		'production'=>true,
		'resolved'=>'Fallback',
		'unknown_locale_file_exists'=>false,
	], $result->json());
});
