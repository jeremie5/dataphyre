<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/fixtures/application_runtime_fixed_port_lock.php';
require_once dirname(__DIR__).'/kernel/application_runtime_process_broker.php';
require_once dirname(__DIR__).'/kernel/application_runtime_environment.php';
require_once dirname(__DIR__).'/kernel/application_runtime_supervisor.php';

suite('Managed persistent PHP web pool')
	->contract('core.managed-php-web-pool',1)
	->layer('integration')->risk('critical')->watches('module:core')->isolation('case')
	->through('fpm','master-envelope','request-reset','worker-recycle','signal-lifecycle')
	->tag('core','runtime','web','fpm','environment','security','release')->group('framework-coverage');

function dataphyre_managed_fpm_exact_runtime(): bool
{
	return function_exists('posix_geteuid') && posix_geteuid()===0
		&& getenv('DATAPHYRE_TEST_CONTAINER_ROOT')==='1'
		&& extension_loaded('dataphyre_environment_fd')
		&& phpversion('dataphyre_environment_fd')==='1.2.0'
		&& function_exists('dataphyre_managed_pool_request_context')
		&& is_executable('/usr/bin/setpriv') && is_executable('/usr/local/sbin/php-fpm');
}

function dataphyre_managed_fpm_length(int $length): string
{
	return $length<128 ? chr($length) : pack('N',$length|0x80000000);
}

function dataphyre_managed_fpm_record(int $type,int $requestId,string $content): string
{
	$padding=(8-(strlen($content)%8))%8;
	return pack('CCnnCC',1,$type,$requestId,strlen($content),$padding,0)
		.$content.str_repeat("\0",$padding);
}

/** @param array<string,string> $parameters */
function dataphyre_managed_fpm_parameters(array $parameters): string
{
	$bytes='';
	foreach($parameters as $name=>$value){
		$bytes.=dataphyre_managed_fpm_length(strlen($name)).dataphyre_managed_fpm_length(strlen($value)).$name.$value;
	}
	return $bytes;
}

/** @return array{status:int,body:string,stderr:string} */
function dataphyre_managed_fpm_request(int $port,string $script,string $query): array
{
	$socket=null;$errno=0;$error='';$deadline=microtime(true)+5.0;
	do{
		$socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,0.1);
		if(is_resource($socket)) break;
		usleep(10000);
	}while(microtime(true)<$deadline);
	if(!is_resource($socket)) throw new RuntimeException('Managed FPM listener unavailable: '.$errno.' '.$error);
	stream_set_timeout($socket,5,0);$requestId=1;
	$parameters=dataphyre_managed_fpm_parameters([
		'GATEWAY_INTERFACE'=>'CGI/1.1','REQUEST_METHOD'=>'GET','QUERY_STRING'=>$query,
		'SCRIPT_FILENAME'=>$script,'SCRIPT_NAME'=>'/probe.php','REQUEST_URI'=>'/probe.php'.($query==='' ? '' : '?'.$query),
		'DOCUMENT_ROOT'=>dirname($script),'SERVER_SOFTWARE'=>'dataphyre-test','REMOTE_ADDR'=>'127.0.0.1',
		'REMOTE_PORT'=>'39001','SERVER_ADDR'=>'127.0.0.1','SERVER_PORT'=>'8083','SERVER_NAME'=>'localhost',
		'SERVER_PROTOCOL'=>'HTTP/1.1','CONTENT_LENGTH'=>'0','CONTENT_TYPE'=>'',
	]);
	$bytes=dataphyre_managed_fpm_record(1,$requestId,pack('nCxxxxx',1,0))
		.dataphyre_managed_fpm_record(4,$requestId,$parameters)
		.dataphyre_managed_fpm_record(4,$requestId,'')
		.dataphyre_managed_fpm_record(5,$requestId,'');
	if(fwrite($socket,$bytes)!==strlen($bytes)) throw new RuntimeException('Managed FPM request write failed.');
	$stdout='';$stderr='';
	while(true){
		$header='';
		while(strlen($header)<8){
			$chunk=fread($socket,8-strlen($header));
			if(!is_string($chunk) || $chunk==='') throw new RuntimeException('Managed FPM response ended early.');
			$header.=$chunk;
		}
		$fields=unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved',$header);
		if(!is_array($fields) || $fields['version']!==1 || $fields['request']!==$requestId){
			throw new RuntimeException('Managed FPM response framing is invalid.');
		}
		$content='';
		while(strlen($content)<$fields['length']){
			$chunk=fread($socket,$fields['length']-strlen($content));
			if(!is_string($chunk) || $chunk==='') throw new RuntimeException('Managed FPM response content ended early.');
			$content.=$chunk;
		}
		$remaining=$fields['padding'];
		while($remaining>0){$chunk=fread($socket,$remaining);if(!is_string($chunk) || $chunk==='') break;$remaining-=strlen($chunk);}
		if($fields['type']===6) $stdout.=$content;
		if($fields['type']===7) $stderr.=$content;
		if($fields['type']===3) break;
	}
	fclose($socket);
	[$headers,$body]=array_pad(preg_split("/\r?\n\r?\n/",$stdout,2) ?: [],2,'');
	$status=preg_match('/^Status:\s+(\d{3})/mi',$headers,$match)===1 ? (int)$match[1] : 200;
	return ['status'=>$status,'body'=>$body,'stderr'=>$stderr];
}

