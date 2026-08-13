<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelFilesystemPreferenceStore;
use Dataphyre\Panel\PanelInMemoryPreferenceStore;
use Dataphyre\Panel\PanelPreferenceConflictException;
use Dataphyre\Panel\PanelWorkspacePreferenceProfile;
use Dataphyre\Panel\PanelWorkspacePreferences;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataphyre_path;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel preference profiles retain versions and perform field-aware optimistic three-way merges', static function(Context $t): void {
	$store=new PanelInMemoryPreferenceStore(32, 20);
	$base=$store->save(new PanelWorkspacePreferenceProfile('operator-7', 'operations', [
		'appearance'=>['theme'=>'glass', 'density'=>'normal'],
		'table_views'=>['orders'=>['active'=>['filters'=>['status'=>'active']]]],
	]), 0);
	$t->same(1, $base->revision());
	$themeCandidate=$base->with(array_replace_recursive($base->settings(), ['appearance'=>['theme'=>'flat']]));
	$theme=$store->save($themeCandidate, 1);
	$t->same(2, $theme->revision());
	$densityCandidate=$base->with(array_replace_recursive($base->settings(), ['appearance'=>['density'=>'compact']]));
	$merged=$store->save($densityCandidate, 1, 'merge');
	$t->same(3, $merged->revision());
	$t->same('flat', $merged->settings()['appearance']['theme']);
	$t->same('compact', $merged->settings()['appearance']['density']);

	$conflicting=$base->with(array_replace_recursive($base->settings(), ['appearance'=>['theme'=>'brutalist']]));
	$exception=$t->throws(static fn()=> $store->save($conflicting, 1, 'merge'), PanelPreferenceConflictException::class);
	$t->contains('settings.appearance.theme', $exception->conflicts());
	$t->same(3, $exception->currentRevision());
	$preferred=$store->save($conflicting, 1, 'merge_prefer_incoming');
	$t->same('brutalist', $preferred->settings()['appearance']['theme']);
	$t->same(4, $preferred->revision());
	$t->same([4, 3, 2, 1], array_map(static fn(PanelWorkspacePreferenceProfile $profile): int => $profile->revision(), $store->history('operator-7', 'operations')));
	$t->throws(static fn()=> $store->delete('operator-7', 'operations', 1), PanelPreferenceConflictException::class);
	$t->isTrue($store->delete('operator-7', 'operations', 4));
})->tag('panel', 'preferences', 'optimistic-locking', 'production')->group('panel-production-runtime');

test('panel workspace manager persists appearance views recents pins notifications devices and secret-free exports', static function(Context $t): void {
	$store=new PanelInMemoryPreferenceStore();
	$manager=new PanelWorkspacePreferences($store, 'operator-7', 'default', 'laptop');
	$manager->appearance('Glass', 'Compact', 'fr_CA', 'rtl');
	$manager->saveTableView('Orders', 'Risk Review', [
		'filters'=>['risk'=>'high'], 'sorts'=>[['column'=>'created_at', 'direction'=>'desc']],
		'groups'=>['market'], 'columns'=>['id'=>true, 'customer'=>true], 'page_size'=>50,
	]);
	$manager->touchRecent('resource', 'orders');
	$manager->touchRecent('command', 'create-order');
	$manager->touchRecent('resource', 'orders');
	$manager->pin('resource', 'orders');
	$manager->pin('command', 'open-search');
	$manager->unpin('command', 'open-search');
	$manager->notifications(['channels'=>['database', 'mail'], 'digest'=>'daily', 'webhook_token'=>'must-not-persist']);
	$manager->deviceOverrides('laptop', ['appearance'=>['density'=>'roomy'], 'table_views'=>['orders'=>['risk-review'=>['page_size'=>25]]]]);
	$resolved=$manager->resolved();
	$t->same('glass', $resolved['appearance']['theme']);
	$t->same('roomy', $resolved['appearance']['density']);
	$t->same('fr-CA', $resolved['appearance']['locale']);
	$t->same(25, $manager->tableView('orders', 'risk-review')['page_size']);
	$t->same(['resource', 'command'], array_column($resolved['recent'], 'type'));
	$t->same(['orders'], array_column($resolved['pinned'], 'id'));
	$t->same(['database', 'mail'], $resolved['notifications']['channels']);
	$t->isFalse(str_contains((string)json_encode($manager->profile()), 'must-not-persist'));
	$export=$manager->export();
	$t->same('panel_workspace_preferences_export', $export['type']);
	$t->isFalse(str_contains((string)json_encode($export), 'webhook_token'));

	$target=new PanelInMemoryPreferenceStore();
	$imported=$target->import($export, 'merge');
	$t->same(1, count($imported));
	$t->same('glass', $target->load('operator-7')?->settings()['appearance']['theme']);
	$crossUser=$export;
	$crossUser['profiles'][0]['user_id']='operator-8';
	$t->throws(static fn()=> $manager->import($crossUser), InvalidArgumentException::class);
	$t->isTrue($manager->manifest()['capabilities']['saved_table_views']);
})->tag('panel', 'preferences', 'workspace', 'production')->group('panel-production-runtime');

