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
 * Storage decorator that stores logical objects as content-addressed blobs.
 *
 * The driver writes unique content blobs to a target disk under a configured
 * prefix and records logical path-to-blob references in a JSON manifest. Reads,
 * streams, metadata, temporary URLs, and listings resolve through the manifest
 * while blob cleanup removes unreferenced content after rewrites or deletes.
 */
final class DeduplicatedDriver implements StorageDriver {

	/** @var string Target disk that stores physical blobs. */
	private string $disk;
	/** @var string Blob namespace prefix on the target disk. */
	private string $prefix;
	/** @var string Filesystem path to the logical reference manifest. */
	private string $manifest;
	/** @var string Default hash algorithm used to address blobs. */
	private string $algorithm;

	/**
	 * Initializes content-addressed blob storage and logical reference tracking.
	 *
	 * `disk` or `target` identifies the physical blob disk. `prefix` defines the
	 * blob namespace. `manifest` stores logical path references, and `algorithm`
	 * controls content addressing for new writes.
	 *
	 * @param array<string, mixed> $config Deduplication driver configuration.
	 * @param ?StorageManager $manager Storage manager used for target-disk delegation.
	 *
	 * @throws \RuntimeException When target disk, blob prefix, or hash algorithm is invalid.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->prefix=Path::normalize((string)($config['prefix'] ?? '_dataphyre_blobs'));
		$this->manifest=(string)($config['manifest'] ?? sys_get_temp_dir().'/dataphyre-storage-deduplicated.json');
		$this->algorithm=(string)($config['algorithm'] ?? 'sha256');
		if($this->disk===''){
			throw new \RuntimeException('Deduplicated storage disks require a target disk.');
		}
		if($this->prefix===''){
			throw new \RuntimeException('Deduplicated storage disks require a blob prefix.');
		}
		if(!in_array($this->algorithm, hash_algos(), true)){
			throw new \RuntimeException("Deduplication hash algorithm '{$this->algorithm}' is unavailable.");
		}
	}

	/**
	 * Checks whether a logical path references an existing blob.
	 *
	 * @param string $path Logical object path.
	 * @return bool True when the manifest record exists and its blob exists on the target disk.
	 */
	public function exists(string $path): bool {
		$record=$this->recordFor($path);
		return is_array($record) && $this->manager->exists((string)$record['blob_path'], $this->disk);
	}

	/**
	 * Reads logical object contents from the referenced blob.
	 *
	 * Missing manifest records or unreadable blob streams return false.
	 *
	 * @param string $path Logical object path.
	 * @param array<string, mixed> $options Target-driver read options.
	 * @return string|false Blob contents, or false when the logical object cannot be read.
	 */
	public function read(string $path, array $options=[]): string|false {
		$stream=$this->readStream($path, $options);
		return is_resource($stream) ? Stream::contents($stream) : false;
	}

