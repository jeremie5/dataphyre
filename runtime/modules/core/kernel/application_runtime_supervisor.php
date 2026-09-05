<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Dataphyre-owned PID 1 for fixed web, scheduler, and realtime pools. */

require_once __DIR__.'/application_runtime_scheduler_protocol.php';
require_once __DIR__.'/application_runtime_scheduler_state.php';
require_once __DIR__.'/application_runtime_probe_state.php';
require_once __DIR__.'/application_runtime_activation_latch.php';
require_once __DIR__.'/application_runtime_environment.php';
require_once __DIR__.'/application_runtime_process_broker.php';
require_once __DIR__.'/application_runtime_scheduler_gateway.php';

final class DataphyreManagedWebInventoryUnavailable extends RuntimeException {}
final class DataphyreManagedRuntimeGenerationUnavailable extends RuntimeException {}
final class DataphyreManagedRuntimeControlPeerFailure extends RuntimeException {}
final class DataphyreManagedRuntimeGracefulShutdown extends RuntimeException {}

function dataphyre_runtime_env(string $name, ?string $default=null): string
{
    $value=getenv($name);
    if ($value===false || trim($value)==='') {
        if ($default!==null) return $default;
        throw new RuntimeException("Missing required environment variable {$name}");
    }
    return trim($value);
}

function dataphyre_runtime_integer(string $name, int $default, int $minimum, int $maximum): int
{
    $raw=dataphyre_runtime_env($name, (string)$default);
    if (preg_match('/^[0-9]+$/D', $raw)!==1) throw new RuntimeException("{$name} must be an integer");
    $value=(int)$raw;
    if ($value<$minimum || $value>$maximum) throw new RuntimeException("{$name} is outside its fixed bounds");
    return $value;
}

function dataphyre_runtime_require_managed_web_runtime(): void
{
	$fpm='/usr/local/sbin/php-fpm';$config=__DIR__.'/application_runtime_php_fpm.conf';
	foreach([$fpm,$config] as $path){
		$stat=@lstat($path);$resolved=@realpath($path);
		if(is_link($path) || !is_array($stat) || (($stat['mode'] ?? 0)&0170000)!==0100000
			|| !is_string($resolved) || !hash_equals($path,$resolved)){
			throw new RuntimeException('Managed PHP web runtime source is invalid.');
		}
	}
	if(!is_executable($fpm) || !is_executable('/usr/bin/prlimit') || !extension_loaded('dataphyre_environment_fd')
		|| phpversion('dataphyre_environment_fd')!=='1.2.0'
		|| !function_exists('dataphyre_managed_pool_request_context')
		|| !function_exists('dataphyre_enable_scheduler_child_subreaper')){
		throw new RuntimeException('Managed PHP web runtime is unavailable.');
	}
}

/** Assigns the fixed FPM group without requiring CAP_CHOWN. */
function dataphyre_runtime_assign_web_socket_group(string $directory): bool
{
	if(posix_getegid()!==0 || !posix_setegid(10001)) return false;
	try{return @chgrp($directory,10001);}
	finally{
		if(!posix_setegid(0) || posix_getegid()!==0){
			throw new RuntimeException('Managed web runtime effective group could not be restored.');
		}
	}
}

/** @return array{directory:array{dev:int,ino:int},parent:array{dev:int,ino:int}} */
function dataphyre_runtime_prepare_web_socket(): array
{
	$parent='/run/dataphyre';$directory='/run/dataphyre/web';$socket=$directory.'/php-fpm.sock';
	$parentStat=@lstat($parent);$parentReal=@realpath($parent);
	if(is_link($parent) || !is_array($parentStat) || (($parentStat['mode'] ?? 0)&0170000)!==0040000
		|| !in_array(($parentStat['mode'] ?? 0)&0777,[0700,0711],true)
		|| ($parentStat['uid'] ?? -1)!==0 || ($parentStat['gid'] ?? -1)!==0
		|| !is_string($parentReal) || !hash_equals($parent,$parentReal)){
		throw new RuntimeException('Managed web runtime parent is invalid.');
	}
	if(!is_int($parentStat['dev'] ?? null) || !is_int($parentStat['ino'] ?? null)){
		throw new RuntimeException('Managed web runtime parent identity is unavailable.');
	}
	$parentIdentity=['dev'=>$parentStat['dev'],'ino'=>$parentStat['ino']];$directoryIdentity=null;
	try{
		if(file_exists($directory) || is_link($directory)){
			$stat=@lstat($directory);$resolved=@realpath($directory);
			$permissions=is_array($stat) ? (($stat['mode'] ?? 0)&0777) : -1;
			$recoverable=is_array($stat) && ($stat['uid'] ?? -1)===0 && (
				(($stat['gid'] ?? -1)===0 && (($permissions&~0700)===0))
				|| (($stat['gid'] ?? -1)===10001 && (($permissions&~0730)===0))
			);
			$locked=is_array($stat) && (($stat['mode'] ?? 0)&0777)===0711
				&& ($stat['uid'] ?? -1)===0 && ($stat['gid'] ?? -1)===0;
			if(is_link($directory) || !is_array($stat) || (($stat['mode'] ?? 0)&0170000)!==0040000
				|| (!$recoverable && !$locked) || !is_int($stat['dev'] ?? null) || !is_int($stat['ino'] ?? null)
				|| !is_string($resolved) || !hash_equals($directory,$resolved)){
				throw new RuntimeException('Managed web runtime directory is invalid.');
			}
			$directoryIdentity=['dev'=>$stat['dev'],'ino'=>$stat['ino']];
			if(!@chmod($directory,0700)){
				throw new RuntimeException('Managed web runtime directory could not be unlocked.');
			}
		}else{
			if(!@mkdir($directory,0700)) throw new RuntimeException('Managed web runtime directory could not be created.');
			$stat=@lstat($directory);
			if(is_link($directory) || !is_array($stat) || (($stat['mode'] ?? 0)&0170000)!==0040000
				|| ((($stat['mode'] ?? 0)&0777)&~0700)!==0 || ($stat['uid'] ?? -1)!==0 || ($stat['gid'] ?? -1)!==0
				|| !is_int($stat['dev'] ?? null) || !is_int($stat['ino'] ?? null)){
				throw new RuntimeException('Managed web runtime directory identity is unavailable.');
			}
			$directoryIdentity=['dev'=>$stat['dev'],'ino'=>$stat['ino']];
			if(!@chmod($directory,0700)){
				throw new RuntimeException('Managed web runtime directory permissions could not be established.');
			}
		}
		if(file_exists($socket) || is_link($socket)){
			$stat=@lstat($socket);
			if(is_link($socket) || !is_array($stat) || (($stat['mode'] ?? 0)&0170000)!==0140000
				|| (($stat['mode'] ?? 0)&0777)!==0600 || ($stat['uid'] ?? -1)!==10001 || ($stat['gid'] ?? -1)!==10001
				|| !@unlink($socket)){
				throw new RuntimeException('Managed web runtime stale socket is invalid.');
			}
		}
		// PID 1 remains the owner. The fixed group gets write access only until
		// the capability-free FPM master has bound its private socket.
		if(!dataphyre_runtime_assign_web_socket_group($directory) || !@chmod($directory,0730)){
			throw new RuntimeException('Managed web runtime directory could not be prepared.');
		}
		if(!@chmod($parent,0711)) throw new RuntimeException('Managed web runtime parent could not be opened.');
		$prepared=@lstat($directory);$openedParent=@lstat($parent);
		if(is_link($directory) || !is_array($prepared) || (($prepared['mode'] ?? 0)&0170000)!==0040000
			|| (($prepared['mode'] ?? 0)&0777)!==0730 || ($prepared['uid'] ?? -1)!==0 || ($prepared['gid'] ?? -1)!==10001
			|| ($prepared['dev'] ?? null)!==$directoryIdentity['dev'] || ($prepared['ino'] ?? null)!==$directoryIdentity['ino']
			|| is_link($parent) || !is_array($openedParent) || (($openedParent['mode'] ?? 0)&0170000)!==0040000
			|| (($openedParent['mode'] ?? 0)&0777)!==0711 || ($openedParent['uid'] ?? -1)!==0 || ($openedParent['gid'] ?? -1)!==0
			|| ($openedParent['dev'] ?? null)!==$parentIdentity['dev'] || ($openedParent['ino'] ?? null)!==$parentIdentity['ino']){
			throw new RuntimeException('Managed web runtime prepared identity changed.');
		}
		return ['directory'=>$directoryIdentity,'parent'=>$parentIdentity];
	}catch(Throwable $failure){
		dataphyre_runtime_cleanup_web_socket(null,$directoryIdentity,$parentIdentity);
		throw $failure;
	}
}

/** @param null|array{dev:int,ino:int} $expectedSocketIdentity @param null|array{dev:int,ino:int} $expectedDirectoryIdentity */
function dataphyre_runtime_web_socket_valid(?array $expectedSocketIdentity=null,?array $expectedDirectoryIdentity=null): bool
{
	$socket=@lstat('/run/dataphyre/web/php-fpm.sock');$directory=@lstat('/run/dataphyre/web');
	return !is_link('/run/dataphyre/web/php-fpm.sock') && is_array($socket)
		&& (($socket['mode'] ?? 0)&0170000)===0140000 && (($socket['mode'] ?? 0)&0777)===0600
		&& ($socket['uid'] ?? -1)===10001 && ($socket['gid'] ?? -1)===10001
		&& !is_link('/run/dataphyre/web') && is_array($directory)
		&& (($directory['mode'] ?? 0)&0170000)===0040000 && (($directory['mode'] ?? 0)&0777)===0711
		&& ($directory['uid'] ?? -1)===0 && ($directory['gid'] ?? -1)===0
		&& ($expectedSocketIdentity===null || (
			array_keys($expectedSocketIdentity)===['dev','ino']
			&& is_int($expectedSocketIdentity['dev']) && is_int($expectedSocketIdentity['ino'])
			&& ($socket['dev'] ?? null)===$expectedSocketIdentity['dev'] && ($socket['ino'] ?? null)===$expectedSocketIdentity['ino']
		))
		&& ($expectedDirectoryIdentity===null || (
			array_keys($expectedDirectoryIdentity)===['dev','ino']
			&& is_int($expectedDirectoryIdentity['dev']) && is_int($expectedDirectoryIdentity['ino'])
			&& ($directory['dev'] ?? null)===$expectedDirectoryIdentity['dev'] && ($directory['ino'] ?? null)===$expectedDirectoryIdentity['ino']
		));
}

/** @param array{dev:int,ino:int} $socketIdentity @param array{dev:int,ino:int} $directoryIdentity */
function dataphyre_runtime_lock_web_socket(array $socketIdentity,array $directoryIdentity): bool
{
	if(array_keys($socketIdentity)!==['dev','ino'] || array_keys($directoryIdentity)!==['dev','ino']
		|| !is_int($socketIdentity['dev']) || !is_int($socketIdentity['ino'])
		|| !is_int($directoryIdentity['dev']) || !is_int($directoryIdentity['ino'])) return false;
	$socket='/run/dataphyre/web/php-fpm.sock';$directory='/run/dataphyre/web';
	$beforeSocket=@lstat($socket);$beforeDirectory=@lstat($directory);
	if(is_link($socket) || !is_array($beforeSocket) || (($beforeSocket['mode'] ?? 0)&0170000)!==0140000
		|| (($beforeSocket['mode'] ?? 0)&0777)!==0600 || ($beforeSocket['uid'] ?? -1)!==10001 || ($beforeSocket['gid'] ?? -1)!==10001
		|| ($beforeSocket['dev'] ?? null)!==$socketIdentity['dev'] || ($beforeSocket['ino'] ?? null)!==$socketIdentity['ino']
		|| is_link($directory) || !is_array($beforeDirectory) || (($beforeDirectory['mode'] ?? 0)&0170000)!==0040000
		|| (($beforeDirectory['mode'] ?? 0)&0777)!==0730 || ($beforeDirectory['uid'] ?? -1)!==0 || ($beforeDirectory['gid'] ?? -1)!==10001
		|| ($beforeDirectory['dev'] ?? null)!==$directoryIdentity['dev'] || ($beforeDirectory['ino'] ?? null)!==$directoryIdentity['ino']
		|| !@chmod($directory,0700)) return false;
	$revokedSocket=@lstat($socket);$revokedDirectory=@lstat($directory);
	if(is_link($socket) || !is_array($revokedSocket)
		|| ($revokedSocket['dev'] ?? null)!==$socketIdentity['dev'] || ($revokedSocket['ino'] ?? null)!==$socketIdentity['ino']
		|| is_link($directory) || !is_array($revokedDirectory) || (($revokedDirectory['mode'] ?? 0)&0777)!==0700
		|| ($revokedDirectory['uid'] ?? -1)!==0 || ($revokedDirectory['gid'] ?? -1)!==10001
		|| ($revokedDirectory['dev'] ?? null)!==$directoryIdentity['dev'] || ($revokedDirectory['ino'] ?? null)!==$directoryIdentity['ino']
		|| !@chgrp($directory,0) || !@chmod($directory,0711)) return false;
	return dataphyre_runtime_web_socket_valid($socketIdentity,$directoryIdentity);
}

/** @param null|array{dev:int,ino:int} $socketIdentity @param null|array{dev:int,ino:int} $directoryIdentity */
function dataphyre_runtime_web_pool_healthy(
	int $masterPid,bool $startup=false,?array $socketIdentity=null,?array $directoryIdentity=null,
): bool
{
	try{
		if(!dataphyre_runtime_web_socket_valid($socketIdentity,$directoryIdentity)){
			if($startup) return false;
			throw new RuntimeException('Managed web socket identity changed after readiness.');
		}
		dataphyre_runtime_web_process_identity($masterPid,'web-pool',1,$masterPid);
		foreach(dataphyre_runtime_web_worker_pids($masterPid) as $worker){
			dataphyre_runtime_web_process_identity($worker,'web-worker',$masterPid,$masterPid);
		}
		return true;
	}catch(DataphyreManagedWebInventoryUnavailable){return false;}
}

/** @return array{socket:array{dev:int,ino:int},directory:array{dev:int,ino:int}} */
function dataphyre_runtime_wait_for_web_pool(
	int $masterPid,?array &$observedSocketIdentity=null,?array &$observedDirectoryIdentity=null,
	?bool &$stopRequested=null,
): array
{
	$deadline=microtime(true)+5.0;
	do{
		dataphyre_runtime_require_not_stopping($stopRequested);
		$ready=@lstat('/run/dataphyre/web/php-fpm.sock');$readyDirectory=@lstat('/run/dataphyre/web');
		if(is_array($ready) && (($ready['mode'] ?? 0)&0170000)===0140000 && (($ready['mode'] ?? 0)&0777)===0600
			&& ($ready['uid'] ?? -1)===10001 && ($ready['gid'] ?? -1)===10001
			&& is_int($ready['dev'] ?? null) && is_int($ready['ino'] ?? null)
			&& ($observedSocketIdentity=['dev'=>$ready['dev'],'ino'=>$ready['ino']])
			&& !is_link('/run/dataphyre/web') && is_array($readyDirectory)
			&& (($readyDirectory['mode'] ?? 0)&0170000)===0040000 && (($readyDirectory['mode'] ?? 0)&0777)===0730
			&& ($readyDirectory['uid'] ?? -1)===0 && ($readyDirectory['gid'] ?? -1)===10001
			&& is_int($readyDirectory['dev'] ?? null) && is_int($readyDirectory['ino'] ?? null)
			&& ($observedDirectoryIdentity=['dev'=>$readyDirectory['dev'],'ino'=>$readyDirectory['ino']])
			&& dataphyre_runtime_lock_web_socket($observedSocketIdentity,$observedDirectoryIdentity)
			&& dataphyre_runtime_web_pool_healthy(
				$masterPid,true,$observedSocketIdentity,$observedDirectoryIdentity,
			)){
			return ['socket'=>$observedSocketIdentity,'directory'=>$observedDirectoryIdentity];
		}
		if(dataphyre_runtime_web_pool_healthy($masterPid,true)){
			$stat=@lstat('/run/dataphyre/web/php-fpm.sock');
			if(is_array($stat) && is_int($stat['dev'] ?? null) && is_int($stat['ino'] ?? null) && $stat['ino']>0){
				continue;
			}
		}
		usleep(10000);
	}while(microtime(true)<$deadline);
	dataphyre_runtime_require_not_stopping($stopRequested);
	throw new RuntimeException('Managed PHP web pool did not become ready.');
}

