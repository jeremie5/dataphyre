<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Passive dependency identity contract used to reject split-brain operation runtimes. */
interface PanelOperationRuntimeGraph {
	public function store():PanelOperationStore;
	public function handlers():PanelOperationHandlerRegistry;
}
