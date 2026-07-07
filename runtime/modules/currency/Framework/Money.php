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
 * Money stores integer minor units, ISO currency, manager, and context
 * overrides; arithmetic, conversion, storage, comparison, splitting, and
 * allocation return deterministic values that preserve rounding and
 * exchange-rate policy.
 */
final class Money implements \JsonSerializable {

	private readonly int $minorAmount;
	private readonly string $currency;
	private readonly CurrencyManager $manager;
	private readonly array $overrides;

	public function __construct(
		float|int|string|null $amount,
		string $currency,
		?CurrencyManager $manager=null,
		array $overrides=[],
		bool $amountIsMinor=false
	){
		$this->manager=$manager ?? CurrencyManager::instance();
		$this->overrides=$overrides;
		$this->currency=mb_strtoupper(trim($currency));
		$this->minorAmount=$amountIsMinor ? (int)$amount : $this->manager->amountToMinorUnits($amount, $this->currency, false, $this->overrides);
	}

	public static function fromMinor(
		int $minorAmount,
		string $currency,
		?CurrencyManager $manager=null,
		array $overrides=[]
	): self {
		return new self($minorAmount, $currency, $manager, $overrides, true);
	}

	public function amount(): float {
		return (float)$this->decimalAmount();
	}

	public function minorAmount(): int {
		return $this->minorAmount;
	}

	public function decimalAmount(): string {
		return $this->manager->minorUnitsToDecimal($this->minorAmount, $this->currency, $this->overrides);
	}

	public function value(): float {
		return $this->amount();
	}

	public function currency(): string {
		return $this->currency;
	}

	public function contextOverrides(): array {
		return $this->overrides;
	}

	public function isZero(): bool {
		return $this->minorAmount===0;
	}

	public function minorUnits(): int {
		return $this->manager->minorUnits($this->currency);
	}

	public function cashRoundingIncrement(): ?float {
		return $this->manager->cashRoundingIncrement($this->currency);
	}

	public function format(bool $showFree=false): string {
		return $this->manager->format($this->decimalAmount(), $showFree, $this->currency, $this->overrides);
	}

	public function withAmount(float|int|string|null $amount): self {
		return new self($amount, $this->currency, $this->manager, $this->overrides);
	}

	public function rounded(bool $cash=false): self {
		return new self(
			$this->manager->roundAmount($this->decimalAmount(), $this->currency, $cash, $this->overrides),
			$this->currency,
			$this->manager,
			$this->overrides
		);
	}

	public function convertedTo(string $targetCurrency): self {
		$targetCurrency=mb_strtoupper(trim($targetCurrency));
		return $this->manager->convertMoney($this, $targetCurrency, false, $this->overrides);
	}

	public function quoteWith(ExchangeSnapshot $snapshot, string $targetCurrency): ExchangeQuote {
		return $snapshot->quoteOrFail($this->currency, $targetCurrency);
	}

	public function convertedWith(ExchangeSnapshot $snapshot, string $targetCurrency): self {
		return $snapshot->convertMoney($this, $targetCurrency);
	}

	public function quoteWithFresh(ExchangeSnapshot $snapshot, string $targetCurrency, int $maxAgeSeconds): ExchangeQuote {
		return $snapshot->quoteOrFailFresh($this->currency, $targetCurrency, $maxAgeSeconds);
	}

	public function convertedWithFresh(ExchangeSnapshot $snapshot, string $targetCurrency, int $maxAgeSeconds): self {
		return $snapshot->convertMoneyOrFailFresh($this, $targetCurrency, $maxAgeSeconds);
	}

	public function stored(?string $baseCurrency=null, bool $refresh=false): StoredMoney {
		return $this->manager->storeMoney($this, $baseCurrency, $refresh, $this->overrides);
	}

	public function storedFresh(int $maxAgeSeconds, ?string $baseCurrency=null, bool $refresh=false): StoredMoney {
		return $this->manager->storeMoneyOrFailFresh($this, $maxAgeSeconds, $baseCurrency, $refresh, $this->overrides);
	}

