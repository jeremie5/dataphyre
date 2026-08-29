<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/application_runtime_child_environment.php';

/** Spawns one final exec and completes its single-use environment handshake. */
final class DataphyreApplicationRuntimeProcessBroker
{
	private const PRE_EXEC='application_runtime_pre_exec.php';
	private const MAX_STANDARD_INPUT_BYTES=268435456;
	private const WRITE_CHUNK_BYTES=65536;

	/**
	 * @param list<string> $command
	 * @param array<int,mixed> $descriptors
	 * @param array<string,string> $publicEnvironment
	 * @param array<string,string> $applicationEnvironment
	 * @param null|array<string,string> $managedBootstrap Reserved framework context, never application values.
	 * @param null|string $standardInput Fixed child input delivered and closed before the environment acknowledgement.
	 * @param bool $newSession Starts the final child as its own process-group/session leader.
	 * @return array{resource:resource,pid:int,pipes:array<int,resource>,identity:array,process_group_id:?int}
	 */
	public static function spawn(
		array $command,
		array $descriptors,
		string $workingDirectory,
		array $publicEnvironment,
		string $role,
		array $applicationEnvironment,
		int $timeoutMilliseconds=5000,
		?array $managedBootstrap=null,
		?string $standardInput=null,
		bool $newSession=false,
	): array {
		if(!function_exists('posix_geteuid') || posix_geteuid()!==0 || $command===[]
			|| !is_string($command[0] ?? null) || !str_starts_with($command[0],'/')
			|| !is_dir($workingDirectory) || is_link($workingDirectory)
			|| isset($descriptors[DataphyreApplicationRuntimeChildEnvironment::INHERITED_FD])
			|| $timeoutMilliseconds<100 || $timeoutMilliseconds>30000
			|| ($standardInput!==null && strlen($standardInput)>self::MAX_STANDARD_INPUT_BYTES)){
			throw new RuntimeException('Application process broker invocation is invalid.');
		}
		if($standardInput!==null && ($descriptors[0] ?? null)!==['pipe','r']){
			throw new RuntimeException('Application process broker standard input descriptor is invalid.');
		}
		$preExec=__DIR__.'/'.self::PRE_EXEC;
		if(!function_exists('dataphyre_close_unlisted_inherited_fds') || is_link($preExec)
			|| !is_file($preExec) || !hash_equals($preExec,(string)realpath($preExec))){
			throw new RuntimeException('Application process broker descriptor boundary is unavailable.');
		}
		foreach($command as $argument){
			if(!is_string($argument) || str_contains($argument,"\0")) throw new RuntimeException('Application process argument is invalid.');
		}
		self::validatePublicEnvironment($publicEnvironment);
		[$brokerChannel,$childChannel]=DataphyreApplicationRuntimeChildEnvironment::socketPair();
		$descriptors[DataphyreApplicationRuntimeChildEnvironment::INHERITED_FD]=$childChannel;
		ksort($descriptors,SORT_NUMERIC);
		$pipes=[];$process=null;$cleanupTarget=null;
		try{
			$preExecCommand=[PHP_BINARY,$preExec];
			if($newSession) $preExecCommand[]='--dataphyre-new-session';
			$process=@proc_open(
				[...$preExecCommand,...$command],$descriptors,$pipes,$workingDirectory,$publicEnvironment,
				['bypass_shell'=>true,'suppress_errors'=>true],
				);
				@fclose($childChannel);
				if(!is_resource($process)) throw new RuntimeException('Application process could not be started.');
				$status=proc_get_status($process);$pid=(int)($status['pid'] ?? 0);
				if($pid<2) throw new RuntimeException('Application process identity is unavailable.');
				if($newSession) $cleanupTarget=self::establishProcessGroup($process,$pid,$timeoutMilliseconds);
				if($standardInput!==null){
					self::writeStandardInput($process,$pipes,$standardInput,$timeoutMilliseconds);
				}
			$identity=DataphyreApplicationRuntimeChildEnvironment::broker(
				$brokerChannel,$pid,getmypid(),$role,$applicationEnvironment,$timeoutMilliseconds,$managedBootstrap,
			);
				return [
					'resource'=>$process,'pid'=>$pid,'pipes'=>$pipes,'identity'=>$identity,
					'process_group_id'=>$cleanupTarget['process_group_id'] ?? null,
				];
		}catch(Throwable $failure){
			if(is_resource($brokerChannel)) @fclose($brokerChannel);
			if(is_resource($childChannel)) @fclose($childChannel);
			$cleanupFailure=null;
			if(is_resource($process)){
				try{
					if(is_array($cleanupTarget)) self::cleanupEstablishedProcessGroup($cleanupTarget);
					else{
						$status=proc_get_status($process);
						if(is_array($status) && ($status['running'] ?? false)===true && (int)($status['pid'] ?? 0)>1){
							@posix_kill((int)$status['pid'],SIGKILL);
						}
					}
				}catch(Throwable $caught){$cleanupFailure=$caught;}
			}
			foreach($pipes as $pipe) if(is_resource($pipe)) @fclose($pipe);
			if(is_resource($process)) @proc_close($process);
			if($cleanupFailure!==null){
				throw new RuntimeException('Application process broker cleanup failed: '.$cleanupFailure->getMessage(),0,$failure);
			}
			throw $failure;
		}
	}

