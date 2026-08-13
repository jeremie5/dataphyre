<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace {
	if(!function_exists('tracelog')){
		function tracelog(...$args): void {}
	}
	if(!function_exists('dp_module_required')){
		function dp_module_required(...$args): bool { return true; }
	}
	if(!function_exists('sql_define_table')){
		function sql_define_table(...$args): void {}
	}
	if(!defined('DP_ACEIT_WORKER_SCENARIO_LOADED') && !class_exists(DpAceItWorkerScenario::class,false)){
		define('DP_ACEIT_WORKER_SCENARIO_LOADED', true);
		final class DpAceItWorkerScenario {
			public static function begin(): void {
				\dataphyre_dpanel_worker_fixture_state::resetSql();
				\dataphyre_dpanel_worker_application_state::replaceSession([]);
			}

			/** @param array<string,mixed> $experiments */
			public static function withOngoingExperiments(array $experiments): void {
				self::begin();
				\dataphyre_dpanel_worker_application_state::replaceSession(['ongoing_experiments'=>$experiments]);
			}

			/** @return array<string,mixed> */
			public static function experiment(string $name): array {
				$experiments=\dataphyre_dpanel_worker_application_state::sessionValue('ongoing_experiments',[]);
				return is_array($experiments[$name] ?? null) ? $experiments[$name] : [];
			}

			/** @param array<string,mixed> $definition @param ?array<string,mixed> $ongoing */
			public static function experimentLifecycle(string $name, array $definition, ?array $ongoing=null): void {
				self::begin();
				self::replaceExperimentList([$name=>$definition]);
				if($ongoing!==null){
					\dataphyre_dpanel_worker_application_state::replaceSession([
						'ongoing_experiments'=>[$name=>$ongoing],
					]);
				}
			}

			/** @return array<string,mixed> */
			public static function insertedExperiment(): array {
				$call=\dataphyre_dpanel_worker_fixture_state::sqlCall('insert');
				return is_array($call[1] ?? null) ? $call[1] : [];
			}

			/** @param array<string,mixed> $experiments */
			public static function replaceExperimentList(array $experiments): void {
				self::experimentListAccess()($experiments,true);
			}

			/** @return array<string,mixed> */
			public static function experimentList(): array {
				return self::experimentListAccess()(null,false);
			}

			private static function experimentListAccess(): \Closure {
				$access=\Closure::bind(
					static function(?array $replacement,bool $replace): array {
						if($replace){
							\dataphyre\aceit_engine::$experiment_list=$replacement ?? [];
						}
						return \dataphyre\aceit_engine::$experiment_list;
					},
					null,
					\dataphyre\aceit_engine::class,
				);
				if(!$access instanceof \Closure){
					throw new \RuntimeException('Unable to access the AceIt experiment fixture state.');
				}
				return $access;
			}
		}
	}
	if(!function_exists('sql_query')){
		function sql_query(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::dispatchSql('query',$args,[]);
		}
	}
	if(!function_exists('sql_select')){
		function sql_select(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::dispatchSql('select',$args,false);
		}
	}
	if(!function_exists('sql_insert')){
		function sql_insert(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::dispatchSql('insert',$args,false);
		}
	}
}

namespace {
	require_once __DIR__.'/../aceit_engine.main.php';
}

namespace dataphyre {
	if(!class_exists(DatePeriod::class, false)){
		class DatePeriod extends \DatePeriod {}
	}
	if(!class_exists(DateTime::class, false)){
		class DateTime extends \DateTime {}
	}
	if(!class_exists(DateInterval::class, false)){
		class DateInterval extends \DateInterval {}
	}
}

