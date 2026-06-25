<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage;

/**
 * Describes storage object metadata without reading or mutating file contents.
 *
 * FileMetadata is the common value object returned by storage drivers for
 * local files, remote objects, mirrors, and cached listings. It keeps portable
 * facts in first-class fields and leaves provider-specific headers, checksums,
 * etags, visibility, or adapter notes in the extra metadata array.
 */
final class FileMetadata {

	/**
	 * Stores metadata reported by a storage driver.
	 *
	 * Null size, modified time, or MIME type means the driver could not provide
	 * that fact cheaply or reliably. The path is stored as supplied by the
	 * driver so virtual prefixes and remote keys are preserved exactly.
	 *
	 * @param string $path Storage-relative path, remote key, or virtual object identifier.
	 * @param ?int $size File size in bytes, or null when unknown.
	 * @param ?int $modifiedAt Unix timestamp for last modification, or null when unknown.
	 * @param ?string $mimeType MIME type reported or inferred by the driver.
	 * @param array<string,mixed> $extra Provider-specific serializable metadata.
	 */
	public function __construct(
		private string $path,
		private ?int $size=null,
		private ?int $modifiedAt=null,
		private ?string $mimeType=null,
		private array $extra=[]
	) {
	}

	/**
	 * Rehydrates metadata from serialized storage metadata.
	 *
	 * This accepts the shape emitted by toArray(). Missing scalar fields become
	 * null except path, which defaults to an empty string to preserve the current
	 * constructor contract for callers that build placeholder metadata.
	 * Expected keys are `path`, `size`, `modified_at`, `mime_type`, and `extra`.
	 *
	 * @param array<string,mixed> $data Serialized metadata row.
	 * @return self Metadata value normalized from serialized storage data.
	 */
	public static function fromArray(array $data): self {
		return new self(
			(string)($data['path'] ?? ''),
			isset($data['size']) ? (int)$data['size'] : null,
			isset($data['modified_at']) ? (int)$data['modified_at'] : null,
			isset($data['mime_type']) ? (string)$data['mime_type'] : null,
			is_array($data['extra'] ?? null) ? $data['extra'] : []
		);
	}

	/**
	 * Returns the storage path or object key.
	 *
	 * @return string Driver-reported object identifier.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Returns the object size when known.
	 *
	 * @return ?int Size in bytes, or null for drivers/listings that do not expose it.
	 */
	public function size(): ?int {
		return $this->size;
	}

	/**
	 * Returns the last-modified timestamp when known.
	 *
	 * @return ?int Unix timestamp, or null when unavailable.
	 */
	public function modifiedAt(): ?int {
		return $this->modifiedAt;
	}

	/**
	 * Returns the MIME type when the driver can provide or infer one.
	 *
	 * @return ?string MIME type string, or null when unknown.
	 */
	public function mimeType(): ?string {
		return $this->mimeType;
	}

	/**
	 * Returns provider-specific metadata.
	 *
	 * The extra metadata array is deliberately open-ended. Storage drivers use it for
	 * details such as checksum, etag, visibility, adapter name, remote headers,
	 * cache state, or replication status without expanding the stable metadata
	 * constructor for every backend.
	 *
	 * @return array<string, mixed> Serializable provider-specific metadata.
	 */
	public function extra(): array {
		return $this->extra;
	}

	/**
	 * Serializes metadata for manifests, cache entries, and diagnostics.
	 *
	 * The key names use snake_case because storage metadata crosses kernel-style
	 * modules, JSON manifests, and legacy Dataphyre callers.
	 *
	 * @return array{path:string,size:?int,modified_at:?int,mime_type:?string,extra:array<string,mixed>}
	 */
	public function toArray(): array {
		return [
			'path'=>$this->path,
			'size'=>$this->size,
			'modified_at'=>$this->modifiedAt,
			'mime_type'=>$this->mimeType,
			'extra'=>$this->extra,
		];
	}
}
