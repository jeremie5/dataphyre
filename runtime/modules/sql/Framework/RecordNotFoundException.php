<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Carries query context for failed SQL record lookups.
 *
 * Repository and query helpers throw this when a single-record expectation is
 * not met while preserving table, key, criteria, or caller context for logging
 * and HTTP translation layers.
 */
final class RecordNotFoundException extends \RuntimeException {

	/**
	 * Creates a not-found exception with structured lookup context.
	 *
	 * @param string $message Human-readable lookup failure message.
	 * @param array<string,mixed> $context Lookup metadata such as table, id, filters, or repository class.
	 */
	public function __construct(
		string $message,
		private readonly array $context=[]
	){
		parent::__construct($message);
	}

	/**
	 * Returns structured lookup metadata captured with the exception.
	 *
	 *
	 * @return array<string,mixed> Lookup context supplied at construction.
	 */
	public function context(): array {
		return $this->context;
	}
}
