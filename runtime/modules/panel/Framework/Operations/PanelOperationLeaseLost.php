<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Raised when a worker attempts to use an expired, released, or superseded lease. */
final class PanelOperationLeaseLost extends \RuntimeException {
	public function __construct(private readonly string $operationId, string $reason='Operation lease is no longer owned.') {
		parent::__construct($reason);
	}
	public function operationId(): string { return $this->operationId; }
}
