<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

use Dataphyre\Currency\Exceptions\CurrencyMismatchException;

/**
 * Immutable rounded money value with currency-aware formatting and conversion.
 *
 * Money stores an amount, ISO currency, manager, and context overrides; arithmetic,
 * conversion, storage, comparison, splitting, and allocation return deterministic
 * values that preserve rounding and exchange-rate policy.
 */
final class Money implements \JsonSerializable {

	private readonly float $amount;
	private readonly string $currency;
	private readonly CurrencyManager $manager;
	private readonly array $overrides;

	/**
	 * Creates an immutable money value rounded to the currency minor-unit policy.
	 *
	 * The constructor binds the value to a CurrencyManager and captures context
	 * overrides used for rounding, formatting, exchange lookup, and later derived
	 * values. The amount is rounded immediately so arithmetic, comparisons, JSON
	 * output, and storage projections all start from the same currency-aware value.
	 * Null amounts are treated as zero by the float cast before rounding.
	 *
	 * @param float|int|null $amount Numeric money amount rounded to the currency minor unit.
	 * @param string $currency ISO currency code normalized by the currency manager.
	 * @param ?CurrencyManager $manager Currency manager supplying rounding, formatting, exchange-rate, and storage policy.
	 * @param array<string,mixed> $overrides Runtime currency, rounding, locale, and exchange-rate overrides carried by derived values.
	 */
	public function __construct(
		float|int|null $amount,
		string $currency,
		?CurrencyManager $manager=null,
		array $overrides=[]
	){
		$this->manager=$manager ?? CurrencyManager::instance();
		$this->overrides=$overrides;
		$this->currency=mb_strtoupper(trim($currency));
		$this->amount=$this->manager->roundAmount((float)$amount, $this->currency, false, $this->overrides);
	}

	/**
	 * Returns the rounded amount stored on this value.
	 *
	 * @return float Rounded amount stored on this Money value.
	 */
	public function amount(): float {
		return $this->amount;
	}

	/**
	 * Returns the rounded amount for generic value-object consumers.
	 *
	 * @return float Alias of amount() for value-object consumers.
	 */
	public function value(): float {
		return $this->amount;
	}

	/**
	 * Returns the uppercase currency code attached to this value.
	 *
	 * @return string Uppercase ISO currency code stored on this Money value.
	 */
	public function currency(): string {
		return $this->currency;
	}

	/**
	 * Returns context overrides carried by this value.
	 *
	 * Overrides are preserved when derived Money, StoredMoney, and conversion
	 * values are created so formatting and exchange policy stay scoped to the
	 * original caller context. The returned array is a copy of the stored override
	 * map; changing it does not mutate this immutable value.
	 *
	 * @return array<string,mixed> Context override values carried by derived immutable Money instances.
	 */
	public function contextOverrides(): array {
		return $this->overrides;
	}

	/**
	 * Reports whether the rounded amount is exactly zero.
	 *
	 * @return bool True when the rounded amount is exactly zero.
	 */
	public function isZero(): bool {
		return $this->amount==0.0;
	}

	/**
	 * Returns the minor-unit precision configured for this value's currency.
	 *
	 * @return int Minor-unit precision configured for this currency.
	 */
	public function minorUnits(): int {
		return $this->manager->minorUnits($this->currency);
	}

	/**
	 * Returns the cash-rounding increment configured for this value's currency.
	 *
	 * A null value means the currency has no special cash-rounding rule in the active manager.
	 *
	 * @return ?float Cash-rounding increment for this currency, or null when none is configured.
	 */
	public function cashRoundingIncrement(): ?float {
		return $this->manager->cashRoundingIncrement($this->currency);
	}

	/**
	 * Formats the amount using current display currency and context policy.
	 *
	 * Formatting is delegated to the manager so locale, free-label, and display policy remain aligned with the active
	 * currency runtime.
	 *
	 * @param bool $showFree Whether zero/null display values may render as a free label.
	 * @return string Formatted money text produced by the currency manager.
	 */
	public function format(bool $showFree=false): string {
		return $this->manager->format($this->amount, $showFree, $this->currency, $this->overrides);
	}

	/**
	 * Returns a new Money value with a replacement amount.
	 *
	 * The replacement amount is rounded through the same manager, currency, and context overrides as the current value.
	 *
	 * @param float|int|null $amount Numeric money amount rounded to the currency minor unit.
	 * @return self New immutable Money value with the replacement amount.
	 */
	public function withAmount(float|int|null $amount): self {
		return new self($amount, $this->currency, $this->manager, $this->overrides);
	}

