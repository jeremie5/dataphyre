<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Currency\CurrencyManager;
use Dataphyre\Currency\Money;
use Dataphyre\Currency\StoredMoney;
use Dataphyre\Database\CurrencyBridge;
use Dataphyre\Test\Context;
use Dataphyre\Test\GlobalState;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'currency'=>true, 'sql'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
if(!defined('DP_CURRENCY_CFG')){
	define('DP_CURRENCY_CFG', [
		'exchange_rate_sources'=>[],
		'exchange_rate_callbacks'=>[],
		'minor_units'=>[],
		'cash_rounding_increments'=>[],
	]);
}
/** @return mixed */
function dp_currency_bridge_private(Context $t,string $method,mixed ...$arguments): mixed {
	return $t->nonPublic(CurrencyBridge::class)->invoke($method,...$arguments);
}

function dp_currency_bridge_boot(Context $t): GlobalState {
	$session=$t->globalMap('_SESSION')->clear();
	$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
	require_once $modulesRoot.'/core/kernel/autoloader.php';
	if(!function_exists('dataphyre\\tracelog')){
		\Dataphyre\Test\define_test_symbols('namespace dataphyre { function tracelog(...$arguments): void {} }');
	}
	require_once $modulesRoot.'/core/kernel/helper_functions.php';
	require_once $modulesRoot.'/core/kernel/core_functions.php';
	\dataphyre\autoloader::register($modulesRoot);
	\dataphyre\autoloader::register_framework_modules(['currency','sql']);
	require_once $modulesRoot.'/currency/kernel/currency.main.php';
	return $session;
}

function dp_currency_bridge_manager(Context $t): CurrencyManager {
	dp_currency_bridge_boot($t);
	\dataphyre\currency::apply_state([
		'base_currency'=>'USD',
		'display_currency'=>'CAD',
		'display_language'=>'en-CA',
		'display_country'=>'CA',
		'available_currencies'=>['USD'=>'$', 'CAD'=>'C$', 'EUR'=>'EUR'],
	]);
	CurrencyManager::flush();
	$manager=CurrencyManager::instance();
	$manager->registerSource('currency-bridge', static fn(): array=>[
		'rates'=>['USD'=>1.0, 'CAD'=>1.25, 'EUR'=>0.8, 'JPY'=>150.0],
		'time'=>time(),
		'source'=>'currency-bridge',
	]);
	$manager->refreshSource('currency-bridge');
	return $manager;
}

/** @param array<string,mixed> $overrides @return array<string,mixed> */
function dp_currency_bridge_stored_row(array $overrides=[]): array {
	return array_replace([
		'original_amount_minor'=>1000,
		'original_currency'=>'CAD',
		'base_amount_minor'=>800,
		'base_currency'=>'USD',
		'exchange_rate'=>0.8,
		'exchange_source'=>'currency-bridge',
		'exchange_time'=>1700000000,
		'exchange_base_currency'=>'USD',
	], $overrides);
}

