<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\Migrations\PostgreSqlMigrationCommand;
use Dataphyre\Database\Migrations\PostgreSqlMigrationManifest;
use Dataphyre\Database\Migrations\PostgreSqlMigrationProfile;
use Dataphyre\Test\Context;
use Dataphyre\Test\TempWorkspace;
use function Dataphyre\Test\test;

$dpPostgreSqlCommandRoot=dirname(__DIR__);
require_once $dpPostgreSqlCommandRoot.'/Framework/Migrations/PostgreSqlMigrationProfile.php';
require_once $dpPostgreSqlCommandRoot.'/Framework/Migrations/PostgreSqlMigrationManifest.php';
require_once $dpPostgreSqlCommandRoot.'/Framework/Migrations/PostgreSqlSchemaInspector.php';
require_once $dpPostgreSqlCommandRoot.'/Framework/Migrations/PostgreSqlMigrationRunner.php';
require_once $dpPostgreSqlCommandRoot.'/Framework/Migrations/PostgreSqlMigrationCommand.php';

function dp_postgresql_command_fixture(Context $test, string $name): TempWorkspace {
	$workspace=$test->workspace('postgresql-migration-command-'.$name);
	$sql="CREATE TABLE fixture.items (id BIGINT PRIMARY KEY);\n";
	$profile=[
		'application_id'=>'fixture',
		'schema'=>'fixture',
		'journal_table'=>'schema_migrations',
		'event_table'=>'schema_migration_events',
		'advisory_lock'=>'fixture.postgresql_migrations',
		'bootstrap_ids'=>['001_base'],
		'bootstrap_cutoff'=>'001_base',
		'manifest_public_path'=>'database/postgresql/manifest.json',
		'lock_timeout'=>'5s',
		'statement_timeout'=>'120s',
	];
	$manifest=[
		'schema_version'=>3,
		'algorithm'=>'sha256',
		'bootstrap_cutoff'=>'001_base',
		'source'=>[
			'generator'=>'command fixture',
			'private_marker'=>'source-secret-must-not-be-emitted',
		],
		'migrations'=>[ [
			'id'=>'001_base',
			'phase'=>'bootstrap',
			'up'=>[
				'path'=>'001_base.sql',
				'sha256'=>hash('sha256', $sql),
			],
			'down'=>null,
			'irreversible_reason'=>'Fixture bootstrap boundary.',
			'minimum_compatible_release'=>null,
			'description'=>'Create the fixture baseline.',
		] ],
	];
	$workspace->file('database/postgresql/profile.json', json_encode(
		$profile,
		JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR
	)."\n");
	$workspace->file('database/postgresql/manifest.json', json_encode(
		$manifest,
		JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR
	)."\n");
	$workspace->file('database/postgresql/001_base.sql', $sql);
	return $workspace;
}

/** @param list<string> $arguments @param array<string,mixed> $runtime @return array{status:int,out:string,error:string,payload:array<string,mixed>} */
function dp_postgresql_command_run(array $arguments, array $runtime=[]): array {
	$out='';
	$error='';
	$status=PostgreSqlMigrationCommand::main($arguments, array_replace($runtime, [
		'write_out'=>static function(string $value) use (&$out): int {
			$out.=$value;
			return strlen($value);
		},
		'write_error'=>static function(string $value) use (&$error): int {
			$error.=$value;
			return strlen($value);
		},
	]));
	$encoded=$out!=='' ? $out : $error;
	$payload=json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
	return ['status'=>$status, 'out'=>$out, 'error'=>$error, 'payload'=>$payload];
}

test('PostgreSQL migration command shares the broad public deployment environment grammar',static function(Context $t): void {
	$command=$t->nonPublic(PostgreSqlMigrationCommand::class);
	foreach(['staging_blue','Staging.Blue'] as $environment){
		$options=$command->invoke('options',[
			'postgresql_migrate.php','--project-root=/tmp','--app=fixture',
			'--environment='.$environment,'--mode=automatic','--dry-run',
		]);
		$t->same($environment,$options['environment'],$environment);
	}
	foreach(['.','..',"staging\nblue","staging\0blue",str_repeat('a',129)] as $environment){
		$t->throws(
			static fn()=>$command->invoke('options',[
				'postgresql_migrate.php','--project-root=/tmp','--app=fixture',
				'--environment='.$environment,'--mode=automatic','--dry-run',
			]),
			InvalidArgumentException::class,
			bin2hex($environment),
		);
	}
})->tag('sql','postgresql','migration','environment-identifier','broad-grammar','negative');