/** @return array{dev:int,ino:int} */
function dataphyre_runtime_prepare_root_socket(string $directory,string $socket): array
{
	$allowed=[
		['/run/dataphyre/control','/run/dataphyre/control/runtime.sock'],
		['/run/dataphyre/scheduler','/run/dataphyre/scheduler/gateway.sock'],
	];
	if(!in_array([$directory,$socket],$allowed,true)) throw new RuntimeException('Managed root socket target is invalid.');
	$parent=@lstat('/run/dataphyre');
	if(is_link('/run/dataphyre') || !is_array($parent) || (($parent['mode'] ?? 0)&0170000)!==0040000
		|| !in_array(($parent['mode'] ?? 0)&0777,[0700,0711],true)
		|| ($parent['uid'] ?? -1)!==0 || ($parent['gid'] ?? -1)!==0){
		throw new RuntimeException('Managed root socket parent is invalid.');
	}
	if(file_exists($directory) || is_link($directory)){
		$stat=@lstat($directory);
		if(is_link($directory) || !is_array($stat) || (($stat['mode'] ?? 0)&0170000)!==0040000
			|| (($stat['mode'] ?? 0)&0777)!==0700 || ($stat['uid'] ?? -1)!==0 || ($stat['gid'] ?? -1)!==0
			|| !hash_equals($directory,(string)realpath($directory))){
			throw new RuntimeException('Managed root socket directory is invalid.');
		}
	}else if(!@mkdir($directory,0700) || !@chgrp($directory,0) || !@chmod($directory,0700)){
		throw new RuntimeException('Managed root socket directory could not be created.');
	}
	if(file_exists($socket) || is_link($socket)){
		$stat=@lstat($socket);
		if(is_link($socket) || !is_array($stat) || (($stat['mode'] ?? 0)&0170000)!==0140000
			|| (($stat['mode'] ?? 0)&0777)!==0600 || ($stat['uid'] ?? -1)!==0 || ($stat['gid'] ?? -1)!==0
			|| !@unlink($socket)) throw new RuntimeException('Managed stale root socket is invalid.');
	}
	$prepared=@lstat($directory);
	if(!is_array($prepared) || !is_int($prepared['dev'] ?? null) || !is_int($prepared['ino'] ?? null)){
		throw new RuntimeException('Managed root socket directory identity is unavailable.');
	}
	return ['dev'=>$prepared['dev'],'ino'=>$prepared['ino']];
}

/** @return array{listener:resource,identity:array{dev:int,ino:int},directory_identity:array{dev:int,ino:int}} */
function dataphyre_runtime_bind_control_socket(): array
{
	$directory='/run/dataphyre/control';$socket=$directory.'/runtime.sock';
	$directoryIdentity=dataphyre_runtime_prepare_root_socket($directory,$socket);$previousUmask=umask(0077);
	try{$listener=@stream_socket_server('unix://'.$socket,$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);}
	finally{umask($previousUmask);}
	if(!is_resource($listener)){
		dataphyre_runtime_cleanup_root_socket($directory,$socket,null,$directoryIdentity);
		throw new RuntimeException('Managed control socket could not be bound.');
	}
	$stat=@lstat($socket);$identity=is_array($stat) && is_int($stat['dev'] ?? null) && is_int($stat['ino'] ?? null)
		? ['dev'=>$stat['dev'],'ino'=>$stat['ino']] : null;
	if(!is_array($identity) || !@chmod($socket,0600) || !dataphyre_runtime_root_socket_valid($socket,$identity)){
		fclose($listener);dataphyre_runtime_cleanup_root_socket($directory,$socket,$identity,$directoryIdentity);
		throw new RuntimeException('Managed control socket boundary is invalid.');
	}
	stream_set_blocking($listener,false);return [
		'listener'=>$listener,'identity'=>$identity,'directory_identity'=>$directoryIdentity,
	];
}

/** @param array{dev:int,ino:int} $identity @param null|array{dev:int,ino:int} $directoryIdentity */
function dataphyre_runtime_root_socket_valid(string $socket,array $identity,?array $directoryIdentity=null): bool
{
	if(!in_array($socket,['/run/dataphyre/control/runtime.sock','/run/dataphyre/scheduler/gateway.sock'],true)
		|| array_keys($identity)!==['dev','ino'] || !is_int($identity['dev']) || !is_int($identity['ino'])) return false;
	$stat=@lstat($socket);$directory=dirname($socket);$directoryStat=@lstat($directory);
	return !is_link($socket) && is_array($stat) && (($stat['mode'] ?? 0)&0170000)===0140000
		&& (($stat['mode'] ?? 0)&0777)===0600 && ($stat['uid'] ?? -1)===0 && ($stat['gid'] ?? -1)===0
		&& ($stat['dev'] ?? null)===$identity['dev'] && ($stat['ino'] ?? null)===$identity['ino']
		&& !is_link($directory) && is_array($directoryStat) && (($directoryStat['mode'] ?? 0)&0170000)===0040000
		&& (($directoryStat['mode'] ?? 0)&0777)===0700 && ($directoryStat['uid'] ?? -1)===0 && ($directoryStat['gid'] ?? -1)===0
		&& ($directoryIdentity===null || (
			array_keys($directoryIdentity)===['dev','ino']
			&& ($directoryStat['dev'] ?? null)===$directoryIdentity['dev']
			&& ($directoryStat['ino'] ?? null)===$directoryIdentity['ino']
		));
}

/** @return array{dev:int,ino:int} */
function dataphyre_runtime_wait_for_scheduler_socket(
	int $gatewayPid,?array &$observedIdentity=null,?bool &$stopRequested=null,
): array
{
	$socket=DataphyreApplicationRuntimeSchedulerGateway::SOCKET;$deadline=microtime(true)+5.0;
	do{
		dataphyre_runtime_require_not_stopping($stopRequested);
		$stat=@lstat($socket);
		if(is_array($stat) && is_int($stat['dev'] ?? null) && is_int($stat['ino'] ?? null)){
			$identity=['dev'=>$stat['dev'],'ino'=>$stat['ino']];
			$observedIdentity=$identity;
			if(dataphyre_runtime_root_socket_valid($socket,$identity)) return $identity;
		}
		if(!is_dir('/proc/'.$gatewayPid)) break;
		usleep(10000);
	}while(microtime(true)<$deadline);
	dataphyre_runtime_require_not_stopping($stopRequested);
	throw new RuntimeException('Managed scheduler socket did not become ready.');
}

/** @param null|array{dev:int,ino:int} $identity @param null|array{dev:int,ino:int} $directoryIdentity */
function dataphyre_runtime_cleanup_root_socket(
	string $directory,string $socket,?array $identity,?array $directoryIdentity=null,
): void
{
	$allowed=[
		['/run/dataphyre/control','/run/dataphyre/control/runtime.sock'],
		['/run/dataphyre/scheduler','/run/dataphyre/scheduler/gateway.sock'],
	];
	if(!in_array([$directory,$socket],$allowed,true) || !is_array($directoryIdentity)
		|| array_keys($directoryIdentity)!==['dev','ino']) return;
	$socketStat=@lstat($socket);$directoryStat=@lstat($directory);
	$exactDirectory=!is_link($directory) && is_array($directoryStat)
		&& (($directoryStat['mode'] ?? 0)&0170000)===0040000 && (($directoryStat['mode'] ?? 0)&0777)===0700
		&& ($directoryStat['uid'] ?? -1)===0 && ($directoryStat['gid'] ?? -1)===0
		&& ($directoryStat['dev'] ?? null)===$directoryIdentity['dev']
		&& ($directoryStat['ino'] ?? null)===$directoryIdentity['ino'];
	if($exactDirectory && is_array($identity) && array_keys($identity)===['dev','ino']
		&& !is_link($socket) && is_array($socketStat) && (($socketStat['mode'] ?? 0)&0170000)===0140000
		&& in_array(($socketStat['mode'] ?? 0)&0777,[0600,0700],true)
		&& ($socketStat['uid'] ?? -1)===0 && ($socketStat['gid'] ?? -1)===0
		&& ($socketStat['dev'] ?? null)===$identity['dev'] && ($socketStat['ino'] ?? null)===$identity['ino']) @unlink($socket);
	$stat=@lstat($directory);
	if(!is_link($directory) && is_array($stat) && (($stat['mode'] ?? 0)&0170000)===0040000
		&& (($stat['mode'] ?? 0)&0777)===0700 && ($stat['uid'] ?? -1)===0 && ($stat['gid'] ?? -1)===0
		&& ($stat['dev'] ?? null)===$directoryIdentity['dev'] && ($stat['ino'] ?? null)===$directoryIdentity['ino']){
		@rmdir($directory);
	}
}

function dataphyre_runtime_signal_child(array $child,int $signal): void
{
	$pid=$child['pid'] ?? null;$group=$child['process_group_id'] ?? null;
	if(!is_int($pid) || $pid<2 || !in_array($signal,[SIGTERM,SIGKILL],true)) return;
	if(is_int($group) && $group===$pid){
		@posix_kill(-$group,$signal);return;
	}
	@posix_kill($pid,$signal);
}

/** @param array<string,array{resource:mixed,pid:int,pool:string,start_time_ticks:string,process_group_id:?int}> $children */
function dataphyre_runtime_require_owned_children_healthy(array $children): void
{
	$roles=array_keys($children);sort($roles,SORT_STRING);
	if($roles!==['realtime','scheduler','web','web-http-gateway']){
		throw new DataphyreManagedRuntimeGenerationUnavailable('Managed runtime owned-child roles are invalid.');
	}
	foreach($children as $role=>$child){
		if(!is_array($child) || array_keys($child)!==[
			'resource','pid','pool','start_time_ticks','process_group_id',
		] || ($child['pool'] ?? null)!==$role || !is_resource($child['resource'] ?? null)
			|| !is_int($child['pid'] ?? null) || $child['pid']<2
			|| !is_string($child['start_time_ticks'] ?? null)
			|| preg_match('/^[1-9][0-9]{0,31}$/D',$child['start_time_ticks'])!==1){
			throw new DataphyreManagedRuntimeGenerationUnavailable('Managed runtime owned-child identity is invalid.');
		}
		$status=proc_get_status($child['resource']);
		if(!is_array($status) || ($status['running'] ?? false)!==true || ($status['pid'] ?? null)!==$child['pid']){
			throw new DataphyreManagedRuntimeGenerationUnavailable($role.' runtime pool exited unexpectedly.');
		}
		try{$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity($child['pid']);}
		catch(Throwable $failure){
			throw new DataphyreManagedRuntimeGenerationUnavailable($role.' runtime pool identity is unavailable.',0,$failure);
		}
		if(($identity['parent_pid'] ?? null)!==getmypid()
			|| !hash_equals($child['start_time_ticks'],(string)($identity['start_time_ticks'] ?? ''))){
			throw new DataphyreManagedRuntimeGenerationUnavailable($role.' runtime pool identity changed.');
		}
		$expectedGroup=$role==='realtime' ? null : $child['pid'];
		if(($child['process_group_id'] ?? null)!==$expectedGroup
			|| ($expectedGroup!==null && (!function_exists('posix_getpgid') || @posix_getpgid($child['pid'])!==$expectedGroup))){
			throw new DataphyreManagedRuntimeGenerationUnavailable($role.' runtime process group changed.');
		}
	}
}

/** Polls the exact production generation; unit-level protocol fixtures omit the managed marker. */
function dataphyre_runtime_require_generation_healthy(array &$runtime): void
{
	if(($runtime['managed_generation'] ?? false)!==true) return;
	try{
		dataphyre_runtime_require_owned_children_healthy($runtime['owned_children'] ?? []);
		if(!dataphyre_runtime_root_socket_valid(
			'/run/dataphyre/control/runtime.sock',$runtime['control_socket_identity'] ?? [],
			$runtime['control_socket_directory_identity'] ?? null,
		) || !dataphyre_runtime_root_socket_valid(
			DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$runtime['scheduler_socket_identity'] ?? [],
			$runtime['scheduler_socket_directory_identity'] ?? null,
		)) throw new RuntimeException('Managed private runtime socket identity changed.');
		if(dataphyre_runtime_web_pool_healthy(
			$runtime['web_fpm_pid'] ?? 0,false,$runtime['web_socket_identity'] ?? null,
			$runtime['web_socket_directory_identity'] ?? null,
		)){
			unset($runtime['_web_inventory_invalid_since']);return;
		}
		$runtime['_web_inventory_invalid_since'] ??= microtime(true);
		if(!is_float($runtime['_web_inventory_invalid_since'])
			|| microtime(true)-$runtime['_web_inventory_invalid_since']>5.0){
			throw new RuntimeException('Managed PHP web worker inventory did not recover.');
		}
	}catch(DataphyreManagedRuntimeGenerationUnavailable $failure){throw $failure;}
	catch(Throwable $failure){
		throw new DataphyreManagedRuntimeGenerationUnavailable('Managed runtime generation became unhealthy.',0,$failure);
	}
}

function dataphyre_runtime_require_not_stopping(?bool $stopRequested): void
{
	if($stopRequested===true){
		throw new DataphyreManagedRuntimeGracefulShutdown('Managed runtime shutdown requested.');
	}
}

