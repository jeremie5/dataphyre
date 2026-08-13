<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Currency\Currency;
use Dataphyre\Currency\CurrencyContext;
use Dataphyre\Currency\CurrencyManager;
use Dataphyre\Currency\ExchangeQuote;
use Dataphyre\Currency\ExchangeRates;
use Dataphyre\Currency\ExchangeSnapshot;
use Dataphyre\Currency\Exceptions\StaleExchangeRatesException;
use Dataphyre\Currency\Money;
use Dataphyre\Currency\StoredMoney;
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
		'exchange_rate_sources'=>['support-coverage'],
		'exchange_rate_callbacks'=>[
			'support-coverage'=>static fn(string $source, string $base, array $cached): array=>[
				'rates'=>['USD'=>1, 'CAD'=>1.25, 'EUR'=>0.8, 'CHF'=>0.9, 'JPY'=>150],
				'time'=>time(),
				'source'=>$source,
			],
		],
		'minor_units'=>['JPY'=>0],
		'cash_rounding_increments'=>['CAD'=>0.05],
	]);
}

$dp_currency_support_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_currency_support_modules_root.'/core/kernel/autoloader.php';
if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre { function tracelog(...$args): void {} }');
}
require_once $dp_currency_support_modules_root.'/core/kernel/helper_functions.php';
require_once $dp_currency_support_modules_root.'/core/kernel/core_functions.php';
\dataphyre\autoloader::register($dp_currency_support_modules_root);
\dataphyre\autoloader::register_framework_modules(['currency']);
require_once $dp_currency_support_modules_root.'/currency/kernel/currency.main.php';

/** @return CurrencyManager Manager with deterministic in-memory rates. */
function dp_currency_support_manager(Context $t): CurrencyManager {
	$t->globalMap('_SESSION')->clear();
	\dataphyre\currency::apply_state([
		'base_currency'=>'USD',
		'display_currency'=>'CAD',
		'display_language'=>'en-CA',
		'display_country'=>'CA',
		'available_currencies'=>['USD'=>'$', 'CAD'=>'C$', 'EUR'=>'E', 'CHF'=>'CHF '],
	]);
	CurrencyManager::flush();
	$manager=CurrencyManager::instance();
	$manager->refreshSource('support-coverage');
	return $manager;
}

