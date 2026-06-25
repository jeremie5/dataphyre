<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage;

use Dataphyre\Storage\Contracts\StorageDriver;

/**
 * Static entry point for Dataphyre Storage disks and file operations.
 *
 * Calls delegate to `StorageManager` so runtime code can read, write, stream, list, inspect
 * metadata, generate signed URLs, and run storage maintenance workflows without binding to a
 * concrete driver class.
 */
final class Storage {

	/**
	 * Returns the process-wide Storage manager.
	 *
	 * The manager caches resolved disk drivers, custom driver factories, and event listeners for
	 * the current PHP process.
	 *
	 * @return StorageManager Active manager instance.
	 */
	public static function manager(): StorageManager {
		return StorageManager::instance();
	}

	/**
	 * Drops the cached Storage manager singleton.
	 *
	 * Long-running workers and tests use this to force disk instances, custom factories, and
	 * listener state to be rebuilt from current runtime configuration.
	 *
	 * @return void
	 */
	public static function flushManager(): void {
		StorageManager::flushInstance();
	}

	/**
	 * Registers or replaces a driver factory on the current manager.
	 *
	 * Factory names are normalized by the manager. The callable must return a `StorageDriver`
	 * when a disk using that driver is first resolved.
	 *
	 * @param string $driver Driver name or alias from disk configuration.
	 * @param callable $factory Factory invoked as `(array $config, string $name, StorageManager $manager)`.
	 * @return void
	 */
	public static function extend(string $driver, callable $factory): void {
		self::manager()->extend($driver, $factory);
	}

	/**
	 * Adds a listener for manager-emitted storage events.
	 *
	 * Listeners receive operation data after the manager has normalized disk names; `*` listeners
	 * observe every emitted event.
	 *
	 * @param string $event Event name; empty names are rejected by the manager.
	 * @param callable $listener Listener invoked with the event data array.
	 * @return void
	 */
	public static function listen(string $event, callable $listener): void {
		self::manager()->listen($event, $listener);
	}

	/**
	 * Emits a storage event through the current manager.
	 *
	 * The manager augments the event data with `event` and `time` keys before dispatching
	 * exact-name listeners and wildcard listeners.
	 *
	 * @param string $event Storage or permission event name.
	 * @param array<string,mixed> $payload Operation event fields supplied by the caller or driver.
	 * @return void
	 */
	public static function emit(string $event, array $payload=[]): void {
		self::manager()->emit($event, $payload);
	}

	/**
	 * Resolves a configured disk driver.
	 *
	 * `null` selects the configured default disk, names are canonicalized, and the manager reuses
	 * the same driver instance for subsequent calls.
	 *
	 * @param ?string $name Disk name; `null` selects the default disk.
	 * @return StorageDriver Resolved disk driver.
	 */
	public static function disk(?string $name=null): StorageDriver {
		return self::manager()->disk($name);
	}

	/**
	 * Checks whether a storage path exists on a disk.
	 *
	 * The path is passed through to the resolved driver. Wrappers such as scoped, policy,
	 * read-only, and failover drivers apply their own boundaries.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return bool True when the resolved driver reports the path exists.
	 */
	public static function exists(string $path, ?string $disk=null): bool {
		return self::manager()->exists($path, $disk);
	}

	/**
	 * Reads an entire object into memory.
	 *
	 * The manager reads a driver stream, decrypts it when disk or option encryption is enabled,
	 * emits `storage.read`, and returns `false` when no readable stream is available.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return string|false Object contents after optional decryption, or false when no readable stream is available.
	 */
	public static function get(string $path, ?string $disk=null, array $options=[]): string|false {
		return self::manager()->get($path, $disk, $options);
	}

	/**
	 * Opens an object for streaming reads.
	 *
	 * A readable resource is returned after optional decryption. Failures emit
	 * `storage.read_stream` and return the driver's falsey result.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return resource|false Readable stream resource after optional decryption, or false when unavailable.
	 */
	public static function readStream(string $path, ?string $disk=null, array $options=[]): mixed {
		return self::manager()->readStream($path, $disk, $options);
	}

	/**
	 * Writes bytes or a stream to a storage path.
	 *
	 * Manager write guards enforce configured size, extension, and MIME allow-lists before
	 * optional encryption and driver writes occur.
	 *
	 * @param string $path Storage path.
	 * @param mixed $contents String bytes or readable stream.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool true when manager guards pass and the selected driver stores the bytes/stream successfully.
	 */
	public static function put(string $path, mixed $contents, ?string $disk=null, array $options=[]): bool {
		return self::manager()->put($path, $contents, $disk, $options);
	}

