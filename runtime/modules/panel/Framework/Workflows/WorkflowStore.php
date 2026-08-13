<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Optimistic persistence boundary for workflow aggregates.
 */
interface WorkflowStore {
	public function create(WorkflowRecord $record): bool;
	public function load(string $definition, string $id): ?WorkflowRecord;
	public function compareAndSwap(WorkflowRecord $record, int $expectedVersion): bool;
	/** @return list<WorkflowRecord> */
	public function all(?string $definition=null): array;
}
