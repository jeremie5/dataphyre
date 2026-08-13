<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Storage\Contracts\StorageDriver;
use Dataphyre\Storage\Drivers\MemoryDriver;
use Dataphyre\Storage\FileMetadata;
use Dataphyre\Storage\StorageManager;
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
$dp_storage_manager_deep_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_storage_manager_deep_modules_root.'/core/kernel/autoloader.php';
require_once $dp_storage_manager_deep_modules_root.'/core/kernel/core_functions.php';
\dataphyre\autoloader::register($dp_storage_manager_deep_modules_root);
\dataphyre\autoloader::register_framework_modules(['storage']);

class DpStorageManagerCapabilityDriver implements StorageDriver {
	/** @var array<string,string> */
	public array $objects=['docs/item.txt'=>'payload'];
	public bool $throwOnList=false;

	public function exists(string $path): bool {
		return array_key_exists(Path::normalize($path), $this->objects);
	}

	public function read(string $path, array $options=[]): string|false {
		return $this->objects[Path::normalize($path)] ?? false;
	}

	public function readStream(string $path, array $options=[]): mixed {
		$body=$this->read($path, $options);
		return $body===false ? false : Stream::fromString($body);
	}

	public function write(string $path, mixed $contents, array $options=[]): bool {
		$body=is_resource($contents) ? Stream::contents($contents) : (string)$contents;
		if($body===false){
			return false;
		}
		$this->objects[Path::normalize($path)]=$body;
		return true;
	}

	public function delete(string $path): bool {
		unset($this->objects[Path::normalize($path)]);
		return true;
	}

	public function metadata(string $path): FileMetadata|false {
		$body=$this->read($path);
		return $body===false ? false : new FileMetadata(Path::normalize($path), strlen($body), 20, 'text/plain');
	}

	public function list(string $prefix='', array $options=[]): array {
		if($this->throwOnList){
			throw new RuntimeException('deterministic list failure');
		}
		$out=[];
		foreach($this->objects as $path=>$body){
			$out[]=new FileMetadata($path, strlen($body), 20, 'text/plain');
		}
		return $out;
	}

	public function temporaryUrl(string $path, int|DateTimeInterface $expires, array $options=[]): string|false {
		return 'read://'.Path::normalize($path);
	}

	public function temporaryUploadUrl(string $path, int|DateTimeInterface $expires, array $options=[]): string|false {
		return 'upload://'.Path::normalize($path);
	}

	public function initiateMultipartUpload(string $path, array $options=[]): array|false {
		return ['upload_id'=>'upload-1', 'path'=>$path];
	}

	public function temporaryMultipartUploadUrls(string $path, string $uploadId, int $parts, int|DateTimeInterface $expires, array $options=[]): array|false {
		return [['part'=>1, 'url'=>'part://1']];
	}

