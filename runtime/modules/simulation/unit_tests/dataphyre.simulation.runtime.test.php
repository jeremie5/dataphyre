<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Simulation\InMemorySimulationStateStore;
use Dataphyre\Simulation\SimulationDomainAdapter;
use Dataphyre\Simulation\SimulationContext;
use Dataphyre\Simulation\SimulationIntent;
use Dataphyre\Simulation\SimulationOutcome;
use Dataphyre\Simulation\SimulationPerspective;
use Dataphyre\Simulation\SimulationRandom;
use Dataphyre\Simulation\SimulationRegistry;
use Dataphyre\Simulation\SimulationRule;
use Dataphyre\Simulation\SimulationRuntime;
use Dataphyre\Simulation\SimulationRuntimePolicy;
use Dataphyre\Simulation\SimulationScenario;
use Dataphyre\Simulation\SimulationScope;
use Dataphyre\Simulation\SimulationStateStore;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/Framework/Bootstrap.php';

test('simulation scopes perspectives and random streams are stable and closed', static function(Context $t): void {
	$left=new SimulationScope(['store_id'=>'7', 'organization_id'=>2]);
	$right=new SimulationScope(['organization_id'=>2, 'store_id'=>'7']);
	$t->same($left->key(), $right->key());
	$t->same(7, $left->requireInt('store_id'));
	$t->same(['organization_id'=>2, 'store_id'=>'7'], $left->all());
	$t->isTrue($left->has('STORE_ID'));
	$t->throws(static fn()=>new SimulationScope([]), InvalidArgumentException::class);
	$t->throws(static fn()=>new SimulationScope(['bad-key'=>1]), InvalidArgumentException::class);
	$t->throws(static fn()=>(new SimulationScope(['store_id'=>0]))->requireInt('store_id'), InvalidArgumentException::class);

	$perspective=SimulationPerspective::forSurface('KDS Operator', ['kds', 'dispatch'], ['manager']);
	$t->isFalse($perspective->allows('kds_operator', ['kds']));
	$t->isFalse($perspective->allows('manager', ['dispatch']));
	$t->isTrue($perspective->allows('customer', ['kds']));
	$t->isFalse($perspective->allows('customer', ['billing']));

	$one=new SimulationRandom('replayable');
	$two=new SimulationRandom('replayable');
	$t->same([$one->float(), $one->int(2, 9), $one->pick(['a','b','c']), $one->shuffled([1,2,3])], [$two->float(), $two->int(2, 9), $two->pick(['a','b','c']), $two->shuffled([1,2,3])]);
	$t->throws(static fn()=>(new SimulationRandom('x'))->int(2, 1), InvalidArgumentException::class);
	$t->throws(static fn()=>(new SimulationRandom('x'))->pick([]), InvalidArgumentException::class);
})->tag('simulation','framework','determinism','safety')->maxMillis(5000);

test('simulation scenarios normalize controls and expose app-owned causal rules', static function(Context $t): void {
	$scenario=new SimulationScenario(' Restaurant Rush ', 'Restaurant rush', 'A busy service.', [
		'enabled_feature'=>['type'=>'boolean', 'default'=>'true'],
		'intensity'=>['type'=>'number', 'default'=>1.5, 'min'=>0.0, 'max'=>10.0],
		'party_size'=>['type'=>'integer', 'default'=>2, 'min'=>1, 'max'=>8],
		'weather'=>['type'=>'enum', 'default'=>'sunny', 'options'=>['sunny','rain']],
	]);
	$scenario->addRule(SimulationRule::every('customer.arrival', 'order.create', 'customer', ['kds'], 2.5, static fn()=>SimulationIntent::make('ignored', ['secret'=>'payload']))->priority(50)->probability(0.75));
	$t->same('restaurant_rush', $scenario->id());
	$t->same(['enabled_feature'=>true, 'intensity'=>1.5, 'party_size'=>2, 'weather'=>'sunny'], $scenario->defaultControls());
	$t->same(['enabled_feature'=>false, 'intensity'=>10.0, 'party_size'=>1, 'weather'=>'sunny'], $scenario->normalizeControls([
		'enabled_feature'=>'false', 'intensity'=>99, 'party_size'=>0, 'weather'=>'hail', 'unknown'=>'ignored',
	]));
	$t->same('customer.arrival', $scenario->rules()[0]->id());
	$t->same('order.create', $scenario->rules()[0]->intentType());
	$t->same(0.75, $scenario->rules()[0]->probabilityValue());
	$t->count(1, $scenario->rules()[0]->plan(
		new SimulationContext('restaurant', new SimulationScope(['store_id'=>1]), SimulationPerspective::forSurface('test'), [], new DateTimeImmutable('2026-07-16T12:00:00Z'), 'run'),
		[], new SimulationRandom('seed'),
	));
	$t->throws(static fn()=>new SimulationScenario('', 'No id'), InvalidArgumentException::class);
	$t->throws(static fn()=>new SimulationRule('', 'event', 'customer', ['kds'], 1, 1, static fn()=>null), InvalidArgumentException::class);
})->tag('simulation','framework','domain-contract')->maxMillis(5000);

