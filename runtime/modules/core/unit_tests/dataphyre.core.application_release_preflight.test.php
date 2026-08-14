<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Release\ApplicationReleasePreflightCommand;
use Dataphyre\Release\ApplicationReleasePreflightEvidence;
use Dataphyre\Test\Context;
use Dataphyre\Test\TempWorkspace;
use dataphyre\app_locator;
use function Dataphyre\Test\test;

$dpApplicationPreflightCore=dirname(__DIR__);
require_once __DIR__.'/fixtures/application_release_preflight_function_boundaries.php';
require_once $dpApplicationPreflightCore.'/Framework/ApplicationReleasePreflightCommand.php';
require_once $dpApplicationPreflightCore.'/kernel/app_locator.php';

function dp_application_preflight_fixture(Context $test, string $name): TempWorkspace {
	$workspace=$test->workspace('application-release-preflight-'.$name);
	$workspace->file('flight_sheet.php', <<<'PHP'
<?php
return [
	'bootstrap'=>[
		'app'=>'fixture',
		'prevent_keyless_direct_access'=>false,
		'allow_app_override'=>false,
		'is_production'=>false,
		'application_roots'=>[__DIR__],
		'modules'=>['enabled'=>[], 'disabled'=>[]],
		'flightdeck'=>['enabled'=>false, 'debugbar'=>['enabled'=>false]],
	],
];
PHP);
	$workspace->file('dataphyre.app.json', json_encode([
		'schema_version'=>1,
		'name'=>'fixture',
	], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
	$workspace->file('app.php', <<<'PHP'
<?php
return [
	'id'=>'fixture',
	'root_directory'=>__DIR__,
	'rootpath_file'=>null,
	'framework_bootstrap_file'=>__DIR__.'/framework_bootstrap.php',
	'options'=>['fallback_to_legacy_bootstrap'=>false],
];
PHP);
	$workspace->file('framework_bootstrap.php', <<<'PHP'
<?php
if((string)($_SERVER['REQUEST_URI'] ?? '/')!=='/health'){
	http_response_code(404);
	exit;
}
header('Content-Type: application/json; charset=utf-8');
echo '{"status":"healthy","missing_environment_keys":[]}';
PHP);
	return $workspace;
}

/** @param list<string> $arguments @param array<string,mixed> $runtime @return array{status:int,output:string,payload:array<string,mixed>} */
function dp_application_preflight_run(array $arguments, array $runtime=[]): array {
	$output='';
	$status=ApplicationReleasePreflightCommand::main($arguments, array_replace([
		'realtime_runner'=>static fn(): array=>[
			'exit_code'=>0,
			'stdout'=>json_encode([
				'contract'=>'dataphyre.application_realtime_registration.v1',
				'ok'=>true,
				'route_count'=>0,
				'registration_sha256'=>'sha256:'.hash('sha256','[]'),
				'registered_table_count'=>0,
				'registered_table_materialization_contract'=>'dataphyre.registered_table_materialization.v1',
				'registered_table_set_sha256'=>'sha256:'.hash('sha256','[]'),
				'scheduler_definition_count'=>0,
				'scheduler_definition_sha256'=>'sha256:'.hash('sha256','[]'),
			],JSON_THROW_ON_ERROR),
			'stderr'=>'',
		],
	], $runtime, [
		'write_out'=>static function(string $value) use (&$output): int {
			$output.=$value;
			return strlen($value);
		},
	]));
	$payload=json_decode($output, true, 64, JSON_THROW_ON_ERROR);
	return ['status'=>$status, 'output'=>$output, 'payload'=>$payload];
}

/** @return list<string> */
function dp_application_preflight_arguments(TempWorkspace $workspace): array {
	return [
		'application_release_preflight.php',
		'--project-root='.$workspace->root(),
		'--application=fixture',
		'--environment=staging',
	];
}

/** @return null|array{http_status:int,response_contract_valid:bool,missing_environment_keys:list<string>} */
function dp_application_preflight_read_health_response(Context $test, string $response): ?array {
	$stream=fopen('php://temp', 'w+b');
	if(!is_resource($stream)){
		throw new RuntimeException('Unable to create the health response fixture stream.');
	}
	try{
		fwrite($stream, $response);
		rewind($stream);
		return $test->nonPublic(ApplicationReleasePreflightCommand::class)->invoke('readLoopbackResponse', $stream);
	}finally{
		fclose($stream);
	}
}

test('application release preflight returns one deterministic boolean verdict with fixed stage evidence', static function(Context $t): void {
	$workspace=dp_application_preflight_fixture($t, 'passed');
	$run=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
		'health_runner'=>static fn(): array=>[
			'ok'=>true,
			'code'=>'healthy',
			'attempts'=>2,
			'http_status'=>204,
			'response_contract_valid'=>true,
			'missing_environment_keys'=>[],
		],
	]);

	$t->same(ApplicationReleasePreflightCommand::EXIT_SUCCESS, $run['status']);
	$t->same('dataphyre.application_release_preflight.v1', $run['payload']['contract']);
	$t->same(true, $run['payload']['ok']);
	$t->same(true, $run['payload']['likely_to_deploy']);
	$t->same('completed', $run['payload']['execution']);
	$t->same('isolated_database_preflight_and_ephemeral_application_boot', $run['payload']['write_policy']);
	$t->same([], $run['payload']['failures']);
	$t->same([
		'configuration_bootstrap',
		'database_migrations',
		'database_runtime',
		'application_health',
		'realtime_registration',
	], array_column($run['payload']['checks'], 'id'));
	$t->same(['passed','not_applicable','not_applicable','passed','passed'], array_column($run['payload']['checks'], 'status'));
	$t->same([
		'declared'=>false,
		'reason'=>'no_database_migration_profile',
	],$run['payload']['checks'][1]['evidence']);
	$t->same([
		'connection_sha256'=>null,
		'declared'=>false,
		'purpose'=>null,
	], $run['payload']['checks'][2]['evidence']);
	$t->same('/health', $run['payload']['checks'][3]['evidence']['path']);
	$t->same(204, $run['payload']['checks'][3]['evidence']['http_status']);
	$t->same(true, $run['payload']['checks'][3]['evidence']['response_contract_valid']);
	$t->same([], $run['payload']['checks'][3]['evidence']['missing_environment_keys']);
	$t->same([
		'authorization_before_upgrade'=>true,
		'fixed_public_port'=>8080,
		'origin_required'=>true,
		'private_web_port'=>8083,
		'registration_sha256'=>'sha256:'.hash('sha256','[]'),
		'registered_table_count'=>0,
		'registered_table_materialization_contract'=>'dataphyre.registered_table_materialization.v1',
		'registered_table_set_sha256'=>'sha256:'.hash('sha256','[]'),
		'route_count'=>0,
		'scheduler_definition_count'=>0,
		'scheduler_definition_sha256'=>'sha256:'.hash('sha256','[]'),
		'tls_termination'=>'platform_edge',
	],$run['payload']['checks'][4]['evidence']);
	$t->contains('exact candidate image', $run['payload']['claim_boundary']);
	$t->isFalse(str_contains($run['output'], $workspace->root()));
})->tag('core','release','preflight','health','cli','security')->group('framework-coverage');

test('application release preflight preserves every broad environment identifier across fixed child stages', static function(Context $t): void {
	$workspace=dp_application_preflight_fixture($t,'broad-environments');
	$validPayload=null;
	foreach(['staging_blue','Staging.Blue'] as $environment){
		$arguments=dp_application_preflight_arguments($workspace);
		$arguments[3]='--environment='.$environment;
		$observed=[];
		$run=dp_application_preflight_run($arguments,[
			'health_runner'=>static function(
				string $projectRoot,string $application,string $actualEnvironment,string $path,int $timeout,
				?string $applicationDataRoot,
			) use (&$observed,$environment): array {
				if($actualEnvironment!==$environment) throw new RuntimeException('Health environment changed.');
				$observed['health']=$actualEnvironment;
				return [
					'ok'=>true,'code'=>'healthy','attempts'=>1,'http_status'=>200,
					'response_contract_valid'=>true,'missing_environment_keys'=>[],
				];
			},
			'realtime_runner'=>static function(
				string $projectRoot,string $application,string $actualEnvironment,int $timeout,
				?string $applicationDataRoot,
			) use (&$observed,$environment): array {
				if($actualEnvironment!==$environment) throw new RuntimeException('Realtime environment changed.');
				$observed['realtime']=$actualEnvironment;
				return [
					'exit_code'=>0,
					'stdout'=>json_encode([
						'contract'=>'dataphyre.application_realtime_registration.v1','ok'=>true,
						'route_count'=>0,'registration_sha256'=>'sha256:'.hash('sha256','[]'),
						'registered_table_count'=>0,
						'registered_table_materialization_contract'=>'dataphyre.registered_table_materialization.v1',
						'registered_table_set_sha256'=>'sha256:'.hash('sha256','[]'),
						'scheduler_definition_count'=>0,
						'scheduler_definition_sha256'=>'sha256:'.hash('sha256','[]'),
					],JSON_THROW_ON_ERROR),
					'stderr'=>'',
				];
			},
		]);
		$t->same(ApplicationReleasePreflightCommand::EXIT_SUCCESS,$run['status'],$environment);
		$t->same($environment,$run['payload']['environment'],$environment);
		$t->same(['health'=>$environment,'realtime'=>$environment],$observed,$environment);
		$t->same(
			$run['payload'],
			ApplicationReleasePreflightEvidence::validate($run['payload'],$run['status'],'fixture',$environment),
			$environment,
		);
		$validPayload=$run['payload'];
	}
	if(!is_array($validPayload)) throw new RuntimeException('Broad environment fixture did not run.');
	foreach(['.','..',"staging\nblue","staging\0blue"] as $environment){
		$forged=$validPayload;$forged['environment']=$environment;
		$t->same(
			null,
			ApplicationReleasePreflightEvidence::validate($forged,$forged['exit_status'],'fixture',$environment),
			bin2hex($environment),
		);
	}
})->tag('core','release','preflight','environment','child-process','regression')->group('framework-coverage');

