<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class sql {
		public static array $calls=[];
		public static array $results=[];
		public static array $hydrateResults=[];

		public function __construct(){ self::$calls[]=['construct']; }
		private static function result(string $method, array $arguments, mixed $default=true): mixed {
			self::$calls[]=[$method, $arguments];
			return (self::$results[$method]??[])!==[] ? array_shift(self::$results[$method]) : $default;
		}
		public static function clear_last_query_error(): void { self::$calls[]=['clear']; }
		public static function hydrate_missing_structure_from_definition(?string $location): bool {
			self::$calls[]=['hydrate', $location];
			return self::$hydrateResults!==[] ? (bool)array_shift(self::$hydrateResults) : false;
		}
		public static function invalidate_cache(string $location): bool { self::$calls[]=['invalidate', $location]; return true; }
		public static function count(mixed ...$arguments): mixed { return self::result('count', $arguments, 1); }
		public static function select(mixed ...$arguments): mixed { return self::result('select', $arguments, []); }
		public static function delete(mixed ...$arguments): mixed { return self::result('delete', $arguments, 1); }
		public static function update(mixed ...$arguments): mixed { return self::result('update', $arguments, 1); }
		public static function insert(mixed ...$arguments): mixed { return self::result('insert', $arguments, 'id'); }
		public static function query(mixed ...$arguments): mixed { return self::result('query', $arguments, []); }
		public static function upsert(mixed ...$arguments): mixed { return self::result('upsert', $arguments, 1); }
		public static function transaction(mixed ...$arguments): mixed { return self::result('transaction', $arguments); }
		public static function begin(mixed ...$arguments): mixed { return self::result('begin', $arguments); }
		public static function commit(mixed ...$arguments): mixed { return self::result('commit', $arguments); }
		public static function rollback(mixed ...$arguments): mixed { return self::result('rollback', $arguments); }
		public static function table(mixed ...$arguments): mixed { return self::result('table', $arguments, 'physical_table'); }
		public static function define_table(mixed ...$arguments): mixed { return self::result('define_table', $arguments); }
		public static function table_definition(mixed ...$arguments): mixed { return self::result('table_definition', $arguments, 'definition'); }
		public static function table_schema(mixed ...$arguments): mixed { return self::result('table_schema', $arguments, 'schema'); }
		public static function registered_table_definitions(): array { return ['orders','users']; }
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	test('sql global facade covers retry recovery wrappers direct delegates and deferred definitions', static function(Context $t): void {
		$deferredRuns=$t->global('dp_sql_global_deferred_runs')->replace(0);
		$deferredDefinitions=$t->globalMap('dataphyre_deferred_sql_table_definitions')->replace([
			static function()use($deferredRuns): void { $deferredRuns->replace((int)$deferredRuns->value()+1); },
			'not-callable',
		]);
		$sqlRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime']??''), '/\\').'/modules/sql/';
		require_once $sqlRoot.'kernel/sql.global.php';
		\dataphyre\sql::$calls=[];
		\dataphyre\sql::$results=[];
		\dataphyre\sql::$hydrateResults=[];

		\dataphyre\sql::$results['count']=[5];
		$t->same(5, sql_count('orders'));
		\dataphyre\sql::$results['count']=[false];
		\dataphyre\sql::$hydrateResults=[false];
		$t->isFalse(sql_count('missing'));
		\dataphyre\sql::$results['count']=[false, 7];
		\dataphyre\sql::$hydrateResults=[true];
		$t->same(7, sql_count('recoverable'));

		\dataphyre\sql::$results['select']=[[['id'=>1]]];
		$t->same([['id'=>1]], sql_select('*', 'orders'));
		\dataphyre\sql::$results['delete']=[2];
		$t->same(2, sql_delete('orders'));
		\dataphyre\sql::$results['update']=[3];
		$t->same(3, sql_update('orders', ['name'=>'updated']));
		\dataphyre\sql::$results['insert']=['insert-id'];
		$t->same('insert-id', sql_insert('orders', ['name'=>'new']));
		\dataphyre\sql::$results['query']=[false, ['retried'=>true]];
		\dataphyre\sql::$hydrateResults=[true];
		$t->same(['retried'=>true], sql_query('SELECT 1'));
		\dataphyre\sql::$results['upsert']=[4];
		$t->same(4, sql_upsert('orders', ['id'=>1]));

		$t->isTrue(sql_transaction(static fn(): bool=>true, 'primary'));
		$t->isTrue(sql_begin('primary'));
		$t->isTrue(sql_commit('primary'));
		$t->isTrue(sql_rollback('primary'));
		$t->same('physical_table', sql_table('logical'));
		$t->isTrue(sql_define_table('orders', 'definition.php', 'id'));
		$t->same('definition', sql_table_definition('orders'));
		$t->same('schema', sql_table_schema('orders'));
		$t->same(['orders','users'],sql_registered_table_definitions());
		$t->same(1,$deferredRuns->value());
		$t->same([],$deferredDefinitions->map());

		$invalidations=array_values(array_filter(\dataphyre\sql::$calls, static fn(array $call): bool=>$call[0]==='invalidate'));
		$t->same([['invalidate', 'recoverable']], $invalidations);
	})->tag('sql', 'sql-residual', 'coverage')->group('framework-coverage');
}
