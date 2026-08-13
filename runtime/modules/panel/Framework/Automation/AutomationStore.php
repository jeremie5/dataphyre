<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Durable receipt and idempotency lookup boundary for automation execution.
 */
interface AutomationStore {
	public function save(AutomationReceipt $receipt): bool;
	public function get(string $receiptId): ?AutomationReceipt;
	public function findByIdempotency(string $action, string $idempotencyKey): ?AutomationReceipt;
	/** @return list<AutomationReceipt> */
	public function all(?string $action=null): array;
}
