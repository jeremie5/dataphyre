<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli' || ($argc ?? 0)!==3) exit(64);
[$script,$kernel,$stateRoot]=$argv;
if(!is_string($kernel) || !is_string($stateRoot) || is_link($kernel) || !is_dir($kernel)
	|| !hash_equals($kernel,(string)realpath($kernel)) || is_link($stateRoot) || !is_dir($stateRoot)
	|| !hash_equals($stateRoot,(string)realpath($stateRoot))){
	exit(64);
}
require_once $kernel.'/application_runtime_scheduler_gateway.php';
require_once $kernel.'/application_runtime_supervisor.php';
if(!function_exists('dataphyre_enable_scheduler_child_subreaper')
	|| dataphyre_enable_scheduler_child_subreaper()!==true){
	exit(70);
}

$scheduler=(new ReflectionClass(DataphyreApplicationRuntimeSchedulerGateway::class))->getMethod('terminateAdoptedChildren');
$broker=(new ReflectionClass(DataphyreApplicationRuntimeProcessBroker::class))->getMethod('cleanupEstablishedProcessGroup');
$waitAbsent=static function(array $pids,float $seconds=1.0): bool {
	$deadline=microtime(true)+$seconds;
	do{
		$remaining=array_values(array_filter($pids,static fn(int $pid): bool=>file_exists('/proc/'.$pid)));
		if($remaining===[]) return true;
		usleep(10000);
	}while(microtime(true)<$deadline);
	return false;
};