	/**
	 * Returns this amount rounded again with standard or cash rounding policy.
	 *
	 * This is useful after arithmetic when callers need to explicitly apply cash increments instead of ordinary minor-unit
	 * rounding.
	 *
	 * @param bool $cash Whether cash-rounding increments should be used.
	 * @return self New immutable Money value with the rounded amount.
	 */
	public function rounded(bool $cash=false): self {
		return new self(
			$this->manager->roundAmount($this->amount, $this->currency, $cash, $this->overrides),
			$this->currency,
			$this->manager,
			$this->overrides
		);
	}

	/**
	 * Converts this value through the manager's current exchange-rate policy.
	 *
	 * Exchange lookup, freshness policy, and target rounding are owned by
	 * CurrencyManager; this value contributes only its source amount, currency, and
	 * context overrides. Invalid, missing, or stale rate data is surfaced by the
	 * manager before a target Money value is returned.
	 *
	 * @param string $targetCurrency ISO currency code normalized by the currency manager.
	 * @return self New immutable Money value in the target currency.
	 */
	public function convertedTo(string $targetCurrency): self {
		$targetCurrency=mb_strtoupper(trim($targetCurrency));
		return $this->manager->convertMoney($this, $targetCurrency, false, $this->overrides);
	}

	/**
	 * Quotes this value against a supplied exchange snapshot.
	 *
	 * The snapshot provides deterministic rates and provenance, making the quote independent from later manager refreshes.
	 *
	 * @param ExchangeSnapshot $snapshot Exchange-rate snapshot used for deterministic quote/conversion.
	 * @param string $targetCurrency ISO currency code normalized by the currency manager.
	 * @return ExchangeQuote Quote carrying rate, pair, source, and timestamp metadata.
	 */
	public function quoteWith(ExchangeSnapshot $snapshot, string $targetCurrency): ExchangeQuote {
		return $snapshot->quoteOrFail($this->currency, $targetCurrency);
	}

	/**
	 * Converts this value with a supplied exchange snapshot.
	 *
	 * The snapshot fixes rate provenance for the conversion and returns a new Money value rounded to the target currency.
	 *
	 * @param ExchangeSnapshot $snapshot Exchange-rate snapshot used for deterministic quote/conversion.
	 * @param string $targetCurrency ISO currency code normalized by the currency manager.
	 * @return self New immutable Money value in the target currency.
	 */
	public function convertedWith(ExchangeSnapshot $snapshot, string $targetCurrency): self {
		return $snapshot->convertMoney($this, $targetCurrency);
	}

	/**
	 * Quotes this value with a supplied snapshot after enforcing freshness.
	 *
	 * Stale snapshots raise the snapshot's freshness exception before a quote is
	 * returned. The Money amount is not converted by this method; callers receive
	 * rate metadata for the source and target currency pair.
	 *
	 * @param ExchangeSnapshot $snapshot Exchange-rate snapshot used for deterministic quote/conversion.
	 * @param string $targetCurrency ISO currency code normalized by the currency manager.
	 * @param int $maxAgeSeconds Maximum accepted exchange snapshot age before freshness failure.
	 * @return ExchangeQuote Fresh quote carrying rate, pair, source, and timestamp metadata.
	 */
	public function quoteWithFresh(ExchangeSnapshot $snapshot, string $targetCurrency, int $maxAgeSeconds): ExchangeQuote {
		return $snapshot->quoteOrFailFresh($this->currency, $targetCurrency, $maxAgeSeconds);
	}

	/**
	 * Converts this value with a supplied snapshot after enforcing freshness.
	 *
	 * Stale snapshots fail before conversion so callers do not persist or display outdated converted amounts.
	 *
	 * @param ExchangeSnapshot $snapshot Exchange-rate snapshot used for deterministic quote/conversion.
	 * @param string $targetCurrency ISO currency code normalized by the currency manager.
	 * @param int $maxAgeSeconds Maximum accepted exchange snapshot age before freshness failure.
	 * @return self New immutable Money value in the target currency.
	 */
	public function convertedWithFresh(ExchangeSnapshot $snapshot, string $targetCurrency, int $maxAgeSeconds): self {
		return $snapshot->convertMoneyOrFailFresh($this, $targetCurrency, $maxAgeSeconds);
	}

