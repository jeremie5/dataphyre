<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

final class CurrencyManager {

	private static ?self $instance=null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public static function flush(): void {
		self::$instance=null;
	}

	public function state(array $overrides=[]): CurrencyState {
		return CurrencyState::fromArray(array_replace(
			\dataphyre\currency::state(),
			$this->filterStateOverrides($overrides)
		));
	}

	public function context(
		?string $display_currency=null,
		?string $display_language=null,
		?string $display_country=null,
		?string $base_currency=null,
		?array $available_currencies=null
	): CurrencyContext {
		return new CurrencyContext(
			$this,
			array_filter([
				'display_currency'=>$display_currency,
				'display_language'=>$display_language,
				'display_country'=>$display_country,
				'base_currency'=>$base_currency,
				'available_currencies'=>$available_currencies,
			], static fn(mixed $value): bool => $value!==null)
		);
	}

	public function baseCurrency(array $overrides=[]): string {
		return $this->state($overrides)->baseCurrency();
	}

	public function displayCurrency(array $overrides=[]): string {
		return $this->state($overrides)->displayCurrency();
	}

	public function displayLanguage(array $overrides=[]): string {
		return $this->state($overrides)->displayLanguage();
	}

	public function displayCountry(array $overrides=[]): string {
		return $this->state($overrides)->displayCountry();
	}

	public function availableCurrencies(array $overrides=[]): array {
		return $this->state($overrides)->availableCurrencies();
	}

	public function exchangeRateSources(): array {
		return \dataphyre\currency::exchange_rate_sources();
	}

	public function minorUnits(string $currency): int {
		return \dataphyre\currency::minor_units(mb_strtoupper(trim($currency)));
	}

	public function cashRoundingIncrement(string $currency): ?float {
		return \dataphyre\currency::cash_rounding_increment(mb_strtoupper(trim($currency)));
	}

	public function registerSource(string $source, callable $callback): void {
		\dataphyre\currency::register_exchange_rate_source($source, $callback);
	}

	public function registerSources(array $callbacks): void {
		\dataphyre\currency::register_exchange_rate_sources($callbacks);
	}

	public function rates(bool $refresh=false, ?string $source=null, array $overrides=[]): ExchangeRates {
		if($source!==null){
			return $this->refresh($source, $overrides);
		}
		if($refresh===true){
			return $this->refresh($source, $overrides);
		}
		return $this->withStateOverrides($overrides, function(): ExchangeRates {
			$exchange_rate_data=\dataphyre\currency::get_exchange_rates();
			return ExchangeRates::fromArray($exchange_rate_data, $this->baseCurrency(), $this->minorUnitMap($exchange_rate_data));
		});
	}

	public function refresh(?string $source=null, array $overrides=[]): ExchangeRates {
		return $this->withStateOverrides($overrides, function() use($source): ExchangeRates {
			if($source!==null){
				$this->refreshSource($source);
			}else{
				$refreshed=false;
				foreach(\dataphyre\currency::exchange_rate_sources() as $configured_source){
					if(\dataphyre\currency::get_rates_data($configured_source)===true){
						$refreshed=true;
						break;
					}
				}
				if($refreshed===false){
					\dataphyre\currency::get_exchange_rates();
				}
			}
			$exchange_rate_data=\dataphyre\currency::get_exchange_rates();
			return ExchangeRates::fromArray($exchange_rate_data, $this->baseCurrency(), $this->minorUnitMap($exchange_rate_data));
		});
	}

	public function refreshSource(string $source, array $overrides=[]): bool {
		return $this->withStateOverrides($overrides, static function() use($source): bool {
			return \dataphyre\currency::get_rates_data($source)===true;
		});
	}

	public function rate(string $currency, bool $refresh=false, array $overrides=[]): ?float {
		$rates=$this->rates($refresh, null, $overrides);
		return $rates->rate($currency);
	}

	public function hasRate(string $currency, bool $refresh=false, array $overrides=[]): bool {
		return $this->rate($currency, $refresh, $overrides)!==null;
	}

	public function quote(
		string $source_currency,
		string $target_currency,
		bool $refresh=false,
		array $overrides=[]
	): ?ExchangeQuote {
		return $this->rates($refresh, null, $overrides)->quote($source_currency, $target_currency);
	}

