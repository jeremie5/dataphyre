<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelLocaleMetadata;
use Dataphyre\Panel\PanelLocalizationRuntime;
use Dataphyre\Panel\PanelMessageFormatter;
use Dataphyre\Panel\PanelRuntimeEnvironmentCoverageSeam;
use Dataphyre\Panel\PanelTranslationCatalogueLoader;
use Dataphyre\Panel\PanelVirtualDirectoryCoverageStream;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_runtime_environment_coverage_seam.php';
framework(['panel']);

test('locale metadata and localization runtime expose normalized fallback formatting contracts', static function(Context $t): void {
	$t->same('', PanelLocaleMetadata::normalize(''));
	$t->same('en-US-posix', PanelLocaleMetadata::normalize('en_US_POSIX'));
	$t->same(',', (new PanelLocaleMetadata('de-DE'))->numberSymbols()['decimal']);
	$metadata=new PanelLocaleMetadata('fr-CA', ['invalid locale', 'de-DE']);
	$t->same(['fr-CA', 'fr', 'de-DE', 'de'], $metadata->fallbackChain());

	$workspace=$t->workspace('panel-localization-runtime-exact');
	$workspace->file('en.json', json_encode(['welcome'=>'Welcome {name}'], JSON_THROW_ON_ERROR));
	$runtime=PanelLocalizationRuntime::make(PanelTranslationCatalogueLoader::make()->addPath($workspace->root()), 'en', null, 'UTC');
	$t->same('Welcome Ada', $runtime->translate('welcome', ['name'=>'Ada']));
	$t->same('en', $runtime->localization()->locale());
	$t->instanceOf(PanelMessageFormatter::class, $runtime->formatter());
	$t->same($runtime->manifest(), $runtime->jsonSerialize());
})->tag('panel', 'localization', 'runtime', 'metadata', 'exact-coverage')->maxMillis(2000);

test('message formatter uses native intl number currency and date services with deterministic fallbacks', static function(Context $t): void {
	$intl=new PanelMessageFormatter('en-US', true, 'invalid/timezone');
	$t->same('Hello Ada', $intl->format('Hello {name}', ['name'=>'Ada']));
	$t->notEmpty($intl->formatNumber(1234.5, 1, 2));
	$t->throws(static fn()=> $intl->formatCurrency(10, 'US'), InvalidArgumentException::class);
	$t->contains('$', $intl->formatCurrency(12.5, 'USD', 'en-US'));
	$t->throws(static fn()=> $intl->formatDate('not a date'), InvalidArgumentException::class);

	$date=new DateTimeImmutable('2026-01-02T12:00:00Z');
	foreach(['full', 'long', 'short', 'medium'] as $style){
		$t->notEmpty($intl->formatDate($date, $style, 'en-US', 'UTC'));
	}

	$fallback=new PanelMessageFormatter('en-US', false, 'UTC');
	$t->contains('2026', $fallback->formatDate($date, 'full', 'en-US', 'UTC'));
	$t->contains('2026', $fallback->formatDate($date, 'long', 'en-US', 'UTC'));
	$t->contains('1/2/26', $fallback->formatDate($date, 'short', 'en-US', 'UTC'));
	$t->same($fallback->manifest(), $fallback->jsonSerialize());

	$resource=fopen('php://memory', 'rb');
	try {
		$t->contains('Resource id #', $intl->format('{value}', ['value'=>$resource]));
	} finally {
		fclose($resource);
	}
	$throwingStringable=new class implements Stringable {
		public function __toString(): string { throw new RuntimeException('String conversion failed.'); }
	};
	$t->throws(static fn()=> $intl->format('{value}', ['value'=>$throwingStringable]), RuntimeException::class);
})->tag('panel', 'localization', 'formatter', 'intl', 'exact-coverage')->maxMillis(2000);

test('fallback message parser handles malformed syntax missing values and every plural family', static function(Context $t): void {
	$formatter=new PanelMessageFormatter('en-US', false, 'UTC');
	$t->same('Hello {name', $formatter->format('Hello {name', ['name'=>'Ada']));
	$t->same('zero', $formatter->format('{count, plural, zero {zero} one {one} other {other}}', ['count'=>'not-numeric'], 'ar'));
	$t->same('1,234.5', $formatter->format('{amount, number}', ['amount'=>1234.5], 'en-US'));
	$t->same('Ada', $formatter->format('{name, unsupported, ignored}', ['name'=>'Ada']));
	$t->same('{user.name}', $formatter->format('{user.name}', ['user'=>[]]));

	$arabic='{count, plural, zero {zero} one {one} two {two} few {few} many {many} other {other}}';
	foreach([0=>'zero', 1=>'one', 2=>'two', 3=>'few', 11=>'many', 100=>'other'] as $number=>$expected){
		$t->same($expected, $formatter->format($arabic, ['count'=>$number], 'ar'));
	}
	$slavic='{count, plural, one {one} few {few} many {many} other {other}}';
	foreach([1=>'one', 2=>'few', 5=>'many'] as $number=>$expected){
		$t->same($expected, $formatter->format($slavic, ['count'=>$number], 'ru'));
		$t->same($expected, $formatter->format($slavic, ['count'=>$number], 'pl'));
	}
	$t->same('few', $formatter->format('{count, plural, one {one} few {few} other {other}}', ['count'=>3], 'cs'));
	$t->same('one', $formatter->format('{count, plural, one {one} other {other}}', ['count'=>0], 'fr'));
	$t->same('other', $formatter->format('{count, selectordinal, one {one} other {other}}', ['count'=>1], 'fr'));

	$t->same(['ther'=>'ok'], $t->nonPublic($formatter)->invoke('options', 'broken  other {ok}'));
	$t->same([], $t->nonPublic($formatter)->invoke('options', 'other {broken'));
	$recursive=[];
	$recursive['self']=&$recursive;
	$t->same('', $formatter->format('{value}', ['value'=>$recursive]));
})->tag('panel', 'localization', 'formatter', 'parser', 'plurals', 'exact-coverage')->maxMillis(2000);

