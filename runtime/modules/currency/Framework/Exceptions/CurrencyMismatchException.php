<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Currency\Exceptions;

final class CurrencyMismatchException extends \InvalidArgumentException {

	public static function forOperation(string $operation, string $expected_currency, string $actual_currency): self {
		return new self(
			'Currency mismatch during '.$operation.': expected '.$expected_currency.', got '.$actual_currency
		);
	}
}
