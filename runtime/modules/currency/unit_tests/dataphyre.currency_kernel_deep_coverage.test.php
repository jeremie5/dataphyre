<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	final class core{
		public static function dialback(string $name, mixed ...$arguments): mixed {
			$value=\Dataphyre\Test\TestState::channel('currency.kernel')->get('dialback:'.$name);
			return is_callable($value) ? $value(...$arguments) : $value;
		}

		public static function unavailable(mixed ...$arguments): bool {
			\Dataphyre\Test\TestState::channel('currency.kernel')->increment('unavailable_calls');
			return false;
		}
	}

	final class sql{}
}

namespace {
	use Dataphyre\Test\Context;
	use Dataphyre\Test\GlobalState;
	use Dataphyre\Test\NonPublicAccess;
	use Dataphyre\Test\TestState;
	use function Dataphyre\Test\test;

	/** Module-load-safe fake for the legacy Currency SQL function contract. */
	final class CurrencySqlProbe {
		/** @var list<array<int,mixed>> */
		private static array $tableDefinitions=[];
		/** @var list<array<int,mixed>> */
		private static array $inserts=[];
		/** @var list<array<int,mixed>> */
		private static array $selects=[];
		private static mixed $selectResult=false;

		/** @param array<int,mixed> $arguments */
		public static function defineTable(array $arguments): void {
			self::$tableDefinitions[]=$arguments;
		}

		/** @param array<int,mixed> $arguments */
		public static function insert(array $arguments): bool {
			self::$inserts[]=$arguments;
			return true;
		}

		/** @param array<int,mixed> $arguments */
		public static function select(array $arguments): mixed {
			self::$selects[]=$arguments;
			return self::$selectResult;
		}

		public static function respondToSelectWith(mixed $result): void {
			self::$selectResult=$result;
		}

		public static function resetScenario(): void {
			self::$inserts=[];
			self::$selects=[];
			self::$selectResult=false;
		}

		/** @return list<array<int,mixed>> */
		public static function tableDefinitions(): array { return self::$tableDefinitions; }
		/** @return list<array<int,mixed>> */
		public static function inserts(): array { return self::$inserts; }
		/** @return list<array<int,mixed>> */
		public static function selects(): array { return self::$selects; }
	}

	final class CurrencyKernelScenario {
		public function __construct(
			public readonly TestState $runtime,
			public readonly GlobalState $session,
			public readonly GlobalState $isTask,
		) {}
	}

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
	if(!function_exists('sql_define_table')){
		function sql_define_table(mixed ...$arguments): void {
			CurrencySqlProbe::defineTable($arguments);
		}
	}
	if(!function_exists('sql_insert')){
		function sql_insert(mixed ...$arguments): mixed {
			return CurrencySqlProbe::insert($arguments);
		}
	}
	if(!function_exists('sql_select')){
		function sql_select(mixed ...$arguments): mixed {
			return CurrencySqlProbe::select($arguments);
		}
	}
	if(!function_exists('locale')){
		function locale(string $key, string $fallback=''): string {
			return $fallback;
		}
	}

	if(!defined('DP_CURRENCY_CFG')){
		define('DP_CURRENCY_CFG', [
			'exchange_rate_sources'=>[17, ' ', "\t"],
			'exchange_rate_callbacks'=>['invalid'=>'not-callable'],
			'minor_units'=>['BTC'=>'8', 'NEGATIVE'=>-3, 'INVALID'=>'not-numeric'],
			'cash_rounding_increments'=>['CAD'=>'0.05', 'ZERO'=>0, 'INVALID'=>'not-numeric', 'TINY'=>0.00001],
		]);
	}

	$currency_deep_runtime=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\');
	require_once $currency_deep_runtime.'/modules/currency/kernel/currency.main.php';

