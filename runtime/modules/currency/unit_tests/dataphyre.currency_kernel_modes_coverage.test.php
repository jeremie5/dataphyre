<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	const RUN_MODE='diagnostic';
	const DP_CURRENCY_CFG=[
		'exchange_rate_sources'=>[' Primary ', 'primary', 17, ' '],
		'exchange_rate_callbacks'=>[],
		'minor_units'=>[],
		'cash_rounding_increments'=>[],
	];

	final class core{
		public static function dialback(string $name, mixed ...$arguments): mixed {
			return null;
		}

		public static function unavailable(mixed ...$arguments): bool {
			return false;
		}
	}

	final class dpanel{
		public static array $verbose=[];

		public static function add_verbose(array $verbose): void {
			self::$verbose=$verbose;
		}
	}
}

namespace {
	use Dataphyre\Test\Context;

	final class DpCurrencyModesProbe {
		/** @var list<array{string,string}> */
		public static array $requirements=[];
	}

	use function Dataphyre\Test\test;

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
			if(!defined($constant)){
				define($constant, $defaults);
			}
		}
	}
	if(!function_exists('dp_module_required')){
		function dp_module_required(string $module, string $required): void {
			DpCurrencyModesProbe::$requirements[]=[$module, $required];
		}
	}

	$currency_modes_runtime=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\');
	require_once $currency_modes_runtime.'/modules/currency/kernel/currency.main.php';

	test('currency kernel diagnostic mode covers configured sources and storage-less fallbacks', static function(Context $t): void {
		$currencyInternals=$t->nonPublic(\dataphyre\currency::class);
		$t->same([['currency', 'sql']], DpCurrencyModesProbe::$requirements);
		$t->same('warning', \dataphyre\dpanel::$verbose[0]['level'] ?? null);
		$t->same(['primary'], \dataphyre\currency::exchange_rate_sources());
		$t->same(null, $currencyInternals->invoke('persist_exchange_rate_data', ['data'=>['USD'=>1], 'source'=>'primary', 'time'=>time()]));
		$t->same(false, $currencyInternals->invoke('load_cached_exchange_rates_from_storage', ['primary']));
	})->tag('currency', 'diagnostic', 'coverage')->group('framework-coverage');
}
