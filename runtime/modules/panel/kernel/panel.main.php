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
	dp_define_module_config('panel', 'DP_PANEL_CFG', [
		'panel_label'=>'Dataphyre Panel',
		'home_label'=>'Panel',
		'global_search_parameter'=>'search',
		'url_builder'=>null,
		'default_icon'=>'settings',
		'authorize'=>null,
		'resources'=>[],
		'providers'=>[],
		'surfaces'=>[],
	]);
}

/**
 * Exposes Panel module configuration to legacy kernel callers.
 *
 * The module entrypoint defines DP_PANEL_CFG defaults when the core config
 * helper is available, then provides a small accessor that reads the finalized
 * constant without coupling callers to the config bootstrap details.
 */
final class panel {

	/**
	 * Reads one Panel configuration value.
	 *
	 *
	 * @return mixed Config value when present, otherwise the provided default.
	 */
	public static function config(string $key, mixed $default=null): mixed {
		if(defined('\DP_PANEL_CFG')){
			$config=\constant('\DP_PANEL_CFG');
			if(is_array($config) && array_key_exists($key, $config)){
				return $config[$key];
			}
		}
		return $default;
	}
}
