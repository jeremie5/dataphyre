<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Migrations;

use Dataphyre\ApplicationEnvironmentIdentifier;
use InvalidArgumentException;
use JsonException;
use PDO;
use Throwable;

require_once dirname(__DIR__,3).'/core/Framework/ApplicationEnvironmentIdentifier.php';

/**
 * Fixed, application-neutral CLI boundary for upward PostgreSQL migrations.
 *
 * The command accepts only typed release context. Application policy and SQL
 * are loaded from fixed project-relative data files; application PHP is never
 * booted and no executable path or arbitrary command is accepted.
 */
final class PostgreSqlMigrationCommand {
	public const CONTRACT='dataphyre.postgresql_migration_command.v1';
	public const EXIT_SUCCESS=0;
	public const EXIT_USAGE=64;
	public const EXIT_MANIFEST=65;
	public const EXIT_PROJECT=66;
	public const EXIT_DATABASE=69;
	public const EXIT_MIGRATION=70;
	public const EXIT_CONFIGURATION=78;

	/**
	 * Execute the command through native streams or explicit test seams.
	 *
	 * @param list<string> $arguments Full PHP argument vector.
	 * @param array<string,mixed> $runtime Optional stream, environment, PDO, and apply seams.
	 */
	public static function main(array $arguments, array $runtime=[]): int {
		$writeOut=$runtime['write_out'] ?? static fn(string $value): int|false=>fwrite(STDOUT, $value);
		$writeError=$runtime['write_error'] ?? static fn(string $value): int|false=>fwrite(STDERR, $value);
		$sapi=(string)($runtime['sapi'] ?? PHP_SAPI);
		if(!in_array($sapi, ['cli', 'phpdbg'], true)){
			return self::failure(
				$writeError,
				self::EXIT_USAGE,
				'invalid_runtime',
				'PostgreSQL migrations are available only through the CLI.'
			);
		}

		try{
			$options=self::options($arguments);
		}catch(Throwable){
			return self::failure(
				$writeError,
				self::EXIT_USAGE,
				'invalid_invocation',
				'Use only the documented typed PostgreSQL migration options.'
			);
		}
		if($options['help']===true){
			self::writeJson($writeOut, [
				'contract'=>self::CONTRACT,
				'exit_status'=>self::EXIT_SUCCESS,
				'ok'=>true,
				'required_environment'=>[
					'DATAPHYRE_DATABASE_DSN',
					'DATAPHYRE_DATABASE_USER (optional)',
					'DATAPHYRE_DATABASE_PASSWORD (optional)',
					'DATAPHYRE_ENVIRONMENT (optional exact-match guard)',
				],
				'usage'=>self::usage(),
			]);
			return self::EXIT_SUCCESS;
		}

		$context=self::context($options);
		try{
			$projectRoot=self::projectRoot($options['project_root']);
		}catch(Throwable){
			return self::failure(
				$writeError,
				self::EXIT_PROJECT,
				'project_unavailable',
				'The selected project root is unavailable.',
				$context
			);
		}

		try{
			$profile=self::loadProfile($projectRoot, $options['app']);
		}catch(Throwable){
			return self::failure(
				$writeError,
				self::EXIT_CONFIGURATION,
				'profile_invalid',
				'The fixed PostgreSQL migration profile is missing or invalid.',
				$context
			);
		}

		try{
			$manifest=PostgreSqlMigrationManifest::load($projectRoot.'/database', $profile);
		}catch(Throwable){
			return self::failure(
				$writeError,
				self::EXIT_MANIFEST,
				'manifest_invalid',
				'The immutable PostgreSQL migration manifest is invalid.',
				$context
			);
		}

		try{
			[$dsn,$username,$password]=self::connectionValues($options, $runtime);
		}catch(Throwable){
			return self::failure(
				$writeError,
				self::EXIT_CONFIGURATION,
				'database_configuration_invalid',
				'The PostgreSQL migration connection is not configured for this environment.',
				$context
			);
		}

		try{
			$pdoFactory=$runtime['pdo_factory'] ?? static fn(
				string $connectionDsn,
				?string $connectionUsername,
				?string $connectionPassword,
				array $attributes
			): PDO=>new PDO($connectionDsn, $connectionUsername, $connectionPassword, $attributes);
			$pdo=$pdoFactory($dsn, $username, $password, [
				PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES=>false,
				PDO::ATTR_PERSISTENT=>false,
			]);
			if(!$pdo instanceof PDO){
				throw new InvalidArgumentException('PostgreSQL migration connection factory returned an invalid value.');
			}
		}catch(Throwable){
			return self::failure(
				$writeError,
				self::EXIT_DATABASE,
				'database_connection_failed',
				'Dataphyre could not connect to the configured PostgreSQL database.',
				$context
			);
		}

		try{
			if($options['mode']==='automatic'){
				$selectMode=$runtime['automatic_mode_selector'] ?? static fn(
					PDO $connection,
					PostgreSqlMigrationProfile $migrationProfile,
					PostgreSqlMigrationManifest $migrationManifest
				): string=>self::automaticMode(
					$migrationManifest,
					(new PostgreSqlMigrationRunner($connection, $migrationProfile))->status($migrationManifest)
				);
				$selectedMode=$selectMode($pdo, $profile, $manifest);
				if(!in_array($selectedMode, ['bootstrap', 'rolling'], true)){
					throw new InvalidArgumentException('Automatic migration mode selection returned an invalid value.');
				}
				$options['mode']=$selectedMode;
			}
			$apply=$runtime['apply'] ?? static function(
				PDO $connection,
				PostgreSqlMigrationProfile $migrationProfile,
				PostgreSqlMigrationManifest $migrationManifest,
				array $commandOptions
			): array {
				$runner=new PostgreSqlMigrationRunner($connection, $migrationProfile);
				$plan=$runner->deploymentEvidence(
					$migrationManifest,
					$runner->status($migrationManifest),
					$commandOptions['mode'],
					$commandOptions['verified_minimum_active_release']
				);
				if(($plan['eligible'] ?? null)!==true){
					return [
						'transaction'=>'not_started',
						'transaction_scope'=>'none',
						'migrations'=>[],
						'deployment_mode'=>$commandOptions['mode'],
						'direction'=>'up',
						'release_version'=>$commandOptions['release_version'],
						'release_sha256'=>$commandOptions['release_sha256'],
						'bootstrap_cutoff'=>$migrationManifest->bootstrapCutoff(),
						'pending_validation'=>$plan,
					];
				}
				return $runner->apply(
					$migrationManifest,
					$commandOptions['mode'],
					$commandOptions['dry_run'],
					self::releaseIdentity($commandOptions),
					$commandOptions['verified_minimum_active_release']
				);
			};
			$result=$apply($pdo, $profile, $manifest, $options);
			if(!is_array($result)){
				throw new InvalidArgumentException('PostgreSQL migration apply result must be an array.');
			}
		}catch(Throwable){
			return self::failure(
				$writeError,
				self::EXIT_MIGRATION,
				'migration_failed',
				'Dataphyre could not apply the selected PostgreSQL migrations.',
				$context
			);
		}
		if(($result['pending_validation']['eligible'] ?? null)===false){
			return self::failure(
				$writeError,
				self::EXIT_MIGRATION,
				'migration_plan_ineligible',
				'The pending PostgreSQL migrations are not eligible for the selected deployment mode.',
				[
					...$context,
					'manifest'=>self::manifestEvidence($manifest),
					'result'=>self::resultEvidence($result),
				]
			);
		}

		self::writeJson($writeOut, [
			...$context,
			'contract'=>self::CONTRACT,
			'exit_status'=>self::EXIT_SUCCESS,
			'manifest'=>self::manifestEvidence($manifest),
			'ok'=>true,
			'result'=>self::resultEvidence($result),
		]);
		return self::EXIT_SUCCESS;
	}

