<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Immutable view over normalized environment values.
 *
 * The snapshot captures a key/value map at the moment it is created so
 * diagnostics and configuration readers can inspect runtime
 * environment state without re-reading process globals. Scoping derives a new
 * snapshot with shorter keys while preserving the composed prefix path.
 */
final class EnvSnapshot implements \JsonSerializable {

	private ?array $arrayPayload=null;

	/**
	 * Captures environment values for inspection and scoped reads.
	 *
	 * The constructor stores values as provided; callers are responsible for any
	 * redaction or source precedence before constructing the snapshot. Prefixes
	 * describe the logical scope already applied to the value map.
	 *
	 * @param ?string $prefix Logical prefix represented by this snapshot, or null for a root snapshot.
	 * @param string $separator Separator used when composing nested prefixes.
	 * @param array<string, mixed> $values Environment values keyed by normalized name.
	 */
	public function __construct(
		private readonly ?string $prefix,
		private readonly string $separator='/',
		private readonly array $values=[]
	){}

	/**
	 * Reads the logical prefix represented by this snapshot.
	 *
	 * @return ?string Prefix path, or null when the snapshot represents root values.
	 */
	public function prefix(): ?string {
		return $this->prefix;
	}

	/**
	 * Reads the separator used for nested environment scopes.
	 *
	 * @return string Separator used to compose prefix paths.
	 */
	public function separator(): string {
		return $this->separator;
	}

	/**
	 * Returns every value captured by the snapshot.
	 *
	 * The returned array is a copy-on-write PHP array; changing it does not alter
	 * the immutable snapshot.
	 *
	 * @return array<string, mixed> Captured environment values keyed by normalized name.
	 */
	public function all(): array {
		return $this->values;
	}

	/**
	 * Reads one captured value by exact key.
	 *
	 * Keys are trimmed before lookup. Blank keys and missing keys return the
	 * caller-provided default without consulting process environment state.
	 *
	 * @param string $key Snapshot key to read.
	 * @param mixed $default Value returned when the key is blank or absent.
	 * @return mixed captured snapshot value, including null, or the caller default when blank/absent.
	 */
	public function get(string $key, mixed $default=null): mixed {
		$key=trim($key);
		return $key!=='' && array_key_exists($key, $this->values) ? $this->values[$key] : $default;
	}

	/**
	 * Indicates whether the snapshot contains values or one exact key.
	 *
	 * With an empty key this reports whether the snapshot has any values at all.
	 * With a non-empty key it tests presence with `array_key_exists`, so stored
	 * null values still count as present.
	 *
	 * @param string $key Optional key to test after trimming.
	 * @return bool True when the snapshot is non-empty or contains the requested key.
	 */
	public function has(string $key=''): bool {
		$key=trim($key);
		if($key===''){
			return $this->values!==[];
		}
		return array_key_exists($key, $this->values);
	}

	/**
	 * Selects a subset of captured values by exact key.
	 *
	 * Candidate keys are trimmed and ignored when blank or absent. Returned keys
	 * keep their original normalized names from this snapshot.
	 *
	 * @param array<int|string, mixed> $keys Keys to preserve.
	 * @return array<string, mixed> Selected values keyed by normalized name.
	 */
	public function only(array $keys): array {
		$selected=[];
		foreach($keys as $key){
			$key=trim((string)$key);
			if($key==='' || !array_key_exists($key, $this->values)){
				continue;
			}
			$selected[$key]=$this->values[$key];
		}
		return $selected;
	}

	/**
	 * Returns captured values except for the requested keys.
	 *
	 * Keys are trimmed before removal. Missing keys are ignored, and stored null
	 * values remain present unless their key is explicitly excluded.
	 *
	 * @param array<int|string, mixed> $keys Keys to remove from the returned map.
	 * @return array<string, mixed> Captured values with requested keys removed.
	 */
	public function except(array $keys): array {
		$values=$this->values;
		foreach($keys as $key){
			unset($values[trim((string)$key)]);
		}
		return $values;
	}

	/**
	 * Lists keys captured in this snapshot.
	 *
	 * @return array<int, string> Snapshot keys in their current array order.
	 */
	public function keys(): array {
		return array_keys($this->values);
	}

	/**
	 * Indicates whether this snapshot contains no captured values.
	 *
	 * @return bool True when the value map is empty.
	 */
	public function isEmpty(): bool {
		return $this->values===[];
	}

	/**
	 * Derives a nested snapshot scoped to keys under a prefix.
	 *
	 * The requested prefix is trimmed of separators and whitespace. Matching keys
	 * must start with `prefix + separator`; the returned snapshot strips that
	 * prefix from each key and composes the logical prefix metadata.
	 *
	 * @param ?string $prefix Nested prefix to select from the current snapshot.
	 * @return self Current snapshot when the prefix is blank, otherwise a scoped snapshot.
	 */
	public function scope(?string $prefix): self {
		$prefix=static::normalizePrefix($prefix, $this->separator);
		if($prefix===null){
			return $this;
		}
		$scoped=[];
		$scopePrefix=$prefix.$this->separator;
		foreach($this->values as $key=>$value){
			if(!is_string($key) || !str_starts_with($key, $scopePrefix)){
				continue;
			}
			$scoped[substr($key, strlen($scopePrefix))]=$value;
		}
		return new self($this->composePrefix($prefix), $this->separator, $scoped);
	}

	/**
	 * Serializes snapshot metadata and values for diagnostics.
	 *
	 * Values are emitted exactly as captured. Callers that expose this payload
	 * outside trusted diagnostics must redact secrets before creating the
	 * snapshot or before rendering the serialized array.
	 *
	 * @return array{prefix: ?string, separator: string, values: array<string, mixed>} Snapshot payload.
	 */
	public function toArray(): array {
		return $this->arrayPayload ??= [
			'prefix'=>$this->prefix,
			'separator'=>$this->separator,
			'values'=>$this->values,
		];
	}

	/**
	 * Exposes the snapshot payload to json_encode().
	 *
	 * @return array{prefix: ?string, separator: string, values: array<string, mixed>} Snapshot payload.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Composes this snapshot prefix with a nested prefix.
	 *
	 * @param string $prefix Normalized nested prefix.
	 * @return string Combined logical prefix path.
	 */
	private function composePrefix(string $prefix): string {
		if($this->prefix===null || $this->prefix===''){
			return $prefix;
		}
		return $this->prefix.$this->separator.$prefix;
	}

	/**
	 * Normalizes an optional scope prefix.
	 *
	 * Separators and surrounding whitespace are trimmed so callers can pass
	 * human-friendly prefix strings without creating empty scope segments.
	 *
	 * @param ?string $prefix Prefix candidate supplied by the caller.
	 * @param string $separator Separator to trim from both ends of the prefix.
	 * @return ?string Normalized prefix, or null when no scoping should occur.
	 */
	private static function normalizePrefix(?string $prefix, string $separator): ?string {
		if(!is_string($prefix)){
			return null;
		}
		$prefix=trim($prefix, $separator." \t\n\r\0\x0B");
		return $prefix!=='' ? $prefix : null;
	}
}
