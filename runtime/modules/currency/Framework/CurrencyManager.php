<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

/**
 * Currency runtime coordinator for rates, formatting, conversion, and money objects.
 *
 * CurrencyManager wraps the legacy kernel currency state with typed framework
 * objects. It owns temporary state overrides, exchange-rate refresh boundaries,
 * freshness assertions, Money creation/conversion/storage workflows, and minor
 * unit maps used by snapshots and quotes.
 */
final class CurrencyManager {

	private static ?self $instance=null;
	private ?array $lastSplitAmountKey=null;
	private ?array $lastSplitAmountResult=null;

	/**
	 * Returns the process-local currency manager.
	 *
	 * @return self Shared manager instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Clears the process-local currency manager singleton.
	 */
	public static function flush(): void {
		self::$instance=null;
	}

	/**
	 * Returns the current currency state with optional scoped overrides applied.
	 *
	 * Overrides are filtered to supported state keys before being merged with the
	 * legacy kernel state, so invalid runtime options cannot leak into CurrencyState.
	 *
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides filtered before touching kernel currency state.
	 * @return CurrencyState Typed currency state snapshot.
	 */
	public function state(array $overrides=[]): CurrencyState {
		return CurrencyState::fromArray(array_replace(
			\dataphyre\currency::state(),
			$this->filterStateOverrides($overrides)
		));
	}

	/**
	 * Creates a reusable currency context with selected state overrides.
	 *
	 * @param ?string $display_currency Display currency override.
	 * @param ?string $display_language Display language override.
	 * @param ?string $display_country Display country override.
	 * @param ?string $base_currency Base currency override.
	 * @param ?array<string,string> $available_currencies Available currency symbol map override.
	 * @return CurrencyContext Context bound to this manager.
	 */
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

	/**
	 * Returns the active base currency code.
	 *
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides.
	 * @return string Uppercase ISO-style currency code.
	 */
	public function baseCurrency(array $overrides=[]): string {
		return $this->state($overrides)->baseCurrency();
	}

	/**
	 * Returns the active display currency code.
	 *
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides.
	 * @return string Uppercase ISO-style currency code.
	 */
	public function displayCurrency(array $overrides=[]): string {
		return $this->state($overrides)->displayCurrency();
	}

	/**
	 * Returns the active display language.
	 *
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides.
	 * @return string Language tag.
	 */
	public function displayLanguage(array $overrides=[]): string {
		return $this->state($overrides)->displayLanguage();
	}

	/**
	 * Returns the active display country.
	 *
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides.
	 * @return string Uppercase country code.
	 */
	public function displayCountry(array $overrides=[]): string {
		return $this->state($overrides)->displayCountry();
	}

	/**
	 * Returns currencies allowed by the active state.
	 *
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides.
	 * @return array<string,string> Currency code to symbol or label map.
	 */
	public function availableCurrencies(array $overrides=[]): array {
		return $this->state($overrides)->availableCurrencies();
	}

	/**
	 * Lists configured exchange-rate source names.
	 *
	 * @return list<string> Exchange-rate source identifiers.
	 */
	public function exchangeRateSources(): array {
		return \dataphyre\currency::exchange_rate_sources();
	}

	/**
	 * Returns ISO minor-unit precision for a currency.
	 *
	 * @param string $currency Currency code.
	 * @return int Decimal minor units.
	 */
	public function minorUnits(string $currency): int {
		return \dataphyre\currency::minor_units(mb_strtoupper(trim($currency)));
	}

	/**
	 * Returns the cash rounding increment for a currency.
	 *
	 * @param string $currency Currency code.
	 * @return ?float Cash rounding increment, or null when none is configured.
	 */
	public function cashRoundingIncrement(string $currency): ?float {
		return \dataphyre\currency::cash_rounding_increment(mb_strtoupper(trim($currency)));
	}

	/**
	 * Registers one exchange-rate source callback.
	 *
	 * @param string $source Source identifier.
	 * @param callable $callback Provider callback consumed by the kernel currency module.
	 */
	public function registerSource(string $source, callable $callback): void {
		\dataphyre\currency::register_exchange_rate_source($source, $callback);
	}

	/**
	 * Registers multiple exchange-rate source callbacks.
	 *
	 * @param array<string,callable> $callbacks Source callbacks keyed by source identifier.
	 */
	public function registerSources(array $callbacks): void {
		\dataphyre\currency::register_exchange_rate_sources($callbacks);
	}

