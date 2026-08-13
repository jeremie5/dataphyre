<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/fulltext_external_engine_test_helpers.php';

suite('Fulltext Vespa engine')
	->contract('fulltext.vespa-adapter', 1)
	->layer('unit')
	->risk('critical')
	->watches('module:fulltext_engine')
	->through('deployment-package', 'prepare-retry', 'activation', 'query', 'document-lifecycle')
	->isolation('case')
	->tag('fulltext', 'vespa', 'external-engine')
	->group('framework-coverage');

test('Vespa preserves packaging retries search projection and document lifecycle semantics', static function(Context $t): void {
	$workspace=$t->workspace('fulltext-vespa');
	$t->fulltext()->assertVespaAdapterContract($workspace->path('applications'));
})->maxMillis(5000);

dataset('Vespa configuration compatibility contracts', [
	'legacy configuration aliases'=>['legacy'],
	'missing archive implementation'=>['missing-archive'],
]);

test('Vespa configuration boundaries remain deterministic', static function(Context $t, string $contract): void {
	$workspace=$t->workspace('fulltext-vespa-configuration');
	if($contract==='legacy'){
		$t->fulltext()->assertVespaLegacyConfigurationContract($workspace->path('applications'));
		return;
	}
	$t->fulltext()->assertVespaMissingArchiveContract($workspace->path('applications'));
})->with('Vespa configuration compatibility contracts')->maxMillis(2000);
