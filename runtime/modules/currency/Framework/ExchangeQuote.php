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
 * Immutable source-to-target exchange quote with rounding and freshness metadata.
 *
 * ExchangeQuote records one source/target pair, the multiplier selected from an
 * exchange snapshot, the precision used for target rounding, and the provider
 * timestamp used for freshness checks. It contains no manager reference and never
 * refreshes rates; callers that need newer data must obtain a new quote.
 */
final class ExchangeQuote implements \JsonSerializable {

	/** @var ?array<string, int|float|string> Cached scalar quote metadata payload. */
	private ?array $arrayPayload=null;

	/**
	 * Stores a quoted conversion pair and its provider metadata.
	 *
	 * The quote is immutable: source/target codes, rounding precision, multiplier,
	 * provider source, and timestamp all describe one conversion decision. Currency
	 * code normalization and rate validation happen before construction in
	 * ExchangeRates or ExchangeSnapshot.
	 *
	 * @param string $baseCurrency Provider base currency used to derive the pair.
	 * @param string $sourceCurrency Currency being converted from.
	 * @param string $targetCurrency Currency being converted to.
	 * @param int $sourceMinorUnits Decimal precision of the source currency.
	 * @param int $targetMinorUnits Decimal precision of the target currency.
	 * @param float $rate Multiplier applied to source amounts.
	 * @param string $source Provider or cache source name.
	 * @param int $time Unix timestamp representing rate freshness.
	 */
	public function __construct(
		private readonly string $baseCurrency,
		private readonly string $sourceCurrency,
		private readonly string $targetCurrency,
		private readonly int $sourceMinorUnits,
		private readonly int $targetMinorUnits,
		private readonly float $rate,
		private readonly string $source,
		private readonly int $time
	){}

	/**
	 * Returns the provider base currency used to derive this quote.
	 *
	 * @return string Uppercase ISO currency code.
	 */
	public function baseCurrency(): string {
		return $this->baseCurrency;
	}

	/**
	 * Returns the currency accepted by convert().
	 *
	 * @return string Uppercase ISO currency code.
	 */
	public function sourceCurrency(): string {
		return $this->sourceCurrency;
	}

	/**
	 * Returns the currency produced by convert().
	 *
	 * @return string Uppercase ISO currency code.
	 */
	public function targetCurrency(): string {
		return $this->targetCurrency;
	}

	/**
	 * Returns decimal precision metadata for the source currency.
	 *
	 * @return int Non-negative decimal places for source values.
	 */
	public function sourceMinorUnits(): int {
		return $this->sourceMinorUnits;
	}

	/**
	 * Returns decimal precision used when rounding converted amounts.
	 *
	 * @return int Non-negative decimal places for target values.
	 */
	public function targetMinorUnits(): int {
		return $this->targetMinorUnits;
	}

	/**
	 * Returns the source-to-target multiplier.
	 *
	 * @return float Multiplier applied to source amounts before target rounding.
	 */
	public function rate(): float {
		return $this->rate;
	}

	/**
	 * Returns the provider or cache source label attached to this quote.
	 *
	 * @return string Source identifier used in diagnostics and freshness exceptions.
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
	 * Returns the non-negative age of the quote.
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
	 * Checks whether the quote is older than a freshness threshold.
	 *
	 * Non-positive thresholds are treated as disabled freshness checks.
	 *
	 * @param int $maxAgeSeconds Maximum acceptable age in seconds.
	 * @return bool True when quote age exceeds the threshold.
	 */
	public function isStale(int $maxAgeSeconds): bool {
		return $maxAgeSeconds > 0 && $this->ageSeconds()>$maxAgeSeconds;
	}

	/**
	 * Ensures the quote satisfies a freshness threshold.
	 *
	 * Stale quotes raise an exception carrying source currency, target currency,
	 * provider source, timestamp, actual age, and maximum age details.
	 *
	 * @param int $maxAgeSeconds Maximum acceptable age in seconds.
	 * @return self This quote when fresh enough.
	 * @throws StaleExchangeRatesException When the quote timestamp is older than the threshold.
	 */
	public function assertFresh(int $maxAgeSeconds): self {
		if($this->isStale($maxAgeSeconds)){
			throw StaleExchangeRatesException::forQuote(
				$this->sourceCurrency,
				$this->targetCurrency,
				$this->source,
				$this->time,
				$this->ageSeconds(),
				$maxAgeSeconds
			);
		}
		return $this;
	}