	public function storedWith(ExchangeSnapshot $snapshot, ?string $baseCurrency=null): StoredMoney {
		return $snapshot->storeMoney($this, $baseCurrency);
	}

	public function storedWithFresh(ExchangeSnapshot $snapshot, int $maxAgeSeconds, ?string $baseCurrency=null): StoredMoney {
		return $snapshot->storeMoneyOrFailFresh($this, $maxAgeSeconds, $baseCurrency);
	}

	public function inDisplayCurrency(?string $currency=null): self {
		$targetCurrency=$currency ?? $this->manager->displayCurrency($this->overrides);
		return $this->convertedTo($targetCurrency);
	}

	public function inBaseCurrency(): self {
		return $this->convertedTo($this->manager->baseCurrency($this->overrides));
	}

	public function display(bool $showFree=true, ?string $currency=null): string {
		return $this->inDisplayCurrency($currency)->format($showFree);
	}

	public function quoteTo(string $targetCurrency, bool $refresh=false): ExchangeQuote {
		return $this->manager->quoteOrFail($this->currency, $targetCurrency, $refresh, $this->overrides);
	}

	public function add(Money|float|int|string $value, ?string $currency=null): self {
		return $this->withMinorAmount(
			$this->minorAmount + $this->normalizeComparableMinorAmount($value, $currency, 'add'),
		);
	}

	public function subtract(Money|float|int|string $value, ?string $currency=null): self {
		return $this->withMinorAmount(
			$this->minorAmount - $this->normalizeComparableMinorAmount($value, $currency, 'subtract'),
		);
	}

	public function multiply(float|int $multiplier, ?int $precision=null, bool $cash=false): self {
		[$multiplier_factor, $multiplier_scale]=$this->decimalParts($multiplier);
		if($precision===null && $cash===false){
			return $this->withMinorAmount($this->roundRatioToInt($this->minorAmount*$multiplier_factor, $multiplier_scale));
		}
		$amount=$this->scaledMinorToDecimal(
			$this->minorAmount*$multiplier_factor,
			$multiplier_scale,
			$precision
		);
		return new self(
			$this->manager->roundAmount($amount, $this->currency, $cash, $this->overrides),
			$this->currency,
			$this->manager,
			$this->overrides
		);
	}

	public function divide(float|int $divisor, ?int $precision=null, bool $cash=false): self {
		[$divisor_factor, $divisor_scale]=$this->decimalParts($divisor);
		if($divisor_factor===0){
			throw new \DivisionByZeroError('Cannot divide money by zero.');
		}
		$numerator=$this->minorAmount*$divisor_scale;
		if($precision===null && $cash===false){
			return $this->withMinorAmount($this->roundRatioToInt($numerator, $divisor_factor));
		}
		$negative=($numerator<0) !== ($divisor_factor<0);
		$numerator=abs($numerator);
		$denominator=abs($divisor_factor);
		$minor_quotient=intdiv(($numerator*2)+$denominator, $denominator*2);
		if($negative){
			$minor_quotient=-$minor_quotient;
		}
		$amount=$this->scaledMinorToDecimal(
			$minor_quotient,
			1,
			$precision
		);
		return new self(
			$this->manager->roundAmount($amount, $this->currency, $cash, $this->overrides),
			$this->currency,
			$this->manager,
			$this->overrides
		);
	}

	public function compare(Money|float|int|string $value, ?string $currency=null): int {
		return $this->minorAmount <=> $this->normalizeComparableMinorAmount($value, $currency, 'compare');
	}

