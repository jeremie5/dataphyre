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
use Dataphyre\Currency\CurrencyState;
use Dataphyre\Currency\ExchangeQuote;
use Dataphyre\Currency\ExchangeRates;
use Dataphyre\Currency\ExchangeSnapshot;
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
$dp_currency_cov_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_currency_cov_modules_root.'/core/kernel/autoloader.php';
if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre { function tracelog(...$args): void {} }');
}
require_once $dp_currency_cov_modules_root.'/core/kernel/helper_functions.php';
require_once $dp_currency_cov_modules_root.'/core/kernel/core_functions.php';
\dataphyre\autoloader::register($dp_currency_cov_modules_root);
\dataphyre\autoloader::register_framework_modules(['currency']);
require_once $dp_currency_cov_modules_root.'/currency/kernel/currency.main.php';

test('currency manager covers state sources rates quotes conversions rounding and scoped restoration', static function(Context $t): void {
	$t->globalMap('_SESSION')->clear();
	\dataphyre\currency::apply_state([
		'base_currency'=>'USD', 'display_currency'=>'CAD', 'display_language'=>'en-CA',
		'display_country'=>'CA', 'available_currencies'=>['USD'=>'$', 'CAD'=>'C$', 'EUR'=>'€'],
	]);
	CurrencyManager::flush();
	$manager=CurrencyManager::instance();
	$t->isTrue($manager===CurrencyManager::instance());
	$state=$manager->state();
	$t->instanceOf(CurrencyState::class, $state);
	$t->same('USD', $state->baseCurrency());
	$t->same('CAD', $manager->displayCurrency());
	$t->same('en-CA', $manager->displayLanguage());
	$t->same('CA', $manager->displayCountry());
	$t->same(['USD'=>'$', 'CAD'=>'C$', 'EUR'=>'€'], $manager->availableCurrencies());
	$t->isTrue($state->hasCurrency('cad'));
	$t->isFalse($state->hasCurrency('gbp'));
	$t->same('C$', $state->symbol('cad'));
	$t->same(null, $state->symbol('gbp'));
	$t->same(0, $state->minorUnits('JPY'));
	$t->same(0.05, $state->cashRoundingIncrement('CHF'));
	$t->same($state->toArray(), $state->jsonSerialize());

	$override=$manager->state([
		'base_currency'=>' eur ', 'display_currency'=>'usd', 'display_language'=>' fr-CA ',
		'display_country'=>'fr', 'available_currencies'=>['EUR'=>'€'], 'invalid'=>'ignored',
	]);
	$t->same('EUR', $override->baseCurrency());
	$t->same('USD', $override->displayCurrency());
	$t->same('fr-CA', $override->displayLanguage());
	$t->same('FR', $override->displayCountry());
	$t->same(['EUR'=>'€'], $override->availableCurrencies());
	$t->same('USD', $manager->baseCurrency());

	$t->same(['coverage'], $manager->exchangeRateSources());
	$t->same(2, $manager->minorUnits('USD'));
	$t->same(0, $manager->minorUnits('JPY'));
	$t->same(8, $manager->minorUnits('BTC'));
	$t->same(100, $manager->minorFactor('USD'));
	$t->same(1235, $manager->amountToMinorUnits('12.345', 'USD'));
	$t->same('12.35', $manager->minorUnitsToDecimal(1235, 'USD'));
	$t->same(125, $manager->convertMinorUnits(100, 'USD', 'CAD'));
	$t->same(0.05, $manager->cashRoundingIncrement('CAD'));
	$t->same(null, $manager->cashRoundingIncrement('USD'));
	$t->same(1.05, $manager->roundAmount(1.03, 'CAD', true));
	$t->same(1.03, $manager->roundAmount(1.034, 'CAD'));

	$t->isTrue($manager->refreshSource('coverage'));
	$t->isFalse($manager->refreshSource('missing'));
	$rates=$manager->rates();
	$t->instanceOf(ExchangeRates::class, $rates);
	$t->same('USD', $rates->baseCurrency());
	$t->same('coverage', $rates->source());
	$t->same(5, $rates->count());
	$t->isTrue($rates->has('cad'));
	$t->same(1.25, $rates->rate('CAD'));
	$t->same(null, $rates->rate('GBP'));
	$t->same(1.25, $manager->rate('CAD'));
	$t->isTrue($manager->hasRate('EUR'));
	$t->isFalse($manager->hasRate('GBP'));
	$t->instanceOf(ExchangeRates::class, $manager->rates(true));
	$t->instanceOf(ExchangeRates::class, $manager->rates(false, 'coverage'));
	$t->instanceOf(ExchangeRates::class, $manager->refresh());
	$quote=$manager->quote('USD', 'CAD');
	$t->instanceOf(ExchangeQuote::class, $quote);
	$t->same(1.25, $quote->rate());
	$t->same(12.5, $manager->convert(10, 'USD', 'CAD'));
	$t->same(12.5, $manager->convertToDisplay(10, false, true, 'CAD'));
	$t->same(8.0, $manager->convertToBase(10, 'CAD'));
	$t->same(null, $manager->quote('USD', 'GBP'));
	$t->throws(static fn()=>$manager->quoteOrFail('USD', 'GBP'), RuntimeException::class);

	$before=\dataphyre\currency::state();
	$value=$manager->withStateOverrides(['display_currency'=>'EUR', 'invalid'=>'x'], static fn(): string=>\dataphyre\currency::$display_currency);
	$t->same('EUR', $value);
	$t->same($before, \dataphyre\currency::state());
	$t->throws(static fn()=>$manager->withStateOverrides(['display_currency'=>'EUR'], static fn()=>throw new LogicException('restore')), LogicException::class);
	$t->same($before, \dataphyre\currency::state());
	$t->same('plain', $manager->withStateOverrides([], static fn()=>'plain'));
	CurrencyManager::flush();
})->tag('currency', 'coverage')->group('framework-coverage');

