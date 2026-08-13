<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

if(function_exists('dataphyre\tracelog')){
	tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Module initialization");
}

if(function_exists('dp_define_module_config')){
	dp_define_module_config('reactor', 'DP_REACTOR_CFG', [
		'secret'=>null,
		'signing_keys'=>null,
		'current_signing_key'=>null,
		'previous_signing_secrets'=>[],
		'production'=>null,
		'expose_trace_manifest'=>null,
		'snapshot_max_age_seconds'=>null,
		'require_signed_mutation_snapshots'=>true,
		'snapshot_version_store'=>null,
		'snapshot_reservation_ttl_seconds'=>120,
		'transport_authorizer'=>null,
		'security_context_resolver'=>null,
		'legacy_snapshot_policy'=>null,
		'component_parameter'=>'component',
		'action_parameter'=>'action',
		'allow_unsigned_in_debug'=>false,
		'max_payload_bytes'=>262144,
		'components'=>[],
	]);
}

/**
 * Provides kernel-level access to Reactor module configuration.
 *
 * The kernel defines DP_REACTOR_CFG defaults during module initialization so
 * component dispatchers, signature validators, and diagnostics can read
 * settings without booting the full framework stack.
 */
final class reactor {

	/**
	 * Reads one Reactor configuration value.
	 *
	 * Missing or non-array configuration falls back to the caller-provided
	 * default, keeping embedded documentation scans and diagnostics bootstrap-safe.
	 *
	 * @param string $key DP_REACTOR_CFG key to read.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed Reactor configuration value from DP_REACTOR_CFG, or the caller default when absent.
	 */
	public static function config(string $key, mixed $default=null): mixed {
		if(defined('\DP_REACTOR_CFG')){
			$config=\constant('\DP_REACTOR_CFG');
			if(is_array($config) && array_key_exists($key, $config)){
				return $config[$key];
			}
		}
		return $default;
	}
}
