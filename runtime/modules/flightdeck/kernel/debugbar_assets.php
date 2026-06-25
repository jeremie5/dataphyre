<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

$debugbar_file=__DIR__.'/debugbar.php';
if(is_file($debugbar_file)!==true){
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	echo 'Not found';
	return;
}

require_once($debugbar_file);

if(class_exists('dataphyre_flightdeck_auth', false) && dataphyre_flightdeck_auth::production_disabled()===true){
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	echo 'Not found';
	return;
}

$route_bindings=class_exists('dataphyre\\routing', false) ? (\dataphyre\routing::$bindings ?? []) : [];
$asset=(string)($route_bindings['asset'] ?? $_GET['asset'] ?? basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)));
$content=class_exists('dataphyre_flightdeck_debugbar', false) ? dataphyre_flightdeck_debugbar::asset_content($asset) : null;
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
