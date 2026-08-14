<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Application runtime probe state exact durability')
	->contract('core.application-runtime-probe-state-exact',1)
	->layer('integration')->risk('critical')->watches('module:core')
	->isolation('case')->tag('core','runtime','probe','durability','filesystem','root')
	->group('framework-coverage');

test('probe state is exact root-owned durable and rejects identity or filesystem substitution',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$kernel=dirname(__DIR__).'/kernel';
	$result=$t->coveredPhpFixture(
		__DIR__.'/fixtures/application_runtime_probe_state_boundary.php',
		[$kernel],timeout_millis:15000,framework_root:$frameworkRoot,
	);
	$t->processSucceeded($result,$result->stderr());
	$payload=$result->json();
	$runningAsRoot=function_exists('posix_geteuid') && posix_geteuid()===0;
	if(!$runningAsRoot){$t->same(false,$payload['supported']);return;}
	$t->same(true,$payload['supported']);
	$t->same('dataphyre.scheduler_noop_probe.v1',$payload['first']['contract']);
	$t->same(1,$payload['first']['count']);
	$t->same('2026-04-13T09:45:00Z',$payload['first']['last_at']);
	$t->same(false,$payload['first']['previous_readback']);
	$t->matches('/^sha256:[a-f0-9]{64}$/D',$payload['first']['state_identity_sha256']);
	$t->same('dataphyre.scheduler_noop_probe.v1',$payload['second']['contract']);
	$t->same(2,$payload['second']['count']);
	$t->same('2026-04-13T09:45:01Z',$payload['second']['last_at']);
	$t->same(true,$payload['second']['previous_readback']);
	$t->same($payload['first']['state_identity_sha256'],$payload['second']['state_identity_sha256']);
	foreach([
		'identity_change_rejected','wrong_mode_rejected','hardlink_rejected','invalid_json_rejected',
		'invalid_contract_rejected','noncanonical_rejected','link_rejected','directory_mode_rejected','cleaned',
	] as $field) $t->same(true,$payload[$field],$field);
	$t->same(0600,$payload['file_mode']);
	$t->same(0,$payload['file_uid']);
	$t->same(0,$payload['file_gid']);
})->tag('positive','negative','identity','canonical-json','substitution');

test('probe write failure cleans its exact owned temporary file',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$kernel=dirname(__DIR__).'/kernel';
	$result=$t->coveredPhpFixture(
		__DIR__.'/fixtures/application_runtime_probe_state_boundary.php',
		[$kernel,'sync-unavailable'],timeout_millis:15000,framework_root:$frameworkRoot,
		php_ini:['disable_functions'=>'fsync'],
	);
	$t->processSucceeded($result,$result->stderr());
	$payload=$result->json();
	$runningAsRoot=function_exists('posix_geteuid') && posix_geteuid()===0;
	if(!$runningAsRoot){$t->same(false,$payload['supported']);return;}
	$t->same([
		'supported'=>true,'failure_class'=>RuntimeException::class,
		'failure_message'=>'Scheduler probe state write failed.',
		'temporary_count'=>0,'cleaned'=>true,
	],$payload);
})->tag('negative','fsync','cleanup');
