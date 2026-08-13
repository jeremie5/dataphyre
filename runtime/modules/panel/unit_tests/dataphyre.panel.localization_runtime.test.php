<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelLocalizationRuntime;
use Dataphyre\Panel\PanelTranslationCatalogueLoader;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
require_once __DIR__.'/panel_test_harness_helpers.php';dp_panel_unit_test_bootstrap();

test('localization runtime loads catalogues formats ICU messages applies Panel context and emits RTL attributes',static function(Context $t):void{
	$workspace=$t->workspace('panel-localization-runtime');
	$workspace->file('ar.json',json_encode([
		'orders'=>['count'=>'{count, plural, =0 {لا طلبات} one {طلب واحد} other {# طلبات}}'],
	],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
	$workspace->file('en.json',json_encode([
		'orders'=>['count'=>'{count, plural, one {One order} other {# orders}}'],
	],JSON_THROW_ON_ERROR));

	$loader=PanelTranslationCatalogueLoader::make()->addPath($workspace->root());
	$runtime=PanelLocalizationRuntime::make($loader,'ar','en','UTC');
	$t->matches('/^[3٣] طلبات$/u',$runtime->translate('orders.count',['count'=>3]));
	$t->same('rtl',$runtime->metadata()->direction());
	$t->contains('dir="rtl"',$runtime->htmlAttributes());
	$t->contains('lang="ar"',$runtime->htmlAttributes());

	$panel=$runtime->apply(PanelInstance::make('localized'));
	$localization=$panel->localization();
	$t->same('ar',$localization->locale());
	$t->isTrue($runtime->manifest()['capabilities']['icu_messages']);
})->tag('panel','localization','runtime','rtl')->maxMillis(2000);
