<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelExtensionAssets;
use Dataphyre\Panel\PanelExtensionDescriptor;
use Dataphyre\Panel\PanelExtensionRegistry;
use Dataphyre\Panel\PanelExtensionRuntime;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('extension descriptors normalize public assets hooks dependencies permissions and capabilities', static function(Context $t): void {
	$extension=PanelExtensionDescriptor::make('Acme Orders', '1.2.3', [
		'requires'=>['core-ui'=>'^1.0.0'], 'provides'=>['orders.timeline', 'orders.export'],
		'assets'=>[['type'=>'module', 'url'=>'/extensions/orders.js', 'integrity'=>'sha384-demo', 'scope'=>'orders']],
		'hooks'=>['page.end'=>'orders_footer'], 'permissions'=>['orders.read'], 'metadata'=>['publisher'=>'Acme'],
	]);
	$payload=$extension->jsonSerialize();
	$t->same('acme-orders', $extension->id());
	$t->same('^1.0.0', $extension->requires()['core-ui']);
	$t->same('module', $extension->assets()[0]['type']);
	$t->same('orders', $extension->assets()[0]['scope']);
	$t->same('orders_footer', $extension->hooks()['page.end']);
	$t->same(1, $payload['api_version']);
})->tag('panel', 'extensions', 'sdk', 'descriptor')->maxMillis(1000);

test('extension registry resolves dependencies and emits deterministic asset hook and capability plans', static function(Context $t): void {
	$registry=(new PanelExtensionRegistry())
		->register(PanelExtensionDescriptor::make('feature', '2.0.0', ['requires'=>['foundation'=>'^1.0.0'], 'provides'=>['feature.view'], 'hooks'=>['page.end'=>'feature_footer']]))
		->register(PanelExtensionDescriptor::make('foundation', '1.4.0', ['provides'=>['ui.core'], 'assets'=>[['type'=>'style','url'=>'/foundation.css']]]));
	$manifest=$registry->manifest();
	$t->same(['foundation', 'feature'], $manifest['load_order']);
	$t->same('foundation', $manifest['assets'][0]['extension']);
	$t->same('feature', $manifest['hooks']['page.end'][0]['extension']);
	$t->same(['feature'], $manifest['provides']['feature.view']);
})->tag('panel', 'extensions', 'sdk', 'dependencies')->maxMillis(1000);

test('extension runtime dispatches scoped priority listeners and render hooks', static function(Context $t): void {
	$runtime=(new PanelExtensionRuntime())
		->on('order.loaded', static fn(array $payload): array => $payload+['low'=>true], 1)
		->on('order.loaded', static fn(array $payload): array => ['high'=>true]+$payload, 20, 'orders')
		->on('render.page.end', static fn(array $fragments): array => [...$fragments, '<p>Footer</p>'], 0, 'orders');
	$orders=$runtime->dispatch('order.loaded', ['id'=>7], 'orders');
	$t->same(['high'=>true, 'id'=>7, 'low'=>true], $orders);
	$other=$runtime->dispatch('order.loaded', ['id'=>8], 'sellers');
	$t->isFalse(isset($other['high']));
	$t->same('<p>Footer</p>', $runtime->render('page.end', [], 'orders'));
	$t->same(2, count($runtime->manifest()['events']['order.loaded']));
})->tag('panel', 'extensions', 'sdk', 'runtime')->maxMillis(1000);

test('extension browser API is optional versioned lifecycle-owned and served as a separate asset', static function(Context $t): void {
	$javascript=PanelExtensionAssets::javascript();
	$t->contains('DataphyrePanelExtensions', $javascript);
	$t->contains('apiVersion', $javascript);
	$t->contains('dp:panel:before-unmount', $javascript);
	$t->contains('cleanups', $javascript);
	$t->contains('data-dp-panel-extension-api="1"', PanelExtensionAssets::scriptTag('/panel-extensions.js'));
	$t->same('', PanelExtensionAssets::scriptTag('javascript:alert(1)'));
	$asset=PanelRenderer::assetContent('panel-extensions.js');
	$t->contains('DataphyrePanelExtensions', (string)($asset['content'] ?? ''));
	$t->same('application/javascript; charset=UTF-8', $asset['content_type'] ?? null);
})->tag('panel', 'extensions', 'sdk', 'browser')->maxMillis(2000);
