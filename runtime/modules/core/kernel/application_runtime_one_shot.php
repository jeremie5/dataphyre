<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Fixed PID 1 launcher for one-off application release operations. */

if(PHP_SAPI!=='cli' || getmypid()!==1 || !function_exists('posix_geteuid') || posix_geteuid()!==0
		|| !function_exists('posix_getpgid') || !function_exists('posix_kill')
		|| !function_exists('pcntl_waitpid') || !function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')
		|| !function_exists('dataphyre_open_inherited_environment_fd')
		|| !function_exists('dataphyre_close_inherited_fd')
		|| !function_exists('dataphyre_close_unlisted_inherited_fds')){
	exit(77);
}
require_once __DIR__.'/application_runtime_environment.php';
require_once __DIR__.'/application_runtime_process_broker.php';
require_once dirname(__DIR__).'/Release/ApplicationReleasePreflightEvidence.php';
DataphyreApplicationRuntimeEnvironment::assertCleanRootEnvironment();

const DATAPHYRE_ONE_SHOT_DATABASE_IDENTITY_MAXIMUM_MILLISECONDS=30000;
const DATAPHYRE_ONE_SHOT_CACHE_MAXIMUM_MILLISECONDS=10000;
const DATAPHYRE_ONE_SHOT_MIGRATION_MAXIMUM_MILLISECONDS=180000;
const DATAPHYRE_ONE_SHOT_TERMINATE_GRACE_SECONDS=0.5;
const DATAPHYRE_ONE_SHOT_KILL_REAP_SECONDS=0.5;
const DATAPHYRE_ONE_SHOT_POLL_MICROSECONDS=10000;

/** Reads one required root process identity without accepting whitespace aliases. */
function dataphyre_one_shot_identity(string $name,string $pattern): string
{
	$value=getenv($name);
	if(!is_string($value) || preg_match($pattern,$value)!==1) throw new RuntimeException('One-shot identity is invalid.');
	return $value;
}

/** Reads the deployment environment through the shared public identifier authority. */
function dataphyre_one_shot_environment(): string
{
	$value=getenv('DATAPHYRE_ENVIRONMENT');
	if(!is_string($value) || !\Dataphyre\ApplicationEnvironmentIdentifier::valid($value)){
		throw new RuntimeException('One-shot environment is invalid.');
	}
	return $value;
}

/** Reads the opaque public application id through its shared authority. */
function dataphyre_one_shot_cloud_application(): string
{
	$value=getenv('DATAPHYRE_APPLICATION_ID');
	if(!is_string($value) || !\Dataphyre\PublicApplicationIdentifier::valid($value)){
		throw new RuntimeException('One-shot public application identity is invalid.');
	}
	return $value;
}

/** Proves one immutable image-owned regular file at its exact public path. */
function dataphyre_one_shot_file(string $path): string
{
	if(is_link($path) || !is_file($path) || !is_readable($path)) throw new RuntimeException('One-shot executable is unavailable.');
	$resolved=realpath($path);$stat=lstat($path);
	if(!is_string($resolved) || !hash_equals($path,$resolved) || !is_array($stat)
		|| (($stat['mode'] ?? 0)&0170000)!==0100000 || (($stat['mode'] ?? 0)&0022)!==0
		|| ($stat['uid'] ?? -1)!==0 || ($stat['gid'] ?? -1)!==0 || ($stat['nlink'] ?? 0)!==1){
		throw new RuntimeException('One-shot executable identity is invalid.');
	}
	return $resolved;
}

/** Derives one operation deadline from the fixed public preflight and migration stage maxima. */
function dataphyre_one_shot_operation_maximum_milliseconds(string $operation): int
{
	return match($operation){
		'database_identity'=>DATAPHYRE_ONE_SHOT_DATABASE_IDENTITY_MAXIMUM_MILLISECONDS,
		'dataphyre_shared_cache_probe'=>DATAPHYRE_ONE_SHOT_CACHE_MAXIMUM_MILLISECONDS,
		'application_preflight'=>\Dataphyre\Release\ApplicationReleasePreflightEvidence::COMMAND_TIMEOUT_MILLISECONDS,
		'artisan_migrate','dataphyre_materialize_tables','dataphyre_postgresql_migrate','dataphyre_sqlite_migrate'
			=>DATAPHYRE_ONE_SHOT_MIGRATION_MAXIMUM_MILLISECONDS,
	};
}