	public function completeMultipartUpload(string $path, string $uploadId, array $parts): bool { return true; }
	public function abortMultipartUpload(string $path, string $uploadId): bool { return true; }
	public function versions(string $path): array { return [['id'=>'v1']]; }
	public function restoreVersion(string $path, string $versionId, array $options=[]): bool { return true; }
	public function purgeVersion(string $path, string $versionId): bool { return true; }
	public function pruneVersions(?string $path=null, array $options=[]): array { return ['ok'=>true, 'pruned'=>1]; }
	public function verifyIntegrity(string $path, array $options=[]): array { return ['ok'=>true, 'path'=>$path]; }
	public function integrityReport(string $prefix='', array $options=[]): array { return ['ok'=>true, 'checked'=>1]; }
	public function auditTrail(?string $path=null, array $options=[]): array { return [['path'=>$path]]; }
	public function deduplicationReport(string $prefix='', array $options=[]): array { return ['ok'=>true, 'logical_objects'=>1]; }
	public function quotaReport(string $prefix='', array $options=[]): array { return ['ok'=>true, 'objects'=>1]; }
	public function cacheReport(string $prefix='', array $options=[]): array { return ['ok'=>true, 'objects'=>1]; }
	public function purgeCache(string $prefix='', array $options=[]): array { return ['ok'=>true, 'purged'=>1]; }
	public function compressionReport(string $prefix='', array $options=[]): array { return ['ok'=>true, 'objects'=>1]; }
	public function setRetention(string $path, array $options=[]): bool { return true; }
	public function releaseRetention(string $path, array $options=[]): bool { return true; }
	public function retentionReport(string $prefix='', array $options=[]): array { return ['ok'=>true, 'objects'=>1]; }
	public function lifecycleReport(string $prefix='', array $options=[]): array { return ['ok'=>true, 'eligible'=>1]; }
	public function applyLifecycle(string $prefix='', array $options=[]): array { return ['ok'=>true, 'eligible'=>2, 'deleted'=>1, 'errors'=>['one']]; }
	public function scanReport(string $prefix='', array $options=[]): array { return ['ok'=>true, 'objects'=>1]; }
	public function purgeQuarantine(string $prefix='', array $options=[]): array { return ['ok'=>true, 'purged'=>1, 'errors'=>['one']]; }
	public function tagObject(string $path, array $options=[]): bool { return true; }
	public function tagsFor(string $path): array { return ['tags'=>['kind'=>'test'], 'metadata'=>[]]; }
	public function findByTags(array $tags, array $options=[]): array { return [['path'=>'docs/item.txt']]; }
	public function tagReport(string $prefix='', array $options=[]): array { return ['ok'=>true, 'objects'=>1]; }
	public function policyReport(string $prefix='', array $options=[]): array { return ['ok'=>true, 'rules'=>['allow']]; }
	public function rateLimitReport(string $prefix='', array $options=[]): array { return ['ok'=>true, 'limits'=>['read']]; }
	public function resetRateLimits(string $prefix='', array $options=[]): array { return ['ok'=>true, 'reset'=>true]; }
	public function eventTrail(?string $path=null, array $options=[]): array { return [['path'=>$path]]; }
}

final class DpStorageManagerSyncDriver implements StorageDriver {
	/** @var array<string,string> */
	public array $objects=[];
	/** @var list<FileMetadata|mixed> */
	public array $listing=[];
	/** @var list<string> */
	public array $unreadable=[];
	/** @var list<string> */
	public array $failWrites=[];
	/** @var list<string> */
	public array $failDeletes=[];

	public function exists(string $path): bool { return array_key_exists(Path::normalize($path), $this->objects); }
	public function read(string $path, array $options=[]): string|false { return $this->objects[Path::normalize($path)] ?? false; }
	public function readStream(string $path, array $options=[]): mixed {
		$path=Path::normalize($path);
		if(in_array($path, $this->unreadable, true) || !array_key_exists($path, $this->objects)){
			return false;
		}
		return Stream::fromString($this->objects[$path]);
	}
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$path=Path::normalize($path);
		if(in_array($path, $this->failWrites, true)){
			return false;
		}
		$body=is_resource($contents) ? Stream::contents($contents) : (string)$contents;
		if($body===false){
			return false;
		}
		$this->objects[$path]=$body;
		return true;
	}
	public function delete(string $path): bool {
		$path=Path::normalize($path);
		if(in_array($path, $this->failDeletes, true)){
			return false;
		}
		unset($this->objects[$path]);
		return true;
	}
	public function metadata(string $path): FileMetadata|false {
		$body=$this->read($path);
		return $body===false ? false : new FileMetadata(Path::normalize($path), strlen($body), 10, 'text/plain');
	}
	public function list(string $prefix='', array $options=[]): array { return $this->listing; }
	public function temporaryUrl(string $path, int|DateTimeInterface $expires, array $options=[]): string|false { return false; }
}