test('PostgreSQL migration command applies the native manifest with typed context and canonical secret-safe JSON', static function(Context $t): void {
	$workspace=dp_postgresql_command_fixture($t, 'success');
	$connection=[];
	$application=[];
	$pdo=$t->scriptedPdo('pgsql');
	$runtime=[
		'environment_values'=>[
			'DATAPHYRE_ENVIRONMENT'=>'staging',
			'DATAPHYRE_DATABASE_DSN'=>'pgsql:host=database.internal;dbname=fixture',
			'DATAPHYRE_DATABASE_USER'=>'fixture_user',
			'DATAPHYRE_DATABASE_PASSWORD'=>'database-password-must-not-be-emitted',
		],
		'pdo_factory'=>static function(
			string $dsn,
			?string $username,
			?string $password,
			array $attributes
		) use (&$connection, $pdo): PDO {
			$connection=[$dsn, $username, $password, $attributes];
			return $pdo;
		},
		'apply'=>static function(
			PDO $actualPdo,
			PostgreSqlMigrationProfile $profile,
			PostgreSqlMigrationManifest $manifest,
			array $options
		) use (&$application, $pdo): array {
			$application=[
				'same_pdo'=>$actualPdo===$pdo,
				'application'=>$profile->applicationId(),
				'ids'=>array_column($manifest->entries(), 'id'),
				'mode'=>$options['mode'],
				'dry_run'=>$options['dry_run'],
			];
			return [
				'transaction'=>'committed',
				'transaction_scope'=>'per_migration',
				'migrations'=>['001_base'],
				'deployment_mode'=>'bootstrap',
				'direction'=>'up',
				'operation_id'=>str_repeat('a', 32),
				'release_version'=>'1.2.3',
				'release_sha256'=>str_repeat('b', 64),
				'bootstrap_cutoff'=>'001_base',
				'pending_validation'=>[
					'mode'=>'bootstrap',
					'eligible'=>true,
					'errors'=>[],
					'pending_migrations'=>['001_base'],
					'selected_migrations'=>['001_base'],
					'deferred_migrations'=>[],
					'rolling_scan'=>[
						'performed'=>false,
						'migration_count'=>0,
						'issue_count'=>0,
						'issues'=>[],
						'private'=>'pending-secret-must-not-be-emitted',
					],
					'private'=>'validation-secret-must-not-be-emitted',
				],
				'private'=>'result-secret-must-not-be-emitted',
			];
		},
	];
	$arguments=[
		'postgresql_migrate.php',
		'--project-root='.$workspace->root(),
		'--app=fixture',
		'--environment=staging',
		'--mode=bootstrap',
		'--release-version=1.2.3',
		'--release-sha256='.str_repeat('b', 64),
	];
	$run=dp_postgresql_command_run($arguments, $runtime);
	$repeat=dp_postgresql_command_run($arguments, $runtime);

	$t->same(PostgreSqlMigrationCommand::EXIT_SUCCESS, $run['status']);
	$t->same('', $run['error']);
	$t->same($run['out'], $repeat['out']);
	$t->same([
		'pgsql:host=database.internal;dbname=fixture',
		'fixture_user',
		'database-password-must-not-be-emitted',
	], array_slice($connection, 0, 3));
	$t->same([
		'same_pdo'=>true,
		'application'=>'fixture',
		'ids'=>['001_base'],
		'mode'=>'bootstrap',
		'dry_run'=>false,
	], $application);
	$t->same(true, $run['payload']['ok']);
	$t->same('fixture', $run['payload']['application']);
	$t->same('staging', $run['payload']['environment']);
	$t->same(['001_base'], $run['payload']['result']['migrations']);
	$t->same(1, $run['payload']['manifest']['migration_count']);
	foreach([
		'database-password-must-not-be-emitted',
		'source-secret-must-not-be-emitted',
		'result-secret-must-not-be-emitted',
		'validation-secret-must-not-be-emitted',
		'pending-secret-must-not-be-emitted',
	] as $secret){
		$t->isFalse(str_contains($run['out'], $secret));
	}
})->tag('sql', 'migration', 'postgresql', 'cli', 'security')->group('framework-coverage');

