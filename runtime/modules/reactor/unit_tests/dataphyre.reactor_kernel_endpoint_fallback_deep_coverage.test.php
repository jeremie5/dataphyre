<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	if(!function_exists(__NAMESPACE__.'\\tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
}

namespace Dataphyre\Reactor {
	final class ReactorEndpoint {
		public static int $singleCalls=0;
		public static int $batchCalls=0;

		public static function emit(): void {
			self::$singleCalls++;
		}

		public static function emitBatch(): void {
			self::$batchCalls++;
		}
	}
}

namespace {
	use Dataphyre\Reactor\ReactorEndpoint;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
			if(!defined($constant)){
				define($constant, $defaults);
			}
		}
	}

	test('reactor endpoint entrypoint bootstraps directly and dispatches a single request', static function(Context $t): void {
		$t->globalMap('_SERVER')->forget('HTTP_X_DATAPHYRE_REACTOR_BATCH');
		require rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/reactor/kernel/endpoint.php';
		$t->isTrue(class_exists('dataphyre\\reactor', false));
		$t->same(0, ReactorEndpoint::$batchCalls);
		$t->same(1, ReactorEndpoint::$singleCalls);
	})->tag('reactor', 'coverage')->group('framework-coverage');
}