	/**
	 * Builds a StoredMoney snapshot preserving display/base amount and quote metadata.
	 *
	 * The manager selects or refreshes rates according to the supplied flag and
	 * records the exchange provenance needed for durable storage. The returned
	 * StoredMoney value contains the original amount, base amount, exchange
	 * snapshot, and quote metadata while this Money value remains unchanged.
	 *
	 * @param ?string $baseCurrency ISO currency code normalized by the currency manager.
	 * @param bool $refresh Whether exchange rates may be refreshed before conversion/storage.
	 * @return StoredMoney Storage projection with original, base, snapshot, and quote values.
	 */
	public function stored(?string $baseCurrency=null, bool $refresh=false): StoredMoney {
		return $this->manager->storeMoney($this, $baseCurrency, $refresh, $this->overrides);
	}

	/**
	 * Builds a StoredMoney snapshot after enforcing exchange-rate freshness.
	 *
	 * Stale rates fail before the storage projection is created.
	 *
	 * @param int $maxAgeSeconds Maximum accepted exchange snapshot age before freshness failure.
	 * @param ?string $baseCurrency ISO currency code normalized by the currency manager.
	 * @param bool $refresh Whether exchange rates may be refreshed before conversion/storage.
	 * @return StoredMoney Storage projection with original, base, snapshot, and quote values.
	 */
	public function storedFresh(int $maxAgeSeconds, ?string $baseCurrency=null, bool $refresh=false): StoredMoney {
		return $this->manager->storeMoneyOrFailFresh($this, $maxAgeSeconds, $baseCurrency, $refresh, $this->overrides);
	}

	/**
	 * Builds a StoredMoney snapshot from a supplied exchange snapshot.
	 *
	 * Using an explicit snapshot pins storage provenance to the caller-selected rate table.
	 *
	 * @param ExchangeSnapshot $snapshot Exchange-rate snapshot used for deterministic quote/conversion.
	 * @param ?string $baseCurrency ISO currency code normalized by the currency manager.
	 * @return StoredMoney Storage projection with original, base, snapshot, and quote values.
	 */
	public function storedWith(ExchangeSnapshot $snapshot, ?string $baseCurrency=null): StoredMoney {
		return $snapshot->storeMoney($this, $baseCurrency);
	}

	/**
	 * Builds a StoredMoney snapshot from a supplied fresh exchange snapshot.
	 *
	 * Stale snapshots fail before the storage projection is created.
	 *
	 * @param ExchangeSnapshot $snapshot Exchange-rate snapshot used for deterministic quote/conversion.
	 * @param int $maxAgeSeconds Maximum accepted exchange snapshot age before freshness failure.
	 * @param ?string $baseCurrency ISO currency code normalized by the currency manager.
	 * @return StoredMoney Storage projection with original, base, snapshot, and quote values.
	 */
	public function storedWithFresh(ExchangeSnapshot $snapshot, int $maxAgeSeconds, ?string $baseCurrency=null): StoredMoney {
		return $snapshot->storeMoneyOrFailFresh($this, $maxAgeSeconds, $baseCurrency);
	}

	/**
	 * Converts this value into the active or supplied display currency.
	 *
	 * Null target currency uses the manager's display currency resolved with this value's context overrides.
	 *
	 * @param ?string $currency ISO currency code normalized by the currency manager.
	 * @return self New immutable Money value in the display currency.
	 */
	public function inDisplayCurrency(?string $currency=null): self {
		$targetCurrency=$currency ?? $this->manager->displayCurrency($this->overrides);
		return $this->convertedTo($targetCurrency);
	}

	/**
	 * Converts this value into the manager's base currency.
	 *
	 * Base-currency resolution honors the value's context overrides.
	 *
	 * @return self New immutable Money value in the base currency.
	 */
	public function inBaseCurrency(): self {
		return $this->convertedTo($this->manager->baseCurrency($this->overrides));
	}

	/**
	 * Formats the amount using current display currency and context policy.
	 *
	 * The amount is converted to the requested display currency before formatting.
	 *
	 * @param bool $showFree Whether zero/null display values may render as a free label.
	 * @param ?string $currency ISO currency code normalized by the currency manager.
	 * @return string Formatted display-currency text.
	 */
	public function display(bool $showFree=true, ?string $currency=null): string {
		return $this->inDisplayCurrency($currency)->format($showFree);
	}

	/**
	 * Quotes this value against the manager's current exchange-rate policy.
	 *
	 * The refresh flag allows callers to request a fresh rate table before the quote
	 * is resolved. Exchange source, timestamp, and rate-pair metadata are returned
	 * without converting this Money value.
	 *
	 * @param string $targetCurrency ISO currency code normalized by the currency manager.
	 * @param bool $refresh Whether exchange rates may be refreshed before conversion/storage.
	 * @return ExchangeQuote Quote carrying rate, pair, source, and timestamp metadata.
	 */
	public function quoteTo(string $targetCurrency, bool $refresh=false): ExchangeQuote {
		return $this->manager->quoteOrFail($this->currency, $targetCurrency, $refresh, $this->overrides);
	}