/** @param list<int> $ownedPids @param array<int,array{start_time_ticks:string,first_seen:float}> $tracked */
function dataphyre_runtime_reap_adopted_children(array $ownedPids,array &$tracked): void
{
	$owned=[];
	foreach($ownedPids as $pid){
		if(!is_int($pid) || $pid<2) throw new RuntimeException('Runtime owned-child inventory is invalid.');
		$owned[$pid]=true;
	}
	$path='/proc/self/task/'.getmypid().'/children';$bytes=@file_get_contents($path);
	if(!is_string($bytes) || strlen($bytes)>32768) throw new RuntimeException('Runtime adopted-child inventory is unavailable.');
	$observed=[];
	foreach(preg_split('/\s+/',trim($bytes),-1,PREG_SPLIT_NO_EMPTY) ?: [] as $candidate){
		if(preg_match('/^[1-9][0-9]{0,9}$/D',$candidate)!==1 || ($pid=(int)$candidate)<2){
			throw new RuntimeException('Runtime adopted-child inventory is invalid.');
		}
		$observed[$pid]=true;
		if(isset($owned[$pid])) continue;
		if(@pcntl_waitpid($pid,$status,WNOHANG)===$pid){unset($tracked[$pid]);continue;}
		try{$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity($pid);}catch(Throwable){continue;}
		if(($identity['parent_pid'] ?? null)!==getmypid()) continue;
		$start=(string)($identity['start_time_ticks'] ?? '');
		if(isset($tracked[$pid]) && !hash_equals($tracked[$pid]['start_time_ticks'],$start)){
			throw new RuntimeException('Runtime adopted-child identity changed.');
		}
		$tracked[$pid] ??=['start_time_ticks'=>$start,'first_seen'=>microtime(true)];
		@posix_kill($pid,SIGKILL);
		if(@pcntl_waitpid($pid,$status,WNOHANG)===$pid){unset($tracked[$pid]);continue;}
		if(microtime(true)-$tracked[$pid]['first_seen']>1.0){
			throw new RuntimeException('Runtime adopted child could not be reaped.');
		}
	}
	foreach(array_keys($tracked) as $pid) if(!isset($observed[$pid])) unset($tracked[$pid]);
}

/**
 * @param null|array{dev:int,ino:int} $socketIdentity
 * @param null|array{dev:int,ino:int} $directoryIdentity
 * @param null|array{dev:int,ino:int} $parentIdentity
 */
function dataphyre_runtime_cleanup_web_socket(
	?array $socketIdentity,?array $directoryIdentity,?array $parentIdentity,
): void
{
	$parent='/run/dataphyre';$socket='/run/dataphyre/web/php-fpm.sock';$directory='/run/dataphyre/web';
	$directoryStat=@lstat($directory);
	$directoryMode=is_array($directoryStat) ? (($directoryStat['mode'] ?? 0)&0777) : -1;
	$directoryOwnerMatches=($directoryStat['uid'] ?? -1)===0 && (
		(($directoryStat['gid'] ?? -1)===0 && (($directoryMode&~0700)===0))
		|| (($directoryStat['gid'] ?? -1)===10001 && (($directoryMode&~0730)===0))
	)
		|| ($directoryMode===0711 && ($directoryStat['uid'] ?? -1)===0 && ($directoryStat['gid'] ?? -1)===0);
	$directoryMatches=is_array($directoryIdentity) && array_keys($directoryIdentity)===['dev','ino']
		&& !is_link($directory) && is_array($directoryStat) && (($directoryStat['mode'] ?? 0)&0170000)===0040000
		&& $directoryOwnerMatches
		&& ($directoryStat['dev'] ?? null)===$directoryIdentity['dev'] && ($directoryStat['ino'] ?? null)===$directoryIdentity['ino'];
	if($directoryMatches){
		@chmod($directory,0700);$socketStat=@lstat($socket);
		if(is_array($socketIdentity) && array_keys($socketIdentity)===['dev','ino'] && !is_link($socket)
			&& is_array($socketStat) && (($socketStat['mode'] ?? 0)&0170000)===0140000
			&& (($socketStat['mode'] ?? 0)&0777)===0600 && ($socketStat['uid'] ?? -1)===10001 && ($socketStat['gid'] ?? -1)===10001
			&& ($socketStat['dev'] ?? null)===$socketIdentity['dev'] && ($socketStat['ino'] ?? null)===$socketIdentity['ino']){
			@unlink($socket);
		}
		@rmdir($directory);
	}
	$parentStat=@lstat($parent);
	if(is_array($parentIdentity) && array_keys($parentIdentity)===['dev','ino']
		&& !is_link($parent) && is_array($parentStat) && (($parentStat['mode'] ?? 0)&0170000)===0040000
		&& in_array(($parentStat['mode'] ?? 0)&0777,[0700,0711],true)
		&& ($parentStat['uid'] ?? -1)===0 && ($parentStat['gid'] ?? -1)===0
		&& ($parentStat['dev'] ?? null)===$parentIdentity['dev'] && ($parentStat['ino'] ?? null)===$parentIdentity['ino']){
		@chmod($parent,0700);
	}
}

function dataphyre_runtime_spawn(
	string $router,
	string $projectRoot,
	string $pool,
	string $host,
	int $port,
	array $applicationEnvironment,
	?array $managedBootstrap,
): array {
	if(!in_array($pool,['web','web-http-gateway','scheduler','realtime'],true)
		|| ($pool==='web' && ($host!=='127.0.0.1' || $port!==8083))
		|| ($pool==='web-http-gateway' && ($host!=='127.0.0.1' || $port!==8083))
		|| ($pool==='scheduler' && ($host!=='' || $port!==0))
		|| ($pool==='realtime' && ($host!=='0.0.0.0' || $port!==8080))
		|| is_link($router) || !is_file($router) || !hash_equals($router,(string)realpath($router))
		|| is_link($projectRoot) || !is_dir($projectRoot) || !hash_equals($projectRoot,(string)realpath($projectRoot))){
		throw new RuntimeException('Runtime pool invocation is invalid.');
	}
	if($pool==='web-http-gateway' && ($applicationEnvironment!==[] || $managedBootstrap!==null)){
		throw new RuntimeException('Runtime web gateway cannot receive application state.');
	}
	if($pool!=='realtime') unset($applicationEnvironment['DATAPHYRE_RUNTIME_REALTIME_PROBE_SECRET']);
	$setpriv='/usr/bin/setpriv';
	$phpOptions=[
		'-d','display_errors=0','-d','log_errors=1','-d','expose_php=0',
		'-d','user_ini.filename=','-d','auto_prepend_file=','-d','auto_append_file=',
	];
	if($pool==='realtime'){
		$command=[
			$setpriv,'--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGTERM',
			PHP_BINARY,...$phpOptions,$router,$pool,$host,(string)$port,$projectRoot,
		];
	}elseif($pool==='scheduler'){
		$gateway=__DIR__.'/application_runtime_scheduler_gateway.php';
		$command=[
			$setpriv,'--reuid=0','--regid=0','--groups=0','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all,+kill,+setuid,+setgid','--pdeathsig=SIGTERM',
			PHP_BINARY,...$phpOptions,$gateway,DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$router,$projectRoot,
		];
	}elseif($pool==='web-http-gateway'){
		$gateway=__DIR__.'/application_runtime_web_gateway.php';
		$command=[
			$setpriv,'--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGTERM',
			PHP_BINARY,...$phpOptions,$gateway,$host,(string)$port,$router,$projectRoot,
		];
	}else{
		$config=__DIR__.'/application_runtime_php_fpm.conf';
		$command=[
			$setpriv,'--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGTERM',
			'/usr/local/sbin/php-fpm','-F','-y',$config,
			'-d','dataphyre_environment_fd.managed_pool_role=web','-d','user_ini.filename=',
		];
	}
	$descriptors=[
		0=>['file','/dev/null','r'],
		1=>['file','php://stdout','a'],
		2=>['file','php://stderr','a'],
    ];
	$brokerRole=match($pool){
		'web'=>'web-pool','web-http-gateway'=>'web-http-gateway','scheduler'=>'scheduler-gateway',default=>'realtime',
	};
	$spawned=DataphyreApplicationRuntimeProcessBroker::spawn(
		$command,$descriptors,$projectRoot,[],$brokerRole,$applicationEnvironment,10000,$managedBootstrap,null,
		in_array($pool,['web','web-http-gateway','scheduler'],true),
	);
	return [
		'resource'=>$spawned['resource'],'pid'=>$spawned['pid'],'pool'=>$pool,
		'start_time_ticks'=>$spawned['identity']['start_time_ticks'],
		'process_group_id'=>in_array($pool,['web','web-http-gateway','scheduler'],true) ? $spawned['pid'] : null,
	];
}

function dataphyre_runtime_status(array $runtime): array
{
    return [
		'contract'=>'dataphyre.application_runtime.v7',
		'deployment_application'=>$runtime['deployment_application'],
		'framework_application'=>$runtime['framework_application'],
		'environment'=>$runtime['environment'],
		'release_id'=>$runtime['release_id'],
		'environment_fingerprint'=>$runtime['environment_fingerprint'],
		'generation'=>$runtime['generation'],
        'supervisor_pid'=>getmypid(),
        'supervisor_uid'=>function_exists('posix_geteuid') ? posix_geteuid() : -1,
        'supervisor_gid'=>function_exists('posix_getegid') ? posix_getegid() : -1,
		'activation_mode'=>$runtime['activation_mode'],
		'active'=>$runtime['active'],
		'scheduler_cycle_in_progress'=>$runtime['scheduler_cycle_in_progress'],
		'control'=>dataphyre_runtime_control_status(
			$runtime['control_socket_identity'],$runtime['control_socket_directory_identity'],
		),
		'web'=>dataphyre_runtime_web_status($runtime),
		'scheduler'=>dataphyre_runtime_pool_identity(
			$runtime['scheduler_pid'],$runtime['scheduler_start_time_ticks'],'scheduler',null,null,
			$runtime['scheduler_socket_identity'],$runtime['scheduler_socket_directory_identity'],
		),
		'realtime'=>dataphyre_runtime_pool_identity(
			$runtime['realtime_pid'],$runtime['realtime_start_time_ticks'],'realtime','0.0.0.0',8080,
		),
		'scheduler_registration'=>dataphyre_runtime_scheduler_registration_summary($runtime['scheduler_registration']),
		'scheduler_noop_probe'=>$runtime['scheduler_noop_probe'],
		'scheduler_state_identity_sha256'=>$runtime['scheduler_state_identity_sha256'],
		'business_cadence'=>[
            'count'=>$runtime['count'],
            'last_at'=>$runtime['last_at'],
            'last_result'=>$runtime['last_result'],
        ],
    ];
}

/** @param array{dev:int,ino:int} $identity @param array{dev:int,ino:int} $directoryIdentity */
function dataphyre_runtime_control_status(array $identity,array $directoryIdentity): array
{
	$socket='/run/dataphyre/control/runtime.sock';
	if(!dataphyre_runtime_root_socket_valid($socket,$identity,$directoryIdentity)){
		throw new RuntimeException('Runtime control socket identity is invalid.');
	}
	return [
		'transport'=>'unix','socket_path_sha256'=>'sha256:'.hash('sha256',$socket),
		'socket_device'=>$identity['dev'],'socket_inode'=>$identity['ino'],
		'socket_uid'=>0,'socket_gid'=>0,'socket_mode'=>'0600',
		'socket_directory_device'=>$directoryIdentity['dev'],'socket_directory_inode'=>$directoryIdentity['ino'],
		'socket_directory_uid'=>0,'socket_directory_gid'=>0,'socket_directory_mode'=>'0700',
	];
}

/** Keeps full task definitions root-internal while exposing bounded registration evidence. */
function dataphyre_runtime_scheduler_registration_summary(mixed $report): ?array
{
	if($report===null) return null;
	if(!dataphyre_runtime_scheduler_registration_valid($report)){
		throw new RuntimeException('Scheduler registration evidence is invalid.');
	}
	return [
		'contract'=>$report['contract'],'ok'=>$report['ok'],
		'registration_attempt_count'=>$report['registration_attempt_count'],
		'registration_accepted_count'=>$report['registration_accepted_count'],
		'registration_failure_count'=>$report['registration_failure_count'],
		'definition_count'=>$report['definition_count'],
		'definition_sha256'=>$report['definition_sha256'],
	];
}