	function currency_deep_scenario(Context $t, NonPublicAccess $private): CurrencyKernelScenario {
		$runtime=$t->state('currency.kernel',['unavailable_calls'=>0]);
		$session=$t->globalMap('_SESSION')->clear();
		$isTask=$t->global('is_task')->replace(false);
		$private
			->replacePropertyForTest('exchange_rate_callbacks',[])
			->replacePropertyForTest('exchange_rate_callbacks_loaded',false)
			->replacePropertyForTest('bcmath_available_override',null);
		\dataphyre\currency::apply_state([
			'base_currency'=>'USD',
			'display_currency'=>'CAD',
			'display_language'=>'en-CA',
			'display_country'=>'CA',
			'available_currencies'=>['USD'=>'$', 'CAD'=>'C$'],
		]);
		CurrencySqlProbe::resetScenario();
		return new CurrencyKernelScenario($runtime,$session,$isTask);
	}

	test('currency kernel covers state metadata normalization and exact arithmetic primitives', static function(Context $t): void {
		$private=$t->nonPublic(\dataphyre\currency::class);
		currency_deep_scenario($t,$private);
		$t->same(1,count(CurrencySqlProbe::tableDefinitions()));
		new \dataphyre\currency('EUR', 'CAD', ['EUR'=>'€', 'CAD'=>'C$'], 'fr-CA', 'CA');
		$t->same([
			'base_currency'=>'EUR',
			'display_currency'=>'CAD',
			'display_language'=>'fr-CA',
			'display_country'=>'CA',
			'available_currencies'=>['EUR'=>'€', 'CAD'=>'C$'],
		], \dataphyre\currency::state());
		\dataphyre\currency::apply_state([
			'base_currency'=>17,
			'display_currency'=>[],
			'display_language'=>null,
			'display_country'=>false,
			'available_currencies'=>'invalid',
		]);
		$t->same('EUR', \dataphyre\currency::$base_currency);

		\dataphyre\currency::register_exchange_rate_source('   ', static fn(): array=>[]);
		$t->same(2, \dataphyre\currency::minor_units(''));
		$t->same(8, \dataphyre\currency::minor_units(' btc '));
		$t->same(0, \dataphyre\currency::minor_units('negative'));
		$t->same(2, \dataphyre\currency::minor_units('invalid'));
		$t->same(0, \dataphyre\currency::minor_units('jpy'));
		$t->same(null, \dataphyre\currency::cash_rounding_increment(''));
		$t->same(0.05, \dataphyre\currency::cash_rounding_increment('cad'));
		$t->same(null, \dataphyre\currency::cash_rounding_increment('zero'));
		$t->same(null, \dataphyre\currency::cash_rounding_increment('invalid'));
		$t->same(0.05, \dataphyre\currency::cash_rounding_increment('chf'));

		$t->same(1235, \dataphyre\currency::amount_to_minor_units('+12.345', 'USD'));
		$t->same(-1235, \dataphyre\currency::amount_to_minor_units('-12.345', 'USD'));
		$t->same(105, \dataphyre\currency::amount_to_minor_units('1.03', 'CAD', true));
		$t->same('-12', \dataphyre\currency::minor_units_to_amount(-12, 'JPY'));
		$t->same(44, \dataphyre\currency::convert_minor_units(44, '', 'CAD'));
		$t->same(44, \dataphyre\currency::convert_minor_units_with_rate(44, 'USD', 'USD', 2));
		$t->same(125, \dataphyre\currency::convert_minor_units_with_rate(100, 'USD', 'CAD', '1.25'));

		$t->same('1000', $private->invoke('decimal_string', '+1000'));
		$t->same('1.23', $private->invoke('decimal_string', '1.2300'));
		$t->same('7', $private->invoke('decimal_string', 7));
		$t->same('1.25', $private->invoke('decimal_string', 1.25));
		$t->same('1000', $private->invoke('decimal_string', '1e3'));
		$t->same('0', $private->invoke('normalize_major_amount_string', null, 4));
		$t->same('12', $private->invoke('normalize_major_amount_string', 12, 4));
		$t->same('1.2500', $private->invoke('normalize_major_amount_string', 1.25, 4));
		$t->same('0', $private->invoke('normalize_major_amount_string', 'not-a-number', 4));
		$t->same('1000', $private->invoke('normalize_major_amount_string', '1e3', 4));
		$t->same('123', $private->invoke('expand_scientific_decimal_string', '1.23e2', 6));
		$t->same('0.0123', $private->invoke('expand_scientific_decimal_string', '1.23e-2', 6));
		$t->same('1.23', $private->invoke('expand_scientific_decimal_string', '1.23009e0', 4));
		$t->same('-12', $private->invoke('expand_scientific_decimal_string', '-1.2e1', 4));
		$t->same('0', $private->invoke('expand_scientific_decimal_string', '0e10', 4));
		$t->same('0', $private->invoke('expand_scientific_decimal_string', 'bad', 4));
	})->tag('currency', 'coverage')->group('framework-coverage');

