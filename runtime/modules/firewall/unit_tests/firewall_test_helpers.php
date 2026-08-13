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
			'root'=>dirname(__DIR__, 5),
			'common_dataphyre'=>dirname(__DIR__, 4).DIRECTORY_SEPARATOR,
			'dataphyre'=>dirname(__DIR__, 4).DIRECTORY_SEPARATOR,
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
	if(!function_exists('sql_define_table')){
		function sql_define_table(...$args): void {}
	}
	if(!defined('DP_FIREWALL_WORKER_SCENARIO_LOADED') && !class_exists(DpFirewallWorkerScenario::class,false)){
		define('DP_FIREWALL_WORKER_SCENARIO_LOADED', true);
		final class DpFirewallWorkerScenario {
			/** @var array{data:string,salt:array<int,mixed>}|null */
			private static ?array $encryption=null;

			/** @param array<string,mixed> $session */
			public static function begin(string $remoteAddress,string $requestUri,array $session=[]): void {
				\dataphyre_dpanel_worker_fixture_state::resetSql();
				\dataphyre_dpanel_worker_application_state::remoteAddress($remoteAddress);
				\dataphyre_dpanel_worker_application_state::requestUri($requestUri);
				\dataphyre_dpanel_worker_application_state::replaceSession($session);
				self::$encryption=null;
			}

			public static function sessionHas(string $key): bool {
				return \dataphyre_dpanel_worker_application_state::sessionHas($key);
			}

			public static function sessionValue(string $key,mixed $default=null): mixed {
				return \dataphyre_dpanel_worker_application_state::sessionValue($key,$default);
			}

			/** @param array<int,mixed> $salt */
			public static function recordEncryption(string $data,array $salt): void {
				self::$encryption=['data'=>$data,'salt'=>$salt];
			}
		}
	}
	if(!function_exists('sql_select')){
		function sql_select(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::dispatchSql('select',$args,false);
		}
	}
	if(!function_exists('sql_delete')){
		function sql_delete(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::dispatchSql('delete',$args,true);
		}
	}
	if(!function_exists('sql_insert')){
		function sql_insert(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::dispatchSql('insert',$args,true);
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
				\DpFirewallWorkerScenario::recordEncryption($data,$salt);
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
		DpFirewallWorkerScenario::begin('203.0.113.10','/checkout',[
			'captcha_unblock'=>true,
			'captcha_blocked'=>true,
			'last_requests'=>[1.0, 2.0],
			'keep'=>'yes',
		]);
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('delete',true);

		\dataphyre\firewall::captcha();
		$delete=\dataphyre_dpanel_worker_fixture_state::sqlCall('delete');

		return json_encode([
			'delete_table'=>$delete[0] ?? null,
			'delete_where'=>$delete[1] ?? null,
			'delete_values'=>$delete[2] ?? null,
			'captcha_unblock'=>DpFirewallWorkerScenario::sessionHas('captcha_unblock'),
			'captcha_blocked'=>DpFirewallWorkerScenario::sessionHas('captcha_blocked'),
			'last_requests'=>DpFirewallWorkerScenario::sessionHas('last_requests'),
			'kept'=>DpFirewallWorkerScenario::sessionValue('keep'),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_firewall_unit_check_not_blocked_json(): string {
		DpFirewallWorkerScenario::begin('203.0.113.20','/catalog');
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('select',false);

		$result=\dataphyre\firewall::check_if_captcha_blocked();
		$select=\dataphyre_dpanel_worker_fixture_state::sqlCall('select');

		return json_encode([
			'result'=>$result,
			'captcha_blocked'=>DpFirewallWorkerScenario::sessionValue('captcha_blocked',false),
			'select_table'=>$select[1] ?? null,
			'select_values'=>$select[3] ?? null,
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_firewall_unit_captcha_block_insert_json(): string {
		DpFirewallWorkerScenario::begin('203.0.113.30','/security/captcha');
		\dataphyre_dpanel_worker_fixture_state::respondToSql('select',static function(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::sqlCallCount('select')===1
				? false
				: ['ip_address'=>'203.0.113.30', 'reason'=>'manual_review'];
		});
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('insert',true);

		$result=\dataphyre\firewall::captcha_block_user('manual_review');
		$insert=\dataphyre_dpanel_worker_fixture_state::sqlCall('insert');
		$record=$insert[1] ?? [];

		return json_encode([
			'result'=>$result,
			'insert_table'=>$insert[0] ?? null,
			'insert_ip'=>$record['ip_address'] ?? null,
			'insert_reason'=>$record['reason'] ?? null,
			'expiry_format'=>isset($record['expiry']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['expiry']) === 1,
			'select_calls'=>\dataphyre_dpanel_worker_fixture_state::sqlCallCount('select'),
			'captcha_blocked'=>DpFirewallWorkerScenario::sessionValue('captcha_blocked',false),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_firewall_unit_captcha_block_existing_json(): string {
		DpFirewallWorkerScenario::begin('203.0.113.31','/security/captcha');
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('select',['ip_address'=>'203.0.113.31','reason'=>'already_blocked']);
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('insert',true);

		$result=\dataphyre\firewall::captcha_block_user('already_blocked');
		$firstSelect=\dataphyre_dpanel_worker_fixture_state::sqlCall('select');

		return json_encode([
			'result'=>$result,
			'select_calls'=>\dataphyre_dpanel_worker_fixture_state::sqlCallCount('select'),
			'insert_called'=>\dataphyre_dpanel_worker_fixture_state::sqlCallCount('insert')>0,
			'first_select_table'=>$firstSelect[1] ?? null,
			'first_select_values'=>$firstSelect[3] ?? null,
			'captcha_blocked'=>DpFirewallWorkerScenario::sessionValue('captcha_blocked',false),
		], JSON_UNESCAPED_SLASHES);
	}
}