function dataphyre_runtime_scheduler_registration_valid(mixed $report): bool
{
	if(!is_array($report) || array_keys($report)!==[
		'contract','ok','registration_attempt_count','registration_accepted_count',
		'registration_failure_count','definition_count','definition_sha256','definitions',
	] || ($report['contract'] ?? null)!=='dataphyre.scheduler_registration.v1'
		|| ($report['ok'] ?? null)!==true
		|| !is_string($report['definition_sha256'] ?? null)
		|| preg_match('/^sha256:[a-f0-9]{64}$/D',$report['definition_sha256'])!==1
		|| !is_array($report['definitions'] ?? null) || !array_is_list($report['definitions'])){
		return false;
	}
	foreach([
		'registration_attempt_count','registration_accepted_count','registration_failure_count',
		'definition_count',
	] as $key){
		if(!is_int($report[$key] ?? null) || $report[$key]<0 || $report[$key]>256){
			return false;
		}
	}
	if($report['registration_failure_count']!==0
		|| $report['registration_attempt_count']!==$report['registration_accepted_count']
		|| $report['registration_accepted_count']!==$report['definition_count']) return false;
	if($report['definition_count']!==count($report['definitions'])) return false;
	$previous='';
	foreach($report['definitions'] as $definition){
		try{
			$sha=DataphyreApplicationRuntimeSchedulerState::definitionSha256($definition);
		}catch(Throwable){return false;}
		if(!is_string($definition['name'] ?? null) || strcmp($definition['name'],$previous)<=0) return false;
		$previous=$definition['name'];
	}
	$encoded=json_encode($report['definitions'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	return hash_equals('sha256:'.hash('sha256',$encoded),$report['definition_sha256']);
}

/** Proves a UID-10001 process has no usable capabilities. */
function dataphyre_runtime_inactive_capability_boundary(array $identity): bool
{
	return ($identity['cap_inheritable'] ?? null)==='0000000000000000'
		&& ($identity['cap_permitted'] ?? null)==='0000000000000000'
		&& ($identity['cap_eff'] ?? null)==='0000000000000000'
		&& in_array(($identity['cap_bounding'] ?? null),[
			'0000000000000000','00000000000000e0',
		],true)
		&& ($identity['cap_ambient'] ?? null)==='0000000000000000'
		&& ($identity['no_new_privileges'] ?? null)===true;
}

/** Allocates one generation-local, strictly increasing signed-request counter. */
function dataphyre_runtime_next_scheduler_counter(array &$runtime): int
{
	$current=$runtime['request_counter'] ?? null;
	if(!is_int($current) || $current<0 || $current>=PHP_INT_MAX){
		throw new RuntimeException('Scheduler request counter is invalid.');
	}
	return $runtime['request_counter']=($current+1);
}

/** @param null|array{dev:int,ino:int} $socketIdentity @param null|array{dev:int,ino:int} $socketDirectoryIdentity */
function dataphyre_runtime_pool_identity(
	int $pid,string $expectedStartTimeTicks,string $role,?string $listenHost,?int $listenPort,?array $socketIdentity=null,
	?array $socketDirectoryIdentity=null,
): array
{
	if(($role==='scheduler' && ($listenHost!==null || $listenPort!==null || !is_array($socketIdentity)))
		|| ($role==='realtime' && [$listenHost,$listenPort]!==['0.0.0.0',8080])
		|| !in_array($role,['scheduler','realtime'],true)
		|| preg_match('/^[1-9][0-9]{0,31}$/D',$expectedStartTimeTicks)!==1){
		throw new RuntimeException('Runtime pool role mapping is invalid');
	}
	try{$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity($pid);}
	catch(Throwable $failure){throw new RuntimeException('Unable to attest runtime pool identity',0,$failure);}
	$gateway=$role==='scheduler';
	$expectedUid=$gateway ? 0 : 10001;$expectedGid=$gateway ? 0 : 10001;
	$expectedGroups=[$expectedGid];
	$capabilityBoundary=$gateway
		? $identity['cap_inheritable']==='0000000000000000'
			&& $identity['cap_permitted']==='00000000000000e0'
			&& $identity['cap_eff']==='00000000000000e0'
			&& $identity['cap_bounding']==='00000000000000e0'
			&& $identity['cap_ambient']==='0000000000000000'
			&& $identity['no_new_privileges']===true
		: dataphyre_runtime_inactive_capability_boundary($identity);
	if(!hash_equals($expectedStartTimeTicks,$identity['start_time_ticks'])
		|| $identity['uid']!==$expectedUid || $identity['gid']!==$expectedGid || $identity['groups']!==$expectedGroups
		|| !$capabilityBoundary || $identity['parent_pid']!==1
		|| ($gateway && (!function_exists('posix_getpgid') || @posix_getpgid($pid)!==$pid))){
		throw new RuntimeException('Runtime pool privilege boundary is invalid');
	}
	$common=[
		'running'=>true,
		'pid'=>$pid,
		'start_time_ticks'=>$identity['start_time_ticks'],
		'uid'=>$identity['uid'],
		'gid'=>$identity['gid'],
		'supplementary_gids'=>$identity['groups'],
		'cap_inheritable'=>$identity['cap_inheritable'],'cap_permitted'=>$identity['cap_permitted'],
		'cap_eff'=>$identity['cap_eff'],'cap_bounding'=>$identity['cap_bounding'],'cap_ambient'=>$identity['cap_ambient'],
		'no_new_privileges'=>$identity['no_new_privileges'],
		'role'=>$role,
		'parent_pid'=>$identity['parent_pid'],
		'execution_model'=>$gateway ? 'one-request-per-process-cgi' : 'single-exec-realtime',
    ];
	if(!$gateway) return [
		...array_slice($common,0,13,true),'listen_host'=>$listenHost,'listen_port'=>$listenPort,
		'parent_pid'=>$common['parent_pid'],'execution_model'=>$common['execution_model'],
	];
	if(!is_array($socketDirectoryIdentity)
		|| !dataphyre_runtime_root_socket_valid(
			DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$socketIdentity,$socketDirectoryIdentity,
		)){
		throw new RuntimeException('Runtime scheduler socket identity is invalid.');
	}
	return [
		...array_slice($common,0,13,true),'transport'=>'unix',
		'socket_path_sha256'=>'sha256:'.hash('sha256',DataphyreApplicationRuntimeSchedulerGateway::SOCKET),
		'socket_device'=>$socketIdentity['dev'],'socket_inode'=>$socketIdentity['ino'],
		'socket_uid'=>0,'socket_gid'=>0,'socket_mode'=>'0600',
		'socket_directory_device'=>$socketDirectoryIdentity['dev'],'socket_directory_inode'=>$socketDirectoryIdentity['ino'],
		'socket_directory_uid'=>0,'socket_directory_gid'=>0,'socket_directory_mode'=>'0700',
		'parent_pid'=>$common['parent_pid'],'execution_model'=>$common['execution_model'],
	];
}

/** @return array{running:bool,pid:int,start_time_ticks:string,uid:int,gid:int,supplementary_gids:list<int>,cap_eff:string,no_new_privileges:bool,role:string,parent_pid:int,process_group_id:int} */
function dataphyre_runtime_web_process_identity(int $pid,string $role,int $parentPid,int $processGroupId): array
{
	if(!in_array($role,['web-http-gateway','web-pool','web-worker'],true) || $pid<2 || $parentPid<1 || $processGroupId<2){
		throw new RuntimeException('Managed web process mapping is invalid.');
	}
	try{$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity($pid);}
	catch(Throwable $failure){
		if($role==='web-worker' && !is_dir('/proc/'.$pid)){
			throw new DataphyreManagedWebInventoryUnavailable('Managed web worker changed during inspection.',0,$failure);
		}
		throw $failure;
	}
	$group=function_exists('posix_getpgid') ? @posix_getpgid($pid) : false;
	if($identity['parent_pid']!==$parentPid || $identity['uid']!==10001 || $identity['gid']!==10001
		|| $identity['groups']!==[10001] || !dataphyre_runtime_inactive_capability_boundary($identity)
		|| $group!==$processGroupId){
		throw new RuntimeException('Managed web process privilege boundary is invalid.');
	}
	return [
		'running'=>true,'pid'=>$pid,'start_time_ticks'=>$identity['start_time_ticks'],
		'uid'=>$identity['uid'],'gid'=>$identity['gid'],'supplementary_gids'=>$identity['groups'],
		'cap_inheritable'=>$identity['cap_inheritable'],'cap_permitted'=>$identity['cap_permitted'],
		'cap_eff'=>$identity['cap_eff'],'cap_bounding'=>$identity['cap_bounding'],'cap_ambient'=>$identity['cap_ambient'],
		'no_new_privileges'=>$identity['no_new_privileges'],
		'role'=>$role,'parent_pid'=>$identity['parent_pid'],'process_group_id'=>$processGroupId,
	];
}

/** @return list<int> */
function dataphyre_runtime_web_worker_pids(int $masterPid): array
{
	$bytes=@file_get_contents('/proc/'.$masterPid.'/task/'.$masterPid.'/children');
	if(!is_string($bytes) || strlen($bytes)>4096) throw new DataphyreManagedWebInventoryUnavailable('Managed web worker inventory is unavailable.');
	$tokens=preg_split('/\s+/',trim($bytes),-1,PREG_SPLIT_NO_EMPTY) ?: [];$workers=[];
	foreach($tokens as $token){
		if(preg_match('/^[1-9][0-9]{0,9}$/D',$token)!==1 || ($pid=(int)$token)<2){
			throw new RuntimeException('Managed web worker inventory is invalid.');
		}
		$workers[]=$pid;
	}
	sort($workers,SORT_NUMERIC);$workers=array_values(array_unique($workers));
	if(count($workers)!==8) throw new DataphyreManagedWebInventoryUnavailable('Managed web worker inventory is incomplete.');
	return $workers;
}

function dataphyre_runtime_web_status(array $runtime): array
{
	$gateway=dataphyre_runtime_web_process_identity(
		$runtime['web_gateway_pid'],'web-http-gateway',1,$runtime['web_gateway_pid'],
	);
	$gateway['listen_host']='127.0.0.1';$gateway['listen_port']=8083;
	$gateway=[
		'running'=>$gateway['running'],'pid'=>$gateway['pid'],'start_time_ticks'=>$gateway['start_time_ticks'],
		'uid'=>$gateway['uid'],'gid'=>$gateway['gid'],'supplementary_gids'=>$gateway['supplementary_gids'],
		'cap_inheritable'=>$gateway['cap_inheritable'],'cap_permitted'=>$gateway['cap_permitted'],
		'cap_eff'=>$gateway['cap_eff'],'cap_bounding'=>$gateway['cap_bounding'],'cap_ambient'=>$gateway['cap_ambient'],
		'no_new_privileges'=>$gateway['no_new_privileges'],'role'=>$gateway['role'],
		'listen_host'=>$gateway['listen_host'],'listen_port'=>$gateway['listen_port'],
		'parent_pid'=>$gateway['parent_pid'],'process_group_id'=>$gateway['process_group_id'],
	];
	$master=dataphyre_runtime_web_process_identity($runtime['web_fpm_pid'],'web-pool',1,$runtime['web_fpm_pid']);
	$workers=[];
	foreach(dataphyre_runtime_web_worker_pids($runtime['web_fpm_pid']) as $pid){
		$workers[]=dataphyre_runtime_web_process_identity($pid,'web-worker',$runtime['web_fpm_pid'],$runtime['web_fpm_pid']);
	}
	$generationPayload=json_encode([
		'contract'=>'dataphyre.managed_php_web_generation.v1',
		'environment_fingerprint'=>$runtime['environment_fingerprint'],'generation'=>$runtime['generation'],
		'master_pid'=>$master['pid'],'master_start_time_ticks'=>$master['start_time_ticks'],
	],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	return [
		'execution_model'=>'persistent-php-fpm','http_gateway'=>$gateway,'fpm_master'=>$master,'workers'=>$workers,
		'socket_path_sha256'=>'sha256:'.hash('sha256','/run/dataphyre/web/php-fpm.sock'),
		'socket_device'=>$runtime['web_socket_identity']['dev'],'socket_inode'=>$runtime['web_socket_identity']['ino'],
		'socket_uid'=>10001,'socket_gid'=>10001,'socket_mode'=>'0600',
		'socket_directory_device'=>$runtime['web_socket_directory_identity']['dev'],
		'socket_directory_inode'=>$runtime['web_socket_directory_identity']['ino'],
		'socket_directory_uid'=>0,'socket_directory_gid'=>0,'socket_directory_mode'=>'0711',
		'native_envelope_generation_sha256'=>'sha256:'.hash(
			'sha256',"dataphyre.managed_php_web_generation.v1\0".$generationPayload,
		),
		'recycle_policy'=>[
			'process_manager'=>'static','max_children'=>8,'max_requests'=>500,
			'request_terminate_timeout_seconds'=>300,
		],
	];
}

function dataphyre_runtime_read_private_request(mixed $connection): ?array
{
	if(!is_resource($connection) || !stream_set_blocking($connection,false)) return null;
	$deadline=hrtime(true)+250_000_000;$wire='';$headerEnd=false;
	do{
		$remaining=$deadline-hrtime(true);if($remaining<=0) return null;
		$read=[$connection];$write=[];$except=[];
		$selected=@stream_select($read,$write,$except,intdiv($remaining,1_000_000_000),intdiv($remaining%1_000_000_000,1000));
		if($selected===false) return null;
		if($selected===0) continue;
		$chunk=@fread($connection,8193-strlen($wire));
		if(!is_string($chunk) || ($chunk==='' && feof($connection))) return null;
		$wire.=$chunk;if(strlen($wire)>8192) return null;$headerEnd=strpos($wire,"\r\n\r\n");
	}while($headerEnd===false);
	$head=substr($wire,0,$headerEnd);$body=substr($wire,$headerEnd+4);
	$lines=explode("\r\n",$head);$requestLine=array_shift($lines);
	if(!is_string($requestLine)
		|| preg_match('#^(GET|POST) (/dataphyre/runtime/(?:status|scheduler/claim|realtime/probe)) HTTP/1\.[01]$#D',$requestLine,$matches)!==1) return null;
	$headers=[];
	foreach($lines as $header){
		if(preg_match('/^([A-Za-z0-9-]+):\s*([^\r\n]*)$/D',$header,$headerMatch)!==1) return null;
		$name=strtolower($headerMatch[1]);if(isset($headers[$name])) return null;$headers[$name]=$headerMatch[2];
	}
	if(isset($headers['transfer-encoding'])) return null;
	if($matches[1]==='GET') return $body==='' && !isset($headers['content-length'])
		? ['method'=>'GET','path'=>$matches[2],'body'=>''] : null;
	$lengthRaw=$headers['content-length'] ?? '';
	if(preg_match('/^[1-9][0-9]{0,3}$/D',$lengthRaw)!==1 || ($length=(int)$lengthRaw)>4096
		|| strlen($body)>$length) return null;
	while(strlen($body)<$length){
		$remaining=$deadline-hrtime(true);if($remaining<=0) return null;
		$read=[$connection];$write=[];$except=[];
		$selected=@stream_select($read,$write,$except,intdiv($remaining,1_000_000_000),intdiv($remaining%1_000_000_000,1000));
		if($selected===false) return null;
		if($selected===0) continue;
		$chunk=@fread($connection,$length-strlen($body));
		if(!is_string($chunk) || ($chunk==='' && feof($connection))) return null;$body.=$chunk;
	}
	return ['method'=>'POST','path'=>$matches[2],'body'=>$body];
}

function dataphyre_runtime_private_response(mixed $connection, int $status, array $payload): void
{
	$body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	if(strlen($body)>DataphyreApplicationRuntimeSchedulerProtocol::MAX_TRANSPORT_BYTES){
		throw new RuntimeException('Private runtime response exceeded its fixed bound.');
	}
    $reason=match($status){200=>'OK',409=>'Conflict',default=>'Not Found'};
	$response="HTTP/1.1 {$status} {$reason}\r\nContent-Type: application/json\r\nCache-Control: no-store\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
	if(!is_resource($connection) || !stream_set_blocking($connection,false)){
		throw new DataphyreManagedRuntimeControlPeerFailure('Private runtime response stream is unavailable.');
	}
	$offset=0;$deadline=hrtime(true)+250_000_000;
	while($offset<strlen($response)){
		$remaining=$deadline-hrtime(true);
		if($remaining<=0) throw new DataphyreManagedRuntimeControlPeerFailure('Private runtime response write timed out.');
		$read=[];$write=[$connection];$except=[];
		$selected=@stream_select($read,$write,$except,intdiv($remaining,1_000_000_000),intdiv($remaining%1_000_000_000,1000));
		if($selected===false) throw new DataphyreManagedRuntimeControlPeerFailure('Private runtime response readiness failed.');
		if($selected===0) continue;
		$written=@fwrite($connection,substr($response,$offset));
		if(!is_int($written) || $written<1) throw new DataphyreManagedRuntimeControlPeerFailure('Private runtime response write failed.');
		$offset+=$written;
	}
}

function dataphyre_runtime_write_websocket_frame(mixed $socket, int $opcode, string $payload): void
{
    if (strlen($payload)>125) throw new RuntimeException('Realtime probe frame exceeded its fixed bound');
    $mask=random_bytes(4);
    $masked=$payload;
    for ($position=0;$position<strlen($payload);$position++) $masked[$position]=$payload[$position]^$mask[$position%4];
    $frame=chr(0x80|$opcode).chr(0x80|strlen($payload)).$mask.$masked;
    $offset=0;
    while ($offset<strlen($frame)) {
        $written=fwrite($socket,substr($frame,$offset));
        if (!is_int($written) || $written<1) throw new RuntimeException('Realtime probe frame write failed');
        $offset+=$written;
    }
}

function dataphyre_runtime_read_websocket_frame(mixed $socket, string &$buffer): array
{
    $read=static function(int $required) use ($socket,&$buffer): void {
        while (strlen($buffer)<$required) {
            $chunk=fread($socket,8192);
            if (!is_string($chunk) || $chunk==='') throw new RuntimeException('Realtime probe frame was incomplete');
            $buffer.=$chunk;
            if (strlen($buffer)>65536) throw new RuntimeException('Realtime probe frame exceeded its fixed bound');
        }
    };
    $read(2);
    $first=ord($buffer[0]);$second=ord($buffer[1]);
    if (($first&0x80)===0 || ($first&0x70)!==0 || ($second&0x80)!==0) {
        throw new RuntimeException('Realtime probe frame metadata was invalid');
    }
    $length=$second&0x7f;$offset=2;
    if ($length===126) {
        $read(4);$length=(int)unpack('nlength',substr($buffer,2,2))['length'];$offset=4;
    } elseif ($length===127) {
        $read(10);$parts=unpack('Nhigh/Nlow',substr($buffer,2,8));
        if (($parts['high'] ?? 1)!==0) throw new RuntimeException('Realtime probe frame was oversized');
        $length=(int)$parts['low'];$offset=10;
    }
    if ($length>65536) throw new RuntimeException('Realtime probe frame was oversized');
    $read($offset+$length);
    $payload=substr($buffer,$offset,$length);
    $buffer=substr($buffer,$offset+$length);
    return ['opcode'=>$first&0x0f,'payload'=>$payload];
}

function dataphyre_runtime_realtime_probe(): array
{
    $failure=[
        'contract'=>'dataphyre.application_realtime_probe.v1',
        'ok'=>false,
        'framework_listener_roundtrip'=>false,
		'application_authorization_rejections'=>false,
		'application_authorization_rejection_count'=>0,
		'registration_sha256'=>null,
		'ping_pong'=>false,
        'close_handshake'=>false,
    ];
    $socket=@stream_socket_client('tcp://127.0.0.1:8080',$errno,$error,2,STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) return $failure;
    try {
        stream_set_timeout($socket,3,0);
        $key=base64_encode(random_bytes(16));
        $request="GET /dataphyre/runtime/realtime/probe HTTP/1.1\r\n".
            "Host: 127.0.0.1:8080\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n".
            "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n".
            "Origin: https://dataphyre.invalid\r\n\r\n";
        $offset=0;
        while ($offset<strlen($request)) {
            $written=fwrite($socket,substr($request,$offset));
            if (!is_int($written) || $written<1) return $failure;
            $offset+=$written;
        }
        $buffer='';
        while (($headerEnd=strpos($buffer,"\r\n\r\n"))===false) {
            $chunk=fread($socket,4096);
            if (!is_string($chunk) || $chunk==='') return $failure;
            $buffer.=$chunk;
            if (strlen($buffer)>16384) return $failure;
        }
        $head=substr($buffer,0,$headerEnd);
        $buffer=substr($buffer,$headerEnd+4);
        $accept=base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));
        if (preg_match('/^HTTP\/1\.1 101 Switching Protocols\r\n/D',$head)!==1
            || preg_match('/^Sec-WebSocket-Accept:\s*'.preg_quote($accept,'/').'\s*$/mi',$head)!==1) return $failure;
        $eventFrame=dataphyre_runtime_read_websocket_frame($socket,$buffer);
        $event=json_decode($eventFrame['payload'],true);
        if ($eventFrame['opcode']!==0x1 || !is_array($event)
            || array_keys($event)!==[
				'contract','ok','framework_listener_roundtrip','application_authorization_rejections',
				'application_authorization_rejection_count','registration_sha256',
            ]
            || ($event['contract'] ?? null)!=='dataphyre.application_realtime_probe.v1'
            || ($event['ok'] ?? null)!==true || ($event['framework_listener_roundtrip'] ?? null)!==true
            || ($event['application_authorization_rejections'] ?? null)!==true
            || !is_int($event['application_authorization_rejection_count'] ?? null)
			|| $event['application_authorization_rejection_count']<0
			|| $event['application_authorization_rejection_count']>128
			|| !is_string($event['registration_sha256'] ?? null)
			|| preg_match('/^sha256:[a-f0-9]{64}$/D',$event['registration_sha256'])!==1) return $failure;
        $ping=random_bytes(8);
        dataphyre_runtime_write_websocket_frame($socket,0x9,$ping);
        $pong=dataphyre_runtime_read_websocket_frame($socket,$buffer);
        if ($pong['opcode']!==0xA || !hash_equals($ping,$pong['payload'])) return $failure;
        dataphyre_runtime_write_websocket_frame($socket,0x8,pack('n',1000));
        $close=dataphyre_runtime_read_websocket_frame($socket,$buffer);
        if ($close['opcode']!==0x8) return $failure;
        return $event+['ping_pong'=>true,'close_handshake'=>true];
    } catch (Throwable) {
        return $failure;
    } finally {
        fclose($socket);
    }
}

function dataphyre_runtime_serve_status(
    mixed $listener,
	array &$runtime,
	array &$pendingRequests,
	string $publicKey
): void {
	for($accepted=0;$accepted<4;$accepted++){
		dataphyre_runtime_require_generation_healthy($runtime);
		$connection=@stream_socket_accept($listener,0);if(!is_resource($connection)) break;
		try{$request=dataphyre_runtime_read_private_request($connection);
		if (is_array($request) && $request['method']==='GET' && $request['path']==='/dataphyre/runtime/status') {
			try{dataphyre_runtime_private_response($connection,200,dataphyre_runtime_status($runtime));}
			catch(DataphyreManagedWebInventoryUnavailable){
				dataphyre_runtime_private_response($connection,409,[
					'contract'=>'dataphyre.application_runtime_temporarily_unavailable.v1','ok'=>false,
				]);
			}
        } elseif (is_array($request) && $request['method']==='GET' && $request['path']==='/dataphyre/runtime/realtime/probe') {
            $probe=dataphyre_runtime_realtime_probe();
            dataphyre_runtime_private_response($connection,($probe['ok'] ?? false)===true ? 200 : 409,$probe);
		} elseif (is_array($request) && $request['method']==='POST' && $request['path']==='/dataphyre/runtime/scheduler/claim') {
			$candidate=json_decode($request['body'],true);
			$consumed=is_array($candidate)
					&& DataphyreApplicationRuntimeSchedulerProtocol::matchesCanonicalJson($candidate,$request['body'])
					&& DataphyreApplicationRuntimeSchedulerProtocol::consume($pendingRequests,$candidate,$publicKey);
			$payload=['ok'=>$consumed];
			dataphyre_runtime_private_response($connection,$consumed ? 200 : 409,$payload);
		} else {
			dataphyre_runtime_private_response($connection,404,['ok'=>false]);
		}
		}catch(DataphyreManagedRuntimeControlPeerFailure){}
		finally{
			if(is_resource($connection)){fclose($connection);$connection=null;}
		}
	}
}

/** Counts a canonical Linux CPU-list such as `0-3,6,8-11`. */
function dataphyre_runtime_scheduler_allowed_cpu_count(string $allowed): int
{
	$allowed=trim($allowed);
	if($allowed==='' || strlen($allowed)>4096 || preg_match('/^[0-9,-]+$/D',$allowed)!==1){
		throw new RuntimeException('Scheduler CPU allocation is invalid.');
	}
	$cpus=[];
	foreach(explode(',',$allowed) as $range){
		if(preg_match('/^([0-9]{1,5})(?:-([0-9]{1,5}))?$/D',$range,$matches)!==1){
			throw new RuntimeException('Scheduler CPU allocation is invalid.');
		}
		$first=(int)$matches[1];$last=isset($matches[2]) && $matches[2]!=='' ? (int)$matches[2] : $first;
		if($last<$first || $last>8191) throw new RuntimeException('Scheduler CPU allocation is invalid.');
		for($cpu=$first;$cpu<=$last;$cpu++) $cpus[$cpu]=true;
	}
	if($cpus===[]) throw new RuntimeException('Scheduler CPU allocation is empty.');
	return count($cpus);
}

/** Resolves the internal callback fan-out from the VM/cgroup CPU boundary. */
function dataphyre_runtime_scheduler_callback_concurrency(
	?string $allowedCpuList=null,
	?string $cpuMax=null,
): int {
	static $detected=null;
	$detect=$allowedCpuList===null && $cpuMax===null;
	if($detect && is_int($detected)) return $detected;
	if($allowedCpuList===null){
		$status=@file_get_contents('/proc/self/status');
		if(!is_string($status) || strlen($status)>1048576
			|| preg_match('/^Cpus_allowed_list:\s*([0-9,-]+)\s*$/m',$status,$matches)!==1){
			throw new RuntimeException('Scheduler CPU allocation is unavailable.');
		}
		$allowedCpuList=$matches[1];
	}
	if($cpuMax===null){
		$bytes=@file_get_contents('/sys/fs/cgroup/cpu.max');
		$cpuMax=is_string($bytes) && strlen($bytes)<=128 ? trim($bytes) : 'max 100000';
	}
	if(preg_match('/^(max|[1-9][0-9]{0,18}) ([1-9][0-9]{0,18})$/D',$cpuMax,$matches)!==1){
		throw new RuntimeException('Scheduler CPU quota is invalid.');
	}
	$available=dataphyre_runtime_scheduler_allowed_cpu_count($allowedCpuList);
	if($matches[1]!=='max'){
		$quota=(int)$matches[1];$period=(int)$matches[2];
		$available=min($available,max(1,intdiv($quota,$period)));
	}
	$capacity=max(1,min(32,$available));
	if($detect) $detected=$capacity;
	return $capacity;
}

/**
 * Opens one signed callback request without waiting for its receipt.
 *
 * The caller owns the returned socket until the request is settled.  The
 * pending signed request is retained until the response (or failure cleanup)
 * so the root control plane can consume it exactly once.
 *
 * @return array{socket:resource,request:string,offset:int,response:string,deadline:float,
 *  request_key:string,definition:array<string,mixed>,scheduled:array<string,mixed>,claim_nonce:string,
 *  started_at_milliseconds:int,eof:bool}
 */
function dataphyre_runtime_scheduler_open_callback(
	string $socketPath,
	array $identity,
	string $generation,
	int $counter,
	string $secretKey,
	array &$pendingRequests,
	array $definition,
	array $scheduled,
	string $claimNonce,
	int $startedAtMilliseconds,
): array {
	if($socketPath!==DataphyreApplicationRuntimeSchedulerGateway::SOCKET
		|| !is_string($definition['name'] ?? null)){
		throw new RuntimeException('Scheduler callback transport is invalid.');
	}
	$definitionSha=DataphyreApplicationRuntimeSchedulerState::definitionSha256($definition);
	$issued=DataphyreApplicationRuntimeSchedulerProtocol::issue(
		'callback',$identity,$generation,$counter,$secretKey,$definition['name'],$definitionSha,
		$definition['timeout_milliseconds'],
	);
	$requestKey='callback:'.$counter;
	$pendingRequests[$requestKey]=$issued;
	$body=json_encode($issued,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	$request="POST /dataphyre/runtime/scheduler/callback HTTP/1.1\r\nHost: dataphyre-scheduler\r\n".
		"Content-Type: application/json\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
	try{
		$socket=@stream_socket_client('unix://'.$socketPath,$errno,$error,2,STREAM_CLIENT_CONNECT);
		if(!is_resource($socket)) throw new RuntimeException('Scheduler request connection failed');
		stream_set_blocking($socket,false);
		return [
			'socket'=>$socket,'request'=>$request,'offset'=>0,'response'=>'',
			'deadline'=>microtime(true)+((int)$definition['timeout_milliseconds']/1000)+2.0,
			'request_key'=>$requestKey,'definition'=>$definition,'scheduled'=>$scheduled,
			'claim_nonce'=>$claimNonce,'started_at_milliseconds'=>$startedAtMilliseconds,'eof'=>false,
		];
	}catch(Throwable $failure){
		unset($pendingRequests[$requestKey]);
		throw $failure;
	}
}

/** @return array{contract:string,ok:bool} */
function dataphyre_runtime_scheduler_decode_callback_response(string $response): array
{
	[$head,$body]=array_pad(explode("\r\n\r\n",$response,2),2,'');
	$status=preg_match('/^HTTP\/1\.[01]\s+(\d{3})\b/D',$head,$matches)===1 ? (int)$matches[1] : null;
	$decoded=json_decode($body,true);
	if($status===null || $status<200 || $status>=300 || !is_array($decoded)
		|| array_keys($decoded)!==['contract','ok']
		|| ($decoded['contract'] ?? null)!=='dataphyre.scheduler_callback.v1'
		|| ($decoded['ok'] ?? null)!==true){
		throw new RuntimeException('Scheduler callback response contract is invalid.');
	}
	return ['contract'=>'dataphyre.scheduler_callback.v1','ok'=>true];
}

function dataphyre_runtime_scheduler_select(
	array &$read,
	array &$write,
	array &$except,
	?bool &$stopRequested,
	?bool &$activationRequested,
	?callable $selector=null,
): int {
	$selector ??= static function(array &$read,array &$write,array &$except): int|false {
		return @stream_select($read,$write,$except,0,20000);
	};
	$selected=$selector($read,$write,$except);
	// An async TERM/INT may interrupt select or arrive as it returns ready.
	// Stop authority must win before any socket settlement or cadence mutation.
	dataphyre_runtime_require_not_stopping($stopRequested);
	if($selected===false && $activationRequested!==null){
		// Normal activation signals wake the loop without abandoning callbacks.
		// Clear readiness left over from the interrupted call, then apply the
		// pending transition and drain every already-dispatched receipt.
		$read=[];$write=[];$except=[];return 0;
	}
	if($selected===false || !is_int($selected) || $selected<0){
		throw new RuntimeException('Scheduler callback multiplex select failed.');
	}
	return $selected;
}

/**
 * Drains due callbacks through bounded non-blocking sockets.
 *
 * Each task is claimed before a socket is opened.  Receipts are validated and
 * recorded independently; a failed request releases only its own claim after
 * EOF proves that the gateway has finished. TERM, generation loss, or cleanup
 * failure tears down the whole in-flight set while retaining unproven claims
 * until their fixed broker-safe expiry.
 *
 * @param list<array{definition:array<string,mixed>,due_at_milliseconds:int,first_execution:bool}> $due
 * @return array{observations:list<array<string,mixed>>,cycle_failed:bool}
 */
function dataphyre_runtime_run_scheduler_multiplexed_callbacks(
	string $socketPath,
	array $identity,
	string $generation,
	string $secretKey,
	string $publicKey,
	mixed $statusListener,
	array &$runtime,
	array &$pendingRequests,
	?bool &$activationRequested,
	float &$nextTick,
	int $interval,
	array $due,
	?callable $activationPersister=null,
	?callable $clockMilliseconds=null,
	?bool &$stopRequested=null,
): array {
	$clockMilliseconds ??= static fn(): int=>(int)floor(microtime(true)*1000);
	$active=[];$dueIndex=0;$cycleFailed=false;$observations=[];
	$capacity=dataphyre_runtime_scheduler_callback_concurrency();
	if($capacity<1 || $capacity>128) throw new RuntimeException('Scheduler callback concurrency bound is invalid.');
	if($interval<1 || $interval>86400) throw new RuntimeException('Scheduler interval bound is invalid.');
	usort($due,static function(array $left,array $right) use ($interval): int {
		$leftDefinition=$left['definition'];$rightDefinition=$right['definition'];
		$leftDeadline=$left['due_at_milliseconds']+(
			(int)($leftDefinition['frequency_milliseconds']>0
				? $leftDefinition['frequency_milliseconds'] : $interval*1000)
		);
		$rightDeadline=$right['due_at_milliseconds']+(
			(int)($rightDefinition['frequency_milliseconds']>0
				? $rightDefinition['frequency_milliseconds'] : $interval*1000)
		);
		return $leftDeadline===$rightDeadline
			? strcmp($leftDefinition['name'],$rightDefinition['name'])
			: ($leftDeadline<=>$rightDeadline);
	});

	$close=static function(array &$request) use (&$pendingRequests): void {
		unset($pendingRequests[$request['request_key']]);
		if(is_resource($request['socket'])) fclose($request['socket']);
		$request['socket']=null;
	};
	$release=static function(array $request) use ($identity,$generation): void {
		DataphyreApplicationRuntimeSchedulerState::releaseClaim(
			$identity,$request['definition'],$identity['release_id'],$generation,$request['claim_nonce'],
		);
	};
	$cleanup=static function() use (&$active,$close,$release): void {
		$failure=null;
		foreach($active as $key=>$request){
			$close($active[$key]);
			// Closing a client socket does not prove that the root gateway reaped
			// its CGI child.  Retain that claim until its fixed expiry to prevent
			// a successor generation from overlapping still-running work.
			if(($request['eof'] ?? false)===true){
				try{$release($request);}catch(Throwable $caught){$failure ??= $caught;}
			}
			unset($active[$key]);
		}
		if($failure!==null) throw new DataphyreManagedRuntimeGenerationUnavailable(
			'Managed runtime scheduler claim cleanup failed.',0,$failure,
		);
	};
	$settle=static function(string $key,?Throwable $requestFailure=null) use (
		&$active,&$observations,&$cycleFailed,&$pendingRequests,$close,$release,$clockMilliseconds,
	): void {
		$request=$active[$key] ?? null;
		if(!is_array($request)) return;
		unset($active[$key]);
		try{
			if($requestFailure!==null) throw $requestFailure;
			if(isset($pendingRequests[$request['request_key']])){
				throw new RuntimeException('Scheduler callback receipt was not claim-consumed.');
			}
			dataphyre_runtime_scheduler_decode_callback_response($request['response']);
			$completedAtMilliseconds=$clockMilliseconds();
			if(!is_int($completedAtMilliseconds)
				|| $completedAtMilliseconds<$request['started_at_milliseconds']){
				throw new RuntimeException('Scheduler callback completion time is invalid.');
			}
			DataphyreApplicationRuntimeSchedulerState::recordSuccess(
				$request['identity'],$request['definition'],$request['identity']['release_id'],$request['generation'],
				max(1,intdiv($completedAtMilliseconds,1000)),$request['claim_nonce'],
			);
			$observations[]=[
				'name'=>$request['definition']['name'],
				'frequency_milliseconds'=>$request['definition']['frequency_milliseconds'],
				'due_at_milliseconds'=>$request['scheduled']['due_at_milliseconds'],
				'first_execution'=>$request['scheduled']['first_execution'],
				'started_at_milliseconds'=>$request['started_at_milliseconds'],
				'completed_at_milliseconds'=>$completedAtMilliseconds,
			];
		}catch(DataphyreManagedRuntimeGracefulShutdown|DataphyreManagedRuntimeGenerationUnavailable $failure){
			$close($request);
			if(($request['eof'] ?? false)===true){
				try{$release($request);}catch(Throwable $cleanupFailure){
					throw new DataphyreManagedRuntimeGenerationUnavailable(
						'Managed runtime scheduler claim cleanup failed.',0,$failure,
					);
				}
			}
			throw $failure;
		}catch(Throwable $failure){
			$close($request);
			if(($request['eof'] ?? false)===true){
				try{$release($request);}catch(Throwable $cleanupFailure){
					throw new DataphyreManagedRuntimeGenerationUnavailable(
						'Managed runtime scheduler claim cleanup failed.',0,$failure,
					);
				}
			}
			$cycleFailed=true;
			return;
		}
		$close($request);
	};

	try{
		while($dueIndex<count($due) || $active!==[]){
			dataphyre_runtime_require_not_stopping($stopRequested);
			dataphyre_runtime_require_generation_healthy($runtime);
			dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick,$activationPersister);
			while($runtime['active']===true && $dueIndex<count($due) && count($active)<$capacity){
				dataphyre_runtime_require_not_stopping($stopRequested);
				dataphyre_runtime_require_generation_healthy($runtime);
				dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick,$activationPersister);
				if($runtime['active']!==true) break;
				$scheduled=$due[$dueIndex++];$definition=$scheduled['definition'];
				$startedAtMilliseconds=$clockMilliseconds();
				if(!is_int($startedAtMilliseconds) || $startedAtMilliseconds<$scheduled['due_at_milliseconds']){
					throw new RuntimeException('Scheduler callback start time is invalid.');
				}
				$claimNonce=bin2hex(random_bytes(32));
				if(!DataphyreApplicationRuntimeSchedulerState::claim(
					$identity,$definition,$identity['release_id'],$generation,$claimNonce,
					max(1,intdiv($startedAtMilliseconds,1000)),
				)) continue;
				$claimed=[
					'definition'=>$definition,'scheduled'=>$scheduled,'claim_nonce'=>$claimNonce,
					'identity'=>$identity,'generation'=>$generation,
				];
				try{
					$request=dataphyre_runtime_scheduler_open_callback(
						$socketPath,$identity,$generation,dataphyre_runtime_next_scheduler_counter($runtime),
						$secretKey,$pendingRequests,$definition,$scheduled,$claimNonce,$startedAtMilliseconds,
					);
					$active[$request['request_key']]=$request+[
						'identity'=>$identity,'generation'=>$generation,
					];
				}catch(DataphyreManagedRuntimeGracefulShutdown|DataphyreManagedRuntimeGenerationUnavailable $failure){
					try{$release($claimed);}catch(Throwable $cleanupFailure){
						throw new DataphyreManagedRuntimeGenerationUnavailable(
							'Managed runtime scheduler claim cleanup failed.',0,$failure,
						);
					}
					throw $failure;
				}catch(Throwable $failure){
					try{$release($claimed);}catch(Throwable $cleanupFailure){
						throw new DataphyreManagedRuntimeGenerationUnavailable(
							'Managed runtime scheduler claim cleanup failed.',0,$failure,
						);
					}
					$cycleFailed=true;
				}
			}
			if($active===[]){
				if($dueIndex>=count($due) || $runtime['active']!==true) break;
				continue;
			}

			$read=[];$write=[];$except=[];$socketKeys=[];
			foreach($active as $key=>$request){
				if(microtime(true)>=$request['deadline']){
					$settle($key,new RuntimeException('Scheduler request timed out.'));continue;
				}
				$id=(int)get_resource_id($request['socket']);$socketKeys[$id]=$key;
				if($request['offset']<strlen($request['request'])) $write[]=$request['socket'];
				else $read[]=$request['socket'];
			}
			if($active===[]){
				if($dueIndex>=count($due) || $runtime['active']!==true) break;
				continue;
			}
			dataphyre_runtime_require_not_stopping($stopRequested);
			dataphyre_runtime_require_generation_healthy($runtime);
			dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick,$activationPersister);
			if(is_resource($statusListener)){
				dataphyre_runtime_serve_status($statusListener,$runtime,$pendingRequests,$publicKey);
			}
			$selected=dataphyre_runtime_scheduler_select($read,$write,$except,$stopRequested,$activationRequested);
			foreach($write as $socket){
				$key=$socketKeys[(int)get_resource_id($socket)] ?? null;
				if(!is_string($key) || !isset($active[$key])) continue;
				$remaining=substr($active[$key]['request'],$active[$key]['offset']);
				$written=@fwrite($socket,$remaining);
				if($written===false){$settle($key,new RuntimeException('Scheduler request write failed.'));continue;}
				if($written===0) continue; // EAGAIN after select; the per-task deadline remains authoritative.
				$active[$key]['offset']+=$written;
				if($active[$key]['offset']>=strlen($active[$key]['request'])) @stream_socket_shutdown($socket,STREAM_SHUT_WR);
			}
			foreach($read as $socket){
				$key=$socketKeys[(int)get_resource_id($socket)] ?? null;
				if(!is_string($key) || !isset($active[$key])) continue;
				$chunk=@fread($socket,8192);
				if($chunk===false){$settle($key,new RuntimeException('Scheduler request read failed.'));continue;}
				if($chunk!==''){
					$active[$key]['response'].=$chunk;
					if(strlen($active[$key]['response'])>DataphyreApplicationRuntimeSchedulerProtocol::MAX_TRANSPORT_BYTES){
						$settle($key,new RuntimeException('Scheduler response exceeded its bound.'));continue;
					}
				}
				if(feof($socket)){
					$active[$key]['eof']=true;
					$settle($key);
				}
			}
		}
	}catch(Throwable $failure){
		$cleanup();throw $failure;
	}
	return ['observations'=>$observations,'cycle_failed'=>$cycleFailed];
}

