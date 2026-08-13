<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Optional change-feed extension for live Panel data sources. */
interface PanelSubscribableDataSource extends PanelDataSource {
	/** @return list<PanelDataChange> */
	public function changes(int $afterSequence=0, int $limit=100): array;
	public function subscribe(int $afterSequence=0): PanelDataSubscription;
}
