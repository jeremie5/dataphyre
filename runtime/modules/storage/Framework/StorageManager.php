<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage;

use Dataphyre\Storage\Contracts\StorageDriver;
use Dataphyre\Storage\Drivers\AuditDriver;
use Dataphyre\Storage\Drivers\CachedDriver;
use Dataphyre\Storage\Drivers\CompressedDriver;
use Dataphyre\Storage\Drivers\VestraDriver;
use Dataphyre\Storage\Drivers\DeduplicatedDriver;
use Dataphyre\Storage\Drivers\EventedDriver;
use Dataphyre\Storage\Drivers\FailoverDriver;
use Dataphyre\Storage\Drivers\IntegrityDriver;
use Dataphyre\Storage\Drivers\LifecycleDriver;
use Dataphyre\Storage\Drivers\LocalDriver;
use Dataphyre\Storage\Drivers\MemoryDriver;
use Dataphyre\Storage\Drivers\MirrorDriver;
use Dataphyre\Storage\Drivers\PolicyDriver;
use Dataphyre\Storage\Drivers\QuotaDriver;
use Dataphyre\Storage\Drivers\RateLimitedDriver;
use Dataphyre\Storage\Drivers\ReadOnlyDriver;
use Dataphyre\Storage\Drivers\RetentionDriver;
use Dataphyre\Storage\Drivers\ScopedDriver;
use Dataphyre\Storage\Drivers\ScannedDriver;
use Dataphyre\Storage\Drivers\S3CompatibleDriver;
use Dataphyre\Storage\Drivers\TaggedDriver;
use Dataphyre\Storage\Drivers\VersionedDriver;
use Dataphyre\Storage\Support\Encryption;
use Dataphyre\Storage\Support\Stream;

/**
 * Stateful manager for Dataphyre Storage disks, drivers, and events.
 *
 * The manager owns disk instances, driver factories, event listeners, and wrapper-driver
 * composition for local, memory, S3-compatible, Vestra reference aliases, cached, mirrored,
 * policy, audit, and lifecycle storage. Manager-level reads and writes apply configured
 * encryption, guards, and event emission before delegating backend-specific behavior to drivers.
 */
final class StorageManager {

	private static ?self $instance=null;
	/** @var array<string, StorageDriver> Resolved disk instances keyed by disk name. */
	private array $disks=[];
	/** @var array<string, callable> Driver factories keyed by normalized driver name. */
	private array $factories=[];
	/** @var array<string, array<int, callable>> Event listeners keyed by event name. */
	private array $listeners=[];

	/**
	 * Creates a manager with Dataphyre's built-in driver factories registered.
	 */
	public function __construct() {
		$this->registerBuiltInDrivers();
	}
	/**
	 * Returns the process-wide Storage manager.
	 *
	 * The singleton holds resolved disk drivers, custom factories, and event listeners until
	 * `flushInstance()` is called.
	 *
	 * @return self Active manager instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Drops the cached manager singleton.
	 *
	 * The next `instance()` call rebuilds manager state from runtime configuration, which keeps
	 * tests and workers from leaking disk instances.
	 *
	 * @return void
	 */
	public static function flushInstance(): void {
		self::$instance=null;
	}

	/**
	 * Registers or replaces a driver factory.
	 *
	 * @param string $driver Driver name or alias; empty normalized names throw.
	 * @param callable $factory Factory invoked as `(array $config, string $name, self $manager)`.
	 * @return void
	 */
	public function extend(string $driver, callable $factory): void {
		$driver=$this->normalizeName($driver);
		if($driver===''){
			throw new \InvalidArgumentException('Storage driver name cannot be empty.');
		}
		$this->factories[$driver]=$factory;
	}

	/**
	 * Registers Dataphyre's bundled storage drivers.
	 *
	 * The factories are intentionally installed at construction time so CLI tools,
	 * app bootstraps, Panel upload endpoints, and tests all receive the same disk
	 * resolution behavior without hand-registering `local`, wrapper, or remote
	 * drivers.
	 *
	 * @return void
	 */
	private function registerBuiltInDrivers(): void {
		$this->factories=[
			'local'=>static fn(array $config, string $name, self $manager): StorageDriver => new LocalDriver($config),
			'memory'=>static fn(array $config, string $name, self $manager): StorageDriver => new MemoryDriver($config),
			'vestra'=>static fn(array $config, string $name, self $manager): StorageDriver => new VestraDriver($config),
			's3'=>static fn(array $config, string $name, self $manager): StorageDriver => new S3CompatibleDriver($config),
			'r2'=>static fn(array $config, string $name, self $manager): StorageDriver => new S3CompatibleDriver($config),
			'mirror'=>static fn(array $config, string $name, self $manager): StorageDriver => new MirrorDriver($config, $manager),
			'scoped'=>static fn(array $config, string $name, self $manager): StorageDriver => new ScopedDriver($config, $manager),
			'readonly'=>static fn(array $config, string $name, self $manager): StorageDriver => new ReadOnlyDriver($config, $manager),
			'read_only'=>static fn(array $config, string $name, self $manager): StorageDriver => new ReadOnlyDriver($config, $manager),
			'quota'=>static fn(array $config, string $name, self $manager): StorageDriver => new QuotaDriver($config, $manager),
			'failover'=>static fn(array $config, string $name, self $manager): StorageDriver => new FailoverDriver($config, $manager),
			'cached'=>static fn(array $config, string $name, self $manager): StorageDriver => new CachedDriver($config, $manager),
			'compressed'=>static fn(array $config, string $name, self $manager): StorageDriver => new CompressedDriver($config, $manager),
			'retention'=>static fn(array $config, string $name, self $manager): StorageDriver => new RetentionDriver($config, $manager),
			'lifecycle'=>static fn(array $config, string $name, self $manager): StorageDriver => new LifecycleDriver($config, $manager),
			'scanned'=>static fn(array $config, string $name, self $manager): StorageDriver => new ScannedDriver($config, $manager),
			'tagged'=>static fn(array $config, string $name, self $manager): StorageDriver => new TaggedDriver($config, $manager),
			'evented'=>static fn(array $config, string $name, self $manager): StorageDriver => new EventedDriver($config, $manager),
			'policy'=>static fn(array $config, string $name, self $manager): StorageDriver => new PolicyDriver($config, $manager),
			'rate_limited'=>static fn(array $config, string $name, self $manager): StorageDriver => new RateLimitedDriver($config, $manager),
			'versioned'=>static fn(array $config, string $name, self $manager): StorageDriver => new VersionedDriver($config, $manager),
			'deduplicated'=>static fn(array $config, string $name, self $manager): StorageDriver => new DeduplicatedDriver($config, $manager),
			'integrity'=>static fn(array $config, string $name, self $manager): StorageDriver => new IntegrityDriver($config, $manager),
			'audit'=>static fn(array $config, string $name, self $manager): StorageDriver => new AuditDriver($config, $manager),
		];
	}

