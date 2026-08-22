<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\Seeds\InMemorySeedLedger;
use Dataphyre\Database\Seeds\SeedDefinition;
use Dataphyre\Database\Seeds\SeedManager;
use Dataphyre\Database\Seeds\SqlSeedLedger;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

$managedSeedRoot=dirname(__DIR__);
$managedSeedCoreKernel=dirname($managedSeedRoot).'/core/kernel';
foreach(['SeedDefinition','SeedContext','SeedExecutionException','SeedLedger','InMemorySeedLedger','SeedManager'] as $managedSeedClass){
	require_once $managedSeedRoot.'/Framework/Seeds/'.$managedSeedClass.'.php';
}
require_once $managedSeedCoreKernel.'/application_runtime_seed_evidence.php';
require_once $managedSeedRoot.'/kernel/managed_seeds.php';
require_once $managedSeedRoot.'/kernel/seeds.php';

suite('Cloud-managed SQL seed one-shot contract')
	->contract('sql.managed-seed-one-shot',1)
	->layer('integration')
	->risk('critical')
	->watches('module:core','module:sql')
	->through('root-envelope','fixed-dispatch','seed-profile','data-environment')
	->isolation('case')
	->tag('sql','seeds','one-shot','security')
	->group('framework-coverage');

test('managed seed entrypoint rejects invalid identity before application bootstrap',static function(Context $t): void {
	$entrypoint=dirname(__DIR__).'/kernel/managed_seeds.php';
	$t->processFailed($t->phpProcess([$entrypoint]),64);
	$arguments=[
		$entrypoint,
		'--project-root=/app','--application=serve','--environment=production',
		'--profile=demo','--data-environment=live','--allow-demo=1',
	];
	$mismatch=$t->phpProcess($arguments,environment:[
		'DATAPHYRE_FRAMEWORK_APPLICATION'=>'other','DATAPHYRE_ENVIRONMENT'=>'production',
	]);
	$t->processFailed($mismatch,78);$t->same('',$mismatch->stdout());$t->same('',$mismatch->stderr());
	$missingProject=$t->phpProcess($arguments,environment:[
		'DATAPHYRE_FRAMEWORK_APPLICATION'=>'serve','DATAPHYRE_ENVIRONMENT'=>'production',
	]);
	$t->processFailed($missingProject,66);$t->same('',$missingProject->stdout());$t->same('',$missingProject->stderr());
	$duplicate=$arguments;$duplicate[6]='--profile=demo';
	$t->processFailed($t->phpProcess($duplicate,environment:[
		'DATAPHYRE_FRAMEWORK_APPLICATION'=>'serve','DATAPHYRE_ENVIRONMENT'=>'production',
	]),64);
})->tag('entrypoint','negative','identity');

