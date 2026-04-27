<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

final class Currency {

	public static function manager(): CurrencyManager {
		return CurrencyManager::instance();
	}

	public static function flush(): void {
		CurrencyManager::flush();
	}

	public static function state(): CurrencyState {
		return self::manager()->state();
	}

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

	public static function baseCurrency(): string {
		return self::manager()->baseCurrency();
	}

	public static function displayCurrency(): string {
		return self::manager()->displayCurrency();
	}

	public static function displayLanguage(): string {
		return self::manager()->displayLanguage();
	}

	public static function displayCountry(): string {
		return self::manager()->displayCountry();
	}

	public static function availableCurrencies(): array {
		return self::manager()->availableCurrencies();
	}

	public static function exchangeRateSources(): array {
		return self::manager()->exchangeRateSources();
	}

	public static function minorUnits(string $currency): int {
		return self::manager()->minorUnits($currency);
	}

	public static function cashRoundingIncrement(string $currency): ?float {
		return self::manager()->cashRoundingIncrement($currency);
	}

	public static function registerSource(string $source, callable $callback): void {
		self::manager()->registerSource($source, $callback);
	}

	public static function registerSources(array $callbacks): void {
		self::manager()->registerSources($callbacks);
	}

	public static function rates(bool $refresh=false, ?string $source=null): ExchangeRates {
		return self::manager()->rates($refresh, $source);
	}

	public static function refresh(?string $source=null): ExchangeRates {
		return self::manager()->refresh($source);
	}

	public static function snapshot(bool $refresh=false, ?string $source=null): ExchangeSnapshot {
		return self::manager()->snapshot($refresh, $source);
	}

	public static function snapshotOrFail(int $max_age_seconds, bool $refresh=false, ?string $source=null): ExchangeSnapshot {
		return self::manager()->snapshotOrFail($max_age_seconds, $refresh, $source);
	}

	public static function refreshSource(string $source): bool {
		return self::manager()->refreshSource($source);
	}

	public static function rate(string $currency, bool $refresh=false): ?float {
		return self::manager()->rate($currency, $refresh);
	}

	public static function hasRate(string $currency, bool $refresh=false): bool {
		return self::manager()->hasRate($currency, $refresh);
	}

	public static function quote(string $source_currency, string $target_currency, bool $refresh=false): ?ExchangeQuote {
		return self::manager()->quote($source_currency, $target_currency, $refresh);
	}

	public static function quoteOrFail(string $source_currency, string $target_currency, bool $refresh=false): ExchangeQuote {
		return self::manager()->quoteOrFail($source_currency, $target_currency, $refresh);
	}

	public static function quoteOrFailFresh(
		string $source_currency,
		string $target_currency,
		int $max_age_seconds,
		bool $refresh=false
	): ExchangeQuote {
		return self::manager()->quoteOrFailFresh($source_currency, $target_currency, $max_age_seconds, $refresh);
	}

	public static function format(float|int|null $amount, bool $show_free=false, ?string $currency=null): string {
		return self::manager()->format($amount, $show_free, $currency);
	}

	public static function roundAmount(float|int|null $amount, string $currency, bool $cash=false): float {
		return self::manager()->roundAmount($amount, $currency, $cash);
	}

	public static function convert(
		float|int|null $amount,
		string $source_currency,
		string $target_currency,
		bool $formatted=false,
		bool $show_free=true
	): string|float {
		return self::manager()->convert($amount, $source_currency, $target_currency, $formatted, $show_free);
	}

	public static function convertToDisplay(
		float|int|null $amount,
		bool $formatted=false,
		bool $show_free=true,
		?string $currency=null
	): string|float {
		return self::manager()->convertToDisplay($amount, $formatted, $show_free, $currency);
	}

	public static function convertToBase(
		float|int|null $amount,
		string $original_currency,
		bool $formatted=false,
		bool $show_free=true
	): string|float {
		return self::manager()->convertToBase($amount, $original_currency, $formatted, $show_free);
	}

	public static function money(float|int|null $amount, ?string $currency=null): Money {
		return self::manager()->money($amount, $currency);
	}

	public static function convertMoney(Money $money, string $target_currency, bool $refresh=false): Money {
		return self::manager()->convertMoney($money, $target_currency, $refresh);
	}

	public static function convertMoneyOrFailFresh(
		Money $money,
		string $target_currency,
		int $max_age_seconds,
		bool $refresh=false
	): Money {
		return self::manager()->convertMoneyOrFailFresh($money, $target_currency, $max_age_seconds, $refresh);
	}

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

	public static function storeMoney(
		Money $money,
		?string $base_currency=null,
		bool $refresh=false
	): StoredMoney {
		return self::manager()->storeMoney($money, $base_currency, $refresh);
	}

	public static function storeMoneyOrFailFresh(
		Money $money,
		int $max_age_seconds,
		?string $base_currency=null,
		bool $refresh=false
	): StoredMoney {
		return self::manager()->storeMoneyOrFailFresh($money, $max_age_seconds, $base_currency, $refresh);
	}

	public static function splitAmount(float|int|null $amount, string $currency, int $parts, bool $cash=false): array {
		return self::manager()->splitAmount($amount, $currency, $parts, $cash);
	}

	public static function allocateAmount(float|int|null $amount, string $currency, array $ratios, bool $cash=false): array {
		return self::manager()->allocateAmount($amount, $currency, $ratios, $cash);
	}
}
