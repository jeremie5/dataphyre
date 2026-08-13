<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace {

	final class DpTableQueryCoverageState {
		/** @var array<string,array<int,mixed>> */
		public static array $results=[];
		/** @var array<string,array<int,array<int,mixed>>> */
		public static array $calls=[];

		public static function reset(): void {
			self::$results=[];
			self::$calls=[];
			\dataphyre\sql::resetCoverageState();
		}

		public static function set(string $operation, mixed ...$results): void {
			self::$results[$operation]=$results;
		}

		public static function push(string $operation, mixed ...$results): void {
			self::$results[$operation]=array_merge(self::$results[$operation] ?? [], $results);
		}

		public static function next(string $operation, mixed $default): mixed {
			if((self::$results[$operation] ?? [])===[]){
				return $default;
			}
			return array_shift(self::$results[$operation]);
		}

		public static function dispatch(
			string $operation,
			array $arguments,
			mixed $default,
			?int $callbackIndex=null
		): mixed {
			self::$calls[$operation][]=$arguments;
			$result=self::next($operation, $default);
			$callback=$callbackIndex===null ? null : ($arguments[$callbackIndex] ?? null);
			if(!is_callable($callback)){
				return $result;
			}
			$queueResult=self::next($operation.'_queue_return', null);
			if($queueResult===false){
				return false;
			}
			$callback($result);
			return $queueResult;
		}

		public static function defaultSelect(array $arguments): mixed {
			$columns=is_array($arguments[0] ?? null)
				? implode(', ', $arguments[0])
				: (string)($arguments[0] ?? '*');
			$all=($arguments[4] ?? true)===true;
			if(str_contains($columns, 'aggregate_value')){
				return $all
					? [
						['group_id'=>'a', 'aggregate_value'=>'2'],
						['group_id'=>'b', 'aggregate_value'=>'3.5'],
					]
					: ['aggregate_value'=>'2'];
			}
			$row=['id'=>'1', 'name'=>'Ada', 'group_id'=>'a', 'amount'=>'12.5', 'currency'=>'USD', 'lock_version'=>1];
			return $all ? [$row, ['id'=>'2', 'name'=>'Grace', 'group_id'=>'b', 'amount'=>'7.5', 'currency'=>'USD', 'lock_version'=>2]] : $row;
		}
	}

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}

	if(!function_exists('sql_select')){
		function sql_select(mixed ...$arguments): mixed {
			return DpTableQueryCoverageState::dispatch(
				'select',
				$arguments,
				DpTableQueryCoverageState::defaultSelect($arguments),
				7
			);
		}
	}

	if(!function_exists('sql_count')){
		function sql_count(mixed ...$arguments): mixed {
			return DpTableQueryCoverageState::dispatch('count', $arguments, 2, 5);
		}
	}

	if(!function_exists('sql_insert')){
		function sql_insert(mixed ...$arguments): mixed {
			return DpTableQueryCoverageState::dispatch('insert', $arguments, 10, 5);
		}
	}

	if(!function_exists('sql_update')){
		function sql_update(mixed ...$arguments): mixed {
			return DpTableQueryCoverageState::dispatch('update', $arguments, 1, 6);
		}
	}

	if(!function_exists('sql_delete')){
		function sql_delete(mixed ...$arguments): mixed {
			return DpTableQueryCoverageState::dispatch('delete', $arguments, 1, 5);
		}
	}

	if(!function_exists('sql_upsert')){
		function sql_upsert(mixed ...$arguments): mixed {
			return DpTableQueryCoverageState::dispatch('upsert', $arguments, 1, 6);
		}
	}
}

namespace dataphyre {
	if(!class_exists(sql::class, false)){
		final class sql {
			/** @var array<int,bool> */
			public static array $hydrateResults=[];
			public static int $clearCalls=0;
			public static int $hydrateCalls=0;
			public static int $invalidateCalls=0;

			public static function resetCoverageState(): void {
				self::$hydrateResults=[];
				self::$clearCalls=0;
				self::$hydrateCalls=0;
				self::$invalidateCalls=0;
			}

			public static function clear_last_query_error(): void {
				self::$clearCalls++;
			}

			public static function hydrate_missing_structure_from_definition(string $table): bool {
				self::$hydrateCalls++;
				return self::$hydrateResults===[] ? false : (bool)array_shift(self::$hydrateResults);
			}

			public static function invalidate_cache(string $table): void {
				self::$invalidateCalls++;
			}

			public static function hydrate_table_definition(string $table): bool {
				return true;
			}
		}
	}
}

namespace {
	if(!defined('DATAPHYRE_MODULE_POLICY')){
		define('DATAPHYRE_MODULE_POLICY', [
			'enabled'=>['core'=>true, 'currency'=>true, 'sql'=>true],
			'disabled'=>[],
			'core_implicit'=>true,
		]);
	}
	$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
	require_once $modulesRoot.'/core/kernel/autoloader.php';
	require_once $modulesRoot.'/core/kernel/helper_functions.php';
	require_once $modulesRoot.'/core/kernel/core_functions.php';
	\dataphyre\autoloader::register($modulesRoot);
	\dataphyre\autoloader::register_framework_modules(['currency', 'sql']);
	if(!class_exists('dataphyre\\currency', false)){
		require_once $modulesRoot.'/currency/kernel/currency.main.php';
	}

	final class DpTableQueryHydrator implements \Dataphyre\Database\Contracts\RecordHydrator {
		public function hydrate(array $row, ?\Dataphyre\Database\TableSchema $schema=null): mixed {
			return ['hydrated'=>$row, 'schema'=>$schema?->table()];
		}
	}

	final class DpTableQueryPojo {
		public mixed $id=null;
		public mixed $name=null;
	}

	function dp_table_query_schema(): \Dataphyre\Database\TableSchema {
		return new \Dataphyre\Database\TableSchema(
			'coverage_items',
			[
				'id', 'alt_id', 'name', 'group_id', 'amount', 'currency', 'lock_version',
				'original_amount_minor', 'original_currency', 'base_amount_minor', 'base_currency',
				'exchange_rate', 'exchange_source', 'exchange_time', 'exchange_base_currency', 'stored_money',
			],
			['listing'=>['id', 'name', 'amount']],
			'id',
			['id'=>'integer', 'alt_id'=>'integer', 'amount'=>'float', 'lock_version'=>'integer']
		);
	}

	function dp_table_query_private(\Dataphyre\Test\Context $t,object $target,string $method,mixed ...$arguments): mixed {
		return $t->nonPublic($target)->invoke($method,...$arguments);
	}
}
