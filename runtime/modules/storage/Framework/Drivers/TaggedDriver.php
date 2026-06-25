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

/**
 * Decorates a storage disk with sidecar tag and custom metadata indexing.
 *
 * TaggedDriver delegates all object I/O to a target disk through StorageManager and persists tag
 * records in a JSON manifest. The manifest is keyed by normalized object path and stores tags,
 * custom metadata, created_at, and updated_at timestamps. This makes tag search and tag reports
 * available even when the underlying disk has no native object-tagging feature.
 *
 * Tag state is eventually consistent with the wrapped disk at the method-call level: writes can
 * create/update tag records, deletes remove tag records before deleting the object, and
 * metadata/list calls enrich underlying FileMetadata with the sidecar record when available.
 */
final class TaggedDriver implements StorageDriver {

	/** @var string Target disk name delegated to StorageManager. */
	private string $disk;
	/** @var string JSON sidecar manifest path for tag records. */
	private string $manifest;

	/**
	 * Creates a tagged storage decorator for a target disk.
	 *
	 * Required config key is disk or target. Optional manifest overrides the JSON file used for
	 * tag records; otherwise a temporary-system path is used.
	 *
	 * @param array<string, mixed> $config Tagged driver configuration.
	 * @param ?StorageManager $manager Storage manager used to delegate object operations.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->manifest=(string)($config['manifest'] ?? sys_get_temp_dir().'/dataphyre-storage-tags.json');
		if($this->disk===''){
			throw new \RuntimeException('Tagged storage disks require a target disk.');
		}
	}

	/**
	 * Checks object existence on the target disk.
	 *
	 * @param string $path Object path.
	 * @return bool Whether the delegated disk reports the object exists.
	 */
	public function exists(string $path): bool {
		return $this->manager->exists($path, $this->disk);
	}

	/**
	 * Reads object contents from the target disk.
	 *
	 * @param string $path Object path.
	 * @param array<string, mixed> $options Delegated read options.
	 * @return string|false Object contents or false from the target disk.
	 */
	public function read(string $path, array $options=[]): string|false {
		return $this->manager->get($path, $this->disk, $options);
	}

	/**
	 * Opens a read stream from the target disk.
	 *
	 * @param string $path Object path.
	 * @param array<string, mixed> $options Delegated stream options.
	 * @return resource|false Stream from the target disk, or false when unavailable.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		return $this->manager->readStream($path, $this->disk, $options);
	}

	/**
	 * Writes an object and optionally records tags/custom metadata.
	 *
	 * Object write must succeed before the sidecar manifest is touched. If tags or metadata are
	 * supplied, the existing sidecar record is replaced. If no tags or metadata are supplied and
	 * no record exists, an empty record is created so the object can still appear in tag reports.
	 *
	 * @param string $path Object path.
	 * @param mixed $contents Contents accepted by the target disk.
	 * @param array{tags?:array<int,string>|string,metadata?:array<string,mixed>,custom_metadata?:array<string,mixed>} $options
	 * Delegated write options plus tag metadata.
	 * @return bool Whether the target write succeeded.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$path=Path::normalize($path);
		if($this->manager->put($path, $contents, $this->disk, $options)!==true){
			return false;
		}
		$tags=$this->normalizeTags($options['tags'] ?? []);
		$metadata=$this->normalizeMetadata($options['metadata'] ?? $options['custom_metadata'] ?? []);
		if($tags!==[] || $metadata!==[]){
			$this->tagObject($path, ['tags'=>$tags, 'metadata'=>$metadata, 'merge'=>false]);
		}
		elseif($this->recordFor($path)===null){
			$this->tagObject($path, ['tags'=>[], 'metadata'=>[], 'merge'=>false]);
		}
		return true;
	}

	/**
	 * Deletes an object and removes its sidecar tag record.
	 *
	 * The manifest is updated before the delegated delete. If the target delete fails, the tag
	 * record has already been removed.
	 *
	 * @param string $path Object path.
	 * @return bool Whether the target disk delete succeeded.
	 */
	public function delete(string $path): bool {
		$path=Path::normalize($path);
		$records=$this->records();
		unset($records[$path]);
		$this->writeRecords($records);
		return $this->manager->delete($path, $this->disk);
	}