	/**
	 * Loads exchange rates, optionally refreshing first or targeting one source.
	 *
	 * When a source is supplied this always refreshes that source. Otherwise cached
	 * rates are used unless refresh is requested. Temporary overrides are applied
	 * only for the duration of the rate lookup.
	 *
	 * @param bool $refresh Force provider refresh before returning rates.
	 * @param ?string $source Optional source identifier.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during the rate lookup.
	 * @return ExchangeRates Typed exchange-rate collection.
	 */
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

	/**
	 * Refreshes exchange-rate data and returns the resulting rates.
	 *
	 * With a source, only that source is refreshed. Without a source, configured
	 * sources are tried until one refresh succeeds; if none succeeds, cached or
	 * fallback exchange rates are still loaded.
	 *
	 * @param ?string $source Optional source identifier.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during refresh/loading.
	 * @return ExchangeRates Refreshed or loaded exchange rates.
	 */
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

	/**
	 * Refreshes one exchange-rate source.
	 *
	 * @param string $source Source identifier.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during the provider refresh.
	 * @return bool Provider refresh success flag.
	 */
	public function refreshSource(string $source, array $overrides=[]): bool {
		return $this->withStateOverrides($overrides, static function() use($source): bool {
			return \dataphyre\currency::get_rates_data($source)===true;
		});
	}

	/**
	 * Reads one currency rate from the active exchange-rate collection.
	 *
	 * @param string $currency Currency code.
	 * @param bool $refresh Force refresh before lookup.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during the rate lookup.
	 * @return ?float Rate, or null when unavailable.
	 */
	public function rate(string $currency, bool $refresh=false, array $overrides=[]): ?float {
		$rates=$this->rates($refresh, null, $overrides);
		return $rates->rate($currency);
	}

	/**
	 * Checks whether a currency rate exists.
	 *
	 * @param string $currency Currency code.
	 * @param bool $refresh Force refresh before lookup.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during the rate lookup.
	 * @return bool Rate availability decision.
	 */
	public function hasRate(string $currency, bool $refresh=false, array $overrides=[]): bool {
		return $this->rate($currency, $refresh, $overrides)!==null;
	}

	/**
	 * Builds an exchange quote between two currencies when rates are available.
	 *
	 * @param string $source_currency Source currency code.
	 * @param string $target_currency Target currency code.
	 * @param bool $refresh Force refresh before quoting.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during quote lookup.
	 * @return ?ExchangeQuote Quote, or null when unavailable.
	 */
	public function quote(
		string $source_currency,
		string $target_currency,
		bool $refresh=false,
		array $overrides=[]
	): ?ExchangeQuote {
		return $this->rates($refresh, null, $overrides)->quote($source_currency, $target_currency);
	}

	/**
	 * Builds an exchange quote or throws when rates are unavailable.
	 *
	 * @param string $source_currency Source currency code.
	 * @param string $target_currency Target currency code.
	 * @param bool $refresh Force refresh before quoting.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during quote lookup.
	 * @return ExchangeQuote Available exchange quote.
	 */
	public function quoteOrFail(
		string $source_currency,
		string $target_currency,
		bool $refresh=false,
		array $overrides=[]
	): ExchangeQuote {
		return $this->rates($refresh, null, $overrides)->quoteOrFail($source_currency, $target_currency);
	}

	/**
	 * Captures an exchange-rate snapshot for freshness checks and persistence.
	 *
	 * @param bool $refresh Force refresh before snapshot.
	 * @param ?string $source Optional source identifier.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides captured with the snapshot.
	 * @return ExchangeSnapshot Snapshot bound to this manager.
	 */
	public function snapshot(bool $refresh=false, ?string $source=null, array $overrides=[]): ExchangeSnapshot {
		return $this->rates($refresh, $source, $overrides)->snapshot($this, $overrides);
	}

	/**
	 * Captures a snapshot and asserts it is fresh enough.
	 *
	 * @param int $max_age_seconds Maximum allowed snapshot age in seconds.
	 * @param bool $refresh Force refresh before snapshot.
	 * @param ?string $source Optional source identifier.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides captured with the snapshot.
	 * @return ExchangeSnapshot Fresh snapshot.
	 */
	public function snapshotOrFail(
		int $max_age_seconds,
		bool $refresh=false,
		?string $source=null,
		array $overrides=[]
	): ExchangeSnapshot {
		return $this->snapshot($refresh, $source, $overrides)->assertFresh($max_age_seconds);
	}

