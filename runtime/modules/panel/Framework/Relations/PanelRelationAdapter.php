<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Storage-neutral relation workspace mutation boundary. */
interface PanelRelationAdapter {
	/** @return list<array<string,mixed>> */
	public function records(string|int $parentKey): array;
	/** @return list<array<string,mixed>> */
	public function available(string|int $parentKey): array;
	public function version(string|int $parentKey): int;
	/** @return array<string,mixed> */
	public function snapshot(string|int $parentKey): array;
	public function restore(string|int $parentKey, array $snapshot): void;
	public function attach(string|int $parentKey, string|int $relatedKey, array $pivot=[]): void;
	public function detach(string|int $parentKey, string|int $relatedKey): void;
	public function updatePivot(string|int $parentKey, string|int $relatedKey, array $pivot): void;
	/** @param list<string|int> $orderedKeys */
	public function reorder(string|int $parentKey, array $orderedKeys): void;
}
