<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Deterministic causal runtime.
 *
 * The engine never knows how a business mutation works. It plans bounded,
 * idempotent intents and delegates them to the registered application adapter.
 */
final class SimulationRuntime {
	private int $maxEventsPerTick;
	private int $journalLimit;
	private int $pendingLimit;

	public function __construct(
		private SimulationRegistry $registry,
		private SimulationStateStore $store,
		private SimulationRuntimePolicy $policy,
		?int $maxEventsPerTick=null,
		?int $journalLimit=null,
		?int $pendingLimit=null,
	) {
		$this->maxEventsPerTick=max(1, min(100, $maxEventsPerTick ?? (int)$this->moduleConfig('max_events_per_tick', 8)));
		$this->journalLimit=max(10, min(5000, $journalLimit ?? (int)$this->moduleConfig('journal_limit', 200)));
		$this->pendingLimit=max(10, min(10000, $pendingLimit ?? (int)$this->moduleConfig('pending_limit', 500)));
	}

	/** @param array<string,mixed> $settings @return array<string,mixed> */
	public function configure(string $domain, SimulationScope|array $scope, array $settings): array {
		$scope=SimulationScope::from($scope);
		$default=$this->registry->defaultScenario($domain);
		if($default===null) return ['ok'=>false, 'status'=>'simulation_domain_not_registered'];
		$current=$this->store->load($domain, $scope);
		$session=SimulationSession::fromArray($current ?? [], $default);
		$scenarioId=strtolower(trim((string)($settings['scenario'] ?? $session->scenario() ?: $default)));
		$scenario=$this->registry->scenario($domain, $scenarioId);
		if($scenario===null) return ['ok'=>false, 'status'=>'simulation_scenario_not_registered'];
		$enabled=$this->boolean($settings['enabled'] ?? $session->enabled());
		if($enabled && !$this->policy->allows(true)){
			return ['ok'=>false, 'status'=>$this->policy->status(true)];
		}
		$controls=$scenario->normalizeControls(is_array($settings['controls'] ?? null) ? $settings['controls'] : $session->controls());
		$seed=array_key_exists('seed', $settings) ? trim((string)$settings['seed']) : null;
		$expected=$session->revision();
		$session->configure($enabled, $scenarioId, $controls, $seed);
		$session->advanceRevision();
		if(!$this->store->save($domain, $scope, $session->toArray(), $expected)){
			return ['ok'=>false, 'status'=>'simulation_state_conflict', 'retry_safe'=>true];
		}
		return array_replace($this->status($domain, $scope), ['status'=>'simulation_configuration_saved']);
	}

	/** @return array<string,mixed> */
	public function status(string $domain, SimulationScope|array $scope): array {
		$scope=SimulationScope::from($scope);
		$default=$this->registry->defaultScenario($domain);
		if($default===null) return ['ok'=>false, 'status'=>'simulation_domain_not_registered', 'scenarios'=>[]];
		$state=$this->store->load($domain, $scope);
		$session=SimulationSession::fromArray($state ?? [], $default);
		$scenario=$this->registry->scenario($domain, $session->scenario()) ?? $this->registry->scenario($domain, $default);
		$controls=$scenario?->normalizeControls($session->controls()) ?? [];
		return [
			'ok'=>true,
			'status'=>'simulation_status_ready',
			'domain'=>$domain,
			'scope'=>$scope->all(),
			'scope_key'=>$scope->key(),
			'configured'=>$state!==null,
			'enabled'=>$session->enabled(),
			'allowed'=>$this->policy->allows($session->enabled()),
			'policy_status'=>$this->policy->status($session->enabled()),
			'scenario'=>$scenario?->id() ?? $default,
			'controls'=>$controls,
			'scenarios'=>$this->registry->describe($domain),
			'last_tick_at'=>$session->lastTickAt(),
			'pending_count'=>count($session->pending()),
			'journal'=>array_slice($session->journal(), -25),
			'revision'=>$session->revision(),
		];
	}

