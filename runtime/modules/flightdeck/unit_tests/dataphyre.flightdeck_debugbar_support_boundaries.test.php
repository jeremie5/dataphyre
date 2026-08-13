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

require_once __DIR__.'/fixtures/flightdeck_support_headers_probe.php';
require_once dirname(__DIR__).'/kernel/debugbar/support.php';

final class DpFlightdeckDebugbarSupportHarness {
	private const COOKIE='dataphyre_flightdeck_debugbar_support_probe';
	use dataphyre_flightdeck_debugbar_support;
}

suite('Flightdeck debugbar support boundaries')
	->tag('flightdeck','debugbar','support','cookies','coverage')
	->group('framework-coverage')
	->contract('flightdeck.debugbar.support-boundaries',1)
	->layer('unit')
	->risk('high')
	->watches('module:flightdeck')
	->through('memory limits','header adapters','redaction bounds','signed cookies','replay secrets')
	->isolation('process');

test('support helpers fail closed across host limits malformed tokens and partial bootstrap state',static function(Context $t): void {
	$support=$t->nonPublic(DpFlightdeckDebugbarSupportHarness::class);

	$t->phpIni(['memory_limit'=>-1]);
	$t->same(PHP_INT_MAX,$support->invoke('memory_remaining_bytes'));
	$t->isTrue($support->invoke('has_memory_headroom',PHP_INT_MAX,PHP_INT_MAX));
	$t->hasPathValues([
		'X-Flightdeck-Probe'=>'ready',
		'Authorization'=>'Bearer hidden',
	],$support->invoke('request_headers'));

	$t->same('[depth-limit]',$support->invoke('sanitize_value','deep','',5));
	$largePayload=array_combine(range(1,41),range(1,41));
	$sanitized=$support->invoke('sanitize_value',$largePayload);
	$t->same('truncated',$sanitized['...']);
	$resource=fopen('php://memory','rb');
	$t->contains('resource',$support->invoke('sanitize_value',$resource));
	fclose($resource);

	$secret=$support->invoke('secret');
	$t->same(64,strlen($support->invoke('secret_for_app','shop')));
	$future=$support->invoke('base64url_encode',json_encode(['exp'=>time()+300],JSON_THROW_ON_ERROR));
	$expired=$support->invoke('base64url_encode',json_encode(['exp'=>time()-1],JSON_THROW_ON_ERROR));
	$invalidJson=$support->invoke('base64url_encode','not-json');
	$t->same([
		'empty'=>false,
		'one_part'=>false,
		'wrong_signature'=>false,
		'expired'=>false,
		'invalid_json'=>false,
		'valid'=>true,
	],$support->invokeCases([
		'empty'=>['method'=>'verify_cookie','arguments'=>['']],
		'one_part'=>['method'=>'verify_cookie','arguments'=>['one-part']],
		'wrong_signature'=>['method'=>'verify_cookie','arguments'=>[$future.'.wrong']],
		'expired'=>['method'=>'verify_cookie','arguments'=>[$expired.'.'.hash_hmac('sha256',$expired,$secret)]],
		'invalid_json'=>['method'=>'verify_cookie','arguments'=>[$invalidJson.'.'.hash_hmac('sha256',$invalidJson,$secret)]],
		'valid'=>['method'=>'verify_cookie','arguments'=>[$future.'.'.hash_hmac('sha256',$future,$secret)]],
	]));
	$t->same('payload',$support->invoke('base64url_decode',$support->invoke('base64url_encode','payload')));
	$t->same('',$support->invoke('base64url_encode',false));
	$t->same('',$support->invoke('base64url_decode','!'));

	$bootstrap=$t->global('dataphyre_bootstrap_config');
	$bootstrap->replace('not-an-array');
	$t->same('',$support->invoke('replay_secret'));
	$bootstrap->replace(['flightdeck'=>'not-an-array']);
	$t->same('',$support->invoke('replay_secret'));
	$bootstrap->replace([
		'flightdeck'=>['password'=>'global-secret'],
		'license'=>['key'=>'global-license'],
	]);
	$t->same(64,strlen($support->invoke('replay_secret')));

	$t->isFalse(defined('APP'));
	$t->isFalse(defined('DATAPHYRE_BOOTSTRAP_CONFIG'));
	define('APP','support-app');
	define('DATAPHYRE_BOOTSTRAP_CONFIG',[
		'app'=>'bootstrap-app',
		'flightdeck'=>['password_hash'=>'bootstrap-hash'],
		'license'=>['key'=>'bootstrap-license'],
	]);
	if(!defined('DATAPHYRE_PROJECT_ROOT')){
		define('DATAPHYRE_PROJECT_ROOT','/workspace/support-project');
	}
	$t->containsAll(['support-app','bootstrap-app'],$support->invoke('legacy_secret_apps'));
	$t->same(64,strlen($support->invoke('replay_secret')));

	$cookies=$t->globalMap('_COOKIE')->replace([]);
	$t->globalMap('_SERVER')->replace(['HTTP_X_FORWARDED_PROTO'=>'https']);
	$support->invoke('set_cookie','signed-cookie',time()+300);
	$t->same('signed-cookie',$cookies->get('dataphyre_flightdeck_debugbar_support_probe'));

	include dirname(__DIR__).'/kernel/debugbar/support.php';
});