test('application release preflight validates realtime failures through the shared completed-evidence authority', static function(Context $t): void {
	$workspace=dp_application_preflight_fixture($t, 'realtime-failure');
	$run=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
		'health_runner'=>static fn(): array=>[
			'ok'=>true,
			'code'=>'healthy',
			'attempts'=>1,
			'http_status'=>200,
			'response_contract_valid'=>true,
			'missing_environment_keys'=>[],
		],
		'realtime_runner'=>static fn(): array=>[
			'exit_code'=>70,
			'stdout'=>'SECRET_REALTIME_FAILURE_DETAIL_MUST_NOT_LEAK',
			'stderr'=>'',
		],
	]);

	$t->same(ApplicationReleasePreflightCommand::EXIT_VERIFICATION, $run['status']);
	$t->same(false, $run['payload']['likely_to_deploy']);
	$t->same('application_realtime_registration_failed', $run['payload']['failures'][0]['code']);
	$t->same('failed', $run['payload']['checks'][4]['status']);
	$t->same(
		$run['payload'],
		ApplicationReleasePreflightEvidence::validate($run['payload'], $run['status'], 'fixture', 'staging')
	);
	$t->isFalse(str_contains($run['output'], 'SECRET_REALTIME_FAILURE_DETAIL_MUST_NOT_LEAK'));
})->tag('core','release','preflight','realtime','evidence','security')->group('framework-coverage');

test('application release preflight fails closed on forged registered table inventory evidence', static function(Context $t): void {
	$workspace=dp_application_preflight_fixture($t,'registered-table-evidence');
	$arguments=dp_application_preflight_arguments($workspace);
	$valid=[
		'contract'=>'dataphyre.application_realtime_registration.v1',
		'ok'=>true,
		'route_count'=>0,
		'registration_sha256'=>'sha256:'.hash('sha256','[]'),
		'registered_table_count'=>2,
		'registered_table_materialization_contract'=>'dataphyre.registered_table_materialization.v1',
		'registered_table_set_sha256'=>'sha256:'.hash('sha256','["fixture.orders","fixture.users"]'),
		'scheduler_definition_count'=>0,
		'scheduler_definition_sha256'=>'sha256:'.hash('sha256','[]'),
	];
	$health=[
		'ok'=>true,'code'=>'healthy','attempts'=>1,'http_status'=>200,
		'response_contract_valid'=>true,'missing_environment_keys'=>[],
	];
	$passed=dp_application_preflight_run($arguments,[
		'health_runner'=>static fn(): array=>$health,
		'realtime_runner'=>static fn(): array=>[
			'exit_code'=>0,'stdout'=>json_encode($valid,JSON_THROW_ON_ERROR),'stderr'=>'',
		],
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_SUCCESS,$passed['status']);
	$t->same(2,$passed['payload']['checks'][4]['evidence']['registered_table_count']);
	$t->same($valid['registered_table_set_sha256'],
		$passed['payload']['checks'][4]['evidence']['registered_table_set_sha256']);

	foreach([
		'count-type'=>[...$valid,'registered_table_count'=>'2'],
		'count-negative'=>[...$valid,'registered_table_count'=>-1],
		'count-overflow'=>[...$valid,'registered_table_count'=>1025],
		'contract'=>[...$valid,'registered_table_materialization_contract'=>'application.materializer.v1'],
		'hash-prefix'=>[...$valid,'registered_table_set_sha256'=>hash('sha256','[]')],
		'hash-case'=>[...$valid,'registered_table_set_sha256'=>'sha256:'.str_repeat('A',64)],
	] as $name=>$forged){
		$run=dp_application_preflight_run($arguments,[
			'health_runner'=>static fn(): array=>$health,
			'realtime_runner'=>static fn(): array=>[
				'exit_code'=>0,'stdout'=>json_encode($forged,JSON_THROW_ON_ERROR),'stderr'=>'',
			],
		]);
		$t->same(ApplicationReleasePreflightCommand::EXIT_VERIFICATION,$run['status'],$name);
		$t->same('application_realtime_registration_failed',$run['payload']['failures'][0]['code'],$name);
		$t->same(0,$run['payload']['checks'][4]['evidence']['registered_table_count'],$name);
		$t->same(null,$run['payload']['checks'][4]['evidence']['registered_table_set_sha256'],$name);
		$t->same($run['payload'],ApplicationReleasePreflightEvidence::validate(
			$run['payload'],$run['status'],'fixture','staging',
		),$name);
	}
})->tag('core','release','preflight','sql','table-definition','evidence','security')->group('framework-coverage');

test('application release preflight shared evidence rejects completed output beyond the public cap', static function(Context $t): void {
	$workspace=dp_application_preflight_fixture($t, 'evidence-output-cap');
	$base=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
		'health_runner'=>static fn(): array=>[
			'ok'=>true,
			'code'=>'healthy',
			'attempts'=>1,
			'http_status'=>200,
			'response_contract_valid'=>true,
			'missing_environment_keys'=>[],
		],
	])['payload'];
	$issues=[];
	foreach(['002_expand','003_expand'] as $migration){
		for($statement=1;$statement<=2048;$statement++){
			$issues[]=[
				'migration'=>$migration,
				'code'=>'add_not_null_column',
				'statement'=>$statement,
			];
		}
	}
	$oversized=$base;
	$oversized['exit_status']=ApplicationReleasePreflightCommand::EXIT_VERIFICATION;
	$oversized['ok']=false;
	$oversized['likely_to_deploy']=false;
	$oversized['checks']=[
		$base['checks'][0],
		[
			'id'=>'database_migrations',
			'status'=>'failed',
			'evidence'=>[
				'declared'=>true,
				'dry_run'=>true,
				'contract'=>'dataphyre.postgresql_migration_command.v1',
				'manifest'=>[
					'algorithm'=>'sha256',
					'bootstrap_cutoff'=>'001_base',
					'migration_count'=>3,
					'schema_version'=>3,
					'sha256'=>str_repeat('a', 64),
				],
				'plan'=>[
					'mode'=>'rolling',
					'eligible'=>false,
					'errors'=>['pending_rolling_migrations_contain_incompatible_sql'],
					'pending_migrations'=>['002_expand','003_expand'],
					'selected_migrations'=>['002_expand','003_expand'],
					'deferred_migrations'=>[],
					'rolling_scan'=>[
						'performed'=>true,
						'migration_count'=>2,
						'issue_count'=>count($issues),
						'issues'=>$issues,
					],
				],
				'exit_status'=>70,
				'error_code'=>'migration_plan_ineligible',
			],
		],
	];
	$oversized['failures']=[[
		'kind'=>'verification',
		'code'=>'migration_plan_ineligible',
		'message'=>'The database migration preflight found drift or an ineligible migration plan.',
	]];

	$t->same(
		$oversized,
		ApplicationReleasePreflightEvidence::validate($oversized, 70, 'fixture', 'staging')
	);
	$pretty=json_encode(
		$oversized,
		JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR
	)."\n";
	$t->isTrue(strlen($pretty)>ApplicationReleasePreflightEvidence::MAX_OUTPUT_BYTES);
	$t->throws(
		static fn()=>ApplicationReleasePreflightEvidence::encodeCompleted($oversized),
		LengthException::class
	);
})->tag('core','release','preflight','evidence','bounds','security')->group('framework-coverage');

test('application release preflight proves the application-resolved managed primary identity without exposing connection material', static function(Context $t): void {
	$workspace=dp_application_preflight_fixture($t, 'managed-database-identity');
	$marker='sha256:'.str_repeat('a', 64);
	$connection='sha256:'.str_repeat('b', 64);
	$t->environment(['DATAPHYRE_DATABASE_BINDING_PRIMARY_SHA256'=>$marker]);
	$databaseCalls=0;
	$healthCalls=0;
	$run=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
		'database_runtime_runner'=>static function(
			string $projectRoot,
			string $application,
			string $environment,
			int $timeout
		) use (&$databaseCalls, $workspace, $marker, $connection): array {
			$databaseCalls++;
			if($projectRoot!==$workspace->root() || $application!=='fixture' || $environment!=='staging' || $timeout!==30000){
				throw new RuntimeException('database runtime invocation was not fixed');
			}
			return [
				'exit_code'=>0,
				'stdout'=>json_encode([
					'contract'=>'dataphyre.database_connection_probe.v1',
					'purpose'=>'primary',
					'binding_sha256'=>$marker,
					'connection_sha256'=>$connection,
					'connected'=>true,
					'identity_query'=>true,
				], JSON_THROW_ON_ERROR),
				'stderr'=>'',
			];
		},
		'health_runner'=>static function() use (&$healthCalls): array {
			$healthCalls++;
			return [
				'ok'=>true,
				'code'=>'healthy',
				'attempts'=>1,
				'http_status'=>200,
				'response_contract_valid'=>true,
				'missing_environment_keys'=>[],
			];
		},
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_SUCCESS, $run['status']);
	$t->same(1, $databaseCalls);
	$t->same(1, $healthCalls);
	$t->same('passed', $run['payload']['checks'][2]['status']);
	$t->same([
		'connection_sha256'=>$connection,
		'declared'=>true,
		'purpose'=>'primary',
	], $run['payload']['checks'][2]['evidence']);
	$t->isFalse(str_contains($run['output'], $marker));

	$failedHealthCalls=0;
	$failed=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
		'database_runtime_runner'=>static fn(): array=>[
			'exit_code'=>69,
			'stdout'=>'',
			'stderr'=>'DATABASE_PASSWORD=must-never-escape',
		],
		'health_runner'=>static function() use (&$failedHealthCalls): array {
			$failedHealthCalls++;
			throw new RuntimeException('health must not run after database identity failure');
		},
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_DEPENDENCY, $failed['status']);
	$t->same(false, $failed['payload']['likely_to_deploy']);
	$t->same(0, $failedHealthCalls);
	$t->same('application_database_identity_failed', $failed['payload']['failures'][0]['code']);
	$t->same('failed', $failed['payload']['checks'][2]['status']);
	$t->same([
		'connection_sha256'=>null,
		'declared'=>true,
		'purpose'=>'primary',
	], $failed['payload']['checks'][2]['evidence']);
	$t->isFalse(str_contains($failed['output'], 'must-never-escape'));

	$validPrivateProbe=[
		'contract'=>'dataphyre.database_connection_probe.v1',
		'purpose'=>'primary',
		'binding_sha256'=>$marker,
		'connection_sha256'=>$connection,
		'connected'=>true,
		'identity_query'=>true,
	];
	foreach([
		'contract'=>[...$validPrivateProbe,'contract'=>'dataphyre.application_database_runtime.v1'],
		'purpose'=>[...$validPrivateProbe,'purpose'=>'analytics'],
		'binding'=>[...$validPrivateProbe,'binding_sha256'=>'sha256:'.str_repeat('c',64)],
		'connected'=>[...$validPrivateProbe,'connected'=>false],
		'identity_query'=>[...$validPrivateProbe,'identity_query'=>false],
		'connection'=>[...$validPrivateProbe,'connection_sha256'=>'not-a-hash'],
	] as $case=>$privateProbe){
		$healthCalls=0;
		$rejected=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
			'database_runtime_runner'=>static fn(): array=>[
				'exit_code'=>0,
				'stdout'=>json_encode($privateProbe,JSON_THROW_ON_ERROR),
				'stderr'=>'',
			],
			'health_runner'=>static function() use (&$healthCalls): array {$healthCalls++;return [];},
		]);
		$t->same(ApplicationReleasePreflightCommand::EXIT_DEPENDENCY,$rejected['status'],$case);
		$t->same(0,$healthCalls,$case);
		$t->same('application_database_identity_failed',$rejected['payload']['failures'][0]['code'],$case);
	}

	$t->environment([
		'DATAPHYRE_DATABASE_BINDING_PRIMARY_SHA256'=>$marker,
		'DATAPHYRE_DATABASE_DSN'=>'sqlite::memory:',
		'DATAPHYRE_DATABASE_USER'=>'fixture',
		'DATAPHYRE_DATABASE_PASSWORD'=>'fixture',
	]);
	$fixedRuntime=$t->nonPublic(ApplicationReleasePreflightCommand::class)->invoke(
		'runDatabaseRuntime',$workspace->root(),'fixture','staging',30000,null,
	);
	$t->same(69,$fixedRuntime['exit_code']);
	$t->same('',$fixedRuntime['stdout']);
	$t->same('',$fixedRuntime['stderr']);
})->tag('core','release','preflight','database','identity','security')->group('framework-coverage');