	/**
	 * Writes a local filesystem file to a storage path.
	 *
	 * Missing or unreadable source files return `false`. Successful opens are streamed through
	 * the same guards, encryption, events, and driver write path as `put()`.
	 *
	 * @param string $path Storage path.
	 * @param string $localFile Absolute or relative local source filename.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool True when the local file is readable and the driver stores it successfully.
	 */
	public static function putFile(string $path, string $localFile, ?string $disk=null, array $options=[]): bool {
		return self::manager()->putFile($path, $localFile, $disk, $options);
	}

	/**
	 * Stores one PHP upload array.
	 *
	 * Upload errors, missing temporary files, or guard failures return `false`. Content type,
	 * original name, and max-byte defaults are derived before streaming the temp file.
	 *
	 * @param string $path Storage path.
	 * @param array{tmp_name?:string,error?:int|string,name?:string,type?:string,size?:int|string} $file
	 * PHP upload array for the current file.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool True when the upload array is valid and the driver stores it successfully.
	 */
	public static function putUploadedFile(string $path, array $file, ?string $disk=null, array $options=[]): bool {
		return self::manager()->putUploadedFile($path, $file, $disk, $options);
	}

	/**
	 * Deletes a path from a disk.
	 *
	 * The manager emits before/after delete events and returns the driver success flag without
	 * swallowing driver-level exceptions.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return bool True when the resolved driver deletes the path successfully.
	 */
	public static function delete(string $path, ?string $disk=null): bool {
		return self::manager()->delete($path, $disk);
	}

	/**
	 * Copies an object between paths or disks.
	 *
	 * The source is streamed through the manager read path and then written through the manager
	 * write path, so encryption, guards, and events apply on both sides.
	 *
	 * @param string $from Source storage path.
	 * @param string $to Destination storage path.
	 * @param ?string $disk Source disk name; `null` selects the default disk.
	 * @param ?string $toDisk Destination disk name; `null` reuses the source disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool True when the source is read and the destination write succeeds.
	 */
	public static function copy(string $from, string $to, ?string $disk=null, ?string $toDisk=null, array $options=[]): bool {
		return self::manager()->copy($from, $to, $disk, $toDisk, $options);
	}

	/**
	 * Moves an object between paths or disks.
	 *
	 * Move is implemented as copy followed by source delete, so failed writes leave the source
	 * intact and successful cross-disk moves can still fail during deletion.
	 *
	 * @param string $from Source storage path.
	 * @param string $to Destination storage path.
	 * @param ?string $disk Source disk name; `null` selects the default disk.
	 * @param ?string $toDisk Destination disk name; `null` reuses the source disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool True when copy succeeds and the original path is deleted.
	 */
	public static function move(string $from, string $to, ?string $disk=null, ?string $toDisk=null, array $options=[]): bool {
		return self::manager()->move($from, $to, $disk, $toDisk, $options);
	}

	/**
	 * Computes a checksum from the readable object stream.
	 *
	 * Unsupported hash algorithms or unreadable objects return `false`; readable streams are rewound before hashing.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param string $algorithm PHP hash algorithm name.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return string|false Hex checksum for the readable object stream, or false when unavailable.
	 */
	public static function checksum(string $path, ?string $disk=null, string $algorithm='sha256', array $options=[]): string|false {
		return self::manager()->checksum($path, $disk, $algorithm, $options);
	}

	/**
	 * Returns driver metadata for one path.
	 *
	 * Metadata objects expose normalized path, size, timestamps, MIME type, and attributes when
	 * the backend can provide them; missing objects return `false`.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return FileMetadata|false Driver-normalized metadata, or false when unavailable.
	 */
	public static function metadata(string $path, ?string $disk=null): FileMetadata|false {
		return self::manager()->metadata($path, $disk);
	}

	/**
	 * Lists metadata entries below a prefix.
	 *
	 * Prefix and options are passed to the driver so local, remote, scoped, cached, and wrapped
	 * disks can apply their own listing limits and filtering.
	 *
	 * @param string $prefix Storage prefix to list, or an empty string for the disk root.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return list<array<string,mixed>> Object listing rows for the prefix, normalized by the selected disk driver.
	 */
	public static function list(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->list($prefix, $disk, $options);
	}

