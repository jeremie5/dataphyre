<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Storage\Contracts\StorageDriver;
use Dataphyre\Storage\Drivers\MemoryDriver;
use Dataphyre\Storage\FileMetadata;
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
if(!defined('DP_STORAGE_CFG')){
	define('DP_STORAGE_CFG', [
		'default_disk'=>'memory',
		'disks'=>[
			'memory'=>['driver'=>'memory'],
			'guarded'=>[
				'driver'=>'memory', 'max_bytes'=>5,
				'allowed_extensions'=>['txt'], 'allowed_mime_types'=>['text/plain'],
			],
			'broken'=>['driver'=>'missing-driver'],
		],
	]);
}
$dp_storage_cov_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_storage_cov_modules_root.'/core/kernel/autoloader.php';
require_once $dp_storage_cov_modules_root.'/core/kernel/core_functions.php';
\dataphyre\autoloader::register($dp_storage_cov_modules_root);
\dataphyre\autoloader::register_framework_modules(['storage']);

test('storage manager covers memory IO guards uploads metadata events and custom disks', static function(Context $t): void {
	MemoryDriver::flush();
	$t->cleanup(static fn()=>MemoryDriver::flush());
	$manager=new StorageManager();
	$events=$t->state('storage.manager-events', ['events'=>[]]);
	$manager->listen('*', static function(array $event) use ($events): void { $events->append('events', $event['event']); });
	$manager->listen('custom', static function(array $event) use ($events): void { $events->append('events', 'exact:'.$event['value']); });
	$manager->emit('custom', ['value'=>'one']);
	$t->contains('custom', $events->get('events'));
	$t->contains('exact:one', $events->get('events'));
	$t->throws(static fn()=>$manager->listen('', static fn()=>null), InvalidArgumentException::class);
	$t->throws(static fn()=>$manager->extend('', static fn()=>null), InvalidArgumentException::class);
	$t->throws(static fn()=>$manager->disk('broken'), RuntimeException::class);
	$manager->extend('invalid', static fn()=>new stdClass());
	$t->throws(static fn()=>$manager->disk('invalid'), RuntimeException::class);
	$manager->extend('custom-memory', static fn(array $config, string $name, StorageManager $owner): StorageDriver=>new MemoryDriver($config));
	$t->instanceOf(StorageDriver::class, $manager->disk('custom-memory'));
	$t->isTrue($manager->disk()===$manager->disk('memory'));

	$t->isTrue($manager->put('docs/a.txt', 'alpha'));
	$t->isTrue($manager->exists('docs/a.txt'));
	$t->same('alpha', $manager->get('docs/a.txt'));
	$stream=$manager->readStream('docs/a.txt');
	$t->isTrue(is_resource($stream));
	$t->same('alpha', stream_get_contents($stream));
	$t->same(false, $manager->readStream('missing.txt'));
	$t->same(false, $manager->get('missing.txt'));
	$t->same(hash('sha256', 'alpha'), $manager->checksum('docs/a.txt'));
	$t->same(false, $manager->checksum('docs/a.txt', null, 'not-an-algorithm'));
	$t->same(false, $manager->checksum('missing.txt'));
	$t->instanceOf(FileMetadata::class, $manager->metadata('docs/a.txt'));
	$t->same(false, $manager->metadata('missing.txt'));
	$t->same(1, count($manager->list('docs')));

	$t->isTrue($manager->copy('docs/a.txt', 'docs/b.txt'));
	$t->same('alpha', $manager->get('docs/b.txt'));
	$t->isFalse($manager->copy('missing.txt', 'no.txt'));
	$t->isTrue($manager->move('docs/b.txt', 'docs/c.txt'));
	$t->isFalse($manager->exists('docs/b.txt'));
	$t->isFalse($manager->move('missing.txt', 'still-missing.txt'));
	$t->isTrue($manager->delete('docs/c.txt'));

	$upload=$t->workspace('storage-upload')->file('source.txt', 'file');
	$t->isTrue($manager->putFile('docs/file.txt', $upload));
	$t->isFalse($manager->putFile('docs/no.txt', $upload.'.missing'));
	$t->isFalse($manager->putUploadedFile('docs/error.txt', ['error'=>UPLOAD_ERR_NO_FILE]));
	$t->isFalse($manager->putUploadedFile('docs/missing.txt', ['error'=>UPLOAD_ERR_OK, 'tmp_name'=>$upload.'.missing']));
	$t->isTrue($manager->putUploadedFile('docs/upload.txt', [
		'error'=>UPLOAD_ERR_OK, 'tmp_name'=>$upload, 'name'=>'source.txt', 'type'=>'text/plain', 'size'=>4,
	]));

	$t->isFalse($manager->put('large.txt', '123456', 'guarded', ['content_type'=>'text/plain']));
	$t->isFalse($manager->put('bad.jpg', 'x', 'guarded', ['content_type'=>'text/plain']));
	$t->isFalse($manager->put('good.txt', 'x', 'guarded', ['content_type'=>'image/png']));
	$t->isTrue($manager->put('good.txt', 'okay', 'guarded', ['content_type'=>'text/plain']));
	$t->contains('storage.write', $events->get('events'));
	$t->contains('storage.read', $events->get('events'));
	$t->contains('storage.delete', $events->get('events'));
	$t->same('alpha', $manager->fakeSnapshot()['docs/a.txt']['body']);
	$manager->fakeFlush();
	$t->same([], $manager->fakeSnapshot());
	StorageManager::flushInstance();
	$t->isTrue(StorageManager::instance()===StorageManager::instance());
	StorageManager::flushInstance();
})->tag('storage', 'manager', 'coverage')->group('framework-coverage');

