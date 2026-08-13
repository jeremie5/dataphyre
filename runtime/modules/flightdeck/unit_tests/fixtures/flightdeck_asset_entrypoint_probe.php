<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$root=rtrim((string)($argv[1] ?? ''),'/\\');
$entrypoint=(string)($argv[2] ?? '');
$asset=(string)($argv[3] ?? '');

if(!defined('ROOTPATH')){
	define('ROOTPATH',[
		'common_dataphyre_runtime'=>$root.'/runtime/',
		'common_dataphyre'=>$root.'/',
		'dataphyre'=>$root.'/',
	]);
}
if(!defined('IS_PRODUCTION')){
	define('IS_PRODUCTION',false);
}
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

$GLOBALS['dataphyre_flightdeck_config']=[ // dataphyre-test-architecture: exempt[raw-global-variable] reason="Entrypoint fixture must publish the native Flightdeck configuration boundary."
	'enabled'=>true,
	'password'=>'asset-entrypoint-probe',
	'debugbar'=>['enabled'=>true,'memory_limit'=>null],
];
$_GET=['asset'=>$asset]; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Entrypoint fixture must model the native asset query boundary."
$_SERVER=[ // dataphyre-test-architecture: exempt[raw-superglobal] reason="Entrypoint fixture must model the native HTTP request boundary."
	'REQUEST_METHOD'=>'GET',
	'REQUEST_URI'=>'/dataphyre/flightdeck/assets/'.rawurlencode($asset),
];

ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Entrypoint fixture captures bytes emitted by the real asset transport boundary."
require $entrypoint;
$body=(string)ob_get_clean();

echo json_encode([
	'status'=>(int)(http_response_code() ?: 200),
	'body_length'=>strlen($body),
	'body_hash'=>sha1($body),
],JSON_THROW_ON_ERROR);
