<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Recovery;

$kernelEntry=dirname(__DIR__).'/kernel/recovery.main.php';
if(is_file($kernelEntry)) require_once $kernelEntry;

if(class_exists('\dataphyre\core', false)){
	\dataphyre\core::load_framework_module('http');
}
if(!class_exists('\Dataphyre\Http\Response', false)){
	$httpResponse=dirname(__DIR__, 2).'/http/Framework/Response.php';
	if(is_file($httpResponse)) require_once $httpResponse;
}

foreach([
	'LocalizedText.php',
	'RecoveryContext.php',
	'RecoveryAction.php',
	'RecoveryActionDefinition.php',
	'ProblemDefinition.php',
	'Evidence.php',
	'IncidentFingerprint.php',
	'Problem.php',
	'RecoveryRegistry.php',
	'RecoveryManager.php',
	'ProblemResponse.php',
	'Recovery.php',
] as $frameworkFile){
	$path=__DIR__.'/'.$frameworkFile;
	if(is_file($path)) require_once $path;
}
