<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Release;

use JsonException;
use RuntimeException;
use Throwable;

require_once __DIR__.'/../Release/ApplicationReleasePreflightEvidence.php';

/**
 * Runs the application-neutral checks that can be reproduced before release.
 *
 * The command owns every executable path. A caller selects only an application
 * project, application id, and environment. PostgreSQL migrations use the
 * fixed Dataphyre dry-run command; SQLite migrations use the fixed SQL-only
 * command against a disposable application-data root; and application boot
 * uses the fixed loopback router shipped with this class. No application
 * release script or caller-supplied command is accepted.
 */
final class ApplicationReleasePreflightCommand {
	public const CONTRACT=ApplicationReleasePreflightEvidence::CONTRACT;
	public const EXIT_SUCCESS=0;
	public const EXIT_USAGE=64;
	public const EXIT_PROJECT=66;
	public const EXIT_DEPENDENCY=69;
	public const EXIT_VERIFICATION=70;
	public const EXIT_HEALTH=75;
	public const EXIT_CONFIGURATION=78;

	private const HEALTH_PATH='/health';
	private const HEALTH_TIMEOUT_SECONDS=60;
	private const MAX_HEALTH_HEADER_BYTES=16384;
	private const MAX_HEALTH_BODY_BYTES=65536;
	private const MAX_MISSING_ENVIRONMENT_KEYS=64;
	private const MIGRATION_TIMEOUT_MILLISECONDS=180000;
	private const DATABASE_RUNTIME_TIMEOUT_MILLISECONDS=30000;
	private const REALTIME_REGISTRATION_TIMEOUT_MILLISECONDS=30000;
	private const MAX_PROCESS_OUTPUT_BYTES=262144;
	private const PROCESS_DRAIN_BYTES_PER_STREAM=65536;
	private const PROCESS_GROUP_START_TIMEOUT_SECONDS=0.25;
	private const PROCESS_TERMINATE_GRACE_SECONDS=0.5;
	private const PROCESS_KILL_REAP_SECONDS=0.5;
	private const PROCESS_POLL_MICROSECONDS=10000;
	private const PROCESS_SIGNAL_KILL=9;
	private const PROCESS_SIGNAL_TERM=15;
	private const SETSID_EXECUTABLE='/usr/bin/setsid';
	private const DATABASE_RUNTIME_MARKER='DATAPHYRE_DATABASE_BINDING_PRIMARY_SHA256';
	private const DATABASE_RUNTIME_CONTRACT='dataphyre.database_connection_probe.v1';
	private const REALTIME_REGISTRATION_CONTRACT='dataphyre.application_realtime_registration.v1';