test('storage manager delegates every supported advanced capability and lifecycle hook', static function(Context $t): void {
	$driver=new DpStorageManagerCapabilityDriver();
	$manager=new StorageManager();
	$t->nonPublic($manager)->writeProperty('disks', ['advanced'=>$driver]);
	$dialbackState=$t->state('storage.manager-capability-dialbacks');
	$coreInternals=$t->nonPublic(\dataphyre\core::class);
	$baselineDialbacks=$coreInternals->readProperty('dialbacks');
	$coreInternals->replacePropertyForTest('dialbacks', $baselineDialbacks);

	$t->same('upload://docs/new.txt', $manager->temporaryUploadUrl('docs/new.txt', time()+60, 'advanced'));
	$t->same('upload-1', $manager->initiateMultipartUpload('docs/new.txt', 'advanced')['upload_id']);
	$t->same('part://1', $manager->temporaryMultipartUploadUrls('docs/new.txt', 'upload-1', 1, time()+60, 'advanced')[0]['url']);
	$t->isTrue($manager->completeMultipartUpload('docs/new.txt', 'upload-1', [], 'advanced'));
	$t->isTrue($manager->abortMultipartUpload('docs/new.txt', 'upload-1', 'advanced'));
	$t->same('v1', $manager->versions('docs/item.txt', 'advanced')[0]['id']);
	$t->isTrue($manager->restoreVersion('docs/item.txt', 'v1', 'advanced'));
	$t->isTrue($manager->purgeVersion('docs/item.txt', 'v1', 'advanced'));
	$t->isTrue($manager->pruneVersions(null, 'advanced')['ok']);
	$t->isTrue($manager->verifyIntegrity('docs/item.txt', 'advanced')['ok']);
	$t->same(1, $manager->integrityReport('docs', 'advanced')['checked']);
	$t->same('docs/item.txt', $manager->auditTrail('docs/item.txt', 'advanced')[0]['path']);
	$t->isTrue($manager->deduplicationReport('docs', 'advanced')['ok']);
	$t->same(1, $manager->quotaReport('docs', 'advanced')['objects']);
	$t->same(1, $manager->cacheReport('docs', 'advanced')['objects']);
	$t->same(1, $manager->purgeCache('docs', 'advanced')['purged']);
	$t->same(1, $manager->compressionReport('docs', 'advanced')['objects']);
	$t->isTrue($manager->setRetention('docs/item.txt', 'advanced'));
	$t->isTrue($manager->releaseRetention('docs/item.txt', 'advanced'));
	$t->same(1, $manager->retentionReport('docs', 'advanced')['objects']);
	$t->same(1, $manager->lifecycleReport('docs', 'advanced')['eligible']);
	$t->same(1, $manager->scanReport('docs', 'advanced')['objects']);
	$t->isTrue($manager->tagObject('docs/item.txt', 'advanced', ['tags'=>['kind'=>'test']]));
	$t->same('test', $manager->tagsFor('docs/item.txt', 'advanced')['tags']['kind']);
	$t->same('docs/item.txt', $manager->findByTags(['kind'=>'test'], 'advanced')[0]['path']);
	$t->same(1, $manager->tagReport('docs', 'advanced')['objects']);
	$t->isTrue($manager->policyReport('docs', 'advanced')['ok']);
	$t->isTrue($manager->rateLimitReport('docs', 'advanced')['ok']);
	$t->isTrue($manager->resetRateLimits('docs', 'advanced')['reset']);
	$t->same('docs/item.txt', $manager->eventTrail('docs/item.txt', 'advanced')[0]['path']);

	\dataphyre\core::register_dialback('CALL_STORAGE_FRAMEWORK_LIFECYCLE_BEFORE_APPLY', static function(array $payload) use ($dialbackState): array {
		$dialbackState->put('lifecycle_before', $payload);
		return ['before'=>$payload['prefix']];
	});
	$t->same('docs', $manager->applyLifecycle('/docs/', 'advanced')['before']);
	$t->same('docs', $dialbackState->get('lifecycle_before')['prefix']);
	$coreInternals->writeProperty('dialbacks', $baselineDialbacks);
	$t->same(1, $manager->applyLifecycle('docs', 'advanced')['deleted']);
	\dataphyre\core::register_dialback('CALL_STORAGE_FRAMEWORK_LIFECYCLE_AFTER_APPLY', static function(array $payload) use ($dialbackState): array {
		$dialbackState->put('lifecycle_after', $payload);
		return ['after'=>$payload['counts']];
	});
	$t->same(1, $manager->applyLifecycle('docs', 'advanced')['after']['deleted']);
	$t->same(1, $dialbackState->get('lifecycle_after')['counts']['deleted']);
	$coreInternals->writeProperty('dialbacks', $baselineDialbacks);

	\dataphyre\core::register_dialback('CALL_STORAGE_FRAMEWORK_QUARANTINE_BEFORE_PURGE', static function(array $payload) use ($dialbackState): array {
		$dialbackState->put('quarantine_before', $payload);
		return ['before'=>$payload['prefix']];
	});
	$t->same('docs', $manager->purgeQuarantine('/docs/', 'advanced')['before']);
	$t->same('docs', $dialbackState->get('quarantine_before')['prefix']);
	$coreInternals->writeProperty('dialbacks', $baselineDialbacks);
	$t->same(1, $manager->purgeQuarantine('docs', 'advanced')['purged']);
	\dataphyre\core::register_dialback('CALL_STORAGE_FRAMEWORK_QUARANTINE_AFTER_PURGE', static function(array $payload) use ($dialbackState): array {
		$dialbackState->put('quarantine_after', $payload);
		return ['after'=>$payload['counts']];
	});
	$t->same(1, $manager->purgeQuarantine('docs', 'advanced')['after']['purged']);
	$t->same(1, $dialbackState->get('quarantine_after')['counts']['purged']);
})->tag('storage', 'manager', 'deep-coverage')->group('framework-coverage');

