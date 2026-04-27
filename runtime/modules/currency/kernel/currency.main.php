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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		$source=self::normalize_source_name($source);
		if($source===''){
			return;
		}
		self::$exchange_rate_callbacks[$source]=$callback;
	}

	public static function register_exchange_rate_sources(array $callbacks): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
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
		return (int)round(pow(10, self::minor_units($currency)));
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

	public static function round_amount(float|int|null $amount, string $currency, bool $cash=false): float {
		$currency=mb_strtoupper(trim($currency));
		$precision=self::minor_units($currency);
		$amount=(float)$amount;
		if($cash===true){
			$increment=self::cash_rounding_increment($currency);
			if($increment!==null){
				return self::round_to_increment($amount, $increment, $precision);
			}
		}
		return round($amount, $precision);
	}

	protected static function amount_to_allocation_units(float|int|null $amount, string $currency, bool $cash=false): int {
		$rounded=self::round_amount($amount, $currency, $cash);
		$minor=(int)round($rounded*self::minor_factor($currency));
		$step=self::allocation_minor_step($currency, $cash);
		return (int)round($minor/$step);
	}

	protected static function allocation_units_to_amount(int $units, string $currency, bool $cash=false): float {
		$minor=$units*self::allocation_minor_step($currency, $cash);
		return round($minor/self::minor_factor($currency), self::minor_units($currency));
	}

	public static function split_amount(float|int|null $amount, string $currency, int $parts, bool $cash=false): array {
		if($parts<=0){
			return [];
		}
		$total_units=self::amount_to_allocation_units($amount, $currency, $cash);
		$sign=$total_units<0 ? -1 : 1;
		$total_units=abs($total_units);
		$base_units=intdiv($total_units, $parts);
		$remainder=$total_units % $parts;
		$allocations=[];
		for($index=0;$index<$parts;$index++){
			$units=$base_units+($index<$remainder ? 1 : 0);
			$allocations[]=
				self::allocation_units_to_amount($units*$sign, $currency, $cash);
		}
		return $allocations;
	}

	public static function allocate_amount(float|int|null $amount, string $currency, array $ratios, bool $cash=false): array {
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
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

	public static function formatter(float|null $amount, bool|null $show_free=false, string|null $currency=null) : string {
		if(null!==$early_return=core::dialback("CALL_CURRENCY_FORMATTER",...func_get_args())) return $early_return;
		if($currency===null)$currency=currency::$display_currency;
		$currency=mb_strtoupper(trim((string)$currency));
		if((float)$amount==0.0 && $show_free===true){
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
		$decimals=self::minor_units($currency);
		$amount=self::round_amount($amount, $currency);
		$currency_symbol=currency::$available_currencies[$currency] ?? ($currency.' ');
		return $currency_symbol.number_format($amount, $decimals, $decimal_separator, $thousands_separator);
	}
	
	public static function convert(float|null $amount, string $source_currency, string $target_currency, bool|null $formatted=false, bool|null $show_free=true): string|float {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		if(null!==$early_return=core::dialback("CALL_CONVERT_TO_USER_CURRENCY",...func_get_args())) return $early_return;
		if(!self::has_valid_session_exchange_rates(self::exchange_rate_sources())){
			self::get_exchange_rates();
		}
		if(empty($_SESSION['exchange_rate_data']['data']) || !is_array($_SESSION['exchange_rate_data']['data'])){
			if(RUN_MODE!=='diagnostic'){
				core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCurrency: No cached rates available in session.', 'safemode');
			}
		}
		$amount=(float)$amount;
		$source_currency=mb_strtoupper($source_currency);
		$target_currency=mb_strtoupper($target_currency);
		$source_multiplier=$_SESSION['exchange_rate_data']['data'][$source_currency] ?? 1;
		$target_multiplier=$_SESSION['exchange_rate_data']['data'][$target_currency] ?? 1;
		$value=self::round_amount(($amount/$source_multiplier)*$target_multiplier, $target_currency);
		if($amount==0){
			if($show_free===true)return locale('global:FREE', 'Free');
			return number_format(0, self::minor_units($target_currency), ".", "");
		}
		if($formatted===false)return number_format($value, self::minor_units($target_currency), ".", "");
		return self::formatter($value, $show_free, $target_currency);
	}

	public static function convert_to_user_currency(float|null $amount, bool|null $formatted=false, bool|null $show_free=true, string|null $currency=null) : string|float {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		if($currency===null)$currency=currency::$display_currency;
		return self::convert($amount, currency::$base_currency, $currency, $formatted, $show_free);
	}

	public static function convert_to_website_currency(float|null $amount, string $original_currency, bool|null $formatted=false, bool|null $show_free=true) : string|float {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		return self::convert($amount, $original_currency, currency::$base_currency, $formatted, $show_free);
	}
	
}
