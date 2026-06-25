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
 * Decorates a storage disk with file version snapshots.
 *
 * VersionedDriver delegates normal object operations to a target disk and stores historical
 * copies under a private version prefix. A JSON manifest records each version id, original path,
 * version storage path, operation that created the snapshot, timestamp, size, and MIME type.
 * Snapshots are taken before overwriting, deleting, and restoring existing objects.
 *
 * Version data is sidecar state: the target disk must successfully store snapshot objects and
 * the manifest for restore and pruning to work. Listing hides internal version objects so
 * callers see only user-facing files.
 */
final class VersionedDriver implements StorageDriver {

	/** @var string Target disk name delegated to StorageManager. */
	private string $disk;
	/** @var string Internal object prefix where version snapshots are stored. */
	private string $prefix;
	/** @var string JSON manifest path for version records. */
	private string $manifest;
	/** @var int Number of newest versions to keep per path after writes. */
	private int $keep;

	/**
	 * Creates a versioning decorator for a target disk.
	 *
	 * Required config key is disk or target. Optional prefix controls where version objects are
	 * stored, manifest controls the JSON index location, and keep controls per-path retention
	 * after writes.
	 *
	 * @param array<string, mixed> $config Versioned driver configuration.
	 * @param ?StorageManager $manager Storage manager used to delegate object operations.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->prefix=Path::normalize((string)($config['prefix'] ?? '_dataphyre_versions'));
		$this->manifest=(string)($config['manifest'] ?? sys_get_temp_dir().'/dataphyre-storage-versions.json');
		$this->keep=max(0, (int)($config['keep'] ?? 25));
		if($this->disk===''){
			throw new \RuntimeException('Versioned storage disks require a target disk.');
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
	 * Reads current object contents from the target disk.
	 *
	 * @param string $path Object path.
	 * @param array<string, mixed> $options Delegated read options.
	 * @return string|false Object contents or false from the target disk.
	 */
	public function read(string $path, array $options=[]): string|false {
		return $this->manager->get($path, $this->disk, $options);
	}

	/**
	 * Opens a read stream for the current object on the target disk.
	 *
	 * @param string $path Object path.
	 * @param array<string, mixed> $options Delegated stream options.
	 * @return resource|false Stream from the target disk, or false when unavailable.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		return $this->manager->readStream($path, $this->disk, $options);
	}

	/**
	 * Writes current object contents, snapshotting the prior object when present.
	 *
	 * Snapshot failure does not block the write. Retention trimming runs only after the delegated
	 * write succeeds.
	 *
	 * @param string $path Object path.
	 * @param mixed $contents Contents accepted by the target disk.
	 * @param array<string, mixed> $options Delegated write options and snapshot read options.
	 * @return bool Whether the target write succeeded.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		if($this->exists($path)){
			$this->snapshot($path, 'write', $options);
		}
		$ok=$this->manager->put($path, $contents, $this->disk, $options);
		if($ok){
			$this->trim($path);
		}
		return $ok;
	}

	/**
	 * Deletes the current object after snapshotting it when present.
	 *
	 * @param string $path Object path.
	 * @return bool Whether the target delete succeeded.
	 */
	public function delete(string $path): bool {
		if($this->exists($path)){
			$this->snapshot($path, 'delete');
		}
		return $this->manager->delete($path, $this->disk);
	}

	/**
	 * Returns current object metadata from the target disk.
	 *
	 * @param string $path Object path.
	 * @return FileMetadata|false Metadata from the target disk.
	 */
	public function metadata(string $path): FileMetadata|false {
		return $this->manager->metadata($path, $this->disk);
	}

	/**
	 * Lists user-facing objects while hiding internal version snapshots.
	 *
	 * @param string $prefix Object prefix.
	 * @param array<string, mixed> $options Delegated listing options.
	 * @return array<int, FileMetadata> Target objects excluding version storage paths.
	 */
	public function list(string $prefix='', array $options=[]): array {
		return array_values(array_filter(
			$this->manager->list($prefix, $this->disk, $options),
			fn(FileMetadata $item): bool => !str_starts_with($item->path(), $this->prefix.'/')
		));
	}

