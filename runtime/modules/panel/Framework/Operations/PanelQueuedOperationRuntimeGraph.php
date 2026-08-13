<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Operation runtime graph that also owns a separately registered queue. */
interface PanelQueuedOperationRuntimeGraph extends PanelOperationRuntimeGraph {
	public function queue():PanelOperationQueue;
}
