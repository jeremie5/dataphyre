<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

/**
 * Read-only request input wrapper with sanitation shortcuts.
 *
 * InputBag exposes dot-path access, subset extraction, schema validation, preset
 * validation, typed cleaners, and conditional callbacks over one immutable input
 * array. It never mutates the source input; only returned subsets, sanitized
 * results, and callback return values carry derived data.
 */
final class InputBag {

	/**
	 * Creates an input bag backed by a sanitation manager.
	 *
	 * @param SanitationManager $manager Sanitation manager used for cleaners and schemas.
	 * @param array<string,mixed> $input Raw request or form input stored immutably by the bag.
	 */
	public function __construct(
		private readonly SanitationManager $manager,
		private readonly array $input
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
	 * Returns the complete raw input array.
	 *
	 * @return array<string,mixed> Raw input.
	 */
	public function all(): array {
		return $this->input;
	}

	/**
	 * Checks whether a dot-path exists in the input.
	 *
	 * Dot-path segments address nested arrays only. Literal keys containing dots
	 * are treated as paths, not as top-level literal keys.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @return bool Presence flag.
	 */
	public function has(string $key): bool {
		if($key==='' || !str_contains($key, '.')){
			return array_key_exists($key, $this->input);
		}
		$current=$this->input;
		foreach(self::pathSegments($key) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return false;
			}
			$current=$current[$segment];
		}
		return true;
	}

	/**
	 * Alias for has() using validation vocabulary.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @return bool Presence flag.
	 */
	public function present(string $key): bool {
		return $this->has($key);
	}

	/**
	 * Checks whether a dot-path is absent from the input.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @return bool Missing flag.
	 */
	public function missing(string $key): bool {
		return !$this->has($key);
	}

	/**
	 * Checks whether a dot-path exists and contains a non-blank value.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @return bool Filled-value flag.
	 */
	public function filled(string $key): bool {
		$value=$this->pathValue($this->input, $key);
		return $value['present']===true && $this->isFilledValue($value['value']);
	}

	/**
	 * Checks whether a dot-path is missing or blank.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @return bool Blank-value flag.
	 */
	public function blank(string $key): bool {
		return !$this->filled($key);
	}