test('currency money arithmetic allocation splitting formatting and contexts preserve exact minor units', static function(Context $t): void {
	$t->globalMap('_SESSION')->clear();
	\dataphyre\currency::apply_state([
		'base_currency'=>'USD', 'display_currency'=>'CAD', 'display_language'=>'en-CA',
		'display_country'=>'CA', 'available_currencies'=>['USD'=>'$', 'CAD'=>'C$'],
	]);
	$manager=new CurrencyManager();
	$manager->refreshSource('coverage');
	$money=$manager->money('10.25', 'usd', ['display_currency'=>'CAD']);
	$t->instanceOf(Money::class, $money);
	$t->same(10.25, $money->amount());
	$t->same(1025, $money->minorAmount());
	$t->same('10.25', $money->decimalAmount());
	$t->same('USD', $money->currency());
	$t->same(['display_currency'=>'CAD'], $money->contextOverrides());
	$t->isFalse($money->isZero());
	$t->same(2, $money->minorUnits());
	$t->same(null, $money->cashRoundingIncrement());
	$t->instanceOf(Money::class, $money->withAmount(20));
	$t->same(10.25, $money->rounded()->amount());
	$t->same(12.81, $money->convertedTo('CAD')->amount());
	$t->same(15.25, $money->add(5)->amount());
	$t->same(5.25, $money->subtract(5)->amount());
	$t->same(20.5, $money->multiply(2)->amount());
	$t->same(5.13, $money->divide(2)->amount());
	$t->throws(static fn()=>$money->divide(0), DivisionByZeroError::class);
	$t->same(0, $money->compare('10.25'));
	$t->isTrue($money->equals('10.25'));
	$t->isTrue($money->greaterThan(10));
	$t->isTrue($money->greaterThanOrEqual('10.25'));
	$t->isTrue($money->lessThan(11));
	$t->isTrue($money->lessThanOrEqual('10.25'));
	$t->same($money->toArray(), $money->jsonSerialize());
	$t->same(1025, Money::fromMinor(1025, 'USD', $manager)->minorAmount());
	$t->same(0, $manager->money(null, 'USD')->minorAmount());

	$parts=$money->split(3);
	$t->same(3, count($parts));
	$t->same(1025, array_sum(array_map(static fn(Money $part): int=>$part->minorAmount(), $parts)));
	$t->same([342, 342, 341], $money->splitMinor(3));
	$t->same([], $manager->splitAmount(10, 'USD', 0));
	$t->same(
		array_map(static fn(Money $part): int=>$part->minorAmount(), $parts),
		array_map(static fn(Money $part): int=>$part->minorAmount(), $manager->splitAmount('10.25', 'USD', 3))
	);
	$negative=$manager->splitAmount(-1, 'USD', 3);
	$t->same(-100, array_sum(array_map(static fn(Money $part): int=>$part->minorAmount(), $negative)));
	$cash=$manager->splitAmount(1.03, 'CAD', 2, true);
	$t->same(105, array_sum(array_map(static fn(Money $part): int=>$part->minorAmount(), $cash)));

	$allocated=$money->allocate(['first'=>1, 'second'=>2, 'bad'=>0, 'text'=>'x']);
	$t->same(['first', 'second'], array_keys($allocated));
	$t->same(1025, array_sum(array_map(static fn(Money $part): int=>$part->minorAmount(), $allocated)));
	$t->same(['first'=>342, 'second'=>683], $money->allocateMinor(['first'=>1, 'second'=>2]));
	$t->same([], $manager->allocateAmount(10, 'USD', [0, -1, 'x']));
	$t->same(['a'=>33, 'b'=>67], $manager->allocateMinorUnits(100, 'USD', ['a'=>1, 'b'=>2]));
	$t->same([34, 33, 33], $manager->splitMinorUnits(100, 'USD', 3));

	$context=$manager->context('EUR', 'fr-CA', 'FR', 'USD', ['USD'=>'$', 'EUR'=>'€']);
	$t->instanceOf(CurrencyContext::class, $context);
	$context=$context->baseCurrency('USD')->displayCurrency('CAD')->language('en-CA')->country('CA')->availableCurrencies(['USD'=>'$', 'CAD'=>'C$']);
	$t->same('CAD', $context->state()->displayCurrency());
	$t->same(100, $context->minorFactor('USD'));
	$t->same(125, $context->convertMinorUnits(100, 'USD', 'CAD'));
	$t->same(3, count($context->splitAmount(1, 'USD', 3)));
	$t->same(['a'=>50, 'b'=>50], $context->allocateMinorUnits(100, 'USD', ['a'=>1, 'b'=>1]));
	$t->instanceOf(Money::class, $context->money(1));
	$t->instanceOf(Money::class, $context->moneyFromMinor(100));
})->tag('currency', 'coverage')->group('framework-coverage');