	/** @param list<string> $arguments @return array<string,mixed> */
	private static function options(array $arguments): array {
		$options=[
			'project_root'=>null,
			'app'=>null,
			'environment'=>null,
			'mode'=>null,
			'dry_run'=>false,
			'release_version'=>null,
			'release_sha256'=>null,
			'verified_minimum_active_release'=>null,
			'help'=>false,
		];
		$names=[
			'project-root'=>'project_root',
			'app'=>'app',
			'environment'=>'environment',
			'mode'=>'mode',
			'release-version'=>'release_version',
			'release-sha256'=>'release_sha256',
			'verified-minimum-active-release'=>'verified_minimum_active_release',
		];
		$seen=[];
		foreach(array_slice($arguments, 1) as $argument){
			$argument=(string)$argument;
			if($argument==='--help' || $argument==='-h'){
				if(isset($seen['help'])){
					throw new InvalidArgumentException('Duplicate help option.');
				}
				$seen['help']=true;
				$options['help']=true;
				continue;
			}
			if($argument==='--dry-run'){
				if(isset($seen['dry-run'])){
					throw new InvalidArgumentException('Duplicate dry-run option.');
				}
				$seen['dry-run']=true;
				$options['dry_run']=true;
				continue;
			}
			if(preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/D', $argument, $match)!==1){
				throw new InvalidArgumentException('PostgreSQL migration arguments must use --name=value.');
			}
			$name=$match[1];
			if(!isset($names[$name]) || isset($seen[$name])){
				throw new InvalidArgumentException('Unknown or duplicate PostgreSQL migration option.');
			}
			$value=trim($match[2]);
			if(
				$value===''
				|| strlen($value)>4096
				|| preg_match('/[\x00-\x1f\x7f]/', $value)===1
			){
				throw new InvalidArgumentException('PostgreSQL migration option value is invalid.');
			}
			$seen[$name]=true;
			$options[$names[$name]]=$value;
		}
		if($options['help']===true){
			return $options;
		}
		foreach(['project_root', 'app', 'environment', 'mode'] as $required){
			if(!is_string($options[$required]) || $options[$required]===''){
				throw new InvalidArgumentException('Required PostgreSQL migration option is missing.');
			}
		}
		if(preg_match('/^[A-Za-z_][A-Za-z0-9_$]{0,62}$/D', $options['app'])!==1){
			throw new InvalidArgumentException('PostgreSQL migration application id is invalid.');
		}
		if(!ApplicationEnvironmentIdentifier::valid($options['environment'])){
			throw new InvalidArgumentException('PostgreSQL migration environment id is invalid.');
		}
		if(!in_array($options['mode'], ['automatic', 'bootstrap', 'rolling', 'maintenance'], true)){
			throw new InvalidArgumentException('PostgreSQL migration mode is invalid.');
		}
		if(($options['release_version']===null)!==($options['release_sha256']===null)){
			throw new InvalidArgumentException('PostgreSQL migration release identity is incomplete.');
		}
		if(
			$options['release_version']!==null
			&& !PostgreSqlMigrationProfile::validVersion($options['release_version'])
		){
			throw new InvalidArgumentException('PostgreSQL migration release version is invalid.');
		}
		if(
			$options['release_sha256']!==null
			&& preg_match('/^[a-f0-9]{64}$/D', $options['release_sha256'])!==1
		){
			throw new InvalidArgumentException('PostgreSQL migration release digest is invalid.');
		}
		$minimum=$options['verified_minimum_active_release'];
		if(
			($options['mode']!=='maintenance' && $minimum!==null)
			|| ($minimum!==null && !PostgreSqlMigrationProfile::validVersion($minimum))
		){
			throw new InvalidArgumentException('PostgreSQL migration compatibility floor is invalid.');
		}
		return $options;
	}