test('PostgreSQL migration command rejects command paths scripts and untyped arguments', static function(Context $t): void {
	foreach([
		['postgresql_migrate.php', 'apply'],
		['postgresql_migrate.php', '--script=/tmp/release.sh'],
		['postgresql_migrate.php', '--bootstrap=/tmp/bootstrap.php'],
		['postgresql_migrate.php', '--fresh-database'],
		['postgresql_migrate.php', '--framework-prerequisite=permission_roles'],
		['postgresql_migrate.php', '--command=php -r secret-value'],
		['postgresql_migrate.php', '--dry-run=true'],
		['postgresql_migrate.php', '--project-root=/tmp', '--project-root=/tmp'],
		[
			'postgresql_migrate.php', '--project-root=/tmp', '--app=../../serve',
			'--environment=production', '--mode=rolling',
		],
	] as $arguments){
		$run=dp_postgresql_command_run($arguments);
		$t->same(PostgreSqlMigrationCommand::EXIT_USAGE, $run['status']);
		$t->same('invalid_invocation', $run['payload']['error']['code']);
		$t->isFalse(str_contains($run['error'], implode(' ', array_slice($arguments, 1))));
	}

	$help=dp_postgresql_command_run(['postgresql_migrate.php', '--help']);
	$t->same(PostgreSqlMigrationCommand::EXIT_SUCCESS, $help['status']);
	$t->same(true, $help['payload']['ok']);
	$t->contains('--project-root=<project>', $help['payload']['usage']);

	$commandSource=(string)file_get_contents(dirname(__DIR__).'/Framework/Migrations/PostgreSqlMigrationCommand.php');
	$entrypointSource=(string)file_get_contents(dirname(__DIR__).'/kernel/postgresql_migrate.php');
	foreach(['proc_open', 'shell_exec', 'passthru', 'system(', 'popen'] as $forbidden){
		$t->isFalse(str_contains($commandSource, $forbidden));
		$t->isFalse(str_contains($entrypointSource, $forbidden));
	}
})->tag('sql', 'migration', 'postgresql', 'cli', 'security')->group('framework-coverage');

test('PostgreSQL migration command resolves automatic first-environment policy before apply', static function(Context $t): void {
	$workspace=dp_postgresql_command_fixture($t, 'automatic');
	$pdo=$t->scriptedPdo('pgsql');
	$selected=[];
	$run=dp_postgresql_command_run([
		'postgresql_migrate.php',
		'--project-root='.$workspace->root(),
		'--app=fixture',
		'--environment=production',
		'--mode=automatic',
	], [
		'environment_values'=>[
			'DATAPHYRE_DATABASE_DSN'=>'pgsql:host=database.internal;dbname=fixture',
		],
		'pdo_factory'=>static fn()=> $pdo,
		'automatic_mode_selector'=>static function(
			PDO $actualPdo,
			PostgreSqlMigrationProfile $profile,
			PostgreSqlMigrationManifest $manifest
		) use (&$selected, $pdo): string {
			$selected=[
				'same_pdo'=>$actualPdo===$pdo,
				'application'=>$profile->applicationId(),
				'cutoff'=>$manifest->bootstrapCutoff(),
			];
			return 'bootstrap';
		},
		'apply'=>static fn(
			PDO $connection,
			PostgreSqlMigrationProfile $profile,
			PostgreSqlMigrationManifest $manifest,
			array $options
		): array=>[
			'transaction'=>'committed',
			'transaction_scope'=>'per_migration',
			'migrations'=>['001_base'],
			'deployment_mode'=>$options['mode'],
			'direction'=>'up',
			'release_version'=>null,
			'release_sha256'=>null,
			'bootstrap_cutoff'=>$manifest->bootstrapCutoff(),
			'pending_validation'=>[
				'mode'=>$options['mode'],
				'eligible'=>true,
				'errors'=>[],
			],
			'convergence_validation'=>[
				'mode'=>'rolling',
				'bootstrap_cutoff_status'=>'applied',
				'pending_migrations'=>[],
				'pending_phases'=>[],
				'selected_migrations'=>[],
				'selected_phases'=>[],
				'deferred_migrations'=>[],
				'eligible'=>true,
				'errors'=>[],
				'compatibility_floor_satisfied'=>true,
			],
		],
	]);

	$t->same(PostgreSqlMigrationCommand::EXIT_SUCCESS, $run['status']);
	$t->same([
		'same_pdo'=>true,
		'application'=>'fixture',
		'cutoff'=>'001_base',
	], $selected);
	$t->same('automatic', $run['payload']['mode']);
	$t->same('bootstrap', $run['payload']['result']['deployment_mode']);
	$t->same('bootstrap', $run['payload']['result']['pending_validation']['mode']);
	$t->same([], $run['payload']['result']['convergence_validation']['pending_migrations']);
})->tag('sql', 'migration', 'postgresql', 'cli', 'automatic')->group('framework-coverage');

