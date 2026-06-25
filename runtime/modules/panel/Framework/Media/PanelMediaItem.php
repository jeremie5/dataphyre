<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Normalized representation of one uploaded or managed Panel media file.
 *
 * Media items capture collection, disk, storage path, sanitized filenames, MIME information, visibility, URL, variants,
 * metadata, and validation state as a JSON-safe value. The object is used after upload validation and before persistence or
 * rendering so Panel resources can reason about files without carrying raw upload arrays around.
 */
final class PanelMediaItem implements \JsonSerializable {

	private string $id;
	private string $collection;
	private string $disk;
	private string $path;
	private string $filename;
	private string $originalName;
	private string $mime;
	private string $extension;
	private int $size;
	private string $visibility;
	private ?string $url;
	private array $variants;
	private array $metadata;
	private array $validation;

	/**
	 * Normalizes loose media attributes into the stable media item shape.
	 *
	 * Collection, disk, and visibility use resource-style normalized names; filenames are stripped of separators and unsafe
	 * characters; missing MIME values are guessed from extension; and missing identifiers are derived from the storage
	 * coordinates and file metadata.
	 *
	 * @param array<string, mixed> $attributes Media fields from uploads, persistence, or collection defaults.
	 */
	public function __construct(array $attributes=[]) {
		$this->collection=Resource::normalizeName((string)($attributes['collection'] ?? 'default')) ?: 'default';
		$this->disk=Resource::normalizeName((string)($attributes['disk'] ?? 'local')) ?: 'local';
		$this->visibility=Resource::normalizeName((string)($attributes['visibility'] ?? 'private')) ?: 'private';
		$this->originalName=self::cleanFilename((string)($attributes['original_name'] ?? $attributes['name'] ?? $attributes['filename'] ?? 'file'));
		$this->extension=strtolower((string)($attributes['extension'] ?? pathinfo($this->originalName, PATHINFO_EXTENSION)));
		$this->filename=self::cleanFilename((string)($attributes['filename'] ?? self::storedFilename($this->originalName, $this->extension)));
		$this->mime=strtolower(trim((string)($attributes['mime'] ?? $attributes['type'] ?? self::guessMime($this->extension))));
		$this->size=max(0, (int)($attributes['size'] ?? 0));
		$this->path=trim((string)($attributes['path'] ?? $this->collection.'/'.$this->filename), "\\/");
		$this->url=isset($attributes['url']) && is_string($attributes['url']) && trim($attributes['url'])!=='' ? trim($attributes['url']) : null;
		$this->variants=is_array($attributes['variants'] ?? null) ? $attributes['variants'] : [];
		$this->metadata=is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [];
		$this->validation=is_array($attributes['validation'] ?? null) ? $attributes['validation'] : ['ok'=>true, 'errors'=>[], 'warnings'=>[]];
		$this->id=(string)($attributes['id'] ?? substr(sha1($this->collection.'|'.$this->disk.'|'.$this->path.'|'.$this->size.'|'.$this->mime), 0, 24));
	}

	/**
	 * Builds a media item from an upload-style file array and optional collection context.
	 *
	 * A PanelMediaCollection contributes item defaults, collection name, and validation output. Array collection input is
	 * treated as default attributes, while string input sets only the collection name. Explicit attributes always win last.
	 *
	 * @param array<string, mixed> $file Upload-style file payload with name, filename, type, mime, size, or error keys.
	 * @param PanelMediaCollection|array<string, mixed>|string|null $collection Collection context or defaults.
	 * @param array<string, mixed> $attributes Explicit media attributes.
	 * @return self Normalized media item.
	 */
	public static function from(array $file, PanelMediaCollection|array|string|null $collection=null, array $attributes=[]): self {
		if($collection instanceof PanelMediaCollection){
			$attributes=array_merge($collection->itemDefaults(), $attributes);
			$attributes['collection']=$collection->name();
			$attributes['validation']=$collection->validate($file);
		}
		elseif(is_array($collection)){
			$attributes=array_merge($collection, $attributes);
		}
		elseif(is_string($collection) && trim($collection)!==''){
			$attributes['collection']=$collection;
		}
		return new self(array_merge([
			'original_name'=>$file['name'] ?? $file['filename'] ?? null,
			'mime'=>$file['type'] ?? $file['mime'] ?? null,
			'size'=>$file['size'] ?? 0,
			'error'=>$file['error'] ?? UPLOAD_ERR_OK,
		], $attributes));
	}

	/**
	 * Returns the stable media item identifier.
	 *
	 * @return string Caller-provided id or deterministic constructor fallback.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Returns the normalized media collection name.
	 *
	 * @return string Collection name, defaulting to default.
	 */
	public function collection(): string {
		return $this->collection;
	}

	/**
	 * Returns the normalized storage disk name.
	 *
	 * @return string Disk name, defaulting to local.
	 */
	public function disk(): string {
		return $this->disk;
	}

	/**
	 * Returns the trimmed storage path relative to the configured disk.
	 *
	 * @return string Storage path without leading or trailing separators.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Returns the sanitized stored filename.
	 *
	 * @return string Filename safe for storage path composition.
	 */
	public function filename(): string {
		return $this->filename;
	}

	/**
	 * Returns the sanitized original filename shown to operators.
	 *
	 * @return string Original upload name after separator and character cleanup.
	 */
	public function originalName(): string {
		return $this->originalName;
	}

	/**
	 * Returns the normalized MIME type.
	 *
	 * @return string Lowercase MIME type, guessed from extension when missing.
	 */
	public function mime(): string {
		return $this->mime;
	}