test('currency bridge deep money mapping hydration and minor-unit validation', static function(Context $t): void {
	dp_currency_bridge_manager($t);

	$fixed=CurrencyBridge::normalizeMoneyMapping(' amount_minor ', 'ignored', ' usd ', null, 'bridge-owner');
	$t->same([
		'amount_column'=>'amount_minor',
		'currency_column'=>null,
		'currency'=>'USD',
		'target_column'=>'amount_minor',
	], $fixed);
	$dynamic=CurrencyBridge::normalizeMoneyMapping('amount', ' currency ', null, ' hydrated ', 'bridge-owner');
	$t->same('currency', $dynamic['currency_column']);
	$t->same('hydrated', $dynamic['target_column']);

	$t->throws(
		static fn()=>CurrencyBridge::normalizeMoneyMapping('amount', null, null, null, 'bridge-owner'),
		InvalidArgumentException::class
	);
	$t->throws(
		static fn()=>CurrencyBridge::normalizeMoneyMapping('bad-column', null, 'USD', null, 'bridge-owner'),
		InvalidArgumentException::class
	);
	$t->throws(
		static fn()=>CurrencyBridge::normalizeMoneyMapping('amount', null, 'USD', 'bad-target', 'bridge-owner'),
		InvalidArgumentException::class
	);
	$t->throws(
		static fn()=>CurrencyBridge::normalizeMoneyMapping('amount', 'bad-currency', null, null, 'bridge-owner'),
		InvalidArgumentException::class
	);
	$t->throws(
		static fn()=>CurrencyBridge::normalizeMoneyMapping('amount', null, ' ', null, 'bridge-owner'),
		InvalidArgumentException::class
	);

	$minor=CurrencyBridge::applyMoneyMapping(['amount_minor'=>'00125'], $fixed, 'bridge-owner');
	$t->instanceOf(Money::class, $minor['amount_minor']);
	$t->same(125, $minor['amount_minor']->minorAmount());
	$decimal=CurrencyBridge::applyMoneyMapping(['amount'=>'12.34', 'currency'=>' cad '], $dynamic, 'bridge-owner');
	$t->same('CAD', $decimal['hydrated']->currency());
	$t->same('12.34', $decimal['hydrated']->decimalAmount());

	$existing=CurrencyBridge::money('4.25', 'USD');
	$preserved=CurrencyBridge::applyMoneyMapping(['amount'=>$existing, 'currency'=>'CAD'], $dynamic, 'bridge-owner');
	$t->isTrue($existing===$preserved['hydrated']);
	$t->same(null, CurrencyBridge::applyMoneyMapping(['amount'=>null, 'currency'=>'USD'], $dynamic, 'bridge-owner')['hydrated']);
	$t->same(null, CurrencyBridge::applyMoneyMapping(['amount'=>'  ', 'currency'=>'USD'], $dynamic, 'bridge-owner')['hydrated']);

	$t->throws(
		static fn()=>CurrencyBridge::applyMoneyMapping([], $dynamic, 'bridge-owner'),
		RuntimeException::class
	);
	$t->throws(
		static fn()=>CurrencyBridge::applyMoneyMapping(['amount'=>1], $dynamic, 'bridge-owner'),
		RuntimeException::class
	);
	$t->throws(
		static fn()=>CurrencyBridge::applyMoneyMapping(['amount'=>1, 'currency'=>[]], $dynamic, 'bridge-owner'),
		InvalidArgumentException::class
	);
	$t->throws(
		static fn()=>CurrencyBridge::applyMoneyMapping(['amount'=>1, 'currency'=>' '], $dynamic, 'bridge-owner'),
		InvalidArgumentException::class
	);
	$t->throws(static fn()=>CurrencyBridge::money(1, ' '), InvalidArgumentException::class);

	$t->same(7, dp_currency_bridge_private($t,'minorAmountFromColumn', 7, 'amount_minor'));
	$t->same(0, dp_currency_bridge_private($t,'minorAmountFromColumn', '000', 'amount_minor'));
	$t->same(0, dp_currency_bridge_private($t,'minorAmountFromColumn', '-0', 'amount_minor'));
	$t->same(PHP_INT_MAX, dp_currency_bridge_private($t,'minorAmountFromColumn', (string)PHP_INT_MAX, 'amount_minor'));
	$t->same(PHP_INT_MIN, dp_currency_bridge_private($t,'minorAmountFromColumn', '-9223372036854775808', 'amount_minor'));
	foreach(['9223372036854775808', '-9223372036854775809', '1.5', '', 'word'] as $invalid){
		$t->throws(
			static fn()=>dp_currency_bridge_private($t,'minorAmountFromColumn', $invalid, 'amount_minor'),
			InvalidArgumentException::class
		);
	}
	$t->throws(
		static fn()=>dp_currency_bridge_private($t,'minorAmountFromColumn', 1.5, 'amount_minor'),
		InvalidArgumentException::class
	);
	$t->isTrue($existing===dp_currency_bridge_private($t,'moneyFromColumnValue', $existing, 'USD', 'amount_minor'));
	$t->isTrue(CurrencyBridge::isMoney($existing));
	$t->isFalse(CurrencyBridge::isMoney('12.00'));
	$t->isFalse(CurrencyBridge::isStoredMoney($existing));
})->tag('sql', 'currency', 'coverage')->group('framework-coverage');

