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

if(!defined('CFG')){
	define('CFG', new class {
		/** @return array<string,mixed> */
		public function raw(): array {
			return [
				'dataphyre'=>[
					'from_cfg'=>'core',
					'cfg_module'=>['from_cfg'=>'module'],
				],
			];
		}
	});
}

$dp_helper_cfg_kernel=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core/kernel';
require_once $dp_helper_cfg_kernel.'/helper_functions.php';

test('helper functions merge non array config files through CFG raw fallback', static function(Context $t): void {
	$workspace=$t->workspace('helper-cfg-fallback');
	$common=$workspace->directory('common');
	$app=$workspace->directory('app');
	$t->global('DATAPHYRE_HELPER_ROOTPATH_OVERRIDE')->replace([
		'common_dataphyre'=>$common.'/',
		'dataphyre'=>$app.'/',
	]);
	$workspace->file('common/config/core.php',"<?php return null;\n");
	$workspace->file('common/config/cfg_module.php',"<?php return null;\n");
	$t->same(
		['from_cfg'=>'core', 'cfg_module'=>['from_cfg'=>'module']],
		dp_define_core_config('DP_HELPER_CFG_FALLBACK_CORE')
	);
	$t->same(
		['default'=>true, 'from_cfg'=>'module'],
		dp_define_module_config('cfg_module', 'DP_HELPER_CFG_FALLBACK_MODULE', ['default'=>true])
	);
})->tag('core', 'helper-functions', 'cfg', 'coverage')->group('framework-coverage');