	/**
	 * Execute through native process/stream functions or explicit test seams.
	 *
	 * @param list<string> $arguments Full PHP argument vector.
	 * @param array<string,mixed> $runtime Optional writers and fixed-stage runners.
	 */
	public static function main(array $arguments, array $runtime=[]): int {
		$commandDeadline=microtime(true)
			+(ApplicationReleasePreflightEvidence::COMMAND_TIMEOUT_MILLISECONDS/1000);
		$write=$runtime['write_out'] ?? static fn(string $value): int|false=>fwrite(STDOUT, $value);
		$sapi=(string)($runtime['sapi'] ?? PHP_SAPI);
		if(!in_array($sapi, ['cli', 'phpdbg'], true)){
			return self::emitFailure(
				$write,
				self::EXIT_USAGE,
				null,
				null,
				[],
				'configuration',
				'invalid_runtime',
				'Application release preflight is available only through the CLI.'
			);
		}

		try{
			$options=self::options($arguments);
		}catch(Throwable){
			return self::emitFailure(
				$write,
				self::EXIT_USAGE,
				null,
				null,
				[],
				'configuration',
				'invalid_invocation',
				'Use only the documented typed application release preflight options.'
			);
		}
		if($options['help']===true){
			self::writeJson($write, [
				'contract'=>self::CONTRACT,
				'contract_version'=>1,
				'exit_status'=>self::EXIT_SUCCESS,
				'ok'=>true,
				'likely_to_deploy'=>false,
				'execution'=>'not_started',
				'mode'=>'help',
				'usage'=>self::usage(),
				'json_exit_contract'=>self::jsonExitContract(),
			]);
			return self::EXIT_SUCCESS;
		}

		$application=(string)$options['application'];
		$environment=(string)$options['environment'];
		$checks=[];
		try{
			$projectRoot=self::projectRoot((string)$options['project_root']);
		}catch(Throwable){
			return self::emitFailure(
				$write,
				self::EXIT_PROJECT,
				$application,
				$environment,
				$checks,
				'configuration',
				'project_unavailable',
				'The selected application project root is unavailable.'
			);
		}

		try{
			$applicationContext=self::applicationContext($projectRoot, $application);
			$checks[]=[
				'id'=>'configuration_bootstrap',
				'status'=>'passed',
				'evidence'=>[
					'application_layout'=>$applicationContext['layout'],
					'application_definition'=>true,
					'flight_sheet'=>true,
					'runtime_bootstrap'=>true,
				],
			];
		}catch(RuntimeException $error){
			return self::emitFailure(
				$write,
				self::EXIT_CONFIGURATION,
				$application,
				$environment,
				$checks,
				'configuration',
				self::safeCode($error->getMessage(), 'application_configuration_invalid'),
				'The application bootstrap configuration is incomplete or invalid.'
			);
		}

		$migrationFiles=self::migrationFiles($applicationContext['application_root']);
		if($migrationFiles['valid']!==true){
			$checks[]=[
				'id'=>'database_migrations',
				'status'=>'failed',
				'evidence'=>['declared'=>true],
			];
			return self::emitFailure(
				$write,
				self::EXIT_CONFIGURATION,
				$application,
				$environment,
				$checks,
				'configuration',
				'migration_configuration_incomplete',
				'The application must provide exactly one complete PostgreSQL or SQLite migration profile and immutable manifest.'
			);
		}

		$applicationDataRoot=null;
		if($migrationFiles['engine']==='sqlite'){
			try{
				$applicationDataRoot=self::createIsolatedStateRoot();
			}catch(Throwable){
				$checks[]=[
					'id'=>'database_migrations',
					'status'=>'failed',
					'evidence'=>['declared'=>true],
				];
				return self::emitFailure(
					$write,
					self::EXIT_DEPENDENCY,
					$application,
					$environment,
					$checks,
					'dependency',
					'application_data_root_unavailable',
					'The isolated application data root could not be created.'
				);
			}
		}

		if($migrationFiles['engine']!=='none'){
			try{
				if($migrationFiles['engine']==='sqlite'){
					$migrationRunner=$runtime['sqlite_migration_runner'] ?? [self::class, 'runSqliteMigration'];
					$migration=$migrationRunner(
						$applicationContext['application_root'],
						$application,
						$environment,
						self::boundedTimeoutMilliseconds($commandDeadline, self::MIGRATION_TIMEOUT_MILLISECONDS),
						$applicationDataRoot
					);
				}else{
					$migrationRunner=$runtime['migration_runner'] ?? [self::class, 'runMigration'];
					$migration=$migrationRunner(
						$applicationContext['application_root'],
						$application,
						$environment,
						self::boundedTimeoutMilliseconds($commandDeadline, self::MIGRATION_TIMEOUT_MILLISECONDS)
					);
				}
			}catch(Throwable){
				$migration=['exit_code'=>self::EXIT_DEPENDENCY, 'stdout'=>'', 'stderr'=>''];
			}
			$migration=self::normalizeProcessResult($migration);
			$migrationPayload=self::decodeProcessPayload($migration);
			if($migration['exit_code']!==0 || ($migrationPayload['ok'] ?? false)!==true){
				$classification=self::migrationFailureClassification($migration['exit_code']);
				$code=self::safeCode(
					(string)($migrationPayload['error']['code'] ?? ''),
					'migration_preflight_failed'
				);
				$checks[]=[
					'id'=>'database_migrations',
					'status'=>'failed',
					'evidence'=>array_replace(self::migrationEvidence($migrationPayload, $migrationFiles['engine']), [
						'declared'=>true,
						'exit_status'=>$migration['exit_code'],
						'error_code'=>$code,
					]),
				];
				self::removeApplicationDataRoot($applicationDataRoot);
				return self::emitFailure(
					$write,
					$classification['exit_status'],
					$application,
					$environment,
					$checks,
					$classification['kind'],
					$code,
					$classification['message']
				);
			}
			$checks[]=[
				'id'=>'database_migrations',
				'status'=>'passed',
				'evidence'=>self::migrationEvidence($migrationPayload, $migrationFiles['engine']),
			];
		}else{
			$checks[]=[
				'id'=>'database_migrations',
				'status'=>'not_applicable',
				'evidence'=>[
					'declared'=>false,
					'reason'=>'no_database_migration_profile',
				],
			];
		}

		$databaseMarker=getenv(self::DATABASE_RUNTIME_MARKER);
		if(!is_string($databaseMarker) || trim($databaseMarker)===''){
			$checks[]=[
				'id'=>'database_runtime',
				'status'=>'not_applicable',
				'evidence'=>self::databaseRuntimeEvidence(),
			];
		}else{
			$databaseMarker=trim($databaseMarker);
			$databaseRunner=$runtime['database_runtime_runner'] ?? [self::class, 'runDatabaseRuntime'];
			try{
				$database=self::normalizeProcessResult($databaseRunner(
					$projectRoot,
					$application,
					$environment,
					self::boundedTimeoutMilliseconds($commandDeadline, self::DATABASE_RUNTIME_TIMEOUT_MILLISECONDS),
					$applicationDataRoot
				));
			}catch(Throwable){
				$database=['exit_code'=>self::EXIT_DEPENDENCY, 'stdout'=>'', 'stderr'=>''];
			}
			$databasePayload=self::decodeProcessPayload($database);
			$connectionSha=$databasePayload['connection_sha256'] ?? null;
			$databaseValid=preg_match('/^sha256:[0-9a-f]{64}$/D', $databaseMarker)===1
				&& $database['exit_code']===0
				&& ($databasePayload['contract'] ?? null)===self::DATABASE_RUNTIME_CONTRACT
				&& ($databasePayload['purpose'] ?? null)==='primary'
				&& ($databasePayload['binding_sha256'] ?? null)===$databaseMarker
				&& ($databasePayload['connected'] ?? null)===true
				&& ($databasePayload['identity_query'] ?? null)===true
				&& is_string($connectionSha)
				&& preg_match('/^sha256:[0-9a-f]{64}$/D', $connectionSha)===1;
			if($databaseValid!==true){
				$checks[]=[
					'id'=>'database_runtime',
					'status'=>'failed',
					'evidence'=>self::databaseRuntimeEvidence(true),
				];
				self::removeApplicationDataRoot($applicationDataRoot);
				return self::emitFailure(
					$write,
					self::EXIT_DEPENDENCY,
					$application,
					$environment,
					$checks,
					'dependency',
					'application_database_identity_failed',
					'The application-resolved managed database identity could not be verified.'
				);
			}
			$checks[]=[
				'id'=>'database_runtime',
				'status'=>'passed',
				'evidence'=>self::databaseRuntimeEvidence(true, $connectionSha),
			];
		}

		$healthRunner=$runtime['health_runner'] ?? [self::class, 'runHealth'];
		try{
			$health=$healthRunner(
				$projectRoot,
				$application,
				$environment,
				self::HEALTH_PATH,
				self::boundedTimeoutSeconds($commandDeadline, self::HEALTH_TIMEOUT_SECONDS),
				$applicationDataRoot
			);
		}catch(Throwable){
			$health=[
				'ok'=>false,
				'code'=>'application_boot_failed',
				'attempts'=>0,
				'http_status'=>null,
			];
		}
		$health=is_array($health) ? $health : [];
		$healthEvidence=self::healthEvidence($health);
		if($healthEvidence['missing_environment_keys']!==[]){
			$health['ok']=false;
			$health['code']='application_environment_keys_missing';
		}elseif(($health['ok'] ?? false)===true && $healthEvidence['response_contract_valid']!==true){
			$health['ok']=false;
			$health['code']='application_health_evidence_invalid';
		}
		if(($health['ok'] ?? false)!==true){
			$code=self::safeCode((string)($health['code'] ?? ''), 'application_health_failed');
			$checks[]=[
				'id'=>'application_health',
				'status'=>'failed',
				'evidence'=>$healthEvidence,
			];
			self::removeApplicationDataRoot($applicationDataRoot);
			return self::emitFailure(
				$write,
				self::EXIT_HEALTH,
				$application,
				$environment,
				$checks,
				'verification',
				$code,
				'The application did not become healthy through the fixed loopback probe.'
			);
		}
		$checks[]=[
			'id'=>'application_health',
			'status'=>'passed',
			'evidence'=>$healthEvidence,
		];

		$realtimeRunner=$runtime['realtime_runner'] ?? [self::class, 'runRealtimeRegistration'];
		try{
			$realtime=self::normalizeProcessResult($realtimeRunner(
				$projectRoot,
				$application,
				$environment,
				self::boundedTimeoutMilliseconds($commandDeadline, self::REALTIME_REGISTRATION_TIMEOUT_MILLISECONDS),
				$applicationDataRoot
			));
		}catch(Throwable){
			$realtime=['exit_code'=>self::EXIT_VERIFICATION, 'stdout'=>'', 'stderr'=>''];
		}
		$realtimePayload=self::decodeProcessPayload($realtime);
		$routeCount=$realtimePayload['route_count'] ?? null;
		$registrationSha=$realtimePayload['registration_sha256'] ?? null;
		$schedulerDefinitionCount=$realtimePayload['scheduler_definition_count'] ?? null;
		$schedulerDefinitionSha=$realtimePayload['scheduler_definition_sha256'] ?? null;
		$registeredTableCount=$realtimePayload['registered_table_count'] ?? null;
		$registeredTableContract=$realtimePayload['registered_table_materialization_contract'] ?? null;
		$registeredTableSha=$realtimePayload['registered_table_set_sha256'] ?? null;
		$realtimeValid=$realtime['exit_code']===0
			&& ($realtimePayload['ok'] ?? null)===true
			&& ($realtimePayload['contract'] ?? null)===self::REALTIME_REGISTRATION_CONTRACT
			&& is_int($routeCount) && $routeCount>=0 && $routeCount<=128
			&& is_string($registrationSha)
			&& preg_match('/^sha256:[0-9a-f]{64}$/D',$registrationSha)===1
			&& is_int($schedulerDefinitionCount) && $schedulerDefinitionCount>=0 && $schedulerDefinitionCount<=256
			&& is_string($schedulerDefinitionSha)
			&& preg_match('/^sha256:[0-9a-f]{64}$/D',$schedulerDefinitionSha)===1
			&& is_int($registeredTableCount) && $registeredTableCount>=0 && $registeredTableCount<=1024
			&& $registeredTableContract==='dataphyre.registered_table_materialization.v1'
			&& is_string($registeredTableSha)
			&& preg_match('/^sha256:[0-9a-f]{64}$/D',$registeredTableSha)===1;
		$realtimeEvidence=self::realtimeEvidence(
			$realtimeValid ? $routeCount : 0,
			$realtimeValid ? $registrationSha : null,
			$realtimeValid ? $schedulerDefinitionCount : 0,
			$realtimeValid ? $schedulerDefinitionSha : null,
			$realtimeValid ? $registeredTableCount : 0,
			$realtimeValid ? $registeredTableSha : null,
		);
		if(!$realtimeValid){
			$checks[]=[
				'id'=>'realtime_registration',
				'status'=>'failed',
				'evidence'=>$realtimeEvidence,
			];
			self::removeApplicationDataRoot($applicationDataRoot);
			return self::emitFailure(
				$write,
				self::EXIT_VERIFICATION,
				$application,
				$environment,
				$checks,
				'verification',
				'application_realtime_registration_failed',
				'The application realtime callbacks, scheduler definitions, or registered table definitions did not load through the fixed framework bootstrap.'
			);
		}
		$checks[]=[
			'id'=>'realtime_registration',
			'status'=>'passed',
			'evidence'=>$realtimeEvidence,
		];

		self::removeApplicationDataRoot($applicationDataRoot);
		self::writeJson($write, self::resultEnvelope(
			self::EXIT_SUCCESS,
			true,
			$application,
			$environment,
			$checks,
			[]
		));
		return self::EXIT_SUCCESS;
	}

