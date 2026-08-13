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

require_once __DIR__.'/permission_check_test_helpers.php';
require_once dirname(__DIR__, 2).'/sql/Framework/TableDefinition.php';

suite('Permission checker operational contract')
	->contract('permission.checker', 1)
	->layer('integration')
	->risk('high')
	->watches('module:permission')
	->through('entrypoint', 'options', 'normalization', 'reporting', 'filesystem', 'policy-exit', 'schema')
	->isolation('case')
	->tag('permission', 'checker', 'exact-coverage')
	->group('framework-coverage');

test('entrypoint dispatch exposes process status without terminating its embedding worker', static function(Context $t): void {
	$scenario=DpPermissionCheckScenario::open($t);
	$help=$scenario->dispatch(['--help']);
	$t->same(0, $help->result());
	$t->contains('Usage: php', $help->output());
	$t->same([0], $scenario->terminations());
	$t->same(null, dp_permission_check_entrypoint(['permission_check.php'], false));

	$invalidTerminator=$t->captureExecution(static fn()=>dp_permission_check_entrypoint(
		['permission_check.php', '--help'],
		true,
		['terminate'=>'not-callable']
	));
	$t->instanceOf(LogicException::class, $invalidTerminator->throwable());
});

test('runner distinguishes web access help malformed invocation and invalid error wiring', static function(Context $t): void {
	$scenario=DpPermissionCheckScenario::open($t);
	$web=$scenario->run([], ['sapi'=>'fpm-fcgi']);
	$t->same(2, $web->result());
	$t->contains('only available from CLI', $web->output());

	$failure=$scenario->run(['--unknown']);
	$t->same(2, $failure->result());
	$t->contains('Unknown option', $scenario->errors()[0]);

	$invalidWriter=$t->throws(
		static fn()=>dp_permission_check_run(['permission_check.php', '--unknown'], ['error'=>'not-callable']),
		LogicException::class
	);
	$t->contains('error writer must be callable', $invalidWriter->getMessage());
});

test('option parser names every path and strictness policy while rejecting ambiguous commands', static function(Context $t): void {
	$options=dp_permission_check_options([
		'permission_check.php',
		'--manifest', 'current.json',
		'--roles=roles.json',
		'--known', 'known.json',
		'--assignments=assignments.json',
		'--against', 'old.json',
		'--json=report.json',
		'--fail-on-warning', '--fail-on-info', '--fail-on-diff', '--quiet',
	]);
	$t->hasPathValues([
		'manifest'=>'current.json',
		'roles'=>'roles.json',
		'known'=>'known.json',
		'assignments'=>'assignments.json',
		'against'=>'old.json',
		'json'=>'report.json',
		'fail_on_warning'=>true,
		'fail_on_info'=>true,
		'fail_on_diff'=>true,
		'quiet'=>true,
	], $options);
	$t->isTrue(dp_permission_check_options(['permission_check.php', '-h'])['help']);
	$t->throws(static fn()=>dp_permission_check_options(['permission_check.php', '--manifest']), InvalidArgumentException::class);
	$t->throws(static fn()=>dp_permission_check_options(['permission_check.php']), InvalidArgumentException::class);
	$t->throws(static fn()=>dp_permission_check_options(['permission_check.php', '--roles=roles.json', '--against=old.json']), InvalidArgumentException::class);
	$t->throws(static fn()=>dp_permission_check_options(['permission_check.php', '--mystery']), InvalidArgumentException::class);
});

test('normalizers accept manifest preset direct catalog and assignment dialects deterministically', static function(Context $t): void {
	$t->same([
		'editor'=>['orders.edit','orders.view'],
	], dp_permission_check_roles(['roles'=>[
		' editor '=>['permissions'=>['orders.view','orders.edit']],
		''=>['ignored'],
	]]));
	$t->same(['viewer'=>['orders.view']], dp_permission_check_roles([
		'presets'=>[['name'=>'Viewer','permissions'=>'orders.view']],
	]));
	$t->same(['direct'=>['orders.view']], dp_permission_check_roles([
		'direct'=>'orders.view',
	]));

	$t->same(['orders.edit','orders.view'], dp_permission_check_known(['catalog'=>[
		['permission'=>'orders.view'],
		['name'=>'orders.edit'],
		'orders.view',
		['ignored'=>'row'],
	]]));
	$t->same(['profile.view'], dp_permission_check_known(['known'=>['Profile/View']]));
	$t->same(['users.view'], dp_permission_check_known(['permissions'=>['users.view']]));
	$t->same(['keyed.permission'], dp_permission_check_known(['Keyed.Permission'=>true]));

	$assignment=['kind'=>'role','value'=>'viewer'];
	$t->same([$assignment], dp_permission_check_assignments(['assignments'=>[$assignment, 'ignored', ['other'=>'ignored']]]));
	$t->same([['value'=>'orders.view']], dp_permission_check_assignments([['value'=>'orders.view']]));
	$t->isTrue(dp_permission_check_diff_changed(['roles'=>['added'=>['editor']]]));
	$t->isTrue(dp_permission_check_diff_changed(['catalog'=>['changed'=>['orders.view']]]));
	$t->isFalse(dp_permission_check_diff_changed([]));
});

