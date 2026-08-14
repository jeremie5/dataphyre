<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpInspectionBoundaryHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpTestKit.php';

$inspectionContracts=[];
foreach(McpInspectionBoundaryHarness::contractNames() as $contract){$inspectionContracts[$contract]=[$contract];}
dataset('MCP inspection boundary contracts',$inspectionContracts);

suite('MCP release inspection and repair contracts')
	->tag('mcp','inspection','release','repair','doctor','verification','coupling','boundary','contract')
	->group('framework-coverage')
	->contract('mcp.inspection.release-boundaries',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp','path:runtime/modules/mcp/kernel/dataphyre_mcp.inspection.php')
	->through('release transcript builder','failure classifier','repair catalog','bounded repair plan','doctor','aggregate verification','managed repository fixture','coupling guard')
	->isolation('process')
	->maxMillis(180000)
	->coverageMemoryLimit('1G');

test('each inspection boundary reads as a release diagnostic contract',static function(Context $t,string $name): void {
	$contract=(new McpInspectionBoundaryHarness($t))->contract($name);
	$t->same($name,$contract['contract']);

	if($name==='release check executes the fixed local preflight with a deterministic verdict'){
		$release=$contract['release'];
		$t->same('dataphyre.application-release-prediction',$release['contract_type']);
		$t->same(2,$release['contract_version']);
		$t->same('local_preflight_executed',$release['execution']);
		$t->isTrue($release['prediction']['available']);
		$t->isTrue($release['prediction']['likely_to_deploy']);
		$t->same('application_preflight_passed',$release['prediction']['reason_code']);
		$t->isTrue($release['passed']);
		$t->same('dataphyre.application_release_preflight.v1',$release['preflight']['contract']);
		$preflightFields=array_keys($release['preflight']);
		sort($preflightFields,SORT_STRING);
		$t->same([
			'application','checks','claim_boundary','contract','contract_version','environment','execution',
			'execution_boundary','exit_status','failures','likely_to_deploy','ok','write_policy',
		],$preflightFields);
		$t->same('fixed_dataphyre_commands_and_loopback_application_boot',$release['preflight']['execution_boundary']);
		$t->same('isolated_database_preflight_and_ephemeral_application_boot',$release['preflight']['write_policy']);
		$t->same([
			'configuration_bootstrap','database_migrations','database_runtime','application_health','realtime_registration',
		],array_column($release['preflight']['checks'],'id'));
		$t->same([
			'connection_sha256'=>null,
			'declared'=>false,
			'purpose'=>null,
		],$release['preflight']['checks'][2]['evidence']);
		$t->same('/health',$release['preflight']['checks'][3]['evidence']['path']);
		$t->same(true,$release['preflight']['checks'][3]['evidence']['response_contract_valid']);
		$t->same([],$release['preflight']['checks'][3]['evidence']['missing_environment_keys']);
		$t->contains('exact candidate image',$release['maintainer_tool_boundary']['claim_boundary']);
		$t->contains('a framework listener roundtrip',$release['maintainer_tool_boundary']['claim_boundary']);
		$t->contains('strict invalid-Origin rejection by every registered application authorization callback',$release['maintainer_tool_boundary']['claim_boundary']);
		$t->contains('WebSocket ping/pong and close',$release['maintainer_tool_boundary']['claim_boundary']);
		$t->count(1,$contract['commands']);
		$command=$contract['commands'][0];
		$t->endsWith('/runtime/modules/core/kernel/application_release_preflight.php',$command[1]);
		$t->contains('--project-root=',$command[2]);
		$t->same('--application=fixture-app',$command[3]);
		$t->same('--environment=staging',$command[4]);
		$t->count(5,$command);
		$t->isTrue($contract['configuration_failure']['prediction']['available']);
		$t->isFalse($contract['configuration_failure']['prediction']['likely_to_deploy']);
		$t->same('flight_sheet_missing',$contract['configuration_failure']['prediction']['reason_code']);
		$t->same('configuration',$contract['configuration_failure']['preflight']['failures'][0]['kind']);
		$t->isTrue($contract['invalid_executable_result']['prediction']['available']);
		$t->isFalse($contract['invalid_executable_result']['prediction']['likely_to_deploy']);
		$t->same('preflight_result_invalid',$contract['invalid_executable_result']['prediction']['reason_code']);
		$t->same('verification',$contract['invalid_executable_result']['preflight']['failures'][0]['kind']);
		foreach([
			'wrong_application',
			'wrong_environment',
			'wrong_contract_version',
			'wrong_execution',
			'wrong_execution_boundary',
			'wrong_write_policy',
			'wrong_claim_boundary',
			'extra_envelope_field',
			'extra_database_runtime_evidence',
			'invalid_database_runtime_hash',
			'contradictory_database_runtime_not_applicable',
			'failed_database_runtime_hash',
			'extra_health_evidence',
			'unsafe_missing_keys',
			'value_bearing_missing_key',
			'too_many_missing_keys',
			'unhealthy_passed_check',
			'zero_attempt_pass',
			'sqlite_pending_migration',
			'sqlite_unsafe_database_file',
			'sqlite_out_of_order_journal',
			'raw_migration_evidence',
			'reordered_migration_plan',
			'unknown_migration_issue',
			'deferred_migration_issue',
			'mismatched_migration_code',
			'arbitrary_failure_message',
			'mismatched_fixed_failure_message',
			'wrong_type_migration_exit',
			'zero_migration_id',
			'duplicate_rolling_issue',
			'duplicate_migration_errors',
			'missing_rolling_error',
			'contractful_migration_fallback',
			'impossible_migration_child_tuple',
			'impossible_health_null_status',
			'impossible_health_invalid_attempts',
			'unsupported_exit',
		] as $invalidMetadata){
			$result=$contract['invalid_metadata_results'][$invalidMetadata];
			$t->isTrue($result['prediction']['available']);
			$t->isFalse($result['prediction']['likely_to_deploy']);
			$t->isFalse($result['passed']);
			$t->same('preflight_result_invalid',$result['prediction']['reason_code']);
			$t->same(70,$result['preflight']['exit_status']);
			$t->same('verification',$result['preflight']['failures'][0]['kind']);
			$encoded=json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
			$t->isFalse(str_contains($encoded,'SECRET_'),$invalidMetadata.' raw evidence');
		}
		$missing=$contract['missing_environment_failure'];
		$t->isFalse($missing['prediction']['likely_to_deploy']);
		$t->same('application_environment_keys_missing',$missing['prediction']['reason_code']);
		$t->same([
			'SERVE_SIGNING_KEY',
			'SERVE_STAFF_SESSION_SECRET',
		],$missing['preflight']['checks'][3]['evidence']['missing_environment_keys']);
		$t->same(503,$missing['preflight']['checks'][3]['evidence']['http_status']);
		$databaseRuntime=$contract['database_runtime_success'];
		$t->isTrue($databaseRuntime['prediction']['likely_to_deploy']);
		$t->same('passed',$databaseRuntime['preflight']['checks'][2]['status']);
		$t->matches('/^sha256:[0-9a-f]{64}$/D',$databaseRuntime['preflight']['checks'][2]['evidence']['connection_sha256']);
		$t->same('primary',$databaseRuntime['preflight']['checks'][2]['evidence']['purpose']);
		$databaseRuntimeFailure=$contract['database_runtime_failure'];
		$t->isFalse($databaseRuntimeFailure['prediction']['likely_to_deploy']);
		$t->same('application_database_identity_failed',$databaseRuntimeFailure['prediction']['reason_code']);
		$t->same('dependency',$databaseRuntimeFailure['preflight']['failures'][0]['kind']);
		$t->same([
			'connection_sha256'=>null,
			'declared'=>true,
			'purpose'=>'primary',
		],$databaseRuntimeFailure['preflight']['checks'][2]['evidence']);
		$t->isTrue($contract['migration_success']['prediction']['likely_to_deploy']);
		$t->same('sha256',$contract['migration_success']['preflight']['checks'][1]['evidence']['manifest']['algorithm']);
		$t->same(true,$contract['migration_success']['preflight']['checks'][1]['evidence']['plan']['eligible']);
		$t->isTrue($contract['sqlite_migration_success']['prediction']['likely_to_deploy']);
		$t->same('sqlite',$contract['sqlite_migration_success']['preflight']['checks'][1]['evidence']['engine']);
		$t->same([],$contract['sqlite_migration_success']['preflight']['checks'][1]['evidence']['result']['pending_migrations']);
		$t->isFalse($contract['migration_failure']['prediction']['likely_to_deploy']);
		$t->same('migration_plan_ineligible',$contract['migration_failure']['prediction']['reason_code']);
		$t->same(
			'add_not_null_column',
			$contract['migration_failure']['preflight']['checks'][1]['evidence']['plan']['rolling_scan']['issues'][0]['code']
		);
		$t->same('migration_plan_ineligible',$contract['large_migration_errors']['prediction']['reason_code']);
		$t->count(299,$contract['large_migration_errors']['preflight']['checks'][1]['evidence']['plan']['errors']);
		$t->same('migration_plan_ineligible',$contract['large_rolling_issues']['prediction']['reason_code']);
		$t->same(1000,$contract['large_rolling_issues']['preflight']['checks'][1]['evidence']['plan']['rolling_scan']['issue_count']);
		$t->same(2048,$contract['large_rolling_issues']['preflight']['checks'][1]['evidence']['plan']['rolling_scan']['issues'][999]['statement']);
		$t->same('application_boot_failed',$contract['health_boot_after_rejection']['prediction']['reason_code']);
		$t->same(503,$contract['health_boot_after_rejection']['preflight']['checks'][3]['evidence']['http_status']);
		$t->same('application_health_failed',$contract['health_fallback']['prediction']['reason_code']);
		$t->same('migration_preflight_failed',$contract['arbitrary_migration_exit']['prediction']['reason_code']);
		$t->same(42,$contract['arbitrary_migration_exit']['preflight']['checks'][1]['evidence']['exit_status']);
		$t->same('migration_preflight_failed',$contract['zero_migration_exit']['prediction']['reason_code']);
		$t->same(0,$contract['zero_migration_exit']['preflight']['checks'][1]['evidence']['exit_status']);
		$t->isTrue($contract['hyphenated_environment']['prediction']['likely_to_deploy']);
		$t->same('preview-123',$contract['hyphenated_environment']['preflight']['environment']);
		$t->isTrue($contract['profile_application_identifier']['prediction']['likely_to_deploy']);
		$t->same('_fixture$worker',$contract['profile_application_identifier']['preflight']['application']);
		$t->isTrue($contract['stderr_is_ignored']['prediction']['likely_to_deploy']);
		$t->isFalse(str_contains(
			json_encode($contract['stderr_is_ignored'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
			'SECRET_STDERR_VALUE_MUST_NOT_LEAK'
		));
		$t->same('preflight_result_invalid',$contract['oversized_runner_result']['prediction']['reason_code']);
		$t->isFalse(str_contains(
			json_encode($contract['oversized_runner_result'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
			'SECRET_OVERSIZED_VALUE_MUST_NOT_LEAK'
		));
		$t->same(null,$contract['bounded_process']['exit_code']);
		$t->same('',$contract['bounded_process']['stdout']);
		$t->same('',$contract['bounded_process']['stderr']);
		$t->isTrue($contract['bounded_process']['stdout_limit_exceeded']);
		$t->same(0,$contract['stderr_process']['exit_code']);
		$t->same('{"ok":true}',$contract['stderr_process']['stdout']);
		$t->same('',$contract['stderr_process']['stderr']);
		$t->isFalse($contract['stderr_process']['stdout_limit_exceeded']);
		return;
	}

	if($name==='release output classifies every failure family while ignoring non-fail lines'){
		$t->same(['module_docs','module_index','invalid_json','license_wording','release_hygiene','missing_spdx_headers','other'],$contract['category_order']);
		$t->same(8,$contract['failure_count']);
		$t->count(2,$contract['categories']['module_docs']);
		foreach(['module_index','invalid_json','license_wording','release_hygiene','missing_spdx_headers','other'] as $family){$t->count(1,$contract['categories'][$family]);}
		foreach($contract['empty'] as $failures){$t->same([],$failures);}
		return;
	}

	if($name==='repair plans batch failures in priority order with bounded examples'){
		$plan=$contract['plan'];
		$t->same('provided_output',$plan['source']);
		$t->same('not_executed',$plan['execution']);
		$t->same(10,$plan['total_failures']);
		$t->same(7,$plan['batch_count']);
		$t->same(['module_index','module_docs','invalid_json','missing_spdx_headers','license_wording','release_hygiene','other'],$contract['batch_categories']);
		$t->same(2,$contract['invalid_examples']);
		$t->same(4,$contract['bounded_examples']);
		$t->same(4,$contract['fallback_examples']);
		$t->same('none',$contract['empty']['source']);
		$t->same('not_executed',$contract['empty']['execution']);
		$t->same(0,$contract['empty']['total_failures']);
		$t->same(0,$contract['empty']['batch_count']);
		return;
	}

	if($name==='repair contracts keep priority action and verification metadata synchronized'){
		$t->same(['module_docs','module_index','invalid_json','license_wording','release_hygiene','missing_spdx_headers','other'],$contract['categories']);
		$t->same(['module_index','module_docs','invalid_json','missing_spdx_headers','license_wording','release_hygiene','other'],$contract['repair_order']);
		foreach($contract['contracts'] as $category=>$evidence){
			$t->same($evidence['contract']['priority'],$evidence['priority'],$category.' priority');
			$t->same($evidence['contract']['action'],$evidence['action'],$category.' action');
			$t->same($evidence['contract']['verification'],$evidence['verification'],$category.' verification');
			$t->contains('rerun the external maintainer package check that produced the supplied output',$evidence['verification']);
		}
		$t->startsWith('P1:',$contract['contracts']['module_index']['priority']);
		$t->startsWith('P2:',$contract['contracts']['missing_spdx_headers']['priority']);
		$t->startsWith('P3:',$contract['contracts']['other']['priority']);
		return;
	}

	if($name==='maintainer inspection surfaces remain read-only and internally bounded'){
		$t->same('local_preflight_executed',$contract['release']['execution']);
		$t->isTrue($contract['release']['prediction']['available']);
		$t->isTrue($contract['release']['prediction']['likely_to_deploy']);
		$t->same(0,$contract['triage']['total_failures']);
		$t->same('local_preflight_executed',$contract['triage']['release_check_execution']);
		$t->same('application_preflight_passed',$contract['triage']['release_prediction']['reason_code']);
		$t->same([],$contract['doctor']['coupling_leaks']);
		$t->same([],$contract['doctor']['failed_names']);
		$t->same(0,$contract['doctor']['failed_count']);
		$t->greaterThan(100,$contract['doctor']['check_count']);
		$t->same([],$contract['verify']['failed_results']);
		$t->same([],$contract['verify']['failed_names']);
		$t->same(0,$contract['verify']['failed_count']);
		$t->same(5,$contract['verify']['step_count']);
		$t->same(['php_lint','live_stdio_validation','full_self_test','mcp_doctor','app_coupling_guard'],$contract['verify']['step_names']);
		return;
	}

	$t->contains('MCP live validator not found',$contract['missing_validator']);
	$t->same([
		'dataphyre/runtime/modules/mcp/leaky.php',
		'dataphyre/dev/tools/public/mcp_config.php',
	],$contract['leaks']);
})->with('MCP inspection boundary contracts');