test('storage manager covers encryption stream guards manifests warnings and diagnostics failures', static function(Context $t): void {
	$workspace=$t->workspace('storage-manager-deep');
	if(!defined('DP_STORAGE_CFG')){
		define('DP_STORAGE_CFG', [
			'default_disk'=>'advanced',
			'disks'=>[
				'advanced'=>['driver'=>'memory'],
				'encrypted'=>['driver'=>'memory', 'encryption'=>['enabled'=>true, 'key'=>'coverage-secret']],
				'guarded_stream'=>['driver'=>'memory', 'max_bytes'=>3],
				'warning'=>[
					'driver'=>'s3',
					'manifest'=>$workspace->path('missing/manifest.json'),
					'encryption'=>['enabled'=>true],
				],
				'throw_list'=>['driver'=>'memory'],
				'manifest_disk'=>[
					'driver'=>'memory',
					'manifest'=>$workspace->path('created/manifest.json'),
					'log'=>$workspace->path('created/storage.log'),
				],
				'source'=>['driver'=>'memory'],
				'target'=>['driver'=>'memory'],
				'invalid_config'=>'not-an-array',
			],
		]);
	}
	MemoryDriver::flush();
	$t->cleanup(static fn()=>MemoryDriver::flush());
		$manager=new StorageManager();
		$managerInternals=$t->nonPublic($manager);
		$t->isTrue($manager->put('secret.txt', 'plaintext', 'encrypted'));
		$t->same('plaintext', $manager->get('secret.txt', 'encrypted'));
		$decrypted=$manager->readStream('secret.txt', 'encrypted');
		$t->isTrue(is_resource($decrypted));
		$t->same('plaintext', stream_get_contents($decrypted));
		@fclose($decrypted);

		$large=Stream::fromString('four');
		$t->isFalse($manager->put('large.txt', $large, 'guarded_stream'));
		@fclose($large);
		$t->same(null, $managerInternals->invoke('streamSize', 'not-a-stream'));
		$t->same('memory', $managerInternals->invoke('config', 'disks.encrypted.driver', 'missing'));
		$t->same('fallback', $managerInternals->invoke('config', 'disks.encrypted.missing', 'fallback'));

		$t->same(null, $managerInternals->invoke('readJsonFile', $workspace->path('absent.json')));
		$bundle=[
			'format'=>'dataphyre-storage-manifests',
			'manifests'=>[
				'invalid-entry'=>'not-an-array',
				'unknown.manifest'=>['data'=>['ignored'=>true]],
				'manifest_disk.manifest'=>['data'=>null],
				'manifest_disk.log'=>['data'=>['written'=>true]],
			],
		];
		$t->same(1, $manager->importManifests($bundle)['imported']);
		$t->isTrue(is_file($workspace->path('created/storage.log')));
		$t->isFalse($manager->manifestReport('warning')['manifests']['warning.manifest']['exists']);

		$warningDriver=new DpStorageManagerCapabilityDriver();
		$throwingDriver=new DpStorageManagerCapabilityDriver();
		$throwingDriver->throwOnList=true;
		$managerInternals->writeProperty('disks', ['warning'=>$warningDriver, 'throw_list'=>$throwingDriver]);
		$warning=$manager->diagnostics('warning', ['write'=>false]);
		$t->same(6, count($warning['disks']['warning']['warnings']));
		$throwing=$manager->diagnostics('throw_list', ['write'=>false]);
		$t->isFalse($throwing['ok']);
		$t->contains('List probe failed:', $throwing['disks']['throw_list']['warnings'][0]);
})->tag('storage', 'manager', 'deep-coverage')->group('framework-coverage');

