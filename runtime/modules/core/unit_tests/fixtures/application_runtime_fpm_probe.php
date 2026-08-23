<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$routerOutputLevel=ob_get_level();
ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="FPM fixture captures the actual framework router/bootstrap response before emitting its lifecycle evidence."
require dirname(__DIR__,2).'/kernel/application_runtime_router.php';
$routerBody=(string)ob_get_clean();
if(ob_get_level()!==$routerOutputLevel) throw new RuntimeException('Framework router changed the fixture output-buffer boundary.');
header_remove();
$routerPayload=json_decode($routerBody,true);
$routerHealthy=is_array($routerPayload) && ($routerPayload['status'] ?? null)==='healthy';
$frameworkSessionActive=session_status()===PHP_SESSION_ACTIVE && session_id()!=='';
if(!class_exists(\dataphyre\sql::class,false)) require dirname(__DIR__,3).'/sql/kernel/sql.main.php';
if(!class_exists(\dataphyre\tracelog::class,false)) require dirname(__DIR__,3).'/tracelog/kernel/tracelog.main.php';
$databaseEndpoint='managed-fpm-database-endpoint';
$databaseAvailableBefore=\dataphyre\sql::is_server_available($databaseEndpoint);
$databaseAvailableAfter=$databaseAvailableBefore;
if((string)($_GET['action'] ?? '')==='mutate'){ // dataphyre-test-architecture: exempt[raw-superglobal] reason="FPM fixture receives the adversarial mutation selector through FastCGI."
	\dataphyre\sql::flag_server_unavailable($databaseEndpoint);
	$databaseAvailableAfter=\dataphyre\sql::is_server_available($databaseEndpoint);
}
$_SESSION=null; // dataphyre-test-architecture: exempt[raw-superglobal] reason="FPM fixture reproduces the production Tracelog shutdown boundary with a null process session."
\dataphyre\tracelog::reset(['roots'=>[],'retroactive'=>[],'cookies'=>[],'session_name'=>'managed-fpm','session_id'=>'','suppressed'=>false]);
\dataphyre\tracelog::$enable=true;
\dataphyre\tracelog::$defer=false;
\dataphyre\tracelog::$tracelog='managed-fpm-trace';
\dataphyre\tracelog::persist_to_session();
$tracelogPersisted=is_array($_SESSION) && ($_SESSION['tracelog'] ?? null)==='managed-fpm-trace'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="FPM fixture verifies Tracelog normalized the native process session."
$secret=(string)(getenv('PROBE_SECRET') ?: '');
$processEnvironment=(string)@file_get_contents('/proc/self/environ');
$processCommand=(string)@file_get_contents('/proc/self/cmdline');
$status=(string)@file_get_contents('/proc/self/status');
$handlerProbe=static fn(): bool=>false;
$previousHandler=set_error_handler($handlerProbe);restore_error_handler();
$errorHandlerFingerprint=match(true){
	$previousHandler===null=>'none',
	is_string($previousHandler)=>$previousHandler,
	is_array($previousHandler) && count($previousHandler)===2=>(is_object($previousHandler[0])
		? get_class($previousHandler[0]) : (string)$previousHandler[0]).'::'.(string)$previousHandler[1],
	is_object($previousHandler)=>get_class($previousHandler),
	default=>get_debug_type($previousHandler),
};

