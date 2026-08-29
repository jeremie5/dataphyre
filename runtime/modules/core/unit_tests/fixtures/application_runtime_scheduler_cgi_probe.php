<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Fresh scheduler-only CGI process boundary fixture. */

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
		$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity(getmypid());
		$signalMask=[];
		if(!pcntl_sigprocmask(SIG_BLOCK,[SIGCHLD],$signalMask)
			|| !pcntl_sigprocmask(SIG_SETMASK,$signalMask)){
			throw new RuntimeException('Scheduler CGI signal mask is unavailable.');
		}
		sort($signalMask,SORT_NUMERIC);
		$descendantPath=(string)($values['DATAPHYRE_RUNTIME_TEST_CGI_DESCENDANT_PID_PATH'] ?? '');
		if($descendantPath!==''){
			$descendant=pcntl_fork();
			if($descendant===-1){
				$limit=posix_getrlimit(POSIX_RLIMIT_NPROC);$probePipes=[];$process=null;
				set_error_handler(static fn(): bool=>true);
				try{$process=proc_open([PHP_BINARY,'-r','exit(0);'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$probePipes);}
				catch(Throwable){}finally{restore_error_handler();}
				$processDenied=!is_resource($process);
				if(is_resource($process)){@proc_terminate($process,9);@proc_close($process);}
				file_put_contents($descendantPath,json_encode([
					'fork_denied'=>true,'pid'=>getmypid(),'parent_pid'=>posix_getppid(),
					'process_group_id'=>posix_getpgid(0),'session_id'=>posix_getsid(0),
					'cap_inheritable'=>$identity['cap_inheritable'],'cap_permitted'=>$identity['cap_permitted'],
					'cap_eff'=>$identity['cap_eff'],'cap_bounding'=>$identity['cap_bounding'],'cap_ambient'=>$identity['cap_ambient'],
					'signal_mask'=>$signalMask,
					'rlimit_nproc_soft'=>$limit[0] ?? null,'rlimit_nproc_hard'=>$limit[1] ?? null,
					'proc_open_denied'=>$processDenied,
					'thread_creation_surface_available'=>class_exists('Thread',false) || class_exists('parallel\\Runtime',false),
				],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
			}
			if($descendant===0){
				pcntl_async_signals(true);pcntl_signal(SIGTERM,SIG_IGN);pcntl_signal(SIGINT,SIG_IGN);pcntl_signal(SIGHUP,SIG_IGN);
				$standardNames=['STDIN','STDOUT','STDERR'];
				foreach([[0,'r'],[1,'w'],[2,'w']] as $index=>[$descriptor,$mode]){
					$standard=defined($standardNames[$index]) ? constant($standardNames[$index]) : @fopen('php://fd/'.$descriptor,$mode);
					if(is_resource($standard)) @fclose($standard);
				}
				file_put_contents($descendantPath,json_encode([
					'pid'=>getmypid(),'parent_pid'=>posix_getppid(),'process_group_id'=>posix_getpgid(0),
					'session_id'=>posix_getsid(0),
				],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
				while(true) usleep(100000);
			}
			$descendantDeadline=microtime(true)+2.0;
			while(!is_file($descendantPath) && microtime(true)<$descendantDeadline) usleep(10000);
			if(!is_file($descendantPath)) throw new RuntimeException('Scheduler descendant fixture did not start.');
		}
		$blockMilliseconds=(int)($values['DATAPHYRE_RUNTIME_TEST_CGI_BLOCK_MILLISECONDS'] ?? 0);
		if($blockMilliseconds>0) usleep(min(30000,$blockMilliseconds)*1000);
		$outputBytes=(int)($values['DATAPHYRE_RUNTIME_TEST_CGI_OUTPUT_BYTES'] ?? 0);
	if($outputBytes>0){header('Content-Type: text/plain');echo str_repeat('x',$outputBytes);return;}
	$secret=$values['PROBE_SECRET'] ?? null;$expected=$_SERVER['HTTP_X_PROBE_SECRET_SHA256'] ?? null; // dataphyre-test-architecture: exempt[raw-superglobal] reason="CGI protocol proof must inspect the server-projected request header."
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
	$expectedManagedRole=(string)($_SERVER['SERVER_PORT'] ?? '')==='8081' ? 'scheduler' : 'web';
	$payload=[
		'ok'=>is_string($secret) && is_string($expected) && hash_equals($expected,hash('sha256',$secret)),
		'pid'=>getmypid(),'parent_pid'=>posix_getppid(),'process_group_id'=>posix_getpgid(0),'session_id'=>posix_getsid(0),
		'uid'=>$identity['uid'],'gid'=>$identity['gid'],
		'groups'=>$identity['groups'],'cap_inheritable'=>$identity['cap_inheritable'],
		'cap_permitted'=>$identity['cap_permitted'],'cap_eff'=>$identity['cap_eff'],
		'cap_bounding'=>$identity['cap_bounding'],'cap_ambient'=>$identity['cap_ambient'],
		'signal_mask'=>$signalMask,
		'no_new_privileges'=>$identity['no_new_privileges'],
		'broker_descriptor_closed'=>is_array($descriptors)
			&& !in_array((string)DataphyreApplicationRuntimeChildEnvironment::INHERITED_FD,$descriptors,true),
		'unexpected_descriptors'=>$unexpectedDescriptors,
		'secret_absent_from_proc'=>is_string($secret)
			&& !str_contains($environ,$secret) && !str_contains($cmdline,$secret),
		'managed_bootstrap'=>is_array($managed) && ($managed['role'] ?? null)===$expectedManagedRole
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
