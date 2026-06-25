<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Builder and manifest value for a panel media collection.
 *
 * A collection describes where uploaded media is stored, which files are accepted,
 * how previews and variants are generated, and what defaults are applied to
 * PanelMediaItem instances. It is configuration state only; persistence and file
 * movement happen in the upload/storage layer that consumes the manifest.
 */
final class PanelMediaCollection implements \JsonSerializable {

	private string $name;
	private string $label;
	private string $disk='local';
	private string $path='media/{collection}';
	private string $visibility='private';
	private bool $multiple=false;
	private array $acceptedTypes=[];
	private int $minSize=0;
	private int $maxSize=0;
	private array $variants=[];
	private array $cleanupPolicy=[];
	private array $metadata=[];
	private array $previewTypes=['image/*', 'video/*', 'audio/*', 'application/pdf'];
	private $validator=null;

	/**
	 * Creates a media collection with normalized name and human label.
	 *
	 * @param string $name Collection identifier used in paths and manifests.
	 */
	public function __construct(string $name='default') {
		$this->name=Resource::normalizeName($name) ?: 'default';
		$this->label=self::humanLabel($this->name);
	}

	/**
	 * Creates a media collection builder.
	 *
	 * @param string $name Collection identifier.
	 * @return self New collection builder.
	 */
	public static function make(string $name='default'): self {
		return new self($name);
	}

	/**
	 * Normalizes a collection definition into a builder instance.
	 *
	 * Array definitions may include name, label, disk, path, visibility, multiple,
	 * accepted_types, min_size, max_size, variants, cleanup, and metadata keys.
	 *
	 * @param array<string, mixed>|string|self $definition Collection definition.
	 * @return self Normalized collection builder.
	 */
	public static function from(array|string|self $definition): self {
		if($definition instanceof self){
			return $definition;
		}
		if(is_string($definition)){
			return self::make($definition);
		}
		$collection=self::make((string)($definition['name'] ?? 'default'));
		if(isset($definition['label'])){
			$collection=$collection->label((string)$definition['label']);
		}
		if(isset($definition['disk'])){
			$collection=$collection->disk((string)$definition['disk']);
		}
		if(isset($definition['path'])){
			$collection=$collection->path((string)$definition['path']);
		}
		if(isset($definition['visibility'])){
			$collection=$collection->visibility((string)$definition['visibility']);
		}
		if(isset($definition['multiple'])){
			$collection=$collection->multiple((bool)$definition['multiple']);
		}
		if(isset($definition['accepted_types'])){
			$collection=$collection->accept($definition['accepted_types']);
		}
		if(isset($definition['min_size'])){
			$collection=$collection->minSize((int)$definition['min_size']);
		}
		if(isset($definition['max_size'])){
			$collection=$collection->maxSize((int)$definition['max_size']);
		}
		if(is_array($definition['variants'] ?? null)){
			foreach($definition['variants'] as $name=>$variant){
				$collection=$collection->variant((string)$name, is_array($variant) ? $variant : []);
			}
		}
		if(is_array($definition['cleanup'] ?? null)){
			$collection=$collection->cleanup($definition['cleanup']);
		}
		if(is_array($definition['metadata'] ?? null)){
			$collection=$collection->meta($definition['metadata']);
		}
		return $collection;
	}

	/**
	 * Returns the normalized collection name.
	 *
	 * @return string Collection identifier.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Gets or sets the operator-facing collection label.
	 *
	 * @param string|null $label Label to set, or null to read the current label.
	 * @return string|self Current label when reading, or this collection when setting.
	 */
	public function label(?string $label=null): string|self {
		if($label===null){
			return $this->label;
		}
		$this->label=trim($label) ?: self::humanLabel($this->name);
		return $this;
	}

	/**
	 * Sets the storage disk identifier.
	 *
	 * @param string $disk Storage disk name consumed by the upload layer.
	 * @return self Same collection builder.
	 */
	public function disk(string $disk): self {
		$this->disk=Resource::normalizeName($disk) ?: 'local';
		return $this;
	}