test('application release preflight fails closed for every non-regular PostgreSQL migration pair', static function(Context $t): void {
	$healthy=static fn(): array=>[
		'ok'=>true,
		'code'=>'healthy',
		'attempts'=>1,
		'http_status'=>200,
		'response_contract_valid'=>true,
		'missing_environment_keys'=>[],
	];
	$assertInvalid=static function(TempWorkspace $workspace, string $case) use ($t, $healthy): void {
		$migrationCalls=0;
		$healthCalls=0;
		$run=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
			'migration_runner'=>static function() use (&$migrationCalls): array {
				$migrationCalls++;
				return [
					'exit_code'=>0,
					'stdout'=>json_encode(['ok'=>true], JSON_THROW_ON_ERROR),
					'stderr'=>'',
				];
			},
			'health_runner'=>static function() use (&$healthCalls, $healthy): array {
				$healthCalls++;
				return $healthy();
			},
		]);
		$t->same(ApplicationReleasePreflightCommand::EXIT_CONFIGURATION, $run['status'], $case.' exit');
		$t->same(false, $run['payload']['likely_to_deploy'], $case.' verdict');
		$t->same('migration_configuration_incomplete', $run['payload']['failures'][0]['code'], $case.' failure');
		$t->same(0, $migrationCalls, $case.' migration calls');
		$t->same(0, $healthCalls, $case.' health calls');
	};

	$profileOnly=dp_application_preflight_fixture($t, 'migration-profile-only');
	$profileOnly->file('database/postgresql/profile.json', "{}\n");
	$assertInvalid($profileOnly, 'profile only');

	$manifestBodies=[
		'invalid JSON'=>'{"mode":"shadow_schema_only"',
		'empty object'=>"{}\n",
		'missing mode'=>'{"engine":"postgresql"}',
		'non-string mode'=>'{"mode":false}',
		'unknown mode'=>'{"mode":"unknown"}',
		'shadow-only mode'=>'{"mode":"shadow_schema_only"}',
		'duplicate modes'=>'{"mode":"shadow_schema_only","mode":"deployable"}',
	];
	foreach($manifestBodies as $case=>$body){
		$manifestOnly=dp_application_preflight_fixture($t, 'migration-manifest-'.str_replace(' ', '-', $case));
		$manifestOnly->file('database/postgresql/manifest.json', $body);
		$assertInvalid($manifestOnly, 'manifest only '.$case);
	}

	$linked=dp_application_preflight_fixture($t, 'migration-linked-pair');
	$linked->directory('database/postgresql');
	$profileTarget=$linked->file('targets/profile.json', "{}\n");
	$manifestTarget=$linked->file('targets/manifest.json', "{}\n");
	$t->same(true, symlink($profileTarget, $linked->path('database/postgresql/profile.json')));
	$t->same(true, symlink($manifestTarget, $linked->path('database/postgresql/manifest.json')));
	$assertInvalid($linked, 'linked pair');

	$broken=dp_application_preflight_fixture($t, 'migration-broken-linked-pair');
	$broken->directory('database/postgresql');
	$t->same(true, symlink($broken->path('missing-profile'), $broken->path('database/postgresql/profile.json')));
	$t->same(true, symlink($broken->path('missing-manifest'), $broken->path('database/postgresql/manifest.json')));
	$assertInvalid($broken, 'broken linked pair');

	$linkedParent=dp_application_preflight_fixture($t, 'migration-linked-parent');
	$parentTarget=$linkedParent->directory('migration-target/postgresql');
	$linkedParent->file('migration-target/postgresql/profile.json', "{}\n");
	$linkedParent->file('migration-target/postgresql/manifest.json', "{}\n");
	$t->same(true, symlink(dirname($parentTarget), $linkedParent->path('database')));
	$assertInvalid($linkedParent, 'linked database directory');

	$directoryEntries=dp_application_preflight_fixture($t, 'migration-directory-entries');
	$directoryEntries->directory('database/postgresql/profile.json');
	$directoryEntries->directory('database/postgresql/manifest.json');
	$assertInvalid($directoryEntries, 'directory entries');

	if(function_exists('posix_mkfifo')){
		$fifoEntries=dp_application_preflight_fixture($t, 'migration-fifo-entries');
		$fifoEntries->directory('database/postgresql');
		$fifoProfile=$fifoEntries->path('database/postgresql/profile.json');
		$fifoManifest=$fifoEntries->path('database/postgresql/manifest.json');
		$t->same(true, posix_mkfifo($fifoProfile, 0600));
		$t->same(true, posix_mkfifo($fifoManifest, 0600));
		$assertInvalid($fifoEntries, 'FIFO entries');
		$t->same(true, unlink($fifoProfile));
		$t->same(true, unlink($fifoManifest));
	}

	if(DIRECTORY_SEPARATOR!=='\\'){
		$unreadable=dp_application_preflight_fixture($t, 'migration-unreadable-pair');
		$unreadableProfile=$unreadable->file('database/postgresql/profile.json', "{}\n");
		$unreadableManifest=$unreadable->file('database/postgresql/manifest.json', "{}\n");
		$t->same(true, chmod($unreadableProfile, 0000));
		$t->same(true, chmod($unreadableManifest, 0000));
		clearstatcache(true, $unreadableProfile);
		clearstatcache(true, $unreadableManifest);
		$assertInvalid($unreadable, 'unreadable pair');
		chmod($unreadableProfile, 0600);
		chmod($unreadableManifest, 0600);
	}

	$outside=dp_application_preflight_fixture($t, 'migration-offline-material-outside-reserved-tree');
	$outside->file('research/postgresql/profile.json', "{}\n");
	$outside->file('research/postgresql/manifest.json', "{}\n");
	$outsideMigrationCalls=0;
	$outsideRun=dp_application_preflight_run(dp_application_preflight_arguments($outside), [
		'migration_runner'=>static function() use (&$outsideMigrationCalls): array {
			$outsideMigrationCalls++;
			return ['exit_code'=>0, 'stdout'=>'', 'stderr'=>''];
		},
		'health_runner'=>$healthy,
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_SUCCESS, $outsideRun['status']);
	$t->same(0, $outsideMigrationCalls);
	$t->same('not_applicable', $outsideRun['payload']['checks'][1]['status']);

	$deployable=dp_application_preflight_fixture($t, 'migration-regular-pair');
	$deployable->file('database/postgresql/profile.json', "{}\n");
	$deployable->file('database/postgresql/manifest.json', "{}\n");
	$deployableMigrationCalls=0;
	$deployableRun=dp_application_preflight_run(dp_application_preflight_arguments($deployable), [
		'migration_runner'=>static function() use (&$deployableMigrationCalls): array {
			$deployableMigrationCalls++;
			return [
				'exit_code'=>0,
				'stdout'=>json_encode([
					'contract'=>'dataphyre.postgresql_migration_command.v1',
					'ok'=>true,
					'manifest'=>[
						'algorithm'=>'sha256',
						'bootstrap_cutoff'=>'001_base',
						'migration_count'=>1,
						'schema_version'=>3,
						'sha256'=>str_repeat('a', 64),
					],
					'result'=>[
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
							],
						],
					],
				], JSON_THROW_ON_ERROR),
				'stderr'=>'',
			];
		},
		'health_runner'=>$healthy,
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_SUCCESS, $deployableRun['status']);
	$t->same(1, $deployableMigrationCalls);
	$t->same('passed', $deployableRun['payload']['checks'][1]['status']);
})->tag('core','release','preflight','migration','filesystem','security')->group('framework-coverage');