	public function quoteOrFail(
		string $source_currency,
		string $target_currency,
		bool $refresh=false,
		array $overrides=[]
	): ExchangeQuote {
		return $this->rates($refresh, null, $overrides)->quoteOrFail($source_currency, $target_currency);
	}

	public function snapshot(bool $refresh=false, ?string $source=null, array $overrides=[]): ExchangeSnapshot {
		return $this->rates($refresh, $source, $overrides)->snapshot($this, $overrides);
	}

	public function snapshotOrFail(
		int $max_age_seconds,
		bool $refresh=false,
		?string $source=null,
		array $overrides=[]
	): ExchangeSnapshot {
		return $this->snapshot($refresh, $source, $overrides)->assertFresh($max_age_seconds);
	}

	public function quoteOrFailFresh(
		string $source_currency,
		string $target_currency,
		int $max_age_seconds,
		bool $refresh=false,
		array $overrides=[]
	): ExchangeQuote {
		return $this->snapshotOrFail($max_age_seconds, $refresh, null, $overrides)
			->quoteOrFail($source_currency, $target_currency);
	}

	public function format(float|int|null $amount, bool $show_free=false, ?string $currency=null, array $overrides=[]): string {
		return $this->withStateOverrides($overrides, static function() use($amount, $show_free, $currency): string {
			return (string)\dataphyre\currency::formatter((float)$amount, $show_free, $currency);
		});
	}

	public function roundAmount(float|int|null $amount, string $currency, bool $cash=false, array $overrides=[]): float {
		return $this->withStateOverrides($overrides, static function() use($amount, $currency, $cash): float {
			return \dataphyre\currency::round_amount((float)$amount, mb_strtoupper(trim($currency)), $cash);
		});
	}

	public function convert(
		float|int|null $amount,
		string $source_currency,
		string $target_currency,
		bool $formatted=false,
		bool $show_free=true,
		array $overrides=[]
	): string|float {
		return $this->withStateOverrides($overrides, static function() use($amount, $source_currency, $target_currency, $formatted, $show_free): string|float {
			$result=\dataphyre\currency::convert(
				(float)$amount,
				mb_strtoupper(trim($source_currency)),
				mb_strtoupper(trim($target_currency)),
				$formatted,
				$show_free
			);
			return $formatted ? (string)$result : (float)$result;
		});
	}

	public function convertToDisplay(
		float|int|null $amount,
		bool $formatted=false,
		bool $show_free=true,
		?string $currency=null,
		array $overrides=[]
	): string|float {
		if($currency!==null){
			$overrides['display_currency']=$currency;
		}
		return $this->withStateOverrides($overrides, static function() use($amount, $formatted, $show_free, $currency): string|float {
			$result=\dataphyre\currency::convert_to_user_currency((float)$amount, $formatted, $show_free, $currency);
			return $formatted ? (string)$result : (float)$result;
		});
	}

	public function convertToBase(
		float|int|null $amount,
		string $original_currency,
		bool $formatted=false,
		bool $show_free=true,
		array $overrides=[]
	): string|float {
		return $this->withStateOverrides($overrides, static function() use($amount, $original_currency, $formatted, $show_free): string|float {
			$result=\dataphyre\currency::convert_to_website_currency(
				(float)$amount,
				mb_strtoupper(trim($original_currency)),
				$formatted,
				$show_free
			);
			return $formatted ? (string)$result : (float)$result;
		});
	}

	public function money(float|int|null $amount, ?string $currency=null, array $overrides=[]): Money {
		$currency=$currency===null ? $this->baseCurrency($overrides) : mb_strtoupper(trim($currency));
		return new Money((float)$amount, $currency, $this, $overrides);
	}

	public function convertMoney(Money $money, string $target_currency, bool $refresh=false, array $overrides=[]): Money {
		$overrides=array_replace($money->contextOverrides(), $overrides);
		$target_currency=mb_strtoupper(trim($target_currency));
		$quote=$this->quoteOrFail($money->currency(), $target_currency, $refresh, $overrides);
		return new Money(
			$quote->convert($money->amount()),
			$target_currency,
			$this,
			$overrides
		);
	}

