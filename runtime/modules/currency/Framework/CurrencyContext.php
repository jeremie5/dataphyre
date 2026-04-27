<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

final class CurrencyContext {

	public function __construct(
		private readonly CurrencyManager $manager,
		private readonly array $overrides=[]
	){}

	public function baseCurrency(?string $base_currency): self {
		return new self($this->manager, $this->withOverride('base_currency', $base_currency));
	}

	public function displayCurrency(?string $display_currency): self {
		return new self($this->manager, $this->withOverride('display_currency', $display_currency));
	}

	public function language(?string $display_language): self {
		return new self($this->manager, $this->withOverride('display_language', $display_language));
	}

	public function country(?string $display_country): self {
		return new self($this->manager, $this->withOverride('display_country', $display_country));
	}

	public function availableCurrencies(?array $available_currencies): self {
		return new self($this->manager, $this->withOverride('available_currencies', $available_currencies));
	}

	public function state(): CurrencyState {
		return $this->manager->state($this->overrides);
	}

	public function minorUnits(string $currency): int {
		return $this->manager->minorUnits($currency);
	}

	public function cashRoundingIncrement(string $currency): ?float {
		return $this->manager->cashRoundingIncrement($currency);
	}

	public function rates(bool $refresh=false, ?string $source=null): ExchangeRates {
		return $this->manager->rates($refresh, $source, $this->overrides);
	}

	public function refresh(?string $source=null): ExchangeRates {
		return $this->manager->refresh($source, $this->overrides);
	}

	public function snapshot(bool $refresh=false, ?string $source=null): ExchangeSnapshot {
		return $this->manager->snapshot($refresh, $source, $this->overrides);
	}

	public function snapshotOrFail(int $max_age_seconds, bool $refresh=false, ?string $source=null): ExchangeSnapshot {
		return $this->manager->snapshotOrFail($max_age_seconds, $refresh, $source, $this->overrides);
	}

	public function refreshSource(string $source): bool {
		return $this->manager->refreshSource($source, $this->overrides);
	}

	public function format(float|int|null $amount, bool $show_free=false, ?string $currency=null): string {
		return $this->manager->format($amount, $show_free, $currency, $this->overrides);
	}

	public function roundAmount(float|int|null $amount, string $currency, bool $cash=false): float {
		return $this->manager->roundAmount($amount, $currency, $cash, $this->overrides);
	}

	public function quote(string $source_currency, string $target_currency, bool $refresh=false): ?ExchangeQuote {
		return $this->manager->quote($source_currency, $target_currency, $refresh, $this->overrides);
	}

	public function quoteOrFail(string $source_currency, string $target_currency, bool $refresh=false): ExchangeQuote {
		return $this->manager->quoteOrFail($source_currency, $target_currency, $refresh, $this->overrides);
	}

	public function quoteOrFailFresh(
		string $source_currency,
		string $target_currency,
		int $max_age_seconds,
		bool $refresh=false
	): ExchangeQuote {
		return $this->manager->quoteOrFailFresh(
			$source_currency,
			$target_currency,
			$max_age_seconds,
			$refresh,
			$this->overrides
		);
	}

	public function convert(
		float|int|null $amount,
		string $source_currency,
		string $target_currency,
		bool $formatted=false,
		bool $show_free=true
	): string|float {
		return $this->manager->convert($amount, $source_currency, $target_currency, $formatted, $show_free, $this->overrides);
	}

	public function convertToDisplay(
		float|int|null $amount,
		bool $formatted=false,
		bool $show_free=true,
		?string $currency=null
	): string|float {
		return $this->manager->convertToDisplay($amount, $formatted, $show_free, $currency, $this->overrides);
	}

	public function convertToBase(
		float|int|null $amount,
		string $original_currency,
		bool $formatted=false,
		bool $show_free=true
	): string|float {
		return $this->manager->convertToBase($amount, $original_currency, $formatted, $show_free, $this->overrides);
	}

	public function money(float|int|null $amount, ?string $currency=null): Money {
		return $this->manager->money($amount, $currency, $this->overrides);
	}

	public function convertMoney(Money $money, string $target_currency, bool $refresh=false): Money {
		return $this->manager->convertMoney($money, $target_currency, $refresh, $this->overrides);
	}

	public function convertMoneyOrFailFresh(
		Money $money,
		string $target_currency,
		int $max_age_seconds,
		bool $refresh=false
	): Money {
		return $this->manager->convertMoneyOrFailFresh(
			$money,
			$target_currency,
			$max_age_seconds,
			$refresh,
			$this->overrides
		);
	}

	public function convertOrFailFresh(
		float|int|null $amount,
		string $source_currency,
		string $target_currency,
		int $max_age_seconds,
		bool $refresh=false
	): float {
		return $this->manager->convertOrFailFresh(
			$amount,
			$source_currency,
			$target_currency,
			$max_age_seconds,
			$refresh,
			$this->overrides
		);
	}

	public function storeMoney(
		Money $money,
		?string $base_currency=null,
		bool $refresh=false
	): StoredMoney {
		return $this->manager->storeMoney($money, $base_currency, $refresh, $this->overrides);
	}

	public function storeMoneyOrFailFresh(
		Money $money,
		int $max_age_seconds,
		?string $base_currency=null,
		bool $refresh=false
	): StoredMoney {
		return $this->manager->storeMoneyOrFailFresh(
			$money,
			$max_age_seconds,
			$base_currency,
			$refresh,
			$this->overrides
		);
	}

	public function splitAmount(float|int|null $amount, string $currency, int $parts, bool $cash=false): array {
		return $this->manager->splitAmount($amount, $currency, $parts, $cash, $this->overrides);
	}

	public function allocateAmount(float|int|null $amount, string $currency, array $ratios, bool $cash=false): array {
		return $this->manager->allocateAmount($amount, $currency, $ratios, $cash, $this->overrides);
	}

	private function withOverride(string $key, mixed $value): array {
		$overrides=$this->overrides;
		if($value===null){
			unset($overrides[$key]);
			return $overrides;
		}
		$overrides[$key]=$value;
		return $overrides;
	}
}
