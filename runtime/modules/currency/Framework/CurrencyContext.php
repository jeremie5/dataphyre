<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

/**
 * Immutable scoped currency facade over the shared CurrencyManager.
 *
 * CurrencyContext carries temporary base/display currency, locale, and symbol-map
 * overrides through formatting, conversion, quote, snapshot, Money, StoredMoney,
 * splitting, and allocation calls. Each modifier returns a new context so callers
 * can localize currency behavior without mutating process-wide kernel state.
 */
final class CurrencyContext {

	/**
	 * Creates an immutable currency context bound to a manager and override set.
	 *
	 * Context overrides are applied to manager calls without mutating global currency
	 * state, allowing scoped base/display currency, locale, and symbol-map behavior.
	 *
	 * @param CurrencyManager $manager Manager that performs kernel-backed currency operations.
	 * @param array<string, mixed> $overrides Context-specific currency state overrides.
	 */
	public function __construct(
		private readonly CurrencyManager $manager,
		private readonly array $overrides=[]
	){}

	/**
	 * Returns a context with a base-currency override applied or removed.
	 *
	 * Passing null clears the override so the manager's current state is used.
	 *
	 * @param string|null $baseCurrency ISO base currency override.
	 * @return self New context with updated override state.
	 */
	public function baseCurrency(?string $baseCurrency): self {
		return new self($this->manager, $this->withOverride('base_currency', $baseCurrency));
	}

	/**
	 * Returns a context with a display-currency override applied or removed.
	 *
	 * @param string|null $displayCurrency ISO display currency override.
	 * @return self New context with updated override state.
	 */
	public function displayCurrency(?string $displayCurrency): self {
		return new self($this->manager, $this->withOverride('display_currency', $displayCurrency));
	}

	/**
	 * Returns a context with a display-language override applied or removed.
	 *
	 * The language controls formatting separator rules in kernel-backed formatting.
	 *
	 * @param string|null $displayLanguage Display language tag override.
	 * @return self New context with updated override state.
	 */
	public function language(?string $displayLanguage): self {
		return new self($this->manager, $this->withOverride('display_language', $displayLanguage));
	}

	/**
	 * Returns a context with a display-country override applied or removed.
	 *
	 * The country participates in locale-specific formatting lookup together with the
	 * display language.
	 *
	 * @param string|null $displayCountry Display country code override.
	 * @return self New context with updated override state.
	 */
	public function country(?string $displayCountry): self {
		return new self($this->manager, $this->withOverride('display_country', $displayCountry));
	}

	/**
	 * Returns a context with a currency-symbol map override applied or removed.
	 *
	 * The map is used by format() and formatted conversion results.
	 *
	 * @param array<string, string>|null $availableCurrencies Symbols keyed by ISO currency code.
	 * @return self New context with updated override state.
	 */
	public function availableCurrencies(?array $availableCurrencies): self {
		return new self($this->manager, $this->withOverride('available_currencies', $availableCurrencies));
	}

	/**
	 * Returns the effective currency state for this context.
	 *
	 * Manager state is merged with context overrides and exposed as a typed
	 * CurrencyState value.
	 *
	 * @return CurrencyState Effective currency state snapshot.
	 */
	public function state(): CurrencyState {
		return $this->manager->state($this->overrides);
	}

	/**
	 * Returns the decimal precision configured for a currency.
	 *
	 * Context overrides do not affect per-currency minor-unit metadata.
	 *
	 * @param string $currency ISO currency code.
	 * @return int Non-negative number of minor-unit decimal places.
	 */
	public function minorUnits(string $currency): int {
		return $this->manager->minorUnits($currency);
	}

	/**
	 * Returns the cash-rounding increment configured for a currency.
	 *
	 * @param string $currency ISO currency code.
	 * @return float|null Cash rounding increment, or null when none applies.
	 */
	public function cashRoundingIncrement(string $currency): ?float {
		return $this->manager->cashRoundingIncrement($currency);
	}

	/**
	 * Returns exchange rates visible to this context.
	 *
	 * Refresh requests force the manager to fetch new rates. A source can constrain
	 * which provider is refreshed or read, depending on manager policy.
	 *
	 * @param bool $refresh Whether to refresh rates before returning them.
	 * @param string|null $source Optional provider source.
	 * @return ExchangeRates Effective exchange-rate table.
	 */
	public function rates(bool $refresh=false, ?string $source=null): ExchangeRates {
		return $this->manager->rates($refresh, $source, $this->overrides);
	}

