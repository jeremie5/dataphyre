<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\ApplicationEnvironmentIdentifier;
use Dataphyre\PublicApplicationIdentifier;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/kernel/application_runtime_environment.php';

suite('Application runtime environment boundary')
	->contract('core.application-runtime-environment',1)
	->layer('integration')
	->risk('critical')
	->watches('module:core')
	->isolation('case')
	->tag('core','runtime','environment','security','release')
	->group('framework-coverage');

test('child environment re-adds only fixed platform identity log and data values',static function(Context $t): void {
	$result=DataphyreApplicationRuntimeEnvironment::childEnvironment(
		['APP_TOKEN'=>'secret','Z_VALUE'=>'last'],
		'example-store','example_store','production','dep_'.str_repeat('a',40),
		'/var/lib/dataphyre/application',
	);
	$t->same('secret',$result['APP_TOKEN']);
	$t->same('example-store',$result['DATAPHYRE_APPLICATION_ID']);
	$t->same('example_store',$result['DATAPHYRE_FRAMEWORK_APPLICATION']);
	$t->same('production',$result['DATAPHYRE_ENVIRONMENT']);
	$t->same('production',$result['DATAPHYRE_APPLICATION_ENVIRONMENT']);
	$t->same('dep_'.str_repeat('a',40),$result['DATAPHYRE_APPLICATION_RELEASE']);
	$t->same('/var/log/dataphyre',$result['DATAPHYRE_APPLICATION_LOG_DIRECTORY']);
	$t->same('jsonl',$result['DATAPHYRE_APPLICATION_LOG_DRIVER']);
	$t->same('dataphyre.application-log.v1',$result['DATAPHYRE_APPLICATION_LOG_FORMAT']);
	$t->same('/var/log/dataphyre/application.jsonl',$result['DATAPHYRE_APPLICATION_LOG_PATH']);
	$t->same('/var/lib/dataphyre/application',$result['DATAPHYRE_APPLICATION_DATA_ROOT']);
	$keys=array_keys($result);$sorted=$keys;sort($sorted,SORT_STRING);
	$t->same($sorted,$keys);
	$t->throws(
		static fn()=>DataphyreApplicationRuntimeEnvironment::childEnvironment(
			[],'example-app','example_app','production','dep_'.str_repeat('b',40),'/tmp/tenant-selected',
		),
		RuntimeException::class,
	);
})->tag('projection','fixed-values');

