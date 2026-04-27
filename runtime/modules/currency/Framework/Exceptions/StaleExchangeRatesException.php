<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency\Exceptions;

final class StaleExchangeRatesException extends \RuntimeException {

	public static function forSnapshot(?string $provider_source, int $time, int $age_seconds, int $max_age_seconds): self {
		$message='Exchange snapshot is stale';
		if($provider_source!==null && trim($provider_source)!==''){
			$message.=' for source '.$provider_source;
		}
		$message.='. Age '.$age_seconds.'s exceeds max age '.$max_age_seconds.'s';
		$message.=' (captured at '.gmdate('c', $time).')';
		return new self($message);
	}

	public static function forQuote(
		string $source_currency,
		string $target_currency,
		?string $provider_source,
		int $time,
		int $age_seconds,
		int $max_age_seconds
	): self {
		$message='Exchange quote '.$source_currency.' -> '.$target_currency.' is stale';
		if($provider_source!==null && trim($provider_source)!==''){
			$message.=' for source '.$provider_source;
		}
		$message.='. Age '.$age_seconds.'s exceeds max age '.$max_age_seconds.'s';
		$message.=' (captured at '.gmdate('c', $time).')';
		return new self($message);
	}
}
