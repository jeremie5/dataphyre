<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Mcp\Panel;

/** Supplies one execution-free snapshot of Panel capability source contracts. */
interface PanelCapabilitySource {
	/** @return array<string,mixed> */
	public function snapshot(): array;
}
