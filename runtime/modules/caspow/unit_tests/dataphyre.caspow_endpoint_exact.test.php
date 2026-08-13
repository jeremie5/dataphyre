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

if(!defined('DATAPHYRE_CASPOW_ENDPOINT_NO_DISPATCH')){
	define('DATAPHYRE_CASPOW_ENDPOINT_NO_DISPATCH', true);
}
require_once dirname(__DIR__, 2).'/dpanel/tooling/WorkerFixtureState.php';
require_once __DIR__.'/caspow_test_helpers.php';
require_once dirname(__DIR__).'/kernel/endpoint.php';

suite('CASPoW composable endpoint')
	->contract('caspow.http-endpoint', 1)
	->layer('integration')
	->risk('high')
	->watches('module:caspow')
	->through('bootstrap', 'creation', 'verification', 'method-policy', 'json')
	->isolation('case')
	->tag('caspow', 'exact-coverage', 'endpoint')
	->group('framework-coverage');

test('endpoint bootstrap captures request response and runtime boundaries without terminating the worker', static function(Context $t): void {
	$t->same(null, dataphyre_caspow_endpoint::bootstrap());
	$bootstrap=$t->spy();
	$emit=$t->spy();
	$response=dataphyre_caspow_endpoint::bootstrap(true, [
		'bootstrap'=>$bootstrap,
		'method'=>'POST',
		'action'=>'create',
		'query'=>['scope'=>'query-scope'],
		'body'=>'{"scope":"body-scope","capabilities":{"device_memory":4}}',
		'create'=>static fn(mixed $scope, array $capabilities): array=>['scope'=>$scope, 'capabilities'=>$capabilities],
		'emit'=>$emit,
	]);
	$t->same(200, $response['status']);
	$t->same('body-scope', $response['payload']['scope']);
	$bootstrap->assertCalledTimes($t, 1);
	$emit->assertCalledWith($t, [$response['payload'], 200]);
});

test('endpoint dispatch makes method errors and unknown actions self-describing', static function(Context $t): void {
	$t->same(['status'=>405, 'payload'=>['error'=>'Method not allowed']], dataphyre_caspow_endpoint::dispatch('DELETE', 'create'));
	$t->same(['status'=>405, 'payload'=>['error'=>'Method not allowed']], dataphyre_caspow_endpoint::dispatch('GET', 'verify'));
	$t->same(['status'=>404, 'payload'=>['error'=>'Endpoint not found']], dataphyre_caspow_endpoint::dispatch('GET', 'missing'));
	$t->same([], dataphyre_caspow_endpoint::readJsonRequest(''));
	$t->same([], dataphyre_caspow_endpoint::readJsonRequest('not-json'));
	$t->same(['value'=>1], dataphyre_caspow_endpoint::readJsonRequest('{"value":1}'));
});

test('endpoint defaults create and verify real signed CASPoW protocol payloads', static function(Context $t): void {
	$created=dataphyre_caspow_endpoint::dispatch('GET', 'create', ['scope'=>'checkout']);
	$t->same(200, $created['status']);
	$t->same('checkout', $created['payload']['scope']);
	$payload=dp_caspow_solved_payload();
	$verified=dataphyre_caspow_endpoint::dispatch('POST', 'verify', [], (string)json_encode(['payload'=>$payload]));
	$t->same(['valid'=>true], $verified['payload']);
	$raw=dataphyre_caspow_endpoint::dispatch('POST', 'verify', [], $payload, ['verify'=>static fn(mixed $value): bool=>$value===$payload]);
	$t->same(['valid'=>true], $raw['payload']);
});
