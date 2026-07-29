<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

if(function_exists('dataphyre\\tracelog')){
	tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Module initialization');
}

if(function_exists('dp_define_module_config')){
	dp_define_module_config('simulation', 'DP_SIMULATION_CFG', [
		'enabled'=>false,
		'allowed_environments'=>['local', 'development', 'dev', 'test', 'testing'],
		'max_events_per_tick'=>8,
		'journal_limit'=>200,
		'pending_limit'=>500,
	]);
}

/** Kernel-safe access to Dataphyre Simulation configuration. */
final class simulation {
	public static function config(string $key, mixed $default=null): mixed {
		if(defined('\\DP_SIMULATION_CFG')){
			$config=constant('\\DP_SIMULATION_CFG');
			if(is_array($config) && array_key_exists($key, $config)) return $config[$key];
		}
		return $default;
	}
}
