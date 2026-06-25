<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

/**
 * Immutable result object returned by sanitation and validation pipelines.
 *
 * The result keeps sanitized data, validation errors, and original input separate so callers can
 * safely consume cleaned values, inspect failed fields, compare raw input, or throw a contextual
 * SanitizationException when validation did not pass.
 */
final class SanitizationResult {

	/**
	 * Creates a sanitation result from sanitized data, errors, and original input.
	 *
	 * @param array<string,mixed> $data Sanitized and validated output values.
	 * @param array<string,string|list<string>> $errors Error messages keyed by field/path.
	 * @param array<string,mixed> $input Original unsanitized input values.
	 */
	public function __construct(
		private readonly array $data,
		private readonly array $errors,
		private readonly array $input=[]
	){}

	/** @var list<string>|null */
	private ?array $onlyKeysPayload=null;

	/** @var array<string,mixed>|null */
	private ?array $onlyPayload=null;

	/** @var list<string>|null */
	private ?array $exceptKeysPayload=null;

	/** @var array<string,mixed>|null */
	private ?array $exceptPayload=null;

	/** @var array<string,list<string>> */
	private static array $pathSegmentCache=[];

	/**
	 * Reports whether validation completed without errors.
	 *
	 * @return bool True when the error bag is empty.
	 */
	public function passed(): bool {
		return $this->errors===[];
	}

	/**
	 * Alias for passed().
	 *
	 * @return bool True when the error bag is empty.
	 */
	public function passes(): bool {
		return $this->passed();
	}

	/**
	 * Reports whether validation produced any errors.
	 *
	 * @return bool True when at least one field/path has an error.
	 */
	public function failed(): bool {
		return !$this->passed();
	}

	/**
	 * Alias for failed().
	 *
	 * @return bool True when at least one field/path has an error.
	 */
	public function fails(): bool {
		return $this->failed();
	}

	/**
	 * Returns all sanitized data.
	 *
	 * @return array<string,mixed> Sanitized output values.
	 */
	public function all(): array {
		return $this->data;
	}

	/**
	 * Returns all sanitized data using validation terminology.
	 *
	 * @return array<string,mixed> Sanitized output values.
	 */
	public function validated(): array {
		return $this->data;
	}

	/**
	 * Returns all sanitized data.
	 *
	 * @return array<string,mixed> Sanitized output values.
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Returns validation errors.
	 *
	 * @return array<string,string|list<string>> Error messages keyed by field/path.
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Returns validation error messages.
	 *
	 * @return array<string,string|list<string>> Error messages keyed by field/path.
	 */
	public function messages(): array {
		return $this->errors;
	}

	/**
	 * Returns all errors or one error entry.
	 *
	 * @param ?string $key Error key to fetch, or null for the full error bag.
	 * @return string|array|null Full error bag, one error entry, or null when the key is absent.
	 */
	public function error(?string $key=null): string|array|null {
		if($key===null){
			return $this->errors;
		}
		return $this->errors[$key] ?? null;
	}

	/**
	 * Returns the first error message in insertion order.
	 *
	 * @return ?string First error message, or null when there are no errors.
	 */
	public function firstError(): ?string {
		return $this->errors===[] ? null : (string)reset($this->errors);
	}

