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
$stateRoot=realpath((string)(getenv('DATAPHYRE_PREFLIGHT_STATE_ROOT') ?: ''));
$runtimeProjectRoot=realpath((string)(getenv('DATAPHYRE_RUNTIME_PROJECT_ROOT') ?: ''));
$path=parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if(
	$projectRoot==='' || $application===''
	|| $stateRoot===false || !is_dir($stateRoot) || is_link($stateRoot)
	|| $runtimeProjectRoot===false || realpath($projectRoot)!==$runtimeProjectRoot
	|| (string)(getenv('DATAPHYRE_RUNTIME_POOL') ?: '')!=='health-preflight'
	|| (string)(getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: '')!=='health-preflight'
	|| (string)(getenv('DATAPHYRE_SCHEDULER_ACTIVATION_MODE') ?: '')!=='record_only'
	|| realpath((string)(getenv('DATAPHYRE_SCHEDULER_STATE_ROOT') ?: ''))!==$stateRoot
	|| !is_string($path) || $path!=='/health'
	|| in_array($application, ['.', '..'], true)
	|| preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D', $application)!==1
){
	http_response_code(404);
	exit;
}

$GLOBALS['DATAPHYRE_INTERNAL_APPLICATION_RELEASE_PREFLIGHT']=[
	'state_root'=>$stateRoot,
	'private_key'=>bin2hex(random_bytes(32)),
	'project_root'=>$runtimeProjectRoot,
	'token'=>bin2hex(random_bytes(32)),
	'scheduler_attempt_count'=>0,
	'scheduler_failure_count'=>0,
];

$_SERVER['DATAPHYRE_PROJECT_ROOT']=$projectRoot;
$_SERVER['HTTP_X_DATAPHYRE_APPLICATION']=$application;
$_SERVER['HTTP_X_TRAFFIC_SOURCE']='internal_traffic';
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['REQUEST_URI']='/health';
$_GET['uri']='health';
$_REQUEST['uri']='health';

require dirname(__DIR__, 3).'/bootstrap.php';