$escaped=[];$spawned=null;$pipes=[];$supervisorOrphanPid=null;
try{
	for($index=0;$index<16;$index++){
		$path=$stateRoot.'/escaped-'.$index.'.json';$parent=pcntl_fork();
		if($parent===-1) throw new RuntimeException('Subreaper probe could not fork its intermediate child.');
		if($parent===0){
			$descendant=pcntl_fork();
			if($descendant===-1) exit(71);
			if($descendant===0){
				if(posix_setsid()<1) exit(72);
				pcntl_async_signals(true);pcntl_signal(SIGTERM,SIG_IGN);pcntl_signal(SIGINT,SIG_IGN);pcntl_signal(SIGHUP,SIG_IGN);
				foreach([STDIN,STDOUT,STDERR] as $stream) if(is_resource($stream)) fclose($stream);
				file_put_contents($path,json_encode([
					'pid'=>getmypid(),'parent_pid'=>posix_getppid(),'process_group_id'=>posix_getpgid(0),'session_id'=>posix_getsid(0),
				],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
				while(true) usleep(100000);
			}
			exit(0);
		}
		pcntl_waitpid($parent,$status);
		if(!pcntl_wifexited($status) || pcntl_wexitstatus($status)!==0) throw new RuntimeException('Subreaper intermediate failed.');
		$deadline=microtime(true)+2.0;while(!is_file($path) && microtime(true)<$deadline) usleep(10000);
		if(!is_file($path)) throw new RuntimeException('Escaped descendant evidence is unavailable.');
		$evidence=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
		$escaped[]=$evidence['pid'];
		$scheduler->invoke(null,[]);
		if(!$waitAbsent([$evidence['pid']])) throw new RuntimeException('Escaped descendant was not reaped.');
	}

	$supervisorOrphanPath=$stateRoot.'/supervisor-orphan.pid';$intermediate=pcntl_fork();
	if($intermediate===-1) throw new RuntimeException('Supervisor orphan reaper probe could not fork its intermediate child.');
	if($intermediate===0){
		$orphan=pcntl_fork();
		if($orphan===-1) exit(73);
		if($orphan===0){
			file_put_contents($supervisorOrphanPath,(string)getmypid(),LOCK_EX);exit(0);
		}
		exit(0);
	}
	pcntl_waitpid($intermediate,$intermediateStatus);
	if(!pcntl_wifexited($intermediateStatus) || pcntl_wexitstatus($intermediateStatus)!==0){
		throw new RuntimeException('Supervisor orphan reaper intermediate failed.');
	}
	$deadline=microtime(true)+2.0;while(!is_file($supervisorOrphanPath) && microtime(true)<$deadline) usleep(10000);
	$supervisorOrphanPid=is_file($supervisorOrphanPath) ? (int)trim((string)file_get_contents($supervisorOrphanPath)) : 0;
	if($supervisorOrphanPid<2) throw new RuntimeException('Supervisor orphan reaper identity is unavailable.');
	$supervisorTracked=[];$deadline=microtime(true)+2.0;
	do{
		dataphyre_runtime_reap_adopted_children([],$supervisorTracked);
		if(!file_exists('/proc/'.$supervisorOrphanPid) && $supervisorTracked===[]) break;
		usleep(10000);
	}while(microtime(true)<$deadline);
	if(file_exists('/proc/'.$supervisorOrphanPid) || $supervisorTracked!==[]){
		throw new RuntimeException('Supervisor orphan was not reaped through the production helper.');
	}

	$pidPath=$stateRoot.'/leader-fork-exit.pid';
	$process=proc_open([ // dataphyre-test-architecture: exempt[raw-process-control] reason="The subreaper race proof needs an exact setsid leader that exits while its descendant remains."
		'/usr/bin/setsid',
		'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
		'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
		PHP_BINARY,__DIR__.'/application_runtime_process_broker_input.php',$kernel,'raw-fork-exit',$pidPath,
	],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname($kernel,4),[],[
		'bypass_shell'=>true,'suppress_errors'=>true,
	]);
	if(!is_resource($process)) throw new RuntimeException('Post-leader-exit process group could not start.');
	$status=proc_get_status($process);$leaderPid=(int)($status['pid'] ?? 0);$leaderIdentity=null;$deadline=microtime(true)+2.0;
	do{
		try{$leaderIdentity=DataphyreApplicationRuntimeChildEnvironment::processIdentity($leaderPid);}catch(Throwable){}
		if(is_array($leaderIdentity) && @posix_getpgid($leaderPid)===$leaderPid) break;
		usleep(10000);
	}while(microtime(true)<$deadline);
	if(!is_array($leaderIdentity) || @posix_getpgid($leaderPid)!==$leaderPid){
		throw new RuntimeException('Post-leader-exit process group identity is unavailable.');
	}
	$spawned=[
		'resource'=>$process,'pid'=>$leaderPid,'start_time_ticks'=>$leaderIdentity['start_time_ticks'],
		'process_group_id'=>$leaderPid,
	];
	$descendantPath=$pidPath.'.descendant';$deadline=microtime(true)+2.0;
	while(!is_file($descendantPath) && microtime(true)<$deadline) usleep(10000);
	if(!is_file($descendantPath)) throw new RuntimeException('Post-ack descendant evidence is unavailable.');
	$descendant=json_decode((string)file_get_contents($descendantPath),true,8,JSON_THROW_ON_ERROR);
	$leaderDeadline=microtime(true)+2.0;
	do{$status=proc_get_status($spawned['resource']);if(($status['running'] ?? false)!==true) break;usleep(10000);}
	while(microtime(true)<$leaderDeadline);
	$broker->invoke(null,[
		'pid'=>$spawned['pid'],'start_time_ticks'=>$spawned['start_time_ticks'],
		'process_group_id'=>$spawned['process_group_id'],
	]);
	foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);$pipes=[];
	proc_close($spawned['resource']);$scheduler->invoke(null,[]);
	if(!$waitAbsent([$spawned['pid'],$descendant['pid']])) throw new RuntimeException('Post-ack process group was not reaped.');
	$spawned=null;
	echo json_encode([
		'contract'=>'dataphyre.scheduler_subreaper_probe.v1','ok'=>true,
		'escaped_reaped_count'=>count($escaped),'post_leader_exit_group_reaped'=>true,
		'supervisor_orphan_reaped'=>true,
	],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $failure){
	fwrite(STDERR,$failure->getMessage()."\n");exit(70);
}finally{
	foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);
	if(is_array($spawned)){
		if(is_int($spawned['process_group_id'] ?? null)) @posix_kill(-$spawned['process_group_id'],SIGKILL);
		@proc_close($spawned['resource']);
	}
	foreach($escaped as $pid) if(is_int($pid) && $pid>1) @posix_kill($pid,SIGKILL);
	if(is_int($supervisorOrphanPid) && $supervisorOrphanPid>1) @posix_kill($supervisorOrphanPid,SIGKILL);
}
