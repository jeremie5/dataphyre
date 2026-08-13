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

require_once __DIR__.'/date_translation_test_helpers.php';

test('date locale loading selects local or common PHP and JSON catalogs without host ini dependence', static function(Context $t): void {
	$internals=$t->nonPublic(\dataphyre\date_translation::class);
	$catalog=['zz'=>['abstract'=>[], 'months'=>[], 'weekdays'=>[]]];
	$loaded=[];
	$phpLoader=static function(string $path) use (&$loaded,$catalog): array {
		$loaded[]=$path;
		return $catalog;
	};
	$jsonReader=static function(string $path) use (&$loaded,$catalog): array {
		$loaded[]=$path;
		return $catalog;
	};

	$t->same($catalog, $internals->invoke('load_date_locale', 'zz', true, static fn(string $path): bool=>true, $phpLoader, $jsonReader));
	$t->same($catalog, $internals->invoke('load_date_locale', 'zz', true, static fn(string $path): bool=>false, $phpLoader, $jsonReader));
	$t->same($catalog, $internals->invoke('load_date_locale', 'zz', false, static fn(string $path): bool=>true, $phpLoader, $jsonReader));
	$t->same($catalog, $internals->invoke('load_date_locale', 'zz', false, static fn(string $path): bool=>false, $phpLoader, $jsonReader));
	$t->same([
		ROOTPATH['dataphyre'].'config/date_translation/languages/zz.php',
		ROOTPATH['common_dataphyre'].'config/date_translation/languages/zz.php',
		ROOTPATH['dataphyre'].'config/date_translation/languages/zz.json',
		ROOTPATH['common_dataphyre'].'config/date_translation/languages/zz.json',
	], $loaded);
});

test('translation accepts a per-call catalog loader and returns null for malformed catalogs', static function(Context $t): void {
	DpDateTranslationFixtureState::replaceLocales([]);
	$catalog=['zz'=>[
		'abstract'=>['today'=>'now'],
		'months'=>[],
		'weekdays'=>[],
	]];
	$t->same('now', \dataphyre\date_translation::translate_date('today', 'zz', 'relative', static fn(string $language): array=>$catalog));
	DpDateTranslationFixtureState::replaceLocales([]);
	$t->same(null, \dataphyre\date_translation::translate_date('today', 'bad', 'relative', static fn(string $language): array=>[]));
})->tag('date-translation','loader','exact-coverage')->group('framework-coverage');
