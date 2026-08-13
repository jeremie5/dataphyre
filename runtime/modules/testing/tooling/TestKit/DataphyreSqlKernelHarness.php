<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class DataphyreSqlKernelHarness {

	public function __construct(private string $database_path, private string $cluster='sql') {}

	public function databasePath(): string {
		return $this->database_path;
	}

	public function query(string $sql, ?array $vars=null, bool $associative=true): mixed {
		return \sql_query($sql, $vars, $associative, false, false, false, null);
	}

	public function createTable(string $sql): bool {
		return $this->query($sql, null, true)!==false;
	}

	public function insert(string $table, array $fields): mixed {
		return \sql_insert($table, $fields, null, false, null);
	}

	public function select(array|string $columns, string $table, ?string $where=null, ?array $vars=null): mixed {
		return \sql_select($columns, $table, $where, $vars, true, false, null);
	}

	public function count(string $table, ?string $where=null, ?array $vars=null): int|bool {
		return \sql_count($table, $where, $vars, false, null);
	}

	public function update(string $table, string|array $fields, ?string $where=null, ?array $vars=null): int|bool|null {
		return \sql_update($table, $fields, $where, $vars, false, null);
	}

	public function delete(string $table, ?string $where=null, ?array $vars=null): int|bool|null {
		return \sql_delete($table, $where, $vars, false, null);
	}

	public function lastError(): ?array {
		return \dataphyre\sql::last_query_error();
	}
}