test('application release preflight applies SQLite only inside one isolated data root before health and removes it', static function(Context $t): void {
	$workspace=dp_application_preflight_fixture($t, 'sqlite-isolated-release');
	$workspace->file('database/sqlite/profile.json', "{}\n");
	$workspace->file('database/sqlite/manifest.json', "{}\n");
	$sqliteRoot=null;
	$healthRoot=null;
	$realtimeRoot=null;
	$run=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
		'sqlite_migration_runner'=>static function(
			string $applicationRoot,
			string $application,
			string $environment,
			int $timeout,
			?string $applicationDataRoot
		) use (&$sqliteRoot, $workspace): array {
			if($applicationRoot!==$workspace->root() || $application!=='fixture'
				|| $environment!=='staging' || $timeout!==180000
				|| !is_string($applicationDataRoot) || !is_dir($applicationDataRoot)){
				throw new RuntimeException('SQLite migration invocation was not fixed.');
			}
			$sqliteRoot=$applicationDataRoot;
			file_put_contents($applicationDataRoot.'/tenant.sqlite', 'isolated-candidate-data');
			return [
				'exit_code'=>0,
				'stdout'=>json_encode([
					'contract'=>'dataphyre.sqlite_migration_command.v1',
					'ok'=>true,
					'manifest'=>[
						'algorithm'=>'sha256',
						'migration_count'=>1,
						'sha256'=>str_repeat('a',64),
					],
					'result'=>[
						'applied_migrations'=>['001_initial'],
						'database_file'=>'tenant.sqlite',
						'dry_run'=>false,
						'pending_migrations'=>[],
					],
				],JSON_THROW_ON_ERROR),
				'stderr'=>'',
			];
		},
		'health_runner'=>static function(
			string $projectRoot,
			string $application,
			string $environment,
			string $path,
			int $timeout,
			?string $applicationDataRoot
		) use (&$healthRoot): array {
			$healthRoot=$applicationDataRoot;
			if(!is_string($applicationDataRoot)
				|| file_get_contents($applicationDataRoot.'/tenant.sqlite')!=='isolated-candidate-data'){
				throw new RuntimeException('Health did not receive the migrated SQLite root.');
			}
			return ['ok'=>true,'code'=>'healthy','attempts'=>1,'http_status'=>200,'response_contract_valid'=>true,'missing_environment_keys'=>[]];
		},
		'realtime_runner'=>static function(
			string $projectRoot,
			string $application,
			string $environment,
			int $timeout,
			?string $applicationDataRoot
		) use (&$realtimeRoot): array {
			$realtimeRoot=$applicationDataRoot;
			return [
				'exit_code'=>0,
				'stdout'=>json_encode([
					'contract'=>'dataphyre.application_realtime_registration.v1',
					'ok'=>true,
					'route_count'=>0,
					'registration_sha256'=>'sha256:'.hash('sha256','[]'),
					'registered_table_count'=>0,
					'registered_table_materialization_contract'=>'dataphyre.registered_table_materialization.v1',
					'registered_table_set_sha256'=>'sha256:'.hash('sha256','[]'),
					'scheduler_definition_count'=>0,
					'scheduler_definition_sha256'=>'sha256:'.hash('sha256','[]'),
				],JSON_THROW_ON_ERROR),
				'stderr'=>'',
			];
		},
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_SUCCESS,$run['status']);
	$t->same($sqliteRoot,$healthRoot);
	$t->same($sqliteRoot,$realtimeRoot);
	$t->isTrue(is_string($sqliteRoot) && !file_exists($sqliteRoot));
	$t->same([
		'contract'=>'dataphyre.sqlite_migration_command.v1',
		'declared'=>true,
		'dry_run'=>false,
		'engine'=>'sqlite',
		'manifest'=>[
			'algorithm'=>'sha256',
			'migration_count'=>1,
			'sha256'=>str_repeat('a',64),
		],
		'result'=>[
			'applied_migrations'=>['001_initial'],
			'database_file'=>'tenant.sqlite',
			'pending_migrations'=>[],
		],
		'write_scope'=>'isolated_application_data',
	],$run['payload']['checks'][1]['evidence']);

	$ambiguous=dp_application_preflight_fixture($t, 'ambiguous-migrations');
	foreach(['postgresql','sqlite'] as $engine){
		$ambiguous->file('database/'.$engine.'/profile.json', "{}\n");
		$ambiguous->file('database/'.$engine.'/manifest.json', "{}\n");
	}
	$invalid=dp_application_preflight_run(dp_application_preflight_arguments($ambiguous), [
		'health_runner'=>static fn()=>throw new RuntimeException('ambiguous migration configuration must not boot'),
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_CONFIGURATION,$invalid['status']);
	$t->same('migration_configuration_incomplete',$invalid['payload']['failures'][0]['code']);

	$throwing=dp_application_preflight_fixture($t, 'sqlite-runner-exception');
	$throwing->file('database/sqlite/profile.json', "{}\n");
	$throwing->file('database/sqlite/manifest.json', "{}\n");
	$throwingRoot=null;
	$failed=dp_application_preflight_run(dp_application_preflight_arguments($throwing), [
		'sqlite_migration_runner'=>static function(
			string $applicationRoot,
			string $application,
			string $environment,
			int $timeout,
			?string $applicationDataRoot
		) use (&$throwingRoot): never {
			$throwingRoot=$applicationDataRoot;
			throw new RuntimeException('runner detail must not escape');
		},
		'health_runner'=>static fn()=>throw new RuntimeException('health must not run after migration failure'),
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_DEPENDENCY,$failed['status']);
	$t->same('migration_preflight_failed',$failed['payload']['failures'][0]['code']);
	$t->isTrue(is_string($throwingRoot) && !file_exists($throwingRoot));
	$t->isFalse(str_contains(json_encode($failed['payload'],JSON_THROW_ON_ERROR),'runner detail'));
})->tag('core','release','preflight','sqlite','migration','isolation')->group('framework-coverage');

test('application release preflight preserves only bounded safe missing environment key names from health', static function(Context $t): void {
	$workspace=dp_application_preflight_fixture($t, 'missing-environment-keys');
	$run=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
		'health_runner'=>static fn(): array=>[
			'ok'=>false,
			'code'=>'application_health_rejected',
			'attempts'=>3,
			'http_status'=>503,
			'response_contract_valid'=>true,
			'missing_environment_keys'=>['SERVE_STAFF_SESSION_SECRET', 'SERVE_SIGNING_KEY'],
			'response_body'=>'SECRET_VALUE_MUST_NOT_LEAK',
		],
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_HEALTH, $run['status']);
	$t->same(false, $run['payload']['likely_to_deploy']);
	$t->same('failed', $run['payload']['checks'][3]['status']);
	$t->same([
		'SERVE_SIGNING_KEY',
		'SERVE_STAFF_SESSION_SECRET',
	], $run['payload']['checks'][3]['evidence']['missing_environment_keys']);
	$t->same(true, $run['payload']['checks'][3]['evidence']['response_contract_valid']);
	$t->isFalse(str_contains($run['output'], 'SECRET_VALUE_MUST_NOT_LEAK'));
	$twoHundredMissing=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
		'health_runner'=>static fn(): array=>[
			'ok'=>true,
			'code'=>'healthy',
			'attempts'=>1,
			'http_status'=>200,
			'response_contract_valid'=>true,
			'missing_environment_keys'=>['SERVE_SIGNING_KEY'],
		],
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_HEALTH, $twoHundredMissing['status']);
	$t->same(false, $twoHundredMissing['payload']['likely_to_deploy']);
	$t->same('application_environment_keys_missing', $twoHundredMissing['payload']['failures'][0]['code']);
	$t->same(['SERVE_SIGNING_KEY'], $twoHundredMissing['payload']['checks'][3]['evidence']['missing_environment_keys']);

	$validBody=json_encode([
		'ok'=>false,
		'missing_environment_keys'=>['SERVE_STAFF_SESSION_SECRET', 'SERVE_SIGNING_KEY'],
		'private_detail'=>'PRIVATE_HEALTH_DETAIL_MUST_NOT_LEAK',
	], JSON_THROW_ON_ERROR);
	$valid=dp_application_preflight_read_health_response(
		$t,
		"HTTP/1.1 503 Service Unavailable\r\nContent-Type: application/json\r\n\r\n".$validBody
	);
	$t->same(503, $valid['http_status'] ?? null);
	$t->same(true, $valid['response_contract_valid'] ?? null);
	$t->same([
		'SERVE_SIGNING_KEY',
		'SERVE_STAFF_SESSION_SECRET',
	], $valid['missing_environment_keys'] ?? null);
	$t->isFalse(str_contains(json_encode($valid, JSON_THROW_ON_ERROR), 'PRIVATE_HEALTH_DETAIL_MUST_NOT_LEAK'));

	$tooMany=[];
	for($index=0;$index<65;$index++) $tooMany[]=sprintf('SERVE_KEY_%02d', $index);
	$unsafeBodies=[
		'malformed'=>'{"missing_environment_keys":["SERVE_SIGNING_KEY"]',
		'incomplete'=>json_encode(['ok'=>true], JSON_THROW_ON_ERROR),
		'oversized'=>json_encode([
			'missing_environment_keys'=>['SERVE_SIGNING_KEY'],
			'padding'=>str_repeat('x', 65536),
		], JSON_THROW_ON_ERROR),
		'too_many'=>json_encode(['missing_environment_keys'=>$tooMany], JSON_THROW_ON_ERROR),
		'duplicate'=>json_encode(['missing_environment_keys'=>['SERVE_SIGNING_KEY', 'SERVE_SIGNING_KEY']], JSON_THROW_ON_ERROR),
		'object_instead_of_list'=>'{"missing_environment_keys":{}}',
		'value_bearing'=>json_encode(['missing_environment_keys'=>[['name'=>'SERVE_SIGNING_KEY', 'value'=>'secret']]], JSON_THROW_ON_ERROR),
		'value_in_name'=>json_encode(['missing_environment_keys'=>['SERVE_SIGNING_KEY=secret']], JSON_THROW_ON_ERROR),
		'invalid_name'=>json_encode(['missing_environment_keys'=>['serve_signing_key']], JSON_THROW_ON_ERROR),
		'overlong_name'=>json_encode(['missing_environment_keys'=>['S'.str_repeat('X', 120)]], JSON_THROW_ON_ERROR),
	];
	foreach($unsafeBodies as $case=>$body){
		$parsed=dp_application_preflight_read_health_response(
			$t,
			"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n".$body
		);
		$t->same(200, $parsed['http_status'] ?? null, $case.' status');
		$t->same(false, $parsed['response_contract_valid'] ?? null, $case.' contract');
		$t->same([], $parsed['missing_environment_keys'] ?? null, $case.' keys');
		$failed=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
			'health_runner'=>static fn(): array=>[
				...$parsed,
				'ok'=>true,
				'code'=>'healthy',
				'attempts'=>1,
			],
		]);
		$t->same(ApplicationReleasePreflightCommand::EXIT_HEALTH, $failed['status'], $case.' exit');
		$t->same(false, $failed['payload']['likely_to_deploy'], $case.' verdict');
		$t->same('application_health_evidence_invalid', $failed['payload']['failures'][0]['code'], $case.' failure');
		$t->same(false, $failed['payload']['checks'][3]['evidence']['response_contract_valid'], $case.' evidence');
		$t->same([], $failed['payload']['checks'][3]['evidence']['missing_environment_keys'], $case.' public keys');
		$t->isFalse(str_contains($failed['output'], 'secret'), $case.' value secrecy');
	}
	$oversizedHeader=dp_application_preflight_read_health_response(
		$t,
		"HTTP/1.1 200 OK\r\nX-Oversized: ".str_repeat('x', 17000)."\r\n\r\n".
		json_encode(['missing_environment_keys'=>[]], JSON_THROW_ON_ERROR)
	);
	$t->same(200, $oversizedHeader['http_status'] ?? null);
	$t->same(false, $oversizedHeader['response_contract_valid'] ?? null);
	$t->same([], $oversizedHeader['missing_environment_keys'] ?? null);
	$oversizedHeaderFailure=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
		'health_runner'=>static fn(): array=>[
			...$oversizedHeader,
			'ok'=>true,
			'code'=>'healthy',
			'attempts'=>1,
		],
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_HEALTH, $oversizedHeaderFailure['status']);
	$t->same(false, $oversizedHeaderFailure['payload']['likely_to_deploy']);
	$t->same('application_health_evidence_invalid', $oversizedHeaderFailure['payload']['failures'][0]['code']);
	$t->same(false, $oversizedHeaderFailure['payload']['checks'][3]['evidence']['response_contract_valid']);
	$t->same([], $oversizedHeaderFailure['payload']['checks'][3]['evidence']['missing_environment_keys']);
	$invalidStatus=dp_application_preflight_read_health_response(
		$t,
		"HTTP/1.1 999 Invalid\r\nContent-Type: application/json\r\n\r\n".
		json_encode(['missing_environment_keys'=>[]], JSON_THROW_ON_ERROR)
	);
	$t->same(null, $invalidStatus);
	$invalidStatusFailure=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
		'health_runner'=>static fn(): array=>[
			'ok'=>true,
			'code'=>'healthy',
			'attempts'=>1,
			'http_status'=>999,
			'response_contract_valid'=>true,
			'missing_environment_keys'=>[],
		],
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_HEALTH, $invalidStatusFailure['status']);
	$t->same('application_health_evidence_invalid', $invalidStatusFailure['payload']['failures'][0]['code']);
	$t->same(null, $invalidStatusFailure['payload']['checks'][3]['evidence']['http_status']);
	$t->same(false, $invalidStatusFailure['payload']['checks'][3]['evidence']['response_contract_valid']);
})->tag('core','release','preflight','health','environment','security','boundary')->group('framework-coverage');