	/**
	 * Adds a same-currency amount and returns a rounded Money value.
	 *
	 * Money operands must use the same currency. Scalar operands may declare a
	 * currency guard through the optional argument; mismatches raise
	 * CurrencyMismatchException before any arithmetic is performed.
	 *
	 * @param Money|float|int $value Comparable Money or numeric value.
	 * @param ?string $currency ISO currency code normalized by the currency manager.
	 * @return self New immutable Money value with the summed amount.
	 */
	public function add(Money|float|int $value, ?string $currency=null): self {
		return $this->withAmount($this->amount + $this->normalizeComparableAmount($value, $currency, 'add'));
	}

	/**
	 * Subtracts a same-currency amount and returns a rounded Money value.
	 *
	 * Money operands must use the same currency. Scalar operands may declare a
	 * currency guard through the optional argument; mismatches raise
	 * CurrencyMismatchException before any arithmetic is performed.
	 *
	 * @param Money|float|int $value Comparable Money or numeric value.
	 * @param ?string $currency ISO currency code normalized by the currency manager.
	 * @return self New immutable Money value with the difference.
	 */
	public function subtract(Money|float|int $value, ?string $currency=null): self {
		return $this->withAmount($this->amount - $this->normalizeComparableAmount($value, $currency, 'subtract'));
	}

	/**
	 * Multiplies the amount and rounds the result through currency policy.
	 *
	 * Optional precision is applied before currency rounding, allowing callers to
	 * control intermediate arithmetic precision. The final amount is still rounded
	 * through the manager so minor-unit and optional cash rounding policy remain
	 * consistent.
	 *
	 * @param float|int $multiplier Factor used by money multiplication.
	 * @param ?int $precision Optional precision used before currency rounding.
	 * @param bool $cash Whether cash-rounding increments should be used.
	 * @return self New immutable Money value with the multiplied amount.
	 */
	public function multiply(float|int $multiplier, ?int $precision=null, bool $cash=false): self {
		$amount=$this->amount * (float)$multiplier;
		if($precision!==null){
			$amount=round($amount, $precision);
		}
		return new self(
			$this->manager->roundAmount($amount, $this->currency, $cash, $this->overrides),
			$this->currency,
			$this->manager,
			$this->overrides
		);
	}

	/**
	 * Divides the amount and rounds the result through currency policy.
	 *
	 * Division by zero throws before any rounded value is created. Optional
	 * precision is applied to the quotient before manager rounding, matching
	 * multiply().
	 *
	 * @param float|int $divisor Non-zero divisor used by money division.
	 * @param ?int $precision Optional precision used before currency rounding.
	 * @param bool $cash Whether cash-rounding increments should be used.
	 * @return self New immutable Money value with the divided amount.
	 */
	public function divide(float|int $divisor, ?int $precision=null, bool $cash=false): self {
		$divisor=(float)$divisor;
		if($divisor==0.0){
			throw new \DivisionByZeroError('Cannot divide money by zero.');
		}
		$amount=$this->amount / $divisor;
		if($precision!==null){
			$amount=round($amount, $precision);
		}
		return new self(
			$this->manager->roundAmount($amount, $this->currency, $cash, $this->overrides),
			$this->currency,
			$this->manager,
			$this->overrides
		);
	}

	/**
	 * Compares amounts after normalizing numeric values or Money instances to the current currency.
	 *
	 * Currency mismatches throw before comparison so ordering never crosses
	 * currencies implicitly. Scalar values without a currency guard are treated as
	 * already expressed in this Money value's currency.
	 *
	 * @param Money|float|int $value Comparable Money or numeric value.
	 * @param ?string $currency ISO currency code normalized by the currency manager.
	 * @return int -1, 0, or 1 after normalizing the comparable amount.
	 */
	public function compare(Money|float|int $value, ?string $currency=null): int {
		$otherAmount=$this->normalizeComparableAmount($value, $currency, 'compare');
		return $this->amount <=> $otherAmount;
	}