test('currency facade and immutable context delegate every support operation', static function(Context $t): void {
	$manager=dp_currency_support_manager($t);
	$t->same(['USD'=>'$', 'CAD'=>'C$', 'EUR'=>'E', 'CHF'=>'CHF '], Currency::availableCurrencies());
	$t->same(['support-coverage'], Currency::exchangeRateSources());
	$t->same(125, Currency::convertMinorUnits(100, 'USD', 'CAD'));
	$t->same(0.05, Currency::cashRoundingIncrement('CAD'));
	$t->instanceOf(ExchangeQuote::class, Currency::quoteOrFailFresh('USD', 'CAD', 60));
	$t->isTrue(is_string(Currency::format(12.5, false, 'CAD')));
	$t->same(1.05, Currency::roundAmount(1.03, 'CAD', true));

	$money=Currency::money(10, 'CAD');
	$t->same(8.0, Currency::convertMoney($money, 'USD')->amount());
	$t->same(8.0, Currency::convertMoneyOrFailFresh($money, 'USD', 60)->amount());
	$t->same(12.5, Currency::convertOrFailFresh(10, 'USD', 'CAD', 60));
	$t->instanceOf(StoredMoney::class, Currency::storeMoney($money, 'USD'));
	$t->instanceOf(StoredMoney::class, Currency::storeMoneyOrFailFresh($money, 60, 'USD'));

	$context=Currency::context('CAD', 'en-CA', 'CA', 'USD', ['USD'=>'$', 'CAD'=>'C$']);
	$t->instanceOf(CurrencyContext::class, $context);
	$t->same(2, $context->minorUnits('USD'));
	$t->same(100, $context->minorFactor('USD'));
	$t->same(103, $context->amountToMinorUnits('1.03', 'USD'));
	$t->same('1.03', $context->minorUnitsToDecimal(103, 'USD'));
	$t->same(125, $context->convertMinorUnits(100, 'USD', 'CAD'));
	$t->same(0.05, $context->cashRoundingIncrement('CAD'));
	$t->instanceOf(ExchangeRates::class, $context->rates());
	$t->instanceOf(ExchangeRates::class, $context->refresh('support-coverage'));
	$t->instanceOf(ExchangeSnapshot::class, $context->snapshot());
	$t->instanceOf(ExchangeSnapshot::class, $context->snapshotOrFail(60));
	$t->isTrue($context->refreshSource('support-coverage'));
	$t->isTrue(is_string($context->format(12.5, false, 'CAD')));
	$t->same(1.05, $context->roundAmount(1.03, 'CAD', true));
	$t->instanceOf(ExchangeQuote::class, $context->quote('USD', 'CAD'));
	$t->instanceOf(ExchangeQuote::class, $context->quoteOrFail('USD', 'CAD'));
	$t->instanceOf(ExchangeQuote::class, $context->quoteOrFailFresh('USD', 'CAD', 60));
	$t->same(12.5, $context->convert(10, 'USD', 'CAD'));
	$t->same(12.5, $context->convertToDisplay(10, false, true, 'CAD'));
	$t->same(8.0, $context->convertToBase(10, 'CAD'));

	$contextMoney=$context->money(10, 'CAD');
	$t->same(1000, $context->moneyFromMinor(1000, 'CAD')->minorAmount());
	$t->same(8.0, $context->convertMoney($contextMoney, 'USD')->amount());
	$t->same(8.0, $context->convertMoneyOrFailFresh($contextMoney, 'USD', 60)->amount());
	$t->same(12.5, $context->convertOrFailFresh(10, 'USD', 'CAD', 60));
	$t->instanceOf(StoredMoney::class, $context->storeMoney($contextMoney, 'USD'));
	$t->instanceOf(StoredMoney::class, $context->storeMoneyOrFailFresh($contextMoney, 60, 'USD'));
	$t->same(101, array_sum($context->splitMinorUnits(101, 'USD', 3)));
	$allocated=$context->allocateAmount(1, 'USD', ['left'=>1, 'right'=>2]);
	$t->same(100, array_sum(array_map(static fn(Money $part): int=>$part->minorAmount(), $allocated)));
	$t->same(100, array_sum($context->allocateMinorUnits(100, 'USD', ['left'=>1, 'right'=>2])));

	$cleared=$context
		->baseCurrency(null)
		->displayCurrency(null)
		->language(null)
		->country(null)
		->availableCurrencies(null);
	$t->same($manager->state()->toArray(), $cleared->state()->toArray());
})->tag('currency', 'currency-support', 'deep-coverage')->group('framework-coverage');

