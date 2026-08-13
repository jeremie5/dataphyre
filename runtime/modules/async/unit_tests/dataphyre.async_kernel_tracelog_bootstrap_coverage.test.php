<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class DpAsyncBootstrapTrace {
		/** @var list<array<int,mixed>> */
		public static array $calls=[];
	}

	function tracelog(mixed ...$arguments): void {
		DpAsyncBootstrapTrace::$calls[]=$arguments;
	}
	function dp_module_present(mixed ...$arguments): bool { return true; }
	function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
		if(!defined($constant)){
			define($constant, $defaults);
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
	$dp_async_bootstrap_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime']??''), '/\\').'/modules/async/';
	require_once $dp_async_bootstrap_root.'kernel/async.main.php';

	test('async kernel bootstrap wires the tracelog logger when the module is present', static function(Context $t): void {
		$t->nonPublic(\dataphyre\async::class)->invoke('log', 'bootstrap-log');
		$t->isTrue(count(\dataphyre\DpAsyncBootstrapTrace::$calls)>=2);
		$flattened=json_encode(\dataphyre\DpAsyncBootstrapTrace::$calls, JSON_UNESCAPED_SLASHES);
		$t->contains('bootstrap-log', (string)$flattened);
	})->tag('async', 'coverage')->group('kernel-coverage');
}