test('typed managed database purpose projects one complete binding onto the canonical child contract',static function(Context $t): void {
	$marker='sha256:'.str_repeat('b',64);
	$values=[
		'APP_TOKEN'=>'preserved',
		'DATAPHYRE_DATABASE_BINDING_PRIMARY_SHA256'=>'sha256:'.str_repeat('a',64),
		'DATAPHYRE_DATABASE_DSN'=>'pgsql:host=primary.database.internal;port=5432;dbname=primary',
		'DATAPHYRE_DATABASE_HOST'=>'primary.database.internal',
		'DATAPHYRE_DATABASE_PORT'=>'5432',
		'DATAPHYRE_DATABASE_NAME'=>'primary',
		'DATAPHYRE_DATABASE_USER'=>'primary_role',
		'DATAPHYRE_DATABASE_PASSWORD'=>'primary_password',
		'DATAPHYRE_DATABASE_BINDING_SANDBOX_SHA256'=>$marker,
		'DATAPHYRE_DATABASE_SANDBOX_DSN'=>'pgsql:host=sandbox.database.internal;port=5433;dbname=sandbox',
		'DATAPHYRE_DATABASE_SANDBOX_HOST'=>'sandbox.database.internal',
		'DATAPHYRE_DATABASE_SANDBOX_PORT'=>'5433',
		'DATAPHYRE_DATABASE_SANDBOX_NAME'=>'sandbox',
		'DATAPHYRE_DATABASE_SANDBOX_USER'=>'sandbox_role',
		'DATAPHYRE_DATABASE_SANDBOX_PASSWORD'=>'sandbox_password',
	];
	$projected=DataphyreApplicationRuntimeEnvironment::projectManagedDatabasePurpose($values,'sandbox');
	$t->same('preserved',$projected['APP_TOKEN']);
	$t->same($marker,$projected['DATAPHYRE_DATABASE_BINDING_PRIMARY_SHA256']);
	foreach(['DSN','HOST','PORT','NAME','USER','PASSWORD'] as $field){
		$t->same($values['DATAPHYRE_DATABASE_SANDBOX_'.$field],$projected['DATAPHYRE_DATABASE_'.$field],$field);
		$t->isFalse(array_key_exists('DATAPHYRE_DATABASE_SANDBOX_'.$field,$projected),$field.' named binding');
	}
	$t->isFalse(array_key_exists('DATAPHYRE_DATABASE_BINDING_SANDBOX_SHA256',$projected));
	$keys=array_keys($projected);$sorted=$keys;sort($sorted,SORT_STRING);$t->same($sorted,$keys);
	$t->same(
		$values['DATAPHYRE_DATABASE_DSN'],
		DataphyreApplicationRuntimeEnvironment::projectManagedDatabasePurpose($values,'primary')['DATAPHYRE_DATABASE_DSN'],
	);
	foreach(['','Primary','sandbox-blue','sandbox.',str_repeat('a',33)] as $purpose){
		$t->throws(
			static fn()=>DataphyreApplicationRuntimeEnvironment::projectManagedDatabasePurpose($values,$purpose),
			RuntimeException::class,$purpose,
		);
	}
	foreach(['missing','sha256:'.str_repeat('A',64),'sha256:'.str_repeat('c',63)] as $invalidMarker){
		$invalid=$values;
		if($invalidMarker==='missing') unset($invalid['DATAPHYRE_DATABASE_BINDING_SANDBOX_SHA256']);
		else $invalid['DATAPHYRE_DATABASE_BINDING_SANDBOX_SHA256']=$invalidMarker;
		$t->throws(
			static fn()=>DataphyreApplicationRuntimeEnvironment::projectManagedDatabasePurpose($invalid,'sandbox'),
			RuntimeException::class,$invalidMarker,
		);
	}
	foreach(['DSN','HOST','PORT','NAME','USER','PASSWORD'] as $field){
		$key='DATAPHYRE_DATABASE_SANDBOX_'.$field;
		$missing=$values;unset($missing[$key]);
		$t->throws(
			static fn()=>DataphyreApplicationRuntimeEnvironment::projectManagedDatabasePurpose($missing,'sandbox'),
			RuntimeException::class,'missing '.$field,
		);
		$blank=$values;$blank[$key]='';
		$t->throws(
			static fn()=>DataphyreApplicationRuntimeEnvironment::projectManagedDatabasePurpose($blank,'sandbox'),
			RuntimeException::class,'blank '.$field,
		);
	}
})->tag('database','purpose','projection','complete-binding','positive','negative');

test('one public environment grammar survives the child boundary and rejects traversal or controls',static function(Context $t): void {
	foreach(['staging_blue','Staging.Blue','9-preview',str_repeat('a',128)] as $environment){
		$t->isTrue(ApplicationEnvironmentIdentifier::valid($environment),$environment);
		$child=DataphyreApplicationRuntimeEnvironment::childEnvironment(
			[],'example-app','ExampleApp',$environment,'dep_'.str_repeat('a',40),
		);
		$t->same($environment,$child['DATAPHYRE_ENVIRONMENT'],$environment);
		$t->same($environment,$child['DATAPHYRE_APPLICATION_ENVIRONMENT'],$environment);
	}
	foreach(['.','..','_staging',"staging\nblue","staging\0blue",str_repeat('a',129)] as $environment){
		$t->isFalse(ApplicationEnvironmentIdentifier::valid($environment),bin2hex($environment));
		$t->throws(
			static fn()=>DataphyreApplicationRuntimeEnvironment::childEnvironment(
				[],'example-app','ExampleApp',$environment,'dep_'.str_repeat('a',40),
			),
			RuntimeException::class,
			bin2hex($environment),
		);
	}
})->tag('environment-identifier','projection','broad-grammar','negative','regression');

test('one public application grammar survives the child boundary and rejects aliases or controls',static function(Context $t): void {
	$t->same(120,PublicApplicationIdentifier::MAX_BYTES);
	foreach(['A','Store:North_2-Beta',':','_','-',str_repeat('Z',120)] as $application){
		$t->isTrue(PublicApplicationIdentifier::valid($application),$application);
		$child=DataphyreApplicationRuntimeEnvironment::childEnvironment(
			[],$application,'ExampleApp','production','dep_'.str_repeat('a',40),
		);
		$t->same($application,$child['DATAPHYRE_APPLICATION_ID'],$application);
	}
	foreach(['','app.name','app/name','app name','$app',"app\nname","app\0name",'é',str_repeat('a',121)] as $application){
		$t->isFalse(PublicApplicationIdentifier::valid($application),bin2hex($application));
		$t->throws(
			static fn()=>DataphyreApplicationRuntimeEnvironment::childEnvironment(
				[],$application,'ExampleApp','production','dep_'.str_repeat('a',40),
			),
			RuntimeException::class,
			bin2hex($application),
		);
	}
})->tag('public-application-identifier','projection','exact-grammar','negative','regression');

