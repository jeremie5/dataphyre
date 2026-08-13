<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class DpSqlMainQueueMysqlResult {
		public function fetch_all(int $mode=0): array {return [['count'=>1]];}
	}
	final class DpSqlMainQueueMysqlStatement {
		public int $field_count=1;
		public int $insert_id=0;
		public int $affected_rows=1;
		public function bind_param(string $types,mixed &...$values): bool {return true;}
		public function execute(): bool {return true;}
		public function get_result(): object {return new DpSqlMainQueueMysqlResult();}
		public function close(): void {}
	}
	final class DpSqlMainQueueMysqlConnection {
		public function prepare(string $query): object {return new DpSqlMainQueueMysqlStatement();}
		public function begin_transaction(): bool {return true;}
		public function commit(): bool {return true;}
		public function rollback(): bool {return true;}
	}

	final class DpSqlMainQueuePgConnection {}
	final class DpSqlMainQueuePgResult {
		public int $index=0;
		public array $rows=[['count'=>'1']];
		public array $types=['count'=>'int8'];
	}
	if(!function_exists(__NAMESPACE__.'\\pg_prepare')){ function pg_prepare(object $connection,string $name,string $query): bool {return true;} }
	if(!function_exists(__NAMESPACE__.'\\pg_execute')){ function pg_execute(object $connection,string $name,array $vars): object {return new DpSqlMainQueuePgResult();} }
	if(!function_exists(__NAMESPACE__.'\\pg_num_fields')){ function pg_num_fields(object $result): int {return 1;} }
	if(!function_exists(__NAMESPACE__.'\\pg_fetch_all')){ function pg_fetch_all(object $result,int $mode=0): array {return $result->rows;} }
	if(!function_exists(__NAMESPACE__.'\\pg_field_num')){ function pg_field_num(object $result,string|int $field): int {return 0;} }
	if(!function_exists(__NAMESPACE__.'\\pg_field_type')){ function pg_field_type(object $result,int $field): string {return 'int8';} }
	if(!function_exists(__NAMESPACE__.'\\pg_free_result')){ function pg_free_result(object $result): bool {return true;} }

	final class DpSqlMainQueueSqliteResult {
		private int $index=0;
		public function numColumns(): int {return 1;}
		public function fetchArray(int $mode): array|false {return $this->index++===0 ? ['count'=>1] : false;}
	}
	final class DpSqlMainQueueSqliteStatement {
		public function bindValue(int $index,mixed $value,int $type=0): bool {return true;}
		public function execute(): object {return new DpSqlMainQueueSqliteResult();}
		public function close(): void {}
	}
	class SQLite3 {
		public function prepare(string $query): object {return new DpSqlMainQueueSqliteStatement();}
		public function exec(string $query): bool {return true;}
		public function changes(): int {return 1;}
		public function lastErrorMsg(): string {return '';}
	}
}

namespace {
	if(!defined('MYSQLI_ASSOC')){define('MYSQLI_ASSOC',1);}
	if(!defined('PGSQL_ASSOC')){define('PGSQL_ASSOC',1);}
	if(!defined('SQLITE3_ASSOC')){define('SQLITE3_ASSOC',1);}
	if(!defined('SQLITE3_NUM')){define('SQLITE3_NUM',2);}
	if(!defined('SQLITE3_TEXT')){define('SQLITE3_TEXT',3);}

	function dp_sql_kernel_queue_payload(): array {
		return ['count'=>[[
			'location'=>'items',
			'params'=>'WHERE id=?',
			'vars'=>[1],
			'callback'=>static function(mixed $result): void {},
		]]];
	}
}
