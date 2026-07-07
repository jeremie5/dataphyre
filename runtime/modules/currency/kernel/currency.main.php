<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

dp_define_module_config('currency', 'DP_CURRENCY_CFG', [
	'exchange_rate_sources'=>[],
	'exchange_rate_callbacks'=>[],
	'minor_units'=>[],
	'cash_rounding_increments'=>[],
]);
if(function_exists('sql_define_table')){
	sql_define_table('dataphyre.exchange_rates', __DIR__.'/currency.tables.php', 'exchange_rates');
}

if(RUN_MODE==='diagnostic'){
	require_once(__DIR__.'/currency.diagnostic.php');
}

class currency{

	public static $base_currency='USD';
	public static $display_currency='USD';
	public static $display_language='en-CA';
	public static $display_country='CA';
	public static $available_currencies=["USD"=>"$"];
	public static $special_formatting=[
		'zh-HK'=>['HK'=>['.', ',', 2]],
		'zh-CN'=>['CN'=>['.', ',', 2]],
		'zh-TW'=>['TW'=>['.', ',', 2]],
		'en-AU'=>['AU'=>['.', ',', 2]],
		'en-CA'=>['CA'=>['.', ',', 2]],
		'en-IN'=>['IN'=>['.', ',', 2]],
		'en-NZ'=>['NZ'=>['.', ',', 2]],
		'en-ZA'=>['ZA'=>['.', ',', 2]],
		'en-GB'=>['GB'=>['.', ',', 2]],
		'en-US'=>['US'=>['.', ',', 2]],
		'de-AT'=>['AT'=>[',', '.', 2]],
		'de-DE'=>['DE'=>[',', '.', 2]],
		'de-LI'=>['LI'=>[',', '.', 2]],
		'de-CH'=>['CH'=>[',', '.', 2]],
		'fr-FR'=>['FR'=>['.', ' ', 2]],
		'fr-CH'=>['CH'=>['.', ' ', 2]],
		'it-IT'=>['IT'=>['.', ',', 2]],
		'it-CH'=>['CH'=>['.', ',', 2]],
		'ja'=>['JP'=>['.', ',', 0]],
		'ko'=>['KR'=>['.', ',', 0]],
		'pt-BR'=>['BR'=>['.', ',', 2]],
		'pt-PT'=>['PT'=>['.', ',', 2]],
		'es-AR'=>['AR'=>['.', ',', 2]],
		'es-419'=> ['419'=> ['.', ',', 2]],
		'es-MX'=>['MX'=>['.', ',', 2]],
		'es-ES'=>['ES'=>['.', ',', 2]],
		'es-US'=>['US'=>['.', ',', 2]],
		'th'=>['TH'=>['.', ',', 0]],
	];
	public static $default_currency_minor_units=[
		'BHD'=>3,
		'BIF'=>0,
		'CLP'=>0,
		'DJF'=>0,
		'GNF'=>0,
		'IQD'=>3,
		'ISK'=>0,
		'JOD'=>3,
		'JPY'=>0,
		'KMF'=>0,
		'KRW'=>0,
		'KWD'=>3,
		'LYD'=>3,
		'OMR'=>3,
		'PYG'=>0,
		'RWF'=>0,
		'TND'=>3,
		'UGX'=>0,
		'UYI'=>0,
		'VND'=>0,
		'VUV'=>0,
		'XAF'=>0,
		'XOF'=>0,
		'XPF'=>0,
	];
	public static $default_currency_cash_rounding_increments=[
		'CHF'=>0.05,
	];

	protected static $exchange_rate_callbacks=[];
	protected static $exchange_rate_callbacks_loaded=false;

	function __construct(string $base, string $currency, array $available, string $language, string $country){
		currency::$base_currency=$base;
		currency::$display_currency=$currency;
		currency::$available_currencies=$available;
		currency::$display_language=$language;
		currency::$display_country=$country;
	}

	public static function state(): array {
		return [
			'base_currency'=>self::$base_currency,
			'display_currency'=>self::$display_currency,
			'display_language'=>self::$display_language,
			'display_country'=>self::$display_country,
			'available_currencies'=>self::$available_currencies,
		];
	}

	public static function apply_state(array $state): void {
		if(array_key_exists('base_currency', $state) && is_string($state['base_currency'])){
			self::$base_currency=$state['base_currency'];
		}
		if(array_key_exists('display_currency', $state) && is_string($state['display_currency'])){
			self::$display_currency=$state['display_currency'];
		}
		if(array_key_exists('display_language', $state) && is_string($state['display_language'])){
			self::$display_language=$state['display_language'];
		}
		if(array_key_exists('display_country', $state) && is_string($state['display_country'])){
			self::$display_country=$state['display_country'];
		}
		if(array_key_exists('available_currencies', $state) && is_array($state['available_currencies'])){
			self::$available_currencies=$state['available_currencies'];
		}
	}

