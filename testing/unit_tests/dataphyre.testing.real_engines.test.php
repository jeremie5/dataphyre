<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\Generators;
use function Dataphyre\Test\test;

test('real browser worker renders html checks accessibility and writes screenshot', static function(Context $t): void {
	$html='<html lang="en"><head><title>Dataphyre Browser Probe</title></head><body><main><button id="save" aria-label="Save order">Save</button><p data-state="ready">Ready</p></main></body></html>';
	$result=$t->browser()->assertHtml($t, $html, [
		'expect_selectors'=>['#save', '[data-state=ready]'],
		'expect_text'=>['Save', 'Ready'],
		'assert_a11y'=>true,
		'screenshot_path'=>'cache/ci/dataphyre-testing-browser-proof.png',
	]);
	$t->pathEquals('browser.engine', 'chromium', $result);
	$t->pathEquals('title', 'Dataphyre Browser Probe', $result);
	$t->pathEquals('a11y_issues', [], $result);
	$t->hasPath('screenshot.sha256', $result);
})->tag('browser', 'real-engine')->group('real-engine')->maxMillis(8000);

test('real browser worker can run axe accessibility rules', static function(Context $t): void {
	$html='<html lang="en"><head><title>Axe Probe</title></head><body><main><h1>Checkout</h1><label for="email">Email</label><input id="email" type="email" autocomplete="email"><button type="button">Save</button></main></body></html>';
	$result=$t->browser()->assertHtml($t, $html, [
		'expect_selectors'=>['h1', '#email'],
		'assert_axe'=>true,
		'axe_tags'=>['wcag2a', 'wcag2aa'],
		'axe_min_impact'=>'serious',
	]);
	$t->pathEquals('axe.skipped', false, $result);
	$t->pathEquals('a11y_issues', [], $result);
	$t->hasPath('axe.summary.passes', $result);
})->tag('browser', 'a11y', 'axe', 'real-engine')->group('real-engine')->maxMillis(10000);

test('real browser worker creates and verifies exact visual baselines', static function(Context $t): void {
	$html='<html lang="en"><head><title>Dataphyre Visual Probe</title><style>body{margin:0;background:#fff}.box{width:160px;height:80px;font:16px Arial,sans-serif;color:#111;display:grid;place-items:center;border:2px solid #111}</style></head><body><main class="box">Visual OK</main></body></html>';
	$options=[
		'expect_selectors'=>['.box'],
		'expect_text'=>['Visual OK'],
		'viewport'=>['width'=>180, 'height'=>100],
		'full_page'=>false,
	];
	$updated=$t->browser()->visualSnapshot($t, $html, 'visual-proof', true, $options);
	$t->pathEquals('screenshot.matches_baseline', true, $updated);
	$t->hasPath('screenshot.baseline_path', $updated);
	$verified=$t->browser()->visualSnapshot($t, $html, 'visual-proof', false, $options);
	$t->pathEquals('screenshot.matches_baseline', true, $verified);
})->tag('browser', 'visual', 'real-engine')->group('real-engine')->maxMillis(10000);

test('real browser worker supports visual diff tolerances and artifacts', static function(Context $t): void {
	$baseline='<html lang="en"><head><title>Dataphyre Diff Probe</title><style>body{margin:0;background:#fff}.box{width:120px;height:64px;background:#f8f8f8;color:#0a0a0a;border:2px solid #0a0a0a}</style></head><body><main class="box"></main></body></html>';
	$actual='<html lang="en"><head><title>Dataphyre Diff Probe</title><style>body{margin:0;background:#fff}.box{width:120px;height:64px;background:#f8f8f8;color:#0b0a0a;border:2px solid #0b0a0a}</style></head><body><main class="box"></main></body></html>';
	$options=[
		'expect_selectors'=>['.box'],
		'viewport'=>['width'=>140, 'height'=>84],
		'full_page'=>false,
		'visual_pixel_threshold'=>1,
		'visual_max_diff_pixels'=>0,
		'visual_max_diff_ratio'=>0,
	];
	$t->browser()->visualSnapshot($t, $baseline, 'visual-diff-proof', true, $options);
	$verified=$t->browser()->visualSnapshot($t, $actual, 'visual-diff-proof', false, $options);
	$t->pathEquals('screenshot.matches_baseline', true, $verified);
	$t->pathEquals('screenshot.visual_diff.diff_pixels', 0, $verified);
	$t->hasPath('screenshot.visual_diff.diff_path', $verified);
})->tag('browser', 'visual', 'real-engine')->group('real-engine')->maxMillis(10000);

