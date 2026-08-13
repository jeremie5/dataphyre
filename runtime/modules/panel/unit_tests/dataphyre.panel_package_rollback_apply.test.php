<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelPackageApplyResult;
use Dataphyre\Panel\PanelPackageInstallPlan;
use Dataphyre\Panel\PanelPackageRollbackPlan;
use Dataphyre\Panel\PanelPackageRollbackResult;
use Dataphyre\Panel\PanelPackageTemplate;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
if(!class_exists(\dataphyre\core::class, false)){
	require_once dirname(__DIR__, 2).'/core/kernel/core_functions.php';
}
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

/** @return array{0:string,1:string,2:PanelPackageApplyResult} */
function dp_panel_applied_rollback_fixture(Context $t, string $name): array {
	$workspace=$t->workspace($name);
	$root=$workspace->directory('target');
	$backups=$workspace->directory('backups');
	file_put_contents($root.DIRECTORY_SEPARATOR.'existing.txt', 'before');
	$template=PanelPackageTemplate::make($name)
		->plugin(false)->provider(false)->docs(false)->tests(false)->with('marketplace', false)
		->file('existing.txt', 'installed')
		->file('created.txt', 'created');
	$result=PanelPackageInstallPlan::make($template)
		->overwritePolicy('replace')
		->apply($root, ['backup_root'=>$backups]);
	return [$root, $backups, $result];
}

test('package rollback restores replaced files and deletes created artifacts as one transaction', static function(Context $t): void {
	[$root, $backups, $apply]=dp_panel_applied_rollback_fixture($t, 'rollback-success');
	$t->isTrue($apply->ok());
	$t->same('installed', file_get_contents($root.DIRECTORY_SEPARATOR.'existing.txt'));
	$t->same('created', file_get_contents($root.DIRECTORY_SEPARATOR.'created.txt'));

	$plan=PanelPackageRollbackPlan::fromApplyResult($apply);
	$manifest=$plan->manifest();
	$t->same(realpath($root), $manifest['target_root']);
	$t->same(1, $manifest['summary']['restores']);
	$t->same(2, $manifest['summary']['deletes']);
	$restoreStep=array_values(array_filter($manifest['steps'], static fn(array $step): bool => ($step['action'] ?? '')==='restore'))[0];
	$t->same(64, strlen((string)$restoreStep['installed_sha256']));
	$t->same(64, strlen((string)$restoreStep['backup_sha256']));

	$result=$plan->apply(['backup_root'=>$backups, 'meta'=>['operator'=>'Ada']]);
	$t->instanceOf(PanelPackageRollbackResult::class, $result);
	$t->isTrue($result->ok());
	$t->same('before', file_get_contents($root.DIRECTORY_SEPARATOR.'existing.txt'));
	$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.'created.txt'));
	$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.'dataphyre-panel-package.json'));
	$t->same(1, count($result->restored()));
	$t->same(2, count($result->deleted()));
	$t->same([], $result->blocked());
	$t->same([], $result->reverted());
	$t->isTrue($result->toArray()['meta']['atomic']);
	$t->same('Ada', $result->toArray()['meta']['operator']);
	$t->same($result->toArray(), $result->jsonSerialize());
})->tag('panel', 'package', 'rollback', 'filesystem', 'transaction')->maxMillis(2000);

test('stale installed files block the complete rollback before any mutation', static function(Context $t): void {
	[$root, $backups, $apply]=dp_panel_applied_rollback_fixture($t, 'rollback-stale');
	file_put_contents($root.DIRECTORY_SEPARATOR.'created.txt', 'operator changed this');
	$result=PanelPackageRollbackPlan::fromApplyResult($apply)->apply(['backup_root'=>$backups]);

	$t->isFalse($result->ok());
	$t->notEmpty($result->blocked());
	$t->contains('changed after package installation', $result->blocked()[0]['reason']);
	$t->same('installed', file_get_contents($root.DIRECTORY_SEPARATOR.'existing.txt'));
	$t->same('operator changed this', file_get_contents($root.DIRECTORY_SEPARATOR.'created.txt'));
	$t->isTrue(is_file($root.DIRECTORY_SEPARATOR.'dataphyre-panel-package.json'));
	$t->same([], $result->restored());
	$t->same([], $result->deleted());
})->tag('panel', 'package', 'rollback', 'stale-write', 'transaction')->maxMillis(2000);