	/** @param list<string> $arguments @return array<string,mixed> */
	private static function options(array $arguments): array {
		$options=[
			'project_root'=>null,
			'application'=>null,
			'environment'=>null,
			'help'=>false,
		];
		$names=[
			'project-root'=>'project_root',
			'application'=>'application',
			'environment'=>'environment',
		];
		$seen=[];
		foreach(array_slice($arguments, 1) as $argument){
			$argument=(string)$argument;
			if($argument==='--help' || $argument==='-h'){
				if(isset($seen['help'])){
					throw new RuntimeException('Duplicate help option.');
				}
				$seen['help']=true;
				$options['help']=true;
				continue;
			}
			if(preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/D', $argument, $match)!==1){
				throw new RuntimeException('Arguments must use --name=value.');
			}
			$name=$match[1];
			if(!isset($names[$name]) || isset($seen[$name])){
				throw new RuntimeException('Unknown or duplicate option.');
			}
			$value=trim($match[2]);
			if($value==='' || strlen($value)>4096 || preg_match('/[\x00-\x1f\x7f]/', $value)===1){
				throw new RuntimeException('Option value is invalid.');
			}
			$seen[$name]=true;
			$options[$names[$name]]=$value;
		}
		if($options['help']===true){
			return $options;
		}
		foreach(['project_root','application','environment'] as $required){
			if(!is_string($options[$required]) || $options[$required]===''){
				throw new RuntimeException('Required option is missing.');
			}
		}
		if(!self::validApplicationIdentifier($options['application'])){
			throw new RuntimeException('Application id is invalid.');
		}
		if(!self::validEnvironmentIdentifier($options['environment'])){
			throw new RuntimeException('Environment is invalid.');
		}
		return $options;
	}

	private static function validApplicationIdentifier(string $value): bool {
		return ApplicationReleasePreflightEvidence::applicationIdentifier($value);
	}

	private static function validEnvironmentIdentifier(string $value): bool {
		return ApplicationReleasePreflightEvidence::environmentIdentifier($value);
	}

	private static function boundedTimeoutMilliseconds(float $deadline, int $stageMaximum): int {
		$remaining=(int)floor(($deadline-microtime(true))*1000);
		return max(1, min($stageMaximum, $remaining));
	}

	private static function boundedTimeoutSeconds(float $deadline, int $stageMaximum): int {
		$remaining=(int)floor($deadline-microtime(true));
		return max(1, min($stageMaximum, $remaining));
	}

	private static function projectRoot(string $path): string {
		$resolved=realpath($path);
		if($resolved===false || !is_dir($resolved) || !is_readable($resolved)){
			throw new RuntimeException('Project root is unavailable.');
		}
		return rtrim(str_replace('\\', '/', $resolved), '/');
	}

	/** @return array{application_root:string,layout:string} */
	private static function applicationContext(string $projectRoot, string $application): array {
		$flightSheet=$projectRoot.'/flight_sheet.php';
		if(is_link($flightSheet) || !is_file($flightSheet) || !is_readable($flightSheet)){
			throw new RuntimeException('flight_sheet_missing');
		}
		$runtimeBootstrap=dirname(__DIR__, 3).'/bootstrap.php';
		if(is_link($runtimeBootstrap) || !is_file($runtimeBootstrap) || !is_readable($runtimeBootstrap)){
			throw new RuntimeException('runtime_bootstrap_missing');
		}

		$directManifest=$projectRoot.'/dataphyre.app.json';
		if(is_file($directManifest) || is_link($directManifest)){
			if(!self::directManifestMatches($directManifest, $application)){
				throw new RuntimeException('application_manifest_mismatch');
			}
			self::requireApplicationDefinition($projectRoot);
			return [
				'application_root'=>$projectRoot,
				'layout'=>'standalone_application_root',
			];
		}

		foreach([
			$projectRoot.'/applications/'.$application,
			dirname($projectRoot).'/applications/'.$application,
		] as $candidate){
			$resolved=realpath($candidate);
			if($resolved===false || !is_dir($resolved)){
				continue;
			}
			$resolved=rtrim(str_replace('\\', '/', $resolved), '/');
			self::requireApplicationDefinition($resolved);
			return [
				'application_root'=>$resolved,
				'layout'=>'project_applications_root',
			];
		}
		throw new RuntimeException('application_definition_missing');
	}

