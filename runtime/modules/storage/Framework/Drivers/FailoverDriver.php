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

/**
 * Storage driver that reads from ordered fallback disks and writes to one disk.
 *
 * Read operations try configured read disks in order and return the first
 * successful value. Writes go to the configured write disk only. Deletes are
 * attempted on every read disk so stale copies can be removed from all fallback
 * sources even when some disks fail.
 */
final class FailoverDriver implements StorageDriver {

	/** @var list<string> Ordered disks used for read fallback and broad deletes. */
	private array $reads;

	/** @var string Disk that receives writes. */
	private string $write;

	/**
	 * Configures ordered read fallbacks and the write target.
	 *
	 * Read disks may be supplied as `reads` or `disks`; the write disk may be
	 * supplied as `write` and otherwise defaults to the first read disk. Missing
	 * reads or write target are configuration errors because failover requires
	 * at least one source for reads and one destination for writes.
	 *
	 * @param array<string, mixed> $config Driver configuration.
	 * @param ?StorageManager $manager Manager used for disk delegation, or the singleton when omitted.
	 * @throws \RuntimeException When read disks or write disk are missing.
	 */
	public function __construct(array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->reads=array_values(array_filter(array_map('strval', (array)($config['reads'] ?? $config['disks'] ?? []))));
		$this->write=(string)($config['write'] ?? $this->reads[0] ?? '');
		if($this->reads===[] || $this->write===''){
			throw new \RuntimeException('Failover storage disks require read disks and a write disk.');
		}
	}

	/**
	 * Checks whether any read disk contains a path.
	 *
	 * @param string $path Path relative to the logical failover disk.
	 * @return bool `true` when any configured read disk reports the path exists.
	 */
	public function exists(string $path): bool {
		return $this->firstDiskWith($path)!==null;
	}

	/**
	 * Fetches file contents from the first read disk that returns a value.
	 *
	 * A `false` value from a disk is treated as a miss and the next read disk is
	 * tried. Empty strings are valid file contents and are returned immediately.
	 *
	 * @param string $path Path relative to the logical failover disk.
	 * @param array<string, mixed> $options Driver-specific read options forwarded to each read disk.
	 * @return string|false First successful file contents, or `false` when every read disk misses.
	 */
	public function read(string $path, array $options=[]): string|false {
		foreach($this->reads as $disk){
			$value=$this->manager->get($path, $disk, $options);
			if($value!==false){
				return $value;
			}
		}
		return false;
	}

	/**
	 * Opens a stream from the first read disk that returns a resource.
	 *
	 * @param string $path Path relative to the logical failover disk.
	 * @param array<string, mixed> $options Driver-specific stream options forwarded to each read disk.
	 * @return mixed First readable stream resource, or `false` when no read disk can stream the file.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		foreach($this->reads as $disk){
			$stream=$this->manager->readStream($path, $disk, $options);
			if(is_resource($stream)){
				return $stream;
			}
		}
		return false;
	}

	/**
	 * Writes to the configured write disk.
	 *
	 * The write is not automatically copied to read fallback disks. Deployments
	 * that need replication should combine this driver with external sync or a
	 * mirror driver.
	 *
	 * @param string $path Path relative to the logical failover disk.
	 * @param mixed $contents Contents accepted by the target write disk.
	 * @param array<string, mixed> $options Driver-specific write options forwarded to the write disk.
	 * @return bool `true` when the write disk accepts the write.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		return $this->manager->put($path, $contents, $this->write, $options);
	}

	/**
	 * Deletes a path from every configured read disk.
	 *
	 * Delete does not stop on first failure; all read disks are attempted and
	 * the method reports aggregate success.
	 *
	 * @param string $path Path relative to the logical failover disk.
	 * @return bool `true` only when every read disk reports deletion success.
	 */
	public function delete(string $path): bool {
		$ok=true;
		foreach($this->reads as $disk){
			$ok=$this->manager->delete($path, $disk) && $ok;
		}
		return $ok;
	}

	/**
	 * Reads metadata from the first read disk that contains the path.
	 *
	 * @param string $path Path relative to the logical failover disk.
	 * @return FileMetadata|false Metadata from the first disk containing the path, or `false` when no disk has it.
	 */
	public function metadata(string $path): FileMetadata|false {
		$disk=$this->firstDiskWith($path);
		return $disk!==null ? $this->manager->metadata($path, $disk) : false;
	}

	/**
	 * Lists unique file metadata from all read disks.
	 *
	 * Duplicate paths are returned once, preserving the first read disk's
	 * metadata for that path. Non-`FileMetadata` entries from underlying disks
	 * are ignored.
	 *
	 * @param array<string, mixed> $options Driver-specific listing options forwarded to every read disk.
	 * @return array<int, FileMetadata> Unique metadata entries keyed by discovery order.
	 */
	public function list(string $prefix='', array $options=[]): array {
		$seen=[];
		$out=[];
		foreach($this->reads as $disk){
			foreach($this->manager->list($prefix, $disk, $options) as $item){
				if(!$item instanceof FileMetadata || isset($seen[$item->path()])){
					continue;
				}
				$seen[$item->path()]=true;
				$out[]=$item;
			}
		}
		return $out;
	}

	/**
	 * Creates a temporary URL from the first read disk containing the path.
	 *
	 * @param string $path Path relative to the logical failover disk.
	 * @param int|\DateTimeInterface $expires Expiry timestamp or date object.
	 * @param array<string, mixed> $options Driver-specific signing options forwarded to the selected read disk.
	 * @return string|false Temporary URL, or `false` when no read disk contains the path or can sign it.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		$disk=$this->firstDiskWith($path);
		return $disk!==null ? $this->manager->temporaryUrl($path, $expires, $disk, $options) : false;
	}

	/**
	 * Returns the first configured read disk that reports a path exists.
	 *
	 * @param string $path Path relative to the logical failover disk.
	 * @return ?string Disk name, or `null` when every read disk misses.
	 */
	private function firstDiskWith(string $path): ?string {
		foreach($this->reads as $disk){
			if($this->manager->exists($path, $disk)){
				return $disk;
			}
		}
		return null;
	}
}
