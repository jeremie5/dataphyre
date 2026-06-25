<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Immutable view over a configuration value or subtree.
 *
 * A snapshot keeps the resolved config path, whether that path existed, and the
 * captured value. Callers can read the whole value, traverse nested arrays with
 * slash-delimited keys, project selected paths, exclude paths, and serialize the
 * snapshot for diagnostics without mutating the original config payload.
 */
final class ConfigSnapshot implements \JsonSerializable {

	private ?array $arrayPayload=null;

	/**
	 * Creates a configuration snapshot.
	 *
	 * @param ?string $path Slash-delimited path represented by this snapshot.
	 * @param bool $exists Whether the path existed when the snapshot was created.
	 * @param mixed $value Captured config value for the path.
	 */
	public function __construct(
		private readonly ?string $path,
		private readonly bool $exists,
		private readonly mixed $value
	){}

	/**
	 * Returns the slash-delimited config path represented by this snapshot.
	 *
	 * @return ?string Snapshot path, or null for the root config.
	 */
	public function path(): ?string {
		return $this->path;
	}

	/**
	 * Reports whether the snapshot's path existed when resolved.
	 *
	 * @return bool True when the snapshot represents an existing config path.
	 */
	public function exists(): bool {
		return $this->exists;
	}

	/**
	 * Returns the captured value or a fallback when the snapshot is missing.
	 *
	 * @param mixed $default Value returned when the snapshot path did not exist.
	 * @return mixed Captured value, or the provided default.
	 */
	public function value(mixed $default=null): mixed {
		return $this->exists ? $this->value : $default;
	}

	/**
	 * Reads a nested value from the snapshot using slash-delimited path segments.
	 *
	 * Blank keys return the snapshot value itself. Non-array snapshots and
	 * missing nested paths return the caller-provided default.
	 *
	 * @param string $key Slash-delimited nested config path.
	 * @param mixed $default Value returned when the nested path is missing.
	 * @return mixed Nested config value, or the provided default.
	 */
	public function get(string $key, mixed $default=null): mixed {
		$key=trim($key);
		if($key===''){
			return $this->value($default);
		}
		if(!is_array($this->value)){
			return $default;
		}
		$found=static::getPathString($this->value, $key, $exists);
		return $exists ? $found : $default;
	}

	/**
	 * Checks whether the snapshot or one of its nested paths exists.
	 *
	 * A blank key checks the snapshot existence flag. Non-blank keys require the
	 * captured value to be an array and the full slash-delimited path to exist.
	 *
	 * @param string $key Optional slash-delimited nested config path.
	 * @return bool True when the requested path exists.
	 */
	public function has(string $key=''): bool {
		$key=trim($key);
		if($key===''){
			return $this->exists;
		}
		if(!is_array($this->value)){
			return false;
		}
		static::getPathString($this->value, $key, $exists);
		return $exists;
	}

	/**
	 * Returns the captured value as an array.
	 *
	 * Non-array snapshots return an empty array so projection helpers can be used
	 * safely against scalar config values.
	 *
	 * @return array<string|int, mixed> Captured array value, or an empty array.
	 */
	public function all(): array {
		return is_array($this->value) ? $this->value : [];
	}

	/**
	 * Projects selected nested paths from the snapshot.
	 *
	 * Returned keys match the requested path strings rather than rebuilding a
	 * nested tree. Missing or blank keys are ignored.
	 *
	 * @param array<int, string> $keys Slash-delimited config paths to include.
	 * @return array<string, mixed> Selected values keyed by requested path.
	 */
	public function only(array $keys): array {
		$selected=[];
		foreach($keys as $key){
			$key=trim((string)$key);
			if($key==='' || !$this->has($key)){
				continue;
			}
			$selected[$key]=$this->get($key);
		}
		return $selected;
	}

	/**
	 * Returns the snapshot array with selected nested paths removed.
	 *
	 * Removal operates on a copy of `all()`, so the snapshot remains immutable.
	 *
	 * @param array<int, string> $keys Slash-delimited config paths to remove.
	 * @return array<string|int, mixed> Snapshot array after exclusions.
	 */
	public function except(array $keys): array {
		$config=$this->all();
		foreach($keys as $key){
			static::unsetPath($config, static::segments((string)$key));
		}
		return $config;
	}

	/**
	 * Returns top-level keys from the snapshot array.
	 *
	 * @return array<int, string|int> Top-level config keys.
	 */
	public function keys(): array {
		return array_keys($this->all());
	}