test('application release preflight distinguishes configuration dependency and executable failures', static function(Context $t): void {
	$missing=$t->workspace('application-release-preflight-missing');
	$missingRun=dp_application_preflight_run([
		'application_release_preflight.php',
		'--project-root='.$missing->root(),
		'--application=fixture',
		'--environment=staging',
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_CONFIGURATION, $missingRun['status']);
	$t->same(false, $missingRun['payload']['likely_to_deploy']);
	$t->same('configuration', $missingRun['payload']['failures'][0]['kind']);
	$t->same('flight_sheet_missing', $missingRun['payload']['failures'][0]['code']);

	$workspace=dp_application_preflight_fixture($t, 'migration-failures');
	$workspace->file('database/postgresql/profile.json', "{}\n");
	$workspace->file('database/postgresql/manifest.json', "{}\n");
	$cases=[
		'configuration'=>[
			'exit'=>78,
			'expected_exit'=>ApplicationReleasePreflightCommand::EXIT_CONFIGURATION,
			'code'=>'database_configuration_invalid',
		],
		'dependency'=>[
			'exit'=>69,
			'expected_exit'=>ApplicationReleasePreflightCommand::EXIT_DEPENDENCY,
			'code'=>'database_connection_failed',
		],
		'verification'=>[
			'exit'=>70,
			'expected_exit'=>ApplicationReleasePreflightCommand::EXIT_VERIFICATION,
			'code'=>'migration_plan_ineligible',
		],
	];
	foreach($cases as $expectedKind=>$case){
		$run=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
			'migration_runner'=>static fn()=>[
				'exit_code'=>$case['exit'],
				'stdout'=>'',
				'stderr'=>json_encode([
					'contract'=>'dataphyre.postgresql_migration_command.v1',
					'ok'=>false,
					'error'=>['code'=>$case['code']],
					'manifest'=>$expectedKind==='verification' ? [
						'algorithm'=>'sha256',
						'bootstrap_cutoff'=>'001_base',
						'migration_count'=>154,
						'schema_version'=>3,
						'sha256'=>str_repeat('a', 64),
					] : [],
					'result'=>$expectedKind==='verification' ? [
						'pending_validation'=>[
							'mode'=>'rolling',
							'eligible'=>false,
							'errors'=>['pending_rolling_migrations_contain_incompatible_sql'],
							'pending_migrations'=>['154_unsafe'],
							'selected_migrations'=>['154_unsafe'],
							'deferred_migrations'=>[],
							'rolling_scan'=>[
								'performed'=>true,
								'migration_count'=>1,
								'issue_count'=>1,
								'issues'=>[ [
									'migration'=>'154_unsafe',
									'code'=>'create_index_requires_concurrent_autocommit_protocol',
									'statement'=>1,
								] ],
							],
						],
					] : null,
				], JSON_THROW_ON_ERROR),
			],
			'health_runner'=>static fn()=>throw new RuntimeException('Health must not run after migration failure.'),
		]);
		$t->same($case['expected_exit'], $run['status'], $expectedKind.' exit');
		$t->same(false, $run['payload']['likely_to_deploy'], $expectedKind.' verdict');
		$t->same($expectedKind, $run['payload']['failures'][0]['kind'], $expectedKind.' kind');
		$t->same($case['code'], $run['payload']['failures'][0]['code'], $expectedKind.' code');
		$t->same('failed', $run['payload']['checks'][1]['status'], $expectedKind.' check');
		if($expectedKind==='verification'){
			$t->same(false, $run['payload']['checks'][1]['evidence']['plan']['eligible']);
			$t->same(
				'create_index_requires_concurrent_autocommit_protocol',
				$run['payload']['checks'][1]['evidence']['plan']['rolling_scan']['issues'][0]['code']
			);
		}
	}
})->tag('core','release','preflight','migration','failure','contract')->group('framework-coverage');

test('application release preflight bounds output and kills a TERM-resistant process tree retaining both pipes', static function(Context $t): void {
	if(DIRECTORY_SEPARATOR==='\\' || !function_exists('pcntl_fork') || !function_exists('posix_kill')){
		$t->same(true,true);
		return;
	}
	$workspace=$t->workspace('application-release-preflight-process-tree');
	$pidFile=$workspace->path('process-tree.pids');
	$fixture=__DIR__.'/fixtures/application_release_preflight_process_tree.php';
	$started=microtime(true);
	$result=$t->nonPublic(ApplicationReleasePreflightCommand::class)->invoke(
		'runProcess',
		[PHP_BINARY,$fixture],
		$workspace->root(),
		100,
		['DATAPHYRE_TEST_PROCESS_TREE_PID_FILE'=>$pidFile],
	);
	$elapsed=microtime(true)-$started;
	$t->same(124,$result['exit_code']);
	$t->isTrue($elapsed>=0.5 && $elapsed<3.0,'TERM grace and KILL/reap remain bounded');
	$t->isTrue(strlen($result['stdout'])>0 && strlen($result['stdout'])<=262144);
	$t->isTrue(strlen($result['stderr'])>0 && strlen($result['stderr'])<=262144);
	$t->isTrue(is_file($pidFile));
	$pids=array_values(array_filter(array_map('intval',file($pidFile,FILE_IGNORE_NEW_LINES) ?: [])));
	$t->same(2,count($pids));
	$deadline=microtime(true)+1.0;
	do{
		$live=[];
		foreach($pids as $pid){
			$stat=@file_get_contents('/proc/'.$pid.'/stat');
			$separator=is_string($stat) ? strrpos($stat,') ') : false;
			$state=is_int($separator) ? ($stat[$separator+2] ?? '') : '';
			if(is_string($stat) && !in_array($state,['Z','X'],true)) $live[]=$pid;
		}
		if($live===[]) break;
		usleep(10000);
	}while(microtime(true)<$deadline);
	$t->same([],$live,'the direct process and pipe-retaining descendant are no longer runnable');
})->tag('core','release','preflight','process-group','timeout','security')->group('framework-coverage');