test('every fixed release runtime and migration boundary delegates environment validation to one authority',static function(Context $t): void {
	$core=dirname(__DIR__);$modules=dirname($core);
	$files=[
		$core.'/Release/ApplicationReleasePreflightEvidence.php',
		$core.'/kernel/application_release_preflight_realtime.php',
		$core.'/kernel/application_runtime_environment.php',
		$core.'/kernel/application_runtime_one_shot.php',
		$core.'/kernel/application_runtime_probe_state.php',
		$core.'/kernel/application_runtime_realtime_bootstrap.php',
		$core.'/kernel/application_runtime_scheduler_protocol.php',
		$core.'/kernel/application_runtime_scheduler_state.php',
		$core.'/kernel/application_runtime_status_probe.php',
		$modules.'/cache/Framework/SharedCacheProbeCommand.php',
		$modules.'/sql/Framework/Migrations/PostgreSqlMigrationCommand.php',
		$modules.'/sql/Framework/Migrations/SqliteMigrationCommand.php',
		$modules.'/sql/Framework/RegisteredTableMaterializationCommand.php',
	];
	foreach($files as $file){
		$source=(string)file_get_contents($file);
		$t->contains('ApplicationEnvironmentIdentifier::valid',$source,$file);
		$t->isFalse(str_contains($source,'^[a-z0-9][a-z0-9-]{0,79}$'),$file);
	}
	$publicApplicationFiles=[
		$core.'/kernel/application_runtime_environment.php',
		$core.'/kernel/application_runtime_one_shot.php',
		$core.'/kernel/application_runtime_probe_state.php',
		$core.'/kernel/application_runtime_scheduler_protocol.php',
		$core.'/kernel/application_runtime_scheduler_state.php',
		$core.'/kernel/application_runtime_status_probe.php',
		$modules.'/cache/Framework/SharedCacheProbeCommand.php',
	];
	foreach($publicApplicationFiles as $file){
		$source=(string)file_get_contents($file);
		$t->contains('PublicApplicationIdentifier::valid',$source,$file);
		$t->isFalse(str_contains($source,'^[a-z0-9][a-z0-9_-]{0,62}$'),$file);
	}
	$supervisor=(string)file_get_contents($core.'/kernel/application_runtime_supervisor.php');
	$t->contains('DataphyreApplicationRuntimeEnvironment::consume(',$supervisor);
})->tag('environment-identifier','single-authority','source','release','runtime','migration');

test('mounted application data root accepts only one fixed rw canonical owned directory',static function(Context $t): void {
	$validStat=['mode'=>0040750,'uid'=>10001,'gid'=>10001,'nlink'=>2];
	$runtime=[
		'read_file'=>static fn(string $path): string=>"42 35 0:99 / /var/lib/dataphyre/application rw,nosuid,nodev - ext4 /dev/data rw\n",
		'lstat'=>static fn(string $path): array=>$validStat,
		'realpath'=>static fn(string $path): string=>$path,
		'is_link'=>static fn(string $path): bool=>false,
	];
	$t->same('/var/lib/dataphyre/application',DataphyreApplicationRuntimeEnvironment::mountedApplicationDataRoot(10001,$runtime));
	$absent=$runtime;
	$absent['read_file']=static fn(string $path): string=>"41 35 0:98 / /app rw - overlay overlay rw\n";
	$t->same(null,DataphyreApplicationRuntimeEnvironment::mountedApplicationDataRoot(10001,$absent));
	foreach([
		"42 35 0:99 / /var/lib/dataphyre/application ro - ext4 /dev/data ro\n",
		"42 35 0:99 / /var/lib/dataphyre/application rw - ext4 /dev/data rw\n43 35 0:100 / /var/lib/dataphyre/application rw - ext4 /dev/data2 rw\n",
	] as $mountInfo){
		$invalid=$runtime;$invalid['read_file']=static fn(string $path): string=>$mountInfo;
		$t->throws(static fn()=>DataphyreApplicationRuntimeEnvironment::mountedApplicationDataRoot(10001,$invalid),RuntimeException::class);
	}
	$wrongOwner=$runtime;$wrongOwner['lstat']=static fn(string $path): array=>[...$validStat,'uid'=>0];
	$t->throws(static fn()=>DataphyreApplicationRuntimeEnvironment::mountedApplicationDataRoot(10001,$wrongOwner),RuntimeException::class);
})->tag('mount','sqlite','fail-closed');

