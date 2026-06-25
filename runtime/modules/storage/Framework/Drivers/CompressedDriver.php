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
 * Storage decorator that transparently compresses selected object contents.
 *
 * Objects are written to a target disk at their logical path. A local JSON
 * manifest records whether each object was gzip-compressed, its original size,
 * stored size, ratio, and update time so reads can decode and metadata can
 * report logical sizes while the target disk stores compressed bytes.
 */
final class CompressedDriver implements StorageDriver {

	/** @var string Target disk that stores the physical bytes. */
	private string $disk;
	/** @var string Filesystem path to the compression manifest. */
	private string $manifest;
	/** @var int Default gzip compression level, clamped from 1 to 9. */
	private int $level;
	/** @var int Minimum logical byte size before automatic compression is considered. */
	private int $minBytes;
	/** @var list<string> */
	private array $skipExtensions;

	/**
	 * Initializes compression metadata, thresholds, and delegated target storage.
	 *
	 * `disk` or `target` selects the physical storage disk. `manifest` stores
	 * compression metadata, `level` sets the default gzip level, `min_bytes`
	 * controls automatic compression threshold, and `skip_extensions` names file
	 * types that are assumed to already be compressed.
	 *
	 * @param array<string, mixed> $config Compression driver configuration.
	 * @param ?StorageManager $manager Storage manager used for target-disk delegation.
	 *
	 * @throws \RuntimeException When no target disk is configured.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->manifest=(string)($config['manifest'] ?? sys_get_temp_dir().'/dataphyre-storage-compressed.json');
		$this->level=max(1, min(9, (int)($config['level'] ?? 6)));
		$this->minBytes=max(0, (int)($config['min_bytes'] ?? 1024));
		$this->skipExtensions=array_values(array_filter(array_map(
			static fn(mixed $value): string => strtolower(ltrim((string)$value, '.')),
			(array)($config['skip_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'zip', 'gz', 'bz2', '7z', 'mp3', 'mp4', 'mov', 'pdf'])
		)));
		if($this->disk===''){
			throw new \RuntimeException('Compressed storage disks require a target disk.');
		}
	}

	/**
	 * Checks whether the logical object exists on the target disk.
	 *
	 * @param string $path Logical object path.
	 * @return bool True when the target disk has an object at the path.
	 */
	public function exists(string $path): bool {
		return $this->manager->exists($path, $this->disk);
	}

	/**
	 * Reads logical object contents, decoding compressed bytes when needed.
	 *
	 * Missing objects, target read failures, or invalid gzip data return
	 * false through `readStream()`.
	 *
	 * @param string $path Logical object path.
	 * @param array<string, mixed> $options Target-driver read options.
	 * @return string|false Decoded logical contents, or false when unavailable.
	 */
	public function read(string $path, array $options=[]): string|false {
		$stream=$this->readStream($path, $options);
		return is_resource($stream) ? Stream::contents($stream) : false;
	}

