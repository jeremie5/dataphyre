<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Storage\Drivers\MemoryDriver;
use Dataphyre\Storage\StorageManager;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'storage'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_storage_manifest_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_storage_manifest_modules_root.'/core/kernel/autoloader.php';
require_once $dp_storage_manifest_modules_root.'/core/kernel/core_functions.php';
\dataphyre\autoloader::register($dp_storage_manifest_modules_root);

test('storage manager covers manifest bundles diagnostics and real cross disk synchronization', static function(Context $t): void {
	$workspace=$t->workspace('storage-manager-manifests');
	if(!defined('DP_STORAGE_CFG')){
		define('DP_STORAGE_CFG', [
			'default_disk'=>'memory',
			'disks'=>[
				'memory'=>['driver'=>'memory'],
				'target'=>['driver'=>'local', 'root'=>$workspace->path('target')],
				'local'=>[
					'driver'=>'local',
					'root'=>$workspace->path('local'),
					'manifest'=>$workspace->path('manifests/local.json'),
					'log'=>$workspace->path('manifests/local.log'),
				],
				'broken'=>['driver'=>'missing-driver'],
			],
		]);
	}
	\dataphyre\autoloader::register_framework_modules(['storage']);
	MemoryDriver::flush();
	$t->cleanup(static fn()=>MemoryDriver::flush());

	$manager=new StorageManager();
	$manifest=$workspace->file('manifests/local.json', (string)json_encode(['items'=>['a'=>1]]));
	$workspace->file('manifests/local.log', 'not-json');
	$exportPath=$workspace->path('manifests/bundle.json');

	$report=$manager->manifestReport('local');
	$t->same(2, count($report['manifests']));
	$t->isTrue($report['manifests']['local.manifest']['exists']);
	$bundle=$manager->exportManifests('local', ['path'=>$exportPath]);
	$t->same('dataphyre-storage-manifests', $bundle['format']);
	$t->same(['items'=>['a'=>1]], $bundle['manifests']['local.manifest']['data']);
	$t->same(['_raw'=>'not-json'], $bundle['manifests']['local.log']['data']);
	$t->isTrue(is_file($exportPath));
	$t->isFalse($manager->importManifests(['format'=>'wrong'])['ok']);
	$bundle['manifests']['local.manifest']['data']=['items'=>['b'=>2]];
	$t->same(2, $manager->importManifests($bundle, ['mode'=>'merge'])['imported']);
	$t->same(['items'=>['a'=>1, 'b'=>2]], json_decode((string)file_get_contents($manifest), true));
	$t->isTrue($manager->importManifests($exportPath)['ok']);

	$diagnostic=$manager->diagnostics('memory', ['write'=>true, 'probe_prefix'=>'health']);
	$t->same(1, $diagnostic['checked']);
	$t->isTrue($diagnostic['disks']['memory']['checks']['write']);
	$broken=$manager->diagnostics('broken', ['write'=>false]);
	$t->isFalse($broken['ok']);

	$manager->put('sync/a.txt', 'one', 'memory');
	$manager->put('sync/b.txt', 'two', 'memory');
	$manager->put('sync/extra.txt', 'extra', 'target');
	$dry=$manager->sync('memory', 'target', 'sync', ['dry_run'=>true, 'delete_extra'=>true, 'compare'=>'size']);
	$t->same(2, $dry['counts']['copied']);
	$t->same(1, $dry['counts']['deleted']);
	$live=$manager->sync('memory', 'target', 'sync', ['dry_run'=>false, 'delete_extra'=>true]);
	$t->isTrue($live['ok']);
	$t->same('one', $manager->get('sync/a.txt', 'target'));
	$t->isFalse($manager->exists('sync/extra.txt', 'target'));
	$repeat=$manager->sync('memory', 'target', 'sync', ['dry_run'=>false, 'compare'=>'checksum']);
	$t->same(2, $repeat['counts']['skipped']);
})->tag('storage', 'manager', 'coverage')->group('framework-coverage');
