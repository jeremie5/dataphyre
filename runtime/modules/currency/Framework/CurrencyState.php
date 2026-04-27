<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

final class CurrencyState implements \JsonSerializable {

	public function __construct(
		private readonly string $base_currency,
		private readonly string $display_currency,
		private readonly string $display_language,
		private readonly string $display_country,
		private readonly array $available_currencies
	){}

	public static function fromArray(array $state): self {
		return new self(
			mb_strtoupper((string)($state['base_currency'] ?? 'USD')),
			mb_strtoupper((string)($state['display_currency'] ?? 'USD')),
			(string)($state['display_language'] ?? 'en-CA'),
			mb_strtoupper((string)($state['display_country'] ?? 'CA')),
			is_array($state['available_currencies'] ?? null) ? $state['available_currencies'] : []
		);
	}

	public function baseCurrency(): string {
		return $this->base_currency;
	}

	public function displayCurrency(): string {
		return $this->display_currency;
	}

	public function displayLanguage(): string {
		return $this->display_language;
	}

	public function displayCountry(): string {
		return $this->display_country;
	}

	public function availableCurrencies(): array {
		return $this->available_currencies;
	}

	public function hasCurrency(string $currency): bool {
		return array_key_exists(mb_strtoupper(trim($currency)), $this->available_currencies);
	}

	public function symbol(string $currency): ?string {
		$currency=mb_strtoupper(trim($currency));
		return isset($this->available_currencies[$currency]) ? (string)$this->available_currencies[$currency] : null;
	}

	public function minorUnits(string $currency): int {
		return \dataphyre\currency::minor_units(mb_strtoupper(trim($currency)));
	}

	public function cashRoundingIncrement(string $currency): ?float {
		return \dataphyre\currency::cash_rounding_increment(mb_strtoupper(trim($currency)));
	}

	public function toArray(): array {
		return [
			'base_currency'=>$this->base_currency,
			'display_currency'=>$this->display_currency,
			'display_language'=>$this->display_language,
			'display_country'=>$this->display_country,
			'available_currencies'=>$this->available_currencies,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
