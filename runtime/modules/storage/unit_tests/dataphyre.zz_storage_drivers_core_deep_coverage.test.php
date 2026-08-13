<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Storage\Contracts\StorageDriver;
use Dataphyre\Storage\Drivers\CachedDriver;
use Dataphyre\Storage\Drivers\DeduplicatedDriver;
use Dataphyre\Storage\Drivers\RetentionDriver;
use Dataphyre\Storage\Drivers\S3CompatibleDriver;
use Dataphyre\Storage\Drivers\VersionedDriver;
use Dataphyre\Storage\Drivers\VestraDriver;
use Dataphyre\Storage\FileMetadata;
use Dataphyre\Storage\StorageManager;
use Dataphyre\Storage\Support\Stream;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'storage'=>true, 'vestra'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_storage_driver_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_storage_driver_modules_root.'/core/kernel/autoloader.php';
require_once $dp_storage_driver_modules_root.'/core/kernel/core_functions.php';
\dataphyre\autoloader::register($dp_storage_driver_modules_root);
\dataphyre\autoloader::register_framework_modules(['storage']);

if(!function_exists('Dataphyre\\Storage\\Drivers\\sys_get_temp_dir')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Storage\Drivers;
function sys_get_temp_dir(): string { // dataphyre-test-architecture: exempt[unmanaged-system-temporary-directory] reason="Storage namespace shim redirects production temporary paths into TestState."
	$state=\Dataphyre\Test\TestState::channelIfActive('storage.vestra-temp-directory');
	return $state===null
		? \sys_get_temp_dir() // dataphyre-test-architecture: exempt[unmanaged-system-temporary-directory] reason="Inactive storage scenarios preserve the production native-directory fallback."
		: (string)$state->get('path');
}
PHP);
}

/** In-memory probe disk with controllable failures for storage decorator coverage. */
final class DpStorageDriverProbe implements StorageDriver {
	/** @var array<string,array{body:string,options:array<string,mixed>,time:int}> */
	public array $objects=[];
	/** @var array<int,array{path:string,options:array<string,mixed>}> */
	public array $writes=[];
	/** @var list<string> */
	public array $deletes=[];
	/** @var list<string> */
	public array $failWritePaths=[];
	/** @var list<string> */
	public array $failWritePrefixes=[];
	/** @var list<string> */
	public array $failDeletePaths=[];
	/** @var list<string> */
	public array $failStreamPaths=[];
	/** @var list<string> */
	public array $failMetadataPaths=[];
	public bool $writeEnabled=true;
	public bool $deleteEnabled=true;

	public function seed(string $path, string $body, array $options=[]): void {
		$this->objects[$path]=['body'=>$body, 'options'=>$options, 'time'=>(int)($options['modified_at'] ?? time())];
	}

	public function exists(string $path): bool {
		return isset($this->objects[$path]);
	}

	public function read(string $path, array $options=[]): string|false {
		return isset($this->objects[$path]) ? $this->objects[$path]['body'] : false;
	}

	public function readStream(string $path, array $options=[]): mixed {
		if(in_array($path, $this->failStreamPaths, true) || !isset($this->objects[$path])){
			return false;
		}
		return Stream::fromString($this->objects[$path]['body']);
	}

	public function write(string $path, mixed $contents, array $options=[]): bool {
		$this->writes[]=['path'=>$path, 'options'=>$options];
		if(!$this->writeEnabled || in_array($path, $this->failWritePaths, true)){
			return false;
		}
		foreach($this->failWritePrefixes as $prefix){
			if(str_starts_with($path, $prefix)){
				return false;
			}
		}
		$body=is_resource($contents) ? Stream::contents($contents) : (string)$contents;
		if($body===false){ return false; }
		$this->seed($path, $body, $options);
		return true;
	}

	public function delete(string $path): bool {
		$this->deletes[]=$path;
		if(!$this->deleteEnabled || in_array($path, $this->failDeletePaths, true)){ return false; }
		unset($this->objects[$path]);
		return true;
	}

	public function metadata(string $path): FileMetadata|false {
		if(in_array($path, $this->failMetadataPaths, true) || !isset($this->objects[$path])){
			return false;
		}
		$row=$this->objects[$path];
		return new FileMetadata(
			$path,
			strlen($row['body']),
			$row['time'],
			is_string($row['options']['content_type'] ?? null) ? $row['options']['content_type'] : null,
			['probe'=>true]
		);
	}

	public function list(string $prefix='', array $options=[]): array {
		$out=[];
		foreach(array_keys($this->objects) as $path){
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, rtrim($prefix, '/').'/')){
				continue;
			}
			$metadata=$this->metadata($path);
			if($metadata instanceof FileMetadata){
				$out[]=$metadata;
			}
		}
		return $out;
	}

	public function temporaryUrl(string $path, int|DateTimeInterface $expires, array $options=[]): string|false {
		if(!$this->exists($path)){ return false; }
		$expires=$expires instanceof DateTimeInterface ? $expires->getTimestamp() : $expires;
		return 'probe://'.rawurlencode($path).'?expires='.$expires;
	}
}

/** Readable-but-not-writable virtual manifest used to cover persistence failures. */
final class DpStorageReadOnlyManifestWrapper {
	public mixed $context=null;
	private int $offset=0;

	private static function contents(): string {
		$state=\Dataphyre\Test\TestState::channelIfActive('storage.read-only-manifest');
		return $state===null ? '{}' : (string)$state->get('contents', '{}');
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		if(strpbrk($mode, 'waxc+')!==false){ return false; }
		$this->offset=0;
		return true;
	}

	public function stream_read(int $count): string {
		$chunk=substr(self::contents(), $this->offset, $count);
		$this->offset+=strlen($chunk);
		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->offset>=strlen(self::contents());
	}

	public function stream_stat(): array {
		return $this->url_stat('dpreadonly://manifest', 0);
	}

	public function url_stat(string $path, int $flags): array {
		$size=strlen(self::contents());
		return ['mode'=>0100444, 'size'=>$size, 2=>0100444, 7=>$size];
	}
}
if(!in_array('dpreadonly', stream_get_wrappers(), true)){
	stream_wrapper_register('dpreadonly', DpStorageReadOnlyManifestWrapper::class);
}