	/**
	 * Reports whether sanitized data contains a key or dot-path.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @return bool True when the value exists, even if the value itself is null.
	 */
	public function has(string $key): bool {
		if($key==='' || !str_contains($key, '.')){
			return array_key_exists($key, $this->data);
		}
		$current=$this->data;
		foreach(self::pathSegments($key) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return false;
			}
			$current=$current[$segment];
		}
		return true;
	}

	/**
	 * Reports whether a field/path has a validation error.
	 *
	 * @param string $key Error key to inspect.
	 * @return bool True when the error bag contains the key.
	 */
	public function invalid(string $key): bool {
		return array_key_exists($key, $this->errors);
	}

	/**
	 * Returns a sanitized value by key or dot-path.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param mixed $default Value returned when the key/path is absent.
	 * @return mixed sanitized value at the literal key or dotted path, or the caller default when absent.
	 */
	public function get(string $key, mixed $default=null): mixed {
		if($key==='' || !str_contains($key, '.')){
			return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
		}
		$value=$this->pathValue($this->data, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	/**
	 * Returns a subset of sanitized data.
	 *
	 * Dot-path keys are rebuilt into nested arrays in the returned subset.
	 *
	 * @param list<string> $keys Top-level keys or dot-paths to keep.
	 * @return array<string,mixed> Sanitized data containing only present requested keys.
	 */
	public function only(array $keys): array {
		if($this->onlyKeysPayload===$keys && $this->onlyPayload!==null){
			return $this->onlyPayload;
		}
		$subset=[];
		foreach($keys as $key){
			$value=$this->pathValue($this->data, (string)$key);
			if($value['present']===true){
				$this->setPathValue($subset, (string)$key, $value['value']);
			}
		}
		$this->onlyKeysPayload=$keys;
		return $this->onlyPayload=$subset;
	}

	/**
	 * Returns sanitized data without selected keys or dot-paths.
	 *
	 * @param list<string> $keys Top-level keys or dot-paths to remove.
	 * @return array<string,mixed> Sanitized data after removals.
	 */
	public function except(array $keys): array {
		if($this->exceptKeysPayload===$keys && $this->exceptPayload!==null){
			return $this->exceptPayload;
		}
		$subset=$this->data;
		foreach($keys as $key){
			$this->unsetPathValue($subset, (string)$key);
		}
		$this->exceptKeysPayload=$keys;
		return $this->exceptPayload=$subset;
	}

	/**
	 * Returns an original unsanitized input value by key or dot-path.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param mixed $default Value returned when the key/path is absent.
	 * @return mixed original unsanitized value at the literal key or dotted path, or the caller default when absent.
	 */
	public function raw(string $key, mixed $default=null): mixed {
		if($key==='' || !str_contains($key, '.')){
			return array_key_exists($key, $this->input) ? $this->input[$key] : $default;
		}
		$value=$this->pathValue($this->input, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	/**
	 * Returns the original input captured before sanitation.
	 *
	 * @return array<string,mixed> Raw input values.
	 */
	public function input(): array {
		return $this->input;
	}

	/**
	 * Throws when the result contains validation errors.
	 *
	 * @param ?string $message Optional exception message override.
	 * @param array<string,mixed> $context Extra exception context.
	 * @return self Same result when validation passed.
	 *
	 * @throws SanitizationException When failed() is true.
	 */
	public function ensureValid(?string $message=null, array $context=[]): self {
		if($this->failed()){
			throw new SanitizationException($this, $context, $message);
		}
		return $this;
	}

	/**
	 * Alias for ensureValid().
	 *
	 * @param ?string $message Optional exception message override.
	 * @param array<string,mixed> $context Extra exception context.
	 * @return self Same result when validation passed.
	 *
	 * @throws SanitizationException When failed() is true.
	 */
	public function throwIfFailed(?string $message=null, array $context=[]): self {
		return $this->ensureValid($message, $context);
	}

	/**
	 * Resolves a top-level key or dot-path from sanitized data or raw input.
	 *
	 * The returned shape separates presence from value so null remains a valid
	 * stored value. Empty paths are treated as literal top-level keys for backward
	 * compatibility with array access semantics.
	 *
	 * @param array<string,mixed> $source Data source to inspect.
	 * @param string $path Top-level key or dot-separated path.
	 * @return array{present: bool, value: mixed} Lookup result.
	 */
	private function pathValue(array $source, string $path): array {
		if($path==='' || !str_contains($path, '.')){
			$present=array_key_exists($path, $source);
			return [
				'present'=>$present,
				'value'=>$present ? $source[$path] : null,
			];
		}
		$current=$source;
		foreach(self::pathSegments($path) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return [
					'present'=>false,
					'value'=>null,
				];
			}
			$current=$current[$segment];
		}
		return [
			'present'=>true,
			'value'=>$current,
		];
	}

	/**
	 * Writes a value into a nested array using top-level or dot-path addressing.
	 *
	 * Missing intermediate segments are created as arrays, and non-array
	 * intermediates are replaced so only() can rebuild requested nested output from
	 * flat path requests without leaking unrelated data.
	 *
	 * @param array<string,mixed> $target Subset array being built by reference.
	 * @param string $path Top-level key or dot-separated path.
	 * @param mixed $value Sanitized value to store.
	 * @return void
	 */
	private function setPathValue(array &$target, string $path, mixed $value): void {
		if($path==='' || !str_contains($path, '.')){
			$target[$path]=$value;
			return;
		}
		$segments=self::pathSegments($path);
		$lastIndex=count($segments)-1;
		$current=&$target;
		foreach($segments as $index=>$segment){
			if($index===$lastIndex){
				$current[$segment]=$value;
				return;
			}
			if(!isset($current[$segment]) || !is_array($current[$segment])){
				$current[$segment]=[];
			}
			$current=&$current[$segment];
		}
	}

	/**
	 * Removes a top-level key or nested dot-path from sanitized output.
	 *
	 * Missing intermediate paths are ignored, making except() safe for optional
	 * fields and caller-provided exclusion lists. Empty paths remove the literal
	 * empty-string key when present.
	 *
	 * @param array<string,mixed> $target Sanitized data being filtered by reference.
	 * @param string $path Top-level key or dot-separated path.
	 * @return void
	 */
	private function unsetPathValue(array &$target, string $path): void {
		if($path==='' || !str_contains($path, '.')){
			unset($target[$path]);
			return;
		}
		$segments=self::pathSegments($path);
		$lastIndex=count($segments)-1;
		$current=&$target;
		foreach($segments as $index=>$segment){
			if($index===$lastIndex){
				unset($current[$segment]);
				return;
			}
			if(!isset($current[$segment]) || !is_array($current[$segment])){
				return;
			}
			$current=&$current[$segment];
		}
	}

	/**
	 * Returns cached dot-path segments for repeated result projection paths.
	 *
	 * @param string $path Dot-path string.
	 * @return list<string> Dot-path segments.
	 */
	private static function pathSegments(string $path): array {
		if(isset(self::$pathSegmentCache[$path])){
			return self::$pathSegmentCache[$path];
		}
		$segments=explode('.', $path);
		if(count(self::$pathSegmentCache)<64){
			self::$pathSegmentCache[$path]=$segments;
		}
		return $segments;
	}

}