	/**
	 * Reads a raw value by dot-path with a default fallback.
	 *
	 * Defaults are used only for absent paths. Present null, blank string, false,
	 * zero, and empty array values are returned as stored.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed Raw value at the dot-path, or the supplied default when absent.
	 */
	public function get(string $key, mixed $default=null): mixed {
		if($key==='' || !str_contains($key, '.')){
			return array_key_exists($key, $this->input) ? $this->input[$key] : $default;
		}
		$value=$this->pathValue($this->input, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	/**
	 * Extracts only selected dot-paths from the input.
	 *
	 * Nested dot-paths are reconstructed in the returned subset, preserving the
	 * path shape callers asked for rather than flattening values.
	 *
	 * @param list<string> $keys Top-level keys or dot-paths.
	 * @return array<string,mixed> Input subset.
	 */
	public function only(array $keys): array {
		if($this->onlyKeysPayload===$keys && $this->onlyPayload!==null){
			return $this->onlyPayload;
		}
		$subset=[];
		foreach($keys as $key){
			$value=$this->pathValue($this->input, (string)$key);
			if($value['present']===true){
				$this->setPathValue($subset, (string)$key, $value['value']);
			}
		}
		$this->onlyKeysPayload=$keys;
		return $this->onlyPayload=$subset;
	}

	/**
	 * Returns input with selected dot-paths removed.
	 *
	 * @param list<string> $keys Top-level keys or dot-paths to remove.
	 * @return array<string,mixed> Input subset.
	 */
	public function except(array $keys): array {
		if($this->exceptKeysPayload===$keys && $this->exceptPayload!==null){
			return $this->exceptPayload;
		}
		$subset=$this->input;
		foreach($keys as $key){
			$this->unsetPathValue($subset, (string)$key);
		}
		$this->exceptKeysPayload=$keys;
		return $this->exceptPayload=$subset;
	}

	/**
	 * Sanitizes the bag with a schema and returns the detailed result.
	 *
	 * @param array<string,mixed> $schema Rule schema keyed by input field.
	 * @param array<string,mixed> $defaults Default values.
	 * @param array<string,mixed> $options Schema execution options.
	 * @return SanitizationResult Detailed sanitation result.
	 */
	public function sanitize(array $schema, array $defaults=[], array $options=[]): SanitizationResult {
		return $this->manager->schema($this->input, $schema, $defaults, $options);
	}

	/**
	 * Validates the bag with a schema.
	 *
	 * @param array<string,mixed> $schema Rule schema keyed by input field.
	 * @param array<string,mixed> $defaults Default values.
	 * @param array<string,mixed> $options Schema execution options.
	 * @return SanitizationResult Detailed sanitation result.
	 */
	public function validate(array $schema, array $defaults=[], array $options=[]): SanitizationResult {
		return $this->sanitize($schema, $defaults, $options);
	}

	/**
	 * Returns sanitized schema values that passed validation.
	 *
	 * @param array<string,mixed> $schema Rule schema keyed by input field.
	 * @param array<string,mixed> $defaults Default values.
	 * @param array<string,mixed> $options Schema execution options.
	 * @return array<string,mixed> Validated values.
	 */
	public function validated(array $schema, array $defaults=[], array $options=[]): array {
		return $this->manager->validated($this->input, $schema, $defaults, $options);
	}

	/**
	 * Returns validated schema values or throws on validation failure.
	 *
	 * @param array<string,mixed> $schema Rule schema keyed by input field.
	 * @param array<string,mixed> $defaults Default values.
	 * @param array<string,mixed> $options Schema execution options.
	 * @param ?string $message Optional exception message override.
	 * @return array<string,mixed> Validated values.
	 */
	public function validatedOrFail(array $schema, array $defaults=[], array $options=[], ?string $message=null): array {
		return $this->manager->schemaOrFail($this->input, $schema, $defaults, $options, $message);
	}

	/**
	 * Sanitizes the bag with a named preset.
	 *
	 * @param string $name Preset name.
	 * @param array<string,mixed> $preset_overrides Rule overrides merged into the preset.
	 * @param array<string,mixed> $defaults Default values.
	 * @param array<string,mixed> $options Preset execution options.
	 * @return SanitizationResult Detailed sanitation result.
	 */
	public function preset(string $name, array $preset_overrides=[], array $defaults=[], array $options=[]): SanitizationResult {
		return $this->manager->preset($name, $this->input, $preset_overrides, $defaults, $options);
	}

	/**
	 * Validates the bag with a named preset.
	 *
	 * @param string $name Preset name.
	 * @param array<string,mixed> $preset_overrides Rule overrides merged into the preset.
	 * @param array<string,mixed> $defaults Default values.
	 * @param array<string,mixed> $options Preset execution options.
	 * @return SanitizationResult Detailed sanitation result.
	 */
	public function validatePreset(string $name, array $preset_overrides=[], array $defaults=[], array $options=[]): SanitizationResult {
		return $this->preset($name, $preset_overrides, $defaults, $options);
	}

	/**
	 * Returns validated values from a named preset.
	 *
	 * @param string $name Preset name.
	 * @param array<string,mixed> $preset_overrides Rule overrides merged into the preset.
	 * @param array<string,mixed> $defaults Default values.
	 * @param array<string,mixed> $options Preset execution options.
	 * @return array<string,mixed> Validated values.
	 */
	public function validatedPreset(string $name, array $preset_overrides=[], array $defaults=[], array $options=[]): array {
		return $this->manager->validatedPreset($name, $this->input, $preset_overrides, $defaults, $options);
	}

	/**
	 * Returns validated preset values or throws on validation failure.
	 *
	 * @param string $name Preset name.
	 * @param array<string,mixed> $preset_overrides Rule overrides merged into the preset.
	 * @param array<string,mixed> $defaults Default values.
	 * @param array<string,mixed> $options Preset execution options.
	 * @param ?string $message Optional exception message override.
	 * @return array<string,mixed> Validated values.
	 */
	public function validatedPresetOrFail(string $name, array $preset_overrides=[], array $defaults=[], array $options=[], ?string $message=null): array {
		return $this->manager->presetOrFail($name, $this->input, $preset_overrides, $defaults, $options, $message);
	}

	/**
	 * Sanitizes one input value by dot-path.
	 *
	 * Failed sanitation, absent paths, omitted nullable fields, or excluded values
	 * return the supplied default, keeping typed accessor methods from leaking
	 * false sentinels into caller code.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param string|array<string,mixed> $rule Sanitation rule or type.
	 * @param mixed $default Default value for failure, absence, or exclusion.
	 * @return mixed Sanitized value when included and valid, or the supplied default on absence, exclusion, or validation failure.
	 */
	public function clean(string $key, string|array $rule='default', mixed $default=null): mixed {
		$value=$this->pathValue($this->input, $key);
		$detail=$this->manager->sanitizeDetailed($value['value'], $rule, ['present'=>$value['present']]);
		return $detail['failed']===true ? $default : ($detail['include']===true ? $detail['value'] : $default);
	}

	/**
	 * Reads and sanitizes a value as a string.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?string $default Default value.
	 * @return ?string Sanitized string or default.
	 */
	public function string(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'default', $default);
		return $value===false || $value===null ? $default : (string)$value;
	}

	/**
	 * Reads and sanitizes default text.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?string $default Default value.
	 * @return ?string Sanitized text or default.
	 */
	public function text(string $key, ?string $default=null): ?string {
		return $this->string($key, $default);
	}

	/**
	 * Reads and sanitizes text without special characters.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?string $default Default value.
	 * @return ?string Sanitized text or default.
	 */
	public function textNoSpecial(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'text_nospecial', $default);
		return $value===false || $value===null ? $default : (string)$value;
	}

