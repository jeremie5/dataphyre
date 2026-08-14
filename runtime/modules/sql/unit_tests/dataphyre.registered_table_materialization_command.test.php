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
	$t->contains("require $".'resolved',$command);
	$t->contains('registered_table_definitions',$command);
	$t->contains('hydrate_table_definition($table)',$command);
	$t->contains("realpath((string)($"."_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__",$entrypoint);
	$t->contains("'dataphyre_materialize_tables'=>[",$oneShot);
	$t->contains("in_array($"."operation,['dataphyre_materialize_tables','dataphyre_sqlite_migrate'],true)",$oneShot);
	$t->contains('mountedApplicationDataRoot($uid)',$oneShot);
	$t->contains("'--project-root=/app','--application='.$".'frameworkApplication', $oneShot);
	$t->contains("'--environment='.$".'environment', $oneShot);
	$t->contains("'dataphyre_materialize_tables'=>dirname(__DIR__,3).'/modules/sql/kernel/materialize_registered_tables.php'",$worker);
})->tag('sql','table-definition','materialization','one-shot','source','security')->group('framework-coverage');
