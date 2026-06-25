<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Signals that a write lost an optimistic concurrency check.
 *
 * Repository and record save paths throw this when the expected version,
 * timestamp, or comparison columns no longer match the persisted row, allowing
 * callers to surface a conflict instead of silently overwriting newer data.
 */
final class OptimisticLockException extends \RuntimeException {

	/**
	 * Creates an optimistic lock failure with structured conflict context.
	 *
	 * @param string $message Human-readable conflict message.
	 * @param array<string, mixed> $context Table, key, expected value, actual value, or caller-specific conflict metadata.
	 */
	public function __construct(
		string $message,
		private readonly array $context=[]
	){
		parent::__construct($message);
	}

	/**
	 * Returns structured conflict context for logs, APIs, and tests.
	 *
	 * @return array<string, mixed> Metadata captured when the optimistic lock failed.
	 */
	public function context(): array {
		return $this->context;
	}
}
