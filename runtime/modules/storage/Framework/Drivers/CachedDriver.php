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
 * Decorates a source storage disk with read-through caching on a second disk.
 *
 * CachedDriver keeps cached object bodies under a configured cache prefix and tracks cache
 * records in a local JSON manifest. Reads prefer fresh cache entries, cache misses are hydrated
 * from the source disk, writes either refresh cache immediately or invalidate it, and diagnostics
 * expose freshness, missing cache files, and byte counts without scanning the source disk.
 */
final class CachedDriver implements StorageDriver {

	private string $disk;
	private string $cache;
	private string $prefix;
	private string $manifest;
	private int $ttl;
	private bool $writeThrough;

	/**
	 * Initializes read-through cache storage, freshness metadata, TTL, and write-through behavior.
	 *
	 * The source disk can be supplied as disk, source, or target. The cache disk is required
	 * separately so cached bodies do not share the authoritative storage namespace. The cache
	 * prefix scopes cached objects, and the manifest records cached_at and size metadata for
	 * freshness checks.
	 *
	 * @param array<string,mixed> $config Cached disk configuration.
	 * @param ?StorageManager $manager Optional storage manager; defaults to the shared instance.
	 * @throws \RuntimeException When source disk, cache disk, or cache prefix is missing.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['source'] ?? $config['target'] ?? '');
		$this->cache=(string)($config['cache'] ?? $config['cache_disk'] ?? '');
		$this->prefix=Path::normalize((string)($config['prefix'] ?? '_dataphyre_cache'));
		$this->manifest=(string)($config['manifest'] ?? sys_get_temp_dir().'/dataphyre-storage-cache.json');
		$this->ttl=(int)($config['ttl'] ?? $config['ttl_seconds'] ?? 300);
		$this->writeThrough=(bool)($config['write_through'] ?? true);
		if($this->disk==='' || $this->cache===''){
			throw new \RuntimeException('Cached storage disks require source and cache disks.');
		}
		if($this->prefix===''){
			throw new \RuntimeException('Cached storage disks require a cache prefix.');
		}
	}

	/**
	 * Calculates exists for the current Storage Framework selection.
	 *
	 * Existence is true when the source disk has the object or this driver has a fresh cache
	 * entry. Stale or missing cache records do not make an object exist.
	 *
	 * @param string $path Storage path to check.
	 * @return bool True when the source object or fresh cached object exists.
	 */
	public function exists(string $path): bool {
		return $this->manager->exists($path, $this->disk) || $this->fresh($path);
	}

	/**
	 * Reads object contents through the cache layer.
	 *
	 * This method delegates to readStream() so stream and string reads share the same freshness
	 * and hydration behavior.
	 *
	 * @param string $path Storage path to read.
	 * @param array<string,mixed> $options Read options forwarded to source/cache disks.
	 * @return string|false File contents, or false when neither cache nor source can provide them.
	 */
	public function read(string $path, array $options=[]): string|false {
		$stream=$this->readStream($path, $options);
		return is_resource($stream) ? Stream::contents($stream) : false;
	}

	/**
	 * Opens a read stream, hydrating the cache on source hits.
	 *
	 * Fresh cache entries are streamed from the cache disk. Cache misses and stale entries stream
	 * from the source disk, copy the body into the cache, update the manifest, and return a new
	 * in-memory stream for the caller.
	 *
	 * @param string $path Storage path to stream.
	 * @param array<string,mixed> $options Stream options forwarded to source/cache disks.
	 * @return mixed Stream resource/handle, or false when the object cannot be read.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		$path=Path::normalize($path);
		if($this->fresh($path)){
			return $this->manager->readStream($this->cachePath($path), $this->cache, $options);
		}
		$stream=$this->manager->readStream($path, $this->disk, $options);
		if(!is_resource($stream)){
			return false;
		}
		$body=Stream::contents($stream);
		if($body===false){
			return false;
		}
		$this->storeCache($path, $body, $options);
		return Stream::fromString($body);
	}

	/**
	 * Writes to the source disk and either refreshes or invalidates the cache entry.
	 *
	 * Resource contents are read into a string so source and cache writes receive identical bytes.
	 * With write-through enabled, the cache body and manifest record are refreshed after the
	 * source write. With write-through disabled, the manifest entry and cache object are removed.
	 *
	 * @param string $path Storage path to write.
	 * @param mixed $contents Stringable contents or readable resource accepted by the source disk.
	 * @param array<string,mixed> $options Write options forwarded to source/cache disks.
	 * @return bool True when the source write succeeds and cache handling does not block completion.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$path=Path::normalize($path);
		$body=is_resource($contents) ? Stream::contents($contents) : (string)$contents;
		if($body===false){
			return false;
		}
		if($this->manager->put($path, $body, $this->disk, $options)!==true){
			return false;
		}
		if($this->writeThrough){
			$this->storeCache($path, $body, $options);
		}
		else{
			$this->forget($path);
			$this->manager->delete($this->cachePath($path), $this->cache);
		}
		return true;
	}

	/**
	 * Deletes from source storage and removes the matching cache entry.
	 *
	 * Cache manifest and cache body deletion are attempted before source deletion so stale cached
	 * data is not retained after explicit delete requests.
	 *
	 * @param string $path Storage path to delete.
	 * @return bool True when the source disk deletes the object.
	 */
	public function delete(string $path): bool {
		$path=Path::normalize($path);
		$this->forget($path);
		$this->manager->delete($this->cachePath($path), $this->cache);
		return $this->manager->delete($path, $this->disk);
	}