	/**
	 * Returns the lowercase filename extension.
	 *
	 * @return string Extension without leading dot.
	 */
	public function extension(): string {
		return $this->extension;
	}

	/**
	 * Returns the non-negative file size in bytes.
	 *
	 * @return int Size in bytes.
	 */
	public function size(): int {
		return $this->size;
	}

	/**
	 * Returns the normalized storage visibility.
	 *
	 * @return string Visibility name such as private or public.
	 */
	public function visibility(): string {
		return $this->visibility;
	}

	/**
	 * Returns the optional public or signed media URL.
	 *
	 * @return ?string URL supplied by storage or persistence, or null when unavailable.
	 */
	public function url(): ?string {
		return $this->url;
	}

	/**
	 * Returns derived media variants such as thumbnails or transcoded renditions.
	 *
	 * @return array<string, mixed> Variant metadata keyed by variant name.
	 */
	public function variants(): array {
		return $this->variants;
	}

	/**
	 * Returns caller-defined metadata attached to the media item.
	 *
	 * @return array<string, mixed> Metadata map preserved for persistence and rendering.
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * Returns validation output from the media collection or upload pipeline.
	 *
	 * @return array<string, mixed> Validation payload, conventionally containing ok, errors, and warnings.
	 */
	public function validation(): array {
		return $this->validation;
	}

	/**
	 * Reports whether validation marked the media item as acceptable.
	 *
	 * @return bool True unless validation.ok is explicitly false.
	 */
	public function valid(): bool {
		return ($this->validation['ok'] ?? true)===true;
	}

	/**
	 * Reports whether Panel can show an inline preview for the media type.
	 *
	 * @return bool True for image, video, audio, PDF, SVG, WebP, and common raster image extensions.
	 */
	public function previewable(): bool {
		if(str_starts_with($this->mime, 'image/') || str_starts_with($this->mime, 'video/') || str_starts_with($this->mime, 'audio/')){
			return true;
		}
		return in_array($this->extension, ['pdf', 'svg', 'webp', 'jpg', 'jpeg', 'png', 'gif'], true);
	}

	/**
	 * Exports the canonical media item payload for persistence, APIs, and Panel clients.
	 *
	 * @return array{id: string, collection: string, disk: string, path: string, filename: string, original_name: string, mime: string, extension: string, size: int, visibility: string, url: ?string, previewable: bool, variants: array<string, mixed>, metadata: array<string, mixed>, validation: array<string, mixed>}
	 */
	public function toArray(): array {
		return [
			'id'=>$this->id,
			'collection'=>$this->collection,
			'disk'=>$this->disk,
			'path'=>$this->path,
			'filename'=>$this->filename,
			'original_name'=>$this->originalName,
			'mime'=>$this->mime,
			'extension'=>$this->extension,
			'size'=>$this->size,
			'visibility'=>$this->visibility,
			'url'=>$this->url,
			'previewable'=>$this->previewable(),
			'variants'=>$this->variants,
			'metadata'=>$this->metadata,
			'validation'=>$this->validation,
		];
	}

	/**
	 * Serializes the canonical media item payload for JSON output.
	 *
	 * @return array{id: string, collection: string, disk: string, path: string, filename: string, original_name: string, mime: string, extension: string, size: int, visibility: string, url: ?string, previewable: bool, variants: array<string, mixed>, metadata: array<string, mixed>, validation: array<string, mixed>}
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Sanitizes a filename for safe storage and display.
	 *
	 * Null bytes and path separators are replaced before non-portable characters collapse to dashes. Empty results fall
	 * back to file so downstream path construction never receives an empty filename.
	 *
	 * @param string $filename Raw filename.
	 * @return string Sanitized filename.
	 */
	private static function cleanFilename(string $filename): string {
		$filename=trim(str_replace(["\0", "\\", "/"], '-', $filename));
		$filename=preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $filename) ?: 'file';
		return trim($filename, '.-') ?: 'file';
	}

	/**
	 * Generates a collision-resistant stored filename from the original name.
	 *
	 * The random suffix avoids reusing user-supplied names directly while preserving enough of the original basename for
	 * operator diagnostics.
	 *
	 * @param string $original Sanitized original filename.
	 * @param string $extension Lowercase extension without leading dot.
	 * @return string Generated stored filename.
	 */
	private static function storedFilename(string $original, string $extension): string {
		$base=pathinfo($original, PATHINFO_FILENAME);
		$base=self::cleanFilename($base);
		$suffix=substr(sha1($original.'|'.microtime(true).'|'.random_int(1, PHP_INT_MAX)), 0, 10);
		return $extension!=='' ? $base.'-'.$suffix.'.'.$extension : $base.'-'.$suffix;
	}

	/**
	 * Guesses a MIME type for common Panel preview and upload extensions.
	 *
	 * @param string $extension File extension without leading dot.
	 * @return string MIME type, defaulting to application/octet-stream.
	 */
	private static function guessMime(string $extension): string {
		return match(strtolower($extension)){
			'jpg', 'jpeg'=>'image/jpeg',
			'png'=>'image/png',
			'gif'=>'image/gif',
			'webp'=>'image/webp',
			'svg'=>'image/svg+xml',
			'pdf'=>'application/pdf',
			'csv'=>'text/csv',
			'json'=>'application/json',
			'txt'=>'text/plain',
			'mp4'=>'video/mp4',
			'mp3'=>'audio/mpeg',
			default=>'application/octet-stream',
		};
	}
}