test('storage manager covers sync failure deletion comparison and dialback branches', static function(Context $t): void {
	$source=new DpStorageManagerSyncDriver();
	$target=new DpStorageManagerSyncDriver();
	$source->objects=['shared.txt'=>'same', 'copy-fail.txt'=>'source', 'mtime.txt'=>'source'];
	$target->objects=['shared.txt'=>'same', 'extra.txt'=>'extra', 'mtime.txt'=>'target'];
	$source->listing=[
		new FileMetadata('shared.txt', 4, 20),
		new FileMetadata('copy-fail.txt', 6, 20),
	];
	$target->listing=[
		new FileMetadata('shared.txt', 4, 10),
		new FileMetadata('extra.txt', 5, 10),
	];
	$source->unreadable=['copy-fail.txt', 'mtime.txt'];
	$target->failDeletes=['extra.txt'];

	$manager=new StorageManager();
	$managerInternals=$t->nonPublic($manager);
	$managerInternals->writeProperty('disks', ['source'=>$source, 'target'=>$target]);
	$result=$manager->sync('source', 'target', '', ['dry_run'=>false, 'delete_extra'=>true, 'compare'=>'size']);
	$t->isFalse($result['ok']);
	$t->same(2, $result['counts']['failed']);
	$t->same('copied', $result['failed'][0]['operation']);
	$t->same('delete', $result['failed'][1]['operation']);

	$t->isTrue($managerInternals->invoke('needsSync',
		'size.txt', 'source', 'target', new FileMetadata('size.txt', 5, 20), new FileMetadata('size.txt', 4, 10), 'checksum',
	));
	$t->isFalse($managerInternals->invoke('needsSync',
		'shared.txt', 'source', 'target', new FileMetadata('shared.txt', 4, 20), new FileMetadata('shared.txt', 4, 10), 'size',
	));
	$t->isTrue($managerInternals->invoke('needsSync',
		'mtime.txt', 'source', 'target', new FileMetadata('mtime.txt', 6, 20), new FileMetadata('mtime.txt', 6, 10), 'checksum',
	));

	$dialbackState=$t->state('storage.manager-sync-dialbacks');
	$coreInternals=$t->nonPublic(\dataphyre\core::class);
	$baselineDialbacks=$coreInternals->readProperty('dialbacks');
	$coreInternals->replacePropertyForTest('dialbacks', $baselineDialbacks);
	\dataphyre\core::register_dialback('CALL_STORAGE_FRAMEWORK_SYNC_BEFORE', static function(array $payload) use ($dialbackState): array {
		$dialbackState->put('sync_before', $payload);
		return ['before'=>$payload['from'].'>'.$payload['to']];
	});
	$t->same('source>target', $manager->sync('source', 'target')['before']);
	$t->same('source', $dialbackState->get('sync_before')['from']);
})->tag('storage', 'manager', 'deep-coverage')->group('framework-coverage');