/** @return array{status:int,head:string,body:string} */
function dataphyre_managed_web_http(string $method,string $target): array
{
	$socket=null;$errno=0;$error='';$deadline=microtime(true)+5.0;
	do{
		$socket=@stream_socket_client('tcp://127.0.0.1:8083',$errno,$error,0.1,STREAM_CLIENT_CONNECT);
		if(is_resource($socket)) break;
		usleep(10000);
	}while(microtime(true)<$deadline);
	if(!is_resource($socket)) throw new RuntimeException('Managed web gateway unavailable: '.$errno.' '.$error);
	try{
		stream_set_timeout($socket,5,0);
		$request="{$method} {$target} HTTP/1.1\r\nHost: 127.0.0.1:8083\r\nConnection: close\r\n\r\n";
		$offset=0;
		while($offset<strlen($request)){
			$written=fwrite($socket,substr($request,$offset));
			if(!is_int($written) || $written<1) throw new RuntimeException('Managed web request write failed.');
			$offset+=$written;
		}
		stream_socket_shutdown($socket,STREAM_SHUT_WR);$response='';
		while(!feof($socket)){
			$chunk=fread($socket,65536);
			if($chunk===false) throw new RuntimeException('Managed web response read failed.');
			if($chunk===''){
				$metadata=stream_get_meta_data($socket);
				if(($metadata['timed_out'] ?? false)===true) throw new RuntimeException('Managed web response timed out.');
				continue;
			}
			$response.=$chunk;
			if(strlen($response)>1048576) throw new RuntimeException('Managed web response exceeded its test bound.');
		}
	}finally{fclose($socket);}
	[$head,$body]=array_pad(explode("\r\n\r\n",$response,2),2,'');
	if(preg_match('/^HTTP\/1\.1 (\d{3})\b/D',$head,$match)!==1){
		throw new RuntimeException('Managed web response framing is invalid.');
	}
	return ['status'=>(int)$match[1],'head'=>$head,'body'=>$body];
}

/** @return list<int> */
function dataphyre_managed_fpm_workers(int $masterPid,float $timeoutSeconds=5.0): array
{
	$deadline=microtime(true)+$timeoutSeconds;
	do{
		$raw=@file_get_contents('/proc/'.$masterPid.'/task/'.$masterPid.'/children');
		$pids=is_string($raw) ? array_values(array_map(
			'intval',preg_split('/\s+/',trim($raw),-1,PREG_SPLIT_NO_EMPTY) ?: [],
		)) : [];
		$workers=[];
		foreach($pids as $pid){
			$status=(string)@file_get_contents('/proc/'.$pid.'/status');
			if(preg_match('/^PPid:\s*'.preg_quote((string)$masterPid,'/').'$/mD',$status)===1) $workers[]=$pid;
		}
		sort($workers,SORT_NUMERIC);
		if(count($workers)===8) return $workers;
		usleep(10000);
	}while(microtime(true)<$deadline);
	return [];
}

function dataphyre_managed_fpm_copy_project(string $source,string $target): void
{
	if(!mkdir($target,0755,true)) throw new RuntimeException('Managed FPM fixture project could not be created.');
	$iterator=new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST,
	);
	foreach($iterator as $entry){
		$relative=substr($entry->getPathname(),strlen($source)+1);$destination=$target.'/'.$relative;
		if($entry->isDir()){
			if(!mkdir($destination,0755,true) && !is_dir($destination)) throw new RuntimeException('Fixture directory copy failed.');
		}elseif(!$entry->isFile() || !copy($entry->getPathname(),$destination) || !chmod($destination,0644)){
			throw new RuntimeException('Fixture file copy failed.');
		}
	}
}

