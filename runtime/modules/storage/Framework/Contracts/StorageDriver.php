<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage\Contracts;

use Dataphyre\Storage\FileMetadata;

/**
 * Defines the storage adapter contract used by Dataphyre disks.
 *
 * Drivers may target local filesystems, object stores, or remote services, but
 * they expose the same path-oriented operations: existence checks, reads,
 * streams, writes, deletes, metadata lookup, listing, and temporary URL creation.
 * Expected operational misses return false rather than throwing; configuration
 * errors and unsafe path boundaries may still raise driver-specific exceptions.
 */
interface StorageDriver {

	/**
	 * Reports whether a path exists.
	 *
	 * @param string $path Driver-relative path.
	 * @return bool True when the object exists.
	 */
	public function exists(string $path): bool;

	/**
	 * Reads an object into memory.
	 *
	 * @param string $path Driver-relative path.
	 * @param array<string, mixed> $options Driver-specific read options.
	 * @return string|false Object contents, or false when the object cannot be read.
	 */
	public function read(string $path, array $options=[]): string|false;

	/**
	 * Opens an object as a readable stream.
	 *
	 * @param string $path Driver-relative path.
	 * @param array<string, mixed> $options Driver-specific stream options.
	 * @return resource|false Readable stream resource, or false when unavailable.
	 */
	public function readStream(string $path, array $options=[]): mixed;

	/**
	 * Writes contents to a path.
	 *
	 * @param string $path Driver-relative path.
	 * @param string|resource $contents String contents or readable stream resource.
	 * @param array<string, mixed> $options Driver-specific write options such as visibility or content type.
	 * @return bool True when the write succeeds.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool;

	/**
	 * Deletes an object.
	 *
	 * @param string $path Driver-relative path.
	 * @return bool True when the object is deleted or confirmed absent.
	 */
	public function delete(string $path): bool;

	/**
	 * Returns metadata for a stored object.
	 *
	 * @param string $path Driver-relative path.
	 * @return FileMetadata|false Metadata value, or false when unavailable.
	 */
	public function metadata(string $path): FileMetadata|false;

	/**
	 * Lists objects under a prefix.
	 *
	 * @param string $prefix Driver-relative prefix.
	 * @param array<string, mixed> $options Driver-specific listing options.
	 * @return list<FileMetadata> Metadata entries for objects under the prefix.
	 */
	public function list(string $prefix='', array $options=[]): array;

	/**
	 * Creates a temporary URL for direct object access.
	 *
	 * Drivers that cannot safely issue temporary URLs return false.
	 *
	 * @param string $path Driver-relative path.
	 * @param int|\DateTimeInterface $expires Expiry timestamp or DateTime.
	 * @param array<string, mixed> $options Driver-specific URL options.
	 * @return string|false Temporary URL, or false when unsupported.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false;
}
