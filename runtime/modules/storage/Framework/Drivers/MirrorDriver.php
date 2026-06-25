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
use Dataphyre\Storage\Support\Stream;

/**
 * Storage driver that mirrors mutations to multiple disks while reading from one disk.
 *
 * The mirror driver exposes one logical disk backed by a configured read disk
 * and one or more write disks. Reads, metadata, listings, and temporary URLs
 * are resolved from the read disk. Writes and deletes are attempted on every
 * write disk, and the operation reports success only when every target accepts
 * the mutation.
 */
final class MirrorDriver implements StorageDriver {

	/** @var list<string> Disks that receive write and delete operations. */
	private array $writes;

	/** @var string Disk used for reads, metadata, listings, and temporary URLs. */
	private string $read;

	/**
	 * Configures mirror read and write targets.
	 *
	 * Write disks may be supplied as `writes` or `disks`; the read disk may be
	 * supplied as `read` and otherwise defaults to the first write disk. Missing
	 * read or write targets are configuration errors because the mirror cannot
	 * provide a meaningful consistency contract without both sides.
	 *
	 * @param array<string, mixed> $config Driver configuration.
	 * @param ?StorageManager $manager Manager used for disk delegation, or the singleton when omitted.
	 * @throws \RuntimeException When no read disk or write disk is configured.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->writes=array_values(array_filter(array_map('strval', (array)($config['writes'] ?? $config['disks'] ?? []))));
		$this->read=(string)($config['read'] ?? $this->writes[0] ?? '');
		if($this->read==='' || $this->writes===[]){
			throw new \RuntimeException('Mirror storage disks require a read disk and at least one write disk.');
		}
	}

	/**
	 * Checks path existence on the read disk.
	 *
	 * @param string $path Path relative to the logical mirror disk.
	 * @return bool `true` when the configured read disk reports the path exists.
	 */
	public function exists(string $path): bool {
		return $this->manager->exists($path, $this->read);
	}

	/**
	 * Reads file contents from the read disk.
	 *
	 * @param string $path Path relative to the logical mirror disk.
	 * @param array<string, mixed> $options Driver-specific read options forwarded to the read disk.
	 * @return string|false File contents, or `false` when the read disk cannot return the file.
	 */
	public function read(string $path, array $options=[]): string|false {
		return $this->manager->get($path, $this->read, $options);
	}

	/**
	 * Opens a read stream from the read disk.
	 *
	 * @param string $path Path relative to the logical mirror disk.
	 * @param array<string, mixed> $options Driver-specific stream options forwarded to the read disk.
	 * @return mixed stream handle or failure marker returned by the configured read disk.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		return $this->manager->readStream($path, $this->read, $options);
	}

	/**
	 * Writes the same body to every configured write disk.
	 *
	 * Resource contents are fully buffered once before fan-out so every disk
	 * receives the same bytes. The method does not roll back disks that already
	 * accepted a write when a later disk fails; callers receive `false` to
	 * signal the mirror is no longer fully consistent.
	 *
	 * @param string $path Path relative to the logical mirror disk.
	 * @param mixed $contents Stringable contents or readable stream.
	 * @param array<string, mixed> $options Driver-specific write options forwarded to every write disk.
	 * @return bool `true` only when every write disk accepts the write.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$body=is_resource($contents) ? Stream::contents($contents) : (string)$contents;
		if($body===false){
			return false;
		}
		$ok=true;
		foreach($this->writes as $disk){
			$ok=$this->manager->put($path, $body, $disk, $options) && $ok;
		}
		return $ok;
	}

	/**
	 * Deletes a path from every configured write disk.
	 *
	 * Delete is best-effort across all write disks and reports aggregate
	 * success. It does not stop at the first failure, giving later disks a
	 * chance to remove stale data.
	 *
	 * @param string $path Path relative to the logical mirror disk.
	 * @return bool `true` only when every write disk reports deletion success.
	 */
	public function delete(string $path): bool {
		$ok=true;
		foreach($this->writes as $disk){
			$ok=$this->manager->delete($path, $disk) && $ok;
		}
		return $ok;
	}

	/**
	 * Reads file metadata from the read disk.
	 *
	 * @param string $path Path relative to the logical mirror disk.
	 * @return FileMetadata|false Metadata from the read disk, or `false` when unavailable.
	 */
	public function metadata(string $path): FileMetadata|false {
		return $this->manager->metadata($path, $this->read);
	}

	/**
	 * Lists files from the read disk.
	 *
	 * Listing reflects the configured read disk only; this driver does not reconcile
	 * inventories from write-only mirrors.
	 *
	 * @param array<string, mixed> $options Driver-specific listing options forwarded to the read disk.
	 * @return array<int|string, mixed> Listing entries returned by the read disk.
	 */
	public function list(string $prefix='', array $options=[]): array {
		return $this->manager->list($prefix, $this->read, $options);
	}

	/**
	 * Creates a temporary URL from the read disk.
	 *
	 * @param string $path Path relative to the logical mirror disk.
	 * @param int|\DateTimeInterface $expires Expiry timestamp or date object.
	 * @param array<string, mixed> $options Driver-specific signing options forwarded to the read disk.
	 * @return string|false Temporary URL from the read disk, or `false` when unsupported.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		return $this->manager->temporaryUrl($path, $expires, $this->read, $options);
	}
}
