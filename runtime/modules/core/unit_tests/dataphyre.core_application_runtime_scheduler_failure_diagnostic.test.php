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

final class DataphyreSchedulerContinuousDiagnosticStream
{
	public mixed $context=null;
	public function stream_open(string $path,string $mode,int $options,?string &$openedPath): bool {return true;}
	public function stream_read(int $count): string {return str_repeat('x',max(1,min($count,8192)));}
	public function stream_eof(): bool {return false;}
	/** @return array<string,int> */
	public function stream_stat(): array {return [];}
}

suite('Managed scheduler private failure diagnostics')
	->contract('core.application-runtime-scheduler-failure-diagnostic',1)
	->layer('integration')->risk('critical')->watches('module:core','module:scheduling')
	->isolation('case')->tag('core','runtime','scheduler','diagnostic','security')
	->group('framework-coverage');

test('callback throwable diagnostics disclose only an allowlisted phase and short class',static function(Context $t): void {
	require_once dirname(__DIR__).'/kernel/application_runtime_scheduler_protocol.php';
	$safe=DataphyreApplicationRuntimeSchedulerFailureDiagnostic::fromThrowable(
		'task_execution',new RuntimeException('change_control_retention_task_failed:change_control_retention_partial_failure'),
	);
	$t->same([
		'failure_phase'=>'task_execution',
		'exception_class'=>'RuntimeException',
	],$safe);
	foreach([
		'/workspace/applications/tenant/tasks/run.php',
		'password=correct-horse-battery-staple',
		'password123',
		'token123456',
		'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature',
		'SELECT * FROM private_table WHERE token = 123',
		"RuntimeException: failed\n#0 /private/path.php(12): call()",
		str_repeat('a',64),
	] as $unsafe){
		$diagnostic=DataphyreApplicationRuntimeSchedulerFailureDiagnostic::fromThrowable(
			'task_execution',new RuntimeException($unsafe),
		);
		$t->same($safe,$diagnostic);
	}
	$anonymous=new class('safe_failure') extends RuntimeException {};
	$t->same(
		'Throwable',
		DataphyreApplicationRuntimeSchedulerFailureDiagnostic::fromThrowable('task_execution',$anonymous)['exception_class'],
	);
	$transport=DataphyreApplicationRuntimeSchedulerFailureDiagnostic::encodeChild($safe);
	$t->lessThan(1025,strlen($transport));
	$t->same($safe,DataphyreApplicationRuntimeSchedulerFailureDiagnostic::decodeChild($transport));
	$t->isFalse(str_contains($transport,'change_control_retention'));
	$t->isFalse(str_contains($transport,'password123'));
	$t->isNull(DataphyreApplicationRuntimeSchedulerFailureDiagnostic::decodeChild("tenant stderr ignored\n".$transport));
	$t->isNull(DataphyreApplicationRuntimeSchedulerFailureDiagnostic::decodeChild($transport.$transport));
})->tag('throwable','redaction','no-message','bounds','child-transport');

