<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Transactional write seam for SQL adapters; read-only executors remain valid query adapters. */
interface PanelSqlMutationExecutor extends PanelSqlExecutor {
	/** @param array<string, null|bool|int|float|string> $parameters */
	public function execute(string $sql, array $parameters=[]): int;

	/**
	 * Execute a callback atomically. Implementations must isolate nested/host-owned
	 * transactions with a savepoint so a caught mutation failure cannot leak writes.
	 */
	public function transaction(callable $callback): mixed;
}