	/**
	 * Builds a temporary read URL for one object.
	 *
	 * URL signing is delegated to the driver; disks without temporary URL support return `false`.
	 *
	 * @param string $path Storage path.
	 * @param int|\DateTimeInterface $expires Unix timestamp or date-time expiry.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return string|false Temporary read URL, or false when the driver cannot sign one.
	 */
	public static function temporaryUrl(string $path, int|\DateTimeInterface $expires, ?string $disk=null, array $options=[]): string|false {
		return self::manager()->temporaryUrl($path, $expires, $disk, $options);
	}

	/**
	 * Builds a temporary direct-upload URL for one object.
	 *
	 * Manager write guards run before the driver is asked to sign the upload URL; unsupported
	 * drivers or blocked paths return `false`.
	 *
	 * @param string $path Storage path.
	 * @param int|\DateTimeInterface $expires Unix timestamp or date-time expiry.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return string|false Temporary upload URL, or false when signing is unsupported or blocked by guards.
	 */
	public static function temporaryUploadUrl(string $path, int|\DateTimeInterface $expires, ?string $disk=null, array $options=[]): string|false {
		return self::manager()->temporaryUploadUrl($path, $expires, $disk, $options);
	}

	/**
	 * Starts a driver-managed multipart upload.
	 *
	 * Manager write guards run before the driver creates backend upload state; unsupported
	 * drivers or blocked paths return `false`.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array{upload_id?:string,path?:string,disk?:string,parts?:array<int,mixed>,metadata?:array<string,mixed>}|false
	 * Multipart upload session data, or false when the driver cannot start one.
	 */
	public static function initiateMultipartUpload(string $path, ?string $disk=null, array $options=[]): array|false {
		return self::manager()->initiateMultipartUpload($path, $disk, $options);
	}

	/**
	 * Builds temporary URLs for multipart upload parts.
	 *
	 * The manager verifies write eligibility before delegating to drivers that know how to sign
	 * per-part upload URLs.
	 *
	 * @param string $path Storage path.
	 * @param string $uploadId Driver-issued multipart upload identifier.
	 * @param int $parts Number of part URLs to request.
	 * @param int|\DateTimeInterface $expires Unix timestamp or date-time expiry.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<int,array{part:int,url:string,headers?:array<string,string>,expires_at?:string}>|false
	 * Temporary upload URLs keyed or ordered by part number, or false when unsupported.
	 */
	public static function temporaryMultipartUploadUrls(string $path, string $uploadId, int $parts, int|\DateTimeInterface $expires, ?string $disk=null, array $options=[]): array|false {
		return self::manager()->temporaryMultipartUploadUrls($path, $uploadId, $parts, $expires, $disk, $options);
	}

	/**
	 * Completes a driver-managed multipart upload.
	 *
	 * Part descriptors are passed unchanged to the driver; unsupported disks return `false`
	 * without mutating backend upload state.
	 *
	 * @param string $path Storage path.
	 * @param string $uploadId Driver-issued multipart upload identifier.
	 * @param list<array<string,mixed>> $parts Multipart upload part descriptors.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return bool true when the selected driver accepts and finalizes the multipart part list.
	 */
	public static function completeMultipartUpload(string $path, string $uploadId, array $parts, ?string $disk=null): bool {
		return self::manager()->completeMultipartUpload($path, $uploadId, $parts, $disk);
	}

	/**
	 * Aborts a driver-managed multipart upload.
	 *
	 * Unsupported disks return `false`; supported disks decide how to remove any backend upload
	 * state for the identifier.
	 *
	 * @param string $path Storage path.
	 * @param string $uploadId Driver-issued multipart upload identifier.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return bool True when the driver aborts the multipart upload state.
	 */
	public static function abortMultipartUpload(string $path, string $uploadId, ?string $disk=null): bool {
		return self::manager()->abortMultipartUpload($path, $uploadId, $disk);
	}

	/**
	 * Lists known versions for an object.
	 *
	 * Versioned disks return driver-normalized version descriptors; disks without version support
	 * return an empty list.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return list<array<string,mixed>> Stored version rows for the path, newest-first when the driver provides ordering.
	 */
	public static function versions(string $path, ?string $disk=null): array {
		return self::manager()->versions($path, $disk);
	}

