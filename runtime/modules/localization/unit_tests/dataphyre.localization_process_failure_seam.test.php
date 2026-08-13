<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	/** Deterministic process-start failure for the source metadata boundary. */
	function proc_open(array|string $command, array $descriptor_spec, array &$pipes): false { // dataphyre-test-architecture: exempt[raw-process-control] reason="Namespace seam deterministically models process-start failure at the production source metadata boundary."
		$pipes=[];
		return false;
	}

	function function_exists(string $function): bool {
		return strtolower($function)==='proc_open' || \function_exists($function);
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

	$localizationProcessRuntime=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\');
	require_once $localizationProcessRuntime.'/modules/localization/kernel/localization.main.php';

	suite('Localization source process failure seam')
		->contract('localization.source-process-failure', 1)
		->layer('integration')
		->risk('low')
		->watches('module:localization')
		->through('source-metadata-process-boundary')
		->isolation('case')
		->tag('localization', 'environment-seams', 'exact-coverage')
		->group('framework-coverage');

	test('source metadata returns null when an available process API cannot start git', static function(Context $t): void {
		$scenario=new \LocalizationKernelScenario($t);
		$t->same(null, $scenario->internals()->invoke('git_value', $scenario->workspace()->root(), ['rev-parse', 'HEAD']));
	});
}