	/**
	 * Delegates temporary URL creation for the current object.
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
	 * Returns manifest rows for one object path.
	 *
	 * @param string $path Object path.
	 * @return array<int, array<string, mixed>> Version records for the path.
	 */
	public function versions(string $path): array {
		$path=Path::normalize($path);
		return array_values(array_filter(
			$this->manifestRows(),
			static fn(array $row): bool => ($row['path'] ?? '')===$path
		));
	}

	/**
	 * Restores a historical version over the current object.
	 *
	 * When the current object exists, it is snapshotted with operation=restore before the
	 * selected version stream is written back to the original path.
	 *
	 * @param string $path Object path.
	 * @param string $versionId Version id from versions().
	 * @param array<string, mixed> $options Delegated read/write options.
	 * @return bool Whether the selected version was written to the target path.
	 */
	public function restoreVersion(string $path, string $versionId, array $options=[]): bool {
		foreach($this->versions($path) as $row){
			if((string)($row['id'] ?? '')!==$versionId){
				continue;
			}
			$versionPath=(string)($row['version_path'] ?? '');
			$stream=$this->manager->readStream($versionPath, $this->disk, $options);
			if(!is_resource($stream)){
				return false;
			}
			if($this->exists($path)){
				$this->snapshot($path, 'restore', $options);
			}
			return $this->manager->put($path, $stream, $this->disk, $options);
		}
		return false;
	}

	/**
	 * Permanently deletes one stored version and removes its manifest row.
	 *
	 * The method attempts to delete the version snapshot from the target disk, then writes the
	 * manifest without the row. A missing version id returns false.
	 *
	 * @param string $path Original object path.
	 * @param string $versionId Version id from versions().
	 * @return bool Whether a manifest row was removed and the manifest was written.
	 */
	public function purgeVersion(string $path, string $versionId): bool {
		$rows=$this->manifestRows();
		$kept=[];
		$deleted=false;
		foreach($rows as $row){
			if(($row['path'] ?? '')===Path::normalize($path) && (string)($row['id'] ?? '')===$versionId){
				$versionPath=(string)($row['version_path'] ?? '');
				if($versionPath!==''){
					$this->manager->delete($versionPath, $this->disk);
				}
				$deleted=true;
				continue;
			}
			$kept[]=$row;
		}
		return $deleted ? $this->writeManifest($kept) : false;
	}

	/**
	 * Prunes stored versions by path, retention count, or age.
	 *
	 * keep defaults to the driver retention setting. older_than accepts a Unix timestamp,
	 * DateTimeInterface, or parseable date string. When keep is explicitly zero, every version
	 * in scope is eligible for deletion.
	 *
	 * @param ?string $path Optional path scope.
	 * @param array{keep?:int, older_than?:int|string|\DateTimeInterface} $options Pruning options.
	 * @return array{ok:bool, pruned:int, deleted_ids:array<int, string>} Prune result.
	 */
	public function pruneVersions(?string $path=null, array $options=[]): array {
		$path=$path!==null ? Path::normalize($path) : null;
		$keep=max(0, (int)($options['keep'] ?? $this->keep));
		$olderThan=$this->normalizeTime($options['older_than'] ?? null);
		$rows=$this->manifestRows();
		$groups=[];
		foreach($rows as $row){
			$rowPath=(string)($row['path'] ?? '');
			if($rowPath===''){
				continue;
			}
			if($path!==null && $rowPath!==$path){
				continue;
			}
			$groups[$rowPath][]=$row;
		}
		$deleteIds=[];
		foreach($groups as $rowPath=>$versions){
			usort($versions, static fn(array $a, array $b): int => ((int)($a['created_at'] ?? 0))<=>((int)($b['created_at'] ?? 0)));
			$candidates=[];
			if($olderThan!==null){
				foreach($versions as $row){
					if((int)($row['created_at'] ?? 0)<$olderThan){
						$candidates[(string)($row['id'] ?? '')]=$row;
					}
				}
			}
			if($keep>0 && count($versions)>$keep){
				foreach(array_slice($versions, 0, count($versions)-$keep) as $row){
					$candidates[(string)($row['id'] ?? '')]=$row;
				}
			}
			elseif($keep===0 && ($options['keep'] ?? null)!==null){
				foreach($versions as $row){
					$candidates[(string)($row['id'] ?? '')]=$row;
				}
			}
			foreach($candidates as $id=>$row){
				if($id!==''){
					$deleteIds[$id]=$row;
				}
			}
		}
		if($deleteIds===[]){
			return ['ok'=>true, 'pruned'=>0, 'deleted_ids'=>[]];
		}
		$kept=[];
		$deleted=[];
		foreach($rows as $row){
			$id=(string)($row['id'] ?? '');
			if(isset($deleteIds[$id])){
				$versionPath=(string)($row['version_path'] ?? '');
				if($versionPath!==''){
					$this->manager->delete($versionPath, $this->disk);
				}
				$deleted[]=$id;
				continue;
			}
			$kept[]=$row;
		}
		return [
			'ok'=>$this->writeManifest($kept),
			'pruned'=>count($deleted),
			'deleted_ids'=>$deleted,
		];
	}

