<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$projectRoot=trim((string)(getenv('DATAPHYRE_PREFLIGHT_PROJECT_ROOT') ?: ''));
$application=trim((string)(getenv('DATAPHYRE_PREFLIGHT_APPLICATION') ?: ''));
$path=parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if(
	$projectRoot==='' || $application===''
	|| !is_string($path) || $path!=='/health'
	|| in_array($application, ['.', '..'], true)
	|| preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D', $application)!==1
){
	http_response_code(404);
	exit;
}

$_SERVER['DATAPHYRE_PROJECT_ROOT']=$projectRoot;
$_SERVER['HTTP_X_DATAPHYRE_APPLICATION']=$application;
$_SERVER['HTTP_X_TRAFFIC_SOURCE']='internal_traffic';
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['REQUEST_URI']='/health';
$_GET['uri']='health';
$_REQUEST['uri']='health';

require dirname(__DIR__, 3).'/bootstrap.php';
