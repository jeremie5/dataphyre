<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelLocaleMetadata;
use Dataphyre\Panel\PanelMessageFormatter;
use Dataphyre\Panel\PanelTranslationCatalogueLoader;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel catalogue loader layers filesystem package namespace priority and trusted php catalogues', static function(Context $t): void {
	$base=$t->tempDirectory('panel-catalogue-base');
	$override=$t->tempDirectory('panel-catalogue-override');
	$package=$t->tempDirectory('panel-catalogue-package');
	file_put_contents($base.'/en.json', json_encode(['actions'=>['save'=>'Save', 'cancel'=>'Cancel']], JSON_THROW_ON_ERROR));
	file_put_contents($override.'/en.json', json_encode(['actions'=>['save'=>'Store']], JSON_THROW_ON_ERROR));
	mkdir($package.'/fr-CA', 0775, true);
	file_put_contents($package.'/fr-CA/panel.json', json_encode(['title'=>'Command centre', 'items'=>'Articles'], JSON_THROW_ON_ERROR));
	file_put_contents($package.'/de.php', "<?php return ['title'=>'Steuerung'];");

	$loader=PanelTranslationCatalogueLoader::make()
		->addPath($base, 'core', 0)
		->addPath($override, 'core', 100)
		->addPackage('shop-module', $package, 'shop', 10, true);
	$catalogue=$loader->catalogue();
	$t->same('Store', $catalogue['en']['core.actions.save']);
	$t->same('Cancel', $catalogue['en']['core.actions.cancel']);
	$t->same('Command centre', $catalogue['fr-CA']['shop.panel.title']);
	$t->same('Steuerung', $catalogue['de']['shop.title']);
	$localization=$loader->load('fr-CA', 'en');
	$t->same('Command centre', $localization->translate('title', [], null, null, 'shop.panel'));
	$t->same('Store', $localization->translate('actions.save', [], 'en', null, 'core'));
	$t->same(4, count($loader->diagnostics()));
	$t->isTrue($loader->manifest()['capabilities']['deterministic_merge']);

	$broken=$t->tempDirectory('panel-catalogue-broken');
	file_put_contents($broken.'/en.json', '{bad');
	$t->throws(static fn()=> PanelTranslationCatalogueLoader::make()->addPath($broken)->catalogue(), RuntimeException::class);
	$lenient=PanelTranslationCatalogueLoader::make(false)->addPath($broken);
	$t->same([], $lenient->catalogue());
	$t->same('failed', $lenient->diagnostics()[0]['status']);
})->tag('panel', 'localization', 'catalogue', 'production')->group('panel-production-runtime');

test('panel fallback message formatter handles nested plurals selects exact values offsets ordinals and placeholders without intl', static function(Context $t): void {
	$formatter=new PanelMessageFormatter('en-US', false, 'America/Toronto');
	$t->same('No files', $formatter->format('{count, plural, =0 {No files} one {# file} other {# files}}', ['count'=>0]));
	$t->same('1 file', $formatter->format('{count, plural, =0 {No files} one {# file} other {# files}}', ['count'=>1]));
	$t->same('2 files', $formatter->format('{count, plural, =0 {No files} one {# file} other {# files}}', ['count'=>2]));
	$t->same('She assigned 3 orders', $formatter->format('{gender, select, female {She assigned {count, plural, one {# order} other {# orders}}} male {He assigned orders} other {They assigned orders}}', ['gender'=>'female', 'count'=>3]));
	$t->same('You and 2 others', $formatter->format('{count, plural, offset:1 =1 {Only you} one {You and one other} other {You and # others}}', ['count'=>3]));
	$t->same('22nd', $formatter->format('{position, selectordinal, one {#st} two {#nd} few {#rd} other {#th}}', ['position'=>22]));
	$t->same('Bonjour Ada / Ada / Ada', $formatter->format('Bonjour {user.name} / {{ user.name }} / :name', ['user'=>['name'=>'Ada'], 'name'=>'Ada']));
	$t->same('1 234,5', $formatter->formatNumber(1234.5, 0, 2, 'fr-CA'));
	$t->same('١٬٢٣٤٫٥', $formatter->formatNumber(1234.5, 0, 2, 'ar-EG'));
	$t->contains('€', $formatter->formatCurrency(12.5, 'EUR', 'fr-CA'));
	$t->same('Jan 2, 2026', $formatter->formatDate('2026-01-02T12:00:00-05:00', 'medium', 'en-US', 'America/Toronto'));
	$t->isTrue($formatter->manifest()['capabilities']['no_intl_fallback']);
})->tag('panel', 'localization', 'icu', 'production')->group('panel-production-runtime');

test('panel locale metadata normalizes script region fallback chains number symbols and rtl html attributes', static function(Context $t): void {
	$arabic=new PanelLocaleMetadata('ar_Arab_EG', ['en_US', 'en']);
	$t->same('ar-Arab-EG', $arabic->locale());
	$t->same('ar', $arabic->language());
	$t->same('Arab', $arabic->script());
	$t->same('EG', $arabic->region());
	$t->isTrue($arabic->isRtl());
	$t->same('rtl', $arabic->htmlAttributes()['dir']);
	$t->same(['ar-Arab-EG', 'ar', 'en-US', 'en'], $arabic->fallbackChain());
	$t->same('٫', $arabic->numberSymbols()['decimal']);
	$t->same('', PanelLocaleMetadata::normalize('invalid-locale'));
	$english=new PanelLocaleMetadata('en-CA');
	$t->isFalse($english->isRtl());
	$t->same('ltr', $english->direction());
	$t->same($english->manifest(), $english->jsonSerialize());
})->tag('panel', 'localization', 'rtl', 'production')->group('panel-production-runtime');
