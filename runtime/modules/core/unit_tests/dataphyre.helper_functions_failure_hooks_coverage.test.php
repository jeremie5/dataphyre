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
	define('DP_CORE_CFG', []);
}
if(!function_exists('pre_init_error')){
	function pre_init_error(string $message): void {
		\Dataphyre\Test\TestState::channel('helper.failure-hooks')->append('pre_init_errors',$message);
	}
}
$dp_helper_failure_kernel=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core/kernel';
require_once $dp_helper_failure_kernel.'/helper_functions.php';

test('helper functions delegate missing keys and dependencies to the pre init error hook', static function(Context $t): void {
	$state=$t->state('helper.failure-hooks',['pre_init_errors'=>[]]);
	$t->global('DATAPHYRE_HELPER_ROOTPATH_OVERRIDE')->replace(false);
	$t->global('DATAPHYRE_HELPER_RUN_MODE_OVERRIDE')->replace('pre-init');
	$t->throws(static fn()=>dpvks(), RuntimeException::class);
	dp_module_required('consumer', 'helper_missing', '2.0');
	dp_module_required('consumer', 'core', '1.0', '1.5');
	$errors=$state->get('pre_init_errors');
	$t->count(3,$errors);
	$t->contains('Failed getting private keys',$errors[0]);
	$t->contains('v2.0+',$errors[1]);
	$t->contains('v1.0 - v1.5',$errors[2]);
})->tag('core', 'helper-functions', 'failure', 'coverage')->group('framework-coverage');
