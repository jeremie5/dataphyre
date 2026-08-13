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
}

namespace {
	require_once __DIR__.'/flightdeck_surface_templating_probe.php';

	$surface=(string)($argv[1] ?? '');
	$runtime=(string)($argv[2] ?? '');
	$module=(string)($argv[3] ?? '');
	if($surface==='' || $runtime==='' || $module===''){
		throw new InvalidArgumentException('surface, runtime, and module arguments are required.');
	}

	if($runtime!=='-'){
		define('ROOTPATH',['common_dataphyre_runtime'=>rtrim($runtime, '/\\').DIRECTORY_SEPARATOR]);
	}
	ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Unavailable-surface fixture captures repeated output from the real include boundary."
	include $surface;
	$surfaceClass='dataphyre_flightdeck_'.$module.'_surface';
	$surfaceClass::dispatch();
	include $surface;
	$html=(string)ob_get_clean();

	echo json_encode([
		'status'=>http_response_code(),
		'unavailable'=>str_contains($html, 'module is unavailable'),
		'repeated_dispatches'=>substr_count($html, 'module is unavailable'),
	], JSON_THROW_ON_ERROR);
}
