<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/kernel/application_runtime_scheduler_protocol.php';

test('managed scheduler accepts only the canonical signed and claim-bound CGI route',static function(Context $t): void {
	$keypair=sodium_crypto_sign_keypair();
	$secret=sodium_crypto_sign_secretkey($keypair);
	$public=sodium_crypto_sign_publickey($keypair);
	$identity=[
		'deployment_application'=>'fixture-app',
		'framework_application'=>'FixtureApp',
		'environment'=>'production',
		'release_id'=>'dep_'.str_repeat('a',40),
	];
	$request=DataphyreApplicationRuntimeSchedulerProtocol::issue(
		'callback',$identity,'gen_'.str_repeat('b',32),7,$secret,
		'fixture.lifecycle','sha256:'.str_repeat('c',64),30000,
		1776073500,str_repeat('d',32),
	);
	$raw=json_encode($request,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	$t->isTrue(DataphyreApplicationRuntimeSchedulerProtocol::matchesCanonicalJson($request,$raw));
	$t->isTrue(DataphyreApplicationRuntimeSchedulerProtocol::verify($request,$public,1776073500));
	$pending=['callback:7'=>$request];
	$t->isTrue(DataphyreApplicationRuntimeSchedulerProtocol::consume($pending,$request,$public,1776073500));
	$t->same([],$pending);
	$t->isFalse(DataphyreApplicationRuntimeSchedulerProtocol::consume($pending,$request,$public,1776073500));

	$runtime=(string)file_get_contents(dirname(__DIR__).'/kernel/runtime.php');
	$router=(string)file_get_contents(dirname(__DIR__).'/kernel/application_runtime_router.php');
	$gateway=(string)file_get_contents(dirname(__DIR__).'/kernel/application_runtime_scheduler_gateway.php');
	$t->isFalse(str_contains($runtime,'boot_internal_runtime_route'));
	$t->isFalse(str_contains($runtime,'scheduler_route_name'));
	$t->isFalse(str_contains($runtime,'/dataphyre/scheduler/'));
	$t->contains("(\$_SERVER['REQUEST_METHOD'] ?? '')!=='POST'",$router);
	$t->contains("'/dataphyre/runtime/scheduler/register'",$router);
	$t->contains("'/dataphyre/runtime/scheduler/callback'",$router);
	$t->contains("'/dataphyre/runtime/scheduler/noop'",$router);
	$t->contains('DataphyreApplicationRuntimeSchedulerProtocol::matchesCanonicalJson',$router);
	$t->contains('DataphyreApplicationRuntimeSchedulerProtocol::verify',$router);
	$t->contains("private const CONTROL_SOCKET='/run/dataphyre/control/runtime.sock'",$gateway);
	$t->contains("stream_socket_client('unix://'.self::CONTROL_SOCKET",$gateway);
	$t->contains('POST /dataphyre/runtime/scheduler/claim HTTP/1.1',$gateway);
	$t->isFalse(str_contains($gateway,'127.0.0.1:8082'));
	$t->isTrue(strpos($gateway,'claimSchedulerRequest($request,$body')<strpos($gateway,'DataphyreApplicationRuntimeProcessBroker::spawn'));
	$t->isFalse(str_contains($router,'/dataphyre/runtime/scheduler/claim'));
	$t->contains('writeCompletedResponse($connection,$schedulerKind,$output',$gateway);
	$t->isFalse(str_contains($router,'HTTP_X_DATAPHYRE_SCHEDULER_KEY'));
})->tag('core','runtime','scheduler','cgi','signature','claim','replay','security');
