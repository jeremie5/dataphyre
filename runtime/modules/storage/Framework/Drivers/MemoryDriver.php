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
use Dataphyre\Storage\Support\Path;
use Dataphyre\Storage\Support\Stream;

/**
 * In-process storage driver for ephemeral objects and tests.
 *
 * Objects are stored in a static array shared by all MemoryDriver instances in
 * the current PHP process. Instance prefixes namespace logical paths, while
 * bodies and metadata remain process-local and disappear on `flush()` or process
 * shutdown.
 */
final class MemoryDriver implements StorageDriver {

	/** @var array<string, array{body:string, metadata:array}> */
	private static array $objects=[];

	/**
	 * Creates a memory-backed storage driver.
	 *
	 * Supported config currently includes `prefix`, which is prepended to every
	 * normalized path handled by this instance.
	 *
	 * @param array<string, mixed> $config Memory driver configuration.
	 */
	public function __construct(private array $config=[]) {
	}

	/**
	 * Checks whether a prefixed object exists in the process-local store.
	 *
	 * @param string $path Logical object path.
	 * @return bool True when an object exists at the resolved memory path.
	 */
	public function exists(string $path): bool {
		return isset(self::$objects[$this->path($path)]);
	}

	/**
	 * Reads an object body from the process-local store.
	 *
	 * @param string $path Logical object path.
	 * @param array<string, mixed> $options Reserved read options.
	 * @return string|false Object body, or false when missing.
	 */
	public function read(string $path, array $options=[]): string|false {
		$object=self::$objects[$this->path($path)] ?? null;
		return is_array($object) ? $object['body'] : false;
	}

	/**
	 * Opens an in-memory stream for an object body.
	 *
	 * @param string $path Logical object path.
	 * @param array<string, mixed> $options Reserved stream options.
	 * @return mixed Readable stream resource, or false when missing.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		$body=$this->read($path, $options);
		return is_string($body) ? Stream::fromString($body) : false;
	}

	/**
	 * Stores an object body and metadata in memory.
	 *
	 * Streams are materialized to strings. `content_type` becomes metadata MIME
	 * type, and all other options are preserved under metadata `extra`.
	 *
	 * @param string $path Logical object path.
	 * @param mixed $contents Stringable contents or readable stream.
	 * @param array<string, mixed> $options Write options captured as metadata.
	 * @return bool True when the object was stored.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$path=$this->path($path);
		$body=is_resource($contents) ? Stream::contents($contents) : (string)$contents;
		if($path==='' || $body===false){
			return false;
		}
		self::$objects[$path]=[
			'body'=>$body,
			'metadata'=>[
				'path'=>$path,
				'size'=>strlen($body),
				'modified_at'=>time(),
				'mime_type'=>is_string($options['content_type'] ?? null) ? $options['content_type'] : null,
				'extra'=>array_diff_key($options, array_flip(['content_type'])),
			],
		];
		return true;
	}

	/**
	 * Removes an object from the process-local store.
	 *
	 * @param string $path Logical object path.
	 * @return bool Always true after the resolved path has been unset.
	 */
	public function delete(string $path): bool {
		unset(self::$objects[$this->path($path)]);
		return true;
	}

	/**
	 * Returns metadata for an in-memory object.
	 *
	 * @param string $path Logical object path.
	 * @return FileMetadata|false Stored metadata, or false when missing.
	 */
	public function metadata(string $path): FileMetadata|false {
		$object=self::$objects[$this->path($path)] ?? null;
		return is_array($object) ? FileMetadata::fromArray($object['metadata']) : false;
	}

	/**
	 * Lists in-memory objects under an optional prefix.
	 *
	 * Listing is sorted by resolved path and includes objects whose path matches
	 * the prefix exactly or is below it.
	 *
	 * @param string $prefix Optional logical path prefix.
	 * @param array<string, mixed> $options Reserved listing options.
	 * @return array<int, FileMetadata> Metadata entries sorted by path.
	 */
	public function list(string $prefix='', array $options=[]): array {
		$prefix=$this->path($prefix);
		$out=[];
		foreach(self::$objects as $path=>$object){
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			$out[]=FileMetadata::fromArray($object['metadata']);
		}
		usort($out, static fn(FileMetadata $a, FileMetadata $b): int => strcmp($a->path(), $b->path()));
		return $out;
	}

	/**
	 * Returns a synthetic memory URL for an existing in-memory object.
	 *
	 * The URL is diagnostic only; it is not backed by an external HTTP server.
	 *
	 * @param string $path Logical object path.
	 * @param int|\DateTimeInterface $expires Expiration timestamp or date object encoded into the URL.
	 * @param array<string, mixed> $options Reserved URL options.
	 * @return string|false Synthetic `memory://` URL, or false when the object is missing.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		if(!$this->exists($path)){
			return false;
		}
		$expires=$expires instanceof \DateTimeInterface ? $expires->getTimestamp() : $expires;
		return 'memory://'.rawurlencode($this->path($path)).'?expires='.(int)$expires;
	}

	/**
	 * Clears every object from the shared process-local memory store.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$objects=[];
	}

	/**
	 * Returns the raw process-local memory object table.
	 *
	 * @return array<string, array{body:string, metadata:array}> Stored objects keyed by resolved path.
	 */
	public static function snapshot(): array {
		return self::$objects;
	}

	/**
	 * Resolves a logical path into this instance's prefixed memory path.
	 *
	 * @param string $path Logical object path.
	 * @return string Normalized path inside the shared memory table.
	 */
	private function path(string $path): string {
		$prefix=Path::normalize((string)($this->config['prefix'] ?? ''));
		$path=Path::normalize($path);
		return $prefix==='' ? $path : Path::normalize($prefix.'/'.$path);
	}
}
