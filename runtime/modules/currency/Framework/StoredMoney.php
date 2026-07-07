<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

/**
 * Immutable money value prepared for durable storage with exchange provenance.
 *
 * StoredMoney keeps the user's original Money value, the converted base Money
 * value used for reporting or indexing, the exchange snapshot that supplied
 * rates, and the quote applied between the two currencies. Persisting all four
 * pieces keeps historical records auditable even when exchange rates change
 * later.
 *
 * Amount precision, currency normalization, rate freshness, and missing-rate
 * failures are owned by Money, ExchangeSnapshot, and ExchangeQuote before this
 * object is created. This wrapper exposes a flat storage-friendly projection and
 * keeps the exchange fields tied to the conversion used to create the base
 * amount.
 */
final class StoredMoney implements \JsonSerializable {

	/** @var ?array<string, float|int|string> Cached flat storage projection for default prefixes. */
	private ?array $defaultArrayPayload=null;

	/**
	 * Captures original money, converted base money, and exchange provenance.
	 *
	 * The constructor does not recompute or revalidate the conversion. Callers must
	 * pass a base value and quote produced from the same snapshot path when they need
	 * the flattened projection to be audit-consistent.
	 *
	 * @param Money $original Money value as supplied by the user or source system.
	 * @param Money $base Money value converted into the application's base/reporting currency.
	 * @param ExchangeSnapshot $snapshot Rate snapshot used for the conversion.
	 * @param ExchangeQuote $quote Specific quote selected from the snapshot.
	 */
	public function __construct(
		private readonly Money $original,
		private readonly Money $base,
		private readonly ExchangeSnapshot $snapshot,
		private readonly ExchangeQuote $quote
	){}

	/**
	 * Returns the original money value before conversion.
	 *
	 * This value preserves the user-facing amount and currency that entered the
	 * storage pipeline.
	 *
	 * @return Money Source amount and currency.
	 */
	public function original(): Money {
		return $this->original;
	}

	/**
	 * Returns the converted base money value.
	 *
	 * This value is intended for normalized storage, reporting, indexing, and
	 * comparisons that require a shared currency.
	 *
	 * @return Money Amount and currency used for normalized storage, reporting, or indexing.
	 */
	public function base(): Money {
		return $this->base;
	}

	/**
	 * Returns the exchange snapshot that supplied the conversion rate.
	 *
	 * The snapshot exposes the rate table provenance and timestamp used to derive
	 * quote().
	 *
	 * @return ExchangeSnapshot Snapshot source, base currency, time, and rate table.
	 */
	public function snapshot(): ExchangeSnapshot {
		return $this->snapshot;
	}

	/**
	 * Returns the specific exchange quote applied to the original value.
	 *
	 * The quote records the source/target pair, multiplier, precision, provider
	 * source, and timestamp used for base().
	 *
	 * @return ExchangeQuote Quote containing the conversion rate used for base().
	 */
	public function quote(): ExchangeQuote {
		return $this->quote;
	}

	/**
	 * Returns the original numeric amount.
	 *
	 * @return float Amount from original().
	 */
	public function originalAmount(): float {
		return $this->original->amount();
	}

	/**
	 * Returns the original amount as a fixed decimal string.
	 *
	 * @return string Decimal string from original().
	 */
	public function originalDecimalAmount(): string {
		return $this->original->decimalAmount();
	}

	/**
	 * Returns the original amount in integer minor units.
	 *
	 * @return int Minor-unit amount from original().
	 */
	public function originalMinorAmount(): int {
		return $this->original->minorAmount();
	}

	/**
	 * Returns the original currency code.
	 *
	 * @return string Currency code from original().
	 */
	public function originalCurrency(): string {
		return $this->original->currency();
	}

	/**
	 * Returns the converted base amount.
	 *
	 * @return float Amount from base().
	 */
	public function baseAmount(): float {
		return $this->base->amount();
	}

