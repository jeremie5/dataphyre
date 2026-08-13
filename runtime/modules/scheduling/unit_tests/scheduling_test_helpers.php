<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace DataphyreUnitTests;

require_once rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules/scheduling/kernel/scheduling.main.php';
require_once rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules/scheduling/Framework/Period.php';
require_once rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules/scheduling/Framework/ScheduledTask.php';
require_once rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules/scheduling/Framework/Scheduling.php';

function scheduling_name_validation_summary_json(): string {
	return json_encode([
		'plain'=>\dataphyre\scheduling::valid_scheduler_name('daily'),
		'trimmed'=>\dataphyre\scheduling::valid_scheduler_name('  nightly.export-1  '),
		'empty'=>\dataphyre\scheduling::valid_scheduler_name('   '),
		'slash'=>\dataphyre\scheduling::valid_scheduler_name('../escape'),
		'colon'=>\dataphyre\scheduling::valid_scheduler_name('tenant:sync'),
	], JSON_UNESCAPED_SLASHES);
}

function scheduling_task_runner_lifecycle_summary_json(): string {
	\dataphyre\scheduling::end_task_runner();
	$initial=\dataphyre\scheduling::in_task_runner();
	\dataphyre\scheduling::begin_task_runner('worker.1');
	$active=\dataphyre\scheduling::in_task_runner();
	$current=\dataphyre\scheduling::current_scheduler_name();
	\dataphyre\scheduling::begin_task_runner('bad/name');
	$invalid_current=\dataphyre\scheduling::current_scheduler_name();
	\dataphyre\scheduling::end_task_runner();
	return json_encode([
		'initial'=>$initial,
		'active'=>$active,
		'current'=>$current,
		'invalid_current'=>$invalid_current,
		'ended'=>\dataphyre\scheduling::in_task_runner(),
	], JSON_UNESCAPED_SLASHES);
}

function scheduling_path_summary_json(): string {
	$directory=\dataphyre\scheduling::scheduler_directory('  report.run  ');
	return json_encode([
		'directory_suffix'=>str_replace('\\', '/', substr($directory, -strlen('cache/scheduling/report.run/'))),
		'properties_file'=>basename(\dataphyre\scheduling::scheduler_properties_file('report.run')),
		'lock_file'=>basename(\dataphyre\scheduling::running_lock_file('report.run')),
		'last_run_file'=>basename(\dataphyre\scheduling::last_run_file('report.run')),
		'invalid_directory_suffix'=>str_replace('\\', '/', substr(\dataphyre\scheduling::scheduler_directory('../bad'), -strlen('cache/scheduling/'))),
	], JSON_UNESCAPED_SLASHES);
}

function scheduling_read_scheduler_normalizes_properties_json(): string {
	$name='unit_read_scheduler';
	$workspace=\dataphyre_dpanel_worker_workspace::active();
	$workspace->directory('cache/scheduling/'.$name);
	$properties_file=\dataphyre\scheduling::scheduler_properties_file($name);
	$helper_file=realpath(__FILE__) ?: __FILE__;
	$workspace->file('cache/scheduling/'.$name.'/properties.json', json_encode([
		'file_path'=>$helper_file,
		'frequency'=>-25,
		'dependencies'=>[$helper_file, $helper_file],
		'timeout'=>0,
		'memory_limit'=>'',
		'app_override'=>'unit-app',
	], JSON_UNESCAPED_SLASHES));
	$scheduler=\dataphyre\scheduling::read_scheduler($name);
	return json_encode([
		'name'=>$scheduler['name'] ?? null,
		'file_is_helper'=>($scheduler['file_path'] ?? null)===$helper_file,
		'frequency'=>$scheduler['frequency'] ?? null,
		'timeout'=>$scheduler['timeout'] ?? null,
		'memory_limit'=>$scheduler['memory_limit'] ?? null,
		'dependency_count'=>count($scheduler['dependencies'] ?? []),
		'app_override'=>$scheduler['app_override'] ?? null,
	], JSON_UNESCAPED_SLASHES);
}

function scheduling_read_scheduler_rejects_invalid_payloads_json(): string {
	$name='unit_invalid_scheduler';
	$workspace=\dataphyre_dpanel_worker_workspace::active();
	$workspace->directory('cache/scheduling/'.$name);
	$properties_file=\dataphyre\scheduling::scheduler_properties_file($name);
	$workspace->file('cache/scheduling/'.$name.'/properties.json', '{not-json');
	$invalid_json=\dataphyre\scheduling::read_scheduler($name);
	$workspace->file('cache/scheduling/'.$name.'/properties.json', '');
	$empty_file=\dataphyre\scheduling::read_scheduler($name);
	$workspace->removeFile('cache/scheduling/'.$name.'/properties.json');
	return json_encode([
		'invalid_json'=>$invalid_json,
		'empty_file'=>$empty_file,
		'missing_file'=>\dataphyre\scheduling::read_scheduler($name),
		'invalid_name'=>\dataphyre\scheduling::read_scheduler('../bad'),
	], JSON_UNESCAPED_SLASHES);
}

