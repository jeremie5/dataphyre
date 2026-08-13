<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace {
	if(!defined('RUN_MODE')){
		define('RUN_MODE', 'diagnostic');
	}
	if(!defined('DPID')){
		define('DPID', 'unit-dpid');
	}
	if(!defined('RQID')){
		define('RQID', 'unit-rqid');
	}
	if(!function_exists('tracelog')){
		function tracelog(...$args): void {}
	}
	if(!function_exists('sql_define_table')){
		function sql_define_table(...$args): void {}
	}
	if(!function_exists('dp_module_required')){
		function dp_module_required(...$args): bool { return true; }
	}
	if(!defined('DATAPHYRE_TIME_MACHINE_UNIT_USER_STUB_LOADED')){
		define('DATAPHYRE_TIME_MACHINE_UNIT_USER_STUB_LOADED', true);
		class user {
			public static function get(int $userid): array|false {
				return [
					'userid'=>$userid,
					'preferences'=>[
						'lang'=>'en',
					],
				];
			}

			public static function clear_cache(int $userid): void {}
		}
	}
	if(!defined('DP_TIME_MACHINE_WORKER_SCENARIO_LOADED') && !class_exists(DpTimeMachineWorkerScenario::class,false)){
		define('DP_TIME_MACHINE_WORKER_SCENARIO_LOADED', true);
		final class DpTimeMachineWorkerScenario {
			public static function begin(): void {
				\dataphyre_dpanel_worker_fixture_state::resetSql();
				\dataphyre_dpanel_worker_application_state::forgetAuthenticatedUserId();
				\dataphyre\core::$dialbacks=[];
				\dataphyre\core::$dialback_calls=[];
			}

			public static function asAuthenticatedUser(int $userid): void {
				\dataphyre_dpanel_worker_application_state::authenticatedUserId($userid);
			}

			/** @param array<string,mixed> $payload */
			public static function rollbackRecord(
				string $rollbackType,
				array $payload,
				int $owner=42,
				bool $canRollback=true
			): void {
				\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', [
					'userid'=>$owner,
					'can_rollback'=>$canRollback,
					'data'=>json_encode($payload, JSON_THROW_ON_ERROR),
					'rollback_type'=>$rollbackType,
				]);
			}

			public static function malformedRollbackRecord(int $owner=42): void {
				\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', [
					'userid'=>$owner,
					'can_rollback'=>true,
					'data'=>'not-json',
					'rollback_type'=>'SQL_INSERT',
				]);
			}

			public static function allMutationsSucceed(): void {
				\dataphyre_dpanel_worker_fixture_state::returnFromSql('delete', true);
				\dataphyre_dpanel_worker_fixture_state::returnFromSql('insert', ['changeid'=>'unit-change']);
				\dataphyre_dpanel_worker_fixture_state::returnFromSql('update', true);
			}

			public static function rollback(
				string $changeid='unit-change',
				int $owner=42,
				int $requester=0
			): bool {
				return \dataphyre\time_machine::rollback($changeid, $owner, $requester);
			}

			public static function sqlCalls(string $operation): int {
				return \dataphyre_dpanel_worker_fixture_state::sqlCallCount($operation);
			}

			public static function dialbackCalls(string $hook): int {
				return count(array_filter(
					\dataphyre\core::$dialback_calls,
					static fn(array $call): bool=>$call['hook']===$hook
				));
			}
		}
	}
}

namespace dataphyre {
	if(!class_exists(__NAMESPACE__.'\\dpanel', false)){
		class dpanel{
			public static function add_verbose(?array $verboses): void {}
		}
	}
	if(!class_exists(__NAMESPACE__.'\\core', false)){
		class core{
			public static array $dialbacks=[];
			public static array $dialback_calls=[];

			public static function dialback(string $hook, mixed ...$args): mixed {
				self::$dialback_calls[]=[
					'hook'=>$hook,
					'args'=>$args,
				];
				$result=null;
				foreach((array)(self::$dialbacks[$hook] ?? []) as $callback){
					$result=is_callable($callback) ? $callback(...$args) : $callback;
				}
				return $result;
			}

			public static function register_dialback(string $hook, callable $callback): bool {
				self::$dialbacks[$hook][]=$callback;
				return true;
			}

			public static function encrypt_data(mixed $data, array $keys=[]): mixed { return $data; }
			public static function decrypt_data(mixed $data, array $keys=[]): mixed { return $data; }
		}
	}
}