	/**
	 * Opens a stream for the blob referenced by a logical path.
	 *
	 * The returned stream points at the physical blob path, not the logical path.
	 *
	 * @param string $path Logical object path.
	 * @param array<string, mixed> $options Target-driver stream options.
	 * @return mixed Target-driver stream resource/value, or false when no manifest record exists.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		$record=$this->recordFor($path);
		return is_array($record) ? $this->manager->readStream((string)$record['blob_path'], $this->disk, $options) : false;
	}

	/**
	 * Writes a logical object by storing or reusing a content-addressed blob.
	 *
	 * Contents are fully materialized to compute the checksum. If the addressed
	 * blob already exists, only the manifest reference is updated. Rewrites remove
	 * the previous blob only when no remaining logical paths reference it.
	 *
	 * @param string $path Logical object path to write.
	 * @param mixed $contents Stringable contents or readable stream.
	 * @param array<string, mixed> $options Target write options; `deduplication_algorithm` overrides the default hash.
	 * @return bool True when blob storage and manifest update succeed.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$path=Path::normalize($path);
		$body=is_resource($contents) ? Stream::contents($contents) : (string)$contents;
		if($path==='' || $body===false){
			return false;
		}
		$algorithm=(string)($options['deduplication_algorithm'] ?? $this->algorithm);
		if(!in_array($algorithm, hash_algos(), true)){
			return false;
		}
		$checksum=hash($algorithm, $body);
		$blobPath=$this->blobPath($checksum, $algorithm);
		$records=$this->records();
		$oldBlob=is_string($records[$path]['blob_path'] ?? null) ? (string)$records[$path]['blob_path'] : null;
		if(!$this->manager->exists($blobPath, $this->disk)){
			if($this->manager->put($blobPath, $body, $this->disk, $options)!==true){
				return false;
			}
		}
		$records[$path]=[
			'path'=>$path,
			'blob_path'=>$blobPath,
			'algorithm'=>$algorithm,
			'checksum'=>$checksum,
			'size'=>strlen($body),
			'mime_type'=>is_string($options['content_type'] ?? null) ? $options['content_type'] : null,
			'updated_at'=>time(),
		];
		if($this->writeRecords($records)!==true){
			return false;
		}
		if($oldBlob!==null && $oldBlob!==$blobPath){
			$this->deleteBlobIfUnreferenced($oldBlob, $records);
		}
		return true;
	}

	/**
	 * Deletes a logical object reference and cleans up unreferenced blobs.
	 *
	 * Deleting an unknown logical path is treated as successful. Physical blob
	 * deletion only occurs after the manifest no longer contains any reference to
	 * that blob.
	 *
	 * @param string $path Logical object path to delete.
	 * @return bool True when the manifest was updated or no record existed.
	 */
	public function delete(string $path): bool {
		$path=Path::normalize($path);
		$records=$this->records();
		$record=is_array($records[$path] ?? null) ? $records[$path] : null;
		if($record===null){
			return true;
		}
		unset($records[$path]);
		if($this->writeRecords($records)!==true){
			return false;
		}
		$blobPath=is_string($record['blob_path'] ?? null) ? (string)$record['blob_path'] : '';
		if($blobPath!==''){
			$this->deleteBlobIfUnreferenced($blobPath, $records);
		}
		return true;
	}

	/**
	 * Builds metadata for a logical object from its manifest record and blob.
	 *
	 * Returned metadata uses the logical path while extra metadata exposes the
	 * physical blob path, algorithm, checksum, and current reference count.
	 *
	 * @param string $path Logical object path.
	 * @return FileMetadata|false Logical metadata, or false when the manifest record is absent.
	 */
	public function metadata(string $path): FileMetadata|false {
		$path=Path::normalize($path);
		$record=$this->recordFor($path);
		if(!is_array($record)){
			return false;
		}
		$metadata=$this->manager->metadata((string)$record['blob_path'], $this->disk);
		$extra=$metadata instanceof FileMetadata ? $metadata->extra() : [];
		$extra['deduplicated']=[
			'blob_path'=>$record['blob_path'] ?? null,
			'algorithm'=>$record['algorithm'] ?? $this->algorithm,
			'checksum'=>$record['checksum'] ?? null,
			'references'=>$this->referencesFor((string)($record['blob_path'] ?? '')),
		];
		return new FileMetadata(
			$path,
			isset($record['size']) ? (int)$record['size'] : ($metadata instanceof FileMetadata ? $metadata->size() : null),
			isset($record['updated_at']) ? (int)$record['updated_at'] : ($metadata instanceof FileMetadata ? $metadata->modifiedAt() : null),
			is_string($record['mime_type'] ?? null) ? $record['mime_type'] : ($metadata instanceof FileMetadata ? $metadata->mimeType() : null),
			$extra
		);
	}

	/**
	 * Lists logical objects recorded in the manifest.
	 *
	 * Listing is manifest-driven, not blob-prefix-driven, so internal blob paths
	 * are not exposed as storage objects.
	 *
	 * @param string $prefix Optional logical path prefix.
	 * @param array<string, mixed> $options Reserved listing options.
	 * @return array<int, FileMetadata> Logical metadata entries sorted by path.
	 */
	public function list(string $prefix='', array $options=[]): array {
		$prefix=Path::normalize($prefix);
		$records=$this->records();
		$items=[];
		foreach($records as $path=>$record){
			if(!is_array($record)){
				continue;
			}
			$path=(string)$path;
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			$metadata=$this->metadata($path);
			if($metadata instanceof FileMetadata){
				$items[]=$metadata;
			}
		}
		usort($items, static fn(FileMetadata $a, FileMetadata $b): int => strcmp($a->path(), $b->path()));
		return $items;
	}

