<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\CoverageParts;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/fixtures/application_runtime_fixed_port_lock.php';

require_once dirname(__DIR__).'/kernel/application_runtime_process_broker.php';
require_once dirname(__DIR__,2).'/testing/tooling/TestKit/CoverageParts.php';

suite('Application runtime single-use secret broker')
	->contract('core.application-runtime-secret-broker',1)
	->layer('integration')->risk('critical')->watches('module:core')->isolation('case')
	->through('socketpair','post-exec','pid-binding','no-new-privileges','zeroization')
	->tag('core','runtime','environment','secret','security','release')->group('framework-coverage');

/** @return array{resource:resource,pid:int,pipes:array<int,resource>,identity:array} */
function dataphyre_secret_broker_probe(string $role,string $secret): array
{
	$kernel=dirname(__DIR__).'/kernel';
	$fixture=__DIR__.'/fixtures/application_runtime_child_environment_probe.php';
	$project=(string)realpath(dirname(__DIR__,4));
	$managedKey=$role==='realtime' ? random_bytes(32) : null;
	$managed=$managedKey!==null
		? DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext($role,$project,$managedKey)
		: null;
	try{return DataphyreApplicationRuntimeProcessBroker::spawn([
		'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
		'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
		PHP_BINARY,'-d','display_errors=0','-d','log_errors=1','-d','user_ini.filename=',
		'-d','auto_prepend_file=','-d','auto_append_file=',$fixture,$role,hash('sha256',$secret),
	],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$project,[], $role,[
		'PROBE_SECRET'=>$secret,'DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$project,
	],5000,$managed);}finally{
		if(is_string($managedKey)) sodium_memzero($managedKey);
		if(is_string($managed['private_key'] ?? null)) sodium_memzero($managed['private_key']);
	}
}

function dataphyre_secret_broker_exact_root_runtime(): bool
{
	return function_exists('posix_geteuid') && posix_geteuid()===0
		&& getenv('DATAPHYRE_TEST_CONTAINER_ROOT')==='1'
		&& extension_loaded('dataphyre_environment_fd')
		&& phpversion('dataphyre_environment_fd')==='1.2.0'
		&& is_executable('/usr/bin/setpriv') && is_executable('/usr/local/bin/php-cgi');
}

test('realtime and one-shot receive one post-exec envelope and retain no transport capability',static function(Context $t): void {
	foreach(['realtime','one-shot'] as $role){
		$secret=$role.'-'.bin2hex(random_bytes(32));$child=dataphyre_secret_broker_probe($role,$secret);
		$out=stream_get_contents($child['pipes'][1]);$err=stream_get_contents($child['pipes'][2]);
		fclose($child['pipes'][1]);fclose($child['pipes'][2]);$exit=proc_close($child['resource']);
		$t->same(0,$exit,$role.': '.$err);$result=json_decode((string)$out,true,8,JSON_THROW_ON_ERROR);
		$t->same(true,$result['ok'],$role.' value');$t->same(true,$result['refetch_rejected'],$role.' refetch');
		$t->same(true,$result['descriptor_closed'],$role.' descriptor');
		$t->same(true,$result['secret_absent_from_proc'],$role.' proc');$t->same(true,$result['no_new_privileges'],$role.' nnp');
		$t->same(true,$result['pre_exec_closer_rejected'],$role.' pre-exec closer privilege');
		$t->same('0000000000000000',$result['cap_eff']);$t->same(10001,$result['uid']);
		$t->same(10001,$result['gid']);$t->same([10001],$result['groups']);
		$t->isFalse(str_contains((string)$out,$secret));$t->isFalse(str_contains((string)$err,$secret));
		sodium_memzero($secret);
	}
})->tag('multi-role','lifecycle','proc','fd-enumeration','replay')
	->skipUnless(dataphyre_secret_broker_exact_root_runtime(),'Requires the canonical root test image and native descriptor extension.');

