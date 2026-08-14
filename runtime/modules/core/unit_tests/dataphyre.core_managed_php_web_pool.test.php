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
