<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	function tracelog(mixed ...$arguments): void {}

	final class core {
		public static function load_framework_module(string $module): void {
			$GLOBALS['dp_flightdeck_surface_module']=$module; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Core-loader fixture records the module requested through the legacy loader boundary."
			$GLOBALS['dp_flightdeck_surface_load_ready']=true; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Core-loader fixture publishes readiness to its standalone autoload callback."
		}
	}
}

namespace {
	require_once __DIR__.'/flightdeck_surface_templating_probe.php';

	$surface=(string)($argv[1] ?? '');
	$facades=(string)($argv[2] ?? '');
	$runtime=(string)($argv[3] ?? '');
	$module=(string)($argv[4] ?? '');
	if($surface==='' || $facades==='' || $runtime==='' || $module===''){
		throw new InvalidArgumentException('surface, facade, runtime, and module arguments are required.');
	}

	define('ROOTPATH',['common_dataphyre_runtime'=>rtrim($runtime, '/\\').DIRECTORY_SEPARATOR]);
	spl_autoload_register(static function(string $class)use($facades,$module): void {
		$target=$module==='panel' ? 'Dataphyre\\Panel\\Panel' : 'Dataphyre\\Reactor\\Reactor';
		if($class===$target && !empty($GLOBALS['dp_flightdeck_surface_load_ready'])){ // dataphyre-test-architecture: exempt[raw-global-variable] reason="Standalone autoloader consumes the readiness marker published by the core-loader probe."
			require_once $facades;
		}
	});
	include $surface;
	ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Core-loader fixture captures HTML emitted by the real surface dispatch boundary."
	$surfaceClass='dataphyre_flightdeck_'.$module.'_surface';
	$surfaceClass::dispatch();
	$html=(string)ob_get_clean();

	echo json_encode([
		'loaded_module'=>$GLOBALS['dp_flightdeck_surface_module'] ?? null, // dataphyre-test-architecture: exempt[raw-global-variable] reason="Core-loader fixture serializes the module observed at the legacy loader boundary."
		'rendered'=>str_contains($html, $module==='panel' ? 'Panel Resource Inspector' : 'Reactor Inspector'),
	], JSON_THROW_ON_ERROR);
}
