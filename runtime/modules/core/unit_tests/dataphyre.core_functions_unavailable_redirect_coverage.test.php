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
		'core'=>['unavailable'=>['file_path'=>__DIR__.'/fixtures/core_functions_unavailable_redirect_view.php', 'redirection'=>true]],
	]);
	require_once __DIR__.'/fixtures/core_functions_unavailable_bootstrap.php';

	test('core functions unavailable request emits configured redirection', static function(Context $t): void {
		$state=dp_core_unavailable_scenario($t);
		dp_core_unavailable_install_terminator();
		$t->throws(static fn()=>\dataphyre\core::unavailable(__FILE__, '1', __CLASS__, __FUNCTION__, 'Redirect unavailable', 'coverage'), RuntimeException::class);
		$locations=array_values(array_filter($state->get('headers'), static fn(string $header): bool=>str_starts_with($header, 'Location: ')));
		$t->same(1, count($locations));
	})->tag('core', 'functions', 'unavailable', 'redirect', 'coverage')->group('framework-coverage');
}
