<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Release;

use Dataphyre\ApplicationEnvironmentIdentifier;

require_once __DIR__.'/../Framework/ApplicationEnvironmentIdentifier.php';

/**
 * Sole semantic authority for completed application release preflight v1 evidence.
 *
 * Producers and consumers share this validator so exact keys, safe evidence,
 * failure tuples, exit relationships, and bounds cannot drift independently.
 */
final class ApplicationReleasePreflightEvidence
{
	public const CONTRACT='dataphyre.application_release_preflight.v1';
	public const MAX_OUTPUT_BYTES=524288;
	/** 300 seconds of fixed stage maxima plus 15 seconds for startup, cleanup, and encoding. */
	public const COMMAND_TIMEOUT_MILLISECONDS=315000;
	public const MCP_TRANSPORT_OVERHEAD_MILLISECONDS=10000;
	private const RELEASE_PREFLIGHT_MAX_MIGRATION_ITEMS=999;
	private const RELEASE_PREFLIGHT_MAX_MIGRATION_ERRORS=2048;
	private const RELEASE_PREFLIGHT_MAX_ROLLING_ISSUES=4096;
	private const RELEASE_PREFLIGHT_MAX_MISSING_ENVIRONMENT_KEYS=64;
	private const RELEASE_PREFLIGHT_MAX_EVIDENCE_NODES=65536;

	/** @return array<string,mixed>|null */
	public static function validate(
		mixed $preflight,
		mixed $processExit,
		?string $expectedApplication,
		?string $expectedEnvironment,
	): ?array {
		return self::validated_release_preflight(
			$preflight,$processExit,$expectedApplication,$expectedEnvironment,
		);
	}

	public static function applicationIdentifier(string $value): bool {
		return self::release_preflight_application_identifier($value);
	}

	public static function environmentIdentifier(string $value): bool {
		return self::release_preflight_environment_identifier($value);
	}

	public static function claimBoundary(): string {
		return self::release_preflight_claim_boundary();
	}

	/** @return array<string,mixed> */
	public static function failure(
		?string $application,
		?string $environment,
		int $exitStatus,
		string $kind,
		string $code,
		string $message,
	): array {
		$typedTarget=is_string($application) && is_string($environment)
			&& self::applicationIdentifier($application) && self::environmentIdentifier($environment);
		$payload=[
			'contract'=>self::CONTRACT,'contract_version'=>1,'exit_status'=>$exitStatus,
			'ok'=>false,'likely_to_deploy'=>false,
			'application'=>$typedTarget ? $application : null,
			'environment'=>$typedTarget ? $environment : null,
			'execution'=>'completed',
			'execution_boundary'=>'fixed_dataphyre_commands_and_loopback_application_boot',
			'write_policy'=>'isolated_database_preflight_and_ephemeral_application_boot',
			'checks'=>[],
			'failures'=>[['kind'=>$kind,'code'=>$code,'message'=>$message]],
			'claim_boundary'=>self::claimBoundary(),
		];
		$validated=self::validate(
			$payload,
			$exitStatus,
			$typedTarget ? $application : null,
			$typedTarget ? $environment : null,
		);
		if($validated===null){
			throw new \LogicException('Application release preflight failure tuple is not part of v1.');
		}
		return $validated;
	}

	/** Encodes only a semantically valid completed v1 payload within the public cap. */
	public static function encodeCompleted(array $payload): string {
		$validated=self::validate(
			$payload,$payload['exit_status'] ?? null,
			is_string($payload['application'] ?? null) ? $payload['application'] : null,
			is_string($payload['environment'] ?? null) ? $payload['environment'] : null,
		);
		if($validated===null){
			throw new \LogicException('Application release preflight constructed invalid evidence.');
		}
		$encoded=json_encode(
			$validated,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR,
		)."\n";
		if(strlen($encoded)>self::MAX_OUTPUT_BYTES){
			throw new \LengthException('Application release preflight evidence exceeded its public output bound.');
		}
		return $encoded;
	}

