<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loaded");

if(defined('ROOTPATH')===false){
	throw new \RuntimeException('ROOTPATH must be defined before bootstrapping Dataphyre.');
}
if(defined('DP_CORE_LOADED')===false){
	require_once(ROOTPATH['common_dataphyre_runtime'].'modules/core/kernel/core.main.php');
}
if(defined('DP_DATADOC_CFG')===false && function_exists('dp_define_module_config')){
	dp_define_module_config('datadoc', 'DP_DATADOC_CFG');
}

$datadoc_timezone=(string)(DP_DATADOC_CFG['timezone'] ?? DP_CORE_CFG['timezone'] ?? date_default_timezone_get());
date_default_timezone_set($datadoc_timezone);
$nonce='';

if(!function_exists('sanitize')){
	/**
	 * Compatibility shim for Datadoc-rendered examples that call the sanitation module.
	 *
	 * @deprecated Wrapper-only fallback; load the owning module directly in runtime code.
	 */
	function sanitize($a=null,$b=null){return \dataphyre\sanitation::sanitize($a,$b);}
}
if(!function_exists('array_count')){
	/**
	 * Compatibility shim for Datadoc-rendered examples that call Dataphyre array counting helpers.
	 *
	 * @deprecated Wrapper-only fallback.
	 */
	function array_count($a=null){return is_array($a) ? count($a) : 0;}
}
if(!function_exists('clear_cache')){
	/**
	 * Compatibility shim for Datadoc-rendered examples that clear cache state.
	 *
	 * @deprecated Wrapper-only fallback.
	 */
	function clear_cache(){return \dataphyre\sql::db_clear_cache();}
}
if(!function_exists('is_bot')){
	/**
	 * Compatibility shim for Datadoc-rendered examples that inspect bot detection.
	 *
	 * @deprecated Wrapper-only fallback.
	 */
	function is_bot(){return \dataphyre\access::is_bot();}
}
if(!function_exists('rps_limiter')){
	/**
	 * Compatibility shim for Datadoc-rendered examples that reference request-rate limiting.
	 *
	 * @deprecated Wrapper-only fallback.
	 */
	function rps_limiter($a=null){return \dataphyre\firewall::rps_limiter($a);}
}
if(!function_exists('anonymize_email')){
	/**
	 * Compatibility shim for Datadoc-rendered examples that anonymize email addresses.
	 *
	 * @deprecated Wrapper-only fallback.
	 */
	function anonymize_email($a=null,$b=null,$c=null){return \dataphyre\sanitation::anonymize_email($a,$b,$c);}
}
if(!function_exists('currency_formatter')){
	/**
	 * Compatibility shim for Datadoc-rendered examples that format currency values.
	 *
	 * @deprecated Wrapper-only fallback.
	 */
	function currency_formatter($a=null, $b=null){return \dataphyre\currency::formatter($a, $b);}
}
if(!function_exists('rounder')){
	/**
	 * Compatibility shim for Datadoc-rendered examples that round numeric values.
	 *
	 * @deprecated Wrapper-only fallback.
	 */
	function rounder($a=null){return \dataphyre\currency::convert_to_user_currency($a);}
}
if(!function_exists('convert_to_user_currency')){
	/**
	 * Compatibility shim for Datadoc-rendered examples that convert to user currency.
	 *
	 * @deprecated Wrapper-only fallback.
	 */
	function convert_to_user_currency($a=null, $b=null, $c=null, $d=null){return \dataphyre\currency::convert_to_user_currency($a, $b, $c, $d);}
}
if(!function_exists('convert_to_website_currency')){
	/**
	 * Compatibility shim for Datadoc-rendered examples that convert to website currency.
	 *
	 * @deprecated Wrapper-only fallback.
	 */
	function convert_to_website_currency($a=null, $b=null, $c=null, $d=null){return \dataphyre\currency::convert_to_website_currency($a, $b, $c, $d);}
}
if(!function_exists('adapt')){
	/**
	 * Compatibility shim for Datadoc-rendered examples that call localization adaptation helpers.
	 *
	 * @deprecated Wrapper-only fallback.
	 */
	function adapt($a=null, $b=null){ return \dataphyre\templating::adapt($a, $b);}
}