test('storage driver core deep coverage versioned lifecycle and pruning', static function(Context $t): void {
	$workspace=$t->workspace('storage-versioned');
	$manifest=$workspace->path('nested/versions.json');
	$source=new DpStorageDriverProbe();
	$manager=new StorageManager();
	$manager->extend('dp_version_source', static fn(array $config, string $name, StorageManager $owner): StorageDriver=>$source);
	$t->throws(static fn()=>new VersionedDriver([], $manager), RuntimeException::class);
	$driver=new VersionedDriver([
		'target'=>'dp_version_source',
		'prefix'=>'_versions',
		'manifest'=>$manifest,
		'keep'=>2,
	], $manager);
		$t->isFalse($driver->exists('docs/a.txt'));
		$t->isTrue($driver->write('docs/a.txt', 'one', ['content_type'=>'text/plain']));
		$t->isTrue($driver->exists('docs/a.txt'));
		$t->same('one', $driver->read('docs/a.txt'));
		$stream=$driver->readStream('docs/a.txt');
		$t->isTrue(is_resource($stream));
		$t->same('one', stream_get_contents($stream));
		$t->instanceOf(FileMetadata::class, $driver->metadata('docs/a.txt'));
		$t->contains('probe://', (string)$driver->temporaryUrl('docs/a.txt', new DateTimeImmutable('+1 minute')));

		$t->isTrue($driver->write('docs/a.txt', 'two'));
		$t->isTrue($driver->write('docs/a.txt', 'three'));
		$t->isTrue($driver->write('docs/a.txt', 'four'));
		$versions=$driver->versions('/docs//a.txt');
		$t->same(2, count($versions));
		$t->same('write', $versions[0]['operation']);
		$t->same(0, count($driver->list('_versions')));
		$visible=$driver->list();
		$t->same(1, count($visible));
		$t->same('docs/a.txt', $visible[0]->path());

		$restoreId=(string)$versions[0]['id'];
		$t->isFalse($driver->restoreVersion('docs/a.txt', 'missing'));
		$t->isTrue($driver->restoreVersion('docs/a.txt', $restoreId));
		$t->same('two', $driver->read('docs/a.txt'));
		$t->isTrue(count($driver->versions('docs/a.txt'))>=2);
		$t->isFalse($driver->purgeVersion('docs/a.txt', 'missing'));
		$current=$driver->versions('docs/a.txt');
		$t->isTrue($driver->purgeVersion('docs/a.txt', (string)$current[0]['id']));

		$t->same(0, $driver->pruneVersions('missing')['pruned']);
		$t->same(1, $driver->pruneVersions('docs/a.txt', ['keep'=>1])['pruned']);
		$pruned=$driver->pruneVersions('docs/a.txt', ['keep'=>0]);
		$t->isTrue($pruned['ok']);
		$t->isTrue($pruned['pruned']>=1);
		$t->same([], $driver->versions('docs/a.txt'));

		$source->seed('docs/delete.txt', 'delete-me');
		$t->isTrue($driver->delete('docs/delete.txt'));
		$t->isFalse($driver->exists('docs/delete.txt'));
		$t->same(1, count($driver->versions('docs/delete.txt')));
})->tag('storage', 'drivers', 'versioned', 'coverage')->group('framework-coverage');

test('storage driver core deep coverage versioned failure and time edges', static function(Context $t): void {
	$workspace=$t->workspace('storage-versioned-edge');
	$manifest=$workspace->path('versions.json');
	$source=new DpStorageDriverProbe();
	$manager=new StorageManager();
	$manager->extend('dp_version_edge', static fn(array $config, string $name, StorageManager $owner): StorageDriver=>$source);
	$driver=new VersionedDriver(['disk'=>'dp_version_edge', 'prefix'=>'private', 'manifest'=>$manifest, 'keep'=>0], $manager);
	$internals=$t->nonPublic($driver);
		$t->same(null, $internals->invoke('normalizeTime', null));
		$t->same(null, $internals->invoke('normalizeTime', ''));
		$t->same(123, $internals->invoke('normalizeTime', 123));
		$t->same(321, $internals->invoke('normalizeTime', new DateTimeImmutable('@321')));
		$t->same(strtotime('2020-01-02'), $internals->invoke('normalizeTime', '2020-01-02'));
		$t->same(null, $internals->invoke('normalizeTime', 'not-a-date'));
		$t->same(null, $internals->invoke('normalizeTime', 1.2));

		$t->same(null, $internals->invoke('snapshot', 'missing.txt', 'write', []));
		$source->seed('a.txt', 'alpha');
		$source->failStreamPaths[]='a.txt';
		$t->same(null, $internals->invoke('snapshot', 'a.txt', 'write', []));
		$source->failStreamPaths=[];
		$source->failWritePrefixes[]='private/';
		$t->same(null, $internals->invoke('snapshot', 'a.txt', 'write', []));
		$source->failWritePrefixes=[];
		$source->failMetadataPaths[]='a.txt';
		$t->isTrue(is_array($internals->invoke('snapshot', 'a.txt', 'manual', [])));

		$source->writeEnabled=false;
		$t->isFalse($driver->write('failed.txt', 'body'));
		$source->writeEnabled=true;
		$t->isTrue($driver->write('keep-zero.txt', 'body'));
		$t->isFalse($driver->restoreVersion('a.txt', 'does-not-exist'));
		$rows=$driver->versions('a.txt');
		$versionPath=(string)$rows[0]['version_path'];
		$source->failStreamPaths[]=$versionPath;
		$t->isFalse($driver->restoreVersion('a.txt', (string)$rows[0]['id']));

		$workspace->file('versions.json', '{invalid');
		$t->same([], $driver->versions('a.txt'));
		$workspace->file('versions.json', (string)json_encode([['path'=>''], 'bad', ['path'=>'x', 'id'=>''], ['path'=>'x', 'id'=>'old', 'created_at'=>1, 'version_path'=>''] ]));
		$t->same(0, $driver->pruneVersions(null, ['older_than'=>'invalid'])['pruned']);
		$t->same(1, $driver->pruneVersions(null, ['older_than'=>new DateTimeImmutable('@2')])['pruned']);
		$t->isTrue($internals->invoke('writeManifest', []));
})->tag('storage', 'drivers', 'versioned', 'coverage')->group('framework-coverage');