test('currency exchange values normalize edge inputs and enforce freshness', static function(Context $t): void {
	$manager=dp_currency_support_manager($t);
	$empty=ExchangeRates::fromArray(
		['source'=>'invalid', 'time'=>'not-a-date', 'data'=>'not-an-array'],
		null,
		[4=>2, 'BAD'=>'not-numeric']
	);
	$t->same([], $empty->rates());
	$t->same([], $empty->currencies());
	$t->isTrue($empty->time()>0);

	$rates=ExchangeRates::fromArray([
		'source'=>'normalized',
		'time'=>'123',
		'data'=>[' usd '=>'1', 'CAD'=>'1.25', 'ZERO'=>0, 3=>2, 'BAD'=>'no'],
	], ' usd ', [' usd '=>'2', 'JPY'=>'-3', 4=>2, 'BAD'=>'no']);
	$t->same(123, $rates->time());
	$t->same(['USD'=>1.0, 'CAD'=>1.25, 'ZERO'=>0.0], $rates->rates());
	$t->same(['USD', 'CAD', 'ZERO'], $rates->currencies());
	$t->same(0, $rates->minorUnits('JPY'));
	$t->same(12.5, $rates->convert(10, 'USD', 'CAD'));
	$t->same(125, $rates->convertMinorUnits(100, 'USD', 'CAD'));
	$t->same(12.5, $rates->convertOrFail(10, 'USD', 'CAD'));
	$t->same(1.0, $rates->quoteOrFail('USD', 'USD')->rate());
	$t->same(null, $rates->quote('', 'CAD'));
	$t->same(null, $rates->quote('ZERO', 'CAD'));
	$t->instanceOf(ExchangeSnapshot::class, $rates->snapshotOrFail(0, $manager, ['display_currency'=>'CAD']));
	$t->same($rates->toArray(), $rates->jsonSerialize());

	$parsed=ExchangeRates::fromArray(['time'=>'2025-01-02T03:04:05+00:00', 'data'=>[]]);
	$t->same(strtotime('2025-01-02T03:04:05+00:00'), $parsed->time());
	$future=ExchangeRates::fromArray(['time'=>time()+60, 'data'=>['USD'=>1]], 'USD');
	$t->same(0, $future->ageSeconds());

	$staleRates=ExchangeRates::fromArray([
		'source'=>'stale-provider',
		'time'=>time()-120,
		'data'=>['USD'=>1, 'CAD'=>1.25],
	], 'USD', ['USD'=>2, 'CAD'=>2]);
	$snapshot=$staleRates->snapshot($manager, ['display_currency'=>'CAD']);
	$t->isTrue($snapshot->rates()===$staleRates);
	$t->same(['USD', 'CAD'], $snapshot->currencies());
	$t->instanceOf(ExchangeQuote::class, $snapshot->quote('USD', 'CAD'));
	$t->same('CAD', $snapshot->state()->displayCurrency());
	$t->same(['display_currency'=>'CAD'], $snapshot->contextOverrides());
	$t->throws(static fn()=>$snapshot->assertFresh(1), StaleExchangeRatesException::class);

	$staleQuote=$staleRates->quoteOrFail('USD', 'CAD');
	$t->same($staleRates->time(), $staleQuote->time());
	$t->throws(static fn()=>$staleQuote->assertFresh(1), StaleExchangeRatesException::class);
	$t->throws(static fn()=>$staleQuote->convertOrFailFresh(10, 1), StaleExchangeRatesException::class);
	$freshQuote=$future->quoteOrFail('USD', 'USD');
	$t->same(10.0, $freshQuote->convertOrFailFresh(10, 1));

	$stored=$snapshot->storeMoney($snapshot->money(10, 'CAD'), 'USD');
	$t->instanceOf(Money::class, $stored->base());
	$custom=$stored->toArray('input_', 'reporting_', 'fx_');
	$t->same(1000, $custom['input_amount_minor']);
	$t->same('CAD', $custom['input_currency']);
	$t->same(800, $custom['reporting_amount_minor']);
	$t->same('USD', $custom['reporting_currency']);
	$t->same(0.8, $custom['fx_rate']);
	$t->same('stale-provider', $custom['fx_source']);
	$t->same($staleRates->time(), $custom['fx_time']);
	$t->same('USD', $custom['fx_base_currency']);
})->tag('currency', 'currency-support', 'deep-coverage')->group('framework-coverage');

test('currency manager residual caches allocation rounding and normalization are deterministic', static function(Context $t): void {
	$manager=dp_currency_support_manager($t);
	$t->isTrue(is_string($manager->format(1, false, 'USD')));
	$first=$manager->splitAmount('1.01', 'USD', 3);
	$second=$manager->splitAmount('1.01', 'USD', 3);
	$t->isTrue($first[0]===$second[0]);

	$allocated=$manager->allocateAmount(1, 'USD', ['left'=>1, 'right'=>2]);
	$t->same(100, array_sum(array_map(static fn(Money $part): int=>$part->minorAmount(), $allocated)));
	$cashWithoutIncrement=$manager->allocateAmount(1, 'USD', ['left'=>1, 'right'=>1], true);
	$t->same(100, array_sum(array_map(static fn(Money $part): int=>$part->minorAmount(), $cashWithoutIncrement)));

	$managerInternals=$t->nonPublic($manager);
	$t->same(21, $managerInternals->invoke('minorToAllocationUnits', 103, 'CAD', true));
	$t->same(['USD'=>2], $managerInternals->invoke('minorUnitMap', ['data'=>[''=>1, 0=>1, 'USD'=>1]]));
})->tag('currency', 'currency-support', 'deep-coverage')->group('framework-coverage');