test('PostgreSQL migration command selects fresh convergence internally and emits exact final evidence', static function(Context $t): void {
	$workspace=dp_postgresql_command_fixture($t, 'automatic-fresh-convergence');
	$pdo=$t->scriptedPdo('pgsql')
		->queueScalar(0)
		->queueScalar(0)
		->queueScalar(0);
	$received=[];
	$run=dp_postgresql_command_run([
		'postgresql_migrate.php',
		'--project-root='.$workspace->root(),
		'--app=fixture',
		'--environment=production',
		'--mode=automatic',
	], [
		'environment_values'=>[
			'DATAPHYRE_DATABASE_DSN'=>'pgsql:host=database.internal;dbname=fixture',
		],
		'pdo_factory'=>static fn()=> $pdo,
		'apply'=>static function(
			PDO $connection,
			PostgreSqlMigrationProfile $profile,
			PostgreSqlMigrationManifest $manifest,
			array $options
		) use (&$received, $pdo): array {
			$received=[
				'same_pdo'=>$connection===$pdo,
				'mode'=>$options['mode'],
				'automatic_requested'=>$options['automatic_requested'],
				'fresh_database_convergence'=>$options['fresh_database_convergence'],
			];
			return [
				'transaction'=>'committed',
				'transaction_scope'=>'per_migration',
				'migrations'=>['001_base'],
				'deployment_mode'=>'automatic',
				'direction'=>'up',
				'bootstrap_cutoff'=>$manifest->bootstrapCutoff(),
				'pending_validation'=>[
					'mode'=>'automatic',
					'bootstrap_cutoff_status'=>'pending',
					'pending_migrations'=>['001_base'],
					'pending_phases'=>['bootstrap'=>1],
					'selected_migrations'=>['001_base'],
					'selected_phases'=>['bootstrap'=>1],
					'deferred_migrations'=>[],
					'eligible'=>true,
					'errors'=>[],
					'compatibility_floor_satisfied'=>true,
					'fresh_database_proven'=>true,
				],
				'convergence_validation'=>[
					'mode'=>'automatic',
					'bootstrap_cutoff_status'=>'applied',
					'pending_migrations'=>[],
					'pending_phases'=>[],
					'selected_migrations'=>[],
					'selected_phases'=>[],
					'deferred_migrations'=>[],
					'eligible'=>true,
					'errors'=>[],
					'compatibility_floor_satisfied'=>true,
					'fresh_database_proven'=>true,
				],
			];
		},
	]);

	$t->same(PostgreSqlMigrationCommand::EXIT_SUCCESS, $run['status']);
	$t->same('', $run['error']);
	$t->same([
		'same_pdo'=>true,
		'mode'=>'automatic',
		'automatic_requested'=>true,
		'fresh_database_convergence'=>true,
	], $received);
	$convergence=$run['payload']['result']['convergence_validation'];
	$t->same(true, $convergence['eligible']);
	$t->same([], $convergence['pending_migrations']);
	$t->same([], $convergence['selected_migrations']);
	$t->same([], $convergence['deferred_migrations']);
	$t->same(true, $convergence['fresh_database_proven']);
	$t->isTrue(strlen($run['out'])<=PostgreSqlMigrationCommand::MAX_EVIDENCE_BYTES);
})->tag('sql', 'migration', 'postgresql', 'cli', 'automatic', 'fresh', 'convergence')->group('framework-coverage');

