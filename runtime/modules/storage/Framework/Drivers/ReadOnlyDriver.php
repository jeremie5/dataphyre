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
 * Read-only storage wrapper over another configured disk.
 *
 * The driver delegates all read-oriented operations to a target disk through
 * `StorageManager` while refusing mutation calls locally. It exposes shared,
 * imported, or protected storage through the normal driver interface without
 * allowing callers to write or delete through that mount.
 */
final class ReadOnlyDriver implements StorageDriver {

	/** @var string Target disk name used for delegated read operations. */
	private string $disk;

	/**
	 * Initializes the read-only wrapper and resolves its delegated target disk.
	 *
	 * The target disk may be supplied as `disk` or `target`. A missing target is
	 * a configuration error because every operation must delegate somewhere
	 * explicit rather than accidentally reading from the current driver.
	 *
	 * @param array<string, mixed> $config Driver configuration containing `disk` or `target`.
	 * @param ?StorageManager $manager Manager used for delegation, or the singleton when omitted.
	 * @throws \RuntimeException When no target disk is configured.
	 */
	public function __construct(array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		if($this->disk===''){
			throw new \RuntimeException('Read-only storage disks require a target disk.');
		}
	}

	/**
	 * Checks whether a path exists on the target disk.
	 *
	 * @param string $path Path relative to the target disk.
	 * @return bool `true` when the delegated disk reports the path exists.
	 */
	public function exists(string $path): bool {
		return $this->manager->exists($path, $this->disk);
	}

	/**
	 * Reads file contents from the target disk.
	 *
	 * @param string $path Path relative to the target disk.
	 * @param array<string, mixed> $options Driver-specific read options forwarded to the target disk.
	 * @return string|false File contents, or `false` when the delegated read fails.
	 */
	public function read(string $path, array $options=[]): string|false {
		return $this->manager->get($path, $this->disk, $options);
	}

	/**
	 * Opens a read stream from the target disk.
	 *
	 * @param string $path Path relative to the target disk.
	 * @param array<string, mixed> $options Driver-specific stream options forwarded to the target disk.
	 * @return mixed stream handle or failure marker returned by the target disk.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		return $this->manager->readStream($path, $this->disk, $options);
	}

	/**
	 * Refuses all writes through the read-only wrapper.
	 *
	 * The call is intentionally not forwarded, even when the target disk itself
	 * is writable. Returning `false` preserves the storage-driver interface while
	 * making the mutation boundary explicit.
	 *
	 * @param string $path Ignored path that would have been written.
	 * @param mixed $contents Ignored contents.
	 * @param array<string, mixed> $options Ignored write options.
	 * @return bool Always `false`.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		return false;
	}

	/**
	 * Refuses all deletes through the read-only wrapper.
	 *
	 * The call is intentionally not forwarded to the target disk.
	 *
	 * @param string $path Ignored path that would have been deleted.
	 * @return bool Always `false`.
	 */
	public function delete(string $path): bool {
		return false;
	}

	/**
	 * Reads file metadata from the target disk.
	 *
	 * @param string $path Path relative to the target disk.
	 * @return FileMetadata|false Metadata from the delegated disk, or `false` when unavailable.
	 */
	public function metadata(string $path): FileMetadata|false {
		return $this->manager->metadata($path, $this->disk);
	}

	/**
	 * Lists files from the target disk.
	 *
	 * @param string $prefix Path prefix relative to the target disk.
	 * @param array<string, mixed> $options Driver-specific listing options forwarded to the target disk.
	 * @return array<int|string, mixed> Listing entries returned by the delegated disk.
	 */
	public function list(string $prefix='', array $options=[]): array {
		return $this->manager->list($prefix, $this->disk, $options);
	}

	/**
	 * Creates a temporary URL through the target disk.
	 *
	 * URL generation is considered read-oriented and is delegated. Drivers that
	 * cannot sign URLs may still return `false`.
	 *
	 * @param string $path Path relative to the target disk.
	 * @param int|\DateTimeInterface $expires Expiry timestamp or date object.
	 * @param array<string, mixed> $options Driver-specific signing options.
	 * @return string|false Temporary URL, or `false` when the target disk cannot provide one.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		return $this->manager->temporaryUrl($path, $expires, $this->disk, $options);
	}
}
