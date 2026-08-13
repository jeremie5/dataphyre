<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\TableDefinition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/tracelog_runtime_test_helpers.php';
require_once dirname(__DIR__, 2).'/sql/Framework/TableDefinition.php';

suite('Tracelog diagnostic and persistence schema contract')
	->contract('tracelog.diagnostic', 1)
	->layer('integration')
	->risk('medium')
	->watches('module:tracelog')
	->through('requirements', 'extensions', 'sql-schema', 'table-manifest')
	->isolation('case')
	->tag('tracelog', 'diagnostic', 'exact-coverage')
	->group('framework-coverage');

test('diagnostic reports old runtimes missing extensions and unavailable SQL without hiding observations', static function(Context $t): void {
	$required=$t->spy()->willReturn(null);
	$publish=$t->spy()->willReturn(null);
	$findings=\dataphyre\tracelog\diagnostic::tests([
		'require_module'=>$required,
		'publish'=>$publish,
		'php_version'=>'8.0.30',
		'time'=>1700000000,
		'extensions'=>['json','missing_extension'],
		'extension_loaded'=>static fn(string $extension): bool=>$extension==='json',
		'sql_query'=>false,
	]);
	$required->assertCalledWith($t, ['tracelog','sql']);
	$publish->assertCalledTimes($t, 1);
	$t->count(3, $findings);
	$t->contains('PHP version 8.1.0', $findings[0]['error']);
	$t->contains('missing_extension', $findings[1]['error']);
	$t->same('warning', $findings[2]['level']);
});

test('diagnostic publishes backend-specific schema queries when every prerequisite is available', static function(Context $t): void {
	$query=$t->spy()->willReturn(true);
	$publish=$t->spy()->willReturn(null);
	$findings=\dataphyre\tracelog\diagnostic::tests([
		'require_module'=>static fn(): null=>null,
		'publish'=>$publish,
		'php_version'=>'8.4.0',
		'extensions'=>['json'],
		'extension_loaded'=>static fn(): bool=>true,
		'sql_query'=>$query,
	]);
	$t->same([], $findings);
	$query->assertCalledTimes($t, 1);
	$queries=\dataphyre\tracelog\diagnostic::schemaQueries();
	$t->same(['mysql','postgresql','sqlite'], array_keys($queries));
	$t->contains('dataphyre.tracelogs', $queries['postgresql']);
	$t->contains('dataphyre_tracelogs', $queries['sqlite']);
	$t->same([], \dataphyre\tracelog\diagnostic::bootstrap(false));
	$t->throws(static fn()=>\dataphyre\tracelog\diagnostic::tests([
		'require_module'=>'invalid','publish'=>'invalid',
	]), LogicException::class);
	$t->throws(static fn()=>\dataphyre\tracelog\diagnostic::tests([
		'require_module'=>static fn(): null=>null,
		'publish'=>static fn(): null=>null,
		'extension_loaded'=>'invalid',
	]), LogicException::class);
});

test('tracelog table manifest preserves request identity and chronological lookup', static function(Context $t): void {
	$factory=require dirname(__DIR__).'/kernel/tracelog.tables.php';
	$t->isTrue(is_callable($factory));
	$definition=$factory('dataphyre.tracelogs');
	$t->instanceOf(TableDefinition::class, $definition);
	$t->same('dataphyre.tracelogs', $definition->table());
	$t->same(['rqid'], $definition->primaryColumns());
});
