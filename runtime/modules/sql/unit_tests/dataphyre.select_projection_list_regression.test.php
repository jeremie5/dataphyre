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
use Dataphyre\Database\TableSchema;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\test;

require_once __DIR__.'/sql_framework_test_helpers.php';
require_once __DIR__.'/../Framework/ExecutionTrace.php';
require_once __DIR__.'/../Framework/DB.php';
require_once __DIR__.'/../Framework/Concerns/TransformsRows.php';
require_once __DIR__.'/../Framework/TableQuery.php';

function dp_select_projection_state(): TestState {
	return TestState::channel('sql.select-projection-list');
}

if(!function_exists('sql_select')){
	function sql_select(mixed ...$arguments): mixed {
		dp_select_projection_state()->append('calls', $arguments);
		return ($arguments[4] ?? true)===true
			? [['id'=>'7', 'name'=>'Native projection']]
			: ['id'=>'7', 'name'=>'Native projection'];
	}
}

if(!class_exists('dataphyre\sql', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class sql {
		public static function clear_last_query_error(): void {}
		public static function hydrate_missing_structure_from_definition(string $table): bool { return false; }
		public static function invalidate_cache(string|array $target): bool { return true; }
	}');
}

final class DpSelectProjectionRepository extends TableRepository {
	protected static function table(): string {
		return 'projection_records';
	}

	protected static function schema(): ?TableSchema {
		return new TableSchema(
			'projection_records',
			['id', 'name', 'private_note'],
			['listing'=>['id', 'name']],
			'id',
			['id'=>'integer'],
		);
	}
}

test('repository and table-query projection lists compile to SQL selector strings', static function(Context $t): void {
	$state=$t->state('sql.select-projection-list', ['calls'=>[]]);
	$schema=new TableSchema(
		'projection_records',
		['id', 'name', 'private_note'],
		['listing'=>['id', 'name']],
		'id',
		['id'=>'integer'],
	);

	$repositoryRows=DpSelectProjectionRepository::all(DpSelectProjectionRepository::projectionNamed('listing'));
	$tableRows=(new TableQuery($schema))->get($schema->projection('listing'));

	$calls=$state->get('calls', []);
	$t->same('id, name', $calls[0][0] ?? null);
	$t->same('id, name', $calls[1][0] ?? null);
	$t->same(false, is_array($calls[0][0] ?? null));
	$t->same(false, is_array($calls[1][0] ?? null));
	$t->same(7, $repositoryRows[0]['id'] ?? null);
	$t->same(7, $tableRows[0]['id'] ?? null);
})->tag('sql', 'repository', 'table-query', 'projection', 'regression')->group('framework-regression');