function dataphyre_runtime_scheduler_request(
	string $socketPath,
	string $kind,
	array $identity,
	string $generation,
    int $counter,
    string $secretKey,
    string $publicKey,
    mixed $statusListener,
	array &$runtime,
	array &$pendingRequests,
	?bool &$activationRequested,
	float &$nextTick,
		?string $schedulerName=null,
		?string $definitionSha256=null,
		?int $budgetMilliseconds=null,
		?array &$issuedEvidence=null,
		?bool &$stopRequested=null,
	): array {
		if($socketPath!==DataphyreApplicationRuntimeSchedulerGateway::SOCKET){
			throw new RuntimeException('Scheduler request transport is invalid.');
		}
		dataphyre_runtime_require_not_stopping($stopRequested);
		dataphyre_runtime_require_generation_healthy($runtime);
		$issued=DataphyreApplicationRuntimeSchedulerProtocol::issue(
		$kind,$identity,$generation,$counter,$secretKey,$schedulerName,$definitionSha256,$budgetMilliseconds,
		);
		$issuedEvidence=$issued;
	$pendingRequests[$kind.':'.$counter]=$issued;
	$body=json_encode($issued,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	$path=match($kind){
		'registration'=>'/dataphyre/runtime/scheduler/register',
		'callback'=>'/dataphyre/runtime/scheduler/callback',
		'noop'=>'/dataphyre/runtime/scheduler/noop',
	};
	$request="POST {$path} HTTP/1.1\r\nHost: dataphyre-scheduler\r\n".
		"Content-Type: application/json\r\n".
		"Connection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
	try{
		dataphyre_runtime_require_not_stopping($stopRequested);
		dataphyre_runtime_require_generation_healthy($runtime);
	}catch(Throwable $failure){
		unset($pendingRequests[$kind.':'.$counter]);
		throw $failure;
	}
	$socket=@stream_socket_client('unix://'.$socketPath,$errno,$error,2,STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) {
		unset($pendingRequests[$kind.':'.$counter]);
		dataphyre_runtime_require_not_stopping($stopRequested);
		dataphyre_runtime_require_generation_healthy($runtime);
		throw new RuntimeException('Scheduler request connection failed');
    }
    try {
        stream_set_timeout($socket,2,0);
        $offset=0;
        while ($offset<strlen($request)) {
			dataphyre_runtime_require_not_stopping($stopRequested);
			dataphyre_runtime_require_generation_healthy($runtime);
			$written=@fwrite($socket,substr($request,$offset));
			if (!is_int($written) || $written<1) {
				dataphyre_runtime_require_not_stopping($stopRequested);
				dataphyre_runtime_require_generation_healthy($runtime);
				throw new RuntimeException('Scheduler request write failed');
			}
            $offset+=$written;
        }
        stream_set_blocking($socket,false);
		$response='';
		$deadline=microtime(true)+(($budgetMilliseconds ?? 3000)/1000)+2.0;
		while (microtime(true)<$deadline) {
			dataphyre_runtime_require_not_stopping($stopRequested);
			dataphyre_runtime_require_generation_healthy($runtime);
			dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick);
			dataphyre_runtime_serve_status($statusListener,$runtime,$pendingRequests,$publicKey);
            $chunk=fread($socket,8192);
            if (is_string($chunk) && $chunk!=='') {
                $response.=$chunk;
					if (strlen($response)>DataphyreApplicationRuntimeSchedulerProtocol::MAX_TRANSPORT_BYTES) {
						throw new RuntimeException('Scheduler response exceeded its bound');
					}
            }
            if (feof($socket)) break;
            usleep(10000);
		}
		dataphyre_runtime_require_not_stopping($stopRequested);
		dataphyre_runtime_require_generation_healthy($runtime);
		if (!feof($socket)) throw new RuntimeException('Scheduler request timed out');
        [$head,$responseBody]=array_pad(explode("\r\n\r\n",$response,2),2,'');
        $status=preg_match('/^HTTP\/1\.[01]\s+(\d{3})\b/D',$head,$matches)===1 ? (int)$matches[1] : null;
        $decoded=json_decode($responseBody,true);
		if ($status===null || $status<200 || $status>=300 || !is_array($decoded)) {
			throw new RuntimeException('Scheduler request failed with HTTP status '.($status ?? 'unavailable'));
        }
		$validResponse=match($kind){
			'noop'=>($decoded['contract'] ?? null)==='dataphyre.scheduler_noop.v1'
				&& ($decoded['ok'] ?? null)===true,
			'callback'=>($decoded['contract'] ?? null)==='dataphyre.scheduler_callback.v1'
				&& ($decoded['ok'] ?? null)===true,
			'registration'=>dataphyre_runtime_scheduler_registration_valid($decoded),
		};
		if(!$validResponse) throw new RuntimeException('Scheduler response contract is invalid.');
		dataphyre_runtime_require_not_stopping($stopRequested);
		dataphyre_runtime_require_generation_healthy($runtime);
		return $decoded;
    } finally {
		if(is_resource($socket)){fclose($socket);$socket=null;}
		unset($pendingRequests[$kind.':'.$counter]);
    }
}

