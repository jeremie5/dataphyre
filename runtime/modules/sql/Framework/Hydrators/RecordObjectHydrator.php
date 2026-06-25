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

/**
 * Hydrates database result rows into Dataphyre Record objects.
 *
 * The hydrator preserves repository identity and primary-key metadata so records
 * created from low-level SQL results can still participate in repository-backed
 * refresh, persistence, and relation workflows.
 */
final class RecordObjectHydrator implements RecordHydrator {

	/**
	 * Creates a record object hydrator instance.
	 *
	 * Constructor parameters define the immutable or initial runtime contract for this value object/service.
	 */
	public function __construct(
		private readonly ?string $repositoryClass=null,
		private readonly ?string $primaryKey=null
	){}

	/**
	 * Wraps a raw row in a Record with schema and repository context.
	 *
	 * The schema primary key takes precedence when present; the constructor
	 * fallback is used for callers hydrating rows before schema discovery.
	 *
	 * @param array<string, mixed> $row Column values returned by the database driver.
	 * @param ?TableSchema $schema Optional table schema associated with the row.
	 * @return Record Record object carrying the row, schema, repository class, and primary key.
	 */
	public function hydrate(array $row, ?TableSchema $schema=null): mixed {
		return new Record($row, $schema, $this->repositoryClass, $schema?->primaryKey() ?? $this->primaryKey);
	}
}
