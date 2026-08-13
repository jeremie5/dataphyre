<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Passive queue dependency identity contract. */
interface PanelOperationQueueGraph {
	public function store():PanelOperationStore;
}