test('tampered or untrusted backups block restore without deleting sibling artifacts', static function(Context $t): void {
	[$root, $backups, $apply]=dp_panel_applied_rollback_fixture($t, 'rollback-backup-tamper');
	$backup=(string)($apply->backups()[0]['backup'] ?? '');
	file_put_contents($backup, 'tampered backup');
	$result=PanelPackageRollbackPlan::fromApplyResult($apply)->apply(['backup_root'=>$backups]);

	$t->isFalse($result->ok());
	$t->contains('backup digest', strtolower((string)$result->blocked()[0]['reason']));
	$t->same('installed', file_get_contents($root.DIRECTORY_SEPARATOR.'existing.txt'));
	$t->same('created', file_get_contents($root.DIRECTORY_SEPARATOR.'created.txt'));

	$outside=$t->workspace('rollback-outside-backup')->directory('outside');
	$outsideResult=PanelPackageRollbackPlan::fromApplyResult($apply)->apply(['backup_root'=>$outside]);
	$t->isFalse($outsideResult->ok());
	$t->contains('allowed backup roots', $outsideResult->blocked()[0]['reason']);
})->tag('panel', 'package', 'rollback', 'backup-integrity', 'security')->maxMillis(2000);

test('rollback dry runs report exact work and leave the filesystem untouched', static function(Context $t): void {
	[$root, $backups, $apply]=dp_panel_applied_rollback_fixture($t, 'rollback-dry-run');
	$plan=PanelPackageRollbackPlan::fromApplyResult($apply);
	$result=$plan->apply(['backup_root'=>$backups, 'dry_run'=>true]);

	$t->isTrue($result->ok());
	$t->same(1, count($result->restored()));
	$t->same(2, count($result->deleted()));
	$t->isTrue($result->restored()[0]['dry_run']);
	$t->same('installed', file_get_contents($root.DIRECTORY_SEPARATOR.'existing.txt'));
	$t->same('created', file_get_contents($root.DIRECTORY_SEPARATOR.'created.txt'));
	$t->isTrue($result->toArray()['meta']['dry_run']);
})->tag('panel', 'package', 'rollback', 'dry-run')->maxMillis(2000);

test('rollback refuses preview-only and dry-run apply sources and supports explicit legacy force', static function(Context $t): void {
	$workspace=$t->workspace('rollback-boundaries');
	$root=$workspace->directory('target');
	$template=PanelPackageTemplate::make('preview-only')->plugin(false)->provider(false)->docs(false)->tests(false)->with('marketplace', false);
	$preview=PanelPackageRollbackPlan::make(PanelPackageInstallPlan::make($template, $root))->apply();
	$t->isFalse($preview->ok());
	$t->contains('concrete package apply result', $preview->blocked()[0]['reason']);

	$dryApply=PanelPackageInstallPlan::make($template)->apply($root, ['dry_run'=>true]);
	$dryRollback=PanelPackageRollbackPlan::fromApplyResult($dryApply)->apply();
	$t->isFalse($dryRollback->ok());
	$t->contains('dry-run package apply result', $dryRollback->blocked()[0]['reason']);

	$legacyTarget=$root.DIRECTORY_SEPARATOR.'legacy.txt';
	file_put_contents($legacyTarget, 'legacy installed bytes');
	$legacy=PanelPackageApplyResult::make([
		'ok'=>true,'package'=>['id'=>'legacy'],'target_root'=>$root,
		'written'=>[['action'=>'create','target'=>$legacyTarget]],
	]);
	$blocked=PanelPackageRollbackPlan::fromApplyResult($legacy)->apply();
	$t->isFalse($blocked->ok());
	$t->isTrue(is_file($legacyTarget));
	$forced=PanelPackageRollbackPlan::fromApplyResult($legacy)->apply(['force'=>true]);
	$t->isTrue($forced->ok());
	$t->isFalse(is_file($legacyTarget));
})->tag('panel', 'package', 'rollback', 'security', 'legacy')->maxMillis(2000);

test('rollback package-root lock prevents concurrent mutation', static function(Context $t): void {
	[$root, $backups, $apply]=dp_panel_applied_rollback_fixture($t, 'rollback-lock');
	$lockDirectory=dirname($t->tempDirectory('panel-rollback-lock-anchor')).DIRECTORY_SEPARATOR.'dataphyre-panel-package-locks';
	if(!is_dir($lockDirectory)){mkdir($lockDirectory, 0775, true);}
	$lockPath=$lockDirectory.DIRECTORY_SEPARATOR.hash('sha256', realpath($root) ?: $root).'.lock';
	$t->cleanup(static fn()=>@unlink($lockPath));
	$handle=fopen($lockPath, 'c+');
	$t->isTrue(is_resource($handle));
	flock($handle, LOCK_EX | LOCK_NB);
	try{
		$result=PanelPackageRollbackPlan::fromApplyResult($apply)->apply(['backup_root'=>$backups, 'lock_timeout_ms'=>0]);
		$t->isFalse($result->ok());
		$t->contains('lock could not be acquired', $result->blocked()[0]['reason']);
		$t->same('installed', file_get_contents($root.DIRECTORY_SEPARATOR.'existing.txt'));
	}
	finally{
		flock($handle, LOCK_UN);
		fclose($handle);
	}
})->tag('panel', 'package', 'rollback', 'concurrency')->maxMillis(2000);
