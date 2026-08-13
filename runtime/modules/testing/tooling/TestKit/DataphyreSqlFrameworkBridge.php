<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class DataphyreSqlFrameworkBridge {

	public function querySpec(): object {
		return new \Dataphyre\Database\QuerySpec();
	}

	public function schema(string $table, array $columns, array $projections=[], ?string $primary_key=null, array $casts=[]): object {
		return new \Dataphyre\Database\TableSchema($table, $columns, $projections, $primary_key, $casts);
	}

	public function definition(string $table): object {
		return \Dataphyre\Database\TableDefinition::for($table);
	}
}
