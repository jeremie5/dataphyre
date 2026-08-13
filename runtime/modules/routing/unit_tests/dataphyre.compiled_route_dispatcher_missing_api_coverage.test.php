<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	if(!class_exists(core::class,false)){
		class core {
			public static function load_framework_modules(array $modules): array { return $modules; }
		}
	}
}

namespace Dataphyre\Http {
	if(!class_exists(Request::class,false)){
		final class Request {
			public static function capture(array $parameters=[]): self { return new self(); }
		}
	}
}

namespace {
	require_once \Dataphyre\Test\dataphyre_path().'/runtime/modules/routing/kernel/compiled_route_dispatcher.php';

	use Dataphyre\Http\Request;
	use Dataphyre\Test\Context;
	use dataphyre\routing\compiled_route_dispatcher;
	use function Dataphyre\Test\test;

	test('compiled route dispatcher api bridges degrade cleanly when api is unavailable',static function(Context $t): void {
		$dispatcher=$t->nonPublic(compiled_route_dispatcher::class);
		$request=Request::capture();
		$route=['api'=>['execution'=>[]]];
		$t->same(null,$dispatcher->invoke('authorize_api_route',$route,$request));
		$t->same(null,$dispatcher->invoke('execute_api_route',$route,$request));
	})->tag('routing','compiled-dispatcher','coverage')->group('framework-coverage');
}
