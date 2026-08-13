<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace {
	require_once dirname(__DIR__, 2).'/dpanel/tooling/WorkerFixtureState.php';

	if(!defined('REQUEST_USER_AGENT')){
		define('REQUEST_USER_AGENT', 'Dataphyre Access TestKit/1.0');
	}
	if(!defined('REQUEST_IP_ADDRESS')){
		define('REQUEST_IP_ADDRESS', '203.0.113.10');
	}
	if(!defined('DP_CORE_CFG')){
		define('DP_CORE_CFG', ['public_app_name'=>'Dataphyre TestKit', 'private_key'=>['access-test-key']]);
	}
	if(!defined('DP_ACCESS_CFG')){
		define('DP_ACCESS_CFG', [
			'sanction_on_useragent_change'=>false,
			'sessions_table_name'=>'dataphyre.sessions',
			'sessions_cookie_name'=>'DPID',
			'auth_types'=>['session','jwt','custom-auth'],
			'default_auth_type'=>'session',
			'botlist'=>['Googlebot','bingbot'],
			'requires_app_redirect'=>'/open-app',
			'robot_redirect'=>'/robots-not-allowed',
			'must_no_session_redirect'=>'/home',
			'require_session_redirect'=>'/login',
			'identity'=>['tokens_table'=>'dataphyre.access_tokens'],
		]);
	}

	if(!class_exists(DpAccessLazyConstant::class, false)){
		final class DpAccessLazyConstant {
			private bool $resolved=false;
			private mixed $value=null;
			public function __construct(private \Closure $resolver) {}
			public function reset(): void { $this->resolved=false; $this->value=null; }
			public function value(): mixed {
				if(!$this->resolved){ $this->value=($this->resolver)(); $this->resolved=true; }
				return $this->value;
			}
			public function __toString(): string { return (string)$this->value(); }
		}
	}
	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
			if(!defined($constant)){ define($constant, $defaults); }
		}
	}
	if(!function_exists('heisenconstant')){
		function heisenconstant(string $name, mixed $value): void {
			if(!defined($name)){ define($name, $value instanceof \Closure ? new DpAccessLazyConstant($value) : $value); }
		}
	}
	if(!function_exists('dpvk')){
		function dpvk(): string { return 'dataphyre-access-unit-test-signing-key'; }
	}
	if(!function_exists('minified_font')){
		function minified_font(): string { return ''; }
	}
	if(!function_exists('dp_module_present')){
		function dp_module_present(string $module): bool { return DpAccessSessionWorkerScenario::modulePresent($module); }
	}
	if(!function_exists('dp_module_required')){
		function dp_module_required(string $module, string $dependency): bool { return true; }
	}
	if(!function_exists('sql_define_table')){
		function sql_define_table(mixed ...$arguments): void { DpAccessSessionWorkerScenario::recordTableDefinition($arguments); }
	}
	if(!function_exists('sql_select')){
		function sql_select(mixed ...$arguments): mixed { return dataphyre_dpanel_worker_fixture_state::dispatchSql('select', $arguments, false); }
	}
	if(!function_exists('sql_insert')){
		function sql_insert(mixed ...$arguments): mixed { return dataphyre_dpanel_worker_fixture_state::dispatchSql('insert', $arguments, false); }
	}
	if(!function_exists('sql_update')){
		function sql_update(mixed ...$arguments): mixed { return dataphyre_dpanel_worker_fixture_state::dispatchSql('update', $arguments, false); }
	}
}

namespace dataphyre {
	if(!class_exists(core::class, false)){
		final class core {
			/** @var array<string,callable> */
			private static array $dialbacks=[];
			/** @var list<array<int,mixed>> */
			private static array $calls=[];
			/** @var list<array<int,mixed>> */
			private static array $unavailable=[];

			public static function resetAccessScenario(): void { self::$dialbacks=[]; self::$calls=[]; self::$unavailable=[]; }
			public static function register_dialback(string $event, callable $callback): bool { self::$dialbacks[$event]=$callback; return true; }
			public static function dialback(string $event, mixed ...$arguments): mixed {
				self::$calls[]=[$event,...$arguments];
				return isset(self::$dialbacks[$event]) ? (self::$dialbacks[$event])(...$arguments) : null;
			}
			/** @return list<array<int,mixed>> */
			public static function accessScenarioCalls(): array { return self::$calls; }
			/** @return list<array<int,mixed>> */
			public static function accessScenarioUnavailableCalls(): array { return self::$unavailable; }
			public static function unavailable(mixed ...$arguments): never {
				self::$unavailable[]=$arguments;
				throw new \RuntimeException((string)($arguments[4] ?? 'Dataphyre unavailable.'));
			}
			public static function get_config(string $key): mixed { return $key==='public_app_name' ? 'Dataphyre TestKit' : null; }
		}
	}