	private static function release_preflight_application_identifier(string $value): bool {
		return (
			preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $value)===1
				&& !in_array($value, ['.','..'], true)
		) || preg_match('/^[A-Za-z_][A-Za-z0-9_$]{0,62}$/D', $value)===1;
	}

	private static function release_preflight_environment_identifier(string $value): bool {
		return ApplicationEnvironmentIdentifier::valid($value);
	}

	/** @return array<string,mixed>|null */
	private static function validated_release_preflight(
		mixed $preflight,
		mixed $processExit,
		?string $expectedApplication,
		?string $expectedEnvironment
	): ?array {
		$remainingNodes=self::RELEASE_PREFLIGHT_MAX_EVIDENCE_NODES;
		if(
			!is_array($preflight)
			|| !self::release_preflight_value_is_bounded($preflight, $remainingNodes)
			|| !self::release_preflight_exact_object($preflight, [
				'contract',
				'contract_version',
				'exit_status',
				'ok',
				'likely_to_deploy',
				'application',
				'environment',
				'execution',
				'execution_boundary',
				'write_policy',
				'checks',
				'failures',
				'claim_boundary',
			])
			|| $preflight['contract']!=='dataphyre.application_release_preflight.v1'
			|| $preflight['contract_version']!==1
			|| $preflight['execution']!=='completed'
			|| $preflight['execution_boundary']!=='fixed_dataphyre_commands_and_loopback_application_boot'
			|| $preflight['write_policy']!=='isolated_database_preflight_and_ephemeral_application_boot'
			|| $preflight['claim_boundary']!==self::release_preflight_claim_boundary()
			|| $preflight['application']!==$expectedApplication
			|| $preflight['environment']!==$expectedEnvironment
			|| !(($preflight['application']===null && $preflight['environment']===null)
				|| (is_string($preflight['application']) && self::applicationIdentifier($preflight['application'])
					&& is_string($preflight['environment']) && self::environmentIdentifier($preflight['environment'])))
			|| !is_int($processExit)
			|| !is_int($preflight['exit_status'])
			|| $processExit!==$preflight['exit_status']
			|| !in_array($preflight['exit_status'], [0,64,66,69,70,75,78], true)
			|| !is_bool($preflight['ok'])
			|| !is_bool($preflight['likely_to_deploy'])
			|| $preflight['ok']!==$preflight['likely_to_deploy']
			|| ($preflight['exit_status']===0)!==$preflight['ok']
			|| !is_array($preflight['checks'])
			|| !is_array($preflight['failures'])
			|| !self::release_preflight_failures_are_valid(
				$preflight['failures'],
				$preflight['exit_status'],
				$preflight['ok']
			)
			|| !self::release_preflight_checks_are_valid(
				$preflight['checks'],
				$preflight['failures'],
				$preflight['exit_status'],
				$preflight['ok']
			)
			|| !self::release_preflight_failure_stage_is_valid($preflight)
		){
			return null;
		}
		return $preflight;
	}

	/** @param list<mixed> $failures */
	private static function release_preflight_failures_are_valid(array $failures, int $exitStatus, bool $ok): bool {
		if(!array_is_list($failures) || count($failures)!==($ok ? 0 : 1)) return false;
		if($ok) return true;
		$failure=$failures[0] ?? null;
		if(
			!is_array($failure)
			|| !self::release_preflight_exact_object($failure, ['kind','code','message'])
			|| !is_string($failure['kind'])
			|| !is_string($failure['code'])
			|| !is_string($failure['message'])
		){
			return false;
		}
		$applicationMessage='The application bootstrap configuration is incomplete or invalid.';
		$migrationConfigurationMessage='The database migration profile, manifest, or connection configuration is invalid.';
		$migrationVerificationMessage='The database migration preflight found drift or an ineligible migration plan.';
		$migrationDependencyMessage='The configured database dependency could not be verified.';
		$databaseIdentityMessage='The application-resolved managed database identity could not be verified.';
		$healthMessage='The application did not become healthy through the fixed loopback probe.';
		$realtimeMessage='The application realtime callbacks, scheduler definitions, or registered table definitions did not load through the fixed framework bootstrap.';
		$tuples=[
			'64:invalid_runtime'=>['configuration','Application release preflight is available only through the CLI.'],
			'64:invalid_invocation'=>['configuration','Use only the documented typed application release preflight options.'],
			'66:project_unavailable'=>['configuration','The selected application project root is unavailable.'],
			'69:database_connection_failed'=>['dependency',$migrationDependencyMessage],
			'69:database_unavailable'=>['dependency',$migrationDependencyMessage],
			'69:migration_preflight_failed'=>['dependency',$migrationDependencyMessage],
			'69:application_data_root_unavailable'=>['dependency','The isolated application data root could not be created.'],
			'69:application_database_identity_failed'=>['dependency',$databaseIdentityMessage],
			'69:preflight_executable_unavailable'=>[
				'dependency',
				'The fixed Dataphyre application preflight executable is unavailable.',
			],
			'69:preflight_runner_unavailable'=>[
				'dependency',
				'The fixed Dataphyre application preflight could not be executed.',
			],
			'70:migration_failed'=>['verification',$migrationVerificationMessage],
			'70:migration_plan_ineligible'=>['verification',$migrationVerificationMessage],
			'70:migration_preflight_failed'=>['verification',$migrationVerificationMessage],
			'70:legacy_database_requires_one_time_migration'=>['verification',$migrationVerificationMessage],
			'70:application_realtime_registration_failed'=>['verification',$realtimeMessage],
			'70:preflight_result_invalid'=>[
				'verification',
				'The fixed Dataphyre application preflight returned an invalid result.',
			],
			'75:preflight_router_missing'=>['verification',$healthMessage],
			'75:application_server_unavailable'=>['verification',$healthMessage],
			'75:application_health_evidence_invalid'=>['verification',$healthMessage],
			'75:application_environment_keys_missing'=>['verification',$healthMessage],
			'75:application_boot_failed'=>['verification',$healthMessage],
			'75:application_health_timeout'=>['verification',$healthMessage],
			'75:application_health_rejected'=>['verification',$healthMessage],
			'75:application_health_failed'=>['verification',$healthMessage],
			'78:flight_sheet_missing'=>['configuration',$applicationMessage],
			'78:runtime_bootstrap_missing'=>['configuration',$applicationMessage],
			'78:application_manifest_mismatch'=>['configuration',$applicationMessage],
			'78:application_definition_missing'=>['configuration',$applicationMessage],
			'78:application_configuration_invalid'=>['configuration',$applicationMessage],
			'78:migration_configuration_incomplete'=>[
				'configuration',
				'The application must provide exactly one complete PostgreSQL or SQLite migration profile and immutable manifest.',
			],
			'78:invalid_runtime'=>['configuration',$migrationConfigurationMessage],
			'78:invalid_invocation'=>['configuration',$migrationConfigurationMessage],
			'78:project_unavailable'=>['configuration',$migrationConfigurationMessage],
			'78:profile_invalid'=>['configuration',$migrationConfigurationMessage],
			'78:manifest_invalid'=>['configuration',$migrationConfigurationMessage],
			'78:database_configuration_invalid'=>['configuration',$migrationConfigurationMessage],
			'78:application_data_root_invalid'=>['configuration',$migrationConfigurationMessage],
			'78:migration_preflight_failed'=>['configuration',$migrationConfigurationMessage],
		];
		$expected=$tuples[$exitStatus.':'.$failure['code']] ?? null;
		return is_array($expected)
			&& $failure['kind']===$expected[0]
			&& $failure['message']===$expected[1];
	}

	/** @param array<string,mixed> $preflight */
	private static function release_preflight_failure_stage_is_valid(array $preflight): bool {
		if($preflight['ok']===true) return true;
		$checks=$preflight['checks'];
		$failure=$preflight['failures'][0];
		$code=$failure['code'];
		$exitStatus=$preflight['exit_status'];
		if($checks===[]){
			return in_array([$exitStatus,$code], [[64,'invalid_runtime'],
				[64,'invalid_invocation'],
				[66,'project_unavailable'],
				[69,'preflight_executable_unavailable'],
				[69,'preflight_runner_unavailable'],
				[70,'preflight_result_invalid'],
				[78,'flight_sheet_missing'],
				[78,'runtime_bootstrap_missing'],
				[78,'application_manifest_mismatch'],
				[78,'application_definition_missing'],
				[78,'application_configuration_invalid'],
			], true);
		}
		if(count($checks)===2){
			$database=$checks[1]['evidence'];
			if(self::release_preflight_exact_object($database, ['declared'])){
				return ($database['declared'] ?? null)===true && in_array([$exitStatus,$code], [[69,'application_data_root_unavailable'],
					[78,'migration_configuration_incomplete'],
				], true);
			}
			return ($database['error_code'] ?? null)===$code
				&& in_array([$exitStatus,$code], [[69,'database_connection_failed'],
					[69,'database_unavailable'],
					[69,'migration_preflight_failed'],
					[69,'application_data_root_unavailable'],
					[70,'migration_failed'],
					[70,'migration_plan_ineligible'],
					[70,'migration_preflight_failed'],
					[70,'legacy_database_requires_one_time_migration'],
					[78,'profile_invalid'],
					[78,'manifest_invalid'],
					[78,'invalid_runtime'],
					[78,'invalid_invocation'],
					[78,'project_unavailable'],
					[78,'database_configuration_invalid'],
					[78,'application_data_root_invalid'],
					[78,'migration_preflight_failed'],
				], true);
		}
		if(count($checks)===3){
			return $exitStatus===69
				&& $code==='application_database_identity_failed'
				&& ($checks[2]['status'] ?? null)==='failed';
		}
		if(count($checks)===4) return $exitStatus===75;
		return count($checks)===5
			&& $exitStatus===70
			&& $code==='application_realtime_registration_failed'
			&& ($checks[4]['status'] ?? null)==='failed';
	}

	/** @param list<mixed> $checks @param list<mixed> $failures */
	private static function release_preflight_checks_are_valid(array $checks, array $failures, int $exitStatus, bool $ok): bool {
		if(!array_is_list($checks) || count($checks)>5) return false;
		$expectedIds=['configuration_bootstrap','database_migrations','database_runtime','application_health','realtime_registration'];
		foreach($checks as $index=>$check){
			if(
				!is_array($check)
				|| !self::release_preflight_exact_object($check, ['id','status','evidence'])
				|| ($check['id'] ?? null)!==$expectedIds[$index]
				|| !is_array($check['evidence'])
			){
				return false;
			}
		}
		$failureCode=is_string($failures[0]['code'] ?? null) ? $failures[0]['code'] : '';
		if(isset($checks[0]) && !self::release_preflight_configuration_check_is_valid($checks[0])) return false;
		if(isset($checks[1]) && !self::release_preflight_database_check_is_valid($checks[1], $failureCode, $exitStatus)) return false;
		if(isset($checks[2]) && !self::release_preflight_database_runtime_check_is_valid($checks[2], $failureCode)) return false;
		if(isset($checks[3]) && !self::release_preflight_health_check_is_valid($checks[3], $failureCode)) return false;
		if(isset($checks[4]) && !self::release_preflight_realtime_check_is_valid($checks[4], $failureCode)) return false;

		if($ok){
			return count($checks)===5
				&& in_array($checks[1]['status'], ['passed','not_applicable'], true)
				&& in_array($checks[2]['status'], ['passed','not_applicable'], true)
				&& $checks[3]['status']==='passed'
				&& $checks[4]['status']==='passed';
		}
		if($checks===[]) return in_array($exitStatus, [64,66,69,70,78], true);
		if(count($checks)===2){
			return $checks[1]['status']==='failed' && in_array($exitStatus, [69,70,78], true);
		}
		if(count($checks)===3){
			return in_array($checks[1]['status'], ['passed','not_applicable'], true)
				&& $checks[2]['status']==='failed'
				&& $failureCode==='application_database_identity_failed'
				&& $exitStatus===69;
		}
		if(count($checks)===4){
			return in_array($checks[1]['status'], ['passed','not_applicable'], true)
				&& in_array($checks[2]['status'], ['passed','not_applicable'], true)
				&& $checks[3]['status']==='failed'
				&& $exitStatus===75;
		}
		return count($checks)===5
			&& in_array($checks[1]['status'], ['passed','not_applicable'], true)
			&& in_array($checks[2]['status'], ['passed','not_applicable'], true)
			&& $checks[3]['status']==='passed'
			&& $checks[4]['status']==='failed'
			&& $failureCode==='application_realtime_registration_failed'
			&& $exitStatus===70;
	}

	/** @param array<string,mixed> $check */
	private static function release_preflight_realtime_check_is_valid(array $check, string $failureCode): bool {
		$evidence=$check['evidence'];
		if(!in_array($check['status'], ['passed','failed'], true)
			|| !self::release_preflight_exact_object($evidence, [
				'authorization_before_upgrade','fixed_public_port','origin_required','private_web_port',
				'registration_sha256','registered_table_count','registered_table_materialization_contract',
				'registered_table_set_sha256','route_count','scheduler_definition_count',
				'scheduler_definition_sha256','tls_termination',
			])
			|| $evidence['authorization_before_upgrade']!==true
			|| $evidence['fixed_public_port']!==8080
			|| $evidence['origin_required']!==true
			|| $evidence['private_web_port']!==8083
			|| !is_int($evidence['route_count']) || $evidence['route_count']<0 || $evidence['route_count']>128
			|| !is_int($evidence['scheduler_definition_count'])
			|| $evidence['scheduler_definition_count']<0 || $evidence['scheduler_definition_count']>256
			|| !is_int($evidence['registered_table_count'])
			|| $evidence['registered_table_count']<0 || $evidence['registered_table_count']>1024
			|| $evidence['registered_table_materialization_contract']!=='dataphyre.registered_table_materialization.v1'
			|| $evidence['tls_termination']!=='platform_edge'){
			return false;
		}
		if($check['status']==='passed'){
			return is_string($evidence['registration_sha256'])
				&& preg_match('/^sha256:[0-9a-f]{64}$/D',$evidence['registration_sha256'])===1
				&& is_string($evidence['scheduler_definition_sha256'])
				&& preg_match('/^sha256:[0-9a-f]{64}$/D',$evidence['scheduler_definition_sha256'])===1
				&& is_string($evidence['registered_table_set_sha256'])
				&& preg_match('/^sha256:[0-9a-f]{64}$/D',$evidence['registered_table_set_sha256'])===1;
		}
		return $evidence['route_count']===0
			&& $evidence['registration_sha256']===null
			&& $evidence['scheduler_definition_count']===0
			&& $evidence['scheduler_definition_sha256']===null
			&& $evidence['registered_table_count']===0
			&& $evidence['registered_table_set_sha256']===null
			&& $failureCode==='application_realtime_registration_failed';
	}

	/** @param array<string,mixed> $check */
	private static function release_preflight_configuration_check_is_valid(array $check): bool {
		$evidence=$check['evidence'];
		return $check['status']==='passed'
			&& self::release_preflight_exact_object($evidence, [
				'application_layout','application_definition','flight_sheet','runtime_bootstrap',
			])
			&& in_array($evidence['application_layout'], ['standalone_application_root','project_applications_root'], true)
			&& $evidence['application_definition']===true
			&& $evidence['flight_sheet']===true
			&& $evidence['runtime_bootstrap']===true;
	}

	/** @param array<string,mixed> $check */
	private static function release_preflight_database_check_is_valid(array $check, string $failureCode, int $preflightExitStatus): bool {
		$evidence=$check['evidence'];
		if($check['status']==='not_applicable'){
			return self::release_preflight_exact_object($evidence, ['declared','reason'])
				&& $evidence['declared']===false
				&& $evidence['reason']==='no_database_migration_profile';
		}
		if($check['status']==='failed' && self::release_preflight_exact_object($evidence, ['declared'])){
			return $evidence['declared']===true;
		}
		if(array_key_exists('engine',$evidence)){
			return self::release_preflight_sqlite_database_check_is_valid(
				$check,$failureCode,$preflightExitStatus,
			);
		}
		$expectedKeys=$check['status']==='passed'
			? ['declared','dry_run','contract','manifest','plan']
			: ['declared','dry_run','contract','manifest','plan','exit_status','error_code'];
		if(
			!in_array($check['status'], ['passed','failed'], true)
			|| !self::release_preflight_exact_object($evidence, $expectedKeys)
		){
			return false;
		}
		$manifestEmpty=$evidence['manifest']===[];
		$planEmpty=$evidence['plan']===[];
		if(
			$evidence['declared']!==true
			|| $evidence['dry_run']!==true
			|| !is_string($evidence['contract'])
			|| !in_array($evidence['contract'], ['', 'dataphyre.postgresql_migration_command.v1'], true)
			|| !is_array($evidence['manifest'])
			|| !is_array($evidence['plan'])
			|| $manifestEmpty!==$planEmpty
			|| !self::release_preflight_manifest_evidence_is_valid($evidence['manifest'], $check['status']==='passed')
			|| !self::release_preflight_plan_evidence_is_valid($evidence['plan'], $check['status']==='passed')
		){
			return false;
		}
		if($check['status']==='passed'){
			return $evidence['contract']==='dataphyre.postgresql_migration_command.v1'
				&& $evidence['plan']['eligible']===true
				&& self::release_preflight_plan_fits_manifest($evidence['manifest'], $evidence['plan']);
		}
		$childExit=$evidence['exit_status'];
		if(
			!is_int($childExit)
			|| $childExit<0
			|| $childExit>255
			|| !is_string($evidence['error_code'])
			|| preg_match('/^[a-z][a-z0-9_]{2,119}$/D', $evidence['error_code'])!==1
		){
			return false;
		}
		$classifiedExit=in_array($childExit, [64,65,66,78], true)
			? 78
			: (in_array($childExit, [69,124,127], true) ? 69 : 70);
		return $classifiedExit===$preflightExitStatus
			&& (!$manifestEmpty
				? $evidence['contract']==='dataphyre.postgresql_migration_command.v1'
					&& $childExit===70
					&& $evidence['plan']['eligible']===false
					&& self::release_preflight_plan_fits_manifest($evidence['manifest'], $evidence['plan'])
					&& $failureCode==='migration_plan_ineligible'
				: self::release_preflight_empty_migration_failure_is_valid(
					$childExit,
					$evidence['contract'],
					$failureCode
				))
			&& $evidence['error_code']===$failureCode;
	}

	/** @param array<string,mixed> $check */
	private static function release_preflight_sqlite_database_check_is_valid(
		array $check,
		string $failureCode,
		int $preflightExitStatus,
	): bool {
		$evidence=$check['evidence'];
		$expectedKeys=$check['status']==='passed'
			? ['contract','declared','dry_run','engine','manifest','result','write_scope']
			: ['contract','declared','dry_run','engine','manifest','result','write_scope','exit_status','error_code'];
		if(!in_array($check['status'],['passed','failed'],true)
			|| !self::release_preflight_exact_object($evidence,$expectedKeys)
			|| $evidence['declared']!==true
			|| $evidence['dry_run']!==false
			|| $evidence['engine']!=='sqlite'
			|| $evidence['write_scope']!=='isolated_application_data'
			|| !is_string($evidence['contract'])
			|| !in_array($evidence['contract'],['','dataphyre.sqlite_migration_command.v1'],true)
			|| !is_array($evidence['manifest'])
			|| !is_array($evidence['result'])){
			return false;
		}
		$manifestValid=self::release_preflight_sqlite_manifest_is_valid($evidence['manifest']);
		$resultValid=self::release_preflight_sqlite_result_is_valid($evidence['result'],$evidence['manifest']);
		if($check['status']==='passed'){
			return $evidence['contract']==='dataphyre.sqlite_migration_command.v1'
				&& $manifestValid && $resultValid
				&& $evidence['result']['pending_migrations']===[]
				&& self::release_preflight_sqlite_migration_ids_are_complete(
					$evidence['result']['applied_migrations'],$evidence['manifest']['migration_count'],
				);
		}
		$childExit=$evidence['exit_status'];
		if(!is_int($childExit) || $childExit<0 || $childExit>255
			|| !is_string($evidence['error_code'])
			|| preg_match('/^[a-z][a-z0-9_]{2,119}$/D',$evidence['error_code'])!==1
			|| $evidence['error_code']!==$failureCode){
			return false;
		}
		$classifiedExit=in_array($childExit,[64,65,66,78],true)
			? 78
			: (in_array($childExit,[69,124,127],true) ? 69 : 70);
		return $classifiedExit===$preflightExitStatus
			&& (($evidence['contract']===''
					&& $failureCode==='migration_preflight_failed'
					&& $evidence['manifest']===[]
					&& $evidence['result']===[])
				|| ($evidence['contract']==='dataphyre.sqlite_migration_command.v1'
					&& (($evidence['manifest']===[] && $evidence['result']===[])
						|| ($manifestValid && $evidence['result']===[]))));
	}

	private static function release_preflight_sqlite_manifest_is_valid(array $manifest): bool {
		return self::release_preflight_exact_object($manifest,['algorithm','migration_count','sha256'])
			&& ($manifest['algorithm'] ?? null)==='sha256'
			&& is_int($manifest['migration_count'] ?? null)
			&& $manifest['migration_count']>=1
			&& $manifest['migration_count']<=self::RELEASE_PREFLIGHT_MAX_MIGRATION_ITEMS
			&& is_string($manifest['sha256'] ?? null)
			&& preg_match('/^[a-f0-9]{64}$/D',$manifest['sha256'])===1;
	}

	private static function release_preflight_sqlite_result_is_valid(array $result,array $manifest): bool {
		return self::release_preflight_exact_object($result,['applied_migrations','database_file','pending_migrations'])
			&& is_string($result['database_file'] ?? null)
			&& preg_match('/^[a-z0-9][a-z0-9._-]{0,119}\.sqlite$/D',$result['database_file'])===1
			&& self::release_preflight_migration_ids_are_valid($result['applied_migrations'] ?? null)
			&& self::release_preflight_migration_ids_are_valid($result['pending_migrations'] ?? null)
			&& count($result['applied_migrations'])+count($result['pending_migrations'])===($manifest['migration_count'] ?? -1)
			&& array_intersect($result['applied_migrations'],$result['pending_migrations'])===[]
			&& self::release_preflight_sqlite_migration_ids_are_complete(
				array_merge($result['applied_migrations'],$result['pending_migrations']),
				(int)($manifest['migration_count'] ?? 0),
			);
	}

	private static function release_preflight_sqlite_migration_ids_are_complete(mixed $ids,int $count): bool {
		if(!is_array($ids) || !array_is_list($ids) || count($ids)!==$count) return false;
		foreach($ids as $index=>$id){
			if(!self::release_preflight_migration_id($id) || (int)substr($id,0,3)!==$index+1) return false;
		}
		return true;
	}

	/** @param array<string,mixed> $check */
	private static function release_preflight_database_runtime_check_is_valid(array $check, string $failureCode): bool {
		$evidence=$check['evidence'];
		if(
			!in_array($check['status'], ['passed','failed','not_applicable'], true)
			|| !self::release_preflight_exact_object($evidence, [
				'connection_sha256','declared','purpose',
			])
			|| !is_bool($evidence['declared'])
			|| !(is_null($evidence['purpose']) || is_string($evidence['purpose']))
			|| !(is_null($evidence['connection_sha256']) || is_string($evidence['connection_sha256']))
		){
			return false;
		}
		return match($check['status']){
			'not_applicable'=>$evidence['declared']===false
				&& $evidence['purpose']===null
				&& $evidence['connection_sha256']===null,
			'passed'=>$evidence['declared']===true
				&& $evidence['purpose']==='primary'
				&& is_string($evidence['connection_sha256'])
				&& preg_match('/^sha256:[0-9a-f]{64}$/D', $evidence['connection_sha256'])===1,
			'failed'=>$evidence['declared']===true
				&& $evidence['purpose']==='primary'
				&& $evidence['connection_sha256']===null
				&& $failureCode==='application_database_identity_failed',
			default=>false,
		};
	}

	private static function release_preflight_plan_fits_manifest(array $manifest, array $plan): bool {
		$count=$manifest['migration_count'];
		if((int)substr($manifest['bootstrap_cutoff'], 0, 3)>$count) return false;
		foreach($plan['pending_migrations'] as $migration){
			if((int)substr($migration, 0, 3)>$count) return false;
		}
		foreach($plan['errors'] as $error){
			$parts=explode(':', $error);
			if(count($parts)!==2) continue;
			$migration=$parts[1];
			if((int)substr($migration, 0, 3)>$count) return false;
			if(
					in_array($parts[0], ['pending_contract_requires_compatibility_finalization',
					'pending_migration_is_not_rolling_expand',
				], true)
				&& !in_array($migration, $plan['pending_migrations'], true)
			){
				return false;
			}
		}
		return true;
	}

	private static function release_preflight_empty_migration_failure_is_valid(
		int $childExit,
		string $contract,
		string $failureCode
	): bool {
		if($childExit<0 || $childExit>255) return false;
		if($failureCode==='migration_preflight_failed'){
			return $contract==='';
		}
		if($contract!=='dataphyre.postgresql_migration_command.v1') return false;
		return in_array([$childExit,$failureCode], [[64,'invalid_runtime'],
			[64,'invalid_invocation'],
			[65,'manifest_invalid'],
			[66,'project_unavailable'],
			[69,'database_connection_failed'],
			[70,'migration_failed'],
			[78,'profile_invalid'],
			[78,'database_configuration_invalid'],
		], true);
	}

	private static function release_preflight_manifest_evidence_is_valid(array $manifest, bool $required): bool {
		if($manifest===[]) return !$required;
		return self::release_preflight_exact_object($manifest, [
			'algorithm','bootstrap_cutoff','migration_count','schema_version','sha256',
		])
			&& $manifest['algorithm']==='sha256'
			&& self::release_preflight_migration_id($manifest['bootstrap_cutoff'] ?? null)
			&& is_int($manifest['migration_count'])
			&& $manifest['migration_count']>=1
			&& $manifest['migration_count']<=self::RELEASE_PREFLIGHT_MAX_MIGRATION_ITEMS
			&& $manifest['schema_version']===3
			&& is_string($manifest['sha256'])
			&& preg_match('/^[a-f0-9]{64}$/D', $manifest['sha256'])===1;
	}

	private static function release_preflight_plan_evidence_is_valid(array $plan, bool $required): bool {
		if($plan===[]) return !$required;
		if(
			!self::release_preflight_exact_object($plan, [
				'mode','eligible','errors','pending_migrations','selected_migrations','deferred_migrations','rolling_scan',
			])
			|| !in_array($plan['mode'], ['bootstrap','rolling'], true)
			|| !is_bool($plan['eligible'])
			|| !self::release_preflight_migration_errors_are_valid($plan['errors'])
			|| !self::release_preflight_migration_ids_are_valid($plan['pending_migrations'])
			|| !self::release_preflight_migration_ids_are_valid($plan['selected_migrations'])
			|| !self::release_preflight_migration_ids_are_valid($plan['deferred_migrations'])
			|| !is_array($plan['rolling_scan'])
			|| !self::release_preflight_rolling_scan_is_valid($plan['rolling_scan'], $plan['mode'], $plan['selected_migrations'])
		){
			return false;
		}
		if($plan['pending_migrations']!==array_merge($plan['selected_migrations'], $plan['deferred_migrations'])) return false;
		$hasRollingIssues=$plan['rolling_scan']['issues']!==[];
		$hasRollingError=in_array('pending_rolling_migrations_contain_incompatible_sql', $plan['errors'], true);
		if($hasRollingIssues!==$hasRollingError) return false;
		return $plan['eligible']===($plan['errors']===[] && $plan['rolling_scan']['issues']===[])
			&& (!$required || $plan['eligible']===true);
	}

	private static function release_preflight_rolling_scan_is_valid(array $scan, string $mode, array $selected): bool {
		if(
			!self::release_preflight_exact_object($scan, ['performed','migration_count','issue_count','issues'])
			|| !is_bool($scan['performed'])
			|| $scan['performed']!==($mode==='rolling')
			|| !is_int($scan['migration_count'])
			|| $scan['migration_count']!==($mode==='rolling' ? count($selected) : 0)
			|| !is_int($scan['issue_count'])
			|| !is_array($scan['issues'])
			|| !array_is_list($scan['issues'])
			|| count($scan['issues'])>self::RELEASE_PREFLIGHT_MAX_ROLLING_ISSUES
			|| $scan['issue_count']!==count($scan['issues'])
			|| ($scan['performed']===false && $scan['issues']!==[])
		){
			return false;
		}
		$issueCodes=[
			'drop_object',
			'truncate_rows',
			'delete_rows',
			'replace_object',
			'create_index_requires_concurrent_autocommit_protocol',
			'create_trigger',
			'revoke_privilege',
			'dynamic_sql',
			'unsafe_create_table',
			'unsafe_comment',
			'set_not_null',
			'add_not_null_column',
			'incompatible_alter_table',
			'incompatible_alter',
			'data_mutation_not_allowlisted',
			'privilege_change_not_allowlisted',
			'unapproved_statement',
		];
		$selectedPositions=array_flip($selected);
		$previousPosition=-1;
		$previousStatement=0;
		foreach($scan['issues'] as $issue){
			if(
				!is_array($issue)
				|| !self::release_preflight_exact_object($issue, ['migration','code','statement'])
				|| !self::release_preflight_migration_id($issue['migration'] ?? null)
				|| !is_string($issue['code'])
				|| !in_array($issue['code'], $issueCodes, true)
				|| !is_int($issue['statement'])
				|| $issue['statement']<1
				|| $issue['statement']>2048
			){
				return false;
			}
			$position=$selectedPositions[$issue['migration']] ?? null;
			if(
				!is_int($position)
				|| $position<$previousPosition
				|| ($position===$previousPosition && $issue['statement']<=$previousStatement)
			){
				return false;
			}
			if($position!==$previousPosition) $previousStatement=0;
			$previousPosition=$position;
			$previousStatement=$issue['statement'];
		}
		return true;
	}

	private static function release_preflight_migration_ids_are_valid(mixed $values): bool {
		if(!is_array($values) || !array_is_list($values) || count($values)>self::RELEASE_PREFLIGHT_MAX_MIGRATION_ITEMS) return false;
		$seen=[];
		$previous=0;
		foreach($values as $value){
			$ordinal=is_string($value) ? (int)substr($value, 0, 3) : 0;
			if(!self::release_preflight_migration_id($value) || isset($seen[$value]) || $ordinal<=$previous) return false;
			$seen[$value]=true;
			$previous=$ordinal;
		}
		return true;
	}

	private static function release_preflight_migration_errors_are_valid(mixed $values): bool {
		if(!is_array($values) || !array_is_list($values) || count($values)>self::RELEASE_PREFLIGHT_MAX_MIGRATION_ERRORS) return false;
		$fixed=[
			'migration_state_entries_invalid',
			'migration_state_entries_duplicate',
			'migration_state_entries_unmanifested',
			'migration_state_drift_count_invalid',
			'migration_state_has_drift',
			'bootstrap_cutoff_already_applied_with_pending_rolling_migrations',
			'bootstrap_history_is_out_of_order',
			'bootstrap_cutoff_not_applied',
			'pending_rolling_migrations_contain_incompatible_sql',
		];
		$withMigration=[
			'migration_state_status_missing',
			'migration_state_status_not_deployable',
			'pending_contract_requires_compatibility_finalization',
			'pending_migration_is_not_rolling_expand',
		];
		$seen=[];
		foreach($values as $value){
			if(!is_string($value) || strlen($value)>512 || isset($seen[$value])) return false;
			$seen[$value]=true;
			if(in_array($value, $fixed, true)) continue;
			$parts=explode(':', $value);
			if(count($parts)!==2 || !in_array($parts[0], $withMigration, true) || !self::release_preflight_migration_id($parts[1])) return false;
		}
		return true;
	}

	private static function release_preflight_migration_id(mixed $value): bool {
		return is_string($value)
			&& preg_match('/^[0-9]{3}_[a-z0-9_]{1,124}$/D', $value)===1
			&& (int)substr($value, 0, 3)>=1;
	}

	/** @param array<string,mixed> $check */
	private static function release_preflight_health_check_is_valid(array $check, string $failureCode): bool {
		$evidence=$check['evidence'];
		if(
			!in_array($check['status'], ['passed','failed'], true)
			|| !self::release_preflight_exact_object($evidence, [
				'path','loopback_only','attempts','http_status','response_contract_valid','missing_environment_keys',
			])
			|| $evidence['path']!=='/health'
			|| $evidence['loopback_only']!==true
			|| !is_int($evidence['attempts'])
			|| $evidence['attempts']<0
			|| $evidence['attempts']>10000
			|| !(is_null($evidence['http_status']) || (
				is_int($evidence['http_status'])
				&& $evidence['http_status']>=100
				&& $evidence['http_status']<=599
			))
			|| !is_bool($evidence['response_contract_valid'])
			|| !self::release_preflight_missing_environment_keys_are_valid($evidence['missing_environment_keys'])
			|| ($evidence['response_contract_valid']===false && $evidence['missing_environment_keys']!==[])
		){
			return false;
		}
		$healthyStatus=is_int($evidence['http_status'])
			&& $evidence['http_status']>=200
			&& $evidence['http_status']<300;
		if($check['status']==='passed'){
			return $healthyStatus
				&& $evidence['attempts']>=1
				&& $evidence['response_contract_valid']===true
				&& $evidence['missing_environment_keys']===[];
		}
		if($evidence['missing_environment_keys']!==[]){
			return is_int($evidence['http_status'])
				&& $evidence['response_contract_valid']===true
				&& $evidence['attempts']>=1
				&& $failureCode==='application_environment_keys_missing';
		}
		if($failureCode==='application_environment_keys_missing') return false;
		if($failureCode==='application_health_failed') return true;
		if($evidence['response_contract_valid']===false){
			if(is_int($evidence['http_status'])){
				return $evidence['attempts']>=1 && $failureCode==='application_health_evidence_invalid';
			}
			return match($failureCode){
				'preflight_router_missing','application_server_unavailable'=>$evidence['attempts']===0,
				'application_boot_failed'=>$evidence['attempts']>=0,
				'application_health_evidence_invalid'=>$evidence['attempts']>=1,
				'application_health_timeout'=>$evidence['attempts']>=1,
				default=>false,
			};
		}
		return is_int($evidence['http_status'])
			&& !$healthyStatus
			&& $evidence['attempts']>=1
			&& in_array($failureCode, ['application_boot_failed','application_health_rejected'], true);
	}

	private static function release_preflight_missing_environment_keys_are_valid(mixed $values): bool {
		if(
			!is_array($values)
			|| !array_is_list($values)
			|| count($values)>self::RELEASE_PREFLIGHT_MAX_MISSING_ENVIRONMENT_KEYS
		){
			return false;
		}
		$seen=[];
		foreach($values as $value){
			if(
				!is_string($value)
				|| preg_match('/^[A-Z_][A-Z0-9_]{0,119}$/D', $value)!==1
				|| isset($seen[$value])
			){
				return false;
			}
			$seen[$value]=true;
		}
		$sorted=$values;
		sort($sorted, SORT_STRING);
		return $values===$sorted;
	}

	/** @param list<string> $keys */
	private static function release_preflight_exact_object(array $value, array $keys): bool {
		if(array_is_list($value)) return false;
		$actual=array_keys($value);
		sort($actual, SORT_STRING);
		sort($keys, SORT_STRING);
		return $actual===$keys;
	}

	private static function release_preflight_value_is_bounded(mixed $value, int &$remainingNodes, int $depth=0): bool {
		$remainingNodes--;
		if($remainingNodes<0 || $depth>16) return false;
		if(!is_array($value)) return true;
		foreach($value as $item){
			if(!self::release_preflight_value_is_bounded($item, $remainingNodes, $depth+1)) return false;
		}
		return true;
	}

	private static function release_preflight_claim_boundary(): string {
		return 'This verdict covers local configuration bootstrap, the native PostgreSQL migration dry-run or isolated SQL-only SQLite apply when declared, application startup against the same database state, GET /health, and deterministic realtime callback, scheduler definition, and registered table-definition inventories. A release platform must run this same command inside the exact candidate image and separately prove registered-table materialization before application migrations, the three fixed process identities, scheduler callback execution, a framework listener roundtrip, execution and strict invalid-Origin rejection by every registered application authorization callback, WebSocket ping/pong and close, signal lifecycle, persistent application-data binding, and source, image, environment, database, and traffic identity.';
	}

}
