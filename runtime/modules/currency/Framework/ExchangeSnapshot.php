<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

use Dataphyre\Currency\Exceptions\StaleExchangeRatesException;

/**
 * Captures an immutable exchange-rate table with the currency context that used it.
 *
 * ExchangeSnapshot is the high-level conversion surface returned by ExchangeRates
 * when callers want Money, StoredMoney, quote freshness checks, and JSON-safe
 * diagnostics to share the same provider source, timestamp, manager, and context
 * overrides. The snapshot never refreshes rates or writes persistence itself; it
 * only binds an already-normalized rate table to the manager policy used for
 * derived value objects.
 */
final class ExchangeSnapshot implements \Countable, \JsonSerializable {

	/** @var array<string, mixed>|null */
	private ?array $serialized=null;

	/**
	 * Captures an exchange-rate table with manager and context overrides.
	 *
	 * The snapshot is immutable and represents rates at the timestamp carried by
	 * ExchangeRates. Overrides are preserved for Money and StoredMoney values created
	 * from this snapshot. The constructor trusts the ExchangeRates instance to have
	 * normalized provider data and minor-unit metadata.
	 *
	 * @param ExchangeRates $rates Exchange rates and metadata.
	 * @param CurrencyManager $manager Manager used for money value construction.
	 * @param array<string, mixed> $overrides Context overrides active when captured.
	 */
	public function __construct(
		private readonly ExchangeRates $rates,
		private readonly CurrencyManager $manager,
		private readonly array $overrides=[]
	){}

	/**
	 * Returns the underlying exchange-rate value object.
	 *
	 * @return ExchangeRates Captured exchange rates.
	 */
	public function rates(): ExchangeRates {
		return $this->rates;
	}

	/**
	 * Returns the base currency for the captured rate table.
	 *
	 * @return string Base ISO currency code.
	 */
	public function baseCurrency(): string {
		return $this->rates->baseCurrency();
	}

	/**
	 * Returns the provider source that produced the captured rates.
	 *
	 * @return string Exchange-rate provider source.
	 */
	public function source(): string {
		return $this->rates->source();
	}

	/**
	 * Returns the Unix timestamp associated with the captured rates.
	 *
	 * @return int Rate timestamp in seconds.
	 */
	public function time(): int {
		return $this->rates->time();
	}

	/**
	 * Returns the age of the captured rates in seconds.
	 *
	 * @return int Non-negative rate age.
	 */
	public function ageSeconds(): int {
		return $this->rates->ageSeconds();
	}

	/**
	 * Checks whether the snapshot is older than a freshness threshold.
	 *
	 * Non-positive thresholds never mark the snapshot stale.
	 *
	 * @param int $maxAgeSeconds Maximum acceptable age in seconds.
	 * @return bool True when rate age exceeds the threshold.
	 */
	public function isStale(int $maxAgeSeconds): bool {
		return $maxAgeSeconds > 0 && $this->ageSeconds()>$maxAgeSeconds;
	}

	/**
	 * Ensures the snapshot satisfies a freshness threshold.
	 *
	 * Stale snapshots raise StaleExchangeRatesException with source, timestamp, age,
	 * and maximum age details.
	 *
	 * @param int $maxAgeSeconds Maximum acceptable age in seconds.
	 * @return self This snapshot when fresh enough.
	 * @throws StaleExchangeRatesException When the captured rate timestamp is older than the threshold.
	 */
	public function assertFresh(int $maxAgeSeconds): self {
		if($this->isStale($maxAgeSeconds)){
			throw StaleExchangeRatesException::forSnapshot(
				$this->source(),
				$this->time(),
				$this->ageSeconds(),
				$maxAgeSeconds
			);
		}
		return $this;
	}

	/**
	 * Checks whether the snapshot contains a rate for a currency.
	 *
	 * @param string $currency ISO currency code.
	 * @return bool True when a rate exists for the currency.
	 */
	public function has(string $currency): bool {
		return $this->rates->has($currency);
	}