test('mounted application log root requires one fixed private rw pool-owned directory',static function(Context $t): void {
	$validStat=['mode'=>0040750,'uid'=>10001,'gid'=>10001,'nlink'=>2];
	$runtime=[
		'read_file'=>static fn(string $path): string=>"42 35 0:99 / /var/log/dataphyre rw,nosuid,nodev - ext4 /dev/log rw\n",
		'lstat'=>static fn(string $path): array=>$validStat,
		'realpath'=>static fn(string $path): string=>$path,
		'is_link'=>static fn(string $path): bool=>false,
	];
	$t->same('/var/log/dataphyre',DataphyreApplicationRuntimeEnvironment::mountedApplicationLogRoot(10001,$runtime));
	foreach([
		"41 35 0:98 / /app rw - overlay overlay rw\n",
		"42 35 0:99 / /var/log/dataphyre ro - ext4 /dev/log ro\n",
	] as $mountInfo){
		$invalid=$runtime;$invalid['read_file']=static fn(string $path): string=>$mountInfo;
		$t->throws(static fn()=>DataphyreApplicationRuntimeEnvironment::mountedApplicationLogRoot(10001,$invalid),RuntimeException::class);
	}
	$public=$runtime;$public['lstat']=static fn(string $path): array=>[...$validStat,'mode'=>0040755];
	$t->throws(static fn()=>DataphyreApplicationRuntimeEnvironment::mountedApplicationLogRoot(10001,$public),RuntimeException::class);
	$wrongGroup=$runtime;$wrongGroup['lstat']=static fn(string $path): array=>[...$validStat,'gid'=>0];
	$t->throws(static fn()=>DataphyreApplicationRuntimeEnvironment::mountedApplicationLogRoot(10001,$wrongGroup),RuntimeException::class);
})->tag('mount','log','fail-closed');

test('every fixed runtime mount rejects a wrong group',static function(Context $t): void {
	$cases=[
		['/var/lib/dataphyre/application',0040750,10001,10001,'mountedApplicationDataRoot',[10001]],
		['/var/log/dataphyre',0040750,10001,10001,'mountedApplicationLogRoot',[10001]],
		['/var/lib/dataphyre/scheduler-state',0040700,0,0,'mountedSchedulerStateRoot',[]],
	];
	foreach($cases as [$path,$mode,$uid,$gid,$method,$arguments]){
		$runtime=[
			'read_file'=>static fn(string $candidate): string=>"42 35 0:99 / {$path} rw,nosuid,nodev - ext4 /dev/data rw\n",
			'lstat'=>static fn(string $candidate): array=>['mode'=>$mode,'uid'=>$uid,'gid'=>$gid+1,'nlink'=>2],
			'realpath'=>static fn(string $candidate): string=>$candidate,
			'is_link'=>static fn(string $candidate): bool=>false,
		];
		$t->throws(
			static fn()=>DataphyreApplicationRuntimeEnvironment::{$method}(...[...$arguments,$runtime]),
			RuntimeException::class,
			$path,
		);
	}
})->tag('mount','gid','wrong-group','negative');

test('canonical channel bytes preserve all valid utf8 including line separator code points',static function(Context $t): void {
	$canonical=$t->nonPublic(DataphyreApplicationRuntimeEnvironment::class)->invoke(
		'canonicalEnvelope',
		'serve','serve','production','dep_'.str_repeat('a',40),
		'hmac-sha256:'.str_repeat('b',64),
		['Z_VALUE'=>"line\u{2028}paragraph\u{2029}end",'A_VALUE'=>'https://example.invalid/a/b'],
	);
	$expected='{"contract":"dataphyre.application_environment.v1","cloud_application":"serve",'
		.'"framework_application":"serve","environment":"production","release_id":"dep_'.str_repeat('a',40).'",'
		.'"environment_fingerprint":"hmac-sha256:'.str_repeat('b',64).'","values":{'
		.'"A_VALUE":"https://example.invalid/a/b","Z_VALUE":"line'."\u{2028}".'paragraph'."\u{2029}".'end"}}'."\n";
	$t->same($expected,$canonical);
	$t->isFalse(str_contains($canonical,'\\u2028'));
	$t->isFalse(str_contains($canonical,'\\u2029'));
	$t->same("\n",substr($canonical,-1));
})->tag('canonical','unicode','cross-language');

