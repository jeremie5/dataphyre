<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelPackageInstallPlan;
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

test('atomic package install restores replacements and removes creations after a later write failure', static function(Context $t): void {
	$root=$t->workspace('dp-panel-atomic-install')->root();
	file_put_contents($root.DIRECTORY_SEPARATOR.'a-existing.txt', 'original');
	file_put_contents($root.DIRECTORY_SEPARATOR.'b-blocked', 'directory blocker');
	$template=PanelPackageTemplate::make('atomic-install')
		->plugin(false)->provider(false)->docs(false)->tests(false)->with('marketplace', false)
		->file('a-existing.txt', 'replacement')
		->file('a-new.txt', 'new artifact')
		->file('b-blocked/child.txt', 'cannot be written');
	$result=PanelPackageInstallPlan::make($template)->overwritePolicy('replace')->apply($root);

	$t->isFalse($result->ok());
	$t->same('original', file_get_contents($root.DIRECTORY_SEPARATOR.'a-existing.txt'));
	$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.'a-new.txt'));
	$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.'dataphyre-panel-package.json'));
	$t->same('directory blocker', file_get_contents($root.DIRECTORY_SEPARATOR.'b-blocked'));
	$t->same([], $result->written());
	$t->same(3, count($result->attempted()));
	$t->same(3, count($result->reverted()));
	$t->same([], array_values(array_filter($result->reverted(), static fn(array $row): bool => empty($row['ok']))));
	$t->isTrue($result->toArray()['meta']['atomic']);
	$t->isTrue($result->toArray()['meta']['transaction_reverted']);
	$t->same('', $result->toArray()['meta']['transaction_snapshot']);
	$t->same($result->toArray(), $result->jsonSerialize());
})->tag('panel', 'package', 'install', 'transaction', 'atomic')->maxMillis(2000);

test('atomic package install lock prevents concurrent package mutation', static function(Context $t): void {
	$root=$t->workspace('dp-panel-install-lock')->root();
	$resolved=realpath($root) ?: $root;
	$lockDirectory=dirname($t->tempDirectory('panel-package-lock-anchor')).DIRECTORY_SEPARATOR.'dataphyre-panel-package-locks';
	if(!is_dir($lockDirectory)){mkdir($lockDirectory, 0775, true);}
	$lockPath=$lockDirectory.DIRECTORY_SEPARATOR.hash('sha256', $resolved).'.lock';
	$t->cleanup(static fn()=>@unlink($lockPath));
	$handle=fopen($lockPath, 'c+');
	$t->isTrue(is_resource($handle));
	flock($handle, LOCK_EX | LOCK_NB);
	try{
		$template=PanelPackageTemplate::make('locked-install')
			->plugin(false)->provider(false)->docs(false)->tests(false)->with('marketplace', false)
			->file('artifact.txt', 'content');
		$result=PanelPackageInstallPlan::make($template)->apply($root, ['lock_timeout_ms'=>0]);
		$t->isFalse($result->ok());
		$t->same([], $result->written());
		$t->same([], $result->attempted());
		$t->contains('lock could not be acquired', $result->blocked()[0]['reason']);
		$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.'artifact.txt'));
	}
	finally{
		flock($handle, LOCK_UN);
		fclose($handle);
	}
})->tag('panel', 'package', 'install', 'transaction', 'concurrency')->maxMillis(2000);

test('package apply result normalizes attempted and reverted transaction telemetry', static function(Context $t): void {
	$result=\Dataphyre\Panel\PanelPackageApplyResult::make([
		'attempted'=>['bad', ['target'=>'one']],
		'reverted'=>[null, ['target'=>'one','ok'=>true]],
	]);
	$t->same([['target'=>'one']], $result->attempted());
	$t->same([['target'=>'one','ok'=>true]], $result->reverted());
	$t->same($result->attempted(), $result->toArray()['attempted']);
	$t->same($result->reverted(), $result->toArray()['reverted']);
})->tag('panel', 'package', 'install', 'result')->maxMillis(1000);