test('PostgreSQL migration command rejects automatic success without final convergence proof', static function(Context $t): void {
	$workspace=dp_postgresql_command_fixture($t, 'automatic-incomplete');
	$run=dp_postgresql_command_run([
		'postgresql_migrate.php',
		'--project-root='.$workspace->root(),
		'--app=fixture',
		'--environment=production',
		'--mode=automatic',
	], [
		'environment_values'=>[
			'DATAPHYRE_DATABASE_DSN'=>'pgsql:host=database.internal;dbname=fixture',
		],
		'pdo_factory'=>static fn()=> $t->scriptedPdo('pgsql'),
		'automatic_mode_selector'=>static fn()=> 'rolling',
		'apply'=>static fn(): array=>[
			'transaction'=>'committed',
			'transaction_scope'=>'deployment',
			'migrations'=>[],
			'deployment_mode'=>'rolling',
			'direction'=>'up',
			'bootstrap_cutoff'=>'001_base',
			'pending_validation'=>[
				'mode'=>'rolling',
				'eligible'=>true,
				'errors'=>[],
			],
			'convergence_validation'=>[
				'mode'=>'rolling',
				'bootstrap_cutoff_status'=>'applied',
				'pending_migrations'=>['002_contract'],
				'pending_phases'=>['rolling_contract'=>1],
				'selected_migrations'=>[],
				'selected_phases'=>[],
				'deferred_migrations'=>['002_contract'],
				'eligible'=>false,
				'errors'=>[
					'pending_contract_requires_compatibility_finalization:002_contract',
				],
			],
		],
	]);

	$t->same(PostgreSqlMigrationCommand::EXIT_MIGRATION, $run['status']);
	$t->same('', $run['out']);
	$t->same('migration_convergence_incomplete', $run['payload']['error']['code']);
	$t->same(false, $run['payload']['result']['convergence_validation']['eligible']);
	$t->same(['002_contract'], $run['payload']['result']['convergence_validation']['deferred_migrations']);
})->tag('sql', 'migration', 'postgresql', 'cli', 'automatic', 'convergence', 'fail-closed')->group('framework-coverage');

test('PostgreSQL migration command treats a fully applied automatic rerun as one evidenced no-op', static function(Context $t): void {
	$workspace=dp_postgresql_command_fixture($t, 'automatic-idempotent-rerun');
	$run=dp_postgresql_command_run([
		'postgresql_migrate.php',
		'--project-root='.$workspace->root(),
		'--app=fixture',
		'--environment=production',
		'--mode=automatic',
	], [
		'environment_values'=>[
			'DATAPHYRE_DATABASE_DSN'=>'pgsql:host=database.internal;dbname=fixture',
		],
		'pdo_factory'=>static fn()=> $t->scriptedPdo('pgsql'),
		'automatic_mode_selector'=>static fn()=> 'rolling',
		'apply'=>static fn(): array=>[
			'transaction'=>'committed',
			'transaction_scope'=>'deployment',
			'migrations'=>[],
			'deployment_mode'=>'rolling',
			'direction'=>'up',
			'bootstrap_cutoff'=>'001_base',
			'pending_validation'=>[
				'mode'=>'rolling',
				'bootstrap_cutoff_status'=>'applied',
				'pending_migrations'=>[],
				'pending_phases'=>[],
				'selected_migrations'=>[],
				'selected_phases'=>[],
				'deferred_migrations'=>[],
				'eligible'=>true,
				'errors'=>[],
				'compatibility_floor_satisfied'=>true,
			],
			'convergence_validation'=>[
				'mode'=>'rolling',
				'bootstrap_cutoff_status'=>'applied',
				'pending_migrations'=>[],
				'pending_phases'=>[],
				'selected_migrations'=>[],
				'selected_phases'=>[],
				'deferred_migrations'=>[],
				'eligible'=>true,
				'errors'=>[],
				'compatibility_floor_satisfied'=>true,
			],
		],
	]);

	$t->same(PostgreSqlMigrationCommand::EXIT_SUCCESS, $run['status']);
	$t->same([], $run['payload']['result']['migrations']);
	$t->same('committed', $run['payload']['result']['transaction']);
	$t->same([], $run['payload']['result']['convergence_validation']['pending_migrations']);
	$t->same(true, $run['payload']['result']['convergence_validation']['eligible']);
})->tag('sql', 'migration', 'postgresql', 'cli', 'automatic', 'idempotent', 'no-op')->group('framework-coverage');

