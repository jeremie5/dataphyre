<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\after_all;
use function Dataphyre\Test\before_all;
use function Dataphyre\Test\test;

before_all(static function(): void {
	$GLOBALS['dataphyre_testkit_before_all']='ready';
});

after_all(static function(): void {
	unset($GLOBALS['dataphyre_testkit_before_all']);
});

test('negative numeric string and money assertions are explicit', static function(Context $t): void {
	$t->notSame('shopiro', 'dataphyre');
	$t->notEquals(10, 11);
	$t->expect('dataphyre-core')->not()->toBe('laravel');
	$t->expect('dataphyre-core')->toStartWith('data')->toEndWith('core')->toHaveLength(14);
	$t->expect('billing')->not()->toMatch('/shipping/');
	$t->expect([1, 2, 3])->not()->toBeEmpty();
	$t->greaterThanOrEqual(10, 10);
	$t->lessThanOrEqual(20, 19);
	$t->between(1, 5, 3);
	$t->approximately(1.0, 1.04, 0.05);
	$t->isMinorUnits(1299);
	$t->minorUnits(1299, 1299);
	$t->moneyAmount('12.99', 1299);
})->tag('assertion', 'money');

test('deep paths subsets and exception details are first class', static function(Context $t): void {
	$payload=[
		'tenant'=>'shopiro',
		'items'=>[
			['id'=>42, 'state'=>'active'],
		],
		'meta'=>[
			'currency'=>'CAD',
		],
	];
	$t->hasPath('items[0].id', $payload);
	$t->pathEquals('items.0.state', 'active', $payload);
	$t->missingPath('items.1.id', $payload);
	$t->subset(['meta'=>['currency'=>'CAD']], $payload);
	$t->expect($payload)->toHavePathValue('tenant', 'shopiro')->not()->toHavePath('archived_at');
	$t->throwsLike(static fn()=>throw new RuntimeException('provider refused token', 409), RuntimeException::class, 'token', 409);
	$t->doesNotThrow(static fn()=>true);
})->tag('assertion', 'deep-structure');

test('response panel schema trace and event helpers cover common app surfaces', static function(Context $t): void {
	$response=[
		'status'=>202,
		'headers'=>['Content-Type'=>'application/json'],
		'body'=>json_encode(['ok'=>true, 'data'=>['id'=>42, 'status'=>'active']], JSON_THROW_ON_ERROR),
	];
	$t->responseStatus(202, $response);
	$t->responseHeader('content-type', 'application/json', $response);
	$t->responseJsonPath('data.id', 42, $response);
	$t->responseJsonSubset(['data'=>['status'=>'active']], $response);

	$panel=[
		'fields'=>[['name'=>'name'], ['field'=>'status']],
		'filters'=>[['key'=>'status']],
		'actions'=>[['action'=>'archive']],
	];
	$t->panelHasField($panel, 'name');
	$t->panelHasField($panel, 'status');
	$t->panelHasFilter($panel, 'status');
	$t->panelHasAction($panel, 'archive');

	$t->schemaHasColumn(['columns'=>['id', 'price_minor', 'currency']], 'price_minor');
	$t->queryMatches(['sql'=>'select * from products where id = ?', 'bindings'=>[42]], '/from products/i', [42]);
	$t->traceContains([['type'=>'dialback', 'name'=>'DATAPHYRE_STORAGE_SIGNED_URL', 'passed'=>true]], 'dialback', ['name'=>'DATAPHYRE_STORAGE_SIGNED_URL']);
	$t->eventContains([['name'=>'reactor.dispatched', 'payload'=>['channel'=>'orders']]], 'reactor.dispatched', ['payload'=>['channel'=>'orders']]);
})->tag('assertion', 'surface');

test('fake-specific assertions keep service boundary tests compact', static function(Context $t): void {
	$storage=$t->fakeStorage();
	$storage->put('tenant/logo.txt', 'logo');
	$storage->write('tenant/readme.txt', 'readme');
	$storage->assertStored($t, 'tenant/logo.txt', 'logo');
	$t->same('readme', $storage->read('tenant/readme.txt'));
	$t->same('test-storage://tenant/logo.txt', $storage->url('tenant/logo.txt'));
	$storage->assertMissing($t, 'tenant/missing.txt');

	$mailer=$t->fakeMailer();
	$mailer->send('ops@example.test', 'Ready', ['tenant'=>'shopiro']);
	$mailer->queue('ops@example.test', 'Queued', ['tenant'=>'shopiro']);
	$mailer->assertSent($t, 'ops@example.test', 'Ready', ['tenant'=>'shopiro']);
	$mailer->assertSent($t, 'ops@example.test', 'Queued', ['tenant'=>'shopiro']);
	$mailer->assertSentCount($t, 2);

	$http=$t->fakeHttp();
	$http->respond('POST', 'https://example.test/hook', 202, ['ok'=>true]);
	$http->post('https://example.test/hook', ['id'=>42]);
	$http->assertRequested($t, 'POST', 'https://example.test/hook', ['id'=>42]);
	$http->assertRequestCount($t, 1);

	$auth=$t->fakeAuth(['id'=>42]);
	$auth->assertAuthenticated($t);
	$auth->assertAuthenticatedAs($t, 42);
	$auth->logout();
	$auth->assertGuest($t);

	$sql=$t->fakeSql();
	$sql->query('update products set price_minor = ? where id = ?', [1299, 42]);
	$sql->assertQueried($t, '/update products/i', [1299, 42]);
	$sql->assertQueryCount($t, 1);
	$sql->assertNoUnboundWrites($t);
})->tag('fakes', 'service-boundary');

test('snapshot and lifecycle helpers are worker local', static function(Context $t): void {
	$t->same('ready', $GLOBALS['dataphyre_testkit_before_all'] ?? null);
	$t->snapshot('contract payload', [
		'name'=>'Asset',
		'fields'=>['id', 'name', 'price_minor'],
		'currency'=>'CAD',
	]);
})->tag('snapshot', 'lifecycle');