test('fuzz generated cases are seed replayable and shrinkable', static function(Context $t): void {
	$t->fuzz(Generators::fuzzIntegers(1, 100, 16, 20260706), static function(Context $t, int $value): void {
		$t->between(1, 100, $value);
	});
})->tag('fuzz', 'property')->group('real-engine');

test('function patches and static proxies cover PHP-permitted call seams', static function(Context $t): void {
	$function='Dataphyre\\Testing\\GeneratedPatch'.bin2hex(random_bytes(4)).'\\token_value';
	$spy=$t->functionPatch($function, static fn(string $prefix): string=>$prefix.'-patched');
	$t->same('unit-patched', $function('unit'));
	$spy->assertCalledWith($t, ['unit']);

	$proxy=$t->staticProxy(DateTimeImmutable::class);
	$now=$proxy->call('createFromFormat', 'Y-m-d', '2026-07-06');
	$t->instanceOf(DateTimeImmutable::class, $now);
	$proxy->spy('createFromFormat')->assertCalled($t);
})->tag('mocking', 'function-patch', 'static-proxy')->group('real-engine');

test('dataphyre storage bridge uses the real memory driver and event surface', static function(Context $t): void {
	$manager=$t->dataphyreModules()->storage();
	$events=$t->dataphyreModules()->storageEvents($manager);
	$t->isTrue($manager->put('tenant/product.txt', 'ready'));
	$t->same('ready', $manager->get('tenant/product.txt'));
	$t->isTrue($manager->exists('tenant/product.txt'));
	$events->assertRecorded($t, 'storage.write', ['path'=>'tenant/product.txt', 'ok'=>true]);
	$events->assertRecorded($t, 'storage.read', ['path'=>'tenant/product.txt', 'ok'=>true]);
	$t->hasPath(['tenant/product.txt'], $manager->fakeSnapshot());
})->tag('storage', 'real-module')->group('real-engine');

test('dataphyre sql bridge uses real framework query and schema surfaces', static function(Context $t): void {
	$sql=$t->dataphyreModules()->sqlFramework();
	$compiled=$sql->querySpec()
		->whereEq('tenant_id', 7)
		->whereIn('status', ['paid', 'open'])
		->requireWhereForWrite()
		->compile(false);
	$t->contains('tenant_id = ?', $compiled['params']);
	$t->contains('status IN (?, ?)', $compiled['params']);
	$t->same([7, 'paid', 'open'], $compiled['vars']);

	$schema=$sql->schema('orders', ['id', 'tenant_id', 'total_minor', 'metadata'], [
		'listing'=>['id', 'total_minor'],
	], 'id', [
		'id'=>'int',
		'total_minor'=>'int',
		'metadata'=>'json',
	]);
	$t->same(['id', 'total_minor'], $schema->projection('listing'));
	$t->same(['tenant_id'=>7, 'total_minor'=>1299, 'metadata'=>'{"source":"unit"}'], $schema->fields([
		'tenant_id'=>7,
		'total_minor'=>'1299',
		'metadata'=>['source'=>'unit'],
	]));
	$t->pathEquals('total_minor', 1299, $schema->castRow(['total_minor'=>'1299']));

	$definition=$sql->definition('tenant_orders')
		->integer('id')->primary()
		->integer('tenant_id')->notNull()
		->integer('total_minor')->notNull()
		->projection('listing', ['id', 'total_minor']);
	$t->pathEquals('schema_primary_key', 'id', [
		'schema_primary_key'=>$definition->schema()->primaryKey(),
	]);
	$t->same(['id', 'total_minor'], $definition->schema()->projection('listing'));
})->tag('sql', 'real-module')->group('real-engine');