test('storage driver core deep coverage deduplicated lifecycle metadata and reports', static function(Context $t): void {
	$workspace=$t->workspace('storage-deduplicated');
	$manifest=$workspace->path('nested/dedup.json');
	$source=new DpStorageDriverProbe();
	$manager=new StorageManager();
	$manager->extend('dp_dedup_source', static fn(array $config, string $name, StorageManager $owner): StorageDriver=>$source);
	$t->throws(static fn()=>new DeduplicatedDriver([], $manager), RuntimeException::class);
	$t->throws(static fn()=>new DeduplicatedDriver(['disk'=>'dp_dedup_source', 'prefix'=>''], $manager), RuntimeException::class);
	$t->throws(static fn()=>new DeduplicatedDriver(['disk'=>'dp_dedup_source', 'algorithm'=>'no-such-hash'], $manager), RuntimeException::class);
	$driver=new DeduplicatedDriver(['target'=>'dp_dedup_source', 'prefix'=>'blobs', 'manifest'=>$manifest], $manager);
		$t->isFalse($driver->write('', 'body'));
		$t->isFalse($driver->write('bad.txt', 'body', ['deduplication_algorithm'=>'bad']));
		$t->isTrue($driver->write('/docs//a.txt', 'same', ['content_type'=>'text/plain']));
		$stream=Stream::fromString('same');
		$t->isTrue($driver->write('docs/b.txt', $stream));
		$t->isTrue($driver->exists('docs/a.txt'));
		$t->same('same', $driver->read('docs/a.txt'));
		$t->isFalse($driver->read('missing.txt'));
		$t->isFalse($driver->readStream('missing.txt'));
		$t->instanceOf(FileMetadata::class, $driver->metadata('docs/a.txt'));
		$t->isFalse($driver->metadata('missing.txt'));
		$list=$driver->list('docs');
		$t->same(2, count($list));
		$t->same('docs/a.txt', $list[0]->path());
		$t->same([], $driver->list('other'));
		$t->contains('probe://', (string)$driver->temporaryUrl('docs/a.txt', time()+60));
		$t->isFalse($driver->temporaryUrl('missing.txt', time()+60));
		$report=$driver->deduplicationReport('docs');
		$t->isTrue($report['ok']);
		$t->same(2, $report['logical_objects']);
		$t->same(1, $report['unique_blobs']);
		$t->same(4, $report['saved_bytes']);

		$records=json_decode((string)file_get_contents($manifest), true);
		$sharedBlob=(string)$records['docs/a.txt']['blob_path'];
		$t->isTrue($driver->write('docs/a.txt', 'different'));
		$t->isTrue($source->exists($sharedBlob));
		$t->isTrue($driver->delete('docs/b.txt'));
		$t->isFalse($source->exists($sharedBlob));
		$t->isTrue($driver->delete('missing.txt'));
		$t->isTrue($driver->delete('docs/a.txt'));
})->tag('storage', 'drivers', 'deduplicated', 'coverage')->group('framework-coverage');

test('storage driver core deep coverage deduplicated failure and malformed manifest edges', static function(Context $t): void {
	$workspace=$t->workspace('storage-deduplicated-edge');
	$manifest=$workspace->path('dedup.json');
	$source=new DpStorageDriverProbe();
	$manager=new StorageManager();
	$manager->extend('dp_dedup_edge', static fn(array $config, string $name, StorageManager $owner): StorageDriver=>$source);
	$driver=new DeduplicatedDriver(['disk'=>'dp_dedup_edge', 'manifest'=>$manifest, 'algorithm'=>'sha1'], $manager);
	$internals=$t->nonPublic($driver);
	$readOnlyManifest=$t->state('storage.read-only-manifest', ['contents'=>'{}']);
		$source->writeEnabled=false;
		$t->isFalse($driver->write('failed.txt', 'body'));
		$source->writeEnabled=true;
		$workspace->file('dedup.json', '{invalid');
		$t->same([], $driver->list());
		$t->same(0, $driver->deduplicationReport()['logical_objects']);
		$t->same(0, $internals->invoke('referencesFor', ''));

		$source->seed('physical/known', 'underlying');
		$rows=[
			'malformed'=>'bad',
			'docs/missing'=>['path'=>'docs/missing', 'blob_path'=>'physical/missing', 'size'=>9],
			'docs/empty'=>['path'=>'docs/empty', 'blob_path'=>'', 'size'=>2],
			'docs/known'=>['path'=>'docs/known', 'blob_path'=>'physical/known', 'algorithm'=>'sha1'],
		];
		$workspace->file('dedup.json', (string)json_encode($rows));
		$source->failMetadataPaths[]='physical/known';
		$metadata=$driver->metadata('docs/known');
		$t->instanceOf(FileMetadata::class, $metadata);
		$t->same(null, $metadata->size());
		$t->same(3, count($driver->list('docs')));
		$report=$driver->deduplicationReport();
		$t->isFalse($report['ok']);
		$t->same(1, count($report['missing']));
		$t->same(3, $report['logical_objects']);
		$t->same(0, $driver->deduplicationReport('none')['logical_objects']);

		$internals->invoke('deleteBlobIfUnreferenced', 'physical/known', [['blob_path'=>'physical/known']]);
		$t->isTrue($source->exists('physical/known'));
		$internals->invoke('deleteBlobIfUnreferenced', 'physical/known', []);
		$t->isFalse($source->exists('physical/known'));

		$workspace->file('dedup.json', (string)json_encode(['x'=>['path'=>'x', 'blob_path'=>'physical/x']]));
		$source->seed('physical/x', 'x');
		$workspace->file('dedup.json', (string)json_encode(['x'=>['path'=>'x', 'blob_path'=>'physical/x']]));
		$t->isTrue($driver->delete('x'));

		$readOnly=new DeduplicatedDriver(['disk'=>'dp_dedup_edge', 'manifest'=>'dpreadonly://manifest'], $manager);
		$readOnlyManifest->put('contents', '{}');
		$t->isFalse($readOnly->write('cannot-persist.txt', 'body'));
		$blob='_dataphyre_blobs/sha256/'.substr(hash('sha256', 'body'), 0, 2).'/'.hash('sha256', 'body');
		$readOnlyManifest->put('contents', (string)json_encode(['locked'=>['path'=>'locked', 'blob_path'=>$blob]]));
		$t->isFalse($readOnly->delete('locked'));
})->tag('storage', 'drivers', 'deduplicated', 'coverage')->group('framework-coverage');

