<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Currency\CurrencyManager;
use Dataphyre\Currency\ExchangeQuote;
use Dataphyre\Currency\Money;
use Dataphyre\Currency\StoredMoney;
use Dataphyre\Currency\Exceptions\CurrencyMismatchException;
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
		'exchange_rate_sources'=>['coverage'],
		'exchange_rate_callbacks'=>[
			'coverage'=>static fn(string $source, string $base, array $cached): array=>[
				'rates'=>['USD'=>1, 'CAD'=>1.25, 'EUR'=>0.8, 'JPY'=>150, 'CHF'=>0.9],
				'time'=>time(),
				'source'=>$source,
			],
		],
		'minor_units'=>['BTC'=>8],
		'cash_rounding_increments'=>['CAD'=>0.05],
	]);
}

$dp_money_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_money_modules_root.'/core/kernel/autoloader.php';
if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre { function tracelog(...$args): void {} }');
}
require_once $dp_money_modules_root.'/core/kernel/helper_functions.php';
require_once $dp_money_modules_root.'/core/kernel/core_functions.php';
\dataphyre\autoloader::register($dp_money_modules_root);
\dataphyre\autoloader::register_framework_modules(['currency']);
require_once $dp_money_modules_root.'/currency/kernel/currency.main.php';

function dp_money_manager(Context $t): CurrencyManager {
	$t->globalMap('_SESSION')->clear();
	\dataphyre\currency::apply_state([
		'base_currency'=>'USD',
		'display_currency'=>'CAD',
		'display_language'=>'en-CA',
		'display_country'=>'CA',
		'available_currencies'=>['USD'=>'$', 'CAD'=>'C$', 'EUR'=>'€'],
	]);
	$manager=new CurrencyManager();
	$manager->refreshSource('coverage');
	return $manager;
}

test('money delegates formatting quotes snapshots storage and display currency workflows', static function(Context $t): void {
	$manager=dp_money_manager($t);
	$snapshot=$manager->snapshot();
	$usd=$manager->money('10.00', 'USD', ['display_currency'=>'CAD']);
	$cad=$manager->money('12.50', 'CAD');
	$t->same(10.0, $usd->value());
	$t->notEmpty($usd->format());
	$t->instanceOf(ExchangeQuote::class, $usd->quoteWith($snapshot, 'CAD'));
	$t->same(12.5, $usd->convertedWith($snapshot, 'CAD')->amount());
	$t->instanceOf(ExchangeQuote::class, $usd->quoteWithFresh($snapshot, 'CAD', 60));
	$t->same(12.5, $usd->convertedWithFresh($snapshot, 'CAD', 60)->amount());
	$t->instanceOf(StoredMoney::class, $cad->stored('USD'));
	$t->instanceOf(StoredMoney::class, $cad->storedFresh(60, 'USD'));
	$t->instanceOf(StoredMoney::class, $cad->storedWith($snapshot, 'USD'));
	$t->instanceOf(StoredMoney::class, $cad->storedWithFresh($snapshot, 60, 'USD'));
	$t->same('CAD', $usd->inDisplayCurrency()->currency());
	$t->same('EUR', $usd->inDisplayCurrency(' eur ')->currency());
	$t->same('USD', $cad->inBaseCurrency()->currency());
	$t->notEmpty($usd->display(false));
	$t->instanceOf(ExchangeQuote::class, $usd->quoteTo('CAD', true));
})->tag('currency', 'coverage')->group('framework-coverage');

test('money arithmetic covers precision cash signed division and comparable currency guards', static function(Context $t): void {
	$manager=dp_money_manager($t);
	$money=$manager->money('10.25', 'USD');
	$t->same(11.25, $money->add($manager->money(1, 'USD'))->amount());
	$t->same(9.25, $money->subtract($manager->money(1, 'USD'))->amount());
	$t->same(0, $money->compare('10.25', ' usd '));
	$t->same(0, $money->compare('10.25', ''));
	$t->throws(static fn()=>$money->add($manager->money(1, 'CAD')), CurrencyMismatchException::class);
	$t->throws(static fn()=>$money->subtract(1, 'CAD'), CurrencyMismatchException::class);

	$t->same(12.66, $money->multiply(1.235, 3)->amount());
	$t->same(12.66, $money->multiply(1.235, 3, true)->amount());
	$t->same(-3.42, $money->divide(-3, 3)->amount());
	$t->same(5.0, $money->divide(2, 0)->amount());
	$t->same(-5.13, $money->divide(-2)->amount());
	$t->same(0.0, $money->multiply(0.0)->amount());
})->tag('currency', 'coverage')->group('framework-coverage');

test('money decimal helpers cover zero signed ratios precision carry and integer input', static function(Context $t): void {
	$money=Money::fromMinor(100, 'USD',dp_money_manager($t));
	$moneyInternals=$t->nonPublic($money);
	$t->same([0, 1], $moneyInternals->invoke('decimalParts', 0.0));
	$t->same([-125, 100], $moneyInternals->invoke('decimalParts', -1.25));
	$t->same('5', $moneyInternals->invoke('decimalInput', 5));
	$t->same(-2, $moneyInternals->invoke('roundRatioToInt', 3, -2));
	$t->same('2', $moneyInternals->invoke('scaledMinorToDecimal', 150, 1, 0));
	$t->same('-1', $moneyInternals->invoke('scaledMinorToDecimal', -149, 1, 0));
	$t->same('2.0', $moneyInternals->invoke('scaledMinorToDecimal', 199, 1, 1));
	$t->same('1.25', $moneyInternals->invoke('scaledMinorToDecimal', 125, 1, 2));
})->tag('currency', 'coverage')->group('framework-coverage');
