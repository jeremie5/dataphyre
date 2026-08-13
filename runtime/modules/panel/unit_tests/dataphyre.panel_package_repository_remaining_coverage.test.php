<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelPackageLock;
use Dataphyre\Panel\PanelPackageRepository;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

final class DpPanelPackageUnreadableDirectory {
	public mixed $context;
	/** @return array<string|int,int>|false */
	public function url_stat(string $path, int $flags): array|false {
		if(str_ends_with($path,'.json')){
			return false;
		}
		return [2=>0040777,'mode'=>0040777];
	}
	public function dir_opendir(string $path, int $options): bool {
		return false;
	}
}

test('panel package repository discovers artifacts directories errors and skipped dependency trees',static function(Context $t): void {
	$runtime=['php'=>PHP_VERSION,'panel'=>'1.0.0','reactor'=>'1.0.0','modules'=>[],'themes'=>[]];
	$repository=PanelPackageRepository::make([
		['id'=>'initial-package','version'=>'1.0.0'],
	],$runtime);
	$repository->register(['id'=>'sourced-package','version'=>'2.0.0'],[],'manual-source');
	$repository->register(['id'=>'blank-source-package'],[],'   ');
	$repository->meta(['owner'=>'panel'])->meta('environment','test')->meta('   ','ignored');
	$repository->discoverArtifacts([
		'not-an-artifact',
		['path'=>'bundle/other.json','contents'=>'{}'],
		['path'=>'bundle/dataphyre-panel-package.json','contents'=>'{"id":"artifact-package","version":"3.0.0"}'],
		['path'=>'broken/dataphyre-panel-package.json','contents'=>'{'],
	],'compiled');

	$workspace=$t->workspace('dataphyre-panel-package-repository');
	$root=$workspace->directory('root');
	$child=$root.DIRECTORY_SEPARATOR.'child';
	$vendor=$root.DIRECTORY_SEPARATOR.'vendor';
	$nodeModules=$root.DIRECTORY_SEPARATOR.'node_modules';
	mkdir($child,0777,true);
	mkdir($vendor,0777,true);
	mkdir($nodeModules,0777,true);
	file_put_contents($root.DIRECTORY_SEPARATOR.'dataphyre-panel-package.json','{"id":"root-package","version":"1.0.0"}');
	file_put_contents($child.DIRECTORY_SEPARATOR.'dataphyre-panel-package.json','{');
	file_put_contents($vendor.DIRECTORY_SEPARATOR.'dataphyre-panel-package.json','{"id":"vendor-package"}');
	file_put_contents($nodeModules.DIRECTORY_SEPARATOR.'dataphyre-panel-package.json','{"id":"node-package"}');

	$depthRoot=$workspace->directory('depth');
	$depthChild=$depthRoot.DIRECTORY_SEPARATOR.'child';
	mkdir($depthChild,0777,true);
	file_put_contents($depthChild.DIRECTORY_SEPARATOR.'dataphyre-panel-package.json','{"id":"too-deep-package"}');
	$repository->discover([$root,$root.DIRECTORY_SEPARATOR.'missing'],2);
	$repository->discover($depthRoot,-1);
	$ids=array_map(static fn($package): string=>$package->id(),$repository->packages());
	$t->isTrue(in_array('root-package',$ids,true));
	$t->isFalse(in_array('vendor-package',$ids,true));
	$t->isFalse(in_array('node-package',$ids,true));
	$t->isFalse(in_array('too-deep-package',$ids,true));

	$missingManifest=$t->workspace('panel-package-manifest')->path('missing.json');
	$t->nonPublic($repository)->invoke('readManifest',$missingManifest);
	if(!in_array('dppkgunreadable',stream_get_wrappers(),true)){
		stream_wrapper_register('dppkgunreadable',DpPanelPackageUnreadableDirectory::class);
	}
	$repository->discover('dppkgunreadable://root',1);

	$manifest=$repository->manifest(['request'=>'coverage']);
	$t->same('panel_package_repository',$manifest['type']);
	$t->same(count($repository->packages()),$manifest['package_count']);
	$t->same('panel',$manifest['meta']['owner']);
	$t->same('coverage',$manifest['meta']['request']);
	$t->isTrue($manifest['source_count']>=3);
	$t->isTrue($manifest['error_count']>=4);
	$t->same($repository->toArray(),$repository->jsonSerialize());

	$matrix=$repository->matrix();
	$t->same(count($repository->packages()),$matrix->manifest()['package_count']);
	$lock=$repository->lock();
	$t->instanceOf(PanelPackageLock::class,$lock);
	$lockManifest=$repository->lockManifest(['locked'=>true]);
	$t->same('panel_package_lock',$lockManifest['type']);
	$t->same(true,$lockManifest['meta']['locked']);
	$t->same(64,strlen($lockManifest['checksum']));
	$t->same(array_keys($lockManifest['packages']),array_values(array_unique(array_keys($lockManifest['packages']))));
})->tag('panel','package-repository','coverage')->group('framework-coverage');