	/**
	 * Adds a listener for storage manager events.
	 *
	 * Empty event names throw immediately; wildcard listeners registered under `*` are invoked
	 * for every emitted event.
	 *
	 * @param string $event Event name to observe.
	 * @param callable $listener Listener invoked with the normalized event data array.
	 * @return void
	 */
	public function listen(string $event, callable $listener): void {
		$event=trim($event);
		if($event===''){
			throw new \InvalidArgumentException('Storage event name cannot be empty.');
		}
		$this->listeners[$event][]=$listener;
	}

	/**
	 * Emits a storage event to exact-name and wildcard listeners.
	 *
	 * Event data is augmented with `event` and `time`; listener exceptions are allowed to bubble
	 * to the caller.
	 *
	 * @param string $event Storage or permission event name.
	 * @param array<string,mixed> $payload Operation event fields supplied by manager or driver code.
	 * @return void
	 */
	public function emit(string $event, array $payload=[]): void {
		$payload+=['event'=>$event, 'time'=>time()];
		foreach($this->listeners[$event] ?? [] as $listener){
			$listener($payload);
		}
		foreach($this->listeners['*'] ?? [] as $listener){
			$listener($payload);
		}
	}

	/**
	 * Resolves and caches a configured disk driver.
	 *
	 * Disk names are canonicalized, missing factories throw, and factories must return
	 * `StorageDriver` instances before the disk is cached.
	 *
	 * @param ?string $name Disk name; `null` selects the configured default disk.
	 * @return StorageDriver Resolved disk driver.
	 */
	public function disk(?string $name=null): StorageDriver {
		$name=$this->diskName($name);
		if(isset($this->disks[$name])){
			return $this->disks[$name];
		}
		$config=$this->diskConfig($name);
		$driver=$this->normalizeName((string)($config['driver'] ?? $name));
		if(!isset($this->factories[$driver])){
			throw new \RuntimeException("Storage driver '{$driver}' is not registered.");
		}
		$disk=($this->factories[$driver])($config, $name, $this);
		if(!$disk instanceof StorageDriver){
			throw new \RuntimeException("Storage disk '{$name}' factory did not return a StorageDriver.");
		}
		return $this->disks[$name]=$disk;
	}

	/**
	 * Checks whether a path exists on a disk.
	 *
	 * The resolved driver receives the path unchanged, allowing scoped, policy, and remote
	 * drivers to enforce their own path rules.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return bool True when the resolved driver reports the path exists.
	 */
	public function exists(string $path, ?string $disk=null): bool {
		return $this->disk($disk)->exists($path);
	}

	/**
	 * Reads an entire object into memory.
	 *
	 * The manager reads a stream, applies optional decryption, emits `storage.read`, and returns
	 * `false` when no readable stream is available.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return string|false Object contents after optional decryption, or false when no readable stream is available.
	 */
	public function get(string $path, ?string $disk=null, array $options=[]): string|false {
		$stream=$this->readStream($path, $disk, $options);
		$result=is_resource($stream) ? Stream::contents($stream) : false;
		$this->emit('storage.read', ['path'=>$path, 'disk'=>$this->diskName($disk), 'ok'=>$result!==false]);
		return $result;
	}

	/**
	 * Opens an object for streaming reads.
	 *
	 * Driver streams are decrypted when disk or call options enable encryption; failures emit
	 * `storage.read_stream` with `ok=false`.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return resource|false Readable stream resource after optional decryption, or false when unavailable.
	 */
	public function readStream(string $path, ?string $disk=null, array $options=[]): mixed {
		$name=$this->diskName($disk);
		$config=$this->diskConfig($name);
		$stream=$this->disk($name)->readStream($path, $options);
		if(!is_resource($stream)){
			$this->emit('storage.read_stream', ['path'=>$path, 'disk'=>$name, 'ok'=>false]);
			return false;
		}
		if(Encryption::enabled($config, $options)){
			$stream=Encryption::decryptStream($stream, Encryption::key($config, $options));
			$this->emit('storage.read_stream', ['path'=>$path, 'disk'=>$name, 'ok'=>is_resource($stream)]);
			return $stream;
		}
		$this->emit('storage.read_stream', ['path'=>$path, 'disk'=>$name, 'ok'=>true]);
		return $stream;
	}

	/**
	 * Writes bytes or a stream to a storage path.
	 *
	 * Size, extension, and MIME guards run before optional encryption; successful attempts emit
	 * before/after write events with disk, path, and options.
	 *
	 * @param string $path Storage path.
	 * @param mixed $contents String bytes or readable stream.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool true when write guards pass and the selected driver stores the bytes/stream successfully.
	 */
	public function put(string $path, mixed $contents, ?string $disk=null, array $options=[]): bool {
		if($this->guardWrite($path, $contents, $disk, $options)!==true){
			return false;
		}
		$name=$this->diskName($disk);
		$config=$this->diskConfig($name);
		$writeStream=is_resource($contents) ? $contents : Stream::fromString((string)$contents);
		if(Encryption::enabled($config, $options)){
			$writeStream=Encryption::encryptStream($writeStream, Encryption::key($config, $options));
		}
		$this->emit('storage.before_write', ['path'=>$path, 'disk'=>$name, 'options'=>$options]);
		$result=$this->disk($name)->write($path, $writeStream, $options);
		$this->emit('storage.write', ['path'=>$path, 'disk'=>$name, 'ok'=>$result, 'options'=>$options]);
		return $result;
	}

	/**
	 * Writes a local file to a storage path.
	 *
	 * Unreadable local files return `false`; readable files are streamed through the same guards,
	 * encryption, and write events as `put()`.
	 *
	 * @param string $path Storage path.
	 * @param string $localFile Absolute or relative local source filename.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool True when the local file is readable and the write succeeds.
	 */
	public function putFile(string $path, string $localFile, ?string $disk=null, array $options=[]): bool {
		$stream=is_file($localFile) ? fopen($localFile, 'rb') : false;
		if(!is_resource($stream)){
			return false;
		}
		return $this->put($path, $stream, $disk, $options);
	}

