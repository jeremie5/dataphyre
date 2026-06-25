<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

/**
 * Static facade for currency state, exchange rates, conversion, and Money values.
 *
 * Currency forwards to the process-local CurrencyManager while preserving a
 * compact application-facing API for formatting, conversion, snapshots, and
 * allocation helpers.
 */
final class Currency {

	/**
	 * Returns the shared currency manager.
	 *
	 * @return CurrencyManager Process-local manager.
	 */
	public static function manager(): CurrencyManager {
		return CurrencyManager::instance();
	}

	/**
	 * Clears the shared currency manager singleton.
	 */
	public static function flush(): void {
		CurrencyManager::flush();
	}

	/** Returns the active currency state snapshot. */
	public static function state(): CurrencyState {
		return self::manager()->state();
	}

	/**
	 * Creates a reusable currency context.
	 *
	 * @param ?string $display_currency Display currency override.
	 * @param ?string $display_language Display language override.
	 * @param ?string $display_country Display country override.
	 * @param ?string $base_currency Base currency override.
	 * @param ?array $available_currencies Available currency list override.
	 * @return CurrencyContext Context bound to the shared manager.
	 */
	public static function context(
		?string $display_currency=null,
		?string $display_language=null,
		?string $display_country=null,
		?string $base_currency=null,
		?array $available_currencies=null
	): CurrencyContext {
		return self::manager()->context(
			$display_currency,
			$display_language,
			$display_country,
			$base_currency,
			$available_currencies
		);
	}

	/** Returns the active base currency code. */
	public static function baseCurrency(): string {
		return self::manager()->baseCurrency();
	}

	/** Returns the active display currency code. */
	public static function displayCurrency(): string {
		return self::manager()->displayCurrency();
	}

	/** Returns the active display language. */
	public static function displayLanguage(): string {
		return self::manager()->displayLanguage();
	}

	/** Returns the active display country. */
	public static function displayCountry(): string {
		return self::manager()->displayCountry();
	}

	/** Returns the active available currency list. */
	public static function availableCurrencies(): array {
		return self::manager()->availableCurrencies();
	}

	/** Returns configured exchange-rate source identifiers. */
	public static function exchangeRateSources(): array {
		return self::manager()->exchangeRateSources();
	}

	/** Returns minor-unit precision for a currency code. */
	public static function minorUnits(string $currency): int {
		return self::manager()->minorUnits($currency);
	}

	/** Returns the cash rounding increment for a currency code. */
	public static function cashRoundingIncrement(string $currency): ?float {
		return self::manager()->cashRoundingIncrement($currency);
	}

	/** Registers one exchange-rate source callback. */
	public static function registerSource(string $source, callable $callback): void {
		self::manager()->registerSource($source, $callback);
	}

	/** Registers multiple exchange-rate source callbacks. */
	public static function registerSources(array $callbacks): void {
		self::manager()->registerSources($callbacks);
	}

	/** Loads exchange rates, optionally refreshing first or targeting one source. */
	public static function rates(bool $refresh=false, ?string $source=null): ExchangeRates {
		return self::manager()->rates($refresh, $source);
	}

	/** Refreshes exchange-rate data and returns the resulting rates. */
	public static function refresh(?string $source=null): ExchangeRates {
		return self::manager()->refresh($source);
	}

	/** Captures an exchange-rate snapshot for freshness checks and persistence. */
	public static function snapshot(bool $refresh=false, ?string $source=null): ExchangeSnapshot {
		return self::manager()->snapshot($refresh, $source);
	}

	/** Captures a snapshot and asserts it is fresh enough. */
	public static function snapshotOrFail(int $max_age_seconds, bool $refresh=false, ?string $source=null): ExchangeSnapshot {
		return self::manager()->snapshotOrFail($max_age_seconds, $refresh, $source);
	}

	/** Refreshes one exchange-rate source. */
	public static function refreshSource(string $source): bool {
		return self::manager()->refreshSource($source);
	}

	/** Reads one currency rate from the active exchange-rate collection. */
	public static function rate(string $currency, bool $refresh=false): ?float {
		return self::manager()->rate($currency, $refresh);
	}

	/** Checks whether a currency rate exists. */
	public static function hasRate(string $currency, bool $refresh=false): bool {
		return self::manager()->hasRate($currency, $refresh);
	}