test('managed seed source fixes every executable and filesystem selection',static function(Context $t): void {
	$runtime=dirname(__DIR__);$core=dirname($runtime).'/core/kernel';
	$entrypoint=(string)file_get_contents($runtime.'/kernel/managed_seeds.php');
	$oneShot=(string)file_get_contents($core.'/application_runtime_one_shot.php');
	$seedEvidence=(string)file_get_contents($core.'/application_runtime_seed_evidence.php');
	$worker=(string)file_get_contents($core.'/application_runtime_one_shot_worker.php');
	$environment=(string)file_get_contents($core.'/application_runtime_environment.php');
	foreach(['proc_open(','shell'.'_exec(','system(','passthru(','popen(','eval('] as $forbidden){
		$t->isFalse(str_contains($entrypoint,$forbidden));
	}
	foreach([
		"$"."projectRoot='/app'","$"."seedRoot=$"."projectRoot.'/database/seeds'",
		"$"."bootstrap=$"."seedRoot.'/bootstrap.php'","$"."seedKernel=__DIR__.'/seeds.php'",
		"'--path='.$"."seedRoot","'--bootstrap='.$"."resolvedBootstrap",
		"'--data-environment='.$"."values['data-environment']",
	] as $required) $t->contains($required,$entrypoint);
	foreach(['--mode=','--cluster=','--ledger-table=','--id=','rollback','reset','getMessage','dp_sql_seed_main'] as $forbidden){
		$t->isFalse(str_contains($entrypoint,$forbidden));
	}
	foreach([
		'dataphyre.managed_seed_apply.v1','DATAPHYRE_MANAGED_SEED_MAXIMUM_OUTPUT_BYTES',
		'dataphyre_managed_seed_apply_profile','dp_sql_seed_prepare_runtime_environment',
		'dp_sql_seed_in_resolved_environment',
		'if($requested===[])',
	] as $required) $t->contains($required,$entrypoint);
	$t->contains("'dataphyre_seed'=>[",$oneShot);
	$t->contains("'/modules/sql/kernel/managed_seeds.php'",$oneShot);
	$t->contains("require_once __DIR__.'/application_runtime_seed_evidence.php'",$oneShot);
	$t->contains("'dataphyre_seed'=>dirname(__DIR__,3).'/modules/sql/kernel/managed_seeds.php'",$worker);
	foreach(['DATAPHYRE_ONE_SHOT_SEED_PROFILE','DATAPHYRE_ONE_SHOT_SEED_ALLOW_DEMO'] as $control){
		$t->contains($control,$oneShot);$t->contains($control,$environment);
	}
	foreach([
		'DATAPHYRE_ONE_SHOT_SEED_MAXIMUM_CAPTURE_BYTES','dataphyre_one_shot_validate_seed_evidence',
		'dataphyre_one_shot_valid_seed_result','hash_hmac',
	] as $required) $t->contains($required,$seedEvidence);
	foreach([
		"'seed_output_invalid'","[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']]",
		'$seedProfile===\'demo\' && $seedAllowDemo===\'0\'','random_bytes(32)','sodium_memzero',
		'disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork',
		'dataphyre_one_shot_clear_uid_processes',"dataphyre_one_shot_file('/usr/bin/prlimit')",'--nproc=0:0',
	] as $required) $t->contains($required,$oneShot);
	$setprivPosition=strpos($oneShot,"\t\t$"."setpriv,");
	$prlimitPosition=strpos($oneShot,"[$"."prlimit,'--nproc=0:0','--']");
	$phpPosition=strpos($oneShot,"\t\tPHP_BINARY,");
	$t->same(true,
		$setprivPosition!==false && $prlimitPosition!==false && $phpPosition!==false
		&& $setprivPosition<$prlimitPosition && $prlimitPosition<$phpPosition
	);
	foreach(['dataphyre_managed_seed_read_evidence_key','fclose(STDIN)','without_deferred_queries'] as $required){
		$t->contains($required,$entrypoint);
	}
	foreach(['seed_key','phase','getMessage'] as $forbidden) $t->isFalse(str_contains($seedEvidence,$forbidden));
})->tag('source','allowlist','fixed-path','no-shell');

test('managed seed apply rejects empty profiles and emits only bounded convergence evidence',static function(Context $t): void {
	$definitions=[
		new SeedDefinition(id:'managed.alpha',version:1,up:static fn()=>null,profiles:['demo']),
		new SeedDefinition(id:'managed.beta',version:1,up:static fn()=>null,profiles:['demo']),
	];
	$manager=new SeedManager($definitions,new InMemorySeedLedger(),null,['default','demo']);
	$stage='initialization';$first=dataphyre_managed_seed_apply_profile($manager,'demo',$stage);
	$t->same('convergence',$stage);$t->same(2,$first['requested_profile_definition_count']);
	$t->same(2,$first['active_definition_count']);$t->same(2,$first['applied_count']);
	$t->same(0,$first['skipped_count']);$t->same(2,$first['convergence']['active_applied_count']);
	$t->matches('/^sha256:[a-f0-9]{64}$/D',$first['active_keyset_sha256']);
	$second=dataphyre_managed_seed_apply_profile($manager,'demo',$stage);
	$t->same(null,$second['batch']);$t->same(0,$second['applied_count']);$t->same(2,$second['skipped_count']);

	$unknown=new SeedManager($definitions,new InMemorySeedLedger(),null,['default','dmeo']);
	$stage='initialization';$t->throws(
		static function() use ($unknown,&$stage): void {
			dataphyre_managed_seed_apply_profile($unknown,'dmeo',$stage);
		},
		RuntimeException::class,
	);
	$t->same('profile',$stage);
	$t->throws(static fn()=>dataphyre_managed_seed_keyset_sha256(['invalid']),RuntimeException::class);
})->tag('apply','profile','convergence','bounded');