test('simulation runtime unfolds causal chains while preserving surface agency', static function(Context $t): void {
	$applied=[];
	$adapter=new class($applied) implements SimulationDomainAdapter {
		/** @var array<int,array<string,mixed>> */ public array $applied=[];
		public function __construct(array $applied) { $this->applied=$applied; }
		public function snapshot(SimulationContext $context): array { return ['open_orders'=>count($this->applied), 'scope'=>$context->scope()->all()]; }
		public function apply(SimulationIntent $intent, SimulationContext $context): SimulationOutcome {
			$this->applied[]=['type'=>$intent->type(), 'payload'=>$intent->payload(), 'surface'=>$context->perspective()->surface()];
			if($intent->type()==='restaurant.order.received'){
				$followUp=(new SimulationIntent('restaurant.driver.arrived', ['order_ref'=>$intent->payload()['order_ref']], '', 'driver', ['dispatch']))->afterSeconds(10, $context->now());
				return SimulationOutcome::applied('order_created', ['business_reference'=>$intent->payload()['order_ref']], [$followUp]);
			}
			return SimulationOutcome::applied('driver_arrived', ['movement'=>'arrived']);
		}
	};
	$scenario=new SimulationScenario('rush', 'Rush', '', [
		'time_scale'=>['type'=>'number','default'=>1.0,'min'=>0.05,'max'=>100],
		'intensity'=>['type'=>'number','default'=>1.0,'min'=>0,'max'=>10],
	], [
		SimulationRule::every('external.order', 'restaurant.order.received', 'customer', ['kds','dispatch'], 60, static fn(SimulationContext $context, array $snapshot, SimulationRandom $random)=>SimulationIntent::make('proposal', [
			'order_ref'=>'SIM-'.$random->int(1000,9999), 'snapshot_count'=>$snapshot['open_orders'],
		]))->priority(100),
		SimulationRule::every('operator.accept', 'restaurant.kds.accepted', 'kds', ['kds'], 1, static fn()=>SimulationIntent::make('proposal')),
	]);
	$registry=(new SimulationRegistry())->register('restaurant', $adapter, [$scenario]);
	$store=new InMemorySimulationStateStore();
	$runtime=new SimulationRuntime($registry, $store, new SimulationRuntimePolicy(true, 'test', ['test']), 8, 50, 50);
	$scope=new SimulationScope(['organization_id'=>4, 'store_id'=>9]);
	$config=$runtime->configure('restaurant', $scope, ['enabled'=>true, 'scenario'=>'rush', 'seed'=>'known-seed', 'controls'=>['time_scale'=>1, 'intensity'=>1]]);
	$t->isTrue($config['ok']);
	$t->same('simulation_configuration_saved', $config['status']);
	$t->isTrue($config['allowed']);

	$at=new DateTimeImmutable('2026-07-16T12:00:00.100000Z');
	$kds=SimulationPerspective::forSurface('kds', ['kds'], ['kds_operator']);
	$first=$runtime->tick('restaurant', $scope, $kds, $at);
	$t->same('simulation_tick_applied', $first->status());
	$t->same(1, $first->appliedCount());
	$t->same('restaurant.order.received', $adapter->applied[0]['type']);
	$t->same('kds', $adapter->applied[0]['surface']);
	$t->same(1, $runtime->status('restaurant', $scope)['pending_count']);

	$tooSoon=$runtime->tick('restaurant', $scope, $kds, new DateTimeImmutable('2026-07-16T12:00:00.600000Z'));
	$t->same('simulation_tick_idle', $tooSoon->status());
	$t->count(1, $adapter->applied);
	$delivery=SimulationPerspective::forSurface('dispatch', ['dispatch'], ['dispatcher']);
	$followUp=$runtime->tick('restaurant', $scope, $delivery, $at->modify('+11 seconds'));
	$t->same(1, $followUp->appliedCount());
	$t->same('restaurant.driver.arrived', $adapter->applied[1]['type']);
	$t->same('dispatch', $adapter->applied[1]['surface']);
	$status=$runtime->status('restaurant', $scope);
	$t->same(0, $status['pending_count']);
	$t->isFalse(array_key_exists('payload', $status['journal'][0]));
	$t->same('order_created', $status['journal'][0]['status']);
})->tag('simulation','framework','causality','agency','journal')->maxMillis(5000);