namespace {
	function dp_aceit_engine_unit_session_flow_json(): string {
		DpAceItWorkerScenario::withOngoingExperiments([
			'button_copy'=>[
				'group'=>'variant',
				'events'=>[],
			],
		]);
		$group=\dataphyre\aceit_engine::get_group('button_copy');
		$fallback=\dataphyre\aceit_engine::get_group('missing');
		\dataphyre\aceit_engine::event('click', ['button'=>'checkout'], 'button_copy', 'missing');
		$event=DpAceItWorkerScenario::experiment('button_copy')['events'][0] ?? [];
		return json_encode([
			'event_name'=>$event['name'] ?? null,
			'event_value'=>$event['value'] ?? null,
			'fallback'=>$fallback,
			'group'=>$group,
			'has_time'=>isset($event['time']) && is_float($event['time']),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_aceit_engine_unit_import_precedence_json(): string {
		DpAceItWorkerScenario::begin();
		DpAceItWorkerScenario::replaceExperimentList([]);

		\dataphyre\aceit_engine::import_experiments([
			'exp_existing'=>['group'=>'first', 'count'=>1],
		]);
		\dataphyre\aceit_engine::import_experiments([
			'exp_existing'=>['group'=>'second', 'count'=>2],
			'exp_new'=>['group'=>'new', 'count'=>3],
		]);
		$list=DpAceItWorkerScenario::experimentList();
		ksort($list);
		return json_encode($list, JSON_UNESCAPED_SLASHES);
	}

	function dp_aceit_engine_unit_chart_json(): string {
		DpAceItWorkerScenario::begin();
		\dataphyre_dpanel_worker_fixture_state::respondToSql('query',static function(string $query, array $params): array {
			return [
				['group'=>'control', 'experiment_date'=>'2026-01-01', 'total_score'=>5],
				['group'=>'control', 'experiment_date'=>'2026-01-03', 'total_score'=>7],
			];
		});
		$chart=\dataphyre\aceit_engine::chart_experiment('checkout_copy', 'control', [
			'start_date'=>'2026-01-01',
			'end_date'=>'2026-01-03',
		]);
		ksort($chart['control']);
		$queryCall=\dataphyre_dpanel_worker_fixture_state::sqlCall('query');
		return json_encode([
			'query'=>[
				'has_group_filter'=>str_contains((string)($queryCall[0] ?? ''),'AND `group`=?'),
				'has_date_filter'=>str_contains((string)($queryCall[0] ?? ''),'BETWEEN ? AND ?'),
				'params'=>$queryCall[1] ?? [],
			],
			'chart'=>$chart,
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_aceit_engine_unit_leading_group_json(): string {
		DpAceItWorkerScenario::begin();
		$saved=[];
		DpAceItWorkerScenario::replaceExperimentList([
			'checkout_banner'=>[
				'count'=>8,
				'save_callback'=>static function(array $experiment) use (&$saved): void {
					$saved=$experiment;
				},
			],
		]);
		\dataphyre_dpanel_worker_fixture_state::respondToSql('query',static function(string $query, array $params): array {
			return [
				['group'=>'variant_b', 'total_score'=>23],
			];
		});

		$leading_group=\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\aceit_engine::class,'get_leading_test_group',['checkout_banner']);
		$list=DpAceItWorkerScenario::experimentList();
		$queryCall=\dataphyre_dpanel_worker_fixture_state::sqlCall('query');
		return json_encode([
			'leading_group'=>$leading_group,
			'query'=>[
				'has_sum'=>str_contains((string)($queryCall[0] ?? ''),'SUM(score) as total_score'),
				'has_order'=>str_contains((string)($queryCall[0] ?? ''),'ORDER BY total_score DESC LIMIT 1'),
				'params'=>$queryCall[1] ?? [],
			],
			'is_finished'=>$list['checkout_banner']['is_finished'] ?? false,
			'saved_finished'=>$saved['is_finished'] ?? false,
			'saved_count'=>$saved['count'] ?? null,
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_aceit_engine_unit_aggregate_daily_json(): string {
		DpAceItWorkerScenario::begin();
		$saved=[];
		DpAceItWorkerScenario::replaceExperimentList([
			'pricing_copy'=>[
				'count'=>12,
				'save_callback'=>static function(array $experiment) use (&$saved): void {
					$saved=$experiment;
				},
			],
		]);
		\dataphyre_dpanel_worker_fixture_state::respondToSql('query',static function(string $query, array $params): array {
			if(str_starts_with($query, 'SELECT DISTINCT')){
				return [
					['group'=>'control'],
					['group'=>'variant'],
				];
			}
			if(str_contains($query, "DATE_FORMAT(experiment_date, '%Y-%m-%d')") && ($params[1] ?? null)==='control'){
				return [
					['group'=>'control', 'total_score'=>8],
				];
			}
			if(str_contains($query, "DATE_FORMAT(experiment_date, '%Y-%m-%d')") && ($params[1] ?? null)==='variant'){
				return [
					['group'=>'variant', 'total_score'=>13],
				];
			}
			return [];
		});

		\dataphyre\aceit_engine::aggregate_experiment('pricing_copy', 'daily');
		$list=DpAceItWorkerScenario::experimentList();
		$calls=array_map(
			static fn(array $call): array=>['query'=>(string)($call[0] ?? ''),'params'=>$call[1] ?? []],
			\dataphyre_dpanel_worker_fixture_state::sqlCalls('query')
		);
		return json_encode([
			'query_count'=>count($calls),
			'distinct_params'=>$calls[0]['params'] ?? null,
			'uses_daily_granulation'=>isset($calls[1]['query']) && str_contains($calls[1]['query'], "DATE_FORMAT(experiment_date, '%Y-%m-%d')"),
			'control_insert_params'=>$calls[2]['params'] ?? null,
			'control_delete_params'=>$calls[3]['params'] ?? null,
			'variant_insert_params'=>$calls[5]['params'] ?? null,
			'variant_delete_params'=>$calls[6]['params'] ?? null,
			'is_aggregated'=>$list['pricing_copy']['is_aggregated'] ?? false,
			'saved_aggregated'=>$saved['is_aggregated'] ?? false,
		], JSON_UNESCAPED_SLASHES);
	}
}