test('storage driver core deep coverage retention locks releases reports and time forms', static function(Context $t): void {
	$workspace=$t->workspace('storage-retention');
	$manifest=$workspace->path('nested/retention.json');
	$source=new DpStorageDriverProbe();
	$manager=new StorageManager();
	$manager->extend('dp_retention_source', static fn(array $config, string $name, StorageManager $owner): StorageDriver=>$source);
	$t->throws(static fn()=>new RetentionDriver([], $manager), RuntimeException::class);
	$driver=new RetentionDriver([
		'target'=>'dp_retention_source',
		'manifest'=>$manifest,
		'default_retain_for'=>'1 hour',
		'default_legal_hold'=>false,
	], $manager);
		$t->isFalse($driver->exists('missing.txt'));
		$t->isTrue($driver->write('docs/a.txt', 'alpha'));
		$t->isTrue($driver->exists('docs/a.txt'));
		$t->same('alpha', $driver->read('docs/a.txt'));
		$t->isTrue(is_resource($driver->readStream('docs/a.txt')));
		$t->instanceOf(FileMetadata::class, $driver->metadata('docs/a.txt'));
		$t->isFalse($driver->metadata('missing.txt'));
		$t->same(1, count($driver->list('docs')));
		$t->contains('probe://', (string)$driver->temporaryUrl('docs/a.txt', time()+60));
		$t->isFalse($driver->write('docs/a.txt', 'blocked'));
		$t->isFalse($driver->delete('docs/a.txt'));
		$t->isFalse($driver->setRetention('missing.txt', ['retain_for'=>1]));

		$t->isTrue($driver->releaseRetention('missing.txt'));
		$t->isTrue($driver->releaseRetention('docs/a.txt'));
		$t->isTrue($driver->write('docs/a.txt', 'beta', ['legal_hold'=>true, 'retain_until'=>new DateTimeImmutable('-1 minute')]));
		$report=$driver->retentionReport('docs');
		$t->same(1, $report['objects']);
		$t->same(1, $report['locked']);
		$t->same(1, $report['legal_holds']);
		$t->isTrue($driver->setRetention('docs/a.txt', ['retain_for'=>'120', 'legal_hold'=>false]));
		$t->isFalse($driver->delete('docs/a.txt'));
		$t->isTrue($driver->releaseRetention('docs/a.txt', ['release_legal_hold'=>true, 'release_retain_until'=>true]));
		$t->isTrue($driver->delete('docs/a.txt'));

		$source->writeEnabled=false;
		$t->isFalse($driver->write('failed.txt', 'body'));
})->tag('storage', 'drivers', 'retention', 'coverage')->group('framework-coverage');

test('storage driver core deep coverage retention malformed records and private conversions', static function(Context $t): void {
	$workspace=$t->workspace('storage-retention-edge');
	$manifest=$workspace->path('retention.json');
	$source=new DpStorageDriverProbe();
	$manager=new StorageManager();
	$manager->extend('dp_retention_edge', static fn(array $config, string $name, StorageManager $owner): StorageDriver=>$source);
	$driver=new RetentionDriver(['disk'=>'dp_retention_edge', 'manifest'=>$manifest, 'retain_for'=>-5, 'legal_hold'=>true], $manager);
	$internals=$t->nonPublic($driver);
		foreach([null, '', [], 1.2, 'invalid date'] as $value){
			$t->same(null, $internals->invoke('timeValue', $value));
		}
		$t->same(123, $internals->invoke('timeValue', 123));
		$t->same(321, $internals->invoke('timeValue', new DateTimeImmutable('@321')));
		$t->same(strtotime('2022-03-04'), $internals->invoke('timeValue', '2022-03-04'));
		$t->same(null, $internals->invoke('durationSeconds', null));
		$t->same(0, $internals->invoke('durationSeconds', -2));
		$t->same(12, $internals->invoke('durationSeconds', '12'));
		$t->isTrue((int)$internals->invoke('durationSeconds', '2 minutes')>=119);
		$t->same(null, $internals->invoke('durationSeconds', 'not-duration'));
		$t->same(null, $internals->invoke('durationSeconds', []));
		$t->same(222, $internals->invoke('retentionUntil', ['retain_until'=>222], []));
		$t->isTrue((int)$internals->invoke('retentionUntil', ['retain_for'=>1], [])>=time());
		$t->same(333, $internals->invoke('retentionUntil', [], ['retain_until'=>333]));
		$t->same(null, $internals->invoke('retentionUntil', [], ['retain_until'=>null]));

		$source->seed('expired.txt', 'x');
		$workspace->file('retention.json', (string)json_encode([
			'malformed'=>'bad',
			'expired.txt'=>['path'=>'expired.txt', 'retain_until'=>time()-10, 'legal_hold'=>false],
			'held.txt'=>['path'=>'held.txt', 'retain_until'=>null, 'legal_hold'=>true],
		]));
		$report=$driver->retentionReport();
		$t->same(2, $report['objects']);
		$t->same(1, $report['expired']);
		$t->same(1, $report['locked']);
		$t->same(0, $driver->retentionReport('none')['objects']);
		$t->isTrue($driver->releaseRetention('held.txt', ['release_legal_hold'=>false, 'release_retain_until'=>false]));
		$workspace->file('retention.json', '{invalid');
		$t->same(0, $driver->retentionReport()['objects']);
})->tag('storage', 'drivers', 'retention', 'coverage')->group('framework-coverage');