	/**
	 * Returns the exchange multiplier for one currency.
	 *
	 * @param string $currency ISO currency code.
	 * @return float|null Rate multiplier or null when missing.
	 */
	public function rate(string $currency): ?float {
		return $this->rates->rate($currency);
	}

	/**
	 * Returns currency codes present in the snapshot.
	 *
	 * @return array<int, string> ISO currency codes with available rates.
	 */
	public function currencies(): array {
		return $this->rates->currencies();
	}

	/**
	 * Returns decimal precision metadata for a currency.
	 *
	 * @param string $currency ISO currency code.
	 * @return int Non-negative minor-unit precision.
	 */
	public function minorUnits(string $currency): int {
		return $this->rates->minorUnits($currency);
	}

	/**
	 * Counts rates available in the snapshot.
	 *
	 * @return int Number of currencies with rates.
	 */
	public function count(): int {
		return $this->rates->count();
	}

	/**
	 * Returns a quote between two currencies when both rates are available.
	 *
	 * Currency normalization, same-currency handling, and unsupported pair behavior
	 * are delegated to the captured ExchangeRates table.
	 *
	 * @param string $sourceCurrency Source ISO currency code.
	 * @param string $targetCurrency Target ISO currency code.
	 * @return ExchangeQuote|null Quote or null when either rate is unavailable.
	 */
	public function quote(string $sourceCurrency, string $targetCurrency): ?ExchangeQuote {
		return $this->rates->quote($sourceCurrency, $targetCurrency);
	}

	/**
	 * Returns a quote between two currencies or raises when unavailable.
	 *
	 * The exception path carries normalized pair details and provider source from
	 * the underlying rate table.
	 *
	 * @param string $sourceCurrency Source ISO currency code.
	 * @param string $targetCurrency Target ISO currency code.
	 * @return ExchangeQuote Available quote.
	 * @throws Exceptions\UnknownExchangeRateException When either currency cannot be quoted by this rate table.
	 */
	public function quoteOrFail(string $sourceCurrency, string $targetCurrency): ExchangeQuote {
		return $this->rates->quoteOrFail($sourceCurrency, $targetCurrency);
	}

	/**
	 * Returns a quote only when it satisfies a freshness threshold.
	 *
	 * Missing rates are reported before freshness is checked. Once a quote exists,
	 * the quote timestamp is compared against the threshold and stale quotes raise a
	 * StaleExchangeRatesException.
	 *
	 * @param string $sourceCurrency Source ISO currency code.
	 * @param string $targetCurrency Target ISO currency code.
	 * @param int $maxAgeSeconds Maximum acceptable rate age.
	 * @return ExchangeQuote Fresh quote.
	 * @throws Exceptions\UnknownExchangeRateException When either currency cannot be quoted by this rate table.
	 * @throws StaleExchangeRatesException When the quote timestamp is older than the threshold.
	 */
	public function quoteOrFailFresh(string $sourceCurrency, string $targetCurrency, int $maxAgeSeconds): ExchangeQuote {
		return $this->quoteOrFail($sourceCurrency, $targetCurrency)->assertFresh($maxAgeSeconds);
	}

	/**
	 * Converts a scalar amount using a quote from this snapshot.
	 *
	 * Missing rates raise through quoteOrFail(). This method does not perform a
	 * freshness check; use convertOrFailFresh() when stale rates must be rejected
	 * before a rounded amount is returned.
	 *
	 * @param float|int|null $amount Amount in source currency major units.
	 * @param string $sourceCurrency Source ISO currency code.
	 * @param string $targetCurrency Target ISO currency code.
	 * @return float Converted rounded amount.
	 * @throws Exceptions\UnknownExchangeRateException When either currency cannot be quoted by this rate table.
	 */
	public function convert(float|int|null $amount, string $sourceCurrency, string $targetCurrency): float {
		return $this->quoteOrFail($sourceCurrency, $targetCurrency)->convert($amount);
	}