	/**
	 * Captures the current object stream as a version snapshot and manifest row.
	 *
	 * @param string $path Original object path.
	 * @param string $operation Operation that triggered the snapshot.
	 * @param array<string, mixed> $options Delegated stream and write options.
	 * @return ?array<string, mixed> Version row, or null when snapshot storage fails.
	 */
	private function snapshot(string $path, string $operation, array $options=[]): ?array {
		$path=Path::normalize($path);
		$stream=$this->manager->readStream($path, $this->disk, $options);
		if(!is_resource($stream)){
			return null;
		}
		$id=date('YmdHis').'-'.bin2hex(random_bytes(6));
		$versionPath=$this->prefix.'/'.sha1($path).'/'.$id.'/'.basename($path);
		if($this->manager->put($versionPath, $stream, $this->disk, $options)!==true){
			return null;
		}
		$metadata=$this->manager->metadata($path, $this->disk);
		$row=[
			'id'=>$id,
			'path'=>$path,
			'version_path'=>$versionPath,
			'operation'=>$operation,
			'created_at'=>time(),
			'size'=>$metadata instanceof FileMetadata ? $metadata->size() : null,
			'mime_type'=>$metadata instanceof FileMetadata ? $metadata->mimeType() : null,
		];
		$rows=$this->manifestRows();
		$rows[]=$row;
		$this->writeManifest($rows);
		return $row;
	}

	/**
	 * Applies automatic per-path retention after a successful write.
	 *
	 * @param string $path Original object path.
	 */
	private function trim(string $path): void {
		if($this->keep===0){
			return;
		}
		$versions=$this->versions($path);
		if(count($versions)<=$this->keep){
			return;
		}
		usort($versions, static fn(array $a, array $b): int => ((int)($a['created_at'] ?? 0))<=>((int)($b['created_at'] ?? 0)));
		$remove=array_slice($versions, 0, count($versions)-$this->keep);
		foreach($remove as $row){
			$this->purgeVersion($path, (string)($row['id'] ?? ''));
		}
	}

	/**
	 * Reads all version manifest rows.
	 *
	 * @return array<int, array<string, mixed>> Manifest rows.
	 */
	private function manifestRows(): array {
		if(!is_file($this->manifest)){
			return [];
		}
		$decoded=json_decode((string)file_get_contents($this->manifest), true);
		return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
	}

	/**
	 * Writes all version manifest rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Manifest rows.
	 * @return bool Whether the manifest file was written.
	 */
	private function writeManifest(array $rows): bool {
		$dir=dirname($this->manifest);
		if(!is_dir($dir)){
			@mkdir($dir, 0775, true);
		}
		return file_put_contents($this->manifest, json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)!==false;
	}

	/**
	 * Normalizes time-like pruning input to a Unix timestamp.
	 *
	 * @param mixed $value Null, integer timestamp, DateTimeInterface, or parseable date string.
	 * @return ?int Unix timestamp, or null when unavailable.
	 */
	private function normalizeTime(mixed $value): ?int {
		if($value===null || $value===''){
			return null;
		}
		if($value instanceof \DateTimeInterface){
			return $value->getTimestamp();
		}
		if(is_int($value)){
			return $value;
		}
		if(is_string($value)){
			$time=strtotime($value);
			return $time!==false ? $time : null;
		}
		return null;
	}
}
