<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

use Dataphyre\Currency\Exceptions\StaleExchangeRatesException;

final class ExchangeQuote implements \JsonSerializable {

	public function __construct(
		private readonly string $base_currency,
		private readonly string $source_currency,
		private readonly string $target_currency,
		private readonly int $source_minor_units,
		private readonly int $target_minor_units,
		private readonly float $rate,
		private readonly string $source,
		private readonly int $time
	){}

	public function baseCurrency(): string {
		return $this->base_currency;
	}

	public function sourceCurrency(): string {
		return $this->source_currency;
	}

	public function targetCurrency(): string {
		return $this->target_currency;
	}

	public function sourceMinorUnits(): int {
		return $this->source_minor_units;
	}

	public function targetMinorUnits(): int {
		return $this->target_minor_units;
	}

	public function rate(): float {
		return $this->rate;
	}

	public function source(): string {
		return $this->source;
	}

	public function time(): int {
		return $this->time;
	}

	public function ageSeconds(): int {
		return max(0, time()-$this->time);
	}

	public function isStale(int $max_age_seconds): bool {
		return $max_age_seconds > 0 && $this->ageSeconds()>$max_age_seconds;
	}

	public function assertFresh(int $max_age_seconds): self {
		if($this->isStale($max_age_seconds)){
			throw StaleExchangeRatesException::forQuote(
				$this->source_currency,
				$this->target_currency,
				$this->source,
				$this->time,
				$this->ageSeconds(),
				$max_age_seconds
			);
		}
		return $this;
	}

	public function convert(float|int|null $amount): float {
		return (float)number_format(((float)$amount)*$this->rate, $this->target_minor_units, '.', '');
	}

	public function convertOrFailFresh(float|int|null $amount, int $max_age_seconds): float {
		return $this->assertFresh($max_age_seconds)->convert($amount);
	}

	public function inverse(): self {
		return new self(
			$this->base_currency,
			$this->target_currency,
			$this->source_currency,
			$this->target_minor_units,
			$this->source_minor_units,
			$this->rate==0.0 ? 0.0 : (1 / $this->rate),
			$this->source,
			$this->time
		);
	}

	public function toArray(): array {
		return [
			'base_currency'=>$this->base_currency,
			'source_currency'=>$this->source_currency,
			'target_currency'=>$this->target_currency,
			'source_minor_units'=>$this->source_minor_units,
			'target_minor_units'=>$this->target_minor_units,
			'rate'=>$this->rate,
			'source'=>$this->source,
			'time'=>$this->time,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