test('same worker restores sealed state then recycles and terminates without metadata leaks',static function(Context $t): void {
	$t->isFalse(dataphyre_managed_pool_request_context());
	$portLock=dataphyre_application_runtime_fixed_port_lock();
	$workspace=$t->workspace('managed-fpm-pool');chmod($workspace->root(),0777);
	$project=(string)realpath(__DIR__.'/fixtures/application_runtime_project');
	$runtimeRoot=(string)realpath(dirname(__DIR__,3));
	$stateRoot=$workspace->directory('application-state');chmod($stateRoot,0777);
	$probe=(string)realpath(__DIR__.'/fixtures/application_runtime_fpm_probe.php');
	$prepend=(string)realpath(__DIR__.'/fixtures/application_runtime_fpm_environment.php');
	$errorLog=$workspace->path('php-fpm-error.log');
	$port=8083;
	$config=$workspace->file('php-fpm.conf',implode("\n",[
		'[global]','error_log = '.$errorLog,'log_level = notice','daemonize = no','',
		'[dataphyre-web]','user = 10001','group = 10001','listen = 127.0.0.1:'.$port,
		'listen.allowed_clients = 127.0.0.1','pm = static','pm.max_children = 1','pm.max_requests = 2',
		'clear_env = yes','catch_workers_output = yes','decorate_workers_output = no','chdir = '.$project,
		'security.limit_extensions = .php','php_admin_flag[display_errors] = off','php_admin_flag[log_errors] = on',
		'php_admin_value[error_log] = '.$errorLog,'php_admin_value[user_ini.filename] =',
		'php_admin_value[auto_prepend_file] = '.$prepend,'php_admin_value[auto_append_file] =','',
	]));
	$secret='managed-fpm-'.bin2hex(random_bytes(32));$secretSha=hash('sha256',$secret);$key=random_bytes(32);
	$managed=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext('web',$project,$key);
	$applicationEnvironment=DataphyreApplicationRuntimeEnvironment::childEnvironment(
		['PROBE_SECRET'=>$secret],
		'managed-fpm-probe','_Runtime$Probe','staging','dep_'.str_repeat('a',40),
	);
	$applicationEnvironment['DATAPHYRE_RUNTIME_PROJECT_ROOT']=$project;
	$applicationEnvironment['DATAPHYRE_RUNTIME_APPLICATION']='_Runtime$Probe';
	$applicationEnvironment['DATAPHYRE_RUNTIME_ENVIRONMENT']='staging';
	$applicationEnvironment['DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT']=$runtimeRoot;
	$applicationEnvironment['DATAPHYRE_RUNTIME_TEST_STATE_ROOT']=$stateRoot;
	$applicationEnvironment['DATAPHYRE_SCHEDULER_ACTIVATION_MODE']='record_only';
	$managedEncoded=$managed['private_key'];
	$pool=null;$results=[];$failure=null;$stdout='';$stderr='';$sensitiveOutputLeak=false;
	try{
		$pool=DataphyreApplicationRuntimeProcessBroker::spawn([
			'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
			'/usr/local/sbin/php-fpm','-F','-y',$config,
			'-d','dataphyre_environment_fd.managed_pool_role=web','-d','user_ini.filename=',
		],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$project,[],'web-pool',
			$applicationEnvironment,10000,$managed);
		$t->isFalse(str_contains((string)@file_get_contents('/proc/'.$pool['pid'].'/environ'),$secret));
		$t->isFalse(str_contains((string)@file_get_contents('/proc/'.$pool['pid'].'/cmdline'),$secret));
		$t->isFalse(str_contains((string)@file_get_contents('/proc/'.$pool['pid'].'/environ'),$managedEncoded));
		$t->isFalse(str_contains((string)@file_get_contents('/proc/'.$pool['pid'].'/cmdline'),$managedEncoded));
		$t->isFalse(file_exists('/proc/'.$pool['pid'].'/fd/198'));
		foreach(['action=mutate','',''] as $query){
			$response=dataphyre_managed_fpm_request($port,$probe,$query);
			$t->same(200,$response['status'],$response['stderr'].' '.$response['body']);
			$t->isFalse(str_contains($response['body'],$secret));
			$result=json_decode($response['body'],true,16,JSON_THROW_ON_ERROR);$results[]=$result;
			$workerEnvironment=(string)@file_get_contents('/proc/'.$result['worker_pid'].'/environ');
			$workerCommand=(string)@file_get_contents('/proc/'.$result['worker_pid'].'/cmdline');
			$t->isFalse(str_contains($workerEnvironment,$secret));$t->isFalse(str_contains($workerCommand,$secret));
			$t->isFalse(str_contains($workerEnvironment,$managedEncoded));$t->isFalse(str_contains($workerCommand,$managedEncoded));
		}
		$t->same($results[0]['worker_pid'],$results[1]['worker_pid']);
		$t->isFalse($results[1]['worker_pid']===$results[2]['worker_pid']);
		foreach(['locale','timezone','memory_limit','output_buffer_level'] as $field){
			$t->same($results[0][$field],$results[1][$field],$field.' same-worker reset');
			$t->same($results[0][$field],$results[2][$field],$field.' recycled-worker reset');
		}
		$t->notSame('DataphyreManagedFpmLeakedHandler::handle',$results[0]['error_handler_fingerprint']);
		$t->same($results[0]['error_handler_fingerprint'],$results[1]['error_handler_fingerprint']);
		$t->same($results[0]['error_handler_fingerprint'],$results[2]['error_handler_fingerprint']);
		foreach($results as $result){
			$t->same(true,$result['ok']);$t->same($pool['pid'],$result['parent_pid']);
			$t->same('healthy',$result['router_body']['status'] ?? null);
			$t->same([],$result['router_body']['missing_environment_keys'] ?? null);
			$t->same(true,$result['tracelog_persisted']);
			$t->same(10001,$result['uid']);$t->same(10001,$result['gid']);$t->same([10001],$result['groups']);
			$t->same($project,$result['cwd']);$t->same(0027,$result['umask']);
			$t->same('clean',$result['static_state']);
			$t->same($secretSha,$result['secret_sha256']);$t->same(true,$result['secret_in_environment_superglobals']);
			$t->same(true,$result['secret_absent_from_process_metadata']);
			$t->same(false,$result['leaked_environment_present']);
			$t->same(false,$result['leaked_environment_superglobals_present']);
			$t->same(false,$result['leaked_global_present']);
			$t->same(true,$result['broker_descriptor_closed']);$t->same(true,$result['context_refetch_rejected']);
			$t->same('dataphyre.managed_runtime_bootstrap.v1',$result['managed_bootstrap']['contract'] ?? null);
			$t->same('web',$result['managed_bootstrap']['role'] ?? null);
			$t->same('fpm-fcgi',$result['managed_bootstrap']['sapi'] ?? null);
		}
		$t->same(true,$results[0]['database_available_before']);
		$t->same(false,$results[0]['database_available_after']);
		$t->same(true,$results[1]['database_available_before']);
		$t->same(true,$results[1]['database_available_after']);
		$t->same(true,$results[2]['database_available_before']);
		$t->same(true,$results[2]['database_available_after']);
		$firstWorker=$results[0]['worker_pid'];$replacementWorker=$results[2]['worker_pid'];
		$t->isFalse(@posix_kill($firstWorker,0));
		$t->isTrue(posix_kill($pool['pid'],SIGTERM));
		$deadline=microtime(true)+5.0;
		do{$status=proc_get_status($pool['resource']);if(($status['running'] ?? false)!==true) break;usleep(10000);}while(microtime(true)<$deadline);
		$t->isFalse((proc_get_status($pool['resource'])['running'] ?? true)===true);
		$t->isFalse(@posix_kill($replacementWorker,0));
	}catch(Throwable $caught){$failure=$caught;
	}finally{
		if(is_array($pool)){
			$status=proc_get_status($pool['resource']);
			if(($status['running'] ?? false)===true) @posix_kill($pool['pid'],SIGKILL);
			foreach($pool['pipes'] as $pipe) if(is_resource($pipe)) stream_set_blocking($pipe,false);
			$stdout=is_resource($pool['pipes'][1] ?? null) ? (string)stream_get_contents($pool['pipes'][1]) : '';
			$stderr=is_resource($pool['pipes'][2] ?? null) ? (string)stream_get_contents($pool['pipes'][2]) : '';
			foreach($pool['pipes'] as $pipe) if(is_resource($pipe)) fclose($pipe);
			proc_close($pool['resource']);
		}
		if(is_file($errorLog)) $stderr.=(string)file_get_contents($errorLog);
		$sensitiveOutputLeak=str_contains($stdout,$secret) || str_contains($stderr,$secret)
			|| str_contains($stdout,$managedEncoded) || str_contains($stderr,$managedEncoded);
		dataphyre_application_runtime_fixed_port_unlock($portLock);
		sodium_memzero($secret);sodium_memzero($key);sodium_memzero($managedEncoded);sodium_memzero($managed['private_key']);
	}
	$t->isFalse($sensitiveOutputLeak);
	$t->isFalse(str_contains($stderr,'dataphyre\\tracelog::session(): Return value must be of type array'));
	if($failure!==null) throw new RuntimeException(
		'Managed FPM proof failed: '.$failure->getMessage().' results='.json_encode($results)
			.' stdout='.$stdout.' stderr='.$stderr,0,$failure,
	);
})->tag('same-worker','adversarial-mutation','recycle','signal','proc','no-secret-leak')->maxMillis(60000)
	->skipUnless(dataphyre_managed_fpm_exact_runtime(),'Requires the canonical root test image with exact matching PHP-FPM.');