	/** Builds an exchange quote when rates are available. */
	public static function quote(string $source_currency, string $target_currency, bool $refresh=false): ?ExchangeQuote {
		return self::manager()->quote($source_currency, $target_currency, $refresh);
	}

	/** Builds an exchange quote or throws when rates are unavailable. */
	public static function quoteOrFail(string $source_currency, string $target_currency, bool $refresh=false): ExchangeQuote {
		return self::manager()->quoteOrFail($source_currency, $target_currency, $refresh);
	}

	/** Returns a fresh exchange quote or throws when data is stale or unavailable. */
	public static function quoteOrFailFresh(
		string $source_currency,
		string $target_currency,
		int $max_age_seconds,
		bool $refresh=false
	): ExchangeQuote {
		return self::manager()->quoteOrFailFresh($source_currency, $target_currency, $max_age_seconds, $refresh);
	}

	/** Formats an amount using active currency display state. */
	public static function format(float|int|null $amount, bool $show_free=false, ?string $currency=null): string {
		return self::manager()->format($amount, $show_free, $currency);
	}

	/** Rounds an amount according to currency precision or cash increment. */
	public static function roundAmount(float|int|null $amount, string $currency, bool $cash=false): float {
		return self::manager()->roundAmount($amount, $currency, $cash);
	}

	/** Converts an amount between explicit currencies. */
	public static function convert(
		float|int|null $amount,
		string $source_currency,
		string $target_currency,
		bool $formatted=false,
		bool $show_free=true
	): string|float {
		return self::manager()->convert($amount, $source_currency, $target_currency, $formatted, $show_free);
	}

	/** Converts an amount from base currency to display currency. */
	public static function convertToDisplay(
		float|int|null $amount,
		bool $formatted=false,
		bool $show_free=true,
		?string $currency=null
	): string|float {
		return self::manager()->convertToDisplay($amount, $formatted, $show_free, $currency);
	}

	/** Converts an amount from an original currency to base currency. */
	public static function convertToBase(
		float|int|null $amount,
		string $original_currency,
		bool $formatted=false,
		bool $show_free=true
	): string|float {
		return self::manager()->convertToBase($amount, $original_currency, $formatted, $show_free);
	}

	/** Creates a Money value bound to the shared manager. */
	public static function money(float|int|null $amount, ?string $currency=null): Money {
		return self::manager()->money($amount, $currency);
	}

	/** Converts a Money value to another currency. */
	public static function convertMoney(Money $money, string $target_currency, bool $refresh=false): Money {
		return self::manager()->convertMoney($money, $target_currency, $refresh);
	}

	/** Converts Money using a snapshot that must satisfy a freshness limit. */
	public static function convertMoneyOrFailFresh(
		Money $money,
		string $target_currency,
		int $max_age_seconds,
		bool $refresh=false
	): Money {
		return self::manager()->convertMoneyOrFailFresh($money, $target_currency, $max_age_seconds, $refresh);
	}

	/** Converts a scalar amount using a fresh-enough snapshot. */
	public static function convertOrFailFresh(
		float|int|null $amount,
		string $source_currency,
		string $target_currency,
		int $max_age_seconds,
		bool $refresh=false
	): float {
		return self::manager()->convertOrFailFresh(
			$amount,
			$source_currency,
			$target_currency,
			$max_age_seconds,
			$refresh
		);
	}

	/** Captures a Money value for storage with base-currency conversion metadata. */
	public static function storeMoney(
		Money $money,
		?string $base_currency=null,
		bool $refresh=false
	): StoredMoney {
		return self::manager()->storeMoney($money, $base_currency, $refresh);
	}

	/** Captures a Money value for storage using a fresh-enough snapshot. */
	public static function storeMoneyOrFailFresh(
		Money $money,
		int $max_age_seconds,
		?string $base_currency=null,
		bool $refresh=false
	): StoredMoney {
		return self::manager()->storeMoneyOrFailFresh($money, $max_age_seconds, $base_currency, $refresh);
	}

	/** Splits an amount into equal Money parts while preserving rounding remainder. */
	public static function splitAmount(float|int|null $amount, string $currency, int $parts, bool $cash=false): array {
		return self::manager()->splitAmount($amount, $currency, $parts, $cash);
	}

	/** Allocates an amount by ratios and returns Money values keyed like the ratios. */
	public static function allocateAmount(float|int|null $amount, string $currency, array $ratios, bool $cash=false): array {
		return self::manager()->allocateAmount($amount, $currency, $ratios, $cash);
	}
}
