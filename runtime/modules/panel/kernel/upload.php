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
	\dataphyre\autoloader::register_framework_modules(['http', 'panel', 'storage']);
}

if(!class_exists('\Dataphyre\Http\Request')){ foreach([dirname(__DIR__, 2).'/http/Framework/UploadedFile.php', dirname(__DIR__, 2).'/http/Framework/Request.php', dirname(__DIR__, 2).'/http/Framework/Response.php'] as $path){ if(is_file($path)){ require_once $path; } } }

foreach([
	dirname(__DIR__, 2).'/core/kernel/helper_functions.php',
	dirname(__DIR__).'/Framework/Core/PanelContext.php',
	dirname(__DIR__).'/Framework/Core/PanelConfig.php',
	dirname(__DIR__).'/Framework/Support/PanelExtensible.php',
	dirname(__DIR__).'/Framework/Resources/Resource.php',
	dirname(__DIR__).'/Framework/Support/PanelTrace.php',
	dirname(__DIR__).'/Framework/Http/PanelPageResult.php',
] as $path){
	if(is_file($path)){
		require_once $path;
	}
}

$controller=dirname(__DIR__).'/Framework/Http/PanelUploadController.php';
if(is_file($controller)){
	require_once $controller;
}

if(!class_exists('\Dataphyre\Panel\PanelUploadController') || !class_exists('\Dataphyre\Http\Request')){ http_response_code(503); header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); echo json_encode(['ok'=>false, 'error'=>'Panel upload endpoint is unavailable.'], JSON_UNESCAPED_SLASHES); return; }

$response=\Dataphyre\Panel\PanelUploadController::handle(\Dataphyre\Http\Request::capture());

http_response_code((int)($response->status ?? 200));
foreach((array)($response->headers ?? []) as $name=>$value){
	$name=trim((string)$name);
	if($name==='' || preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $name)!==1){ continue; }
	foreach((array)$value as $line){
		if(is_scalar($line)){
			$line=trim((string)$line);
			if($line!=='' && !str_contains($line, "\r") && !str_contains($line, "\n")){
				header($name.': '.$line, strtolower($name)!=='set-cookie');
			}
		}
	}
}
echo (string)($response->body ?? $response->content() ?? '');
