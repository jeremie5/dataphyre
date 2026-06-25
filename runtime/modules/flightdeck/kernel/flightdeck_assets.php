<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

$view_file=__DIR__.'/view.php';
$flightdeck_file=__DIR__.'/flightdeck.php';
$auth_file=__DIR__.'/auth.php';
if(is_file($auth_file)){
	require_once($auth_file);
}
if(is_file($view_file)!==true){
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	echo 'Not found';
	return;
}
if(class_exists('dataphyre_flightdeck_auth', false) && dataphyre_flightdeck_auth::production_disabled()===true){
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	echo 'Not found';
	return;
}

if(defined('DATAPHYRE_FLIGHTDECK_NO_DISPATCH')!==true){
	define('DATAPHYRE_FLIGHTDECK_NO_DISPATCH', true);
}
if(defined('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST')!==true){
	define('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST', true);
}
if(is_file($flightdeck_file)){
	require_once($flightdeck_file);
}
require_once($view_file);

$route_bindings=class_exists('dataphyre\\routing', false) ? (\dataphyre\routing::$bindings ?? []) : [];
$asset=(string)($route_bindings['asset'] ?? $_GET['asset'] ?? basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)));
$content=class_exists('dataphyre_flightdeck', false) ? dataphyre_flightdeck::asset_content($asset) : null;
if(!is_array($content)){
	$content=class_exists('dataphyre_flightdeck_view', false) ? dataphyre_flightdeck_view::asset_content($asset) : null;
}
if(!is_array($content)){
	$surface_assets=[
		'panel-surface.css'=>'panel.php',
		'reactor-surface.css'=>'reactor.php',
		'dpanel-surface.css'=>'dpanel.php',
		'datadoc-surface.css'=>'datadoc.php',
		'tracelog-surface.css'=>'tracelog.php',
		'tracelog-plotter.js'=>'tracelog.php',
	];
	$surface_file=$surface_assets[basename($asset)] ?? '';
	if($surface_file!==''){
		$surface_path=__DIR__.'/surfaces/'.$surface_file;
		if(is_file($surface_path)){
			require_once($surface_path);
			foreach([
				'dataphyre_flightdeck_panel_surface',
				'dataphyre_flightdeck_reactor_surface',
				'dataphyre_flightdeck_dpanel_surface',
				'dataphyre_flightdeck_datadoc_surface',
				'dataphyre_flightdeck_tracelog_surface',
			] as $surface_class){
				if(class_exists($surface_class, false) && method_exists($surface_class, 'asset_content')){
					$content=$surface_class::asset_content($asset);
					if(is_array($content)){
						break;
					}
				}
			}
		}
	}
}
if(!is_array($content)){
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	echo 'Not found';
	return;
}

$body=(string)($content['body'] ?? '');
$content_type=(string)($content['content_type'] ?? 'application/octet-stream');
$etag='"'.sha1($asset.'|'.$body).'"';
$last_modified=gmdate('D, d M Y H:i:s', filemtime(__FILE__) ?: time()).' GMT';
$method=strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

header_remove('Pragma');
header_remove('Expires');
header('Content-Type: '.$content_type);
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: '.$etag);
header('Last-Modified: '.$last_modified);
header('Vary: Accept-Encoding');
header('X-Content-Type-Options: nosniff');

$if_none_match=trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$if_modified_since=strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
$mtime=strtotime($last_modified) ?: time();
if(($if_none_match!=='' && $if_none_match===$etag) || ($if_none_match==='' && $if_modified_since>0 && $if_modified_since>=$mtime)){
	http_response_code(304);
	return;
}

header('Content-Length: '.strlen($body));
if($method==='HEAD'){
	return;
}
echo $body;
