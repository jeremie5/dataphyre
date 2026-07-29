<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Seeds;

/** Persistence contract for applied seed metadata and transaction boundaries. */
interface SeedLedger {
	public function ensureSchema(): void;
	/** @return array<string,array<string,mixed>> Records keyed by `id@version`. */
	public function all(): array;
	public function nextBatch(): int;
	/** @param array<string,mixed> $record */
	public function recordApplied(array $record): void;
	public function remove(string $id, int $version): void;
	public function transaction(callable $callback): mixed;
}
