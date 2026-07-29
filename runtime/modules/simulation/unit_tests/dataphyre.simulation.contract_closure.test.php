<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	function dp_simulation_kernel_traces(mixed ...$arguments): array {
		static $traces=[];
		if($arguments!==[]) $traces[]=$arguments;
		return $traces;
	}

	if(!function_exists(__NAMESPACE__.'\\tracelog')){
		function tracelog(mixed ...$arguments): void {
			dp_simulation_kernel_traces(...$arguments);
		}
	}
}

namespace {
	use Dataphyre\Simulation\InMemorySimulationStateStore;
	use Dataphyre\Simulation\Simulation;
	use Dataphyre\Simulation\SimulationContext;
	use Dataphyre\Simulation\SimulationDomainAdapter;
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
	use Dataphyre\Simulation\SimulationSession;
	use Dataphyre\Simulation\SimulationStateStore;
	use Dataphyre\Simulation\SimulationTickResult;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
			if(!defined($constant)) define($constant, $defaults);
		}
	}

	require_once dirname(__DIR__).'/Framework/Bootstrap.php';

	test('simulation value objects expose complete immutable projections', static function(Context $t): void {
		$scope=new SimulationScope(['tenant_id'=>7]);
		$t->same($scope, SimulationScope::from($scope));
		$t->same(['tenant_id'=>7], $scope->jsonSerialize());

		$perspective=SimulationPerspective::forSurface('Operator Console', ['orders'], ['manager']);
		$t->same('operator_console', $perspective->surface());
		$t->same(['orders'], $perspective->interests());
		$t->same(['operator_console','manager'], $perspective->blockedOrigins());
		$t->same([
			'surface'=>'operator_console',
			'interests'=>['orders'],
			'blocked_origins'=>['operator_console','manager'],
		], $perspective->jsonSerialize());

		$now=new DateTimeImmutable('2026-07-16T12:00:00Z');
		$context=new SimulationContext('orders', $scope, $perspective, ['speed'=>2], $now, 'run-1');
		$t->same('orders', $context->domain());
		$t->same(['speed'=>2], $context->controls());
		$t->same(2, $context->control('speed'));
		$t->same('fallback', $context->control('missing', 'fallback'));
		$t->same([
			'domain'=>'orders',
			'scope'=>$scope,
			'perspective'=>$perspective,
			'controls'=>['speed'=>2],
			'now'=>'2026-07-16T12:00:00+00:00',
			'run_id'=>'run-1',
		], $context->jsonSerialize());

		$intent=SimulationIntent::make('order.created');
		$outcome=new SimulationOutcome(true, '', ['reference'=>'A-1'], [$intent, 'ignored'], ['failure','failure','']);
		$t->isTrue($outcome->wasApplied());
		$t->same('applied', $outcome->status());
		$t->same(['reference'=>'A-1'], $outcome->summary());
		$t->same([$intent], $outcome->followUps());
		$t->same(['failure'], $outcome->errors());
		$t->same([
			'applied'=>true,
			'status'=>'applied',
			'summary'=>['reference'=>'A-1'],
			'errors'=>['failure'],
		], $outcome->jsonSerialize());
		$t->same('skipped', SimulationOutcome::skipped('', ['reason'=>'closed'])->status());
		$t->same(['boom'], SimulationOutcome::failed('failed', ['boom'])->errors());

		$tick=SimulationTickResult::failed('simulation_failed', ['boom'], 7, [['applied'=>true],['applied'=>false]]);
		$t->same(7, $tick->revision());
		$t->same([
			'ok'=>false,
			'status'=>'simulation_failed',
			'event_count'=>2,
			'applied_count'=>1,
			'events'=>[['applied'=>true],['applied'=>false]],
			'errors'=>['boom'],
			'revision'=>7,
			'retry_safe'=>true,
		], $tick->jsonSerialize());

		$store=new InMemorySimulationStateStore();
		$store->seed('orders', $scope, ['revision'=>3, 'nested'=>['value'=>1]]);
		$t->count(1, $store->all());
		$loaded=$store->load('orders', $scope);
		$loaded['nested']['value']=2;
		$t->same(1, $store->load('orders', $scope)['nested']['value']);

		$scenario=new SimulationScenario('closure', 'Closure', 'Complete projections.', [
			'label'=>['type'=>'string', 'default'=>'ready'],
		]);
		$t->same('Closure', $scenario->label());
		$t->same('Complete projections.', $scenario->description());
		$t->hasKey('label', $scenario->controlSchema());

		$adapter=new class implements SimulationDomainAdapter {
			public function snapshot(SimulationContext $context): array { return []; }
			public function apply(SimulationIntent $intent, SimulationContext $context): SimulationOutcome { return SimulationOutcome::applied(); }
		};
		$registry=(new SimulationRegistry())->register('Orders', $adapter, [$scenario]);
		$t->isTrue($registry->has('orders'));

		$nullRule=SimulationRule::every('nothing', 'order.none', 'external', ['orders'], 1, static fn()=>null);
		$t->same([], $nullRule->plan($context, [], new SimulationRandom('null-plan')));
		$mixedRule=SimulationRule::every('mixed', 'order.mixed', 'external', ['orders'], 1, static fn()=>[$intent, 'ignored']);
		$t->same([$intent], $mixedRule->plan($context, [], new SimulationRandom('mixed-plan')));

		$fresh=SimulationSession::fresh('Closure', 'known-seed');
		$t->same('closure', $fresh->scenario());
		$t->same('known-seed', $fresh->seed());
	})->tag('simulation','framework','value-objects','contract-closure')->maxMillis(5000);

	test('simulation kernel policy and facade fail closed through explicit dependencies', static function(Context $t): void {
		$t->notEmpty(\dataphyre\dp_simulation_kernel_traces());
		$t->isFalse(\dataphyre\simulation::config('enabled', true));
		$t->same('fallback', \dataphyre\simulation::config('missing', 'fallback'));

		$configuredPolicy=SimulationRuntimePolicy::fromConfig([], 'test');
		$t->same('simulation_module_disabled', $configuredPolicy->status());
		$t->isFalse($configuredPolicy->jsonSerialize()['allowed']);
		$t->same('simulation_session_disabled', (new SimulationRuntimePolicy(true, 'test', ['test']))->status(false));
		$t->same('simulation_forbidden_in_production', (new SimulationRuntimePolicy(true, 'production', ['production']))->status());
		$t->same('simulation_environment_not_allowed', (new SimulationRuntimePolicy(true, 'staging', ['test']))->status());
		$t->same('simulation_allowed', (new SimulationRuntimePolicy(true, 'test', ['test']))->status());

		$adapter=new class implements SimulationDomainAdapter {
			public function snapshot(SimulationContext $context): array { return []; }
			public function apply(SimulationIntent $intent, SimulationContext $context): SimulationOutcome { return SimulationOutcome::applied(); }
		};
		$scenario=new SimulationScenario('default', 'Default');

		Simulation::reset();
		try{
			$t->throws(static fn()=>Simulation::runtime(), LogicException::class);
			$registry=Simulation::registry();
			Simulation::register('facade', $adapter, [$scenario]);
			$t->isTrue($registry->has('facade'));
			Simulation::usePolicy(new SimulationRuntimePolicy(true, 'test', ['test']));
			Simulation::useStore(new InMemorySimulationStateStore());
			$first=Simulation::runtime();
			$t->same($first, Simulation::runtime());

			Simulation::usePolicy(new SimulationRuntimePolicy(true, 'test', ['test']));
			$second=Simulation::runtime();
			$t->isFalse($first===$second);
			Simulation::useStore(new InMemorySimulationStateStore());
			$t->isFalse($second===Simulation::runtime());
		}finally{
			Simulation::reset();
		}

		Simulation::useStore(new InMemorySimulationStateStore());
		$t->isTrue(Simulation::runtime() instanceof SimulationRuntime);
		Simulation::reset();
		$t->isFalse($registry===Simulation::registry());
		Simulation::reset();
	})->tag('simulation','framework','kernel','policy','facade')->maxMillis(5000);

	test('simulation runtime quarantines malformed pending work and preserves retry safety', static function(Context $t): void {
		$adapter=new class implements SimulationDomainAdapter {
			public function snapshot(SimulationContext $context): array { return []; }
			public function apply(SimulationIntent $intent, SimulationContext $context): SimulationOutcome { return SimulationOutcome::applied(); }
		};
		$scenario=new SimulationScenario('closure', 'Closure', '', [
			'time_scale'=>['type'=>'number', 'default'=>1.0],
			'intensity'=>['type'=>'number', 'default'=>1.0],
		], [
			SimulationRule::every('planner.failure', 'domain.failed', 'external', ['surface'], 1, static function(): never {
				throw new RuntimeException('planner failed');
			})->priority(100),
			SimulationRule::every('future.intent', 'domain.future', 'external', ['surface'], 1, static function(SimulationContext $context): SimulationIntent {
				return SimulationIntent::make('proposal')->afterSeconds(60, $context->now());
			})->priority(90),
		]);
		$registry=(new SimulationRegistry())->register('domain', $adapter, [$scenario]);
		$policy=new SimulationRuntimePolicy(true, 'test', ['test']);
		$scope=new SimulationScope(['tenant_id'=>1]);
		$state=[
			'enabled'=>true,
			'scenario'=>'closure',
			'seed'=>'closure-seed',
			'cursor'=>0,
			'controls'=>['time_scale'=>1.0, 'intensity'=>1.0],
			'last_tick_at'=>null,
			'rule_runs'=>[],
			'pending'=>[['id'=>'missing-type']],
			'journal'=>[],
			'revision'=>1,
		];

		$store=new InMemorySimulationStateStore();
		$store->seed('domain', $scope, $state);
		$runtime=new SimulationRuntime($registry, $store, $policy);
		$result=$runtime->tick('domain', $scope, SimulationPerspective::forSurface('viewer', ['surface']), new DateTimeImmutable('2026-07-16T12:00:00Z'));
		$t->same('simulation_tick_partial_failure', $result->status());
		$status=$runtime->status('domain', $scope);
		$t->same(1, $status['pending_count']);
		$t->same('simulation_planner_failed', $status['journal'][0]['status']);

		$t->isTrue($runtime->configure('domain', ['tenant_id'=>2], ['enabled'=>1, 'scenario'=>'closure', 'seed'=>'integer'])['ok']);
		$t->isTrue($runtime->configure('domain', ['tenant_id'=>3], ['enabled'=>'true', 'scenario'=>'closure', 'seed'=>'string'])['ok']);

		$conflictingStore=new class($state) implements SimulationStateStore {
			public function __construct(private array $state) {}
			public function load(string $domain, SimulationScope|string $scope): ?array { return $this->state; }
			public function save(string $domain, SimulationScope|string $scope, array $state, int $expectedRevision): bool { return false; }
		};
		$conflicting=new SimulationRuntime($registry, $conflictingStore, $policy);
		$conflict=$conflicting->tick('domain', $scope, SimulationPerspective::forSurface('viewer', ['surface']), new DateTimeImmutable('2026-07-16T12:00:00Z'));
		$t->same('simulation_state_conflict', $conflict->status());
		$t->same(1, $conflict->revision());
	})->tag('simulation','framework','runtime','contract-closure')->maxMillis(5000);
}