	/**
	 * Loads file metadata and attaches cache freshness details.
	 *
	 * Source metadata is preferred. When source metadata is unavailable but a fresh cache exists,
	 * cache metadata is used. The returned metadata extras include cached, fresh, cached_at, and
	 * expires_at fields.
	 *
	 * @param string $path Storage path to inspect.
	 * @return FileMetadata|false Metadata enriched with cache state, or false when unavailable.
	 */
	public function metadata(string $path): FileMetadata|false {
		$path=Path::normalize($path);
		$metadata=$this->manager->metadata($path, $this->disk);
		if(!$metadata instanceof FileMetadata && $this->fresh($path)){
			$metadata=$this->manager->metadata($this->cachePath($path), $this->cache);
		}
		if(!$metadata instanceof FileMetadata){
			return false;
		}
		$record=$this->recordFor($path);
		$extra=$metadata->extra();
		$extra['cache']=[
			'cached'=>$record!==null && $this->manager->exists($this->cachePath($path), $this->cache),
			'fresh'=>$this->fresh($path),
			'cached_at'=>$record['cached_at'] ?? null,
			'expires_at'=>$this->ttl>0 && isset($record['cached_at']) ? (int)$record['cached_at']+$this->ttl : null,
		];
		return new FileMetadata($path, $metadata->size(), $metadata->modifiedAt(), $metadata->mimeType(), $extra);
	}

	/**
	 * Lists objects from the source disk.
	 *
	 * Listings are not served from cache because the manifest only tracks objects this driver has
	 * read or written, not a complete source-disk inventory.
	 *
	 * @param string $prefix Source prefix to list.
	 * @param array<string,mixed> $options List options forwarded to the source disk.
	 * @return array<int|string,mixed> Source disk listing.
	 */
	public function list(string $prefix='', array $options=[]): array {
		return $this->manager->list($prefix, $this->disk, $options);
	}

	/**
	 * Creates a temporary URL for the source object.
	 *
	 * URLs always target the authoritative source disk, never the cache disk.
	 *
	 * @param string $path Source path for the signed URL.
	 * @param int|\DateTimeInterface $expires Expiration time or timestamp accepted by the manager.
	 * @param array<string,mixed> $options URL options forwarded to the source disk.
	 * @return string|false Temporary URL, or false when unsupported.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		return $this->manager->temporaryUrl($path, $expires, $this->disk, $options);
	}

	/**
	 * Reports manifest-backed cache health and size information.
	 *
	 * The report is based on manifest records under the optional prefix. It counts objects,
	 * recorded bytes, fresh and stale entries, and records whose cache body is missing from the
	 * cache disk.
	 *
	 * @param string $prefix Optional source prefix used to filter manifest records.
	 * @param array<string,mixed> $options Reserved report options.
	 * @return array<string,mixed> Cache health report.
	 */
	public function cacheReport(string $prefix='', array $options=[]): array {
		$prefix=Path::normalize($prefix);
		$records=$this->records();
		$objects=0;
		$bytes=0;
		$fresh=0;
		$stale=0;
		$missing=[];
		foreach($records as $path=>$record){
			if(!is_array($record)){
				continue;
			}
			$path=(string)$path;
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			$objects++;
			$bytes+=(int)($record['size'] ?? 0);
			$cachePath=$this->cachePath($path);
			if(!$this->manager->exists($cachePath, $this->cache)){
				$missing[]=$path;
				continue;
			}
			$this->fresh($path) ? $fresh++ : $stale++;
		}
		return [
			'ok'=>$missing===[],
			'objects'=>$objects,
			'bytes'=>$bytes,
			'fresh'=>$fresh,
			'stale'=>$stale,
			'missing'=>$missing,
			'ttl'=>$this->ttl,
			'cache_disk'=>$this->cache,
			'source_disk'=>$this->disk,
		];
	}

