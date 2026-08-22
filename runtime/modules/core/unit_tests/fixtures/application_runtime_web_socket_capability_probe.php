<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(count($argv)!==2 || !is_dir($argv[1])) throw new RuntimeException('Web socket capability probe arguments are invalid.');
require_once $argv[1].'/application_runtime_supervisor.php';

/** @return array<string,string|bool|int> */
function dataphyre_web_socket_capability_identity(): array
{
	$fields=[];
	foreach((array)file('/proc/self/status',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
		if(!is_string($line) || !str_contains($line,':')) continue;
		[$name,$value]=explode(':',$line,2);$fields[$name]=trim($value);
	}
	return [
		'uid'=>posix_geteuid(),'gid'=>posix_getegid(),
		'cap_inheritable'=>$fields['CapInh'] ?? '',
		'cap_permitted'=>$fields['CapPrm'] ?? '',
		'cap_effective'=>$fields['CapEff'] ?? '',
		'cap_bounding'=>$fields['CapBnd'] ?? '',
		'cap_ambient'=>$fields['CapAmb'] ?? '',
		'no_new_privileges'=>($fields['NoNewPrivs'] ?? '')==='1',
	];
}

$identity=dataphyre_web_socket_capability_identity();
if($identity!==[
	'uid'=>0,'gid'=>0,
	'cap_inheritable'=>'0000000000000000',
	'cap_permitted'=>'00000000000000e0',
	'cap_effective'=>'00000000000000e0',
	'cap_bounding'=>'00000000000000e0',
	'cap_ambient'=>'0000000000000000',
	'no_new_privileges'=>true,
]) throw new RuntimeException('Web socket capability probe identity is invalid.');

$parent='/run/dataphyre';$directory=$parent.'/web';$socket=$directory.'/php-fpm.sock';
$parentCreated=false;$parentMode=null;$preparation=null;$socketIdentity=null;$child=null;$channel=null;
try{
	if(is_link($parent)) throw new RuntimeException('Web socket capability probe parent is invalid.');
	if(!is_dir($parent)){
		if(!mkdir($parent,0700)) throw new RuntimeException('Web socket capability probe parent could not be created.');
		$parentCreated=true;
	}else{
		$parentStat=lstat($parent);$parentMode=is_array($parentStat) ? (($parentStat['mode'] ?? 0)&0777) : null;
		if(!is_int($parentMode) || !chmod($parent,0700)) throw new RuntimeException('Web socket capability probe parent could not be closed.');
	}
	if(file_exists($directory) || is_link($directory)) throw new RuntimeException('Web socket capability probe directory is already in use.');
	$preparation=dataphyre_runtime_prepare_web_socket();$prepared=lstat($directory);
	if(!is_array($prepared) || (($prepared['mode'] ?? 0)&0777)!==0730
		|| ($prepared['uid'] ?? -1)!==0 || ($prepared['gid'] ?? -1)!==10001 || posix_getegid()!==0){
		throw new RuntimeException('Web socket capability probe preparation is invalid.');
	}
	$channels=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	if(!is_array($channels) || count($channels)!==2) throw new RuntimeException('Web socket capability probe channel is unavailable.');
	$child=pcntl_fork();
	if($child===-1) throw new RuntimeException('Web socket capability probe child could not fork.');
	if($child===0){
		fclose($channels[0]);$childChannel=$channels[1];
		if(!posix_setgid(10001) || !posix_setuid(10001)) exit(71);
		$listener=@stream_socket_server('unix://'.$socket,$errorNumber,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
		if(!is_resource($listener) || !chmod($socket,0600)) exit(72);
		$bound=lstat($socket);
		fwrite($childChannel,json_encode([
			'uid'=>$bound['uid'] ?? -1,'gid'=>$bound['gid'] ?? -1,'mode'=>(($bound['mode'] ?? 0)&0777),
		],JSON_THROW_ON_ERROR)."\n");fflush($childChannel);
		if(trim((string)fgets($childChannel))!=='lock') exit(73);
		$replacement=$directory.'/tenant-replacement.sock';
		$replacementListener=@stream_socket_server('unix://'.$replacement,$replacementError,$replacementMessage,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
		fwrite($childChannel,json_encode([
			'unlink'=>@unlink($socket),'rename'=>@rename($socket,$replacement),
			'replacement_bound'=>is_resource($replacementListener),
		],JSON_THROW_ON_ERROR)."\n");fflush($childChannel);
		if(is_resource($replacementListener)) fclose($replacementListener);
		if(trim((string)fgets($childChannel))!=='stop') exit(74);
		fclose($listener);fclose($childChannel);exit(0);
	}
	fclose($channels[1]);$channel=$channels[0];stream_set_timeout($channel,5,0);
	$bound=json_decode(trim((string)fgets($channel)),true,8,JSON_THROW_ON_ERROR);
	$socketStat=lstat($socket);
	if(!is_array($socketStat) || !is_array($bound)
		|| $bound!==['uid'=>10001,'gid'=>10001,'mode'=>0600]){
		throw new RuntimeException('Web socket capability probe child binding is invalid.');
	}
	$socketIdentity=['dev'=>$socketStat['dev'],'ino'=>$socketStat['ino']];
	if(!dataphyre_runtime_lock_web_socket($socketIdentity,$preparation['directory'])){
		throw new RuntimeException('Web socket capability probe lock failed.');
	}
	fwrite($channel,"lock\n");fflush($channel);
	$mutation=json_decode(trim((string)fgets($channel)),true,8,JSON_THROW_ON_ERROR);
	if($mutation!==['unlink'=>false,'rename'=>false,'replacement_bound'=>false]){
		throw new RuntimeException('Web socket capability probe write revocation failed.');
	}
	$locked=lstat($directory);
	if(!is_array($locked) || (($locked['mode'] ?? 0)&0777)!==0711
		|| ($locked['uid'] ?? -1)!==0 || ($locked['gid'] ?? -1)!==0 || posix_getegid()!==0){
		throw new RuntimeException('Web socket capability probe locked state is invalid.');
	}
	fwrite($channel,"stop\n");fflush($channel);fclose($channel);$channel=null;
	pcntl_waitpid($child,$childStatus);$child=null;
	if(!pcntl_wifexited($childStatus) || pcntl_wexitstatus($childStatus)!==0){
		throw new RuntimeException('Web socket capability probe child failed.');
	}
	dataphyre_runtime_cleanup_web_socket($socketIdentity,$preparation['directory'],$preparation['parent']);
	$preparation=null;$socketIdentity=null;
	if(file_exists($directory) || is_link($directory) || ((lstat($parent)['mode'] ?? 0)&0777)!==0700){
		throw new RuntimeException('Web socket capability probe cleanup failed.');
	}
	if(!mkdir($directory,0711) || !chgrp($directory,0) || !chmod($directory,0711)){
		throw new RuntimeException('Web socket capability probe restart fixture could not be prepared.');
	}
	$restart=dataphyre_runtime_prepare_web_socket();$restartStat=lstat($directory);
	if(!is_array($restartStat) || (($restartStat['mode'] ?? 0)&0777)!==0730
		|| ($restartStat['uid'] ?? -1)!==0 || ($restartStat['gid'] ?? -1)!==10001 || posix_getegid()!==0){
		throw new RuntimeException('Web socket capability probe restart failed.');
	}
	dataphyre_runtime_cleanup_web_socket(null,$restart['directory'],$restart['parent']);
	if(file_exists($directory) || is_link($directory)) throw new RuntimeException('Web socket capability probe restart cleanup failed.');
	echo json_encode([
		'contract'=>'dataphyre.web_socket_capability_probe.v1','ok'=>true,
		'capabilities'=>$identity,'prepared'=>['uid'=>0,'gid'=>10001,'mode'=>0730],
		'socket'=>['uid'=>10001,'gid'=>10001,'mode'=>0600],
		'locked'=>['uid'=>0,'gid'=>0,'mode'=>0711],
		'tenant_mutation'=>$mutation,'cleanup'=>true,'restart'=>true,'effective_gid_restored'=>true,
	],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}finally{
	if(is_resource($channel)){@fwrite($channel,"stop\n");@fclose($channel);}
	if(is_int($child) && $child>0){@posix_kill($child,SIGKILL);@pcntl_waitpid($child,$status);}
	if(is_array($preparation)) dataphyre_runtime_cleanup_web_socket($socketIdentity,$preparation['directory'],$preparation['parent']);
	if(file_exists($directory) && is_dir($directory) && !is_link($directory)) @rmdir($directory);
	if($parentCreated){@chmod($parent,0700);@rmdir($parent);}
	elseif(is_int($parentMode)) @chmod($parent,$parentMode);
}
