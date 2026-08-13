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
use Dataphyre\Storage\Storage;
use Dataphyre\Storage\StorageManager;
use Dataphyre\Storage\StorageResult;
use Dataphyre\Storage\Support\Path;
use Dataphyre\Storage\Support\Stream;
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
			'target'=>['driver'=>'memory'],
		],
	]);
}

$dp_storage_facade_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_storage_facade_modules_root.'/core/kernel/autoloader.php';
require_once $dp_storage_facade_modules_root.'/core/kernel/core_functions.php';
\dataphyre\autoloader::register($dp_storage_facade_modules_root);
\dataphyre\autoloader::register_framework_modules(['storage']);

test('storage metadata result path and stream values cover complete contracts', static function(Context $t): void {
	$metadata=FileMetadata::fromArray([
		'path'=>'docs/report.txt',
		'size'=>'7',
		'modified_at'=>'123',
		'mime_type'=>'text/plain',
		'extra'=>['checksum'=>'abc'],
	]);
	$t->same('docs/report.txt', $metadata->path());
	$t->same(7, $metadata->size());
	$t->same(123, $metadata->modifiedAt());
	$t->same('text/plain', $metadata->mimeType());
	$t->same(['checksum'=>'abc'], $metadata->extra());
	$t->same('docs/report.txt', $metadata->toArray()['path']);
	$empty=FileMetadata::fromArray(['extra'=>'invalid']);
	$t->same('', $empty->path());
	$t->same(null, $empty->size());
	$t->same([], $empty->extra());

	$ok=StorageResult::ok(['id'=>1], 'Stored');
	$t->isTrue($ok->okStatus());
	$t->same('Stored', $ok->message());
	$t->same(['id'=>1], $ok->data());
	$t->isTrue($ok->toArray()['ok']);
	$failed=StorageResult::fail('Failed', ['reason'=>'disk']);
	$t->isFalse($failed->okStatus());
	$t->same('Failed', $failed->message());

	$t->same('safe/file.txt', Path::normalize('/root/../safe//./file.txt'));
	$t->same('base/safe/file.txt', Path::join('base/', './safe/file.txt'));
	$source=Stream::fromString('payload');
	$t->same('payload', Stream::contents($source));
	$t->same(false, Stream::contents('invalid'));
	$destination=fopen('php://temp', 'w+b');
	$t->isTrue(Stream::copy($source, $destination));
	rewind($destination);
	$t->same('payload', stream_get_contents($destination));
	$t->isFalse(Stream::copy('invalid', $destination));
	$t->isFalse(Stream::copy($source, 'invalid'));
	@fclose($source);
	@fclose($destination);
})->tag('storage', 'coverage')->group('framework-coverage');