	/**
	 * Reads and sanitizes basic safe HTML.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?string $default Default value.
	 * @return ?string Sanitized HTML or default.
	 */
	public function basicHtml(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'basic_html', $default);
		return $value===false || $value===null ? $default : (string)$value;
	}

	/**
	 * Reads and sanitizes an integer value.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?int $default Default value.
	 * @return ?int Sanitized integer or default.
	 */
	public function integer(string $key, ?int $default=null): ?int {
		$value=$this->clean($key, 'integer');
		return $value===false || $value===null || $value==='' ? $default : (int)$value;
	}

	/**
	 * Reads and sanitizes a float value.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?float $default Default value.
	 * @return ?float Sanitized float or default.
	 */
	public function float(string $key, ?float $default=null): ?float {
		$value=$this->clean($key, 'float');
		return $value===false || $value===null || $value==='' ? $default : (float)$value;
	}

	/**
	 * Reads and sanitizes a boolean value.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?bool $default Default value.
	 * @return ?bool Sanitized boolean or default.
	 */
	public function boolean(string $key, ?bool $default=null): ?bool {
		$value=$this->clean($key, 'boolean');
		return $value===false || $value===null || $value==='' ? $default : (bool)$value;
	}

	/**
	 * Reads and sanitizes an associative array value.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?array $default Default value.
	 * @return ?array Sanitized array or default.
	 */
	public function arrayValue(string $key, ?array $default=null): ?array {
		$value=$this->clean($key, 'array');
		return is_array($value) ? $value : $default;
	}

	/**
	 * Reads and sanitizes a list value.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?array $default Default value.
	 * @return ?array Sanitized list or default.
	 */
	public function listValue(string $key, ?array $default=null): ?array {
		$value=$this->clean($key, 'list');
		return is_array($value) ? $value : $default;
	}

	/**
	 * Reads and sanitizes an email address.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?string $default Default value.
	 * @return ?string Sanitized email or default.
	 */
	public function email(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'email');
		return $value===false ? $default : $value;
	}

	/**
	 * Reads and sanitizes a URL.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?string $default Default value.
	 * @return ?string Sanitized URL or default.
	 */
	public function url(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'url');
		return $value===false ? $default : $value;
	}

	/**
	 * Reads and sanitizes a phone number.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?string $default Default value.
	 * @return ?string Sanitized phone number or default.
	 */
	public function phone(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'phone_number');
		return $value===false ? $default : $value;
	}

	/**
	 * Reads and sanitizes a person name.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?string $default Default value.
	 * @return ?string Sanitized name or default.
	 */
	public function name(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'person_name');
		return $value===false ? $default : $value;
	}

	/**
	 * Reads and sanitizes a numeric string.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?string $default Default value.
	 * @return ?string Sanitized numeric string or default.
	 */
	public function numeric(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'numeric');
		return $value===false ? $default : $value;
	}

	/**
	 * Reads and sanitizes a slug.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?string $default Default value.
	 * @return ?string Sanitized slug or default.
	 */
	public function slug(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'slug');
		return $value===false ? $default : $value;
	}

	/**
	 * Reads and sanitizes a username.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?string $default Default value.
	 * @return ?string Sanitized username or default.
	 */
	public function username(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'username');
		return $value===false ? $default : $value;
	}

	/**
	 * Reads and sanitizes a postal code.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param ?string $default Default value.
	 * @return ?string Sanitized postal code or default.
	 */
	public function postalCode(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'postal_code');
		return $value===false ? $default : $value;
	}

	/**
	 * Invokes a callback only when a dot-path is present.
	 *
	 * The callback receives the raw value and this bag, enabling local transforms
	 * without repeating presence checks at call sites.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param callable $callback Callback receiving value and InputBag.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed Callback result when the path is present, or the supplied default when absent.
	 */
	public function whenPresent(string $key, callable $callback, mixed $default=null): mixed {
		$value=$this->pathValue($this->input, $key);
		if($value['present']===false){
			return $default;
		}
		return $callback($value['value'], $this);
	}

	/**
	 * Invokes a callback only when a dot-path is present and filled.
	 *
	 * @param string $key Top-level key or dot-path.
	 * @param callable $callback Callback receiving value and InputBag.
	 * @param mixed $default Value returned when the key is absent or blank.
	 * @return mixed Callback result when the path is present and filled, or the supplied default when absent or blank.
	 */
	public function whenFilled(string $key, callable $callback, mixed $default=null): mixed {
		$value=$this->pathValue($this->input, $key);
		if($value['present']===false || !$this->isFilledValue($value['value'])){
			return $default;
		}
		return $callback($value['value'], $this);
	}

	/**
	 * Reads a value and presence flag from an array using dot-path notation.
	 *
	 * Traversal stops when a segment encounters a non-array value, and that path is
	 * treated as absent. Empty string paths are allowed and read the literal empty
	 * key at the current level.
	 *
	 * @param array<string,mixed> $source Source array.
	 * @param string $path Top-level key or dot-path.
	 * @return array{present:bool,value:mixed} Path lookup result.
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
	 * Writes a value into an array using dot-path notation.
	 *
	 * @param array<string,mixed> $target Target array passed by reference.
	 * @param string $path Top-level key or dot-path.
	 * @param mixed $value Value to write.
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
	 * Removes a value from an array using dot-path notation.
	 *
	 * @param array<string,mixed> $target Target array passed by reference.
	 * @param string $path Top-level key or dot-path.
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
	 * Returns cached dot-path segments for repeated input projection paths.
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

	/**
	 * Applies InputBag's filled-value semantics.
	 *
	 * Null, empty strings after trim, and empty arrays are blank. Other scalar and
	 * object values count as filled.
	 *
	 * @param mixed $value Raw value.
	 * @return bool Filled-value decision.
	 */
	private function isFilledValue(mixed $value): bool {
		if($value===null){
			return false;
		}
		if(is_string($value)){
			return trim($value)!=='';
		}
		if(is_array($value)){
			return $value!==[];
		}
		return true;
	}
}