/** Sends an already-consumed signed request again and requires the listener to reject it. */
function dataphyre_runtime_require_scheduler_replay_rejection(
	string $socketPath,
	array $issued,
	mixed $statusListener,
	array &$runtime,
	array &$pendingRequests,
	string $publicKey,
	?bool &$activationRequested,
	float &$nextTick,
	?bool &$stopRequested=null,
): void {
	if($socketPath!==DataphyreApplicationRuntimeSchedulerGateway::SOCKET
		|| !DataphyreApplicationRuntimeSchedulerProtocol::verify($issued,$publicKey)
		|| !in_array($issued['kind'] ?? null,['registration','callback','noop'],true)){
		throw new RuntimeException('Scheduler replay evidence is invalid.');
	}
	dataphyre_runtime_require_not_stopping($stopRequested);
	dataphyre_runtime_require_generation_healthy($runtime);
	$body=json_encode($issued,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	$path=match($issued['kind']){
		'registration'=>'/dataphyre/runtime/scheduler/register',
		'callback'=>'/dataphyre/runtime/scheduler/callback',
		'noop'=>'/dataphyre/runtime/scheduler/noop',
	};
	$request="POST {$path} HTTP/1.1\r\nHost: dataphyre-scheduler\r\n".
		"Content-Type: application/json\r\n".
		"Connection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
	$socket=@stream_socket_client('unix://'.$socketPath,$errno,$error,2,STREAM_CLIENT_CONNECT);
	if(!is_resource($socket)){
		dataphyre_runtime_require_not_stopping($stopRequested);
		dataphyre_runtime_require_generation_healthy($runtime);
		throw new RuntimeException('Scheduler replay connection failed.');
	}
	try{
		stream_set_timeout($socket,2,0);
		$offset=0;
		while($offset<strlen($request)){
			dataphyre_runtime_require_not_stopping($stopRequested);
			dataphyre_runtime_require_generation_healthy($runtime);
			$written=@fwrite($socket,substr($request,$offset));
			if(!is_int($written) || $written<1){
				dataphyre_runtime_require_not_stopping($stopRequested);
				dataphyre_runtime_require_generation_healthy($runtime);
				throw new RuntimeException('Scheduler replay write failed.');
			}
			$offset+=$written;
		}
		stream_set_blocking($socket,false);
		$response='';$deadline=microtime(true)+3.0;
		while(microtime(true)<$deadline){
			dataphyre_runtime_require_not_stopping($stopRequested);
			dataphyre_runtime_require_generation_healthy($runtime);
			dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick);
			dataphyre_runtime_serve_status($statusListener,$runtime,$pendingRequests,$publicKey);
			$chunk=@fread($socket,8192);
			if(is_string($chunk) && $chunk!==''){
				$response.=$chunk;
				if(strlen($response)>16384) throw new RuntimeException('Scheduler replay response exceeded its bound.');
			}
			if(feof($socket)) break;
			usleep(10000);
		}
		dataphyre_runtime_require_not_stopping($stopRequested);
		dataphyre_runtime_require_generation_healthy($runtime);
		if(!feof($socket)) throw new RuntimeException('Scheduler replay request timed out.');
		[$head]=array_pad(explode("\r\n\r\n",$response,2),2,'');
		if(preg_match('/^HTTP\/1\.[01]\s+404\b/D',$head)!==1){
			throw new RuntimeException('Scheduler replay was not rejected.');
		}
	}finally{if(is_resource($socket)){fclose($socket);$socket=null;}}
}