test('storage manager covers every unsupported advanced capability contract', static function(Context $t): void {
	MemoryDriver::flush();
	$t->cleanup(static fn()=>MemoryDriver::flush());
	$manager=new StorageManager();
	$t->same(false, $manager->temporaryUrl('a.txt', time()+60));
	$t->same(false, $manager->temporaryUploadUrl('a.jpg', time()+60, 'guarded'));
	$t->same(false, $manager->temporaryUploadUrl('a.txt', new DateTimeImmutable('+1 minute')));
	$t->same(false, $manager->initiateMultipartUpload('a.jpg', 'guarded'));
	$t->same(false, $manager->initiateMultipartUpload('a.txt'));
	$t->same(false, $manager->temporaryMultipartUploadUrls('a.jpg', 'upload', 2, time()+60, 'guarded'));
	$t->same(false, $manager->temporaryMultipartUploadUrls('a.txt', 'upload', 2, time()+60));
	$t->isFalse($manager->completeMultipartUpload('a.txt', 'upload', []));
	$t->isFalse($manager->abortMultipartUpload('a.txt', 'upload'));
	$t->same([], $manager->versions('a.txt'));
	$t->isFalse($manager->restoreVersion('a.txt', 'v1'));
	$t->isFalse($manager->purgeVersion('a.txt', 'v1'));
	$t->isFalse($manager->pruneVersions()['ok']);
	$t->isFalse($manager->verifyIntegrity('a.txt')['ok']);
	$t->isFalse($manager->integrityReport()['ok']);
	$t->same([], $manager->auditTrail());
	$t->isFalse($manager->deduplicationReport()['ok']);
	$t->isFalse($manager->quotaReport()['ok']);
	$t->isFalse($manager->cacheReport()['ok']);
	$t->isFalse($manager->purgeCache()['ok']);
	$t->isFalse($manager->compressionReport()['ok']);
	$t->isFalse($manager->setRetention('a.txt'));
	$t->isFalse($manager->releaseRetention('a.txt'));
	$t->isFalse($manager->retentionReport()['ok']);
	$t->isFalse($manager->lifecycleReport()['ok']);
	$t->isFalse($manager->applyLifecycle()['ok']);
	$t->isFalse($manager->scanReport()['ok']);
	$t->isFalse($manager->purgeQuarantine()['ok']);
	$t->isFalse($manager->tagObject('a.txt'));
	$t->same(['tags'=>[], 'metadata'=>[]], $manager->tagsFor('a.txt'));
	$t->same([], $manager->findByTags(['one']));
	$t->isFalse($manager->tagReport()['ok']);
	$t->isFalse($manager->policyReport()['ok']);
	$t->isFalse($manager->rateLimitReport()['ok']);
	$t->isFalse($manager->resetRateLimits()['ok']);
	$t->same([], $manager->eventTrail());
})->tag('storage', 'manager', 'coverage')->group('framework-coverage');