test('currency snapshots quotes and stored money expose freshness conversion and serialization contracts', static function(Context $t): void {
	$t->globalMap('_SESSION')->clear();
	\dataphyre\currency::apply_state([
		'base_currency'=>'USD', 'display_currency'=>'CAD', 'display_language'=>'en-CA',
		'display_country'=>'CA', 'available_currencies'=>['USD'=>'$', 'CAD'=>'C$'],
	]);
	$manager=new CurrencyManager();
	$manager->refreshSource('coverage');
	$snapshot=$manager->snapshot();
	$t->instanceOf(ExchangeSnapshot::class, $snapshot);
	$t->same('USD', $snapshot->baseCurrency());
	$t->same('coverage', $snapshot->source());
	$t->isTrue($snapshot->ageSeconds()>=0);
	$t->isFalse($snapshot->isStale(60));
	$t->isTrue($snapshot->assertFresh(60)===$snapshot);
	$t->isTrue($snapshot->has('CAD'));
	$t->same(1.25, $snapshot->rate('CAD'));
	$t->same(5, $snapshot->count());
	$t->same(0, $snapshot->minorUnits('JPY'));
	$t->same(12.5, $snapshot->convert(10, 'USD', 'CAD'));
	$t->same(125, $snapshot->convertMinorUnits(100, 'USD', 'CAD'));
	$t->same(12.5, $snapshot->convertOrFail(10, 'USD', 'CAD'));
	$t->same(12.5, $snapshot->convertOrFailFresh(10, 'USD', 'CAD', 60));
	$t->throws(static fn()=>$snapshot->quoteOrFail('USD', 'GBP'), RuntimeException::class);
	$t->instanceOf(Money::class, $snapshot->money(1));
	$t->instanceOf(Money::class, $snapshot->moneyFromMinor(100));
	$t->same($snapshot->toArray(), $snapshot->jsonSerialize());

	$quote=$snapshot->quoteOrFail('USD', 'CAD');
	$t->same('USD', $quote->baseCurrency());
	$t->same('USD', $quote->sourceCurrency());
	$t->same('CAD', $quote->targetCurrency());
	$t->same(2, $quote->sourceMinorUnits());
	$t->same(2, $quote->targetMinorUnits());
	$t->same(1.25, $quote->rate());
	$t->same('coverage', $quote->source());
	$t->isFalse($quote->isStale(60));
	$t->isTrue($quote->assertFresh(60)===$quote);
	$t->same(12.5, $quote->convert(10));
	$t->same(125, $quote->convertMinorUnits(100));
	$t->same(0.8, $quote->inverse()->rate());
	$t->same($quote->toArray(), $quote->jsonSerialize());

	$money=$snapshot->money(10, 'CAD');
	$t->same(8.0, $snapshot->convertMoney($money, 'USD')->amount());
	$t->same(8.0, $snapshot->convertMoneyOrFailFresh($money, 'USD', 60)->amount());
	$stored=$snapshot->storeMoney($money, 'USD');
	$t->instanceOf(StoredMoney::class, $stored);
	$t->same('CAD', $stored->originalCurrency());
	$t->same('USD', $stored->baseCurrency());
	$t->same(10.0, $stored->originalAmount());
	$t->same(8.0, $stored->baseAmount());
	$t->same(1000, $stored->originalMinorAmount());
	$t->same(800, $stored->baseMinorAmount());
	$t->same(0.8, $stored->exchangeRate());
	$t->same('coverage', $stored->exchangeSource());
	$t->same('USD', $stored->exchangeSnapshotBaseCurrency());
	$t->isTrue($stored->original()===$money);
	$t->isTrue($stored->snapshot()===$snapshot);
	$t->instanceOf(ExchangeQuote::class, $stored->quote());
	$t->same($stored->toArray(), $stored->jsonSerialize());
	$t->instanceOf(StoredMoney::class, $snapshot->storeMoneyOrFailFresh($money, 60, 'USD'));
	$t->instanceOf(StoredMoney::class, $manager->storeMoney($money, 'USD'));
	$t->instanceOf(StoredMoney::class, $manager->storeMoneyOrFailFresh($money, 60, 'USD'));
	$t->instanceOf(ExchangeQuote::class, $manager->quoteOrFailFresh('USD', 'CAD', 60));
	$t->same(12.5, $manager->convertOrFailFresh(10, 'USD', 'CAD', 60));
	$t->same(8.0, $manager->convertMoneyOrFailFresh($money, 'USD', 60)->amount());
})->tag('currency', 'coverage')->group('framework-coverage');

