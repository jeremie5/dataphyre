<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once dirname(__DIR__).'/Framework/Bootstrap.php';

if(class_exists('\dataphyre\autoloader', false)){
	\dataphyre\autoloader::register_framework_modules(['http', 'panel']);
}

if(!class_exists('\Dataphyre\Http\Request')){
	foreach([
		dirname(__DIR__, 2).'/http/Framework/UploadedFile.php',
		dirname(__DIR__, 2).'/http/Framework/Request.php',
		dirname(__DIR__, 2).'/http/Framework/Response.php',
	] as $path){
		if(is_file($path)){
			require_once $path;
		}
	}
}
if(!class_exists('\Dataphyre\Panel\PanelRenderer', false)){
	$rendering_root=dirname(__DIR__).'/Framework/Rendering/';
	foreach([
		'PanelRendererPages.php',
		'PanelRendererImports.php',
		'PanelRendererActions.php',
		'PanelRendererBulkOperations.php',
		'PanelRendererShell.php',
		'PanelRendererRecordSections.php',
		'PanelRendererTables.php',
		'PanelRendererData.php',
		'PanelRendererForms.php',
		'PanelRendererAssets.php',
		'PanelRenderer.php',
	] as $renderer_file){
		$path=$rendering_root.$renderer_file;
		if(is_file($path)){
			require_once $path;
		}
	}
}

foreach([
	dirname(__DIR__).'/Framework/Http/PanelPageResult.php',
	dirname(__DIR__).'/Framework/Http/PanelAssetController.php',
] as $path){
	if(is_file($path)){
		require_once $path;
	}
}

$request_uri=(string)($_SERVER['REQUEST_URI'] ?? '');
$request_path=(string)(parse_url($request_uri, PHP_URL_PATH) ?: '');
$asset=(string)(\dataphyre\routing::$bindings['asset'] ?? $_GET['asset'] ?? basename($request_path));
$asset=basename(str_replace('\\', '/', $asset));

if(!class_exists('\Dataphyre\Panel\PanelAssetController') || !class_exists('\Dataphyre\Http\Request')){
	http_response_code(503);
	header('Content-Type: text/plain; charset=UTF-8');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	echo 'Dataphyre Panel asset endpoint is unavailable.';
	return;
}

$request=\Dataphyre\Http\Request::capture(['asset'=>$asset]);
$response=\Dataphyre\Panel\PanelAssetController::response($asset, $request);

http_response_code((int)($response->status ?? 200));
foreach((array)($response->headers ?? []) as $name=>$value){
	if(!is_string($name) || trim($name)===''){
		continue;
	}
	foreach((array)$value as $line){
		if(is_scalar($line)){
			header($name.': '.(string)$line);
		}
	}
}
echo (string)($response->body ?? $response->content() ?? '');
