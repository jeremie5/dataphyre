<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

require_once(__DIR__.'/assets_support.php');

$route_bindings=class_exists('dataphyre\\routing', false) ? (\dataphyre\routing::$bindings ?? []) : [];
$asset_request=(string)($route_bindings['asset'] ?? $_GET['asset'] ?? basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)));
$asset=dataphyre_datadoc_ui_asset_name($asset_request);
$content=dataphyre_datadoc_ui_asset_content($asset);
if($content===null){
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	echo 'Not found';
	exit();
}

$body=$content['content'];
$etag='"'.hash('sha256', $asset.'|'.$body).'"';
$last_modified_epoch=1767225600;

header_remove('Pragma');
header_remove('Expires');
header('Content-Type: '.$content['content_type']);
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: '.$etag);
header('Last-Modified: '.gmdate('D, d M Y H:i:s', $last_modified_epoch).' GMT');
header('Vary: Accept-Encoding');
header('X-Content-Type-Options: nosniff');

$if_none_match=trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$if_modified_since=strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
if(($if_none_match!=='' && $if_none_match===$etag) || ($if_none_match==='' && $if_modified_since>=$last_modified_epoch)){
	http_response_code(304);
	exit();
}

header('Content-Length: '.strlen($body));
if(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))==='HEAD'){
	exit();
}
echo $body;
exit();