	private static function projectRoot(string $path): string {
		$resolved=realpath($path);
		if($resolved===false || !is_dir($resolved) || !is_readable($resolved)){
			throw new InvalidArgumentException('PostgreSQL migration project root is unavailable.');
		}
		return rtrim($resolved, '/\\');
	}

	private static function loadProfile(
		string $projectRoot,
		string $application
	): PostgreSqlMigrationProfile {
		$path=$projectRoot.'/database/postgresql/profile.json';
		if(is_link($path) || !is_file($path) || !is_readable($path)){
			throw new InvalidArgumentException('PostgreSQL migration profile is unavailable.');
		}
		$bytes=file_get_contents($path);
		if(!is_string($bytes)){
			throw new InvalidArgumentException('PostgreSQL migration profile could not be read.');
		}
		try{
			$decoded=json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
		}catch(JsonException $exception){
			throw new InvalidArgumentException('PostgreSQL migration profile is invalid JSON.', 0, $exception);
		}
		if(!is_array($decoded) || array_is_list($decoded)){
			throw new InvalidArgumentException('PostgreSQL migration profile must be an object.');
		}
		$profile=PostgreSqlMigrationProfile::fromArray($decoded);
		if(!hash_equals($profile->applicationId(), $application)){
			throw new InvalidArgumentException('PostgreSQL migration profile application does not match.');
		}
		if($profile->manifestPublicPath()!=='database/postgresql/manifest.json'){
			throw new InvalidArgumentException('PostgreSQL migration profile manifest path is not fixed.');
		}
		return $profile;
	}