test('storage driver core deep coverage cached read through write through and diagnostics', static function(Context $t): void {
	$workspace=$t->workspace('storage-cache');
	$manifest=$workspace->path('nested/cache.json');
	$source=new DpStorageDriverProbe();
	$cache=new DpStorageDriverProbe();
	$manager=new StorageManager();
	$manager->extend('dp_cache_source', static fn(array $config, string $name, StorageManager $owner): StorageDriver=>$source);
	$manager->extend('dp_cache_target', static fn(array $config, string $name, StorageManager $owner): StorageDriver=>$cache);
	$t->throws(static fn()=>new CachedDriver([], $manager), RuntimeException::class);
	$t->throws(static fn()=>new CachedDriver(['disk'=>'dp_cache_source', 'cache'=>'dp_cache_target', 'prefix'=>''], $manager), RuntimeException::class);
	$driver=new CachedDriver([
		'source'=>'dp_cache_source',
		'cache_disk'=>'dp_cache_target',
		'prefix'=>'cache',
		'manifest'=>$manifest,
		'ttl_seconds'=>60,
	], $manager);
		$t->isFalse($driver->exists('missing.txt'));
		$t->isFalse($driver->read('missing.txt'));
		$t->isFalse($driver->readStream('missing.txt'));
		$source->seed('docs/a.txt', 'alpha', ['content_type'=>'text/plain']);
		$t->isTrue($driver->exists('docs/a.txt'));
		$t->same('alpha', $driver->read('docs/a.txt'));
		$t->isTrue($cache->exists('cache/docs/a.txt'));
		unset($source->objects['docs/a.txt']);
		$t->isTrue($driver->exists('docs/a.txt'));
		$t->same('alpha', $driver->read('docs/a.txt'));
		$metadata=$driver->metadata('docs/a.txt');
		$t->instanceOf(FileMetadata::class, $metadata);
		$t->isTrue($metadata->extra()['cache']['fresh']);
		$t->isFalse($driver->metadata('missing.txt'));

		$t->isTrue($driver->write('docs/b.txt', 'beta', ['content_type'=>'text/plain']));
		$t->same('beta', $source->read('docs/b.txt'));
		$t->same('beta', $cache->read('cache/docs/b.txt'));
		$stream=Stream::fromString('gamma');
		$t->isTrue($driver->write('docs/c.txt', $stream));
		$t->same(2, count($driver->list('docs')));
		$t->contains('probe://', (string)$driver->temporaryUrl('docs/b.txt', time()+60));
		$report=$driver->cacheReport('docs');
		$t->isTrue($report['ok']);
		$t->same(3, $report['objects']);
		$t->same(3, $report['fresh']);
		unset($cache->objects['cache/docs/c.txt']);
		$report=$driver->cacheReport();
		$t->isFalse($report['ok']);
		$t->contains('docs/c.txt', $report['missing']);
		$t->same(0, $driver->cacheReport('other')['objects']);
		$t->same(0, $driver->purgeCache('other')['purged']);

		$purged=$driver->purgeCache('docs');
		$t->same(3, $purged['purged']);
		$t->same(0, $driver->cacheReport()['objects']);
		$t->isTrue($driver->delete('docs/b.txt'));
})->tag('storage', 'drivers', 'cached', 'coverage')->group('framework-coverage');

test('storage driver core deep coverage cached invalid manifests stale entries and failures', static function(Context $t): void {
	$workspace=$t->workspace('storage-cache-edge');
	$manifest=$workspace->path('cache.json');
	$source=new DpStorageDriverProbe();
	$cache=new DpStorageDriverProbe();
	$manager=new StorageManager();
	$manager->extend('dp_cache_edge_source', static fn(array $config, string $name, StorageManager $owner): StorageDriver=>$source);
	$manager->extend('dp_cache_edge_target', static fn(array $config, string $name, StorageManager $owner): StorageDriver=>$cache);
	$driver=new CachedDriver([
		'disk'=>'dp_cache_edge_source', 'cache'=>'dp_cache_edge_target', 'manifest'=>$manifest,
		'prefix'=>'edge-cache', 'ttl'=>1, 'write_through'=>false,
	], $manager);
		$source->writeEnabled=false;
		$t->isFalse($driver->write('failed.txt', 'body'));
		$source->writeEnabled=true;
		$source->seed('docs/a.txt', 'alpha');
		$cache->seed('edge-cache/docs/a.txt', 'old');
		$workspace->file('cache.json', (string)json_encode([
			'docs/a.txt'=>['path'=>'docs/a.txt', 'cache_path'=>'edge-cache/docs/a.txt', 'size'=>3, 'cached_at'=>time()-100],
			'malformed'=>'bad',
		]));
		$t->same('alpha', $driver->read('docs/a.txt'));
		$t->same(1, $driver->cacheReport()['fresh']);
		$t->isTrue($driver->write('docs/a.txt', 'new'));
		$t->isFalse($cache->exists('edge-cache/docs/a.txt'));
		$t->same(0, $driver->cacheReport()['objects']);

		$cache->writeEnabled=false;
		$source->seed('uncacheable.txt', 'body');
		$t->same('body', $driver->read('uncacheable.txt'));
		$cache->writeEnabled=true;
		$source->failMetadataPaths[]='uncacheable.txt';
		$t->isFalse($driver->metadata('uncacheable.txt'));
		$workspace->file('cache.json', (string)json_encode(['malformed'=>'bad']));
		$t->same(0, $driver->purgeCache()['purged']);
		$workspace->file('cache.json', '{invalid');
		$t->same(0, $driver->cacheReport()['objects']);

		$forever=new CachedDriver([
			'disk'=>'dp_cache_edge_source', 'cache'=>'dp_cache_edge_target', 'manifest'=>$manifest,
			'prefix'=>'forever', 'ttl'=>0,
		], $manager);
		$source->seed('forever.txt', 'value');
		$t->same('value', $forever->read('forever.txt'));
		$workspace->file('cache.json', (string)json_encode(['forever.txt'=>['path'=>'forever.txt', 'size'=>5, 'cached_at'=>1]]));
		$cache->seed('forever/forever.txt', 'value');
		unset($source->objects['forever.txt']);
		$t->isTrue($forever->exists('forever.txt'));
})->tag('storage', 'drivers', 'cached', 'coverage')->group('framework-coverage');

