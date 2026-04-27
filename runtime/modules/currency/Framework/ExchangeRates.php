<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency;

use Dataphyre\Currency\Exceptions\UnknownExchangeRateException;

final class ExchangeRates implements \Countable, \JsonSerializable {

	public function __construct(
		private readonly string $base_currency,
		private readonly string $source,
		private readonly int $time,
		private readonly array $rates,
		private readonly array $minor_units=[]
	){}

	public static function fromArray(array $exchange_rate_data, ?string $base_currency=null, array $minor_units=[]): self {
		return new self(
			mb_strtoupper(trim((string)($base_currency ?? 'USD'))),
			(string)($exchange_rate_data['source'] ?? ''),
			self::normalizeTimestamp($exchange_rate_data['time'] ?? null),
			self::normalizeRates($exchange_rate_data['data'] ?? []),
			self::normalizeMinorUnits($minor_units)
		);
	}

	public function baseCurrency(): string {
		return $this->base_currency;
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

	public function has(string $currency): bool {
		return array_key_exists(mb_strtoupper(trim($currency)), $this->rates);
	}

	public function rate(string $currency): ?float {
		$currency=mb_strtoupper(trim($currency));
		return isset($this->rates[$currency]) ? (float)$this->rates[$currency] : null;
	}

	public function rates(): array {
		return $this->rates;
	}

	public function currencies(): array {
		return array_keys($this->rates);
	}

	public function count(): int {
		return count($this->rates);
	}

	public function minorUnits(string $currency): int {
		$currency=mb_strtoupper(trim($currency));
		return $this->minor_units[$currency] ?? 2;
	}

	public function snapshot(?CurrencyManager $manager=null, array $overrides=[]): ExchangeSnapshot {
		return new ExchangeSnapshot($this, $manager ?? CurrencyManager::instance(), $overrides);
	}

	public function snapshotOrFail(int $max_age_seconds, ?CurrencyManager $manager=null, array $overrides=[]): ExchangeSnapshot {
		return $this->snapshot($manager, $overrides)->assertFresh($max_age_seconds);
	}

	public function convert(float|int|null $amount, string $source_currency, string $target_currency): float {
		return $this->quoteOrFail($source_currency, $target_currency)->convert($amount);
	}

	public function quote(string $source_currency, string $target_currency): ?ExchangeQuote {
		$source_currency=mb_strtoupper(trim($source_currency));
		$target_currency=mb_strtoupper(trim($target_currency));
		if($source_currency==='' || $target_currency===''){
			return null;
		}
		if($source_currency===$target_currency){
			return new ExchangeQuote(
				$this->base_currency,
				$source_currency,
				$target_currency,
				$this->minorUnits($source_currency),
				$this->minorUnits($target_currency),
				1.0,
				$this->source,
				$this->time
			);
		}
		$source_multiplier=$this->rate($source_currency);
		$target_multiplier=$this->rate($target_currency);
		if($source_multiplier===null || $target_multiplier===null || $source_multiplier<=0){
			return null;
		}
		return new ExchangeQuote(
			$this->base_currency,
			$source_currency,
			$target_currency,
			$this->minorUnits($source_currency),
			$this->minorUnits($target_currency),
			$target_multiplier/$source_multiplier,
			$this->source,
			$this->time
		);
	}

	public function quoteOrFail(string $source_currency, string $target_currency): ExchangeQuote {
		$quote=$this->quote($source_currency, $target_currency);
		if($quote===null){
			throw UnknownExchangeRateException::forPair(
				mb_strtoupper(trim($source_currency)),
				mb_strtoupper(trim($target_currency)),
				$this->source
			);
		}
		return $quote;
	}

	public function convertOrFail(float|int|null $amount, string $source_currency, string $target_currency): float {
		return $this->quoteOrFail($source_currency, $target_currency)->convert($amount);
	}

	public function toArray(): array {
		return [
			'base_currency'=>$this->base_currency,
			'source'=>$this->source,
			'time'=>$this->time,
			'data'=>$this->rates,
			'minor_units'=>$this->minor_units,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	private static function normalizeRates(mixed $rates): array {
		if(!is_array($rates)){
			return [];
		}
		$normalized=[];
		foreach($rates as $currency=>$rate){
			if(!is_string($currency) || !is_numeric($rate)){
				continue;
			}
			$currency=mb_strtoupper(trim($currency));
			$normalized[$currency]=(float)$rate;
		}
		return $normalized;
	}

	private static function normalizeTimestamp(mixed $timestamp): int {
		if(is_int($timestamp)){
			return $timestamp>0 ? $timestamp : time();
		}
		if(is_numeric($timestamp)){
			$timestamp=(int)$timestamp;
			return $timestamp>0 ? $timestamp : time();
		}
		if(is_string($timestamp) && trim($timestamp)!==''){
			$parsed=strtotime($timestamp);
			if($parsed!==false){
				return $parsed;
			}
		}
		return time();
	}

	private static function normalizeMinorUnits(array $minor_units): array {
		$normalized=[];
		foreach($minor_units as $currency=>$precision){
			if(!is_string($currency) || !is_numeric($precision)){
				continue;
			}
			$normalized[mb_strtoupper(trim($currency))]=max(0, (int)$precision);
		}
		return $normalized;
	}

}
