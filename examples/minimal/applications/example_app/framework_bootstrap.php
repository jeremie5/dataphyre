<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
$payload=[
	'ok'=>true,
	'runtime'=>'dataphyre',
	'application'=>defined('APP') ? APP : 'example_app',
	'project_root'=>defined('DATAPHYRE_PROJECT_ROOT') ? DATAPHYRE_PROJECT_ROOT : null,
	'runtime_root'=>defined('DATAPHYRE_RUNTIME_ROOT') ? DATAPHYRE_RUNTIME_ROOT : null,
	'booted_at'=>gmdate('c'),
];

if(PHP_SAPI!=='cli' && headers_sent()===false){
	header('Content-Type: application/json; charset=utf-8');
}

echo json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
