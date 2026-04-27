<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

use Dataphyre\Currency\Exceptions\StaleExchangeRatesException;

final class ExchangeSnapshot implements \Countable, \JsonSerializable {

	public function __construct(
		private readonly ExchangeRates $rates,
		private readonly CurrencyManager $manager,
		private readonly array $overrides=[]
	){}

	public function rates(): ExchangeRates {
		return $this->rates;
	}

	public function baseCurrency(): string {
		return $this->rates->baseCurrency();
	}

	public function source(): string {
		return $this->rates->source();
	}

	public function time(): int {
		return $this->rates->time();
	}

	public function ageSeconds(): int {
		return $this->rates->ageSeconds();
	}

	public function isStale(int $max_age_seconds): bool {
		return $max_age_seconds > 0 && $this->ageSeconds()>$max_age_seconds;
	}

	public function assertFresh(int $max_age_seconds): self {
		if($this->isStale($max_age_seconds)){
			throw StaleExchangeRatesException::forSnapshot(
				$this->source(),
				$this->time(),
				$this->ageSeconds(),
				$max_age_seconds
			);
		}
		return $this;
	}

	public function has(string $currency): bool {
		return $this->rates->has($currency);
	}

	public function rate(string $currency): ?float {
		return $this->rates->rate($currency);
	}

	public function currencies(): array {
		return $this->rates->currencies();
	}

	public function minorUnits(string $currency): int {
		return $this->rates->minorUnits($currency);
	}

	public function count(): int {
		return $this->rates->count();
	}

	public function quote(string $source_currency, string $target_currency): ?ExchangeQuote {
		return $this->rates->quote($source_currency, $target_currency);
	}

	public function quoteOrFail(string $source_currency, string $target_currency): ExchangeQuote {
		return $this->rates->quoteOrFail($source_currency, $target_currency);
	}

	public function quoteOrFailFresh(string $source_currency, string $target_currency, int $max_age_seconds): ExchangeQuote {
		return $this->quoteOrFail($source_currency, $target_currency)->assertFresh($max_age_seconds);
	}

	public function convert(float|int|null $amount, string $source_currency, string $target_currency): float {
		return $this->quoteOrFail($source_currency, $target_currency)->convert($amount);
	}

	public function convertOrFail(float|int|null $amount, string $source_currency, string $target_currency): float {
		return $this->convert($amount, $source_currency, $target_currency);
	}

	public function convertOrFailFresh(
		float|int|null $amount,
		string $source_currency,
		string $target_currency,
		int $max_age_seconds
	): float {
		return $this->quoteOrFailFresh($source_currency, $target_currency, $max_age_seconds)->convert($amount);
	}

	public function money(float|int|null $amount, ?string $currency=null): Money {
		$currency=$currency===null ? $this->baseCurrency() : mb_strtoupper(trim($currency));
		return new Money((float)$amount, $currency, $this->manager, $this->overrides);
	}

	public function convertMoney(Money $money, string $target_currency): Money {
		$target_currency=mb_strtoupper(trim($target_currency));
		$overrides=array_replace($this->overrides, $money->contextOverrides());
		return new Money(
			$this->quoteOrFail($money->currency(), $target_currency)->convert($money->amount()),
			$target_currency,
			$this->manager,
			$overrides
		);
	}

	public function convertMoneyOrFailFresh(Money $money, string $target_currency, int $max_age_seconds): Money {
		$this->assertFresh($max_age_seconds);
		return $this->convertMoney($money, $target_currency);
	}

	public function storeMoney(Money $money, ?string $base_currency=null): StoredMoney {
		$base_currency=$base_currency===null ? $this->baseCurrency() : mb_strtoupper(trim($base_currency));
		$quote=$this->quoteOrFail($money->currency(), $base_currency);
		return new StoredMoney(
			$money,
			$this->convertMoney($money, $base_currency),
			$this,
			$quote
		);
	}

	public function storeMoneyOrFailFresh(Money $money, int $max_age_seconds, ?string $base_currency=null): StoredMoney {
		$this->assertFresh($max_age_seconds);
		return $this->storeMoney($money, $base_currency);
	}

	public function state(): CurrencyState {
		return $this->manager->state($this->overrides);
	}

	public function contextOverrides(): array {
		return $this->overrides;
	}

	public function toArray(): array {
		return array_merge(
			$this->rates->toArray(),
			[
				'context_overrides'=>$this->overrides,
			]
		);
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
