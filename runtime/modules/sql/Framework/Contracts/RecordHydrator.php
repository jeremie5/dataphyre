<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database\Contracts;

use Dataphyre\Database\TableSchema;

interface RecordHydrator {

	public function hydrate(array $row, ?TableSchema $schema=null): mixed;
}
