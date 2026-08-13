<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\FulltextEngine\SearchManager;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'fulltext_engine'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
if(!defined('DP_FULLTEXT_ENGINE_CFG')){
	define('DP_FULLTEXT_ENGINE_CFG', [
		'framework'=>[
			'indexes'=>[],
			'resolvers'=>'invalid',
		],
	]);
}
$dp_fulltext_invalid_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_fulltext_invalid_modules_root.'/core/kernel/autoloader.php';
if(!function_exists('tracelog')){
	function tracelog(...$arguments): void {}
}
require_once __DIR__.'/fulltext_coverage_helpers.php';
\dataphyre\autoloader::register($dp_fulltext_invalid_modules_root);
\dataphyre\autoloader::register_framework_modules(['fulltext_engine']);

test('fulltext manager treats non-array resolver configuration as unavailable', static function(Context $t): void {
	SearchManager::flush();
	$t->same(null, SearchManager::instance()->resolver('unconfigured'));
	SearchManager::flush();
})->tag('fulltext', 'manager', 'deep-coverage')->group('framework-coverage');