test('canonical channel decoder rejects malformed contracts entries and alternate encodings',static function(Context $t): void {
	$internals=$t->nonPublic(DataphyreApplicationRuntimeEnvironment::class);
	$release='dep_'.str_repeat('a',40);$fingerprint='hmac-sha256:'.str_repeat('b',64);
	$arguments=['serve','Serve','Staging.Blue',$release,$fingerprint,['Z_VALUE'=>'last','A_VALUE'=>'first']];
	$canonical=$internals->invoke('canonicalEnvelope',...$arguments);
	$t->same([
		'release_id'=>$release,'environment_fingerprint'=>$fingerprint,
		'values'=>['A_VALUE'=>'first','Z_VALUE'=>'last'],
	],$internals->invoke('decodeEnvelope',$canonical,'serve','Serve','Staging.Blue',$release));
	$t->throws(
		static fn()=>$internals->invoke('decodeEnvelope',"{\n",'serve','Serve','Staging.Blue',$release),
		RuntimeException::class,
	);
	$wrong=json_decode($canonical,true,8,JSON_THROW_ON_ERROR);$wrong['contract']='wrong';
	$t->throws(static fn()=>$internals->invoke(
		'decodeEnvelope',json_encode($wrong,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",'serve','Serve','Staging.Blue',$release,
	),RuntimeException::class);
	$reserved=json_decode($canonical,true,8,JSON_THROW_ON_ERROR);$reserved['values']=['DATAPHYRE_RUNTIME_FORGED'=>'value'];
	$t->throws(static fn()=>$internals->invoke(
		'decodeEnvelope',json_encode($reserved,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",'serve','Serve','Staging.Blue',$release,
	),RuntimeException::class);
	$t->throws(
		static fn()=>$internals->invoke('decodeEnvelope',' '.$canonical,'serve','Serve','Staging.Blue',$release),
		RuntimeException::class,
	);
})->tag('canonical','decoder','contract','entry','negative');

test('one mountinfo parser owns optional required and fixed mount decisions',static function(Context $t): void {
	$internals=$t->nonPublic(DataphyreApplicationRuntimeEnvironment::class);
	$t->throws(static fn()=>$internals->invoke('mountModes','/fixed','Application environment',['read_file'=>'invalid']),RuntimeException::class);
	$t->throws(static fn()=>$internals->invoke('mountModes','/fixed','Application environment',[
		'read_file'=>static fn(string $path): false=>false,
	]),RuntimeException::class);
	foreach([
		str_repeat('x',4097)."\n",
		"missing-separator\n",
		"1 2 3 - overlay overlay rw\n",
	] as $inventory){
		$t->throws(static fn()=>$internals->invoke('mountModes','/fixed','Application environment',[
			'read_file'=>static fn(string $path): string=>$inventory,
		]),RuntimeException::class);
	}
	$inventory="42 35 0:99 / /fixed\\040path ro,nosuid - ext4 /dev/data ro\n";
	$runtime=['read_file'=>static fn(string $path): string=>$inventory];
	$t->same(['ro,nosuid'],$internals->invoke('mountModes','/fixed path','Application environment',$runtime));
	$internals->invoke('assertFixedMount','/fixed path','ro',$runtime);$t->same(true,true);
	$t->throws(static fn()=>$internals->invoke('assertFixedMount','/fixed path','rw',$runtime),RuntimeException::class);
	$schedulerStat=['mode'=>0040700,'uid'=>0,'gid'=>0,'nlink'=>2];
	$schedulerRuntime=[
		'read_file'=>static fn(string $path): string=>"42 35 0:99 / /var/lib/dataphyre/scheduler-state rw,nosuid - ext4 /dev/state rw\n",
		'lstat'=>static fn(string $path): array=>$schedulerStat,'realpath'=>static fn(string $path): string=>$path,
		'is_link'=>static fn(string $path): bool=>false,
	];
	$t->same('/var/lib/dataphyre/scheduler-state',DataphyreApplicationRuntimeEnvironment::mountedSchedulerStateRoot($schedulerRuntime));
	$t->isTrue($internals->invoke('exactFile',['mode'=>0100400,'uid'=>0,'gid'=>0,'nlink'=>1]));
	$t->isFalse($internals->invoke('exactFile',['mode'=>0100400,'uid'=>0,'gid'=>0,'nlink'=>2]));
})->tag('mountinfo','single-parser','fixed-mount','negative');

test('root environment rejects PHP auto-prepend startup control in a covered process',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$kernel=dirname(__DIR__).'/kernel';
	$result=$t->coveredPhpProcess([
		__DIR__.'/fixtures/application_runtime_environment_startup_boundary.php',$kernel,
	],framework_root:$frameworkRoot,php_ini:[
		'auto_prepend_file'=>__DIR__.'/fixtures/application_runtime_empty_prepend.php',
	]);
	$t->processSucceeded($result,$result->stderr());$t->same(true,$result->json()['rejected']);
})->tag('root-environment','php-startup','exact-coverage','negative');

test('tenant values reject ascii controls while reserving every framework control namespace',static function(Context $t): void {
	$internals=$t->nonPublic(DataphyreApplicationRuntimeEnvironment::class);
	foreach([
		'DATAPHYRE_APPLICATION_RELEASE','DATAPHYRE_CLOUD_TOKEN','DATAPHYRE_FRAMEWORK_APPLICATION',
		'DATAPHYRE_INTERNAL_VALUE','DATAPHYRE_INTERNAL_POSTGRESQL_MIGRATION_DATA_ENVIRONMENT',
		'DATAPHYRE_ONE_SHOT_OPERATION','DATAPHYRE_PREFLIGHT_MODE',
		'DATAPHYRE_RUNTIME_ROOT','DATAPHYRE_SCHEDULER_STATE_ROOT','DATAPHYRE_PHP_BINARY',
		'DATAPHYRE_ONE_SHOT_CACHE_PHASE','DATAPHYRE_ONE_SHOT_CACHE_CHALLENGE',
		'DATAPHYRE_PROJECT_ROOT','PHP_INI_SCAN_DIR','LD_PRELOAD','DYLD_INSERT_LIBRARIES','PATH','HOME',
	] as $name){
		$t->isTrue($internals->invoke('reserved',$name),$name);
	}
	$source=(string)file_get_contents(dirname(__DIR__).'/kernel/application_runtime_environment.php');
	$t->contains("preg_match('/[\\x00-\\x1f\\x7f]/D',$".'value)===1',$source);
})->tag('reserved','controls','fail-closed');

test('root process accepts only the image-owned root home value',static function(Context $t): void {
	$t->throws(static fn()=>DataphyreApplicationRuntimeEnvironment::consume(
		'serve','Serve','production','dep_'.str_repeat('a',40),
	),RuntimeException::class);
	$fixed=[
		'DATAPHYRE_APPLICATION_ID'=>'fixture','DATAPHYRE_FRAMEWORK_APPLICATION'=>'Fixture',
		'DATAPHYRE_ENVIRONMENT'=>'production','DATAPHYRE_APPLICATION_RELEASE'=>'dep_'.str_repeat('a',40),
		'DATAPHYRE_RUNTIME_PROJECT_ROOT'=>'/app','PATH'=>'/usr/local/bin:/usr/bin:/bin',
		'PHP_INI_DIR'=>'/usr/local/etc/php','HOSTNAME'=>'fixture','HOME'=>'/root',
		'DATAPHYRE_ONE_SHOT_OPERATION'=>'dataphyre_shared_cache_probe',
		'DATAPHYRE_ONE_SHOT_CACHE_PHASE'=>'detect','DATAPHYRE_ONE_SHOT_CACHE_CHALLENGE'=>str_repeat('b',64),
	];
	DataphyreApplicationRuntimeEnvironment::assertCleanRootEnvironment($fixed);$t->same('/root',$fixed['HOME']);
	foreach(['/tmp','/app','/root/..',''] as $home){
		$invalid=$fixed;$invalid['HOME']=$home;
		$t->throws(
			static fn()=>DataphyreApplicationRuntimeEnvironment::assertCleanRootEnvironment($invalid),
			RuntimeException::class,$home,
		);
	}
	$unexpected=$fixed;$unexpected['SHELL']='/bin/sh';
	$t->throws(static fn()=>DataphyreApplicationRuntimeEnvironment::assertCleanRootEnvironment($unexpected),RuntimeException::class);
	$kernel=dirname(__DIR__).'/kernel/application_runtime_environment.php';
	$source=(string)file_get_contents($kernel);
	$t->contains("$" . "name==='HOME' ? !hash_equals('/root',$" . "value)",$source);
	$t->isFalse(str_contains($source,"'TERM','HOME'"));
})->tag('root-environment','home','fixed-value','positive','negative');

test('root process accepts public fixed endpoints and rejects removed private TCP endpoint controls',static function(Context $t): void {
	$base=['HOME'=>'/root'];
	$fixedEndpoints=[
		'DATAPHYRE_RUNTIME_WEB_HOST'=>'127.0.0.1',
		'DATAPHYRE_RUNTIME_WEB_PORT'=>'8083',
		'DATAPHYRE_RUNTIME_REALTIME_HOST'=>'0.0.0.0',
		'DATAPHYRE_RUNTIME_REALTIME_PORT'=>'8080',
	];
	foreach($fixedEndpoints as $name=>$value){
		DataphyreApplicationRuntimeEnvironment::assertCleanRootEnvironment($base+[$name=>$value]);
		$t->same($value,($base+[$name=>$value])[$name],$name);
	}
	DataphyreApplicationRuntimeEnvironment::assertCleanRootEnvironment($base+$fixedEndpoints);
	foreach([
		'DATAPHYRE_RUNTIME_SCHEDULER_HOST'=>'127.0.0.1','DATAPHYRE_RUNTIME_SCHEDULER_PORT'=>'8081',
		'DATAPHYRE_RUNTIME_STATUS_HOST'=>'127.0.0.1','DATAPHYRE_RUNTIME_STATUS_PORT'=>'8082',
	] as $legacyName=>$legacyValue){
		$t->throws(
			static fn()=>DataphyreApplicationRuntimeEnvironment::assertCleanRootEnvironment(
				$base+$fixedEndpoints+[$legacyName=>$legacyValue],
			),RuntimeException::class,$legacyName,
		);
	}
	$tenantEndpointControl=$base+$fixedEndpoints+['DATAPHYRE_RUNTIME_WEB_HOST_OVERRIDE'=>'tenant.example'];
	$t->throws(
		static fn()=>DataphyreApplicationRuntimeEnvironment::assertCleanRootEnvironment($tenantEndpointControl),
		RuntimeException::class,
	);
})->tag('root-environment','fixed-endpoints','private-uds','positive','negative','regression');

test('fixed root directory accepts overlay nlink one but rejects an impossible zero count',static function(Context $t): void {
	$internals=$t->nonPublic(DataphyreApplicationRuntimeEnvironment::class);
	$path='/run/dataphyre';
	$runtime=[
		'lstat'=>static fn(string $candidate): array=>['mode'=>0040700,'uid'=>0,'gid'=>0,'nlink'=>1],
		'realpath'=>static fn(string $candidate): string=>$candidate,
		'is_link'=>static fn(string $candidate): bool=>false,
	];
	$stat=$internals->invoke('exactDirectory',$path,0700,$runtime);
	$t->same(1,$stat['nlink']);
	$zero=$runtime;$zero['lstat']=static fn(string $candidate): array=>['mode'=>0040700,'uid'=>0,'gid'=>0,'nlink'=>0];
	$t->throws(static fn()=>$internals->invoke('exactDirectory',$path,0700,$zero),RuntimeException::class);
	$source=(string)file_get_contents(dirname(__DIR__).'/kernel/application_runtime_environment.php');
	$method=strstr($source,'private static function exactDirectory');
	$t->isTrue(is_string($method));$method=strstr($method,'private static function exactFile',true);
	$t->isTrue(is_string($method));
	$t->contains("($" . "stat['nlink'] ?? 0)<1",$method);
	$t->isFalse(str_contains($method,"($" . "stat['nlink'] ?? 0)<2"));
})->tag('root-environment','overlay','bind-mount','nlink','boundary');

test('source freezes the root-only canonical channel and fixed one-shot allowlist',static function(Context $t): void {
	$kernel=dirname(__DIR__).'/kernel';
	$environment=(string)file_get_contents($kernel.'/application_runtime_environment.php');
	$childEnvironment=(string)file_get_contents($kernel.'/application_runtime_child_environment.php');
	$oneShot=(string)file_get_contents($kernel.'/application_runtime_one_shot.php');
	$migrationCommand=(string)file_get_contents(
		dirname(__DIR__,2).'/sql/Framework/Migrations/PostgreSqlMigrationCommand.php'
	);
	$t->contains("getmypid()!==1",$environment);
	$t->contains("posix_geteuid()!==0",$environment);
	$t->contains("CHANNEL='/run/dataphyre/application-environment.json'",$environment);
	$t->contains("MAX_BYTES=262144",$environment);
	$t->contains("MAX_ENTRIES=512",$environment);
	$t->contains("exactDirectory(dirname(self::CHANNEL),0700)",$environment);
	$t->contains("assertFixedMount(self::CHANNEL,'ro')",$environment);
	$t->contains("===0400",$environment);
	$t->contains("($"."stat['nlink'] ?? 0)===1",$environment);
	$t->contains("dataphyre.application_environment.v1",$environment);
	$t->contains("JSON_UNESCAPED_LINE_TERMINATORS",$environment);
	$t->contains("DATAPHYRE_ONE_SHOT_OPERATION",$environment);
	$t->contains("database_identity|application_preflight|artisan_migrate|dataphyre_materialize_tables|dataphyre_postgresql_migrate|dataphyre_sqlite_migrate|dataphyre_seed|dataphyre_shared_cache_probe",$oneShot);
	$t->contains('DATAPHYRE_ONE_SHOT_CACHE_MAXIMUM_MILLISECONDS=10000',$oneShot);
	$t->contains('DATAPHYRE_ONE_SHOT_SEED_MAXIMUM_MILLISECONDS=900000',$oneShot);
	foreach(['DATAPHYRE_ONE_SHOT_SEED_PROFILE','DATAPHYRE_ONE_SHOT_SEED_ALLOW_DEMO'] as $control){
		$t->contains($control,$environment);$t->contains($control,$oneShot);
	}
	$t->contains("'--phase='.$"."cachePhase,'--challenge='.$"."cacheChallenge",$oneShot);
	$t->contains("/modules/cache/kernel/shared_cache_probe.php",$oneShot);
	$t->contains("/usr/bin/setpriv",$oneShot);
	$t->contains("/usr/bin/setsid",$oneShot);
	$t->contains("/usr/bin/prlimit",$oneShot);
	$t->contains("--nproc=0:0",$oneShot);
	$t->same(true,
		strpos($oneShot,"\t\t$"."setpriv,")<strpos($oneShot,"[$"."prlimit,'--nproc=0:0','--']")
		&& strpos($oneShot,"[$"."prlimit,'--nproc=0:0','--']")<strpos($oneShot,"\t\tPHP_BINARY,")
	);
	$t->contains("--no-new-privs",$oneShot);
	$t->contains("'--groups='.$"."gid",$oneShot);
	$t->isFalse(str_contains($oneShot,'--init-groups'));
	$t->contains("--bounding-set=-all",$oneShot);
	$t->contains("['migrate','--force','--no-interaction']",$oneShot);
	$t->contains("in_array($"."operation,['dataphyre_materialize_tables','dataphyre_sqlite_migrate'],true)",$oneShot);
	$t->contains("$"."operation==='dataphyre_sqlite_migrate' && $"."applicationDataRoot===null",$oneShot);
	$t->contains("in_array($"."operation,['dataphyre_materialize_tables','dataphyre_postgresql_migrate','dataphyre_seed'],true)",$oneShot);
	$t->contains('projectManagedDatabasePurpose($child,$purpose)',$oneShot);
	$t->contains("$"."purpose!==null && $"."operation!=='database_identity'",$oneShot);
	$t->contains('DataphyreApplicationRuntimeChildEnvironment::ONE_SHOT_MATERIALIZER_DATABASE_PURPOSE]=$purpose',$oneShot);
	$t->contains("$"."operation==='dataphyre_materialize_tables'",$oneShot);
	$t->contains("'/modules/sql/kernel/managed_seeds.php'",$oneShot);
	$t->contains("'--data-environment='.($"."purpose==='primary' ? 'live' : $"."purpose)",$oneShot);
	$t->same(1,substr_count($oneShot,"'--data-environment='.($"."purpose==='primary' ? 'live' : $"."purpose)"));
	$dataEnvironmentVariable='DATAPHYRE_INTERNAL_POSTGRESQL_MIGRATION_DATA_ENVIRONMENT';
	$t->contains("ONE_SHOT_POSTGRESQL_DATA_ENVIRONMENT='".$dataEnvironmentVariable."'",$childEnvironment);
	$t->contains("DATA_ENVIRONMENT_VARIABLE='".$dataEnvironmentVariable."'",$migrationCommand);
	$t->same(1,substr_count($childEnvironment,$dataEnvironmentVariable));
	$t->same(1,substr_count($migrationCommand,$dataEnvironmentVariable));
	$t->contains('ONE_SHOT_POSTGRESQL_DATA_ENVIRONMENT]',$oneShot);
	$t->contains("$"."purpose==='primary' ? 'live' : $"."purpose",$oneShot);
	$t->isFalse(str_contains($oneShot,"in_array($"."operation,['dataphyre_postgresql_migrate'"));
	$t->isFalse(str_contains($oneShot,"in_array($"."operation,['dataphyre_shared_cache_probe'"));
	$t->contains('ApplicationReleasePreflightEvidence::COMMAND_TIMEOUT_MILLISECONDS',$oneShot);
	$t->contains('dataphyre_one_shot_cloud_application()',$oneShot);
	$t->contains('PublicApplicationIdentifier::valid',$oneShot);
	$t->contains("@posix_kill(-$"."processGroup,SIGTERM)",$oneShot);
	$t->contains("@posix_kill(-$"."processGroup,SIGKILL)",$oneShot);
	$t->contains("$"."terminationReason==='timeout'",$oneShot);
	$t->contains('exit(124)',$oneShot);
	$t->contains('pcntl_waitpid(-1',$oneShot);
	foreach(['proc_open(','shell'.'_exec(','system(','passthru(','popen(','eval('] as $forbidden){
		$t->isFalse(str_contains($oneShot,$forbidden));
	}
})->tag('source','one-shot','allowlist');