	/**
	 * Refreshes exchange rates and returns the resulting rate table.
	 *
	 * @param string|null $source Optional provider source to refresh.
	 * @return ExchangeRates Refreshed exchange-rate table.
	 */
	public function refresh(?string $source=null): ExchangeRates {
		return $this->manager->refresh($source, $this->overrides);
	}

	/**
	 * Returns an exchange-rate snapshot with metadata for this context.
	 *
	 * Snapshots preserve rates, source, timestamp, age, base currency, and context
	 * state for diagnostics or freshness checks.
	 *
	 * @param bool $refresh Whether to refresh before capturing the snapshot.
	 * @param string|null $source Optional provider source.
	 * @return ExchangeSnapshot Exchange-rate snapshot.
	 */
	public function snapshot(bool $refresh=false, ?string $source=null): ExchangeSnapshot {
		return $this->manager->snapshot($refresh, $source, $this->overrides);
	}

	/**
	 * Returns a snapshot only when exchange rates are fresh enough.
	 *
	 * Manager policy decides the exact exception when rates are missing or older than
	 * the maximum age.
	 *
	 * @param int $maxAgeSeconds Maximum acceptable snapshot age.
	 * @param bool $refresh Whether to refresh before capturing the snapshot.
	 * @param string|null $source Optional provider source.
	 * @return ExchangeSnapshot Fresh exchange-rate snapshot.
	 */
	public function snapshotOrFail(int $maxAgeSeconds, bool $refresh=false, ?string $source=null): ExchangeSnapshot {
		return $this->manager->snapshotOrFail($maxAgeSeconds, $refresh, $source, $this->overrides);
	}

	/**
	 * Refreshes rates from one provider source.
	 *
	 * @param string $source Provider source name.
	 * @return bool True when the source produced usable rates.
	 */
	public function refreshSource(string $source): bool {
		return $this->manager->refreshSource($source, $this->overrides);
	}

	/**
	 * Formats an amount using this context's display currency, locale, and symbol map.
	 *
	 * Passing a currency overrides only the currency used for this formatting call.
	 *
	 * @param float|int|null $amount Amount in major currency units.
	 * @param bool $showFree Render zero as a localized Free label when true.
	 * @param string|null $currency Optional currency override for output.
	 * @return string User-facing formatted amount.
	 */
	public function format(float|int|null $amount, bool $showFree=false, ?string $currency=null): string {
		return $this->manager->format($amount, $showFree, $currency, $this->overrides);
	}

	/**
	 * Rounds an amount according to currency precision and optional cash rules.
	 *
	 * @param float|int|null $amount Amount in major currency units.
	 * @param string $currency ISO currency code.
	 * @param bool $cash Whether cash-rounding increments should apply.
	 * @return float Rounded amount.
	 */
	public function roundAmount(float|int|null $amount, string $currency, bool $cash=false): float {
		return $this->manager->roundAmount($amount, $currency, $cash, $this->overrides);
	}

	/**
	 * Returns an exchange quote between two currencies when rates are available.
	 *
	 * @param string $sourceCurrency Source ISO currency code.
	 * @param string $targetCurrency Target ISO currency code.
	 * @param bool $refresh Whether to refresh rates first.
	 * @return ExchangeQuote|null Quote object or null when unavailable.
	 */
	public function quote(string $sourceCurrency, string $targetCurrency, bool $refresh=false): ?ExchangeQuote {
		return $this->manager->quote($sourceCurrency, $targetCurrency, $refresh, $this->overrides);
	}

	/**
	 * Returns an exchange quote or raises when it cannot be produced.
	 *
	 * @param string $sourceCurrency Source ISO currency code.
	 * @param string $targetCurrency Target ISO currency code.
	 * @param bool $refresh Whether to refresh rates first.
	 * @return ExchangeQuote Available exchange quote.
	 */
	public function quoteOrFail(string $sourceCurrency, string $targetCurrency, bool $refresh=false): ExchangeQuote {
		return $this->manager->quoteOrFail($sourceCurrency, $targetCurrency, $refresh, $this->overrides);
	}

