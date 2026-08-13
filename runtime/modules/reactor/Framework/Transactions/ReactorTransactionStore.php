<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/** Persistence boundary for Reactor state, receipts, events, and offline work. */
interface ReactorTransactionStore {
	/** @return array{state:array<string,mixed>,version:int,updated_at:int} */
	public function load(string $component): array;

	/** Atomically commits when the stored version matches the expected version. */
	public function commit(string $component, int $expectedVersion, array $state, string $idempotencyKey, array $receipt, array $events=[]): bool;

	/** @return array<string,mixed>|null */
	public function receipt(string $component, string $idempotencyKey): ?array;

	public function enqueue(ReactorStateTransaction $transaction): void;

	/** @return list<array<string,mixed>> */
	public function queued(string $component, int $limit=100): array;

	public function dequeue(string $component, string $transactionId): bool;

	/** @return list<array<string,mixed>> */
	public function events(string $component, int $afterSequence=0, int $limit=100): array;
}
