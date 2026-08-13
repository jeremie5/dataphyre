<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$root=rtrim((string)($argv[1] ?? ''), '/\\');
$mode=(string)($argv[2] ?? '');
if($root==='' || !is_dir($root.'/runtime/modules') || $mode===''){
	fwrite(STDERR,"Dataphyre root and probe mode are required.\n");
	exit(1);
}

define('ROOTPATH',[
	'root'=>$root.'/',
	'common_root'=>$root.'/',
	'common_dataphyre'=>$root.'/',
	'common_dataphyre_runtime'=>$root.'/runtime/',
	'dataphyre'=>$root.'/',
]);
define('DATAPHYRE_MODULE_POLICY',[
	'enabled'=>['core'=>true,'dpanel'=>true,'flightdeck'=>true],
	'disabled'=>[],
	'core_implicit'=>true,
]);

require_once $root.'/runtime/modules/testing/tooling/bootstrap.php';
require_once $root.'/runtime/modules/core/kernel/autoloader.php';
if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; function tracelog(mixed ...$arguments): void {}');
}
\dataphyre\autoloader::register($root.'/runtime/modules');
\dataphyre\autoloader::register_framework_modules(['dpanel','flightdeck']);
require_once $root.'/runtime/modules/flightdeck/kernel/auth.php';

$surfaceFile=$root.'/runtime/modules/flightdeck/kernel/surfaces/dpanel.php';
if($mode==='dispatch'){
	$_SERVER['REQUEST_METHOD']='GET'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Standalone Dpanel fixture must model the native HTTP method boundary."
	$_POST=[]; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Standalone Dpanel fixture must clear the native form payload boundary."
	ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Standalone Dpanel fixture captures pages emitted by repeated real includes."
	include $surfaceFile;
	include $surfaceFile;
	$html=(string)ob_get_clean();
	echo json_encode([
		'pages'=>substr_count($html,'id="fd-dpanel-content"'),
		'repeated'=>substr_count($html,'fd-dpanel-content')>=2,
	],JSON_THROW_ON_ERROR)."\n";
	return;
}

define('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST',true);
require_once $surfaceFile;
$context=new \Dataphyre\Test\Context('Flightdeck Dpanel environment probe');
$surface=$context->nonPublic('dataphyre_flightdeck_dpanel_surface');

if($mode==='disabled-processes'){
	$unit=$surface->invoke('run_unit_test_worker','probe');
	$code=$surface->invoke('run_code_unit_test_worker','probe',__FILE__);
	echo json_encode([
		'proc_open_available'=>function_exists('proc_open'),
		'unit_passed'=>$unit['passed'] ?? null,
		'unit_message'=>$unit['trace'][0]['message'] ?? '',
		'code_passed'=>$code['passed'] ?? null,
		'code_message'=>$code['trace'][0]['message'] ?? '',
	],JSON_THROW_ON_ERROR)."\n";
	return;
}

if($mode==='disabled-memory-change'){
	$scope=$surface->invoke('run_scope','runtime',['core']);
	$batch=$surface->invoke('run_scan_batch',[
		'token'=>'low-memory-scan',
		'scope'=>'runtime',
		'queue'=>['core'],
		'cursor'=>0,
		'trace'=>[],
		'done'=>false,
		'batches'=>0,
		'test_queue'=>[],
		'test_cursor'=>0,
		'test_done'=>true,
		'manifest_queue'=>[],
		'manifest_cursor'=>0,
		'manifest_done'=>true,
	]);
	echo json_encode([
		'ini_set_available'=>function_exists('ini_set'),
		'memory_limit'=>(string)ini_get('memory_limit'),
		'processed'=>$scope['processed'] ?? null,
		'scope_trace'=>$scope['trace'] ?? [],
		'batch_done'=>$batch['done'] ?? null,
		'batch_trace'=>$batch['trace'] ?? [],
	],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
	return;
}

if($mode==='exhausted-process-descriptors'){
	if(!function_exists('posix_setrlimit') || !defined('POSIX_RLIMIT_NOFILE')){
		fwrite(STDERR,"POSIX descriptor limits are unavailable.\n");
		exit(2);
	}
	$stateDir=sys_get_temp_dir().'/dataphyre-dpanel-fd-'.getmypid(); // dataphyre-test-architecture: exempt[unmanaged-system-temporary-directory] reason="Descriptor exhaustion fixture must survive after intentionally reducing open-file capacity."
	if(!is_dir($stateDir) && !mkdir($stateDir,0775,true) && !is_dir($stateDir)){
		throw new RuntimeException('Unable to create descriptor probe state directory.');
	}
	putenv('DATAPHYRE_DPANEL_WORKER_STATE_DIR='.$stateDir);
	posix_setrlimit(POSIX_RLIMIT_NOFILE,64,64);
	$held=[];
	while(count($held)<128 && ($handle=@fopen('/dev/null','rb'))!==false){
		$held[]=$handle;
	}
	$released=array_pop($held);
	if(is_resource($released)){
		fclose($released);
	}
	$result=$surface->invoke(
		'run_unit_test_payload_worker','probe',__FILE__,[],
		'Descriptor probe worker','descriptor_probe_worker'
	);
	foreach($held as $handle){
		if(is_resource($handle)){
			fclose($handle);
		}
	}
	@array_map('unlink',glob($stateDir.'/*') ?: []);
	@rmdir($stateDir);
	echo json_encode([
		'passed'=>$result['passed'] ?? null,
		'message'=>$result['trace'][0]['message'] ?? '',
	],JSON_THROW_ON_ERROR)."\n";
	return;
}

throw new InvalidArgumentException('Unknown Dpanel environment probe mode.');