test('currency bridge deep stored-money mapping hydration and normalization branches', static function(Context $t): void {
	$manager=dp_currency_bridge_manager($t);
	$definition=[
		'original_prefix'=>'orig_',
		'base_prefix'=>'normalized_',
		'exchange_prefix'=>'fx_',
		'base_currency'=>' usd ',
		'target'=>'ledger_money',
	];
	$prefixed=CurrencyBridge::normalizeStoredMoneyMapping($definition, null, 'bridge-owner');
	$t->same('orig_amount_minor', $prefixed['original_amount_column']);
	$t->same('orig_currency', $prefixed['original_currency_column']);
	$t->same('normalized_amount_minor', $prefixed['base_amount_column']);
	$t->same('normalized_currency', $prefixed['base_currency_column']);
	$t->same('fx_rate', $prefixed['exchange_rate_column']);
	$t->same('fx_source', $prefixed['exchange_source_column']);
	$t->same('fx_time', $prefixed['exchange_time_column']);
	$t->same('fx_base_currency', $prefixed['exchange_base_currency_column']);
	$t->same('USD', $prefixed['base_currency']);
	$t->same('ledger_money', $prefixed['target_column']);
	$t->same($prefixed, CurrencyBridge::normalizeStoredMoneyMapping($definition, null, 'bridge-owner'));

	$t->same(
		'column_target',
		CurrencyBridge::normalizeStoredMoneyMapping(['target_column'=>'column_target'], null, 'column-owner')['target_column']
	);
	$t->same(
		'argument_target',
		CurrencyBridge::normalizeStoredMoneyMapping(['target'=>'definition_target'], 'argument_target', 'argument-owner')['target_column']
	);
	$mapping=CurrencyBridge::normalizeStoredMoneyMapping([], null, 'default-owner');
	$t->same('stored_money', $mapping['target_column']);
	$t->same(null, $mapping['base_currency']);
	$t->throws(
		static fn()=>CurrencyBridge::normalizeStoredMoneyMapping(['original_amount_column'=>'bad-column'], null, 'bridge-owner'),
		InvalidArgumentException::class
	);

	$row=CurrencyBridge::applyStoredMoneyMapping(dp_currency_bridge_stored_row(), $mapping, 'bridge-owner');
	$t->instanceOf(StoredMoney::class, $row['stored_money']);
	$stored=$row['stored_money'];
	$t->same('CAD', $stored->originalCurrency());
	$t->same('USD', $stored->baseCurrency());
	$t->same(1000, $stored->originalMinorAmount());
	$t->same(800, $stored->baseMinorAmount());
	$t->same(0.8, $stored->exchangeRate());
	$t->isTrue(CurrencyBridge::isStoredMoney($stored));
	$t->isTrue($stored===CurrencyBridge::applyStoredMoneyMapping(
		['stored_money'=>$stored],
		$mapping,
		'bridge-owner'
	)['stored_money']);

	$missing=dp_currency_bridge_stored_row();
	unset($missing['exchange_base_currency']);
	$t->throws(
		static fn()=>CurrencyBridge::applyStoredMoneyMapping($missing, $mapping, 'bridge-owner'),
		RuntimeException::class
	);
	$t->same(null, CurrencyBridge::applyStoredMoneyMapping(
		dp_currency_bridge_stored_row(['original_amount_minor'=>' ']),
		$mapping,
		'bridge-owner'
	)['stored_money']);
	$t->same(null, CurrencyBridge::applyStoredMoneyMapping(
		dp_currency_bridge_stored_row(['base_amount_minor'=>null]),
		$mapping,
		'bridge-owner'
	)['stored_money']);

	$originalMoney=$manager->moneyFromMinor(1000, 'CAD');
	$moneyRow=CurrencyBridge::applyStoredMoneyMapping(
		dp_currency_bridge_stored_row(['original_amount_minor'=>$originalMoney]),
		$mapping,
		'bridge-owner'
	);
	$t->isTrue($originalMoney===$moneyRow['stored_money']->original());

	foreach([
		['original_amount_minor'=>'not-numeric'],
		['original_currency'=>[]],
		['exchange_rate'=>0],
		['exchange_source'=>' '],
		['exchange_time'=>'not-a-time'],
		['exchange_base_currency'=>[]],
	] as $override){
		$t->throws(
			static fn()=>CurrencyBridge::applyStoredMoneyMapping(
				dp_currency_bridge_stored_row($override),
				$mapping,
				'bridge-owner'
			),
			InvalidArgumentException::class
		);
	}

	$t->same(['USD'=>1.0], dp_currency_bridge_private($t,'storedMoneyRateMap', 'USD', 'USD', 'USD', 3.0));
	$t->same(['USD'=>1.0, 'CAD'=>1.25], dp_currency_bridge_private($t,'storedMoneyRateMap', 'USD', 'CAD', 'USD', 0.8));
	$t->same(['CAD'=>1.0, 'USD'=>0.8], dp_currency_bridge_private($t,'storedMoneyRateMap', 'CAD', 'CAD', 'USD', 0.8));
	$t->same(['EUR'=>1.0, 'CAD'=>1.25, 'USD'=>1.0], dp_currency_bridge_private($t,'storedMoneyRateMap', 'EUR', 'CAD', 'USD', 0.8));

	$t->same(1.5, dp_currency_bridge_private($t,'normalizeStoredRate', '1.5', 'bridge-owner', 'rate'));
	$t->throws(static fn()=>dp_currency_bridge_private($t,'normalizeStoredRate', 'no', 'bridge-owner', 'rate'), InvalidArgumentException::class);
	$t->same('123', dp_currency_bridge_private($t,'normalizeStoredSource', 123, 'bridge-owner', 'source'));
	$t->throws(static fn()=>dp_currency_bridge_private($t,'normalizeStoredSource', [], 'bridge-owner', 'source'), InvalidArgumentException::class);
	$t->same(123, dp_currency_bridge_private($t,'normalizeStoredTimestamp', 123, 'bridge-owner', 'time'));
	$before=time();
	$t->isTrue(dp_currency_bridge_private($t,'normalizeStoredTimestamp', 0, 'bridge-owner', 'time') >= $before);
	$t->same(456, dp_currency_bridge_private($t,'normalizeStoredTimestamp', '456', 'bridge-owner', 'time'));
	$t->isTrue(dp_currency_bridge_private($t,'normalizeStoredTimestamp', '0', 'bridge-owner', 'time') >= $before);
	$t->same(strtotime('2024-01-02 03:04:05 UTC'), dp_currency_bridge_private($t,'normalizeStoredTimestamp', '2024-01-02 03:04:05 UTC', 'bridge-owner', 'time'));
	$t->throws(static fn()=>dp_currency_bridge_private($t,'normalizeStoredTimestamp', [], 'bridge-owner', 'time'), InvalidArgumentException::class);
	$t->same('USD', dp_currency_bridge_private($t,'normalizeStoredCurrency', ' usd ', 'bridge-owner', 'currency'));
	$t->throws(static fn()=>dp_currency_bridge_private($t,'normalizeStoredCurrency', [], 'bridge-owner', 'currency'), InvalidArgumentException::class);
	$t->isTrue(dp_currency_bridge_private($t,'isBlankAmount', null));
	$t->isTrue(dp_currency_bridge_private($t,'isBlankAmount', '  '));
	$t->isFalse(dp_currency_bridge_private($t,'isBlankAmount', 0));
})->tag('sql', 'currency', 'coverage')->group('framework-coverage');

