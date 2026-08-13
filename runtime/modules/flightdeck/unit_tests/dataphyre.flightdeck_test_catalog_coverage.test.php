<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Flightdeck unit-test catalog')
	->tag('flightdeck','unit-test-catalog')
	->group('framework-coverage');

test('code-defined tests remain visible by suite case file and concern',static function(Context $t): void {
	$dataphyreRoot=rtrim((string)(ROOTPATH['common_dataphyre'] ?? ''), '/\\');
	$probe=__DIR__.'/fixtures/flightdeck_unit_test_catalog_probe.php';
	$probeResult=$t->coveredPhpFixture($probe,[$dataphyreRoot],working_directory:$dataphyreRoot);
	$t->same(0,$probeResult->exitCode(),trim($probeResult->stderr()));
	$result=$probeResult->json();
	$t->count(2,$result['queue']);
	$t->hasPathValues([
		'queue.0.suite'=>'Readable catalog',
		'queue.0.test_name'=>'catalog exposes the named contract',
		'queue.1.suite'=>'',
		'queue.1.test_name'=>'legacy case remains discoverable',
	],$result);
	$t->containsAll(
		['testing:Readable catalog / catalog exposes the named contract','#1'],
		$result['label']
	);
	$t->containsAll([
		'Self-described suites: 1',
		'Browse all 2 code-defined test cases',
		'Readable catalog',
		'catalog exposes the named contract',
		'legacy case remains discoverable',
		'No suite declared',
		'data-dpanel-test-filter',
		'dataphyre.catalog.test.php',
		'catalog, visibility, framework-coverage',
	],$result['html']);
	$t->contains('[data-dpanel-test-filter]',$result['client_script']);
	$t->contains('.fd-test-catalog-table',$result['style']);
});
