<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Optimistic revision, identity, or idempotency conflict. */
final class PanelDataMutationConflict extends PanelDataMutationException {
	public function __construct(string $code='mutation_conflict',string $message='The data mutation conflicts with current state.',bool $retryable=false,?\Throwable $previous=null){ parent::__construct($code,$message,409,$retryable,$previous); }
}