	private static function requireApplicationDefinition(string $applicationRoot): void {
		$definition=$applicationRoot.'/app.php';
		if(is_link($definition) || !is_file($definition) || !is_readable($definition)){
			throw new RuntimeException('application_definition_missing');
		}
	}

	private static function directManifestMatches(string $path, string $application): bool {
		if(is_link($path) || !is_file($path) || !is_readable($path)){
			return false;
		}
		$bytes=file_get_contents($path, false, null, 0, 65537);
		if(!is_string($bytes) || strlen($bytes)>65536){
			return false;
		}
		try{
			$decoded=json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
		}catch(JsonException){
			return false;
		}
		return is_array($decoded)
			&& !array_is_list($decoded)
			&& is_string($decoded['name'] ?? null)
			&& hash_equals($application, $decoded['name']);
	}

	/** @return array{engine:string,valid:bool} */
	private static function migrationFiles(string $applicationRoot): array {
		$databaseRoot=$applicationRoot.'/database';
		if(!self::migrationDirectoryIsUsable($databaseRoot)){
			return ['engine'=>'none', 'valid'=>false];
		}
		$declared=[];
		foreach(['postgresql','sqlite'] as $engine){
			$root=$databaseRoot.'/'.$engine;
			if(!self::migrationDirectoryIsUsable($root)){
				return ['engine'=>'none', 'valid'=>false];
			}
			$profile=self::migrationFileState($root.'/profile.json');
			$manifest=self::migrationFileState($root.'/manifest.json');
			if($profile==='invalid' || $manifest==='invalid' || ($profile==='regular')!==($manifest==='regular')){
				return ['engine'=>'none', 'valid'=>false];
			}
			if($profile==='regular') $declared[]=$engine;
		}
		return count($declared)<=1
			? ['engine'=>$declared[0] ?? 'none', 'valid'=>true]
			: ['engine'=>'none', 'valid'=>false];
	}

	private static function migrationDirectoryIsUsable(string $path): bool {
		if(is_link($path)){
			return false;
		}
		if(!file_exists($path)){
			return true;
		}
		return is_dir($path) && is_readable($path);
	}

	private static function migrationFileState(string $path): string {
		if(is_link($path)){
			return 'invalid';
		}
		if(!file_exists($path)){
			return 'absent';
		}
		$permissions=fileperms($path);
		return is_file($path)
			&& is_readable($path)
			&& is_int($permissions)
			&& ($permissions & 0444)!==0
			? 'regular'
			: 'invalid';
	}

	/** @return array<string,mixed> */
	private static function runMigration(
		string $applicationRoot,
		string $application,
		string $environment,
		int $timeoutMilliseconds
	): array {
		$command=dirname(__DIR__, 2).'/sql/kernel/postgresql_migrate.php';
		if(!is_file($command)){
			return ['exit_code'=>self::EXIT_CONFIGURATION, 'stdout'=>'', 'stderr'=>''];
		}
		return self::runProcess([
			self::phpBinary(),
			$command,
			'--project-root='.$applicationRoot,
			'--app='.$application,
			'--environment='.$environment,
			'--mode=automatic',
			'--dry-run',
		], $applicationRoot, $timeoutMilliseconds);
	}

	/** @return array<string,mixed> */
	private static function runSqliteMigration(
		string $applicationRoot,
		string $application,
		string $environment,
		int $timeoutMilliseconds,
		?string $applicationDataRoot
	): array {
		$command=dirname(__DIR__, 2).'/sql/kernel/sqlite_migrate.php';
		if(!is_file($command) || !is_string($applicationDataRoot) || $applicationDataRoot===''){
			return ['exit_code'=>self::EXIT_CONFIGURATION, 'stdout'=>'', 'stderr'=>''];
		}
		return self::runProcess([
			self::phpBinary(),
			$command,
			'--project-root='.$applicationRoot,
			'--app='.$application,
			'--environment='.$environment,
		], $applicationRoot, $timeoutMilliseconds, [
			'DATAPHYRE_APPLICATION_DATA_ROOT'=>$applicationDataRoot,
			'DATAPHYRE_ENVIRONMENT'=>$environment,
		]);
	}

	/** @return array<string,mixed> */
	private static function runDatabaseRuntime(
		string $projectRoot,
		string $application,
		string $environment,
		int $timeoutMilliseconds,
		?string $applicationDataRoot=null
	): array {
		$command=dirname(__DIR__).'/kernel/application_runtime_database_identity.php';
		if(!is_file($command)){
			return ['exit_code'=>self::EXIT_CONFIGURATION, 'stdout'=>'', 'stderr'=>''];
		}
		return self::runProcess([
			self::phpBinary(),
			$command,
			'--purpose=primary',
		], $projectRoot, $timeoutMilliseconds, self::applicationDataEnvironment($applicationDataRoot));
	}

	/** @return array<string,mixed> */
	private static function runRealtimeRegistration(
		string $projectRoot,
		string $application,
		string $environment,
		int $timeoutMilliseconds,
		?string $applicationDataRoot=null
	): array {
		$command=dirname(__DIR__).'/kernel/application_release_preflight_realtime.php';
		if(!is_file($command)){
			return ['exit_code'=>self::EXIT_CONFIGURATION, 'stdout'=>'', 'stderr'=>''];
		}
		return self::runProcess([
			self::phpBinary(),
			$command,
			'--project-root='.$projectRoot,
			'--application='.$application,
			'--environment='.$environment,
		], $projectRoot, $timeoutMilliseconds, self::applicationDataEnvironment($applicationDataRoot));
	}

