<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Reactor\ReactorFileSnapshotVersionStore;
use Dataphyre\Reactor\ReactorInMemorySnapshotVersionStore;
use Dataphyre\Reactor\ReactorSnapshotVersionStore;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['reactor','mvc']);

test('reactor memory snapshot store reserves finalizes aborts and classifies stale concurrency deterministically', static function(Context $t): void {
	$store=new ReactorInMemorySnapshotVersionStore();
	$id=str_repeat('a', 32);
	$scope=str_repeat('b', 64);
	$expires=time()+300;
	$reservation=str_repeat('c', 32);
	$t->isTrue($store->register($id, $scope, 'orders', 0, $expires));
	$t->isTrue($store->register($id, $scope, 'orders', 0, $expires));
	$t->same(ReactorSnapshotVersionStore::CLAIMED, $store->reserve($id, $scope, 'orders', 0, $reservation, time()+30));
	$t->same(ReactorSnapshotVersionStore::BUSY, $store->reserve($id, $scope, 'orders', 0, str_repeat('d', 32), time()+30));
	$t->isFalse($store->abort($id, $scope, 'orders', 0, str_repeat('d', 32)));
	$t->isTrue($store->abort($id, $scope, 'orders', 0, $reservation));
	$t->same(ReactorSnapshotVersionStore::CLAIMED, $store->reserve($id, $scope, 'orders', 0, $reservation, time()+30));
	$t->same(ReactorSnapshotVersionStore::CLAIMED, $store->finalize($id, $scope, 'orders', 0, 1, time()+300, $reservation));
	$t->same(ReactorSnapshotVersionStore::STALE, $store->reserve($id, $scope, 'orders', 0, $reservation, time()+30));
	$t->same(ReactorSnapshotVersionStore::FUTURE, $store->reserve($id, $scope, 'orders', 2, $reservation, time()+30));
	$t->same(ReactorSnapshotVersionStore::MISMATCH, $store->reserve($id, str_repeat('e', 64), 'orders', 1, $reservation, time()+30));
	$t->same(ReactorSnapshotVersionStore::MISSING, $store->reserve(str_repeat('f', 32), $scope, 'orders', 0, $reservation, time()+30));
	$t->same(ReactorSnapshotVersionStore::UNAVAILABLE, $store->reserve('../escape', $scope, 'orders', 0, $reservation, time()+30));
	$boundaryId=str_repeat('4', 32);
	$t->isTrue($store->register($boundaryId, $scope, 'orders', 0, time()));
	$t->same(ReactorSnapshotVersionStore::EXPIRED, $store->reserve($boundaryId, $scope, 'orders', 0, $reservation, time()+30));
	$boundedLeaseId=str_repeat('5', 32);
	$t->isTrue($store->register($boundedLeaseId, $scope, 'orders', 0, time()+600));
	$t->same(ReactorSnapshotVersionStore::UNAVAILABLE, $store->reserve($boundedLeaseId, $scope, 'orders', 0, $reservation, time()+3600));
	$shortSnapshotId=str_repeat('6', 32);
	$t->isTrue($store->register($shortSnapshotId, $scope, 'orders', 0, time()+60));
	$t->same(ReactorSnapshotVersionStore::UNAVAILABLE, $store->reserve($shortSnapshotId, $scope, 'orders', 0, $reservation, time()+120));

	$expiredId=str_repeat('1', 32);
	$t->isTrue($store->register($expiredId, $scope, 'orders', 0, time()-1));
	$t->same(ReactorSnapshotVersionStore::EXPIRED, $store->reserve($expiredId, $scope, 'orders', 0, $reservation, time()+30));
	$leaseId=str_repeat('2', 32);
	$t->isTrue($store->register($leaseId, $scope, 'orders', 0, time()+300));
	$t->same(ReactorSnapshotVersionStore::UNAVAILABLE, $store->reserve($leaseId, $scope, 'orders', 0, $reservation, time()-1));
	$t->same(ReactorSnapshotVersionStore::UNAVAILABLE, $store->reserve($leaseId, $scope, 'orders', 0, $reservation, time()));
	$revokedId=str_repeat('3', 32);
	$t->isTrue($store->register($revokedId, $scope, 'orders', 0, time()+300));
	$t->isTrue($store->revoke($revokedId, $scope, 'orders', 0));
	$t->same(ReactorSnapshotVersionStore::MISSING, $store->reserve($revokedId, $scope, 'orders', 0, $reservation, time()+30));
	$t->isTrue($store->manifest()['reservation_finalize_abort']);
	$t->isFalse($store->manifest()['production_safe']);
	$t->same('expires_at_lte_now_is_expired', $store->manifest()['expiry_boundary']);
})->tag('reactor','security','snapshot-store','memory','cas')->maxMillis(1000);

