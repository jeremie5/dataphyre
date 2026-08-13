<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Mcp\Contracts;

/**
 * Produces one immutable, read-only view of contracts declared by a
 * Dataphyre source tree.
 */
interface ContractSource {
	/**
	 * @return array{
	 *   contracts:list<array<string,mixed>>,
	 *   declarations:list<array<string,mixed>>,
	 *   test_files:list<array<string,mixed>>,
	 *   diagnostics:array<string,mixed>
	 * }
	 */
	public function snapshot(): array;
}
