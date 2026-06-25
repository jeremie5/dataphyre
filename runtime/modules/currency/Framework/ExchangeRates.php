<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

use Dataphyre\Currency\Exceptions\UnknownExchangeRateException;

/**
 * Immutable provider exchange-rate table used to quote and convert currencies.
 *
 * ExchangeRates owns the normalized rate map, provider metadata, freshness
 * timestamp, and minor-unit precision used by ExchangeQuote and ExchangeSnapshot.
 * It performs no I/O; callers can safely serialize it for caches or bind it to a
 * CurrencyManager when they need Money-aware workflows.
 */
final class ExchangeRates implements \Countable, \JsonSerializable {

	/**
	 * Stores a normalized provider rate table.
	 *
	 * Currency codes are expected to already be uppercase ISO-like keys. The class
	 * treats the rate map as immutable and uses minor-unit metadata only for quote
	 * rounding decisions.
	 *
	 * @param string $baseCurrency Currency that provider rates are relative to.
	 * @param string $source Provider or cache source name for diagnostics.
	 * @param int $time Unix timestamp representing rate freshness.
	 * @param array<string, float|int|string> $rates Provider multipliers keyed by currency code.
	 * @param array<string, int> $minorUnits Decimal precision by currency code.
	 */
	public function __construct(
		private readonly string $baseCurrency,
		private readonly string $source,
		private readonly int $time,
		private readonly array $rates,
		private readonly array $minorUnits=[]
	){}

	/** @var array{base_currency:string, source:string, time:int, data:array<string, float>, minor_units:array<string, int>}|null */
	private ?array $arrayPayload=null;

	/**
	 * Builds a rate table from provider or cache data.
	 *
	 * The input accepts source, time, and data keys. Missing or invalid timestamps
	 * are replaced with the current time, non-numeric rates are dropped, currency
	 * keys are uppercased, and the optional base currency defaults to USD. No
	 * network or cache I/O happens here; callers supply the already-read data.
	 *
	 * @param array{source?:mixed, time?:mixed, data?:mixed} $exchangeRateData Provider or cache exchange-rate data.
	 * @param string|null $baseCurrency Base ISO currency code to attach to the rate table.
	 * @param array<string, int|numeric-string> $minorUnits Decimal precision overrides by currency code.
	 * @return self Normalized immutable rate table.
	 */
	public static function fromArray(array $exchangeRateData, ?string $baseCurrency=null, array $minorUnits=[]): self {
		return new self(
			mb_strtoupper(trim((string)($baseCurrency ?? 'USD'))),
			(string)($exchangeRateData['source'] ?? ''),
			self::normalizeTimestamp($exchangeRateData['time'] ?? null),
			self::normalizeRates($exchangeRateData['data'] ?? []),
			self::normalizeMinorUnits($minorUnits)
		);
	}

	/**
	 * Returns the provider base currency for this table.
	 *
	 * @return string Uppercase ISO currency code.
	 */
	public function baseCurrency(): string {
		return $this->baseCurrency;
	}

	/**
	 * Returns the provider or cache source label attached to the rates.
	 *
	 * @return string Source identifier used in diagnostics and exceptions.
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Returns the Unix timestamp used for freshness checks.
	 *
	 * @return int Rate timestamp in seconds.
	 */
	public function time(): int {
		return $this->time;
	}

	/**
	 * Returns the non-negative age of the rate table.
	 *
	 * Future timestamps are clamped to zero age so freshness checks remain stable
	 * when provider clocks drift slightly ahead of the application server.
	 *
	 * @return int Age in seconds.
	 */
	public function ageSeconds(): int {
		return max(0, time()-$this->time);
	}

	/**
	 * Checks whether a currency has a usable provider multiplier.
	 *
	 * The lookup normalizes whitespace and case before checking the rate map.
	 *
	 * @param string $currency Currency code to look up.
	 * @return bool True when the normalized currency exists in the rate map.
	 */
	public function has(string $currency): bool {
		return array_key_exists(mb_strtoupper(trim($currency)), $this->rates);
	}

	/**
	 * Returns the provider multiplier for a currency.
	 *
	 * Missing currencies return null so callers can distinguish absence from a
	 * numeric zero supplied by provider rate data.
	 *
	 * @param string $currency Currency code to look up.
	 * @return float|null Multiplier relative to the provider base currency, or null when absent.
	 */
	public function rate(string $currency): ?float {
		$currency=mb_strtoupper(trim($currency));
		return isset($this->rates[$currency]) ? (float)$this->rates[$currency] : null;
	}

	/**
	 * Returns all normalized provider multipliers.
	 *
	 * @return array<string, float> Rate map keyed by uppercase currency code.
	 */
	public function rates(): array {
		return $this->rates;
	}

	/**
	 * Returns the currencies available for quote generation.
	 *
	 * @return array<int, string> Uppercase currency codes in rate-map order.
	 */
	public function currencies(): array {
		return array_keys($this->rates);
	}