	test('currency kernel covers native and arbitrary precision conversion helpers', static function(Context $t): void {
		$private=$t->nonPublic(\dataphyre\currency::class);
		currency_deep_scenario($t,$private);
		if(function_exists('bcmul')){
			$t->same(-125, $private->invoke('bc_minor_conversion', -100, '1.25', '1', 100, 100, 12));
			$t->same(0, $private->invoke('bc_minor_conversion', 100, '1', '0', 100, 100, 12));
		}else{
			$t->isFalse($private->invoke('bcmath_available'));
		}
		$t->same(-125, $private->invoke('php_minor_conversion', -100, '1.25', '1', 100, 100));
		$t->same(0, $private->invoke('php_minor_conversion', 0, '1', '1', 100, 100));
		$t->same(0, $private->invoke('php_minor_conversion', 1, '0', '1', 100, 100));
		$t->same(0, $private->invoke('php_minor_conversion', 1, '1', '0', 100, 100));
		$t->same(7, $private->invoke('php_minor_conversion', 7, '100000000000000000000', '100000000000000000000', 100, 100));
		$t->same(6148914691236517205, $private->invoke('php_minor_conversion', PHP_INT_MAX, '2', '3', 1, 1));

		$private->writeProperty('bcmath_available_override',false);
		$t->same(125, \dataphyre\currency::convert_minor_units_with_rate(100, 'USD', 'CAD', '1.25'));
		$t->same(125, $private->invoke('convert_minor_units_with_multipliers', 100, 'USD', 'CAD', '1', '1.25'));
		$private->writeProperty('bcmath_available_override',null);

		$t->same(['125', '100'], $private->invoke('decimal_ratio', '-1.25'));
		$t->same(['5', '10'], $private->invoke('decimal_ratio', '+.5'));
		$t->same(['0', '100'], $private->invoke('decimal_ratio', '0.00'));
		$t->same(3, $private->invoke('php_minor_conversion_fast', '5', '1', '1', '2', '1', 1, 1));
		$t->same(2, $private->invoke('php_minor_conversion_fast', '4', '1', '1', '2', '1', 1, 1));
		$t->same(PHP_INT_MAX-7, $private->invoke('php_minor_conversion_fast', (string)(PHP_INT_MAX-7), '2', '1', '1', '1', 2, 1));
		$t->same(null, $private->invoke('php_minor_conversion_fast', (string)PHP_INT_MAX, '2', '1', '1', '1', 1, 1));
		$t->same(null, $private->invoke('php_minor_conversion_fast', '999999999999999999999', '1', '1', '1', '1', 1, 1));
		$t->same(null, $private->invoke('php_minor_conversion_fast', '1', '1', '1', '0', '1', 1, 1));

		$top=[1, 6, 2];
		$bottom=[1, 3, 4];
		$reduced=$private->capture('reduce_factor_sets',top_factors:$top,bottom_factors:$bottom);
		$top=$reduced->argument('top_factors');
		$bottom=$reduced->argument('bottom_factors');
		$t->same([1, 1, 1], $top);
		$t->same([1, 1, 1], $bottom);
		$t->same(6, $private->invoke('gcd', 54, 24));
		$t->same(12, $private->invoke('safe_int', '00012'));
		$t->same(null, $private->invoke('safe_int', '999999999999999999999'));
		$t->same(null, $private->invoke('safe_int', '9223372036854775808'));
		$t->same(24, $private->invoke('safe_product', [2, 3, 4]));
		$t->same(0, $private->invoke('safe_product', [2, 0, 4]));
		$t->same(null, $private->invoke('safe_product', [PHP_INT_MAX, 2]));

		$t->same('0', $private->invoke('big_normalize', '000'));
		$t->same('12', $private->invoke('big_normalize', '0012'));
		$t->same(-1, $private->invoke('big_compare', '9', '10'));
		$t->same(0, $private->invoke('big_compare', '0010', '10'));
		$t->same(1, $private->invoke('big_compare', '11', '10'));
		$t->same('1000', $private->invoke('big_add', '999', '1'));
		$t->same('15', $private->invoke('big_add', '12', '3'));
		$t->same('999', $private->invoke('big_sub', '1000', '1'));
		$t->same('111', $private->invoke('big_sub', '123', '12'));
		$t->same('1107', $private->invoke('big_mul_small', '123', 9));
		$t->same('0', $private->invoke('big_mul', '123', '0'));
		$t->same('56088', $private->invoke('big_mul', '123', '456'));
		$t->same(['0', '0'], $private->invoke('big_divmod', '12', '0'));
		$t->same(['102', '10'], $private->invoke('big_divmod', '1234', '12'));
	})->tag('currency', 'coverage')->group('framework-coverage');