/**
 * Evaluates whether completed callbacks actually fit their declared cadence.
 *
 * Successful HTTP receipts are not timing evidence. The supervisor measures the
 * real synchronous scheduler-gateway path around every callback and allows one
 * fixed scheduler tick plus one second because durable success timestamps
 * currently have second precision.
 * Dispatch start lateness remains diagnostic: queueing within the completion
 * window does not make successfully completed work unhealthy. Every callback
 * must complete before the next declared period plus the fixed grace, and work
 * completed early in a serial cycle must not miss its next recurrence before
 * that same cycle ends. Callback execution timeouts remain independently enforced.
 *
 * @param list<array{name:string,frequency_milliseconds:int,due_at_milliseconds:int,first_execution:bool,started_at_milliseconds:int,completed_at_milliseconds:int}> $observations
 * @return array{ok:bool,observation_count:int,late_start_count:int,late_completion_count:int,overdue_again_count:int,max_start_lateness_milliseconds:int,max_completion_lateness_milliseconds:int,max_recurrence_lateness_milliseconds:int}
 */
function dataphyre_runtime_scheduler_cadence_assessment(
	array $observations,
	int $cycleCompletedAtMilliseconds,
	int $intervalMilliseconds,
): array {
	if($cycleCompletedAtMilliseconds<1000 || $intervalMilliseconds<1000 || $intervalMilliseconds>60000){
		throw new RuntimeException('Scheduler cadence timing boundary is invalid.');
	}
	$lateStarts=0;$lateCompletions=0;$overdueAgain=0;
	$maxStartLateness=0;$maxCompletionLateness=0;$maxRecurrenceLateness=0;
	$graceMilliseconds=$intervalMilliseconds+1000;
	foreach($observations as $observation){
		if(!is_array($observation) || array_keys($observation)!==[
			'name','frequency_milliseconds','due_at_milliseconds','first_execution',
			'started_at_milliseconds','completed_at_milliseconds',
		] || !is_string($observation['name'] ?? null)
			|| preg_match('/^[A-Za-z0-9._-]{1,128}$/D',$observation['name'])!==1
			|| !is_int($observation['frequency_milliseconds'] ?? null)
			|| $observation['frequency_milliseconds']<0
			|| $observation['frequency_milliseconds']>2147483647
			|| !is_int($observation['due_at_milliseconds'] ?? null)
			|| !is_bool($observation['first_execution'] ?? null)
			|| !is_int($observation['started_at_milliseconds'] ?? null)
			|| !is_int($observation['completed_at_milliseconds'] ?? null)
			|| $observation['due_at_milliseconds']<1000
			|| $observation['started_at_milliseconds']<$observation['due_at_milliseconds']
			|| $observation['completed_at_milliseconds']<$observation['started_at_milliseconds']
			|| $cycleCompletedAtMilliseconds<$observation['completed_at_milliseconds']){
			throw new RuntimeException('Scheduler cadence observation is invalid.');
		}
		$cadenceMilliseconds=$observation['frequency_milliseconds']>0
			? $observation['frequency_milliseconds']
			: $intervalMilliseconds;
		$startDeadline=$observation['due_at_milliseconds']+$graceMilliseconds
			+($observation['first_execution'] ? $cadenceMilliseconds : 0);
		$completionDeadline=$observation['due_at_milliseconds']+$cadenceMilliseconds+$graceMilliseconds;
		$nextDueDeadline=$observation['completed_at_milliseconds']+$cadenceMilliseconds+$graceMilliseconds;
		$startLateness=max(0,$observation['started_at_milliseconds']-$startDeadline);
		$completionLateness=max(0,$observation['completed_at_milliseconds']-$completionDeadline);
		$recurrenceLateness=max(0,$cycleCompletedAtMilliseconds-$nextDueDeadline);
		if($startLateness>0) $lateStarts++;
		if($completionLateness>0) $lateCompletions++;
		if($recurrenceLateness>0) $overdueAgain++;
		$maxStartLateness=max($maxStartLateness,$startLateness);
		$maxCompletionLateness=max($maxCompletionLateness,$completionLateness);
		$maxRecurrenceLateness=max($maxRecurrenceLateness,$recurrenceLateness);
	}
	return [
		'ok'=>$lateCompletions===0 && $overdueAgain===0,
		'observation_count'=>count($observations),
		'late_start_count'=>$lateStarts,
		'late_completion_count'=>$lateCompletions,
		'overdue_again_count'=>$overdueAgain,
		'max_start_lateness_milliseconds'=>$maxStartLateness,
		'max_completion_lateness_milliseconds'=>$maxCompletionLateness,
		'max_recurrence_lateness_milliseconds'=>$maxRecurrenceLateness,
	];
}

/** Runs one active cadence without allowing a deactivation to schedule a second tick. */
function dataphyre_runtime_run_scheduler_cycle(
	string $socketPath,
	array $identity,
	string $generation,
	string $secretKey,
	string $publicKey,
	mixed $statusListener,
	array &$runtime,
	array &$pendingRequests,
	int $interval,
	?bool &$activationRequested,
	float &$nextTick,
	?callable $requestRunner=null,
	?callable $activationPersister=null,
	?callable $clockMilliseconds=null,
	?callable $cadenceReporter=null,
	?bool &$stopRequested=null,
): void {
	dataphyre_runtime_require_not_stopping($stopRequested);
	$startedAt=microtime(true);
	$clockMilliseconds ??= static fn(): int=>(int)floor(microtime(true)*1000);
	$cycleStartedAtMilliseconds=$clockMilliseconds();
	if(!is_int($cycleStartedAtMilliseconds) || $cycleStartedAtMilliseconds<1000){
		throw new RuntimeException('Scheduler cadence clock is invalid.');
	}
	$runtime['scheduler_cycle_in_progress']=true;
	try{
		dataphyre_runtime_require_not_stopping($stopRequested);
		$cycleFailed=false;
		$cadenceObservations=[];
		$registration=$runtime['scheduler_registration'];
		if(!dataphyre_runtime_scheduler_registration_valid($registration)){
			throw new RuntimeException('Scheduler registration evidence is invalid.');
		}
		DataphyreApplicationRuntimeSchedulerState::reconcile($identity,$registration['definitions']);
		$dueInventory=DataphyreApplicationRuntimeSchedulerState::dueScheduleInventory(
			$identity,$registration['definitions'],$cycleStartedAtMilliseconds,
			is_int($runtime['scheduler_active_since_milliseconds'] ?? null)
				? $runtime['scheduler_active_since_milliseconds'] : null,
			);
		$due=$dueInventory['schedule'];$dueCount=$dueInventory['due_count'];
		if($requestRunner===null){
			$multiplexed=dataphyre_runtime_run_scheduler_multiplexed_callbacks(
				$socketPath,$identity,$generation,$secretKey,$publicKey,$statusListener,$runtime,$pendingRequests,
				$activationRequested,$nextTick,$interval,$due,$activationPersister,$clockMilliseconds,$stopRequested,
			);
			$cadenceObservations=$multiplexed['observations'];
			$cycleFailed=$multiplexed['cycle_failed'];
		}else{
			foreach($due as $scheduled){
				dataphyre_runtime_require_not_stopping($stopRequested);
				dataphyre_runtime_require_generation_healthy($runtime);
				dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick,$activationPersister);
				dataphyre_runtime_require_not_stopping($stopRequested);
			if($runtime['active']!==true) break;
			$definition=$scheduled['definition'];
			$callbackStartedAtMilliseconds=$clockMilliseconds();
			if(!is_int($callbackStartedAtMilliseconds)
				|| $callbackStartedAtMilliseconds<$scheduled['due_at_milliseconds']){
				throw new RuntimeException('Scheduler callback start time is invalid.');
			}
			$definitionSha=DataphyreApplicationRuntimeSchedulerState::definitionSha256($definition);
			$claimNonce=bin2hex(random_bytes(32));
			if(!DataphyreApplicationRuntimeSchedulerState::claim(
				$identity,$definition,$identity['release_id'],$generation,$claimNonce,
				max(1,intdiv($callbackStartedAtMilliseconds,1000)),
			)) continue;
			try{
				dataphyre_runtime_require_not_stopping($stopRequested);
				$callbackIssued=null;
				$requestRunner(
					$socketPath,'callback',$identity,$generation,dataphyre_runtime_next_scheduler_counter($runtime),$secretKey,$publicKey,
					$statusListener,$runtime,$pendingRequests,$activationRequested,$nextTick,
					$definition['name'],$definitionSha,$definition['timeout_milliseconds'],$callbackIssued,$stopRequested,
				);
				dataphyre_runtime_require_not_stopping($stopRequested);
				$callbackCompletedAtMilliseconds=$clockMilliseconds();
				if(!is_int($callbackCompletedAtMilliseconds)
					|| $callbackCompletedAtMilliseconds<$callbackStartedAtMilliseconds){
					throw new RuntimeException('Scheduler callback completion time is invalid.');
				}
				DataphyreApplicationRuntimeSchedulerState::recordSuccess(
					$identity,$definition,$identity['release_id'],$generation,
					max(1,intdiv($callbackCompletedAtMilliseconds,1000)),$claimNonce,
				);
				$cadenceObservations[]=[
					'name'=>$definition['name'],
					'frequency_milliseconds'=>$definition['frequency_milliseconds'],
					'due_at_milliseconds'=>$scheduled['due_at_milliseconds'],
					'first_execution'=>$scheduled['first_execution'],
					'started_at_milliseconds'=>$callbackStartedAtMilliseconds,
					'completed_at_milliseconds'=>$callbackCompletedAtMilliseconds,
				];
				}catch(DataphyreManagedRuntimeGracefulShutdown $failure){
					try{
						DataphyreApplicationRuntimeSchedulerState::releaseClaim(
							$identity,$definition,$identity['release_id'],$generation,$claimNonce,
						);
					}catch(Throwable $cleanupFailure){
						throw new DataphyreManagedRuntimeGenerationUnavailable(
							'Managed runtime scheduler claim cleanup failed.',0,$failure,
						);
					}
					throw $failure;
				}catch(DataphyreManagedRuntimeGenerationUnavailable $failure){
					try{
						DataphyreApplicationRuntimeSchedulerState::releaseClaim(
							$identity,$definition,$identity['release_id'],$generation,$claimNonce,
						);
					}catch(Throwable $cleanupFailure){
						throw new DataphyreManagedRuntimeGenerationUnavailable(
							'Managed runtime generation failed and its current scheduler claim cleanup also failed.',0,$failure,
						);
					}
					throw $failure;
				}catch(Throwable $callbackFailure){
					try{
						DataphyreApplicationRuntimeSchedulerState::releaseClaim(
							$identity,$definition,$identity['release_id'],$generation,$claimNonce,
						);
					}catch(Throwable $cleanupFailure){
						throw new DataphyreManagedRuntimeGenerationUnavailable(
							'Managed runtime scheduler claim cleanup failed.',0,$callbackFailure,
						);
					}
					dataphyre_runtime_require_not_stopping($stopRequested);
					dataphyre_runtime_require_generation_healthy($runtime);
					$cycleFailed=true;
				}
		}
		}
		dataphyre_runtime_require_not_stopping($stopRequested);
		$cycleCompletedAtMilliseconds=$clockMilliseconds();
		if(!is_int($cycleCompletedAtMilliseconds) || $cycleCompletedAtMilliseconds<$cycleStartedAtMilliseconds){
			throw new RuntimeException('Scheduler cycle completion time is invalid.');
		}
		$cadence=dataphyre_runtime_scheduler_cadence_assessment(
			$cadenceObservations,$cycleCompletedAtMilliseconds,$interval*1000,
		);
		if($cadence['ok']!==true){
			$cycleFailed=true;
			$cadenceReporter ??= static function(array $evidence): void {
				$encoded=json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
				fwrite(STDERR,'Scheduler cadence deadline missed: '.$encoded."\n");
			};
			$cadenceReporter($cadence);
		}
		$priorResult=$runtime['last_result'] ?? 'never';
		$fullGreenCycle=!$cycleFailed && $dueCount>0
			&& $cadence['observation_count']===$dueCount && $cadence['ok']===true;
		if($cycleFailed) $runtime['last_result']='failed';
		elseif($fullGreenCycle) $runtime['last_result']='ok';
		elseif($dueCount===0 && !in_array($priorResult,['ok','failed'],true)) $runtime['last_result']='ok';
		else $runtime['last_result']=$priorResult;
	}catch(DataphyreManagedRuntimeGracefulShutdown $failure){throw $failure;}
	catch(DataphyreManagedRuntimeGenerationUnavailable $failure){throw $failure;}
	catch(Throwable){
		$runtime['last_result']='failed';
	}finally{
		$runtime['scheduler_cycle_in_progress']=false;
	}
	$runtime['count']++;
	$runtime['last_at']=gmdate('Y-m-d\TH:i:s\Z');
	$nextTick=$startedAt+$interval;
	if($activationRequested!==null){
		$requested=$activationRequested;
		dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick,$activationPersister);
		if($requested!==true) $nextTick=$startedAt+$interval;
	}
}

/** Persists a pending signal transition before exposing it in status or cadence. */
function dataphyre_runtime_apply_activation_request(
	array &$runtime,
	?bool &$activationRequested,
	float &$nextTick,
	?callable $persister=null,
): void {
	if($activationRequested===null) return;
	$requested=$activationRequested;
	$activationRequested=null;
	$wasActive=($runtime['active'] ?? false)===true;
	$persister ??= [DataphyreApplicationRuntimeActivationLatch::class,'persist'];
	$persister($requested);
	$runtime['active']=$requested;
	if(!$requested) $runtime['scheduler_active_since_milliseconds']=null;
	elseif(!$wasActive){
		$runtime['scheduler_active_since_milliseconds']=max(1000,(int)floor(microtime(true)*1000));
	}
	if($requested) $nextTick=microtime(true);
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))!==__FILE__) return;
foreach([
	'pcntl_async_signals','pcntl_signal','pcntl_waitpid','posix_kill','posix_setsid','posix_getpgid',
	'posix_getegid','posix_setegid','sodium_crypto_sign_keypair',
	'dataphyre_open_inherited_environment_fd','dataphyre_close_inherited_fd',
	'dataphyre_close_unlisted_inherited_fds',
] as $requiredFunction){
	if(!function_exists($requiredFunction)){
		fwrite(STDERR,"Missing required runtime function {$requiredFunction}\n");
		exit(70);
	}
}
if (getmypid() !== 1 || function_exists('posix_geteuid') && posix_geteuid() !== 0) {
	fwrite(STDERR,"The Dataphyre runtime supervisor must be container root PID 1\n");
	exit(77);
}

