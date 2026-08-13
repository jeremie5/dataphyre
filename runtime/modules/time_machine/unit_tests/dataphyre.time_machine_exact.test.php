<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_TIME_MACHINE_DIAGNOSTIC_NO_DISPATCH')){
	define('DATAPHYRE_TIME_MACHINE_DIAGNOSTIC_NO_DISPATCH', true);
}
require_once dirname(__DIR__, 2).'/dpanel/tooling/WorkerFixtureState.php';
require_once __DIR__.'/time_machine_test_helpers.php';
require_once dirname(__DIR__).'/kernel/time_machine.diagnostic.php';
require_once dirname(__DIR__, 2).'/sql/Framework/TableDefinition.php';

suite('Time Machine exact journal behavior')
	->contract('time-machine.exact-journal', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:time_machine')
	->through('retention', 'authorization', 'rollback-strategies', 'persistence', 'diagnostics', 'schema')
	->isolation('case')
	->tag('time-machine', 'exact-coverage')
	->group('framework-coverage');

test('journal retention reports both successful and failed storage deletion', static function(Context $t): void {
	DpTimeMachineWorkerScenario::begin();
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('delete', true);
	$t->isTrue(\dataphyre\time_machine::purge_old('30 days'));

	DpTimeMachineWorkerScenario::begin();
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('delete', false);
	$t->isFalse(\dataphyre\time_machine::purge_old('30 days'));
});

test('rollback rejects absent unauthorized disabled and malformed journal records', static function(Context $t): void {
	DpTimeMachineWorkerScenario::begin();
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', false);
	$t->isFalse(DpTimeMachineWorkerScenario::rollback());

	DpTimeMachineWorkerScenario::begin();
	DpTimeMachineWorkerScenario::rollbackRecord('SQL_INSERT', ['table'=>'orders', 'row'=>['id'=>1]], 42, false);
	$t->isFalse(DpTimeMachineWorkerScenario::rollback(requester: 42));

	DpTimeMachineWorkerScenario::begin();
	DpTimeMachineWorkerScenario::rollbackRecord('SQL_INSERT', ['table'=>'orders', 'row'=>['id'=>1]]);
	$t->isFalse(DpTimeMachineWorkerScenario::rollback(owner: 99));

	DpTimeMachineWorkerScenario::begin();
	DpTimeMachineWorkerScenario::malformedRollbackRecord();
	$t->isFalse(DpTimeMachineWorkerScenario::rollback());
	$t->same(0, DpTimeMachineWorkerScenario::sqlCalls('insert'));

	DpTimeMachineWorkerScenario::begin();
	DpTimeMachineWorkerScenario::rollbackRecord('UNSUPPORTED', ['table'=>'orders']);
	$t->isFalse(DpTimeMachineWorkerScenario::rollback());
	$t->same(0, DpTimeMachineWorkerScenario::sqlCalls('update'));
});

test('user preference rollback restores the recorded value before marking the journal', static function(Context $t): void {
	DpTimeMachineWorkerScenario::begin();
	DpTimeMachineWorkerScenario::allMutationsSucceed();
	DpTimeMachineWorkerScenario::rollbackRecord('USER_PARAMETER', [
		'setting_name'=>'lang',
		'old_value'=>'fr',
	]);
	$t->isTrue(DpTimeMachineWorkerScenario::rollback());
	$t->same(2, DpTimeMachineWorkerScenario::sqlCalls('update'));
});

test('SQL delete rollback supports one predicate or a recorded predicate set', static function(Context $t): void {
	DpTimeMachineWorkerScenario::begin();
	DpTimeMachineWorkerScenario::allMutationsSucceed();
	DpTimeMachineWorkerScenario::rollbackRecord('SQL_DELETE', [
		'table'=>'orders',
		'parameters'=>'WHERE id=?',
		'values'=>[11],
	]);
	$t->isTrue(DpTimeMachineWorkerScenario::rollback());
	$t->same(1, DpTimeMachineWorkerScenario::sqlCalls('delete'));

	DpTimeMachineWorkerScenario::begin();
	DpTimeMachineWorkerScenario::allMutationsSucceed();
	DpTimeMachineWorkerScenario::rollbackRecord('SQL_DELETE', [
		'table'=>'orders',
		'rows'=>[
			['parameters'=>'WHERE id=?', 'values'=>[11]],
			['parameters'=>'WHERE id=?', 'values'=>[12]],
		],
	]);
	$t->isTrue(DpTimeMachineWorkerScenario::rollback());
	$t->same(2, DpTimeMachineWorkerScenario::sqlCalls('delete'));
});

test('SQL insert rollback supports one row or a recorded row set', static function(Context $t): void {
	DpTimeMachineWorkerScenario::begin();
	DpTimeMachineWorkerScenario::allMutationsSucceed();
	DpTimeMachineWorkerScenario::rollbackRecord('SQL_INSERT', [
		'table'=>'orders',
		'row'=>['id'=>21, 'status'=>'pending'],
	]);
	$t->isTrue(DpTimeMachineWorkerScenario::rollback());
	$t->same(1, DpTimeMachineWorkerScenario::sqlCalls('insert'));

	DpTimeMachineWorkerScenario::begin();
	DpTimeMachineWorkerScenario::allMutationsSucceed();
	DpTimeMachineWorkerScenario::rollbackRecord('SQL_INSERT', [
		'table'=>'orders',
		'rows'=>[
			['id'=>21, 'status'=>'pending'],
			['id'=>22, 'status'=>'approved'],
		],
	]);
	$t->isTrue(DpTimeMachineWorkerScenario::rollback());
	$t->same(2, DpTimeMachineWorkerScenario::sqlCalls('insert'));
});

test('SQL update rollback supports one row or a recorded row set', static function(Context $t): void {
	DpTimeMachineWorkerScenario::begin();
	DpTimeMachineWorkerScenario::allMutationsSucceed();
	DpTimeMachineWorkerScenario::rollbackRecord('SQL_UPDATE', [
		'table'=>'orders',
		'row'=>['status'=>'pending'],
		'parameters'=>'WHERE id=?',
		'values'=>[31],
	]);
	$t->isTrue(DpTimeMachineWorkerScenario::rollback());
	$t->same(2, DpTimeMachineWorkerScenario::sqlCalls('update'));

	DpTimeMachineWorkerScenario::begin();
	DpTimeMachineWorkerScenario::allMutationsSucceed();
	DpTimeMachineWorkerScenario::rollbackRecord('SQL_UPDATE', [
		'table'=>'orders',
		'rows'=>[
			['status'=>'pending'],
			['status'=>'approved'],
		],
		'parameters'=>'WHERE id=?',
		'values'=>[31],
	]);
	$t->isTrue(DpTimeMachineWorkerScenario::rollback());
	$t->same(3, DpTimeMachineWorkerScenario::sqlCalls('update'));
});

test('rollback marker and journal creation failures remain visible to callers and dialbacks', static function(Context $t): void {
	DpTimeMachineWorkerScenario::begin();
	DpTimeMachineWorkerScenario::rollbackRecord('SQL_DELETE', [
		'table'=>'orders',
		'parameters'=>'WHERE id=?',
		'values'=>[41],
	]);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('delete', true);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('update', false);
	$t->isFalse(DpTimeMachineWorkerScenario::rollback());

	DpTimeMachineWorkerScenario::begin();
	DpTimeMachineWorkerScenario::asAuthenticatedUser(42);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('insert', false);
	$t->isFalse(\dataphyre\time_machine::create('order', 'SQL_UPDATE', ['id'=>41], true));
	$t->same(1, DpTimeMachineWorkerScenario::dialbackCalls('CALL_TIME_MACHINE_FAILED_CREATING'));
});

test('diagnostics describe host failures and publish all supported journal schemas', static function(Context $t): void {
	$required=$t->spy();
	$publish=$t->spy();
	$findings=\dataphyre\time_machine\diagnostic::tests([
		'module_required'=>$required,
		'extension_loaded'=>static fn(string $extension): bool=>$extension!=='session',
		'php_version'=>'8.0.30',
		'clock'=>static fn(): int=>1_700_000_000,
		'sql_query'=>null,
		'publish'=>$publish,
	]);
	$t->contains('PHP version 8.1.0 or higher is required.', array_column($findings, 'error'));
	$t->contains("PHP extension 'session' is not loaded.", array_column($findings, 'error'));
	$t->same('warning', $findings[2]['level']);
	$required->assertCalledTimes($t, 2);
	$publish->assertCalledTimes($t, 1);

	$query=$t->spy();
	$t->same([], \dataphyre\time_machine\diagnostic::tests([
		'module_required'=>static fn(): bool=>true,
		'extension_loaded'=>static fn(): bool=>true,
		'php_version'=>'8.4.0',
		'sql_query'=>$query,
		'publish'=>null,
	]));
	$schemas=$query->lastCall()[0];
	$t->hasKeys(['mysql', 'postgresql', 'sqlite'], $schemas);
	$t->contains('user_changes', $schemas['sqlite']);
	$t->greaterThan(0, count(\dataphyre\time_machine\diagnostic::tests()));
});

test('journal table manifest preserves change identity and rollback indexes', static function(Context $t): void {
	$manifest=require dirname(__DIR__).'/kernel/time_machine.tables.php';
	$t->hasKey('user_changes', $manifest);
	$definition=$manifest['user_changes']('dataphyre.user_changes');
	$t->instanceOf(TableDefinition::class, $definition);
	$t->same('dataphyre.user_changes', $definition->table());
	$t->same(['changeid'], $definition->primaryColumns());
});