	test('currency kernel covers cash splitting allocation validation and stable remainder ordering', static function(Context $t): void {
		$private=$t->nonPublic(\dataphyre\currency::class);
		currency_deep_scenario($t,$private);
		$t->same(1, $private->invoke('allocation_minor_step', 'USD', false));
		$t->same(1, $private->invoke('allocation_minor_step', 'USD', true));
		$t->same(5, $private->invoke('allocation_minor_step', 'CAD', true));
		$t->same(1, $private->invoke('allocation_minor_step', 'TINY', true));
		$t->same(1.23, $private->invoke('round_to_increment', 1.234, 0.0, 2));
		$t->same(1.05, $private->invoke('round_to_increment', 1.03, 0.05, 2));
		$t->same(1.05, \dataphyre\currency::round_amount(1.03, 'CAD', true));
		$t->same(21, $private->invoke('amount_to_allocation_units', 1.03, 'CAD', true));
		$t->same(1.05, $private->invoke('allocation_units_to_amount', 21, 'CAD', true));

		$t->same([], \dataphyre\currency::split_amount(1, 'USD', 0));
		$t->same([0.34, 0.33, 0.33], \dataphyre\currency::split_amount(1, 'USD', 3));
		$t->same([-0.34, -0.33, -0.33], \dataphyre\currency::split_amount(-1, 'USD', 3));
		$t->same([0.55, 0.5], \dataphyre\currency::split_amount(1.03, 'CAD', 2, true));
		$t->same([], \dataphyre\currency::split_minor_units(1, 'USD', 0));
		$t->same([55, 50], \dataphyre\currency::split_minor_units(103, 'CAD', 2, true));
		$t->same([-55, -50], \dataphyre\currency::split_minor_units(-103, 'CAD', 2, true));
		$t->same([50, 50], \dataphyre\currency::split_minor_units(102, 'CAD', 2, true));

		$t->same([], \dataphyre\currency::allocate_amount(10, 'USD', ['zero'=>0, 'negative'=>-1, 'text'=>'x', 'nan'=>NAN, 'infinity'=>INF]));
		$t->same([], \dataphyre\currency::allocate_minor_units(100, 'USD', ['nan'=>NAN, 'infinity'=>INF]));
		$t->same([], \dataphyre\currency::allocate_minor_units(100, 'USD', ['text'=>'x']));
		$t->same([], \dataphyre\currency::allocate_amount(10, 'USD', ['a'=>PHP_FLOAT_MAX, 'b'=>PHP_FLOAT_MAX]));
		$t->same([], \dataphyre\currency::allocate_minor_units(100, 'USD', ['a'=>PHP_FLOAT_MAX, 'b'=>PHP_FLOAT_MAX]));
		$t->same(['a'=>0.34, 'b'=>0.33, 'c'=>0.33], \dataphyre\currency::allocate_amount(1, 'USD', ['a'=>1, 'b'=>1, 'c'=>1]));
		$t->same(['a'=>-0.33, 'b'=>-0.67], \dataphyre\currency::allocate_amount(-1, 'USD', ['a'=>1, 'b'=>2]));
		$t->same(['a'=>35, 'b'=>70], \dataphyre\currency::allocate_minor_units(103, 'CAD', ['a'=>1, 'b'=>2], true));
		$t->same(['a'=>-35, 'b'=>-70], \dataphyre\currency::allocate_minor_units(-103, 'CAD', ['a'=>1, 'b'=>2], true));
		$t->same(['a'=>1, 'b'=>1], \dataphyre\currency::allocate_minor_units(2, 'USD', ['a'=>1, 'b'=>2]));
	})->tag('currency', 'coverage')->group('framework-coverage');