if(!defined('DATAPHYRE_MANAGED_FPM_PROBE_CLASSES_LOADED')){
	define('DATAPHYRE_MANAGED_FPM_PROBE_CLASSES_LOADED',true);
	class DataphyreManagedFpmProbeState { public static string $value='clean'; }
	class DataphyreManagedFpmLeakedHandler { public static function handle(): bool { return true; } }
}
$groups=[];
if(preg_match('/^Groups:\s*([^\r\n]*)$/m',$status,$match)===1){
	$groups=array_values(array_map('intval',preg_split('/\s+/',trim($match[1]),-1,PREG_SPLIT_NO_EMPTY) ?: []));
}
$currentUmask=umask();umask($currentUmask);
$result=[
	'ok'=>$routerHealthy,
	'framework_session_active'=>$frameworkSessionActive,
	'router_body'=>$routerPayload,
	'database_available_before'=>$databaseAvailableBefore,
	'database_available_after'=>$databaseAvailableAfter,
	'tracelog_persisted'=>$tracelogPersisted,
	'worker_pid'=>getmypid(),
	'parent_pid'=>posix_getppid(),
	'uid'=>posix_geteuid(),
	'gid'=>posix_getegid(),
	'groups'=>$groups,
	'cwd'=>getcwd(),
	'umask'=>$currentUmask,
	'locale'=>(string)setlocale(LC_ALL,0),
	'timezone'=>date_default_timezone_get(),
	'memory_limit'=>(string)ini_get('memory_limit'),
	'output_buffer_level'=>ob_get_level(),
	'error_handler_fingerprint'=>$errorHandlerFingerprint,
	'static_state'=>DataphyreManagedFpmProbeState::$value,
	'secret_sha256'=>hash('sha256',$secret),
	'secret_in_environment_superglobals'=>(($_ENV['PROBE_SECRET'] ?? null)===$secret) // dataphyre-test-architecture: exempt[raw-superglobal] reason="FPM fixture must inspect the native environment projection."
		&& (($_SERVER['PROBE_SECRET'] ?? null)===$secret), // dataphyre-test-architecture: exempt[raw-superglobal] reason="FPM fixture must inspect the native server projection."
	'secret_absent_from_process_metadata'=>$secret!==''
		&& !str_contains($processEnvironment,$secret) && !str_contains($processCommand,$secret),
	'leaked_environment_present'=>getenv('DATAPHYRE_REQUEST_LEAK')!==false,
	'leaked_environment_superglobals_present'=>array_key_exists('DATAPHYRE_REQUEST_LEAK',$_ENV) // dataphyre-test-architecture: exempt[raw-superglobal] reason="FPM fixture must detect an environment projection leaked by the preceding request."
		|| array_key_exists('DATAPHYRE_REQUEST_LEAK',$_SERVER), // dataphyre-test-architecture: exempt[raw-superglobal] reason="FPM fixture must detect a server projection leaked by the preceding request."
	'leaked_global_present'=>array_key_exists('DATAPHYRE_REQUEST_LEAK',$GLOBALS), // dataphyre-test-architecture: exempt[raw-global-variable] reason="FPM fixture must detect a global leaked by the preceding request."
	'broker_descriptor_closed'=>!file_exists('/proc/self/fd/198'),
	'context_refetch_rejected'=>dataphyre_managed_pool_request_context()===false,
	'managed_bootstrap'=>DataphyreApplicationRuntimeChildEnvironment::managedBootstrapAttestation(),
];
if((string)($_GET['action'] ?? '')==='mutate'){ // dataphyre-test-architecture: exempt[raw-superglobal] reason="FPM fixture receives the adversarial mutation selector through FastCGI."
	putenv('PROBE_SECRET=mutated');
	putenv('DATAPHYRE_REQUEST_LEAK=leaked');
	$_ENV['PROBE_SECRET']='mutated';$_SERVER['PROBE_SECRET']='mutated'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="FPM fixture deliberately corrupts both request environment projections."
	$_ENV['DATAPHYRE_REQUEST_LEAK']='leaked';$_SERVER['DATAPHYRE_REQUEST_LEAK']='leaked'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="FPM fixture deliberately introduces request-only projection names."
	$GLOBALS['DATAPHYRE_REQUEST_LEAK']='leaked'; // dataphyre-test-architecture: exempt[raw-global-variable] reason="FPM fixture deliberately introduces a request-only global."
	DataphyreManagedFpmProbeState::$value='leaked';
	setlocale(LC_ALL,(string)setlocale(LC_ALL,0)==='C' ? 'C.UTF-8' : 'C');
	date_default_timezone_set(date_default_timezone_get()==='America/Toronto' ? 'UTC' : 'America/Toronto');
	ini_set('memory_limit',(string)ini_get('memory_limit')==='-1' ? '128M' : '-1');
	set_error_handler([DataphyreManagedFpmLeakedHandler::class,'handle']);ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="FPM fixture deliberately leaves a request output buffer for lifecycle reset proof."
	chdir('/tmp');umask(0000);
}
$_SESSION=null; // dataphyre-test-architecture: exempt[raw-superglobal] reason="FPM fixture forces the framework shutdown callback through the production null-session regression boundary."
sodium_memzero($secret);
header('Content-Type: application/json');
echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
