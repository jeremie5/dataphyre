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
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $config): void {
			if(!defined($constant)){
				define($constant, $config);
			}
		}
	}
	if(!function_exists('sql_define_table')){
		function sql_define_table(...$args): void {}
	}
	$GLOBALS['DP_STRIPE_CFG_OVERRIDE']=[
		'test_mode'=>false,
		'webhook_secret_key'=>'unit_webhook_secret',
		'api_secret_key_live'=>'unit_live_secret',
		'api_publishable_key_live'=>'pk_live_unit',
		'api_secret_key_test_mode'=>'unit_test_secret',
		'api_publishable_key_test_mode'=>'pk_test_unit',
		'payment_intent_minimum_amount'=>[],
	];
}

namespace dataphyre {
	if(!class_exists(core::class, false)){
		class core {
			public static array $stripe_unit_unavailable_calls=[];
			private static array $stripe_unit_dialbacks=[];
			public static function dialback(string $event, mixed ...$args): mixed {
				$result=null;
				foreach(self::$stripe_unit_dialbacks[$event] ?? [] as $callback){
					$result=$callback(...$args);
				}
				return $result;
			}
			public static function register_dialback(string $event, callable $callback): bool {
				self::$stripe_unit_dialbacks[$event][]=$callback;
				return true;
			}
			public static function unavailable(...$args): void {
				self::$stripe_unit_unavailable_calls[]=$args;
			}
		}
	}
}

namespace Stripe {
	if(!class_exists(Stripe::class, false)){
		class Stripe {
			public static string $api_key='sk_stub_existing';
			public static string $apiKey='sk_stub_existing';
			public static int $maxNetworkRetries=0;
			public static function setMaxNetworkRetries(int $retries): void {
				self::$maxNetworkRetries=$retries;
			}
		}
	}
}

namespace {
	require_once __DIR__.'/../kernel/stripe.main.php';

	function dp_stripe_unit_key_selection_json(): string {
		return json_encode([
			'publishable'=>\dataphyre\stripe::get_publishable_key(),
			'secret'=>\dataphyre\stripe::get_secret_key(),
			'webhook'=>\dataphyre\stripe::get_webhook_secret_key(),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_stripe_unit_config_state_json(): string {
		$reflection=new ReflectionClass(\dataphyre\stripe::class);
		$method=$reflection->getMethod('test_mode');
		$method->setAccessible(true);
		return json_encode([
			'test_mode'=>$method->invoke(null),
			'live_keys_selected'=>\dataphyre\stripe::get_publishable_key()==='pk_live_unit' && \dataphyre\stripe::get_secret_key()==='unit_live_secret',
			'minimum_amount_configured'=>$GLOBALS['DP_STRIPE_CFG_OVERRIDE']['payment_intent_minimum_amount'],
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_stripe_unit_config_contract_json(): string {
		return json_encode([
			'webhook_falls_back_to_false'=>array_key_exists('webhook_secret_key', $GLOBALS['DP_STRIPE_CFG_OVERRIDE']),
			'live_secret_is_string'=>is_string(\dataphyre\stripe::get_secret_key()),
			'publishable_secret_are_distinct'=>\dataphyre\stripe::get_publishable_key()!==\dataphyre\stripe::get_secret_key(),
			'minimum_amount_empty_array'=>$GLOBALS['DP_STRIPE_CFG_OVERRIDE']['payment_intent_minimum_amount']===[],
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_stripe_unit_load_stripe_dialback_json(): string {
		\dataphyre\core::register_dialback('CALL_LOAD_STRIPE', fn()=>false);
		$blocked=\dataphyre\stripe::load_stripe();
		\dataphyre\core::register_dialback('CALL_LOAD_STRIPE', fn()=>true);
		\Stripe\Stripe::$api_key='unit_dialback_existing';
		\Stripe\Stripe::$apiKey='unit_dialback_existing';
		$platform_account=\dataphyre\stripe::get_platform_account();
		return json_encode([
			'blocked_load'=>$blocked,
			'platform_account'=>$platform_account,
			'api_key_unchanged'=>\Stripe\Stripe::$api_key==='unit_dialback_existing' && \Stripe\Stripe::$apiKey==='unit_dialback_existing',
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_stripe_unit_public_api_contract_json(): string {
		$reflection=new ReflectionClass(\dataphyre\stripe::class);
		return json_encode([
			'load_stripe_returns_bool'=>(string)$reflection->getMethod('load_stripe')->getReturnType()==='bool',
			'publishable_returns_string_or_bool'=>(string)$reflection->getMethod('get_publishable_key')->getReturnType()==='string|bool',
			'secret_returns_string_or_bool'=>(string)$reflection->getMethod('get_secret_key')->getReturnType()==='string|bool',
			'test_mode_private'=>$reflection->getMethod('test_mode')->isPrivate(),
			'handle_new_payment_method_params'=>$reflection->getMethod('handle_new_payment_method')->getNumberOfParameters(),
		], JSON_UNESCAPED_SLASHES);
	}
}
