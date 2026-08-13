<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Durable operation persistence with optimistic and atomic mutation semantics. */
interface PanelOperationStore {
	public function create(PanelOperationRecord $record): PanelOperationRecord;
	public function get(string $id): ?PanelOperationRecord;
	public function save(PanelOperationRecord $record, ?int $expectedRevision=null): PanelOperationRecord;
	/** @param callable(PanelOperationRecord): PanelOperationRecord $mutator */
	public function update(string $id, callable $mutator, ?int $expectedRevision=null): PanelOperationRecord;
	public function findByIdempotencyKey(string $key): ?PanelOperationRecord;
	/** @param array<string, mixed> $criteria @return list<PanelOperationRecord> */
	public function all(array $criteria=[], int $limit=100, int $offset=0): array;
	public function delete(string $id): bool;
}