test('application release preflight rejects tenant commands scripts and selectable execution paths', static function(Context $t): void {
	$t->isTrue(ApplicationReleasePreflightEvidence::COMMAND_TIMEOUT_MILLISECONDS>=300000);
	$t->isTrue(ApplicationReleasePreflightEvidence::MCP_TRANSPORT_OVERHEAD_MILLISECONDS>0);
	$applicationIdentifier=$t->nonPublic(ApplicationReleasePreflightCommand::class);
	foreach(['fixture', '_fixture', 'Fixture_2', 'fixture$worker', 'fixture-app', 'fixture.app', '2fixture'] as $identifier){
		$t->same(true, $applicationIdentifier->invoke('validApplicationIdentifier', $identifier), $identifier.' public application grammar');
	}
	foreach(['-fixture', '.fixture', '$fixture', '.', '..', 'fixture/app', str_repeat('a', 129)] as $identifier){
		$t->same(false, $applicationIdentifier->invoke('validApplicationIdentifier', $identifier), $identifier.' rejected application grammar');
	}
	foreach(['staging', 'staging-blue', 'preview-pr-123', 'staging_blue', 'Staging.Blue'] as $identifier){
		$t->same(true, $applicationIdentifier->invoke('validEnvironmentIdentifier', $identifier), $identifier.' public environment grammar');
	}
	foreach(['_staging', '.', '..', 'staging$blue', "staging\nblue", "staging\0blue", str_repeat('a', 129)] as $identifier){
		$t->same(false, $applicationIdentifier->invoke('validEnvironmentIdentifier', $identifier), $identifier.' rejected environment grammar');
	}
	foreach([
		['application_release_preflight.php'],
		['application_release_preflight.php', '--script=release.sh'],
		['application_release_preflight.php', '--command=php artisan migrate'],
		['application_release_preflight.php', '--health-path=/ready'],
		['application_release_preflight.php', '--project-root=/tmp', '--project-root=/tmp'],
		['application_release_preflight.php', '--project-root=/tmp', '--application=../fixture', '--environment=local'],
	] as $arguments){
		$run=dp_application_preflight_run($arguments);
		$t->same(ApplicationReleasePreflightCommand::EXIT_USAGE, $run['status']);
		$t->same(false, $run['payload']['likely_to_deploy']);
		$t->same('invalid_invocation', $run['payload']['failures'][0]['code']);
	}

	$help=dp_application_preflight_run(['application_release_preflight.php', '--help']);
	$t->same(ApplicationReleasePreflightCommand::EXIT_SUCCESS, $help['status']);
	$t->same('help', $help['payload']['mode']);
	$t->contains('--project-root=<application-project>', $help['payload']['usage'][0]);
	$t->contains('never null', $help['payload']['json_exit_contract']['boolean_verdict']);
	$t->same('application, migration, or environment configuration invalid', $help['payload']['json_exit_contract']['exit_statuses']['78']);

	$commandSource=(string)file_get_contents(dirname(__DIR__).'/Framework/ApplicationReleasePreflightCommand.php');
	$entrypointSource=(string)file_get_contents(dirname(__DIR__).'/kernel/application_release_preflight.php');
	$routerSource=(string)file_get_contents(dirname(__DIR__).'/kernel/application_release_preflight_router.php');
	$t->contains("/sql/kernel/postgresql_migrate.php", $commandSource);
	$t->contains("'--mode=automatic'", $commandSource);
	$t->same(1, substr_count($commandSource, "'--dry-run'"));
	$t->contains('ApplicationReleasePreflightEvidence::encodeCompleted($payload)', $commandSource);
	$t->contains('boundedTimeoutMilliseconds($commandDeadline', $commandSource);
	$t->contains("path!=='/health'", $routerSource);
	$t->contains('A-Za-z0-9._-', $routerSource);
	$t->contains('A-Za-z0-9_$', $routerSource);
	foreach(['shell_exec','passthru','system(','popen','/bin/sh','release.sh'] as $forbidden){
		$t->isFalse(str_contains($commandSource, $forbidden));
		$t->isFalse(str_contains($entrypointSource, $forbidden));
		$t->isFalse(str_contains($routerSource, $forbidden));
	}
})->tag('core','release','preflight','cli','security','boundary')->group('framework-coverage');

test('application release preflight boots an existing standalone application root through the fixed loopback health route', static function(Context $t): void {
	$workspace=dp_application_preflight_fixture($t, 'standalone-live');
	$t->same($workspace->root(), app_locator::locate($workspace->root(), 'fixture'));
	$t->same(null, app_locator::locate($workspace->root(), 'different-app'));
	$t->environment([
		'DATAPHYRE_PHP_BINARY'=>$workspace->path('caller-selected-php-must-not-run'),
	]);
	$t->isFalse(is_file($workspace->path('caller-selected-php-must-not-run')));

	$run=dp_application_preflight_run(dp_application_preflight_arguments($workspace));
	$t->same(ApplicationReleasePreflightCommand::EXIT_SUCCESS, $run['status']);
	$t->same(true, $run['payload']['likely_to_deploy']);
	$t->same('standalone_application_root', $run['payload']['checks'][0]['evidence']['application_layout']);
	$t->same('passed', $run['payload']['checks'][3]['status']);
	$t->same(200, $run['payload']['checks'][3]['evidence']['http_status']);
	$t->isFalse(is_file($workspace->path('caller-selected-php-must-not-run')));
})->tag('core','release','preflight','standalone','health','integration')->group('framework-coverage');

test('application release preflight fixed command boundaries fail closed without selectable machinery', static function(Context $t): void {
	$access=$t->nonPublic(ApplicationReleasePreflightCommand::class);
	$workspace=dp_application_preflight_fixture($t,'fixed-command-boundaries');

	$invalidRuntime=dp_application_preflight_run(dp_application_preflight_arguments($workspace),['sapi'=>'fpm-fcgi']);
	$t->same(ApplicationReleasePreflightCommand::EXIT_USAGE,$invalidRuntime['status']);
	$t->same('invalid_runtime',$invalidRuntime['payload']['failures'][0]['code']);
	$missingArguments=dp_application_preflight_arguments($workspace);
	$missingArguments[1]='--project-root='.$workspace->path('missing-project');
	$missingProject=dp_application_preflight_run($missingArguments);
	$t->same(ApplicationReleasePreflightCommand::EXIT_PROJECT,$missingProject['status']);
	$t->same('project_unavailable',$missingProject['payload']['failures'][0]['code']);

	foreach([
		['application_release_preflight.php','--help','--help'],
		['application_release_preflight.php','not-an-option'],
		['application_release_preflight.php','--project-root='.$workspace->root(),'--application=fixture','--environment='],
		['application_release_preflight.php','--project-root='.$workspace->root(),'--application=fixture','--environment=.'],
	] as $arguments){
		$t->same(ApplicationReleasePreflightCommand::EXIT_USAGE,dp_application_preflight_run($arguments)['status']);
	}
	$t->throws(
		static fn()=>$access->invoke('projectRoot',$workspace->path('missing-project')),
		RuntimeException::class,
		'missing project root',
	);

	$layouts=$t->workspace('application-release-preflight-layouts');
	$firstProject=$layouts->directory('first-project');
	$layouts->file('first-project/flight_sheet.php','<?php return [];');
	$layouts->file('first-project/applications/fixture/app.php','<?php return [];');
	$t->same([
		'application_root'=>$firstProject.'/applications/fixture',
		'layout'=>'project_applications_root',
	],$access->invoke('applicationContext',$firstProject,'fixture'));
	$secondProject=$layouts->directory('second-project');
	$layouts->file('second-project/flight_sheet.php','<?php return [];');
	$layouts->file('applications/fixture/app.php','<?php return [];');
	$t->same([
		'application_root'=>$layouts->root().'/applications/fixture',
		'layout'=>'project_applications_root',
	],$access->invoke('applicationContext',$secondProject,'fixture'));
	$missingProjectRoot=$layouts->directory('missing-application-project');
	$layouts->file('missing-application-project/flight_sheet.php','<?php return [];');
	$t->throws(
		static fn()=>$access->invoke('applicationContext',$missingProjectRoot,'absent'),
		RuntimeException::class,
		'missing application',
	);
	$missingDefinitionProject=$layouts->directory('missing-definition-project');
	$layouts->file('missing-definition-project/flight_sheet.php','<?php return [];');
	$layouts->directory('missing-definition-project/applications/fixture');
	$t->throws(
		static fn()=>$access->invoke('applicationContext',$missingDefinitionProject,'fixture'),
		RuntimeException::class,
		'missing application definition',
	);

	$manifestFixtures=$t->workspace('application-release-preflight-manifest-boundaries');
	$manifestTarget=$manifestFixtures->file('target.json',"{\"name\":\"fixture\"}\n");
	$linkedManifest=$manifestFixtures->path('linked.json');
	$t->same(true,symlink($manifestTarget,$linkedManifest));
	$t->same(false,$access->invoke('directManifestMatches',$linkedManifest,'fixture'));
	$oversizedManifest=$manifestFixtures->file('oversized.json',str_repeat('x',65537));
	$t->same(false,$access->invoke('directManifestMatches',$oversizedManifest,'fixture'));
	$malformedManifest=$manifestFixtures->file('malformed.json','{"name":');
	$t->same(false,$access->invoke('directManifestMatches',$malformedManifest,'fixture'));
	$t->same(false,$access->invoke('directManifestMatches',$manifestTarget,'different'));

	$migrationTree=$t->workspace('application-release-preflight-migration-engine-link');
	$migrationTree->directory('database');
	$migrationTarget=$migrationTree->directory('migration-target');
	$t->same(true,symlink($migrationTarget,$migrationTree->path('database/postgresql')));
	$t->same(['engine'=>'none','valid'=>false],$access->invoke('migrationFiles',$migrationTree->root()));

	$stateRoot=$access->invoke('createIsolatedStateRoot');
	$t->isTrue(is_string($stateRoot) && is_dir($stateRoot));
	mkdir($stateRoot.'/nested');
	file_put_contents($stateRoot.'/nested/value.txt','value');
	symlink($workspace->root(),$stateRoot.'/outside-link');
	$access->invoke('removeIsolatedStateRoot',$stateRoot);
	$t->isFalse(file_exists($stateRoot));
	$access->invoke('removeIsolatedStateRoot',$workspace->root());
	$t->same([], $access->invoke('applicationDataEnvironment',null));
	$t->same(['DATAPHYRE_APPLICATION_DATA_ROOT'=>'/isolated'], $access->invoke('applicationDataEnvironment','/isolated'));
	$t->same(['exit_code'=>127,'stdout'=>'','stderr'=>''],$access->invoke('normalizeProcessResult',false));
	$t->same('kept',$access->invoke('appendBounded','kept',false));
	$t->same('kept',$access->invoke('appendBounded','kept',''));
	$t->same(null,$access->invoke('openOwnedProcessGroup',[],[
		0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w'],
	],$workspace->root(),null));
	$t->same(['exit_code'=>127,'stdout'=>'','stderr'=>''],$access->invoke('runProcess',[],$workspace->root(),10,[]));

	$sqliteState=$access->invoke('createIsolatedStateRoot');
	$sqliteMissingRoot=$access->invoke('runSqliteMigration',$workspace->root(),'fixture','staging',10,null);
	$t->same(ApplicationReleasePreflightCommand::EXIT_CONFIGURATION,$sqliteMissingRoot['exit_code']);
	foreach([
		$access->invoke('runMigration',$workspace->root(),'fixture','staging',250),
		$access->invoke('runSqliteMigration',$workspace->root(),'fixture','staging',250,$sqliteState),
		$access->invoke('runDatabaseRuntime',$workspace->root(),'fixture','staging',250,null),
		$access->invoke('runRealtimeRegistration',$workspace->root(),'fixture','staging',250,null),
	] as $fixedProcess){
		$t->isTrue(is_array($fixedProcess));
		$t->hasKey('exit_code',$fixedProcess);
	}
	$access->invoke('removeIsolatedStateRoot',$sqliteState);

	$emptyResponse=dp_application_preflight_read_health_response($t,'');
	$t->same(null,$emptyResponse);
	$incompleteHeaders=dp_application_preflight_read_health_response($t,"HTTP/1.1 200 OK\r\nX-Test: value");
	$t->same(false,$incompleteHeaders['response_contract_valid'] ?? null);
	$emptyBody=dp_application_preflight_read_health_response($t,"HTTP/1.1 200 OK\r\n\r\n");
	$t->same(false,$emptyBody['response_contract_valid'] ?? null);
	$oversizedBody=dp_application_preflight_read_health_response(
		$t,"HTTP/1.1 200 OK\r\n\r\n".str_repeat('x',65537),
	);
	$t->same(false,$oversizedBody['response_contract_valid'] ?? null);

	$writeCalls=0;
	$t->throws(
		static fn()=>$access->invoke('writeJson',static function()use(&$writeCalls): void{$writeCalls++;},[
			'execution'=>'not_started','padding'=>str_repeat('x',ApplicationReleasePreflightEvidence::MAX_OUTPUT_BYTES),
		]),
		LengthException::class,
		'oversized help output',
	);
	$t->same(0,$writeCalls);
})->tag('core','release','preflight','fixed-command','boundary','coverage')->group('framework-coverage');

