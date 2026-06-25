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
			$GLOBALS['dp_time_machine_unit_sql_calls']['select'][]=$args;
			return $GLOBALS['dp_time_machine_unit_sql_select_result'] ?? false;
		}
	}
	if(!function_exists('sql_update')){
		function sql_update(...$args): mixed {
			$GLOBALS['dp_time_machine_unit_sql_calls']['update'][]=$args;
			return $GLOBALS['dp_time_machine_unit_sql_update_result'] ?? false;
		}
	}
	if(!function_exists('sql_delete')){
		function sql_delete(...$args): mixed {
			$GLOBALS['dp_time_machine_unit_sql_calls']['delete'][]=$args;
			return $GLOBALS['dp_time_machine_unit_sql_delete_result'] ?? false;
		}
	}
	if(!function_exists('sql_insert')){
		function sql_insert(...$args): mixed {
			$GLOBALS['dp_time_machine_unit_sql_calls']['insert'][]=$args;
			return $GLOBALS['dp_time_machine_unit_sql_insert_result'] ?? false;
		}
	}

	require_once __DIR__.'/../kernel/time_machine.main.php';

	function dp_time_machine_unit_install_sql_callbacks(): void {
		$GLOBALS['dp_unit_sql_select']=static function(...$args): mixed {
			$GLOBALS['dp_time_machine_unit_sql_calls']['select'][]=$args;
			return $GLOBALS['dp_time_machine_unit_sql_select_result'] ?? false;
		};
		$GLOBALS['dp_unit_sql_update']=static function(...$args): mixed {
			$GLOBALS['dp_time_machine_unit_sql_calls']['update'][]=$args;
			return $GLOBALS['dp_time_machine_unit_sql_update_result'] ?? false;
		};
		$GLOBALS['dp_unit_sql_delete']=static function(...$args): mixed {
			$GLOBALS['dp_time_machine_unit_sql_calls']['delete'][]=$args;
			return $GLOBALS['dp_time_machine_unit_sql_delete_result'] ?? false;
		};
		$GLOBALS['dp_unit_sql_insert']=static function(...$args): mixed {
			$GLOBALS['dp_time_machine_unit_sql_calls']['insert'][]=$args;
			return $GLOBALS['dp_time_machine_unit_sql_insert_result'] ?? false;
		};
	}

	function dp_time_machine_unit_change_id_shape(): bool {
		$reflection=new ReflectionClass(\dataphyre\time_machine::class);
		$method=$reflection->getMethod('change_id');
		$method->setAccessible(true);
		$first=$method->invoke(null);
		$second=$method->invoke(null);
		return is_string($first)
			&& is_string($second)
			&& $first!==$second
			&& preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first)===1
			&& preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $second)===1;
	}

	function dp_time_machine_unit_dialback_short_circuit_json(): string {
		dp_time_machine_unit_install_sql_callbacks();
		\dataphyre\core::$dialbacks=[];
		\dataphyre\core::register_dialback('CALL_TIME_MACHINE_CREATE', static fn(): bool => true);
		\dataphyre\core::register_dialback('CALL_TIME_MACHINE_ROLLBACK', static fn(): bool => false);
		\dataphyre\core::$dialback_calls=[];
		$GLOBALS['dp_time_machine_unit_sql_calls']=[];
		$create=\dataphyre\time_machine::create('settings', 'USER_PARAMETER', ['setting_name'=>'locale'], true);
		$purge=\dataphyre\time_machine::purge_old('14 days');
		$rollback=\dataphyre\time_machine::rollback('changeid123', 42, 42);
		$result=[
			'create'=>$create,
			'purge'=>$purge,
			'rollback'=>$rollback,
			'hooks'=>array_column(\dataphyre\core::$dialback_calls, 'hook'),
			'sql_calls'=>array_sum(array_map('count', $GLOBALS['dp_time_machine_unit_sql_calls'])),
		];
		\dataphyre\core::$dialbacks=[];
		return json_encode($result, JSON_UNESCAPED_SLASHES);
	}

	function dp_time_machine_unit_create_basic(): string|false {
		dp_time_machine_unit_install_sql_callbacks();
		$GLOBALS['userid']=42;
		$GLOBALS['dp_time_machine_unit_sql_insert_result']=['changeid'=>'changeid123'];
		$result=\dataphyre\time_machine::create('setting', 'USER_PARAMETER', ['setting_name'=>'lang', 'old_value'=>'fr'], true);
		unset($GLOBALS['dp_time_machine_unit_sql_insert_result']);
		return $result;
	}

	function dp_time_machine_unit_rollback_success(): bool {
		dp_time_machine_unit_install_sql_callbacks();
		$GLOBALS['dp_time_machine_unit_sql_select_result']=[
			'userid'=>42,
			'can_rollback'=>true,
			'data'=>json_encode(['setting_name'=>'lang', 'old_value'=>'fr']),
			'rollback_type'=>'USER_PARAMETER',
		];
		$GLOBALS['dp_time_machine_unit_sql_update_result']=['unit_update'=>true];
		$result=\dataphyre\time_machine::rollback('changeid123', 42, 0);
		unset($GLOBALS['dp_time_machine_unit_sql_select_result'], $GLOBALS['dp_time_machine_unit_sql_update_result']);
		return $result;
	}

	function dp_time_machine_unit_rollback_unknown_type_json(): string {
		dp_time_machine_unit_install_sql_callbacks();
		\dataphyre\core::$dialbacks=[];
		\dataphyre\core::$dialback_calls=[];
		$GLOBALS['dp_time_machine_unit_sql_calls']=[];
		$GLOBALS['dp_time_machine_unit_sql_select_result']=[
			'userid'=>9,
			'can_rollback'=>true,
			'data'=>json_encode(['table'=>'unit_table']),
			'rollback_type'=>'UNKNOWN',
		];
		$result=\dataphyre\time_machine::rollback('changeid-unknown', 9, 9);
		unset($GLOBALS['dp_time_machine_unit_sql_select_result']);
		$mutations=count($GLOBALS['dp_time_machine_unit_sql_calls']['insert'] ?? [])
			+count($GLOBALS['dp_time_machine_unit_sql_calls']['update'] ?? [])
			+count($GLOBALS['dp_time_machine_unit_sql_calls']['delete'] ?? []);
		return json_encode([
			'result'=>$result,
			'selects'=>count($GLOBALS['dp_time_machine_unit_sql_calls']['select'] ?? []),
			'mutations'=>$mutations,
			'hooks'=>array_column(\dataphyre\core::$dialback_calls, 'hook'),
		], JSON_UNESCAPED_SLASHES);
	}
}
