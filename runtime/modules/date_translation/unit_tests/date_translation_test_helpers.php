<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace {
	if(!function_exists('tracelog')){
		function tracelog(...$args): void {}
	}
}

namespace {
	require_once __DIR__.'/../kernel/date_translation.main.php';

	final class DpDateTranslationFixtureState {
		/** @param array<string,mixed> $locales */
		public static function replaceLocales(array $locales): void {
			$replace=\Closure::bind(
				static function(array $values): void {
					\dataphyre\date_translation::$date_locales=$values;
				},
				null,
				\dataphyre\date_translation::class,
			);
			if(!$replace instanceof \Closure){
				throw new \RuntimeException('Unable to install date-translation fixture locales.');
			}
			$replace($locales);
		}
	}

	function dp_date_translation_unit_seed_french(): bool {
		DpDateTranslationFixtureState::replaceLocales([
			'fr'=>[
				'abstract'=>[
					'today'=>'aujourd hui',
				],
				'months'=>[
					'january'=>['janvier', 'janv'],
					'march'=>['mars', 'mars'],
				],
				'weekdays'=>[
					'monday'=>['lundi', 'lun'],
					'tuesday'=>['mardi', 'mar'],
				],
			],
			'es'=>[
				'abstract'=>[
					'tomorrow'=>'manana',
				],
				'months'=>[
					'march'=>['marzo', 'mar'],
					'september'=>['septiembre', 'sept'],
				],
				'weekdays'=>[
					'tuesday'=>['martes', 'mar'],
				],
			],
		]);
		return true;
	}

	function dp_date_translation_unit_french_json(): string {
		dp_date_translation_unit_seed_french();
		return json_encode([
			'day_month_year'=>\dataphyre\date_translation::translate_date('1st January 2026', 'fr', 'd M Y'),
			'month_day'=>\dataphyre\date_translation::translate_date('March 2nd', 'fr', 'F j'),
			'weekday'=>\dataphyre\date_translation::translate_date('Monday today', 'fr', 'l relative'),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_date_translation_unit_non_french_json(): string {
		dp_date_translation_unit_seed_french();
		return json_encode([
			'ordinal_removed'=>\dataphyre\date_translation::translate_date('September 3rd tomorrow', 'es', 'F jS relative'),
			'weekday_abbrev'=>\dataphyre\date_translation::translate_date('Tue March 2nd', 'es', 'D F jS'),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_date_translation_unit_french_abbrev_json(): string {
		dp_date_translation_unit_seed_french();
		return json_encode([
			'abbrev_month'=>\dataphyre\date_translation::translate_date('Jan 2nd', 'fr', 'F jS'),
			'abbrev_weekday'=>\dataphyre\date_translation::translate_date('Tue March 3rd', 'fr', 'D F jS'),
		], JSON_UNESCAPED_SLASHES);
	}
}