	/**
	 * Returns target metadata enriched with tags and custom metadata.
	 *
	 * Tag data is added under extra()['tags'] and custom metadata under extra()['custom_metadata'].
	 *
	 * @param string $path Object path.
	 * @return FileMetadata|false Enriched metadata or false when the target disk has no metadata.
	 */
	public function metadata(string $path): FileMetadata|false {
		$path=Path::normalize($path);
		$metadata=$this->manager->metadata($path, $this->disk);
		if(!$metadata instanceof FileMetadata){
			return false;
		}
		$record=$this->recordFor($path);
		$extra=$metadata->extra();
		$extra['tags']=$record['tags'] ?? [];
		$extra['custom_metadata']=$record['metadata'] ?? [];
		return new FileMetadata($path, $metadata->size(), $metadata->modifiedAt(), $metadata->mimeType(), $extra);
	}

	/**
	 * Lists target objects and enriches each item with tag metadata.
	 *
	 * @param string $prefix Object prefix.
	 * @param array<string, mixed> $options Delegated listing options.
	 * @return array<int, FileMetadata> Enriched metadata rows for listed objects.
	 */
	public function list(string $prefix='', array $options=[]): array {
		$out=[];
		foreach($this->manager->list($prefix, $this->disk, $options) as $metadata){
			if(!$metadata instanceof FileMetadata){
				continue;
			}
			$item=$this->metadata($metadata->path());
			if($item instanceof FileMetadata){
				$out[]=$item;
			}
		}
		return $out;
	}

	/**
	 * Delegates temporary URL creation to the target disk.
	 *
	 * @param string $path Object path.
	 * @param int|\DateTimeInterface $expires Expiration understood by the target disk.
	 * @param array<string, mixed> $options Delegated URL options.
	 * @return string|false Temporary URL or false from the target disk.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		return $this->manager->temporaryUrl($path, $expires, $this->disk, $options);
	}

	/**
	 * Writes or updates the sidecar tag record for an existing object.
	 *
	 * By default, tags and metadata are merged with the current record. Passing merge=false
	 * replaces both collections. Tagging a missing object returns false and does not create an
	 * orphan manifest row.
	 *
	 * @param string $path Object path.
	 * @param array<string,mixed> $options
	 * Tag operation options.
	 * @return bool Whether the manifest was written successfully.
	 */
	public function tagObject(string $path, array $options=[]): bool {
		$path=Path::normalize($path);
		if(!$this->exists($path)){
			return false;
		}
		$records=$this->records();
		$current=is_array($records[$path] ?? null) ? $records[$path] : [];
		$merge=(bool)($options['merge'] ?? true);
		$tags=$this->normalizeTags($options['tags'] ?? []);
		$metadata=$this->normalizeMetadata($options['metadata'] ?? $options['custom_metadata'] ?? []);
		$records[$path]=[
			'path'=>$path,
			'tags'=>$merge ? array_values(array_unique(array_merge((array)($current['tags'] ?? []), $tags))) : $tags,
			'metadata'=>$merge ? array_merge((array)($current['metadata'] ?? []), $metadata) : $metadata,
			'updated_at'=>time(),
		]+array_intersect_key($current, ['created_at'=>true]);
		if(!isset($records[$path]['created_at'])){
			$records[$path]['created_at']=time();
		}
		return $this->writeRecords($records);
	}

	/**
	 * Returns tag and custom metadata for one object path.
	 *
	 * @param string $path Object path.
	 * @return array{tags:array<int,string>,metadata:array<string,mixed>} Sidecar tag data.
	 */
	public function tagsFor(string $path): array {
		$record=$this->recordFor($path);
		return is_array($record) ? ['tags'=>$record['tags'] ?? [], 'metadata'=>$record['metadata'] ?? []] : ['tags'=>[], 'metadata'=>[]];
	}

