<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class DpReactorKernelTrace {
		public static int $calls=0;
	}

	if(!function_exists(__NAMESPACE__.'\\tracelog')){
		function tracelog(mixed ...$arguments): void {
			DpReactorKernelTrace::$calls++;
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
			if(!defined($constant)){
				define($constant, $defaults);
			}
		}
	}

	$dp_reactor_kernel_init_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
	require_once $dp_reactor_kernel_init_root.'/reactor/kernel/reactor.main.php';
	require_once $dp_reactor_kernel_init_root.'/core/kernel/autoloader.php';
	\dataphyre\autoloader::register($dp_reactor_kernel_init_root);
	\dataphyre\autoloader::register_framework_modules(['reactor']);

	test('reactor kernel initialization traces defines defaults and reads configured values', static function(Context $t): void {
		$t->isTrue(defined('DP_REACTOR_CFG'));
		$t->same('component', \dataphyre\reactor::config('component_parameter'));
		$t->same('fallback', \dataphyre\reactor::config('missing', 'fallback'));
		$t->isTrue(\dataphyre\DpReactorKernelTrace::$calls>=1);
	})->tag('reactor', 'coverage')->group('framework-coverage');
}
