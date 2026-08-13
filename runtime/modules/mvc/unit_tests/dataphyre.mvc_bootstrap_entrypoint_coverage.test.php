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
			public static array $loaded=[];
			public static function load_framework_modules(array $modules): array {
				self::$loaded=$modules;
				return $modules;
			}
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $defaults): void {
			if(!defined($constant)){
				define($constant, $defaults);
			}
		}
	}

	test('mvc bootstrap and kernel config entrypoints initialize framework dependencies', static function(Context $t): void {
		$modules=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
		require $modules.'/mvc/Framework/Bootstrap.php';
		$t->isTrue(class_exists('dataphyre\\mvc', false));
		$t->same([], \dataphyre\mvc::config('routes'));
		$t->same('fallback', \dataphyre\mvc::config('missing', 'fallback'));
		$t->same(['http', 'routing', 'templating', 'sql'], \dataphyre\core::$loaded);
	})->tag('mvc', 'entrypoint', 'coverage')->group('framework-coverage');
}