	/**
	 * Finds objects whose sidecar tags match a requested tag set.
	 *
	 * match_all defaults to true. prefix restricts matches to a normalized object subtree. Only
	 * records whose underlying object still returns metadata are included, which prunes stale
	 * manifest rows from search results without mutating the manifest.
	 *
	 * @param array<int, string>|array<string, mixed> $tags Requested tags.
	 * @param array{match_all?:bool, prefix?:string} $options Search options.
	 * @return array<int, FileMetadata> Enriched metadata rows for matching objects.
	 */
	public function findByTags(array $tags, array $options=[]): array {
		$tags=$this->normalizeTags($tags);
		$matchAll=(bool)($options['match_all'] ?? true);
		$prefix=Path::normalize((string)($options['prefix'] ?? ''));
		$out=[];
		foreach($this->records() as $path=>$record){
			if(!is_array($record)){
				continue;
			}
			$path=(string)$path;
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			$recordTags=(array)($record['tags'] ?? []);
			$matches=count(array_intersect($tags, $recordTags));
			if(($matchAll && $matches===count($tags)) || (!$matchAll && $matches>0)){
				$metadata=$this->metadata($path);
				if($metadata instanceof FileMetadata){
					$out[]=$metadata;
				}
			}
		}
		return $out;
	}

	/**
	 * Summarizes tag usage under an optional prefix.
	 *
	 * The report reads only the manifest and does not verify each object against the target disk.
	 *
	 * @param string $prefix Optional object prefix.
	 * @param array<string, mixed> $options Reserved for future report options.
	 * @return array<string,mixed> Tag usage report with object count and tag frequencies.
	 */
	public function tagReport(string $prefix='', array $options=[]): array {
		$prefix=Path::normalize($prefix);
		$objects=0;
		$tagCounts=[];
		foreach($this->records() as $path=>$record){
			if(!is_array($record)){
				continue;
			}
			$path=(string)$path;
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			$objects++;
			foreach((array)($record['tags'] ?? []) as $tag){
				$tagCounts[$tag]=($tagCounts[$tag] ?? 0)+1;
			}
		}
		ksort($tagCounts);
		return ['ok'=>true, 'objects'=>$objects, 'tags'=>$tagCounts];
	}

	/**
	 * Normalizes tag input into unique lowercase tag names.
	 *
	 * @param mixed $tags String or array of tag values.
	 * @return array<int, string> Unique normalized tags.
	 */
	private function normalizeTags(mixed $tags): array {
		if(is_string($tags)){
			$tags=preg_split('/[, ]+/', $tags) ?: [];
		}
		$out=[];
		foreach((array)$tags as $tag){
			$tag=strtolower(trim((string)$tag));
			if($tag!==''){
				$out[]=$tag;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * Normalizes custom metadata into scalar or JSON string values.
	 *
	 * @param mixed $metadata Metadata map.
	 * @return array<string, mixed> Normalized metadata map.
	 */
	private function normalizeMetadata(mixed $metadata): array {
		$out=[];
		foreach((array)$metadata as $key=>$value){
			$key=trim((string)$key);
			if($key!==''){
				$out[$key]=is_scalar($value) || $value===null ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
			}
		}
		return $out;
	}

	/**
	 * Reads one sidecar manifest record.
	 *
	 * @param string $path Object path.
	 * @return ?array<string, mixed> Manifest record, or null when absent.
	 */
	private function recordFor(string $path): ?array {
		$record=$this->records()[Path::normalize($path)] ?? null;
		return is_array($record) ? $record : null;
	}

	/**
	 * Reads the sidecar manifest.
	 *
	 * @return array<string, array<string, mixed>> Manifest records keyed by normalized object path.
	 */
	private function records(): array {
		if(!is_file($this->manifest)){
			return [];
		}
		$decoded=json_decode((string)file_get_contents($this->manifest), true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Writes the sidecar manifest with stable path ordering.
	 *
	 * @param array<string, array<string, mixed>> $records Manifest records keyed by normalized object path.
	 * @return bool Whether the manifest file was written.
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