test('simulation runtime fails closed in production and reports bounded adapter failures', static function(Context $t): void {
	$adapter=new class implements SimulationDomainAdapter {
		public int $applies=0;
		public bool $breakSnapshot=false;
		public function snapshot(SimulationContext $context): array {
			if($this->breakSnapshot) throw new RuntimeException('snapshot unavailable');
			return [];
		}
		public function apply(SimulationIntent $intent, SimulationContext $context): SimulationOutcome { $this->applies++; throw new RuntimeException('mutation failed'); }
	};
	$scenario=new SimulationScenario('default', 'Default', '', [], [
		SimulationRule::every('event', 'domain.event', 'external', ['surface'], 1, static fn()=>SimulationIntent::make('event', ['private'=>'never journal'])),
	]);
	$registry=(new SimulationRegistry())->register('domain', $adapter, [$scenario]);
	$scope=new SimulationScope(['tenant_id'=>1]);
	$store=new InMemorySimulationStateStore();
	$development=new SimulationRuntime($registry, $store, new SimulationRuntimePolicy(true, 'development', ['development']));
	$t->isTrue($development->configure('domain', $scope, ['enabled'=>true, 'seed'=>'safe'])['ok']);
	$production=new SimulationRuntime($registry, $store, new SimulationRuntimePolicy(true, 'production', ['production']));
	$revisionBefore=$store->load('domain', $scope)['revision'] ?? null;
	$productionConfiguration=$production->configure('domain', $scope, ['enabled'=>true, 'scenario'=>'default']);
	$t->isFalse($productionConfiguration['ok']);
	$t->same('simulation_forbidden_in_production', $productionConfiguration['status']);
	$t->same($revisionBefore, $store->load('domain', $scope)['revision'] ?? null);
	$blocked=$production->tick('domain', $scope, SimulationPerspective::forSurface('viewer', ['surface']), new DateTimeImmutable('2026-07-16T12:00:00Z'));
	$t->same('simulation_forbidden_in_production', $blocked->status());
	$t->same(0, $adapter->applies);

	$partial=$development->tick('domain', $scope, SimulationPerspective::forSurface('viewer', ['surface']), new DateTimeImmutable('2026-07-16T12:00:01Z'));
	$t->isFalse($partial->ok());
	$t->same('simulation_tick_partial_failure', $partial->status());
	$t->same(0, $partial->appliedCount());
	$t->same('simulation_adapter_failed', $partial->events()[0]['status']);
	$t->isFalse(array_key_exists('payload', $partial->events()[0]));

	$adapter->breakSnapshot=true;
	$snapshotFailure=$development->tick('domain', $scope, SimulationPerspective::forSurface('viewer', ['surface']), new DateTimeImmutable('2026-07-16T12:00:03Z'));
	$t->same('simulation_snapshot_failed', $snapshotFailure->status());

	$conflictingStore=new class implements SimulationStateStore {
		public function load(string $domain, SimulationScope|string $scope): ?array { return null; }
		public function save(string $domain, SimulationScope|string $scope, array $state, int $expectedRevision): bool { return false; }
	};
	$conflicting=new SimulationRuntime($registry, $conflictingStore, new SimulationRuntimePolicy(true, 'test', ['test']));
	$t->same('simulation_state_conflict', $conflicting->configure('domain', $scope, ['enabled'=>true])['status']);
})->tag('simulation','framework','production-safety','failure')->maxMillis(5000);