test('managed seed failure evidence redacts application and driver messages',static function(Context $t): void {
	$sentinel='database-password-must-never-escape';
	$definition=new SeedDefinition(
		id:'managed.failure',version:1,up:static fn()=>null,profiles:['demo'],
		preflight:static function() use ($sentinel): void { throw new RuntimeException($sentinel); },
	);
	$manager=new SeedManager([$definition],new InMemorySeedLedger(),null,['default','demo']);
	$stage='initialization';$failure=null;
	try{dataphyre_managed_seed_apply_profile($manager,'demo',$stage);}catch(Throwable $caught){$failure=$caught;}
	$t->isTrue($failure instanceof Throwable);$evidence=dataphyre_managed_seed_error($stage);
	$encoded=json_encode($evidence,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
	$t->isFalse(str_contains($encoded,$sentinel));$t->same('seed_apply_failed',$evidence['code']);
	$t->same(['code'=>'seed_apply_failed'],$evidence);
	$t->isTrue(strlen($encoded)<DATAPHYRE_MANAGED_SEED_MAXIMUM_OUTPUT_BYTES);
})->tag('redaction','error','bounded');

test('managed seed SQL transaction rejects and discards every deferred queue',static function(Context $t) use ($managedSeedRoot): void {
	if(!function_exists('dataphyre\\end')){
		\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
function end(array &$value): mixed { return false; }
PHP);
	}
	$shadowProbe=[['cluster'=>'spoofed']];
	$t->same(false,\dataphyre\end($shadowProbe));
	dp_sql_seed_boot_sql(dirname($managedSeedRoot,2));
	\dataphyre\mysql_query_builder::$queued_queries=[];
	\dataphyre\postgresql_query_builder::$queued_queries=[];
	\dataphyre\sqlite_query_builder::$queued_queries=[];
	$t->same('immediate',\dataphyre\sql::without_deferred_queries(static fn(): string=>'immediate'));
	$reenterControl=false;$reentrantControlFailure=null;
	$t->isTrue(\dataphyre\core::register_dialback(
		'CALL_SQL_DB_SELECT',
		static function() use (&$reenterControl,&$reentrantControlFailure): string {
			if($reenterControl){
				try{\dataphyre\sql::query('COMMIT');}catch(Throwable $failure){$reentrantControlFailure=$failure;}
			}
			return 'direct-immediate';
		},
	));
	$structuredOuter=new \Dataphyre\Database\Transaction();
	$t->same('structured-guarded',$structuredOuter->run(static function() use ($t): string {
		return \dataphyre\sql::without_deferred_queries(static function() use ($t): string {
			$driverDialbackCalls=0;
			foreach(['SQL','POSTGRESQL'] as $driver){
				foreach(['SELECT','COUNT','UPDATE','INSERT','DELETE'] as $operation){
					$t->isTrue(\dataphyre\core::register_dialback(
						'CALL_'.$driver.'_SIMPLE_'.$operation,
						static function() use (&$driverDialbackCalls): null {$driverDialbackCalls++;return null;},
					));
				}
			}
			$driverStructuredAttacks=[];
			foreach(['mysql','postgresql','sqlite'] as $driver){
				$builder='\\dataphyre\\'.$driver.'_query_builder';
				$driverStructuredAttacks[]=$driver==='postgresql'
					? static fn()=>$builder::postgresql_select('primary','*','fixture; COMMIT; --','',null,true)
					: static fn()=>$builder::{$driver.'_select'}('primary','*','fixture; COMMIT; --','',null,true);
				$driverStructuredAttacks[]=static fn()=>$builder::{$driver.'_count'}('primary','fixture; COMMIT; --','',null);
				$driverStructuredAttacks[]=static fn()=>$builder::{$driver.'_update'}('primary','fixture; COMMIT; --','id=?','WHERE id=?',[1,1]);
				$driverStructuredAttacks[]=static fn()=>$builder::{$driver.'_insert'}('primary','fixture; COMMIT; --','id',[1]);
				$driverStructuredAttacks[]=static fn()=>$builder::{$driver.'_delete'}('primary','fixture','WHERE 1=1; COMMIT; --',null);
			}
			foreach($driverStructuredAttacks as $attack){$t->throws($attack,RuntimeException::class);}
			$t->same(0,$driverDialbackCalls);
			return 'structured-guarded';
		});
	}));
	$t->same('direct-immediate',\dataphyre\sql::without_deferred_queries(
		static fn()=>\dataphyre\sql::query('SELECT 1'),
	));
	$t->throws(static fn()=>\dataphyre\sql::without_deferred_queries(
		static fn()=>\dataphyre\sql::query('SELECT 1',queue:null,callback:static fn()=>null),
	),RuntimeException::class);
	$t->throws(static fn()=>\dataphyre\sql::without_deferred_queries(
		static fn()=>\dataphyre\postgresql_query_builder::execute_multiquery('any'),
	),RuntimeException::class);

	$outer=new \Dataphyre\Database\Transaction();
	$t->same('guarded',$outer->run(static function() use ($t,&$reenterControl,&$reentrantControlFailure): string {
		return \dataphyre\sql::without_deferred_queries(
			static function() use ($t,&$reenterControl,&$reentrantControlFailure): string {
				$t->throws(static fn()=>sql_commit(),RuntimeException::class);
				$t->throws(static fn()=>\dataphyre\sql::query('SELECT 1; COMMIT'),RuntimeException::class);
				$t->throws(static fn()=>\dataphyre\sql::query("SELECT '--'; COMMIT"),RuntimeException::class);
				$t->throws(static fn()=>\dataphyre\sql::query('SELECT $$--$$; COMMIT'),RuntimeException::class);
				$t->throws(static fn()=>\dataphyre\sql::query('SELECT "\\"; COMMIT; SELECT "x"'),RuntimeException::class);
				$t->throws(static fn()=>\dataphyre\sql::query('SELECT 1 # 1; COMMIT'),RuntimeException::class);
				$t->throws(static fn()=>\dataphyre\sql::query([
					'mysql'=>'SELECT 1','postgresql'=>'SELECT 1','sqlite'=>'SELECT 1',
					'dbms_cluster_override'=>'other',
				]),RuntimeException::class);
				foreach(['',false,' primary '] as $invalidOverride){
					$t->throws(static fn()=>\dataphyre\sql::query([
						'mysql'=>'SELECT 1','postgresql'=>'SELECT 1','sqlite'=>'SELECT 1',
						'dbms_cluster_override'=>$invalidOverride,
					]),RuntimeException::class);
				}
				$t->throws(static fn()=>(new \Dataphyre\Database\Transaction('other'))->begin(),RuntimeException::class);
				$t->throws(static fn()=>\dataphyre\postgresql_query_builder::postgresql_query(
					'', 'COMMIT', null, false, false,
				),RuntimeException::class);
				$t->throws(static fn()=>\dataphyre\postgresql_query_builder::postgresql_select(
					'other','*','fixture','',null,true,
				),RuntimeException::class);

				$nested=new \Dataphyre\Database\Transaction();
				$reenterControl=true;
				try{$nested->begin();}finally{$reenterControl=false;}
				$t->instanceOf(RuntimeException::class,$reentrantControlFailure);
				$nested->commit();

				$queryFiber=new Fiber(static fn()=>\dataphyre\sql::query('SELECT 1'));
				$t->throws(static fn()=>$queryFiber->start(),RuntimeException::class);
				$selectFiber=new Fiber(static fn()=>\dataphyre\sql::select('*','fixture'));
				$t->throws(static fn()=>$selectFiber->start(),RuntimeException::class);
				return 'guarded';
			},
		);
	}));

	$query=static function(string|array $sql,?array $vars,bool $associative): mixed {
		$encoded=json_encode($sql,JSON_THROW_ON_ERROR);
		if($associative && str_contains($encoded,'SELECT lock_id')) return [['lock_id'=>1]];
		return true;
	};
	$ledger=new SqlSeedLedger(
		'managed_seed_queue_ledger',null,$query,static fn(callable $callback): mixed=>$callback(),'postgresql',
	);
	$t->throws(static fn()=>$ledger->transaction(static function(): void {
		\dataphyre\postgresql_query_builder::$queued_queries['arbitrary-name']=['raw'=>[['query'=>'SELECT 1']]];
	}),RuntimeException::class);
	$t->same([],\dataphyre\postgresql_query_builder::$queued_queries);
	\dataphyre\mysql_query_builder::$queued_queries['pre-existing']=['raw'=>[['query'=>'SELECT 1']]];
	$t->throws(static fn()=>$ledger->transaction(static fn()=>null),RuntimeException::class);
	$t->same([],\dataphyre\mysql_query_builder::$queued_queries);
	$t->same([],\dataphyre\sqlite_query_builder::$queued_queries);

	$unbalancedEnvironment=new \Dataphyre\Database\Transaction();
	$t->throws(
		static fn()=>$unbalancedEnvironment->run(
			static fn()=>\Dataphyre\Database\DataEnvironment::run(
				'live',
				static fn()=>\dataphyre\sql::without_deferred_queries(
					static fn()=>\Dataphyre\Database\DataEnvironment::push('live'),
				),
			),
		),
		RuntimeException::class,
	);
	$t->isTrue($unbalancedEnvironment->rolledBack());
})->tag('transaction','queue','rollback','shutdown','security');

