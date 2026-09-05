<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\Migrations\PostgreSqlMigrationManifest;
use Dataphyre\Database\Migrations\PostgreSqlMigrationProfile;
use Dataphyre\Database\Migrations\PostgreSqlMigrationRunner;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;
require_once dirname(__DIR__).'/kernel/postgresql_migrate.php';

suite('Shared PostgreSQL migration prerequisites')
	->contract('sql.shared-migration-prerequisite-lock',1)->layer('integration')->risk('critical')
	->watches('module:sql')->isolation('case')->through('postgresql','concurrent-applications','transaction-rollback')
	->tag('sql','migration','postgresql','concurrency')->group('framework-coverage');

function dp_prerequisite_test_manifest(Context $t,string $app,bool $fail=false): array {
	$workspace=$t->workspace('postgresql-shared-prerequisites');
	$sql=$fail ? 'SELECT 1/0;' : 'CREATE TABLE "'.$app.'"."records" (id bigint PRIMARY KEY);';
	$profile=['application_id'=>$app,'schema'=>$app,'journal_table'=>'schema_migrations',
		'event_table'=>'schema_migration_events','advisory_lock'=>$app.'.migrations',
		'bootstrap_ids'=>['001_initial'],'bootstrap_cutoff'=>'001_initial',
		'manifest_public_path'=>'database/postgresql/manifest.json','lock_timeout'=>'10s','statement_timeout'=>'20s'];
	$manifest=['schema_version'=>3,'algorithm'=>'sha256','bootstrap_cutoff'=>'001_initial','source'=>['fixture'=>'shared-prerequisite'],
		'migrations'=>[['id'=>'001_initial','phase'=>'bootstrap','up'=>['path'=>'001_initial.sql','sha256'=>hash('sha256',$sql)],
			'down'=>null,'irreversible_reason'=>'Fixture initial schema.','minimum_compatible_release'=>null,'description'=>'Create fixture schema.']]];
	$workspace->file('postgresql/001_initial.sql',$sql);
	$workspace->file('postgresql/profile.json',json_encode($profile,JSON_THROW_ON_ERROR));
	$workspace->file('postgresql/manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
	$policy=PostgreSqlMigrationProfile::fromArray($profile);
	return [$workspace->root(),$policy,PostgreSqlMigrationManifest::load($workspace->root(),$policy)];
}
function dp_prerequisite_test_worker(string $root): array {
	$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/fixtures/postgresql_prerequisite_worker.php',$root],[
		0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w'],
	],$pipes,null,['DATAPHYRE_TEST_POSTGRES_DSN'=>(string)getenv('DATAPHYRE_TEST_POSTGRES_DSN')]);
	if(!is_resource($process)) throw new RuntimeException('Migration worker unavailable.');
	stream_set_timeout($pipes[1],10);
	$ready=json_decode((string)fgets($pipes[1]),true,8,JSON_THROW_ON_ERROR);
	fwrite($pipes[0],"run\n");fflush($pipes[0]);
	return ['process'=>$process,'pipes'=>$pipes,'backend_pid'=>$ready['backend_pid']];
}
function dp_prerequisite_wait_event(PDO $pdo,int $pid): string {
	$statement=$pdo->prepare('SELECT wait_event_type,wait_event FROM pg_stat_activity WHERE pid=?');
	$deadline=microtime(true)+5;
	do{
		$statement->execute([$pid]);$row=$statement->fetch(PDO::FETCH_ASSOC);
		if(is_array($row) && $row['wait_event_type']==='Lock') return (string)$row['wait_event'];
		usleep(10000);
	}while(microtime(true)<$deadline);
	throw new RuntimeException('Migration worker did not reach its deterministic database lock barrier.');
}