test('storage driver core deep coverage S3 compatible HTTP operations and multipart contracts', static function(Context $t): void {
	$http=$t->state('storage.s3-http', ['requests'=>[]]);
	$handler=static function(array $request) use ($http): mixed {
		$http->append('requests', $request);
		$path=(string)$request['path'];
		$method=(string)$request['method'];
		$query=(string)$request['query'];
		if($path==='invalid-handler'){
			return 'invalid';
		}
		if($method==='HEAD'){
			if(str_contains($path, 'missing')){ return ['status'=>404]; }
			if($path==='no-headers'){ return ['status'=>200, 'headers'=>'invalid']; }
			return ['status'=>200, 'headers'=>[
				'content-length'=>'5',
				'last-modified'=>$path==='invalid-date' ? 'not-a-date' : 'Wed, 01 Jan 2025 00:00:00 GMT',
				'content-type'=>'text/plain',
				'etag'=>'"abc"',
			]];
		}
		if($method==='GET' && str_contains($query, 'list-type=2')){
			parse_str($query, $parameters);
			$prefix=(string)($parameters['prefix'] ?? '');
			if($prefix==='failed'){ return ['status'=>500]; }
			if($prefix==='invalid'){ return ['status'=>200, 'body'=>'<invalid']; }
			return ['status'=>200, 'body'=>'<?xml version="1.0"?><ListBucketResult><Contents><Key>docs/a.txt</Key><Size>5</Size><LastModified>2025-01-01T00:00:00Z</LastModified></Contents><Contents><Key>docs/b.txt</Key><Size>7</Size><LastModified>invalid</LastModified></Contents></ListBucketResult>'];
		}
		if($method==='GET'){
			return str_contains($path, 'failed') ? ['status'=>500, 'body'=>'error'] : ['status'=>200, 'body'=>'hello'];
		}
		if($method==='PUT'){
			return str_contains($path, 'failed') ? ['status'=>500] : ['status'=>201];
		}
		if($method==='DELETE'){
			return str_contains($path, 'denied') ? ['status'=>500] : ['status'=>404];
		}
		if($method==='POST' && $query==='uploads='){
			if(str_contains($path, 'failed')){ return ['status'=>500]; }
			if(str_contains($path, 'invalid')){ return ['status'=>200, 'body'=>'<bad']; }
			if(str_contains($path, 'empty')){ return ['status'=>200, 'body'=>'<InitiateMultipartUploadResult/>']; }
			return ['status'=>200, 'body'=>'<InitiateMultipartUploadResult><UploadId>upload-1</UploadId></InitiateMultipartUploadResult>'];
		}
		if($method==='POST'){
			return str_contains($path, 'failed') ? ['status'=>500] : ['status'=>200];
		}
		return ['status'=>0];
	};
	$config=[
		'endpoint'=>'https://objects.example.test',
		'bucket'=>'bucket',
		'access_key'=>'access',
		'secret_key'=>'secret',
		'region'=>'ca-central-1',
		'session_token'=>'session',
		'http_handler'=>$handler,
	];
	$driver=new S3CompatibleDriver($config);
	$t->same(['status'=>0], $handler(['method'=>'PATCH', 'path'=>'other', 'query'=>'', 'headers'=>[], 'body'=>'', 'url'=>'https://example.test']));
	$t->isTrue($driver->exists('/docs//a.txt'));
	$t->isFalse($driver->exists('missing.txt'));
	$t->isFalse($driver->exists('invalid-handler'));
	$t->same('hello', $driver->read('docs/a.txt'));
	$t->isFalse($driver->read('failed.txt'));
	$stream=$driver->readStream('docs/a.txt');
	$t->isTrue(is_resource($stream));
	$t->same('hello', stream_get_contents($stream));
	$t->isFalse($driver->readStream('failed.txt'));
	$t->isTrue($driver->write('docs/a.txt', 'hello', [
		'content_type'=>'text/plain', 'visibility'=>'public', 'cache_control'=>'max-age=60', 'content_disposition'=>'inline',
	]));
	$body=Stream::fromString('streamed');
	$t->isTrue($driver->write('docs/stream.txt', $body));
	$t->isFalse($driver->write('failed.txt', 'body'));
	$t->isTrue($driver->delete('missing.txt'));
	$t->isFalse($driver->delete('denied.txt'));

	$metadata=$driver->metadata('docs/a.txt');
	$t->instanceOf(FileMetadata::class, $metadata);
	$t->same(5, $metadata->size());
	$t->same('text/plain', $metadata->mimeType());
	$t->same(null, $driver->metadata('invalid-date')->modifiedAt());
	$t->same(null, $driver->metadata('no-headers')->size());
	$t->isFalse($driver->metadata('missing.txt'));
	$items=$driver->list('docs', ['limit'=>2]);
	$t->same(2, count($items));
	$t->same('docs/a.txt', $items[0]->path());
	$t->same(null, $items[1]->modifiedAt());
	$t->same([], $driver->list('failed'));
	$t->same([], $driver->list('invalid'));

	$public=new S3CompatibleDriver($config+['public_url'=>'https://cdn.example.test/base']);
	$t->same('https://cdn.example.test/base/docs/a%20b.txt', $public->temporaryUrl('docs/a b.txt', time()+60));
	$t->contains('X-Amz-Signature=', (string)$driver->temporaryUrl('docs/a.txt', new DateTimeImmutable('+2 minutes')));
	$t->contains('X-Amz-Signature=', (string)$driver->temporaryUploadUrl('docs/upload.txt', time()+120, [
		'content_type'=>'text/plain', 'visibility'=>'public', 'cache_control'=>'no-cache',
	]));
	$t->contains('X-Amz-Signature=', (string)$driver->temporaryUploadUrl('docs/plain.txt', time()+120));

	$upload=$driver->initiateMultipartUpload('docs/large.bin', [
		'content_type'=>'application/octet-stream', 'visibility'=>'public', 'cache_control'=>'no-cache',
	]);
	$t->same('upload-1', $upload['upload_id']);
	$t->same('docs/large.bin', $upload['path']);
	$t->isFalse($driver->initiateMultipartUpload('failed.bin'));
	$t->isFalse($driver->initiateMultipartUpload('invalid.bin'));
	$t->isFalse($driver->initiateMultipartUpload('empty.bin'));
	$partUrls=$driver->temporaryMultipartUploadUrls('docs/large.bin', 'upload-1', 0, time()+120, ['content_type'=>'application/octet-stream']);
	$t->same(1, count($partUrls));
	$t->contains('partNumber=1', $partUrls[1]);
	$t->same(2, count($driver->temporaryMultipartUploadUrls('docs/large.bin', 'upload-1', 2, time()+120)));
	$t->isFalse($driver->completeMultipartUpload('docs/large.bin', 'upload-1', []));
	$t->isFalse($driver->completeMultipartUpload('docs/large.bin', 'upload-1', [0=>'', -1=>'bad']));
	$t->isTrue($driver->completeMultipartUpload('docs/large.bin', 'upload-1', [
		2=>'"two"',
		['PartNumber'=>1, 'ETag'=>'one&amp;'],
		['part_number'=>0, 'etag'=>'skip'],
		['part_number'=>3, 'etag'=>''],
	]));
	$t->isFalse($driver->completeMultipartUpload('failed.bin', 'upload-1', [1=>'one']));
	$t->isTrue($driver->abortMultipartUpload('docs/large.bin', 'upload-1'));
	$t->isFalse($driver->abortMultipartUpload('denied.bin', 'upload-1'));
	$t->isTrue(count($http->get('requests'))>20);
})->tag('storage', 'drivers', 's3', 'coverage')->group('framework-coverage');

