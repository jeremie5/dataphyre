<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace {
	if(!defined('REQUEST_IP_ADDRESS')){
		define('REQUEST_IP_ADDRESS','203.0.113.10');
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

	/** Builds a solved, signed CASPoW payload for declarative JSON fixtures. */
	function dp_caspow_solved_payload(bool $tamper_signature=false): string {
		$challenge=\dataphyre\caspow::create_challenge('unit_test_verify', [
			'hardware_concurrency'=>1,
			'device_memory'=>1,
			'save_data'=>true,
			'reduced_motion'=>true,
		]);
		$counter=0;
		$target=(int)$challenge['difficulty_bits'];
		while(true){
			$digest=hash('sha256', $challenge['challenge_id'].':'.$challenge['nonce'].':'.$counter);
			$bits=0;
			for($i=0, $length=strlen($digest); $i<$length; $i++){
				$nibble=hexdec($digest[$i]);
				if($nibble===0){
					$bits+=4;
					continue;
				}
				foreach([8, 4, 2, 1] as $mask){
					if(($nibble & $mask)!==0){
						break 2;
					}
					$bits++;
				}
				break;
			}
			if($bits>=$target){
				break;
			}
			$counter++;
		}
		return base64_encode((string)json_encode([
			'version'=>$challenge['version'],
			'challenge_id'=>$challenge['challenge_id'],
			'scope'=>$challenge['scope'],
			'algorithm'=>$challenge['algorithm'],
			'nonce'=>$challenge['nonce'],
			'signature'=>$tamper_signature ? 'tampered' : $challenge['signature'],
			'counter'=>$counter,
			'digest'=>$digest,
			'duration_ms'=>1,
			'iterations'=>$counter+1,
			'worker'=>false,
		], JSON_UNESCAPED_SLASHES));
	}

	function dp_caspow_unit_internal_helpers_json(): string {
		return json_encode([
			'bits_a'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\caspow::class,'leading_zero_bits',['0f']),
			'bits_b'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\caspow::class,'leading_zero_bits',['00ff']),
			'counter_bad'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\caspow::class,'normalize_counter',['-1']),
			'counter_string'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\caspow::class,'normalize_counter',['42']),
			'decoded'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\caspow::class,'decode_payload',[base64_encode(json_encode(['ok'=>true]))]),
			'scope'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\caspow::class,'normalize_scope',[' checkout scope!* ']),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_caspow_unit_profile_and_network_helpers_json(): string {
		return json_encode([
			'strong'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\caspow::class,'select_profile',[[
				'hardware_concurrency'=>8,
				'device_memory'=>8,
				'save_data'=>false,
				'reduced_motion'=>false,
			]]),
			'constrained'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\caspow::class,'select_profile',[[
				'hardware_concurrency'=>1,
				'device_memory'=>1,
				'save_data'=>true,
				'reduced_motion'=>true,
			]]),
			'ipv4_subnet'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\caspow::class,'ip_subnet',['203.0.113.42']),
			'ipv6_subnet'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\caspow::class,'ip_subnet',['2001:db8:abcd:0012:ffff::1']),
			'invalid_subnet'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\caspow::class,'ip_subnet',['not an ip']),
			'digest'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\caspow::class,'proof_digest',['nonce','challenge',7]),
		], JSON_UNESCAPED_SLASHES);
	}
}
