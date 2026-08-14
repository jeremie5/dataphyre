<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['core','http','routing','sql','mvc','api','scheduling','stripe']);

if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
function tracelog(mixed ...$arguments): void {}
if(!defined('DATAPHYRE_RUNTIME_CAPABILITY_ROUTING_TEST_STUB_LOADED')){
	define('DATAPHYRE_RUNTIME_CAPABILITY_ROUTING_TEST_STUB_LOADED', true);
	final class routing {
		public static array $bindings=[];
		public static function valid_scheduler_name(string $name): bool { return $name!==''; }
		public static function scheduler_route(string $name): string { return '/scheduler/'.$name; }
	}
}
PHP);
}
require_once __DIR__.'/../../stripe/kernel/stripe.account_client.php';
require_once __DIR__.'/../../scheduling/kernel/scheduling.main.php';
if(!defined('DATAPHYRE_SCHEDULING_TASK_RUNNER_NO_DISPATCH')){
	define('DATAPHYRE_SCHEDULING_TASK_RUNNER_NO_DISPATCH', true);
}
require_once __DIR__.'/../../scheduling/kernel/task_runner.php';
require_once __DIR__.'/../kernel/runtime.php';
require_once __DIR__.'/../kernel/application_runtime_scheduler_protocol.php';

test('runtime capability contracts expose application-neutral framework surfaces', static function(Context $t): void {
	$required=[
		'Dataphyre\\Database\\TableDefinition'=>['columnDefinitions'],
		'Dataphyre\\Mvc\\RouteDefinition'=>['api','apiMetadata'],
		'Dataphyre\\Mvc\\MvcDispatcher'=>['authorizeApiRoute','executeApiRoute'],
		'dataphyre\\stripe_account_client'=>['readiness','construct_webhook_event'],
		'DataphyreApplicationRuntimeSchedulerProtocol'=>['issue','verify','matchesCanonicalJson','consume'],
		'dataphyre\\scheduling'=>['use_state_root','acquire_running_lock'],
		'dataphyre_scheduling_task_runner'=>['claimRunningLock'],
	];
	foreach($required as $class=>$methods){
		$t->same(true, class_exists($class, true), $class.' must be available');
		foreach($methods as $method){
			$t->same(true, method_exists($class, $method), $class.'::'.$method.' must be available');
		}
	}
})->tag('core','runtime','capability','contract','unit');
