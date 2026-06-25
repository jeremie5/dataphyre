<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage\Drivers;

use Dataphyre\Storage\Contracts\StorageDriver;
use Dataphyre\Storage\FileMetadata;
use Dataphyre\Storage\StorageManager;
use Dataphyre\Storage\Support\Path;
use Dataphyre\Storage\Support\Stream;

/**
 * Storage decorator that enforces scoped byte and object quotas before writes.
 *
 * The driver delegates all physical I/O to a target disk, but inspects the
 * configured scope before writes to estimate the post-write object count and
 * total stored bytes. Reads, deletes, listing, metadata, and temporary URLs pass
 * through while metadata and reports expose current quota consumption.
 */
final class QuotaDriver implements StorageDriver {

	/** @var string Target disk where objects are stored. */
	private string $disk;
	/** @var string Optional path prefix whose writes are quota-controlled. */
	private string $scope;
	/** @var int Maximum bytes allowed in scope; zero disables byte quota. */
	private int $maxBytes;
	/** @var int Maximum object count allowed in scope; zero disables object quota. */
	private int $maxObjects;

	/**
	 * Initializes quota limits for a delegated disk and optional path scope.
	 *
	 * `disk` or `target` selects the physical disk. `scope`/`prefix` restricts
	 * quota enforcement to a path prefix. `max_bytes` and `max_objects` define
	 * optional ceilings, with zero or negative values treated as unlimited.
	 *
	 * @param array<string, mixed> $config Quota driver configuration.
	 * @param ?StorageManager $manager Storage manager used for target-disk delegation.
	 *
	 * @throws \RuntimeException When no target disk is configured.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->scope=Path::normalize((string)($config['scope'] ?? $config['prefix'] ?? ''));
		$this->maxBytes=(int)($config['max_bytes'] ?? 0);
		$this->maxObjects=(int)($config['max_objects'] ?? 0);
		if($this->disk===''){
			throw new \RuntimeException('Quota storage disks require a target disk.');
		}
	}

	/**
	 * Checks whether an object exists on the target disk.
	 *
	 * @param string $path Logical object path.
	 * @return bool True when the target disk has an object at the path.
	 */
	public function exists(string $path): bool {
		return $this->manager->exists($path, $this->disk);
	}

	/**
	 * Reads object contents from the target disk without quota checks.
	 *
	 * @param string $path Logical object path.
	 * @param array<string, mixed> $options Target-driver read options.
	 * @return string|false Object contents, or false when the target read fails.
	 */
	public function read(string $path, array $options=[]): string|false {
		return $this->manager->get($path, $this->disk, $options);
	}

	/**
	 * Opens a target-disk read stream without quota checks.
	 *
	 * @param string $path Logical object path.
	 * @param array<string, mixed> $options Target-driver stream options.
	 * @return mixed Target stream result, or false when unavailable.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		return $this->manager->readStream($path, $this->disk, $options);
	}

	/**
	 * Writes object contents only when the scoped quota would remain valid.
	 *
	 * Contents are materialized to count incoming bytes. Writes outside the
	 * configured scope bypass quota checks; writes inside scope fail before target
	 * storage when byte or object ceilings would be exceeded.
	 *
	 * @param string $path Logical object path.
	 * @param mixed $contents Stringable contents or readable stream.
	 * @param array<string, mixed> $options Target-driver write options.
	 * @return bool True when quota allows the target write and the target write succeeds.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$body=is_resource($contents) ? Stream::contents($contents) : (string)$contents;
		if($body===false){
			return false;
		}
		$size=strlen($body);
		if($this->withinQuota($path, $size)!==true){
			return false;
		}
		return $this->manager->put($path, $body, $this->disk, $options);
	}

	/**
	 * Deletes an object from the target disk.
	 *
	 * @param string $path Logical object path.
	 * @return bool True when the target disk reports delete success.
	 */
	public function delete(string $path): bool {
		return $this->manager->delete($path, $this->disk);
	}

