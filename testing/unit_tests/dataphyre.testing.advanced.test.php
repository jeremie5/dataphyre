<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\Dataset;
use Dataphyre\Test\Generators;
use function Dataphyre\Test\test;

test('fake database transactions and table assertions cover app persistence contracts', static function(Context $t): void {
	$db=$t->fakeDatabase([
		'orders'=>[
			'id'=>'integer',
			'tenant_id'=>'integer',
			'total_minor'=>'integer',
		],
	]);
	$db->insert('orders', ['id'=>1, 'tenant_id'=>10, 'total_minor'=>1299]);
	$t->tableHas($db, 'orders', ['tenant_id'=>10, 'total_minor'=>1299]);
	$t->tableCount($db, 'orders', 1);
	$db->begin()->insert('orders', ['id'=>2, 'tenant_id'=>10, 'total_minor'=>500])->rollback();
	$t->tableMissing($db, 'orders', ['id'=>2]);
	$db->assertSchemaHasColumn($t, 'orders', 'total_minor');
	$t->same([], $db->diffSchema('orders', [
		'id'=>'integer',
		'tenant_id'=>'integer',
		'total_minor'=>'integer',
	])['missing']);
})->tag('database', 'assertion')->group('advanced')->order(10);

test('pdo database assertions run inside rollback-safe test transactions', static function(Context $t): void {
	if(!in_array('sqlite', PDO::getAvailableDrivers(), true)){
		$t->skip('pdo_sqlite driver is not available.');
	}
	$pdo=new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, total_minor INTEGER NOT NULL)');
	$database=$t->pdoDatabase($pdo);
	$database->transaction(static function($database)use($t): void {
		$database->assertSchemaHasColumn($t, 'orders', 'total_minor');
		$database->assertTableCount($t, 'orders', 0);
		$database->begin();
	});
	$pdo->exec('INSERT INTO orders (id, tenant_id, total_minor) VALUES (1, 10, 1299)');
	$database->assertTableHas($t, 'orders', ['tenant_id'=>10, 'total_minor'=>1299]);
	$database->assertTableMissing($t, 'orders', ['tenant_id'=>11]);
})->tag('database', 'pdo')->group('advanced')->order(20);

test('queue clock hooks reactor and permission fakes stay scoped', static function(Context $t): void {
	$clock=$t->fakeClock('2026-01-01 00:00:00 UTC');
	$queue=$t->fakeQueue($clock);
	$queue->push('sync-product', ['id'=>42], static fn(array $payload): int=>$payload['id']);
	$queue->later(60, 'delayed-billing', ['id'=>99], static fn(array $payload): int=>$payload['id']);
	$queue->assertPushed($t, 'sync-product', ['id'=>42]);
	$t->same(42, $queue->runNext());
	$t->same(null, $queue->runNext());
	$clock->travel(60);
	$t->same(99, $queue->runNext());

	$dialbacks=$t->fakeDialbacks('framework');
	$dialbacks->on('DATAPHYRE_VESTRA_OBJECT_SIGN', static fn(array $payload): string=>'signed-'.$payload['id']);
	$t->same(['signed-7'], $dialbacks->call('dataphyre_vestra_object_sign', ['id'=>7]));
	$dialbacks->assertCalled($t, 'DATAPHYRE_VESTRA_OBJECT_SIGN', 'framework', ['id'=>7]);
	$dialbacks->assertNotCalled($t, 'DATAPHYRE_VESTRA_OBJECT_SIGN', 'app');

	$callbacks=$t->fakeCallbacks('app');
	$callbacks->on('demo_product_saved', static fn(array $payload): int=>$payload['id']);
	$callbacks->dispatch('DEMO_PRODUCT_SAVED', ['id'=>42]);
	$callbacks->assertCalledTimes($t, 'demo_product_saved', 1);

	$reactor=$t->fakeReactor();
	$reactor->listen('product.saved', static fn(array $payload): int=>$payload['id']);
	$reactor->assertListening($t, 'product.saved');
	$t->same([42], $reactor->dispatch('product.saved', ['id'=>42]));
	$reactor->assertDispatched($t, 'product.saved', ['id'=>42]);

	$permissions=$t->fakePermissions()
		->allow('products.update', ['id'=>42], ['id'=>7])
		->deny('products.delete', '*', ['id'=>7]);
	$t->permits($permissions, ['id'=>7], 'products.update', ['id'=>42]);
	$t->denies($permissions, ['id'=>7], 'products.delete', ['id'=>42]);
})->tag('queue', 'hooks', 'reactor', 'permissions')->group('advanced')->order(30);

test('html spies mocks datasets properties and performance helpers are usable together', static function(Context $t): void {
	$html='<main><button id="save" class="primary action" data-state="ready">Save order</button></main>';
	$t->htmlHasSelector($html, 'button#save.primary');
	$t->htmlAttribute($html, '#save', 'data-state', 'ready');
	$t->htmlContainsText($html, 'Save order');
	$t->expect($html)->toHaveHtmlSelector('[data-state=ready]')->toContainHtmlText('Save');

	$spy=$t->spy(static fn(int $value): int=>$value * 2);
	$t->same(8, $spy(4));
	$spy->assertCalledWith($t, [4]);
	$spy->assertCalledTimes($t, 1);

	$mock=$t->mock(['totalMinor'=>static fn(): int=>1299]);
	$t->same(1299, $mock->totalMinor());
	$mock->spy('totalMinor')->assertCalled($t);

	$rows=iterator_to_array(Dataset::matrix([
		'currency'=>['CAD', 'USD'],
		'state'=>['draft', 'paid'],
	]));
	$t->count(4, $rows);
	$t->forAll(Generators::integers(1, 10, 5, 123), static function(Context $t, int $value): void {
		$t->between(1, 10, $value);
	});

	$result=$t->performanceUnder(static function(): void {
		strtolower('DATAPHYRE');
	}, 50, 5);
	$t->greaterThanOrEqual(5, $result->iterations());
})->tag('html', 'spy', 'property', 'performance')->group('advanced')->order(40);

test('dependency source passes', static function(Context $t): void {
	$t->isTrue(true);
})->tag('dependency')->group('advanced')->order(50);

test('dependent code test executes after source', static function(Context $t): void {
	$t->isTrue(true);
})->tag('dependency')->group('advanced')->dependsOn('dependency source passes')->order(60);

test('dependency source dataset rows all pass', static function(Context $t, int $value): void {
	$t->between(1, 2, $value);
})->tag('dependency')->group('advanced')->with([
	'one'=>[1],
	'two'=>[2],
])->order(70);

test('dependency on dataset base waits for all source rows', static function(Context $t): void {
	$t->isTrue(true);
})->tag('dependency')->group('advanced')->dependsOn('dependency source dataset rows all pass')->order(80);