test('dataphyre sql kernel harness executes real isolated sqlite queries', static function(Context $t): void {
	if(!extension_loaded('sqlite3') || !class_exists(SQLite3::class)){
		$t->skip('SQLite3 extension is not available.');
	}
	$sql=$t->dataphyreModules()->sqlKernel();
	$t->isTrue($sql->createTable('CREATE TABLE kernel_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL, total_minor INTEGER NOT NULL)'));
	$t->isTrue($sql->insert('kernel_items', ['id'=>1, 'name'=>'Ada', 'total_minor'=>1299])!==false);
	$rows=$sql->select('*', 'kernel_items', 'WHERE id=?', [1]);
	$t->pathEquals([0, 'name'], 'Ada', $rows);
	$t->pathEquals([0, 'total_minor'], 1299, $rows);
	$t->same(1, $sql->count('kernel_items', 'WHERE total_minor>=?', [1000]));
	$t->same(1, $sql->update('kernel_items', ['name'=>'Grace'], 'WHERE id=?', [1]));
	$t->pathEquals([0, 'name'], 'Grace', $sql->select('*', 'kernel_items', 'WHERE id=?', [1]));
	$t->same(1, $sql->delete('kernel_items', 'WHERE id=?', [1]));
	$t->same(0, $sql->count('kernel_items'));
	$t->same(null, $sql->lastError());
	$t->isTrue(is_file($sql->databasePath()));
})->tag('sql', 'sql-kernel', 'real-module')->group('real-engine');

test('dataphyre permission bridge uses the real matrix and trace surfaces', static function(Context $t): void {
	$permission=$t->dataphyreModules()->permission([
		'roles'=>[
			'manager'=>['products.view', 'products.update'],
		],
	]);
	$report=$permission::testMatrix([
		'manager'=>['id'=>7, 'roles'=>['manager']],
		'guest'=>['id'=>8, 'roles'=>[]],
	], [
		'manager'=>[
			'allow'=>['products.view', 'products.update'],
			'deny'=>['products.delete'],
		],
		'guest'=>[
			'deny'=>['products.update'],
		],
	]);
	$t->pathEquals('ok', true, $report);
	$t->pathEquals('failed', 0, $report);
	$t->notEmpty($permission::traces());
	$snapshot=$permission::snapshot(['id'=>7, 'roles'=>['manager']], ['products.view', 'products.delete']);
	$t->pathEquals(['decisions', 'products.view'], true, $snapshot);
	$t->pathEquals(['decisions', 'products.delete'], false, $snapshot);
})->tag('permission', 'real-module')->group('real-engine');

test('dataphyre reactor bridge uses the real manager harness and response surface', static function(Context $t): void {
	$harness=$t->dataphyreModules()->reactor();
	$harness->register([
		'name'=>'product-counter',
		'state'=>['count'=>1],
		'render'=>static fn(array $state): string=>'<button id="counter">Count '.(int)($state['count'] ?? 0).'</button>',
		'actions'=>[
			'increment'=>static function(array $state, array $params, object $component, object $effects): array {
				$state['count']=(int)($state['count'] ?? 0)+(int)($params['step'] ?? 1);
				$effects->dispatch('counter.updated', ['count'=>$state['count']]);
				return $state;
			},
		],
	]);
	$mount=$harness->mount('product-counter', ['count'=>2], ['aria-label'=>'Product counter']);
	$t->pathEquals('component', 'product-counter', $mount);
	$t->htmlContainsText($mount['html'], 'Count 2');
	$t->hasPath(['snapshot', 'signature'], $mount);
	$response=$harness->dispatch('product-counter', 'increment', ['count'=>2], ['step'=>3], \Dataphyre\Reactor\ReactorSnapshot::from($mount['snapshot']));
	\Dataphyre\Reactor\ReactorTestHarness::assertOk($response);
	$t->pathEquals('count', 5, $response->state());
	$t->htmlContainsText($response->html(), 'Count 5');
	$t->pathEquals(['events', 0, 'name'], 'counter.updated', $response->effects());
	$t->pathEquals(['events', 0, 'detail', 'count'], 5, $response->effects());
})->tag('reactor', 'real-module')->group('real-engine');