	public function tick(
		string $domain,
		SimulationScope|array $scope,
		SimulationPerspective $perspective,
		?DateTimeImmutable $now=null,
	): SimulationTickResult {
		$scope=SimulationScope::from($scope);
		$default=$this->registry->defaultScenario($domain);
		$adapter=$this->registry->adapter($domain);
		if($default===null || $adapter===null) return SimulationTickResult::failed('simulation_domain_not_registered');
		$stored=$this->store->load($domain, $scope);
		if($stored===null) return SimulationTickResult::idle('simulation_not_configured');
		$session=SimulationSession::fromArray($stored, $default);
		if(!$this->policy->allows($session->enabled())) return SimulationTickResult::idle($this->policy->status($session->enabled()), $session->revision());
		$scenario=$this->registry->scenario($domain, $session->scenario());
		if($scenario===null) return SimulationTickResult::failed('simulation_scenario_not_registered', [], $session->revision());

		$now=($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
		$controls=$scenario->normalizeControls($session->controls());
		$runId='sim_'.substr(hash('sha256', implode(':', [$domain, $scope->key(), $session->revision(), $session->cursor(), $now->format('U.u')])), 0, 32);
		$context=new SimulationContext($domain, $scope, $perspective, $controls, $now, $runId);
		try{
			$snapshot=$adapter->snapshot($context);
		}catch(Throwable $failure){
			return SimulationTickResult::failed('simulation_snapshot_failed', [$failure::class], $session->revision());
		}

		$random=new SimulationRandom($session->seed(), $session->cursor());
		$ruleRuns=$session->ruleRuns();
		$pending=[];
		$journal=$session->journal();
		$events=[];
		$errors=[];
		$stateChanged=false;

		foreach($session->pending() as $pendingState){
			$intent=SimulationIntent::fromArray($pendingState);
			if($intent===null){
				$stateChanged=true;
				continue;
			}
			if(!$intent->isDue($now) || !$perspective->allows($intent->origin(), $intent->affects()) || count($events)>=$this->maxEventsPerTick){
				$pending[]=$intent->jsonSerialize();
				continue;
			}
			$stateChanged=true;
			$this->applyIntent($adapter, $intent, $context, $events, $journal, $pending, $errors);
		}

		$timeScale=max(0.05, min(100.0, (float)($controls['time_scale'] ?? 1.0)));
		$intensity=max(0.0, min(10.0, (float)($controls['intensity'] ?? 1.0)));
		foreach($scenario->rules() as $rule){
			if(count($events)>=$this->maxEventsPerTick) break;
			if(!$perspective->allows($rule->origin(), $rule->affects())) continue;
			$interval=max(0.05, $rule->everySeconds()/$timeScale);
			$last=(float)($ruleRuns[$rule->id()] ?? 0.0);
			if($last>0 && (float)$now->format('U.u')-$last<$interval) continue;
			$ruleRuns[$rule->id()]=(float)$now->format('U.u');
			$stateChanged=true;
			if(!$random->chance(min(1.0, $rule->probabilityValue()*$intensity))) continue;
			try{
				$planned=$rule->plan($context, $snapshot, $random);
			}catch(Throwable $failure){
				$errors[]='planner:'.$rule->id().':'.$failure::class;
				$journal[]=[
					'at'=>$now->format(DATE_ATOM), 'run_id'=>$runId, 'rule'=>$rule->id(),
					'status'=>'simulation_planner_failed', 'error'=>$failure::class,
				];
				continue;
			}
			foreach($planned as $index=>$proposal){
				if(count($events)>=$this->maxEventsPerTick) break;
				$intentId='intent_'.substr(hash('sha256', $runId.':'.$rule->id().':'.$index.':'.$random->cursor()), 0, 40);
				$correlationId='cause_'.substr(hash('sha256', $scope->key().':'.$rule->id().':'.$intentId), 0, 32);
				$intent=(new SimulationIntent(
					$rule->intentType(),
					$proposal->payload(),
					$intentId,
					$rule->origin(),
					$rule->affects(),
					$proposal->dueAt(),
					$correlationId,
					$proposal->causationId(),
				));
				if(!$intent->isDue($now)){
					$pending[]=$intent->jsonSerialize();
					continue;
				}
				$this->applyIntent($adapter, $intent, $context, $events, $journal, $pending, $errors);
			}
		}

		if(!$stateChanged && $events===[]) return SimulationTickResult::idle('simulation_tick_idle', $session->revision());
		$pending=array_slice($pending, -$this->pendingLimit);
		$journal=array_slice($journal, -$this->journalLimit);
		$expected=$session->revision();
		$session->recordTick($now->format(DATE_ATOM), $random->cursor(), $ruleRuns, $pending, $journal);
		$session->advanceRevision();
		if(!$this->store->save($domain, $scope, $session->toArray(), $expected)){
			return SimulationTickResult::failed('simulation_state_conflict', ['optimistic_state_write_failed'], $expected, $events);
		}
		$status=$errors!==[] ? 'simulation_tick_partial_failure' : ($events!==[] ? 'simulation_tick_applied' : 'simulation_tick_idle');
		return new SimulationTickResult($errors===[], $status, $events, $errors, $session->revision(), true);
	}

	/** @param array<int,array<string,mixed>> $events @param array<int,array<string,mixed>> $journal @param array<int,array<string,mixed>> $pending @param array<int,string> $errors */
	private function applyIntent(
		SimulationDomainAdapter $adapter,
		SimulationIntent $intent,
		SimulationContext $context,
		array &$events,
		array &$journal,
		array &$pending,
		array &$errors,
	): void {
		try{
			$outcome=$adapter->apply($intent, $context);
		}catch(Throwable $failure){
			$outcome=SimulationOutcome::failed('simulation_adapter_failed', [$failure::class]);
		}
		$event=$intent->evidence()+$outcome->jsonSerialize()+['at'=>$context->now()->format(DATE_ATOM), 'run_id'=>$context->runId()];
		$events[]=$event;
		$journal[]=$event;
		foreach($outcome->errors() as $error) $errors[]=$intent->type().':'.$error;
		foreach($outcome->followUps() as $index=>$followUp){
			$id=$followUp->id()!=='' ? $followUp->id() : 'intent_'.substr(hash('sha256', $intent->id().':follow-up:'.$index), 0, 40);
			$origin=$followUp->origin()!=='unknown' ? $followUp->origin() : $intent->origin();
			$affects=$followUp->affects()!==[] ? $followUp->affects() : $intent->affects();
			$correlation=$followUp->correlationId()!=='' ? $followUp->correlationId() : $intent->correlationId();
			$pending[]=$followUp->enveloped($id, $origin, $affects, $correlation, $intent->id())->jsonSerialize();
		}
	}

	private function moduleConfig(string $key, mixed $default): mixed {
		return class_exists('dataphyre\\simulation', false) ? \dataphyre\simulation::config($key, $default) : $default;
	}

	private function boolean(mixed $value): bool {
		if(is_bool($value)) return $value;
		if(is_int($value)) return $value===1;
		return filter_var((string)$value, FILTER_VALIDATE_BOOL);
	}
}
