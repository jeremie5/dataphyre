<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\RegisteredTableMaterializationCommand;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/Framework/RegisteredTableMaterializationCommand.php';

/** @param list<string> $arguments @param array<string,mixed> $runtime @return array{status:int,out:string,error:string,payload:array<string,mixed>} */
function dp_registered_table_command_run(array $arguments,array $runtime=[]): array {
	$out='';$error='';
	$status=RegisteredTableMaterializationCommand::main($arguments,array_replace($runtime,[
		'write_out'=>static function(string $value) use (&$out): int {$out.=$value;return strlen($value);},
		'write_error'=>static function(string $value) use (&$error): int {$error.=$value;return strlen($value);},
	]));
	$payload=json_decode($out!=='' ? $out : $error,true,32,JSON_THROW_ON_ERROR);
	return ['status'=>$status,'out'=>$out,'error'=>$error,'payload'=>$payload];
}

/** @return list<string> */
function dp_registered_table_names(int $count): array {
	$tables=[];
	for($index=0;$index<$count;$index++) $tables[]='fixture.table_'.$index;
	return $tables;
}

/**
 * Creates a minimal native Framework application for exact materializer subprocess probes.
 *
 * @return array{root:string,state:string}
 */
function dp_registered_table_native_project(Context $t,string $name,string $frameworkBootstrap,?string $definition=null): array {
	$workspace=$t->workspace('registered-table-native-'.$name);
	$workspace->file('flight_sheet.php',<<<'PHP'
<?php
return ['bootstrap'=>[
	'app'=>'fixture',
	'prevent_keyless_direct_access'=>false,
	'allow_app_override'=>false,
	'is_production'=>true,
	'application_roots'=>[__DIR__.'/applications'],
	'modules'=>['enabled'=>['sql'],'disabled'=>[]],
	'flightdeck'=>['enabled'=>false,'debugbar'=>['enabled'=>false]],
]];
PHP);
	$workspace->file('applications/fixture/app.php',<<<'PHP'
<?php
return [
	'id'=>'fixture',
	'framework_bootstrap_file'=>__DIR__.'/framework_bootstrap.php',
	'options'=>['fallback_to_legacy_bootstrap'=>false],
];
PHP);
	$workspace->file('applications/fixture/framework_bootstrap.php',$frameworkBootstrap);
	$workspace->file('applications/fixture/sql_runtime.php',<<<'PHP'
<?php
namespace dataphyre {
	final class sql {
		private static array $definitions=[];
		public static function define_table(string $table,string $definition): bool {
			self::$definitions[$table]=$definition;
			return true;
		}
		public static function registered_table_definitions(): array {
			$deferred=$GLOBALS['dataphyre_deferred_sql_table_definitions'] ?? [];
			$GLOBALS['dataphyre_deferred_sql_table_definitions']=[];
			foreach($deferred as $callback) if(\is_callable($callback)) $callback();
			$tables=\array_keys(self::$definitions);
			\sort($tables,\SORT_STRING);
			return $tables;
		}
		public static function materializable_table_definitions(): array {
			return self::registered_table_definitions();
		}
		public static function hydrate_table_definition(string $table): bool {
			$definition=self::$definitions[$table] ?? null;
			if(!\is_string($definition) || !\is_file($definition)) return false;
			$value=require $definition;
			if(\is_callable($value)) $value=$value($table);
			return $value===true;
		}
	}
}
namespace {
	function sql_define_table(string $table,string $definition): bool {
		return \dataphyre\sql::define_table($table,$definition);
	}
}
PHP);
	if($definition!==null) $workspace->file('definition.php',$definition);
	return ['root'=>$workspace->root(),'state'=>$workspace->path('materializer-state.log')];
}