	/**
	 * Returns target metadata enriched with current quota consumption.
	 *
	 * The `quota` extra contains the report for the configured scope so callers
	 * inspecting one object can also see remaining bytes/objects.
	 *
	 * @param string $path Logical object path.
	 * @return FileMetadata|false Metadata with quota extra, or false when target metadata is unavailable.
	 */
	public function metadata(string $path): FileMetadata|false {
		$metadata=$this->manager->metadata($path, $this->disk);
		if(!$metadata instanceof FileMetadata){
			return false;
		}
		$extra=$metadata->extra();
		$extra['quota']=$this->quotaReport($this->scope);
		return new FileMetadata($metadata->path(), $metadata->size(), $metadata->modifiedAt(), $metadata->mimeType(), $extra);
	}

	/**
	 * Lists objects from the target disk.
	 *
	 * @param string $prefix Optional target path prefix.
	 * @param array<string, mixed> $options Target-driver listing options.
	 * @return array<int, FileMetadata> Target metadata entries.
	 */
	public function list(string $prefix='', array $options=[]): array {
		return $this->manager->list($prefix, $this->disk, $options);
	}

	/**
	 * Creates a temporary URL through the target disk.
	 *
	 * @param string $path Logical object path.
	 * @param int|\DateTimeInterface $expires Expiration timestamp or date object.
	 * @param array<string, mixed> $options Target-driver URL options.
	 * @return string|false Temporary URL, or false when unavailable.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		return $this->manager->temporaryUrl($path, $expires, $this->disk, $options);
	}

	/**
	 * Reports current quota usage for a prefix or the configured scope.
	 *
	 * Object count and byte usage are calculated by listing target metadata.
	 * Remaining values are null when the corresponding quota is unlimited.
	 *
	 * @param string $prefix Optional prefix override; blank uses the configured scope.
	 * @param array<string, mixed> $options Target-driver listing options.
	 * @return array<string,mixed> Quota usage, limits, and remaining capacity for the scope.
	 */
	public function quotaReport(string $prefix='', array $options=[]): array {
		$scope=$prefix!=='' ? Path::normalize($prefix) : $this->scope;
		$objects=0;
		$bytes=0;
		foreach($this->manager->list($scope, $this->disk, $options) as $metadata){
			if(!$metadata instanceof FileMetadata){
				continue;
			}
			$objects++;
			$bytes+=(int)($metadata->size() ?? 0);
		}
		return [
			'ok'=>($this->maxBytes<=0 || $bytes<=$this->maxBytes) && ($this->maxObjects<=0 || $objects<=$this->maxObjects),
			'scope'=>$scope,
			'bytes'=>$bytes,
			'objects'=>$objects,
			'max_bytes'=>$this->maxBytes,
			'max_objects'=>$this->maxObjects,
			'bytes_remaining'=>$this->maxBytes>0 ? max(0, $this->maxBytes-$bytes) : null,
			'objects_remaining'=>$this->maxObjects>0 ? max(0, $this->maxObjects-$objects) : null,
		];
	}

	/**
	 * Predicts whether a write would remain within configured quotas.
	 *
	 * Writes outside scope are allowed. Replacing an existing path does not add to
	 * object count, and the old object's bytes are excluded before adding incoming
	 * bytes.
	 *
	 * @param string $path Logical object path being written.
	 * @param int $incomingSize Incoming object byte length.
	 * @return bool True when the write may proceed.
	 */
	private function withinQuota(string $path, int $incomingSize): bool {
		$path=Path::normalize($path);
		$scope=$this->scope;
		if($scope!=='' && $path!==$scope && !str_starts_with($path, $scope.'/')){
			return true;
		}
		$objects=0;
		$bytes=0;
		$replacing=false;
		foreach($this->manager->list($scope, $this->disk) as $metadata){
			if(!$metadata instanceof FileMetadata){
				continue;
			}
			if($metadata->path()===$path){
				$replacing=true;
				continue;
			}
			$objects++;
			$bytes+=(int)($metadata->size() ?? 0);
		}
		$nextObjects=$objects+1;
		$nextBytes=$bytes+$incomingSize;
		if($this->maxObjects>0 && !$replacing && $nextObjects>$this->maxObjects){
			return false;
		}
		if($this->maxBytes>0 && $nextBytes>$this->maxBytes){
			return false;
		}
		return true;
	}
}
