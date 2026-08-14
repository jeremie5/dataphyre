<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$kernel=realpath((string)($argv[1] ?? ''));
if(!is_string($kernel) || !is_file($kernel.'/application_runtime_probe_state.php')) exit(64);
require_once $kernel.'/application_runtime_probe_state.php';
$mode=(string)($argv[2] ?? 'ordinary');
if(!function_exists('posix_geteuid') || posix_geteuid()!==0){
	echo "{\"supported\":false}\n";
	exit(0);
}

$lock=@fopen('/tmp/dataphyre-runtime-control-tests.lock','c+b');
if(!is_resource($lock) || !@flock($lock,LOCK_EX)) exit(70);
$root='/var/lib/dataphyre';
$directory=$root.'/runtime-control';
$file=$directory.'/scheduler-probe.json';
$hardlink=$file.'.hardlink';
if(file_exists($root) || is_link($root)){
	echo "{\"supported\":false,\"preexisting\":true}\n";
	exit(0);
}
$cleanup=static function() use($root,$directory,$file,$hardlink): bool {
	foreach(glob($directory.'/.scheduler-probe.*.tmp') ?: [] as $temporary) @unlink($temporary);
	if(is_file($hardlink) || is_link($hardlink)) @unlink($hardlink);
	if(is_file($file) || is_link($file)) @unlink($file);
	if(is_dir($directory) && !is_link($directory)){
		@chmod($directory,0700);@rmdir($directory);
	}elseif(is_link($directory)) @unlink($directory);
	if(is_dir($root) && !is_link($root)){
		@chmod($root,0755);@rmdir($root);
	}elseif(is_link($root)) @unlink($root);
	return !file_exists($root) && !is_link($root);
};
$identity=[
	'cloud_application'=>'serve_shop','framework_application'=>'Serve','environment'=>'Staging.Blue',
	'release_id'=>'dep_'.str_repeat('a',40),
	'environment_fingerprint'=>'hmac-sha256:'.str_repeat('b',64),
];

if($mode==='sync-unavailable'){
	$previousUmask=umask(0);
	@mkdir($root,0755);@mkdir($directory,0700);
	umask($previousUmask);
	$failure=null;
	try{DataphyreApplicationRuntimeProbeState::record($identity,1776073500);}
	catch(Throwable $caught){$failure=$caught;}
	$temporaryFiles=glob($directory.'/.scheduler-probe.*.tmp') ?: [];
	$cleaned=$cleanup();
	echo json_encode([
		'supported'=>true,'failure_class'=>is_object($failure) ? $failure::class : null,
		'failure_message'=>$failure?->getMessage(),'temporary_count'=>count($temporaryFiles),'cleaned'=>$cleaned,
	],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
	exit(0);
}

$identityChangeRejected=false;
$wrongModeRejected=false;
$hardlinkRejected=false;
$invalidJsonRejected=false;
$invalidContractRejected=false;
$noncanonicalRejected=false;
$linkRejected=false;
$directoryModeRejected=false;
$first=null;$second=null;$stat=null;
try{
	$first=DataphyreApplicationRuntimeProbeState::record($identity,1776073500);
	$second=DataphyreApplicationRuntimeProbeState::record($identity,1776073501);
	$stat=lstat($file);
	$validState=json_decode((string)file_get_contents($file),true,8,JSON_THROW_ON_ERROR);
	$changed=$identity;$changed['environment']='production';
	try{DataphyreApplicationRuntimeProbeState::record($changed,1776073502);}
	catch(RuntimeException){$identityChangeRejected=true;}
	chmod($file,0644);
	try{DataphyreApplicationRuntimeProbeState::record($identity,1776073502);}
	catch(RuntimeException){$wrongModeRejected=true;}
	chmod($file,0600);link($file,$hardlink);
	try{DataphyreApplicationRuntimeProbeState::record($identity,1776073502);}
	catch(RuntimeException){$hardlinkRejected=true;}
	@unlink($hardlink);
	file_put_contents($file,"{\n",LOCK_EX);chmod($file,0600);
	try{DataphyreApplicationRuntimeProbeState::record($identity,1776073502);}
	catch(RuntimeException){$invalidJsonRejected=true;}
	$invalidContract=$validState;$invalidContract['contract']='invalid';
	file_put_contents($file,json_encode($invalidContract,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX);
	chmod($file,0600);
	try{DataphyreApplicationRuntimeProbeState::record($identity,1776073502);}
	catch(RuntimeException){$invalidContractRejected=true;}
	file_put_contents($file,json_encode($validState,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX);
	chmod($file,0600);
	try{DataphyreApplicationRuntimeProbeState::record($identity,1776073502);}
	catch(RuntimeException){$noncanonicalRejected=true;}
	@unlink($file);symlink('/dev/null',$file);
	try{DataphyreApplicationRuntimeProbeState::record($identity,1776073502);}
	catch(RuntimeException){$linkRejected=true;}
	@unlink($file);chmod($directory,0755);
	try{DataphyreApplicationRuntimeProbeState::record($identity,1776073502);}
	catch(RuntimeException){$directoryModeRejected=true;}
}finally{$cleaned=$cleanup();}

echo json_encode([
	'supported'=>true,'first'=>$first,'second'=>$second,
	'identity_change_rejected'=>$identityChangeRejected,'wrong_mode_rejected'=>$wrongModeRejected,
	'hardlink_rejected'=>$hardlinkRejected,'invalid_json_rejected'=>$invalidJsonRejected,
	'invalid_contract_rejected'=>$invalidContractRejected,'noncanonical_rejected'=>$noncanonicalRejected,
	'link_rejected'=>$linkRejected,'directory_mode_rejected'=>$directoryModeRejected,
	'file_mode'=>is_array($stat) ? (($stat['mode'] ?? 0)&0777) : null,
	'file_uid'=>is_array($stat) ? ($stat['uid'] ?? null) : null,
	'file_gid'=>is_array($stat) ? ($stat['gid'] ?? null) : null,
	'cleaned'=>$cleaned,
],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