	test('currency kernel covers exchange payload cache storage callback and failure lifecycles', static function(Context $t): void {
		$private=$t->nonPublic(\dataphyre\currency::class);
		$scenario=currency_deep_scenario($t,$private);
		$t->same([], \dataphyre\currency::exchange_rate_sources());
		\dataphyre\currency::register_exchange_rate_source(' First ', static fn(): array=>['USD'=>1]);
		\dataphyre\currency::register_exchange_rate_sources([
			'Second'=>static fn(): array=>['CAD'=>1.25],
			'invalid'=>'not-callable',
		]);
		$t->same(['first', 'second'], \dataphyre\currency::exchange_rate_sources());

		$t->isFalse($private->invoke('has_valid_session_exchange_rates', []));
		$scenario->session->put('exchange_rate_data',['data'=>['USD'=>1], 'source'=>'other', 'time'=>time()]);
		$t->isFalse($private->invoke('has_valid_session_exchange_rates', ['first']));
		$scenario->session->put('exchange_rate_data',['data'=>['USD'=>1], 'source'=>'first', 'time'=>time()-7200]);
		$t->isFalse($private->invoke('has_valid_session_exchange_rates', ['first']));
		$scenario->session->put('exchange_rate_data',['data'=>['USD'=>1], 'source'=>'first', 'time'=>time()]);
		$t->isTrue($private->invoke('has_valid_session_exchange_rates', ['first']));

		$before=time();
		$t->isTrue($private->invoke('normalize_timestamp', null)>=$before);
		$t->same(5, $private->invoke('normalize_timestamp', 5, 99));
		$t->same(99, $private->invoke('normalize_timestamp', -1, 99));
		$t->same(6, $private->invoke('normalize_timestamp', '6', 99));
		$t->same(99, $private->invoke('normalize_timestamp', '0', 99));
		$t->same(99, $private->invoke('normalize_timestamp', '2026-01-02', 99));
		$t->same(strtotime('2026-01-02 03:04:05'), $private->invoke('normalize_timestamp', '2026-01-02 03:04:05', 99));
		$t->same(99, $private->invoke('normalize_timestamp', 'not-a-date', 99));

		$rates=$private->invoke('normalize_rates_array', [0=>1, ''=>1, 'bad'=>'x', 'zero'=>0, 'nan'=>NAN, 'inf'=>INF, ' cad '=>'1.25']);
		$t->same(['CAD'=>1.25, 'USD'=>1.0], $rates);
		$t->same(['CAD'=>1.25, 'USD'=>1.0], $private->invoke('extract_rates_from_payload', '{"rates":{"CAD":1.25}}'));
		$t->same(false, $private->invoke('extract_rates_from_payload', '{bad json'));
		$t->same(false, $private->invoke('extract_rates_from_payload', 42));

		$payload=$private->invoke('normalize_exchange_rate_payload', 'first', [
			'rates'=>['CAD'=>1.25],
			'date'=>'2026-01-02 03:04:05',
			'source'=>' Override ',
		]);
		$t->same('override', $payload['source']);
		$t->same(strtotime('2026-01-02 03:04:05'), $payload['time']);
		$t->same(['CAD'=>1.25, 'USD'=>1.0], $payload['data']);
		$t->same('first', $private->invoke('normalize_exchange_rate_payload', ' First ', ['CAD'=>1.25])['source']);
		$t->same(false, $private->invoke('normalize_exchange_rate_payload', 'first', 'invalid'));
		\dataphyre\currency::$base_currency='';
		$t->same(false, $private->invoke('extract_rates_from_payload', []));
		$t->same(false, $private->invoke('normalize_exchange_rate_payload', 'first', []));
		\dataphyre\currency::$base_currency='USD';

		$private->invoke('persist_exchange_rate_data', ['data'=>['USD'=>1], 'time'=>null, 'source'=>'first']);
		$t->same(1,count(CurrencySqlProbe::inserts()));
		CurrencySqlProbe::respondToSelectWith(false);
		$t->same(false, $private->invoke('load_cached_exchange_rates_from_storage', ['first']));
		CurrencySqlProbe::respondToSelectWith(['source'=>'other', 'data'=>'{"USD":1}', 'date'=>'2026-01-02 03:04:05']);
		$t->same(false, $private->invoke('load_cached_exchange_rates_from_storage', ['first']));
		CurrencySqlProbe::respondToSelectWith(['source'=>'first', 'data'=>'invalid', 'date'=>'2026-01-02 03:04:05']);
		$t->same(false, $private->invoke('load_cached_exchange_rates_from_storage', ['first']));
		CurrencySqlProbe::respondToSelectWith(['source'=>' FIRST ', 'data'=>'{"CAD":1.25}', 'date'=>'2026-01-02 03:04:05']);
		$stored=$private->invoke('load_cached_exchange_rates_from_storage', ['first']);
		$t->same('first', $stored['source']);
		$t->same(1.25, $stored['data']['CAD']);

		$scenario->runtime->put('dialback:CALL_CURRENCY_GET_RATES_DATA','rates-dialback');
		$t->same('rates-dialback', \dataphyre\currency::get_rates_data('first'));
		$scenario->runtime->forget('dialback:CALL_CURRENCY_GET_RATES_DATA');
		$private
			->writeProperty('exchange_rate_callbacks',[
				'throw'=>static fn()=>throw new RuntimeException('rate failure'),
				'invalid'=>static fn()=>false,
				'valid'=>static fn(string $source, string $base, array $cached): array=>['rates'=>['CAD'=>1.25], 'time'=>time(), 'source'=>$source],
			])
			->writeProperty('exchange_rate_callbacks_loaded',true);
		$t->same(false, \dataphyre\currency::get_rates_data('missing'));
		$t->same(false, \dataphyre\currency::get_rates_data('throw'));
		$t->same(false, \dataphyre\currency::get_rates_data('invalid'));
		$t->isTrue(\dataphyre\currency::get_rates_data('valid'));
		$t->same('valid',$scenario->session->get('exchange_rate_data')['source']);

		$scenario->runtime->put('dialback:CALL_CURRENCY_GET_EXCHANGE_RATES',['dialback'=>true]);
		$t->same(['dialback'=>true], \dataphyre\currency::get_exchange_rates());
		$scenario->runtime->forget('dialback:CALL_CURRENCY_GET_EXCHANGE_RATES');
		$t->same('valid', \dataphyre\currency::get_exchange_rates()['source']);
		$scenario->session->clear();
		CurrencySqlProbe::respondToSelectWith(['source'=>'valid', 'data'=>'{"CAD":1.3}', 'date'=>date('Y-m-d H:i:s')]);
		$t->same(1.3, \dataphyre\currency::get_exchange_rates()['data']['CAD']);
		$scenario->session->clear();
		CurrencySqlProbe::respondToSelectWith(false);
		$scenario->isTask->replace(true);
		$t->same([], \dataphyre\currency::get_exchange_rates());
		$scenario->isTask->replace(false);
		$private
			->writeProperty('exchange_rate_callbacks',[
				'invalid'=>static fn()=>false,
				'valid'=>static fn(string $source): array=>['rates'=>['CAD'=>1.4], 'source'=>$source],
			])
			->writeProperty('exchange_rate_callbacks_loaded',true);
		$t->same(1.4, \dataphyre\currency::get_exchange_rates()['data']['CAD']);
		$scenario->session->clear();
		$private
			->writeProperty('exchange_rate_callbacks',['invalid'=>static fn()=>false])
			->writeProperty('exchange_rate_callbacks_loaded',true);
		$t->same([], \dataphyre\currency::get_exchange_rates());
		$t->isTrue($scenario->runtime->get('unavailable_calls')>0);
	})->tag('currency', 'coverage')->group('framework-coverage');

