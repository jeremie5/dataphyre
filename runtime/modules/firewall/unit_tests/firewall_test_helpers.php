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
	if(!defined('ROOTPATH')){
		define('ROOTPATH', [
			'root'=>dirname(__DIR__, 6),
			'common_dataphyre'=>dirname(__DIR__, 3).DIRECTORY_SEPARATOR,
			'dataphyre'=>dirname(__DIR__, 3).DIRECTORY_SEPARATOR,
			'common_root'=>dirname(__DIR__, 5).DIRECTORY_SEPARATOR,
		]);
	}
	if(!function_exists('tracelog')){
		function tracelog(...$args): void {}
	}
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $config): void {
			if(!defined($constant)){
				define($constant, $config);
			}
		}
	}
	if(!function_exists('dp_module_required')){
		function dp_module_required(...$args): bool { return true; }
	}
	if(!function_exists('dp_module_present')){
		function dp_module_present(string $module): bool { return $module === 'access'; }
	}
	if(!function_exists('sql_select')){
		function sql_select(...$args): mixed {
			return is_callable($GLOBALS['dp_unit_sql_select'] ?? null)
				? $GLOBALS['dp_unit_sql_select'](...$args)
				: false;
		}
	}
	if(!function_exists('sql_delete')){
		function sql_delete(...$args): mixed {
			return is_callable($GLOBALS['dp_unit_sql_delete'] ?? null)
				? $GLOBALS['dp_unit_sql_delete'](...$args)
				: true;
		}
	}
	if(!function_exists('sql_insert')){
		function sql_insert(...$args): mixed {
			return is_callable($GLOBALS['dp_unit_sql_insert'] ?? null)
				? $GLOBALS['dp_unit_sql_insert'](...$args)
				: true;
		}
	}
}

namespace dataphyre {
	if(!class_exists(__NAMESPACE__.'\\core', false)){
		class core{
			public static function dialback(...$args): mixed { return null; }
			public static function url_self(): string { return '/'; }
			public static function config_all(): array { return ['base_timezone'=>'America/Toronto']; }
			public static function get_client_ip(): string { return '127.0.0.1'; }
			public static function get_server_load_level(): string { return 'low'; }
			public static function encrypt_data(string $data, array $salt): string {
				$GLOBALS['dp_issue_encrypt_payload']=[
					'data'=>$data,
					'salt'=>$salt,
				];
				return 'encrypted:'.md5($data.implode('|', $salt));
			}
		}
	}
	if(!class_exists(__NAMESPACE__.'\\dpanel', false)){
		class dpanel{
			public static function add_verbose(?array $verboses): void {}
		}
	}
}

namespace {
	require_once __DIR__.'/../kernel/firewall.main.php';