/** Reaps every terminated orphan adopted by the one-shot PID 1 until the fixed cleanup deadline. */
function dataphyre_one_shot_reap(float $deadline): void
{
	do{
		$result=pcntl_waitpid(-1,$status,WNOHANG);
		while($result>0) $result=pcntl_waitpid(-1,$status,WNOHANG);
		if($result===-1) break;
		usleep(DATAPHYRE_ONE_SHOT_POLL_MICROSECONDS);
	}while(microtime(true)<$deadline);
}

$process=null;$processGroup=null;
try{
	$operation=dataphyre_one_shot_identity(
		'DATAPHYRE_ONE_SHOT_OPERATION',
		'/^(?:database_identity|application_preflight|artisan_migrate|dataphyre_materialize_tables|dataphyre_postgresql_migrate|dataphyre_sqlite_migrate|dataphyre_shared_cache_probe)$/D',
	);
	$cloudApplication=dataphyre_one_shot_cloud_application();
	$frameworkApplication=dataphyre_one_shot_identity('DATAPHYRE_FRAMEWORK_APPLICATION','/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D');
	$environment=dataphyre_one_shot_environment();
	$releaseId=dataphyre_one_shot_identity('DATAPHYRE_APPLICATION_RELEASE','/^dep_[a-f0-9]{40}$/D');
	$uid=10001;$gid=10001;
	$envelope=DataphyreApplicationRuntimeEnvironment::consume(
		$cloudApplication,$frameworkApplication,$environment,$releaseId,
	);
	DataphyreApplicationRuntimeEnvironment::mountedApplicationLogRoot($uid);
	$applicationDataRoot=in_array($operation,['dataphyre_materialize_tables','dataphyre_sqlite_migrate'],true)
		? DataphyreApplicationRuntimeEnvironment::mountedApplicationDataRoot($uid)
		: null;
	if($operation==='dataphyre_sqlite_migrate' && $applicationDataRoot===null){
		throw new RuntimeException('One-shot SQLite data mount is unavailable.');
	}
	$child=DataphyreApplicationRuntimeEnvironment::childEnvironment(
		$envelope['values'],$cloudApplication,$frameworkApplication,$environment,$releaseId,$applicationDataRoot,
	);
	$child['DATAPHYRE_RUNTIME_PROJECT_ROOT']='/app';
	$purpose=null;
	$cachePhase=null;$cacheChallenge=null;
	if($operation==='database_identity'){
		$purpose=dataphyre_one_shot_identity('DATAPHYRE_ONE_SHOT_DATABASE_PURPOSE','/^[a-z][a-z0-9_]{0,31}$/D');
	}elseif(in_array($operation,['dataphyre_materialize_tables','dataphyre_postgresql_migrate'],true)){
		if(getenv('DATAPHYRE_ONE_SHOT_DATABASE_PURPOSE')!==false){
			$purpose=dataphyre_one_shot_identity('DATAPHYRE_ONE_SHOT_DATABASE_PURPOSE','/^[a-z][a-z0-9_]{0,31}$/D');
		}
	}elseif(getenv('DATAPHYRE_ONE_SHOT_DATABASE_PURPOSE')!==false){
		throw new RuntimeException('One-shot database purpose is not allowed for this operation.');
	}
	if($purpose!==null && $operation!=='database_identity'){
		$child=DataphyreApplicationRuntimeEnvironment::projectManagedDatabasePurpose($child,$purpose);
		if($operation==='dataphyre_materialize_tables'){
			$child[DataphyreApplicationRuntimeChildEnvironment::ONE_SHOT_MATERIALIZER_DATABASE_PURPOSE]=$purpose;
			ksort($child,SORT_STRING);
		}
	}
	if($operation==='dataphyre_shared_cache_probe'){
		$cachePhase=dataphyre_one_shot_identity('DATAPHYRE_ONE_SHOT_CACHE_PHASE','/^(?:detect|write|read-delete)$/D');
		$cacheChallenge=dataphyre_one_shot_identity('DATAPHYRE_ONE_SHOT_CACHE_CHALLENGE','/^[a-f0-9]{64}$/D');
	}elseif(getenv('DATAPHYRE_ONE_SHOT_CACHE_PHASE')!==false || getenv('DATAPHYRE_ONE_SHOT_CACHE_CHALLENGE')!==false){
		throw new RuntimeException('One-shot cache controls are not allowed for this operation.');
	}

	$runtimeRoot=realpath(dirname(__DIR__,3));
	if(!is_string($runtimeRoot)) throw new RuntimeException('One-shot runtime root is unavailable.');
	$dispatch=match($operation){
		'database_identity'=>[
			dataphyre_one_shot_file(__DIR__.'/application_runtime_database_identity.php'),
			['--purpose='.$purpose],
		],
		'application_preflight'=>[
			dataphyre_one_shot_file(__DIR__.'/application_release_preflight.php'),
			['--project-root=/app','--application='.$frameworkApplication,'--environment='.$environment],
		],
		'artisan_migrate'=>[
			dataphyre_one_shot_file('/app/artisan'),
			['migrate','--force','--no-interaction'],
		],
		'dataphyre_materialize_tables'=>[
			dataphyre_one_shot_file($runtimeRoot.'/modules/sql/kernel/materialize_registered_tables.php'),
			['--project-root=/app','--application='.$frameworkApplication,'--environment='.$environment],
		],
		'dataphyre_postgresql_migrate'=>[
			dataphyre_one_shot_file($runtimeRoot.'/modules/sql/kernel/postgresql_migrate.php'),
			['--project-root=/app','--app='.$frameworkApplication,'--environment='.$environment,'--mode=automatic'],
		],
		'dataphyre_sqlite_migrate'=>[
			dataphyre_one_shot_file($runtimeRoot.'/modules/sql/kernel/sqlite_migrate.php'),
			['--project-root=/app','--app='.$frameworkApplication,'--environment='.$environment],
		],
		'dataphyre_shared_cache_probe'=>[
			dataphyre_one_shot_file($runtimeRoot.'/modules/cache/kernel/shared_cache_probe.php'),
			['--phase='.$cachePhase,'--challenge='.$cacheChallenge],
		],
	};
	$setsid=dataphyre_one_shot_file('/usr/bin/setsid');
	$setpriv=dataphyre_one_shot_file('/usr/bin/setpriv');
	$worker=dataphyre_one_shot_file(__DIR__.'/application_runtime_one_shot_worker.php');
	$command=[
		$setsid,
		$setpriv,
		'--reuid='.$uid,'--regid='.$gid,'--groups='.$gid,'--no-new-privs',
		'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGTERM',
		PHP_BINARY,
		'-d','display_errors=0','-d','log_errors=1','-d','expose_php=0',
		'-d','user_ini.filename=','-d','auto_prepend_file=','-d','auto_append_file=',
		$worker,$operation,$dispatch[0],...$dispatch[1],
	];
	$pendingSignal=null;
	pcntl_async_signals(true);
	$rememberSignal=static function(int $signal) use (&$pendingSignal): void {
		if($pendingSignal===null) $pendingSignal=$signal;
	};
	pcntl_signal(SIGTERM,$rememberSignal);pcntl_signal(SIGINT,$rememberSignal);
	$process=DataphyreApplicationRuntimeProcessBroker::spawn(
		$command,[0=>['file','/dev/null','r'],1=>['file','php://stdout','a'],2=>['file','php://stderr','a']],
		'/app',[],'one-shot',$child,10000,
	);
	$processGroup=$process['pid'];
	foreach($child as &$value) sodium_memzero($value);unset($value,$child);
	$observedExit=null;$processGroupProven=false;
	$terminationReason=null;$terminationSignal=null;$termSent=false;$killSent=false;
	$termDeadline=null;$killDeadline=null;
	$beginTermination=static function(string $reason,?int $signal=null) use (
		&$terminationReason,&$terminationSignal,&$termSent,&$termDeadline,$processGroup
	): void {
		if($terminationReason===null) $terminationReason=$reason;
		if($terminationSignal===null && is_int($signal)) $terminationSignal=$signal;
		if(!$termSent){
			$termSent=true;
			@posix_kill(-$processGroup,SIGTERM);
			$termDeadline=microtime(true)+DATAPHYRE_ONE_SHOT_TERMINATE_GRACE_SECONDS;
		}
	};
	$stop=static function(int $signal) use ($beginTermination): void {
		$beginTermination('signal',$signal);
	};
	pcntl_signal(SIGTERM,$stop);pcntl_signal(SIGINT,$stop);
	if(is_int($pendingSignal)) $beginTermination('signal',$pendingSignal);
	$operationDeadline=microtime(true)
		+(dataphyre_one_shot_operation_maximum_milliseconds($operation)/1000);
	while(true){
		$status=proc_get_status($process['resource']);
		if(!is_array($status)) throw new RuntimeException('One-shot process status is unavailable.');
		$running=($status['running'] ?? false)===true;
		if(!$processGroupProven && $running){
			if(@posix_getpgid($processGroup)!==$processGroup){
				@posix_kill($processGroup,SIGKILL);
				throw new RuntimeException('One-shot process group ownership is invalid.');
			}
			$processGroupProven=true;
		}
		if(!$running){
			$candidate=$status['exitcode'] ?? null;
			if($observedExit===null && is_int($candidate) && $candidate!==-1) $observedExit=$candidate;
			if($terminationReason===null && @posix_kill(-$processGroup,0)!==true) break;
			if($terminationReason===null) $beginTermination('cleanup');
		}
		$now=microtime(true);
		if($terminationReason===null && $now>=$operationDeadline) $beginTermination('timeout');
		if($termSent && !$killSent && is_float($termDeadline) && $now>=$termDeadline){
			$killSent=true;
			@posix_kill(-$processGroup,SIGKILL);
			$killDeadline=$now+DATAPHYRE_ONE_SHOT_KILL_REAP_SECONDS;
		}
		if($killSent && is_float($killDeadline) && $now>=$killDeadline) break;
		if($terminationReason!==null && !$running && @posix_kill(-$processGroup,0)!==true) break;
		usleep($terminationReason===null ? 50000 : DATAPHYRE_ONE_SHOT_POLL_MICROSECONDS);
	}
	$closed=@proc_close($process['resource']);
	$process=null;
	if($observedExit===null && is_int($closed) && $closed>=0) $observedExit=$closed;
	dataphyre_one_shot_reap(microtime(true)+DATAPHYRE_ONE_SHOT_KILL_REAP_SECONDS);
	if($terminationReason==='timeout') exit(124);
	if($terminationReason==='signal' && is_int($terminationSignal)) exit(128+$terminationSignal);
	exit(is_int($observedExit) && $observedExit>=0 && $observedExit<=255 ? $observedExit : 70);
}catch(Throwable $failure){
	if(is_array($process ?? null) && is_resource($process['resource'] ?? null)){
		if(is_int($processGroup) && $processGroup>1) @posix_kill(-$processGroup,SIGKILL);
		@proc_close($process['resource']);
		dataphyre_one_shot_reap(microtime(true)+DATAPHYRE_ONE_SHOT_KILL_REAP_SECONDS);
	}
	fwrite(STDERR,"Dataphyre one-shot operation failed.\n");
	exit(78);
}
