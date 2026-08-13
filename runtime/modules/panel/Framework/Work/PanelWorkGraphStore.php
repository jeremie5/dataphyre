<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Atomic tenant-scoped persistence boundary for the universal work graph. */
interface PanelWorkGraphStore {
	/** @return array<string,mixed> */
	public function read(string $tenantId):array;

	/** @param callable(array<string,mixed>&):mixed $mutation @param array<string,mixed> $event */
	public function transaction(string $tenantId,callable $mutation,string $type,array $event=[]):mixed;

	/** @return array{cursor:int,reset_required:bool,changes:list<array<string,mixed>>,snapshot:?array<string,mixed>} */
	public function changesSince(string $tenantId,int $cursor=0,int $limit=100):array;
}
