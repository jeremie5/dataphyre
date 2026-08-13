<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Currency\CurrencyManager;
use Dataphyre\Currency\ExchangeRates;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'currency'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
if(!defined('DP_CURRENCY_CFG')){
	define('DP_CURRENCY_CFG', [
		'exchange_rate_sources'=>['always-empty'],
		'exchange_rate_callbacks'=>[
			'always-empty'=>static fn(string $source, string $base, array $cached): bool=>false,
		],
	]);
}

$dp_currency_fallback_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_currency_fallback_modules_root.'/core/kernel/autoloader.php';
if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre { function tracelog(...$args): void {} }');
}
require_once $dp_currency_fallback_modules_root.'/core/kernel/helper_functions.php';
require_once $dp_currency_fallback_modules_root.'/core/kernel/core_functions.php';
\dataphyre\autoloader::register($dp_currency_fallback_modules_root);
\dataphyre\autoloader::register_framework_modules(['currency']);
require_once $dp_currency_fallback_modules_root.'/currency/kernel/currency.main.php';

test('currency manager refresh falls back to the cached loader when every source fails', static function(Context $t): void {
	$t->global('is_task')->replace(true);
	$t->globalMap('_SESSION')->clear();
	\dataphyre\currency::apply_state([
		'base_currency'=>'USD',
		'display_currency'=>'USD',
		'display_language'=>'en-CA',
		'display_country'=>'CA',
		'available_currencies'=>['USD'=>'$'],
	]);
	$rates=(new CurrencyManager())->refresh();
	$t->instanceOf(ExchangeRates::class, $rates);
	$t->same([], $rates->rates());
	$t->same('USD', $rates->baseCurrency());
})->tag('currency', 'currency-support', 'deep-coverage')->group('framework-coverage');