	/**
	 * Returns a fresh exchange quote or throws when data is stale or unavailable.
	 *
	 * @param string $source_currency Source currency code.
	 * @param string $target_currency Target currency code.
	 * @param int $max_age_seconds Maximum allowed snapshot age in seconds.
	 * @param bool $refresh Force refresh before snapshot.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during fresh quote lookup.
	 * @return ExchangeQuote Fresh exchange quote.
	 */
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

	/**
	 * Formats an amount using active currency display state.
	 *
	 * @param float|int|null $amount Amount to format.
	 * @param bool $show_free Whether zero/null values may render as free.
	 * @param ?string $currency Optional currency override.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during formatting.
	 * @return string Formatted money string.
	 */
	public function format(float|int|null $amount, bool $show_free=false, ?string $currency=null, array $overrides=[]): string {
		return $this->withStateOverrides($overrides, static function() use($amount, $show_free, $currency): string {
			return (string)\dataphyre\currency::formatter((float)$amount, $show_free, $currency);
		});
	}

	/**
	 * Rounds an amount according to currency precision or cash increment.
	 *
	 * @param float|int|null $amount Amount to round.
	 * @param string $currency Currency code.
	 * @param bool $cash Use cash rounding rules.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during rounding.
	 * @return float Rounded amount.
	 */
	public function roundAmount(float|int|null $amount, string $currency, bool $cash=false, array $overrides=[]): float {
		return $this->withStateOverrides($overrides, static function() use($amount, $currency, $cash): float {
			return \dataphyre\currency::round_amount((float)$amount, mb_strtoupper(trim($currency)), $cash);
		});
	}

	/**
	 * Converts an amount between explicit currencies.
	 *
	 * @param float|int|null $amount Amount to convert.
	 * @param string $source_currency Source currency code.
	 * @param string $target_currency Target currency code.
	 * @param bool $formatted Return formatted display string.
	 * @param bool $show_free Whether formatted zero/null values may render as free.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during conversion.
	 * @return string|float Converted amount or formatted string.
	 */
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

	/**
	 * Converts an amount from base currency to the active display currency.
	 *
	 * @param float|int|null $amount Amount to convert.
	 * @param bool $formatted Return formatted display string.
	 * @param bool $show_free Whether formatted zero/null values may render as free.
	 * @param ?string $currency Optional display currency override.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during display conversion.
	 * @return string|float Converted amount or formatted string.
	 */
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

	/**
	 * Converts an amount from an original currency to the active base currency.
	 *
	 * @param float|int|null $amount Amount to convert.
	 * @param string $original_currency Original currency code.
	 * @param bool $formatted Return formatted display string.
	 * @param bool $show_free Whether formatted zero/null values may render as free.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during base-currency conversion.
	 * @return string|float Converted amount or formatted string.
	 */
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

	/**
	 * Creates an immutable Money value bound to this manager and context.
	 *
	 * @param float|int|null $amount Monetary amount.
	 * @param ?string $currency Currency code, or base currency when omitted.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Context overrides carried by the Money object.
	 * @return Money Money value object.
	 */
	public function money(float|int|null $amount, ?string $currency=null, array $overrides=[]): Money {
		$currency=$currency===null ? $this->baseCurrency($overrides) : mb_strtoupper(trim($currency));
		return new Money((float)$amount, $currency, $this, $overrides);
	}

	/**
	 * Converts a Money object to another currency.
	 *
	 * Money context overrides are preserved and can be supplemented by caller
	 * overrides before quote lookup.
	 *
	 * @param Money $money Source money value.
	 * @param string $target_currency Target currency code.
	 * @param bool $refresh Force refresh before quoting.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Additional temporary state overrides merged over the Money context.
	 * @return Money Converted money value.
	 */
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

	/**
	 * Converts Money using a snapshot that must satisfy a freshness limit.
	 *
	 * @param Money $money Source money value.
	 * @param string $target_currency Target currency code.
	 * @param int $max_age_seconds Maximum allowed snapshot age in seconds.
	 * @param bool $refresh Force refresh before snapshot.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Additional temporary state overrides merged over the Money context.
	 * @return Money Converted money value.
	 */
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