test('storage driver core deep coverage S3 URL parsing and native curl failure paths', static function(Context $t): void {
	$config=[
		'endpoint'=>'http://127.0.0.1:1',
		'bucket'=>'bucket',
		'access_key'=>'access',
		'secret_key'=>'secret',
		'session_token'=>'session',
	];
	$driver=new S3CompatibleDriver($config);
	$t->isFalse($driver->exists('head.txt'));
	$t->isFalse($driver->read('get.txt'));
	$t->isFalse($driver->write('put.txt', 'body'));
	$internals=$t->nonPublic($driver);
	$t->same('http://127.0.0.1:1/bucket/docs/a%20b.txt?q=1', $internals->invoke('objectUrl', 'docs/a b.txt', 'q=1'));
	$virtual=new S3CompatibleDriver(array_replace($config, ['style'=>'virtual', 'endpoint'=>'https://objects.example.test']));
	$t->same('https://bucket.objects.example.test/docs/a.txt', $t->nonPublic($virtual)->invoke('objectUrl', '/docs//a.txt', ''));
	$headers=$internals->invoke('parseHeaders', "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nX-Test: one:two\r\nInvalid\r\n\r\n");
	$t->same('text/plain', $headers['content-type']);
	$t->same('one:two', $headers['x-test']);
	$t->same('', $internals->invoke('completeMultipartXml', []));
	$document=$internals->invoke('completeMultipartXml', [3=>['part_number'=>3, 'etag'=>'three'], 1=>'one']);
	$t->contains('<PartNumber>1</PartNumber>', $document);
	$t->contains('<PartNumber>3</PartNumber>', $document);
})->tag('storage', 'drivers', 's3', 'coverage')->group('framework-coverage');