$stopping=false;$activationMode=null;$activationRequested=null;
pcntl_async_signals(true);
$stop=static function() use (&$stopping): void {$stopping=true;};
pcntl_signal(SIGTERM,$stop);
pcntl_signal(SIGINT,$stop);
pcntl_signal(SIGUSR1,static function() use (&$activationMode,&$activationRequested): void {
	if($activationMode==='signal') $activationRequested=true;
});
pcntl_signal(SIGUSR2,static function() use (&$activationMode,&$activationRequested): void {
	if($activationMode==='signal') $activationRequested=false;
});

$children=[];$adoptedChildren=[];
$statusListener=null;$controlSocketIdentity=null;$controlSocketDirectoryIdentity=null;
$schedulerSocketIdentity=null;$schedulerSocketDirectoryIdentity=null;
$webSocketIdentity=null;$webSocketDirectoryIdentity=null;$webSocketParentIdentity=null;
$exitCode=0;
try {
	dataphyre_runtime_require_not_stopping($stopping);
	DataphyreApplicationRuntimeEnvironment::assertCleanRootEnvironment();
	dataphyre_runtime_require_managed_web_runtime();
	dataphyre_runtime_require_not_stopping($stopping);
	$deploymentApplication=dataphyre_runtime_env('DATAPHYRE_APPLICATION_ID');
	$application=dataphyre_runtime_env('DATAPHYRE_FRAMEWORK_APPLICATION');
	$environment=dataphyre_runtime_env('DATAPHYRE_ENVIRONMENT');
	$environmentId=dataphyre_runtime_env('DATAPHYRE_APPLICATION_ENVIRONMENT_ID');
	$releaseId=dataphyre_runtime_env('DATAPHYRE_APPLICATION_RELEASE');
	$applicationEnvelope=DataphyreApplicationRuntimeEnvironment::consume(
		$deploymentApplication,$application,$environment,$environmentId,$releaseId,
	);
	dataphyre_runtime_require_not_stopping($stopping);
	$applicationEnvironment=$applicationEnvelope['values'];
    $projectRoot=realpath(dataphyre_runtime_env('DATAPHYRE_RUNTIME_PROJECT_ROOT'));
    if ($projectRoot===false || !is_dir($projectRoot)) throw new RuntimeException('Runtime project root is invalid');
	$activationMode=strtolower(dataphyre_runtime_env('DATAPHYRE_RUNTIME_ACTIVATION_MODE','active'));
    if (!in_array($activationMode,['active','signal'],true)) throw new RuntimeException('Invalid runtime activation mode');
	$webHost='127.0.0.1';$webPort=8083;
	$schedulerSocket=DataphyreApplicationRuntimeSchedulerGateway::SOCKET;
	$controlSocket='/run/dataphyre/control/runtime.sock';
	$realtimeHost='0.0.0.0';$realtimePort=8080;
	$interval=dataphyre_runtime_integer('DATAPHYRE_RUNTIME_SCHEDULER_INTERVAL_SECONDS',1,1,60);
	$uid=10001;$gid=10001;
	dataphyre_runtime_require_not_stopping($stopping);
	DataphyreApplicationRuntimeEnvironment::mountedApplicationLogRoot($uid);
	dataphyre_runtime_require_not_stopping($stopping);
	DataphyreApplicationRuntimeEnvironment::mountedSchedulerStateRoot();
	dataphyre_runtime_require_not_stopping($stopping);
	$applicationDataRoot=DataphyreApplicationRuntimeEnvironment::mountedApplicationDataRoot($uid);
	dataphyre_runtime_require_not_stopping($stopping);
	$webSocketPreparation=dataphyre_runtime_prepare_web_socket();
	$webSocketDirectoryIdentity=$webSocketPreparation['directory'];
	$webSocketParentIdentity=$webSocketPreparation['parent'];
	unset($webSocketPreparation);
	dataphyre_runtime_require_not_stopping($stopping);
	$schedulerSocketDirectoryIdentity=dataphyre_runtime_prepare_root_socket('/run/dataphyre/scheduler',$schedulerSocket);
	dataphyre_runtime_require_not_stopping($stopping);
	$router=__DIR__.'/application_runtime_router.php';
	$realtimeServer=__DIR__.'/application_runtime_realtime_server.php';

    $keypair=sodium_crypto_sign_keypair();
    $secretKey=sodium_crypto_sign_secretkey($keypair);
    $publicKey=sodium_crypto_sign_publickey($keypair);
	$childEnvironment=DataphyreApplicationRuntimeEnvironment::childEnvironment(
		$applicationEnvironment,$deploymentApplication,$application,$environment,$environmentId,$releaseId,$applicationDataRoot,
	);
	$childEnvironment['DATAPHYRE_RUNTIME_PROJECT_ROOT']=$projectRoot;
	$childEnvironment['DATAPHYRE_RUNTIME_APPLICATION']=$application;
	$childEnvironment['DATAPHYRE_RUNTIME_ENVIRONMENT']=$environment;
	$childEnvironment['DATAPHYRE_RUNTIME_WEB_HOST']=$webHost;
	$childEnvironment['DATAPHYRE_RUNTIME_WEB_PORT']=(string)$webPort;
	$childEnvironment['DATAPHYRE_RUNTIME_REALTIME_HOST']=$realtimeHost;
	$childEnvironment['DATAPHYRE_RUNTIME_REALTIME_PORT']=(string)$realtimePort;
	$childEnvironment['DATAPHYRE_SCHEDULER_ACTIVATION_MODE']='record_only';
	$childEnvironment['DATAPHYRE_RUNTIME_SCHEDULER_PUBLIC_KEY']=sodium_bin2base64($publicKey,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
	ksort($childEnvironment,SORT_STRING);

	$control=dataphyre_runtime_bind_control_socket();
	$statusListener=$control['listener'];$controlSocketIdentity=$control['identity'];
	$controlSocketDirectoryIdentity=$control['directory_identity'];
	dataphyre_runtime_require_not_stopping($stopping);
	$managedPrivateKey=random_bytes(32);
	$managedBootstraps=[];
	try{
		foreach(['realtime','scheduler','web'] as $role){
			dataphyre_runtime_require_not_stopping($stopping);
			$managedBootstraps[$role]=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext(
				$role,$projectRoot,$managedPrivateKey,
			);
		}
		dataphyre_runtime_require_not_stopping($stopping);
		$children['web']=dataphyre_runtime_spawn(
			$router,$projectRoot,'web',$webHost,$webPort,
			$childEnvironment,$managedBootstraps['web'],
		);
		dataphyre_runtime_require_not_stopping($stopping);
		$webSocketAttestation=dataphyre_runtime_wait_for_web_pool(
			$children['web']['pid'],$webSocketIdentity,$webSocketDirectoryIdentity,$stopping,
		);
		$webSocketIdentity=$webSocketAttestation['socket'];
		$webSocketDirectoryIdentity=$webSocketAttestation['directory'];
		dataphyre_runtime_require_not_stopping($stopping);
		$children['scheduler']=dataphyre_runtime_spawn(
			$router,$projectRoot,'scheduler','',0,
			$childEnvironment,$managedBootstraps['scheduler'],
		);
		dataphyre_runtime_require_not_stopping($stopping);
		$schedulerSocketIdentity=dataphyre_runtime_wait_for_scheduler_socket(
			$children['scheduler']['pid'],$schedulerSocketIdentity,$stopping,
		);
		dataphyre_runtime_require_not_stopping($stopping);
		$children['realtime']=dataphyre_runtime_spawn(
			$realtimeServer,$projectRoot,'realtime',$realtimeHost,$realtimePort,
			$childEnvironment,$managedBootstraps['realtime'],
		);
		dataphyre_runtime_require_not_stopping($stopping);
		$children['web-http-gateway']=dataphyre_runtime_spawn(
			$router,$projectRoot,'web-http-gateway',$webHost,$webPort,[],null,
		);
		dataphyre_runtime_require_not_stopping($stopping);
	}finally{
		sodium_memzero($managedPrivateKey);
		foreach($managedBootstraps as &$managedBootstrap){
			if(is_string($managedBootstrap['private_key'] ?? null)) sodium_memzero($managedBootstrap['private_key']);
		}
		unset($managedBootstrap,$managedBootstraps,$managedPrivateKey);
	}

	$identity=[
		'deployment_application'=>$deploymentApplication,
		'framework_application'=>$application,
		'environment'=>$environment,
		'release_id'=>$releaseId,
		'environment_fingerprint'=>$applicationEnvelope['environment_fingerprint'],
	];
	$generation='gen_'.bin2hex(random_bytes(16));
	$initialSchedulerActive=$activationMode==='active'
		? true
		: DataphyreApplicationRuntimeActivationLatch::restore();
	$initialSchedulerActiveSince=$initialSchedulerActive
		? max(1000,(int)floor(microtime(true)*1000))
		: null;
    $runtime=[
		'deployment_application'=>$deploymentApplication,
		'framework_application'=>$application,
		'environment'=>$environment,
		'release_id'=>$releaseId,
		'environment_fingerprint'=>$applicationEnvelope['environment_fingerprint'],
		'generation'=>$generation,
        'activation_mode'=>$activationMode,
		'active'=>$initialSchedulerActive,
		'web_fpm_pid'=>$children['web']['pid'],'web_gateway_pid'=>$children['web-http-gateway']['pid'],
		'web_socket_identity'=>$webSocketIdentity,
		'web_socket_directory_identity'=>$webSocketDirectoryIdentity,
		'scheduler_pid'=>$children['scheduler']['pid'],
		'scheduler_start_time_ticks'=>$children['scheduler']['start_time_ticks'],
		'scheduler_socket_identity'=>$schedulerSocketIdentity,
		'scheduler_socket_directory_identity'=>$schedulerSocketDirectoryIdentity,
		'control_socket_identity'=>$controlSocketIdentity,
		'control_socket_directory_identity'=>$controlSocketDirectoryIdentity,
		'realtime_pid'=>$children['realtime']['pid'],
		'realtime_start_time_ticks'=>$children['realtime']['start_time_ticks'],
		'count'=>0,'last_at'=>null,'last_result'=>'never','request_counter'=>0,
		'scheduler_active_since_milliseconds'=>$initialSchedulerActiveSince,
		'scheduler_cycle_in_progress'=>false,'scheduler_registration'=>null,
		'scheduler_noop_probe'=>null,
		'scheduler_state_identity_sha256'=>DataphyreApplicationRuntimeSchedulerState::identitySha256($identity),
		'managed_generation'=>true,'owned_children'=>$children,
    ];
    $nextTick=microtime(true);
    $lastLogged=null;
	$pendingRequests=[];
	$noopCounter=dataphyre_runtime_next_scheduler_counter($runtime);
	$noopIssued=null;
	dataphyre_runtime_scheduler_request(
		$schedulerSocket,'noop',$identity,$generation,$noopCounter,$secretKey,$publicKey,
		$statusListener,$runtime,$pendingRequests,$activationRequested,$nextTick,null,null,null,$noopIssued,$stopping,
	);
	if(!is_array($noopIssued)) throw new RuntimeException('Scheduler no-op issue evidence is unavailable.');
	dataphyre_runtime_require_scheduler_replay_rejection(
		$schedulerSocket,$noopIssued,$statusListener,$runtime,$pendingRequests,$publicKey,$activationRequested,$nextTick,
		$stopping,
	);
	$probeState=DataphyreApplicationRuntimeProbeState::record($identity,time());
	$runtime['scheduler_noop_probe']=[
		'contract'=>'dataphyre.scheduler_noop_probe.v2','ok'=>true,
		'generation'=>$generation,'request_counter'=>$noopCounter,
		'claim_consumed'=>true,'worker_receipt'=>true,'worker_reaped'=>true,'replay_suppressed'=>true,
		'count'=>$probeState['count'],'last_at'=>$probeState['last_at'],
		'previous_readback'=>$probeState['previous_readback'],
		'state_identity_sha256'=>$probeState['state_identity_sha256'],
	];
	$registrationIssued=null;
	$registration=dataphyre_runtime_scheduler_request(
		$schedulerSocket,'registration',$identity,$generation,dataphyre_runtime_next_scheduler_counter($runtime),
		$secretKey,$publicKey,$statusListener,$runtime,$pendingRequests,$activationRequested,$nextTick,
		null,null,null,$registrationIssued,$stopping,
	);
	$runtime['scheduler_registration']=$registration;
    while (!$stopping) {
		dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick);
		dataphyre_runtime_reap_adopted_children(array_column($children,'pid'),$adoptedChildren);
		dataphyre_runtime_require_generation_healthy($runtime);
		dataphyre_runtime_serve_status($statusListener,$runtime,$pendingRequests,$publicKey);
		$now=microtime(true);
		if ($runtime['active'] && $now>=$nextTick) {
			dataphyre_runtime_run_scheduler_cycle(
				$schedulerSocket,$identity,$generation,$secretKey,$publicKey,$statusListener,
				$runtime,$pendingRequests,$interval,$activationRequested,$nextTick,stopRequested:$stopping,
			);
        }
        $logKey=json_encode([
			$runtime['active'],$runtime['scheduler_cycle_in_progress'],$runtime['count'],$runtime['last_result'],
		],JSON_THROW_ON_ERROR);
        if ($logKey!==$lastLogged) {
			try{$statusForLog=dataphyre_runtime_status($runtime);}
			catch(DataphyreManagedWebInventoryUnavailable){usleep(10000);continue;}
            fwrite(STDOUT,json_encode($statusForLog,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
            fflush(STDOUT);
            $lastLogged=$logKey;
        }
        usleep(50000);
	}
} catch (DataphyreManagedRuntimeGracefulShutdown) {
	$exitCode=0;
} catch (Throwable $failure) {
    fwrite(STDERR,$failure->getMessage()."\n");
    if ($exitCode===0) $exitCode=70;
} finally {
    if (is_resource($statusListener)) fclose($statusListener);
	foreach ($children as $child) dataphyre_runtime_signal_child($child,SIGTERM);
    $deadline=microtime(true)+5.0;
    foreach ($children as $child) {
        while (microtime(true)<$deadline) {
            $status=proc_get_status($child['resource']);
            if (!is_array($status) || ($status['running'] ?? false)!==true) break;
            usleep(50000);
        }
        $status=proc_get_status($child['resource']);
		if (is_array($status) && ($status['running'] ?? false)===true) dataphyre_runtime_signal_child($child,SIGKILL);
        proc_close($child['resource']);
    }
	$adoptedDeadline=microtime(true)+1.0;
	do{
		dataphyre_runtime_reap_adopted_children([],$adoptedChildren);
		if($adoptedChildren===[]) break;
		usleep(10000);
	}while(microtime(true)<$adoptedDeadline);
	dataphyre_runtime_cleanup_root_socket(
		'/run/dataphyre/scheduler','/run/dataphyre/scheduler/gateway.sock',
		$schedulerSocketIdentity,$schedulerSocketDirectoryIdentity,
	);
	dataphyre_runtime_cleanup_root_socket(
		'/run/dataphyre/control','/run/dataphyre/control/runtime.sock',
		$controlSocketIdentity,$controlSocketDirectoryIdentity,
	);
	dataphyre_runtime_cleanup_web_socket($webSocketIdentity,$webSocketDirectoryIdentity,$webSocketParentIdentity);
}
exit($exitCode);
