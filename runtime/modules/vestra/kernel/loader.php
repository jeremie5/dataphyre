<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */

$requested_filename=(string)(\dataphyre\routing::$bindings['filename'] ?? '');
$requested_filename=urldecode($requested_filename);
$filename=basename(str_replace(["\0", "\\", "/"], '', $requested_filename));
$filepath=ROOTPATH['common_dataphyre'].'cache/vestra/'.$filename;

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
	$mtime=(int)(filemtime($filepath) ?: time());
	$size=(int)(filesize($filepath) ?: 0);
	$etag='"'.sha1($filepath.'|'.$mtime.'|'.$size).'"';
	$last_modified=gmdate('D, d M Y H:i:s', $mtime).' GMT';
	$if_none_match=trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
	$if_modified_since=strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
	header_remove('Pragma');
	header_remove('Expires');
	header('Content-Type: '.$content_type);
	header('Cache-Control: public, max-age=31536000, immutable');
	header('ETag: '.$etag);
	header('Last-Modified: '.$last_modified);
	header('X-Content-Type-Options: nosniff');
	if(in_array($extension, ['css', 'js', 'mjs', 'map', 'svg', 'json', 'txt'], true)){
		header('Vary: Accept-Encoding');
	}
	if(($if_none_match!=='' && $if_none_match===$etag) || ($if_none_match==='' && $if_modified_since>0 && $if_modified_since>=$mtime)){
		http_response_code(304);
		exit();
	}
	header('Content-Length: '.$size);
	if(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))==='HEAD'){
		exit();
	}
	readfile($filepath);
	exit();
}

http_response_code(404);
exit();
