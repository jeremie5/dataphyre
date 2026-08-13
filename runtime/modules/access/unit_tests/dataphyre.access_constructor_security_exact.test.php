<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!defined('DP_ACCESS_CFG')){
	define('DP_ACCESS_CFG', [
		'sanction_on_useragent_change'=>true,
		'sessions_table_name'=>'dataphyre.sessions',
		'sessions_cookie_name'=>'DPID',
		'auth_types'=>['session','jwt','custom-auth'],
		'default_auth_type'=>'session',
		'botlist'=>['Googlebot','bingbot'],
		'identity'=>['tokens_table'=>'dataphyre.access_tokens'],
	]);
}
require_once __DIR__.'/access_session_test_helpers.php';

suite('Access constructor security transitions')
	->contract('access.constructor-security', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:access')
	->through('user-agent-sanction', 'session-recovery', 'fail-closed')
	->isolation('case')
	->tag('access', 'exact-coverage', 'constructor')
	->group('framework-coverage');

test('constructor sanctions a changed user agent and records firewall escalation', static function(Context $t): void {
	DpAccessSessionWorkerScenario::begin([
		'dp_access'=>['previous_useragent'=>'Old Browser'],
	], [], ['HTTP_USER_AGENT'=>'Current Browser']);
	DpAccessSessionWorkerScenario::module('firewall');
	$access=new \dataphyre\access();
	$t->instanceOf(\dataphyre\access::class, $access);
	$t->isTrue(\dataphyre\access::$useragent_mismatch);
	$t->isTrue(DpAccessSessionWorkerScenario::accessValue('minimum_security_alert'));
	$t->same(REQUEST_USER_AGENT, DpAccessSessionWorkerScenario::accessValue('previous_useragent'));
	$t->same(['useragent_mismatch'], DpAccessSessionWorkerScenario::firewallReasons());
});

test('constructor fails closed when validation recovery and revocation all reject an active identity', static function(Context $t): void {
	DpAccessSessionWorkerScenario::begin(DpAccessSessionWorkerScenario::authenticatedSession(91));
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_VALIDATE_SESSION', false);
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_RECOVER_SESSION', false);
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_DISABLE_SESSION', false);
	$t->throws(static fn()=>new \dataphyre\access(), RuntimeException::class);
	$t->same(1, count(\dataphyre\core::accessScenarioUnavailableCalls()));
});
