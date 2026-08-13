<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	/** Deterministic namespace seams for environment branches that cannot be changed after worker bootstrap. */
	function defined(string $constant): bool {
		return $constant==='ROOTPATH' ? false : \defined($constant);
	}

	function is_dir(string $path): bool {
		return str_ends_with(str_replace('\\', '/', $path), '/.git') ? false : \is_dir($path);
	}

	function function_exists(string $function): bool {
		return strtolower($function)==='proc_open' ? false : \function_exists($function);
	}

	final class routing {
		public static string $page='catalog';
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\suite;
	use function Dataphyre\Test\test;

	require_once __DIR__.'/localization_kernel_testkit.php';

	if(!\defined('RUN_MODE')){
		\define('RUN_MODE', 'unit_test');
	}
	if(!\defined('IS_PRODUCTION')){
		\define('IS_PRODUCTION', false);
	}

	$localization_environment_runtime=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\');
	require_once $localization_environment_runtime.'/modules/localization/kernel/localization.main.php';

	suite('Localization deterministic environment seams')
		->contract('localization.environment-seams', 1)
		->layer('integration')
		->risk('medium')
		->watches('module:localization')
		->through('routing', 'repository-discovery', 'process-availability')
		->isolation('case')
		->tag('localization', 'environment-seams')
		->group('framework-coverage');

	test('environment seams cover route discovery repository exhaustion and unavailable process execution without eval', static function(Context $t): void {
		$scenario=new LocalizationKernelScenario($t);
		$t->same('/catalog', \dataphyre\localization::active_page());
		$scenario->internals()->writeProperty('source_repository_path', null);
		$t->same(null, $scenario->internals()->invoke('source_repository_path'));
		$t->same(null, $scenario->internals()->invoke('git_value', $scenario->workspace()->root(), ['rev-parse', 'HEAD']));
	});
}
