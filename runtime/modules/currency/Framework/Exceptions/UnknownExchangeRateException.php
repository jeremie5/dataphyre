<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency\Exceptions;

final class UnknownExchangeRateException extends \RuntimeException {

	public static function forPair(string $source_currency, string $target_currency, ?string $provider_source=null): self {
		$message='No exchange rate is available for '.$source_currency.' -> '.$target_currency;
		if($provider_source!==null && trim($provider_source)!==''){
			$message.=' from source '.$provider_source;
		}
		return new self($message);
	}
}