	/**
	 * Alias for convert() kept for fail-fast readability.
	 *
	 * @param float|int|null $amount Amount in source currency major units.
	 * @param string $sourceCurrency Source ISO currency code.
	 * @param string $targetCurrency Target ISO currency code.
	 * @return float Converted rounded amount.
	 * @throws Exceptions\UnknownExchangeRateException When either currency cannot be quoted by this rate table.
	 */
	public function convertOrFail(float|int|null $amount, string $sourceCurrency, string $targetCurrency): float {
		return $this->convert($amount, $sourceCurrency, $targetCurrency);
	}

	/**
	 * Converts a scalar amount only when rates satisfy a freshness threshold.
	 *
	 * The freshness guard runs through quoteOrFailFresh(), so unsupported pairs and
	 * stale quote timestamps fail before conversion.
	 *
	 * @param float|int|null $amount Amount in source currency major units.
	 * @param string $sourceCurrency Source ISO currency code.
	 * @param string $targetCurrency Target ISO currency code.
	 * @param int $maxAgeSeconds Maximum acceptable rate age.
	 * @return float Converted rounded amount using fresh rates.
	 * @throws Exceptions\UnknownExchangeRateException When either currency cannot be quoted by this rate table.
	 * @throws StaleExchangeRatesException When the quote timestamp is older than the threshold.
	 */
	public function convertOrFailFresh(
		float|int|null $amount,
		string $sourceCurrency,
		string $targetCurrency,
		int $maxAgeSeconds
	): float {
		return $this->quoteOrFailFresh($sourceCurrency, $targetCurrency, $maxAgeSeconds)->convert($amount);
	}

	/**
	 * Creates a Money value bound to this snapshot's manager and overrides.
	 *
	 * Missing currency defaults to the snapshot base currency. The Money constructor
	 * performs final rounding and currency normalization through the captured
	 * manager policy.
	 *
	 * @param float|int|null $amount Amount in major currency units.
	 * @param string|null $currency Optional ISO currency code.
	 * @return Money Immutable money value.
	 */
	public function money(float|int|null $amount, ?string $currency=null): Money {
		$currency=$currency===null ? $this->baseCurrency() : mb_strtoupper(trim($currency));
		return new Money((float)$amount, $currency, $this->manager, $this->overrides);
	}

	/**
	 * Converts a Money value using this snapshot's rates.
	 *
	 * Money-specific context overrides are merged over snapshot overrides for the
	 * returned value. The converted Money is bound to this snapshot's manager, not
	 * to any manager that may have created the source Money.
	 *
	 * @param Money $money Source money value.
	 * @param string $targetCurrency Target ISO currency code.
	 * @return Money Converted money value.
	 * @throws Exceptions\UnknownExchangeRateException When either currency cannot be quoted by this rate table.
	 */
	public function convertMoney(Money $money, string $targetCurrency): Money {
		$targetCurrency=mb_strtoupper(trim($targetCurrency));
		$overrides=array_replace($this->overrides, $money->contextOverrides());
		return new Money(
			$this->quoteOrFail($money->currency(), $targetCurrency)->convert($money->amount()),
			$targetCurrency,
			$this->manager,
			$overrides
		);
	}

	/**
	 * Converts a Money value only when the snapshot is fresh enough.
	 *
	 * Snapshot freshness is checked before conversion, so stale rate tables cannot
	 * produce a new Money value for display, storage, or indexing.
	 *
	 * @param Money $money Source money value.
	 * @param string $targetCurrency Target ISO currency code.
	 * @param int $maxAgeSeconds Maximum acceptable rate age.
	 * @return Money Converted money value using fresh rates.
	 * @throws Exceptions\UnknownExchangeRateException When either currency cannot be quoted by this rate table.
	 * @throws StaleExchangeRatesException When the snapshot timestamp is older than the threshold.
	 */
	public function convertMoneyOrFailFresh(Money $money, string $targetCurrency, int $maxAgeSeconds): Money {
		$this->assertFresh($maxAgeSeconds);
		return $this->convertMoney($money, $targetCurrency);
	}

