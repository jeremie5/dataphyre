<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */if(!function_exists('dp_currency_unit_test_with_rates')){
	function dp_currency_unit_test_with_rates(float|int|null $amount, string $source_currency, string $target_currency, bool $formatted=false, bool $show_free=false): string|float {
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