test('registered table materialization emits deterministic bounded success evidence',static function(Context $t): void {
	if(!class_exists('dataphyre\\sql',false)){
		$t->same([
			'registered_count'=>0,
			'table_set_sha256'=>hash('sha256','[]'),
		],RegisteredTableMaterializationCommand::registeredTableInventoryEvidence());
	}
	$workspace=$t->workspace('registered-table-materialization-success');
	$boot=[];$materialized=[];
	$tables=['dataphyre.permission_roles','dataphyre.mailer_outbox','dataphyre.permission_assignments'];
	$sorted=$tables;sort($sorted,SORT_STRING);
	$run=dp_registered_table_command_run([
		'materialize_registered_tables.php','--project-root='.$workspace->root(),
		'--application=Fixture.App','--environment=Staging.Blue',
	],[
		'environment_values'=>['DATAPHYRE_ENVIRONMENT'=>'Staging.Blue'],
		'bootstrap'=>static function(string $root,string $application,string $environment) use (&$boot): void {
			$boot=[$root,$application,$environment];
		},
		'registered_tables'=>static fn(): array=>$tables,
		'materialize'=>static function(string $table) use (&$materialized): bool {$materialized[]=$table;return true;},
	]);
	$t->same(RegisteredTableMaterializationCommand::EXIT_SUCCESS,$run['status']);
	$t->same('', $run['error']);
	$t->same([$workspace->root(),'Fixture.App','Staging.Blue'],$boot);
	$t->same($sorted,$materialized);
	$t->hasPathValues([
		'contract'=>RegisteredTableMaterializationCommand::CONTRACT,
		'contract_version'=>1,
		'ok'=>true,
		'exit_status'=>0,
		'application'=>'Fixture.App',
		'environment'=>'Staging.Blue',
		'registered_count'=>3,
		'materialized_count'=>3,
	],$run['payload']);
	$t->same(hash('sha256',json_encode($sorted,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
		$run['payload']['table_set_sha256']);
	$t->lessThanOrEqual(RegisteredTableMaterializationCommand::MAX_OUTPUT_BYTES,strlen($run['out']));
	$keys=array_keys($run['payload']);$ordered=$keys;sort($ordered,SORT_STRING);$t->same($ordered,$keys);

	$empty=dp_registered_table_command_run([
		'materialize_registered_tables.php','--project-root='.$workspace->root(),
		'--application=fixture','--environment=production',
	],[
		'bootstrap'=>static function(): void {},
		'registered_tables'=>static fn(): array=>[],
		'materialize'=>static fn(): bool=>throw new RuntimeException('must not run'),
	]);
	$t->same(0,$empty['status']);$t->same(0,$empty['payload']['registered_count']);
	$t->same(0,$empty['payload']['materialized_count']);
})->tag('sql','table-definition','materialization','cli','release','positive')->group('framework-coverage');

test('registered table materialization fails closed on bootstrap registry and hydration errors',static function(Context $t): void {
	$workspace=$t->workspace('registered-table-materialization-failures');
	$arguments=[
		'materialize_registered_tables.php','--project-root='.$workspace->root(),
		'--application=fixture','--environment=production',
	];
	$base=['bootstrap'=>static function(): void {}];

	$bootstrap=dp_registered_table_command_run($arguments,[
		'bootstrap'=>static fn()=>throw new RuntimeException('secret-bootstrap-detail'),
	]);
	$t->same(RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,$bootstrap['status']);
	$t->same('bootstrap_failed',$bootstrap['payload']['error']['code']);
	$t->isFalse(str_contains($bootstrap['error'],'secret-bootstrap-detail'));

	foreach([
		['registered_tables'=>static fn(): array=>['valid.table','valid.table']],
		['registered_tables'=>static fn(): array=>['invalid-table']],
		['registered_tables'=>static fn(): array=>['valid.table'=>'not-a-list']],
		['registered_tables'=>static fn(): array=>dp_registered_table_names(1025)],
		['registered_tables'=>static fn()=>throw new RuntimeException('secret-registry-detail')],
	] as $case){
		$invalid=dp_registered_table_command_run($arguments,[...$base,...$case]);
		$t->same(RegisteredTableMaterializationCommand::EXIT_CONFIGURATION,$invalid['status']);
		$t->same('registered_table_inventory_invalid',$invalid['payload']['error']['code']);
		$t->isFalse(str_contains($invalid['error'],'secret-'));
	}

	$attempted=[];
	$failed=dp_registered_table_command_run($arguments,[
		...$base,
		'registered_tables'=>static fn(): array=>['fixture.good','fixture.false','fixture.throwing'],
		'materialize'=>static function(string $table) use (&$attempted): bool {
			$attempted[]=$table;
			return match($table){
				'fixture.good'=>true,
				'fixture.false'=>false,
				default=>throw new RuntimeException('secret-hydration-detail'),
			};
		},
	]);
	$t->same(RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,$failed['status']);
	$t->same('table_materialization_failed',$failed['payload']['error']['code']);
	$t->same(3,$failed['payload']['registered_count']);
	$t->same(1,$failed['payload']['materialized_count']);
	$t->same(2,$failed['payload']['failed_count']);
	$t->same(['fixture.false','fixture.throwing'],$failed['payload']['failed_tables']);
	$t->same(['fixture.false','fixture.good','fixture.throwing'],$attempted);
	$t->lessThanOrEqual(RegisteredTableMaterializationCommand::MAX_OUTPUT_BYTES,strlen($failed['error']));
	$t->isFalse(str_contains($failed['error'],'secret-hydration-detail'));
})->tag('sql','table-definition','materialization','cli','release','fail-closed','negative')->group('framework-coverage');

test('brokered managed purpose scopes materialization to its configured cluster and restores context',static function(Context $t): void {
	$payload=$t->processSucceeded($t->phpFixture(
		__DIR__.'/fixtures/registered_table_materialization_data_environment_probe.php'
	))->json();
	foreach(['absent','primary'] as $ordinary){
		$t->same(RegisteredTableMaterializationCommand::EXIT_SUCCESS,$payload[$ordinary]['status'],$ordinary);
		$t->same('live',$payload[$ordinary]['observed'][0]['name'],$ordinary);
		$t->same(null,$payload[$ordinary]['observed'][0]['cluster'],$ordinary);
		$t->same(['name'=>'live','cluster'=>null,'cache_namespace'=>null],$payload[$ordinary]['after'],$ordinary);
	}
	$t->same(RegisteredTableMaterializationCommand::EXIT_SUCCESS,$payload['sandbox_success']['status']);
	$t->hasPathValues([
		'observed.0.name'=>'sandbox',
		'observed.0.cluster'=>'SandboxCluster',
		'observed.0.cache_namespace'=>'fixture-sandbox',
		'payload.ok'=>true,
	],$payload['sandbox_success']);
	$t->same(['name'=>'live','cluster'=>null,'cache_namespace'=>null],$payload['sandbox_success']['after']);

	$t->same(RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,$payload['sandbox_failure']['status']);
	$t->same('table_materialization_failed',$payload['sandbox_failure']['payload']['error']['code']);
	$t->same('sandbox',$payload['sandbox_failure']['observed'][0]['name']);
	$t->same('SandboxCluster',$payload['sandbox_failure']['observed'][0]['cluster']);
	$t->same(['name'=>'live','cluster'=>null,'cache_namespace'=>null],$payload['sandbox_failure']['after']);

	$t->same(RegisteredTableMaterializationCommand::EXIT_CONFIGURATION,$payload['unbound']['status']);
	$t->same('managed_database_environment_unavailable',$payload['unbound']['payload']['error']['code']);
	$t->same(0,$payload['unbound']['attempted']);
	$t->same([],$payload['unbound']['observed']);
	$t->same(['name'=>'live','cluster'=>null,'cache_namespace'=>null],$payload['unbound']['after']);
})->tag('sql','table-definition','materialization','managed-database','data-environment','cluster','restoration','positive','negative')
	->group('framework-coverage');

test('registered table materialization accepts only typed identities and fixed paths',static function(Context $t): void {
	$workspace=$t->workspace('registered-table-materialization-options');
	$valid=[
		'materialize_registered_tables.php','--project-root='.$workspace->root(),
		'--application=fixture','--environment=production',
	];
	foreach([
		['materialize_registered_tables.php'],
		['materialize_registered_tables.php','run'],
		['materialize_registered_tables.php','--script=release.sh'],
		['materialize_registered_tables.php','--command=php artisan migrate'],
		['materialize_registered_tables.php','--purpose=sandbox'],
		[...$valid,'--environment=production'],
		['materialize_registered_tables.php','--project-root='.$workspace->root(),'--application=../../tenant','--environment=production'],
		['materialize_registered_tables.php','--project-root='.$workspace->root(),'--application=fixture','--environment=..'],
	] as $arguments){
		$invalid=dp_registered_table_command_run($arguments);
		$t->same(RegisteredTableMaterializationCommand::EXIT_USAGE,$invalid['status']);
		$t->same('invalid_invocation',$invalid['payload']['error']['code']);
	}

	$web=dp_registered_table_command_run($valid,['sapi'=>'fpm-fcgi']);
	$t->same(RegisteredTableMaterializationCommand::EXIT_USAGE,$web['status']);
	$t->same('invalid_runtime',$web['payload']['error']['code']);
	$missing=$valid;$missing[1]='--project-root='.$workspace->path('missing');
	$project=dp_registered_table_command_run($missing);
	$t->same(RegisteredTableMaterializationCommand::EXIT_PROJECT,$project['status']);
	$t->same('project_unavailable',$project['payload']['error']['code']);
	$mismatch=dp_registered_table_command_run($valid,[
		'environment_values'=>['DATAPHYRE_ENVIRONMENT'=>'staging'],
	]);
	$t->same(RegisteredTableMaterializationCommand::EXIT_CONFIGURATION,$mismatch['status']);
	$t->same('environment_mismatch',$mismatch['payload']['error']['code']);

	$help=dp_registered_table_command_run(['materialize_registered_tables.php','--help']);
	$t->same(0,$help['status']);$t->same(true,$help['payload']['ok']);
	$t->contains('--project-root=<project>',$help['payload']['usage']);
	$t->contains('--application=<id>',$help['payload']['usage']);
})->tag('sql','table-definition','materialization','cli','typed-boundary','negative')->group('framework-coverage');

test('registered table materialization source owns bootstrap hydration and one-shot argv',static function(Context $t): void {
	$root=dirname(__DIR__);$core=dirname($root).'/core/kernel';
	$command=(string)file_get_contents($root.'/Framework/RegisteredTableMaterializationCommand.php');
	$entrypoint=(string)file_get_contents($root.'/kernel/materialize_registered_tables.php');
	$oneShot=(string)file_get_contents($core.'/application_runtime_one_shot.php');
	$worker=(string)file_get_contents($core.'/application_runtime_one_shot_worker.php');
	foreach(['proc_open','shell_exec','passthru','system(','popen','eval('] as $forbidden){
		$t->isFalse(str_contains($command,$forbidden));$t->isFalse(str_contains($entrypoint,$forbidden));
	}
	foreach([
		'array_is_list','array_key_exists','array_keys','array_map','array_slice','array_values','chdir','class_exists',
		'count','dirname','exit','fwrite','getcwd','getenv','hash','hash_equals','in_array','is_array','is_callable',
		'is_dir','is_int','is_link','is_readable','is_string','json_encode','ksort','method_exists','ob_end_clean',
		'ob_get_level','ob_list_handlers','ob_start','preg_match','putenv','realpath','register_shutdown_function','rtrim','sort',
		'str_replace','strlen','trim',
	] as $builtin){
		$t->same(0,preg_match('/(?<![\\\\A-Za-z0-9_>:])'.preg_quote($builtin,'/').'\s*\(/',$command),
			'Command builtin must resolve only from the global namespace: '.$builtin);
	}
	$t->contains("require $".'resolved',$command);
	$t->contains('materializable_table_definitions',$command);
	$t->contains('hydrate_table_definition($table)',$command);
	$t->contains('if(\\realpath($script)===__FILE__){',$entrypoint);
	$t->contains("$"."_SERVER['SCRIPT_FILENAME']=__FILE__",$entrypoint);
	$t->contains("'dataphyre_materialize_tables'=>[",$oneShot);
	$t->contains("in_array($"."operation,['dataphyre_materialize_tables','dataphyre_sqlite_migrate'],true)",$oneShot);
	$t->contains('mountedApplicationDataRoot($uid)',$oneShot);
	$t->contains("'--project-root=/app','--application='.$".'frameworkApplication', $oneShot);
	$t->contains("'--environment='.$".'environment', $oneShot);
	$t->contains("'dataphyre_materialize_tables'=>dirname(__DIR__,3).'/modules/sql/kernel/materialize_registered_tables.php'",$worker);
	$t->contains('InternalApplicationBootstrapOnly::materializerDatabasePurpose()',$command);
	$t->contains('DataEnvironment::run($databasePurpose',$command);
	$t->isFalse(str_contains($command,"'purpose'=>'"));
})->tag('sql','table-definition','materialization','one-shot','source','security')->group('framework-coverage');

test('registered table materialization ignores late namespaced builtin and class overrides',static function(Context $t): void {
	$payload=$t->processSucceeded($t->phpFixture(
		__DIR__.'/fixtures/registered_table_materialization_late_overrides_probe.php'
	))->json();
	$t->hasPathValues([
		'status'=>RegisteredTableMaterializationCommand::EXIT_SUCCESS,
		'error'=>'',
		'payload.ok'=>true,
		'payload.registered_count'=>2,
		'bootstrap_status'=>RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,
		'bootstrap_failure.error.code'=>'bootstrap_failed',
		'inventory.registered_count'=>0,
	],$payload);
	$t->same(['fixture.alpha','fixture.zeta'],$payload['materialized']);
	$t->same(hash('sha256',json_encode(['fixture.alpha','fixture.zeta'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
		$payload['payload']['table_set_sha256']);
	$t->same(hash('sha256','[]'),$payload['inventory']['table_set_sha256']);
})->tag('sql','table-definition','materialization','namespace','override','security')->group('framework-coverage');

test('importing the materializer command does not expose an unsealed bootstrap-only guard',static function(Context $t): void {
	$payload=$t->processSucceeded($t->phpFixture(
		__DIR__.'/fixtures/registered_table_materialization_import_probe.php'
	))->json();
	$t->same([
		'after'=>false,
		'before'=>false,
		'unrelated_context'=>null,
	],$payload);
})->tag('sql','table-definition','materialization','import','scheduler','isolation','security')->group('framework-coverage');

test('native materializer swallows application output and retains bootstrap context through shutdown',static function(Context $t): void {
	$project=dp_registered_table_native_project($t,'success',<<<'PHP'
<?php
$state=\dirname(__DIR__,2).'/materializer-state.log';
require __DIR__.'/sql_runtime.php';
if($_GET!==[] || $_POST!==[] || $_COOKIE!==[] || $_FILES!==[] || $_REQUEST!==[]){
	throw new \RuntimeException('Materializer request globals were not cleared.');
}
$record=static function(string $stage) use ($state): void {
	$context=\Dataphyre\InternalApplicationBootstrapOnly::context();
	\file_put_contents($state,$stage.':'.(string)($context['purpose'] ?? 'missing')."\n",\FILE_APPEND|\LOCK_EX);
};
$record('bootstrap');
$GLOBALS['dataphyre_deferred_sql_table_definitions'][]=static function() use ($record): void {$record('registry');};
\register_shutdown_function(static function() use ($record): void {$record('shutdown');echo 'shutdown-noise';});
echo 'application-noise';
PHP);
	$entrypoint=dirname(__DIR__).'/kernel/materialize_registered_tables.php';
	$result=$t->phpProcess([
		$entrypoint,'--project-root='.$project['root'],'--application=fixture','--environment=production',
	],working_directory:$project['root']);
	$t->same(RegisteredTableMaterializationCommand::EXIT_SUCCESS,$result->exitCode(),json_encode($result->diagnostic()));
	$t->same('',$result->stderr());
	$t->same(1,substr_count($result->stdout(),"\n"));
	$payload=$result->json();
	$t->hasPathValues(['ok'=>true,'registered_count'=>0,'materialized_count'=>0],$payload);
	$t->same([
		'bootstrap:registered-table-materialization',
		'registry:registered-table-materialization',
		'shutdown:registered-table-materialization',
	],file($project['state'],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
})->tag('sql','table-definition','materialization','bootstrap','shutdown','output','security')->group('framework-coverage');

test('native materializer detects same-level output-buffer replacement and restores swallowing',static function(Context $t): void {
	$project=dp_registered_table_native_project($t,'replaced-output-buffer',<<<'PHP'
<?php
\ob_end_clean();
\ob_start(static fn(string $chunk): string=>$chunk);
echo 'buffered-application-noise';
\register_shutdown_function(static function(): void {echo 'application-shutdown-noise';});
PHP);
	$entrypoint=dirname(__DIR__).'/kernel/materialize_registered_tables.php';
	$result=$t->phpProcess([
		$entrypoint,'--project-root='.$project['root'],'--application=fixture','--environment=production',
	],working_directory:$project['root']);
	$t->same(RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,$result->exitCode(),json_encode($result->diagnostic()));
	$t->same('',$result->stdout());
	$t->same(1,substr_count($result->stderr(),"\n"));
	$t->hasPathValues([
		'ok'=>false,
		'exit_status'=>RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,
		'error.code'=>'bootstrap_failed',
	],$result->stderrJson());
})->tag('sql','table-definition','materialization','bootstrap','output-buffer','security')->group('framework-coverage');

test('native materializer detects deferred registry output-buffer replacement',static function(Context $t): void {
	$project=dp_registered_table_native_project($t,'registry-output-buffer',<<<'PHP'
<?php
require __DIR__.'/sql_runtime.php';
$GLOBALS['dataphyre_deferred_sql_table_definitions'][]=static function(): void {
	\ob_end_clean();
	\ob_start(static fn(string $chunk): string=>$chunk);
	echo 'buffered-registry-noise';
	\register_shutdown_function(static function(): void {echo 'registry-shutdown-noise';});
};
PHP);
	$entrypoint=dirname(__DIR__).'/kernel/materialize_registered_tables.php';
	$result=$t->phpProcess([
		$entrypoint,'--project-root='.$project['root'],'--application=fixture','--environment=production',
	],working_directory:$project['root']);
	$t->same(RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,$result->exitCode(),json_encode($result->diagnostic()));
	$t->same('',$result->stdout());
	$t->same(1,substr_count($result->stderr(),"\n"));
	$t->hasPathValues([
		'ok'=>false,
		'exit_status'=>RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,
		'error.code'=>'bootstrap_failed',
	],$result->stderrJson());
})->tag('sql','table-definition','materialization','registry','output-buffer','security')->group('framework-coverage');

test('native materializer converts premature application exit into one canonical failure',static function(Context $t): void {
	$project=dp_registered_table_native_project($t,'premature-exit',<<<'PHP'
<?php
echo "application-noise";
\register_shutdown_function(static function(): void {echo "application-shutdown-noise";});
\exit(0);
PHP);
	$entrypoint=dirname(__DIR__).'/kernel/materialize_registered_tables.php';
	$result=$t->phpProcess([
		$entrypoint,'--project-root='.$project['root'],'--application=fixture','--environment=production',
	],working_directory:$project['root']);
	$t->same(RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,$result->exitCode(),json_encode($result->diagnostic()));
	$t->same('',$result->stdout());
	$t->same(1,substr_count($result->stderr(),"\n"));
	$t->hasPathValues([
		'ok'=>false,
		'exit_status'=>RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,
		'error.code'=>'bootstrap_terminated',
	],$result->stderrJson());
})->tag('sql','table-definition','materialization','bootstrap','shutdown','exit','security')->group('framework-coverage');

test('native materializer preserves exit 70 when stderr is closed and an exception handler is installed',static function(Context $t): void {
	$project=dp_registered_table_native_project($t,'closed-stderr',<<<'PHP'
<?php
$handlerState=\dirname(__DIR__,2).'/handler.log';
\set_exception_handler(static function(\Throwable $exception) use ($handlerState): void {
	\file_put_contents($handlerState,\get_class($exception));
	\exit(0);
});
\fclose(\STDERR);
\exit(0);
PHP);
	$entrypoint=dirname(__DIR__).'/kernel/materialize_registered_tables.php';
	$result=$t->phpProcess([
		$entrypoint,'--project-root='.$project['root'],'--application=fixture','--environment=production',
	],working_directory:$project['root']);
	$t->same(RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,$result->exitCode(),json_encode($result->diagnostic()));
	$t->same('',$result->stdout());
	$t->same('',$result->stderr());
	$t->isFalse(file_exists($project['root'].'/handler.log'));
})->tag('sql','table-definition','materialization','shutdown','stderr','exit','security')->group('framework-coverage');

test('ordinary hydration failure emits once and keeps context through registry hydration and shutdown',static function(Context $t): void {
	$project=dp_registered_table_native_project($t,'hydration',<<<'PHP'
<?php
$state=\dirname(__DIR__,2).'/materializer-state.log';
$definition=\dirname(__DIR__,2).'/definition.php';
require __DIR__.'/sql_runtime.php';
$record=static function(string $stage) use ($state): void {
	$context=\Dataphyre\InternalApplicationBootstrapOnly::context();
	\file_put_contents($state,$stage.':'.(string)($context['purpose'] ?? 'missing')."\n",\FILE_APPEND|\LOCK_EX);
};
$record('bootstrap');
$GLOBALS['dataphyre_deferred_sql_table_definitions'][]=static function() use ($record,$definition): void {
	$record('registry');
	\sql_define_table('fixture.probe',$definition);
};
\register_shutdown_function(static function() use ($record): void {$record('shutdown');echo 'shutdown-noise';});
echo 'application-noise';
PHP,<<<'PHP'
<?php
$state=__DIR__.'/materializer-state.log';
return static function() use ($state): mixed {
	$context=\Dataphyre\InternalApplicationBootstrapOnly::context();
	\file_put_contents($state,'hydration:'.(string)($context['purpose'] ?? 'missing')."\n",\FILE_APPEND|\LOCK_EX);
	return null;
};
PHP);
	$entrypoint=dirname(__DIR__).'/kernel/materialize_registered_tables.php';
	$result=$t->phpProcess([
		$entrypoint,'--project-root='.$project['root'],'--application=fixture','--environment=production',
	],working_directory:$project['root']);
	$t->same(RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,$result->exitCode(),json_encode($result->diagnostic()));
	$t->same('',$result->stdout());
	$t->same(1,substr_count($result->stderr(),"\n"));
	$t->hasPathValues([
		'ok'=>false,
		'registered_count'=>1,
		'materialized_count'=>0,
		'failed_count'=>1,
		'error.code'=>'table_materialization_failed',
	],$result->stderrJson());
	$t->same([
		'bootstrap:registered-table-materialization',
		'registry:registered-table-materialization',
		'hydration:registered-table-materialization',
		'shutdown:registered-table-materialization',
	],file($project['state'],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
})->tag('sql','table-definition','materialization','registry','hydration','shutdown','security')->group('framework-coverage');

test('native materializer rejects request-global mutation after deferred registry callbacks',static function(Context $t): void {
	$project=dp_registered_table_native_project($t,'registry-context-mutation',<<<'PHP'
<?php
require __DIR__.'/sql_runtime.php';
$GLOBALS['dataphyre_deferred_sql_table_definitions'][]=static function(): void {
	$_GET=['mutated'=>'yes'];
	$_POST=['mutated'=>'yes'];
	$_COOKIE=['mutated'=>'yes'];
	$_FILES=['mutated'=>['name'=>'payload']];
	$_REQUEST=['mutated'=>'yes'];
	throw new \RuntimeException('mutated registry callback');
};
PHP);
	$entrypoint=dirname(__DIR__).'/kernel/materialize_registered_tables.php';
	$result=$t->phpProcess([
		$entrypoint,'--project-root='.$project['root'],'--application=fixture','--environment=production',
	],working_directory:$project['root']);
	$t->same(RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,$result->exitCode(),json_encode($result->diagnostic()));
	$t->same('',$result->stdout());
	$t->same(1,substr_count($result->stderr(),"\n"));
	$t->hasPathValues([
		'ok'=>false,
		'exit_status'=>RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,
		'error.code'=>'bootstrap_failed',
	],$result->stderrJson());
})->tag('sql','table-definition','materialization','registry','request','mutation','security')->group('framework-coverage');

test('native materializer revalidates context immediately after each hydration callback',static function(Context $t): void {
	$project=dp_registered_table_native_project($t,'hydration-context-mutation',<<<'PHP'
<?php
$definition=\dirname(__DIR__,2).'/definition.php';
require __DIR__.'/sql_runtime.php';
$GLOBALS['dataphyre_deferred_sql_table_definitions'][]=static function() use ($definition): void {
	\sql_define_table('fixture.first',$definition);
	\sql_define_table('fixture.second',$definition);
};
PHP,<<<'PHP'
<?php
$state=__DIR__.'/materializer-state.log';
return static function(string $table) use ($state): bool {
	\file_put_contents($state,$table."\n",\FILE_APPEND|\LOCK_EX);
	if($table==='fixture.first') \chdir(\dirname(__DIR__));
	return true;
};
PHP);
	$entrypoint=dirname(__DIR__).'/kernel/materialize_registered_tables.php';
	$result=$t->phpProcess([
		$entrypoint,'--project-root='.$project['root'],'--application=fixture','--environment=production',
	],working_directory:$project['root']);
	$t->same(RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,$result->exitCode(),json_encode($result->diagnostic()));
	$t->same('',$result->stdout());
	$t->same(1,substr_count($result->stderr(),"\n"));
	$t->hasPathValues([
		'ok'=>false,
		'exit_status'=>RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,
		'error.code'=>'bootstrap_failed',
	],$result->stderrJson());
	$t->same(['fixture.first'],file($project['state'],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
})->tag('sql','table-definition','materialization','hydration','context','mutation','security')->group('framework-coverage');

test('native materializer keeps project cwd through deferred registry and hydration',static function(Context $t): void {
	$project=dp_registered_table_native_project($t,'project-cwd',<<<'PHP'
<?php
$state=\dirname(__DIR__,2).'/materializer-state.log';
require __DIR__.'/sql_runtime.php';
\file_put_contents($state,'bootstrap:'.\getcwd()."\n",\FILE_APPEND|\LOCK_EX);
$GLOBALS['dataphyre_deferred_sql_table_definitions'][]=static function() use ($state): void {
	\file_put_contents($state,'registry:'.\getcwd()."\n",\FILE_APPEND|\LOCK_EX);
	\sql_define_table('fixture.cwd','definition.php');
};
PHP,<<<'PHP'
<?php
$state=__DIR__.'/materializer-state.log';
return static function() use ($state): bool {
	\file_put_contents($state,'hydration:'.\getcwd()."\n",\FILE_APPEND|\LOCK_EX);
	return true;
};
PHP);
	$invocationRoot=dirname(__DIR__,4);
	$result=$t->phpProcess([
		'runtime/modules/sql/kernel/materialize_registered_tables.php',
		'--project-root='.$project['root'],'--application=fixture','--environment=production',
	],working_directory:$invocationRoot);
	$t->same(RegisteredTableMaterializationCommand::EXIT_SUCCESS,$result->exitCode(),json_encode($result->diagnostic()));
	$t->same('',$result->stderr());
	$t->same(1,substr_count($result->stdout(),"\n"));
	$t->hasPathValues(['ok'=>true,'registered_count'=>1,'materialized_count'=>1],$result->json());
	$t->same([
		'bootstrap:'.$project['root'],
		'registry:'.$project['root'],
		'hydration:'.$project['root'],
	],file($project['state'],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
})->tag('sql','table-definition','materialization','cwd','registry','hydration','parity')->group('framework-coverage');

test('native materializer detects output-buffer replacement by the last hydration callback',static function(Context $t): void {
	$project=dp_registered_table_native_project($t,'hydration-output-buffer',<<<'PHP'
<?php
$definition=\dirname(__DIR__,2).'/definition.php';
require __DIR__.'/sql_runtime.php';
$GLOBALS['dataphyre_deferred_sql_table_definitions'][]=static function() use ($definition): void {
	\sql_define_table('fixture.first',$definition);
	\sql_define_table('fixture.last',$definition);
};
\register_shutdown_function(static function(): void {echo 'hydration-shutdown-noise';});
PHP,<<<'PHP'
<?php
$state=__DIR__.'/materializer-state.log';
return static function(string $table) use ($state): bool {
	\file_put_contents($state,$table."\n",\FILE_APPEND|\LOCK_EX);
	if($table==='fixture.last'){
		\ob_end_clean();
		\ob_start(static fn(string $chunk): string=>$chunk);
		echo 'buffered-last-hydration-noise';
	}
	return true;
};
PHP);
	$entrypoint=dirname(__DIR__).'/kernel/materialize_registered_tables.php';
	$result=$t->phpProcess([
		$entrypoint,'--project-root='.$project['root'],'--application=fixture','--environment=production',
	],working_directory:$project['root']);
	$t->same(RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,$result->exitCode(),json_encode($result->diagnostic()));
	$t->same('',$result->stdout());
	$t->same(1,substr_count($result->stderr(),"\n"));
	$t->hasPathValues([
		'ok'=>false,
		'exit_status'=>RegisteredTableMaterializationCommand::EXIT_MATERIALIZATION,
		'error.code'=>'bootstrap_failed',
	],$result->stderrJson());
	$t->same(['fixture.first','fixture.last'],file($project['state'],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
})->tag('sql','table-definition','materialization','hydration','output-buffer','security')->group('framework-coverage');