	/** @return array{pid:int,start_time_ticks:string,process_group_id:int} */
	private static function establishProcessGroup(mixed $process,int $pid,int $timeoutMilliseconds): array
	{
		if(!function_exists('posix_getpgid')) throw new RuntimeException('Application process-group support is unavailable.');
		$deadline=hrtime(true)+($timeoutMilliseconds*1_000_000);
		do{
			$status=proc_get_status($process);
			if(!is_array($status) || ($status['running'] ?? false)!==true || (int)($status['pid'] ?? 0)!==$pid){
				throw new RuntimeException('Application process exited before establishing its fixed process group.');
			}
			if(@posix_getpgid($pid)===$pid){
				$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity($pid);
				if(($identity['parent_pid'] ?? null)!==getmypid()){
					throw new RuntimeException('Application process-group parent identity is invalid.');
				}
				return ['pid'=>$pid,'start_time_ticks'=>$identity['start_time_ticks'],'process_group_id'=>$pid];
			}
			usleep(1000);
		}while(hrtime(true)<$deadline);
		throw new RuntimeException('Application process did not establish its fixed process group.');
	}

	/** @param array{pid:int,start_time_ticks:string,process_group_id:int} $target */
	private static function cleanupEstablishedProcessGroup(array $target): void
	{
		$pid=$target['pid'] ?? null;$start=$target['start_time_ticks'] ?? null;$group=$target['process_group_id'] ?? null;
		if(!is_int($pid) || $pid<2 || !is_string($start) || preg_match('/^[0-9]+$/D',$start)!==1
			|| !is_int($group) || $group!==$pid){
			throw new RuntimeException('Application process-group cleanup target is invalid.');
		}
		try{$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity($pid);}
		catch(Throwable){$identity=null;}
		if(is_array($identity) && !hash_equals($start,(string)($identity['start_time_ticks'] ?? ''))){
			throw new RuntimeException('Application process-group cleanup identity changed.');
		}
		if(self::runnableProcessGroupMembers($group)!==[]) @posix_kill(-$group,SIGKILL);
		$deadline=microtime(true)+0.5;
		while(self::runnableProcessGroupMembers($group)!==[] && microtime(true)<$deadline) usleep(10000);
		if(self::runnableProcessGroupMembers($group)!==[]){
			throw new RuntimeException('Application process group survived broker cleanup.');
		}
	}

	/** @return list<int> */
	private static function runnableProcessGroupMembers(int $group): array
	{
		$entries=@scandir('/proc');
		if(!is_array($entries) || count($entries)>65536){
			throw new RuntimeException('Application process-group inventory is unavailable.');
		}
		$members=[];
		foreach($entries as $entry){
			if(!ctype_digit($entry) || ($pid=(int)$entry)<2) continue;
			$stat=@file_get_contents('/proc/'.$entry.'/stat');$separator=is_string($stat) ? strrpos($stat,') ') : false;
			if(!is_int($separator)) continue;
			$fields=explode(' ',substr($stat,$separator+2));
			if((int)($fields[2] ?? 0)===$group && !in_array($fields[0] ?? '',['Z','X'],true)) $members[]=$pid;
		}
		sort($members,SORT_NUMERIC);return $members;
	}

	/** @param array<int,resource> $pipes */
	private static function writeStandardInput(mixed $process,array &$pipes,string $bytes,int $timeoutMilliseconds): void
	{
		$stream=$pipes[0] ?? null;
		if(!is_resource($process) || !is_resource($stream) || !stream_set_blocking($stream,false)){
			throw new RuntimeException('Application process standard input is unavailable.');
		}
		$length=strlen($bytes);$offset=0;$deadline=hrtime(true)+($timeoutMilliseconds*1_000_000);
		try{
			while($offset<$length){
				$status=proc_get_status($process);
				if(!is_array($status) || ($status['running'] ?? false)!==true){
					throw new RuntimeException('Application process exited before accepting its standard input.');
				}
				$remaining=$deadline-hrtime(true);
				if($remaining<=0) throw new RuntimeException('Application process standard input timed out.');
				$write=[$stream];$read=[];$except=[];
				$seconds=intdiv($remaining,1_000_000_000);$microseconds=intdiv($remaining%1_000_000_000,1000);
				$selected=@stream_select($read,$write,$except,$seconds,$microseconds);
				if($selected===false) continue;
				if($selected===0) throw new RuntimeException('Application process standard input timed out.');
				$written=@fwrite($stream,substr($bytes,$offset,self::WRITE_CHUNK_BYTES));
				if(!is_int($written) || $written<1){
					$status=proc_get_status($process);
					if(!is_array($status) || ($status['running'] ?? false)!==true){
						throw new RuntimeException('Application process exited before accepting its standard input.');
					}
					throw new RuntimeException('Application process standard input write failed.');
				}
				$offset+=$written;
			}
		}finally{
			if(is_resource($stream)) @fclose($stream);
			unset($pipes[0]);
		}
	}

	/** @param array<string,mixed> $environment */
	private static function validatePublicEnvironment(array $environment): void
	{
		if(count($environment)>128) throw new RuntimeException('Application process public environment is invalid.');
		foreach($environment as $name=>$value){
			if(!is_string($name) || preg_match('/^[A-Z][A-Z0-9_]{0,119}$/D',$name)!==1 || !is_string($value)
				|| strlen($value)>8192 || preg_match('/[\x00-\x1f\x7f]/D',$value)===1){
				throw new RuntimeException('Application process public environment is invalid.');
			}
		}
	}
}
