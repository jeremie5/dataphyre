<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Optional write side of the universal Panel data-source protocol.
 *
 * Implementations must negotiate their guarantees through the ordinary
 * capability manifest. A source is never considered writable merely because
 * it happens to expose adapter-specific helper methods.
 */
interface PanelMutableDataSource extends PanelDataSource {
	public function mutate(PanelDataMutation $mutation): PanelDataMutationReceipt;
	public function mutateBatch(PanelDataMutationBatch $batch): PanelDataMutationBatchResult;
}