	/**
	 * Reports whether another same-currency amount equals this value.
	 *
	 * Currency mismatches throw before comparison.
	 *
	 * @param Money|float|int $value Comparable Money or numeric value.
	 * @param ?string $currency ISO currency code normalized by the currency manager.
	 * @return bool True when the normalized comparable amount equals this amount.
	 */
	public function equals(Money|float|int $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)===0;
	}

	/**
	 * Reports whether this value is greater than another same-currency amount.
	 *
	 * Currency mismatches throw before comparison.
	 *
	 * @param Money|float|int $value Comparable Money or numeric value.
	 * @param ?string $currency ISO currency code normalized by the currency manager.
	 * @return bool True when this amount is greater than the normalized comparable amount.
	 */
	public function greaterThan(Money|float|int $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)===1;
	}

	/**
	 * Reports whether this value is greater than or equal to another same-currency amount.
	 *
	 * Currency mismatches throw before comparison.
	 *
	 * @param Money|float|int $value Comparable Money or numeric value.
	 * @param ?string $currency ISO currency code normalized by the currency manager.
	 * @return bool True when this amount is greater than or equal to the normalized comparable amount.
	 */
	public function greaterThanOrEqual(Money|float|int $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)>=0;
	}

	/**
	 * Reports whether this value is less than another same-currency amount.
	 *
	 * Currency mismatches throw before comparison.
	 *
	 * @param Money|float|int $value Comparable Money or numeric value.
	 * @param ?string $currency ISO currency code normalized by the currency manager.
	 * @return bool True when this amount is less than the normalized comparable amount.
	 */
	public function lessThan(Money|float|int $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)===-1;
	}

	/**
	 * Reports whether this value is less than or equal to another same-currency amount.
	 *
	 * Currency mismatches throw before comparison.
	 *
	 * @param Money|float|int $value Comparable Money or numeric value.
	 * @param ?string $currency ISO currency code normalized by the currency manager.
	 * @return bool True when this amount is less than or equal to the normalized comparable amount.
	 */
	public function lessThanOrEqual(Money|float|int $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)<=0;
	}

	/**
	 * Splits or allocates the amount while preserving rounded totals.
	 *
	 * The manager validates the number of parts and distributes rounding residue so
	 * the returned values add back to the original amount after minor-unit or cash
	 * rounding.
	 *
	 * @param int $parts Number of equal money splits.
	 * @param bool $cash Whether cash-rounding increments should be used.
	 * @return list<Money> Rounded Money parts whose summed amount equals the original value.
	 */
	public function split(int $parts, bool $cash=false): array {
		return $this->manager->splitAmount($this->amount, $this->currency, $parts, $cash, $this->overrides);
	}

	/**
	 * Allocates the amount across ratios while preserving rounded totals.
	 *
	 * The manager validates ratio shape and distributes rounding residue so the
	 * returned values add back to the original amount after minor-unit or cash
	 * rounding.
	 *
	 * @param list<int|float> $ratios Allocation ratios that must preserve the total amount.
	 * @param bool $cash Whether cash-rounding increments should be used.
	 * @return list<Money> Rounded Money allocations distributed by ratio while preserving the original total.
	 */
	public function allocate(array $ratios, bool $cash=false): array {
		return $this->manager->allocateAmount($this->amount, $this->currency, $ratios, $cash, $this->overrides);
	}

	/**
	 * Serializes amount and currency for storage or APIs.
	 *
	 * Manager and context override state are intentionally omitted so the
	 * representation remains portable across storage, queues, and HTTP responses.
	 *
	 * @return array{amount:float,currency:string} Storage/API representation without manager or runtime override state.
	 */
	public function toArray(): array {
		return [
			'amount'=>$this->amount,
			'currency'=>$this->currency,
		];
	}

	/**
	 * Serializes amount and currency for JSON output.
	 *
	 * The JSON shape matches toArray() and excludes manager/runtime state.
	 *
	 * @return array{amount:float,currency:string} JSON representation matching the storage/API shape.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Normalizes an operand for same-currency comparisons and arithmetic guards.
	 *
	 * Money operands must use this value's currency. Scalar operands may declare an
	 * optional currency, which is normalized to uppercase before comparison. Currency
	 * mismatches throw a domain exception before any numeric comparison occurs.
	 *
	 * @param Money|float|int $value Operand being compared with this Money value.
	 * @param string|null $currency Optional currency for scalar operands.
	 * @param string $operation Operation name used in mismatch diagnostics.
	 * @return float Comparable amount in this value's currency.
	 *
	 * @throws CurrencyMismatchException When the operand currency differs.
	 */
	private function normalizeComparableAmount(Money|float|int $value, ?string $currency, string $operation): float {
		if($value instanceof self){
			if($value->currency()!==$this->currency){
				throw CurrencyMismatchException::forOperation($operation, $this->currency, $value->currency());
			}
			return $value->amount();
		}
		if($currency!==null){
			$currency=mb_strtoupper(trim($currency));
			if($currency!=='' && $currency!==$this->currency){
				throw CurrencyMismatchException::forOperation($operation, $this->currency, $currency);
			}
		}
		return (float)$value;
	}
}
