<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre\routing {
	if(!defined(__NAMESPACE__.'\\ROOTPATH')){
		define(__NAMESPACE__.'\\ROOTPATH',[
			'common_dataphyre_runtime'=>__DIR__.'/fixtures/compiled_route_runtime/',
		]);
	}
}

namespace {
	require_once \Dataphyre\Test\dataphyre_path().'/runtime/modules/routing/kernel/compiled_route_dispatcher.php';

	use Dataphyre\Test\Context;
	use dataphyre\routing\compiled_route_dispatcher;
	use function Dataphyre\Test\test;

	final class DpCompiledRouteCoreProbe {
		public static int $loads=0;
	}

	test('compiled route dispatcher core loader covers bootstrap and lazy framework loader paths',static function(Context $t): void {
		$dispatcher=$t->nonPublic(compiled_route_dispatcher::class);
		$t->isFalse(class_exists('dataphyre\\core',false));
		$dispatcher->invoke('bootstrap_target','core');
		$t->same(1,DpCompiledRouteCoreProbe::$loads);
		$t->isTrue(defined('DP_CORE_LOADED'));
		$dispatcher->invoke('bootstrap_target','core');
		$dispatcher->invoke('ensure_core_framework_loader');
		$t->isTrue(class_exists('dataphyre\\core',false));
		$dispatcher->invoke('ensure_core_framework_loader');
	})->tag('routing','compiled-dispatcher','coverage')->group('framework-coverage');
}