test('managed web and scheduler contexts reject direct CLI execution before tenant bootstrap',static function(Context $t): void {
	$project=(string)realpath(dirname(__DIR__,4));$fixture=__DIR__.'/fixtures/application_runtime_child_environment_probe.php';
	foreach(['web','scheduler'] as $role){
		$secret='cli-forgery-'.bin2hex(random_bytes(16));$key=random_bytes(32);
		$managed=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext($role,$project,$key);
		$child=DataphyreApplicationRuntimeProcessBroker::spawn([
			'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
			PHP_BINARY,$fixture,$role,hash('sha256',$secret),
		],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$project,[],$role,[
			'PROBE_SECRET'=>$secret,'DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$project,
		],5000,$managed);
		$out=stream_get_contents($child['pipes'][1]);$err=stream_get_contents($child['pipes'][2]);
		fclose($child['pipes'][1]);fclose($child['pipes'][2]);$exit=proc_close($child['resource']);
		$t->same(78,$exit,$role);$t->same('',$out);$t->contains('execution boundary',$err);
		$t->isFalse(str_contains($err,$secret));$t->isFalse(str_contains($err,$managed['private_key']));
		sodium_memzero($secret);sodium_memzero($key);sodium_memzero($managed['private_key']);
	}
})->tag('managed-bootstrap','cli','wrong-sapi','negative')
	->skipUnless(dataphyre_secret_broker_exact_root_runtime(),'Requires the canonical root test image and native descriptor extension.');