test('fixed rootless gateway and eight-worker FPM topology serves static and dynamic traffic and fails closed',static function(Context $t): void {
	$portLock=dataphyre_application_runtime_fixed_port_lock();
	$workspace=$t->workspace('managed-fpm-fixed-topology');chmod($workspace->root(),0777);
	$fixtureProject=(string)realpath(__DIR__.'/fixtures/application_runtime_project');
	$project=$workspace->path('application');
	dataphyre_managed_fpm_copy_project($fixtureProject,$project);
	if(!symlink('/etc/passwd',$project.'/public/leak.txt')) throw new RuntimeException('Static symlink fixture could not be created.');
	file_put_contents($project.'/public/.hidden.txt','hidden',LOCK_EX);chmod($project.'/public/.hidden.txt',0644);
	$staticHealth='forged-static-health';file_put_contents($project.'/public/health',$staticHealth,LOCK_EX);chmod($project.'/public/health',0644);
	$runtimeRoot=(string)realpath(dirname(__DIR__,3));$kernel=(string)realpath(dirname(__DIR__).'/kernel');
	$router=$kernel.'/application_runtime_router.php';$gateway=$kernel.'/application_runtime_web_gateway.php';
	$prepend=$kernel.'/application_runtime_fpm_environment.php';$releaseProbe=$kernel.'/application_runtime_release_probe.php';
	$stateRoot=$workspace->directory('application-state');chmod($stateRoot,0777);
	$healthCounter=$workspace->file('dynamic-health-count','0');chown($healthCounter,10001);chgrp($healthCounter,10001);chmod($healthCounter,0600);
	$errorLog=$workspace->path('php-fpm-error.log');$config=$workspace->file('php-fpm.conf',implode("\n",[
		'[global]','daemonize = no','error_log = '.$errorLog,'log_level = warning','process_control_timeout = 5s','',
		'[dataphyre-web]','listen = /run/dataphyre/web/php-fpm.sock','listen.mode = 0600',
		'pm = static','pm.max_children = 8','pm.max_requests = 500','request_terminate_timeout = 300s',
		'request_terminate_timeout_track_finished = yes','clear_env = yes','catch_workers_output = yes',
		'decorate_workers_output = no','security.limit_extensions = .php','php_admin_flag[display_errors] = off',
		'php_admin_flag[log_errors] = on','php_admin_flag[expose_php] = off','php_admin_value[user_ini.filename] =',
		'php_admin_value[auto_prepend_file] = '.$prepend,'php_admin_value[auto_append_file] =',
		'php_admin_value[opcache.validate_timestamps] = 0','php_admin_value[opcache.file_update_protection] = 0','',
	]));
	$key=random_bytes(32);$managed=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext('web',$project,$key);
	$applicationEnvironment=DataphyreApplicationRuntimeEnvironment::childEnvironment(
		['DATAPHYRE_RUNTIME_TEST_WEB_SLEEP'=>'1'],'managed-fpm-topology','_Runtime$Probe','staging',
		'dep_'.str_repeat('b',40),
	);
	$applicationEnvironment['DATAPHYRE_RUNTIME_PROJECT_ROOT']=$project;
	$applicationEnvironment['DATAPHYRE_RUNTIME_APPLICATION']='_Runtime$Probe';
	$applicationEnvironment['DATAPHYRE_RUNTIME_ENVIRONMENT']='staging';
	$applicationEnvironment['DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT']=$runtimeRoot;
	$applicationEnvironment['DATAPHYRE_RUNTIME_TEST_STATE_ROOT']=$stateRoot;
	$applicationEnvironment['DATAPHYRE_RUNTIME_TEST_WEB_HEALTH_COUNTER_PATH']=$healthCounter;
	$applicationEnvironment['DATAPHYRE_SCHEDULER_ACTIVATION_MODE']='record_only';
	$parentCreated=false;$parentMode=null;$webDirectory='/run/dataphyre/web';$socketPath=$webDirectory.'/php-fpm.sock';
	$fpm=null;$web=null;$statusServer=null;$failure=null;$diagnostics='';$initialWorkers=[];$replacementWorkers=[];
	$socketIdentity=null;$webDirectoryIdentity=null;$webParentIdentity=null;
	try{
		if(is_link('/run/dataphyre')) throw new RuntimeException('Managed runtime parent is a symlink.');
		if(!is_dir('/run/dataphyre')){
			if(!mkdir('/run/dataphyre',0711)) throw new RuntimeException('Managed runtime parent could not be created.');
			$parentCreated=true;
		}else{
			$parentStat=lstat('/run/dataphyre');$parentMode=is_array($parentStat) ? (($parentStat['mode'] ?? 0)&0777) : null;
			if(!is_int($parentMode) || !chmod('/run/dataphyre',0711)) throw new RuntimeException('Managed runtime parent could not be prepared.');
		}
		$preparedParent=lstat('/run/dataphyre');
		if(!is_array($preparedParent) || !is_int($preparedParent['dev'] ?? null) || !is_int($preparedParent['ino'] ?? null)){
			throw new RuntimeException('Managed runtime parent identity is unavailable.');
		}
		$webParentIdentity=['dev'=>$preparedParent['dev'],'ino'=>$preparedParent['ino']];
		if(file_exists($webDirectory) || is_link($webDirectory)) throw new RuntimeException('Managed runtime web directory is already in use.');
		if(!mkdir($webDirectory,0700) || !chown($webDirectory,10001) || !chgrp($webDirectory,10001)
			|| !chmod($webDirectory,0700)){
			throw new RuntimeException('Managed runtime web directory could not be prepared.');
		}
		$fpm=DataphyreApplicationRuntimeProcessBroker::spawn([
			'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
			'/usr/local/sbin/php-fpm','-F','-y',$config,
			'-d','dataphyre_environment_fd.managed_pool_role=web','-d','user_ini.filename=',
		],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$project,[],'web-pool',
			$applicationEnvironment,10000,$managed,null,true,
		);
		$t->same($fpm['pid'],posix_getpgid($fpm['pid']));
		$socketDeadline=microtime(true)+5.0;$socketStat=null;
		do{
			$socketStat=@lstat($socketPath);
			if(is_array($socketStat) && (($socketStat['mode'] ?? 0)&0170000)===0140000) break;
			usleep(10000);
		}while(microtime(true)<$socketDeadline);
		$t->isTrue(is_array($socketStat),'the fixed FPM socket exists');
		$t->same(0600,($socketStat['mode'] ?? 0)&0777);$t->same(10001,$socketStat['uid']);$t->same(10001,$socketStat['gid']);
		$socketIdentity=['dev'=>$socketStat['dev'],'ino'=>$socketStat['ino']];
		$t->isTrue(chown($webDirectory,0),'root ownership atomically revokes UID 10001 directory mutation');
		$replacementPath=$webDirectory.'/tenant-replacement.sock';
		$transitionSubstitution=$t->process([
			'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all',PHP_BINARY,'-r',
			'$socket=$argv[1];$replacement=$argv[2];$listener=@stream_socket_server("unix://".$replacement,$e,$m);'.
			'echo json_encode(["unlink"=>@unlink($socket),"rename"=>@rename($socket,$replacement),'.
			'"replacement_bound"=>is_resource($listener)]);if(is_resource($listener))fclose($listener);',
			$socketPath,$replacementPath,
		],working_directory:$project,timeout_millis:5000);
		$t->processSucceeded($transitionSubstitution,$transitionSubstitution->stderr());
		$t->same(['unlink'=>false,'rename'=>false,'replacement_bound'=>false],$transitionSubstitution->json());
		$t->isTrue(chgrp($webDirectory,0));$t->isTrue(chmod($webDirectory,0711));$lockedDirectory=lstat($webDirectory);
		$t->same(0711,($lockedDirectory['mode'] ?? 0)&0777);$t->same(0,$lockedDirectory['uid']);$t->same(0,$lockedDirectory['gid']);
		$webDirectoryIdentity=['dev'=>$lockedDirectory['dev'],'ino'=>$lockedDirectory['ino']];
		$unchangedSocket=lstat($socketPath);$unchangedDirectory=lstat($webDirectory);
		$t->same($socketIdentity['dev'],$unchangedSocket['dev']);$t->same($socketIdentity['ino'],$unchangedSocket['ino']);
		$t->same($webDirectoryIdentity['dev'],$unchangedDirectory['dev']);$t->same($webDirectoryIdentity['ino'],$unchangedDirectory['ino']);
		$initialWorkers=dataphyre_managed_fpm_workers($fpm['pid']);$t->count(8,$initialWorkers);
		$masterIdentity=DataphyreApplicationRuntimeChildEnvironment::processIdentity($fpm['pid']);
		$t->same(10001,$masterIdentity['uid']);$t->same(10001,$masterIdentity['gid']);$t->same([10001],$masterIdentity['groups']);
		foreach(['cap_inheritable','cap_permitted','cap_eff','cap_bounding','cap_ambient'] as $capability) $t->same('0000000000000000',$masterIdentity[$capability]);
		$t->same(true,$masterIdentity['no_new_privileges']);
		foreach($initialWorkers as $workerPid){
			$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity($workerPid);
			$t->same($fpm['pid'],$identity['parent_pid']);$t->same(10001,$identity['uid']);$t->same(10001,$identity['gid']);
			$t->same([10001],$identity['groups']);
			foreach(['cap_inheritable','cap_permitted','cap_eff','cap_bounding','cap_ambient'] as $capability) $t->same('0000000000000000',$identity[$capability]);
			$t->same(true,$identity['no_new_privileges']);$t->same($fpm['pid'],posix_getpgid($workerPid));
		}

		$web=DataphyreApplicationRuntimeProcessBroker::spawn([
			'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
			PHP_BINARY,$gateway,'127.0.0.1','8083',$router,$project,
		],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$project,[],'web-http-gateway',[],10000,null,null,true);
		$t->same($web['pid'],posix_getpgid($web['pid']));
		$gatewayIdentity=DataphyreApplicationRuntimeChildEnvironment::processIdentity($web['pid']);
		$t->same(10001,$gatewayIdentity['uid']);$t->same(10001,$gatewayIdentity['gid']);
		$t->same([10001],$gatewayIdentity['groups']);
		foreach(['cap_inheritable','cap_permitted','cap_eff','cap_bounding','cap_ambient'] as $capability) $t->same('0000000000000000',$gatewayIdentity[$capability]);
		$t->same(true,$gatewayIdentity['no_new_privileges']);
		$modeDrift=$t->process([
			'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all',PHP_BINARY,'-r',
			'echo json_encode(["changed"=>@chmod($argv[1],0644)]);',$socketPath,
		],working_directory:$project,timeout_millis:5000);
		$t->processSucceeded($modeDrift,$modeDrift->stderr());$t->same(['changed'=>true],$modeDrift->json());
		$driftedSocket=lstat($socketPath);$t->same($socketIdentity['dev'],$driftedSocket['dev']);
		$t->same($socketIdentity['ino'],$driftedSocket['ino']);$t->same(0644,($driftedSocket['mode'] ?? 0)&0777);
		$t->same(502,dataphyre_managed_web_http('GET','/health')['status'],'same-inode socket mode drift fails closed');
		$t->isTrue(chmod($socketPath,0600));$restoredSocket=lstat($socketPath);
		$t->same($socketIdentity['ino'],$restoredSocket['ino']);

			$dynamic=dataphyre_managed_web_http('GET','/health');
			$t->same(200,$dynamic['status'],$dynamic['head'].' '.$dynamic['body']);
			$t->same('healthy',json_decode($dynamic['body'],true,32,JSON_THROW_ON_ERROR)['status'] ?? null);
			$t->notContains($staticHealth,$dynamic['body']);$t->same(1,(int)file_get_contents($healthCounter));
			$dynamicQuery=dataphyre_managed_web_http('GET','/health?probe=dynamic');
			$t->same(200,$dynamicQuery['status']);$t->notContains($staticHealth,$dynamicQuery['body']);
			$t->same(2,(int)file_get_contents($healthCounter));
			$dynamicHead=dataphyre_managed_web_http('HEAD','/health');
			$t->same(200,$dynamicHead['status']);$t->same('',$dynamicHead['body']);
			$t->contains('Content-Length: '.strlen($dynamic['body']),$dynamicHead['head'],'dynamic HEAD representation length');
			$t->same(3,(int)file_get_contents($healthCounter));
			$encodedHealth=dataphyre_managed_web_http('GET','/he%61lth');
			$t->notContains($staticHealth,$encodedHealth['body']);
			$t->same(4,(int)file_get_contents($healthCounter));
		$static=dataphyre_managed_web_http('GET','/runtime.txt');
		$t->same(200,$static['status']);$t->same((string)file_get_contents($project.'/public/runtime.txt'),$static['body']);
		$t->contains('Cache-Control: no-cache',$static['head'],'static cache policy');
		$head=dataphyre_managed_web_http('HEAD','/runtime.txt');
		$t->same(200,$head['status']);$t->same('',$head['body']);
		$t->contains('Content-Length: '.filesize($project.'/public/runtime.txt'),$head['head'],'static HEAD representation length');
			$growingPath=$project.'/public/growing.bin';$growingBytes=str_repeat('g',16777216);
			file_put_contents($growingPath,$growingBytes,LOCK_EX);chown($growingPath,10001);chgrp($growingPath,10001);chmod($growingPath,0600);
			$growingClient=stream_socket_client('tcp://127.0.0.1:8083',$errno,$error,2,STREAM_CLIENT_CONNECT);
			if(!is_resource($growingClient)) throw new RuntimeException('Growing-static client could not connect.');
			stream_set_timeout($growingClient,30,0);
			$growingRequest="GET /growing.bin HTTP/1.1\r\nHost: 127.0.0.1:8083\r\nConnection: close\r\n\r\n";
			if(fwrite($growingClient,$growingRequest)!==strlen($growingRequest)) throw new RuntimeException('Growing-static request write failed.');
			stream_socket_shutdown($growingClient,STREAM_SHUT_WR);$growingWire='';$growingSeparator=false;
			do{
				$chunk=fread($growingClient,65536);
				if(!is_string($chunk) || $chunk==='') throw new RuntimeException('Growing-static response ended before its first body chunk.');
				$growingWire.=$chunk;$growingSeparator=strpos($growingWire,"\r\n\r\n");
			}while($growingSeparator===false);
			file_put_contents($growingPath,str_repeat('x',1048576),FILE_APPEND|LOCK_EX);
			$growingWire.=(string)stream_get_contents($growingClient);fclose($growingClient);
			[$growingHead,$growingBody]=array_pad(explode("\r\n\r\n",$growingWire,2),2,'');
			$t->contains('Content-Length: 16777216',$growingHead,'static growth retains its attested representation length');
			$t->same(16777216,strlen($growingBody));$t->same(hash('sha256',$growingBytes),hash('sha256',$growingBody));
			unset($growingBytes,$growingBody,$growingWire);
			foreach(['/leak.txt','/.hidden.txt','/probe.php','/%2e%2e/etc/passwd'] as $unsafeTarget){
				$t->same(404,dataphyre_managed_web_http('GET',$unsafeTarget)['status'],$unsafeTarget);
			}
			$t->same(502,dataphyre_managed_web_http('GET','/health?action=oversized-response-header')['status']);
			$t->same(502,dataphyre_managed_web_http('GET','/health?action=oversized-response-header-line')['status']);

			$slowClients=[];$slowStarted=microtime(true);
			for($index=0;$index<8;$index++){
				$client=stream_socket_client('tcp://127.0.0.1:8083',$errno,$error,2,STREAM_CLIENT_CONNECT);
				if(!is_resource($client)) throw new RuntimeException('Slow-header client could not connect.');
				stream_set_timeout($client,8,0);
				fwrite($client,"GET /health HTTP/1.1\r\nHost: 127.0.0.1");$slowClients[]=$client;
			}
			for($drip=0;$drip<6;$drip++){
				usleep(900000);
				foreach($slowClients as $client) @fwrite($client,' ');
			}
			$slowStatuses=[];
			foreach($slowClients as $client){
				stream_socket_shutdown($client,STREAM_SHUT_WR);$response=(string)stream_get_contents($client);fclose($client);
				$slowStatuses[]=preg_match('/^HTTP\/1\.1 (\d{3})\b/D',$response,$match)===1 ? (int)$match[1] : 0;
			}
			$t->same(array_fill(0,8,504),$slowStatuses);
			$t->isTrue(microtime(true)-$slowStarted>=5.0 && microtime(true)-$slowStarted<8.0,'header timeout is absolute, not idle');
			$t->same(200,dataphyre_managed_web_http('GET','/health')['status']);

			$bodyClients=[];$boundedBody=str_repeat('b',524288);
			for($index=0;$index<8;$index++){
				$client=stream_socket_client('tcp://127.0.0.1:8083',$errno,$error,2,STREAM_CLIENT_CONNECT);
				if(!is_resource($client)) throw new RuntimeException('Bounded-body client could not connect.');
				stream_set_timeout($client,10,0);
				$request="POST /health HTTP/1.1\r\nHost: 127.0.0.1:8083\r\nContent-Type: application/octet-stream\r\n".
					'Content-Length: '.strlen($boundedBody)."\r\nConnection: close\r\n\r\n".$boundedBody;
				$offset=0;while($offset<strlen($request)){$written=fwrite($client,substr($request,$offset,65536));if(!is_int($written) || $written<1) break;$offset+=$written;}
				if($offset!==strlen($request)) throw new RuntimeException('Bounded-body request write failed.');
				stream_socket_shutdown($client,STREAM_SHUT_WR);$bodyClients[]=$client;
			}
			$bodyStatuses=[];
			foreach($bodyClients as $client){
				$response=(string)stream_get_contents($client);fclose($client);
				$bodyStatuses[]=preg_match('/^HTTP\/1\.1 (\d{3})\b/D',$response,$match)===1 ? (int)$match[1] : 0;
			}
			sort($bodyStatuses,SORT_NUMERIC);$t->same(array_fill(0,8,200),$bodyStatuses);

			$oversizedBodyClients=[];
			for($index=0;$index<8;$index++){
				$client=stream_socket_client('tcp://127.0.0.1:8083',$errno,$error,2,STREAM_CLIENT_CONNECT);
				if(!is_resource($client)) throw new RuntimeException('Oversized-body client could not connect.');
				stream_set_timeout($client,5,0);
				fwrite($client,"POST /health HTTP/1.1\r\nHost: 127.0.0.1:8083\r\nContent-Length: 16777217\r\nConnection: close\r\n\r\n");
				stream_socket_shutdown($client,STREAM_SHUT_WR);$oversizedBodyClients[]=$client;
			}
			$oversizedBodyStatuses=[];
			foreach($oversizedBodyClients as $client){
				$response=(string)stream_get_contents($client);fclose($client);
				$oversizedBodyStatuses[]=preg_match('/^HTTP\/1\.1 (\d{3})\b/D',$response,$match)===1 ? (int)$match[1] : 0;
			}
			sort($oversizedBodyStatuses,SORT_NUMERIC);$t->same(array_fill(0,8,400),$oversizedBodyStatuses);

			$oversizedResponseClients=[];
			for($index=0;$index<8;$index++){
				$client=stream_socket_client('tcp://127.0.0.1:8083',$errno,$error,2,STREAM_CLIENT_CONNECT);
				if(!is_resource($client)) throw new RuntimeException('Oversized-response client could not connect.');
				stream_set_timeout($client,30,0);
				$request="GET /health?action=oversized-response HTTP/1.1\r\nHost: 127.0.0.1:8083\r\nConnection: close\r\n\r\n";
				fwrite($client,$request);stream_socket_shutdown($client,STREAM_SHUT_WR);$oversizedResponseClients[]=$client;
			}
			$oversizedResponseStatuses=[];
			foreach($oversizedResponseClients as $client){
				$response=(string)stream_get_contents($client);fclose($client);
				$oversizedResponseStatuses[]=preg_match('/^HTTP\/1\.1 (\d{3})\b/D',$response,$match)===1 ? (int)$match[1] : 0;
			}
			sort($oversizedResponseStatuses,SORT_NUMERIC);$t->same(array_fill(0,8,502),$oversizedResponseStatuses);
			$t->same(200,dataphyre_managed_web_http('GET','/health')['status']);

			$sleeping=[];
		for($index=0;$index<8;$index++){
			$client=stream_socket_client('tcp://127.0.0.1:8083',$errno,$error,2,STREAM_CLIENT_CONNECT);
			if(!is_resource($client)) throw new RuntimeException('Concurrent sleep client could not connect.');
			stream_set_timeout($client,3,0);
			$request="GET /health?action=sleep HTTP/1.1\r\nHost: 127.0.0.1:8083\r\nConnection: close\r\n\r\n";
			if(fwrite($client,$request)!==strlen($request)) throw new RuntimeException('Concurrent sleep request write failed.');
			stream_socket_shutdown($client,STREAM_SHUT_WR);$sleeping[]=$client;
		}
		usleep(250000);$killedWorker=$initialWorkers[0];$t->isTrue(posix_kill($killedWorker,SIGKILL));
		$statuses=[];
		foreach($sleeping as $client){
			$response=(string)stream_get_contents($client);fclose($client);
			$statuses[]=preg_match('/^HTTP\/1\.1 (\d{3})\b/D',$response,$match)===1 ? (int)$match[1] : 0;
		}
		sort($statuses,SORT_NUMERIC);$t->same([200,200,200,200,200,200,200,502],$statuses);
		$replacementWorkers=dataphyre_managed_fpm_workers($fpm['pid']);$t->count(8,$replacementWorkers);
		$t->isFalse(in_array($killedWorker,$replacementWorkers,true));
		$t->same(200,dataphyre_managed_web_http('GET','/health')['status']);

			$healthCountBeforeReleaseProbe=(int)file_get_contents($healthCounter);
			$ready=$workspace->path('release-probe-status.ready');
		$statusServer=$t->startPhpProcess([
			__DIR__.'/fixtures/application_runtime_release_probe_status_server.php',$ready,
		],timeout_millis:20000);
		$statusDeadline=microtime(true)+5.0;
		while(!is_file($ready) && microtime(true)<$statusDeadline) usleep(10000);
		$t->isTrue(is_file($ready),'release-probe status fixture is listening');
		$releaseResult=$t->phpProcess([$releaseProbe],timeout_millis:30000);
		$t->processSucceeded($releaseResult,$releaseResult->stderr());$releaseEvidence=$releaseResult->json();
		$t->same([
			'contract','ok','warm_dynamic','concurrent_dynamic','scheduler_cadence','failure_code',
		],array_keys($releaseEvidence));
		$t->same(true,$releaseEvidence['ok']);$t->same(null,$releaseEvidence['failure_code']);
		$t->same(20,$releaseEvidence['warm_dynamic']['successful_count']);
		$t->lessThan(751,$releaseEvidence['warm_dynamic']['p95_milliseconds']);
		$t->same(8,$releaseEvidence['concurrent_dynamic']['successful_count']);
		$t->lessThan(3001,$releaseEvidence['concurrent_dynamic']['elapsed_milliseconds']);
			$t->same(['count'=>1,'last_at'=>'2026-08-15T12:00:00Z','last_result'=>'ok','definition_budget_enforced'=>true],
				$releaseEvidence['scheduler_cadence']);
			$t->same($healthCountBeforeReleaseProbe+31,(int)file_get_contents($healthCounter),
				'the warm and concurrent release probe crossed PHP-FPM rather than public/health');
		$t->processSucceeded($statusServer->wait(5000));

		$t->isTrue(posix_kill(-$web['pid'],SIGTERM));
		$gatewayDeadline=microtime(true)+5.0;
		do{$webStatus=proc_get_status($web['resource']);if(($webStatus['running'] ?? false)!==true) break;usleep(10000);}
		while(microtime(true)<$gatewayDeadline);
		$t->isFalse((proc_get_status($web['resource'])['running'] ?? true)===true);
		$t->isTrue((proc_get_status($fpm['resource'])['running'] ?? false)===true);$t->isTrue(file_exists($socketPath));
		$deadGateway=@stream_socket_client('tcp://127.0.0.1:8083',$errno,$error,0.1,STREAM_CLIENT_CONNECT);
		$t->isFalse(is_resource($deadGateway));if(is_resource($deadGateway)) fclose($deadGateway);

		$t->isTrue(posix_kill(-$fpm['pid'],SIGTERM));
		$fpmDeadline=microtime(true)+5.0;
		do{$fpmStatus=proc_get_status($fpm['resource']);if(($fpmStatus['running'] ?? false)!==true) break;usleep(10000);}
		while(microtime(true)<$fpmDeadline);
			$t->isFalse((proc_get_status($fpm['resource'])['running'] ?? true)===true);
			foreach(array_unique([...$initialWorkers,...$replacementWorkers]) as $workerPid) $t->isFalse(@posix_kill($workerPid,0));
			$staleSocket=lstat($socketPath);$t->isTrue(is_array($staleSocket),'root lock prevents FPM from unlinking its socket');
			$t->same($socketIdentity['dev'],$staleSocket['dev']);$t->same($socketIdentity['ino'],$staleSocket['ino']);
			$tenantCleanup=$t->process([
				'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
				'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all',PHP_BINARY,'-r',
				'echo json_encode(["unlinked"=>@unlink($argv[1])]);',$socketPath,
			],working_directory:$project,timeout_millis:5000);
			$t->processSucceeded($tenantCleanup,$tenantCleanup->stderr());$t->same(['unlinked'=>false],$tenantCleanup->json());
			dataphyre_runtime_cleanup_web_socket($socketIdentity,$webDirectoryIdentity,$webParentIdentity);
			$t->isFalse(file_exists($socketPath));$t->isFalse(file_exists($webDirectory));
			$socketIdentity=null;$webDirectoryIdentity=null;
	}catch(Throwable $caught){$failure=$caught;
	}finally{
		foreach(['web'=>$web,'fpm'=>$fpm] as $process){
			if(!is_array($process)) continue;
			$status=proc_get_status($process['resource']);
			if(($status['running'] ?? false)===true && $process['pid']>1){
				@posix_kill(-$process['pid'],SIGKILL);@posix_kill($process['pid'],SIGKILL);
			}
			foreach($process['pipes'] as $pipe) if(is_resource($pipe)){
				stream_set_blocking($pipe,false);$diagnostics.=(string)stream_get_contents($pipe);fclose($pipe);
			}
			@proc_close($process['resource']);
		}
		if($statusServer!==null) $statusServer->terminate();
		if(is_file($errorLog)) $diagnostics.=(string)file_get_contents($errorLog);
			dataphyre_runtime_cleanup_web_socket($socketIdentity,$webDirectoryIdentity,$webParentIdentity);
		if($parentCreated){@rmdir('/run/dataphyre');}
		elseif(is_int($parentMode)){@chmod('/run/dataphyre',$parentMode);}
		dataphyre_application_runtime_fixed_port_unlock($portLock);
		sodium_memzero($key);sodium_memzero($managed['private_key']);
	}
	if($failure!==null) throw new RuntimeException(
		'Managed FPM fixed-topology proof failed: '.$failure->getMessage().' diagnostics='.$diagnostics,0,$failure,
	);
})->tag('gateway','static','fastcgi','eight-workers','worker-replacement','process-group','performance','cadence')
	->maxMillis(90000)
	->skipUnless(dataphyre_managed_fpm_exact_runtime(),'Requires the canonical root test image with exact matching PHP-FPM.');

