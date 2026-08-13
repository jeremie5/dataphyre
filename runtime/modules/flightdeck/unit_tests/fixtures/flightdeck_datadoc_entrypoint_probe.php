<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

[$script,$root,$surface,$templating,$facade,$wrapper,$assets]=$argv;

if(!defined('ROOTPATH')){
	define('ROOTPATH', [
		'root'=>rtrim($root,'/\\').'/',
		'common'=>rtrim($root,'/\\').'/',
		'common_dataphyre'=>rtrim($root,'/\\').'/',
		'common_dataphyre_runtime'=>rtrim($root,'/\\').'/runtime/',
		'dataphyre'=>rtrim($root,'/\\').'/',
		'application_roots'=>[rtrim($root,'/\\').'/runtime'],
	]);
}
require_once $templating;
define('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST', true);
require $surface;

$level=ob_get_level();
ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Standalone entrypoint fixture captures the real legacy surface emission boundary."
dataphyre_flightdeck_datadoc_surface::dispatch();
$unavailable=(string)ob_get_clean();

$_SERVER=['REQUEST_URI'=>'/dataphyre/datadoc','REQUEST_METHOD'=>'GET']; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Entrypoint fixture must model the native DataDoc request boundary."
$_GET=[]; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Entrypoint fixture must clear the native DataDoc query boundary."
$_POST=[]; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Entrypoint fixture must clear the native DataDoc form boundary."
ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Standalone entrypoint fixture captures the bootstrapped page emission boundary."
dataphyre_flightdeck_datadoc_surface::dispatch_entrypoint(false, [
	'main'=>$facade,
	'wrapper'=>$wrapper,
	'assets'=>$assets,
]);
$bootstrapped=(string)ob_get_clean();

ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Standalone entrypoint fixture verifies repeated asset-only includes remain silent."
include $surface;
$repeated=(string)ob_get_clean();
while(ob_get_level()>$level){
	ob_end_clean();
}

echo json_encode([
	'unavailable'=>str_contains($unavailable,'DataDoc module class could not be loaded'),
	'facade_loaded'=>class_exists('dataphyre\\datadoc',false),
	'bootstrapped'=>str_contains($bootstrapped,'No DataDoc projects yet'),
	'repeated_silent'=>$repeated==='',
],JSON_UNESCAPED_SLASHES);
