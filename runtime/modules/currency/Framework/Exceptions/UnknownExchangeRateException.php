<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency\Exceptions;

/**
 * Reports that no exchange rate could be resolved for a currency pair.
 *
 * The exception message includes the source and target currency codes and, when
 * available, the provider source that failed to supply the rate. It carries no
 * retry behavior; callers decide whether to fall back or abort conversion.
 */
final class UnknownExchangeRateException extends \RuntimeException {

	/**
	 * Creates an exception for a missing source-to-target exchange rate.
	 *
	 * @param string $sourceCurrency Currency code being converted from.
	 * @param string $targetCurrency Currency code being converted to.
	 * @param ?string $providerSource Optional provider source involved in the lookup.
	 * @return self Exception with a conversion-specific message.
	 */
	public static function forPair(string $sourceCurrency, string $targetCurrency, ?string $providerSource=null): self {
		$message='No exchange rate is available for '.$sourceCurrency.' -> '.$targetCurrency;
		if($providerSource!==null && trim($providerSource)!==''){
			$message.=' from source '.$providerSource;
		}
		return new self($message);
	}
}
