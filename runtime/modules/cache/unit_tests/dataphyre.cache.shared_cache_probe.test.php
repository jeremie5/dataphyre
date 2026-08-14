<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Cache\SharedCacheProbeCommand;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/Framework/SharedCacheProbeCommand.php';

/** @param array<string,mixed> $runtime @return array{status:int,out:string,error:string,payload:array<string,mixed>} */
function dp_shared_cache_probe_run(string $phase,array $runtime=[]): array {
	$out='';$error='';
	$defaults=[
		'environment_values'=>[
			'DATAPHYRE_APPLICATION_ID'=>'fixture-app',
			'DATAPHYRE_FRAMEWORK_APPLICATION'=>'FixtureApp',
			'DATAPHYRE_ENVIRONMENT'=>'Staging.Blue',
			'DATAPHYRE_APPLICATION_RELEASE'=>'dep_'.str_repeat('a',40),
		],
		'extension_version'=>static fn(): string=>'3.4.0',
		'declared_shared'=>static fn(): bool=>true,
	];
	$status=SharedCacheProbeCommand::main([
		'shared_cache_probe.php','--phase='.$phase,'--challenge='.str_repeat('b',64),
	],array_replace($defaults,$runtime,[
		'write_out'=>static function(string $value) use (&$out): int {$out.=$value;return strlen($value);},
		'write_error'=>static function(string $value) use (&$error): int {$error.=$value;return strlen($value);},
	]));
	$payload=json_decode($out!=='' ? $out : $error,true,16,JSON_THROW_ON_ERROR);
	return ['status'=>$status,'out'=>$out,'error'=>$error,'payload'=>$payload];
}

test('shared cache detect is networkless canonical and reports only declared backend shape',static function(Context $t): void {
	$network=static fn()=>throw new RuntimeException('detect must not touch the network');
	$detect=dp_shared_cache_probe_run('detect',[
		'is_shared'=>$network,'get'=>$network,'set'=>$network,'delete'=>$network,
	]);
	$t->same(0,$detect['status']);$t->same('',$detect['error']);
	$t->same([
		'backend'=>'memcached','contract'=>SharedCacheProbeCommand::CONTRACT,'contract_version'=>1,
		'exit_status'=>0,'ok'=>true,'phase'=>'detect','probe_sha256'=>$detect['payload']['probe_sha256'],
		'shared'=>true,
	],$detect['payload']);
	$t->matches('/^sha256:[a-f0-9]{64}$/D',$detect['payload']['probe_sha256']);
	$t->lessThanOrEqual(SharedCacheProbeCommand::MAX_OUTPUT_BYTES,strlen($detect['out']));
	$keys=array_keys($detect['payload']);$sorted=$keys;sort($sorted,SORT_STRING);$t->same($sorted,$keys);

	$local=dp_shared_cache_probe_run('detect',['declared_shared'=>static fn(): bool=>false]);
	$t->same(0,$local['status']);$t->same(false,$local['payload']['shared']);
})->tag('cache','shared','probe','detect','networkless','release')->group('framework-coverage');

test('separate write and read-delete phases prove one internally derived shared value',static function(Context $t): void {
	$stored=[];$sharedChecks=0;
	$write=dp_shared_cache_probe_run('write',[
		'is_shared'=>static function() use (&$sharedChecks): bool {$sharedChecks++;return true;},
		'set'=>static function(string $key,string $value,int $ttl) use (&$stored): bool {
			$stored=compact('key','value','ttl');return true;
		},
	]);
	$t->same(0,$write['status']);$t->same(2,$sharedChecks);
	$t->same(true,$write['payload']['stored']);$t->same(120,$write['payload']['ttl_seconds']);
	$t->contains('dataphyre:shared-cache-probe:v1:',$stored['key']);
	$t->lessThanOrEqual(250,strlen($stored['key']));
	$t->matches('/^sha256:[a-f0-9]{64}$/D',$stored['value']);$t->same(120,$stored['ttl']);
	$t->isFalse(str_contains($write['out'],str_repeat('b',64)));

	$deleted=false;$reads=0;$sharedChecks=0;
	$read=dp_shared_cache_probe_run('read-delete',[
		'is_shared'=>static function() use (&$sharedChecks): bool {$sharedChecks++;return true;},
		'get'=>static function(string $key) use (&$reads,$stored,$t): ?string {
			$t->same($stored['key'],$key);$reads++;return $reads===1 ? $stored['value'] : null;
		},
		'delete'=>static function(string $key) use (&$deleted,$stored,$t): bool {
			$t->same($stored['key'],$key);$deleted=true;return true;
		},
	]);
	$t->same(0,$read['status']);$t->same(true,$deleted);$t->same(2,$reads);$t->same(4,$sharedChecks);
	$t->hasPathValues(['matched'=>true,'deleted'=>true,'missing_after_delete'=>true,'shared'=>true],$read['payload']);
	$t->same($write['payload']['probe_sha256'],$read['payload']['probe_sha256']);
})->tag('cache','shared','probe','cross-process','write','read-delete','release')->group('framework-coverage');