	/**
	 * Sets the storage path template for uploaded media.
	 *
	 * The {collection} token is resolved to the collection name in resolvedPath().
	 *
	 * @param string $path Path template relative to the configured disk.
	 * @return self Same collection builder.
	 */
	public function path(string $path): self {
		$path=trim($path);
		$this->path=$path!=='' ? trim($path, "\\/") : 'media/{collection}';
		return $this;
	}

	/**
	 * Sets media visibility policy.
	 *
	 * Unsupported values are normalized to private.
	 *
	 * @param string $visibility public, protected, or private.
	 * @return self Same collection builder.
	 */
	public function visibility(string $visibility): self {
		$this->visibility=match(Resource::normalizeName($visibility)){
			'public'=>'public',
			'protected'=>'protected',
			default=>'private',
		};
		return $this;
	}

	/**
	 * Convenience setter for public/private visibility.
	 *
	 * @param bool $public True for public visibility, false for private visibility.
	 * @return self Same collection builder.
	 */
	public function public(bool $public=true): self {
		return $this->visibility($public ? 'public' : 'private');
	}

	/**
	 * Controls whether the collection accepts multiple files.
	 *
	 * @param bool $enabled Whether multiple uploads are allowed.
	 * @return self Same collection builder.
	 */
	public function multiple(bool $enabled=true): self {
		$this->multiple=$enabled;
		return $this;
	}

	/**
	 * Sets accepted MIME types or extensions.
	 *
	 * Accepted values may be exact MIME types, wildcard MIME groups such as image/*,
	 * bare extensions, dot-prefixed extensions, or wildcard-all for all files.
	 *
	 * @param array<int, mixed>|string $types Accepted types list or comma-separated string.
	 * @return self Same collection builder.
	 */
	public function accept(array|string $types): self {
		$this->acceptedTypes=array_values(array_filter(array_map(
			static fn(mixed $type): string => strtolower(trim((string)$type)),
			is_array($types) ? $types : (preg_split('/\s*,\s*/', $types) ?: [])
		)));
		return $this;
	}

	/**
	 * Restricts accepted files to image MIME types.
	 *
	 * @return self Same collection builder.
	 */
	public function images(): self {
		return $this->accept(['image/*']);
	}

	/**
	 * Restricts accepted files to common document MIME types.
	 *
	 * @return self Same collection builder.
	 */
	public function documents(): self {
		return $this->accept(['application/pdf', 'text/plain', 'text/csv', 'application/json']);
	}

	/**
	 * Sets the minimum allowed uploaded file size.
	 *
	 * @param int $bytes Minimum size in bytes; negative values are clamped to zero.
	 * @return self Same collection builder.
	 */
	public function minSize(int $bytes): self {
		$this->minSize=max(0, $bytes);
		return $this;
	}

	/**
	 * Sets the maximum allowed uploaded file size.
	 *
	 * @param int $bytes Maximum size in bytes; zero disables the upper bound.
	 * @return self Same collection builder.
	 */
	public function maxSize(int $bytes): self {
		$this->maxSize=max(0, $bytes);
		return $this;
	}

	/**
	 * Registers a derived media variant definition.
	 *
	 * Variant definitions are stored in the manifest for downstream processors that
	 * generate thumbnails, responsive sizes, or alternate formats.
	 *
	 * @param string $name Variant identifier.
	 * @param array<string, mixed> $definition Processor-specific variant settings.
	 * @return self Same collection builder.
	 */
	public function variant(string $name, array $definition=[]): self {
		$name=Resource::normalizeName($name);
		if($name!==''){
			$this->variants[$name]=array_merge(['name'=>$name], $definition);
		}
		return $this;
	}

	/**
	 * Sets cleanup policy metadata for stored media.
	 *
	 * @param array<string, mixed> $policy Retention or orphan-cleanup policy consumed by storage jobs.
	 * @return self Same collection builder.
	 */
	public function cleanup(array $policy): self {
		$this->cleanupPolicy=$policy;
		return $this;
	}