test('application release preflight native health and stage failures retain one bounded verdict', static function(Context $t): void {
	$marker='sha256:'.str_repeat('a',64);
	$t->environment(['DATAPHYRE_DATABASE_BINDING_PRIMARY_SHA256'=>$marker]);
	$databaseFailure=dp_application_preflight_run(dp_application_preflight_arguments(
		dp_application_preflight_fixture($t,'database-runner-throws')
	),[
		'database_runtime_runner'=>static fn()=>throw new RuntimeException('private database failure'),
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_DEPENDENCY,$databaseFailure['status']);
	$t->same('application_database_identity_failed',$databaseFailure['payload']['failures'][0]['code']);
	$t->environment(['DATAPHYRE_DATABASE_BINDING_PRIMARY_SHA256'=>null]);

	$healthFailure=dp_application_preflight_run(dp_application_preflight_arguments(
		dp_application_preflight_fixture($t,'health-runner-throws')
	),[
		'health_runner'=>static fn()=>throw new RuntimeException('private health failure'),
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_HEALTH,$healthFailure['status']);
	$t->same('application_boot_failed',$healthFailure['payload']['failures'][0]['code']);
	$realtimeFailure=dp_application_preflight_run(dp_application_preflight_arguments(
		dp_application_preflight_fixture($t,'realtime-runner-throws')
	),[
		'health_runner'=>static fn(): array=>[
			'ok'=>true,'code'=>'healthy','attempts'=>1,'http_status'=>200,
			'response_contract_valid'=>true,'missing_environment_keys'=>[],
		],
		'realtime_runner'=>static fn()=>throw new RuntimeException('private realtime failure'),
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_VERIFICATION,$realtimeFailure['status']);
	$t->same('application_realtime_registration_failed',$realtimeFailure['payload']['failures'][0]['code']);

	$access=$t->nonPublic(ApplicationReleasePreflightCommand::class);
	$invalid=$dpInvalid=dp_application_preflight_fixture($t,'native-invalid-health');
	$invalid->file('framework_bootstrap.php',<<<'PHP'
<?php
header('Content-Type: application/json');
echo '{"status":"healthy"}';
PHP);
	$invalidHealth=$access->invoke('runHealth',$invalid->root(),'fixture','staging','/health',2,null);
	$t->same('application_health_evidence_invalid',$invalidHealth['code']);

	$missing=dp_application_preflight_fixture($t,'native-missing-health-keys');
	$missing->file('framework_bootstrap.php',<<<'PHP'
<?php
header('Content-Type: application/json');
echo '{"missing_environment_keys":["SERVE_SIGNING_KEY"]}';
PHP);
	$dataRoot=$access->invoke('createIsolatedStateRoot');
	$missingHealth=$access->invoke('runHealth',$missing->root(),'fixture','staging','/health',2,$dataRoot);
	$t->same('application_environment_keys_missing',$missingHealth['code']);
	$t->same(['SERVE_SIGNING_KEY'],$missingHealth['missing_environment_keys']);
	$access->invoke('removeIsolatedStateRoot',$dataRoot);

	$rejected=dp_application_preflight_fixture($t,'native-rejected-health');
	$rejected->file('framework_bootstrap.php',<<<'PHP'
<?php
http_response_code(503);
header('Content-Type: application/json');
echo '{"missing_environment_keys":[]}';
PHP);
	$rejectedHealth=$access->invoke('runHealth',$rejected->root(),'fixture','staging','/health',1,null);
	$t->same('application_health_rejected',$rejectedHealth['code']);
	$t->same(503,$rejectedHealth['http_status']);
})->tag('core','release','preflight','native-health','failure','coverage')->group('framework-coverage');

test('application release preflight evidence authority exercises every bounded semantic rejection', static function(Context $t): void {
	$access=$t->nonPublic(ApplicationReleasePreflightEvidence::class);
	$message='Application release preflight is available only through the CLI.';
	$failure=ApplicationReleasePreflightEvidence::failure(
		'fixture','staging',64,'configuration','invalid_runtime',$message,
	);
	$t->same('fixture',$failure['application']);
	$t->same('staging',$failure['environment']);
	$t->same(null,ApplicationReleasePreflightEvidence::failure(
		null,null,64,'configuration','invalid_runtime',$message,
	)['application']);
	$t->throws(
		static fn()=>ApplicationReleasePreflightEvidence::failure(null,null,64,'configuration','unknown','unknown'),
		LogicException::class,
	);
	$t->throws(
		static fn()=>ApplicationReleasePreflightEvidence::encodeCompleted(['execution'=>'completed']),
		LogicException::class,
	);

	$t->same(false,$access->invoke('release_preflight_failures_are_valid',[null],64,false));
	$t->same(true,$access->invoke('release_preflight_failure_stage_is_valid',[
		'ok'=>false,'checks'=>[],'failures'=>[['code'=>'invalid_runtime']],'exit_status'=>64,
	]));
	$t->same(true,$access->invoke('release_preflight_failure_stage_is_valid',[
		'ok'=>false,
		'checks'=>[
			['evidence'=>[]],
			['evidence'=>['error_code'=>'database_connection_failed']],
		],
		'failures'=>[['code'=>'database_connection_failed']],
		'exit_status'=>69,
	]));
	$t->same(false,$access->invoke('release_preflight_checks_are_valid',[null],[],70,false));
	$t->same(false,$access->invoke('release_preflight_realtime_check_is_valid',[
		'status'=>'invalid','evidence'=>[],
	],'application_realtime_registration_failed'));
	$t->same(false,$access->invoke('release_preflight_database_check_is_valid',[
		'status'=>'invalid','evidence'=>[],
	],'migration_preflight_failed',70));

	$postgresManifest=[
		'algorithm'=>'sha256','bootstrap_cutoff'=>'001_base','migration_count'=>1,
		'schema_version'=>3,'sha256'=>str_repeat('a',64),
	];
	$postgresPlan=[
		'mode'=>'bootstrap','eligible'=>true,'errors'=>[],
		'pending_migrations'=>['001_base'],'selected_migrations'=>['001_base'],'deferred_migrations'=>[],
		'rolling_scan'=>['performed'=>false,'migration_count'=>0,'issue_count'=>0,'issues'=>[]],
	];
	$postgresCheck=['status'=>'passed','evidence'=>[
		'declared'=>true,'dry_run'=>true,'contract'=>'dataphyre.postgresql_migration_command.v1',
		'manifest'=>[...$postgresManifest,'algorithm'=>'sha512'],'plan'=>$postgresPlan,
	]];
	$t->same(false,$access->invoke('release_preflight_database_check_is_valid',$postgresCheck,'',0));
	$postgresCheck=['status'=>'failed','evidence'=>[
		'declared'=>true,'dry_run'=>true,'contract'=>'','manifest'=>[],'plan'=>[],
		'exit_status'=>-1,'error_code'=>'migration_preflight_failed',
	]];
	$t->same(false,$access->invoke('release_preflight_database_check_is_valid',$postgresCheck,'migration_preflight_failed',69));

	$sqliteManifest=['algorithm'=>'sha256','migration_count'=>1,'sha256'=>str_repeat('a',64)];
	$sqliteResult=['applied_migrations'=>['001_base'],'database_file'=>'tenant.sqlite','pending_migrations'=>[]];
	$t->same(false,$access->invoke('release_preflight_sqlite_database_check_is_valid',[
		'status'=>'invalid','evidence'=>[],
	],'migration_preflight_failed',70));
	$t->same(false,$access->invoke('release_preflight_sqlite_database_check_is_valid',[
		'status'=>'failed','evidence'=>[
			'contract'=>'','declared'=>true,'dry_run'=>false,'engine'=>'sqlite','manifest'=>[],'result'=>[],
			'write_scope'=>'isolated_application_data','exit_status'=>-1,'error_code'=>'migration_preflight_failed',
		],
	],'migration_preflight_failed',69));
	$t->same(true,$access->invoke('release_preflight_sqlite_database_check_is_valid',[
		'status'=>'failed','evidence'=>[
			'contract'=>'','declared'=>true,'dry_run'=>false,'engine'=>'sqlite','manifest'=>[],'result'=>[],
			'write_scope'=>'isolated_application_data','exit_status'=>78,'error_code'=>'migration_preflight_failed',
		],
	],'migration_preflight_failed',78));
	$t->same(true,$access->invoke('release_preflight_sqlite_database_check_is_valid',[
		'status'=>'failed','evidence'=>[
			'contract'=>'dataphyre.sqlite_migration_command.v1','declared'=>true,'dry_run'=>false,'engine'=>'sqlite',
			'manifest'=>$sqliteManifest,'result'=>[],'write_scope'=>'isolated_application_data',
			'exit_status'=>70,'error_code'=>'migration_preflight_failed',
		],
	],'migration_preflight_failed',70));
	$t->same(false,$access->invoke('release_preflight_database_runtime_check_is_valid',[
		'status'=>'invalid','evidence'=>[],
	],''));

	$planWithError=[...$postgresPlan,'mode'=>'rolling','eligible'=>false,
		'errors'=>['pending_contract_requires_compatibility_finalization:002_future'],
		'rolling_scan'=>['performed'=>true,'migration_count'=>1,'issue_count'=>0,'issues'=>[]],
	];
	$t->same(false,$access->invoke('release_preflight_plan_fits_manifest',$postgresManifest,$planWithError));
	$planWithError['errors']=['pending_contract_requires_compatibility_finalization:001_base'];
	$planWithError['pending_migrations']=[];
	$t->same(false,$access->invoke('release_preflight_plan_fits_manifest',$postgresManifest,$planWithError));
	$t->same(true,$access->invoke('release_preflight_empty_migration_failure_is_valid',69,'','migration_preflight_failed'));
	$t->same(true,$access->invoke(
		'release_preflight_empty_migration_failure_is_valid',64,'dataphyre.postgresql_migration_command.v1','invalid_runtime',
	));

	$invalidPlan=[...$postgresPlan,'mode'=>'invalid'];
	$t->same(false,$access->invoke('release_preflight_plan_evidence_is_valid',$invalidPlan,true));
	$t->same(false,$access->invoke('release_preflight_rolling_scan_is_valid',[
		'performed'=>false,'migration_count'=>1,'issue_count'=>0,'issues'=>[],
	],'bootstrap',[]));
	$t->same(false,$access->invoke('release_preflight_rolling_scan_is_valid',[
		'performed'=>true,'migration_count'=>1,'issue_count'=>1,
		'issues'=>[['migration'=>'001_base','code'=>'invalid','statement'=>1]],
	],'rolling',['001_base']));
	$t->same(false,$access->invoke('release_preflight_rolling_scan_is_valid',[
		'performed'=>true,'migration_count'=>1,'issue_count'=>2,
		'issues'=>[
			['migration'=>'001_base','code'=>'drop_object','statement'=>2],
			['migration'=>'001_base','code'=>'drop_object','statement'=>1],
		],
	],'rolling',['001_base']));
	$t->same(true,$access->invoke('release_preflight_migration_errors_are_valid',[
		'pending_contract_requires_compatibility_finalization:001_base',
	]));

	$t->same(false,$access->invoke('release_preflight_health_check_is_valid',[
		'status'=>'invalid','evidence'=>[],
	],''));
	$healthEvidence=[
		'path'=>'/health','loopback_only'=>true,'attempts'=>0,'http_status'=>null,
		'response_contract_valid'=>false,'missing_environment_keys'=>[],
	];
	foreach(['preflight_router_missing','application_server_unavailable','application_boot_failed'] as $code){
		$t->same(true,$access->invoke('release_preflight_health_check_is_valid',[
			'status'=>'failed','evidence'=>$healthEvidence,
		],$code),$code);
	}
	foreach(['application_health_evidence_invalid','application_health_timeout'] as $code){
		$t->same(true,$access->invoke('release_preflight_health_check_is_valid',[
			'status'=>'failed','evidence'=>[...$healthEvidence,'attempts'=>1],
		],$code),$code);
	}
	$t->same(true,$access->invoke('release_preflight_health_check_is_valid',[
		'status'=>'failed','evidence'=>[...$healthEvidence,'attempts'=>1,'http_status'=>503,'response_contract_valid'=>true],
	],'application_health_rejected'));
	$t->same(false,$access->invoke('release_preflight_missing_environment_keys_are_valid','invalid'));
	$t->same(false,$access->invoke('release_preflight_missing_environment_keys_are_valid',['invalid-name']));
})->tag('core','release','preflight','evidence','semantic-boundaries','coverage')->group('framework-coverage');

test('application release preflight handles native operating-system failures as bounded evidence', static function(Context $t): void {
	require_once __DIR__.'/fixtures/application_release_preflight_function_boundaries.php';
	$boundary=\Dataphyre\Release\ApplicationReleasePreflightFunctionBoundary::class;
	$access=$t->nonPublic(ApplicationReleasePreflightCommand::class);

	$boundary::reset();
	$sqlite=dp_application_preflight_fixture($t,'native-mkdir-failure');
	$sqlite->file('database/sqlite/profile.json',"{}\n");
	$sqlite->file('database/sqlite/manifest.json',"{}\n");
	$boundary::$mode='mkdir_false';
	$stateFailure=dp_application_preflight_run(dp_application_preflight_arguments($sqlite));
	$t->same(ApplicationReleasePreflightCommand::EXIT_DEPENDENCY,$stateFailure['status']);
	$t->same('application_data_root_unavailable',$stateFailure['payload']['failures'][0]['code']);

	$boundary::reset();
	$workspace=dp_application_preflight_fixture($t,'native-function-failures');
	$boundary::$blockedSuffix='/runtime/bootstrap.php';
	$t->throws(
		static fn()=>$access->invoke('applicationContext',$workspace->root(),'fixture'),
		RuntimeException::class,
		'missing runtime bootstrap',
	);
	$boundary::reset();
	$mismatch=dp_application_preflight_fixture($t,'native-manifest-mismatch');
	$mismatch->file('dataphyre.app.json',"{\"name\":\"different\"}\n");
	$t->throws(
		static fn()=>$access->invoke('applicationContext',$mismatch->root(),'fixture'),
		RuntimeException::class,
		'mismatched application manifest',
	);

	foreach([
		['/runtime/modules/sql/kernel/postgresql_migrate.php','runMigration',[$workspace->root(),'fixture','staging',10]],
		['/runtime/modules/core/kernel/application_runtime_database_identity.php','runDatabaseRuntime',[$workspace->root(),'fixture','staging',10,null]],
		['/runtime/modules/core/kernel/application_release_preflight_realtime.php','runRealtimeRegistration',[$workspace->root(),'fixture','staging',10,null]],
	] as [$suffix,$method,$arguments]){
		$boundary::reset();
		$boundary::$blockedSuffix=$suffix;
		$result=$access->invoke($method,...$arguments);
		$t->same(ApplicationReleasePreflightCommand::EXIT_CONFIGURATION,$result['exit_code'],$method);
	}
	$boundary::reset();
	$boundary::$blockedSuffix='/runtime/modules/core/kernel/application_release_preflight_router.php';
	$t->same('preflight_router_missing',$access->invoke(
		'runHealth',$workspace->root(),'fixture','staging','/health',1,null,
	)['code']);

	$boundary::reset();
	$boundary::$mode='proc_open_false';
	$t->same('application_server_unavailable',$access->invoke(
		'runHealth',$workspace->root(),'fixture','staging','/health',1,null,
	)['code']);
	$boundary::reset();
	$boundary::$mode='exit_after_open';
	$t->same('application_boot_failed',$access->invoke(
		'runHealth',$workspace->root(),'fixture','staging','/health',1,null,
	)['code']);

	$descriptor=[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']];
	$boundary::reset();
	$boundary::$mode='invalid_first_status';
	$t->same(null,$access->invoke('openOwnedProcessGroup',[
		PHP_BINARY,'-r','usleep(1000000);',
	],$descriptor,$workspace->root(),null));
	$boundary::reset();
	$boundary::$mode='group_failure';
	$t->same(null,$access->invoke('openOwnedProcessGroup',[
		PHP_BINARY,'-r','usleep(1000000);',
	],$descriptor,$workspace->root(),null));
	$boundary::reset();
	$boundary::$mode='no_posix';
	$t->same(null,$access->invoke('setsidExecutable'));

	$boundary::reset();
	$owned=$access->invoke('openOwnedProcessGroup',[
		PHP_BINARY,'-r',
		'pcntl_async_signals(true);pcntl_signal(SIGTERM,SIG_IGN);while(true){usleep(10000);}',
	],$descriptor,$workspace->root(),null);
	$t->isTrue(is_array($owned));
	usleep(50000);
	$boundary::$mode='stop_group_mismatch';
	$t->isTrue(is_int($access->invoke(
		'stopProcess',$owned['resource'],$owned['pipes'],$owned['process_group'],
	)));

	foreach([
		'socket_server_false'=>RuntimeException::class,
		'bad_socket_name'=>RuntimeException::class,
		'out_of_range_socket_name'=>RuntimeException::class,
	] as $mode=>$exception){
		$boundary::reset();
		$boundary::$mode=$mode;
		$t->throws(static fn()=>$access->invoke('reserveLoopbackPort'),$exception,$mode);
	}
	$boundary::reset();
	$boundary::$mode='temporary_client';
	$t->same(null,$access->invoke('probeLoopback',8080,'/health'));
	$boundary::reset();
	$boundary::$mode='header_read_false';
	$stream=fopen('php://temp','w+b');
	if(!is_resource($stream)) throw new RuntimeException('Unable to create native failure response stream.');
	try{
		$incomplete=$access->invoke('readLoopbackResponse',$stream);
		$t->same(false,$incomplete['response_contract_valid'] ?? null);
	}finally{
		fclose($stream);
	}
	$boundary::reset();
})->tag('core','release','preflight','native-failure','process','coverage')->group('framework-coverage');

test('application release preflight executable delegates directly to the fixed command', static function(Context $t): void {
	$core=dirname(__DIR__);
	$frameworkRoot=dirname($core,3);
	$t->same(1,require $core.'/kernel/application_release_preflight.php');
	$result=$t->processSucceeded($t->coveredPhpFixture(
		$core.'/kernel/application_release_preflight.php',
		['--help'],
		working_directory:$frameworkRoot,
		framework_root:$frameworkRoot,
	));
	$payload=$result->json();
	$t->same(ApplicationReleasePreflightCommand::EXIT_SUCCESS,$result->exitCode());
	$t->same(ApplicationReleasePreflightCommand::CONTRACT,$payload['contract'] ?? null);
	$t->same('help',$payload['mode'] ?? null);
})->tag('core','release','preflight','entrypoint','process','coverage')->group('framework-coverage');