test('translation loader validates sources filters locales and resolves explicit and multi-locale catalogues', static function(Context $t): void {
	$workspace=$t->workspace('panel-translation-loader-exact');
	$workspace->file('catalogues/en.json', json_encode(['title'=>'English'], JSON_THROW_ON_ERROR));
	$workspace->file('catalogues/fr.json', json_encode(['title'=>'Français'], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
	$workspace->directory('catalogues/empty-directory');
	$loader=PanelTranslationCatalogueLoader::make()->addPath($workspace->path('catalogues'));
	$t->same(['en'], array_keys($loader->catalogue('en')));
	$t->throws(static fn()=> $loader->addPackage(' ', $workspace->root()), InvalidArgumentException::class);
	$t->throws(static fn()=> PanelTranslationCatalogueLoader::make()->addPath($workspace->path('missing')), InvalidArgumentException::class);

	$single=$workspace->file('direct/en.json', json_encode(['direct'=>'loaded'], JSON_THROW_ON_ERROR));
	$t->same('loaded', PanelTranslationCatalogueLoader::make()->addPath($single)->catalogue()['en']['direct']);

	$unresolvable=$workspace->file('unresolvable/en.json', '{}');
	PanelRuntimeEnvironmentCoverageSeam::$enabled=true;
	PanelRuntimeEnvironmentCoverageSeam::$unresolvablePath=$unresolvable;
	try {
		$t->throws(static fn()=> PanelTranslationCatalogueLoader::make()->addPath($unresolvable), InvalidArgumentException::class);
	} finally {
		PanelRuntimeEnvironmentCoverageSeam::reset();
	}

	$explicit=$workspace->file('explicit/messages.json', json_encode([
		'locale'=>'pt-BR', 'translations'=>['title'=>'Painel'],
	], JSON_THROW_ON_ERROR));
	$t->same('Painel', PanelTranslationCatalogueLoader::make()->addPath($explicit)->catalogue()['pt-BR']['messages.title']);

	$multi=$workspace->file('multi/catalogue.json', json_encode([
		'en'=>['title'=>'Panel'], 'fr'=>['title'=>'Panneau'], 'invalid'=>'skip',
	], JSON_THROW_ON_ERROR));
	$multiCatalogue=PanelTranslationCatalogueLoader::make()->addPath($multi)->catalogue();
	$t->same('Panel', $multiCatalogue['en']['catalogue.title']);
	$t->same('Panneau', $multiCatalogue['fr']['catalogue.title']);
})->tag('panel', 'localization', 'catalogue-loader', 'sources', 'exact-coverage')->maxMillis(2000);

test('translation loader private file boundary rejects untrusted unreadable scalar and locale-free payloads', static function(Context $t): void {
	$workspace=$t->workspace('panel-translation-loader-boundaries');
	$loader=PanelTranslationCatalogueLoader::make();
	$scheme='dpcatalogue';
	$t->isTrue(stream_wrapper_register($scheme, PanelVirtualDirectoryCoverageStream::class));
	try {
		$t->same([], $t->nonPublic($loader)->invoke('files', ['path'=>$scheme.'://root', 'trusted_php'=>false]));
	} finally {
		stream_wrapper_unregister($scheme);
	}
	$php=$workspace->file('catalogue.php', "<?php return ['title'=>'Private'];");
	$t->throws(static fn()=> $t->nonPublic($loader)->invoke('entries', $php, ['trusted_php'=>false, 'path'=>$php, 'namespace'=>'']), RuntimeException::class);
	$t->throws(static fn()=> $t->nonPublic($loader)->invoke('entries', $workspace->path('missing.json'), ['trusted_php'=>false, 'path'=>$workspace->root(), 'namespace'=>'']), RuntimeException::class);

	$scalar=$workspace->file('scalar/catalogue.json', 'null');
	$t->throws(static fn()=> $t->nonPublic($loader)->invoke('entries', $scalar, ['trusted_php'=>false, 'path'=>$scalar, 'namespace'=>'']), UnexpectedValueException::class);
	$localeFree=$workspace->file('invalid/catalogue.json', json_encode(['invalid'=>'value'], JSON_THROW_ON_ERROR));
	$t->throws(static fn()=> $t->nonPublic($loader)->invoke('entries', $localeFree, ['trusted_php'=>false, 'path'=>$localeFree, 'namespace'=>'']), UnexpectedValueException::class);
	$t->same(['', 'catalogue'], $t->nonPublic($loader)->invoke('infer', $localeFree, $localeFree));
})->tag('panel', 'localization', 'catalogue-loader', 'trust-boundary', 'exact-coverage')->maxMillis(2000);
