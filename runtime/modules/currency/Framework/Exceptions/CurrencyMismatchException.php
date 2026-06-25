<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency\Exceptions;

/**
 * Reports that a currency-specific operation received the wrong currency.
 *
 * The exception identifies the operation, expected currency, and actual currency
 * so callers can surface a domain error without guessing which monetary value
 * crossed the boundary incorrectly.
 */
final class CurrencyMismatchException extends \InvalidArgumentException {

	/**
	 * Creates an exception for an operation-level currency mismatch.
	 *
	 * @param string $operation Operation being performed.
	 * @param string $expectedCurrency Currency code required by the operation.
	 * @param string $actualCurrency Currency code received by the operation.
	 * @return self Exception with a currency-specific message.
	 */
	public static function forOperation(string $operation, string $expectedCurrency, string $actualCurrency): self {
		return new self(
			'Currency mismatch during '.$operation.': expected '.$expectedCurrency.', got '.$actualCurrency
		);
	}
}