	/**
	 * Converts a scalar amount using this quote multiplier.
	 *
	 * Null is treated as zero. Source amounts are first normalized to the source
	 * currency's minor unit before the quote multiplier is applied, matching the
	 * procedural currency conversion path and avoiding raw float drift from string
	 * inputs such as 10.005. The result is rounded to target currency precision
	 * using half-up numeric rounding before being returned. This method does not
	 * perform freshness checks; use convertOrFailFresh() when stale rates must be
	 * rejected before display or persistence.
	 *
	 * @param float|int|string|null $amount Amount in source currency major units.
	 * @return float Converted amount rounded to target minor units.
	 */
	public function convert(float|int|string|null $amount): float {
		$source_minor=\dataphyre\currency::amount_to_minor_units($amount, $this->sourceCurrency);
		$target_minor=$this->convertMinorUnits($source_minor);
		return (float)\dataphyre\currency::minor_units_to_amount($target_minor, $this->targetCurrency);
	}

	/**
	 * Converts source-currency minor units directly to target-currency minor units.
	 *
	 * @param int $minorAmount Amount in source-currency minor units.
	 * @return int Amount in target-currency minor units.
	 */
	public function convertMinorUnits(int $minorAmount): int {
		return \dataphyre\currency::convert_minor_units_with_rate(
			$minorAmount,
			$this->sourceCurrency,
			$this->targetCurrency,
			$this->rate
		);
	}

	/**
	 * Converts a scalar amount after enforcing quote freshness.
	 *
	 * The freshness guard runs before conversion, so stale quotes never produce a
	 * rounded amount for downstream storage or rendering.
	 *
	 * @param float|int|string|null $amount Amount in source currency major units.
	 * @param int $maxAgeSeconds Maximum acceptable quote age in seconds.
	 * @return float Converted amount rounded to target minor units.
	 * @throws StaleExchangeRatesException When the quote timestamp is older than the threshold.
	 */
	public function convertOrFailFresh(float|int|string|null $amount, int $maxAgeSeconds): float {
		return $this->assertFresh($maxAgeSeconds)->convert($amount);
	}

	/**
	 * Returns the target-to-source version of this quote.
	 *
	 * Precision metadata and currencies are swapped. A zero multiplier remains zero
	 * to avoid division warnings and preserve the invalid-rate state for callers
	 * inspecting the reversed quote.
	 *
	 * @return self Reversed quote carrying the same provider source and timestamp.
	 */
	public function inverse(): self {
		return new self(
			$this->baseCurrency,
			$this->targetCurrency,
			$this->sourceCurrency,
			$this->targetMinorUnits,
			$this->sourceMinorUnits,
			$this->rate==0.0 ? 0.0 : (1 / $this->rate),
			$this->source,
			$this->time
		);
	}

	/**
	 * Serializes quote metadata for diagnostics and JSON output.
	 *
	 * The array keeps only scalar quote metadata: currencies, minor-unit precision,
	 * multiplier, provider/source label, and timestamp. It intentionally omits any
	 * exchange table or manager state so the representation can be logged, cached,
	 * or embedded in storage records safely.
	 *
	 * @return array{base_currency:string, source_currency:string, target_currency:string, source_minor_units:int, target_minor_units:int, rate:float, source:string, time:int} Serialized quote metadata.
	 */
	public function toArray(): array {
		return $this->arrayPayload ??= [
			'base_currency'=>$this->baseCurrency,
			'source_currency'=>$this->sourceCurrency,
			'target_currency'=>$this->targetCurrency,
			'source_minor_units'=>$this->sourceMinorUnits,
			'target_minor_units'=>$this->targetMinorUnits,
			'rate'=>$this->rate,
			'source'=>$this->source,
			'time'=>$this->time,
		];
	}

	/**
	 * Serializes quote metadata for JSON output.
	 *
	 * JSON output matches toArray() so diagnostics and persisted exchange metadata
	 * share one field layout.
	 *
	 * @return array{base_currency:string, source_currency:string, target_currency:string, source_minor_units:int, target_minor_units:int, rate:float, source:string, time:int} Serialized quote metadata.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