	/** @param array<string,mixed> $options @return array{0:string,1:?string,2:?string} */
	private static function connectionValues(array $options, array $runtime): array {
		$configuredEnvironment=self::environmentValue($runtime, 'DATAPHYRE_ENVIRONMENT');
		if(
			is_string($configuredEnvironment)
			&& trim($configuredEnvironment)!==''
			&& !hash_equals($options['environment'], trim($configuredEnvironment))
		){
			throw new InvalidArgumentException('Configured migration environment does not match.');
		}
		$dsn=self::environmentValue($runtime, 'DATAPHYRE_DATABASE_DSN');
		$dsn=is_string($dsn) ? trim($dsn) : '';
		if(
			$dsn===''
			|| strlen($dsn)>8192
			|| strncasecmp($dsn, 'pgsql:', 6)!==0
			|| preg_match('/[\x00-\x1f\x7f]/', $dsn)===1
		){
			throw new InvalidArgumentException('PostgreSQL migration DSN is unavailable or invalid.');
		}
		$username=self::environmentValue($runtime, 'DATAPHYRE_DATABASE_USER');
		$password=self::environmentValue($runtime, 'DATAPHYRE_DATABASE_PASSWORD');
		return [
			$dsn,
			is_string($username) && $username!=='' ? $username : null,
			is_string($password) ? $password : null,
		];
	}

	private static function environmentValue(array $runtime, string $name): ?string {
		$values=$runtime['environment_values'] ?? null;
		if(is_array($values)){
			$value=$values[$name] ?? null;
			return is_string($value) ? $value : null;
		}
		$value=getenv($name);
		return is_string($value) ? $value : null;
	}

	/** @param array<string,mixed> $options @return ?array{release_version:string,release_sha256:string} */
	private static function releaseIdentity(array $options): ?array {
		if($options['release_version']===null){
			return null;
		}
		return [
			'release_version'=>$options['release_version'],
			'release_sha256'=>$options['release_sha256'],
		];
	}

	/** @param array<string,mixed> $options @return array<string,mixed> */
	private static function context(array $options): array {
		return [
			'application'=>$options['app'],
			'dry_run'=>$options['dry_run'],
			'environment'=>$options['environment'],
			'mode'=>$options['mode'],
		];
	}

	/** @param array<string,mixed> $state */
	private static function automaticMode(
		PostgreSqlMigrationManifest $manifest,
		array $state
	): string {
		if(($state['drift_count'] ?? null)!==0
			|| !is_array($state['migrations'] ?? null)
			|| !array_is_list($state['migrations'])){
			throw new InvalidArgumentException('Automatic migration mode requires one drift-free migration state.');
		}
		$cutoffStatus=null;
		foreach($state['migrations'] as $migration){
			if(!is_array($migration)
				|| !hash_equals($manifest->bootstrapCutoff(), (string)($migration['id'] ?? ''))){
				continue;
			}
			if($cutoffStatus!==null){
				throw new InvalidArgumentException('Automatic migration mode found duplicate bootstrap cutoff state.');
			}
			$cutoffStatus=$migration['status'] ?? null;
		}
		return match($cutoffStatus){
			'pending'=>'bootstrap',
			'applied'=>'rolling',
			default=>throw new InvalidArgumentException(
				'Automatic migration mode could not establish the bootstrap cutoff state.'
			),
		};
	}