	/**
	 * Opens a stream containing logical object contents.
	 *
	 * The physical target object is fetched as bytes. If the manifest marks the
	 * object as compressed, gzip decoding happens before an in-memory stream is
	 * returned.
	 *
	 * @param string $path Logical object path.
	 * @param array<string, mixed> $options Target-driver read options.
	 * @return mixed Readable stream resource, or false when the object cannot be decoded/read.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		$path=Path::normalize($path);
		$body=$this->manager->get($path, $this->disk, $options);
		if($body===false){
			return false;
		}
		$record=$this->recordFor($path);
		if(($record['compressed'] ?? false)===true){
			$decoded=gzdecode($body);
			if($decoded===false){
				return false;
			}
			$body=$decoded;
		}
		return Stream::fromString($body);
	}

	/**
	 * Writes a logical object and records whether its stored bytes were compressed.
	 *
	 * Contents are materialized to evaluate size and gzip savings. Compression is
	 * skipped for small objects and configured extensions unless overridden with
	 * `compress`; `force_compression` stores gzip output even when it is larger.
	 *
	 * @param string $path Logical object path.
	 * @param mixed $contents Stringable contents or readable stream.
	 * @param array<string, mixed> $options Target write options plus `compress`, `force_compression`, and `compression_level`.
	 * @return bool True when the target write and manifest update both succeed.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$path=Path::normalize($path);
		$body=is_resource($contents) ? Stream::contents($contents) : (string)$contents;
		if($path==='' || $body===false){
			return false;
		}
		$originalSize=strlen($body);
		$storedBody=$body;
		$compressed=false;
		if($this->shouldCompress($path, $originalSize, $options)){
			$encoded=gzencode($body, (int)($options['compression_level'] ?? $this->level));
			if($encoded!==false && (strlen($encoded)<$originalSize || ($options['force_compression'] ?? false)===true)){
				$storedBody=$encoded;
				$compressed=true;
			}
		}
		if($this->manager->put($path, $storedBody, $this->disk, $options)!==true){
			return false;
		}
		$records=$this->records();
		$records[$path]=[
			'path'=>$path,
			'compressed'=>$compressed,
			'algorithm'=>$compressed ? 'gzip' : null,
			'original_size'=>$originalSize,
			'stored_size'=>strlen($storedBody),
			'ratio'=>$originalSize>0 ? round(strlen($storedBody)/$originalSize, 6) : 1,
			'updated_at'=>time(),
		];
		return $this->writeRecords($records);
	}

	/**
	 * Deletes an object from the target disk and removes its manifest record.
	 *
	 * Manifest write failure is intentionally not surfaced when deleting; the
	 * delete result reflects the target disk operation.
	 *
	 * @param string $path Logical object path.
	 * @return bool True when the target disk reports delete success.
	 */
	public function delete(string $path): bool {
		$path=Path::normalize($path);
		$records=$this->records();
		unset($records[$path]);
		$this->writeRecords($records);
		return $this->manager->delete($path, $this->disk);
	}

	/**
	 * Returns logical metadata enriched with compression details.
	 *
	 * Size reflects the original uncompressed size when a manifest record exists.
	 * The raw target metadata extras are preserved and augmented with a
	 * `compression` entry containing the manifest record.
	 *
	 * @param string $path Logical object path.
	 * @return FileMetadata|false Logical metadata, or false when target metadata is unavailable.
	 */
	public function metadata(string $path): FileMetadata|false {
		$path=Path::normalize($path);
		$metadata=$this->manager->metadata($path, $this->disk);
		if(!$metadata instanceof FileMetadata){
			return false;
		}
		$record=$this->recordFor($path);
		$extra=$metadata->extra();
		if($record!==null){
			$extra['compression']=$record;
		}
		return new FileMetadata(
			$path,
			is_array($record) && isset($record['original_size']) ? (int)$record['original_size'] : $metadata->size(),
			$metadata->modifiedAt(),
			$metadata->mimeType(),
			$extra
		);
	}

	/**
	 * Lists target objects with logical compression-aware metadata.
	 *
	 * Listing delegates path discovery to the target disk and then rehydrates each
	 * item through `metadata()` so callers see uncompressed sizes and extras.
	 *
	 * @param string $prefix Optional logical path prefix.
	 * @param array<string, mixed> $options Target-driver listing options.
	 * @return array<int, FileMetadata> Compression-aware metadata entries.
	 */
	public function list(string $prefix='', array $options=[]): array {
		$items=[];
		foreach($this->manager->list($prefix, $this->disk, $options) as $metadata){
			if(!$metadata instanceof FileMetadata){
				continue;
			}
			$item=$this->metadata($metadata->path());
			if($item instanceof FileMetadata){
				$items[]=$item;
			}
		}
		return $items;
	}