function scheduling_normalize_definition_summary_json(): string {
	$helper_file=realpath(__FILE__) ?: __FILE__;
	$normalized=\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(
		\dataphyre\scheduling::class,
		'normalize_scheduler_definition',
		['unit_normalize', $helper_file, -3.5, 0.25, '', [$helper_file, $helper_file], 'unit-app']
	);
	$missing=\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(
		\dataphyre\scheduling::class,
		'normalize_scheduler_definition',
		['unit_normalize', $helper_file.'.missing', 1.0, 1.0, '64M', [], 'unit-app']
	);
	return json_encode([
		'name'=>$normalized['name'] ?? null,
		'file_is_helper'=>($normalized['file_path'] ?? null)===$helper_file,
		'frequency'=>$normalized['frequency'] ?? null,
		'timeout'=>$normalized['timeout'] ?? null,
		'memory_limit'=>$normalized['memory_limit'] ?? null,
		'dependency_count'=>count($normalized['dependencies'] ?? []),
		'missing_file'=>$missing,
	], JSON_UNESCAPED_SLASHES);
}

function scheduling_dispatch_url_summary_json(): string {
	$original_server=\dataphyre_dpanel_worker_application_state::server();
	\dataphyre_dpanel_worker_application_state::forgetServer('SELF_ADDR');
	\dataphyre_dpanel_worker_application_state::forgetServer('HTTPS');
	\dataphyre_dpanel_worker_application_state::forgetServer('REQUEST_SCHEME');
	$missing=\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(
		\dataphyre\scheduling::class,
		'scheduler_dispatch_url',
		['nightly.export', '']
	);
	\dataphyre_dpanel_worker_application_state::replaceServerValue('SELF_ADDR', 'example.test:8443');
	\dataphyre_dpanel_worker_application_state::replaceServerValue('REQUEST_SCHEME', 'https');
	$https=\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(
		\dataphyre\scheduling::class,
		'scheduler_dispatch_url',
		['nightly.export', '']
	);
	\dataphyre_dpanel_worker_application_state::replaceServer($original_server);
	return json_encode([
		'missing'=>$missing,
		'https'=>$https,
	], JSON_UNESCAPED_SLASHES);
}

function scheduling_framework_period_summary_json(): string {
	return json_encode([
		'numeric'=>\Dataphyre\Scheduling\Period::make(90)->secondsValue(),
		'minutes'=>\Dataphyre\Scheduling\Period::make('5 minutes')->secondsValue(),
		'compact_hours'=>\Dataphyre\Scheduling\Period::make('2h')->secondsValue(),
		'daily'=>\Dataphyre\Scheduling\Period::make('daily')->secondsValue(),
		'date_interval'=>\Dataphyre\Scheduling\Period::make(new \DateInterval('PT45S'))->secondsValue(),
		'unknown'=>\Dataphyre\Scheduling\Period::make('eventually')->secondsValue(),
	], JSON_UNESCAPED_SLASHES);
}

function scheduling_framework_task_definition_summary_json(): string {
	$helper_file=realpath(__FILE__) ?: __FILE__;
	$definition=\Dataphyre\Scheduling\Scheduling::task('framework.cleanup', $helper_file)
		->setPeriod('15 minutes')
		->setTimeout('2 hours')
		->memory('256M')
		->dependency($helper_file)
		->dependency($helper_file)
		->appOverride('unit-app')
		->definition();
	return json_encode([
		'name'=>$definition['name'],
		'file_is_helper'=>$definition['file_path']===$helper_file,
		'frequency'=>$definition['frequency'],
		'timeout'=>$definition['timeout'],
		'memory_limit'=>$definition['memory_limit'],
		'dependency_count'=>count($definition['dependencies']),
		'app_override'=>$definition['app_override'],
	], JSON_UNESCAPED_SLASHES);
}

function scheduling_framework_facade_summary_json(): string {
	return json_encode([
		'valid_name'=>\Dataphyre\Scheduling\Scheduling::validName('framework.cleanup'),
		'invalid_name'=>\Dataphyre\Scheduling\Scheduling::validName('../bad'),
		'period_seconds'=>\Dataphyre\Scheduling\Scheduling::period('weekly')->secondsValue(),
		'in_runner'=>\Dataphyre\Scheduling\Scheduling::inTaskRunner(),
		'current'=>\Dataphyre\Scheduling\Scheduling::current(),
	], JSON_UNESCAPED_SLASHES);
}