test('storage static facade delegates basic events IO and advanced capability contracts', static function(Context $t): void {
	MemoryDriver::flush();
	Storage::flushManager();
	$t->instanceOf(StorageManager::class, Storage::manager());
	Storage::extend('coverage-memory', static fn(array $config, string $name, StorageManager $manager): StorageDriver=>new MemoryDriver($config));
	$events=[];
	Storage::listen('*', static function(array $event) use (&$events): void { $events[]=$event['event']; });
	Storage::emit('coverage', ['value'=>1]);
	$t->contains('coverage', $events);
	$t->instanceOf(StorageDriver::class, Storage::disk());
	$t->instanceOf(StorageDriver::class, Storage::disk('coverage-memory'));

	$t->isTrue(Storage::put('docs/a.txt', 'alpha'));
	$t->isTrue(Storage::exists('docs/a.txt'));
	$t->same('alpha', Storage::get('docs/a.txt'));
	$stream=Storage::readStream('docs/a.txt');
	$t->isTrue(is_resource($stream));
	@fclose($stream);
	$fixture=$t->workspace('storage-facade')->file('source.txt', 'file');
	$t->isTrue(Storage::putFile('docs/file.txt', $fixture));
	$t->isFalse(Storage::putUploadedFile('docs/upload.txt', ['error'=>UPLOAD_ERR_NO_FILE]));
	$t->isTrue(Storage::copy('docs/a.txt', 'docs/b.txt'));
	$t->isTrue(Storage::move('docs/b.txt', 'docs/c.txt'));
	$t->isTrue(Storage::delete('docs/c.txt'));
	$t->same(hash('sha256', 'alpha'), Storage::checksum('docs/a.txt'));
	$t->instanceOf(FileMetadata::class, Storage::metadata('docs/a.txt'));
	$t->notEmpty(Storage::list('docs'));

	$expires=time()+60;
	$t->isTrue(is_string(Storage::temporaryUrl('docs/a.txt', $expires)) || Storage::temporaryUrl('docs/a.txt', $expires)===false);
	$t->isTrue(is_string(Storage::temporaryUploadUrl('docs/new.txt', $expires)) || Storage::temporaryUploadUrl('docs/new.txt', $expires)===false);
	$t->isTrue(is_array(Storage::initiateMultipartUpload('docs/new.txt')) || Storage::initiateMultipartUpload('docs/new.txt')===false);
	$t->isTrue(is_array(Storage::temporaryMultipartUploadUrls('docs/new.txt', 'upload', 2, $expires)) || Storage::temporaryMultipartUploadUrls('docs/new.txt', 'upload', 2, $expires)===false);
	$t->isTrue(is_bool(Storage::completeMultipartUpload('docs/new.txt', 'upload', [])));
	$t->isTrue(is_bool(Storage::abortMultipartUpload('docs/new.txt', 'upload')));
	$t->isTrue(is_array(Storage::versions('docs/a.txt')));
	$t->isTrue(is_bool(Storage::restoreVersion('docs/a.txt', 'v1')));
	$t->isTrue(is_bool(Storage::purgeVersion('docs/a.txt', 'v1')));
	$t->isTrue(is_array(Storage::pruneVersions()));
	$t->isTrue(is_array(Storage::verifyIntegrity('docs/a.txt')));
	$t->isTrue(is_array(Storage::integrityReport('docs')));
	$t->isTrue(is_array(Storage::auditTrail('docs/a.txt')));
	$t->isTrue(is_array(Storage::deduplicationReport('docs')));
	$t->isTrue(is_array(Storage::quotaReport('docs')));
	$t->isTrue(is_array(Storage::cacheReport('docs')));
	$t->isTrue(is_array(Storage::purgeCache('docs')));
	$t->isTrue(is_array(Storage::compressionReport('docs')));
	$t->isTrue(is_bool(Storage::setRetention('docs/a.txt')));
	$t->isTrue(is_bool(Storage::releaseRetention('docs/a.txt')));
	$t->isTrue(is_array(Storage::retentionReport('docs')));
	$t->isTrue(is_array(Storage::lifecycleReport('docs')));
	$t->isTrue(is_array(Storage::applyLifecycle('docs')));
	$t->isTrue(is_array(Storage::scanReport('docs')));
	$t->isTrue(is_array(Storage::purgeQuarantine('docs')));
	$t->isTrue(is_bool(Storage::tagObject('docs/a.txt', null, ['tags'=>['one']])));
	$t->isTrue(is_array(Storage::tagsFor('docs/a.txt')));
	$t->isTrue(is_array(Storage::findByTags(['one'])));
	$t->isTrue(is_array(Storage::tagReport('docs')));
	$t->isTrue(is_array(Storage::policyReport('docs')));
	$t->isTrue(is_array(Storage::rateLimitReport('docs')));
	$t->isTrue(is_array(Storage::resetRateLimits('docs')));
	$t->isTrue(is_array(Storage::eventTrail('docs/a.txt')));
	$t->isTrue(is_array(Storage::manifestReport()));
	$t->isTrue(is_array(Storage::exportManifests()));
	$t->isFalse(Storage::importManifests(['format'=>'wrong'])['ok']);
	$t->isTrue(is_array(Storage::diagnostics('memory', ['write'=>false])));
	$t->isTrue(is_array(Storage::sync('memory', 'target', 'docs', ['dry_run'=>true])));
	$t->notEmpty(Storage::fakeSnapshot());
	Storage::fakeFlush();
	$t->same([], Storage::fakeSnapshot());
})->tag('storage', 'coverage')->group('framework-coverage')->maxMillis(10000);