	public static function register_exchange_rate_source(string $source, callable $callback): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call');
		$source=self::normalize_source_name($source);
		if($source===''){
			return;
		}
		self::$exchange_rate_callbacks[$source]=$callback;
	}

	public static function register_exchange_rate_sources(array $callbacks): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call');
		foreach($callbacks as $source=>$callback){
			if(is_callable($callback)){
				self::register_exchange_rate_source((string)$source, $callback);
			}
		}
	}

	protected static function ensure_exchange_rate_callbacks_loaded(): void {
		if(self::$exchange_rate_callbacks_loaded){
			return;
		}
		self::$exchange_rate_callbacks_loaded=true;
		$configured_callbacks=DP_CURRENCY_CFG['exchange_rate_callbacks'] ?? [];
		if(is_array($configured_callbacks)){
			self::register_exchange_rate_sources($configured_callbacks);
		}
	}

	protected static function normalize_source_name(string $source): string {
		return mb_strtolower(trim($source));
	}

	protected static function configured_currency_metadata(string $key): array {
		$metadata=DP_CURRENCY_CFG[$key] ?? [];
		return is_array($metadata) ? $metadata : [];
	}

	public static function minor_units(string $currency): int {
		$currency=mb_strtoupper(trim($currency));
		if($currency===''){
			return 2;
		}
		$configured=self::configured_currency_metadata('minor_units');
		if(isset($configured[$currency]) && is_numeric($configured[$currency])){
			return max(0, (int)$configured[$currency]);
		}
		return self::$default_currency_minor_units[$currency] ?? 2;
	}

	public static function cash_rounding_increment(string $currency): ?float {
		$currency=mb_strtoupper(trim($currency));
		if($currency===''){
			return null;
		}
		$configured=self::configured_currency_metadata('cash_rounding_increments');
		if(isset($configured[$currency]) && is_numeric($configured[$currency])){
			$increment=(float)$configured[$currency];
			return $increment>0 ? $increment : null;
		}
		return self::$default_currency_cash_rounding_increments[$currency] ?? null;
	}

	protected static function minor_factor(string $currency): int {
		static $cache=[];
		return $cache[$currency] ??= (int)round(pow(10, self::minor_units($currency)));
	}

	public static function amount_to_minor_units(float|int|string|null $amount, string $currency, bool $cash=false): int {
		$currency=mb_strtoupper(trim($currency));
		$precision=self::minor_units($currency);
		$factor=self::minor_factor($currency);
		$value=self::normalize_major_amount_string($amount, $precision+6);
		$negative=false;
		if(isset($value[0]) && $value[0]==='-'){
			$negative=true;
			$value=substr($value, 1);
		}
		elseif(isset($value[0]) && $value[0]==='+'){
			$value=substr($value, 1);
		}
		[$whole, $fraction]=array_pad(explode('.', $value, 2), 2, '');
		$whole=preg_replace('/\D/', '', $whole) ?: '0';
		$fraction=preg_replace('/\D/', '', $fraction) ?: '';
		$round_digit=(int)($fraction[$precision] ?? '0');
		$fraction=substr(str_pad($fraction, $precision, '0'), 0, $precision);
		$minor=((int)$whole*$factor)+(int)$fraction;
		if($round_digit>=5){
			$minor++;
		}
		if($cash===true){
			$step=self::allocation_minor_step($currency, true);
			if($step>1){
				$minor=(int)(round($minor/$step)*$step);
			}
		}
		return $negative ? -$minor : $minor;
	}

	public static function minor_units_to_amount(int $minor_amount, string $currency): string {
		$precision=self::minor_units($currency);
		$factor=self::minor_factor($currency);
		$negative=$minor_amount<0;
		$minor_amount=abs($minor_amount);
		$whole=intdiv($minor_amount, $factor);
		if($precision===0){
			return ($negative ? '-' : '').(string)$whole;
		}
		$fraction=str_pad((string)($minor_amount % $factor), $precision, '0', STR_PAD_LEFT);
		return ($negative ? '-' : '').$whole.'.'.$fraction;
	}

	public static function convert_minor_units(int $minor_amount, string $source_currency, string $target_currency): int {
		$source_currency=mb_strtoupper(trim($source_currency));
		$target_currency=mb_strtoupper(trim($target_currency));
		if($source_currency==='' || $source_currency===$target_currency){
			return $minor_amount;
		}
		if(!self::has_valid_session_exchange_rates(self::exchange_rate_sources())){
			self::get_exchange_rates();
		}
		if(empty($_SESSION['exchange_rate_data']['data']) || !is_array($_SESSION['exchange_rate_data']['data'])){
			if(RUN_MODE!=='diagnostic'){
				core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCurrency: No cached rates available in session.', 'safemode');
			}
		}
		$source_multiplier=$_SESSION['exchange_rate_data']['data'][$source_currency] ?? 1;
		$target_multiplier=$_SESSION['exchange_rate_data']['data'][$target_currency] ?? 1;
		return self::convert_minor_units_with_multipliers($minor_amount, $source_currency, $target_currency, $source_multiplier, $target_multiplier);
	}

	public static function convert_minor_units_with_rate(int $minor_amount, string $source_currency, string $target_currency, float|int|string $rate): int {
		$source_currency=mb_strtoupper(trim($source_currency));
		$target_currency=mb_strtoupper(trim($target_currency));
		if($source_currency==='' || $source_currency===$target_currency){
			return $minor_amount;
		}
		$source_factor=self::minor_factor($source_currency);
		$target_factor=self::minor_factor($target_currency);
		$scale=max(12, self::minor_units($source_currency)+self::minor_units($target_currency)+8);
		if(!self::bcmath_available()){
			return self::php_minor_conversion($minor_amount, self::decimal_string($rate), '1', $source_factor, $target_factor);
		}
		return self::bc_minor_conversion($minor_amount, self::decimal_string($rate), '1', $source_factor, $target_factor, $scale);
	}

	protected static function convert_minor_units_with_multipliers(int $minor_amount, string $source_currency, string $target_currency, float|int|string $source_multiplier, float|int|string $target_multiplier): int {
		$source_factor=self::minor_factor($source_currency);
		$target_factor=self::minor_factor($target_currency);
		$scale=max(12, self::minor_units($source_currency)+self::minor_units($target_currency)+8);
		if(!self::bcmath_available()){
			return self::php_minor_conversion($minor_amount, self::decimal_string($target_multiplier), self::decimal_string($source_multiplier), $source_factor, $target_factor);
		}
		return self::bc_minor_conversion($minor_amount, self::decimal_string($target_multiplier), self::decimal_string($source_multiplier), $source_factor, $target_factor, $scale);
	}

	protected static function bc_minor_conversion(int $minor_amount, string $numerator_rate, string $denominator_rate, int $source_factor, int $target_factor, int $scale): int {
		$minor_string=(string)$minor_amount;
		$negative=str_starts_with($minor_string, '-');
		if($negative){
			$minor_string=substr($minor_string, 1);
		}
		$denominator=bcmul($denominator_rate, (string)$source_factor, $scale);
		if(bccomp($denominator, '0', $scale)<=0){
			return 0;
		}
		$numerator=bcmul(bcmul($minor_string, $numerator_rate, $scale), (string)$target_factor, $scale);
		$rounded=bcdiv(
			bcadd(bcmul($numerator, '2', $scale), $denominator, $scale),
			bcmul($denominator, '2', $scale),
			0
		);
		$result=(int)$rounded;
		return $negative ? -$result : $result;
	}

	protected static function php_minor_conversion(int $minor_amount, string $numerator_rate, string $denominator_rate, int $source_factor, int $target_factor): int {
		$minor_string=(string)$minor_amount;
		$negative=str_starts_with($minor_string, '-');
		if($negative){
			$minor_string=substr($minor_string, 1);
		}
		[$numerator_digits, $numerator_scale]=self::decimal_ratio($numerator_rate);
		[$denominator_digits, $denominator_scale]=self::decimal_ratio($denominator_rate);
		if($minor_string==='0' || $numerator_digits==='0' || $denominator_digits==='0'){
			return 0;
		}
		$fast=self::php_minor_conversion_fast($minor_string, $numerator_digits, $numerator_scale, $denominator_digits, $denominator_scale, $source_factor, $target_factor);
		if($fast!==null){
			return $negative ? -$fast : $fast;
		}
		$numerator=self::big_mul(self::big_mul(self::big_mul($minor_string, $numerator_digits), $denominator_scale), (string)$target_factor);
		$denominator=self::big_mul(self::big_mul(self::big_mul($denominator_digits, $numerator_scale), (string)$source_factor), '1');
		[$quotient, $remainder]=self::big_divmod($numerator, $denominator);
		if(self::big_compare(self::big_mul_small($remainder, 2), $denominator)>=0){
			$quotient=self::big_add($quotient, '1');
		}
		$result=(int)$quotient;
		return $negative ? -$result : $result;
	}

	protected static function decimal_ratio(string $value): array {
		$value=trim($value);
		$negative=str_starts_with($value, '-');
		if($negative || str_starts_with($value, '+')){
			$value=substr($value, 1);
		}
		[$whole, $fraction]=array_pad(explode('.', $value, 2), 2, '');
		$whole=$whole==='' ? '0' : $whole;
		$scale='1'.str_repeat('0', strlen($fraction));
		$digits=ltrim($whole.$fraction, '0');
		return [$digits==='' ? '0' : $digits, $scale];
	}

	protected static function php_minor_conversion_fast(string $minor_string, string $numerator_digits, string $numerator_scale, string $denominator_digits, string $denominator_scale, int $source_factor, int $target_factor): ?int {
		$minor=self::safe_int($minor_string);
		$numerator=self::safe_int($numerator_digits);
		$numerator_scale_int=self::safe_int($numerator_scale);
		$denominator=self::safe_int($denominator_digits);
		$denominator_scale_int=self::safe_int($denominator_scale);
		if($minor===null || $numerator===null || $numerator_scale_int===null || $denominator===null || $denominator_scale_int===null){
			return null;
		}
		$top_factors=[$minor, $numerator, $denominator_scale_int, $target_factor];
		$bottom_factors=[$denominator, $numerator_scale_int, $source_factor];
		$top=self::safe_product($top_factors);
		$bottom=self::safe_product($bottom_factors);
		if($top===null || $bottom===null){
			self::reduce_factor_sets($top_factors, $bottom_factors);
			$top=self::safe_product($top_factors);
			$bottom=self::safe_product($bottom_factors);
		}
		if($top===null || $bottom===null || $bottom<=0){
			return null;
		}
		$quotient=intdiv($top, $bottom);
		$remainder=$top%$bottom;
		if($remainder>=intdiv($bottom, 2)+($bottom%2)){
			$quotient++;
		}
		return $quotient;
	}

	protected static function reduce_factor_sets(array &$top_factors, array &$bottom_factors): void {
		foreach($top_factors as &$top){
			if($top<=1){
				continue;
			}
			foreach($bottom_factors as &$bottom){
				if($bottom<=1){
					continue;
				}
				$gcd=self::gcd($top, $bottom);
				if($gcd>1){
					$top=intdiv($top, $gcd);
					$bottom=intdiv($bottom, $gcd);
					if($top<=1){
						break;
					}
				}
			}
			unset($bottom);
		}
		unset($top);
	}

	protected static function gcd(int $a, int $b): int {
		while($b!==0){
			$remainder=$a%$b;
			$a=$b;
			$b=$remainder;
		}
		return abs($a);
	}

	protected static function safe_int(string $value): ?int {
		$value=ltrim($value, '0');
		$value=$value==='' ? '0' : $value;
		if(strlen($value)>strlen((string)PHP_INT_MAX) || (strlen($value)===strlen((string)PHP_INT_MAX) && strcmp($value, (string)PHP_INT_MAX)>0)){
			return null;
		}
		return (int)$value;
	}

	protected static function safe_product(array $factors): ?int {
		$result=1;
		foreach($factors as $factor){
			$factor=(int)$factor;
			if($factor===0){
				return 0;
			}
			if($result>intdiv(PHP_INT_MAX, $factor)){
				return null;
			}
			$result*=$factor;
		}
		return $result;
	}

	protected static function big_normalize(string $value): string {
		$value=ltrim($value, '0');
		return $value==='' ? '0' : $value;
	}

	protected static function big_compare(string $a, string $b): int {
		$a=self::big_normalize($a);
		$b=self::big_normalize($b);
		return strlen($a)<=>strlen($b) ?: strcmp($a, $b);
	}

	protected static function big_add(string $a, string $b): string {
		$a=strrev(self::big_normalize($a));
		$b=strrev(self::big_normalize($b));
		$carry=0;
		$result='';
		$length=max(strlen($a), strlen($b));
		for($i=0; $i<$length; $i++){
			$sum=(int)($a[$i] ?? '0')+(int)($b[$i] ?? '0')+$carry;
			$result.=($sum%10);
			$carry=intdiv($sum, 10);
		}
		if($carry>0){
			$result.=$carry;
		}
		return self::big_normalize(strrev($result));
	}

	protected static function big_sub(string $a, string $b): string {
		$a=strrev(self::big_normalize($a));
		$b=strrev(self::big_normalize($b));
		$borrow=0;
		$result='';
		for($i=0, $length=strlen($a); $i<$length; $i++){
			$digit=(int)$a[$i]-(int)($b[$i] ?? '0')-$borrow;
			if($digit<0){
				$digit+=10;
				$borrow=1;
			}
			else
			{
				$borrow=0;
			}
			$result.=$digit;
		}
		return self::big_normalize(strrev($result));
	}

	protected static function big_mul_small(string $a, int $b): string {
		$a=strrev(self::big_normalize($a));
		$carry=0;
		$result='';
		for($i=0, $length=strlen($a); $i<$length; $i++){
			$product=((int)$a[$i]*$b)+$carry;
			$result.=($product%10);
			$carry=intdiv($product, 10);
		}
		while($carry>0){
			$result.=($carry%10);
			$carry=intdiv($carry, 10);
		}
		return self::big_normalize(strrev($result));
	}

	protected static function big_mul(string $a, string $b): string {
		$a=self::big_normalize($a);
		$b=self::big_normalize($b);
		if($a==='0' || $b==='0'){
			return '0';
		}
		$result='0';
		for($i=strlen($b)-1, $zeros=0; $i>=0; $i--, $zeros++){
			$partial=self::big_mul_small($a, (int)$b[$i]).str_repeat('0', $zeros);
			$result=self::big_add($result, $partial);
		}
		return self::big_normalize($result);
	}

	protected static function big_divmod(string $numerator, string $denominator): array {
		$numerator=self::big_normalize($numerator);
		$denominator=self::big_normalize($denominator);
		if($denominator==='0'){
			return ['0', '0'];
		}
		$quotient='';
		$remainder='0';
		for($i=0, $length=strlen($numerator); $i<$length; $i++){
			$remainder=self::big_normalize($remainder.$numerator[$i]);
			$digit=0;
			while(self::big_compare($remainder, $denominator)>=0){
				$remainder=self::big_sub($remainder, $denominator);
				$digit++;
			}
			$quotient.=(string)$digit;
		}
		return [self::big_normalize($quotient), self::big_normalize($remainder)];
	}

	protected static function bcmath_available(): bool {
		static $available=null;
		return $available ??= function_exists('bcdiv');
	}

	protected static function decimal_string(float|int|string $value): string {
		if(is_int($value)){
			return (string)$value;
		}
		if(is_string($value)){
			$value=trim($value);
			if($value!=='' && is_numeric($value) && stripos($value, 'e')===false){
				$value=ltrim($value, '+');
				return rtrim(rtrim($value, '0'), '.') ?: '0';
			}
		}
		$value=sprintf('%.14F', (float)$value);
		return rtrim(rtrim($value, '0'), '.') ?: '0';
	}

	protected static function normalize_major_amount_string(float|int|string|null $amount, int $precision): string {
		if($amount===null){
			return '0';
		}
		if(is_int($amount)){
			return (string)$amount;
		}
		if(is_float($amount)){
			return number_format($amount, $precision, '.', '');
		}
		$amount=trim($amount);
		if($amount==='' || !is_numeric($amount)){
			return '0';
		}
		if(stripos($amount, 'e')!==false){
			return self::expand_scientific_decimal_string($amount, $precision);
		}
		return $amount;
	}

	protected static function expand_scientific_decimal_string(string $amount, int $precision): string {
		if(preg_match('/^([+-]?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/', trim($amount), $matches)!==1){
			return '0';
		}
		$sign=$matches[1] ?? '';
		$whole=$matches[2] ?? '0';
		$fraction=$matches[3] ?? '';
		$exponent=(int)($matches[4] ?? 0);
		$digits=ltrim($whole.$fraction, '0');
		if($digits===''){
			return '0';
		}
		$point=strlen($whole)+$exponent;
		if($point<=0){
			$result='0.'.str_repeat('0', abs($point)).$digits;
		}
		elseif($point>=strlen($digits)){
			$result=$digits.str_repeat('0', $point-strlen($digits));
		}
		else
		{
			$result=substr($digits, 0, $point).'.'.substr($digits, $point);
		}
		if(str_contains($result, '.')){
			[$result_whole, $result_fraction]=explode('.', $result, 2);
			$result=$result_whole.'.'.substr($result_fraction, 0, max(0, $precision));
			$result=rtrim(rtrim($result, '0'), '.');
		}
		return ($sign==='-' ? '-' : '').($result==='' ? '0' : $result);
	}

	protected static function allocation_minor_step(string $currency, bool $cash=false): int {
		if($cash!==true){
			return 1;
		}
		$increment=self::cash_rounding_increment($currency);
		if($increment===null){
			return 1;
		}
		$step=(int)round($increment*self::minor_factor($currency));
		return $step>0 ? $step : 1;
	}

	protected static function round_to_increment(float $amount, float $increment, int $precision): float {
		if($increment<=0){
			return round($amount, $precision);
		}
		return round(round($amount/$increment)*$increment, $precision);
	}

	public static function round_amount(float|int|string|null $amount, string $currency, bool $cash=false): float {
		return (float)self::minor_units_to_amount(self::amount_to_minor_units($amount, $currency, $cash), $currency);
	}

	protected static function amount_to_allocation_units(float|int|string|null $amount, string $currency, bool $cash=false): int {
		$minor=self::amount_to_minor_units($amount, $currency, $cash);
		$step=self::allocation_minor_step($currency, $cash);
		return (int)round($minor/$step);
	}

	protected static function allocation_units_to_amount(int $units, string $currency, bool $cash=false): float {
		$minor=$units*self::allocation_minor_step($currency, $cash);
		return round($minor/self::minor_factor($currency), self::minor_units($currency));
	}

	public static function split_amount(float|int|string|null $amount, string $currency, int $parts, bool $cash=false): array {
		if($parts<=0){
			return [];
		}
		$total_units=self::amount_to_allocation_units($amount, $currency, $cash);
		$sign=$total_units<0 ? -1 : 1;
		$total_units=abs($total_units);
		$base_units=intdiv($total_units, $parts);
		$remainder=$total_units % $parts;
		$precision=self::minor_units($currency);
		$factor=(int)round(pow(10, $precision));
		$step=self::allocation_minor_step($currency, $cash);
		$allocations=[];
		for($index=0;$index<$parts;$index++){
			$units=$base_units+($index<$remainder ? 1 : 0);
			$allocations[]=round(($units*$sign*$step)/$factor, $precision);
		}
		return $allocations;
	}

	public static function split_minor_units(int $minor_amount, string $currency, int $parts, bool $cash=false): array {
		if($parts<=0){
			return [];
		}
		$step=self::allocation_minor_step($currency, $cash);
		$negative=$minor_amount<0;
		$minor_amount=abs($minor_amount);
		$total_units=intdiv($minor_amount, $step);
		$remainder_minor=$minor_amount%$step;
		if($remainder_minor*2>=$step){
			$total_units++;
		}
		$sign=$negative ? -1 : 1;
		$base_units=intdiv($total_units, $parts);
		$remainder_units=$total_units%$parts;
		$allocations=[];
		for($index=0;$index<$parts;$index++){
			$units=$base_units+($index<$remainder_units ? 1 : 0);
			$allocations[]=($units*$step)*$sign;
		}
		return $allocations;
	}

	public static function allocate_amount(float|int|string|null $amount, string $currency, array $ratios, bool $cash=false): array {
		$prepared=[];
		foreach($ratios as $key=>$ratio){
			if(!is_numeric($ratio) || (float)$ratio<=0){
				continue;
			}
			$prepared[$key]=(float)$ratio;
		}
		if(empty($prepared)){
			return [];
		}
		$total_units=self::amount_to_allocation_units($amount, $currency, $cash);
		$sign=$total_units<0 ? -1 : 1;
		$total_units=abs($total_units);
		$ratio_sum=array_sum($prepared);
		if($ratio_sum<=0){
			return [];
		}
		$unit_allocations=[];
		$fractional_parts=[];
		$positions=[];
		$allocated_units=0;
		$position=0;
		foreach($prepared as $key=>$ratio){
			$exact_units=($total_units*$ratio)/$ratio_sum;
			$floor_units=(int)floor($exact_units);
			$unit_allocations[$key]=$floor_units;
			$fractional_parts[$key]=$exact_units-$floor_units;
			$positions[$key]=$position++;
			$allocated_units+=$floor_units;
		}
		$remaining_units=$total_units-$allocated_units;
		uksort($fractional_parts, static function($left, $right) use($fractional_parts, $positions): int {
			$comparison=$fractional_parts[$right]<=>$fractional_parts[$left];
			if($comparison!==0){
				return $comparison;
			}
			return $positions[$left]<=>$positions[$right];
		});
		foreach(array_keys($fractional_parts) as $key){
			if($remaining_units<=0){
				break;
			}
			$unit_allocations[$key]++;
			$remaining_units--;
		}
		$allocations=[];
		foreach($unit_allocations as $key=>$units){
			$allocations[$key]=self::allocation_units_to_amount($units*$sign, $currency, $cash);
		}
		return $allocations;
	}

	public static function allocate_minor_units(int $minor_amount, string $currency, array $ratios, bool $cash=false): array {
		$prepared=[];
		foreach($ratios as $key=>$ratio){
			if(!is_numeric($ratio) || (float)$ratio<=0){
				continue;
			}
			$prepared[$key]=(float)$ratio;
		}
		if(empty($prepared)){
			return [];
		}
		$step=self::allocation_minor_step($currency, $cash);
		$negative=$minor_amount<0;
		$minor_amount=abs($minor_amount);
		$total_units=intdiv($minor_amount, $step);
		$remainder_minor=$minor_amount%$step;
		if($remainder_minor*2>=$step){
			$total_units++;
		}
		$ratio_sum=array_sum($prepared);
		if($ratio_sum<=0){
			return [];
		}
		$unit_allocations=[];
		$fractional_parts=[];
		$positions=[];
		$allocated_units=0;
		$position=0;
		foreach($prepared as $key=>$ratio){
			$exact_units=($total_units*$ratio)/$ratio_sum;
			$floor_units=(int)floor($exact_units);
			$unit_allocations[$key]=$floor_units;
			$fractional_parts[$key]=$exact_units-$floor_units;
			$positions[$key]=$position++;
			$allocated_units+=$floor_units;
		}
		$remaining_units=$total_units-$allocated_units;
		uksort($fractional_parts, static function($left, $right) use($fractional_parts, $positions): int {
			$comparison=$fractional_parts[$right]<=>$fractional_parts[$left];
			if($comparison!==0){
				return $comparison;
			}
			return $positions[$left]<=>$positions[$right];
		});
		foreach(array_keys($fractional_parts) as $key){
			if($remaining_units<=0){
				break;
			}
			$unit_allocations[$key]++;
			$remaining_units--;
		}
		$sign=$negative ? -1 : 1;
		$allocations=[];
		foreach($unit_allocations as $key=>$units){
			$allocations[$key]=($units*$step)*$sign;
		}
		return $allocations;
	}

	public static function exchange_rate_sources(): array {
		self::ensure_exchange_rate_callbacks_loaded();
		$sources=DP_CURRENCY_CFG['exchange_rate_sources'] ?? [];
		$normalized_sources=[];
		if(is_array($sources)){
			foreach($sources as $source){
				if(!is_string($source)){
					continue;
				}
				$source=self::normalize_source_name($source);
				if($source!=='' && !in_array($source, $normalized_sources, true)){
					$normalized_sources[]=$source;
				}
			}
		}
		if(!empty($normalized_sources)){
			return $normalized_sources;
		}
		return array_keys(self::$exchange_rate_callbacks);
	}

	protected static function session_exchange_rate_data(): array {
		return is_array($_SESSION['exchange_rate_data'] ?? null) ? $_SESSION['exchange_rate_data'] : [];
	}

	protected static function has_valid_session_exchange_rates(array $allowed_sources=[]): bool {
		$data=self::session_exchange_rate_data();
		if(empty($data['data']) || !is_array($data['data'])){
			return false;
		}
		$source=self::normalize_source_name((string)($data['source'] ?? ''));
		if(!empty($allowed_sources) && !in_array($source, $allowed_sources, true)){
			return false;
		}
		$timestamp=(int)($data['time'] ?? 0);
		if($timestamp<=0 || $timestamp<(time()-3600)){
			return false;
		}
		return true;
	}

	protected static function normalize_timestamp(mixed $timestamp, ?int $fallback=null): int {
		if($fallback===null){
			$fallback=time();
		}
		if(is_int($timestamp)){
			return $timestamp>0 ? $timestamp : $fallback;
		}
		if(is_numeric($timestamp)){
			$timestamp=(int)$timestamp;
			return $timestamp>0 ? $timestamp : $fallback;
		}
		if(is_string($timestamp) && trim($timestamp)!==''){
			if(preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($timestamp))){
				return $fallback;
			}
			$parsed=strtotime($timestamp);
			if($parsed!==false){
				return $parsed;
			}
		}
		return $fallback;
	}

	protected static function normalize_rates_array(array $rates): array {
		$normalized_rates=[];
		foreach($rates as $currency=>$rate){
			if(!is_string($currency) || $currency===''){
				continue;
			}
			if(!is_numeric($rate)){
				continue;
			}
			$rate=(float)$rate;
			if($rate<=0){
				continue;
			}
			$currency=mb_strtoupper(trim($currency));
			$normalized_rates[$currency]=$rate;
		}
		$base_currency=mb_strtoupper(self::$base_currency);
		if($base_currency!==''){
			$normalized_rates[$base_currency]=1.0;
		}
		return $normalized_rates;
	}

	protected static function extract_rates_from_payload(mixed $payload): array|false {
		if(is_string($payload)){
			$payload=json_decode($payload, true);
		}
		if(!is_array($payload)){
			return false;
		}
		if(isset($payload['rates']) && is_array($payload['rates'])){
			$payload=$payload['rates'];
		}
		$rates=self::normalize_rates_array($payload);
		if(empty($rates)){
			return false;
		}
		return $rates;
	}

	protected static function normalize_exchange_rate_payload(string $source, mixed $payload): array|false {
		$timestamp=time();
		if(is_array($payload) && isset($payload['rates']) && is_array($payload['rates'])){
			$rates=self::normalize_rates_array($payload['rates']);
			$timestamp=self::normalize_timestamp($payload['time'] ?? ($payload['date'] ?? null), $timestamp);
			if(isset($payload['source']) && is_string($payload['source'])){
				$source=self::normalize_source_name($payload['source']);
			}
		}
		elseif(is_array($payload)){
			$rates=self::normalize_rates_array($payload);
		}
		else
		{
			return false;
		}
		if(empty($rates)){
			return false;
		}
		return [
			'data'=>$rates,
			'time'=>$timestamp,
			'source'=>self::normalize_source_name($source),
		];
	}

	protected static function persist_exchange_rate_data(array $exchange_rate_data): void {
		if(!class_exists(__NAMESPACE__.'\\sql', false)){
			return;
		}
		sql_insert(
			$L="dataphyre.exchange_rates",
			$F=[
				"data"=>json_encode($exchange_rate_data['data']),
				"date"=>date('Y-m-d H:i:s', self::normalize_timestamp($exchange_rate_data['time'] ?? null)),
				"source"=>$exchange_rate_data['source'],
			],
			$V=null,
			$CC=true
		);
	}

	protected static function load_cached_exchange_rates_from_storage(array $allowed_sources=[]): array|false {
		if(!class_exists(__NAMESPACE__.'\\sql', false)){
			return false;
		}
		$row=sql_select(
			$S="*",
			$L="dataphyre.exchange_rates",
			$P=[
				"mysql"=>"WHERE date>DATE_SUB(NOW(),INTERVAL 60 MINUTE) ORDER BY date DESC LIMIT 1",
				"postgresql"=>"WHERE date>NOW() - INTERVAL '60 minutes' ORDER BY date DESC LIMIT 1"
			],
			$V=null,
			$F=false,
			$C=false
		);
		if($row===false){
			return false;
		}
		$source=self::normalize_source_name((string)($row['source'] ?? ''));
		if(!empty($allowed_sources) && !in_array($source, $allowed_sources, true)){
			return false;
		}
		$rates=self::extract_rates_from_payload($row['data'] ?? null);
		if($rates===false){
			return false;
		}
		$_SESSION['exchange_rate_data']=[
			'data'=>$rates,
			'time'=>self::normalize_timestamp($row['date'] ?? null),
			'source'=>$source,
		];
		return $_SESSION['exchange_rate_data'];
	}

	public static function get_exchange_rates(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call');
		if(null!==$early_return=core::dialback("CALL_CURRENCY_GET_EXCHANGE_RATES",...func_get_args())) return $early_return;
		global $is_task;
		$sources=self::exchange_rate_sources();
		if(self::has_valid_session_exchange_rates($sources)){
			return $_SESSION['exchange_rate_data'];
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Cached exchange rates expired, invalid or missing", $S="warning");
		if(false!==$exchange_rate_data=self::load_cached_exchange_rates_from_storage($sources)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Exchange rates loaded into cache");
			return $exchange_rate_data;
		}
		if($is_task==true){
			return self::session_exchange_rate_data();
		}
		foreach($sources as $source){
			if(self::get_rates_data($source)!==false){
				return $_SESSION['exchange_rate_data'];
			}
		}
		if(RUN_MODE!=='diagnostic'){
			core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCurrency: No valid exchange-rate callback returned usable rates and no cached data is available.', 'safemode');
		}
		return self::session_exchange_rate_data();
	}
	
	public static function get_rates_data(string $source){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call');
		if(null!==$early_return=core::dialback("CALL_CURRENCY_GET_RATES_DATA",...func_get_args())) return $early_return;
		self::ensure_exchange_rate_callbacks_loaded();
		$source=self::normalize_source_name($source);
		$callback=self::$exchange_rate_callbacks[$source] ?? null;
		if(!is_callable($callback)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="No exchange-rate callback registered for source '".$source."'", $S="warning");
			return false;
		}
		try{
			$payload=$callback($source, self::$base_currency, self::session_exchange_rate_data());
		}catch(\Throwable $exception){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Exchange-rate callback for '".$source."' failed: ".$exception->getMessage(), $S="warning");
			return false;
		}
		$exchange_rate_data=self::normalize_exchange_rate_payload($source, $payload);
		if($exchange_rate_data===false){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Exchange-rate callback for '".$source."' returned invalid data", $S="warning");
			return false;
		}
		$_SESSION['exchange_rate_data']=$exchange_rate_data;
		self::persist_exchange_rate_data($exchange_rate_data);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Exchange rates updated");
		return true;
	}

	public static function formatter(float|int|string|null $amount, bool|null $show_free=false, string|null $currency=null) : string {
		if(null!==$early_return=core::dialback("CALL_CURRENCY_FORMATTER",...func_get_args())) return $early_return;
		if($currency===null)$currency=currency::$display_currency;
		$currency=mb_strtoupper(trim((string)$currency));
		$minor_amount=self::amount_to_minor_units($amount, $currency);
		if($minor_amount===0 && $show_free===true){
			return locale('global:FREE', 'Free');
		}
		if(isset(self::$special_formatting[self::$display_language][self::$display_country])){
			[$decimal_separator, $thousands_separator]=array_pad(
				self::$special_formatting[self::$display_language][self::$display_country],
				2,
				null
			);
		}
		else
		{
			[$decimal_separator, $thousands_separator]=[',', ' '];
		}
		$currency_symbol=currency::$available_currencies[$currency] ?? ($currency.' ');
		return $currency_symbol.self::format_decimal_amount_string(
			self::minor_units_to_amount($minor_amount, $currency),
			$decimal_separator,
			$thousands_separator
		);
	}

	protected static function format_decimal_amount_string(string $amount, ?string $decimal_separator, ?string $thousands_separator): string {
		$negative=isset($amount[0]) && $amount[0]==='-';
		if($negative){
			$amount=substr($amount, 1);
		}
		[$whole, $fraction]=array_pad(explode('.', $amount, 2), 2, '');
		$whole=$whole==='' ? '0' : $whole;
		$separator=$thousands_separator ?? '';
		if($separator!==''){
			$groups=[];
			for($offset=strlen($whole); $offset>0; $offset-=3){
				$start=max(0, $offset-3);
				$groups[]=substr($whole, $start, $offset-$start);
			}
			$whole=implode($separator, array_reverse($groups));
		}
		if($fraction!==''){
			$whole.=($decimal_separator ?? '.').$fraction;
		}
		return ($negative ? '-' : '').$whole;
	}
	
	public static function convert(float|int|string|null $amount, string $source_currency, string $target_currency, bool|null $formatted=false, bool|null $show_free=true): string|float {
		if(null!==$early_return=core::dialback("CALL_CURRENCY_CONVERT_TO_USER_CURRENCY",...func_get_args())) return $early_return;
		$source_currency=mb_strtoupper(trim($source_currency));
		$target_currency=mb_strtoupper(trim($target_currency));
		$source_minor=self::amount_to_minor_units($amount, $source_currency);
		if($source_currency==='' || $source_currency===$target_currency){
			if($source_minor===0 && $show_free===true){
				return locale('global:FREE', 'Free');
			}
			$value=self::minor_units_to_amount($source_minor, $target_currency);
			if($formatted===false)return $value;
			return self::formatter($value, $show_free, $target_currency);
		}
		if(!self::has_valid_session_exchange_rates(self::exchange_rate_sources())){
			self::get_exchange_rates();
		}
		if(empty($_SESSION['exchange_rate_data']['data']) || !is_array($_SESSION['exchange_rate_data']['data'])){
			if(RUN_MODE!=='diagnostic'){
				core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCurrency: No cached rates available in session.', 'safemode');
			}
		}
		$source_multiplier=$_SESSION['exchange_rate_data']['data'][$source_currency] ?? 1;
		$target_multiplier=$_SESSION['exchange_rate_data']['data'][$target_currency] ?? 1;
		$target_minor=self::convert_minor_units_with_multipliers($source_minor, $source_currency, $target_currency, $source_multiplier, $target_multiplier);
		if($source_minor===0){
			if($show_free===true)return locale('global:FREE', 'Free');
			return self::minor_units_to_amount(0, $target_currency);
		}
		$value=self::minor_units_to_amount($target_minor, $target_currency);
		if($formatted===false)return $value;
		return self::formatter($value, $show_free, $target_currency);
	}

	public static function convert_to_user_currency(float|int|string|null $amount, bool|null $formatted=false, bool|null $show_free=true, string|null $currency=null) : string|float {
		if($currency===null)$currency=currency::$display_currency;
		return self::convert($amount, currency::$base_currency, $currency, $formatted, $show_free);
	}

	public static function convert_to_website_currency(float|int|string|null $amount, string $original_currency, bool|null $formatted=false, bool|null $show_free=true) : string|float {
		return self::convert($amount, $original_currency, currency::$base_currency, $formatted, $show_free);
	}
	
}
