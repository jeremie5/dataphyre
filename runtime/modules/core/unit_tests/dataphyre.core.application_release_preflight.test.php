<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Release\ApplicationReleasePreflightCommand;
use Dataphyre\Test\Context;
use Dataphyre\Test\TempWorkspace;
use dataphyre\app_locator;
use function Dataphyre\Test\test;

$dpApplicationPreflightCore=dirname(__DIR__);
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
	$status=ApplicationReleasePreflightCommand::main($arguments, array_replace($runtime, [
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
	$t->same('database_dry_run_and_ephemeral_application_boot', $run['payload']['write_policy']);
	$t->same([], $run['payload']['failures']);
	$t->same([
		'configuration_bootstrap',
		'database_migrations',
		'database_runtime',
		'application_health',
	], array_column($run['payload']['checks'], 'id'));
	$t->same(['passed','not_applicable','not_applicable','passed'], array_column($run['payload']['checks'], 'status'));
	$t->same([
		'connection_sha256'=>null,
		'declared'=>false,
		'purpose'=>null,
	], $run['payload']['checks'][2]['evidence']);
	$t->same('/health', $run['payload']['checks'][3]['evidence']['path']);
	$t->same(204, $run['payload']['checks'][3]['evidence']['http_status']);
	$t->same(true, $run['payload']['checks'][3]['evidence']['response_contract_valid']);
	$t->same([], $run['payload']['checks'][3]['evidence']['missing_environment_keys']);
	$t->contains('exact candidate image', $run['payload']['claim_boundary']);
	$t->isFalse(str_contains($run['output'], $workspace->root()));
})->tag('core','release','preflight','health','cli','security')->group('framework-coverage');

test('application release preflight proves the application-resolved managed primary identity without exposing connection material', static function(Context $t): void {
	$workspace=dp_application_preflight_fixture($t, 'managed-database-identity');
	$marker='sha256:'.str_repeat('a', 64);
	$connection='sha256:'.str_repeat('b', 64);
	$t->environment(['DATAPHYRE_CLOUD_DATABASE_BINDING_PRIMARY_SHA256'=>$marker]);
	$databaseCalls=0;
	$healthCalls=0;
	$run=dp_application_preflight_run(dp_application_preflight_arguments($workspace), [
		'database_runtime_runner'=>static function(
			string $projectRoot,
			string $application,
			string $environment,
			int $timeout
		) use (&$databaseCalls, $workspace, $connection): array {
			$databaseCalls++;
			if($projectRoot!==$workspace->root() || $application!=='fixture' || $environment!=='staging' || $timeout!==30000){
				throw new RuntimeException('database runtime invocation was not fixed');
			}
			return [
				'exit_code'=>0,
				'stdout'=>json_encode([
					'contract'=>'dataphyre.application_database_runtime.v1',
					'ok'=>true,
					'purpose'=>'primary',
					'connection_sha256'=>$connection,
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
				'stdout'=>json_encode(['ok'=>true], JSON_THROW_ON_ERROR),
				'stderr'=>'',
			];
		},
		'health_runner'=>$healthy,
	]);
	$t->same(ApplicationReleasePreflightCommand::EXIT_SUCCESS, $deployableRun['status']);
	$t->same(1, $deployableMigrationCalls);
	$t->same('passed', $deployableRun['payload']['checks'][1]['status']);
})->tag('core','release','preflight','migration','filesystem','security')->group('framework-coverage');

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
					'ok'=>false,
					'error'=>['code'=>$case['code']],
					'result'=>$expectedKind==='verification' ? [
						'pending_validation'=>[
							'mode'=>'rolling',
							'eligible'=>false,
							'errors'=>['pending_rolling_migrations_contain_incompatible_sql'],
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

test('application release preflight rejects tenant commands scripts and selectable execution paths', static function(Context $t): void {
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
	foreach(['_staging', '.', '..', 'staging$blue', str_repeat('a', 129)] as $identifier){
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
