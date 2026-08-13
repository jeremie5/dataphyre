<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Host-replaceable optimistic store for replay, cancellation, and audit state. */
interface PanelAgentWorkflowStore {
	public function revision(): int;
	/** @return list<PanelAgentAuditReceipt> */ public function audit(): array;
	public function lastAuditHash(): string;
	public function append(PanelAgentAuditReceipt $receipt, int $expectedRevision): int;
	public function lookup(string $planHash, string $scopeFingerprint, string $idempotencyKey, string $requestHash): ?PanelAgentExecutionResult;
	/** @param list<string> $nonces */
	public function reserve(string $planHash, string $scopeFingerprint, string $idempotencyKey, string $requestHash, array $nonces, int $expectedRevision): PanelAgentStoreReservation;
	/** Atomically renews the current fenced owner for at least the requested duration. */
	public function renew(string $reservationId, int $expectedLeaseRevision, int $minimumLeaseSeconds): PanelAgentStoreReservation;
	/** @param array<string,mixed> $auditDetails */
	public function complete(string $reservationId, PanelAgentExecutionResult $result, PanelAgentRequestContext $actor, string $auditEvent, string $auditCode, array $auditDetails, int $occurredAt, int $expectedRevision): PanelAgentExecutionResult;
	public function cancel(string $planHash, PanelAgentAuditReceipt $receipt, int $expectedRevision): int;
	public function cancelled(string $planHash): bool;
}