	/**
	 * Returns an exchange quote only when rates satisfy a freshness bound.
	 *
	 * @param string $sourceCurrency Source ISO currency code.
	 * @param string $targetCurrency Target ISO currency code.
	 * @param int $maxAgeSeconds Maximum acceptable rate age.
	 * @param bool $refresh Whether to refresh rates first.
	 * @return ExchangeQuote Fresh exchange quote.
	 */
	public function quoteOrFailFresh(
		string $sourceCurrency,
		string $targetCurrency,
		int $maxAgeSeconds,
		bool $refresh=false
	): ExchangeQuote {
		return $this->manager->quoteOrFailFresh(
			$sourceCurrency,
			$targetCurrency,
			$maxAgeSeconds,
			$refresh,
			$this->overrides
		);
	}

	/**
	 * Converts an amount between currencies using this context's rate state.
	 *
	 * Unformatted results follow the manager/kernel numeric-string behavior, while
	 * formatted results use context formatting and optional Free display.
	 *
	 * @param float|int|null $amount Amount in source currency major units.
	 * @param string $sourceCurrency Source ISO currency code.
	 * @param string $targetCurrency Target ISO currency code.
	 * @param bool $formatted Whether to return formatted display output.
	 * @param bool $showFree Whether zero should display as Free.
	 * @return string|float Converted amount representation.
	 */
	public function convert(
		float|int|null $amount,
		string $sourceCurrency,
		string $targetCurrency,
		bool $formatted=false,
		bool $showFree=true
	): string|float {
		return $this->manager->convert($amount, $sourceCurrency, $targetCurrency, $formatted, $showFree, $this->overrides);
	}

	/**
	 * Converts a base-currency amount into this context's display currency.
	 *
	 * A currency argument overrides the context display currency for this call.
	 *
	 * @param float|int|null $amount Amount in base currency major units.
	 * @param bool $formatted Whether to return formatted display output.
	 * @param bool $showFree Whether zero should display as Free.
	 * @param string|null $currency Optional target display currency.
	 * @return string|float Converted amount representation.
	 */
	public function convertToDisplay(
		float|int|null $amount,
		bool $formatted=false,
		bool $showFree=true,
		?string $currency=null
	): string|float {
		return $this->manager->convertToDisplay($amount, $formatted, $showFree, $currency, $this->overrides);
	}

	/**
	 * Converts an amount from its original currency into this context's base currency.
	 *
	 * @param float|int|null $amount Amount in original currency major units.
	 * @param string $originalCurrency Source ISO currency code.
	 * @param bool $formatted Whether to return formatted display output.
	 * @param bool $showFree Whether zero should display as Free.
	 * @return string|float Converted base-currency representation.
	 */
	public function convertToBase(
		float|int|null $amount,
		string $originalCurrency,
		bool $formatted=false,
		bool $showFree=true
	): string|float {
		return $this->manager->convertToBase($amount, $originalCurrency, $formatted, $showFree, $this->overrides);
	}

	/**
	 * Creates a Money value using this context's manager and effective currency state.
	 *
	 * Missing currency defaults to the context display currency.
	 *
	 * @param float|int|null $amount Amount in major currency units.
	 * @param string|null $currency Optional ISO currency code.
	 * @return Money Immutable money value.
	 */
	public function money(float|int|null $amount, ?string $currency=null): Money {
		return $this->manager->money($amount, $currency, $this->overrides);
	}

	/**
	 * Converts a Money value to a target currency.
	 *
	 * @param Money $money Source money value.
	 * @param string $targetCurrency Target ISO currency code.
	 * @param bool $refresh Whether to refresh rates first.
	 * @return Money Converted money value.
	 */
	public function convertMoney(Money $money, string $targetCurrency, bool $refresh=false): Money {
		return $this->manager->convertMoney($money, $targetCurrency, $refresh, $this->overrides);
	}

	/**
	 * Converts a Money value only when rates satisfy a freshness bound.
	 *
	 * @param Money $money Source money value.
	 * @param string $targetCurrency Target ISO currency code.
	 * @param int $maxAgeSeconds Maximum acceptable rate age.
	 * @param bool $refresh Whether to refresh rates first.
	 * @return Money Converted money value using fresh rates.
	 */
	public function convertMoneyOrFailFresh(
		Money $money,
		string $targetCurrency,
		int $maxAgeSeconds,
		bool $refresh=false
	): Money {
		return $this->manager->convertMoneyOrFailFresh(
			$money,
			$targetCurrency,
			$maxAgeSeconds,
			$refresh,
			$this->overrides
		);
	}