test('shared cache probe accepts only fixed typed phases challenge and runtime identities',static function(Context $t): void {
	$challenge=str_repeat('b',64);
	foreach([
		['shared_cache_probe.php'],
		['shared_cache_probe.php','--phase=write'],
		['shared_cache_probe.php','--phase=invalid','--challenge='.$challenge],
		['shared_cache_probe.php','--phase=write','--challenge=ABC'],
		['shared_cache_probe.php','--phase=write','--challenge='.$challenge,'--challenge='.$challenge],
		['shared_cache_probe.php','--script=release.sh','--challenge='.$challenge],
		['shared_cache_probe.php','--command=php artisan','--challenge='.$challenge],
		['shared_cache_probe.php','--key=chosen','--challenge='.$challenge],
		['shared_cache_probe.php','--value=chosen','--challenge='.$challenge],
		['shared_cache_probe.php','--host=cache','--challenge='.$challenge],
		['shared_cache_probe.php','--port=11211','--challenge='.$challenge],
		['shared_cache_probe.php','--ttl=999','--challenge='.$challenge],
	] as $arguments){
		$out='';$error='';
		$status=SharedCacheProbeCommand::main($arguments,[
			'write_out'=>static function(string $value) use (&$out): int {$out.=$value;return strlen($value);},
			'write_error'=>static function(string $value) use (&$error): int {$error.=$value;return strlen($value);},
		]);
		$t->same(64,$status);$t->same('',$out);
		$t->same('invalid_invocation',json_decode($error,true,8,JSON_THROW_ON_ERROR)['error']['code']);
	}
	$web=dp_shared_cache_probe_run('detect',['sapi'=>'fpm-fcgi']);
	$t->same(64,$web['status']);$t->same('invalid_runtime',$web['payload']['error']['code']);
	foreach(['DATAPHYRE_APPLICATION_ID','DATAPHYRE_FRAMEWORK_APPLICATION','DATAPHYRE_ENVIRONMENT','DATAPHYRE_APPLICATION_RELEASE'] as $missing){
		$values=[
			'DATAPHYRE_APPLICATION_ID'=>'fixture-app','DATAPHYRE_FRAMEWORK_APPLICATION'=>'FixtureApp',
			'DATAPHYRE_ENVIRONMENT'=>'production','DATAPHYRE_APPLICATION_RELEASE'=>'dep_'.str_repeat('a',40),
		];
		unset($values[$missing]);
		$invalid=dp_shared_cache_probe_run('detect',['environment_values'=>$values]);
		$t->same(78,$invalid['status']);$t->same('runtime_configuration_invalid',$invalid['payload']['error']['code']);
	}
	$extension=dp_shared_cache_probe_run('detect',['extension_version'=>static fn(): string=>'3.3.0']);
	$t->same(69,$extension['status']);$t->same('shared_cache_unavailable',$extension['payload']['error']['code']);
})->tag('cache','shared','probe','typed-boundary','negative','security')->group('framework-coverage');

test('shared cache proof rejects misses mismatches failed cleanup and post-operation fallback',static function(Context $t): void {
	$cases=[
		['write',['is_shared'=>static fn(): bool=>false]],
		['write',['is_shared'=>static fn(): bool=>true,'set'=>static fn(): bool=>false]],
		['write',['is_shared'=>(function(){static $calls=0;return static function() use (&$calls): bool {return ++$calls===1;};})()]],
		['read-delete',['is_shared'=>static fn(): bool=>true,'get'=>static fn(): null=>null,'delete'=>static fn(): bool=>true]],
		['read-delete',['is_shared'=>static fn(): bool=>true,'get'=>static fn(): string=>'wrong','delete'=>static fn(): bool=>true]],
		['read-delete',['is_shared'=>static fn(): bool=>true,'get'=>static fn(): null=>null,'delete'=>static fn(): bool=>false]],
	];
	foreach($cases as [$phase,$runtime]){
		$failed=dp_shared_cache_probe_run($phase,$runtime);
		$t->same(69,$failed['status'],$phase);$t->same('',$failed['out']);
		$t->same('shared_cache_proof_failed',$failed['payload']['error']['code']);
		$t->isFalse(str_contains($failed['error'],'wrong'));
	}
	$undeclared=dp_shared_cache_probe_run('write',['declared_shared'=>static fn(): bool=>false]);
	$t->same(69,$undeclared['status']);$t->same('shared_cache_unavailable',$undeclared['payload']['error']['code']);
})->tag('cache','shared','probe','fallback','cleanup','negative','release')->group('framework-coverage');