test('storage driver core deep coverage Vestra aliases references downloads and client adapter', static function(Context $t): void {
	$workspace=$t->workspace('storage-vestra');
	$manifest=$workspace->path('nested/aliases.json');
	$t->state('storage.vestra-temp-directory', ['path'=>$workspace->directory('temporary')]);
	$client=$t->state('storage.vestra-client', ['calls'=>[]]);
	$handler=static function(string $operation, array $arguments, array $config) use ($client): mixed {
		$client->append('calls', [$operation, $arguments]);
		if($operation==='propagate'){
			$body=(string)file_get_contents((string)$arguments[0]);
			if($body==='reject'){ return false; }
			return ['object_id'=>7, 'filesize'=>strlen($body), 'metadata'=>['source'=>'probe'], 'tenant'=>''];
		}
		if($operation==='asset_url'){
			return 'vestra://asset/'.($arguments[1] !== '' ? $arguments[1] : 'raw');
		}
		if($operation==='download'){
			return str_contains((string)$arguments[0], 'fail') ? false : 'downloaded';
		}
		if($operation==='update_use_count'){
			return 0;
		}
		return false;
	};
	$driver=new VestraDriver([
		'manifest'=>$manifest,
		'tenant'=>'tenant-a',
		'base_url'=>'https://vestra.example.test',
		'api_token'=>'token',
		'client_handler'=>$handler,
	]);
	$t->isFalse($handler('unknown', [], []));
		$t->isFalse($driver->exists('missing.txt'));
		$t->isFalse($driver->read('missing.txt'));
		$t->isFalse($driver->readStream('missing.txt'));
		$t->isTrue($driver->write('/docs//a.txt', 'alpha', [
			'vestra_encrypt'=>true,
			'metadata'=>['nested'=>['one'=>1]],
			'original_name'=>'original.TXT',
			'content_type'=>'text/plain',
		]));
		$resource=Stream::fromString('beta');
		$t->isTrue($driver->write('docs/b', $resource, ['tenant'=>'tenant-b']));
		$t->isFalse($driver->write('docs/rejected.txt', 'reject'));
		$t->isTrue($driver->exists('docs/a.txt'));
		$t->same('downloaded', $driver->read('docs/a.txt'));
		$stream=$driver->readStream('docs/a.txt');
		$t->isTrue(is_resource($stream));
		$t->same('downloaded', stream_get_contents($stream));
		$metadata=$driver->metadata('docs/a.txt');
		$t->instanceOf(FileMetadata::class, $metadata);
		$t->same(5, $metadata->size());
		$t->same('text/plain', $metadata->mimeType());
		$t->isFalse($driver->metadata('missing.txt'));
		$t->same(2, count($driver->list('docs')));
		$t->same(1, count($driver->list('docs/a')));
		$t->same(0, count($driver->list('none')));
		$url=$driver->temporaryUrl('docs/a.txt', new DateTimeImmutable('+1 minute'), [
			'extension'=>'.PDF',
			'query'=>['custom'=>'value'],
			'rate'=>'fast',
		]);
		$t->same('vestra://asset/pdf', $url);
		$t->isFalse($driver->temporaryUrl('missing.txt', time()+60));
		$t->isTrue($driver->delete('docs/a.txt'));
		$t->isFalse($driver->exists('docs/a.txt'));
		$t->isTrue($driver->delete('already-missing.txt'));

		$internals=$t->nonPublic($driver);
		$t->same('txt', $internals->invoke('assetExtension', 'name.TXT', []));
		$t->same('webp', $internals->invoke('assetExtension', 'name', ['extension'=>'.WEBP']));
		$t->same('', $internals->invoke('assetExtension', 'name.toolongextension', []));
		$t->same('', $internals->invoke('assetExtension', 'name.bad!', []));
		$built=$internals->invoke('reference', ['object_id'=>8, 'tenant'=>'existing', 'mime_type'=>'image/png', 'metadata'=>['nested'=>['zero'=>0]]], 'folder/item.png', [
			'tenant'=>'ignored', 'metadata'=>['nested'=>['one'=>1]], 'original_name'=>'', 'content_type'=>'image/jpeg',
		]);
		$t->same('existing', $built['tenant']);
		$t->same('image/png', $built['mime_type']);
		$t->same(1, $built['metadata']['nested']['one']);
		$params=$internals->invoke('urlParameters', ['query'=>'invalid', 'tenant'=>'override', 'allow_unsigned'=>true]);
		$t->same('override', $params['tenant']);
		$t->isTrue($params['allow_unsigned']);
		$t->same('https://vestra.example.test', $params['base_url']);
		$tmpNoExtension=$internals->invoke('temporaryPath', 'no-extension', []);
		$t->isTrue(is_file($tmpNoExtension));
		$tmpWithExtension=$internals->invoke('temporaryPath', 'asset.png', []);
		$t->isTrue(str_ends_with($tmpWithExtension, '.png'));

		$workspace->file('nested/aliases.json', '{invalid');
		$t->same([], $driver->list());
		$workspace->file('nested/aliases.json', (string)json_encode([
			'invalid'=>'not-array',
			'no-id'=>['metadata'=>[]],
			'bad-id'=>['object_id'=>'not-numeric'],
			'metadata-size'=>['object_id'=>'9', 'metadata'=>['size'=>'12', 'content_type'=>'application/json']],
		]));
		$metadataSize=$driver->metadata('metadata-size');
		$t->same(12, $metadataSize->size());
		$t->same('application/json', $metadataSize->mimeType());
		$t->isTrue(count($client->get('calls'))>=8);
})->tag('storage', 'drivers', 'vestra', 'coverage')->group('framework-coverage');

test('storage driver core deep coverage Vestra manifest failures CA resolution and native download paths', static function(Context $t): void {
	$workspace=$t->workspace('storage-vestra-edge');
	$t->state('storage.vestra-temp-directory', ['path'=>$workspace->directory('temporary')]);
	$ca=$workspace->file('ca.pem', 'test-ca');
	$download=$workspace->file('download.txt', 'local-body');
	$moduleStub=$workspace->file(
		'vestra-module-stub.php',
		'<?php namespace dataphyre; class vestra { public static function update_use_count(array $reference, int $amount): int { return 0; } }',
	);
	$handler=static function(string $operation, array $arguments, array $config): mixed {
		return match($operation){
			'propagate'=>['object_id'=>1],
			'asset_url'=>'vestra://fail',
			'download'=>false,
			default=>false,
		};
	};
	$noManifest=new VestraDriver(['client_handler'=>$handler]);
	$t->isFalse($noManifest->write('a.txt', 'body'));
	$t->isTrue($noManifest->delete('missing.txt')===false);
	$manifest=$workspace->file('aliases.json', (string)json_encode(['a.txt'=>['object_id'=>1]]));
	$driver=new VestraDriver(['manifest'=>$manifest, 'client_handler'=>$handler]);
	$t->isFalse($driver->read('a.txt'));
	$t->isFalse($driver->readStream('a.txt'));

	$native=new VestraDriver(['manifest'=>$manifest, 'ca_bundle'=>$ca, 'read_timeout'=>1]);
	$internals=$t->nonPublic($native);
	$t->same($ca, $internals->invoke('caBundle'));
	$t->same('local-body', $internals->invoke('downloadUrl', str_replace('\\', '/', 'file:///'.$download)));
	$t->isFalse($internals->invoke('downloadUrl', 'http://127.0.0.1:1/unavailable'));
	$t->isFalse($internals->invoke('ensureVestra'));
	$t->state('storage.vestra-module', ['path'=>$moduleStub]);
	if(!function_exists('dp_module_present')){
		\Dataphyre\Test\define_test_symbols(<<<'PHP'
function dp_module_present(string $module): array {
	return [(string)\Dataphyre\Test\TestState::channel('storage.vestra-module')->get('path')];
}
PHP);
	}
	$t->isTrue($internals->invoke('ensureVestra'));
	$missingCa=new VestraDriver(['ca_bundle'=>$workspace->path('missing.pem')]);
	$t->same('', $t->nonPublic($missingCa)->invoke('caBundle'));
	if(!defined('DP_VESTRA_CFG')){
		define('DP_VESTRA_CFG', ['default_tenant'=>'tenant-one', 'tenants'=>['tenant-one'=>['ca_bundle'=>$ca]], 'ca_bundle'=>$ca]);
	}
	$tenantCa=new VestraDriver(['tenant'=>'tenant-one']);
	$t->same($ca, $t->nonPublic($tenantCa)->invoke('caBundle'));
})->tag('storage', 'drivers', 'vestra', 'coverage')->group('framework-coverage');