test('currency bridge deep write expansion comparisons and scalar helper fallbacks', static function(Context $t): void {
	$manager=dp_currency_bridge_manager($t);
	$usd=$manager->money('10.00', 'USD');
	$cad=$manager->money('10.00', 'CAD');
	$stored=$manager->storeMoney($cad, 'USD');
	$storedMapping=CurrencyBridge::normalizeStoredMoneyMapping(['base_currency'=>'USD'], null, 'bridge-owner');
	$dynamicMapping=CurrencyBridge::normalizeMoneyMapping('amount_minor', 'currency', null, 'money', 'bridge-owner');
	$fixedMapping=CurrencyBridge::normalizeMoneyMapping('fixed_minor', null, 'USD', 'fixed', 'bridge-owner');

	$expanded=CurrencyBridge::expandWriteFields(['stored_money'=>$stored], [], [$storedMapping], 'bridge-owner');
	$t->same(1000, $expanded['original_amount_minor']);
	$t->same('CAD', $expanded['original_currency']);
	$t->same(800, $expanded['base_amount_minor']);
	$t->same('USD', $expanded['base_currency']);
	$t->same(0.8, $expanded['exchange_rate']);
	$t->same('currency-bridge', $expanded['exchange_source']);
	$t->same('USD', $expanded['exchange_base_currency']);
	$t->isFalse(array_key_exists('stored_money', $expanded));

	$storedFromMoney=CurrencyBridge::expandWriteFields(['stored_money'=>$cad], [], [$storedMapping], 'bridge-owner');
	$t->same(1000, $storedFromMoney['original_amount_minor']);
	$t->same(800, $storedFromMoney['base_amount_minor']);
	$t->same(['other'=>1], CurrencyBridge::expandWriteFields(['other'=>1], [], [$storedMapping], 'bridge-owner'));
	$t->same(['stored_money'=>'plain'], CurrencyBridge::expandWriteFields(['stored_money'=>'plain'], [], [$storedMapping], 'bridge-owner'));

	$moneyFields=CurrencyBridge::expandWriteFields(['money'=>$usd], [$dynamicMapping], [], 'bridge-owner');
	$t->same(1000, $moneyFields['amount_minor']);
	$t->same('USD', $moneyFields['currency']);
	$t->isFalse(array_key_exists('money', $moneyFields));
	$amountCandidate=CurrencyBridge::expandWriteFields(['amount_minor'=>$usd], [$dynamicMapping], [], 'bridge-owner');
	$t->same(1000, $amountCandidate['amount_minor']);
	$t->same('USD', $amountCandidate['currency']);
	$converted=CurrencyBridge::expandWriteFields(['fixed'=>$cad], [$fixedMapping], [], 'bridge-owner');
	$t->same(800, $converted['fixed_minor']);
	$t->same(['fixed'=>'plain'], CurrencyBridge::expandWriteFields(['fixed'=>'plain'], [$fixedMapping], [], 'bridge-owner'));

	$t->isTrue($usd===CurrencyBridge::expandWriteFields(['unmapped'=>$usd], [], [], 'bridge-owner', false)['unmapped']);
	$t->throws(
		static fn()=>CurrencyBridge::expandWriteFields(['unmapped'=>$usd], [], [], 'bridge-owner'),
		InvalidArgumentException::class
	);
	$t->throws(
		static fn()=>CurrencyBridge::expandWriteFields(['unmapped'=>$stored], [], [], 'bridge-owner'),
		InvalidArgumentException::class
	);
	$t->same(['plain'=>1], CurrencyBridge::expandWriteFields(['plain'=>1], [], [], 'bridge-owner'));

	$comparable=CurrencyBridge::normalizeComparableValue($cad, ' usd ', 'bridge-owner', 'amount_minor');
	$t->same(800, $comparable['amount']);
	$t->same('USD', $comparable['currency']);
	$t->same(['amount'=>'10.00', 'currency'=>'CAD'], CurrencyBridge::normalizeComparableValue($cad, null, 'bridge-owner', 'amount'));
	$t->same(['amount'=>500, 'currency'=>'USD'], CurrencyBridge::normalizeComparableValue(5, 'USD', 'bridge-owner', 'amount_minor'));
	$t->same(['amount'=>'1.25', 'currency'=>'USD'], CurrencyBridge::normalizeComparableValue(' 1.25 ', 'USD', 'bridge-owner', 'amount'));
	$t->throws(
		static fn()=>CurrencyBridge::normalizeComparableValue(5, null, 'bridge-owner', 'amount'),
		InvalidArgumentException::class
	);
	$t->throws(
		static fn()=>CurrencyBridge::normalizeComparableValue(1.5, 'USD', 'bridge-owner', 'amount'),
		InvalidArgumentException::class
	);

	$t->same(1000, dp_currency_bridge_private($t,'moneyStorageAmount', $usd, 'amount_minor'));
	$t->same('10.00', dp_currency_bridge_private($t,'moneyStorageAmount', $usd, 'amount'));
	$amountOnly=new class {
		public function amount(): float { return 3.5; }
	};
	$t->same(3.5, dp_currency_bridge_private($t,'moneyStorageAmount', $amountOnly, 'amount'));
	$t->same(1000, dp_currency_bridge_private($t,'storedMoneyStorageAmount', $stored, 'original_amount_minor', 'original'));
	$t->same('10.00', dp_currency_bridge_private($t,'storedMoneyStorageAmount', $stored, 'original_amount', 'original'));
	$t->same(800, dp_currency_bridge_private($t,'storedMoneyStorageAmount', $stored, 'base_amount_minor', 'base'));
	$t->same('8.00', dp_currency_bridge_private($t,'storedMoneyStorageAmount', $stored, 'base_amount', 'base'));
	$storedAmountOnly=new class {
		public function originalAmount(): float { return 2.5; }
		public function baseAmount(): float { return 2.0; }
	};
	$t->same(2.5, dp_currency_bridge_private($t,'storedMoneyStorageAmount', $storedAmountOnly, 'original_amount', 'original'));
	$t->same(2.0, dp_currency_bridge_private($t,'storedMoneyStorageAmount', $storedAmountOnly, 'base_amount', 'base'));

	$t->same(['money', 'amount'], dp_currency_bridge_private($t,'writeCandidateColumns', 'money', 'amount'));
	$t->same(['amount'], dp_currency_bridge_private($t,'writeCandidateColumns', 'amount', 'amount'));
	$t->isTrue(dp_currency_bridge_private($t,'isMinorAmountColumn', 'amount_minor'));
	$t->isTrue(dp_currency_bridge_private($t,'isMinorAmountColumn', 'orders.amount_minor'));
	$t->isFalse(dp_currency_bridge_private($t,'isMinorAmountColumn', 'amount'));
	$t->isTrue(CurrencyBridge::isMoney($usd));
	$t->isTrue(CurrencyBridge::isStoredMoney($stored));
})->tag('sql', 'currency', 'coverage')->group('framework-coverage');
