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
	if(!function_exists('dpvk')){
		function dpvk(): string { return 'unit-test-key'; }
	}
}

namespace dataphyre {
	if(!class_exists(__NAMESPACE__.'\\access', false)){
		class access{
			public static function is_mobile(): bool { return false; }
		}
	}
}

namespace {
	require_once __DIR__.'/../kernel/caspow.main.php';

	function dp_caspow_unit_internal_helpers_json(): string {
		$reflection=new ReflectionClass(\dataphyre\caspow::class);
		$leading=$reflection->getMethod('leading_zero_bits');
		$leading->setAccessible(true);
		$counter=$reflection->getMethod('normalize_counter');
		$counter->setAccessible(true);
		$scope=$reflection->getMethod('normalize_scope');
		$scope->setAccessible(true);
		$decode=$reflection->getMethod('decode_payload');
		$decode->setAccessible(true);
		return json_encode([
			'bits_a'=>$leading->invoke(null, '0f'),
			'bits_b'=>$leading->invoke(null, '00ff'),
			'counter_bad'=>$counter->invoke(null, '-1'),
			'counter_string'=>$counter->invoke(null, '42'),
			'decoded'=>$decode->invoke(null, base64_encode(json_encode(['ok'=>true]))),
			'scope'=>$scope->invoke(null, ' checkout scope!* '),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_caspow_unit_profile_and_network_helpers_json(): string {
		$reflection=new ReflectionClass(\dataphyre\caspow::class);
		$profile=$reflection->getMethod('select_profile');
		$profile->setAccessible(true);
		$subnet=$reflection->getMethod('ip_subnet');
		$subnet->setAccessible(true);
		$digest=$reflection->getMethod('proof_digest');
		$digest->setAccessible(true);
		return json_encode([
			'strong'=>$profile->invoke(null, [
				'hardware_concurrency'=>8,
				'device_memory'=>8,
				'save_data'=>false,
				'reduced_motion'=>false,
			]),
			'constrained'=>$profile->invoke(null, [
				'hardware_concurrency'=>1,
				'device_memory'=>1,
				'save_data'=>true,
				'reduced_motion'=>true,
			]),
			'ipv4_subnet'=>$subnet->invoke(null, '203.0.113.42'),
			'ipv6_subnet'=>$subnet->invoke(null, '2001:db8:abcd:0012:ffff::1'),
			'invalid_subnet'=>$subnet->invoke(null, 'not an ip'),
			'digest'=>$digest->invoke(null, 'nonce', 'challenge', 7),
		], JSON_UNESCAPED_SLASHES);
	}
}