	/**
	 * Counts currencies with available provider multipliers.
	 *
	 * @return int Number of entries in the normalized rate map.
	 */
	public function count(): int {
		return count($this->rates);
	}

	/**
	 * Returns decimal precision metadata for a currency.
	 *
	 * Unknown currencies default to two minor units, matching common fiat currency
	 * behavior and keeping conversions deterministic when metadata is incomplete.
	 *
	 * @param string $currency Currency code whose precision should be read.
	 * @return int Non-negative decimal places used when rounding converted amounts.
	 */
	public function minorUnits(string $currency): int {
		$currency=mb_strtoupper(trim($currency));
		return $this->minorUnits[$currency] ?? 2;
	}

	/**
	 * Wraps this rate table in a conversion snapshot.
	 *
	 * A snapshot binds the immutable rates to a CurrencyManager and context overrides
	 * so Money and StoredMoney values created later inherit the same currency state.
	 *
	 * @param CurrencyManager|null $manager Manager used to create currency value objects.
	 * @param array<string, mixed> $overrides Context overrides captured with the snapshot.
	 * @return ExchangeSnapshot Snapshot bound to this rate table.
	 */
	public function snapshot(?CurrencyManager $manager=null, array $overrides=[]): ExchangeSnapshot {
		return new ExchangeSnapshot($this, $manager ?? CurrencyManager::instance(), $overrides);
	}

	/**
	 * Wraps this table in a snapshot after enforcing freshness.
	 *
	 * @param int $maxAgeSeconds Maximum acceptable table age in seconds.
	 * @param CurrencyManager|null $manager Manager used to create currency value objects.
	 * @param array<string, mixed> $overrides Context overrides captured with the snapshot.
	 * @return ExchangeSnapshot Fresh snapshot bound to this rate table.
	 * @throws Exceptions\StaleExchangeRatesException When the table timestamp is older than the threshold.
	 */
	public function snapshotOrFail(int $maxAgeSeconds, ?CurrencyManager $manager=null, array $overrides=[]): ExchangeSnapshot {
		return $this->snapshot($manager, $overrides)->assertFresh($maxAgeSeconds);
	}

	/**
	 * Converts a scalar amount between two currencies.
	 *
	 * The conversion uses quoteOrFail(), so invalid or unsupported currency pairs are
	 * reported as exceptions instead of silently returning a fallback amount.
	 *
	 * @param float|int|null $amount Amount in source currency major units; null is treated as zero.
	 * @param string $sourceCurrency Source currency code.
	 * @param string $targetCurrency Target currency code.
	 * @return float Converted amount rounded to the target currency minor units.
	 * @throws UnknownExchangeRateException When either side of the pair cannot be quoted.
	 */
	public function convert(float|int|null $amount, string $sourceCurrency, string $targetCurrency): float {
		return $this->quoteOrFail($sourceCurrency, $targetCurrency)->convert($amount);
	}

	/**
	 * Creates a quote between two currencies when the rate table supports both sides.
	 *
	 * Currency codes are normalized before lookup. Same-currency pairs return a rate
	 * of 1.0, empty codes return null, and cross-currency rates are calculated as
	 * target multiplier divided by source multiplier. Missing target rates, missing
	 * source rates, and non-positive source multipliers return null so callers can
	 * choose between nullable lookup and quoteOrFail().
	 *
	 * @param string $sourceCurrency Source currency code.
	 * @param string $targetCurrency Target currency code.
	 * @return ExchangeQuote|null Quote metadata and multiplier, or null when the pair is unavailable.
	 */
	public function quote(string $sourceCurrency, string $targetCurrency): ?ExchangeQuote {
		$sourceCurrency=mb_strtoupper(trim($sourceCurrency));
		$targetCurrency=mb_strtoupper(trim($targetCurrency));
		if($sourceCurrency==='' || $targetCurrency===''){
			return null;
		}
		if($sourceCurrency===$targetCurrency){
			return new ExchangeQuote(
				$this->baseCurrency,
				$sourceCurrency,
				$targetCurrency,
				$this->minorUnits($sourceCurrency),
				$this->minorUnits($targetCurrency),
				1.0,
				$this->source,
				$this->time
			);
		}
		$sourceMultiplier=$this->rate($sourceCurrency);
		$targetMultiplier=$this->rate($targetCurrency);
		if($sourceMultiplier===null || $targetMultiplier===null || $sourceMultiplier<=0){
			return null;
		}
		return new ExchangeQuote(
			$this->baseCurrency,
			$sourceCurrency,
			$targetCurrency,
			$this->minorUnits($sourceCurrency),
			$this->minorUnits($targetCurrency),
			$targetMultiplier/$sourceMultiplier,
			$this->source,
			$this->time
		);
	}

