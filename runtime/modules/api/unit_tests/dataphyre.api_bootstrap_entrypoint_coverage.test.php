<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	if(!function_exists(__NAMESPACE__.'\\tracelog')){
		function tracelog(...$arguments): void {}
	}
	if(!class_exists(core::class, false)){
		final class core {
			public static function load_framework_modules(array $modules): array {
				\Dataphyre\Test\TestState::channel('api.bootstrap')->put('framework_modules',$modules);
				return $modules;
			}
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	test('api bootstrap and kernel entrypoints load framework and layered config files', static function(Context $t): void {
		$state=$t->state('api.bootstrap',['framework_modules'=>[],'loaded'=>[]]);
		$modules=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
		require $modules.'/api/Framework/Bootstrap.php';
		$t->isTrue(class_exists('dataphyre\\api', false));
		$t->same(['routing', 'http'],$state->get('framework_modules'));

		$workspace=$t->workspace('api-bootstrap');
		$common=$workspace->directory('common');
		$app=$workspace->directory('app');
		$workspace->file('common/config/api.php',"<?php \\Dataphyre\\Test\\TestState::channel('api.bootstrap')->append('loaded','common');\n");
		$workspace->file('app/config/api.php',"<?php \\Dataphyre\\Test\\TestState::channel('api.bootstrap')->append('loaded','app');\n");
		\dataphyre\api_bootstrap_config([
			'common_dataphyre'=>$common,
			'dataphyre'=>$app,
			'ignored'=>null,
		]);
		$t->same(['common', 'app'],$state->get('loaded'));
		\dataphyre\api_bootstrap_config(['common_dataphyre'=>'   ']);
		$t->same(['common', 'app'],$state->get('loaded'));
	})->tag('api', 'entrypoint', 'coverage')->group('framework-coverage');
}