namespace {
	if(!function_exists('sql_select')){
		function sql_select(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::dispatchSql('select',$args,false);
		}
	}
	if(!function_exists('sql_update')){
		function sql_update(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::dispatchSql('update',$args,false);
		}
	}
	if(!function_exists('sql_delete')){
		function sql_delete(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::dispatchSql('delete',$args,false);
		}
	}
	if(!function_exists('sql_insert')){
		function sql_insert(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::dispatchSql('insert',$args,false);
		}
	}

	require_once __DIR__.'/../kernel/time_machine.main.php';

	function dp_time_machine_unit_change_id_shape(): bool {
		$first=\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\time_machine::class,'change_id');
		$second=\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\time_machine::class,'change_id');
		return is_string($first)
			&& is_string($second)
			&& $first!==$second
			&& preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first)===1
			&& preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $second)===1;
	}

	function dp_time_machine_unit_dialback_short_circuit_json(): string {
		DpTimeMachineWorkerScenario::begin();
		\dataphyre\core::$dialbacks=[];
		\dataphyre\core::register_dialback('CALL_TIME_MACHINE_CREATE', static fn(): bool => true);
		\dataphyre\core::register_dialback('CALL_TIME_MACHINE_ROLLBACK', static fn(): bool => false);
		\dataphyre\core::$dialback_calls=[];
		$create=\dataphyre\time_machine::create('settings', 'USER_PARAMETER', ['setting_name'=>'locale'], true);
		$purge=\dataphyre\time_machine::purge_old('14 days');
		$rollback=\dataphyre\time_machine::rollback('changeid123', 42, 42);
		$result=[
			'create'=>$create,
			'purge'=>$purge,
			'rollback'=>$rollback,
			'hooks'=>array_column(\dataphyre\core::$dialback_calls, 'hook'),
			'sql_calls'=>\dataphyre_dpanel_worker_fixture_state::sqlCallCount(),
		];
		\dataphyre\core::$dialbacks=[];
		return json_encode($result, JSON_UNESCAPED_SLASHES);
	}

	function dp_time_machine_unit_create_basic(): string|false {
		DpTimeMachineWorkerScenario::begin();
		DpTimeMachineWorkerScenario::asAuthenticatedUser(42);
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('insert',['changeid'=>'changeid123']);
		return \dataphyre\time_machine::create('setting', 'USER_PARAMETER', ['setting_name'=>'lang', 'old_value'=>'fr'], true);
	}

	function dp_time_machine_unit_rollback_success(): bool {
		DpTimeMachineWorkerScenario::begin();
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('select',[
			'userid'=>42,
			'can_rollback'=>true,
			'data'=>json_encode(['setting_name'=>'lang', 'old_value'=>'fr']),
			'rollback_type'=>'USER_PARAMETER',
		]);
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('update',['unit_update'=>true]);
		return \dataphyre\time_machine::rollback('changeid123', 42, 0);
	}

	function dp_time_machine_unit_rollback_unknown_type_json(): string {
		DpTimeMachineWorkerScenario::begin();
		\dataphyre\core::$dialbacks=[];
		\dataphyre\core::$dialback_calls=[];
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('select',[
			'userid'=>9,
			'can_rollback'=>true,
			'data'=>json_encode(['table'=>'unit_table']),
			'rollback_type'=>'UNKNOWN',
		]);
		$result=\dataphyre\time_machine::rollback('changeid-unknown', 9, 9);
		$mutations=\dataphyre_dpanel_worker_fixture_state::sqlCallCount('insert')
			+\dataphyre_dpanel_worker_fixture_state::sqlCallCount('update')
			+\dataphyre_dpanel_worker_fixture_state::sqlCallCount('delete');
		return json_encode([
			'result'=>$result,
			'selects'=>\dataphyre_dpanel_worker_fixture_state::sqlCallCount('select'),
			'mutations'=>$mutations,
			'hooks'=>array_column(\dataphyre\core::$dialback_calls, 'hook'),
		], JSON_UNESCAPED_SLASHES);
	}
}