	/**
	 * Converts a scalar amount only when rates satisfy a freshness bound.
	 *
	 * Unlike convert(), this fail-fast method returns a float amount rather than a
	 * formatted or numeric-string representation.
	 *
	 * @param float|int|null $amount Amount in source currency major units.
	 * @param string $sourceCurrency Source ISO currency code.
	 * @param string $targetCurrency Target ISO currency code.
	 * @param int $maxAgeSeconds Maximum acceptable rate age.
	 * @param bool $refresh Whether to refresh rates first.
	 * @return float Converted rounded amount.
	 */
	public function convertOrFailFresh(
		float|int|null $amount,
		string $sourceCurrency,
		string $targetCurrency,
		int $maxAgeSeconds,
		bool $refresh=false
	): float {
		return $this->manager->convertOrFailFresh(
			$amount,
			$sourceCurrency,
			$targetCurrency,
			$maxAgeSeconds,
			$refresh,
			$this->overrides
		);
	}

	/**
	 * Converts a Money value into a StoredMoney representation for persistence.
	 *
	 * The base currency defaults to this context's base currency unless supplied.
	 *
	 * @param Money $money Money value to persist.
	 * @param string|null $baseCurrency Optional storage base currency.
	 * @param bool $refresh Whether to refresh rates first.
	 * @return StoredMoney Persistable money representation.
	 */
	public function storeMoney(
		Money $money,
		?string $baseCurrency=null,
		bool $refresh=false
	): StoredMoney {
		return $this->manager->storeMoney($money, $baseCurrency, $refresh, $this->overrides);
	}

	/**
	 * Converts a Money value into StoredMoney only with sufficiently fresh rates.
	 *
	 * @param Money $money Money value to persist.
	 * @param int $maxAgeSeconds Maximum acceptable rate age.
	 * @param string|null $baseCurrency Optional storage base currency.
	 * @param bool $refresh Whether to refresh rates first.
	 * @return StoredMoney Persistable money representation using fresh rates.
	 */
	public function storeMoneyOrFailFresh(
		Money $money,
		int $maxAgeSeconds,
		?string $baseCurrency=null,
		bool $refresh=false
	): StoredMoney {
		return $this->manager->storeMoneyOrFailFresh(
			$money,
			$maxAgeSeconds,
			$baseCurrency,
			$refresh,
			$this->overrides
		);
	}

	/**
	 * Splits an amount into equal rounded parts for a currency.
	 *
	 * @param float|int|null $amount Amount in major currency units.
	 * @param string $currency ISO currency code.
	 * @param int $parts Number of parts to produce.
	 * @param bool $cash Whether cash-rounding rules should apply.
	 * @return array<int, float> Rounded split amounts.
	 */
	public function splitAmount(float|int|null $amount, string $currency, int $parts, bool $cash=false): array {
		return $this->manager->splitAmount($amount, $currency, $parts, $cash, $this->overrides);
	}

	/**
	 * Allocates an amount proportionally across positive ratios.
	 *
	 * @param float|int|null $amount Amount in major currency units.
	 * @param string $currency ISO currency code.
	 * @param array<int|string, float|int|string> $ratios Positive allocation ratios keyed by bucket.
	 * @param bool $cash Whether cash-rounding rules should apply.
	 * @return array<int|string, float> Rounded allocated amounts.
	 */
	public function allocateAmount(float|int|null $amount, string $currency, array $ratios, bool $cash=false): array {
		return $this->manager->allocateAmount($amount, $currency, $ratios, $cash, $this->overrides);
	}

	/**
	 * Returns override state with one key applied or removed.
	 *
	 * Null clears the override so the manager falls back to process/global currency
	 * state for that key. Non-null values are stored verbatim because individual
	 * manager operations own validation for currency codes, locale fields, and
	 * symbol maps.
	 *
	 * @param string $key Override key understood by CurrencyManager.
	 * @param mixed $value Override value, or null to remove the key.
	 * @return array<string, mixed> Updated override map for a cloned context.
	 */
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
