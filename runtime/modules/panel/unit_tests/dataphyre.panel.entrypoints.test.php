<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
if(!class_exists('dataphyre\\routing', false)){
	final class DpPanelEntrypointRoutingStub {
		public static array $bindings=[];
	}
	class_alias(DpPanelEntrypointRoutingStub::class, 'dataphyre\\routing');
}

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('asset kernel serves the compiled CSS bundle without an application bootstrap', static function(Context $t): void {
	$t->globalMap('_GET')->replace(['asset'=>'panel.css']);
	$t->globalMap('_SERVER')->merge([
		'REQUEST_METHOD'=>'GET',
		'REQUEST_URI'=>'/panel/assets/panel.css',
	]);
	$emission=$t->captureOutput(static fn()=>require dirname(__DIR__).'/kernel/assets.php');
	$body=$emission->output();

	$t->same(true, strlen($body)>500000);
	$t->contains('.dp-panel', $body);
})->tag('panel', 'entrypoint', 'assets')->maxMillis(2000);

test('upload kernel fails closed when the CSRF token is absent', static function(Context $t): void {
	$t->globalMap('_POST')->clear();
	$t->globalMap('_FILES')->clear();
	$t->globalMap('_SERVER')->merge([
		'REQUEST_METHOD'=>'POST',
		'REQUEST_URI'=>'/panel/upload',
	]);
	$emission=$t->captureOutput(static fn()=>require dirname(__DIR__).'/kernel/upload.php');
	$payload=$t->jsonArray($emission->output());

	$t->same(false, $payload['ok'] ?? null);
	$t->same('Panel upload CSRF validation failed.', $payload['error'] ?? null);
})->tag('panel', 'entrypoint', 'upload', 'csrf')->maxMillis(2000);