	/**
	 * Checks whether the snapshot is absent or contains an empty value.
	 *
	 * Missing snapshots are empty. Array snapshots are empty only when the array
	 * has no entries, while scalar snapshots are empty only when null.
	 *
	 * @return bool True when the snapshot should be treated as empty.
	 */
	public function isEmpty(): bool {
		if(!$this->exists){
			return true;
		}
		if(is_array($this->value)){
			return $this->value===[];
		}
		return $this->value===null;
	}

	/**
	 * Creates a nested snapshot scoped below the current path.
	 *
	 * Null or blank paths return the current snapshot. Non-blank paths compose
	 * onto the current snapshot path and carry their own existence flag/value.
	 *
	 * @param ?string $path Slash-delimited nested path.
	 * @return self Current snapshot for blank paths, or a nested immutable snapshot.
	 */
	public function scope(?string $path): self {
		$path=static::normalizePath($path);
		if($path===null){
			return $this;
		}
		$exists=$this->has($path);
		return new self(
			$this->composePath($path),
			$exists,
			$this->get($path)
		);
	}

	/**
	 * Exports the snapshot as a diagnostics-friendly array.
	 *
	 * @return array{path: ?string, exists: bool, value: mixed} Snapshot payload.
	 */
	public function toArray(): array {
		return $this->arrayPayload ??= [
			'path'=>$this->path,
			'exists'=>$this->exists,
			'value'=>$this->value,
		];
	}

	/**
	 * Serializes the captured config path, existence flag, and value.
	 *
	 * @return array{path: ?string, exists: bool, value: mixed} Snapshot payload.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Appends a normalized child key to the current snapshot path.
	 *
	 * @param string $key Child path.
	 * @return string Composed slash-delimited path.
	 */
	private function composePath(string $key): string {
		$key=trim($key, '/');
		if($this->path===null || $this->path===''){
			return $key;
		}
		return $this->path.'/'.$key;
	}

	/**
	 * Normalizes a path by trimming whitespace and slashes.
	 *
	 * @param ?string $path Raw path.
	 * @return ?string Normalized non-empty path, or null.
	 */
	private static function normalizePath(?string $path): ?string {
		if(!is_string($path)){
			return null;
		}
		$path=trim($path, " \t\n\r\0\x0B/");
		return $path!=='' ? $path : null;
	}

	/**
	 * Splits a slash-delimited path into non-empty segments.
	 *
	 * @param string $path Slash-delimited path.
	 * @return array<int, string> Path segments.
	 */
	private static function segments(string $path): array {
		$segments=[];
		foreach(explode('/', trim($path)) as $segment){
			if($segment!==''){
				$segments[]=$segment;
			}
		}
		return $segments;
	}

	/**
	 * Reads a nested value from an array and reports whether it existed.
	 *
	 * Array keys are checked with `array_key_exists()` so null values still count
	 * as existing config entries.
	 *
	 * @param array<string|int, mixed> $value Source array.
	 * @param array<int, string> $path Path segments.
	 * @param ?bool $exists Output flag set to true when the path exists.
	 * @return mixed Nested value, or null when missing.
	 */
	private static function getPath(array $value, array $path, ?bool &$exists=false): mixed {
		if($path===[]){
			$exists=true;
			return $value;
		}
		$current=$value;
		foreach($path as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				$exists=false;
				return null;
			}
			$current=$current[$segment];
		}
		$exists=true;
		return $current;
	}

	/**
	 * Reads a nested value from an array using a slash-delimited path string.
	 *
	 * Empty path segments are skipped without materializing a separate segment list.
	 *
	 * @param array<string|int, mixed> $value Source array.
	 * @param string $path Slash-delimited path.
	 * @param ?bool $exists Output flag set to true when the path exists.
	 * @return mixed Nested value, or null when missing.
	 */
	private static function getPathString(array $value, string $path, ?bool &$exists=false): mixed {
		$current=$value;
		foreach(explode('/', $path) as $segment){
			if($segment===''){
				continue;
			}
			if(!is_array($current) || !array_key_exists($segment, $current)){
				$exists=false;
				return null;
			}
			$current=$current[$segment];
		}
		$exists=true;
		return $current;
	}

	/**
	 * Removes a nested path from an array copy.
	 *
	 * Missing branches and scalar branches are ignored.
	 *
	 * @param array<string|int, mixed> $value Array being modified.
	 * @param array<int, string> $path Path segments to remove.
	 * @return void
	 */
	private static function unsetPath(array &$value, array $path): void {
		if($path===[]){
			return;
		}
		$key=array_shift($path);
		if($key===null || !array_key_exists($key, $value)){
			return;
		}
		if($path===[]){
			unset($value[$key]);
			return;
		}
		if(!is_array($value[$key])){
			return;
		}
		static::unsetPath($value[$key], $path);
	}
}