	/**
	 * Creates a temporary URL for the blob referenced by a logical path.
	 *
	 * The target disk signs the physical blob path. Callers should treat the URL
	 * as access to the logical object even though it resolves to a shared blob.
	 *
	 * @param string $path Logical object path.
	 * @param int|\DateTimeInterface $expires Expiration timestamp or date object.
	 * @param array<string, mixed> $options Target-driver URL options.
	 * @return string|false Temporary URL, or false when no manifest record exists.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		$record=$this->recordFor($path);
		return is_array($record) ? $this->manager->temporaryUrl((string)$record['blob_path'], $expires, $this->disk, $options) : false;
	}

	/**
	 * Reports deduplication savings and missing blob references.
	 *
	 * Referenced bytes sum every logical object size, while stored bytes sum
	 * unique blob sizes. Missing entries identify manifest records whose blob no
	 * longer exists on the target disk.
	 *
	 * @param string $prefix Optional logical path prefix.
	 * @param array<string, mixed> $options Reserved report options.
	 * @return array<string,mixed> Deduplication report with object counts, byte savings, and missing blob references.
	 */
	public function deduplicationReport(string $prefix='', array $options=[]): array {
		$prefix=Path::normalize($prefix);
		$records=$this->records();
		$logical=0;
		$referencedBytes=0;
		$blobs=[];
		$missing=[];
		foreach($records as $path=>$record){
			if(!is_array($record)){
				continue;
			}
			$path=(string)$path;
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			$logical++;
			$referencedBytes+=(int)($record['size'] ?? 0);
			$blobPath=(string)($record['blob_path'] ?? '');
			if($blobPath===''){
				continue;
			}
			$blobs[$blobPath]=$record;
			if(!$this->manager->exists($blobPath, $this->disk)){
				$missing[]=['path'=>$path, 'blob_path'=>$blobPath];
			}
		}
		$storedBytes=0;
		foreach($blobs as $blobPath=>$record){
			$metadata=$this->manager->metadata((string)$blobPath, $this->disk);
			$storedBytes+=$metadata instanceof FileMetadata ? (int)$metadata->size() : (int)($record['size'] ?? 0);
		}
		return [
			'ok'=>$missing===[],
			'logical_objects'=>$logical,
			'unique_blobs'=>count($blobs),
			'referenced_bytes'=>$referencedBytes,
			'stored_bytes'=>$storedBytes,
			'saved_bytes'=>max(0, $referencedBytes-$storedBytes),
			'missing'=>$missing,
		];
	}

	/**
	 * Computes the physical blob path for a checksum.
	 *
	 * Blob paths include prefix, algorithm, a two-character shard, and checksum
	 * to avoid oversized flat directories.
	 *
	 * @param string $checksum Content checksum.
	 * @param string $algorithm Hash algorithm used for the checksum.
	 * @return string Normalized physical blob path.
	 */
	private function blobPath(string $checksum, string $algorithm): string {
		return Path::normalize($this->prefix.'/'.$algorithm.'/'.substr($checksum, 0, 2).'/'.$checksum);
	}

	/**
	 * Loads one logical path record from the manifest.
	 *
	 * @param string $path Logical object path.
	 * @return ?array<string, mixed> Manifest record, or null when absent or malformed.
	 */
	private function recordFor(string $path): ?array {
		$records=$this->records();
		$record=$records[Path::normalize($path)] ?? null;
		return is_array($record) ? $record : null;
	}

	/**
	 * Reads all logical path records from the manifest file.
	 *
	 * Missing or malformed manifests are treated as empty so future writes can
	 * rebuild the reference map.
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
	 * Persists the complete logical reference manifest.
	 *
	 * Records are sorted by logical path before writing to keep diagnostics and
	 * versioned manifests stable.
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

	/**
	 * Counts logical references to one blob path.
	 *
	 * @param string $blobPath Physical blob path.
	 * @return int Number of manifest records pointing at the blob.
	 */
	private function referencesFor(string $blobPath): int {
		if($blobPath===''){
			return 0;
		}
		$count=0;
		foreach($this->records() as $record){
			if(is_array($record) && ($record['blob_path'] ?? null)===$blobPath){
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Deletes a physical blob when no manifest records reference it.
	 *
	 * @param string $blobPath Physical blob path to check.
	 * @param array<string, array<string, mixed>> $records Current manifest records after mutation.
	 * @return void
	 */
	private function deleteBlobIfUnreferenced(string $blobPath, array $records): void {
		foreach($records as $record){
			if(is_array($record) && ($record['blob_path'] ?? null)===$blobPath){
				return;
			}
		}
		$this->manager->delete($blobPath, $this->disk);
	}
}
