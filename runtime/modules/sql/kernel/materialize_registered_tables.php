<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\RegisteredTableMaterializationCommand;

require_once \dirname(__DIR__).'/Framework/RegisteredTableMaterializationCommand.php';

$script=(string)($_SERVER['SCRIPT_FILENAME'] ?? '');
$serverArgument=(string)((\is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [])[0] ?? '');
$globalArgument=(string)((\is_array($GLOBALS['argv'] ?? null) ? $GLOBALS['argv'] : [])[0] ?? '');
$included=\get_included_files();$firstIncluded=(string)($included[0] ?? '');
if(\realpath($script)===__FILE__){
	foreach([$script,$serverArgument,$globalArgument] as $path){
		if($path==='' || \is_link($path) || \realpath($path)!==__FILE__) exit(64);
	}
	$brokered=(string)(\getenv('DATAPHYRE_RUNTIME_POOL') ?: '')==='one-shot'
		&& (string)(\getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: '')==='one-shot';
	$expectedFirst=$brokered
		? \realpath(\dirname(__DIR__,2).'/core/kernel/application_runtime_one_shot_worker.php')
		: __FILE__;
	if(!\is_string($expectedFirst) || $firstIncluded==='' || \is_link($firstIncluded)
		|| !\hash_equals($expectedFirst,(string)(\realpath($firstIncluded) ?: ''))) exit(64);
	$_SERVER['SCRIPT_FILENAME']=__FILE__;
	if(\is_array($_SERVER['argv'] ?? null)) $_SERVER['argv'][0]=__FILE__;
	if(\is_array($GLOBALS['argv'] ?? null)) $GLOBALS['argv'][0]=__FILE__;
	$_GET=[];$_POST=[];$_COOKIE=[];$_FILES=[];$_REQUEST=[];
	$_SERVER['REQUEST_METHOD']='CLI';$_SERVER['REQUEST_URI']='';$_SERVER['HTTP_X_TRAFFIC_SOURCE']='internal_traffic';
	foreach(['HTTP_X_DATAPHYRE_ENVIRONMENT','DATAPHYRE_RUNTIME_REALTIME_BOOTSTRAP','SERVER_PROTOCOL','SERVER_ADDR','SERVER_NAME','SERVER_PORT','HTTP_HOST','REMOTE_ADDR'] as $name){
		unset($_SERVER[$name]);
	}
	exit(RegisteredTableMaterializationCommand::main(
		\is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []
	));
}