	test('currency kernel covers formatting conversion dialbacks and unavailable-rate fallbacks', static function(Context $t): void {
		$private=$t->nonPublic(\dataphyre\currency::class);
		$scenario=currency_deep_scenario($t,$private);
		$scenario->runtime->put('dialback:CALL_CURRENCY_FORMATTER','formatted-dialback');
		$t->same('formatted-dialback', \dataphyre\currency::formatter(10));
		$scenario->runtime->forget('dialback:CALL_CURRENCY_FORMATTER');
		$t->same('Free', \dataphyre\currency::formatter(0, true, null));
		$t->same('C$1,234.50', \dataphyre\currency::formatter('1234.50', false, 'cad'));
		$t->same('EUR 12.34', \dataphyre\currency::formatter('12.34', false, 'eur'));
		\dataphyre\currency::$display_language='unknown';
		\dataphyre\currency::$display_country='ZZ';
		$t->same('$1 234,50', \dataphyre\currency::formatter('1234.50', false, 'usd'));
		$t->same('-1,234.50', $private->invoke('format_decimal_amount_string', '-1234.50', '.', ','));
		$t->same('0', $private->invoke('format_decimal_amount_string', '', null, null));
		$t->same('1234.5', $private->invoke('format_decimal_amount_string', '1234.5', null, null));

		$scenario->runtime->put('dialback:CALL_CURRENCY_CONVERT_TO_USER_CURRENCY','convert-dialback');
		$t->same('convert-dialback', \dataphyre\currency::convert(1, 'USD', 'CAD'));
		$scenario->runtime->forget('dialback:CALL_CURRENCY_CONVERT_TO_USER_CURRENCY');
		\dataphyre\currency::$display_language='en-CA';
		\dataphyre\currency::$display_country='CA';
		$t->same('Free', \dataphyre\currency::convert(0, 'USD', 'USD'));
		$t->same('1.25', \dataphyre\currency::convert('1.25', '', 'USD', false, false));
		$t->same('$1.25', \dataphyre\currency::convert('1.25', 'USD', 'USD', true, false));
		$scenario->session->put('exchange_rate_data',['data'=>['USD'=>1, 'CAD'=>1.25], 'time'=>time(), 'source'=>'rates']);
		$private
			->writeProperty('exchange_rate_callbacks',['rates'=>static fn()=>['USD'=>1, 'CAD'=>1.25]])
			->writeProperty('exchange_rate_callbacks_loaded',true);
		$t->same('12.50', \dataphyre\currency::convert(10, 'USD', 'CAD', false, false));
		$t->same('C$12.50', \dataphyre\currency::convert(10, 'USD', 'CAD', true, false));
		$t->same('Free', \dataphyre\currency::convert(0, 'USD', 'CAD', false, true));
		$t->same('0.00', \dataphyre\currency::convert(0, 'USD', 'CAD', false, false));
		$t->same('12.50', \dataphyre\currency::convert_to_user_currency(10, false, false, null));
		$t->same('8.00', \dataphyre\currency::convert_to_website_currency(10, 'CAD', false, false));

		$scenario->session->clear();
		$private
			->writeProperty('exchange_rate_callbacks',[])
			->writeProperty('exchange_rate_callbacks_loaded',true);
		CurrencySqlProbe::respondToSelectWith(false);
		$before=$scenario->runtime->get('unavailable_calls');
		$t->same('10.00', \dataphyre\currency::convert(10, 'USD', 'CAD', false, false));
		$t->isTrue($scenario->runtime->get('unavailable_calls')>$before);
		$t->same(100, \dataphyre\currency::convert_minor_units(100, 'USD', 'CAD'));
	})->tag('currency', 'coverage')->group('framework-coverage');
}
