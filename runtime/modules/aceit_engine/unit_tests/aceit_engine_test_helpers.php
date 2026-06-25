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
	if(!function_exists('sql_query')){
		function sql_query(...$args): mixed {
			return is_callable($GLOBALS['dp_unit_sql_query'] ?? null)
				? $GLOBALS['dp_unit_sql_query'](...$args)
				: [];
		}
	}
	if(!function_exists('sql_select')){
		function sql_select(...$args): mixed {
			return is_callable($GLOBALS['dp_unit_sql_select'] ?? null)
				? $GLOBALS['dp_unit_sql_select'](...$args)
				: false;
		}
	}
	if(!function_exists('sql_insert')){
		function sql_insert(...$args): mixed {
			return is_callable($GLOBALS['dp_unit_sql_insert'] ?? null)
				? $GLOBALS['dp_unit_sql_insert'](...$args)
				: false;
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
		$_SESSION['ongoing_experiments']=[
			'button_copy'=>[
				'group'=>'variant',
				'events'=>[],
			],
		];
		$group=\dataphyre\aceit_engine::get_group('button_copy');
		$fallback=\dataphyre\aceit_engine::get_group('missing');
		\dataphyre\aceit_engine::event('click', ['button'=>'checkout'], 'button_copy', 'missing');
		$event=$_SESSION['ongoing_experiments']['button_copy']['events'][0] ?? [];
		return json_encode([
			'event_name'=>$event['name'] ?? null,
			'event_value'=>$event['value'] ?? null,
			'fallback'=>$fallback,
			'group'=>$group,
			'has_time'=>isset($event['time']) && is_float($event['time']),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_aceit_engine_unit_import_precedence_json(): string {
		$reflection=new \ReflectionClass(\dataphyre\aceit_engine::class);
		$experiments=$reflection->getProperty('experiment_list');
		$experiments->setAccessible(true);
		$experiments->setValue(null, []);

		\dataphyre\aceit_engine::import_experiments([
			'exp_existing'=>['group'=>'first', 'count'=>1],
		]);
		\dataphyre\aceit_engine::import_experiments([
			'exp_existing'=>['group'=>'second', 'count'=>2],
			'exp_new'=>['group'=>'new', 'count'=>3],
		]);
		$list=$experiments->getValue();
		ksort($list);
		return json_encode($list, JSON_UNESCAPED_SLASHES);
	}

	function dp_aceit_engine_unit_chart_json(): string {
		$GLOBALS['dp_unit_sql_query']=static function(string $query, array $params): array {
			$GLOBALS['dp_aceit_chart_query']=[
				'has_group_filter'=>str_contains($query, 'AND `group`=?'),
				'has_date_filter'=>str_contains($query, 'BETWEEN ? AND ?'),
				'params'=>$params,
			];
			return [
				['group'=>'control', 'experiment_date'=>'2026-01-01', 'total_score'=>5],
				['group'=>'control', 'experiment_date'=>'2026-01-03', 'total_score'=>7],
			];
		};
		$chart=\dataphyre\aceit_engine::chart_experiment('checkout_copy', 'control', [
			'start_date'=>'2026-01-01',
			'end_date'=>'2026-01-03',
		]);
		ksort($chart['control']);
		$result=json_encode([
			'query'=>$GLOBALS['dp_aceit_chart_query'],
			'chart'=>$chart,
		], JSON_UNESCAPED_SLASHES);
		unset($GLOBALS['dp_unit_sql_query']);
		return $result;
	}

	function dp_aceit_engine_unit_leading_group_json(): string {
		$reflection=new \ReflectionClass(\dataphyre\aceit_engine::class);
		$experiments=$reflection->getProperty('experiment_list');
		$experiments->setAccessible(true);
		$saved=[];
		$experiments->setValue(null, [
			'checkout_banner'=>[
				'count'=>8,
				'save_callback'=>static function(array $experiment) use (&$saved): void {
					$saved=$experiment;
				},
			],
		]);
		$GLOBALS['dp_unit_sql_query']=static function(string $query, array $params): array {
			$GLOBALS['dp_aceit_leading_query']=[
				'has_sum'=>str_contains($query, 'SUM(score) as total_score'),
				'has_order'=>str_contains($query, 'ORDER BY total_score DESC LIMIT 1'),
				'params'=>$params,
			];
			return [
				['group'=>'variant_b', 'total_score'=>23],
			];
		};

		$method=$reflection->getMethod('get_leading_test_group');
		$method->setAccessible(true);
		$leading_group=$method->invoke(null, 'checkout_banner');
		$list=$experiments->getValue();
		$result=json_encode([
			'leading_group'=>$leading_group,
			'query'=>$GLOBALS['dp_aceit_leading_query'],
			'is_finished'=>$list['checkout_banner']['is_finished'] ?? false,
			'saved_finished'=>$saved['is_finished'] ?? false,
			'saved_count'=>$saved['count'] ?? null,
		], JSON_UNESCAPED_SLASHES);
		unset($GLOBALS['dp_unit_sql_query']);
		return $result;
	}

	function dp_aceit_engine_unit_aggregate_daily_json(): string {
		$reflection=new \ReflectionClass(\dataphyre\aceit_engine::class);
		$experiments=$reflection->getProperty('experiment_list');
		$experiments->setAccessible(true);
		$saved=[];
		$experiments->setValue(null, [
			'pricing_copy'=>[
				'count'=>12,
				'save_callback'=>static function(array $experiment) use (&$saved): void {
					$saved=$experiment;
				},
			],
		]);
		$GLOBALS['dp_aceit_aggregate_queries']=[];
		$GLOBALS['dp_unit_sql_query']=static function(string $query, array $params): array {
			$GLOBALS['dp_aceit_aggregate_queries'][]=[
				'query'=>$query,
				'params'=>$params,
			];
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
		};

		\dataphyre\aceit_engine::aggregate_experiment('pricing_copy', 'daily');
		$list=$experiments->getValue();
		$calls=$GLOBALS['dp_aceit_aggregate_queries'];
		$result=json_encode([
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
		unset($GLOBALS['dp_unit_sql_query']);
		return $result;
	}
}
