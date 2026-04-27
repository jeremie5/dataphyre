<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database\Hydrators;

use Dataphyre\Database\Contracts\RecordHydrator;
use Dataphyre\Database\TableSchema;

final class CallbackRecordHydrator implements RecordHydrator {

	public function __construct(
		private readonly mixed $callback
	){}

	public function hydrate(array $row, ?TableSchema $schema=null): mixed {
		return ($this->callback)($row, $schema);
	}
}
