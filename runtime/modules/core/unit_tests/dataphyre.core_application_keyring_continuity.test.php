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

require_once dirname(__DIR__).'/kernel/application_runtime_process_broker.php';

suite('Stable application keyrings across managed runtime identities')
	->contract('core.application-keyring-continuity',1)
	->layer('integration')->risk('critical')->watches('module:core')->isolation('case')
	->through('native-broker','concurrent-processes','restart','stored-ciphertext','key-rotation')
	->tag('core','runtime','security','private-key')->group('framework-coverage');

function dp_keyring_exact_runtime(): bool {
	return function_exists('posix_geteuid') && posix_geteuid()===0
		&& getenv('DATAPHYRE_TEST_CONTAINER_ROOT')==='1'
		&& phpversion('dataphyre_environment_fd')==='1.2.0' && is_executable('/usr/bin/setpriv');
}

/** Each new process has a distinct ephemeral identity, including replacement processes. */
function dp_keyring_start(string $project,mixed $keyring): array {
	$seed=random_bytes(32);
	$managed=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext('realtime',$project,$seed);
	try{
		$child=DataphyreApplicationRuntimeProcessBroker::spawn([
			'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
			PHP_BINARY,'-d','display_errors=0','-d','log_errors=0',
			__DIR__.'/fixtures/application_runtime_keyring_worker.php',
		],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$project,[],'realtime',[
			'DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$project,'PROBE_KEYRING'=>json_encode($keyring,JSON_THROW_ON_ERROR),
		],5000,$managed);
		stream_set_timeout($child['pipes'][1],5);
		$child['ready']=dp_keyring_read($child);
		return $child;
	}finally{sodium_memzero($seed);sodium_memzero($managed['private_key']);}
}
function dp_keyring_read(array $child): array {
	$line=fgets($child['pipes'][1]);
	if(!is_string($line)) throw new RuntimeException('Managed keyring fixture did not reply.');
	return json_decode($line,true,16,JSON_THROW_ON_ERROR);
}
function dp_keyring_request(array $child,array $request): array {
	$line=json_encode($request,JSON_THROW_ON_ERROR)."\n";
	if(fwrite($child['pipes'][0],$line)!==strlen($line)) throw new RuntimeException('Managed keyring request failed.');
	fflush($child['pipes'][0]);return dp_keyring_read($child);
}
function dp_keyring_close(array $child): array {
	fclose($child['pipes'][0]);$out=stream_get_contents($child['pipes'][1]);$err=stream_get_contents($child['pipes'][2]);
	fclose($child['pipes'][1]);fclose($child['pipes'][2]);
	return ['exit'=>proc_close($child['resource']),'stdout'=>$out,'stderr'=>$err];
}
function dp_keyring_project(Context $t,string $name): string {
	$workspace=$t->workspace($name);chmod($workspace->root(),0755);
	return $workspace->root();
}

test('two concurrent managed instances and a replacement read the same stored ciphertext and signature',static function(Context $t): void {
	$project=dp_keyring_project($t,'keyring-concurrent');$key=bin2hex(random_bytes(32));$children=[];
	try{
		$children[]=dp_keyring_start($project,[$key]);$children[]=dp_keyring_start($project,[$key]);
		$t->isFalse($children[0]['ready']['pid']===$children[1]['ready']['pid']);
		foreach($children as $child){
			$t->same(true,$child['ready']['managed']);$t->same(10001,$child['ready']['uid']);
			$t->same(true,$child['ready']['bootstrap_key_guarded']);
		}
		$stored=dp_keyring_request($children[0],['operation'=>'write','plaintext'=>'persistent fixture record']);
		$t->same(true,$stored['ok']);$t->same(hash('sha256',$key),$stored['active_sha256']);
		$request=['operation'=>'read','plaintext'=>'persistent fixture record',...$stored];
		$other=dp_keyring_request($children[1],$request);
		$t->same('persistent fixture record',$other['plaintext']);$t->same(true,$other['verified']);
		$oldPid=$children[0]['ready']['pid'];$closed=dp_keyring_close(array_shift($children));
		$t->same(['exit'=>0,'stdout'=>'','stderr'=>''],$closed);
		$children[]=dp_keyring_start($project,[$key]);$replacement=$children[1];
		$t->isFalse($oldPid===$replacement['ready']['pid']);
		$after=dp_keyring_request($replacement,$request);
		$t->same('persistent fixture record',$after['plaintext']);$t->same(true,$after['verified']);
		$new=dp_keyring_request($replacement,['operation'=>'write','plaintext'=>'replacement record']);
		$back=dp_keyring_request($children[0],['operation'=>'read','plaintext'=>'replacement record',...$new]);
		$t->same('replacement record',$back['plaintext']);$t->same(true,$back['verified']);
	}finally{foreach($children as $child) $t->same(['exit'=>0,'stdout'=>'','stderr'=>''],dp_keyring_close($child));}
})->skipUnless(dp_keyring_exact_runtime(),'Requires the canonical root test image and native descriptor extension.');

