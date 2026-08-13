<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	use Dataphyre\Test\RootpathSandbox;

	require_once __DIR__.'/fulltext_external_engine_test_helpers.php';

	if(!defined('ROOTPATH')){
		define('ROOTPATH', [
			'dataphyre'=>dirname(__DIR__, 4).'/cache/unit-test-fulltext/fulltext-kernel-'.getmypid().'/',
		]);
	}

	if(!function_exists(__NAMESPACE__.'\\tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}

	if(!function_exists(__NAMESPACE__.'\\dp_define_module_config')){
		/** @param array<string,mixed> $defaults */
		function dp_define_module_config(string $module, string $constant, array $defaults): void {
			if(defined($constant)){
				return;
			}
			$defaults['fs_index_entry_count']=1;
			$defaults['fs_index_entry_count_for_sql']=1;
			$defaults['external_engines']=[
				'elastic'=>['url'=>'https://elastic.kernel.test'],
				'vespa'=>[
					'query_url'=>'https://vespa-query.kernel.test',
					'config_url'=>'https://vespa-config.kernel.test',
					'application_root'=>ROOTPATH['dataphyre'].'vespa-applications',
					'archive_class'=>FulltextVespaArchiveFake::class,
					'prepare_max_attempts'=>1,
					'prepare_retry_delay'=>0,
				],
			];
			define($constant, $defaults);
		}
	}

	/** Controls extension-dependent branches without changing the host PHP build. */
	final class FulltextKernelExtensions {
		private static bool $sqlite=true;

		public static function sqlite(bool $available): void {
			self::$sqlite=$available;
		}

		public static function hasSqlite(): bool {
			return self::$sqlite;
		}
	}

	/** Lets the isolated legacy kernel exercise SQLite branches without an extension dependency. */
	function extension_loaded(string $extension): bool {
		return strtolower($extension)==='sqlite3'
			? FulltextKernelExtensions::hasSqlite()
			: \extension_loaded($extension);
	}

	/** Deterministic SQL boundary for the legacy SQL backend. */
	final class FulltextKernelSql {
		/** @var array<string,list<mixed>> */
		private static array $responses=[];
		/** @var list<array{operation:string,arguments:array<int,mixed>}> */
		private static array $calls=[];

		public static function reset(): void {
			FulltextKernelExtensions::sqlite(true);
			self::$responses=[];
			self::$calls=[];
		}

		public static function respond(string $operation, mixed ...$responses): void {
			self::$responses[$operation]=array_merge(self::$responses[$operation] ?? [], $responses);
		}

		/** @param array<int,mixed> $arguments */
		public static function execute(string $operation, array $arguments, mixed $default=true): mixed {
			self::$calls[]=['operation'=>$operation, 'arguments'=>$arguments];
			return !empty(self::$responses[$operation])
				? array_shift(self::$responses[$operation])
				: $default;
		}

		/** @return list<array{operation:string,arguments:array<int,mixed>}> */
		public static function calls(): array {
			return self::$calls;
		}
	}

	function sql_query(mixed $query=null, mixed $variables=null, mixed $buffered=null, mixed $cache=null): mixed {
		return FulltextKernelSql::execute('query', func_get_args());
	}

	function sql_select(mixed $fields=null, mixed $location=null, mixed $parameters=null, mixed $variables=null, mixed $fetchAll=null): mixed {
		return FulltextKernelSql::execute('select', func_get_args(), []);
	}

	function sql_insert(mixed $location=null, mixed $fields=null, mixed $parameters=null, mixed $checkConstraints=null): mixed {
		return FulltextKernelSql::execute('insert', func_get_args());
	}

	function sql_update(mixed $location=null, mixed $fields=null, mixed $parameters=null, mixed $variables=null, mixed $checkConstraints=null): mixed {
		return FulltextKernelSql::execute('update', func_get_args());
	}

	function sql_delete(mixed $location=null, mixed $parameters=null, mixed $variables=null): mixed {
		return FulltextKernelSql::execute('delete', func_get_args());
	}

	/** Owns only the disposable filesystem used by one isolated kernel worker. */
	final class FulltextKernelWorkspace {
		public static function root(): string {
			return RootpathSandbox::root('dataphyre');
		}

		public static function reset(): void {
			RootpathSandbox::reset('dataphyre');
			core::scriptFilesystem();
			FulltextVespaCoreIo::reset();
			FulltextVespaArchiveFake::reset();
			FulltextKernelSql::reset();
			\dataphyre\fulltext_engine\FulltextCurlTransport::reset();
			if(method_exists(\SQLite3::class, 'reset')){
				\SQLite3::reset();
			}
		}

		/** @param array<string,mixed>|string $manifest */
		public static function manifest(array|string $manifest): string {
			$path=self::root().'config/fulltext_engine/indexes.json';
			$directory=dirname($path);
			if(!is_dir($directory)){
				mkdir($directory, 0777, true);
			}
			file_put_contents($path, is_array($manifest) ? json_encode($manifest) : $manifest);
			return $path;
		}

		/** @param array<string,mixed> $entries */
		public static function jsonShard(string $index, int $shard, array|string $entries): string {
			$directory=self::root().'fulltext_indexes/json/'.$index;
			if(!is_dir($directory)){
				mkdir($directory, 0777, true);
			}
			$path=$directory.'/'.$shard;
			file_put_contents($path, is_array($entries) ? json_encode($entries) : $entries);
			return $path;
		}

		/**
		 * Replaces one SQLite shard through the active native or fallback protocol.
		 *
		 * @param array<string,string> $entries Raw JSON payloads keyed by primary key.
		 */
		public static function sqliteShard(string $index, int $shard, array $entries): string {
			$path=self::root().'fulltext_indexes/sqlite/'.$index.'/'.$shard.'.db';
			if(method_exists(\SQLite3::class, 'replaceRows')){
				\SQLite3::replaceRows($path, $entries);
				return $path;
			}

			$db=new \SQLite3($path);
			$db->exec('CREATE TABLE IF NOT EXISTS entries (primary_key TEXT, index_value TEXT)');
			$db->exec('DELETE FROM entries');
			foreach($entries as $primaryKey=>$payload){
				$statement=$db->prepare('INSERT INTO entries (primary_key, index_value) VALUES (:primary_key_value, :values_json)');
				$statement->bindValue(':primary_key_value', $primaryKey);
				$statement->bindValue(':values_json', $payload);
				$statement->execute();
			}
			$db->close();
			return $path;
		}
	}
}

namespace {
	if(!defined('SQLITE3_ASSOC')){
		define('SQLITE3_ASSOC', 1);
	}

	if(!defined('DP_FULLTEXT_SQLITE3_TEST_STUB_LOADED') && !class_exists(SQLite3::class, false)){
		define('DP_FULLTEXT_SQLITE3_TEST_STUB_LOADED', true);
		/** Minimal in-memory SQLite protocol used only by isolated fulltext tests. */
		final class SQLite3 {
			/** @var array<string,array<string,string>> */
			private static array $databases=[];

			public function __construct(private string $path) {
				$directory=dirname($path);
				if(!is_dir($directory)){
					mkdir($directory, 0777, true);
				}
				if(!file_exists($path)){
					file_put_contents($path, 'sqlite-test-double');
				}
				self::$databases[$path] ??=[];
			}

			public static function reset(): void {
				self::$databases=[];
			}

			public function exec(string $query): bool {
				return true;
			}

			public function prepare(string $query): SQLite3Stmt {
				return new SQLite3Stmt($this->path, $query);
			}

			public function close(): bool {
				return true;
			}

			/** @return array<string,string> */
			public static function rows(string $path): array {
				return self::$databases[$path] ?? [];
			}

			/** @param array<string,string> $rows */
			public static function replaceRows(string $path, array $rows): void {
				self::$databases[$path]=$rows;
			}
		}

		final class SQLite3Stmt {
			/** @var array<string,mixed> */
			private array $bindings=[];

			public function __construct(private string $path, private string $query) {}

			public function bindValue(string $name, mixed $value): bool {
				$this->bindings[$name]=$value;
				return true;
			}

			public function execute(): SQLite3Result|false {
				$rows=SQLite3::rows($this->path);
				$primary=(string)($this->bindings[':primary_key_value'] ?? '');
				if(str_starts_with($this->query, 'SELECT COUNT(*)') && str_contains($this->query, 'WHERE')){
					return new SQLite3Result([['count'=>array_key_exists($primary, $rows) ? 1 : 0]]);
				}
				if(str_starts_with($this->query, 'SELECT COUNT(*)')){
					return new SQLite3Result([['count'=>count($rows)]]);
				}
				if(str_starts_with($this->query, 'SELECT *')){
					$materialized=[];
					foreach($rows as $key=>$value){
						$materialized[]=['primary_key'=>$key, 'index_value'=>$value];
					}
					return new SQLite3Result($materialized);
				}
				if(str_starts_with($this->query, 'INSERT')){
					$rows[$primary]=(string)($this->bindings[':values_json'] ?? '{}');
				}
				elseif(str_starts_with($this->query, 'UPDATE') && array_key_exists($primary, $rows)){
					$rows[$primary]=(string)($this->bindings[':values_json'] ?? '{}');
				}
				elseif(str_starts_with($this->query, 'DELETE')){
					unset($rows[$primary]);
				}
				SQLite3::replaceRows($this->path, $rows);
				return new SQLite3Result([]);
			}
		}

		final class SQLite3Result {
			/** @param list<array<string,mixed>> $rows */
			public function __construct(private array $rows) {}

			public function fetchArray(int $mode=SQLITE3_ASSOC): array|false {
				return array_shift($this->rows) ?? false;
			}
		}
	}
}
