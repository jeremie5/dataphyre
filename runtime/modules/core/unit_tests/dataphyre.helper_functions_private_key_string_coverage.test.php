<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DP_CORE_CFG')){
	define('DP_CORE_CFG', ['private_key'=>'string-private-key']);
}
if(!defined('CFG')){
	define('CFG', [
		'dataphyre'=>[
			'array_cfg'=>'core',
			'array_cfg_module'=>['array_cfg'=>'module'],
		],
	]);
}
$dp_helper_key_string_kernel=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core/kernel';
require_once $dp_helper_key_string_kernel.'/helper_functions.php';

test('helper functions prefer static private keys then fall back to a configured string key', static function(Context $t): void {
	$workspace=$t->workspace('helper-key-string');
	$staticRoot=$workspace->directory('static-root');
	$fallbackRoot=$workspace->directory('fallback-root');
	$rootOverride=$t->global('DATAPHYRE_HELPER_ROOTPATH_OVERRIDE')->replace([
		'common_dataphyre'=>'',
		'dataphyre'=>$staticRoot.'/',
	]);
	$workspace->file('static-root/config/core.php',"<?php return null;\n");
	$workspace->file('static-root/config/array_cfg_module.php',"<?php return null;\n");
	$t->same(
		['array_cfg'=>'core', 'array_cfg_module'=>['array_cfg'=>'module']],
		dp_define_core_config('DP_HELPER_ARRAY_CFG_CORE')
	);
	$t->same(
		['array_cfg'=>'module'],
		dp_define_module_config('array_cfg_module', 'DP_HELPER_ARRAY_CFG_MODULE')
	);
	$workspace->file('static-root/config/static/dpvk','first-static,second-static');
	$t->same(['first-static', 'second-static'], dpvks());
	$rootOverride->replace(['common_dataphyre'=>'','dataphyre'=>$fallbackRoot.'/']);
	$t->same(['string-private-key'], dpvks());
	$rootOverride->replace(false);
	$t->same(['string-private-key'], dpvks());
	$t->same('string-private-key', dpvk());
	$t->same('string-private-key', dpvk());
})->tag('core', 'helper-functions', 'private-key', 'coverage')->group('framework-coverage');
