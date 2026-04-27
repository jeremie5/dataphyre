<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database\Hydrators;

use Dataphyre\Database\Contracts\RecordHydrator;
use Dataphyre\Database\Record;
use Dataphyre\Database\TableSchema;

final class RecordObjectHydrator implements RecordHydrator {

	public function __construct(
		private readonly ?string $repository_class=null,
		private readonly ?string $primary_key=null
	){}

	public function hydrate(array $row, ?TableSchema $schema=null): mixed {
		return new Record($row, $schema, $this->repository_class, $schema?->primaryKey() ?? $this->primary_key);
	}
}