	/**
	 * Converts a scalar amount using a fresh-enough snapshot.
	 *
	 * @param float|int|null $amount Amount to convert.
	 * @param string $source_currency Source currency code.
	 * @param string $target_currency Target currency code.
	 * @param int $max_age_seconds Maximum allowed snapshot age in seconds.
	 * @param bool $refresh Force refresh before snapshot.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides applied only during conversion.
	 * @return float Converted amount.
	 */
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

	/**
	 * Captures a Money value for storage with base-currency conversion metadata.
	 *
	 * @param Money $money Money value to persist.
	 * @param ?string $base_currency Storage/base currency override.
	 * @param bool $refresh Force refresh before snapshot.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Additional temporary state overrides merged over the Money context before snapshotting.
	 * @return StoredMoney Storage-ready money payload.
	 */
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

	/**
	 * Captures a Money value for storage using a fresh-enough snapshot.
	 *
	 * @param Money $money Money value to persist.
	 * @param int $max_age_seconds Maximum allowed snapshot age in seconds.
	 * @param ?string $base_currency Storage/base currency override.
	 * @param bool $refresh Force refresh before snapshot.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Additional temporary state overrides merged over the Money context before snapshotting.
	 * @return StoredMoney Storage-ready money payload.
	 */
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

	/**
	 * Splits an amount into equal Money parts while preserving rounding remainder.
	 *
	 * @param float|int|null $amount Amount to split.
	 * @param string $currency Currency code.
	 * @param int $parts Number of parts.
	 * @param bool $cash Use cash rounding rules.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Context overrides for returned Money objects.
	 * @return list<Money> Split money parts whose rounded amounts preserve the original total.
	 */
	public function splitAmount(
		float|int|null $amount,
		string $currency,
		int $parts,
		bool $cash=false,
		array $overrides=[]
	): array {
		$currency=mb_strtoupper(trim($currency));
		if($overrides===[]){
			$cache_key=[
				(float)$amount,
				$currency,
				$parts,
				$cash,
				$this->minorUnits($currency),
				$cash ? $this->cashRoundingIncrement($currency) : null,
			];
			if($this->lastSplitAmountResult!==null && $this->lastSplitAmountKey===$cache_key){
				return $this->lastSplitAmountResult;
			}
			$amounts=\dataphyre\currency::split_amount((float)$amount, $currency, $parts, $cash);
			$result=array_map(fn(float $part): Money => new Money($part, $currency, $this), $amounts);
			$this->lastSplitAmountKey=$cache_key;
			return $this->lastSplitAmountResult=$result;
		}
		return $this->withStateOverrides($overrides, function() use($amount, $currency, $parts, $cash, $overrides): array {
			$amounts=\dataphyre\currency::split_amount((float)$amount, $currency, $parts, $cash);
			return array_map(fn(float $part): Money => new Money($part, $currency, $this, $overrides), $amounts);
		});
	}

	/**
	 * Allocates an amount by ratios and returns Money values keyed like the ratios.
	 *
	 * @param float|int|null $amount Amount to allocate.
	 * @param string $currency Currency code.
	 * @param array<int|string,int|float> $ratios Allocation ratios keyed by recipient.
	 * @param bool $cash Use cash rounding rules.
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Context overrides for returned Money objects.
	 * @return array<int|string,Money> Allocated money values keyed like the ratio input.
	 */
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

	/**
	 * Runs a callback with temporary kernel currency state overrides.
	 *
	 * The original kernel state is restored in a finally block, so exceptions
	 * cannot leak display/base currency changes into later requests.
	 *
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $overrides Temporary state overrides restored after the callback exits or throws.
	 * @param callable $callback Callback executed with overrides applied.
	 * @return mixed value returned by the callback while temporary currency state is active.
	 */
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

	/**
	 * Filters and normalizes supported currency state override keys.
	 *
	 * Currency and country values are uppercased; display language is preserved
	 * after trim; unsupported keys are dropped.
	 *
	 * @param array<string,mixed> $overrides Raw override map from callers or Money context.
	 * @return array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} Filtered override map safe to apply to kernel currency state.
	 */
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

	/**
	 * Builds a currency-to-minor-units map for exchange-rate data.
	 *
	 * The active base currency is always included even if it is not present in the
	 * provider data set.
	 *
	 * @param array{data?:array<string,mixed>} $exchange_rate_data Raw exchange-rate data keyed by currency code.
	 * @return array<string, int> Minor-unit precision keyed by currency code.
	 */
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