test('panel filesystem preferences persist history and issue reset snapshots after cursor retention', static function(Context $t): void {
	$directory=$t->tempDirectory('panel-preferences-filesystem');
	$store=new PanelFilesystemPreferenceStore($directory, 8, 20);
	$current=$store->save(new PanelWorkspacePreferenceProfile('operator-7', 'default', ['counter'=>0]), 0);
	for($index=1; $index<=12; $index++){
		$current=$store->save($current->with(['counter'=>$index]), $current->revision());
	}
	$t->same(13, $current->revision());
	$t->isTrue($store->changesSince(1)['reset_required']);
	$fresh=$store->changesSince(11);
	$t->isFalse($fresh['reset_required']);
	$t->same([12, 13], array_column($fresh['changes'], 'cursor'));
	$rehydrated=new PanelFilesystemPreferenceStore($directory, 8, 20);
	$t->same(12, $rehydrated->load('operator-7')?->settings()['counter']);
	$t->same(13, $rehydrated->cursor());
	$t->same(13, $rehydrated->history('operator-7')[0]->revision());
	$t->same('filesystem_atomic_json', $rehydrated->manifest()['adapter']);
})->tag('panel', 'preferences', 'filesystem', 'cursor', 'production')->group('panel-production-runtime');

test('panel filesystem preferences preserve disjoint stale writes across concurrent processes', static function(Context $t): void {
	$directory=$t->tempDirectory('panel-preferences-concurrency');
	$store=new PanelFilesystemPreferenceStore($directory, 64, 20);
	$store->save(new PanelWorkspacePreferenceProfile('operator-7', 'shared', ['counters'=>[]]), 0);
	$modules=dataphyre_path('runtime/modules');
	$worker=$t->tempFile(<<<'PHP'
<?php
declare(strict_types=1);
$modules=(string)$argv[1];
require_once $modules.'/panel/Framework/Notifications/PanelSnapshotStore.php';
require_once $modules.'/panel/Framework/Notifications/PanelAtomicSnapshotStore.php';
require_once $modules.'/panel/Framework/Preferences/PanelPreferenceStore.php';
require_once $modules.'/panel/Framework/Preferences/PanelPreferenceConflictException.php';
require_once $modules.'/panel/Framework/Preferences/PanelWorkspacePreferenceProfile.php';
require_once $modules.'/panel/Framework/Preferences/PanelPreferenceStateEngine.php';
require_once $modules.'/panel/Framework/Preferences/PanelFilesystemPreferenceStore.php';
$store=new \Dataphyre\Panel\PanelFilesystemPreferenceStore((string)$argv[2], 64, 20);
$worker=(string)$argv[3];
$candidate=new \Dataphyre\Panel\PanelWorkspacePreferenceProfile('operator-7', 'shared', ['counters'=>[$worker=>1]]);
$store->save($candidate, 1, 'merge_prefer_incoming');
PHP, 'panel-preference-worker');
	$processes=[];
	foreach(['alpha', 'beta', 'gamma', 'delta'] as $name){
		$processes[]=$t->startPhpProcess([$worker, $modules, $directory, $name]);
	}
	foreach($processes as $process){
		$result=$process->wait();
		$t->same(0, $result->exitCode(), trim($result->stdout().' '.$result->stderr()));
	}
	$profile=(new PanelFilesystemPreferenceStore($directory, 64, 20))->load('operator-7', 'shared');
	$t->same(5, $profile?->revision());
	$counters=$profile?->settings()['counters'] ?? [];
	ksort($counters);
	$t->same(['alpha'=>1, 'beta'=>1, 'delta'=>1, 'gamma'=>1], $counters);
})->tag('panel', 'preferences', 'concurrency', 'production')->group('panel-production-runtime');
