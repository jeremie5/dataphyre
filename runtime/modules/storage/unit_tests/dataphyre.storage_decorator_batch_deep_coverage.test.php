<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Storage\Contracts\StorageDriver;
use Dataphyre\Storage\Drivers\CompressedDriver;
use Dataphyre\Storage\Drivers\IntegrityDriver;
use Dataphyre\Storage\Drivers\LifecycleDriver;
use Dataphyre\Storage\Drivers\ScannedDriver;
use Dataphyre\Storage\Drivers\TaggedDriver;
use Dataphyre\Storage\FileMetadata;
use Dataphyre\Storage\StorageManager;
use Dataphyre\Storage\Support\Path;
use Dataphyre\Storage\Support\Stream;
use Dataphyre\Test\Context;
use Dataphyre\Test\PhpRuntime;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['storage']);

if(!function_exists('Dataphyre\Storage\Drivers\tempnam')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Storage\Drivers;
function tempnam(string $directory, string $prefix): string|false { // dataphyre-test-architecture: exempt[unmanaged-temporary-file] reason="Storage failure shim must replace the native temporary-file function."
	$fail=\Dataphyre\Test\TestState::channelIfActive('storage.scanned-temp-files')?->get('fail', false) ?? false;
	if($fail===true){
		return false;
	}
	return \tempnam($directory, $prefix); // dataphyre-test-architecture: exempt[unmanaged-temporary-file] reason="Storage failure shim delegates successful calls to the native function."
}
PHP);
}

/** @param list<string> $arguments */
function dp_storage_scanner_command(array $arguments,bool $accepts_file=false): string {
	$command=implode(' ',array_map('escapeshellarg',PhpRuntime::command($arguments)));
	return $accepts_file ? $command.' {file}' : $command;
}

final class DpStorageDecoratorFakeDriver implements StorageDriver {
	/** @var array<string,string> */
	public array $objects=[];
	public bool $writeOk=true;
	public bool $deleteOk=true;
	public bool $readOk=true;
	public bool $metadataOk=true;
	public bool $urlOk=true;
	/** @var ?array<int|string,mixed> */
	public ?array $listOverride=null;
	/** @var array<string,mixed> */
	public array $lastOptions=[];

	public function exists(string $path): bool {
		return array_key_exists(Path::normalize($path), $this->objects);
	}

	public function read(string $path, array $options=[]): string|false {
		$path=Path::normalize($path);
		return $this->readOk && array_key_exists($path, $this->objects) ? $this->objects[$path] : false;
	}

	public function readStream(string $path, array $options=[]): mixed {
		$body=$this->read($path, $options);
		return $body===false ? false : Stream::fromString($body);
	}

	public function write(string $path, mixed $contents, array $options=[]): bool {
		$this->lastOptions=$options;
		if(!$this->writeOk){
			return false;
		}
		$body=is_resource($contents) ? (string)Stream::contents($contents) : (string)$contents;
		$this->objects[Path::normalize($path)]=$body;
		return true;
	}

	public function delete(string $path): bool {
		if(!$this->deleteOk){
			return false;
		}
		unset($this->objects[Path::normalize($path)]);
		return true;
	}

	public function metadata(string $path): FileMetadata|false {
		$path=Path::normalize($path);
		if(!$this->metadataOk || !array_key_exists($path, $this->objects)){
			return false;
		}
		return new FileMetadata($path, strlen($this->objects[$path]), 123, 'text/plain', ['fake'=>true]);
	}