test('root validator accepts only authenticated exact terminal evidence',static function(Context $t): void {
	$key=random_bytes(32);
	$sign=static function(array $payload,string $key): string {
		$unsigned=json_encode(dataphyre_one_shot_canonicalize_seed_evidence($payload),
			JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		$payload['evidence_mac']='sha256:'.hash_hmac('sha256',$unsigned,$key);
		return json_encode(dataphyre_one_shot_canonicalize_seed_evidence($payload),
			JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
	};
	$result=[
		'requested_profile_definition_count'=>2,'active_definition_count'=>2,
		'active_keyset_sha256'=>'sha256:'.str_repeat('a',64),'batch'=>1,'applied_count'=>2,
		'applied_keyset_sha256'=>'sha256:'.str_repeat('b',64),'skipped_count'=>0,
		'convergence'=>[
			'active_applied_count'=>2,'active_keyset_sha256'=>'sha256:'.str_repeat('a',64),
			'drift_count'=>0,'inactive_applied_count'=>0,'orphaned_count'=>0,'pending_count'=>0,
		],
	];
	$success=[
		'application'=>'serve','contract'=>'dataphyre.managed_seed_apply.v1','data_environment'=>'live',
		'demo_acknowledged'=>true,'environment'=>'production','ok'=>true,'profile'=>'demo','result'=>$result,
	];
	$line=$sign($success,$key);
	$validated=dataphyre_one_shot_validate_seed_evidence(
		$line,'',false,$key,'serve','production','live','demo','1',
	);
	$t->isTrue(is_array($validated));$t->same(true,$validated['ok']);
	$t->isFalse(str_contains($validated['line'],'evidence_mac'));
	$failureLine=$sign([
		'application'=>'serve','contract'=>'dataphyre.managed_seed_apply.v1','data_environment'=>'live',
		'environment'=>'production','error'=>['code'=>'seed_apply_failed'],'ok'=>false,'profile'=>'demo',
	],$key);
	$failure=dataphyre_one_shot_validate_seed_evidence(
		$failureLine,'',false,$key,'serve','production','live','demo','1',
	);
	$t->isTrue(is_array($failure));$t->same(false,$failure['ok']);

	$t->same(null,dataphyre_one_shot_validate_seed_evidence(
		$line,'',false,random_bytes(32),'serve','production','live','demo','1',
	));
	$t->same(null,dataphyre_one_shot_validate_seed_evidence(
		$line,'raw-secret',false,$key,'serve','production','live','demo','1',
	));
	$t->same(null,dataphyre_one_shot_validate_seed_evidence(
		$line."noise\n",'',false,$key,'serve','production','live','demo','1',
	));
	$t->same(null,dataphyre_one_shot_validate_seed_evidence(
		$line,'',true,$key,'serve','production','live','demo','1',
	));
	$impossible=$success;$impossible['result']['requested_profile_definition_count']=0;
	$t->same(null,dataphyre_one_shot_validate_seed_evidence(
		$sign($impossible,$key),'',false,$key,'serve','production','live','demo','1',
	));
	$forged=[
		'application'=>'serve','contract'=>'dataphyre.managed_seed_apply.v1','data_environment'=>'live',
		'environment'=>'production','error'=>['code'=>'seed_apply_failed','seed_key'=>'asecret123@1'],
		'evidence_mac'=>'sha256:'.str_repeat('0',64),'ok'=>false,'profile'=>'demo',
	];
	$forgedLine=json_encode(dataphyre_one_shot_canonicalize_seed_evidence($forged),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
	$t->same(null,dataphyre_one_shot_validate_seed_evidence(
		$forgedLine,'',false,$key,'serve','production','live','demo','1',
	));
})->tag('evidence','authentication','canonical','redaction','security');

test('seed pipe capture drains bounded raw streams without forwarding them',static function(Context $t): void {
	$workspace=$t->workspace('managed-seed-pipe-capture');
	$fixture=$workspace->file('raw-output.php',"<?php\n".
		'fwrite(STDOUT,str_repeat("x",200000));'."\n".
		'fwrite(STDERR,"raw-secret-sentinel");'."\n");
	$pipes=[];
	$process=proc_open( // dataphyre-test-architecture: exempt[raw-process-control] reason="The root seed evidence boundary must prove its exact pipe directions and concurrent bounded drain."
		[PHP_BINARY,$fixture],
		[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],
		$pipes,$workspace->root(),null,['bypass_shell'=>true,'suppress_errors'=>true],
	);
	if(!is_resource($process)) throw new RuntimeException('Unable to start seed output fixture.');
	$stdout='';$stderr='';$total=0;$overflow=false;$deadline=microtime(true)+5;
	do{
		dataphyre_one_shot_drain_seed_stream($pipes[1] ?? null,$stdout,$total,$overflow);
		dataphyre_one_shot_drain_seed_stream($pipes[2] ?? null,$stderr,$total,$overflow);
		$status=proc_get_status($process);
		if(!is_array($status)) throw new RuntimeException('Seed output fixture status is unavailable.');
		if(($status['running'] ?? false)!==true && dataphyre_one_shot_seed_streams_eof($pipes)) break;
		usleep(1000);
	}while(microtime(true)<$deadline);
	foreach([1,2] as $index) if(is_resource($pipes[$index] ?? null)) fclose($pipes[$index]);
	proc_close($process);
	$t->isTrue($overflow);$t->same(200019,$total);
	$t->lessThanOrEqual(DATAPHYRE_ONE_SHOT_SEED_MAXIMUM_CAPTURE_BYTES,strlen($stdout)+strlen($stderr));
	$t->same(null,dataphyre_one_shot_validate_seed_evidence(
		$stdout,$stderr,$overflow,random_bytes(32),'serve','production','live','demo','1',
	));
})->tag('output','pipe','bounded','deadlock','security');

test('authenticated terminal emission hard-stops before application shutdown code',static function(Context $t): void {
	$workspace=$t->workspace('managed-seed-hard-stop');
	$entrypoint=dirname(__DIR__).'/kernel/managed_seeds.php';$key=random_bytes(32);
	$fixture=$workspace->file('hard-stop.php',"<?php\n".
		'require '.var_export($entrypoint,true).';'."\n".
		'$key=dataphyre_managed_seed_read_evidence_key();'."\n".
		'$level=dataphyre_managed_seed_install_output_boundary(); $pending=true;'."\n".
		'register_shutdown_function(static fn()=>fwrite(STDOUT,"late-secret-sentinel"));'."\n".
		'dataphyre_managed_seed_terminate($level,['.
		'"application"=>"serve","contract"=>"dataphyre.managed_seed_apply.v1","data_environment"=>"live",'.
		'"environment"=>"production","error"=>["code"=>"seed_operation_failed"],"ok"=>false,"profile"=>"demo"'.
		'],$pending,$key);'."\n");
	$pipes=[];
	$process=proc_open( // dataphyre-test-architecture: exempt[raw-process-control] reason="The managed child must prove authenticated evidence precedes an immediate shutdown-callback boundary."
		[PHP_BINARY,$fixture],
		[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
		$pipes,$workspace->root(),null,['bypass_shell'=>true,'suppress_errors'=>true],
	);
	if(!is_resource($process)) throw new RuntimeException('Unable to start managed seed terminal fixture.');
	$authority=bin2hex($key)."\n";$written=fwrite($pipes[0],$authority);fclose($pipes[0]);
	$t->same(strlen($authority),$written);sodium_memzero($authority);
	$stdout='';$stderr='';$total=0;$overflow=false;$validated=null;$terminalStatus=null;$killAccepted=false;$deadline=microtime(true)+5;
	do{
		dataphyre_one_shot_drain_seed_stream($pipes[1] ?? null,$stdout,$total,$overflow);
		dataphyre_one_shot_drain_seed_stream($pipes[2] ?? null,$stderr,$total,$overflow);
		if(str_ends_with($stdout,"\n")){
			$validated=dataphyre_one_shot_validate_seed_evidence(
				$stdout,$stderr,$overflow,$key,'serve','production','live','demo','1',
			);
			if(is_array($validated)){
				$status=proc_get_status($process);
				if(!$killAccepted && is_array($status) && is_int($status['pid'] ?? null)){
					$killAccepted=posix_kill($status['pid'],9);
				}
			}
		}
		$status=proc_get_status($process);
		if(!is_array($status)) throw new RuntimeException('Managed seed terminal fixture status is unavailable.');
		if(($status['running'] ?? false)!==true){
			$terminalStatus=$status;
			if(dataphyre_one_shot_seed_streams_eof($pipes)) break;
		}
		usleep(1000);
	}while(microtime(true)<$deadline);
	foreach([1,2] as $index) if(is_resource($pipes[$index] ?? null)) fclose($pipes[$index]);
	proc_close($process);
	$t->isFalse(str_contains((string)$stdout,'secret'));$t->same('',(string)$stderr);
	$t->isTrue(
		$killAccepted && is_array($terminalStatus) && ($terminalStatus['running'] ?? true)===false,
		json_encode(['kill_accepted'=>$killAccepted,'terminal_status'=>$terminalStatus],JSON_THROW_ON_ERROR),
	);
	$t->isTrue(is_array($validated));$t->same(false,$validated['ok']);
})->tag('output','stdin','authentication','shutdown','destructor','security');