test('PostgreSQL migration command returns bounded eligibility blockers before mutation', static function(Context $t): void {
	$workspace=dp_postgresql_command_fixture($t, 'ineligible');
	$applyCalled=false;
	$run=dp_postgresql_command_run([
		'postgresql_migrate.php',
		'--project-root='.$workspace->root(),
		'--app=fixture',
		'--environment=production',
		'--mode=rolling',
		'--dry-run',
	], [
		'environment_values'=>[
			'DATAPHYRE_DATABASE_DSN'=>'pgsql:host=database.internal;dbname=fixture',
		],
		'pdo_factory'=>static fn()=> $t->scriptedPdo('pgsql'),
		'apply'=>static function() use (&$applyCalled): array {
			$applyCalled=true;
			return [
				'transaction'=>'not_started',
				'transaction_scope'=>'none',
				'migrations'=>[],
				'deployment_mode'=>'rolling',
				'direction'=>'up',
				'bootstrap_cutoff'=>'001_base',
				'pending_validation'=>[
					'mode'=>'rolling',
					'eligible'=>false,
					'errors'=>['pending_rolling_migrations_contain_incompatible_sql'],
					'pending_migrations'=>['002_unsafe'],
					'selected_migrations'=>['002_unsafe'],
					'deferred_migrations'=>[],
					'rolling_scan'=>[
						'performed'=>true,
						'migration_count'=>1,
						'issue_count'=>1,
						'issues'=>[ [
							'migration'=>'002_unsafe',
							'code'=>'create_index_requires_concurrent_autocommit_protocol',
							'statement'=>1,
							'private'=>'must-not-leak',
						] ],
						'private'=>'must-not-leak',
					],
					'private'=>'must-not-leak',
				],
			];
		},
	]);

	$t->same(true, $applyCalled);
	$t->same(PostgreSqlMigrationCommand::EXIT_MIGRATION, $run['status']);
	$t->same('', $run['out']);
	$t->same('migration_plan_ineligible', $run['payload']['error']['code']);
	$t->same(false, $run['payload']['result']['pending_validation']['eligible']);
	$t->same(
		'create_index_requires_concurrent_autocommit_protocol',
		$run['payload']['result']['pending_validation']['rolling_scan']['issues'][0]['code']
	);
	$t->isFalse(str_contains($run['error'], 'must-not-leak'));
})->tag('sql', 'migration', 'postgresql', 'cli', 'preflight', 'security')->group('framework-coverage');

test('PostgreSQL migration command does not mutate an ineligible dry-run', static function(Context $t): void {
	$workspace=dp_postgresql_command_fixture($t, 'ineligible-no-mutation');
	$pdo=$t->scriptedPdo('pgsql');
	$run=dp_postgresql_command_run([
		'postgresql_migrate.php',
		'--project-root='.$workspace->root(),
		'--app=fixture',
		'--environment=production',
		'--mode=rolling',
		'--dry-run',
	], [
		'environment_values'=>[
			'DATAPHYRE_DATABASE_DSN'=>'pgsql:host=database.internal;dbname=fixture',
		],
		'pdo_factory'=>static fn()=> $pdo,
	]);

	$t->same(PostgreSqlMigrationCommand::EXIT_MIGRATION, $run['status']);
	$t->same('migration_plan_ineligible', $run['payload']['error']['code']);
	$t->same('not_started', $run['payload']['result']['transaction']);
	$t->same([], $run['payload']['result']['migrations']);
	$t->same(false, $run['payload']['result']['pending_validation']['eligible']);
	$t->same([], $pdo->operationNames());
	$t->same(2, count($pdo->prepared()));
	foreach($pdo->prepared() as $statement){
		$t->same('prepare', $statement['operation']);
		$t->startsWith('SELECT CASE WHEN to_regclass(', $statement['sql']);
	}
})->tag('sql', 'migration', 'postgresql', 'cli', 'preflight', 'dry-run', 'security')->group('framework-coverage');

