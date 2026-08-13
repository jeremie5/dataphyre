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

require_once dirname(__DIR__, 2).'/dpanel/tooling/WorkerFixtureState.php';
require_once __DIR__.'/issue_test_helpers.php';
require_once dirname(__DIR__, 2).'/sql/Framework/TableDefinition.php';

suite('Issue exact boundaries')
	->contract('issue.exact-boundaries', 1)
	->layer('unit')
	->risk('high')
	->watches('module:issue')
	->through('context-encoding', 'runtime-identity', 'notifications', 'persistence', 'schema')
	->isolation('case')
	->tag('issue', 'exact-coverage')
	->group('framework-coverage');

test('context encoding and runtime identity fallbacks are explicit observations', static function(Context $t): void {
	new \dataphyre\issue(static fn(): bool=>true, '5.0.0', 'Invalid/Timezone', ['userid'=>null]);
	$internals=$t->nonPublic(\dataphyre\issue::class);
	$t->same('{}', $internals->invoke('encode_context', ['value'=>'ignored'], static fn(array $payload): false=>false));
	$t->matches('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $internals->invoke('current_time_string'));

	$t->same('Module/Zone', $internals->invoke('current_timezone_label', ['module_timezone'=>'Module/Zone']));
	$t->same('Core/Zone', $internals->invoke('current_timezone_label', ['module_timezone'=>'', 'core_timezone'=>'Core/Zone']));
	$t->same('Config/Zone', $internals->invoke('current_timezone_label', [
		'module_timezone'=>'', 'core_timezone'=>'', 'config_timezone'=>'Config/Zone',
	]));
	$t->same('Default/Zone', $internals->invoke('current_timezone_label', [
		'module_timezone'=>'', 'core_timezone'=>'', 'config_timezone'=>'', 'default_timezone'=>'Default/Zone',
	]));

	$t->same('198.51.100.10', $internals->invoke('current_execution_ip', ['request_ip'=>'198.51.100.10']));
	$t->same('198.51.100.11', $internals->invoke('current_execution_ip', [
		'request_ip'=>null,
		'core_client_ip'=>static fn(): string=>'198.51.100.11',
	]));
	$t->same('198.51.100.12', $internals->invoke('current_execution_ip', [
		'request_ip'=>null, 'core_client_ip'=>null, 'server'=>['REMOTE_ADDR'=>'198.51.100.12'],
	]));
	$t->same('0.0.0.0', $internals->invoke('current_execution_ip', [
		'request_ip'=>null, 'core_client_ip'=>null, 'server'=>[],
	]));

	$t->same(72, $internals->invoke('current_execution_userid', [
		'userid'=>null, 'access_userid'=>static fn(): string=>'72',
	]));
	$t->same(null, $internals->invoke('current_execution_userid', ['userid'=>'anonymous', 'access_userid'=>null]));
	if(!class_exists(\dataphyre\access::class)){
		$t->defineSymbols('namespace dataphyre; final class access { public static function userid(): int { return 73; } }');
	}
	$t->same(73, $internals->invoke('current_execution_userid', ['userid'=>null]));
});

test('notification and persistence failures remain isolated from caller control flow', static function(Context $t): void {
	$internals=$t->nonPublic(\dataphyre\issue::class);
	$internals->writeProperty('email_sending_callback', null);
	$internals->invoke('notify_issue', 'No callback', 'Body');
	$internals->writeProperty('email_sending_callback', static fn()=>throw new RuntimeException('mail transport failed'));
	$internals->invoke('notify_issue', 'Throwing callback', 'Body');

	DpIssueWorkerScenario::begin('203.0.113.20');
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', false);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('insert', false);
	new \dataphyre\issue(static fn(): bool=>true, '5.1.0', 'UTC', ['userid'=>null]);
	$t->isFalse(\dataphyre\issue::create('failed_insert', ['path'=>'/failure'], 'Failure'));

	DpIssueWorkerScenario::begin('203.0.113.21');
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', false);
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('insert', true);
	new \dataphyre\issue(static function(string $subject, string $body): void {
		DpIssueWorkerScenario::recordNotification($subject, $body);
	}, '5.2.0', 'UTC', ['userid'=>null]);
	$t->isFalse(\dataphyre\issue::create('missing_identifier', [], 'Created without identifier'));
	$t->contains('<b>Unknown issueid</b>', DpIssueWorkerScenario::firstNotification()['body']);
});

test('recrypt delegation and the issue table manifest publish stable integration contracts', static function(Context $t): void {
	\dataphyre\core::register_dialback('CALL_CORE_DEFER_RECRYPT', static fn(): bool=>true);
	$t->isTrue(\dataphyre\issue::recrypt(8802));

	$manifest=require dirname(__DIR__).'/kernel/issue.tables.php';
	$t->hasKey('issues', $manifest);
	$definition=$manifest['issues']('issues');
	$t->instanceOf(TableDefinition::class, $definition);
	$t->same('issues', $definition->table());
	$t->same(['issueid'], $definition->primaryColumns());
});
