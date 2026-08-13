<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

/**
 * PHP built-in-server router for an external Dataphyre Panel showroom.
 *
 * The showroom remains application-owned. This adapter only pins its ROOTPATH
 * to the current framework checkout so integration tests cannot silently load
 * an older application-vendored Dataphyre copy.
 *
 * Required environment variable:
 *   DP_PANEL_LIVE_EXAMPLE_ENTRY=/absolute/path/to/showroom/index.php
 *
 * Optional environment variable:
 *   DP_PANEL_RUNTIME_ROOT=/absolute/path/to/dataphyre
 */

if(PHP_SAPI!=='cli-server'){
	fwrite(STDERR, "panel_live_example_router.php is only for PHP's built-in server.\n");
	exit(64);
}

$entry=trim((string)getenv('DP_PANEL_LIVE_EXAMPLE_ENTRY'));
$runtimeRoot=trim((string)getenv('DP_PANEL_RUNTIME_ROOT'));
$runtimeRoot=$runtimeRoot!=='' ? $runtimeRoot : dirname(__DIR__, 3);
$runtimeRoot=realpath($runtimeRoot) ?: '';
$entry=$entry!=='' ? (realpath($entry) ?: '') : '';

if($entry==='' || !is_file($entry)){
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'DP_PANEL_LIVE_EXAMPLE_ENTRY must name an existing showroom entrypoint.';
	return;
}
if($runtimeRoot==='' || !is_file($runtimeRoot.'/runtime/modules/core/kernel/autoloader.php')){
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'DP_PANEL_RUNTIME_ROOT must name a Dataphyre checkout.';
	return;
}
if(!defined('ROOTPATH')){
	define('ROOTPATH', [
		'dataphyre'=>$runtimeRoot,
		'common_dataphyre_runtime'=>$runtimeRoot.'/runtime',
	]);
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'panel'=>true, 'reactor'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}

require $entry;