	/**
	 * Returns the converted base amount as a fixed decimal string.
	 *
	 * @return string Decimal string from base().
	 */
	public function baseDecimalAmount(): string {
		return $this->base->decimalAmount();
	}

	/**
	 * Returns the converted base amount in integer minor units.
	 *
	 * @return int Minor-unit amount from base().
	 */
	public function baseMinorAmount(): int {
		return $this->base->minorAmount();
	}

	/**
	 * Returns the converted base currency code.
	 *
	 * @return string Currency code from base().
	 */
	public function baseCurrency(): string {
		return $this->base->currency();
	}

	/**
	 * Returns the exchange rate used for the conversion.
	 *
	 * @return float Quote rate applied between original() and base().
	 */
	public function exchangeRate(): float {
		return $this->quote->rate();
	}

	/**
	 * Returns the provider/source name for the exchange snapshot.
	 *
	 * @return string Snapshot source identifier.
	 */
	public function exchangeSource(): string {
		return $this->snapshot->source();
	}

	/**
	 * Returns the timestamp of the exchange snapshot.
	 *
	 * @return int Unix timestamp for the rate snapshot.
	 */
	public function exchangeTime(): int {
		return $this->snapshot->time();
	}

	/**
	 * Returns the base currency declared by the exchange snapshot.
	 *
	 * @return string Snapshot base currency code.
	 */
	public function exchangeSnapshotBaseCurrency(): string {
		return $this->snapshot->baseCurrency();
	}

	/**
	 * Flattens the stored money value into columns for persistence and diagnostics.
	 *
	 * Prefixes let callers align the same structure with different table column
	 * names while preserving the semantic groups: original amount/currency, base
	 * amount/currency, and exchange provenance. Prefixes are concatenated as-is;
	 * callers that map this array into SQL columns must supply trusted column-name
	 * fragments.
	 *
	 * Monetary amounts are emitted only as integer minor units. Decimal or float
	 * major-unit amounts belong at display/API edges, not durable storage.
	 *
	 * @param string $originalPrefix Prefix for original amount and currency keys.
	 * @param string $basePrefix Prefix for base amount and currency keys.
	 * @param string $exchangePrefix Prefix for exchange provenance keys.
	 * @return array<string, float|int|string> Flat storage projection.
	 */
	public function toArray(
		string $originalPrefix='original_',
		string $basePrefix='base_',
		string $exchangePrefix='exchange_'
	): array {
		if($originalPrefix==='original_' && $basePrefix==='base_' && $exchangePrefix==='exchange_'){
			return $this->defaultArrayPayload ??= [
				'original_amount_minor'=>$this->original->minorAmount(),
				'original_currency'=>$this->original->currency(),
				'base_amount_minor'=>$this->base->minorAmount(),
				'base_currency'=>$this->base->currency(),
				'exchange_rate'=>$this->quote->rate(),
				'exchange_source'=>$this->snapshot->source(),
				'exchange_time'=>$this->snapshot->time(),
				'exchange_base_currency'=>$this->snapshot->baseCurrency(),
			];
		}
		return [
			$originalPrefix.'amount_minor'=>$this->original->minorAmount(),
			$originalPrefix.'currency'=>$this->original->currency(),
			$basePrefix.'amount_minor'=>$this->base->minorAmount(),
			$basePrefix.'currency'=>$this->base->currency(),
			$exchangePrefix.'rate'=>$this->quote->rate(),
			$exchangePrefix.'source'=>$this->snapshot->source(),
			$exchangePrefix.'time'=>$this->snapshot->time(),
			$exchangePrefix.'base_currency'=>$this->snapshot->baseCurrency(),
		];
	}

	/**
	 * Serializes the storage projection for json_encode().
	 *
	 * JSON serialization uses the default prefixes so API, queue, and diagnostic
	 * consumers receive the same flat field names as ordinary toArray() callers.
	 *
	 * @return array<string, float|int|string> Flat storage projection with default prefixes.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