	if(!class_exists(firewall::class, false)){
		final class firewall {
			/** @var list<string> */
			private static array $reasons=[];
			public static function resetAccessScenario(): void { self::$reasons=[]; }
			public static function captcha_block_user(string $reason): bool { self::$reasons[]=$reason; return true; }
			/** @return list<string> */
			public static function accessScenarioReasons(): array { return self::$reasons; }
		}
	}
}

namespace {
	if(!defined('DP_ACCESS_SESSION_WORKER_SCENARIO_LOADED') && !class_exists(DpAccessSessionWorkerScenario::class, false)){
		define('DP_ACCESS_SESSION_WORKER_SCENARIO_LOADED', true);
		final class DpAccessSessionWorkerScenario {
			/** @var array<string,bool> */
			private static array $modules=['firewall'=>false];
			/** @var list<array<int,mixed>> */
			private static array $table_definitions=[];
			/** @var list<array{message:string,status:int,redirect:?string}> */
			private static array $denials=[];

			/** @param array<string,mixed> $session @param array<string,mixed> $cookies @param array<string,mixed> $server */
			public static function begin(array $session=[], array $cookies=[], array $server=[]): void {
				dataphyre_dpanel_worker_fixture_state::resetSql();
				dataphyre_dpanel_worker_application_state::replaceSession($session);
				dataphyre_dpanel_worker_application_state::replaceCookies($cookies);
				dataphyre_dpanel_worker_application_state::replaceServer(array_replace([
					'HTTP_HOST'=>'example.test',
					'HTTP_USER_AGENT'=>REQUEST_USER_AGENT,
					'REQUEST_URI'=>'/private/report',
					'SCRIPT_FILENAME'=>'/srv/app/private.php',
				], $server));
				self::$modules=['firewall'=>false];
				self::$denials=[];
				if(method_exists(\dataphyre\core::class, 'resetAccessScenario')){ \dataphyre\core::resetAccessScenario(); }
				if(method_exists(\dataphyre\firewall::class, 'resetAccessScenario')){ \dataphyre\firewall::resetAccessScenario(); }
				self::resetKernelState();
			}

			public static function cacheSessionValidation(bool $valid): void {
				self::begin([
					'dp_access'=>[
						'userid'=>123,
						'dpid'=>'unit-session',
						'ip_address'=>REQUEST_IP_ADDRESS,
						'last_valid_session'=>$valid ? time() : 0,
						'auth_type'=>'session',
					],
				]);
			}

			/** @return array<string,mixed> */
			public static function authenticatedSession(int|string $userid=123, ?string $dpid=null): array {
				return ['dp_access'=>[
					'userid'=>$userid,
					'dpid'=>$dpid ?? self::validId(),
					'ip_address'=>REQUEST_IP_ADDRESS,
					'auth_type'=>'session',
				]];
			}

			public static function validId(string $auth_type='session'): string { return \dataphyre\access::create_id($auth_type); }
			public static function sessionCookieName(): string { return \dataphyre\access::get_session_cookie_name(); }
			public static function putSessionCookie(string $dpid): void { dataphyre_dpanel_worker_application_state::putCookie(self::sessionCookieName(), $dpid); }
			public static function returnFromSql(string $operation, mixed $result): void { dataphyre_dpanel_worker_fixture_state::returnFromSql($operation, $result); }
			public static function respondToSql(string $operation, callable $responder): void { dataphyre_dpanel_worker_fixture_state::respondToSql($operation, $responder); }
			/** @return list<array<int,mixed>> */
			public static function sqlCalls(string $operation): array { return dataphyre_dpanel_worker_fixture_state::sqlCalls($operation); }
			/** @return array<int,mixed> */
			public static function sqlCall(string $operation, int $index=0): array { return dataphyre_dpanel_worker_fixture_state::sqlCall($operation, $index); }
			public static function dialback(string $event, mixed $result): void {
				\dataphyre\core::register_dialback($event, static fn(mixed ...$arguments): mixed=>$result);
			}
			public static function module(string $module, bool $present=true): void { self::$modules[strtolower(trim($module))]=$present; }
			public static function modulePresent(string $module): bool { return self::$modules[strtolower(trim($module))] ?? false; }
			/** @param array<int,mixed> $arguments */
			public static function recordTableDefinition(array $arguments): void { self::$table_definitions[]=$arguments; }
			/** @return list<array<int,mixed>> */
			public static function tableDefinitions(): array { return self::$table_definitions; }
			public static function denialResponder(): callable {
				return static function(string $message, int $status, ?string $redirect): bool {
					self::$denials[]=['message'=>$message, 'status'=>$status, 'redirect'=>$redirect];
					return false;
				};
			}
			/** @return list<array{message:string,status:int,redirect:?string}> */
			public static function denials(): array { return self::$denials; }
			/** @return array<string,mixed> */
			public static function accessState(): array {
				$state=dataphyre_dpanel_worker_application_state::sessionValue('dp_access', []);
				return is_array($state) ? $state : [];
			}
			public static function accessValue(string $key, mixed $default=null): mixed { return self::accessState()[$key] ?? $default; }
			/** @return list<string> */
			public static function firewallReasons(): array {
				return method_exists(\dataphyre\firewall::class, 'accessScenarioReasons') ? \dataphyre\firewall::accessScenarioReasons() : [];
			}

			private static function resetKernelState(): void {
				if(!class_exists(\dataphyre\access::class, false)){ return; }
				dataphyre_dpanel_worker_fixture_state::replaceNonPublicProperties(\dataphyre\access::class,[
					'session_cookie'=>'__Host-'.(string)(DP_ACCESS_CFG['sessions_cookie_name'] ?? 'DPID'),
					'fingerprint'=>[],
					'current_auth_type'=>null,
					'user_agent_match_cache'=>[],
					'auth_type_prefix_map'=>['session'=>'DPID','jwt'=>'DJTI'],
				]);
				\dataphyre\access::$useragent_mismatch=false;
			}
		}
	}