	/**
	 * Restores one stored object version.
	 *
	 * Unsupported disks return `false`; supported disks receive the version identifier and options
	 * unchanged.
	 *
	 * @param string $path Storage path.
	 * @param string $versionId Driver-issued version identifier.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool True when the driver restores the requested object version.
	 */
	public static function restoreVersion(string $path, string $versionId, ?string $disk=null, array $options=[]): bool {
		return self::manager()->restoreVersion($path, $versionId, $disk, $options);
	}

	/**
	 * Permanently removes one stored object version.
	 *
	 * Unsupported disks return `false`; supported drivers enforce their own retention, legal-hold,
	 * and backend deletion rules.
	 *
	 * @param string $path Storage path.
	 * @param string $versionId Driver-issued version identifier.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return bool True when the driver permanently removes the requested object version.
	 */
	public static function purgeVersion(string $path, string $versionId, ?string $disk=null): bool {
		return self::manager()->purgeVersion($path, $versionId, $disk);
	}

	/**
	 * Prunes stored object versions below an optional path.
	 *
	 * Version-capable drivers return pruning counts and deleted identifiers; unsupported disks
	 * return a structured no-op result.
	 *
	 * @param ?string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Version pruning report with retained, deleted, skipped, and error counts.
	 */
	public static function pruneVersions(?string $path=null, ?string $disk=null, array $options=[]): array {
		return self::manager()->pruneVersions($path, $disk, $options);
	}

	/**
	 * Verifies integrity metadata for one object.
	 *
	 * Integrity-aware disks return hash/check status and failures; unsupported disks return a
	 * structured failure report.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Integrity verification report with checksum matches, mismatches, and driver metadata.
	 */
	public static function verifyIntegrity(string $path, ?string $disk=null, array $options=[]): array {
		return self::manager()->verifyIntegrity($path, $disk, $options);
	}

