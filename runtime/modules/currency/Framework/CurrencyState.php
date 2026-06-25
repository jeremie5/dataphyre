<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

/**
 * Captures the runtime currency context used for display and money formatting decisions.
 *
 * CurrencyState is an immutable read model: it records the base accounting
 * currency, active display currency, display locale hints, and the available
 * currency symbol map. It does not fetch exchange rates or mutate configuration;
 * those responsibilities remain in the currency kernel and persistence layer.
 */
final class CurrencyState implements \JsonSerializable {

	/**
	 * Stores a normalized currency context snapshot.
	 *
	 * Callers using the constructor are expected to pass canonical currency and
	 * country codes. fromArray() performs default normalization for persisted,
	 * session, cache, or request-derived state arrays, while the constructor
	 * preserves the exact values it receives for explicit framework usage.
	 *
	 * @param string $baseCurrency Currency used as the accounting or conversion baseline.
	 * @param string $displayCurrency Currency selected for user-facing presentation.
	 * @param string $displayLanguage Locale language tag used for formatting context.
	 * @param string $displayCountry Country code used for regional currency display decisions.
	 * @param array<string,string> $availableCurrencies Map of uppercase currency codes to symbols or labels.
	 */
	public function __construct(
		private readonly string $baseCurrency,
		private readonly string $displayCurrency,
		private readonly string $displayLanguage,
		private readonly string $displayCountry,
		private readonly array $availableCurrencies
	){}

	/**
	 * Rehydrates a currency state snapshot from an array.
	 *
	 * Missing base or display currency defaults to USD, missing language defaults
	 * to en-CA, and missing country defaults to CA. Currency and country codes are
	 * uppercased so lookups behave consistently across request, session, cache, and
	 * serialized state sources. Non-array available currency data is discarded.
	 *
	 * @param array{base_currency?:string,display_currency?:string,display_language?:string,display_country?:string,available_currencies?:array<string,string>} $state Persisted, session, cache, or request-derived currency state.
	 * @return self Immutable state reconstructed from the array.
	 */
	public static function fromArray(array $state): self {
		return new self(
			mb_strtoupper((string)($state['base_currency'] ?? 'USD')),
			mb_strtoupper((string)($state['display_currency'] ?? 'USD')),
			(string)($state['display_language'] ?? 'en-CA'),
			mb_strtoupper((string)($state['display_country'] ?? 'CA')),
			is_array($state['available_currencies'] ?? null) ? $state['available_currencies'] : []
		);
	}

	/**
	 * Returns the base currency used for accounting and conversion decisions.
	 *
	 * @return string Uppercase currency code.
	 */
	public function baseCurrency(): string {
		return $this->baseCurrency;
	}

	/**
	 * Returns the currency selected for user-facing display.
	 *
	 * @return string Uppercase currency code.
	 */
	public function displayCurrency(): string {
		return $this->displayCurrency;
	}

	/**
	 * Returns the locale language tag used when formatting currency output.
	 *
	 * @return string Language or locale tag such as en-CA.
	 */
	public function displayLanguage(): string {
		return $this->displayLanguage;
	}

	/**
	 * Returns the regional country code used for display decisions.
	 *
	 * @return string Uppercase country code.
	 */
	public function displayCountry(): string {
		return $this->displayCountry;
	}

	/**
	 * Returns the available currency symbol map.
	 *
	 * The map is keyed by uppercase currency code. Values are treated as display
	 * symbols or labels by symbol(), and the array is returned unchanged so callers
	 * can preserve custom runtime currency catalogs.
	 *
	 * @return array<string,mixed> Currency code to symbol or label map.
	 */
	public function availableCurrencies(): array {
		return $this->availableCurrencies;
	}

	/**
	 * Checks whether a currency code exists in the available currency map.
	 *
	 * The lookup trims and uppercases the input before checking keys, matching the
	 * normalization applied by fromArray(). Presence is based on key existence, so
	 * null-like symbols can still represent an intentionally available currency.
	 *
	 * @param string $currency Currency code to check.
	 * @return bool True when the normalized code is present in availableCurrencies().
	 */
	public function hasCurrency(string $currency): bool {
		return array_key_exists(mb_strtoupper(trim($currency)), $this->availableCurrencies);
	}

	/**
	 * Resolves the configured display symbol for a currency code.
	 *
	 * The lookup is case-insensitive after trimming. A null return means the
	 * currency is absent from the available map or its value is not set; this method
	 * does not fall back to the kernel symbol catalog.
	 *
	 * @param string $currency Currency code to resolve.
	 * @return ?string Configured symbol or label, or null when unavailable.
	 */
	public function symbol(string $currency): ?string {
		$currency=mb_strtoupper(trim($currency));
		return isset($this->availableCurrencies[$currency]) ? (string)$this->availableCurrencies[$currency] : null;
	}

	/**
	 * Calculates minor units for the current Currency Framework selection.
	 *
	 * Minor-unit rules come from the currency kernel rather than from the available
	 * symbol map, so callers can ask about a code even when it is not enabled for
	 * display. The input is trimmed and uppercased before delegation.
	 *
	 * @param string $currency Currency code to inspect.
	 * @return int Number of decimal places used by the currency's minor unit.
	 */
	public function minorUnits(string $currency): int {
		return \dataphyre\currency::minor_units(mb_strtoupper(trim($currency)));
	}

	/**
	 * Resolves the cash rounding increment for a currency code.
	 *
	 * Rounding rules are delegated to the currency kernel so this value stays
	 * aligned with shared monetary behavior. A null result means the currency has
	 * no configured cash rounding increment.
	 *
	 * @param string $currency Currency code to inspect.
	 * @return ?float Cash rounding increment, or null when no special cash rounding applies.
	 */
	public function cashRoundingIncrement(string $currency): ?float {
		return \dataphyre\currency::cash_rounding_increment(mb_strtoupper(trim($currency)));
	}

	/**
	 * Exports the immutable currency context for sessions, diagnostics, and JSON output.
	 *
	 * The array contains only scalar display context and the currency symbol map;
	 * exchange-rate tables and mutable kernel state are intentionally omitted.
	 *
	 * @return array{base_currency:string, display_currency:string, display_language:string, display_country:string, available_currencies:array<string, string>} Currency context snapshot.
	 */
	public function toArray(): array {
		return [
			'base_currency'=>$this->baseCurrency,
			'display_currency'=>$this->displayCurrency,
			'display_language'=>$this->displayLanguage,
			'display_country'=>$this->displayCountry,
			'available_currencies'=>$this->availableCurrencies,
		];
	}

	/**
	 * Serializes the currency context without fetching rates or mutating kernel state.
	 *
	 * JSON output matches toArray() so session and HTTP representations share the
	 * same data shape.
	 *
	 * @return array{base_currency:string, display_currency:string, display_language:string, display_country:string, available_currencies:array<string, string>} Currency context snapshot.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
