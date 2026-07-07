<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */if(!function_exists('dp_currency_unit_test_with_rates')){
	function dp_currency_unit_test_with_rates(float|int|string|null $amount, string $source_currency, string $target_currency, bool $formatted=false, bool $show_free=false): string|float {
		$source='unit_test';
		if(class_exists('\dataphyre\currency') && method_exists('\dataphyre\currency', 'exchange_rate_sources')){
			$sources=\dataphyre\currency::exchange_rate_sources();
			$source=(string)($sources[0] ?? $source);
		}
		$_SESSION['exchange_rate_data']=[
			'data'=>[
				'USD'=>1.0,
				'CAD'=>1.35,
				'EUR'=>0.9,
			],
			'time'=>time(),
			'source'=>$source,
		];
		return \dataphyre\currency::convert($amount, $source_currency, $target_currency, $formatted, $show_free);
	}
}
if(!function_exists('dp_currency_unit_test_convert_minor_units_with_rates')){
	function dp_currency_unit_test_convert_minor_units_with_rates(int $amount_minor, string $source_currency, string $target_currency): int {
		$source='unit_test';
		if(class_exists('\dataphyre\currency') && method_exists('\dataphyre\currency', 'exchange_rate_sources')){
			$sources=\dataphyre\currency::exchange_rate_sources();
			$source=(string)($sources[0] ?? $source);
		}
		$_SESSION['exchange_rate_data']=[
			'data'=>[
				'USD'=>1.0,
				'CAD'=>1.35,
				'EUR'=>0.9,
				'JPY'=>150.0,
				'KWD'=>0.307,
			],
			'time'=>time(),
			'source'=>$source,
		];
		return \dataphyre\currency::convert_minor_units($amount_minor, $source_currency, $target_currency);
	}
}
if(!function_exists('dp_currency_unit_test_currency_facade_convert_minor_units')){
	function dp_currency_unit_test_currency_facade_convert_minor_units(int $amount_minor, string $source_currency, string $target_currency): int {
		$source='unit_test';
		if(class_exists('\dataphyre\currency') && method_exists('\dataphyre\currency', 'exchange_rate_sources')){
			$sources=\dataphyre\currency::exchange_rate_sources();
			$source=(string)($sources[0] ?? $source);
		}
		$_SESSION['exchange_rate_data']=[
			'data'=>[
				'USD'=>1.0,
				'CAD'=>1.35,
				'EUR'=>0.9,
			],
			'time'=>time(),
			'source'=>$source,
		];
		return \Dataphyre\Currency\Currency::convertMinorUnits($amount_minor, $source_currency, $target_currency);
	}
}
if(!function_exists('dp_currency_unit_test_framework_convert_minor_units_entrypoints')){
	function dp_currency_unit_test_framework_convert_minor_units_entrypoints(int $amount_minor, string $source_currency, string $target_currency): array {
		$source='unit_test';
		if(class_exists('\dataphyre\currency') && method_exists('\dataphyre\currency', 'exchange_rate_sources')){
			$sources=\dataphyre\currency::exchange_rate_sources();
			$source=(string)($sources[0] ?? $source);
		}
		$_SESSION['exchange_rate_data']=[
			'data'=>[
				'USD'=>1.0,
				'CAD'=>1.35,
				'EUR'=>0.9,
			],
			'time'=>time(),
			'source'=>$source,
		];
		$rates=\Dataphyre\Currency\Currency::rates();
		$snapshot=\Dataphyre\Currency\Currency::snapshot();
		$context=\Dataphyre\Currency\Currency::context();
		return [
			'rates'=>$rates->convertMinorUnits($amount_minor, $source_currency, $target_currency),
			'snapshot'=>$snapshot->convertMinorUnits($amount_minor, $source_currency, $target_currency),
			'context'=>$context->convertMinorUnits($amount_minor, $source_currency, $target_currency),
		];
	}
}
if(!function_exists('dp_currency_unit_test_money_minor_projection')){
	function dp_currency_unit_test_money_minor_projection(float|int|string|null $amount, string $currency): array {
		$money=\Dataphyre\Currency\Currency::money($amount, $currency);
		return [
			'amount'=>$money->amount(),
			'decimal'=>$money->decimalAmount(),
			'minor'=>$money->minorAmount(),
			'array'=>$money->toArray(),
		];
	}
}
if(!function_exists('dp_currency_unit_test_money_from_minor_projection')){
	function dp_currency_unit_test_money_from_minor_projection(int $amount_minor, string $currency): array {
		$money=\Dataphyre\Currency\Currency::moneyFromMinor($amount_minor, $currency);
		return [
			'amount'=>$money->amount(),
			'decimal'=>$money->decimalAmount(),
			'minor'=>$money->minorAmount(),
			'array'=>$money->toArray(),
		];
	}
}
if(!function_exists('dp_currency_unit_test_money_arithmetic_minor_units')){
	function dp_currency_unit_test_money_arithmetic_minor_units(string $amount, string $currency): array {
		$money=\Dataphyre\Currency\Currency::money($amount, $currency);
		return [
			'multiply'=>$money->multiply(1.075)->minorAmount(),
			'divide'=>$money->divide(3)->minorAmount(),
			'add'=>$money->add('0.44')->minorAmount(),
			'subtract'=>$money->subtract('0.56')->minorAmount(),
			'negative_half'=>(\Dataphyre\Currency\Currency::money('-1.25', $currency))->multiply(1.5)->minorAmount(),
		];
	}
}
if(!function_exists('dp_currency_unit_test_minor_allocation_entrypoints')){
	function dp_currency_unit_test_minor_allocation_entrypoints(int $minor_amount, string $currency): array {
		$context=\Dataphyre\Currency\Currency::context();
		$money=\Dataphyre\Currency\Currency::moneyFromMinor($minor_amount, $currency);
		return [
			'kernel_split'=>\dataphyre\currency::split_minor_units($minor_amount, $currency, 3),
			'facade_split'=>\Dataphyre\Currency\Currency::splitMinorUnits($minor_amount, $currency, 3),
			'context_split'=>$context->splitMinorUnits($minor_amount, $currency, 3),
			'money_split'=>$money->splitMinor(3),
			'kernel_allocate'=>\dataphyre\currency::allocate_minor_units($minor_amount, $currency, ['platform'=>1, 'seller'=>3]),
			'facade_allocate'=>\Dataphyre\Currency\Currency::allocateMinorUnits($minor_amount, $currency, ['platform'=>1, 'seller'=>3]),
			'context_allocate'=>$context->allocateMinorUnits($minor_amount, $currency, ['platform'=>1, 'seller'=>3]),
			'money_allocate'=>$money->allocateMinor(['platform'=>1, 'seller'=>3]),
		];
	}
}
if(!function_exists('dp_currency_unit_test_money_allocate_preserves_minor_units')){
	function dp_currency_unit_test_money_allocate_preserves_minor_units(int $minor_amount, string $currency): array {
		$money=\Dataphyre\Currency\Currency::moneyFromMinor($minor_amount, $currency);
		return [
			'allocate'=>array_map(static fn($part): int => $part->minorAmount(), $money->allocate(['platform'=>1, 'seller'=>3])),
			'allocate_minor'=>$money->allocateMinor(['platform'=>1, 'seller'=>3]),
		];
	}
}
if(!function_exists('dp_currency_unit_test_php_minor_conversion_matches_bcmath')){
	function dp_currency_unit_test_php_minor_conversion_matches_bcmath(): array {
		$php=new \ReflectionMethod('\dataphyre\currency', 'php_minor_conversion');
		$php->setAccessible(true);
		$bc=new \ReflectionMethod('\dataphyre\currency', 'bc_minor_conversion');
		$bc->setAccessible(true);
		$cases=[
			'typical'=>[12345, '1.234567', '1', 100, 100],
			'negative'=>[-12345, '1.234567', '1', 100, 100],
			'reduced_large'=>[999999999999, '0.733333', '1.111111', 100, 100],
			'large_precision'=>[123456789012345, '157.123456789123', '1', 1, 100],
		];
		$result=[];
		foreach($cases as $name=>$case){
			[$minor, $numerator, $denominator, $source_factor, $target_factor]=$case;
			$php_result=$php->invoke(null, $minor, $numerator, $denominator, $source_factor, $target_factor);
			$bc_result=$bc->invoke(null, $minor, $numerator, $denominator, $source_factor, $target_factor, 24);
			$result[$name]=[
				'php'=>$php_result,
				'bc'=>$bc_result,
				'same'=>$php_result===$bc_result,
			];
		}
		return $result;
	}
}
if(!function_exists('dp_currency_unit_test_exchange_quote_minor_normalization')){
	function dp_currency_unit_test_exchange_quote_minor_normalization(float|int|string|null $amount, string $source_currency, string $target_currency, float $rate): float {
		$quote=new \Dataphyre\Currency\ExchangeQuote(
			$source_currency,
			$source_currency,
			$target_currency,
			\dataphyre\currency::minor_units($source_currency),
			\dataphyre\currency::minor_units($target_currency),
			$rate,
			'unit_test',
			time()
		);
		return $quote->convert($amount);
	}
}
if(!function_exists('dp_currency_unit_test_stored_money_minor_projection')){
	function dp_currency_unit_test_stored_money_minor_projection(float|int|string|null $amount, string $source_currency, string $base_currency): array {
		$source='unit_test';
		if(class_exists('\dataphyre\currency') && method_exists('\dataphyre\currency', 'exchange_rate_sources')){
			$sources=\dataphyre\currency::exchange_rate_sources();
			$source=(string)($sources[0] ?? $source);
		}
		$_SESSION['exchange_rate_data']=[
			'data'=>[
				'USD'=>1.0,
				'CAD'=>1.35,
				'EUR'=>0.9,
			],
			'time'=>time(),
			'source'=>$source,
		];
		return \Dataphyre\Currency\Currency::storeMoney(
			\Dataphyre\Currency\Currency::money($amount, $source_currency),
			$base_currency
		)->toArray();
	}
}
