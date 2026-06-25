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
 * Hydrates database rows into a configured record class.
 *
 * The hydrator supports custom fromRow factories, Dataphyre Record subclasses,
 * and plain row-aware classes so repositories can preserve domain-specific
 * record behavior without changing query execution paths.
 */
final class ClassRecordHydrator implements RecordHydrator {

	/**
	 * Stores the target record class and optional repository metadata.
	 *
	 * @param class-string $recordClass Class instantiated or asked to hydrate rows.
	 * @param ?string $repositoryClass Repository class associated with generated Record instances.
	 * @param ?string $primaryKey Primary key fallback used when schema metadata is absent.
	 */
	public function __construct(
		private readonly string $recordClass,
		private readonly ?string $repositoryClass=null,
		private readonly ?string $primaryKey=null
	){}

	/**
	 * Hydrates one result row into the configured class.
	 *
	 * A static fromRow() factory takes precedence. Dataphyre Record subclasses
	 * receive repository and primary-key metadata; other classes receive the row
	 * and optional schema only.
	 *
	 * @param array<string, mixed> $row Column values returned by the database driver.
	 * @param ?TableSchema $schema Schema associated with the result set when known.
	 * @return mixed configured record object, DTO, or constructor result built from the database row.
	 */
	public function hydrate(array $row, ?TableSchema $schema=null): mixed {
		$primaryKey=$schema?->primaryKey() ?? $this->primaryKey;
		if(method_exists($this->recordClass, 'fromRow')){
			return $this->recordClass::fromRow($row, $schema, $this->repositoryClass, $primaryKey);
		}
		if($this->recordClass===Record::class || is_subclass_of($this->recordClass, Record::class)){
			return new ($this->recordClass)($row, $schema, $this->repositoryClass, $primaryKey);
		}
		return new ($this->recordClass)($row, $schema);
	}
}