	/**
	 * Deletes cached bodies and manifest records under an optional prefix.
	 *
	 * Purging never deletes source objects. It removes cache disk entries tracked by the manifest
	 * and then persists the filtered manifest.
	 *
	 * @param string $prefix Optional source prefix used to choose cached records.
	 * @param array<string,mixed> $options Reserved purge options.
	 * @return array{ok:bool,purged:int} Purge acknowledgement and deleted cache count.
	 */
	public function purgeCache(string $prefix='', array $options=[]): array {
		$prefix=Path::normalize($prefix);
		$records=$this->records();
		$purged=0;
		foreach($records as $path=>$record){
			if(!is_array($record)){
				continue;
			}
			$path=(string)$path;
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			if($this->manager->delete($this->cachePath($path), $this->cache)){
				$purged++;
			}
			unset($records[$path]);
		}
		$this->writeRecords($records);
		return ['ok'=>true, 'purged'=>$purged];
	}

	/**
	 * Writes one cache body and updates its manifest record.
	 *
	 * Cache records store source path, cache path, byte size, and cached_at timestamp. A failed
	 * cache-disk write prevents the manifest update and reports false.
	 *
	 * @param string $path Source storage path.
	 * @param string $body Bytes to store in the cache disk.
	 * @param array<string,mixed> $options Write options forwarded to the cache disk.
	 * @return bool True when cache body and manifest record are written.
	 */
	private function storeCache(string $path, string $body, array $options=[]): bool {
		$path=Path::normalize($path);
		$cachePath=$this->cachePath($path);
		if($this->manager->put($cachePath, $body, $this->cache, $options)!==true){
			return false;
		}
		$records=$this->records();
		$records[$path]=[
			'path'=>$path,
			'cache_path'=>$cachePath,
			'size'=>strlen($body),
			'cached_at'=>time(),
		];
		return $this->writeRecords($records);
	}

	/**
	 * Determines whether a path has a present, unexpired cache entry.
	 *
	 * TTL values less than or equal to zero make cache entries effectively non-expiring as long as
	 * the cache body still exists.
	 *
	 * @param string $path Source storage path.
	 * @return bool True when manifest and cache body are present and fresh.
	 */
	private function fresh(string $path): bool {
		$record=$this->recordFor($path);
		if($record===null || !$this->manager->exists($this->cachePath($path), $this->cache)){
			return false;
		}
		if($this->ttl<=0){
			return true;
		}
		return (int)($record['cached_at'] ?? 0)+$this->ttl>=time();
	}

	/**
	 * Maps a source path into the cache disk namespace.
	 *
	 * @param string $path Source storage path.
	 * @return string Cache disk path under the configured prefix.
	 */
	private function cachePath(string $path): string {
		return Path::normalize($this->prefix.'/'.Path::normalize($path));
	}

	/**
	 * Looks up one cache manifest record by normalized source path.
	 *
	 * @param string $path Source storage path.
	 * @return ?array<string,mixed> Cache record, or null when absent.
	 */
	private function recordFor(string $path): ?array {
		$records=$this->records();
		$record=$records[Path::normalize($path)] ?? null;
		return is_array($record) ? $record : null;
	}

	/**
	 * Removes one cache record from the manifest.
	 *
	 * This does not delete the cache body; callers delete cachePath() separately when needed.
	 *
	 * @param string $path Source storage path.
	 * @return bool True when the updated manifest was written.
	 */
	private function forget(string $path): bool {
		$records=$this->records();
		unset($records[Path::normalize($path)]);
		return $this->writeRecords($records);
	}

	/**
	 * Loads cache manifest records from disk.
	 *
	 * Missing or invalid manifests are treated as empty cache state.
	 *
	 * @return array<string,mixed> Cache records keyed by normalized source path.
	 */
	private function records(): array {
		if(!is_file($this->manifest)){
			return [];
		}
		$decoded=json_decode((string)file_get_contents($this->manifest), true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Persists sorted cache manifest records as pretty JSON.
	 *
	 * Sorting keeps diagnostics deterministic. The containing directory is created when missing
	 * and writes use an exclusive lock.
	 *
	 * @param array<string,mixed> $records Cache records keyed by normalized source path.
	 * @return bool True when the manifest was written.
	 */
	private function writeRecords(array $records): bool {
		ksort($records);
		$dir=dirname($this->manifest);
		if(!is_dir($dir)){
			@mkdir($dir, 0775, true);
		}
		return file_put_contents($this->manifest, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)!==false;
	}
}