	/**
	 * Adds arbitrary collection metadata.
	 *
	 * @param array<string, mixed>|string $key Metadata map or single key.
	 * @param mixed $value Value used when key is a string.
	 * @return self Same collection builder.
	 */
	public function meta(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			foreach($key as $name=>$metaValue){
				$this->metadata[(string)$name]=$metaValue;
			}
			return $this;
		}
		$this->metadata[$key]=$value;
		return $this;
	}

	/**
	 * Sets MIME types that panel clients can preview inline.
	 *
	 * @param array<int, mixed>|string $types Previewable types list or comma-separated string.
	 * @return self Same collection builder.
	 */
	public function previewTypes(array|string $types): self {
		$this->previewTypes=array_values(array_filter(array_map(
			static fn(mixed $type): string => strtolower(trim((string)$type)),
			is_array($types) ? $types : (preg_split('/\s*,\s*/', $types) ?: [])
		)));
		return $this;
	}

	/**
	 * Registers a custom validation callback.
	 *
	 * The callback receives file and collection context via PanelUtilityResolver and
	 * may return an errors/warnings array or a single error string.
	 *
	 * @param callable|null $validator Custom validator, or null to clear it.
	 * @return self Same collection builder.
	 */
	public function validateUsing(?callable $validator): self {
		$this->validator=$validator;
		return $this;
	}

	/**
	 * Validates an uploaded file descriptor against the collection policy.
	 *
	 * The file payload follows PHP upload conventions: name/filename, type/mime,
	 * size, and error. The returned payload separates blocking errors from
	 * non-blocking warnings for panel UI and API consumers.
	 *
	 * @param array<string, mixed> $file Uploaded file descriptor.
	 * @return array{ok: bool, errors: array<int, string>, warnings: array<int, string>, size: int, accepted: array<int, string>} Validation result.
	 */
	public function validate(array $file): array {
		$errors=[];
		$warnings=[];
		$error=(int)($file['error'] ?? UPLOAD_ERR_OK);
		if($error!==UPLOAD_ERR_OK){
			$errors[]='Upload failed: '.self::uploadError($error).'.';
		}
		$size=max(0, (int)($file['size'] ?? 0));
		if($this->minSize>0 && $size<$this->minSize){
			$errors[]='File is smaller than '.self::format_bytes($this->minSize).'.';
		}
		if($this->maxSize>0 && $size>$this->maxSize){
			$errors[]='File is larger than '.self::format_bytes($this->maxSize).'.';
		}
		if($this->acceptedTypes!==[] && !self::fileAccepted($file, $this->acceptedTypes)){
			$errors[]='File type is not accepted by the '.$this->label.' collection.';
		}
		$name=(string)($file['name'] ?? $file['filename'] ?? '');
		if(trim($name)===''){
			$warnings[]='File has no original filename.';
		}
		if($this->validator!==null){
			$result=PanelUtilityResolver::evaluate($this->validator, [
				'file'=>$file,
				'collection'=>$this,
				'errors'=>$errors,
				'warnings'=>$warnings,
			], ['file', 'collection']);
			if(is_array($result)){
				foreach((array)($result['errors'] ?? []) as $message){
					$errors[]=(string)$message;
				}
				foreach((array)($result['warnings'] ?? []) as $message){
					$warnings[]=(string)$message;
				}
			}
			elseif(is_string($result) && trim($result)!==''){
				$errors[]=$result;
			}
		}
		return [
			'ok'=>$errors===[],
			'errors'=>$errors,
			'warnings'=>$warnings,
			'size'=>$size,
			'accepted'=>$this->acceptedTypes,
		];
	}

	/**
	 * Creates a media item value using this collection's defaults.
	 *
	 * @param array<string, mixed> $file File descriptor or stored media payload.
	 * @param array<string, mixed> $attributes Additional item attributes.
	 * @return PanelMediaItem Media item associated with this collection.
	 */
	public function item(array $file, array $attributes=[]): PanelMediaItem {
		return PanelMediaItem::from($file, $this, $attributes);
	}

	/**
	 * Returns defaults applied to media items in this collection.
	 *
	 * @return array<string, mixed> Collection, disk, path, visibility, variants, and metadata defaults.
	 */
	public function itemDefaults(): array {
		return [
			'collection'=>$this->name,
			'disk'=>$this->disk,
			'path'=>$this->resolvedPath(),
			'visibility'=>$this->visibility,
			'variants'=>$this->variants,
			'metadata'=>$this->metadata,
		];
	}

	/**
	 * Returns the collection manifest consumed by panel clients.
	 *
	 * @return array<string, mixed> Serializable collection configuration.
	 */
	public function manifest(): array {
		return $this->toArray();
	}

	/**
	 * Serializes collection configuration and validation policy.
	 *
	 * @return array<string, mixed> Manifest payload for panel clients and diagnostics.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'disk'=>$this->disk,
			'path'=>$this->path,
			'resolved_path'=>$this->resolvedPath(),
			'visibility'=>$this->visibility,
			'multiple'=>$this->multiple,
			'accepted_types'=>$this->acceptedTypes,
			'min_size'=>$this->minSize,
			'max_size'=>$this->maxSize,
			'variants'=>$this->variants,
			'cleanup'=>$this->cleanupPolicy,
			'metadata'=>$this->metadata,
			'preview_types'=>$this->previewTypes,
			'has_custom_validator'=>$this->validator!==null,
		];
	}

	/**
	 * Serializes the collection for JSON encoding.
	 *
	 * @return array<string, mixed> Manifest payload.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Resolves the storage path template for this collection.
	 *
	 * @return string Storage path with {collection} replaced by name().
	 */
	private function resolvedPath(): string {
		return str_replace('{collection}', $this->name, $this->path);
	}

	/**
	 * Checks whether a file descriptor matches accepted MIME/extension rules.
	 *
	 * @param array<string, mixed> $file Uploaded file descriptor.
	 * @param array<int, string> $accepted Accepted MIME types, wildcards, or extensions.
	 * @return bool True when the file matches at least one accepted rule.
	 */
	private static function fileAccepted(array $file, array $accepted): bool {
		$mime=strtolower((string)($file['type'] ?? $file['mime'] ?? ''));
		$name=strtolower((string)($file['name'] ?? $file['filename'] ?? ''));
		$extension=pathinfo($name, PATHINFO_EXTENSION);
		foreach($accepted as $type){
			$type=strtolower(trim((string)$type));
			if($type===''){
				continue;
			}
			if($type==='*' || $type==='*/*'){
				return true;
			}
			if(str_starts_with($type, '.')){
				if($extension!=='' && '.'.$extension===$type){
					return true;
				}
				continue;
			}
			if(str_ends_with($type, '/*')){
				$prefix=substr($type, 0, -1);
				if($mime!=='' && str_starts_with($mime, $prefix)){
					return true;
				}
				continue;
			}
			if($mime!=='' && $mime===$type){
				return true;
			}
			if($extension!=='' && $type===$extension){
				return true;
			}
		}
		return false;
	}

	/**
	 * Converts a collection name into a readable label.
	 *
	 * @param string $name Normalized collection name.
	 * @return string Human-readable label.
	 */
	private static function humanLabel(string $name): string {
		$name=trim(str_replace(['-', '_'], ' ', $name));
		return $name==='' ? 'Default' : ucwords($name);
	}

	/**
	 * Formats a byte count for validation messages.
	 *
	 * @param int $bytes Byte count.
	 * @return string Human-readable size.
	 */
	private static function formatBytes(int $bytes): string {
		if($bytes>=1048576){
			return round($bytes / 1048576, 2).' MB';
		}
		if($bytes>=1024){
			return round($bytes / 1024, 2).' KB';
		}
		return $bytes.' B';
	}

	/**
	 * Maps PHP upload error codes to panel-facing messages.
	 *
	 * @param int $error PHP UPLOAD_ERR_* code.
	 * @return string Human-readable upload failure reason.
	 */
	private static function uploadError(int $error): string {
		return match($error){
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE=>'file is too large',
			UPLOAD_ERR_PARTIAL=>'upload was incomplete',
			UPLOAD_ERR_NO_FILE=>'no file was selected',
			UPLOAD_ERR_NO_TMP_DIR=>'temporary directory is missing',
			UPLOAD_ERR_CANT_WRITE=>'server could not write the file',
			UPLOAD_ERR_EXTENSION=>'a PHP extension stopped the upload',
			default=>'unknown upload error',
		};
	}
}