test('PostgreSQL migration command exposes stable stages without leaking exception or environment values', static function(Context $t): void {
	$web=dp_postgresql_command_run(['postgresql_migrate.php'], ['sapi'=>'fpm-fcgi']);
	$t->same(PostgreSqlMigrationCommand::EXIT_USAGE, $web['status']);
	$t->same('invalid_runtime', $web['payload']['error']['code']);

	$missing=dp_postgresql_command_run([
		'postgresql_migrate.php', '--project-root=/definitely/missing', '--app=fixture',
		'--environment=production', '--mode=rolling',
	]);
	$t->same(PostgreSqlMigrationCommand::EXIT_PROJECT, $missing['status']);
	$t->same('project_unavailable', $missing['payload']['error']['code']);

	$invalidProfile=dp_postgresql_command_fixture($t, 'invalid-profile');
	$profile=json_decode(
		(string)file_get_contents($invalidProfile->path('database/postgresql/profile.json')),
		true,
		64,
		JSON_THROW_ON_ERROR
	);
	$profile['manifest_public_path']='private/secret-manifest.json';
	$invalidProfile->file('database/postgresql/profile.json', json_encode(
		$profile,
		JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR
	)."\n");
	$profileFailure=dp_postgresql_command_run([
		'postgresql_migrate.php', '--project-root='.$invalidProfile->root(), '--app=fixture',
		'--environment=production', '--mode=bootstrap',
	]);
	$t->same(PostgreSqlMigrationCommand::EXIT_CONFIGURATION, $profileFailure['status']);
	$t->same('profile_invalid', $profileFailure['payload']['error']['code']);
	$t->isFalse(str_contains($profileFailure['error'], 'private/secret-manifest.json'));

	$invalidManifest=dp_postgresql_command_fixture($t, 'invalid-manifest');
	$invalidManifest->file('database/postgresql/manifest.json', '{secret-manifest-value');
	$manifestFailure=dp_postgresql_command_run([
		'postgresql_migrate.php', '--project-root='.$invalidManifest->root(), '--app=fixture',
		'--environment=production', '--mode=bootstrap',
	]);
	$t->same(PostgreSqlMigrationCommand::EXIT_MANIFEST, $manifestFailure['status']);
	$t->same('manifest_invalid', $manifestFailure['payload']['error']['code']);
	$t->isFalse(str_contains($manifestFailure['error'], 'secret-manifest-value'));

	$workspace=dp_postgresql_command_fixture($t, 'failures');
	$arguments=[
		'postgresql_migrate.php', '--project-root='.$workspace->root(), '--app=fixture',
		'--environment=production', '--mode=bootstrap',
	];
	$unconfigured=dp_postgresql_command_run($arguments, ['environment_values'=>[]]);
	$t->same(PostgreSqlMigrationCommand::EXIT_CONFIGURATION, $unconfigured['status']);
	$t->same('database_configuration_invalid', $unconfigured['payload']['error']['code']);

	$environment=[
		'DATAPHYRE_ENVIRONMENT'=>'production',
		'DATAPHYRE_DATABASE_DSN'=>'pgsql:host=secret-host;dbname=secret-database',
		'DATAPHYRE_DATABASE_USER'=>'secret-user',
		'DATAPHYRE_DATABASE_PASSWORD'=>'secret-password',
	];
	$connectionFailure=dp_postgresql_command_run($arguments, [
		'environment_values'=>$environment,
		'pdo_factory'=>static fn()=>throw new RuntimeException(
			'secret-host secret-database secret-user secret-password'
		),
	]);
	$t->same(PostgreSqlMigrationCommand::EXIT_DATABASE, $connectionFailure['status']);
	$t->same('database_connection_failed', $connectionFailure['payload']['error']['code']);

	$migrationFailure=dp_postgresql_command_run($arguments, [
		'environment_values'=>$environment,
		'pdo_factory'=>static fn()=> $t->scriptedPdo('pgsql'),
		'apply'=>static fn()=>throw new RuntimeException(
			'migration failed with secret-password at secret-host'
		),
	]);
	$t->same(PostgreSqlMigrationCommand::EXIT_MIGRATION, $migrationFailure['status']);
	$t->same('migration_failed', $migrationFailure['payload']['error']['code']);
	foreach(['secret-host', 'secret-database', 'secret-user', 'secret-password'] as $secret){
		$t->isFalse(str_contains($connectionFailure['error'], $secret));
		$t->isFalse(str_contains($migrationFailure['error'], $secret));
	}

	$environment['DATAPHYRE_ENVIRONMENT']='staging';
	$mismatch=dp_postgresql_command_run($arguments, ['environment_values'=>$environment]);
	$t->same(PostgreSqlMigrationCommand::EXIT_CONFIGURATION, $mismatch['status']);
	$t->same('database_configuration_invalid', $mismatch['payload']['error']['code']);
})->tag('sql', 'migration', 'postgresql', 'cli', 'security', 'fail-closed')->group('framework-coverage');
