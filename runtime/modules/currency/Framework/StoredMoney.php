<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

final class StoredMoney implements \JsonSerializable {

	public function __construct(
		private readonly Money $original,
		private readonly Money $base,
		private readonly ExchangeSnapshot $snapshot,
		private readonly ExchangeQuote $quote
	){}

	public function original(): Money {
		return $this->original;
	}

	public function base(): Money {
		return $this->base;
	}

	public function snapshot(): ExchangeSnapshot {
		return $this->snapshot;
	}

	public function quote(): ExchangeQuote {
		return $this->quote;
	}

	public function originalAmount(): float {
		return $this->original->amount();
	}

	public function originalCurrency(): string {
		return $this->original->currency();
	}

	public function baseAmount(): float {
		return $this->base->amount();
	}

	public function baseCurrency(): string {
		return $this->base->currency();
	}

	public function exchangeRate(): float {
		return $this->quote->rate();
	}

	public function exchangeSource(): string {
		return $this->snapshot->source();
	}

	public function exchangeTime(): int {
		return $this->snapshot->time();
	}

	public function exchangeSnapshotBaseCurrency(): string {
		return $this->snapshot->baseCurrency();
	}

	public function toArray(
		string $original_prefix='original_',
		string $base_prefix='base_',
		string $exchange_prefix='exchange_'
	): array {
		return [
			$original_prefix.'amount'=>$this->originalAmount(),
			$original_prefix.'currency'=>$this->originalCurrency(),
			$base_prefix.'amount'=>$this->baseAmount(),
			$base_prefix.'currency'=>$this->baseCurrency(),
			$exchange_prefix.'rate'=>$this->exchangeRate(),
			$exchange_prefix.'source'=>$this->exchangeSource(),
			$exchange_prefix.'time'=>$this->exchangeTime(),
			$exchange_prefix.'base_currency'=>$this->exchangeSnapshotBaseCurrency(),
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
