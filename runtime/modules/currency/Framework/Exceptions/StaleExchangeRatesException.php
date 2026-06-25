<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency\Exceptions;

/**
 * Reports exchange-rate snapshots or quotes that exceed freshness limits.
 *
 * The exception message preserves source, age, maximum age, and capture time so
 * callers can surface stale-cache failures without carrying a separate diagnostic
 * array.
 */
final class StaleExchangeRatesException extends \RuntimeException {

	/**
	 * Creates an exception for a stale provider snapshot.
	 *
	 * Provider source text is included only when non-empty. The capture timestamp is
	 * rendered in UTC ISO-8601 form so logs and HTTP errors can be compared across
	 * hosts without timezone ambiguity.
	 *
	 * @param ?string $providerSource Provider or upstream source label.
	 * @param int $time Unix timestamp when the snapshot was captured.
	 * @param int $ageSeconds Current snapshot age.
	 * @param int $maxAgeSeconds Maximum allowed age.
	 * @return self Exception with a formatted stale-snapshot message.
	 */
	public static function forSnapshot(?string $providerSource, int $time, int $ageSeconds, int $maxAgeSeconds): self {
		$message='Exchange snapshot is stale';
		if($providerSource!==null && trim($providerSource)!==''){
			$message.=' for source '.$providerSource;
		}
		$message.='. Age '.$ageSeconds.'s exceeds max age '.$maxAgeSeconds.'s';
		$message.=' (captured at '.gmdate('c', $time).')';
		return new self($message);
	}

	/**
	 * Creates an exception for a stale exchange quote.
	 *
	 * @param string $sourceCurrency Source ISO currency code.
	 * @param string $targetCurrency Target ISO currency code.
	 * @param ?string $providerSource Provider or upstream source label.
	 * @param int $time Unix timestamp when the quote was captured.
	 * @param int $ageSeconds Current quote age.
	 * @param int $maxAgeSeconds Maximum allowed age.
	 * @return self Exception with a formatted stale-quote message.
	 */
	public static function forQuote(
		string $sourceCurrency,
		string $targetCurrency,
		?string $providerSource,
		int $time,
		int $ageSeconds,
		int $maxAgeSeconds
	): self {
		$message='Exchange quote '.$sourceCurrency.' -> '.$targetCurrency.' is stale';
		if($providerSource!==null && trim($providerSource)!==''){
			$message.=' for source '.$providerSource;
		}
		$message.='. Age '.$ageSeconds.'s exceeds max age '.$maxAgeSeconds.'s';
		$message.=' (captured at '.gmdate('c', $time).')';
		return new self($message);
	}
}
