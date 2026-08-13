<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__, 2).'/dpanel/tooling/WorkerFixtureState.php';
require_once __DIR__.'/aceit_engine_test_helpers.php';
require_once dirname(__DIR__, 2).'/sql/Framework/TableDefinition.php';

suite('AceIt experiment lifecycle')
	->contract('aceit.experiment-lifecycle', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:aceit_engine')
	->through('definition', 'assignment', 'metricization', 'aggregation', 'charting', 'storage', 'schema')
	->isolation('case')
	->tag('aceit', 'exact-coverage')
	->group('framework-coverage');

test('experiment storage treats missing malformed callback and JSON persistence as named outcomes', static function(Context $t): void {
	$internals=$t->nonPublic(\dataphyre\aceit_engine::class);
	$internals->writeProperty('experiment_list', []);
	$missingReader=$t->spy()->willReturn(false);
	$internals->invoke('load_experiment_list', $missingReader);
	$t->same([], $internals->readProperty('experiment_list'));
	$missingReader->assertCalledTimes($t, 1);

	$validReader=$t->spy()->willReturn('{"checkout":{"count":2}}');
	$internals->invoke('load_experiment_list', $validReader);
	$t->same(2, $internals->readProperty('experiment_list')['checkout']['count']);
	$validReader->assertCalledTimes($t, 1);
	$internals->invoke('load_experiment_list', $validReader);
	$validReader->assertCalledTimes($t, 1);

	$t->isFalse($internals->invoke('save_experiment', 'missing'));
	$saveCallback=$t->spy();
	$internals->writeProperty('experiment_list', [
		'checkout'=>['count'=>3, 'save_callback'=>$saveCallback],
	]);
	$t->isTrue($internals->invoke('save_experiment', 'checkout'));
	$saveCallback->assertCalledTimes($t, 1);

	$reader=$t->spy()->willReturn('{"existing":{"count":1}}');
	$writer=$t->spy()->willReturn(100);
	$internals->writeProperty('experiment_list', ['checkout'=>['count'=>4]]);
	$t->isTrue($internals->invoke('save_experiment', 'checkout', $reader, $writer));
	$persisted=$t->jsonArray((string)$writer->lastCall()[1]);
	$t->same(1, $persisted['existing']['count']);
	$t->same(4, $persisted['checkout']['count']);

	$resource=fopen('php://memory', 'r');
	$internals->writeProperty('experiment_list', ['unencodable'=>['value'=>$resource]]);
	$t->isFalse($internals->invoke('save_experiment', 'unencodable', $reader, $writer));
	fclose($resource);
});

test('definition respects future starts and initializes eligible sessions only after activation', static function(Context $t): void {
	$eligibility=$t->spy()->willReturn('variant');
	$report=$t->spy();
	DpAceItWorkerScenario::experimentLifecycle('future', ['start'=>2_000, 'count'=>0]);
	\dataphyre\aceit_engine::define_experiment(
		'future',
		['start'=>2_000],
		['member-7'],
		$eligibility,
		static fn(array $events): int=>count($events),
		$report,
		'hourly',
		['clock'=>static fn(): int=>1_000]
	);
	$eligibility->assertCalledTimes($t, 0);
	$t->same([], DpAceItWorkerScenario::experiment('future'));

	DpAceItWorkerScenario::begin();
	DpAceItWorkerScenario::replaceExperimentList([]);
	$save=$t->spy();
	\dataphyre\aceit_engine::define_experiment(
		'active',
		['start'=>1_000, 'required_sample_size'=>10, 'save_callback'=>$save],
		['member-7', 'mobile'],
		static fn(): null=>null,
		static fn(array $events): int=>count($events),
		$report,
		'hourly',
		[
			'clock'=>static fn(): int=>2_000,
			'read_experiments'=>static fn(): false=>false,
		]
	);
	$save->assertCalledTimes($t, 1);
	$t->same('control', DpAceItWorkerScenario::experiment('active')['group']);
	$t->same(0, DpAceItWorkerScenario::experimentList()['active']['count']);
});

test('finished definitions report a leader and schedule exactly one named aggregation', static function(Context $t): void {
	$report=$t->spy();
	$aggregate=$t->spy();
	$state=$t->state('aceit.finished-definition');
	DpAceItWorkerScenario::experimentLifecycle('checkout', [
		'start'=>1_000,
		'count'=>10,
		'required_sample_size'=>10,
	]);
	\dataphyre\aceit_engine::define_experiment(
		'checkout',
		['start'=>1_000, 'required_sample_size'=>10],
		['member-10'],
		static fn(): string=>'variant',
		static fn(): int=>1,
		$report,
		'daily',
		[
			'clock'=>static fn(): int=>2_000,
			'leading_group'=>static fn(): string=>'variant',
			'aggregate'=>$aggregate,
			'register_shutdown'=>static fn(callable $callback)=>$state->put('aggregate', $callback),
		]
	);
	$report->assertCalledWith($t, ['checkout', 'variant']);
	($state->get('aggregate'))();
	$aggregate->assertCalledWith($t, ['checkout', 'daily']);
	$t->same('variant', DpAceItWorkerScenario::experiment('checkout')['group']);

	$defaultReport=$t->spy();
	DpAceItWorkerScenario::experimentLifecycle('default-leader', [
		'start'=>1_000,
		'count'=>5,
		'required_sample_size'=>5,
		'is_aggregated'=>true,
		'save_callback'=>static fn(): null=>null,
	]);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('query', [['group'=>'control', 'total_score'=>12]]);
	\dataphyre\aceit_engine::define_experiment(
		'default-leader',
		['start'=>1_000, 'required_sample_size'=>5],
		[],
		static fn(): string=>'control',
		static fn(): int=>1,
		$defaultReport,
		'hourly',
		['clock'=>static fn(): int=>2_000]
	);
	$defaultReport->assertCalledWith($t, ['default-leader', 'control']);
});

test('metricization derives a stable segment from session factors and commits only successful inserts', static function(Context $t): void {
	$save=$t->spy();
	DpAceItWorkerScenario::experimentLifecycle('checkout', [
		'count'=>2,
		'save_callback'=>$save,
	], [
		'events'=>[['name'=>'checkout', 'value'=>1]],
		'group'=>'variant',
		'metrification_callback'=>static fn(array $events): int=>count($events)*5,
		'environmental_factors'=>['member-7', 'mobile'],
	]);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', false);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('insert', ['id'=>71]);
	$t->isTrue(\dataphyre\aceit_engine::metricize('checkout'));
	$insert=DpAceItWorkerScenario::insertedExperiment();
	$t->same('member-7', $insert['env_factor1']);
	$t->same('mobile', $insert['env_factor2']);
	$t->same(null, $insert['env_factor5']);
	$t->same('variant', $insert['group']);
	$t->same(5, $insert['score']);
	$t->matches('/^[a-f0-9]{32}$/', $insert['segment_identifier']);
	$t->isTrue(DpAceItWorkerScenario::experiment('checkout')['submitted']);
	$t->same(3, DpAceItWorkerScenario::experimentList()['checkout']['count']);
	$save->assertCalledTimes($t, 1);

	$t->isFalse(\dataphyre\aceit_engine::metricize('checkout'));
	$t->same(1, \dataphyre_dpanel_worker_fixture_state::sqlCallCount('insert'));
});

test('metricization rejects unknown finished inactive duplicate unscored and unpersisted outcomes', static function(Context $t): void {
	DpAceItWorkerScenario::begin();
	DpAceItWorkerScenario::replaceExperimentList(['finished'=>['count'=>1, 'is_finished'=>true]]);
	$t->isFalse(\dataphyre\aceit_engine::metricize('unknown'));
	$t->isTrue(\dataphyre\aceit_engine::metricize('finished'));

	DpAceItWorkerScenario::experimentLifecycle('inactive', ['count'=>0]);
	$t->isFalse(\dataphyre\aceit_engine::metricize('inactive'));

	DpAceItWorkerScenario::experimentLifecycle('duplicate', ['count'=>0], [
		'events'=>[], 'group'=>'control', 'metrification_callback'=>static fn(): int=>1, 'environmental_factors'=>[],
	]);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', ['id'=>1]);
	$t->isFalse(\dataphyre\aceit_engine::metricize('duplicate'));

	DpAceItWorkerScenario::experimentLifecycle('unscored', ['count'=>0], [
		'events'=>[], 'group'=>'control', 'metrification_callback'=>static fn(): false=>false, 'environmental_factors'=>[],
	]);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', false);
	$t->isFalse(\dataphyre\aceit_engine::metricize('unscored'));

	DpAceItWorkerScenario::experimentLifecycle('unpersisted', ['count'=>0], [
		'events'=>[], 'group'=>'control', 'metrification_callback'=>static fn(): int=>1, 'environmental_factors'=>[],
	]);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', false);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('insert', false);
	$t->isFalse(\dataphyre\aceit_engine::metricize('unpersisted'));
	$t->isFalse(isset(DpAceItWorkerScenario::experiment('unpersisted')['submitted']));
});

test('aggregation handles hourly empty invalid and unavailable SQL result sets explicitly', static function(Context $t): void {
	$save=$t->spy();
	DpAceItWorkerScenario::experimentLifecycle('hourly', ['count'=>2, 'save_callback'=>$save]);
	\dataphyre_dpanel_worker_fixture_state::respondToSql('query', static function(string $query): array|false {
		if(str_starts_with($query, 'SELECT DISTINCT')){
			return [['group'=>'control']];
		}
		if(str_starts_with($query, 'SELECT group')){
			return false;
		}
		return [];
	});
	\dataphyre\aceit_engine::aggregate_experiment('hourly', 'hourly');
	$t->isTrue(DpAceItWorkerScenario::experimentList()['hourly']['is_aggregated']);
	$save->assertCalledTimes($t, 1);

	DpAceItWorkerScenario::begin();
	\dataphyre\aceit_engine::aggregate_experiment('unknown', 'weekly');
	$t->same(0, \dataphyre_dpanel_worker_fixture_state::sqlCallCount('query'));

	DpAceItWorkerScenario::experimentLifecycle('offline', ['count'=>1]);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('query', false);
	\dataphyre\aceit_engine::aggregate_experiment('offline', 'daily');
	$t->isFalse(isset(DpAceItWorkerScenario::experimentList()['offline']['is_aggregated']));
});

test('leading and chart queries preserve null winners and unbounded result dates', static function(Context $t): void {
	$save=$t->spy();
	DpAceItWorkerScenario::experimentLifecycle('leaderless', ['count'=>1, 'save_callback'=>$save]);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('query', []);
	$t->same(null, $t->nonPublic(\dataphyre\aceit_engine::class)->invoke('get_leading_test_group', 'leaderless'));
	$save->assertCalledTimes($t, 1);

	DpAceItWorkerScenario::begin();
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('query', [
		['group'=>'control', 'experiment_date'=>'2026-02-01', 'total_score'=>4],
		['group'=>'variant', 'experiment_date'=>'2026-02-02', 'total_score'=>7],
	]);
	$t->same([
		'control'=>['2026-02-01'=>4],
		'variant'=>['2026-02-02'=>7],
	], \dataphyre\aceit_engine::chart_experiment('checkout', null, null));

	\dataphyre_dpanel_worker_fixture_state::returnFromSql('query', false);
	$t->same([], \dataphyre\aceit_engine::chart_experiment('checkout', null, []));
});

test('experiment table manifest describes segment and experiment lookup indexes', static function(Context $t): void {
	$manifest=require dirname(__DIR__).'/aceit_engine.tables.php';
	$t->hasKey('experiments', $manifest);
	$definition=$manifest['experiments']('dataphyre.aceit_engine_experiments');
	$t->instanceOf(TableDefinition::class, $definition);
	$t->same('dataphyre.aceit_engine_experiments', $definition->table());
	$t->same(['id'], $definition->primaryColumns());
});