test('typed managed bootstrap rejects wrong role root key and one-shot attachment',static function(Context $t): void {
	$project=(string)realpath(dirname(__DIR__,4));$target=DataphyreApplicationRuntimeChildEnvironment::target(getmypid(),posix_getppid());
	$key=random_bytes(32);$context=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext('web',$project,$key);
	$values=['DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$project];$nonce=str_repeat('c',64);
	$t->isTrue(str_contains(DataphyreApplicationRuntimeChildEnvironment::canonical('web',$nonce,$target,$values,$context),'managed_bootstrap'));
	$t->throws(static fn()=>DataphyreApplicationRuntimeChildEnvironment::canonical('web',$nonce,$target,$values),RuntimeException::class);
	$wrongRole=$context;$wrongRole['role']='scheduler';
	$t->throws(static fn()=>DataphyreApplicationRuntimeChildEnvironment::canonical('web',$nonce,$target,$values,$wrongRole),RuntimeException::class);
	$t->throws(static fn()=>DataphyreApplicationRuntimeChildEnvironment::canonical('web',$nonce,$target,['DATAPHYRE_RUNTIME_PROJECT_ROOT'=>'/tmp'],$context),RuntimeException::class);
	$malformed=$context;$malformed['private_key']=str_repeat('a',42);
	$t->throws(static fn()=>DataphyreApplicationRuntimeChildEnvironment::canonical('web',$nonce,$target,$values,$malformed),RuntimeException::class);
	$t->throws(static fn()=>DataphyreApplicationRuntimeChildEnvironment::canonical('one-shot',$nonce,$target,$values,$context),RuntimeException::class);
	$t->throws(static fn()=>DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext('web-gateway',$project,$key),RuntimeException::class);
	sodium_memzero($key);sodium_memzero($context['private_key']);
})->tag('managed-bootstrap','role','root','key','one-shot','negative');

test('child environment canonical and managed-bootstrap internals reject every malformed boundary',static function(Context $t): void {
	$root=(string)realpath(dirname(__DIR__,4));
	$target=DataphyreApplicationRuntimeChildEnvironment::target(getmypid(),posix_getppid());
	$nonce=str_repeat('d',64);$values=['DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$root];
	$key=random_bytes(32);$context=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext('web',$root,$key);
	$canonical=DataphyreApplicationRuntimeChildEnvironment::canonical('web',$nonce,$target,$values,$context);
	$internals=$t->nonPublic(DataphyreApplicationRuntimeChildEnvironment::class);

	$t->throws(static fn()=>$internals->invoke('decode','','web'),RuntimeException::class);
	$t->throws(static fn()=>$internals->invoke('decode',"{\n",'web'),RuntimeException::class);
	$wrongContract=json_decode($canonical,true,12,JSON_THROW_ON_ERROR);$wrongContract['contract']='wrong';
	$t->throws(static fn()=>$internals->invoke(
		'decode',json_encode($wrongContract,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",'web',
	),RuntimeException::class);
	$t->throws(static fn()=>$internals->invoke('decode',' '.$canonical,'web'),RuntimeException::class);

	$t->throws(static fn()=>DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext(
		'web','/definitely-missing-dataphyre-project-root',$key,
	),RuntimeException::class);
	$missingRoot=$context;$missingRoot['project_root']='/definitely-missing-dataphyre-project-root';
	$t->throws(static fn()=>$internals->invoke(
		'validateManagedBootstrap',$missingRoot,'web',['DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$missingRoot['project_root']],
	),RuntimeException::class);
	$invalidEncoding=$context;$invalidEncoding['private_key']=str_repeat('A',42).'_';
	$t->throws(static fn()=>$internals->invoke('validateManagedBootstrap',$invalidEncoding,'web',$values),RuntimeException::class);
	$t->throws(static fn()=>$internals->capture('establishManagedBootstrap',$context,'web',$values),RuntimeException::class);

	$internals->replacePropertyForTest('managedBootstrap',[
		'contract'=>DataphyreApplicationRuntimeChildEnvironment::MANAGED_BOOTSTRAP_CONTRACT,
		'role'=>'realtime','project_root'=>$root,'private_key'=>str_repeat('k',32),
	]);
	$t->throws(static fn()=>DataphyreApplicationRuntimeChildEnvironment::managedBootstrapPrivateKeyForCore(),RuntimeException::class);
	$internals->writeProperty('managedBootstrap',[
		'contract'=>DataphyreApplicationRuntimeChildEnvironment::MANAGED_BOOTSTRAP_CONTRACT,
		'role'=>'realtime','project_root'=>$root,'private_key'=>'invalid',
	]);
	$t->throws(static fn()=>$internals->invoke('assertActiveManagedBootstrap'),RuntimeException::class);

	$t->throws(static fn()=>$internals->invoke('validateTarget',[]),RuntimeException::class);
	$badAncestor=$target;$badAncestor['ancestry'][0]['pid']=0;
	$t->throws(static fn()=>$internals->invoke('validateTarget',$badAncestor),RuntimeException::class);
	$incomplete=$target;$last=array_key_last($incomplete['ancestry']);$incomplete['ancestry'][$last]['pid']=999999;
	$t->throws(static fn()=>$internals->invoke('validateTarget',$incomplete),RuntimeException::class);
	$t->throws(static fn()=>$internals->invoke('validateValues',['listed']),RuntimeException::class);
	$t->throws(static fn()=>$internals->invoke('validateValues',['lowercase'=>'value']),RuntimeException::class);
	$t->throws(static fn()=>$internals->invoke('parseProcessIdentity','1 (invalid) S','invalid',11),RuntimeException::class);
	$t->throws(static fn()=>$internals->invoke('closeNativeDescriptor',-1),RuntimeException::class);

	sodium_memzero($key);sodium_memzero($context['private_key']);
})->tag('canonical','managed-bootstrap','reflection-boundary','negative');

test('broker and gateway reject invalid invocation unavailable targets and wrong acknowledgements',static function(Context $t): void {
	$workspace=$t->workspace('secret-broker-invalid-channels');
	$invalidChannel=fopen($workspace->path('invalid-channel.bin'),'w+b');
	if(!is_resource($invalidChannel)) throw new RuntimeException('Could not create broker boundary stream.');
	$t->defer(static fn()=>is_resource($invalidChannel) ? fclose($invalidChannel) : null);
	$t->throws(static fn()=>DataphyreApplicationRuntimeChildEnvironment::broker(
		$invalidChannel,getmypid(),posix_getppid(),'one-shot',[],99,
	),RuntimeException::class);
	if(dataphyre_secret_broker_exact_root_runtime()){
		$unavailableChannel=fopen($workspace->path('unavailable-channel.bin'),'w+b');
		if(!is_resource($unavailableChannel)) throw new RuntimeException('Could not create unavailable-target stream.');
		$t->defer(static fn()=>is_resource($unavailableChannel) ? fclose($unavailableChannel) : null);
		$t->throws(static fn()=>DataphyreApplicationRuntimeChildEnvironment::broker(
			$unavailableChannel,2147483647,getmypid(),'one-shot',[],100,
		),RuntimeException::class);
	}
	$t->throws(static fn()=>DataphyreApplicationRuntimeChildEnvironment::consumeGateway('one-shot'),RuntimeException::class);
	$t->throws(static fn()=>DataphyreApplicationRuntimeChildEnvironment::consumeGateway(
		'web-gateway',DataphyreApplicationRuntimeChildEnvironment::INHERITED_FD-1,
	),RuntimeException::class);
})->tag('broker','gateway','timeout','negative');

test('broker rejects a post-exec child acknowledgement that is not canonical',static function(Context $t): void {
	if(!dataphyre_secret_broker_exact_root_runtime()){$t->same(true,true);return;}
	[$broker,$childChannel]=DataphyreApplicationRuntimeChildEnvironment::socketPair();$pipes=[];
	$child=proc_open([ // dataphyre-test-architecture: exempt[raw-process-control] reason="Exact malformed acknowledgement proof requires controlling the inherited broker endpoint."
		'/usr/bin/setpriv','--reuid=0','--regid=0','--groups=0','--no-new-privs',
		'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all,+setuid,+setgid','--pdeathsig=SIGKILL',
		PHP_BINARY,'-r','$s=fopen("php://fd/198","r+");$h=fgets($s,10);$n=hexdec(substr($h,0,8));'.
			'$b="";while(strlen($b)<$n){$b.=fread($s,$n-strlen($b));}fwrite($s,"wrong\\n");fflush($s);',
	],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w'],DataphyreApplicationRuntimeChildEnvironment::INHERITED_FD=>$childChannel],
		$pipes,dirname(__DIR__,4),[],['bypass_shell'=>true]);
	fclose($childChannel);
	if(!is_resource($child)) throw new RuntimeException('Malformed acknowledgement child could not start.');
	$status=proc_get_status($child);$pid=(int)($status['pid'] ?? 0);
	$root=(string)realpath(dirname(__DIR__,4));$key=random_bytes(32);
	$context=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext('web',$root,$key);
	try{
		$t->throws(static fn()=>DataphyreApplicationRuntimeChildEnvironment::broker(
			$broker,$pid,getmypid(),'web-gateway',['DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$root],5000,$context,
		),RuntimeException::class);
	}finally{
		foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);
		proc_close($child);sodium_memzero($key);sodium_memzero($context['private_key']);
	}
})->tag('broker','acknowledgement','post-exec','negative');

test('environment and global values cannot manufacture managed bootstrap attestation',static function(Context $t): void {
	$t->same(null,DataphyreApplicationRuntimeChildEnvironment::managedBootstrapAttestation());
	$t->environment(['DATAPHYRE_INTERNAL_MANAGED_RUNTIME_BOOTSTRAP'=>'forged']);
	$t->global('DATAPHYRE_INTERNAL_MANAGED_RUNTIME_BOOTSTRAP')->replace([
		'contract'=>DataphyreApplicationRuntimeChildEnvironment::MANAGED_BOOTSTRAP_CONTRACT,
		'role'=>'realtime','project_root'=>dirname(__DIR__,4),'private_key'=>str_repeat('a',43),
	]);
	$t->same(null,DataphyreApplicationRuntimeChildEnvironment::managedBootstrapAttestation());
})->tag('managed-bootstrap','environment','global','forgery','negative');

test('canonical envelope accepts exactly 524288 bytes and rejects one byte more',static function(Context $t): void {
	$target=DataphyreApplicationRuntimeChildEnvironment::target(getmypid(),posix_getppid());
	$values=[];
	for($index=0;$index<7;$index++) $values['VALUE_'.$index]=str_repeat(chr(65+$index),65536);
	$nonce=str_repeat('a',64);
	$base=DataphyreApplicationRuntimeChildEnvironment::canonical('one-shot',$nonce,$target,$values+['VALUE_7'=>'']);
	$needed=DataphyreApplicationRuntimeChildEnvironment::MAX_BYTES-strlen($base);
	$t->isTrue($needed>0 && $needed<=65536);
	$values['VALUE_7']=str_repeat('Z',$needed);
	$exact=DataphyreApplicationRuntimeChildEnvironment::canonical('one-shot',$nonce,$target,$values);
	$t->same(DataphyreApplicationRuntimeChildEnvironment::MAX_BYTES,strlen($exact));
	$values['VALUE_7'].='Z';
	$t->throws(
		static fn()=>DataphyreApplicationRuntimeChildEnvironment::canonical('one-shot',$nonce,$target,$values),
		RuntimeException::class,
	);
	foreach($values as &$value) sodium_memzero($value);unset($value);
})->tag('524288-bytes','boundary','negative');

test('same uid sibling has no claimable descriptor while the bound child is waiting',static function(Context $t): void {
	[$broker,$childChannel]=DataphyreApplicationRuntimeChildEnvironment::socketPair();
	$fixture=__DIR__.'/fixtures/application_runtime_child_environment_probe.php';$secret='race-'.bin2hex(random_bytes(32));
	$descriptors=[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w'],DataphyreApplicationRuntimeChildEnvironment::INHERITED_FD=>$childChannel];$pipes=[];
	$target=proc_open([ // dataphyre-test-architecture: exempt[raw-process-control] reason="Inherited descriptor race proof requires a concurrently waiting native process."
		'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
		'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all',PHP_BINARY,$fixture,'one-shot',hash('sha256',$secret),
	],$descriptors,$pipes,dirname(__DIR__,4),[],['bypass_shell'=>true]);
	fclose($childChannel);$targetStatus=proc_get_status($target);$targetPid=(int)$targetStatus['pid'];
	$siblingPipes=[];$sibling=proc_open([ // dataphyre-test-architecture: exempt[raw-process-control] reason="Sibling descriptor exclusion is the native process primitive under test."
		'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
		'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all',PHP_BINARY,'-r',
		'$h=@fopen("php://fd/198","rb");echo is_resource($h)?"claimable":"closed";',
	],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$siblingPipes,dirname(__DIR__,4),[],['bypass_shell'=>true]);
	$siblingOut=stream_get_contents($siblingPipes[1]);$siblingErr=stream_get_contents($siblingPipes[2]);
	fclose($siblingPipes[1]);fclose($siblingPipes[2]);$t->same(0,proc_close($sibling),$siblingErr);$t->same('closed',$siblingOut);
	DataphyreApplicationRuntimeChildEnvironment::broker(
		$broker,$targetPid,getmypid(),'one-shot',['PROBE_SECRET'=>$secret],
	);
	$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
	$t->same(0,proc_close($target),$err);$t->same(true,json_decode((string)$out,true,8,JSON_THROW_ON_ERROR)['ok']);
	$t->isFalse(str_contains((string)$out,$secret));sodium_memzero($secret);
})->tag('same-uid','sibling','race','claim','negative')
	->skipUnless(dataphyre_secret_broker_exact_root_runtime(),'Requires the canonical root test image and native descriptor extension.');

test('a stale start time is rejected before tenant bootstrap and produces no acknowledgement',static function(Context $t): void {
	[$broker,$childChannel]=DataphyreApplicationRuntimeChildEnvironment::socketPair();
	$frameworkRoot=dirname(__DIR__,4);$fixture=__DIR__.'/fixtures/application_runtime_child_environment_probe.php';$pipes=[];
	$coverageBootstrap=$frameworkRoot.'/runtime/modules/testing/tooling/CoverageSubprocess.php';
	$coverageState=$t->workspace('core-child-environment-stale-binding');chmod($coverageState->root(),0777);
	$part=$coverageState->path('coverage.json');
	$process=proc_open([ // dataphyre-test-architecture: exempt[raw-process-control] reason="Stale PID identity proof requires controlling the inherited descriptor handshake."
		'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
		'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all',PHP_BINARY,$coverageBootstrap,$fixture,'one-shot',hash('sha256','stale'),
	],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w'],DataphyreApplicationRuntimeChildEnvironment::INHERITED_FD=>$childChannel],$pipes,$frameworkRoot,[
		'DATAPHYRE_TEST_COVERAGE_PART'=>$part,'DATAPHYRE_TEST_COVERAGE_FRAMEWORK_ROOT'=>$frameworkRoot,
		'DATAPHYRE_TEST_COVERAGE_RESULT_ROOT'=>$frameworkRoot,'XDEBUG_MODE'=>'coverage',
		'PHP_INI_SCAN_DIR'=>(string)getenv('PHP_INI_SCAN_DIR'),
	],['bypass_shell'=>true]);
	fclose($childChannel);$status=proc_get_status($process);$pid=(int)$status['pid'];
	$deadline=microtime(true)+3.0;
	do{try{$target=DataphyreApplicationRuntimeChildEnvironment::target($pid,getmypid());break;}catch(Throwable){usleep(1000);}}while(microtime(true)<$deadline);
	$target['start_time_ticks']=(string)((int)$target['start_time_ticks']+1);
	$bytes=DataphyreApplicationRuntimeChildEnvironment::canonical('one-shot',str_repeat('b',64),$target,['PROBE_SECRET'=>'stale']);
	fwrite($broker,sprintf("%08x\n",strlen($bytes)).$bytes);fflush($broker);stream_set_timeout($broker,2,0);
	$t->same(false,fgets($broker,513));fclose($broker);
	$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
	$t->same(78,proc_close($process),$err);$t->same('',$out);
	$decoded=is_file($part) ? json_decode((string)file_get_contents($part),true) : null;
	if(!is_array($decoded)) throw new RuntimeException('Stale-binding child did not return exact coverage evidence.');
	CoverageParts::add($decoded);
})->tag('pid-reuse','start-time','negative')
	->skipUnless(dataphyre_secret_broker_exact_root_runtime(),'Requires the canonical root test image and native descriptor extension.');

test('inherited environment fails closed on missing native support and malformed framing',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$fixture=__DIR__.'/fixtures/application_runtime_child_environment_probe.php';
	$missingNative=$t->coveredPhpProcess(
		[$fixture,'one-shot',hash('sha256','unused')],framework_root:$frameworkRoot,
		php_ini:['disable_functions'=>'dataphyre_open_inherited_environment_fd'],
	);
	$t->processFailed($missingNative,78,$missingNative->stderr());
	$t->contains('native descriptor support is unavailable',$missingNative->stderr());
	if(!dataphyre_secret_broker_exact_root_runtime()) return;

	[$broker,$childChannel]=DataphyreApplicationRuntimeChildEnvironment::socketPair();$pipes=[];
	$coverageBootstrap=$frameworkRoot.'/runtime/modules/testing/tooling/CoverageSubprocess.php';
	$coverageState=$t->workspace('core-child-environment-framing');chmod($coverageState->root(),0777);
	$part=$coverageState->path('coverage.json');
	$process=proc_open([ // dataphyre-test-architecture: exempt[raw-process-control] reason="Exact inherited framing proof requires controlling descriptor 198 before bootstrap."
		'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
		'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all',
		PHP_BINARY,$coverageBootstrap,$fixture,'one-shot',hash('sha256','unused'),
	],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w'],DataphyreApplicationRuntimeChildEnvironment::INHERITED_FD=>$childChannel],$pipes,$frameworkRoot,[
		'DATAPHYRE_TEST_COVERAGE_PART'=>$part,'DATAPHYRE_TEST_COVERAGE_FRAMEWORK_ROOT'=>$frameworkRoot,
		'DATAPHYRE_TEST_COVERAGE_RESULT_ROOT'=>$frameworkRoot,'XDEBUG_MODE'=>'coverage',
		'PHP_INI_SCAN_DIR'=>(string)getenv('PHP_INI_SCAN_DIR'),
	],['bypass_shell'=>true]);
	fclose($childChannel);
	if(!is_resource($process)) throw new RuntimeException('Malformed framing child could not start.');
	fwrite($broker,"notframe\n");fflush($broker);fclose($broker);
	$out=(string)stream_get_contents($pipes[1]);$err=(string)stream_get_contents($pipes[2]);
	fclose($pipes[1]);fclose($pipes[2]);$t->same(78,proc_close($process),$err);$t->same('',$out);
	$t->contains('framing is invalid',$err);
	$decoded=is_file($part) ? json_decode((string)file_get_contents($part),true) : null;
	if(!is_array($decoded)) throw new RuntimeException('Malformed-framing child did not return exact coverage evidence.');
	CoverageParts::add($decoded);
})->tag('native-extension','framing','exact-coverage','negative');

test('socket exhaustion and over-deep process ancestry fail closed with exact evidence',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$fixture=__DIR__.'/fixtures/application_runtime_child_environment_boundary.php';
	$socketPair=$t->coveredPhpProcess([$fixture,'socket-pair'],framework_root:$frameworkRoot);
	$t->processSucceeded($socketPair,json_encode($socketPair->diagnostic(),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
	$t->same(true,$socketPair->json()['rejected']);

	$coverageBootstrap=$frameworkRoot.'/runtime/modules/testing/tooling/CoverageSubprocess.php';
	$part=$t->workspace('core-child-environment-deep-ancestry')->path('coverage.json');
	$ancestry=$t->process([
		PHP_BINARY,$fixture,'wrap','18',$coverageBootstrap,$part,$frameworkRoot,(string)getenv('PHP_INI_SCAN_DIR'),
	],working_directory:$frameworkRoot,timeout_millis:30000);
	$t->processSucceeded($ancestry,json_encode($ancestry->diagnostic(),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
	$t->same('ancestry-rejected',$ancestry->stdout());
	$decoded=is_file($part) ? json_decode((string)file_get_contents($part),true) : null;
	if(!is_array($decoded)) throw new RuntimeException('Deep-ancestry child did not return exact coverage evidence.');
	CoverageParts::add($decoded);
})->tag('socketpair','resource-exhaustion','ancestry','exact-coverage','negative');

test('web gateway executes every request in a distinct capability-free php-cgi process',static function(Context $t): void {
	$fixedPortLock=dataphyre_application_runtime_fixed_port_lock();
	$kernel=(string)realpath(dirname(__DIR__).'/kernel');$gateway=$kernel.'/application_runtime_cgi_gateway.php';
	$router=(string)realpath(__DIR__.'/fixtures/application_runtime_cgi_probe.php');$project=(string)realpath(dirname(__DIR__,4));
	$secret='cgi-'.bin2hex(random_bytes(32));$expected=hash('sha256',$secret);
	$managedKey=random_bytes(32);$managedKeySha=hash('sha256',$managedKey);
	$managed=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext('web',$project,$managedKey);
	$gatewayPort=8083;
	$gatewayProcess=DataphyreApplicationRuntimeProcessBroker::spawn([
		'/usr/bin/setpriv','--reuid=0','--regid=0','--groups=0','--no-new-privs',
		'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all,+setuid,+setgid','--pdeathsig=SIGKILL',
		PHP_BINARY,'-d','display_errors=0',
		$gateway,'web','127.0.0.1',(string)$gatewayPort,$router,$project,
	],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$project,[],'web-gateway',[
		'PROBE_SECRET'=>$secret,'DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$project,
	],5000,$managed);
	$t->isFalse(str_contains((string)@file_get_contents('/proc/'.$gatewayProcess['pid'].'/environ'),$secret));
	$t->isFalse(str_contains((string)@file_get_contents('/proc/'.$gatewayProcess['pid'].'/cmdline'),$secret));
	$diagnosticFailure=null;
	try{
		$results=[];
		usleep(200000);
		for($request=0;$request<2;$request++){
			$socket=null;$deadline=microtime(true)+3.0;$errno=0;$error='';
			do{
				$socket=@stream_socket_client('tcp://127.0.0.1:'.$gatewayPort,$errno,$error,0.1);
				if(is_resource($socket)) break;
				usleep(10000);
			}while(microtime(true)<$deadline);
			if(!is_resource($socket)) throw new RuntimeException('Gateway connection '.$request.' errno='.$errno.' error='.$error);
			stream_set_timeout($socket,5,0);
			$raw="GET /probe?request={$request} HTTP/1.1\r\nHost: 127.0.0.1:{$gatewayPort}\r\nX-Probe-Secret-Sha256: {$expected}\r\nX-Probe-Managed-Key-Sha256: {$managedKeySha}\r\nConnection: close\r\n\r\n";
			fwrite($socket,$raw);$response=stream_get_contents($socket);fclose($socket);
			if(str_contains((string)$response,$secret)) throw new RuntimeException('Gateway response exposed its application secret.');
			[$head,$body]=array_pad(explode("\r\n\r\n",(string)$response,2),2,'');
			if(preg_match('/^HTTP\/1\.1 200\b/D',$head)!==1) throw new RuntimeException('Gateway response '.$request.': '.$head);
			$results[]=json_decode($body,true,8,JSON_THROW_ON_ERROR);
		}
		$t->same(true,$results[0]['ok']);$t->same(true,$results[1]['ok']);
		$t->isFalse($results[0]['pid']===$results[1]['pid']);
		foreach($results as $result){
			$t->same(10001,$result['uid']);$t->same(10001,$result['gid']);$t->same([10001],$result['groups']);
			$t->same('0000000000000000',$result['cap_eff']);$t->same(true,$result['no_new_privileges']);
			$t->same(true,$result['broker_descriptor_closed']);
			$t->same([],$result['unexpected_descriptors']);
			$t->same(true,$result['secret_absent_from_proc']);
			$t->same(true,$result['managed_bootstrap']);$t->same(true,$result['managed_private_key_matches']);
			$t->same(true,$result['managed_private_key_absent_from_proc_and_environment']);
			$t->same(true,$result['legacy_source_writes_suppressed']);
			$t->same(true,$result['pre_exec_closer_rejected']);
		}
	}catch(Throwable $failure){$diagnosticFailure=$failure;
	}finally{
		$statusBeforeStop=proc_get_status($gatewayProcess['resource']);
		@posix_kill($gatewayProcess['pid'],15);$deadline=microtime(true)+5.0;
		do{$status=proc_get_status($gatewayProcess['resource']);if(($status['running'] ?? false)!==true) break;usleep(10000);}while(microtime(true)<$deadline);
		$status=proc_get_status($gatewayProcess['resource']);if(($status['running'] ?? false)===true) @posix_kill($gatewayProcess['pid'],9);
		foreach($gatewayProcess['pipes'] as $pipe) if(is_resource($pipe)) stream_set_blocking($pipe,false);
		$gatewayStdout=is_resource($gatewayProcess['pipes'][1] ?? null) ? stream_get_contents($gatewayProcess['pipes'][1]) : '';
		$gatewayStderr=is_resource($gatewayProcess['pipes'][2] ?? null) ? stream_get_contents($gatewayProcess['pipes'][2]) : '';
		foreach($gatewayProcess['pipes'] as $pipe) if(is_resource($pipe)) fclose($pipe);
		proc_close($gatewayProcess['resource']);sodium_memzero($secret);sodium_memzero($managedKey);
		sodium_memzero($managed['private_key']);
		dataphyre_application_runtime_fixed_port_unlock($fixedPortLock);
		if($diagnosticFailure!==null || !isset($results) || count($results)!==2){
			throw new RuntimeException(
				'Gateway failure='.($diagnosticFailure?->getMessage() ?? 'none').' status='.json_encode($statusBeforeStop)
				.' results='.json_encode($results ?? null).' stdout='.$gatewayStdout.' stderr='.$gatewayStderr,
				0,$diagnosticFailure,
			);
		}
	}
})->tag('cgi','one-request-per-process','exact-image','multi-request','no-new-privileges')->maxMillis(60000)
	->skipUnless(dataphyre_secret_broker_exact_root_runtime(),'Requires the canonical root test image and native descriptor extension.');

test('source contract contains no reusable child secret file or persistent php development server',static function(Context $t): void {
	$kernel=dirname(__DIR__).'/kernel';
	$child=(string)file_get_contents($kernel.'/application_runtime_child_environment.php');
	$gateway=(string)file_get_contents($kernel.'/application_runtime_cgi_gateway.php');
	$supervisor=(string)file_get_contents($kernel.'/application_runtime_supervisor.php');
	$preExec=(string)file_get_contents($kernel.'/application_runtime_pre_exec.php');
	$native=(string)file_get_contents(dirname(__DIR__,3).'/native/environment_fd/dataphyre_environment_fd.c');
	$bootstrap=(string)file_get_contents(dirname(__DIR__,3).'/bootstrap.php');
	$core=(string)file_get_contents($kernel.'/core.main.php');
	$helpers=(string)file_get_contents($kernel.'/helper_functions.php');
	foreach([$child,$gateway,$supervisor] as $source){
		$t->isFalse(str_contains($source,'application-environment.runtime.json'));
		$t->isFalse(str_contains($source,'consumeRuntimeFile'));
		$t->isFalse(str_contains($source,'PHP_CLI_SERVER_WORKERS'));
	}
	$t->isFalse(str_contains($supervisor,"'-S'"));$t->isFalse(str_contains($supervisor,' -S '));
	$t->contains("'/usr/local/bin/php-cgi'",$gateway);$t->isFalse(str_contains($gateway,"'-n'"));
	$t->contains("public const INHERITED_FD=198",$child);$t->contains('start_time_ticks',$child);
	$t->contains("'--no-new-privs'",$supervisor);$t->contains("'--no-new-privs'",$gateway);
	$t->isFalse(is_file($kernel.'/application_runtime_pool_launcher.php'));
	$t->contains('fd != 198',$native);$t->contains('close((int) fd)',$native);
	$t->contains('dataphyre_close_unlisted_inherited_fds',$native);
	$t->contains('geteuid() != 0',$native);
	$t->contains('dataphyre_close_unlisted_inherited_fds()!==true',$preExec);
	$t->contains('pcntl_exec($executable,$arguments,$environment)',$preExec);
	$t->contains('dataphyre_internal_managed_runtime_bootstrap_context',$bootstrap);
	$t->contains("return '/var/log/dataphyre/'",$bootstrap);
	$t->contains('$application_release_preflight===null && $managed_runtime_bootstrap===null',$bootstrap);
	$t->contains('$applicationReleasePreflight!==null || $managedRuntimeBootstrap!==null || $applicationBootstrapOnly!==null',$core);
	$t->contains('$applicationReleasePreflight===null && $managedRuntimeBootstrap===null',$core);
	$t->contains('function dp_source_local_runtime_writes_allowed()',$helpers);
	$t->contains('managedBootstrapPrivateKeyForCore',$helpers);
	$functions=(string)file_get_contents($kernel.'/core_functions.php');
	$t->contains("if(!function_exists('dp_source_local_runtime_writes_allowed') || dp_source_local_runtime_writes_allowed())",$functions);
	foreach([$child,$gateway,$supervisor,$bootstrap,$core,$helpers] as $source){
		$t->isFalse(str_contains($source,'DATAPHYRE_MANAGED_RUNTIME'));
	}
})->tag('source','deletion','cgi','native-extension');