test('manifest audit run writes a readable diff report and honors quiet strict execution', static function(Context $t): void {
	$scenario=DpPermissionCheckScenario::open($t);
	$current=$scenario->jsonDocument('current', [
		'roles'=>['viewer'=>['orders.view'], 'editor'=>['orders.edit']],
		'catalog'=>[['permission'=>'orders.view'], ['permission'=>'orders.edit']],
		'assignments'=>[['kind'=>'role','value'=>'viewer','subject_id'=>'7']],
	]);
	$old=$scenario->jsonDocument('old', [
		'roles'=>['viewer'=>['orders.view']],
		'catalog'=>[['permission'=>'orders.view']],
	]);
	$roles=$scenario->jsonDocument('roles', ['roles'=>['auditor'=>['orders.view']]]);
	$known=$scenario->jsonDocument('known', ['known'=>['orders.view']]);
	$assignments=$scenario->jsonDocument('assignments', ['assignments'=>[['kind'=>'role','value'=>'auditor']]]);
	$reportPath=$scenario->reportPath();
	$run=$scenario->run([
		'--manifest='.$current,
		'--roles='.$roles,
		'--known='.$known,
		'--assignments='.$assignments,
		'--against='.$old,
		'--json='.$reportPath,
		'--fail-on-diff',
	]);
	$t->same(1, $run->result());
	$t->contains('Permission audit:', $run->output());
	$t->contains('Manifest diff:', $run->output());
	$t->hasPathValues([
		'runner'=>'permission_check.php',
		'changed'=>true,
		'sources.manifest'=>$current,
		'sources.roles'=>$roles,
	], $scenario->report());

	$quiet=$scenario->run(['--roles='.$roles, '--quiet']);
	$t->same(0, $quiet->result());
	$t->same('', $quiet->output());
});

test('human report rendering skips malformed findings and summarizes changed catalogs', static function(Context $t): void {
	$report=[
		'audit'=>[
			'role_count'=>2,
			'catalog_count'=>3,
			'assignment_count'=>1,
			'counts'=>['error'=>1,'warning'=>2,'info'=>3],
			'findings'=>[
				'ignored',
				['severity'=>'warning','type'=>'unknown_role','message'=>'Role is unknown.'],
			],
		],
		'diff'=>[
			'roles'=>['added'=>['editor'],'removed'=>[],'changed'=>['viewer']],
			'catalog'=>['added'=>['orders.edit'],'removed'=>['orders.old']],
		],
	];
	$output=$t->captureOutput(static fn()=>dp_permission_check_print($report, []))->output();
	$t->contains('1 errors, 2 warnings, 3 info', $output);
	$t->contains('[WARNING] unknown_role: Role is unknown.', $output);
	$t->contains('2 role changes, 2 catalog changes', $output);
});

test('JSON boundaries distinguish absent unreadable invalid unwritable and valid documents', static function(Context $t): void {
	$scenario=DpPermissionCheckScenario::open($t);
	$valid=$scenario->jsonDocument('valid', ['roles'=>[]]);
	$t->same(['roles'=>[]], dp_permission_check_read_json($valid));
	$t->throws(static fn()=>dp_permission_check_read_json($scenario->path('missing.json')), RuntimeException::class);
	$t->throws(static fn()=>dp_permission_check_read_json($valid, static fn(): bool=>false), RuntimeException::class);
	$t->throws(static fn()=>dp_permission_check_read_json($scenario->invalidJsonDocument()), RuntimeException::class);

	$t->throws(static fn()=>dp_permission_check_write_json('', []), RuntimeException::class);
	$t->throws(static fn()=>dp_permission_check_write_json(
		$scenario->path('blocked/report.json'),
		[],
		static fn(): bool=>false
	), RuntimeException::class);
	$t->throws(static fn()=>dp_permission_check_write_json(
		$scenario->path('encode.json'),
		[],
		null,
		static fn(): bool=>false
	), RuntimeException::class);
	$t->throws(static fn()=>dp_permission_check_write_json(
		$scenario->path('write.json'),
		[],
		null,
		null,
		static fn(): bool=>false
	), RuntimeException::class);
	$written=$scenario->path('nested/success.json');
	dp_permission_check_write_json($written, ['ok'=>true]);
	$t->same(['ok'=>true], dp_permission_check_read_json($written));
});

test('path and exit policies remain portable across Unix Windows and strict audit modes', static function(Context $t): void {
	$t->same('', dp_permission_check_resolve_path('  '));
	$t->same('/srv/report.json', dp_permission_check_resolve_path('/srv/report.json'));
	$t->same('C:\\reports\\audit.json', dp_permission_check_resolve_path('C:\\reports\\audit.json'));
	$t->same('/workspace/report.json', dp_permission_check_resolve_path('report.json', '/workspace'));
	$t->same('report.json', dp_permission_check_resolve_path('report.json', false));

	$t->same(1, dp_permission_check_exit_code(['audit'=>['counts'=>['error'=>1]]], []));
	$t->same(1, dp_permission_check_exit_code(['audit'=>['counts'=>['warning'=>1]]], ['fail_on_warning'=>true]));
	$t->same(1, dp_permission_check_exit_code(['audit'=>['counts'=>['info'=>1]]], ['fail_on_info'=>true]));
	$t->same(1, dp_permission_check_exit_code(['audit'=>['counts'=>[]], 'changed'=>true], ['fail_on_diff'=>true]));
	$t->same(0, dp_permission_check_exit_code(['audit'=>'malformed', 'changed'=>true], []));
});

test('permission table manifest fixes assignment role and rule identity contracts', static function(Context $t): void {
	$manifest=require dirname(__DIR__).'/kernel/permission.tables.php';
	$t->same(['assignments','roles','role_permissions'], array_keys($manifest));
	$assignments=$manifest['assignments']('dataphyre.permission_assignments');
	$roles=$manifest['roles']('dataphyre.permission_roles');
	$rules=$manifest['role_permissions']('dataphyre.permission_role_permissions');
	foreach([$assignments,$roles,$rules] as $definition){
		$t->instanceOf(TableDefinition::class, $definition);
	}
	$t->same(['id'], $assignments->primaryColumns());
	$t->same(['name'], $roles->primaryColumns());
	$t->same(['id'], $rules->primaryColumns());
});
