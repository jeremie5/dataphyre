<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\TableQuery;
use Dataphyre\Database\TableRepository;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/sql_framework_test_helpers.php';

final class DpStrictReadNoRowState {
	public static string $mode='miss';
}

if(!class_exists('dataphyre\\sql', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class sql {
		private static ?array $lastError=null;
		public static function clear_last_query_error(): void { self::$lastError=null; }
		public static function last_query_error(): ?array { return self::$lastError; }
		public static function set_test_error(?array $error): void { self::$lastError=$error; }
		public static function hydrate_missing_structure_from_definition(string $table): bool { return false; }
		public static function invalidate_cache(string|array $table): bool { return true; }
	}');
}

if(!function_exists('sql_select')){
	function sql_select(mixed ...$arguments): mixed {
		return match(DpStrictReadNoRowState::$mode){
			'miss'=>(static function(): false {
				\dataphyre\sql::set_test_error(null);
				return false;
			})(),
			'failure'=>(static function(): false {
				\dataphyre\sql::set_test_error(['message'=>'connection lost']);
				return false;
			})(),
			'malformed'=>true,
			default=>['id'=>7, 'name'=>'Ada'],
		};
	}
}

require_once __DIR__.'/../Framework/Concerns/TransformsRows.php';
require_once __DIR__.'/../Framework/TableQuery.php';

final class DpStrictReadNoRowRepository extends TableRepository {
	protected static function table(): string { return 'strict_read_records'; }
}

test('strict repository and table reads distinguish PostgreSQL no-row false from query failure', static function(Context $t): void {
	DpStrictReadNoRowState::$mode='miss';
	$t->same(null, DpStrictReadNoRowRepository::firstOrFailOnReadError('*', null, false));
	$t->same(null, (new TableQuery('strict_read_records'))->failOnReadError()->first('*', false));
	$t->same([], (new TableQuery('strict_read_records'))->failOnReadError()->get('*', false));

	DpStrictReadNoRowState::$mode='failure';
	$t->throws(
		static fn()=>DpStrictReadNoRowRepository::firstOrFailOnReadError('*', null, false),
		RuntimeException::class,
	);
	$t->throws(
		static fn()=>(new TableQuery('strict_read_records'))->failOnReadError()->first('*', false),
		RuntimeException::class,
	);

	DpStrictReadNoRowState::$mode='malformed';
	$t->throws(
		static fn()=>DpStrictReadNoRowRepository::firstOrFailOnReadError('*', null, false),
		RuntimeException::class,
	);

	DpStrictReadNoRowState::$mode='row';
	$t->same(7, DpStrictReadNoRowRepository::firstOrFailOnReadError('*', null, false)['id'] ?? null);
})->tag('sql','repository','table-query','strict-read','postgresql','regression')->group('framework-regression');
