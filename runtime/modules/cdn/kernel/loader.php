<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
$requested_filename=(string)(\dataphyre\routing::$bindings['filename'] ?? '');
$requested_filename=urldecode($requested_filename);
$filename=basename(str_replace(["\0", "\\", "/"], '', $requested_filename));
$filepath=ROOTPATH['common_dataphyre'].'cache/cdn/'.$filename;

$mime_types=[
	'png'=>'image/png',
	'jpeg'=>'image/jpeg',
	'jpg'=>'image/jpeg',
	'gif'=>'image/gif',
	'css'=>'text/css',
	'html'=>'text/html; charset=UTF-8',
	'htm'=>'text/html; charset=UTF-8',
	'js'=>'application/javascript',
	'ogg'=>'application/ogg',
	'pdf'=>'application/pdf',
	'zip'=>'application/zip',
	'mp4'=>'video/mp4',
	'mp3'=>'audio/mpeg',
	'wav'=>'audio/wav',
	'svg'=>'image/svg+xml',
	'webp'=>'image/webp',
	'json'=>'application/json',
	'txt'=>'text/plain; charset=UTF-8',
	'woff'=>'font/woff',
	'woff2'=>'font/woff2',
];

if(is_file($filepath) && is_readable($filepath)){
	$extension=strtolower((string)pathinfo($filepath, PATHINFO_EXTENSION));
	$content_type=$mime_types[$extension] ?? 'application/octet-stream';
	header('Content-Type: '.$content_type);
	header('Content-Length: '.filesize($filepath));
	readfile($filepath);
	exit();
}

http_response_code(404);
exit();