test('currency static facade delegates the complete typed surface', static function(Context $t): void {
	$t->globalMap('_SESSION')->clear();
	\dataphyre\currency::apply_state([
		'base_currency'=>'USD', 'display_currency'=>'CAD', 'display_language'=>'en-CA',
		'display_country'=>'CA', 'available_currencies'=>['USD'=>'$', 'CAD'=>'C$'],
	]);
	Currency::flush();
	Currency::registerSource('coverage-two', static fn()=>['rates'=>['USD'=>1, 'CAD'=>1.25]]);
	Currency::registerSources(['coverage-three'=>static fn()=>['USD'=>1, 'EUR'=>0.8], 'invalid'=>'no']);
	$t->instanceOf(CurrencyManager::class, Currency::manager());
	$t->instanceOf(CurrencyState::class, Currency::state());
	$t->instanceOf(CurrencyContext::class, Currency::context('CAD'));
	$t->same('USD', Currency::baseCurrency());
	$t->same('CAD', Currency::displayCurrency());
	$t->same('en-CA', Currency::displayLanguage());
	$t->same('CA', Currency::displayCountry());
	$t->same(2, Currency::minorUnits('USD'));
	$t->same(100, Currency::minorFactor('USD'));
	$t->same(100, Currency::amountToMinorUnits(1, 'USD'));
	$t->same('1.00', Currency::minorUnitsToDecimal(100, 'USD'));
	$t->isTrue(Currency::refreshSource('coverage'));
	$t->instanceOf(ExchangeRates::class, Currency::rates());
	$t->instanceOf(ExchangeRates::class, Currency::refresh('coverage'));
	$t->instanceOf(ExchangeSnapshot::class, Currency::snapshot());
	$t->instanceOf(ExchangeSnapshot::class, Currency::snapshotOrFail(60));
	$t->same(1.25, Currency::rate('CAD'));
	$t->isTrue(Currency::hasRate('CAD'));
	$t->instanceOf(ExchangeQuote::class, Currency::quote('USD', 'CAD'));
	$t->instanceOf(ExchangeQuote::class, Currency::quoteOrFail('USD', 'CAD'));
	$t->same(12.5, Currency::convert(10, 'USD', 'CAD'));
	$t->same(12.5, Currency::convertToDisplay(10, false, true, 'CAD'));
	$t->same(8.0, Currency::convertToBase(10, 'CAD'));
	$t->instanceOf(Money::class, Currency::money(10, 'USD'));
	$t->instanceOf(Money::class, Currency::moneyFromMinor(1000, 'USD'));
	$t->same(3, count(Currency::splitAmount(1, 'USD', 3)));
	$t->same([34, 33, 33], Currency::splitMinorUnits(100, 'USD', 3));
	$t->same(['a', 'b'], array_keys(Currency::allocateAmount(1, 'USD', ['a'=>1, 'b'=>1])));
	$t->same(['a'=>50, 'b'=>50], Currency::allocateMinorUnits(100, 'USD', ['a'=>1, 'b'=>1]));
	Currency::flush();
})->tag('currency', 'coverage')->group('framework-coverage');