	/** @return array<string,mixed> */
	private static function runHealth(
		string $projectRoot,
		string $application,
		string $environment,
		string $path,
		int $timeoutSeconds,
		?string $applicationDataRoot=null
	): array {
		$router=dirname(__DIR__).'/kernel/application_release_preflight_router.php';
		if(!is_file($router)){
			return ['ok'=>false, 'code'=>'preflight_router_missing', 'attempts'=>0, 'http_status'=>null];
		}
		$port=self::reserveLoopbackPort();
		$stateRoot=self::createIsolatedStateRoot();
		$environmentValues=self::processEnvironment();
		$environmentValues['DATAPHYRE_PREFLIGHT_PROJECT_ROOT']=$projectRoot;
		$environmentValues['DATAPHYRE_PREFLIGHT_APPLICATION']=$application;
		$environmentValues['DATAPHYRE_PREFLIGHT_STATE_ROOT']=$stateRoot;
		$environmentValues['DATAPHYRE_ENVIRONMENT']=$environment;
		$environmentValues['DATAPHYRE_RUNTIME_POOL']='health-preflight';
		$environmentValues['DATAPHYRE_RUNTIME_POOL_ROLE']='health-preflight';
		$environmentValues['DATAPHYRE_RUNTIME_PROJECT_ROOT']=$projectRoot;
		$environmentValues['DATAPHYRE_SCHEDULER_ACTIVATION_MODE']='record_only';
		$environmentValues['DATAPHYRE_SCHEDULER_STATE_ROOT']=$stateRoot;
		if(is_string($applicationDataRoot) && $applicationDataRoot!==''){
			$environmentValues['DATAPHYRE_APPLICATION_DATA_ROOT']=$applicationDataRoot;
		}
		$descriptor=[
			0=>['file', self::nullDevice(), 'r'],
			1=>['pipe', 'w'],
			2=>['pipe', 'w'],
		];
		$owned=self::openOwnedProcessGroup([
			self::phpBinary(),
			'-d',
			'variables_order=EGPCS',
			'-S',
			'127.0.0.1:'.$port,
			$router,
		], $descriptor, $projectRoot, $environmentValues);
		if($owned===null){
			self::removeIsolatedStateRoot($stateRoot);
			return ['ok'=>false, 'code'=>'application_server_unavailable', 'attempts'=>0, 'http_status'=>null];
		}
		$process=$owned['resource'];$pipes=$owned['pipes'];$processGroup=$owned['process_group'];
		$deadline=microtime(true)+$timeoutSeconds;
		$attempts=0;
		$lastStatus=null;
		$lastMissingEnvironmentKeys=[];
		$serverExited=false;
		try{
			while(microtime(true)<$deadline){
				self::discardProcessOutput($pipes);
				$status=proc_get_status($process);
				if(($status['running'] ?? false)!==true){
					$serverExited=true;
					break;
				}
				$attempts++;
				$response=self::probeLoopback($port, $path);
				if(is_array($response)){
					$lastStatus=$response['http_status'];
					$lastMissingEnvironmentKeys=$response['missing_environment_keys'];
					if($response['response_contract_valid']!==true){
						return [
							'ok'=>false,
							'code'=>'application_health_evidence_invalid',
							'attempts'=>$attempts,
							'http_status'=>$lastStatus,
							'response_contract_valid'=>false,
							'missing_environment_keys'=>[],
						];
					}
					if($lastMissingEnvironmentKeys!==[]){
						return [
							'ok'=>false,
							'code'=>'application_environment_keys_missing',
							'attempts'=>$attempts,
							'http_status'=>$lastStatus,
							'response_contract_valid'=>true,
							'missing_environment_keys'=>$lastMissingEnvironmentKeys,
						];
					}
					if($lastStatus>=200 && $lastStatus<300){
						return [
							'ok'=>true,
							'code'=>'healthy',
							'attempts'=>$attempts,
							'http_status'=>$lastStatus,
							'response_contract_valid'=>true,
							'missing_environment_keys'=>$lastMissingEnvironmentKeys,
						];
					}
				}
				usleep(100000);
			}
			return [
				'ok'=>false,
				'code'=>$serverExited ? 'application_boot_failed' : ($lastStatus===null ? 'application_health_timeout' : 'application_health_rejected'),
				'attempts'=>$attempts,
				'http_status'=>$lastStatus,
				'response_contract_valid'=>$lastStatus!==null,
				'missing_environment_keys'=>$lastMissingEnvironmentKeys,
			];
		}finally{
			self::stopProcess($process, $pipes, $processGroup);
			self::removeIsolatedStateRoot($stateRoot);
		}
	}

	private static function createIsolatedStateRoot(): string {
		$root=rtrim(sys_get_temp_dir(),'/\\').'/dataphyre-release-preflight-'.bin2hex(random_bytes(16));
		if(!mkdir($root,0700,true) || !is_dir($root) || is_link($root)){
			throw new RuntimeException('Unable to create isolated application preflight state.');
		}
		return $root;
	}

