<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database\Contracts;

use Dataphyre\Database\TableSchema;

/**
 * Converts database rows into domain records for repository consumers.
 *
 * Implementations may return arrays, DTOs, active records, or any other domain
 * representation. The optional schema lets hydrators apply table metadata such
 * as casts, key names, or generated-column hints without coupling callers to a
 * concrete record type.
 */
interface RecordHydrator {

	/**
	 * Hydrates one selected row into its runtime representation.
	 *
	 * @param array<string,mixed> $row Associative database row.
	 * @param ?TableSchema $schema Optional table schema associated with the row.
	 * @return mixed record object, DTO, array, or domain value produced for the selected row.
	 */
	public function hydrate(array $row, ?TableSchema $schema=null): mixed;
}