	/**
	 * Creates a quote or raises a currency-specific lookup exception.
	 *
	 * Exceptions include the normalized source/target pair and provider source so
	 * upstream logs can identify which rate table could not quote the request.
	 *
	 * @param string $sourceCurrency Source currency code.
	 * @param string $targetCurrency Target currency code.
	 * @return ExchangeQuote Quote metadata and multiplier.
	 * @throws UnknownExchangeRateException When either side of the pair cannot be quoted.
	 */
	public function quoteOrFail(string $sourceCurrency, string $targetCurrency): ExchangeQuote {
		$quote=$this->quote($sourceCurrency, $targetCurrency);
		if($quote===null){
			throw UnknownExchangeRateException::forPair(
				mb_strtoupper(trim($sourceCurrency)),
				mb_strtoupper(trim($targetCurrency)),
				$this->source
			);
		}
		return $quote;
	}

	/**
	 * Explicit fail-fast alias for scalar conversion.
	 *
	 * This method keeps call sites readable when missing rates should be treated as
	 * exceptional rather than nullable quote results.
	 *
	 * @param float|int|null $amount Amount in source currency major units; null is treated as zero.
	 * @param string $sourceCurrency Source currency code.
	 * @param string $targetCurrency Target currency code.
	 * @return float Converted amount rounded to the target currency minor units.
	 * @throws UnknownExchangeRateException When either side of the pair cannot be quoted.
	 */
	public function convertOrFail(float|int|null $amount, string $sourceCurrency, string $targetCurrency): float {
		return $this->quoteOrFail($sourceCurrency, $targetCurrency)->convert($amount);
	}

	/**
	 * Serializes the rate table for cache storage, diagnostics, and JSON output.
	 *
	 * The array contains only scalar provider metadata, normalized rates, and
	 * minor-unit precision. It intentionally omits CurrencyManager state so cached
	 * rate tables can be rebound to a manager later through snapshot().
	 *
	 * @return array{base_currency:string, source:string, time:int, data:array<string, float>, minor_units:array<string, int>} Serialized rate-table data.
	 */
	public function toArray(): array {
		return $this->arrayPayload ??= [
			'base_currency'=>$this->baseCurrency,
			'source'=>$this->source,
			'time'=>$this->time,
			'data'=>$this->rates,
			'minor_units'=>$this->minorUnits,
		];
	}

	/**
	 * Serializes the rate table for JSON output.
	 *
	 * JSON output matches toArray() so cache, diagnostics, and HTTP surfaces share
	 * one rate-table shape.
	 *
	 * @return array{base_currency:string, source:string, time:int, data:array<string, float>, minor_units:array<string, int>} Serialized rate-table data.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Normalizes raw provider multipliers into an uppercase numeric rate map.
	 *
	 * Non-array input, non-string currency keys, and non-numeric values are ignored.
	 * Numeric zero is preserved so later quote generation can reject it as an
	 * unusable source multiplier instead of hiding malformed provider data.
	 *
	 * @param mixed $rates Candidate provider rate map.
	 * @return array<string, float> Numeric multipliers keyed by uppercase currency code.
	 */
	private static function normalizeRates(mixed $rates): array {
		if(!is_array($rates)){
			return [];
		}
		$normalized=[];
		foreach($rates as $currency=>$rate){
			if(!is_string($currency) || !is_numeric($rate)){
				continue;
			}
			$currency=mb_strtoupper(trim($currency));
			$normalized[$currency]=(float)$rate;
		}
		return $normalized;
	}

	/**
	 * Normalizes provider timestamp formats into a positive Unix timestamp.
	 *
	 * Integers, numeric strings, and strtotime-compatible strings are accepted. Any
	 * missing or invalid value falls back to the current application time.
	 *
	 * @param mixed $timestamp Provider timestamp value.
	 * @return int Positive Unix timestamp.
	 */
	private static function normalizeTimestamp(mixed $timestamp): int {
		if(is_int($timestamp)){
			return $timestamp>0 ? $timestamp : time();
		}
		if(is_numeric($timestamp)){
			$timestamp=(int)$timestamp;
			return $timestamp>0 ? $timestamp : time();
		}
		if(is_string($timestamp) && trim($timestamp)!==''){
			$parsed=strtotime($timestamp);
			if($parsed!==false){
				return $parsed;
			}
		}
		return time();
	}

	/**
	 * Normalizes decimal precision metadata by currency code.
	 *
	 * Non-string currency keys and non-numeric precision values are discarded; valid
	 * precision values are clamped to zero or greater. Unknown currencies later
	 * fall back to two minor units in minorUnits().
	 *
	 * @param array<mixed, mixed> $minorUnits Raw minor-unit metadata.
	 * @return array<string, int> Non-negative precision values keyed by uppercase currency code.
	 */
	private static function normalizeMinorUnits(array $minorUnits): array {
		$normalized=[];
		foreach($minorUnits as $currency=>$precision){
			if(!is_string($currency) || !is_numeric($precision)){
				continue;
			}
			$normalized[mb_strtoupper(trim($currency))]=max(0, (int)$precision);
		}
		return $normalized;
	}

}
