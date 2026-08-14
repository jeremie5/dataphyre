<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$kernel=realpath((string)($argv[1] ?? ''));
if(!is_string($kernel) || !is_file($kernel.'/application_runtime_activation_latch.php')) exit(64);
require_once $kernel.'/application_runtime_activation_latch.php';
$mode=(string)($argv[2] ?? 'ordinary');
if(!function_exists('posix_geteuid') || posix_geteuid()!==0){
	echo "{\"supported\":false}\n";
	exit(0);
}

$lock=@fopen('/tmp/dataphyre-runtime-control-tests.lock','c+b');
if(!is_resource($lock) || !@flock($lock,LOCK_EX)) exit(70);

$root='/var/lib/dataphyre';
$directory=$root.'/runtime-control';
$file=$directory.'/activation';
if(file_exists($root) || is_link($root)){
	echo "{\"supported\":false,\"preexisting\":true}\n";
	exit(0);
}
$cleanup=static function() use($root,$directory,$file): bool {
	foreach(glob($directory.'/.activation.*.tmp') ?: [] as $temporary) @unlink($temporary);
	if(is_file($file.'.hardlink') || is_link($file.'.hardlink')) @unlink($file.'.hardlink');
	if(is_file($file) || is_link($file)) @unlink($file);
	if(is_dir($directory) && !is_link($directory)){
		if(function_exists('chmod')) @chmod($directory,0700);
		@rmdir($directory);
	}elseif(is_link($directory)) @unlink($directory);
	if(is_dir($root) && !is_link($root)){
		if(function_exists('chmod')) @chmod($root,0755);
		@rmdir($root);
	}elseif(is_link($root)) @unlink($root);
	return !file_exists($root) && !is_link($root);
};

if($mode!=='ordinary'){
	$failure=null;
	try{
		if($mode!=='mkdir-unavailable'){
			$previousUmask=umask(0);
			@mkdir($root,0755);@mkdir($directory,0700);
			umask($previousUmask);
		}
		if($mode==='mkdir-unavailable') DataphyreApplicationRuntimeActivationLatch::restore();
		else DataphyreApplicationRuntimeActivationLatch::persist(true);
	}catch(Throwable $caught){$failure=$caught;}
	$temporaryFiles=glob($directory.'/.activation.*.tmp') ?: [];
	$cleaned=$cleanup();
	echo json_encode([
		'supported'=>true,'mode'=>$mode,
		'failure_class'=>is_object($failure) ? $failure::class : null,
		'failure_message'=>$failure?->getMessage(),
		'temporary_count'=>count($temporaryFiles),'cleaned'=>$cleaned,
	],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
	exit(0);
}

$invalidContentsRejected=false;
$linkRejectedOnRestore=false;
$linkRejectedOnPersist=false;
$hardlinkRejected=false;
$directoryModeRejected=false;
$initial=null;$active=null;$inactive=null;$stat=null;
try{
	$initial=DataphyreApplicationRuntimeActivationLatch::restore();
	DataphyreApplicationRuntimeActivationLatch::persist(true);
	$active=DataphyreApplicationRuntimeActivationLatch::restore();
	DataphyreApplicationRuntimeActivationLatch::persist(false);
	$inactive=DataphyreApplicationRuntimeActivationLatch::restore();
	file_put_contents($file,"invalid\n",LOCK_EX);
	try{DataphyreApplicationRuntimeActivationLatch::restore();}
	catch(RuntimeException){$invalidContentsRejected=true;}
	@unlink($file);
	symlink('/dev/null',$file);
	try{DataphyreApplicationRuntimeActivationLatch::restore();}
	catch(RuntimeException){$linkRejectedOnRestore=true;}
	try{DataphyreApplicationRuntimeActivationLatch::persist(true);}
	catch(RuntimeException){$linkRejectedOnPersist=true;}
	@unlink($file);
	DataphyreApplicationRuntimeActivationLatch::persist(false);
	$stat=lstat($file);
	link($file,$file.'.hardlink');
	try{DataphyreApplicationRuntimeActivationLatch::restore();}
	catch(RuntimeException){$hardlinkRejected=true;}
	@unlink($file.'.hardlink');@unlink($file);
	chmod($directory,0755);
	try{DataphyreApplicationRuntimeActivationLatch::restore();}
	catch(RuntimeException){$directoryModeRejected=true;}
}finally{
	$cleaned=$cleanup();
}

echo json_encode([
	'supported'=>true,'initial'=>$initial,'active'=>$active,'inactive'=>$inactive,
	'invalid_contents_rejected'=>$invalidContentsRejected,
	'link_rejected_on_restore'=>$linkRejectedOnRestore,'link_rejected_on_persist'=>$linkRejectedOnPersist,
	'hardlink_rejected'=>$hardlinkRejected,'directory_mode_rejected'=>$directoryModeRejected,
	'file_mode'=>is_array($stat) ? (($stat['mode'] ?? 0)&0777) : null,
	'file_uid'=>is_array($stat) ? ($stat['uid'] ?? null) : null,
	'file_gid'=>is_array($stat) ? ($stat['gid'] ?? null) : null,
	'cleaned'=>$cleaned,
],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