	function dp_firewall_unit_threshold_json(): string {
		return json_encode([
			'threshold'=>\dataphyre\firewall::flooding_threshold(),
			'rps_limiter'=>\dataphyre\firewall::rps_limiter(1),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_firewall_unit_captcha_unblock_json(): string {
		$_SERVER['REMOTE_ADDR']='203.0.113.10';
		$_SERVER['REQUEST_URI']='/checkout';
		$_SESSION=[
			'captcha_unblock'=>true,
			'captcha_blocked'=>true,
			'last_requests'=>[1.0, 2.0],
			'keep'=>'yes',
		];
		$GLOBALS['dp_firewall_sql_delete_args']=null;
		$GLOBALS['dp_unit_sql_delete']=static function(...$args): bool {
			$GLOBALS['dp_firewall_sql_delete_args']=$args;
			return true;
		};

		\dataphyre\firewall::captcha();

		return json_encode([
			'delete_table'=>$GLOBALS['dp_firewall_sql_delete_args'][0] ?? null,
			'delete_where'=>$GLOBALS['dp_firewall_sql_delete_args'][1] ?? null,
			'delete_values'=>$GLOBALS['dp_firewall_sql_delete_args'][2] ?? null,
			'captcha_unblock'=>array_key_exists('captcha_unblock', $_SESSION),
			'captcha_blocked'=>array_key_exists('captcha_blocked', $_SESSION),
			'last_requests'=>array_key_exists('last_requests', $_SESSION),
			'kept'=>$_SESSION['keep'] ?? null,
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_firewall_unit_check_not_blocked_json(): string {
		$_SERVER['REMOTE_ADDR']='203.0.113.20';
		$_SERVER['REQUEST_URI']='/catalog';
		$_SESSION=[];
		$GLOBALS['dp_firewall_sql_select_args']=null;
		$GLOBALS['dp_unit_sql_select']=static function(...$args): bool {
			$GLOBALS['dp_firewall_sql_select_args']=$args;
			return false;
		};

		$result=\dataphyre\firewall::check_if_captcha_blocked();

		return json_encode([
			'result'=>$result,
			'captcha_blocked'=>$_SESSION['captcha_blocked'] ?? false,
			'select_table'=>$GLOBALS['dp_firewall_sql_select_args'][1] ?? null,
			'select_values'=>$GLOBALS['dp_firewall_sql_select_args'][3] ?? null,
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_firewall_unit_captcha_block_insert_json(): string {
		$_SERVER['REMOTE_ADDR']='203.0.113.30';
		$_SERVER['REQUEST_URI']='/security/captcha';
		$_SESSION=[];
		$GLOBALS['dp_firewall_sql_select_calls']=0;
		$GLOBALS['dp_firewall_sql_insert_args']=null;
		$GLOBALS['dp_unit_sql_select']=static function(...$args): mixed {
			$GLOBALS['dp_firewall_sql_select_calls']++;
			return $GLOBALS['dp_firewall_sql_select_calls'] === 1
				? false
				: ['ip_address'=>'203.0.113.30', 'reason'=>'manual_review'];
		};
		$GLOBALS['dp_unit_sql_insert']=static function(...$args): bool {
			$GLOBALS['dp_firewall_sql_insert_args']=$args;
			return true;
		};

		$result=\dataphyre\firewall::captcha_block_user('manual_review');
		$record=$GLOBALS['dp_firewall_sql_insert_args'][1] ?? [];

		return json_encode([
			'result'=>$result,
			'insert_table'=>$GLOBALS['dp_firewall_sql_insert_args'][0] ?? null,
			'insert_ip'=>$record['ip_address'] ?? null,
			'insert_reason'=>$record['reason'] ?? null,
			'expiry_format'=>isset($record['expiry']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['expiry']) === 1,
			'select_calls'=>$GLOBALS['dp_firewall_sql_select_calls'],
			'captcha_blocked'=>$_SESSION['captcha_blocked'] ?? false,
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_firewall_unit_captcha_block_existing_json(): string {
		$_SERVER['REMOTE_ADDR']='203.0.113.31';
		$_SERVER['REQUEST_URI']='/security/captcha';
		$_SESSION=[];
		$GLOBALS['dp_firewall_sql_select_args']=[];
		$GLOBALS['dp_firewall_sql_insert_called']=false;
		$GLOBALS['dp_unit_sql_select']=static function(...$args): array {
			$GLOBALS['dp_firewall_sql_select_args'][]=$args;
			return ['ip_address'=>'203.0.113.31', 'reason'=>'already_blocked'];
		};
		$GLOBALS['dp_unit_sql_insert']=static function(...$args): bool {
			$GLOBALS['dp_firewall_sql_insert_called']=true;
			return true;
		};

		$result=\dataphyre\firewall::captcha_block_user('already_blocked');

		return json_encode([
			'result'=>$result,
			'select_calls'=>count($GLOBALS['dp_firewall_sql_select_args']),
			'insert_called'=>$GLOBALS['dp_firewall_sql_insert_called'],
			'first_select_table'=>$GLOBALS['dp_firewall_sql_select_args'][0][1] ?? null,
			'first_select_values'=>$GLOBALS['dp_firewall_sql_select_args'][0][3] ?? null,
			'captcha_blocked'=>$_SESSION['captcha_blocked'] ?? false,
		], JSON_UNESCAPED_SLASHES);
	}
}
