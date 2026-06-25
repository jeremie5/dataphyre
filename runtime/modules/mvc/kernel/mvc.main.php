<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Module initialization");

if(function_exists('dp_define_module_config')){
	dp_define_module_config('mvc', 'DP_MVC_CFG', [
		'apps'=>[],
		'default_app'=>null,
		'controllers'=>[],
		'models'=>[],
		'views'=>[],
		'middleware'=>[],
		'routes'=>[],
		'manifest_cache'=>false,
		'error_handler'=>null,
		'not_found_handler'=>null,
		'validation_redirect'=>false,
		'validation_redirect_fallback'=>'/',
		'response_headers'=>[
			'X-Dataphyre-MVC'=>'1',
		],
	]);
}

/**
 * Provides kernel-level access to MVC module configuration.
 *
 * The kernel defines DP_MVC_CFG defaults during module initialization and keeps
 * this accessor intentionally small so bootstrap code can read configuration
 * without constructing framework application objects.
 */
final class mvc {

	/**
	 * Reads one MVC configuration value.
	 *
	 * Missing or non-array configuration falls back to the caller-provided
	 * default, preserving bootstrap safety for diagnostics and tooling scans.
	 *
	 * @param string $key DP_MVC_CFG key to read.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed MVC configuration value from DP_MVC_CFG, or the caller default when absent.
	 */
	public static function config(string $key, mixed $default=null): mixed {
		if(defined('DP_MVC_CFG')){
			$config=\constant('DP_MVC_CFG');
			if(is_array($config) && array_key_exists($key, $config)){
				return $config[$key];
			}
		}
		return $default;
	}
}