	public function list(string $prefix='', array $options=[]): array {
		if($this->listOverride!==null){
			return $this->listOverride;
		}
		$prefix=Path::normalize($prefix);
		$out=[];
		foreach(array_keys($this->objects) as $path){
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
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
		return $this->urlOk ? 'memory://'.Path::normalize($path) : false;
	}
}

test('storage decorator batch compressed driver covers compression delegation manifests and reports', static function(Context $t): void {
	$workspace=$t->workspace('storage-decorator-compressed');
	$manifest=$workspace->path('nested/compression.json');
	$target=new DpStorageDecoratorFakeDriver();
	$manager=new StorageManager();
	$t->nonPublic($manager)->writeProperty('disks', ['decorator_target'=>$target]);
		$t->throws(static fn()=>new CompressedDriver([], $manager), RuntimeException::class);
		$singleton=new CompressedDriver(['target'=>'decorator-target', 'level'=>99, 'min_bytes'=>-1, 'skip_extensions'=>['.TXT', '']], null);
		$t->instanceOf(CompressedDriver::class, $singleton);
		$driver=new CompressedDriver([
			'disk'=>'decorator-target',
			'manifest'=>$manifest,
			'level'=>9,
			'min_bytes'=>8,
			'skip_extensions'=>['.JPG', '', 'zip'],
		], $manager);

		$t->isFalse($driver->exists('docs/missing.txt'));
		$t->same(false, $driver->read('docs/missing.txt'));
		$t->same(false, $driver->readStream('docs/missing.txt'));
		$t->isFalse($driver->write('', 'body'));

		$compressible=str_repeat('compress-me-', 100);
		$t->isTrue($driver->write('/docs/compress.txt', $compressible, ['compression_level'=>7]));
		$t->isTrue($driver->exists('docs/compress.txt'));
		$t->isTrue(strlen($target->objects['docs/compress.txt'])<strlen($compressible));
		$t->same($compressible, $driver->read('docs/compress.txt'));
		$stream=$driver->readStream('docs/compress.txt');
		$t->isTrue(is_resource($stream));
		$t->same($compressible, Stream::contents($stream));

		$resource=Stream::fromString('raw-resource');
		$t->isTrue($driver->write('docs/raw.txt', $resource, ['compress'=>false]));
		$t->same('raw-resource', $target->objects['docs/raw.txt']);
		$t->isTrue($driver->write('docs/small.txt', 'tiny'));
		$t->isTrue($driver->write('docs/image.JPG', str_repeat('j', 100)));
		$t->same(str_repeat('j', 100), $target->objects['docs/image.JPG']);
		$t->isTrue($driver->write('docs/no-extension', str_repeat('z', 100)));
		$t->isTrue($driver->write('docs/inefficient.txt', 'x', ['compress'=>true]));
		$t->same('x', $target->objects['docs/inefficient.txt']);
		$t->isTrue($driver->write('docs/forced.txt', 'x', ['compress'=>true, 'force_compression'=>true]));
		$t->isTrue(str_starts_with($target->objects['docs/forced.txt'], "\x1f\x8b"));
		$t->isTrue($driver->write('docs/empty.txt', '', ['compress'=>false]));

		$target->writeOk=false;
		$t->isFalse($driver->write('docs/fail.txt', 'body'));
		$target->writeOk=true;

		$plainMetadata=$driver->metadata('untracked.txt');
		$t->same(false, $plainMetadata);
		$metadata=$driver->metadata('/docs/compress.txt');
		$t->instanceOf(FileMetadata::class, $metadata);
		$t->same(strlen($compressible), $metadata->size());
		$t->isTrue(isset($metadata->extra()['compression']));
		$target->objects['docs/untracked.txt']='untracked';
		$t->same([], $target->list('outside'));
		$metadata=$driver->metadata('docs/untracked.txt');
		$t->same(9, $metadata->size());
		$t->isFalse(isset($metadata->extra()['compression']));

		$target->listOverride=[
			'not-metadata',
			new FileMetadata('docs/compress.txt'),
			new FileMetadata('docs/stale.txt'),
		];
		$list=$driver->list('docs', ['probe'=>true]);
		$t->same(1, count($list));
		$target->listOverride=null;
		$t->same('memory://docs/compress.txt', $driver->temporaryUrl('docs/compress.txt', time()+60, ['download'=>true]));

		$report=$driver->compressionReport('docs');
		$t->isTrue($report['objects']>=7);
		$t->isTrue($report['compressed_objects']>=2);
		$t->isTrue($report['original_bytes']>=$report['stored_bytes']);
		$t->same(0, $driver->compressionReport('elsewhere')['objects']);

		$workspace->file('nested/compression.json', (string)json_encode([
			'bad'=>'malformed',
			'other/item'=>['compressed'=>false, 'original_size'=>2, 'stored_size'=>5],
			'docs/zero'=>['compressed'=>false, 'original_size'=>0, 'stored_size'=>0],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		$report=$driver->compressionReport();
		$t->same(2, $report['objects']);
		$t->same(0, $report['saved_bytes']);
		$t->same(2.5, $report['ratio']);

		$target->objects['docs/bad.gz']='not-gzip';
		$workspace->file('nested/compression.json', (string)json_encode([
			'docs/bad.gz'=>['compressed'=>true, 'original_size'=>8, 'stored_size'=>8],
		]));
		$t->same(false, $driver->readStream('docs/bad.gz'));
		$workspace->file('nested/compression.json', '{invalid');
		$t->same(0, $driver->compressionReport()['objects']);
		$t->isTrue($driver->delete('docs/bad.gz'));
})->tag('storage', 'decorator-batch', 'compressed', 'deep-coverage')->group('framework-coverage');

test('storage decorator batch scanned driver covers patterns commands quarantine and reports', static function(Context $t): void {
	$workspace=$t->workspace('storage-decorator-scanned');
	$manifest=$workspace->path('nested/scan.json');
	$tempFailure=$t->state('storage.scanned-temp-files', ['fail'=>false]);
	$target=new DpStorageDecoratorFakeDriver();
	$quarantine=new DpStorageDecoratorFakeDriver();
	$manager=new StorageManager();
	$t->nonPublic($manager)->writeProperty('disks', [
		'decorator_target'=>$target,
		'decorator_quarantine'=>$quarantine,
	]);
		$t->throws(static fn()=>new ScannedDriver([], $manager), RuntimeException::class);
		$t->throws(static fn()=>new ScannedDriver(['disk'=>'decorator-target', 'quarantine'=>''], $manager), RuntimeException::class);
		$t->throws(static fn()=>new ScannedDriver(['disk'=>'decorator-target', 'quarantine_prefix'=>'/'], $manager), RuntimeException::class);
		$singleton=new ScannedDriver(['target'=>'decorator-target'], null);
		$t->instanceOf(ScannedDriver::class, $singleton);

		$driver=new ScannedDriver([
			'disk'=>'decorator-target',
			'quarantine_disk'=>'decorator-quarantine',
			'quarantine_prefix'=>'quarantine',
			'manifest'=>$manifest,
			'deny_patterns'=>['/virus/i', 'literal-bad', ''],
		], $manager);
		$t->isFalse($driver->exists('safe.txt'));
		$t->same(false, $driver->read('safe.txt'));
		$t->same(false, $driver->readStream('safe.txt'));
		$t->isFalse($driver->write('', 'body'));
		$t->isFalse($driver->write('blocked/regex.txt', 'contains VIRUS bytes'));
		$t->isFalse($driver->write('blocked/plain.txt', 'contains literal-bad bytes'));
		$t->same(2, count($quarantine->objects));
		$t->isTrue($driver->write('safe.txt', Stream::fromString('clean body'), ['content_type'=>'text/plain']));
		$t->same('clean body', $driver->read('safe.txt'));
		$t->isTrue(is_resource($driver->readStream('safe.txt')));
		$t->same('memory://safe.txt', $driver->temporaryUrl('safe.txt', new DateTimeImmutable('+1 minute')));
		$t->same(1, count($driver->list()));

		$metadata=$driver->metadata('/safe.txt');
		$t->instanceOf(FileMetadata::class, $metadata);
		$t->same('clean', $metadata->extra()['scan']['status']);
		$target->objects['untracked.txt']='raw';
		$t->same([], $driver->metadata('untracked.txt')->extra()['scan']);
		$target->metadataOk=false;
		$t->same(false, $driver->metadata('safe.txt'));
		$target->metadataOk=true;

		$target->writeOk=false;
		$t->isFalse($driver->write('write-fails.txt', 'clean'));
		$target->writeOk=true;

		$required=new ScannedDriver([
			'disk'=>'decorator-target', 'quarantine_disk'=>'decorator-quarantine',
			'manifest'=>$workspace->path('required.json'), 'require_scanner'=>true,
		], $manager);
		$t->isFalse($required->write('required.txt', 'body'));

		$cleanCommand=dp_storage_scanner_command(['-r',"echo 'scanner-clean'; exit(0);"]);
		$commandDriver=new ScannedDriver([
			'disk'=>'decorator-target', 'quarantine_disk'=>'decorator-quarantine',
			'manifest'=>$workspace->path('command.json'), 'scanner_command'=>$cleanCommand,
		], $manager);
		$t->isTrue($commandDriver->write('command-clean.txt', 'body'));

		$blockedCommand=dp_storage_scanner_command(['-r',"echo 'scanner-blocked'; exit(3);",'--'],true);
		$blockedDriver=new ScannedDriver([
			'disk'=>'decorator-target', 'quarantine_disk'=>'decorator-quarantine',
			'manifest'=>$workspace->path('command-blocked.json'), 'scanner_command'=>$blockedCommand,
		], $manager);
		$t->isFalse($blockedDriver->write('command-blocked.txt', 'body'));

		$tempFailure->put('fail', true);
		$t->isFalse($commandDriver->write('temp-fails.txt', 'body'));
		$tempFailure->put('fail', false);

		$report=$driver->scanReport();
		$t->same(3, $report['objects']);
		$t->same(1, $report['clean']);
		$t->same(2, $report['blocked']);
		$t->same(0, $driver->scanReport('missing')['objects']);

		$records=json_decode((string)file_get_contents($manifest), true);
		$blockedPaths=[];
		foreach($records as $path=>$record){
			if(($record['status'] ?? '')==='quarantined'){
				$blockedPaths[$path]=$record;
			}
		}
		$t->same(0, $driver->purgeQuarantine('elsewhere')['purged']);
		$quarantine->deleteOk=false;
		$t->same(0, $driver->purgeQuarantine('blocked/regex.txt')['purged']);
		$quarantine->deleteOk=true;
		$t->same(1, $driver->purgeQuarantine('blocked/plain.txt')['purged']);

		$workspace->file('nested/scan.json', (string)json_encode([
			'bad'=>'record',
			'clean'=>['status'=>'clean'],
			'empty'=>['status'=>'quarantined', 'quarantine_path'=>''],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		$t->same(0, $driver->purgeQuarantine()['purged']);
		$t->same(1, $driver->scanReport()['objects']);
		$workspace->file('nested/scan.json', 'not-json');
		$t->same(0, $driver->scanReport()['objects']);

		$t->isTrue($driver->delete('safe.txt'));
})->tag('storage', 'decorator-batch', 'scanned', 'deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('storage decorator batch tagged driver covers sidecar mutation search normalization and reports', static function(Context $t): void {
	$workspace=$t->workspace('storage-decorator-tagged');
	$manifest=$workspace->path('nested/tags.json');
	$target=new DpStorageDecoratorFakeDriver();
	$manager=new StorageManager();
	$t->nonPublic($manager)->writeProperty('disks', ['decorator_target'=>$target]);
		$t->throws(static fn()=>new TaggedDriver([], $manager), RuntimeException::class);
		$t->instanceOf(TaggedDriver::class, new TaggedDriver(['target'=>'decorator-target'], null));
		$driver=new TaggedDriver(['disk'=>'decorator-target', 'manifest'=>$manifest], $manager);
		$t->isFalse($driver->exists('missing.txt'));
		$t->same(false, $driver->read('missing.txt'));
		$t->same(false, $driver->readStream('missing.txt'));

		$target->writeOk=false;
		$t->isFalse($driver->write('fails.txt', 'body'));
		$target->writeOk=true;
		$t->isTrue($driver->write('/docs/a.txt', 'alpha', [
			'tags'=>' One, TWO one ',
			'metadata'=>[' owner '=>'alice', ''=>'ignored', 'nested'=>['x'=>1], 'nullable'=>null],
		]));
		$t->same(['one', 'two'], $driver->tagsFor('docs/a.txt')['tags']);
		$t->same('{"x":1}', $driver->tagsFor('docs/a.txt')['metadata']['nested']);
		$t->isTrue($driver->write('docs/empty.txt', 'empty'));
		$emptyBefore=$driver->tagsFor('docs/empty.txt');
		$t->same(['tags'=>[], 'metadata'=>[]], $emptyBefore);
		$t->isTrue($driver->write('docs/empty.txt', 'updated'));

		$t->isFalse($driver->tagObject('missing.txt', ['tags'=>['x']]));
		$firstCreated=json_decode((string)file_get_contents($manifest), true)['docs/a.txt']['created_at'];
		$t->isTrue($driver->tagObject('docs/a.txt', [
			'tags'=>['two', 'THREE', ''],
			'custom_metadata'=>['owner'=>'bob', 'flag'=>true],
		]));
		$record=$driver->tagsFor('docs/a.txt');
		$t->same(['one', 'two', 'three'], $record['tags']);
		$t->same('bob', $record['metadata']['owner']);
		$t->same($firstCreated, json_decode((string)file_get_contents($manifest), true)['docs/a.txt']['created_at']);
		$t->isTrue($driver->tagObject('docs/a.txt', ['tags'=>['replacement'], 'metadata'=>['only'=>1], 'merge'=>false]));
		$t->same(['replacement'], $driver->tagsFor('docs/a.txt')['tags']);
		$t->same(['tags'=>[], 'metadata'=>[]], $driver->tagsFor('no-record.txt'));

		$metadata=$driver->metadata('/docs/a.txt');
		$t->instanceOf(FileMetadata::class, $metadata);
		$t->same(['replacement'], $metadata->extra()['tags']);
		$target->objects['docs/untracked.txt']='raw';
		$t->same([], $driver->metadata('docs/untracked.txt')->extra()['tags']);
		$target->metadataOk=false;
		$t->same(false, $driver->metadata('docs/a.txt'));
		$target->metadataOk=true;

		$target->listOverride=['bad', new FileMetadata('docs/a.txt'), new FileMetadata('docs/stale.txt')];
		$t->same(1, count($driver->list('docs')));
		$target->listOverride=null;
		$t->same('memory://docs/a.txt', $driver->temporaryUrl('docs/a.txt', time()+60));

		$t->isTrue($driver->write('docs/b.txt', 'beta', ['tags'=>['replacement', 'blue']]));
		$t->isTrue($driver->write('other/c.txt', 'gamma', ['tags'=>['blue']]));
		$t->same(2, count($driver->findByTags(['replacement'])));
		$t->same(1, count($driver->findByTags(['replacement', 'blue'])));
		$t->same(3, count($driver->findByTags(['replacement', 'blue'], ['match_all'=>false])));
		$t->same(3, count($driver->findByTags([], ['prefix'=>'docs'])));
		$t->same(0, count($driver->findByTags(['absent'])));
		$t->same(1, count($driver->findByTags(['blue'], ['prefix'=>'other'])));

		$report=$driver->tagReport('docs');
		$t->same(3, $report['objects']);
		$t->same(2, $report['tags']['replacement']);
		$t->same(0, $driver->tagReport('missing')['objects']);

		$raw=json_decode((string)file_get_contents($manifest), true);
		$raw['bad']='record';
		$raw['stale/file.txt']=['tags'=>['replacement'], 'metadata'=>[]];
		$workspace->file('nested/tags.json', (string)json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		$t->same(2, count($driver->findByTags(['replacement'])));
		$t->same(5, $driver->tagReport()['objects']);
		$raw['docs/malformed.txt']='record';
		$workspace->file('nested/tags.json', (string)json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		$target->objects['docs/malformed.txt']='body';
		$t->isTrue($driver->tagObject('docs/malformed.txt', ['tags'=>['fixed']]));

		$workspace->file('nested/tags.json', '{');
		$t->same(['tags'=>[], 'metadata'=>[]], $driver->tagsFor('docs/a.txt'));
		$t->same(0, $driver->tagReport()['objects']);
		$t->isTrue($driver->delete('docs/a.txt'));
})->tag('storage', 'decorator-batch', 'tagged', 'deep-coverage')->group('framework-coverage');

test('storage decorator batch lifecycle driver covers rules durations evaluation and deletion outcomes', static function(Context $t): void {
	$workspace=$t->workspace('storage-decorator-lifecycle');
	$manifest=$workspace->path('nested/lifecycle.json');
	$target=new DpStorageDecoratorFakeDriver();
	$manager=new StorageManager();
	$t->nonPublic($manager)->writeProperty('disks', ['decorator_target'=>$target]);
		$t->throws(static fn()=>new LifecycleDriver([], $manager), RuntimeException::class);
		$t->instanceOf(LifecycleDriver::class, new LifecycleDriver(['target'=>'decorator-target', 'rules'=>'invalid'], null));
		$fallback=new LifecycleDriver(['disk'=>'decorator-target', 'manifest'=>$workspace->path('fallback.json'), 'delete_after'=>0], $manager);
		$t->instanceOf(LifecycleDriver::class, $fallback);
		$driver=new LifecycleDriver([
			'disk'=>'decorator-target',
			'manifest'=>$manifest,
			'rules'=>[
				'invalid',
				['prefix'=>'archive', 'extensions'=>['.TXT'], 'delete_after'=>0],
				['prefix'=>'other', 'extensions'=>['bin'], 'max_age'=>'0'],
				['prefix'=>'future', 'delete_after'=>'2 days'],
				['prefix'=>'none', 'delete_after'=>null],
			],
		], $manager);

		$t->isFalse($driver->exists('archive/a.txt'));
		$t->same(false, $driver->read('archive/a.txt'));
		$t->same(false, $driver->readStream('archive/a.txt'));
		$target->writeOk=false;
		$t->isFalse($driver->write('archive/fail.txt', 'body'));
		$target->writeOk=true;
		$t->isTrue($driver->write('/archive/a.txt', 'alpha'));
		$t->isTrue($driver->write('archive/no.bin', 'bin'));
		$t->isTrue($driver->write('other/b.bin', 'beta'));
		$t->isTrue($driver->write('future/c.txt', 'future'));
		$t->isTrue($driver->write('none/d.txt', 'none'));
		$created=json_decode((string)file_get_contents($manifest), true)['archive/a.txt']['created_at'];
		$t->isTrue($driver->write('archive/a.txt', 'updated'));
		$t->same($created, json_decode((string)file_get_contents($manifest), true)['archive/a.txt']['created_at']);

		$target->metadataOk=false;
		$t->isTrue($driver->write('archive/no-metadata.txt', 'body'));
		$target->metadataOk=true;
		$t->same(null, json_decode((string)file_get_contents($manifest), true)['archive/no-metadata.txt']['size']);

		$metadata=$driver->metadata('archive/a.txt');
		$t->instanceOf(FileMetadata::class, $metadata);
		$t->same('archive/a.txt', $metadata->extra()['lifecycle']['path']);
		$target->objects['untracked.txt']='raw';
		$t->same([], $driver->metadata('untracked.txt')->extra()['lifecycle']);
		$target->metadataOk=false;
		$t->same(false, $driver->metadata('archive/a.txt'));
		$target->metadataOk=true;
		$t->same(count($target->objects), count($driver->list()));
		$t->same('memory://archive/a.txt', $driver->temporaryUrl('archive/a.txt', time()+60));

		$report=$driver->lifecycleReport();
		$t->isTrue($report['dry_run']);
		$t->contains('archive/a.txt', $report['paths']);
		$t->contains('other/b.bin', $report['paths']);
		$t->isFalse(in_array('archive/no.bin', $report['paths'], true));
		$t->same(0, $driver->lifecycleReport('missing')['eligible']);
		$t->isTrue($driver->applyLifecycle('', ['dry_run'=>true])['dry_run']);

		$target->deleteOk=false;
		$failed=$driver->applyLifecycle('archive');
		$t->isFalse($failed['ok']);
		$t->same(0, $failed['deleted']);
		$target->deleteOk=true;
		$applied=$driver->applyLifecycle('archive');
		$t->isTrue($applied['ok']);
		$t->isTrue($applied['deleted']>=2);

		$internals=$t->nonPublic($driver);
		$t->same([], $internals->invoke('normalizeRules', 'invalid'));
		$t->same([['x'=>1]], $internals->invoke('normalizeRules', ['bad', ['x'=>1]]));
		$t->same(null, $internals->invoke('durationSeconds', null));
		$t->same(null, $internals->invoke('durationSeconds', ''));
		$t->same(0, $internals->invoke('durationSeconds', -2));
		$t->same(12, $internals->invoke('durationSeconds', '12'));
		$t->isTrue($internals->invoke('durationSeconds', '1 hour')>0);
		$t->same(null, $internals->invoke('durationSeconds', 'not-a-duration-value'));
		$t->same(null, $internals->invoke('durationSeconds', []));

		$workspace->file('nested/lifecycle.json', (string)json_encode([
			'bad'=>'record',
			'archive/zero.txt'=>['created_at'=>0],
			'archive/future.txt'=>['created_at'=>time()+100],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		$t->same(0, $driver->lifecycleReport()['eligible']);
		$workspace->file('nested/lifecycle.json', 'invalid-json');
		$t->same(0, $driver->lifecycleReport()['eligible']);
		$target->objects['manual.txt']='body';
		$t->isTrue($driver->delete('manual.txt'));
})->tag('storage', 'decorator-batch', 'lifecycle', 'deep-coverage')->group('framework-coverage');

test('storage decorator batch integrity driver covers checksums manifests verification and reports', static function(Context $t): void {
	$workspace=$t->workspace('storage-decorator-integrity');
	$manifest=$workspace->path('nested/integrity.json');
	$target=new DpStorageDecoratorFakeDriver();
	$manager=new StorageManager();
	$t->nonPublic($manager)->writeProperty('disks', ['decorator_target'=>$target]);
		$t->throws(static fn()=>new IntegrityDriver([], $manager), RuntimeException::class);
		$t->throws(static fn()=>new IntegrityDriver(['disk'=>'decorator-target', 'algorithm'=>'no-such-hash'], $manager), RuntimeException::class);
		$t->instanceOf(IntegrityDriver::class, new IntegrityDriver(['target'=>'decorator-target'], null));
		$driver=new IntegrityDriver(['disk'=>'decorator-target', 'manifest'=>$manifest, 'algorithm'=>'sha256'], $manager);

		$t->isFalse($driver->exists('missing.txt'));
		$t->same(false, $driver->read('missing.txt'));
		$t->same(false, $driver->readStream('missing.txt'));
		$target->writeOk=false;
		$t->isFalse($driver->write('fails.txt', 'body'));
		$target->writeOk=true;
		$t->isTrue($driver->write('/docs/a.txt', 'alpha'));
		$t->isTrue($driver->write('docs/md5.txt', 'md5-body', ['integrity_algorithm'=>'md5']));
		$t->isTrue($driver->write('docs/unrecorded.txt', 'body', ['integrity_algorithm'=>'invalid-algorithm']));
		$target->readOk=false;
		$t->isTrue($driver->write('docs/unreadable.txt', 'body'));
		$target->readOk=true;

		$t->isTrue($driver->exists('docs/a.txt'));
		$t->same('alpha', $driver->read('docs/a.txt'));
		$t->isTrue(is_resource($driver->readStream('docs/a.txt')));
		$t->same('memory://docs/a.txt', $driver->temporaryUrl('docs/a.txt', time()+60));
		$t->same(count($target->objects), count($driver->list()));

		$metadata=$driver->metadata('docs/a.txt');
		$t->instanceOf(FileMetadata::class, $metadata);
		$t->same('sha256', $metadata->extra()['integrity']['algorithm']);
		$t->isFalse(isset($driver->metadata('docs/unrecorded.txt')->extra()['integrity']));
		$target->metadataOk=false;
		$t->same(false, $driver->metadata('docs/a.txt'));
		$target->metadataOk=true;

		$t->isFalse($driver->verifyIntegrity('missing.txt')['ok']);
		$t->isTrue($driver->verifyIntegrity('docs/a.txt')['ok']);
		$target->objects['docs/a.txt']='tampered';
		$t->isFalse($driver->verifyIntegrity('docs/a.txt')['ok']);
		$target->readOk=false;
		$t->same('Unable to read object for integrity verification.', $driver->verifyIntegrity('docs/a.txt')['message']);
		$target->readOk=true;
		$target->objects['docs/a.txt']='alpha';

		$report=$driver->integrityReport('docs');
		$t->same(2, $report['checked']);
		$t->same(2, $report['passed']);
		$t->same(0, $driver->integrityReport('other')['checked']);

		$target->deleteOk=false;
		$t->isFalse($driver->delete('docs/a.txt'));
		$t->isTrue($driver->verifyIntegrity('docs/a.txt')['ok']);
		$target->deleteOk=true;
		$t->isTrue($driver->delete('docs/a.txt'));
		$t->isFalse($driver->verifyIntegrity('docs/a.txt')['ok']);

		$target->metadataOk=false;
		$t->isTrue($driver->write('docs/no-metadata.txt', 'body'));
		$target->metadataOk=true;
		$record=json_decode((string)file_get_contents($manifest), true)['docs/no-metadata.txt'];
		$t->same(null, $record['size']);

		$workspace->file('nested/integrity.json', (string)json_encode([
			'docs/fallback.txt'=>['checksum'=>hash('sha256', 'fallback')],
			'docs/bad.txt'=>'record',
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		$target->objects['docs/fallback.txt']='fallback';
		$t->isTrue($driver->verifyIntegrity('docs/fallback.txt')['ok']);
		$report=$driver->integrityReport('docs');
		$t->same(2, $report['checked']);
		$t->same(1, $report['failed']);
		$workspace->file('nested/integrity.json', 'invalid-json');
		$t->same(0, $driver->integrityReport()['checked']);
})->tag('storage', 'decorator-batch', 'integrity', 'deep-coverage')->group('framework-coverage');
