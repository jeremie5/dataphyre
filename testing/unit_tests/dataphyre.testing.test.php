<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\fixture;
use function Dataphyre\Test\test;

dataset('strict equality shapes', [
	'string'=>['dataphyre', 'dataphyre'],
	'integer'=>[42, 42],
	'array'=>[['tenant'=>'demo_tenant'], ['tenant'=>'demo_tenant']],
]);

fixture('temp_file', static function(): string {
	$dir=sys_get_temp_dir().'/dataphyre-testkit-'.bin2hex(random_bytes(4));
	if(!is_dir($dir)){
		mkdir($dir, 0775, true);
	}
	return $dir.'/sample.txt';
}, static function(?string $path): void {
	if($path!==null && is_file($path)){
		unlink($path);
	}
	if($path!==null && is_dir(dirname($path))){
		rmdir(dirname($path));
	}
});

test('assertions accept dataset rows', static function(Context $t, mixed $actual, mixed $expected): void {
	$t->same($expected, $actual);
	$t->notNull($actual);
})->with('strict equality shapes')->tag('dataset', 'assertion');

test('fixtures are isolated per worker', static function(Context $t): void {
	$path=$t->fixture('temp_file');
	file_put_contents($path, 'ok');
	$t->same('ok', file_get_contents($path));
	$t->matches('/dataphyre-testkit-/', $path);
})->uses('temp_file')->tag('fixture')->maxMillis(1000);

test('throw assertions return the throwable', static function(Context $t): void {
	$throwable=$t->throws(static fn()=>throw new RuntimeException('expected'), RuntimeException::class);
	$t->same('expected', $throwable->getMessage());
})->tag('assertion');

test('expectation chains keep tests compact', static function(Context $t): void {
	$payload=['tenant'=>'demo_tenant', 'plan'=>'enterprise'];
	$t->expect($payload)
		->toHaveKey('tenant')
		->toHaveCount(2);
	$t->expect($payload['tenant'])->toBe('demo_tenant')->toContain('demo');
	$t->expect(strlen($payload['plan']))->toBeGreaterThan(5)->toBeLessThan(20);
})->tag('expectation', 'assertion');

test('common fakes cover app service boundaries', static function(Context $t): void {
	$clock=$t->fakeClock('2026-01-01 00:00:00 UTC')->advance(60);
	$t->same(60, $clock->timestamp()-strtotime('2026-01-01 00:00:00 UTC'));

	$storage=$t->fakeStorage();
	$storage->put('tenant/logo.txt', 'logo');
	$t->same('logo', $storage->get('tenant/logo.txt'));
	$t->expect($storage->files('tenant'))->toHaveCount(1);

	$mailer=$t->fakeMailer();
	$mailer->send('ops@example.test', 'Ready', ['tenant'=>'demo_tenant']);
	$t->same(1, $mailer->count());
	$t->same('Ready', $mailer->last()['subject'] ?? null);

	$http=$t->fakeHttp();
	$http->respond('POST', 'https://example.test/hook', 202, ['ok'=>true]);
	$response=$http->request('POST', 'https://example.test/hook', ['id'=>42]);
	$t->same(202, $response['status']);
	$t->same(1, count($http->requests()));
})->tag('fakes', 'service-boundary');

test('SQL fake can reject unbound writes', static function(Context $t): void {
	$sql=$t->fakeSql()->rejectUnboundWrites();
	$sql->query('select * from products where id = ?', [42]);
	$t->throws(static fn()=>$sql->query('update products set price_minor = 100'), Dataphyre\Test\AssertionFailed::class);
	$sql->assertNoUnboundWrites($t);
})->tag('fakes', 'sql-safety');
