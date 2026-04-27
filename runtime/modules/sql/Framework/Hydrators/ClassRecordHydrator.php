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

final class ClassRecordHydrator implements RecordHydrator {

	public function __construct(
		private readonly string $record_class,
		private readonly ?string $repository_class=null,
		private readonly ?string $primary_key=null
	){}

	public function hydrate(array $row, ?TableSchema $schema=null): mixed {
		$primary_key=$schema?->primaryKey() ?? $this->primary_key;
		if(method_exists($this->record_class, 'fromRow')){
			return $this->record_class::fromRow($row, $schema, $this->repository_class, $primary_key);
		}
		if(is_subclass_of($this->record_class, Record::class)){
			return new ($this->record_class)($row, $schema, $this->repository_class, $primary_key);
		}
		return new ($this->record_class)($row, $schema);
	}
}
