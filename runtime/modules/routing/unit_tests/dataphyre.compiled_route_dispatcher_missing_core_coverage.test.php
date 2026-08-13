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
			'common_dataphyre_runtime'=>__DIR__.'/fixtures/compiled_route_missing_runtime/',
		]);
	}
}

namespace {
	require_once \Dataphyre\Test\dataphyre_path().'/runtime/modules/routing/kernel/compiled_route_dispatcher.php';

	use Dataphyre\Test\Context;
	use dataphyre\routing\compiled_route_dispatcher;
	use function Dataphyre\Test\test;

	test('compiled route dispatcher core loader rejects a missing framework loader',static function(Context $t): void {
		$t->throws(static fn()=>$t->nonPublic(compiled_route_dispatcher::class)->invoke('ensure_core_framework_loader'),RuntimeException::class);
	})->tag('routing','compiled-dispatcher','coverage')->group('framework-coverage');
}
