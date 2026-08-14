<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once dirname(__DIR__,2).'/kernel/application_runtime_child_environment.php';
if(!function_exists('dataphyre_internal_managed_runtime_bootstrap_context')){
	function dataphyre_internal_managed_runtime_bootstrap_context(): ?array {
		return DataphyreApplicationRuntimeChildEnvironment::managedBootstrapAttestation();
	}
}
require_once dirname(__DIR__,2).'/kernel/helper_functions.php';
try{
	$values=is_array($GLOBALS['DATAPHYRE_INTERNAL_CGI_ENVIRONMENT'] ?? null) // dataphyre-test-architecture: exempt[raw-global-variable] reason="CGI fixture must observe the native post-exec secret handoff global."
		? $GLOBALS['DATAPHYRE_INTERNAL_CGI_ENVIRONMENT'] : []; // dataphyre-test-architecture: exempt[raw-global-variable] reason="CGI fixture must consume the native post-exec secret handoff global."
	unset($GLOBALS['DATAPHYRE_INTERNAL_CGI_ENVIRONMENT']); // dataphyre-test-architecture: exempt[raw-global-variable] reason="CGI fixture verifies the native secret handoff is single-use."
	$outputBytes=(int)($values['DATAPHYRE_RUNTIME_TEST_CGI_OUTPUT_BYTES'] ?? 0);
	if($outputBytes>0){header('Content-Type: text/plain');echo str_repeat('x',$outputBytes);return;}
	$secret=$values['PROBE_SECRET'] ?? null;$expected=$_SERVER['HTTP_X_PROBE_SECRET_SHA256'] ?? null; // dataphyre-test-architecture: exempt[raw-superglobal] reason="CGI protocol proof must inspect the server-projected request header."
	$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity(getmypid());
	$descriptors=@scandir('/proc/self/fd');
	$unexpectedDescriptors=[];
	foreach(is_array($descriptors) ? $descriptors : [] as $descriptor){
		if(!ctype_digit($descriptor) || (int)$descriptor<=2) continue;
		$target=@readlink('/proc/self/fd/'.$descriptor);
		if(!is_string($target)) continue;
		if($target==='/memfd:opcache_lock (deleted)' || $target===__FILE__
			|| $target===dirname(__DIR__,2).'/kernel/application_runtime_cgi_environment.php') continue;
		$unexpectedDescriptors[$descriptor]=$target;
	}
	$environ=(string)@file_get_contents('/proc/self/environ');
	$cmdline=(string)@file_get_contents('/proc/self/cmdline');
	$managed=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapAttestation();
	$managedPrivateKey=dpvk();$managedExpected=$_SERVER['HTTP_X_PROBE_MANAGED_KEY_SHA256'] ?? null; // dataphyre-test-architecture: exempt[raw-superglobal] reason="CGI protocol proof must inspect the managed-key request header."
	$managedProjected=false;
	foreach([getenv(),$_ENV,$_SERVER] as $environment){ // dataphyre-test-architecture: exempt[raw-superglobal] reason="Secret projection proof must enumerate both native CGI environment maps."
		foreach(is_array($environment) ? $environment : [] as $value){
			if(is_string($value) && hash_equals($managedPrivateKey,$value)){$managedProjected=true;break 2;}
		}
	}
	$writeProbe=sys_get_temp_dir().'/dataphyre-managed-bootstrap-'.getmypid(); // dataphyre-test-architecture: exempt[unmanaged-system-temporary-directory] reason="Capability-free CGI UID needs a writable native directory outside read-only source."
	if(!mkdir($writeProbe,0700) && !is_dir($writeProbe)) throw new RuntimeException('Managed bootstrap write probe root failed.');
	if(!defined('ROOTPATH')) define('ROOTPATH',['dataphyre'=>$writeProbe.'/']);
	dp_modcache_save_if_changed(['should_not_exist'=>false]);
	$configWrite=dp_write_module_config_defaults('should_not_exist',['enabled'=>true]);
	$legacyWritesSuppressed=$configWrite===false
		&& dp_source_local_runtime_writes_allowed()===false
		&& !file_exists($writeProbe.'/modcache.php')
		&& !file_exists($writeProbe.'/config/should_not_exist.php');
	@unlink($writeProbe.'/config/should_not_exist.php');@rmdir($writeProbe.'/config');@unlink($writeProbe.'/modcache.php');@rmdir($writeProbe);
	$payload=[
		'ok'=>is_string($secret) && is_string($expected) && hash_equals($expected,hash('sha256',$secret)),
		'pid'=>getmypid(),'parent_pid'=>posix_getppid(),'uid'=>$identity['uid'],'gid'=>$identity['gid'],
		'groups'=>$identity['groups'],'cap_eff'=>$identity['cap_eff'],
		'no_new_privileges'=>$identity['no_new_privileges'],
		'broker_descriptor_closed'=>is_array($descriptors)
			&& !in_array((string)DataphyreApplicationRuntimeChildEnvironment::INHERITED_FD,$descriptors,true),
		'unexpected_descriptors'=>$unexpectedDescriptors,
		'secret_absent_from_proc'=>is_string($secret)
			&& !str_contains($environ,$secret) && !str_contains($cmdline,$secret),
		'managed_bootstrap'=>is_array($managed) && ($managed['role'] ?? null)==='web'
			&& ($managed['sapi'] ?? null)==='cgi-fcgi',
		'managed_private_key_matches'=>is_string($managedExpected)
			&& hash_equals($managedExpected,hash('sha256',$managedPrivateKey)),
		'managed_private_key_absent_from_proc_and_environment'=>!$managedProjected
			&& !str_contains($environ,$managedPrivateKey) && !str_contains($cmdline,$managedPrivateKey),
		'legacy_source_writes_suppressed'=>$legacyWritesSuppressed,
		'pre_exec_closer_rejected'=>dataphyre_close_unlisted_inherited_fds()===false,
	];
	if(is_string($secret)) sodium_memzero($secret);sodium_memzero($managedPrivateKey);
	header('Content-Type: application/json');header('Cache-Control: no-store');
	echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(Throwable){http_response_code(500);echo '{"ok":false}';}
