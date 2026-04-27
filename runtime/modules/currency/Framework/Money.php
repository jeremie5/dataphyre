<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

use Dataphyre\Currency\Exceptions\CurrencyMismatchException;

final class Money implements \JsonSerializable {

	private readonly float $amount;
	private readonly string $currency;
	private readonly CurrencyManager $manager;
	private readonly array $overrides;

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

	public function amount(): float {
		return $this->amount;
	}

	public function value(): float {
		return $this->amount;
	}

	public function currency(): string {
		return $this->currency;
	}

	public function contextOverrides(): array {
		return $this->overrides;
	}

	public function isZero(): bool {
		return $this->amount==0.0;
	}

	public function minorUnits(): int {
		return $this->manager->minorUnits($this->currency);
	}

	public function cashRoundingIncrement(): ?float {
		return $this->manager->cashRoundingIncrement($this->currency);
	}

	public function format(bool $show_free=false): string {
		return $this->manager->format($this->amount, $show_free, $this->currency, $this->overrides);
	}

	public function withAmount(float|int|null $amount): self {
		return new self($amount, $this->currency, $this->manager, $this->overrides);
	}

	public function rounded(bool $cash=false): self {
		return new self(
			$this->manager->roundAmount($this->amount, $this->currency, $cash, $this->overrides),
			$this->currency,
			$this->manager,
			$this->overrides
		);
	}

	public function convertedTo(string $target_currency): self {
		$target_currency=mb_strtoupper(trim($target_currency));
		return $this->manager->convertMoney($this, $target_currency, false, $this->overrides);
	}

	public function quoteWith(ExchangeSnapshot $snapshot, string $target_currency): ExchangeQuote {
		return $snapshot->quoteOrFail($this->currency, $target_currency);
	}

	public function convertedWith(ExchangeSnapshot $snapshot, string $target_currency): self {
		return $snapshot->convertMoney($this, $target_currency);
	}

	public function quoteWithFresh(ExchangeSnapshot $snapshot, string $target_currency, int $max_age_seconds): ExchangeQuote {
		return $snapshot->quoteOrFailFresh($this->currency, $target_currency, $max_age_seconds);
	}

	public function convertedWithFresh(ExchangeSnapshot $snapshot, string $target_currency, int $max_age_seconds): self {
		return $snapshot->convertMoneyOrFailFresh($this, $target_currency, $max_age_seconds);
	}

	public function stored(?string $base_currency=null, bool $refresh=false): StoredMoney {
		return $this->manager->storeMoney($this, $base_currency, $refresh, $this->overrides);
	}

	public function storedFresh(int $max_age_seconds, ?string $base_currency=null, bool $refresh=false): StoredMoney {
		return $this->manager->storeMoneyOrFailFresh($this, $max_age_seconds, $base_currency, $refresh, $this->overrides);
	}

	public function storedWith(ExchangeSnapshot $snapshot, ?string $base_currency=null): StoredMoney {
		return $snapshot->storeMoney($this, $base_currency);
	}

	public function storedWithFresh(ExchangeSnapshot $snapshot, int $max_age_seconds, ?string $base_currency=null): StoredMoney {
		return $snapshot->storeMoneyOrFailFresh($this, $max_age_seconds, $base_currency);
	}

	public function inDisplayCurrency(?string $currency=null): self {
		$target_currency=$currency ?? $this->manager->displayCurrency($this->overrides);
		return $this->convertedTo($target_currency);
	}

	public function inBaseCurrency(): self {
		return $this->convertedTo($this->manager->baseCurrency($this->overrides));
	}

	public function display(bool $show_free=true, ?string $currency=null): string {
		return $this->inDisplayCurrency($currency)->format($show_free);
	}

	public function quoteTo(string $target_currency, bool $refresh=false): ExchangeQuote {
		return $this->manager->quoteOrFail($this->currency, $target_currency, $refresh, $this->overrides);
	}

	public function add(Money|float|int $value, ?string $currency=null): self {
		return $this->withAmount($this->amount + $this->normalizeComparableAmount($value, $currency, 'add'));
	}

	public function subtract(Money|float|int $value, ?string $currency=null): self {
		return $this->withAmount($this->amount - $this->normalizeComparableAmount($value, $currency, 'subtract'));
	}

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

	public function compare(Money|float|int $value, ?string $currency=null): int {
		$other_amount=$this->normalizeComparableAmount($value, $currency, 'compare');
		return $this->amount <=> $other_amount;
	}

	public function equals(Money|float|int $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)===0;
	}

	public function greaterThan(Money|float|int $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)===1;
	}

	public function greaterThanOrEqual(Money|float|int $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)>=0;
	}

	public function lessThan(Money|float|int $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)===-1;
	}

	public function lessThanOrEqual(Money|float|int $value, ?string $currency=null): bool {
		return $this->compare($value, $currency)<=0;
	}

	public function split(int $parts, bool $cash=false): array {
		return $this->manager->splitAmount($this->amount, $this->currency, $parts, $cash, $this->overrides);
	}

	public function allocate(array $ratios, bool $cash=false): array {
		return $this->manager->allocateAmount($this->amount, $this->currency, $ratios, $cash, $this->overrides);
	}

	public function toArray(): array {
		return [
			'amount'=>$this->amount,
			'currency'=>$this->currency,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

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