	/**
	 * Creates a temporary URL for the stored physical bytes.
	 *
	 * The generated URL points at the target object bytes. Consumers of compressed
	 * objects must access through the storage API when they require decoded
	 * logical contents.
	 *
	 * @param string $path Logical object path.
	 * @param int|\DateTimeInterface $expires Expiration timestamp or date object.
	 * @param array<string, mixed> $options Target-driver URL options.
	 * @return string|false Temporary target URL, or false when unavailable.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		return $this->manager->temporaryUrl($path, $expires, $this->disk, $options);
	}

	/**
	 * Reports compression coverage and byte savings from the manifest.
	 *
	 * The report is manifest-driven and summarizes logical object count,
	 * compressed versus raw objects, original bytes, stored bytes, saved bytes,
	 * and aggregate stored/original ratio for an optional prefix.
	 *
	 * @param string $prefix Optional logical path prefix.
	 * @param array<string, mixed> $options Reserved report options.
	 * @return array{ok: bool, objects: int, compressed_objects: int, raw_objects: int, original_bytes: int, stored_bytes: int, saved_bytes: int, ratio: float|int} Compression report.
	 */
	public function compressionReport(string $prefix='', array $options=[]): array {
		$prefix=Path::normalize($prefix);
		$records=$this->records();
		$objects=0;
		$compressed=0;
		$originalBytes=0;
		$storedBytes=0;
		foreach($records as $path=>$record){
			if(!is_array($record)){
				continue;
			}
			$path=(string)$path;
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			$objects++;
			if(($record['compressed'] ?? false)===true){
				$compressed++;
			}
			$originalBytes+=(int)($record['original_size'] ?? 0);
			$storedBytes+=(int)($record['stored_size'] ?? 0);
		}
		return [
			'ok'=>true,
			'objects'=>$objects,
			'compressed_objects'=>$compressed,
			'raw_objects'=>$objects-$compressed,
			'original_bytes'=>$originalBytes,
			'stored_bytes'=>$storedBytes,
			'saved_bytes'=>max(0, $originalBytes-$storedBytes),
			'ratio'=>$originalBytes>0 ? round($storedBytes/$originalBytes, 6) : 1,
		];
	}

	/**
	 * Decides whether a write should attempt gzip compression.
	 *
	 * Explicit `compress=false` disables compression and `compress=true` forces an
	 * attempt. Automatic mode requires the size threshold and skips configured
	 * extensions that usually contain already-compressed data.
	 *
	 * @param string $path Logical object path.
	 * @param int $size Original byte length.
	 * @param array<string, mixed> $options Write options.
	 * @return bool True when gzip compression should be attempted.
	 */
	private function shouldCompress(string $path, int $size, array $options): bool {
		if(($options['compress'] ?? null)===false){
			return false;
		}
		if(($options['compress'] ?? null)===true){
			return true;
		}
		if($size<$this->minBytes){
			return false;
		}
		$extension=strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return $extension==='' || !in_array($extension, $this->skipExtensions, true);
	}

	/**
	 * Loads one compression manifest record by logical path.
	 *
	 * @param string $path Logical object path.
	 * @return ?array<string, mixed> Manifest record, or null when absent/malformed.
	 */
	private function recordFor(string $path): ?array {
		$records=$this->records();
		$record=$records[Path::normalize($path)] ?? null;
		return is_array($record) ? $record : null;
	}

	/**
	 * Reads all compression manifest records.
	 *
	 * Missing or malformed manifest files are treated as empty so future writes
	 * can rebuild compression metadata.
	 *
	 * @return array<string, array<string, mixed>> Manifest records keyed by logical path.
	 */
	private function records(): array {
		if(!is_file($this->manifest)){
			return [];
		}
		$decoded=json_decode((string)file_get_contents($this->manifest), true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Persists the complete compression manifest.
	 *
	 * Records are sorted by logical path and written with a lock for stable,
	 * inspectable diagnostics.
	 *
	 * @param array<string, array<string, mixed>> $records Manifest records keyed by logical path.
	 * @return bool True when the manifest JSON was written.
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