	public function convertMoneyOrFailFresh(
		Money $money,
		string $target_currency,
		int $max_age_seconds,
		bool $refresh=false,
		array $overrides=[]
	): Money {
		$overrides=array_replace($money->contextOverrides(), $overrides);
		return $this->snapshotOrFail($max_age_seconds, $refresh, null, $overrides)
			->convertMoney($money, $target_currency);
	}

	public function convertOrFailFresh(
		float|int|null $amount,
		string $source_currency,
		string $target_currency,
		int $max_age_seconds,
		bool $refresh=false,
		array $overrides=[]
	): float {
		return $this->snapshotOrFail($max_age_seconds, $refresh, null, $overrides)
			->convert($amount, $source_currency, $target_currency);
	}

	public function storeMoney(
		Money $money,
		?string $base_currency=null,
		bool $refresh=false,
		array $overrides=[]
	): StoredMoney {
		$overrides=array_replace($money->contextOverrides(), $overrides);
		return $this->snapshot($refresh, null, $overrides)
			->storeMoney($money, $base_currency ?? $this->baseCurrency($overrides));
	}

	public function storeMoneyOrFailFresh(
		Money $money,
		int $max_age_seconds,
		?string $base_currency=null,
		bool $refresh=false,
		array $overrides=[]
	): StoredMoney {
		$overrides=array_replace($money->contextOverrides(), $overrides);
		return $this->snapshotOrFail($max_age_seconds, $refresh, null, $overrides)
			->storeMoney($money, $base_currency ?? $this->baseCurrency($overrides));
	}

	public function splitAmount(
		float|int|null $amount,
		string $currency,
		int $parts,
		bool $cash=false,
		array $overrides=[]
	): array {
		return $this->withStateOverrides($overrides, function() use($amount, $currency, $parts, $cash, $overrides): array {
			$currency=mb_strtoupper(trim($currency));
			$amounts=\dataphyre\currency::split_amount((float)$amount, $currency, $parts, $cash);
			return array_map(fn(float $part): Money => new Money($part, $currency, $this, $overrides), $amounts);
		});
	}

	public function allocateAmount(
		float|int|null $amount,
		string $currency,
		array $ratios,
		bool $cash=false,
		array $overrides=[]
	): array {
		return $this->withStateOverrides($overrides, function() use($amount, $currency, $ratios, $cash, $overrides): array {
			$currency=mb_strtoupper(trim($currency));
			$amounts=\dataphyre\currency::allocate_amount((float)$amount, $currency, $ratios, $cash);
			$allocations=[];
			foreach($amounts as $key=>$allocated_amount){
				$allocations[$key]=new Money((float)$allocated_amount, $currency, $this, $overrides);
			}
			return $allocations;
		});
	}

	public function withStateOverrides(array $overrides, callable $callback): mixed {
		$overrides=$this->filterStateOverrides($overrides);
		if($overrides===[]){
			return $callback();
		}
		$original_state=\dataphyre\currency::state();
		\dataphyre\currency::apply_state($overrides);
		try{
			return $callback();
		} finally {
			\dataphyre\currency::apply_state($original_state);
		}
	}

	private function filterStateOverrides(array $overrides): array {
		$filtered=[];
		foreach($overrides as $key=>$value){
			switch($key){
				case 'base_currency':
				case 'display_currency':
				case 'display_language':
				case 'display_country':
					if(is_string($value) && trim($value)!==''){
						$filtered[$key]=$key==='display_language' ? trim($value) : mb_strtoupper(trim($value));
						if($key==='display_language'){
							$filtered[$key]=trim($value);
						}
					}
					break;
				case 'available_currencies':
					if(is_array($value)){
						$filtered[$key]=$value;
					}
					break;
			}
		}
		return $filtered;
	}

	private function minorUnitMap(array $exchange_rate_data): array {
		$currencies=array_keys(is_array($exchange_rate_data['data'] ?? null) ? $exchange_rate_data['data'] : []);
		$currencies[]= $this->baseCurrency();
		$map=[];
		foreach(array_unique($currencies) as $currency){
			if(!is_string($currency) || trim($currency)===''){
				continue;
			}
			$currency=mb_strtoupper(trim($currency));
			$map[$currency]=$this->minorUnits($currency);
		}
		return $map;
	}
}