test('root gateway records one bounded exit or timeout line without raw child bytes',static function(Context $t): void {
	require_once dirname(__DIR__).'/kernel/application_runtime_scheduler_gateway.php';
	$gateway=$t->nonPublic(DataphyreApplicationRuntimeSchedulerGateway::class);
	$interruptedLines=[];
	$gateway->invoke(
		'reportRequestFailure','fixture.retention','gateway_wait','exception',null,
		new DataphyreApplicationRuntimeSchedulerGatewayInterrupted('Scheduler handler interrupted.'),null,
		static function(string $line) use (&$interruptedLines): void {$interruptedLines[]=$line;},
	);
	$t->same([],$interruptedLines,'graceful gateway interruption is not a callback failure');
	$lines=[];
	$gateway->invoke(
		'reportRequestFailure','fixture.retention','gateway_timeout','timeout',null,
		new RuntimeException('/private/runtime/path?token=unsafe'),null,
		static function(string $line) use (&$lines): void {$lines[]=$line;},
	);
	$t->count(1,$lines);
	$t->lessThan(1025,strlen($lines[0]));
	$t->isFalse(str_contains($lines[0],'/private/runtime/path'));
	$t->isFalse(str_contains($lines[0],'token=unsafe'));
	$prefix='Dataphyre managed scheduler failure ';
	$t->isTrue(str_starts_with($lines[0],$prefix));
	$timeout=json_decode(trim(substr($lines[0],strlen($prefix))),true,8,JSON_THROW_ON_ERROR);
	$t->same([
		'contract'=>'dataphyre.internal_scheduler_failure.v1',
		'task_name'=>'fixture.retention',
		'failure_phase'=>'gateway_timeout',
		'failure_kind'=>'timeout',
		'exit_code'=>null,
		'gateway_exception_class'=>'RuntimeException',
		'application_reported_phase'=>null,
		'application_reported_exception_class'=>null,
		'message'=>'Managed scheduler callback exceeded its fixed wall-clock budget.',
	],$timeout);

	$child=DataphyreApplicationRuntimeSchedulerFailureDiagnostic::fromThrowable(
		'task_execution',new RuntimeException('password123'),
	);
	$lines=[];
	$gateway->invoke(
		'reportFailure','fixture.retention','router_exit','exit',75,
		new RuntimeException('Application scheduler process failed.'),$child,
		static function(string $line) use (&$lines): void {$lines[]=$line;},
	);
	$exit=json_decode(trim(substr($lines[0],strlen($prefix))),true,8,JSON_THROW_ON_ERROR);
	$t->same('fixture.retention',$exit['task_name']);
	$t->same('router_exit',$exit['failure_phase']);
	$t->same('exit',$exit['failure_kind']);
	$t->same(75,$exit['exit_code']);
	$t->same('RuntimeException',$exit['gateway_exception_class']);
	$t->same('task_execution',$exit['application_reported_phase']);
	$t->same('RuntimeException',$exit['application_reported_exception_class']);
	$t->same('Managed scheduler callback process exited unsuccessfully.',$exit['message']);
	$t->isFalse(str_contains($lines[0],'password123'));

	$forged=[
		'failure_phase'=>'task_cleanup','exception_class'=>'RuntimeException',
	];
	$record=DataphyreApplicationRuntimeSchedulerFailureDiagnostic::logRecord(
		'fixture.retention','router_exit','exit',75,new RuntimeException('ignored'),$forged,
	);
	$t->same('router_exit',$record['failure_phase']);
	$t->same('exit',$record['failure_kind']);
	$t->same('task_cleanup',$record['application_reported_phase']);
	$t->same('RuntimeException',$record['application_reported_exception_class']);
	$t->same('Managed scheduler callback process exited unsuccessfully.',$record['message']);

	$diagnosticStream=fopen('php://temp','w+b');
	fwrite($diagnosticStream,str_repeat('x',9000));rewind($diagnosticStream);
	$diagnosticDrain=$gateway->capture(
		'drainSchedulerStream',$diagnosticStream,'',8192,false,false,hrtime(true)+1_000_000_000,
	);
	$t->isTrue($diagnosticDrain->result());
	$t->same(8192,strlen($diagnosticDrain->argument('buffer')));
	$t->isTrue($diagnosticDrain->argument('overflow'));fclose($diagnosticStream);
	$responseStream=fopen('php://temp','w+b');
	fwrite($responseStream,str_repeat('x',9));rewind($responseStream);
	$t->throws(
		static fn()=>$gateway->capture(
			'drainSchedulerStream',$responseStream,'',8,true,false,hrtime(true)+1_000_000_000,
		),
		RuntimeException::class,
	);
	fclose($responseStream);

	$scheme='dataphyre-scheduler-continuous-diagnostic';
	$t->isTrue(stream_wrapper_register($scheme,DataphyreSchedulerContinuousDiagnosticStream::class));
	try{
		$continuous=fopen($scheme.'://stderr','rb');
		$started=hrtime(true);
		$continuousDrain=$gateway->capture(
			'drainSchedulerStream',$continuous,'',128,false,false,$started+25_000_000,
		);
		$elapsedMilliseconds=(hrtime(true)-$started)/1_000_000;
		$t->isTrue($continuousDrain->result());
		$t->same(128,strlen($continuousDrain->argument('buffer')));
		$t->isTrue($continuousDrain->argument('overflow'));
		$t->lessThan(100.0,$elapsedMilliseconds);
		fclose($continuous);
	}finally{$t->isTrue(stream_wrapper_unregister($scheme));}
})->tag('root-gateway','stderr','timeout','exit','redaction','bounds');

test('router and gateway retain diagnostics only across their private stderr boundary',static function(Context $t): void {
	$core=dirname(__DIR__).'/kernel';
	$router=(string)file_get_contents($core.'/application_runtime_router.php');
		$gateway=(string)file_get_contents($core.'/application_runtime_scheduler_gateway.php');
		$protocol=(string)file_get_contents($core.'/application_runtime_scheduler_protocol.php');
	$runner=(string)file_get_contents(dirname(__DIR__,2).'/scheduling/kernel/task_runner.php');
	$t->contains('DataphyreApplicationRuntimeSchedulerFailureDiagnostic::fromThrowable',$router);
	$t->contains("fopen('php://stderr','wb')",$router);
	$t->contains('DataphyreApplicationRuntimeSchedulerFailureDiagnostic::encodeChild',$router);
	$t->contains("2=>['pipe','w']",$gateway);
	$t->contains('MAX_SCHEDULER_DIAGNOSTIC_BYTES=8192',$gateway);
		$t->contains('DataphyreApplicationRuntimeSchedulerFailureDiagnostic::decodeChild',$gateway);
		$t->contains('if($exitCode===75 && !$diagnosticOverflow)',$gateway);
		$t->contains('hrtime(true)<$deadline',$gateway);
		$t->contains('DataphyreApplicationRuntimeSchedulerFailureDiagnostic::encodeLog',$gateway);
	$t->contains("\$failurePhase='gateway_timeout';\$failureKind='timeout'",$gateway);
	$t->contains("\$failurePhase='router_exit';\$failureKind='exit'",$gateway);
	$t->contains('self::reportRequestFailure(',$gateway);
	$t->contains('instanceof DataphyreApplicationRuntimeSchedulerGatewayInterrupted',$gateway);
		$t->contains('reportManagedFailure($failure_reporter,$failure_phase,$failure)',$runner);
		$t->contains("'application_reported_phase'=>",$protocol);
		$t->contains("'application_reported_exception_class'=>",$protocol);
		$t->isFalse(str_contains($protocol,'sanitizeThrowableMessage'));
		$t->isFalse(str_contains($protocol,'$failure->getMessage()'));
		$t->isFalse(str_contains($gateway,'writeCompletedResponse($connection,$schedulerKind,$diagnosticOutput'));
	$t->isFalse(str_contains($router,'echo $failureDiagnostic'));
})->tag('router','gateway','transport','no-public-status','source-contract');