	/**
	 * Builds a StoredMoney representation from a Money value.
	 *
	 * The base-currency value is produced from this snapshot's quote and attached with
	 * the original money, snapshot, and quote for persistence metadata. The method
	 * returns a value object only; database writes, column selection, and transaction
	 * handling remain caller responsibilities.
	 *
	 * @param Money $money Money value to persist.
	 * @param string|null $baseCurrency Optional storage base currency.
	 * @return StoredMoney Persistable money representation.
	 * @throws Exceptions\UnknownExchangeRateException When the money currency cannot be quoted against the storage base currency.
	 */
	public function storeMoney(Money $money, ?string $baseCurrency=null): StoredMoney {
		$baseCurrency=$baseCurrency===null ? $this->baseCurrency() : mb_strtoupper(trim($baseCurrency));
		$quote=$this->quoteOrFail($money->currency(), $baseCurrency);
		return new StoredMoney(
			$money,
			$this->convertMoney($money, $baseCurrency),
			$this,
			$quote
		);
	}

	/**
	 * Builds StoredMoney only when the snapshot satisfies a freshness threshold.
	 *
	 * The snapshot-level freshness check runs before quote lookup and base
	 * conversion, preventing stale rate tables from producing storage projections.
	 *
	 * @param Money $money Money value to persist.
	 * @param int $maxAgeSeconds Maximum acceptable rate age.
	 * @param string|null $baseCurrency Optional storage base currency.
	 * @return StoredMoney Persistable money representation using fresh rates.
	 * @throws Exceptions\UnknownExchangeRateException When the money currency cannot be quoted against the storage base currency.
	 * @throws StaleExchangeRatesException When the snapshot timestamp is older than the threshold.
	 */
	public function storeMoneyOrFailFresh(Money $money, int $maxAgeSeconds, ?string $baseCurrency=null): StoredMoney {
		$this->assertFresh($maxAgeSeconds);
		return $this->storeMoney($money, $baseCurrency);
	}

	/**
	 * Returns the effective currency state associated with this snapshot.
	 *
	 * @return CurrencyState State produced by the manager and context overrides.
	 */
	public function state(): CurrencyState {
		return $this->manager->state($this->overrides);
	}

	/**
	 * Returns context overrides captured with the snapshot.
	 *
	 * The returned array is a copy of the override map used for derived Money and
	 * CurrencyState values; mutating it does not alter this snapshot.
	 *
	 * @return array<string, mixed> Currency context overrides.
	 */
	public function contextOverrides(): array {
		return $this->overrides;
	}

	/**
	 * Serializes rates and captured context overrides.
	 *
	 * The serialized form combines the scalar ExchangeRates data with the captured
	 * context override map. CurrencyManager state is intentionally omitted so the
	 * array can be logged, cached, or encoded without embedding runtime services.
	 *
	 * @return array{base_currency:string, source:string, time:int, data:array<string, float>, minor_units:array<string, int>, context_overrides:array<string, mixed>} Serialized snapshot data for diagnostics and JSON output.
	 */
	public function toArray(): array {
		if($this->serialized!==null){
			return $this->serialized;
		}
		return $this->serialized=array_merge(
			$this->rates->toArray(),
			[
				'context_overrides'=>$this->overrides,
			]
		);
	}

	/**
	 * Serializes the captured exchange rates with their context overrides.
	 *
	 * JSON output matches toArray() so diagnostics and cache surfaces share one
	 * field layout.
	 *
	 * @return array{base_currency:string, source:string, time:int, data:array<string, float>, minor_units:array<string, int>, context_overrides:array<string, mixed>} Serialized snapshot data.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
