<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre { const RUN_MODE='request'; }

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;
	define('dataphyre\\DP_CORE_CFG', [
		'private_key'=>['unavailable-key'], 'encryption_version'=>0,
		'core'=>['unavailable'=>['file_path'=>__DIR__.'/fixtures/core_functions_unavailable_request_view.php', 'redirection'=>false]],
	]);
	require_once __DIR__.'/fixtures/core_functions_unavailable_bootstrap.php';

	test('core functions unavailable request includes configured fallback and catches view failures', static function(Context $t): void {
		$state=dp_core_unavailable_scenario($t);
		$query=$t->globalMap('_GET')->clear();
		dp_core_unavailable_install_terminator();
		$t->throws(static fn()=>\dataphyre\core::unavailable(__FILE__, '1', __CLASS__, __FUNCTION__, 'Request unavailable', 'coverage'), RuntimeException::class);
		$t->isTrue($state->get('view_loaded'));
		$t->hasPath('err', $query->map());
		$state->put('view_throws', true);
		$t->throws(static fn()=>\dataphyre\core::unavailable(__FILE__, '2', __CLASS__, __FUNCTION__, 'Request unavailable throw', 'coverage'), RuntimeException::class);
		$t->notEmpty($state->get('pre_init_errors'));
	})->tag('core', 'functions', 'unavailable', 'request', 'coverage')->group('framework-coverage');
}