test('different applications serialize shared prerequisite creation and release authority on rollback',static function(Context $t): void {
	$pdo=new PDO((string)getenv('DATAPHYRE_TEST_POSTGRES_DSN'),'postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
	$database=(string)$pdo->query('SELECT current_database()')->fetchColumn();
	if(!str_starts_with($database,'native_prerequisite_')) throw new RuntimeException('Requires an empty native_prerequisite_* disposable database.');
	foreach(['dataphyre','migration_probe_a','migration_probe_b','migration_probe_failure'] as $schema){
		$query=$pdo->prepare('SELECT to_regnamespace(?) IS NULL');$query->execute([$schema]);
		$t->same(true,(bool)$query->fetchColumn());
	}
	$cleanup=static function() use ($pdo): void {
		if($pdo->inTransaction()) $pdo->rollBack();
		$pdo->exec('DROP EVENT TRIGGER IF EXISTS dataphyre_test_prerequisite_gate');
		$pdo->exec('DROP FUNCTION IF EXISTS public.dataphyre_test_prerequisite_gate()');
		foreach(['migration_probe_a','migration_probe_b','migration_probe_failure','dataphyre'] as $schema){
			$pdo->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
		}
	};
	$t->defer($cleanup);
	$gate=random_int(100000000,2000000000);$workers=[];$closed=[];
	$pdo->query('SELECT pg_advisory_lock('.$gate.')');
	$pdo->exec('CREATE FUNCTION public.dataphyre_test_prerequisite_gate() RETURNS event_trigger LANGUAGE plpgsql AS $gate$ BEGIN
		IF EXISTS (SELECT 1 FROM pg_event_trigger_ddl_commands() WHERE object_type=\'schema\' AND object_identity=\'dataphyre\') THEN
			PERFORM pg_advisory_xact_lock('.$gate.');
		END IF;
	END; $gate$');
	$pdo->exec("CREATE EVENT TRIGGER dataphyre_test_prerequisite_gate ON ddl_command_end WHEN TAG IN ('CREATE SCHEMA') EXECUTE FUNCTION public.dataphyre_test_prerequisite_gate()");
	[$a,$policyA,$manifestA]=dp_prerequisite_test_manifest($t,'migration_probe_a');
	[$b,$policyB,$manifestB]=dp_prerequisite_test_manifest($t,'migration_probe_b');
	try{
		$workers[]=dp_prerequisite_test_worker($a);
		$t->same('advisory',dp_prerequisite_wait_event($pdo,$workers[0]['backend_pid']));
		$workers[]=dp_prerequisite_test_worker($b);
		// Baseline waits on the first transaction's uncommitted catalog tuple instead.
		$t->same('advisory',dp_prerequisite_wait_event($pdo,$workers[1]['backend_pid']));
		$pdo->query('SELECT pg_advisory_unlock('.$gate.')');
		foreach($workers as $worker){
			$result=json_decode((string)fgets($worker['pipes'][1]),true,8,JSON_THROW_ON_ERROR);
			$t->same(['ok'=>true,'migrations'=>['001_initial']],$result);
		}
	}finally{
		$pdo->query('SELECT pg_advisory_unlock('.$gate.')');
		foreach($workers as $worker){
			fclose($worker['pipes'][0]);$out=stream_get_contents($worker['pipes'][1]);$error=stream_get_contents($worker['pipes'][2]);
			fclose($worker['pipes'][1]);fclose($worker['pipes'][2]);
			$closed[]=['exit'=>proc_close($worker['process']),'stdout'=>$out];
			$t->same('',$error);
		}
	}
	foreach($closed as $result){
		$t->same(['exit'=>0,'stdout'=>''],$result);
	}
	$t->same(2,(int)$pdo->query("SELECT count(*) FROM pg_tables WHERE schemaname='dataphyre' AND tablename IN ('permission_roles','permission_role_permissions')")->fetchColumn());
	foreach([[$policyA,$manifestA],[$policyB,$manifestB]] as [$policy,$manifest]){
		$runner=new PostgreSqlMigrationRunner($pdo,$policy);
		$t->same(1,$runner->status($manifest)['applied_count']);
		$t->same([],$runner->apply($manifest,'rolling')['migrations']);
	}
	$cleanup();
	[$failedRoot,$failedPolicy,$failedManifest]=dp_prerequisite_test_manifest($t,'migration_probe_failure',true);
	$t->throws(static fn()=>(new PostgreSqlMigrationRunner($pdo,$failedPolicy))->applyFreshDatabase($failedManifest),Throwable::class);
	$t->same(false,$pdo->inTransaction());
	$t->same(true,(bool)$pdo->query("SELECT to_regnamespace('dataphyre') IS NULL")->fetchColumn());
	$t->same(true,(bool)$pdo->query("SELECT to_regnamespace('migration_probe_failure') IS NULL")->fetchColumn());
	$t->same(true,(bool)$pdo->query("SELECT pg_try_advisory_xact_lock(hashtext('dataphyre.postgresql_migration_prerequisites.v1'))")->fetchColumn());
	$retry=(new PostgreSqlMigrationRunner($pdo,$policyA))->applyFreshDatabase($manifestA);
	$t->same(['001_initial'],$retry['migrations']);
	$t->same(true,(bool)$pdo->query("SELECT to_regclass('dataphyre.permission_roles') IS NOT NULL")->fetchColumn());
})->skipUnless(extension_loaded('pdo_pgsql') && str_starts_with((string)getenv('DATAPHYRE_TEST_POSTGRES_DSN'),'pgsql:'),'Requires a disposable PostgreSQL test database.');
