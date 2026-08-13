<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\RepositoryQuery;
use Dataphyre\Database\TableQuery;
use Dataphyre\Database\TableRepository;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/sql_framework_test_helpers.php';
require_once __DIR__.'/../Framework/Concerns/TransformsRows.php';
require_once __DIR__.'/../Framework/TableQuery.php';
require_once __DIR__.'/../Framework/RepositoryQuery.php';

if(!defined('DP_SQL_QUERY_WRAPPER_PARENT_REGRESSION_REPOSITORY_LOADED') && !class_exists(DpQueryWrapperParentRegressionRepository::class, false)){
	define('DP_SQL_QUERY_WRAPPER_PARENT_REGRESSION_REPOSITORY_LOADED', true);
	final class DpQueryWrapperParentRegressionRepository extends TableRepository {
		protected static function table(): string { return 'query_wrapper_records'; }
	}
}

test('table and repository fluent safety wrappers delegate to QuerySpec exactly once', static function(Context $t): void {
	$queries=[
		new TableQuery('query_wrapper_records', 'id'),
		new RepositoryQuery(DpQueryWrapperParentRegressionRepository::class),
	];
	foreach($queries as $query){
		$t->same($query, $query->requireWhereForWrite());
		$t->same(true, $query->writeRequiresWhere());
		$t->same($query, $query->allowUnscopedWrite());
		$t->same(false, $query->writeRequiresWhere(true));

		$t->same($query, $query->forUpdate());
		$t->same([
			'mysql'=>'FOR UPDATE',
			'postgresql'=>'FOR UPDATE',
			'sqlite'=>'',
		], $query->debugContext()['lock_clause'] ?? null);

		$t->same($query, $query->sharedLock());
		$t->same([
			'mysql'=>'LOCK IN SHARE MODE',
			'postgresql'=>'FOR SHARE',
			'sqlite'=>'',
		], $query->debugContext()['lock_clause'] ?? null);

		$t->same($query, $query->lockRaw('FOR NO KEY UPDATE'));
		$t->same('FOR NO KEY UPDATE', $query->debugContext()['lock_clause'] ?? null);
		$t->same($query, $query->withoutLocking());
		$t->same(false, array_key_exists('lock_clause', $query->debugContext()));
	}
})->tag('sql', 'query-spec', 'fluent', 'regression')->group('framework-regression');