test('master envelope uses one total monotonic deadline across a dripped frame',static function(Context $t): void {
	$portLock=dataphyre_application_runtime_fixed_port_lock();
	$workspace=$t->workspace('managed-fpm-envelope-deadline');chmod($workspace->root(),0777);
	$project=(string)realpath(__DIR__.'/fixtures/application_runtime_project');
	$config=$workspace->file('php-fpm.conf',implode("\n",[
		'[global]','error_log = '.$workspace->path('php-fpm-error.log'),'log_level = notice','daemonize = no','',
		'[dataphyre-web]','user = 10001','group = 10001','listen = 127.0.0.1:8083',
		'pm = static','pm.max_children = 1','clear_env = yes','security.limit_extensions = .php','',
	]));
	[$brokerChannel,$childChannel]=DataphyreApplicationRuntimeChildEnvironment::socketPair();
	$pipes=[];$process=null;$running=true;$exitCode=-1;$elapsed=0.0;
	try{
		$process=proc_open([ // dataphyre-test-architecture: exempt[raw-process-control] reason="The native total-deadline proof must drip the inherited descriptor before PHP module startup completes."
			PHP_BINARY,dirname(__DIR__).'/kernel/application_runtime_pre_exec.php',
			'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
			'/usr/local/sbin/php-fpm','-F','-y',$config,
			'-d','dataphyre_environment_fd.managed_pool_role=web','-d','user_ini.filename=',
		],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w'],
			DataphyreApplicationRuntimeChildEnvironment::INHERITED_FD=>$childChannel],
			$pipes,$project,[],['bypass_shell'=>true,'suppress_errors'=>true]);
		fclose($childChannel);
		if(!is_resource($process)) throw new RuntimeException('Managed FPM deadline child could not start.');
		$started=hrtime(true);$header="00000001\n";
		for($index=0;$index<strlen($header);$index++){
			if($index>0) usleep(900000);
			$status=proc_get_status($process);
			if(($status['running'] ?? false)!==true){$running=false;$exitCode=(int)($status['exitcode'] ?? -1);break;}
			if(@fwrite($brokerChannel,$header[$index])!==1){$running=false;break;}
		}
		$deadline=$started+8_000_000_000;
		while($running && hrtime(true)<$deadline){
			$status=proc_get_status($process);
			if(($status['running'] ?? false)!==true){$running=false;$exitCode=(int)($status['exitcode'] ?? -1);break;}
			usleep(10000);
		}
		$elapsed=(hrtime(true)-$started)/1_000_000_000;
	}finally{
		if(is_resource($brokerChannel)) fclose($brokerChannel);
		if(is_resource($childChannel)) fclose($childChannel);
		if(is_resource($process)){
			$status=proc_get_status($process);
			if(($status['running'] ?? false)===true){@posix_kill((int)$status['pid'],SIGKILL);$running=true;}
			foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);
			proc_close($process);
		}
		dataphyre_application_runtime_fixed_port_unlock($portLock);
	}
	$t->isFalse($running);
	$t->isTrue($exitCode!==0);
	$t->isTrue($elapsed>=4.0 && $elapsed<=8.0,'Envelope deadline elapsed '.$elapsed.' seconds.');
})->tag('master-envelope','monotonic-deadline','slow-frame','negative','exact-image')->maxMillis(15000)
	->skipUnless(dataphyre_managed_fpm_exact_runtime(),'Requires the canonical root test image with exact matching PHP-FPM.');
