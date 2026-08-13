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

	define('ROOTPATH',['common_dataphyre_runtime'=>rtrim($runtime, '/\\').DIRECTORY_SEPARATOR]);
	include $surface;
	ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Bootstrap fixture captures HTML emitted by the real surface dispatch boundary."
	$surfaceClass='dataphyre_flightdeck_'.$module.'_surface';
	$surfaceClass::dispatch();
	$html=(string)ob_get_clean();

	echo json_encode([
		'bootstrap_loaded'=>!empty($GLOBALS['dp_flightdeck_surface_bootstrap']), // dataphyre-test-architecture: exempt[raw-global-variable] reason="Bootstrap fixture observes the module bootstrap marker published by its generated facade."
		'rendered'=>str_contains($html, $module==='panel' ? 'Panel Resource Inspector' : 'Reactor Inspector'),
	], JSON_THROW_ON_ERROR);
}
