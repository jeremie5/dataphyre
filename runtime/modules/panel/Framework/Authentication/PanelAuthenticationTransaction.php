<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Multi-record atomic authentication persistence boundary. */
interface PanelAuthenticationTransaction {
	public function get(string $collection, string $id): ?PanelAuthenticationRecord;
	public function create(PanelAuthenticationRecord $record): PanelAuthenticationRecord;
	public function save(PanelAuthenticationRecord $record, ?int $expectedRevision=null): PanelAuthenticationRecord;
	/** @param array<string,mixed> $criteria @return list<PanelAuthenticationRecord> */
	public function all(string $collection, array $criteria=[]): array;
	public function delete(string $collection, string $id): bool;
}
