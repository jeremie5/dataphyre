<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
use Dataphyre\Panel\PanelQualityClientAssets;
use Dataphyre\Panel\PanelQualityGate;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('quality gate combines accessibility focus sizing scheme and custom contract checks', static function(Context $t): void {
	$good=PanelQualityGate::from('<main><h1>Orders</h1><button type="button">Refresh</button></main>',['custom'=>['heading'=>static fn(string $html):bool=>str_contains($html,'<h1>')]]);
	$t->isTrue($good->passed());
	$t->same([], $good->failures());
	$bad=PanelQualityGate::from('<main><input tabindex="3"><a href="javascript:alert(1)">Unsafe</a><div style="width: 2000px"></div></main>');
	$t->isFalse($bad->passed());
	$t->isTrue(count($bad->failures())>=4);
})->tag('panel','quality','gate')->maxMillis(1000);

test('optional browser auditor covers labels focus targets contrast dialogs overflow axe and preferences', static function(Context $t): void {
	$javascript=PanelQualityClientAssets::javascript();
	foreach(['DataphyrePanelQuality','control-label','positive-tabindex','target-size','contrast','dialog-focus','viewport-overflow','window.axe','prefers-reduced-motion','forced-colors','dp:panel-quality-complete'] as $needle){$t->contains($needle,$javascript);}
	$asset=PanelRenderer::assetContent('panel-quality.js');
	$t->same('application/javascript; charset=UTF-8',$asset['content_type']??null);
	$t->contains('dataphyre_panel_browser_quality',(string)($asset['content']??''));
})->tag('panel','quality','browser')->maxMillis(1000);
