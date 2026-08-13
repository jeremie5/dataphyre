<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Host/event-loop seam checked before every broker read. */
interface PanelRealtimeCancellation {
	public function isCancellationRequested(): bool;
}
