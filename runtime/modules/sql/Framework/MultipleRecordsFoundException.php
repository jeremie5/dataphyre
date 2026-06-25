<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Signals that a query expected one record but matched more than one.
 *
 * Repository helpers throw this when uniqueness assumptions are violated,
 * preserving table, filter, and caller context so the ambiguity can be logged or
 * surfaced without discarding the original query intent.
 */
final class MultipleRecordsFoundException extends \RuntimeException {

	/**
	 * Creates the exception with structured query context.
	 *
	 * @param string $message Human-readable ambiguity message.
	 * @param array<string, mixed> $context Table, filter, matched count, or caller-specific query metadata.
	 */
	public function __construct(
		string $message,
		private readonly array $context=[]
	){
		parent::__construct($message);
	}

	/**
	 * Returns structured query context for diagnostics and tests.
	 *
	 * @return array<string, mixed> Metadata captured when multiple records were found.
	 */
	public function context(): array {
		return $this->context;
	}
}
