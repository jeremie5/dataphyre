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
	dp_define_module_config('recovery', 'DP_RECOVERY_CFG', [
		'type_base'=>'',
		'evidence_max_items'=>24,
		'evidence_max_string_length'=>240,
		'correlation_header'=>'X-Correlation-Id',
	]);
}

/** Kernel-safe access to Dataphyre Recovery configuration. */
final class recovery {
	public static function config(string $key, mixed $default=null): mixed {
		if(defined('\\DP_RECOVERY_CFG')){
			$config=constant('\\DP_RECOVERY_CFG');
			if(is_array($config) && array_key_exists($key, $config)) return $config[$key];
		}
		$defaults=[
			'type_base'=>'',
			'evidence_max_items'=>24,
			'evidence_max_string_length'=>240,
			'correlation_header'=>'X-Correlation-Id',
		];
		if(array_key_exists($key, $defaults)) return $defaults[$key];
		return $default;
	}
}
