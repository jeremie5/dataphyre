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
 * Storage driver that confines another disk behind a path prefix.
 *
 * The scoped driver rewrites every caller path into a normalized target-disk
 * path under the configured prefix, then removes that prefix from metadata
 * returned to callers. It creates a namespace boundary for packages,
 * tenants, or generated assets without requiring the underlying disk to know
 * about logical scopes.
 */
final class ScopedDriver implements StorageDriver {

	/** @var string Target disk receiving prefixed operations. */
	private string $disk;

	/** @var string Normalized prefix prepended to every delegated path. */
	private string $prefix;

	/**
	 * Initializes delegated storage with a normalized path prefix.
	 *
	 * The target disk may be supplied as `disk` or `target`. Prefixes are
	 * normalized once at construction, and an empty prefix is allowed to behave
	 * like a transparent pass-through to the target disk.
	 *
	 * @param array<string, mixed> $config Driver configuration containing a target disk and optional prefix.
	 * @param ?StorageManager $manager Manager used for disk delegation, or the singleton when omitted.
	 * @throws \RuntimeException When no target disk is configured.
	 */
	public function __construct(array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->prefix=Path::normalize((string)($config['prefix'] ?? ''));
		if($this->disk===''){
			throw new \RuntimeException('Scoped storage disks require a target disk.');
		}
	}

	/**
	 * Checks whether a scoped path exists on the target disk.
	 *
	 * @param string $path Caller-visible path inside the scope.
	 * @return bool `true` when the prefixed target path exists.
	 */
	public function exists(string $path): bool {
		return $this->manager->exists($this->path($path), $this->disk);
	}

	/**
	 * Reads file contents from the prefixed target path.
	 *
	 * @param string $path Caller-visible path inside the scope.
	 * @param array<string, mixed> $options Driver-specific read options forwarded to the target disk.
	 * @return string|false File contents, or `false` when the target disk cannot read the prefixed path.
	 */
	public function read(string $path, array $options=[]): string|false {
		return $this->manager->get($this->path($path), $this->disk, $options);
	}

	/**
	 * Opens a read stream from the prefixed target path.
	 *
	 * @param string $path Caller-visible path inside the scope.
	 * @param array<string, mixed> $options Driver-specific stream options forwarded to the target disk.
	 * @return mixed stream handle or failure marker returned by the prefixed target disk.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		return $this->manager->readStream($this->path($path), $this->disk, $options);
	}

	/**
	 * Writes contents to the prefixed target path.
	 *
	 * @param string $path Caller-visible path inside the scope.
	 * @param mixed $contents Contents accepted by the target disk.
	 * @param array<string, mixed> $options Driver-specific write options forwarded to the target disk.
	 * @return bool `true` when the target disk accepts the write.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		return $this->manager->put($this->path($path), $contents, $this->disk, $options);
	}

	/**
	 * Deletes the prefixed target path.
	 *
	 * @param string $path Caller-visible path inside the scope.
	 * @return bool `true` when the target disk reports deletion success.
	 */
	public function delete(string $path): bool {
		return $this->manager->delete($this->path($path), $this->disk);
	}

	/**
	 * Reads metadata and removes the scope prefix from the returned path.
	 *
	 * @param string $path Caller-visible path inside the scope.
	 * @return FileMetadata|false Metadata with caller-visible path, or `false` when unavailable.
	 */
	public function metadata(string $path): FileMetadata|false {
		$metadata=$this->manager->metadata($this->path($path), $this->disk);
		return $metadata instanceof FileMetadata ? $this->unscopedMetadata($metadata) : false;
	}

	/**
	 * Lists prefixed entries and returns metadata with unscoped paths.
	 *
	 * @param string $prefix Caller-visible listing prefix inside the scope.
	 * @param array<string, mixed> $options Driver-specific listing options forwarded to the target disk.
	 * @return array<int, FileMetadata> Metadata entries with the configured scope prefix removed.
	 */
	public function list(string $prefix='', array $options=[]): array {
		return array_map(
			fn(FileMetadata $metadata): FileMetadata => $this->unscopedMetadata($metadata),
			$this->manager->list($this->path($prefix), $this->disk, $options)
		);
	}

	/**
	 * Creates a temporary URL for the prefixed target path.
	 *
	 * @param string $path Caller-visible path inside the scope.
	 * @param int|\DateTimeInterface $expires Expiry timestamp or date object.
	 * @param array<string, mixed> $options Driver-specific signing options forwarded to the target disk.
	 * @return string|false Temporary URL, or `false` when the target disk cannot provide one.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		return $this->manager->temporaryUrl($this->path($path), $expires, $this->disk, $options);
	}

	/**
	 * Converts a caller-visible path into the delegated target-disk path.
	 *
	 * @param string $path Caller-visible path inside the scope.
	 * @return string Normalized path with the configured prefix applied.
	 */
	private function path(string $path): string {
		$path=Path::normalize($path);
		return $this->prefix==='' ? $path : Path::normalize($this->prefix.'/'.$path);
	}

	/**
	 * Removes the scope prefix from target-disk metadata.
	 *
	 * The returned metadata preserves size, modification time, MIME type, and
	 * extra fields while replacing the path with the caller-visible path.
	 *
	 * @param FileMetadata $metadata Metadata returned by the target disk.
	 * @return FileMetadata Metadata rewritten for the scoped view.
	 */
	private function unscopedMetadata(FileMetadata $metadata): FileMetadata {
		$path=$metadata->path();
		if($this->prefix!=='' && str_starts_with($path, $this->prefix.'/')){
			$path=substr($path, strlen($this->prefix)+1);
		}
		return new FileMetadata($path, $metadata->size(), $metadata->modifiedAt(), $metadata->mimeType(), $metadata->extra());
	}
}
