<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Host/runtime seam for deadlines, request identities, cancellability, and retry waiting. */
interface PanelHttpDataSourceRuntime {
	public function nowMilliseconds(): int;
	public function requestId(): string;
	public function cancellationRequested(): bool;
	public function cancellationReason(): ?string;
	public function waitMilliseconds(int $milliseconds, int $deadlineUnixMilliseconds): bool;
}