	/** @return array<string,mixed> */
	private static function manifestEvidence(PostgreSqlMigrationManifest $manifest): array {
		$summary=$manifest->publicSummary();
		return [
			'algorithm'=>'sha256',
			'bootstrap_cutoff'=>$summary['bootstrap_cutoff'],
			'migration_count'=>$summary['migration_count'],
			'path'=>$summary['path'],
			'phases'=>$summary['phases'],
			'schema_version'=>$summary['schema_version'],
			'sha256'=>$summary['sha256'],
		];
	}

	/** @param array<string,mixed> $result @return array<string,mixed> */
	private static function resultEvidence(array $result): array {
		$allowed=[
			'transaction',
			'transaction_scope',
			'migrations',
			'deployment_mode',
			'direction',
			'release_version',
			'release_sha256',
			'required_minimum_active_release',
			'verified_minimum_active_release',
			'normalized_legacy_aliases',
			'bootstrap_cutoff',
			'pending_validation',
		];
		$evidence=array_intersect_key($result, array_fill_keys($allowed, true));
		if(array_key_exists('pending_validation', $evidence)){
			$evidence['pending_validation']=self::pendingEvidence($evidence['pending_validation']);
		}
		return $evidence;
	}

	/** @return array<string,mixed>|null */
	private static function pendingEvidence(mixed $pending): ?array {
		if(!is_array($pending) || array_is_list($pending)){
			return null;
		}
		$allowed=[
			'mode',
			'bootstrap_cutoff',
			'bootstrap_cutoff_status',
			'pending_migrations',
			'pending_phases',
			'selected_migrations',
			'selected_phases',
			'deferred_migrations',
			'eligible',
			'errors',
			'required_minimum_active_release',
			'verified_minimum_active_release',
			'compatibility_floor_satisfied',
			'rolling_scan',
		];
		$evidence=array_intersect_key($pending, array_fill_keys($allowed, true));
		$scan=$evidence['rolling_scan'] ?? null;
		if(is_array($scan) && !array_is_list($scan)){
			$scan=array_intersect_key($scan, array_fill_keys([
				'performed', 'migration_count', 'issue_count', 'issues',
			], true));
			$issues=[];
			foreach(is_array($scan['issues'] ?? null) ? $scan['issues'] : [] as $issue){
				if(is_array($issue) && !array_is_list($issue)){
					$issues[]=array_intersect_key($issue, array_fill_keys([
						'migration', 'code', 'statement',
					], true));
				}
			}
			$scan['issues']=$issues;
			$evidence['rolling_scan']=$scan;
		}else{
			unset($evidence['rolling_scan']);
		}
		return $evidence;
	}

	/** @param callable(string):mixed $write @param array<string,mixed> $context */
	private static function failure(
		callable $write,
		int $status,
		string $code,
		string $message,
		array $context=[]
	): int {
		self::writeJson($write, [
			...$context,
			'contract'=>self::CONTRACT,
			'error'=>[
				'code'=>$code,
				'message'=>$message,
			],
			'exit_status'=>$status,
			'ok'=>false,
		]);
		return $status;
	}

	/** @param callable(string):mixed $write @param array<string,mixed> $payload */
	private static function writeJson(callable $write, array $payload): void {
		$write(json_encode(
			self::canonicalize($payload),
			JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES
		).PHP_EOL);
	}

	private static function canonicalize(mixed $value): mixed {
		if(!is_array($value)){
			return $value;
		}
		if(array_is_list($value)){
			return array_map(self::canonicalize(...), $value);
		}
		ksort($value, SORT_STRING);
		foreach($value as $key=>$item){
			$value[$key]=self::canonicalize($item);
		}
		return $value;
	}

	private static function usage(): string {
		return 'php runtime/modules/sql/kernel/postgresql_migrate.php '.
			'--project-root=<project> --app=<id> --environment=<id> '.
			'--mode=<automatic|bootstrap|rolling|maintenance> [--dry-run] '.
			'[--release-version=<semver> --release-sha256=<sha256>] '.
			'[--verified-minimum-active-release=<semver>]';
	}
}
