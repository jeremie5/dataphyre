<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Adapter-neutral source contract consumed by Panel resources and relation workspaces. */
interface PanelDataSource {
	public function query(PanelDataQuery $query): PanelDataResult;
	public function find(string|int $id, ?PanelDataQuery $scope=null): mixed;
	/** @return array<string, bool|int|string|list<string>> */
	public function capabilities(): array;
}