	public function equals(Money|float|int|string $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)===0;
	}

	public function greaterThan(Money|float|int|string $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)===1;
	}

	public function greaterThanOrEqual(Money|float|int|string $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)>=0;
	}

	public function lessThan(Money|float|int|string $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)===-1;
	}

	public function lessThanOrEqual(Money|float|int|string $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)<=0;
	}

	public function split(int $parts, bool $cash=false): array {
		return $this->manager->splitAmount($this->decimalAmount(), $this->currency, $parts, $cash, $this->overrides);
	}

	public function splitMinor(int $parts, bool $cash=false): array {
		return $this->manager->splitMinorUnits($this->minorAmount, $this->currency, $parts, $cash, $this->overrides);
	}

	public function allocate(array $ratios, bool $cash=false): array {
		return array_map(
			fn(int $minorAmount): self => self::fromMinor($minorAmount, $this->currency, $this->manager, $this->overrides),
			$this->allocateMinor($ratios, $cash)
		);
	}

	public function allocateMinor(array $ratios, bool $cash=false): array {
		return $this->manager->allocateMinorUnits($this->minorAmount, $this->currency, $ratios, $cash, $this->overrides);
	}

	public function toArray(): array {
		return [
			'amount'=>$this->decimalAmount(),
			'amount_minor'=>$this->minorAmount,
			'currency'=>$this->currency,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	private function withMinorAmount(int $minorAmount): self {
		return self::fromMinor($minorAmount, $this->currency, $this->manager, $this->overrides);
	}

	private function normalizeComparableMinorAmount(Money|float|int|string $value, ?string $currency, string $operation): int {
		if($value instanceof self){
			if($value->currency()!==$this->currency){
				throw CurrencyMismatchException::forOperation($operation, $this->currency, $value->currency());
			}
			return $value->minorAmount();
		}
		if($currency!==null){
			$currency=mb_strtoupper(trim($currency));
			if($currency!=='' && $currency!==$this->currency){
				throw CurrencyMismatchException::forOperation($operation, $this->currency, $currency);
			}
		}
		return $this->manager->amountToMinorUnits($value, $this->currency, false, $this->overrides);
	}

	private function decimalParts(float|int $value): array {
		if(is_int($value)){
			return [$value, 1];
		}
		$normalized=$this->decimalInput($value);
		$negative=str_starts_with($normalized, '-');
		$normalized=ltrim($normalized, '+-');
		[$whole, $fraction]=array_pad(explode('.', $normalized, 2), 2, '');
		$fraction=rtrim($fraction, '0');
		$digits=(preg_replace('/\D/', '', $whole) ?: '0').preg_replace('/\D/', '', $fraction);
		$factor=(int)ltrim($digits, '0');
		if($factor===0){
			return [0, 1];
		}
		return [$negative ? -$factor : $factor, $fraction==='' ? 1 : 10**strlen($fraction)];
	}

	private function decimalInput(float|int $value): string {
		if(is_int($value)){
			return (string)$value;
		}
		return rtrim(rtrim(sprintf('%.14F', $value), '0'), '.') ?: '0';
	}

	private function roundRatioToInt(int $numerator, int $denominator): int {
		if($denominator<0){
			$numerator=-$numerator;
			$denominator=-$denominator;
		}
		$negative=$numerator<0;
		$numerator=abs($numerator);
		$rounded=intdiv(($numerator*2)+$denominator, $denominator*2);
		return $negative ? -$rounded : $rounded;
	}

	private function scaledMinorToDecimal(int $scaledMinor, int $scale, ?int $precision): string {
		$currency_scale=10**$this->minorUnits();
		$denominator=$currency_scale*$scale;
		$negative=$scaledMinor<0;
		$scaledMinor=abs($scaledMinor);
		$whole=intdiv($scaledMinor, $denominator);
		$remainder=$scaledMinor % $denominator;
		$places=$precision ?? $this->minorUnits()+6;
		if($places<=0){
			$round=$remainder*2 >= $denominator ? 1 : 0;
			return ($negative ? '-' : '').(string)($whole+$round);
		}
		$fraction=intdiv(($remainder*(10**$places)*2)+$denominator, $denominator*2);
		if($fraction>=10**$places){
			$whole++;
			$fraction=0;
		}
		return ($negative ? '-' : '').$whole.'.'.str_pad((string)$fraction, $places, '0', STR_PAD_LEFT);
	}
}