	private static function removeIsolatedStateRoot(string $root): void {
		$real=realpath($root);
		$temp=realpath(sys_get_temp_dir());
		if($real===false || $temp===false || is_link($root)
			|| dirname($real)!==$temp || !str_starts_with(basename($real),'dataphyre-release-preflight-')){
			return;
		}
		$iterator=new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($real,\FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach($iterator as $entry){
			$path=$entry->getPathname();
			if($entry->isLink() || $entry->isFile()) @unlink($path);
			elseif($entry->isDir()) @rmdir($path);
		}
		@rmdir($real);
	}

	private static function removeApplicationDataRoot(?string $root): void {
		if(is_string($root) && $root!=='') self::removeIsolatedStateRoot($root);
	}

	/** @return array<string,string> */
	private static function applicationDataEnvironment(?string $root): array {
		return is_string($root) && $root!==''
			? ['DATAPHYRE_APPLICATION_DATA_ROOT'=>$root]
			: [];
	}

	/** @param list<string> $command @param array<string,string> $environmentOverrides @return array{exit_code:int,stdout:string,stderr:string} */
	private static function runProcess(
		array $command,
		string $workingDirectory,
		int $timeoutMilliseconds,
		array $environmentOverrides=[]
	): array {
		$descriptor=[
			0=>['file', self::nullDevice(), 'r'],
			1=>['pipe', 'w'],
			2=>['pipe', 'w'],
		];
		$processEnvironment=null;
		if($environmentOverrides!==[]){
			$processEnvironment=array_replace(self::processEnvironment(), $environmentOverrides);
		}
		$owned=self::openOwnedProcessGroup(
			$command,$descriptor,$workingDirectory,$processEnvironment,
		);
		if($owned===null){
			return ['exit_code'=>127, 'stdout'=>'', 'stderr'=>''];
		}
		$process=$owned['resource'];$pipes=$owned['pipes'];$processGroup=$owned['process_group'];
		$stdout='';
		$stderr='';
		$started=microtime(true);
		$exitCode=null;
		$timedOut=false;
		while(true){
			self::drainProcessOutput($pipes,$stdout,$stderr);
			$status=proc_get_status($process);
			if(($status['running'] ?? false)!==true){
				$candidate=$status['exitcode'] ?? null;
				$exitCode=is_int($candidate) && $candidate!==-1 ? $candidate : null;
				break;
			}
			if((microtime(true)-$started)*1000>$timeoutMilliseconds){
				$timedOut=true;
				break;
			}
			usleep(self::PROCESS_POLL_MICROSECONDS);
		}
		$stopped=self::stopProcess($process,$pipes,$processGroup,$stdout,$stderr,$exitCode);
		$exitCode=$timedOut ? 124 : $stopped;
		return [
			'exit_code'=>$exitCode,
			'stdout'=>trim($stdout),
			'stderr'=>trim($stderr),
		];
	}

	private static function appendBounded(string $current, string|false $addition): string {
		if(!is_string($addition) || $addition===''){
			return $current;
		}
		$remaining=self::MAX_PROCESS_OUTPUT_BYTES-strlen($current);
		return $remaining>0 ? $current.substr($addition, 0, $remaining) : $current;
	}

	/**
	 * Opens one shell-free child in an immutable, process-owned POSIX session.
	 *
	 * `proc_open()` children inherit the caller's process group and therefore
	 * cannot already be its leader. The fixed `setsid` exec retains that PID,
	 * making the returned PID the immutable process-group id before the selected
	 * command can run.
	 *
	 * @param list<string> $command
	 * @param array<int,mixed> $descriptor
	 * @param null|array<string,string> $environment
	 * @return null|array{resource:resource,pipes:array<int,resource>,process_group:int}
	 */
	private static function openOwnedProcessGroup(
		array $command,
		array $descriptor,
		string $workingDirectory,
		?array $environment
	): ?array {
		$setsid=self::setsidExecutable();
		if($setsid===null || $command===[]){
			return null;
		}
		$process=@proc_open(
			[$setsid,...$command],$descriptor,$pipes,$workingDirectory,$environment,
			['bypass_shell'=>true,'suppress_errors'=>true],
		);
		if(!is_resource($process)){
			return null;
		}
		foreach($pipes as $pipe){
			if(is_resource($pipe)) stream_set_blocking($pipe,false);
		}
		$status=proc_get_status($process);$pid=(int)($status['pid'] ?? 0);
		if(!is_array($status) || $pid<2){
			self::closeProcessPipes($pipes);
			@proc_close($process);
			return null;
		}
		if(($status['running'] ?? false)===true){
			$deadline=microtime(true)+self::PROCESS_GROUP_START_TIMEOUT_SECONDS;
			do{
				$group=@posix_getpgid($pid);
				if($group===$pid) break;
				usleep(1000);
				$status=proc_get_status($process);
			}while(($status['running'] ?? false)===true && microtime(true)<$deadline);
			if(($status['running'] ?? false)===true && @posix_getpgid($pid)!==$pid){
				@posix_kill($pid,self::PROCESS_SIGNAL_KILL);
				self::closeProcessPipes($pipes);
				@proc_close($process);
				return null;
			}
		}
		return ['resource'=>$process,'pipes'=>$pipes,'process_group'=>$pid];
	}

	private static function setsidExecutable(): ?string {
		if(DIRECTORY_SEPARATOR==='\\' || !function_exists('posix_getpgid') || !function_exists('posix_kill')){
			return null;
		}
		$path=self::SETSID_EXECUTABLE;
		clearstatcache(true,$path);
		$resolved=realpath($path);$stat=@lstat($path);
		return is_string($resolved) && hash_equals($path,$resolved)
			&& is_array($stat) && (($stat['mode'] ?? 0)&0170000)===0100000
			&& (($stat['mode'] ?? 0)&0022)===0 && ($stat['uid'] ?? -1)===0
			&& ($stat['gid'] ?? -1)===0 && ($stat['nlink'] ?? 0)===1 && is_executable($path)
			? $resolved
			: null;
	}

	/** @param array<int,resource> $pipes */
	private static function drainProcessOutput(
		array $pipes,
		?string &$stdout=null,
		?string &$stderr=null
	): void {
		foreach([1,2] as $index){
			$pipe=$pipes[$index] ?? null;
			if(!is_resource($pipe)) continue;
			$remaining=self::PROCESS_DRAIN_BYTES_PER_STREAM;
			while($remaining>0){
				$chunk=@fread($pipe,min(8192,$remaining));
				if(!is_string($chunk) || $chunk==='') break;
				$remaining-=strlen($chunk);
				if($index===1 && $stdout!==null) $stdout=self::appendBounded($stdout,$chunk);
				if($index===2 && $stderr!==null) $stderr=self::appendBounded($stderr,$chunk);
			}
		}
	}

	/** @param array<int,resource> $pipes */
	private static function discardProcessOutput(array $pipes): void {
		$stdout=null;$stderr=null;
		self::drainProcessOutput($pipes,$stdout,$stderr);
	}

	/** @param array<int,resource> $pipes */
	private static function closeProcessPipes(array $pipes): void {
		foreach($pipes as $pipe){
			if(is_resource($pipe)) @fclose($pipe);
		}
	}

	/**
	 * @param resource $process
	 * @param array<int,resource> $pipes
	 */
	private static function stopProcess(
		$process,
		array $pipes,
		int $processGroup,
		?string &$stdout=null,
		?string &$stderr=null,
		?int $observedExitCode=null
	): int {
		$status=proc_get_status($process);
		if(($status['running'] ?? false)!==true){
			$candidate=$status['exitcode'] ?? null;
			if($observedExitCode===null && is_int($candidate) && $candidate!==-1) $observedExitCode=$candidate;
		}
		@posix_kill(-$processGroup,self::PROCESS_SIGNAL_TERM);
		$deadline=microtime(true)+self::PROCESS_TERMINATE_GRACE_SECONDS;
		do{
			self::drainProcessOutput($pipes,$stdout,$stderr);
			$status=proc_get_status($process);
			if(($status['running'] ?? false)!==true){
				$candidate=$status['exitcode'] ?? null;
				if($observedExitCode===null && is_int($candidate) && $candidate!==-1) $observedExitCode=$candidate;
			}
			$groupAlive=@posix_kill(-$processGroup,0);
			if(($status['running'] ?? false)!==true && $groupAlive!==true) break;
			usleep(self::PROCESS_POLL_MICROSECONDS);
		}while(microtime(true)<$deadline);

		if(($status['running'] ?? false)===true || $groupAlive===true){
			@posix_kill(-$processGroup,self::PROCESS_SIGNAL_KILL);
			if(($status['running'] ?? false)===true && @posix_getpgid($processGroup)!==$processGroup){
				@posix_kill($processGroup,self::PROCESS_SIGNAL_KILL);
			}
			$deadline=microtime(true)+self::PROCESS_KILL_REAP_SECONDS;
			do{
				self::drainProcessOutput($pipes,$stdout,$stderr);
				$status=proc_get_status($process);
				if(($status['running'] ?? false)!==true){
					$candidate=$status['exitcode'] ?? null;
					if($observedExitCode===null && is_int($candidate) && $candidate!==-1) $observedExitCode=$candidate;
				}
				$groupAlive=@posix_kill(-$processGroup,0);
				if(($status['running'] ?? false)!==true && $groupAlive!==true) break;
				usleep(self::PROCESS_POLL_MICROSECONDS);
			}while(microtime(true)<$deadline);
		}

		self::drainProcessOutput($pipes,$stdout,$stderr);
		self::closeProcessPipes($pipes);
		$closed=@proc_close($process);
		if($observedExitCode===null || $observedExitCode===-1){
			$observedExitCode=is_int($closed) && $closed>=0 ? $closed : 127;
		}
		return $observedExitCode;
	}

	private static function reserveLoopbackPort(): int {
		$errorCode=0;
		$errorMessage='';
		$socket=@stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
		if(!is_resource($socket)){
			throw new RuntimeException('Loopback port reservation failed.');
		}
		$name=stream_socket_get_name($socket, false);
		fclose($socket);
		if(!is_string($name) || preg_match('/:(\d+)$/D', $name, $match)!==1){
			throw new RuntimeException('Loopback port reservation was invalid.');
		}
		$port=(int)$match[1];
		if($port<1 || $port>65535){
			throw new RuntimeException('Loopback port reservation was outside range.');
		}
		return $port;
	}

	/** @return null|array{http_status:int,response_contract_valid:bool,missing_environment_keys:list<string>} */
	private static function probeLoopback(int $port, string $path): ?array {
		$errorCode=0;
		$errorMessage='';
		$stream=@stream_socket_client(
			'tcp://127.0.0.1:'.$port,
			$errorCode,
			$errorMessage,
			0.25,
			STREAM_CLIENT_CONNECT
		);
		if(!is_resource($stream)){
			return null;
		}
		try{
			stream_set_timeout($stream, 1);
			$request="GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: application/json\r\nConnection: close\r\n\r\n";
			if(fwrite($stream, $request)!==strlen($request)){
				return null;
			}
			return self::readLoopbackResponse($stream);
		}finally{
			fclose($stream);
		}
	}

	/** @param resource $stream @return null|array{http_status:int,response_contract_valid:bool,missing_environment_keys:list<string>} */
	private static function readLoopbackResponse($stream): ?array {
		$statusLine=fgets($stream, 4096);
		if(!is_string($statusLine) || preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})(?:\s|$)/D', trim($statusLine), $match)!==1){
			return null;
		}
		$status=(int)$match[1];
		if($status<100 || $status>599){
			return null;
		}
		$headerBytes=strlen($statusLine);
		$headersComplete=false;
		while(!feof($stream)){
			$line=fgets($stream, 4096);
			if(!is_string($line)){
				break;
			}
			$headerBytes+=strlen($line);
			if($headerBytes>self::MAX_HEALTH_HEADER_BYTES){
				return ['http_status'=>$status, 'response_contract_valid'=>false, 'missing_environment_keys'=>[]];
			}
			if($line==="\r\n" || $line==="\n"){
				$headersComplete=true;
				break;
			}
		}
		if(!$headersComplete){
			return ['http_status'=>$status, 'response_contract_valid'=>false, 'missing_environment_keys'=>[]];
		}