test('reactor file snapshot store confines immutable generations rejects symlinks and prunes bounded expired entries', static function(Context $t): void {
	$root=$t->workspace('reactor-file-snapshot-security')->root();
	if(DIRECTORY_SEPARATOR==='/'){
		$preexisting=$root.DIRECTORY_SEPARATOR.'preexisting-wide-ledger';
		mkdir($preexisting, 0777, true);
		chmod($preexisting, 0755);
		clearstatcache(true, $preexisting);
		$before=fileperms($preexisting);
		$t->isTrue($before!==false && ($before & 0077)!==0);
		new ReactorFileSnapshotVersionStore($preexisting);
		clearstatcache(true, $preexisting);
		$after=fileperms($preexisting);
		$t->isTrue($after!==false);
		$t->same(0, $after & 0077);
	}
	else{
		$t->isTrue(true);
	}
	$directory=$root.DIRECTORY_SEPARATOR.'ledger';
	$store=new ReactorFileSnapshotVersionStore($directory, 8, 8);
	$id=str_repeat('a', 32);
	$scope=str_repeat('b', 64);
	$reservation=str_repeat('c', 32);
	$t->isTrue($store->register($id, $scope, 'orders', 0, time()+300));
	$t->same(ReactorSnapshotVersionStore::CLAIMED, $store->reserve($id, $scope, 'orders', 0, $reservation, time()+30));
	$t->same(ReactorSnapshotVersionStore::CLAIMED, $store->finalize($id, $scope, 'orders', 0, 1, time()+300, $reservation));
	$files=glob($directory.DIRECTORY_SEPARATOR.$id.'.*.json') ?: [];
	$t->isTrue(count($files)>=1 && count($files)<=2);
	$serialized=implode('', array_map(static fn(string $file): string=>(string)file_get_contents($file), $files));
	$t->notContains('customer-secret-state', $serialized);
	$t->notContains($directory, json_encode($store->manifest(), JSON_THROW_ON_ERROR));
	$t->same('immutable_generation_temp_plus_atomic_rename', $store->manifest()['write_strategy']);
	$t->isFalse($store->manifest()['production_safe']);
	$t->isTrue((new ReactorFileSnapshotVersionStore($root.DIRECTORY_SEPARATOR.'attested', sharedFilesystemAttested:true))->manifest()['production_safe']);
	$t->same(ReactorSnapshotVersionStore::STALE, $store->reserve($id, $scope, 'orders', 0, $reservation, time()+30));
	$t->isFalse($store->register('../outside', $scope, 'orders', 0, time()+30));
	$boundaryId=str_repeat('8', 32);
	$t->isTrue($store->register($boundaryId, $scope, 'orders', 0, time()));
	$t->same(ReactorSnapshotVersionStore::EXPIRED, $store->reserve($boundaryId, $scope, 'orders', 0, $reservation, time()+30));
	$boundedLeaseId=str_repeat('9', 32);
	$t->isTrue($store->register($boundedLeaseId, $scope, 'orders', 0, time()+600));
	$t->same(ReactorSnapshotVersionStore::UNAVAILABLE, $store->reserve($boundedLeaseId, $scope, 'orders', 0, $reservation, time()+3600));
	$t->same(ReactorSnapshotVersionStore::UNAVAILABLE, $store->reserve($boundedLeaseId, $scope, 'orders', 0, $reservation, time()));

	$aborted=str_repeat('d', 32);
	$t->isTrue($store->register($aborted, $scope, 'orders', 0, time()+300));
	$t->same(ReactorSnapshotVersionStore::CLAIMED, $store->reserve($aborted, $scope, 'orders', 0, $reservation, time()+30));
	$t->isTrue($store->abort($aborted, $scope, 'orders', 0, $reservation));
	$t->same(ReactorSnapshotVersionStore::CLAIMED, $store->reserve($aborted, $scope, 'orders', 0, $reservation, time()+30));
	$t->isTrue($store->abort($aborted, $scope, 'orders', 0, $reservation));
	$t->isTrue($store->revoke($aborted, $scope, 'orders', 0));
	$t->same([], glob($directory.DIRECTORY_SEPARATOR.$aborted.'.*.json') ?: []);

	$expired=str_repeat('e', 32);
	$t->isTrue($store->register($expired, $scope, 'orders', 0, time()-1));
	$t->isTrue($store->register(str_repeat('f', 32), $scope, 'orders', 0, time()+300));
	$t->same([], glob($directory.DIRECTORY_SEPARATOR.$expired.'.*.json') ?: []);

	$boundedDirectory=$root.DIRECTORY_SEPARATOR.'bounded';
	$bounded=new ReactorFileSnapshotVersionStore($boundedDirectory, 1, 4);
	$t->isTrue($bounded->register(str_repeat('1', 32), $scope, 'orders', 0, time()+300));
	$t->isFalse($bounded->register(str_repeat('2', 32), $scope, 'orders', 0, time()+300));

	$corruptDirectory=$root.DIRECTORY_SEPARATOR.'corrupt';
	$corrupt=new ReactorFileSnapshotVersionStore($corruptDirectory);
	$corruptId=str_repeat('3', 32);
	$corruptFile=$corruptDirectory.DIRECTORY_SEPARATOR.$corruptId.'.000000000001.'.str_repeat('4', 16).'.json';
	file_put_contents($corruptFile, '{"scope_hash":"unexpected-only"}');
	$t->same(ReactorSnapshotVersionStore::UNAVAILABLE, $corrupt->reserve($corruptId, $scope, 'orders', 0, $reservation, time()+30));

	$target=$root.DIRECTORY_SEPARATOR.'symlink-target';
	if(!is_dir($target)){ mkdir($target, 0700, true); }
	$link=$root.DIRECTORY_SEPARATOR.'symlink-ledger';
	if(function_exists('symlink') && @symlink($target, $link)){
		$t->throws(static fn()=>new ReactorFileSnapshotVersionStore($link), RuntimeException::class);
	}
	else{
		$t->isTrue(true);
	}

	$symlinkDirectory=$root.DIRECTORY_SEPARATOR.'record-symlink';
	$symlinkStore=new ReactorFileSnapshotVersionStore($symlinkDirectory);
	$symlinkId=str_repeat('5', 32);
	$targetFile=$root.DIRECTORY_SEPARATOR.'record-target.json';
	file_put_contents($targetFile, '{}');
	$recordLink=$symlinkDirectory.DIRECTORY_SEPARATOR.$symlinkId.'.000000000001.'.str_repeat('6', 16).'.json';
	if(function_exists('symlink') && @symlink($targetFile, $recordLink)){
		$t->same(ReactorSnapshotVersionStore::UNAVAILABLE, $symlinkStore->reserve($symlinkId, $scope, 'orders', 0, $reservation, time()+30));
	}
	else{
		$t->isTrue(true);
	}
})->tag('reactor','security','snapshot-store','filesystem','confinement')->maxMillis(3000);
