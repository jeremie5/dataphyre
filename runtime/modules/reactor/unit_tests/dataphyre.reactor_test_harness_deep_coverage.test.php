<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Reactor\ReactorComponent;
use Dataphyre\Reactor\ReactorManager;
use Dataphyre\Reactor\ReactorResponse;
use Dataphyre\Reactor\ReactorTestHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>[
			'core'=>true,
			'mvc'=>true,
			'reactor'=>true,
			'templating'=>false,
		],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_reactor_harness_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_reactor_harness_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_reactor_harness_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'mvc', 'reactor']);

test('reactor test harness wraps manager registration mounting dispatch and response snapshots', static function(Context $t): void {
	$manager=new ReactorManager();
	$harness=ReactorTestHarness::make($manager);
	$t->same($manager, $harness->manager());
	$t->same($harness, $harness->register([
		'name'=>'Harness Probe',
		'state'=>['count'=>1],
		'actions'=>[
			'increment'=>static fn(array $state, array $params): array=>[
				'count'=>(int)($state['count'] ?? 0) + (int)($params['by'] ?? 1),
			],
		],
		'render'=>'<strong>{{ count }}</strong>',
	]));
	$t->same($harness, $harness->register(
		ReactorComponent::make('secondary-probe')->render('<em>Secondary</em>')
	));

	$mounted=$harness->mount('Harness Probe', ['count'=>2], ['class'=>'probe']);
	$t->same('Harness Probe', $mounted['component']);
	$t->same(strlen($mounted['html']), $mounted['html_length']);
	$t->pathEquals('snapshot.state.count', 2, $mounted);
	$t->same('harness_probe', $mounted['manifest']['name']);

	$automatic=$harness->dispatch('Harness Probe', 'increment', ['count'=>2], ['by'=>3]);
	ReactorTestHarness::assertOk($automatic);
	ReactorTestHarness::assertState($automatic, 'count', 5);

	$snapshot=$manager->snapshot('Harness Probe', ['count'=>4]);
	$explicit=$harness->dispatch('Harness Probe', 'increment', ['count'=>4], ['by'=>2], $snapshot);
	$t->same(6, $explicit->state()['count']);

	$summary=ReactorTestHarness::responseSnapshot($explicit);
	$t->same(200, $summary['status']);
	$t->isTrue($summary['ok']);
	$t->same('', $summary['message']);
	$t->same(strlen($explicit->html()), $summary['html_length']);
	$t->contains('count', $summary['state_keys']);
	$t->same(array_keys($explicit->effects()), $summary['effect_keys']);
	$t->same($explicit->effects(), $summary['effects']);

	$errorSummary=ReactorTestHarness::responseSnapshot(ReactorResponse::error('No access', 403, ['focus'=>'name']));
	$t->isFalse($errorSummary['ok']);
	$t->same('No access', $errorSummary['message']);
	$t->contains('focus', $errorSummary['effect_keys']);
})->tag('reactor', 'coverage')->group('framework-coverage');

test('reactor test harness assertion helpers cover every result shape and diagnostic failure', static function(Context $t): void {
	$ok=ReactorResponse::ok(
		'<article><strong>Ready</strong></article>',
		['user'=>['name'=>'Ada'], 'nullable'=>null],
		['toast'=>['message'=>'Saved']]
	);
	$error=ReactorResponse::error('  Broken request  ', 503);

	ReactorTestHarness::assertOk($ok);
	$t->throws(static fn()=>ReactorTestHarness::assertOk($error), RuntimeException::class, '503');

	ReactorTestHarness::assertHtmlContains('<b>Raw HTML</b>', 'Raw');
	ReactorTestHarness::assertHtmlContains(['html'=>'<i>Array HTML</i>'], 'Array');
	ReactorTestHarness::assertHtmlContains($ok, 'Ready');
	$t->throws(static fn()=>ReactorTestHarness::assertHtmlContains($ok, 'Missing'), RuntimeException::class, 'Missing');
	$t->throws(static fn()=>ReactorTestHarness::assertHtmlContains([], ''), RuntimeException::class);

	ReactorTestHarness::assertState($ok, 'user.name', 'Ada');
	ReactorTestHarness::assertState($ok, 'nullable', null);
	ReactorTestHarness::assertState($ok, 'user.missing', null);
	ReactorTestHarness::assertState($ok, 'user.name.first', null);
	$t->throws(static fn()=>ReactorTestHarness::assertState($ok, 'user.name', 'Grace'), RuntimeException::class, 'Grace');

	$stream=fopen('php://temp', 'r+b');
	try{
		$t->throws(static fn()=>ReactorTestHarness::assertState($ok, 'user.name', $stream), RuntimeException::class, 'resource');
	}
	finally{
		fclose($stream);
	}

	ReactorTestHarness::assertEffect($ok, 'toast');
	$t->throws(static fn()=>ReactorTestHarness::assertEffect($ok, 'redirect'), RuntimeException::class, 'redirect');
})->tag('reactor', 'coverage')->group('framework-coverage');