	require_once __DIR__.'/../kernel/access.main.php';

	if(!function_exists('dp_access_unit_create_session')){
		function dp_access_unit_create_session(int $userid, bool $keepalive): bool {
			DpAccessSessionWorkerScenario::begin();
			DpAccessSessionWorkerScenario::respondToSql('insert', static function(string $table, array $fields) use($userid): mixed {
				return (($fields['userid'] ?? null)===$userid && $userid!==999) ? ['unit_insert'=>true] : false;
			});
			return \dataphyre\access::create_session($userid, $keepalive);
		}
	}

	if(!function_exists('dp_access_unit_validate_session_cached')){
		function dp_access_unit_validate_session_cached(bool $valid): bool {
			DpAccessSessionWorkerScenario::cacheSessionValidation($valid);
			return \dataphyre\access::validate_session(true);
		}
	}

	if(!function_exists('dp_access_unit_recover_session')){
		function dp_access_unit_recover_session(): bool {
			DpAccessSessionWorkerScenario::begin();
			\dataphyre\core::register_dialback('CALL_ACCESS_RECOVER_SESSION', static fn(): bool=>true);
			return \dataphyre\access::recover_session();
		}
	}

	if(!function_exists('dp_access_unit_disable_all_sessions_of_user')){
		function dp_access_unit_disable_all_sessions_of_user(int $userid): bool {
			DpAccessSessionWorkerScenario::begin();
			DpAccessSessionWorkerScenario::respondToSql('update', static function(string $table, mixed $fields, mixed $where, array $values) use($userid): mixed {
				return (($values[1] ?? null)===$userid && $userid!==67890) ? ['unit_update'=>true] : false;
			});
			return \dataphyre\access::disable_all_sessions_of_user($userid);
		}
	}
}