		$body=stream_get_contents($stream,self::MAX_HEALTH_BODY_BYTES+1);
		if(!is_string($body) || strlen($body)>self::MAX_HEALTH_BODY_BYTES){
			return ['http_status'=>$status, 'response_contract_valid'=>false, 'missing_environment_keys'=>[]];
		}
		$missingEnvironmentKeys=self::missingEnvironmentKeysFromHealthBody($body);
		return [
			'http_status'=>$status,
			'response_contract_valid'=>$missingEnvironmentKeys!==null,
			'missing_environment_keys'=>$missingEnvironmentKeys ?? [],
		];
	}

	/** @return null|list<string> */
	private static function missingEnvironmentKeysFromHealthBody(string $body): ?array {
		if($body==='' || strlen($body)>self::MAX_HEALTH_BODY_BYTES){
			return null;
		}
		try{
			$payload=json_decode($body, false, 16, JSON_THROW_ON_ERROR);
		}catch(JsonException){
			return null;
		}
		if(!$payload instanceof \stdClass || !property_exists($payload, 'missing_environment_keys')){
			return null;
		}
		return self::normalizeMissingEnvironmentKeys($payload->missing_environment_keys);
	}

	/** @return null|list<string> */
	private static function normalizeMissingEnvironmentKeys(mixed $values): ?array {
		if(!is_array($values) || !array_is_list($values) || count($values)>self::MAX_MISSING_ENVIRONMENT_KEYS){
			return null;
		}
		$names=[];
		foreach($values as $value){
			if(!is_string($value)
				|| preg_match('/^[A-Z_][A-Z0-9_]{0,119}$/D', $value)!==1
				|| isset($names[$value])){
				return null;
			}
			$names[$value]=true;
		}
		$names=array_keys($names);
		sort($names, SORT_STRING);
		return $names;
	}

	/** @return array<string,string> */
	private static function processEnvironment(): array {
		$values=getenv();
		$environment=[];
		foreach(is_array($values) ? $values : [] as $key=>$value){
			if(is_string($key) && is_string($value) && !str_contains($key, "\0") && !str_contains($value, "\0")){
				$environment[$key]=$value;
			}
		}
		return $environment;
	}

	private static function phpBinary(): string {
		return PHP_BINARY;
	}

	private static function nullDevice(): string {
		return DIRECTORY_SEPARATOR==='\\' ? 'NUL' : '/dev/null';
	}

	/** @return array{exit_code:int,stdout:string,stderr:string} */
	private static function normalizeProcessResult(mixed $result): array {
		if(!is_array($result)){
			return ['exit_code'=>127, 'stdout'=>'', 'stderr'=>''];
		}
		$exitCode=$result['exit_code'] ?? null;
		return [
			'exit_code'=>is_int($exitCode) && $exitCode>=0 && $exitCode<=255 ? $exitCode : 127,
			'stdout'=>is_string($result['stdout'] ?? null) ? $result['stdout'] : '',
			'stderr'=>is_string($result['stderr'] ?? null) ? $result['stderr'] : '',
		];
	}

	/** @param array{exit_code:int,stdout:string,stderr:string} $result @return array<string,mixed> */
	private static function decodeProcessPayload(array $result): array {
		$encoded=trim($result['stdout'])!=='' ? $result['stdout'] : $result['stderr'];
		if($encoded===''){
			return [];
		}
		try{
			$decoded=json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
			return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
		}catch(JsonException){
			return [];
		}
	}

	/** @return array{exit_status:int,kind:string,message:string} */
	private static function migrationFailureClassification(int $exitStatus): array {
		if(in_array($exitStatus, [64,65,66,78], true)){
			return [
				'exit_status'=>self::EXIT_CONFIGURATION,
				'kind'=>'configuration',
				'message'=>'The database migration profile, manifest, or connection configuration is invalid.',
			];
		}
		if($exitStatus===69 || $exitStatus===124 || $exitStatus===127){
			return [
				'exit_status'=>self::EXIT_DEPENDENCY,
				'kind'=>'dependency',
				'message'=>'The configured database dependency could not be verified.',
			];
		}
		return [
			'exit_status'=>self::EXIT_VERIFICATION,
			'kind'=>'verification',
			'message'=>'The database migration preflight found drift or an ineligible migration plan.',
		];
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	private static function migrationEvidence(array $payload, string $engine): array {
		$manifest=is_array($payload['manifest'] ?? null) ? $payload['manifest'] : [];
		$result=is_array($payload['result'] ?? null) ? $payload['result'] : [];
		if($engine==='sqlite'){
			return [
				'contract'=>(string)($payload['contract'] ?? ''),
				'declared'=>true,
				'dry_run'=>false,
				'engine'=>'sqlite',
				'manifest'=>array_intersect_key($manifest, array_fill_keys([
					'algorithm','migration_count','sha256',
				], true)),
				'result'=>array_intersect_key($result, array_fill_keys([
					'applied_migrations','database_file','pending_migrations',
				], true)),
				'write_scope'=>'isolated_application_data',
			];
		}
		$pending=is_array($result['pending_validation'] ?? null) ? $result['pending_validation'] : [];
		return [
			'declared'=>true,
			'dry_run'=>true,
			'contract'=>(string)($payload['contract'] ?? ''),
			'manifest'=>array_intersect_key($manifest, array_fill_keys([
				'algorithm','bootstrap_cutoff','migration_count','schema_version','sha256',
			], true)),
			'plan'=>array_intersect_key($pending, array_fill_keys([
				'mode','eligible','errors','pending_migrations','selected_migrations','deferred_migrations',
				'rolling_scan',
			], true)),
		];
	}

	/** @param array<string,mixed> $health @return array<string,mixed> */
	private static function healthEvidence(array $health): array {
		$status=$health['http_status'] ?? null;
		$statusValid=is_int($status) && $status>=100 && $status<=599;
		$missingEnvironmentKeys=self::normalizeMissingEnvironmentKeys($health['missing_environment_keys'] ?? null);
		$responseContractValid=($health['response_contract_valid'] ?? null)===true
			&& $statusValid
			&& $missingEnvironmentKeys!==null;
		return [
			'path'=>self::HEALTH_PATH,
			'loopback_only'=>true,
			'attempts'=>max(0, (int)($health['attempts'] ?? 0)),
			'http_status'=>$statusValid ? $status : null,
			'response_contract_valid'=>$responseContractValid,
			'missing_environment_keys'=>$responseContractValid ? $missingEnvironmentKeys : [],
		];
	}

	/** @return array{connection_sha256:?string,declared:bool,purpose:?string} */
	private static function databaseRuntimeEvidence(
		bool $declared=false,
		?string $connectionSha256=null
	): array {
		return [
			'connection_sha256'=>$connectionSha256,
			'declared'=>$declared,
			'purpose'=>$declared ? 'primary' : null,
		];
	}

	/** @return array<string,mixed> */
	private static function realtimeEvidence(
		int $routeCount,
		?string $registrationSha256,
		int $schedulerDefinitionCount,
		?string $schedulerDefinitionSha256,
		int $registeredTableCount,
		?string $registeredTableSetSha256
	): array {
		return [
			'authorization_before_upgrade'=>true,
			'fixed_public_port'=>8080,
			'origin_required'=>true,
			'private_web_port'=>8083,
			'registration_sha256'=>$registrationSha256,
			'registered_table_count'=>$registeredTableCount,
			'registered_table_materialization_contract'=>'dataphyre.registered_table_materialization.v1',
			'registered_table_set_sha256'=>$registeredTableSetSha256,
			'route_count'=>$routeCount,
			'scheduler_definition_count'=>$schedulerDefinitionCount,
			'scheduler_definition_sha256'=>$schedulerDefinitionSha256,
			'tls_termination'=>'platform_edge',
		];
	}

	private static function safeCode(string $value, string $fallback): string {
		$value=trim($value);
		return preg_match('/^[a-z][a-z0-9_]{2,119}$/D', $value)===1 ? $value : $fallback;
	}

	/** @param callable(string):mixed $write @param list<array<string,mixed>> $checks */
	private static function emitFailure(
		callable $write,
		int $exitStatus,
		?string $application,
		?string $environment,
		array $checks,
		string $kind,
		string $code,
		string $message
	): int {
		self::writeJson($write, self::resultEnvelope(
			$exitStatus,
			false,
			$application,
			$environment,
			$checks,
			[ [
				'kind'=>$kind,
				'code'=>$code,
				'message'=>$message,
			] ]
		));
		return $exitStatus;
	}

	/** @param list<array<string,mixed>> $checks @param list<array<string,string>> $failures @return array<string,mixed> */
	private static function resultEnvelope(
		int $exitStatus,
		bool $ok,
		?string $application,
		?string $environment,
		array $checks,
		array $failures
	): array {
		return [
			'contract'=>self::CONTRACT,
			'contract_version'=>1,
			'exit_status'=>$exitStatus,
			'ok'=>$ok,
			'likely_to_deploy'=>$ok,
			'application'=>$application,
			'environment'=>$environment,
			'execution'=>'completed',
			'execution_boundary'=>'fixed_dataphyre_commands_and_loopback_application_boot',
			'write_policy'=>'isolated_database_preflight_and_ephemeral_application_boot',
			'checks'=>$checks,
			'failures'=>$failures,
			'claim_boundary'=>ApplicationReleasePreflightEvidence::claimBoundary(),
		];
	}

	/** @param callable(string):mixed $write */
	private static function writeJson(callable $write, array $payload): void {
		if(($payload['execution'] ?? null)==='completed'){
			$write(ApplicationReleasePreflightEvidence::encodeCompleted($payload));
			return;
		}
		$encoded=json_encode(
			$payload,
			JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR
		)."\n";
		if(strlen($encoded)>ApplicationReleasePreflightEvidence::MAX_OUTPUT_BYTES){
			throw new \LengthException('Application release preflight output exceeded its public bound.');
		}
		$write($encoded);
	}

	/** @return list<string> */
	private static function usage(): array {
		return [
			'php runtime/modules/core/kernel/application_release_preflight.php --project-root=<application-project> --application=<dataphyre-app> --environment=<environment>',
			'Only --project-root, --application, --environment, and --help are accepted.',
			'No application command, script, executable path, health path, or migration mode is caller-selectable.',
		];
	}

	/** @return array<string,mixed> */
	private static function jsonExitContract(): array {
		return [
			'stdout'=>'One JSON object followed by a newline for every accepted or rejected invocation.',
			'stderr'=>'Unused by this command.',
			'boolean_verdict'=>'likely_to_deploy is always true or false; it is never null or omitted from a preflight result.',
			'exit_statuses'=>[
				'0'=>'all checks passed',
				'64'=>'invalid runtime or typed invocation',
				'66'=>'project root unavailable',
				'69'=>'required dependency could not be verified',
				'70'=>'executable verification failed',
				'75'=>'application did not become healthy',
				'78'=>'application, migration, or environment configuration invalid',
			],
		];
	}
}
