<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	final class core {
		/** @var array<int,string> */
		public static array $frameworkLoads=[];

		public static function load_framework_module(string $module): void {
			self::$frameworkLoads[]=$module;
		}
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

	test('reactor endpoint entrypoint uses the loaded core and dispatches batch requests', static function(Context $t): void {
		$t->globalMap('_SERVER')->put('HTTP_X_DATAPHYRE_REACTOR_BATCH', 'true');
		require rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/reactor/kernel/endpoint.php';
		$t->same(['reactor'], \dataphyre\core::$frameworkLoads);
		$t->same(1, ReactorEndpoint::$batchCalls);
		$t->same(0, ReactorEndpoint::$singleCalls);
	})->tag('reactor', 'coverage')->group('framework-coverage');
}