	/**
	 * Stores one PHP upload array.
	 *
	 * Upload errors, missing temp files, and guard failures return `false`; content type, original
	 * name, and configured max-byte defaults are forwarded as options.
	 *
	 * @param string $path Storage path.
	 * @param array{tmp_name?:string,error?:int|string,name?:string,type?:string,size?:int|string} $file
	 * PHP upload array for the current file.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool True when the upload array is valid and the write succeeds.
	 */
	public function putUploadedFile(string $path, array $file, ?string $disk=null, array $options=[]): bool {
		if((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){
			return false;
		}
		$tmp=(string)($file['tmp_name'] ?? '');
		if($tmp==='' || !is_file($tmp)){
			return false;
		}
		$options['content_type']=$options['content_type'] ?? ($file['type'] ?? null);
		$options['original_name']=$options['original_name'] ?? ($file['name'] ?? null);
		$options['max_bytes']=$options['max_bytes'] ?? ($this->diskConfig($this->diskName($disk))['max_bytes'] ?? 0);
		return $this->putFile($path, $tmp, $disk, array_filter($options, static fn(mixed $value): bool => $value!==null));
	}

	/**
	 * Deletes one path from a disk.
	 *
	 * The manager emits before/after delete events and returns the driver success flag without
	 * catching driver-level exceptions.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return bool True when the resolved driver deletes the path successfully.
	 */
	public function delete(string $path, ?string $disk=null): bool {
		$name=$this->diskName($disk);
		$this->emit('storage.before_delete', ['path'=>$path, 'disk'=>$name]);
		$result=$this->disk($name)->delete($path);
		$this->emit('storage.delete', ['path'=>$path, 'disk'=>$name, 'ok'=>$result]);
		return $result;
	}

	/**
	 * Copies an object between paths or disks.
	 *
	 * Copy streams the source through `readStream()` and writes through `put()`, so encryption,
	 * write guards, and read/write events are applied.
	 *
	 * @param string $from Source storage path.
	 * @param string $to Destination storage path.
	 * @param ?string $disk Source disk name; `null` selects the default disk.
	 * @param ?string $toDisk Destination disk name; `null` reuses the source disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool True when the source stream is read and the destination write succeeds.
	 */
	public function copy(string $from, string $to, ?string $disk=null, ?string $toDisk=null, array $options=[]): bool {
		$stream=$this->readStream($from, $disk, $options);
		if(!is_resource($stream)){
			$this->emit('storage.copy', ['from'=>$from, 'to'=>$to, 'disk'=>$this->diskName($disk), 'to_disk'=>$this->diskName($toDisk ?? $disk), 'ok'=>false]);
			return false;
		}
		$result=$this->put($to, $stream, $toDisk ?? $disk, $options);
		$this->emit('storage.copy', ['from'=>$from, 'to'=>$to, 'disk'=>$this->diskName($disk), 'to_disk'=>$this->diskName($toDisk ?? $disk), 'ok'=>$result]);
		return $result;
	}

	/**
	 * Moves an object between paths or disks.
	 *
	 * Move is copy followed by delete; failed copies keep the source, while successful copies can
	 * still report delete failure.
	 *
	 * @param string $from Source storage path.
	 * @param string $to Destination storage path.
	 * @param ?string $disk Source disk name; `null` selects the default disk.
	 * @param ?string $toDisk Destination disk name; `null` reuses the source disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool True when copy succeeds and the original path is deleted.
	 */
	public function move(string $from, string $to, ?string $disk=null, ?string $toDisk=null, array $options=[]): bool {
		if($this->copy($from, $to, $disk, $toDisk, $options)!==true){
			return false;
		}
		return $this->delete($from, $disk);
	}

	/**
	 * Computes a checksum from a readable object stream.
	 *
	 * Unsupported algorithms or unreadable streams return `false`; readable streams are rewound
	 * before hashing.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param string $algorithm PHP hash algorithm name.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return string|false Hex checksum for the readable object stream, or false when unavailable.
	 */
	public function checksum(string $path, ?string $disk=null, string $algorithm='sha256', array $options=[]): string|false {
		$stream=$this->readStream($path, $disk, $options);
		if(!is_resource($stream) || !in_array($algorithm, hash_algos(), true)){
			return false;
		}
		$context=hash_init($algorithm);
		rewind($stream);
		hash_update_stream($context, $stream);
		return hash_final($context);
	}

	/**
	 * Returns driver metadata for one path.
	 *
	 * Drivers return `FileMetadata` when they can inspect the object and `false` for missing or
	 * unavailable metadata.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return FileMetadata|false Driver-normalized metadata, or false when unavailable.
	 */
	public function metadata(string $path, ?string $disk=null): FileMetadata|false {
		return $this->disk($disk)->metadata($path);
	}

	/**
	 * Lists metadata entries below a prefix.
	 *
	 * Prefix and options are delegated to the resolved driver; wrappers may scope, cache, filter,
	 * or fail over the listing.
	 *
	 * @param string $prefix Storage prefix to list, or an empty string for the disk root.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return list<array<string,mixed>> Object listing rows for the prefix, normalized by the selected disk driver.
	 */
	public function list(string $prefix='', ?string $disk=null, array $options=[]): array {
		return $this->disk($disk)->list($prefix, $options);
	}

	/**
	 * Builds a temporary read URL for one object.
	 *
	 * URL signing is delegated to the driver; disks without support return `false`.
	 *
	 * @param string $path Storage path.
	 * @param int|\DateTimeInterface $expires Unix timestamp or date-time expiry.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return string|false Temporary read URL, or false when the driver cannot sign one.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, ?string $disk=null, array $options=[]): string|false {
		return $this->disk($disk)->temporaryUrl($path, $expires, $options);
	}

	/**
	 * Builds a temporary direct-upload URL for one object.
	 *
	 * Manager write guards run before URL signing; unsupported drivers or blocked paths return
	 * `false`.
	 *
	 * @param string $path Storage path.
	 * @param int|\DateTimeInterface $expires Unix timestamp or date-time expiry.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return string|false Temporary upload URL, or false when signing is unsupported or blocked by guards.
	 */
	public function temporaryUploadUrl(string $path, int|\DateTimeInterface $expires, ?string $disk=null, array $options=[]): string|false {
		if($this->guardWrite($path, '', $disk, $options)!==true){
			return false;
		}
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'temporaryUploadUrl')){
			return false;
		}
		return $driver->temporaryUploadUrl($path, $expires, $options);
	}

	/**
	 * Starts a driver-managed multipart upload.
	 *
	 * Manager write guards run first; unsupported drivers or blocked paths return `false` without
	 * creating backend upload state.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array{upload_id?:string,path?:string,disk?:string,parts?:array<int,mixed>,metadata?:array<string,mixed>}|false
	 * Multipart upload session data, or false when the driver cannot start one.
	 */
	public function initiateMultipartUpload(string $path, ?string $disk=null, array $options=[]): array|false {
		if($this->guardWrite($path, '', $disk, $options)!==true){
			return false;
		}
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'initiateMultipartUpload')){
			return false;
		}
		return $driver->initiateMultipartUpload($path, $options);
	}

	/**
	 * Builds temporary URLs for multipart upload parts.
	 *
	 * The manager verifies write eligibility before delegating part URL signing to drivers that
	 * support multipart uploads.
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
	public function temporaryMultipartUploadUrls(string $path, string $uploadId, int $parts, int|\DateTimeInterface $expires, ?string $disk=null, array $options=[]): array|false {
		if($this->guardWrite($path, '', $disk, $options)!==true){
			return false;
		}
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'temporaryMultipartUploadUrls')){
			return false;
		}
		return $driver->temporaryMultipartUploadUrls($path, $uploadId, $parts, $expires, $options);
	}

	/**
	 * Completes a driver-managed multipart upload.
	 *
	 * Part descriptors pass through unchanged; unsupported disks return `false`.
	 *
	 * @param string $path Storage path.
	 * @param string $uploadId Driver-issued multipart upload identifier.
	 * @param list<array<string,mixed>> $parts Multipart upload part descriptors.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return bool true when the selected driver accepts and finalizes the multipart part list.
	 */
	public function completeMultipartUpload(string $path, string $uploadId, array $parts, ?string $disk=null): bool {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'completeMultipartUpload')){
			return false;
		}
		return $driver->completeMultipartUpload($path, $uploadId, $parts);
	}

	/**
	 * Aborts a driver-managed multipart upload.
	 *
	 * Supported drivers clean up backend upload state for the identifier; unsupported disks
	 * return `false`.
	 *
	 * @param string $path Storage path.
	 * @param string $uploadId Driver-issued multipart upload identifier.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return bool True when the driver aborts the multipart upload state.
	 */
	public function abortMultipartUpload(string $path, string $uploadId, ?string $disk=null): bool {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'abortMultipartUpload')){
			return false;
		}
		return $driver->abortMultipartUpload($path, $uploadId);
	}

	/**
	 * Lists known versions for an object.
	 *
	 * Versioned disks return driver-normalized descriptors; disks without version support return
	 * an empty list.
	 *
	 * @param string $path Storage path.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return list<array<string,mixed>> Stored version rows for the path, newest-first when the driver provides ordering.
	 */
	public function versions(string $path, ?string $disk=null): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'versions')){
			return [];
		}
		return $driver->versions($path);
	}

	/**
	 * Restores one stored object version.
	 *
	 * Supported drivers receive the version identifier and options unchanged; unsupported disks
	 * return `false`.
	 *
	 * @param string $path Storage path.
	 * @param string $versionId Driver-issued version identifier.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return bool True when the driver restores the requested object version.
	 */
	public function restoreVersion(string $path, string $versionId, ?string $disk=null, array $options=[]): bool {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'restoreVersion')){
			return false;
		}
		return $driver->restoreVersion($path, $versionId, $options);
	}

	/**
	 * Permanently removes one stored object version.
	 *
	 * Supported drivers enforce backend retention and deletion rules; unsupported disks return
	 * `false`.
	 *
	 * @param string $path Storage path.
	 * @param string $versionId Driver-issued version identifier.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @return bool True when the driver permanently removes the requested object version.
	 */
	public function purgeVersion(string $path, string $versionId, ?string $disk=null): bool {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'purgeVersion')){
			return false;
		}
		return $driver->purgeVersion($path, $versionId);
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
	public function pruneVersions(?string $path=null, ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'pruneVersions')){
			return ['ok'=>false, 'pruned'=>0, 'deleted_ids'=>[], 'message'=>'Disk does not support version pruning.'];
		}
		return $driver->pruneVersions($path, $options);
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
	public function verifyIntegrity(string $path, ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'verifyIntegrity')){
			return ['ok'=>false, 'path'=>$path, 'message'=>'Disk does not support integrity verification.'];
		}
		return $driver->verifyIntegrity($path, $options);
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
	public function integrityReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'integrityReport')){
			return ['ok'=>false, 'checked'=>0, 'passed'=>0, 'failed'=>0, 'failures'=>[], 'message'=>'Disk does not support integrity verification.'];
		}
		return $driver->integrityReport($prefix, $options);
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
	public function auditTrail(?string $path=null, ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'auditTrail')){
			return [];
		}
		return $driver->auditTrail($path, $options);
	}

	/**
	 * Reports deduplicated storage usage below a prefix.
	 *
	 * Deduplicated disks return logical object counts, unique blob counts, referenced bytes,
	 * stored bytes, and missing-reference details.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Deduplication report with duplicate groups, reclaimable bytes, and scanned object counts.
	 */
	public function deduplicationReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'deduplicationReport')){
			return ['ok'=>false, 'logical_objects'=>0, 'unique_blobs'=>0, 'referenced_bytes'=>0, 'stored_bytes'=>0, 'saved_bytes'=>0, 'missing'=>[], 'message'=>'Disk does not support deduplication reporting.'];
		}
		return $driver->deduplicationReport($prefix, $options);
	}

	/**
	 * Reports quota usage below a prefix.
	 *
	 * Quota-aware disks return byte/object usage, configured limits, and remaining capacity;
	 * unsupported disks return a structured no-op report.
	 *
	 * @param string $prefix Storage prefix used to scope the operation.
	 * @param ?string $disk Disk name; `null` selects the default disk.
	 * @param array<string,mixed> $options Driver-specific options.
	 * @return array<string,mixed> Quota usage report with object counts, bytes used, limits, and threshold status.
	 */
	public function quotaReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'quotaReport')){
			return ['ok'=>false, 'scope'=>$prefix, 'bytes'=>0, 'objects'=>0, 'max_bytes'=>0, 'max_objects'=>0, 'bytes_remaining'=>null, 'objects_remaining'=>null, 'message'=>'Disk does not support quota reporting.'];
		}
		return $driver->quotaReport($prefix, $options);
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
	public function cacheReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'cacheReport')){
			return ['ok'=>false, 'objects'=>0, 'bytes'=>0, 'fresh'=>0, 'stale'=>0, 'missing'=>[], 'message'=>'Disk does not support cache reporting.'];
		}
		return $driver->cacheReport($prefix, $options);
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
	public function purgeCache(string $prefix='', ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'purgeCache')){
			return ['ok'=>false, 'purged'=>0, 'message'=>'Disk does not support cache purging.'];
		}
		return $driver->purgeCache($prefix, $options);
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
	public function compressionReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'compressionReport')){
			return ['ok'=>false, 'objects'=>0, 'compressed_objects'=>0, 'raw_objects'=>0, 'original_bytes'=>0, 'stored_bytes'=>0, 'saved_bytes'=>0, 'ratio'=>1, 'message'=>'Disk does not support compression reporting.'];
		}
		return $driver->compressionReport($prefix, $options);
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
	public function setRetention(string $path, ?string $disk=null, array $options=[]): bool {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'setRetention')){
			return false;
		}
		return $driver->setRetention($path, $options);
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
	public function releaseRetention(string $path, ?string $disk=null, array $options=[]): bool {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'releaseRetention')){
			return false;
		}
		return $driver->releaseRetention($path, $options);
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
	public function retentionReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'retentionReport')){
			return ['ok'=>false, 'objects'=>0, 'locked'=>0, 'unlocked'=>0, 'legal_holds'=>0, 'expired'=>0, 'message'=>'Disk does not support retention reporting.'];
		}
		return $driver->retentionReport($prefix, $options);
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
	public function lifecycleReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'lifecycleReport')){
			return ['ok'=>false, 'dry_run'=>true, 'eligible'=>0, 'deleted'=>0, 'paths'=>[], 'message'=>'Disk does not support lifecycle reporting.'];
		}
		return $driver->lifecycleReport($prefix, $options);
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
	public function applyLifecycle(string $prefix='', ?string $disk=null, array $options=[]): array {
		$name=$this->diskName($disk);
		$prefix=trim(str_replace('\\', '/', $prefix), '/');
		$payload=[
			'disk'=>$name,
			'prefix'=>$prefix,
			'options_keys'=>array_values(array_map('strval', array_keys($options))),
		];
		$dialback=\dataphyre\core::dialback('CALL_STORAGE_FRAMEWORK_LIFECYCLE_BEFORE_APPLY', $payload);
		if(is_array($dialback)){
			return $dialback;
		}
		$driver=$this->disk($name);
		if(!method_exists($driver, 'applyLifecycle')){
			$result=['ok'=>false, 'dry_run'=>false, 'eligible'=>0, 'deleted'=>0, 'paths'=>[], 'message'=>'Disk does not support lifecycle application.'];
			$dialback=\dataphyre\core::dialback('CALL_STORAGE_FRAMEWORK_LIFECYCLE_AFTER_APPLY', $payload+['ok'=>false, 'unsupported'=>true, 'counts'=>['eligible'=>0, 'deleted'=>0]]);
			return is_array($dialback) ? $dialback : $result;
		}
		$result=$driver->applyLifecycle($prefix, $options);
		$dialback=\dataphyre\core::dialback('CALL_STORAGE_FRAMEWORK_LIFECYCLE_AFTER_APPLY', $payload+[
			'ok'=>(bool)($result['ok'] ?? false),
			'unsupported'=>false,
			'counts'=>[
				'eligible'=>(int)($result['eligible'] ?? 0),
				'deleted'=>(int)($result['deleted'] ?? 0),
				'errors'=>is_countable($result['errors'] ?? null) ? count($result['errors']) : 0,
			],
		]);
		return is_array($dialback) ? $dialback : $result;
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
	public function scanReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'scanReport')){
			return ['ok'=>false, 'objects'=>0, 'clean'=>0, 'blocked'=>0, 'message'=>'Disk does not support scan reporting.'];
		}
		return $driver->scanReport($prefix, $options);
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
	public function purgeQuarantine(string $prefix='', ?string $disk=null, array $options=[]): array {
		$name=$this->diskName($disk);
		$prefix=trim(str_replace('\\', '/', $prefix), '/');
		$payload=[
			'disk'=>$name,
			'prefix'=>$prefix,
			'options_keys'=>array_values(array_map('strval', array_keys($options))),
		];
		$dialback=\dataphyre\core::dialback('CALL_STORAGE_FRAMEWORK_QUARANTINE_BEFORE_PURGE', $payload);
		if(is_array($dialback)){
			return $dialback;
		}
		$driver=$this->disk($name);
		if(!method_exists($driver, 'purgeQuarantine')){
			$result=['ok'=>false, 'purged'=>0, 'message'=>'Disk does not support quarantine purging.'];
			$dialback=\dataphyre\core::dialback('CALL_STORAGE_FRAMEWORK_QUARANTINE_AFTER_PURGE', $payload+['ok'=>false, 'unsupported'=>true, 'counts'=>['purged'=>0]]);
			return is_array($dialback) ? $dialback : $result;
		}
		$result=$driver->purgeQuarantine($prefix, $options);
		$dialback=\dataphyre\core::dialback('CALL_STORAGE_FRAMEWORK_QUARANTINE_AFTER_PURGE', $payload+[
			'ok'=>(bool)($result['ok'] ?? false),
			'unsupported'=>false,
			'counts'=>[
				'purged'=>(int)($result['purged'] ?? 0),
				'errors'=>is_countable($result['errors'] ?? null) ? count($result['errors']) : 0,
			],
		]);
		return is_array($dialback) ? $dialback : $result;
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
	public function tagObject(string $path, ?string $disk=null, array $options=[]): bool {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'tagObject')){
			return false;
		}
		return $driver->tagObject($path, $options);
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
	public function tagsFor(string $path, ?string $disk=null): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'tagsFor')){
			return ['tags'=>[], 'metadata'=>[]];
		}
		return $driver->tagsFor($path);
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
	public function findByTags(array $tags, ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'findByTags')){
			return [];
		}
		return $driver->findByTags($tags, $options);
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
	public function tagReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'tagReport')){
			return ['ok'=>false, 'objects'=>0, 'tags'=>[], 'message'=>'Disk does not support tag reporting.'];
		}
		return $driver->tagReport($prefix, $options);
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
	public function policyReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'policyReport')){
			return ['ok'=>false, 'rules'=>[], 'message'=>'Disk does not support policy reporting.'];
		}
		return $driver->policyReport($prefix, $options);
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
	public function rateLimitReport(string $prefix='', ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'rateLimitReport')){
			return ['ok'=>false, 'limits'=>[], 'buckets'=>[], 'message'=>'Disk does not support rate-limit reporting.'];
		}
		return $driver->rateLimitReport($prefix, $options);
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
	public function resetRateLimits(string $prefix='', ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'resetRateLimits')){
			return ['ok'=>false, 'reset'=>false, 'message'=>'Disk does not support rate-limit reset.'];
		}
		return $driver->resetRateLimits($prefix, $options);
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
	public function eventTrail(?string $path=null, ?string $disk=null, array $options=[]): array {
		$driver=$this->disk($disk);
		if(!method_exists($driver, 'eventTrail')){
			return [];
		}
		return $driver->eventTrail($path, $options);
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
	public function manifestReport(?string $disk=null): array {
		$manifests=$this->configuredManifests($disk);
		$out=[];
		foreach($manifests as $name=>$manifest){
			$out[$name]=[
				'disk'=>$manifest['disk'],
				'key'=>$manifest['key'],
				'path'=>$manifest['path'],
				'exists'=>is_file($manifest['path']),
				'bytes'=>is_file($manifest['path']) ? filesize($manifest['path']) : 0,
				'updated_at'=>is_file($manifest['path']) ? filemtime($manifest['path']) : null,
			];
		}
		return ['ok'=>true, 'manifests'=>$out];
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
	public function exportManifests(?string $disk=null, array $options=[]): array {
		$bundle=[
			'format'=>'dataphyre-storage-manifests',
			'version'=>1,
			'exported_at'=>time(),
			'manifests'=>[],
		];
		foreach($this->configuredManifests($disk) as $name=>$manifest){
			$bundle['manifests'][$name]=[
				'disk'=>$manifest['disk'],
				'key'=>$manifest['key'],
				'path'=>$manifest['path'],
				'data'=>is_file($manifest['path']) ? $this->readJsonFile($manifest['path']) : null,
			];
		}
		if(isset($options['path']) && is_string($options['path']) && $options['path']!==''){
			$this->writeJsonFile($options['path'], $bundle);
		}
		return $bundle;
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
	public function importManifests(array|string $bundle, array $options=[]): array {
		if(is_string($bundle)){
			$bundle=$this->readJsonFile($bundle);
		}
		if(($bundle['format'] ?? '')!=='dataphyre-storage-manifests' || !is_array($bundle['manifests'] ?? null)){
			return ['ok'=>false, 'imported'=>0, 'message'=>'Invalid storage manifest bundle.'];
		}
		$configured=$this->configuredManifests(null);
		$mode=(string)($options['mode'] ?? 'replace');
		$imported=0;
		foreach($bundle['manifests'] as $name=>$entry){
			if(!is_array($entry) || !array_key_exists($name, $configured)){
				continue;
			}
			$data=$entry['data'] ?? null;
			if($data===null){
				continue;
			}
			$path=$configured[$name]['path'];
			if($mode==='merge' && is_file($path)){
				$current=$this->readJsonFile($path);
				if(is_array($current) && is_array($data)){
					$data=array_replace_recursive($current, $data);
				}
			}
			$this->writeJsonFile($path, $data);
			$imported++;
		}
		return ['ok'=>true, 'imported'=>$imported];
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
	public function diagnostics(?string $disk=null, array $options=[]): array {
		$names=$disk!==null ? [$this->diskName($disk)] : array_keys((array)$this->config('disks', []));
		if($names===[]){
			$names=[$this->diskName(null)];
		}
		$results=[];
		foreach($names as $name){
			$name=$this->diskName((string)$name);
			$results[$name]=$this->diagnoseDisk($name, $options);
		}
		$ok=true;
		foreach($results as $result){
			$ok=$ok && ($result['ok'] ?? false)===true;
		}
		return ['ok'=>$ok, 'checked'=>count($results), 'disks'=>$results];
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
	public function sync(string $fromDisk, string $toDisk, string $prefix='', array $options=[]): array {
		$from=$this->diskName($fromDisk);
		$to=$this->diskName($toDisk);
		$prefix=trim(str_replace('\\', '/', $prefix), '/');
		$dryRun=(bool)($options['dry_run'] ?? true);
		$deleteExtra=(bool)($options['delete_extra'] ?? false);
		$compare=(string)($options['compare'] ?? 'checksum');
		$payload=[
			'from'=>$from,
			'to'=>$to,
			'prefix'=>$prefix,
			'dry_run'=>$dryRun,
			'delete_extra'=>$deleteExtra,
			'compare'=>$compare,
			'options_keys'=>array_values(array_map('strval', array_keys($options))),
		];
		$dialback=\dataphyre\core::dialback('CALL_STORAGE_FRAMEWORK_SYNC_BEFORE', $payload);
		if(is_array($dialback)){
			return $dialback;
		}
		$source=$this->indexMetadata($this->list($prefix, $from));
		$target=$this->indexMetadata($this->list($prefix, $to));
		$copied=[];
		$updated=[];
		$skipped=[];
		$deleted=[];
		$failed=[];
		foreach($source as $path=>$sourceMetadata){
			$targetMetadata=$target[$path] ?? null;
			if($targetMetadata instanceof FileMetadata && !$this->needsSync($path, $from, $to, $sourceMetadata, $targetMetadata, $compare)){
				$skipped[]=$path;
				continue;
			}
			$operation=$targetMetadata instanceof FileMetadata ? 'updated' : 'copied';
			if(!$dryRun && $this->copy($path, $path, $from, $to, $options)!==true){
				$failed[]=['path'=>$path, 'operation'=>$operation];
				continue;
			}
			${$operation}[]=$path;
		}
		if($deleteExtra){
			foreach($target as $path=>$metadata){
				if(isset($source[$path])){
					continue;
				}
				if(!$dryRun && $this->delete($path, $to)!==true){
					$failed[]=['path'=>$path, 'operation'=>'delete'];
					continue;
				}
				$deleted[]=$path;
			}
		}
		$result=[
			'ok'=>$failed===[],
			'dry_run'=>$dryRun,
			'from'=>$from,
			'to'=>$to,
			'prefix'=>$prefix,
			'copied'=>$copied,
			'updated'=>$updated,
			'skipped'=>$skipped,
			'deleted'=>$deleted,
			'failed'=>$failed,
			'counts'=>[
				'copied'=>count($copied),
				'updated'=>count($updated),
				'skipped'=>count($skipped),
				'deleted'=>count($deleted),
				'failed'=>count($failed),
			],
		];
		$this->emit('storage.sync', $result);
		$dialback=\dataphyre\core::dialback('CALL_STORAGE_FRAMEWORK_SYNC_AFTER', $payload+[
			'ok'=>$result['ok'],
			'counts'=>$result['counts'],
		]);
		return is_array($dialback) ? $dialback : $result;
	}

	/**
	 * Clears all in-memory fake storage.
	 *
	 * This affects `MemoryDriver` state globally for the current process and is intended for
	 * tests or isolated workers.
	 *
	 * @return void
	 */
	public function fakeFlush(): void {
		MemoryDriver::flush();
	}

	/**
	 * Returns the current in-memory fake storage snapshot.
	 *
	 * The snapshot exposes `MemoryDriver` state for assertions and diagnostics without resolving
	 * any configured disk.
	 *
	 * @return array<string,mixed> In-memory fake driver snapshot grouped by disk, object path, metadata, and stored content.
	 */
	public function fakeSnapshot(): array {
		return MemoryDriver::snapshot();
	}

	/**
	 * Resolves a caller-provided disk name to the canonical configured disk key.
	 *
	 * Null selects the configured default disk, names are normalized with
	 * the same rules used for drivers and manifests, and empty results fall back to
	 * the local disk so facade calls always have a deterministic target.
	 *
	 * @param ?string $disk Caller-supplied disk name, or null for the configured default.
	 * @return string Canonical disk key used for config lookup and driver caching.
	 */
	private function diskName(?string $disk): string {
		$disk=$this->normalizeName((string)($disk ?? $this->config('default_disk', 'local')));
		return $disk!=='' ? $disk : 'local';
	}

	/**
	 * Indexes listed file metadata by normalized storage path.
	 *
	 * Sync compares source and target listings by path only, ignoring
	 * non-metadata list entries and sorting keys so dry-run and report output remain
	 * deterministic across driver implementations.
	 *
	 * @param array<int|string,mixed> $items Driver listing output.
	 * @return array<string,FileMetadata> Metadata entries keyed by object path.
	 */
	private function indexMetadata(array $items): array {
		$out=[];
		foreach($items as $item){
			if($item instanceof FileMetadata){
				$out[$item->path()]=$item;
			}
		}
		ksort($out);
		return $out;
	}

	/**
	 * Decides whether a source object should replace the target during sync.
	 *
	 * Size differences always sync, size-only comparison stops there,
	 * checksum comparison is preferred when both disks can be read, and modified
	 * timestamps are used as the fallback when either checksum cannot be produced.
	 *
	 * @param string $path Storage path present in the source listing.
	 * @param string $from Canonical source disk name.
	 * @param string $to Canonical target disk name.
	 * @param FileMetadata $source Source metadata row.
	 * @param FileMetadata $target Target metadata row.
	 * @param string $compare Comparison mode, currently `checksum` or `size`.
	 * @return bool True when the source object should be copied to the target.
	 */
	private function needsSync(string $path, string $from, string $to, FileMetadata $source, FileMetadata $target, string $compare): bool {
		if($source->size()!==null && $target->size()!==null && $source->size()!==$target->size()){
			return true;
		}
		if($compare==='size'){
			return false;
		}
		$sourceHash=$this->checksum($path, $from);
		$targetHash=$this->checksum($path, $to);
		if($sourceHash===false || $targetHash===false){
			return $source->modifiedAt()!==null && $target->modifiedAt()!==null && $source->modifiedAt()>$target->modifiedAt();
		}
		return !hash_equals($sourceHash, $targetHash);
	}

	/**
	 * Returns the configuration array for a canonical disk name.
	 *
	 * Missing or malformed disk entries become an empty config with the
	 * disk name injected, allowing factory resolution and diagnostics to report
	 * clear driver errors instead of undefined-index noise.
	 *
	 * @param string $name Canonical disk name.
	 * @return array<string,mixed> Disk configuration with `name` populated.
	 */
	private function diskConfig(string $name): array {
		$disks=$this->config('disks', []);
		$config=is_array($disks) && is_array($disks[$name] ?? null) ? $disks[$name] : [];
		$config['name']=$name;
		return $config;
	}

	/**
	 * Reads a storage configuration value by direct or dotted key.
	 *
	 * DP_STORAGE_CFG is the authoritative runtime config source. Direct
	 * keys win before dotted traversal so literal config names remain supported
	 * alongside nested convenience lookups.
	 *
	 * @param string $key Direct key or dot-separated config path.
	 * @param mixed $default Value returned when the key path is absent.
	 * @return mixed Configured value or supplied default.
	 */
	private function config(string $key, mixed $default=null): mixed {
		$config=defined('DP_STORAGE_CFG') && is_array(DP_STORAGE_CFG) ? DP_STORAGE_CFG : [];
		if(array_key_exists($key, $config)){
			return $config[$key];
		}
		$current=$config;
		foreach(explode('.', $key) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return $default;
			}
			$current=$current[$segment];
		}
		return $current;
	}

	/**
	 * Lists manifest and log files declared by configured disks.
	 *
	 * Disk filtering uses canonical disk names, only string paths are
	 * reported, and manifest keys are returned as disk.key pairs so import/export
	 * can safely map bundles back onto known configured files.
	 *
	 * @param ?string $disk Optional disk filter.
	 * @return array<string,array{disk:string,key:string,path:string}> Manifest descriptors keyed by `disk.key`.
	 */
	private function configuredManifests(?string $disk): array {
		$disks=$this->config('disks', []);
		if(!is_array($disks)){
			return [];
		}
		$only=$disk!==null ? $this->diskName($disk) : null;
		$out=[];
		foreach($disks as $name=>$config){
			if(!is_array($config)){
				continue;
			}
			$name=$this->normalizeName((string)$name);
			if($only!==null && $name!==$only){
				continue;
			}
			foreach(['manifest', 'log'] as $key){
				if(isset($config[$key]) && is_string($config[$key]) && $config[$key]!==''){
					$out[$name.'.'.$key]=['disk'=>$name, 'key'=>$key, 'path'=>$config[$key]];
				}
			}
		}
		return $out;
	}

	/**
	 * Runs health probes against one configured disk.
	 *
	 * Diagnostics verify driver instantiation, list support, optional
	 * write/read/delete round trips, temporary URL support, manifest visibility, and
	 * configuration warnings while returning structured evidence for each check.
	 *
	 * @param string $name Canonical disk name.
	 * @param array<string,mixed> $options Diagnostic options such as prefix and write probing.
	 * @return array<string,mixed> Disk health report with checks, warnings, and optional error text.
	 */
	private function diagnoseDisk(string $name, array $options=[]): array {
		$config=$this->diskConfig($name);
		$driver=(string)($config['driver'] ?? $name);
		$result=[
			'ok'=>true,
			'disk'=>$name,
			'driver'=>$driver,
			'checks'=>[],
			'warnings'=>$this->configWarnings($config),
		];
		try{
			$this->disk($name);
			$result['checks']['instantiate']=true;
		}
		catch(\Throwable $exception){
			$result['ok']=false;
			$result['checks']['instantiate']=false;
			$result['error']=$exception->getMessage();
			return $result;
		}
		try{
			$list=$this->list((string)($options['prefix'] ?? ''), $name, ['limit'=>1]);
			$result['checks']['list']=is_array($list);
		}
		catch(\Throwable $exception){
			$result['checks']['list']=false;
			$result['warnings'][]='List probe failed: '.$exception->getMessage();
		}
		$probeWrites=(bool)($options['write'] ?? $options['probe_write'] ?? true);
		if($probeWrites){
			$path=$this->probePath((string)($options['probe_prefix'] ?? '_dataphyre_health'));
			$probeBody='dataphyre-storage-health-'.bin2hex(random_bytes(6));
			$written=$this->put($path, $probeBody, $name, ['max_bytes'=>0]);
			$result['checks']['write']=$written;
			$result['checks']['read']=$written && $this->get($path, $name) === $probeBody;
			$result['checks']['delete']=$written ? $this->delete($path, $name) : false;
		}
		$temporaryUrl=$this->temporaryUrl('__dataphyre_health_missing__', time()+60, $name);
		$result['checks']['temporary_url_supported']=$temporaryUrl!==false;
		$manifestReport=$this->manifestReport($name);
		$result['checks']['manifests']=count($manifestReport['manifests'] ?? []);
		foreach($result['checks'] as $check=>$value){
			if(in_array($check, ['temporary_url_supported', 'manifests'], true)){
				continue;
			}
			if($value===false){
				$result['ok']=false;
			}
		}
		return $result;
	}

	/**
	 * Builds non-fatal warnings for risky or incomplete disk configuration.
	 *
	 * Warnings cover S3-compatible credential gaps, missing manifest
	 * directories, and enabled encryption without key material; they inform
	 * diagnostics without preventing disk construction.
	 *
	 * @param array<string,mixed> $config Disk configuration.
	 * @return list<string> Human-readable warning messages.
	 */
	private function configWarnings(array $config): array {
		$warnings=[];
		$driver=(string)($config['driver'] ?? '');
		if(in_array($driver, ['s3', 's3_compatible', 'r2', 'minio'], true)){
			foreach(['endpoint', 'bucket', 'access_key', 'secret_key'] as $key){
				if(($config[$key] ?? null)===null || (string)($config[$key] ?? '')===''){
					$warnings[]="Missing {$key}.";
				}
			}
		}
		if(isset($config['manifest']) && is_string($config['manifest'])){
			$dir=dirname($config['manifest']);
			if(!is_dir($dir)){
				$warnings[]='Manifest directory does not exist yet.';
			}
		}
		if(is_array($config['encryption'] ?? null) && ($config['encryption']['enabled'] ?? false)===true){
			if(empty($config['encryption']['key']) && empty($config['encryption']['key_file'])){
				$warnings[]='Encryption is enabled without a key or key_file.';
			}
		}
		return $warnings;
	}

	/**
	 * Generates a unique health-check object path under an optional prefix.
	 *
	 * Diagnostic write probes are namespaced, timestamped, and randomized
	 * so they avoid colliding with application objects and can be deleted after the
	 * probe completes.
	 *
	 * @param string $prefix Optional storage prefix for health-check objects.
	 * @return string Probe object path.
	 */
	private function probePath(string $prefix): string {
		$prefix=trim(str_replace('\\', '/', $prefix), '/');
		return ($prefix!=='' ? $prefix.'/' : '').date('YmdHis').'-'.bin2hex(random_bytes(8)).'.txt';
	}

	/**
	 * Reads a manifest JSON file with raw-content fallback.
	 *
	 * Missing files return null, valid JSON returns decoded data, and
	 * invalid JSON is preserved under _raw so manifest export does not destroy
	 * operator-visible evidence of corrupt or legacy files.
	 *
	 * @param string $path Local manifest file path.
	 * @return mixed Decoded JSON, raw fallback data, or null when absent.
	 */
	private function readJsonFile(string $path): mixed {
		if(!is_file($path)){
			return null;
		}
		$contents=(string)file_get_contents($path);
		$decoded=json_decode($contents, true);
		if(json_last_error()===JSON_ERROR_NONE){
			return $decoded;
		}
		return ['_raw'=>$contents];
	}

	/**
	 * Writes structured manifest data as pretty JSON with directory creation.
	 *
	 * Manifest import/export writes are lock-protected best-effort file
	 * operations; parent directories are created when possible and the boolean
	 * result reflects the final file write only.
	 *
	 * @param string $path Local manifest file path.
	 * @param mixed $data JSON-serializable manifest data.
	 * @return bool True when the file write succeeds.
	 */
	private function writeJsonFile(string $path, mixed $data): bool {
		$dir=dirname($path);
		if(!is_dir($dir)){
			@mkdir($dir, 0775, true);
		}
		return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)!==false;
	}

	/**
	 * Converts driver, disk, and manifest identifiers to storage-safe tokens.
	 *
	 * Non-alphanumeric separators collapse to underscores, leading and
	 * trailing separators are removed, and lowercase output keeps config lookup
	 * stable across user-provided names.
	 *
	 * @param string $name Raw driver, disk, or manifest identifier.
	 * @return string Lowercase normalized token.
	 */
	private function normalizeName(string $name): string {
		return strtolower(trim(preg_replace('/[^a-zA-Z0-9_]+/', '_', $name) ?? $name, '_'));
	}

	/**
	 * Enforces manager-level write constraints before content reaches a driver.
	 *
	 * Write guards apply max-byte limits, allowed extensions, and allowed
	 * MIME types from options or disk config. A false result blocks writes and
	 * temporary upload flows without invoking backend drivers.
	 *
	 * @param string $path Destination storage path.
	 * @param mixed $contents String bytes or readable stream.
	 * @param ?string $disk Disk name, or null for the configured default.
	 * @param array<string,mixed> $options Write or signing options.
	 * @return bool True when manager-level write constraints allow the operation.
	 */
	private function guardWrite(string $path, mixed $contents, ?string $disk, array $options): bool {
		$name=$this->diskName($disk);
		$config=$this->diskConfig($name);
		$maxBytes=(int)($options['max_bytes'] ?? $config['max_bytes'] ?? 0);
		if($maxBytes>0){
			$size=is_resource($contents) ? $this->streamSize($contents) : strlen((string)$contents);
			if($size!==null && $size>$maxBytes){
				return false;
			}
		}
		$extensions=$options['allowed_extensions'] ?? $config['allowed_extensions'] ?? [];
		if(is_array($extensions) && $extensions!==[]){
			$extension=strtolower(pathinfo($path, PATHINFO_EXTENSION));
			$allowed=array_map(static fn(mixed $value): string => strtolower(ltrim((string)$value, '.')), $extensions);
			if(!in_array($extension, $allowed, true)){
				return false;
			}
		}
		$mime=$options['content_type'] ?? null;
		$mimes=$options['allowed_mime_types'] ?? $config['allowed_mime_types'] ?? [];
		if(is_array($mimes) && $mimes!==[] && is_string($mime) && !in_array($mime, $mimes, true)){
			return false;
		}
		return true;
	}

	/**
	 * Reads a stream size without consuming stream contents.
	 *
	 * guardWrite uses this for max-byte checks on resource contents; null
	 * means the stream is not inspectable and the driver remains responsible for
	 * any backend-specific size enforcement.
	 *
	 * @param mixed $stream Candidate stream resource.
	 * @return ?int Stream byte size, or null when unavailable.
	 */
	private function streamSize(mixed $stream): ?int {
		if(!is_resource($stream)){
			return null;
		}
		$stat=fstat($stream);
		return isset($stat['size']) ? (int)$stat['size'] : null;
	}
}