test('rotation preserves old slots and signs with the appended key while different applications remain isolated',static function(Context $t): void {
	$project=dp_keyring_project($t,'keyring-rotation');$otherProject=dp_keyring_project($t,'keyring-other');
	$old=bin2hex(random_bytes(32));$new=bin2hex(random_bytes(32));$children=[];
	try{
		$children[]=dp_keyring_start($project,[$old]);$children[]=dp_keyring_start($project,[$old,$new]);
		$children[]=dp_keyring_start($otherProject,[bin2hex(random_bytes(32))]);
		$stored=dp_keyring_request($children[0],['operation'=>'write','plaintext'=>'record before rotation']);
		$t->same(0,$stored['slot']);$t->same(true,str_starts_with($stored['ciphertext'],'0:0:'));
		$request=['operation'=>'read','plaintext'=>'record before rotation',...$stored];
		$rotated=dp_keyring_request($children[1],$request);
		$t->same('record before rotation',$rotated['plaintext']);$t->same(true,$rotated['verified']);
		$current=dp_keyring_request($children[1],['operation'=>'write','plaintext'=>'record after rotation']);
		$t->same(1,$current['slot']);$t->same(hash('sha256',$new),$current['active_sha256']);
		$t->same(true,str_starts_with($current['ciphertext'],'0:1:'));
		$t->same(hash_hmac('sha256','record after rotation',$new),$current['signature']);
		$foreign=dp_keyring_request($children[2],$request);
		$t->isFalse($foreign['plaintext']==='record before rotation');$t->same(false,$foreign['verified']);
	}finally{foreach($children as $child) $t->same(['exit'=>0,'stdout'=>'','stderr'=>''],dp_keyring_close($child));}
})->skipUnless(dp_keyring_exact_runtime(),'Requires the canonical root test image and native descriptor extension.');

test('managed static keyrings retain exact legacy ordering and reject malformed or absent keys',static function(Context $t): void {
	$project=dp_keyring_project($t,'keyring-static');mkdir($project.'/config/static',0755,true);
	$keys=[' legacy slot zero ',bin2hex(random_bytes(32))];
	file_put_contents($project.'/config/static/dpvk',implode(',',$keys));chmod($project.'/config/static/dpvk',0444);
	$children=[];
	try{
		$children[]=dp_keyring_start($project,['unused configured key']);
		$stored=dp_keyring_request($children[0],['operation'=>'write','plaintext'=>'static key record']);
		$t->same(1,$stored['slot']);$t->same(hash('sha256',$keys[1]),$stored['active_sha256']);
		$legacy=dp_keyring_project($t,'keyring-static-legacy');mkdir($legacy.'/config/static',0755,true);
		file_put_contents($legacy.'/config/static/dpvk',$keys[0]);chmod($legacy.'/config/static/dpvk',0444);
		$children[]=dp_keyring_start($legacy,['unused legacy configured key']);
		$old=dp_keyring_request($children[1],['operation'=>'write','plaintext'=>'legacy exact key bytes']);
		$t->same(0,$old['slot']);$t->same(hash('sha256',$keys[0]),$old['active_sha256']);
		$read=dp_keyring_request($children[0],['operation'=>'read','plaintext'=>'legacy exact key bytes',...$old]);
		$t->same('legacy exact key bytes',$read['plaintext']);$t->same(true,$read['verified']);
		$clean=dp_keyring_project($t,'keyring-missing');
		foreach([[], '', [''], ['valid',''], ['named'=>'key'], [12]] as $invalid){
			$child=dp_keyring_start($clean,$invalid);$children[]=$child;
			$t->same(true,$child['ready']['managed']);
			$t->same(['ok'=>false,'key_unavailable'=>true],dp_keyring_request($child,['operation'=>'write','plaintext'=>'must not be encrypted']));
		}
		$invalidStatic=dp_keyring_project($t,'keyring-invalid-static');mkdir($invalidStatic.'/config/static',0755,true);
		file_put_contents($invalidStatic.'/config/static/dpvk','');chmod($invalidStatic.'/config/static/dpvk',0444);
		$child=dp_keyring_start($invalidStatic,['valid fallback must not hide corrupt static file']);$children[]=$child;
		$t->same(['ok'=>false,'key_unavailable'=>true],dp_keyring_request($child,['operation'=>'write','plaintext'=>'must fail']));
	}finally{foreach($children as $child) $t->same(['exit'=>0,'stdout'=>'','stderr'=>''],dp_keyring_close($child));}
})->skipUnless(dp_keyring_exact_runtime(),'Requires the canonical root test image and native descriptor extension.');
