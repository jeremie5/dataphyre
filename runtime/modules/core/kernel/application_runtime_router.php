<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$pool=match((string)($_SERVER['SERVER_PORT'] ?? '')){'8081'=>'scheduler','8083'=>'web',default=>''};
require_once __DIR__.'/application_runtime_child_environment.php';
try{
	$runtimeEnvironment=is_array($GLOBALS['DATAPHYRE_INTERNAL_CGI_ENVIRONMENT'] ?? null)
		? $GLOBALS['DATAPHYRE_INTERNAL_CGI_ENVIRONMENT']
		: DataphyreApplicationRuntimeChildEnvironment::consumeInherited($pool);
	unset($GLOBALS['DATAPHYRE_INTERNAL_CGI_ENVIRONMENT']);
}
catch(Throwable){http_response_code(404);echo '{"ok":false}';return;}
$projectRoot=(string)(getenv('DATAPHYRE_RUNTIME_PROJECT_ROOT') ?: '');
$realProjectRoot=$projectRoot;
$requestPath=rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'),PHP_URL_PATH) ?: '/'));

$isLoopback=in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''),['127.0.0.1','::1'],true);
if($pool==='scheduler'){
	require_once __DIR__.'/application_runtime_scheduler_protocol.php';
	if(!$isLoopback || ($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'
		|| !in_array($requestPath,[
			'/dataphyre/runtime/scheduler/register',
			'/dataphyre/runtime/scheduler/callback',
			'/dataphyre/runtime/scheduler/noop',
		],true)){
		http_response_code(404);return;
	}
	$raw=file_get_contents(
		'php://input',false,null,0,DataphyreApplicationRuntimeSchedulerProtocol::MAX_REQUEST_BYTES+1,
	);
	$candidate=json_decode((string)$raw,true);
	$publicKeyEncoded=(string)(getenv('DATAPHYRE_RUNTIME_SCHEDULER_PUBLIC_KEY') ?: '');
	try{$publicKey=sodium_base642bin($publicKeyEncoded,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,'');}
	catch(Throwable){$publicKey='';}
	$expectedKind=match($requestPath){
		'/dataphyre/runtime/scheduler/register'=>'registration',
		'/dataphyre/runtime/scheduler/callback'=>'callback',
		default=>'noop',
	};
	$valid=is_array($candidate)
		&& DataphyreApplicationRuntimeSchedulerProtocol::matchesCanonicalJson($candidate,$raw)
		&& ($candidate['kind'] ?? null)===$expectedKind
		&& DataphyreApplicationRuntimeSchedulerProtocol::verify($candidate,$publicKey)
		&& count($runtimeEnvironment)<=DataphyreApplicationRuntimeSchedulerProtocol::MAX_ENVIRONMENT_ENTRIES;
	if(!$valid){http_response_code(404);return;}
	if(!defined('DATAPHYRE_SCHEDULING_TASK_RUNNER_NO_DISPATCH')) define('DATAPHYRE_SCHEDULING_TASK_RUNNER_NO_DISPATCH',true);
	require_once dirname(__DIR__,2).'/scheduling/kernel/task_runner.php';
	ob_start(static fn(string $chunk): string=>'');
	if($expectedKind==='noop'){
		$ok=dataphyre_scheduling_task_runner::execute_managed_noop();
		@ob_end_clean();exit($ok ? 0 : 75);
	}
	if($expectedKind==='callback'){
		$ok=dataphyre_scheduling_task_runner::execute_managed_callback(
			(string)$candidate['scheduler_name'],
			(string)$candidate['definition_sha256'],
			(int)$candidate['budget_milliseconds'],
		);
		@ob_end_clean();exit($ok ? 0 : 75);
	}
	$report=dataphyre_scheduling_task_runner::execute_managed_registration();
	@ob_end_clean();header_remove();
	http_response_code(is_array($report) && ($report['ok'] ?? null)===true ? 200 : 409);
	header('Content-Type: application/json');header('Cache-Control: no-store');
	echo json_encode(is_array($report) ? $report : ['ok'=>false],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	exit(is_array($report) && ($report['ok'] ?? null)===true ? 0 : 75);
}

$publicRoot=$realProjectRoot.'/public';
if($pool==='web' && is_dir($publicRoot) && $requestPath!=='/'){
	$candidate=realpath($publicRoot.'/'.ltrim($requestPath,'/'));
	$prefix=rtrim($publicRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
	if(is_string($candidate) && strncmp($candidate,$prefix,strlen($prefix))===0
		&& is_file($candidate) && strtolower(pathinfo($candidate,PATHINFO_EXTENSION))!=='php'){
		$mime=function_exists('mime_content_type') ? mime_content_type($candidate) : false;
		if(is_string($mime) && $mime!=='') header('Content-Type: '.$mime);
		header('Content-Length: '.filesize($candidate));readfile($candidate);return;
	}
}

$_SERVER['DATAPHYRE_PROJECT_ROOT']=$realProjectRoot;
$_SERVER['HTTP_X_DATAPHYRE_APPLICATION']=(string)(getenv('DATAPHYRE_RUNTIME_APPLICATION') ?: '');
$_SERVER['HTTP_X_TRAFFIC_SOURCE']='internal_traffic';
require dirname(__DIR__,3).'/bootstrap.php';
