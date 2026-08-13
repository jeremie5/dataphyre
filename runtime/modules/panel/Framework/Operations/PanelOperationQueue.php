<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Queue boundary for submitting and reserving persistent operations. */
interface PanelOperationQueue {
	public function enqueue(PanelOperationRecord $record): PanelOperationRecord;
	public function reserve(?string $queue=null, string $worker='local'): ?PanelOperationRecord;
	public function release(PanelOperationRecord $record, ?int $delaySeconds=null): PanelOperationRecord;
	public function acknowledge(PanelOperationRecord $record): PanelOperationRecord;
	public function size(?string $queue=null): int;
}