	/**
	 * Reports integrity coverage below a prefix.
	 *
	 * Integrity-aware disks return checked, passed, failed, and failure-detail counts; unsupported
	 * disks return an empty failure report with `ok=false`.
	 *
	 * @param string $prefix Storage prefix to inspect.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Prefix integrity report with scanned objects, checksum coverage, mismatches, and warnings.
	 */
	public static function integrityReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->integrityReport($prefix, $disk, $options);
	}

	/**
	 * Reads audit entries for a path or disk.
	 *
	 * Audit-capable wrappers return their stored trail; disks without audit support return an empty list.
	 *
	 * @param ?string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return list<array<string,mixed>> Audit trail entries for the path or disk scope.
	 */
	public static function auditTrail(?string $path=null, ?string $disk=null, array $options=[]): array {
		return self::manager()->auditTrail($path, $disk, $options);
	}

	/**
	 * Reports deduplicated storage usage below a prefix.
	 *
	 * Deduplicated disks return logical object counts, unique blob counts, referenced bytes,
	 * stored bytes, and missing-reference details.
	 *
	 * @param string $prefix Storage prefix to inspect.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Deduplication report with duplicate groups, reclaimable bytes, and scanned object counts.
	 */
	public static function deduplicationReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->deduplicationReport($prefix, $disk, $options);
	}

	/**
	 * Reports quota usage below a prefix.
	 *
	 * Quota-aware disks return byte/object usage, configured limits, and remaining capacity;
	 * unsupported disks return a structured no-op report.
	 *
	 * @param string $prefix Storage prefix or quota scope.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Quota usage report with object counts, bytes used, limits, and threshold status.
	 */
	public static function quotaReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->quotaReport($prefix, $disk, $options);
	}

	/**
	 * Reports cache state below a prefix.
	 *
	 * Cached disks return object counts, byte counts, freshness, staleness, and missing-cache
	 * details; unsupported disks return `ok=false`.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Cache report with cached objects, stale entries, hit metadata, and storage usage.
	 */
	public static function cacheReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->cacheReport($prefix, $disk, $options);
	}

	/**
	 * Purges cached entries below a prefix.
	 *
	 * Cached disks decide which entries match the prefix and options; unsupported disks return a
	 * structured no-op result.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Cache purge report with removed entries, skipped paths, and driver errors.
	 */
	public static function purgeCache(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->purgeCache($prefix, $disk, $options);
	}

	/**
	 * Reports compression efficiency below a prefix.
	 *
	 * Compression-aware disks return raw/compressed object counts, original and stored bytes,
	 * saved bytes, and compression ratio.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Compression report with compressed objects, byte savings, and unsupported entries.
	 */
	public static function compressionReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->compressionReport($prefix, $disk, $options);
	}

	/**
	 * Applies retention settings to one object.
	 *
	 * Retention-capable disks receive the options unchanged; unsupported disks return `false`.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool True when the driver applies retention settings to the object.
	 */
	public static function setRetention(string $path, ?string $disk=null, array $options=[]): bool {
		return self::manager()->setRetention($path, $disk, $options);
	}

	/**
	 * Releases retention settings for one object.
	 *
	 * Retention-capable disks enforce their own unlock rules; unsupported disks return `false`.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool True when the driver releases retention settings for the object.
	 */
	public static function releaseRetention(string $path, ?string $disk=null, array $options=[]): bool {
		return self::manager()->releaseRetention($path, $disk, $options);
	}

	/**
	 * Reports retention and hold state below a prefix.
	 *
	 * Retention-aware disks return locked, unlocked, legal-hold, and expired counts; unsupported
	 * disks return a structured no-op report.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Retention report with protected objects, expiry windows, and policy violations.
	 */
	public static function retentionReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->retentionReport($prefix, $disk, $options);
	}

	/**
	 * Previews lifecycle actions below a prefix.
	 *
	 * Lifecycle-aware disks report eligible objects and candidate paths without requiring deletion;
	 * unsupported disks return a dry-run no-op report.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Lifecycle report with eligible objects, pending actions, skipped paths, and policy metadata.
	 */
	public static function lifecycleReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->lifecycleReport($prefix, $disk, $options);
	}

	/**
	 * Applies lifecycle rules below a prefix.
	 *
	 * Lifecycle-aware disks may delete or transition objects according to options; unsupported
	 * disks return a structured no-op report.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Lifecycle application report with applied actions, skipped objects, and errors.
	 */
	public static function applyLifecycle(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->applyLifecycle($prefix, $disk, $options);
	}

	/**
	 * Reports scan status below a prefix.
	 *
	 * Scan-aware disks return clean and blocked object counts; unsupported disks return a
	 * structured no-op report.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Malware or content scan report with scanned objects, findings, quarantines, and errors.
	 */
	public static function scanReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->scanReport($prefix, $disk, $options);
	}

	/**
	 * Purges quarantined objects below a prefix.
	 *
	 * Scan-aware disks decide which quarantined entries match the prefix and options; unsupported
	 * disks return a structured no-op result.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Quarantine purge report with removed objects, retained findings, and errors.
	 */
	public static function purgeQuarantine(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->purgeQuarantine($prefix, $disk, $options);
	}

	/**
	 * Adds or replaces tags for one object.
	 *
	 * Tag-aware disks read tag data from options and persist it according to their own metadata
	 * backend; unsupported disks return `false`.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool True when tags are written to the object's metadata backend.
	 */
	public static function tagObject(string $path, ?string $disk=null, array $options=[]): bool {
		return self::manager()->tagObject($path, $disk, $options);
	}

	/**
	 * Reads tags for one object.
	 *
	 * Tag-aware disks return tags and metadata; unsupported disks return empty tag and metadata
	 * arrays.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return array<string,string> Object tags keyed by tag name for the selected path.
	 */
	public static function tagsFor(string $path, ?string $disk=null): array {
		return self::manager()->tagsFor($path, $disk);
	}

	/**
	 * Finds objects matching tag constraints.
	 *
	 * Tag-aware disks interpret list tags or key/value constraints and return matching object
	 * descriptors; unsupported disks return an empty list.
	 *
	 * @param list<string>|array<string,string> $tags Object tags to attach or replace.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return list<array<string,mixed>> Objects matching all requested tags within the selected disk scope.
	 */
	public static function findByTags(array $tags, ?string $disk=null, array $options=[]): array {
		return self::manager()->findByTags($tags, $disk, $options);
	}

	/**
	 * Reports tag usage below a prefix.
	 *
	 * Tag-aware disks return object counts and tag distribution; unsupported disks return a
	 * structured no-op report.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Tag coverage report with scanned objects, tag counts, and missing-tag findings.
	 */
	public static function tagReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->tagReport($prefix, $disk, $options);
	}

	/**
	 * Reports policy-driver rules and matches below a prefix.
	 *
	 * Policy-aware disks expose their active rule report; unsupported disks return `ok=false`
	 * with an empty rule set.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Policy report with rule matches, violations, and object-level findings.
	 */
	public static function policyReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->policyReport($prefix, $disk, $options);
	}

	/**
	 * Reports rate-limit buckets below a prefix.
	 *
	 * Rate-limited disks return active limits and bucket state; unsupported disks return a
	 * structured no-op report.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Rate-limit report with counters, windows, remaining capacity, and exceeded limits.
	 */
	public static function rateLimitReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->rateLimitReport($prefix, $disk, $options);
	}

	/**
	 * Resets rate-limit buckets below a prefix.
	 *
	 * Rate-limited disks clear matching counters according to options; unsupported disks return
	 * `reset=false`.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Rate-limit reset report with cleared counters, skipped scopes, and errors.
	 */
	public static function resetRateLimits(string $prefix='', ?string $disk=null, array $options=[]): array {
		return self::manager()->resetRateLimits($prefix, $disk, $options);
	}

	/**
	 * Reads evented-driver history for a path or disk.
	 *
	 * Evented disks return their stored operation trail; disks without event history return an empty list.
	 *
	 * @param ?string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return list<array<string,mixed>> Storage event entries for the path or disk scope.
	 */
	public static function eventTrail(?string $path=null, ?string $disk=null, array $options=[]): array {
		return self::manager()->eventTrail($path, $disk, $options);
	}

	/**
	 * Reports configured manifest and log files.
	 *
	 * The report is built from disk configuration, includes file existence, byte size, and mtime,
	 * and never resolves driver objects.
	 *
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return array<string,mixed> Configured manifest file report with disk keys, paths, existence, byte sizes, and mtimes.
	 */
	public static function manifestReport(?string $disk=null): array {
		return self::manager()->manifestReport($disk);
	}

	/**
	 * Exports configured manifest and log files.
	 *
	 * Existing JSON manifests are decoded into a portable bundle; invalid JSON is preserved as
	 * raw content and optional `path` output writes the bundle to disk.
	 *
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Portable manifest bundle containing configured manifest file data and export metadata.
	 */
	public static function exportManifests(?string $disk=null, array $options=[]): array {
		return self::manager()->exportManifests($disk, $options);
	}

	/**
	 * Imports a previously exported manifest bundle.
	 *
	 * Only configured manifest targets are written; `mode=merge` recursively merges existing JSON
	 * arrays, while the default mode replaces each imported file.
	 *
	 * @param array|string $bundle Bundle array or local JSON bundle path.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Manifest import report with validity status and count of configured manifest files written.
	 */
	public static function importManifests(array|string $bundle, array $options=[]): array {
		return self::manager()->importManifests($bundle, $options);
	}

	/**
	 * Runs diagnostics for one disk or all configured disks.
	 *
	 * Diagnostics instantiate disks, list, optionally perform write/read/delete probes, check
	 * temporary URL support, expose configured manifests, and include config warnings.
	 *
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Storage diagnostics with driver capabilities, configuration status, and health checks.
	 */
	public static function diagnostics(?string $disk=null, array $options=[]): array {
		return self::manager()->diagnostics($disk, $options);
	}

	/**
	 * Synchronizes metadata-listed objects between disks.
	 *
	 * Sync compares source and target by path, size, checksum or mtime, defaults to dry-run, can
	 * delete target-only objects, and emits `storage.sync`.
	 *
	 * @param string $fromDisk Source disk name.
	 * @param string $toDisk Destination disk name.
	 * @param string $prefix Storage prefix to synchronize.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Cross-disk sync report with copied, skipped, deleted, failed, and verified object counts.
	 */
	public static function sync(string $fromDisk, string $toDisk, string $prefix='', array $options=[]): array {
		return self::manager()->sync($fromDisk, $toDisk, $prefix, $options);
	}

	/**
	 * Clears all in-memory fake storage.
	 *
	 * This affects `MemoryDriver` state globally for the current process and is intended for
	 * tests or isolated workers.
	 *
	 * @return void
	 */
	public static function fakeFlush(): void {
		self::manager()->fakeFlush();
	}

	/**
	 * Returns the current in-memory fake storage snapshot.
	 *
	 * The snapshot exposes `MemoryDriver` state for assertions and diagnostics without resolving
	 * any configured disk.
	 *
	 * @return array<string,mixed> In-memory fake driver snapshot grouped by disk, object path, metadata, and stored content.
	 */
	public static function fakeSnapshot(): array {
		return self::manager()->fakeSnapshot();
	}
}
