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
}

namespace dataphyre {
	if(!class_exists(__NAMESPACE__.'\\core', false)){
		class core{
			/** @var array<string,callable> */
			private static array $dialbacks=[];
			public static function register_dialback(string $event, callable $callback): void { self::$dialbacks[$event]=$callback; }
			public static function dialback(string $event, mixed ...$args): mixed {
				$callback=self::$dialbacks[$event] ?? null;
				return is_callable($callback) ? $callback(...$args) : null;
			}
		}
	}
}

namespace {
	require_once __DIR__.'/../kernel/supercookie.main.php';

	if(!function_exists('dp_supercookie_unit_test_get')){
		function dp_supercookie_unit_test_get(array $payload, string $name): mixed {
			dataphyre_dpanel_worker_application_state::putCookie(
				'__Secure-'.\dataphyre\supercookie::$cookie_name,
				json_encode($payload)
			);
			return \dataphyre\supercookie::get($name);
		}
	}

	if(!function_exists('dp_supercookie_unit_test_get_json')){
		function dp_supercookie_unit_test_get_json(array $payload, string $name): string {
			return json_encode(dp_supercookie_unit_test_get($payload, $name), JSON_UNESCAPED_SLASHES);
		}
	}

	if(!function_exists('dp_supercookie_unit_test_missing')){
		function dp_supercookie_unit_test_missing(string $name): bool {
			dataphyre_dpanel_worker_application_state::forgetCookie('__Secure-'.\dataphyre\supercookie::$cookie_name);
			return \dataphyre\supercookie::get($name)===null;
		}
	}

	if(!function_exists('dp_supercookie_unit_test_del_missing')){
		function dp_supercookie_unit_test_del_missing(string $name): bool {
			dataphyre_dpanel_worker_application_state::forgetCookie('__Secure-'.\dataphyre\supercookie::$cookie_name);
			return \dataphyre\supercookie::del($name);
		}
	}

	if(!function_exists('dp_supercookie_unit_test_read_edges_json')){
		function dp_supercookie_unit_test_read_edges_json(): string {
			$cookie='__Secure-'.\dataphyre\supercookie::$cookie_name;
			dataphyre_dpanel_worker_application_state::putCookie($cookie, 'not-json');
			$bad_json_is_null=\dataphyre\supercookie::get('anything')===null;
			dataphyre_dpanel_worker_application_state::putCookie($cookie, json_encode([
				'zero'=>0,
				'false'=>false,
				'empty'=>'',
			]));
			return json_encode([
				'bad_json_is_null'=>$bad_json_is_null,
				'zero'=>\dataphyre\supercookie::get('zero'),
				'false'=>\dataphyre\supercookie::get('false'),
				'empty'=>\dataphyre\supercookie::get('empty'),
				'missing_is_null'=>\dataphyre\supercookie::get('missing')===null,
			], JSON_UNESCAPED_SLASHES);
		}
	}
}
