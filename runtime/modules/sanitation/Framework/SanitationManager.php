<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

/**
 * Central sanitation and validation service for values, input bags, and schemas.
 *
 * SanitationManager normalizes compact rule strings or rule arrays into a
 * canonical config, sanitizes scalar values through the kernel sanitation
 * engine, validates schema constraints, expands wildcard field paths, and
 * returns SanitizationResult objects that preserve cleaned data, raw input, and
 * per-field error messages. HTML escaping only occurs when a rule enables the
 * escape option; callers remain responsible for SQL parameterization and
 * output-context escaping outside sanitized field values.
 */
final class SanitationManager {

	private static ?self $instance=null;

	private readonly PresetRegistry $presets;
	private array $stringRuleConfigs=[];
	private array $humanizedFields=[];

	/**
	 * @var array{input:array<string,mixed>, schema:array<string,mixed>, defaults:array<string,mixed>, options:array<string,mixed>, data:array<string,mixed>, errors:array<string,string|list<string>>}|null
	 */
	private ?array $schemaResultCache=null;

	/**
	 * Creates a manager with a fresh preset registry.
	 */
	public function __construct() {
		$this->presets=new PresetRegistry();
	}

	/**
	 * Returns the process-local singleton manager.
	 *
	 * @return self Shared sanitation manager instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Resets the process-local manager singleton and its registered presets.
	 */
	public static function flush(): void {
		self::$instance=null;
	}

	/**
	 * Sanitizes one value and returns only the cleaned value.
	 *
	 * Use {@see self::sanitizeDetailed()} when callers need the include flag,
	 * error message, exclusion state, or normalized config.
	 *
	 * @param mixed $value Input value to sanitize.
	 * @param string|array<string, mixed> $rule Compact rule string or expanded rule config.
	 * @param array<string, mixed> $options Runtime options such as field name, labels, messages, or context.
	 * @return mixed Sanitized and cast value, false on validation failure, or null when omitted/nullable.
	 */
	public function sanitize(mixed $value, string|array $rule='default', array $options=[]): mixed {
		$detail=$this->sanitizeDetailed($value, $rule, $options+['present'=>true]);
		return $detail['value'];
	}

	/**
	 * Starts a fluent sanitizer for a single value.
	 *
	 * @param mixed $value Value captured by the fluent Sanitizer.
	 * @return Sanitizer Fluent value sanitizer bound to this manager.
	 */
	public function string(mixed $value): Sanitizer {
		return new Sanitizer($this, $value);
	}

	/**
	 * Wraps an input array in an InputBag for repeated field-level sanitation.
	 *
	 * @param array<string, mixed> $input Raw input data.
	 * @return InputBag Input wrapper bound to this manager.
	 */
	public function bag(array $input): InputBag {
		return new InputBag($this, $input);
	}

	/**
	 * Lists registered preset names.
	 *
	 * @return array<int, string> Preset identifiers known to the registry.
	 */
	public function presets(): array {
		return $this->presets->names();
	}

	/**
	 * Checks whether a named schema preset is registered.
	 *
	 * @param string $name Preset identifier.
	 * @return bool True when the preset can be resolved.
	 */
	public function hasPreset(string $name): bool {
		return $this->presets->has($name);
	}

	/**
	 * Registers or replaces a named schema preset.
	 *
	 * Presets may be arrays or callables accepted by PresetRegistry. They are
	 * resolved later into schema, default data, and schema option arrays.
	 *
	 * @param string $name Preset identifier.
	 * @param array<string, mixed>|callable $definition Preset definition.
	 * @return self Current manager for fluent registration.
	 */
	public function registerPreset(string $name, array|callable $definition): self {
		$this->presets->register($name, $definition);
		return $this;
	}

	/**
	 * Resolves the schema portion of a named preset.
	 *
	 * @param string $name Preset identifier.
	 * @param array<string, mixed> $presetOverrides Overrides applied while resolving the preset.
	 * @return array<string, string|array<string, mixed>> Field-to-rule schema.
	 */
	public function presetSchema(string $name, array $presetOverrides=[]): array {
		return $this->presets->resolve($name, $presetOverrides)['schema'];
	}

	/**
	 * Sanitizes and validates input through a named preset.
	 *
	 * Preset defaults are merged with caller defaults, and preset options are
	 * recursively merged with caller options before schema processing begins.
	 *
	 * @param string $name Preset identifier.
	 * @param array<string, mixed> $input Raw input data.
	 * @param array<string, mixed> $presetOverrides Overrides applied while resolving the preset.
	 * @param array<string, mixed> $defaults Initial output data.
	 * @param array<string, mixed> $options Schema-level options such as labels and messages.
	 * @return SanitizationResult Cleaned data and validation errors.
	 */
	public function preset(string $name, array $input, array $presetOverrides=[], array $defaults=[], array $options=[]): SanitizationResult {
		$preset=$this->presets->resolve($name, $presetOverrides);
		return $this->schema(
			$input,
			$preset['schema'],
			array_replace($preset['defaults'], $defaults),
			array_replace_recursive($preset['options'], $options),
		);
	}

	/**
	 * Validates input through a named preset.
	 *
	 * This mirrors preset() for call sites that read in validation vocabulary
	 * while preserving the same preset merge and schema processing semantics.
	 *
	 * @param string $name Preset identifier.
	 * @param array<string, mixed> $input Raw input data.
	 * @param array<string, mixed> $presetOverrides Overrides applied while resolving the preset.
	 * @param array<string, mixed> $defaults Initial output data.
	 * @param array<string, mixed> $options Schema-level options.
	 * @return SanitizationResult Cleaned data and validation errors.
	 */
	public function validatePreset(string $name, array $input, array $presetOverrides=[], array $defaults=[], array $options=[]): SanitizationResult {
		return $this->preset($name, $input, $presetOverrides, $defaults, $options);
	}

	/**
	 * Runs a preset and returns only its sanitized data.
	 *
	 * Failed fields are omitted from the returned data according to
	 * SanitizationResult semantics.
	 *
	 * @param string $name Preset identifier.
	 * @param array<string, mixed> $input Raw input data.
	 * @param array<string, mixed> $presetOverrides Overrides applied while resolving the preset.
	 * @param array<string, mixed> $defaults Initial output data.
	 * @param array<string, mixed> $options Schema-level options.
	 * @return array<string, mixed> Sanitized data.
	 */
	public function validatedPreset(string $name, array $input, array $presetOverrides=[], array $defaults=[], array $options=[]): array {
		return $this->preset($name, $input, $presetOverrides, $defaults, $options)->validated();
	}

	/**
	 * Runs a preset and throws when validation fails.
	 *
	 * The thrown SanitizationException receives the preset name in its context.
	 *
	 * @param string $name Preset identifier.
	 * @param array<string, mixed> $input Raw input data.
	 * @param array<string, mixed> $presetOverrides Overrides applied while resolving the preset.
	 * @param array<string, mixed> $defaults Initial output data.
	 * @param array<string, mixed> $options Schema-level options.
	 * @param ?string $message Optional exception message override.
	 * @return array<string, mixed> Sanitized data.
	 */
	public function presetOrFail(string $name, array $input, array $presetOverrides=[], array $defaults=[], array $options=[], ?string $message=null): array {
		return $this->preset($name, $input, $presetOverrides, $defaults, $options)
			->ensureValid($message, ['preset'=>$name])
			->validated();
	}

	/**
	 * Sanitizes input through a field schema and returns a full result object.
	 *
	 * Schema keys may be dot paths or wildcard paths such as `items.*.email`.
	 * Defaults seed the output before rules run. Each field is normalized,
	 * conditionally included/excluded, sanitized, then constraint-checked after
	 * all candidate values are available so cross-field rules can compare
	 * against cleaned context.
	 *
	 * @param array<string, mixed> $input Raw input data.
	 * @param array<string, string|array<string, mixed>> $schema Field-to-rule map.
	 * @param array<string, mixed> $defaults Initial output data.
	 * @param array<string, mixed> $options Schema-level options such as labels and messages.
	 * @return SanitizationResult Cleaned data, validation errors, and original input.
	 */
	public function schema(array $input, array $schema, array $defaults=[], array $options=[]): SanitizationResult {
		$cacheable=$this->isCacheableTree($input)
			&& $this->isCacheableTree($schema)
			&& $this->isCacheableTree($defaults)
			&& $this->isCacheableTree($options);
		if(
			$cacheable &&
			$this->schemaResultCache!==null &&
			$this->schemaResultCache['input']===$input &&
			$this->schemaResultCache['schema']===$schema &&
			$this->schemaResultCache['defaults']===$defaults &&
			$this->schemaResultCache['options']===$options
		){
			return new SanitizationResult(
				$this->schemaResultCache['data'],
				$this->schemaResultCache['errors'],
				$input
			);
		}
		$data=$defaults;
		$errors=[];
		$configs=[];
		$targets=[];
		$hasDeferredConstraints=false;
		$hasWildcardDistinctConstraints=false;
		$schemaLabels=(array)($options['labels'] ?? []);
		$schemaMessages=(array)($options['messages'] ?? []);
		foreach($schema as $field=>$rule){
			foreach($this->schemaTargets($input, (string)$field) as $target){
				$targets[]=[
					'field'=>$target['field'],
					'path_segments'=>$target['path_segments'],
					'pattern'=>$target['pattern'],
					'wildcard_values'=>$target['wildcard_values'],
					'rule'=>$rule,
				];
			}
		}
		foreach($targets as $target){
			$field=$target['field'];
			$pathSegments=$target['path_segments'] ?? null;
			$inputField=$pathSegments===null ? $this->pathValue($input, $field) : $this->pathValueSegments($input, $pathSegments);
			$ruleOptions=[
				'present'=>$inputField['present'],
				'input'=>$input,
				'context'=>$data,
				'skip_constraints'=>true,
				'field'=>$field,
				'field_pattern'=>$target['pattern'],
				'wildcard_values'=>$target['wildcard_values'],
				'labels'=>$schemaLabels,
				'messages'=>$schemaMessages,
			];
			$config=$this->normalizeRule($target['rule'], $ruleOptions);
			if(!$this->shouldProcessSchemaRule($config, $inputField['present'], $input, $data)){
				continue;
			}
			$detail=$this->sanitizeConfigured($inputField['value'], $config, $ruleOptions);
			$configs[$field]=$detail['config'];
			if($this->hasDeferredSchemaConstraints($detail['config'])){
				$hasDeferredConstraints=true;
			}
			if(($detail['config']['distinct'] ?? false)===true && str_contains((string)($detail['config']['field_pattern'] ?? $field), '*')){
				$hasWildcardDistinctConstraints=true;
			}
			if($detail['failed']===true){
				$errors[$field]=$detail['error'];
				continue;
			}
			if(($detail['excluded'] ?? false)===true){
				if($pathSegments===null){
					$this->unsetPathValue($data, $field);
				}
				else
				{
					$this->unsetPathValueSegments($data, $pathSegments);
				}
				continue;
			}
			if($detail['include']===true){
				if($pathSegments===null){
					$this->setPathValue($data, $field, $detail['value']);
				}
				else
				{
					$this->setPathValueSegments($data, $pathSegments, $detail['value']);
				}
			}
		}
		if($hasDeferredConstraints){
			foreach($targets as $target){
				$field=$target['field'];
				$pathSegments=$target['path_segments'] ?? null;
				$dataField=$pathSegments===null ? $this->pathValue($data, $field) : $this->pathValueSegments($data, $pathSegments);
				if(isset($errors[$field]) || $dataField['present']===false || !isset($configs[$field])){
					continue;
				}
				$error=$this->validateConstraints($dataField['value'], $configs[$field], $data, $input);
				if($error!==null){
					$errors[$field]=$error;
					if($pathSegments===null){
						$this->unsetPathValue($data, $field);
					}
					else
					{
						$this->unsetPathValueSegments($data, $pathSegments);
					}
				}
			}
		}
		if($hasWildcardDistinctConstraints){
			$this->applyDistinctConstraints($targets, $configs, $data, $errors);
		}
		if($cacheable){
			$this->schemaResultCache=[
				'input'=>$input,
				'schema'=>$schema,
				'defaults'=>$defaults,
				'options'=>$options,
				'data'=>$data,
				'errors'=>$errors,
			];
		}
		return new SanitizationResult($data, $errors, $input);
	}

	/**
	 * Sanitizes a schema and returns only cleaned data.
	 *
	 * @param array<string, mixed> $input Raw input data.
	 * @param array<string, string|array<string, mixed>> $schema Field-to-rule map.
	 * @param array<string, mixed> $defaults Initial output data.
	 * @param array<string, mixed> $options Schema-level options.
	 * @return array<string, mixed> Sanitized data.
	 */
	public function validated(array $input, array $schema, array $defaults=[], array $options=[]): array {
		return $this->schema($input, $schema, $defaults, $options)->validated();
	}

	/**
	 * Sanitizes a schema and throws when validation fails.
	 *
	 * @param array<string, mixed> $input Raw input data.
	 * @param array<string, string|array<string, mixed>> $schema Field-to-rule map.
	 * @param array<string, mixed> $defaults Initial output data.
	 * @param array<string, mixed> $options Schema-level options.
	 * @param ?string $message Optional exception message override.
	 * @return array<string, mixed> Sanitized data.
	 */
	public function schemaOrFail(array $input, array $schema, array $defaults=[], array $options=[], ?string $message=null): array {
		return $this->schema($input, $schema, $defaults, $options)
			->ensureValid($message)
			->validated();
	}

	/**
	 * Validates a schema and throws when validation fails.
	 *
	 * This mirrors schemaOrFail() for call sites that read in validation
	 * vocabulary while preserving the same exception and sanitized-data contract.
	 *
	 * @param array<string, mixed> $input Raw input data.
	 * @param array<string, string|array<string, mixed>> $schema Field-to-rule map.
	 * @param array<string, mixed> $defaults Initial output data.
	 * @param array<string, mixed> $options Schema-level options.
	 * @param ?string $message Optional exception message override.
	 * @return array<string, mixed> Sanitized data.
	 */
	public function validateOrFail(array $input, array $schema, array $defaults=[], array $options=[], ?string $message=null): array {
		return $this->schemaOrFail($input, $schema, $defaults, $options, $message);
	}

	/**
	 * Sanitizes one value and returns the detailed normalization result.
	 *
	 * The result includes the sanitized value, a nullable error message, include
	 * and failed booleans, exclusion state, and normalized config. Schema
	 * processing uses this result array to decide whether a field should be
	 * written, skipped, or reported as invalid.
	 *
	 * @param mixed $value Input value to sanitize.
	 * @param string|array<string, mixed> $rule Compact rule string or expanded rule config.
	 * @param array<string, mixed> $options Runtime options such as field name, labels, messages, or context.
	 * @return array{value:mixed,error:?string,include:bool,failed:bool,excluded:bool,config:array<string,mixed>} Detailed sanitation result.
	 */
	public function sanitizeDetailed(mixed $value, string|array $rule='default', array $options=[]): array {
		$config=$this->normalizeRule($rule, $options);
		return $this->sanitizeConfigured($value, $config, $options);
	}

	/**
	 * Executes sanitation against an already-normalized rule config.
	 *
	 * This method owns the value lifecycle: conditional required/present flags are
	 * applied first, exclusions short-circuit output, absence/null/default handling
	 * is resolved, arrays are validated without string coercion, scalar values are
	 * normalized through the kernel sanitation engine, and constraints/casts are
	 * applied before returning a detailed result array.
	 *
	 * @param mixed $value Input value.
	 * @param array<string,mixed> $config Normalized rule config.
	 * @param array{field?:string,field_pattern?:string,present?:bool,context?:array<string,mixed>,input?:array<string,mixed>,labels?:array<string,string>,messages?:array<string,string>,skip_constraints?:bool} $options Runtime options including context, input, and constraint flags.
	 * @return array{value:mixed,error:?string,include:bool,failed:bool,excluded:bool,config:array<string,mixed>} Detailed sanitation result.
	 */
	private function sanitizeConfigured(mixed $value, array $config, array $options=[]): array {
		$present=(bool)($config['present'] ?? true);
		$config=$this->applyConditionalRequired($config, $options);
		$config=$this->applyConditionalPresence($config, $options);
		if($this->shouldExclude($value, $config, $options, $present)){
			return $this->result(null, null, false, false, $config, true);
		}
		if($present===false){
			if(($config['must_present'] ?? false)===true){
				return $this->result(false, $this->resolveMessage($config, 'present', 'The :field field must be present.'), true, true, $config);
			}
			if($config['default_provided']===true){
				return $this->result($this->castValue($config['default'], $config), null, true, false, $config);
			}
			if($config['nullable']===true){
				return $this->result(null, null, true, false, $config);
			}
			if($config['required']===true){
				return $this->result(false, $this->resolveMessage($config, 'required', 'The :field field is required.'), true, true, $config);
			}
			return $this->result(null, null, false, false, $config);
		}

		if($value===null){
			if($config['default_provided']===true){
				return $this->result($this->castValue($config['default'], $config), null, true, false, $config);
			}
			if($config['nullable']===true){
				return $this->result(null, null, true, false, $config);
			}
			if($config['required']===true){
				return $this->result(false, $this->resolveMessage($config, 'required', 'The :field field is required.'), true, true, $config);
			}
			if(in_array($config['type'], ['array', 'list'], true)){
				return $this->result(false, $this->resolveMessage($config, (string)$config['type'], $this->invalidTypeMessage((string)$config['type'])), true, true, $config);
			}
			return $this->result('', null, true, false, $config);
		}

		if(in_array($config['type'], ['array', 'list'], true)){
			if(!is_array($value)){
				return $this->result(false, $this->resolveMessage($config, (string)$config['type'], $this->invalidTypeMessage((string)$config['type'])), true, true, $config);
			}
			if($config['type']==='list' && !$this->isListArray($value)){
				return $this->result(false, $this->resolveMessage($config, 'list', $this->invalidTypeMessage('list')), true, true, $config);
			}
			if(empty($options['skip_constraints'])){
				$error=$this->validateConstraints($value, $config, (array)($options['context'] ?? []), (array)($options['input'] ?? []));
				if($error!==null){
					return $this->result(false, $error, true, true, $config);
				}
			}
			return $this->result($value, null, true, false, $config);
		}

		$string=$this->stringify($value);
		if($string===null){
			return $this->result(false, $this->resolveMessage($config, 'stringable', 'The :field field must be scalar or stringable.'), true, true, $config);
		}

		if($config['trim']===true){
			$string=trim($string);
		}
		if($config['squish']===true){
			$string=preg_replace('/\s+/u', ' ', trim($string)) ?? trim($string);
		}
		if($string===''){
			if($config['default_provided']===true){
				return $this->result($this->castValue($config['default'], $config), null, true, false, $config);
			}
			if($config['nullable']===true){
				return $this->result(null, null, true, false, $config);
			}
			if($config['required']===true){
				return $this->result(false, $this->resolveMessage($config, 'required', 'The :field field is required.'), true, true, $config);
			}
		}
		if($config['lower']===true){
			$string=mb_strtolower($string, 'UTF-8');
		}
		if($config['upper']===true){
			$string=mb_strtoupper($string, 'UTF-8');
		}

		$sanitized=\dataphyre\sanitation::sanitize($string, (string)$config['type'], (bool)$config['escape_html']);
		if($sanitized===false){
			return $this->result(false, $this->resolveMessage($config, (string)$config['type'], $this->invalidTypeMessage((string)$config['type'])), true, true, $config);
		}
		if(is_string($sanitized)){
			$length=mb_strlen($sanitized, 'UTF-8');
			if($config['min_length']!==null && $length<(int)$config['min_length']){
				return $this->result(false, $this->resolveMessage($config, 'min', 'The :field field must be at least :min characters.', [
					'min'=>(string)(int)$config['min_length'],
				]), true, true, $config);
			}
			if($config['max_length']!==null && $length>(int)$config['max_length']){
				return $this->result(false, $this->resolveMessage($config, 'max', 'The :field field may not be greater than :max characters.', [
					'max'=>(string)(int)$config['max_length'],
				]), true, true, $config);
			}
		}
		$castValue=$this->castValue($sanitized, $config);
		if(empty($options['skip_constraints'])){
			$error=$this->validateConstraints($castValue, $config, (array)($options['context'] ?? []), (array)($options['input'] ?? []));
			if($error!==null){
				return $this->result(false, $error, true, true, $config);
			}
		}
		return $this->result($castValue, null, true, false, $config);
	}

	/**
	 * Creates the canonical detailed sanitation result array.
	 *
	 * @param mixed $value Sanitized value or failure sentinel.
	 * @param ?string $error Validation error message.
	 * @param bool $include Whether the field should be included in output.
	 * @param bool $failed Whether sanitation failed.
	 * @param array<string,mixed> $config Normalized rule config.
	 * @param bool $excluded Whether the field was actively excluded.
	 * @return array{value:mixed,error:?string,include:bool,failed:bool,excluded:bool,config:array<string,mixed>} Detailed sanitation result.
	 */
	private function result(mixed $value, ?string $error, bool $include, bool $failed, array $config, bool $excluded=false): array {
		return [
			'value'=>$value,
			'error'=>$error,
			'include'=>$include,
			'failed'=>$failed,
			'excluded'=>$excluded,
			'config'=>$config,
		];
	}

	/**
	 * Converts scalar and stringable input into the string form expected by kernel sanitation.
	 *
	 * Arrays and opaque objects are rejected here so type errors remain explicit
	 * instead of being converted into lossy placeholder strings.
	 *
	 * @param mixed $value Raw value.
	 * @return ?string String value, or null when not stringable.
	 */
	private function stringify(mixed $value): ?string {
		if(is_string($value)){
			return $value;
		}
		if(is_int($value) || is_float($value)){
			return (string)$value;
		}
		if(is_bool($value)){
			return $value ? '1' : '0';
		}
		if($value instanceof \Stringable){
			return (string)$value;
		}
		return null;
	}

	/**
	 * Applies the configured scalar cast to a sanitized value.
	 *
	 * Null and false sentinels pass through unchanged so absence and validation
	 * failure semantics survive final output normalization.
	 *
	 * @param mixed $value Sanitized value.
	 * @param array<string,mixed> $config Normalized rule config.
	 * @return mixed integer, float, boolean, unchanged sanitized value, null absence sentinel, or false validation sentinel.
	 */
	private function castValue(mixed $value, array $config): mixed {
		if($value===null || $value===false){
			return $value;
		}
		return match($config['cast']){
			'integer'=>(int)$value,
			'float'=>(float)$value,
			'boolean'=>in_array((string)$value, ['1', 'true'], true),
			default=>$value,
		};
	}

	/**
	 * Maps sanitizer type tokens to default validation messages.
	 *
	 * @param string $type Normalized sanitation type.
	 * @return string Default error message for the type.
	 */
	private function invalidTypeMessage(string $type): string {
		return match($type){
			'email'=>'The value must be a valid email address.',
			'url'=>'The value must be a valid URL.',
			'phone_number'=>'The value must be a valid phone number.',
			'person_name'=>'The value must be a valid name.',
			'numeric'=>'The value must contain digits only.',
			'integer'=>'The value must be a valid integer.',
			'float'=>'The value must be a valid decimal number.',
			'boolean'=>'The value must be a valid boolean.',
			'array'=>'The value must be an array.',
			'list'=>'The value must be a list.',
			'slug'=>'The value must be a valid slug.',
			'username'=>'The value must be a valid username.',
			'postal_code'=>'The value must be a valid postal code.',
			default=>'The value could not be sanitized.',
		};
	}

	/**
	 * Normalizes a compact rule string or rule array into the canonical config.
	 *
	 * The config carries sanitation type, casting, presence/required conditions,
	 * cross-field constraints, wildcard metadata, labels, and scoped messages.
	 * Options are merged last so schema execution can inject field-specific
	 * context without changing user-authored rule definitions.
	 *
	 * @param string|array $rule Compact rule string, token list, or expanded config.
	 * @param array{field?:string,field_pattern?:string,present?:bool,context?:array<string,mixed>,input?:array<string,mixed>,labels?:array<string,string>,messages?:array<string,string>,skip_constraints?:bool} $options Runtime options supplied by value or schema execution.
	 * @return array<string,mixed> Canonical rule config consumed by the sanitation engine.
	 */
	private function normalizeRule(string|array $rule, array $options=[]): array {
		$config=[
			'type'=>'default',
			'required'=>false,
			'nullable'=>false,
			'trim'=>true,
			'squish'=>false,
			'lower'=>false,
			'upper'=>false,
			'escape_html'=>true,
			'min_length'=>null,
			'max_length'=>null,
			'default_provided'=>false,
			'default'=>null,
			'cast'=>null,
			'present'=>true,
			'must_present'=>false,
			'in'=>null,
			'not_in'=>null,
			'same'=>null,
			'different'=>null,
			'regex'=>null,
			'starts_with'=>[],
			'ends_with'=>[],
			'contains'=>[],
			'accepted'=>false,
			'declined'=>false,
			'digits'=>null,
			'min_value'=>null,
			'max_value'=>null,
			'min_items'=>null,
			'max_items'=>null,
			'distinct'=>false,
			'distinct_ignore_case'=>false,
			'unique_by'=>[],
			'unique_by_ignore_case'=>false,
			'sometimes'=>false,
			'when'=>null,
			'unless'=>null,
			'required_if'=>null,
			'required_unless'=>null,
			'required_with'=>[],
			'required_with_all'=>[],
			'required_without'=>[],
			'required_without_all'=>[],
			'present_if'=>null,
			'present_unless'=>null,
			'present_with'=>[],
			'present_with_all'=>[],
			'present_without'=>[],
			'present_without_all'=>[],
			'exclude_if'=>null,
			'exclude_unless'=>null,
			'exclude_when_blank'=>false,
			'callbacks'=>[],
			'field'=>null,
			'field_pattern'=>null,
			'label'=>null,
			'messages'=>[],
			'labels'=>[],
			'wildcard_values'=>[],
		];

		if(is_string($rule)){
			if(isset($this->stringRuleConfigs[$rule])){
				$config=$this->stringRuleConfigs[$rule];
			}
			else
			{
				$tokens=str_contains($rule, '|') ? explode('|', $rule) : [$rule];
				foreach($tokens as $token){
					$this->applyToken($config, trim((string)$token));
				}
				if(count($this->stringRuleConfigs)>=128){
					$this->stringRuleConfigs=[];
				}
				$this->stringRuleConfigs[$rule]=$config;
			}
		}
		else
		{
			$isList=$rule===[] || array_keys($rule)===range(0, count($rule)-1);
			if($isList){
				foreach($rule as $token){
					$this->applyToken($config, trim((string)$token));
				}
			}
			else
			{
				foreach($rule as $key=>$value){
					switch((string)$key){
						case 'type':
							$config['type']=$this->normalizeType((string)$value);
							break;
						case 'required':
						case 'nullable':
						case 'trim':
						case 'squish':
						case 'lower':
						case 'upper':
						case 'escape_html':
						case 'sometimes':
							$config[(string)$key]=(bool)$value;
							break;
						case 'present':
							$config['must_present']=(bool)$value;
							break;
						case 'raw':
							$config['escape_html']=!(bool)$value;
							break;
						case 'min':
						case 'min_length':
							$config['min_length']=max(0, (int)$value);
							break;
						case 'max':
						case 'max_length':
							$config['max_length']=max(0, (int)$value);
							break;
						case 'default':
							$config['default_provided']=true;
							$config['default']=$value;
							break;
						case 'cast':
							$config['cast']=$value===null ? null : (string)$value;
							break;
						case 'in':
						case 'not_in':
						case 'starts_with':
						case 'ends_with':
						case 'contains':
							$config[(string)$key]=is_array($value) ? array_values($value) : [(string)$value];
							break;
						case 'same':
						case 'different':
						case 'regex':
							$config[(string)$key]=$value===null ? null : (string)$value;
							break;
						case 'accepted':
						case 'declined':
							$config[(string)$key]=(bool)$value;
							break;
						case 'digits':
							$config['digits']=max(0, (int)$value);
							break;
						case 'min_value':
							$config['min_value']=is_numeric($value) ? (float)$value : null;
							break;
						case 'max_value':
							$config['max_value']=is_numeric($value) ? (float)$value : null;
							break;
						case 'min_items':
							$config['min_items']=max(0, (int)$value);
							break;
						case 'max_items':
							$config['max_items']=max(0, (int)$value);
							break;
						case 'distinct':
							$config['distinct']=$this->truthyRuleValue($value);
							if(is_string($value)){
								$config['distinct_ignore_case']=in_array(strtolower(trim($value)), ['ignore_case', 'case_insensitive', 'insensitive'], true);
							}
							elseif(is_array($value)){
								$config['distinct_ignore_case']=(bool)($value['ignore_case'] ?? $value['case_insensitive'] ?? false);
							}
							break;
						case 'distinct_ignore_case':
							$config['distinct_ignore_case']=(bool)$value;
							if($config['distinct_ignore_case']===true){
								$config['distinct']=true;
							}
							break;
						case 'when':
							$config['when']=$this->normalizeSchemaCondition($value);
							break;
						case 'unless':
							$config['unless']=$this->normalizeSchemaCondition($value);
							break;
						case 'unique_by':
							$config['unique_by']=$this->normalizeUniqueByFields($value);
							break;
						case 'unique_by_ignore_case':
							if(is_bool($value)){
								$config['unique_by_ignore_case']=$value;
							}
							else
							{
								$config['unique_by']=array_values(array_unique([
									...$config['unique_by'],
									...$this->normalizeUniqueByFields($value),
								]));
								$config['unique_by_ignore_case']=true;
							}
							break;
						case 'required_if':
							$config['required_if']=$this->normalizeConditionalExclusion($value);
							break;
						case 'required_unless':
							$config['required_unless']=$this->normalizeConditionalExclusion($value);
							break;
						case 'required_with':
						case 'required_with_all':
						case 'required_without':
						case 'required_without_all':
							$config[(string)$key]=$this->normalizeFieldList($value);
							break;
						case 'present_if':
							$config['present_if']=$this->normalizeConditionalExclusion($value);
							break;
						case 'present_unless':
							$config['present_unless']=$this->normalizeConditionalExclusion($value);
							break;
						case 'present_with':
						case 'present_with_all':
						case 'present_without':
						case 'present_without_all':
							$config[(string)$key]=$this->normalizeFieldList($value);
							break;
						case 'exclude_if':
							$config['exclude_if']=$this->normalizeConditionalExclusion($value);
							break;
						case 'exclude_unless':
							$config['exclude_unless']=$this->normalizeConditionalExclusion($value);
							break;
						case 'exclude_when_blank':
							$config['exclude_when_blank']=(bool)$value;
							break;
						case 'validate':
						case 'validator':
							$config['callbacks']=is_array($value) ? array_values($value) : [$value];
							break;
						case 'field':
							$config['field']=$value===null ? null : (string)$value;
							break;
						case 'label':
							$config['label']=$value===null ? null : (string)$value;
							break;
						case 'messages':
							$config['messages']=is_array($value) ? $value : [];
							break;
					}
				}
			}
		}

		foreach($options as $key=>$value){
			if(in_array((string)$key, ['messages', 'labels'], true)){
				continue;
			}
			$config[$key]=$value;
		}
		if($config['field']!==null){
			$fieldMessages=is_array($config['messages']) ? $config['messages'] : [];
			$scopedMessages=$this->fieldScopedOptionValue(
				is_array($options['messages'] ?? null) ? $options['messages'] : [],
				(string)$config['field'],
				$config['field_pattern']===null ? null : (string)$config['field_pattern'],
			);
			if(is_array($scopedMessages)){
				$fieldMessages=array_replace($scopedMessages, $fieldMessages);
			}
			$config['messages']=$fieldMessages;
			if($config['label']===null){
				$scopedLabel=$this->fieldScopedOptionValue(
					is_array($options['labels'] ?? null) ? $options['labels'] : [],
					(string)$config['field'],
					$config['field_pattern']===null ? null : (string)$config['field_pattern'],
				);
				if($scopedLabel!==null){
					$config['label']=(string)$scopedLabel;
				}
				else
				{
					$config['label']=$this->humanizeField((string)$config['field']);
				}
			}
			$config['labels']=is_array($options['labels'] ?? null) ? $options['labels'] : [];
		}

		if($config['type']==='integer' && $config['cast']===null){
			$config['cast']='integer';
		}
		elseif($config['type']==='float' && $config['cast']===null){
			$config['cast']='float';
		}
		elseif($config['type']==='boolean' && $config['cast']===null){
			$config['cast']='boolean';
		}

		return $config;
	}

	/**
	 * Applies one pipe-rule token to a normalized rule config.
	 *
	 * Token parsing supports both unary flags and `name:value` forms. Unknown
	 * tokens are treated as sanitation types so custom kernel types can be used
	 * without updating the parser.
	 *
	 * @param array<string,mixed> $config Rule config mutated in place.
	 * @param string $token Rule token.
	 */
	private function applyToken(array &$config, string $token): void {
		if($token===''){
			return;
		}
		if(str_contains($token, ':')){
			[$name, $value]=explode(':', $token, 2);
			$name=trim($name);
			$value=trim($value);
			switch($name){
				case 'min':
					$config['min_length']=max(0, (int)$value);
					return;
				case 'max':
					$config['max_length']=max(0, (int)$value);
					return;
				case 'digits':
					$config['digits']=max(0, (int)$value);
					return;
				case 'same':
					$config['same']=$value;
					return;
				case 'different':
					$config['different']=$value;
					return;
				case 'regex':
					$config['regex']=$value;
					return;
				case 'in':
					$config['in']=$value==='' ? [] : array_map('trim', explode(',', $value));
					return;
				case 'not_in':
					$config['not_in']=$value==='' ? [] : array_map('trim', explode(',', $value));
					return;
				case 'starts_with':
					$config['starts_with']=$value==='' ? [] : array_map('trim', explode(',', $value));
					return;
				case 'ends_with':
					$config['ends_with']=$value==='' ? [] : array_map('trim', explode(',', $value));
					return;
				case 'contains':
					$config['contains']=$value==='' ? [] : array_map('trim', explode(',', $value));
					return;
				case 'min_value':
					$config['min_value']=is_numeric($value) ? (float)$value : null;
					return;
				case 'max_value':
					$config['max_value']=is_numeric($value) ? (float)$value : null;
					return;
				case 'min_items':
					$config['min_items']=max(0, (int)$value);
					return;
				case 'max_items':
					$config['max_items']=max(0, (int)$value);
					return;
				case 'distinct':
					$config['distinct']=true;
					$config['distinct_ignore_case']=in_array(strtolower($value), ['ignore_case', 'case_insensitive', 'insensitive'], true);
					return;
				case 'unique_by':
					$config['unique_by']=$this->normalizeUniqueByFields($value);
					return;
				case 'unique_by_ignore_case':
					$config['unique_by']=$this->normalizeUniqueByFields($value);
					$config['unique_by_ignore_case']=true;
					return;
				case 'required_if':
					$config['required_if']=$this->normalizeConditionalExclusion($value);
					return;
				case 'required_unless':
					$config['required_unless']=$this->normalizeConditionalExclusion($value);
					return;
				case 'required_with':
					$config['required_with']=$this->normalizeFieldList($value);
					return;
				case 'required_with_all':
					$config['required_with_all']=$this->normalizeFieldList($value);
					return;
				case 'required_without':
					$config['required_without']=$this->normalizeFieldList($value);
					return;
				case 'required_without_all':
					$config['required_without_all']=$this->normalizeFieldList($value);
					return;
				case 'present_if':
					$config['present_if']=$this->normalizeConditionalExclusion($value);
					return;
				case 'present_unless':
					$config['present_unless']=$this->normalizeConditionalExclusion($value);
					return;
				case 'present_with':
					$config['present_with']=$this->normalizeFieldList($value);
					return;
				case 'present_with_all':
					$config['present_with_all']=$this->normalizeFieldList($value);
					return;
				case 'present_without':
					$config['present_without']=$this->normalizeFieldList($value);
					return;
				case 'present_without_all':
					$config['present_without_all']=$this->normalizeFieldList($value);
					return;
				case 'exclude_if':
					$config['exclude_if']=$this->normalizeConditionalExclusion($value);
					return;
				case 'exclude_unless':
					$config['exclude_unless']=$this->normalizeConditionalExclusion($value);
					return;
			}
		}

		switch($token){
			case 'required':
				$config['required']=true;
				return;
			case 'sometimes':
				$config['sometimes']=true;
				return;
			case 'present':
				$config['must_present']=true;
				return;
			case 'nullable':
				$config['nullable']=true;
				return;
			case 'trim':
				$config['trim']=true;
				return;
			case 'no_trim':
				$config['trim']=false;
				return;
			case 'squish':
				$config['squish']=true;
				return;
			case 'lower':
				$config['lower']=true;
				return;
			case 'upper':
				$config['upper']=true;
				return;
			case 'raw':
				$config['escape_html']=false;
				return;
			case 'accepted':
				$config['accepted']=true;
				return;
			case 'declined':
				$config['declined']=true;
				return;
			case 'distinct':
				$config['distinct']=true;
				return;
			case 'exclude_when_blank':
				$config['exclude_when_blank']=true;
				return;
		}

		$config['type']=$this->normalizeType($token);
	}

	/**
	 * Validates post-sanitation constraints that require normalized values or context.
	 *
	 * This layer enforces item counts, distinctness, exact digits, numeric bounds,
	 * accepted/declined semantics, allow/deny lists, cross-field comparisons,
	 * regex and string boundary checks, and custom callbacks.
	 *
	 * @param mixed $value Sanitized value.
	 * @param array<string,mixed> $config Normalized rule config.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @return ?string Error message, or null when constraints pass.
	 */
	private function validateConstraints(mixed $value, array $config, array $context, array $input): ?string {
		if($config['min_items']!==null){
			if(!is_array($value) || count($value)<(int)$config['min_items']){
				return $this->resolveMessage($config, 'min_items', 'The :field field must contain at least :min_items items.', [
					'min_items'=>(string)(int)$config['min_items'],
				]);
			}
		}
		if($config['max_items']!==null){
			if(!is_array($value) || count($value)>(int)$config['max_items']){
				return $this->resolveMessage($config, 'max_items', 'The :field field may not contain more than :max_items items.', [
					'max_items'=>(string)(int)$config['max_items'],
				]);
			}
		}
		if($config['unique_by']!==[] && !str_contains((string)($config['field_pattern'] ?? $config['field'] ?? ''), '*')){
			if(!is_array($value) || !$this->isListArray($value)){
				return $this->resolveMessage($config, 'unique_by', 'The :field field must be a list to enforce unique item keys.', [
					'keys'=>implode(', ', array_map([$this, 'humanizeField'], $config['unique_by'])),
				]);
			}
			if($this->collectionHasDuplicateBy($value, $config['unique_by'], (bool)$config['unique_by_ignore_case'])){
				return $this->resolveMessage($config, 'unique_by', 'The :field field must contain unique items by :keys.', [
					'keys'=>implode(', ', array_map([$this, 'humanizeField'], $config['unique_by'])),
				]);
			}
		}
		if($config['distinct']===true && is_array($value) && !str_contains((string)($config['field_pattern'] ?? $config['field'] ?? ''), '*')){
			if($this->arrayHasDuplicateValues($value, (bool)$config['distinct_ignore_case'])){
				return $this->resolveMessage($config, 'distinct', 'The :field field must contain distinct values.');
			}
		}
		if($config['digits']!==null){
			$string=(string)$value;
			if(preg_match('/^\d+$/', $string)!==1 || strlen($string)!==(int)$config['digits']){
				return $this->resolveMessage($config, 'digits', 'The :field field must contain exactly :digits digits.', [
					'digits'=>(string)(int)$config['digits'],
				]);
			}
		}
		if($config['min_value']!==null){
			if(!is_numeric($value) || (float)$value<(float)$config['min_value']){
				return $this->resolveMessage($config, 'min_value', 'The :field field must be at least :min_value.', [
					'min_value'=>(string)$config['min_value'],
				]);
			}
		}
		if($config['max_value']!==null){
			if(!is_numeric($value) || (float)$value>(float)$config['max_value']){
				return $this->resolveMessage($config, 'max_value', 'The :field field may not be greater than :max_value.', [
					'max_value'=>(string)$config['max_value'],
				]);
			}
		}
		if($config['accepted']===true){
			if(!in_array((string)$value, ['1', 'true', 'yes', 'on'], true)){
				return $this->resolveMessage($config, 'accepted', 'The :field field must be accepted.');
			}
		}
		if($config['declined']===true){
			if(!in_array((string)$value, ['0', 'false', 'no', 'off'], true)){
				return $this->resolveMessage($config, 'declined', 'The :field field must be declined.');
			}
		}
		if(is_array($config['in']) && $config['in']!==[] && !in_array((string)$value, array_map('strval', $config['in']), true)){
			return $this->resolveMessage($config, 'in', 'The selected :field is invalid.', [
				'values'=>implode(', ', array_map('strval', $config['in'])),
			]);
		}
		if(is_array($config['not_in']) && $config['not_in']!==[] && in_array((string)$value, array_map('strval', $config['not_in']), true)){
			return $this->resolveMessage($config, 'not_in', 'The selected :field is invalid.');
		}
		if($config['same']!==null){
			$other=$this->comparisonValue((string)$config['same'], $context, $input, $config);
			if((string)$value!==(string)$other){
				return $this->resolveMessage($config, 'same', 'The :field field must match :other.', [
					'other'=>$this->otherFieldLabel((string)$config['same'], $config),
				]);
			}
		}
		if($config['different']!==null){
			$other=$this->comparisonValue((string)$config['different'], $context, $input, $config);
			if((string)$value===(string)$other){
				return $this->resolveMessage($config, 'different', 'The :field field must be different from :other.', [
					'other'=>$this->otherFieldLabel((string)$config['different'], $config),
				]);
			}
		}
		if($config['regex']!==null){
			$pattern=(string)$config['regex'];
			if(@preg_match($pattern, (string)$value)!==1){
				return $this->resolveMessage($config, 'regex', 'The :field field format is invalid.');
			}
		}
		if(is_array($config['starts_with']) && $config['starts_with']!==[]){
			$matched=false;
			foreach($config['starts_with'] as $candidate){
				if($candidate!=='' && str_starts_with((string)$value, (string)$candidate)){
					$matched=true;
					break;
				}
			}
			if($matched===false){
				return $this->resolveMessage($config, 'starts_with', 'The :field field must start with one of: :prefixes.', [
					'prefixes'=>implode(', ', array_map('strval', $config['starts_with'])),
				]);
			}
		}
		if(is_array($config['ends_with']) && $config['ends_with']!==[]){
			$matched=false;
			foreach($config['ends_with'] as $candidate){
				if($candidate!=='' && str_ends_with((string)$value, (string)$candidate)){
					$matched=true;
					break;
				}
			}
			if($matched===false){
				return $this->resolveMessage($config, 'ends_with', 'The :field field must end with one of: :suffixes.', [
					'suffixes'=>implode(', ', array_map('strval', $config['ends_with'])),
				]);
			}
		}
		if(is_array($config['contains']) && $config['contains']!==[]){
			foreach($config['contains'] as $candidate){
				if($candidate!=='' && str_contains((string)$value, (string)$candidate)===false){
					return $this->resolveMessage($config, 'contains', 'The :field field must contain :contains.', [
						'contains'=>(string)$candidate,
					]);
				}
			}
		}
		foreach($config['callbacks'] as $callback){
			if(!is_callable($callback)){
				continue;
			}
			$result=$callback($value, $context, $input, $config);
			if($result===true || $result===null){
				continue;
			}
			if(is_string($result) && trim($result)!==''){
				return $result;
			}
			return $this->resolveMessage($config, 'validate', 'The :field field did not pass validation.');
		}
		return null;
	}

	/**
	 * Checks whether schema sanitation must run the post-write validation pass.
	 *
	 * @param array<string,mixed> $config Normalized rule config.
	 * @return bool True when a constraint depends on the sanitized value or schema context.
	 */
	private function hasDeferredSchemaConstraints(array $config): bool {
		return $config['min_items']!==null
			|| $config['max_items']!==null
			|| $config['unique_by']!==[]
			|| $config['distinct']===true
			|| $config['digits']!==null
			|| $config['min_value']!==null
			|| $config['max_value']!==null
			|| $config['accepted']===true
			|| $config['declined']===true
			|| (is_array($config['in']) && $config['in']!==[])
			|| (is_array($config['not_in']) && $config['not_in']!==[])
			|| $config['same']!==null
			|| $config['different']!==null
			|| $config['regex']!==null
			|| (is_array($config['starts_with']) && $config['starts_with']!==[])
			|| (is_array($config['ends_with']) && $config['ends_with']!==[])
			|| (is_array($config['contains']) && $config['contains']!==[])
			|| $config['callbacks']!==[];
	}

	/**
	 * Applies conditional required rules to a normalized config.
	 *
	 * Required-if/unless and required-with/without rules are resolved against the
	 * current sanitized context first, then original input, so later fields can
	 * depend on values already cleaned by earlier schema rules.
	 *
	 * @param array<string,mixed> $config Normalized rule config.
	 * @param array{context?:array<string,mixed>,input?:array<string,mixed>} $options Runtime options containing context and input.
	 * @return array Config with required flag updated.
	 */
	private function applyConditionalRequired(array $config, array $options): array {
		$context=(array)($options['context'] ?? []);
		$input=(array)($options['input'] ?? []);
		if(is_array($config['required_if'] ?? null)){
			$comparison=$this->comparisonValue((string)$config['required_if']['field'], $context, $input, $config);
			if($this->comparisonMatchesAny($comparison, (array)$config['required_if']['values'])){
				$config['required']=true;
			}
		}
		if(is_array($config['required_unless'] ?? null)){
			$comparison=$this->comparisonValue((string)$config['required_unless']['field'], $context, $input, $config);
			if(!$this->comparisonMatchesAny($comparison, (array)$config['required_unless']['values'])){
				$config['required']=true;
			}
		}
		if($config['required_with']!==[] && $this->anyComparisonFieldFilled((array)$config['required_with'], $context, $input, $config)){
			$config['required']=true;
		}
		if($config['required_with_all']!==[] && $this->allComparisonFieldsFilled((array)$config['required_with_all'], $context, $input, $config)){
			$config['required']=true;
		}
		if($config['required_without']!==[] && $this->anyComparisonFieldMissingOrBlank((array)$config['required_without'], $context, $input, $config)){
			$config['required']=true;
		}
		if($config['required_without_all']!==[] && $this->allComparisonFieldsMissingOrBlank((array)$config['required_without_all'], $context, $input, $config)){
			$config['required']=true;
		}
		return $config;
	}

	/**
	 * Applies conditional presence rules to a normalized config.
	 *
	 * Presence rules require a field key to exist even when the value may still be
	 * nullable or blank, which is distinct from requiring a filled value.
	 *
	 * @param array<string,mixed> $config Normalized rule config.
	 * @param array{context?:array<string,mixed>,input?:array<string,mixed>} $options Runtime options containing context and input.
	 * @return array Config with must-present flag updated.
	 */
	private function applyConditionalPresence(array $config, array $options): array {
		$context=(array)($options['context'] ?? []);
		$input=(array)($options['input'] ?? []);
		if(is_array($config['present_if'] ?? null)){
			$comparison=$this->comparisonValue((string)$config['present_if']['field'], $context, $input, $config);
			if($this->comparisonMatchesAny($comparison, (array)$config['present_if']['values'])){
				$config['must_present']=true;
			}
		}
		if(is_array($config['present_unless'] ?? null)){
			$comparison=$this->comparisonValue((string)$config['present_unless']['field'], $context, $input, $config);
			if(!$this->comparisonMatchesAny($comparison, (array)$config['present_unless']['values'])){
				$config['must_present']=true;
			}
		}
		if($config['present_with']!==[] && $this->anyComparisonFieldPresent((array)$config['present_with'], $context, $input, $config)){
			$config['must_present']=true;
		}
		if($config['present_with_all']!==[] && $this->allComparisonFieldsPresent((array)$config['present_with_all'], $context, $input, $config)){
			$config['must_present']=true;
		}
		if($config['present_without']!==[] && $this->anyComparisonFieldMissing((array)$config['present_without'], $context, $input, $config)){
			$config['must_present']=true;
		}
		if($config['present_without_all']!==[] && $this->allComparisonFieldsMissing((array)$config['present_without_all'], $context, $input, $config)){
			$config['must_present']=true;
		}
		return $config;
	}

	/**
	 * Decides whether a schema rule should execute for a field target.
	 *
	 * `sometimes` skips absent fields, `when` requires a matching condition, and
	 * `unless` suppresses the rule when its condition matches.
	 *
	 * @param array<string,mixed> $config Normalized rule config.
	 * @param bool $present Whether the target field exists in input.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $data Sanitized data produced so far.
	 * @return bool Rule processing decision.
	 */
	private function shouldProcessSchemaRule(array $config, bool $present, array $input, array $data): bool {
		if(($config['sometimes'] ?? false)===true && $present===false){
			return false;
		}
		if($config['when']!==null && !$this->schemaConditionMatches($config['when'], $data, $input, $config)){
			return false;
		}
		if($config['unless']!==null && $this->schemaConditionMatches($config['unless'], $data, $input, $config)){
			return false;
		}
		return true;
	}

	/**
	 * Decides whether a field should be excluded from sanitized output.
	 *
	 * Exclusion happens before absence/default handling so an excluded blank field
	 * can actively remove a defaulted value from schema output.
	 *
	 * @param mixed $value Raw field value.
	 * @param array<string,mixed> $config Normalized rule config.
	 * @param array{context?:array<string,mixed>,input?:array<string,mixed>} $options Runtime options containing context and input.
	 * @param bool $present Whether the target field exists in input.
	 * @return bool Exclusion decision.
	 */
	private function shouldExclude(mixed $value, array $config, array $options, bool $present): bool {
		if(($config['exclude_when_blank'] ?? false)===true && $this->isBlankForExclusion($value, $config, $present)){
			return true;
		}
		$context=(array)($options['context'] ?? []);
		$input=(array)($options['input'] ?? []);
		if(is_array($config['exclude_if'] ?? null)){
			$comparison=$this->comparisonValue((string)$config['exclude_if']['field'], $context, $input, $config);
			if($this->comparisonMatchesAny($comparison, (array)$config['exclude_if']['values'])){
				return true;
			}
		}
		if(is_array($config['exclude_unless'] ?? null)){
			$comparison=$this->comparisonValue((string)$config['exclude_unless']['field'], $context, $input, $config);
			if(!$this->comparisonMatchesAny($comparison, (array)$config['exclude_unless']['values'])){
				return true;
			}
		}
		return false;
	}

	/**
	 * Applies distinct constraints across wildcard-expanded field groups.
	 *
	 * Per-field wildcard targets are validated after all values have been written,
	 * allowing `items.*.sku|distinct` to compare siblings and remove only the
	 * duplicate field that failed.
	 *
	 * @param list<array{field:string,path:list<string>,wildcards:list<string>}> $targets Expanded schema targets.
	 * @param array<string,array<string,mixed>> $configs Normalized configs keyed by concrete field.
	 * @param array<string,mixed> $data Sanitized data mutated when duplicates are removed.
	 * @param array<string,string> $errors Error map mutated with duplicate failures.
	 */
	private function applyDistinctConstraints(array $targets, array $configs, array &$data, array &$errors): void {
		$groups=[];
		foreach($targets as $target){
			$field=$target['field'];
			if(isset($errors[$field]) || !isset($configs[$field])){
				continue;
			}
			$config=$configs[$field];
			if(($config['distinct'] ?? false)!==true){
				continue;
			}
			$pattern=(string)($config['field_pattern'] ?? $field);
			if(!str_contains($pattern, '*')){
				continue;
			}
			$dataField=$this->pathValue($data, $field);
			if($dataField['present']===false){
				continue;
			}
			$groups[$pattern][]=[
				'field'=>$field,
				'value'=>$dataField['value'],
				'config'=>$config,
			];
		}

		foreach($groups as $entries){
			$seen=[];
			foreach($entries as $entry){
				$fingerprint=$this->distinctFingerprint($entry['value'], (bool)$entry['config']['distinct_ignore_case']);
				if(!array_key_exists($fingerprint, $seen)){
					$seen[$fingerprint]=$entry['field'];
					continue;
				}
				$errors[$entry['field']]=$this->resolveMessage($entry['config'], 'distinct', 'The :field field has a duplicate value.');
				$this->unsetPathValue($data, $entry['field']);
			}
		}
	}

	/**
	 * Resolves and interpolates a validation message.
	 *
	 * Rule-specific custom messages override defaults, then placeholder values
	 * such as field, other, min, max, values, and keys are substituted.
	 *
	 * @param array<string,mixed> $config Normalized rule config.
	 * @param string $rule Rule message key.
	 * @param string $default Default message template.
	 * @param array<string,string> $replacements Additional placeholder replacements.
	 * @return string Interpolated validation message.
	 */
	private function resolveMessage(array $config, string $rule, string $default, array $replacements=[]): string {
		$message=$config['messages'][$rule] ?? $default;
		$replacements=array_merge([
			'field'=>(string)($config['label'] ?? $this->humanizeField((string)($config['field'] ?? 'value'))),
			'type'=>(string)($config['type'] ?? ''),
			'min'=>(string)($config['min_length'] ?? ''),
			'max'=>(string)($config['max_length'] ?? ''),
			'digits'=>(string)($config['digits'] ?? ''),
			'min_value'=>(string)($config['min_value'] ?? ''),
			'max_value'=>(string)($config['max_value'] ?? ''),
			'min_items'=>(string)($config['min_items'] ?? ''),
			'max_items'=>(string)($config['max_items'] ?? ''),
			'other'=>'',
			'values'=>'',
			'keys'=>'',
			'prefixes'=>'',
			'suffixes'=>'',
			'contains'=>'',
		], $replacements);
		foreach($replacements as $key=>$value){
			$message=str_replace(':'.$key, (string)$value, $message);
		}
		return $message;
	}

	/**
	 * Converts a dot-path or snake_case field name into a readable label.
	 *
	 * @param string $field Field path.
	 * @return string Human-readable field label.
	 */
	private function humanizeField(string $field): string {
		if(isset($this->humanizedFields[$field])){
			return $this->humanizedFields[$field];
		}
		$original=$field;
		$field=str_replace(['.', '_'], ' ', $field);
		$field=preg_replace('/\s+/u', ' ', trim($field)) ?? trim($field);
		if($field===''){
			return $this->humanizedFields[$original]='value';
		}
		if(count($this->humanizedFields)>=128){
			$this->humanizedFields=[];
		}
		return $this->humanizedFields[$original]=ucfirst($field);
	}

	/**
	 * Resolves the label used for a comparison field in validation messages.
	 *
	 * Scoped labels can target concrete wildcard paths or wildcard patterns before
	 * falling back to a humanized field path.
	 *
	 * @param string $field Comparison field path from the rule.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return string Human-readable comparison label.
	 */
	private function otherFieldLabel(string $field, array $config): string {
		$resolvedField=$this->resolveComparisonField($field, $config);
		if(isset($config['labels']) && is_array($config['labels'])){
			$label=$this->fieldScopedOptionValue($config['labels'], $resolvedField, $field);
			if($label!==null){
				return (string)$label;
			}
		}
		return $this->humanizeField($resolvedField);
	}

	/**
	 * Reads a comparison value from sanitized context or original input.
	 *
	 * Sanitized context wins so comparisons use cleaned values when the referenced
	 * field has already been processed.
	 *
	 * @param string $field Comparison field path.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return mixed sanitized comparison value when already available, original input value as fallback, or null when absent.
	 */
	private function comparisonValue(string $field, array $context, array $input, array $config): mixed {
		$field=$this->resolveComparisonField($field, $config);
		$value=$this->pathValue($context, $field);
		if($value['present']===true){
			return $value['value'];
		}
		$value=$this->pathValue($input, $field);
		return $value['present']===true ? $value['value'] : null;
	}

	/**
	 * Expands a schema field pattern into concrete field targets.
	 *
	 * Non-wildcard fields map to themselves. Wildcard fields are expanded against
	 * the input data and retain wildcard values for relative comparisons.
	 *
	 * @param array<string,mixed> $input Original input data.
	 * @param string $field Schema field path or wildcard pattern.
	 * @return array Expanded target descriptors.
	 */
	private function schemaTargets(array $input, string $field): array {
		if(!str_contains($field, '*')){
			return [[
				'field'=>$field,
				'path_segments'=>str_contains($field, '.') ? explode('.', $field) : null,
				'pattern'=>$field,
				'wildcard_values'=>[],
			]];
		}
		$matches=$this->wildcardPathMatches($input, $field);
		$targets=[];
		foreach($matches as $match){
			$targets[]=[
				'field'=>$match['path'],
				'path_segments'=>$match['path_segments'],
				'pattern'=>$field,
				'wildcard_values'=>$match['wildcard_values'],
			];
		}
		return $targets;
	}

	/**
	 * Finds concrete input paths matching a wildcard schema pattern.
	 *
	 * @param array<string,mixed> $source Original input data.
	 * @param string $pattern Dot-path pattern containing `*` segments.
	 * @return array Matched paths with wildcard values.
	 */
	private function wildcardPathMatches(array $source, string $pattern): array {
		$matches=[];
		$this->walkWildcardPath($source, explode('.', $pattern), [], [], $matches);
		return $matches;
	}

	/**
	 * Recursively walks input data to expand wildcard path matches.
	 *
	 * @param mixed $current Current input subtree.
	 * @param list<string> $segments Remaining pattern segments.
	 * @param list<string> $pathSegments Concrete path segments collected so far.
	 * @param list<string> $wildcardValues Values captured for wildcard segments.
	 * @param list<array{field:string,path:list<string>,wildcards:list<string>}> $matches Match list mutated in place.
	 */
	private function walkWildcardPath(mixed $current, array $segments, array $pathSegments, array $wildcardValues, array &$matches): void {
		if($segments===[]){
			$matches[]=[
				'path'=>implode('.', $pathSegments),
				'path_segments'=>$pathSegments,
				'wildcard_values'=>$wildcardValues,
			];
			return;
		}
		$segment=array_shift($segments);
		if($segment==='*'){
			if(!is_array($current)){
				return;
			}
			foreach($current as $key=>$value){
				$key=(string)$key;
				$this->walkWildcardPath($value, $segments, [...$pathSegments, $key], [...$wildcardValues, $key], $matches);
			}
			return;
		}
		if(!is_array($current) || !array_key_exists($segment, $current)){
			return;
		}
		$this->walkWildcardPath($current[$segment], $segments, [...$pathSegments, $segment], $wildcardValues, $matches);
	}

	/**
	 * Resolves a field-scoped option such as label or message.
	 *
	 * Exact concrete field keys win, then the schema pattern, then any matching
	 * wildcard option key.
	 *
	 * @param array<string,mixed> $options Field-scoped option map.
	 * @param string $field Concrete field path.
	 * @param ?string $pattern Original schema field pattern.
	 * @return mixed Scoped option value, or null when absent.
	 */
	private function fieldScopedOptionValue(array $options, string $field, ?string $pattern=null): mixed {
		if(array_key_exists($field, $options)){
			return $options[$field];
		}
		if($pattern!==null && array_key_exists($pattern, $options)){
			return $options[$pattern];
		}
		foreach($options as $candidate=>$value){
			if(!is_string($candidate) || !str_contains($candidate, '*')){
				continue;
			}
			if($this->fieldPatternMatches($candidate, $field)){
				return $value;
			}
		}
		return null;
	}

	/**
	 * Checks whether a wildcard field pattern matches a concrete field path.
	 *
	 * @param string $pattern Dot-path pattern that may contain `*` segments.
	 * @param string $field Concrete field path.
	 * @return bool Pattern match decision.
	 */
	private function fieldPatternMatches(string $pattern, string $field): bool {
		$patternSegments=explode('.', $pattern);
		$fieldSegments=explode('.', $field);
		if(count($patternSegments)!==count($fieldSegments)){
			return false;
		}
		foreach($patternSegments as $index=>$segment){
			if($segment==='*'){
				continue;
			}
			if($segment!==$fieldSegments[$index]){
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolves a comparison field relative to the current wildcard field.
	 *
	 * Wildcard placeholders are replaced with captured values first. Bare field
	 * names inside nested rules are resolved relative to the current field's
	 * parent path.
	 *
	 * @param string $field Comparison field path from a rule.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return string Concrete comparison field path.
	 */
	private function resolveComparisonField(string $field, array $config): string {
		$field=$this->applyWildcardValues($field, is_array($config['wildcard_values'] ?? null) ? $config['wildcard_values'] : []);
		if(!str_contains($field, '.') && isset($config['field']) && is_string($config['field']) && str_contains($config['field'], '.')){
			$segments=explode('.', $config['field']);
			array_pop($segments);
			$segments[]=$field;
			return implode('.', $segments);
		}
		return $field;
	}

	/**
	 * Substitutes captured wildcard values into a comparison path.
	 *
	 * @param string $path Path that may contain wildcard placeholders.
	 * @param list<string> $wildcardValues Captured wildcard values.
	 * @return string Concrete path.
	 */
	private function applyWildcardValues(string $path, array $wildcardValues): string {
		foreach($wildcardValues as $wildcardValue){
			if(!str_contains($path, '*')){
				break;
			}
			$path=preg_replace('/\*/', (string)$wildcardValue, $path, 1) ?? $path;
		}
		return $path;
	}

	/**
	 * Reads a value and presence flag from an array using dot-path notation.
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
		return $this->pathValueSegments($source, explode('.', $path));
	}

	/**
	 * Reads a value from an array using pre-split path segments.
	 *
	 * @param array<string,mixed> $source Source array.
	 * @param list<string> $segments Pre-split path segments.
	 * @return array{present:bool,value:mixed} Path lookup result.
	 */
	private function pathValueSegments(array $source, array $segments): array {
		if(count($segments)===1){
			$path=$segments[0];
			$present=array_key_exists($path, $source);
			return [
				'present'=>$present,
				'value'=>$present ? $source[$path] : null,
			];
		}
		$current=$source;
		foreach($segments as $segment){
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
	 * Missing intermediate arrays are created so schema output can materialize
	 * nested values even when defaults did not contain the full path.
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
		$this->setPathValueSegments($target, explode('.', $path), $value);
	}

	/**
	 * Writes a value into an array using pre-split path segments.
	 *
	 * @param array<string,mixed> $target Target array passed by reference.
	 * @param list<string> $segments Pre-split path segments.
	 * @param mixed $value Value to write.
	 */
	private function setPathValueSegments(array &$target, array $segments, mixed $value): void {
		if(count($segments)===1){
			$target[$segments[0]]=$value;
			return;
		}
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
		$this->unsetPathValueSegments($target, explode('.', $path));
	}

	/**
	 * Removes a value from an array using pre-split path segments.
	 *
	 * @param array<string,mixed> $target Target array passed by reference.
	 * @param list<string> $segments Pre-split path segments.
	 */
	private function unsetPathValueSegments(array &$target, array $segments): void {
		if(count($segments)===1){
			unset($target[$segments[0]]);
			return;
		}
		$last=array_pop($segments);
		$current=&$target;
		foreach($segments as $segment){
			if(!isset($current[$segment]) || !is_array($current[$segment])){
				return;
			}
			$current=&$current[$segment];
		}
		if($last!==null){
			unset($current[$last]);
		}
	}

	/**
	 * Normalizes rule type aliases to kernel sanitation type names.
	 *
	 * @param string $type Raw type token.
	 * @return string Normalized sanitation type.
	 */
	private function normalizeType(string $type): string {
		$type=strtolower(trim($type));
		return match($type){
			'text'=>'default',
			'html'=>'basic_html',
			'raw_html'=>'unrestricted',
			'arr'=>'array',
			'phone'=>'phone_number',
			'name'=>'person_name',
			'int'=>'integer',
			'bool'=>'boolean',
			'postal'=>'postal_code',
			default=>$type==='' ? 'default' : $type,
		};
	}

	/**
	 * Checks whether an array has sequential list keys.
	 *
	 * @param array<int|string,mixed> $value Array to inspect.
	 * @return bool List-array decision.
	 */
	private function isListArray(array $value): bool {
		if($value===[]){
			return true;
		}
		return array_keys($value)===range(0, count($value)-1);
	}

	/**
	 * Detects duplicate values in an array using sanitation distinct fingerprints.
	 *
	 * @param array<int|string,mixed> $values Values to compare.
	 * @param bool $ignoreCase Whether string comparisons ignore case.
	 * @return bool Duplicate detection result.
	 */
	private function arrayHasDuplicateValues(array $values, bool $ignoreCase): bool {
		$seen=[];
		foreach($values as $value){
			$fingerprint=$this->distinctFingerprint($value, $ignoreCase);
			if(array_key_exists($fingerprint, $seen)){
				return true;
			}
			$seen[$fingerprint]=true;
		}
		return false;
	}

	/**
	 * Detects duplicate items by one or more relative item fields.
	 *
	 * Items that have no comparable value for any configured field are skipped so
	 * empty placeholder rows do not collide with each other.
	 *
	 * @param list<array<string,mixed>|object> $items List of array/object items.
	 * @param list<string> $fields Relative field paths used as the uniqueness key.
	 * @param bool $ignoreCase Whether string comparisons ignore case.
	 * @return bool Duplicate detection result.
	 */
	private function collectionHasDuplicateBy(array $items, array $fields, bool $ignoreCase): bool {
		$seen=[];
		foreach($items as $item){
			$values=[];
			$hasComparableValue=false;
			foreach($fields as $field){
				$resolved=$this->relativePathValue($item, $field);
				$fieldValue=$resolved['present']===true ? $resolved['value'] : null;
				$values[$field]=$fieldValue;
				if($this->hasComparableUniqueValue($fieldValue)){
					$hasComparableValue=true;
				}
			}
			if($hasComparableValue===false){
				continue;
			}
			$fingerprint=$this->distinctFingerprint($values, $ignoreCase);
			if(array_key_exists($fingerprint, $seen)){
				return true;
			}
			$seen[$fingerprint]=true;
		}
		return false;
	}

	/**
	 * Builds a stable fingerprint for distinct and uniqueness comparisons.
	 *
	 * @param mixed $value Value to fingerprint.
	 * @param bool $ignoreCase Whether string comparisons ignore case.
	 * @return string Stable comparison fingerprint.
	 */
	private function distinctFingerprint(mixed $value, bool $ignoreCase): string {
		$normalized=$this->normalizeDistinctValue($value, $ignoreCase);
		return json_encode($normalized, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: serialize($normalized);
	}

	/**
	 * Normalizes values before distinct fingerprinting.
	 *
	 * Type tags are preserved so values such as string "1", integer 1, and boolean
	 * true remain distinct unless a rule explicitly transforms them earlier.
	 *
	 * @param mixed $value Value to normalize.
	 * @param bool $ignoreCase Whether string values are lowercased.
	 * @return mixed type-tagged scalar, stringable, list, associative array, or object marker used for distinct fingerprints.
	 */
	private function normalizeDistinctValue(mixed $value, bool $ignoreCase): mixed {
		if(is_string($value)){
			return [
				'type'=>'string',
				'value'=>$ignoreCase ? mb_strtolower($value, 'UTF-8') : $value,
			];
		}
		if(is_int($value) || is_float($value) || is_bool($value) || $value===null){
			return [
				'type'=>gettype($value),
				'value'=>$value,
			];
		}
		if($value instanceof \Stringable){
			$string=(string)$value;
			return [
				'type'=>'stringable',
				'value'=>$ignoreCase ? mb_strtolower($string, 'UTF-8') : $string,
			];
		}
		if(is_array($value)){
			if($this->isListArray($value)){
				return [
					'type'=>'list',
					'value'=>array_map(fn(mixed $item): mixed => $this->normalizeDistinctValue($item, $ignoreCase), $value),
				];
			}
			ksort($value);
			$normalized=[];
			foreach($value as $key=>$item){
				$normalized[(string)$key]=$this->normalizeDistinctValue($item, $ignoreCase);
			}
			return [
				'type'=>'array',
				'value'=>$normalized,
			];
		}
		return [
			'type'=>get_debug_type($value),
			'value'=>serialize($value),
		];
	}

	/**
	 * Normalizes a unique-by field definition into field path strings.
	 *
	 * @param mixed $value Comma-delimited string, list, or scalar field value.
	 * @return array Non-empty relative field paths.
	 */
	private function normalizeUniqueByFields(mixed $value): array {
		if(is_string($value)){
			$values=explode(',', $value);
		}
		elseif(is_array($value)){
			$values=$value;
		}
		else
		{
			$values=[$value];
		}
		$parts=[];
		foreach($values as $part){
			$part=trim((string)$part);
			if($part!==''){
				$parts[]=$part;
			}
		}
		return $parts;
	}

	/**
	 * Reads a value from an array or object using a relative dot-path.
	 *
	 * Object public properties are supported for uniqueness checks over hydrated
	 * DTO-style items.
	 *
	 * @param mixed $source Source array, object, or scalar.
	 * @param string $path Relative dot-path.
	 * @return array{present:bool,value:mixed} Relative lookup result.
	 */
	private function relativePathValue(mixed $source, string $path): array {
		if($path===''){
			return [
				'present'=>true,
				'value'=>$source,
			];
		}
		$current=$source;
		foreach(explode('.', $path) as $segment){
			if(is_array($current) && array_key_exists($segment, $current)){
				$current=$current[$segment];
				continue;
			}
			if(is_object($current) && (property_exists($current, $segment) || isset($current->{$segment}))){
				$current=$current->{$segment};
				continue;
			}
			return [
				'present'=>false,
				'value'=>null,
			];
		}
		return [
			'present'=>true,
			'value'=>$current,
		];
	}

	/**
	 * Normalizes field/value conditional rule syntax.
	 *
	 * Accepts compact comma strings, positional arrays, or associative arrays with
	 * field and values/value keys.
	 *
	 * @param mixed $value Raw conditional definition.
	 * @return ?array{field:string,values:array} Normalized condition or null.
	 */
	private function normalizeConditionalExclusion(mixed $value): ?array {
		if(is_string($value)){
			$parts=array_map('trim', explode(',', $value));
		}
		elseif(is_array($value)){
			if(array_key_exists('field', $value)){
				$field=trim((string)$value['field']);
				if($field===''){
					return null;
				}
				return [
					'field'=>$field,
					'values'=>$this->normalizeConditionalExclusionValues($value['values'] ?? $value['value'] ?? []),
				];
			}
			$parts=array_map(static fn(mixed $part): string => trim((string)$part), array_values($value));
		}
		else
		{
			$parts=[trim((string)$value)];
		}

		if($parts===[]){
			return null;
		}
		$field=(string)array_shift($parts);
		if($field===''){
			return null;
		}
		return [
			'field'=>$field,
			'values'=>$this->normalizeConditionalExclusionValues($parts),
		];
	}

	/**
	 * Normalizes schema-level when/unless conditions.
	 *
	 * Conditions may be callbacks, arrays with callback, arrays describing field
	 * value/presence/filled/blank requirements, or compact field/value syntax.
	 *
	 * @param mixed $value Raw schema condition.
	 * @return array|callable|null Normalized condition, callback, or null.
	 */
	private function normalizeSchemaCondition(mixed $value): array|callable|null {
		if($value===null || $value===false || $value===''){
			return null;
		}
		if(is_callable($value)){
			return $value;
		}
		if(is_array($value) && array_key_exists('callback', $value) && is_callable($value['callback'])){
			return $value['callback'];
		}
		if(is_array($value) && array_key_exists('field', $value)){
			$field=trim((string)$value['field']);
			if($field===''){
				return null;
			}
			$condition=[
				'field'=>$field,
				'values'=>[],
				'present'=>null,
				'filled'=>null,
				'blank'=>null,
			];
			if(array_key_exists('values', $value) || array_key_exists('value', $value)){
				$condition['values']=$this->normalizeConditionalExclusionValues($value['values'] ?? $value['value'] ?? []);
			}
			if(array_key_exists('present', $value)){
				$condition['present']=(bool)$value['present'];
			}
			if(array_key_exists('filled', $value)){
				$condition['filled']=(bool)$value['filled'];
			}
			if(array_key_exists('blank', $value)){
				$condition['blank']=(bool)$value['blank'];
			}
			if($condition['values']===[] && $condition['present']===null && $condition['filled']===null && $condition['blank']===null){
				return null;
			}
			return $condition;
		}
		$comparison=$this->normalizeConditionalExclusion($value);
		if($comparison===null){
			return null;
		}
		return [
			'field'=>$comparison['field'],
			'values'=>$comparison['values'],
			'present'=>null,
			'filled'=>null,
			'blank'=>null,
		];
	}

	/**
	 * Normalizes a related-field list for conditional required/present rules.
	 *
	 * @param mixed $value Comma-delimited string, list, or scalar field value.
	 * @return array Non-empty field paths.
	 */
	private function normalizeFieldList(mixed $value): array {
		if(is_string($value)){
			$values=explode(',', $value);
		}
		elseif(is_array($value)){
			$values=$value;
		}
		else
		{
			$values=[$value];
		}
		$parts=[];
		foreach($values as $part){
			$part=trim((string)$part);
			if($part!==''){
				$parts[]=$part;
			}
		}
		return $parts;
	}

	/**
	 * Normalizes expected values used by field/value conditional rules.
	 *
	 * @param mixed $value Raw expected value or list.
	 * @return array Expected values.
	 */
	private function normalizeConditionalExclusionValues(mixed $value): array {
		if(is_array($value)){
			return array_values($value);
		}
		if(is_string($value)){
			return $value==='' ? [] : array_map('trim', explode(',', $value));
		}
		return [$value];
	}

	/**
	 * Checks whether an actual comparison value matches any expected value.
	 *
	 * @param mixed $actual Actual value.
	 * @param list<scalar|null> $expectedValues Expected value list.
	 * @return bool Match decision.
	 */
	private function comparisonMatchesAny(mixed $actual, array $expectedValues): bool {
		foreach($expectedValues as $expectedValue){
			if($this->comparisonEquivalent($actual, $expectedValue)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluates a normalized schema when/unless condition.
	 *
	 * Callback conditions receive input, sanitized context, and field metadata.
	 * Structured conditions can test presence, filledness, blankness, and values
	 * against a comparison field resolved with wildcard context.
	 *
	 * @param array|callable $condition Normalized condition.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool Condition match decision.
	 */
	private function schemaConditionMatches(array|callable $condition, array $context, array $input, array $config): bool {
		if(is_callable($condition)){
			return (bool)$condition($input, $context, [
				'field'=>$config['field'] ?? null,
				'field_pattern'=>$config['field_pattern'] ?? null,
				'wildcard_values'=>$config['wildcard_values'] ?? [],
				'config'=>$config,
			]);
		}
		$field=(string)($condition['field'] ?? '');
		if($field===''){
			return false;
		}
		if(array_key_exists('present', $condition) && $condition['present']!==null){
			$present=$this->comparisonFieldPresent($field, $context, $input, $config);
			if($present!==(bool)$condition['present']){
				return false;
			}
		}
		if(array_key_exists('filled', $condition) && $condition['filled']!==null){
			$filled=$this->comparisonFieldFilled($field, $context, $input, $config);
			if($filled!==(bool)$condition['filled']){
				return false;
			}
		}
		if(array_key_exists('blank', $condition) && $condition['blank']!==null){
			$blank=$this->comparisonFieldMissingOrBlank($field, $context, $input, $config);
			if($blank!==(bool)$condition['blank']){
				return false;
			}
		}
		if(($condition['values'] ?? [])!==[]){
			$comparison=$this->comparisonValue($field, $context, $input, $config);
			if(!$this->comparisonMatchesAny($comparison, (array)$condition['values'])){
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks whether any related comparison field is filled.
	 *
	 * @param list<string> $fields Related field paths.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool Filled-field decision.
	 */
	private function anyComparisonFieldFilled(array $fields, array $context, array $input, array $config): bool {
		foreach($fields as $field){
			if($this->comparisonFieldFilled((string)$field, $context, $input, $config)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether any related comparison field is present.
	 *
	 * @param list<string> $fields Related field paths.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool Presence decision.
	 */
	private function anyComparisonFieldPresent(array $fields, array $context, array $input, array $config): bool {
		foreach($fields as $field){
			if($this->comparisonFieldPresent((string)$field, $context, $input, $config)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether all related comparison fields are present.
	 *
	 * Empty field lists return false because no condition was actually declared.
	 *
	 * @param list<string> $fields Related field paths.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool All-present decision.
	 */
	private function allComparisonFieldsPresent(array $fields, array $context, array $input, array $config): bool {
		if($fields===[]){
			return false;
		}
		foreach($fields as $field){
			if(!$this->comparisonFieldPresent((string)$field, $context, $input, $config)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks whether all related comparison fields are filled.
	 *
	 * @param list<string> $fields Related field paths.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool All-filled decision.
	 */
	private function allComparisonFieldsFilled(array $fields, array $context, array $input, array $config): bool {
		if($fields===[]){
			return false;
		}
		foreach($fields as $field){
			if(!$this->comparisonFieldFilled((string)$field, $context, $input, $config)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks whether any related comparison field is missing or blank.
	 *
	 * @param list<string> $fields Related field paths.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool Missing-or-blank decision.
	 */
	private function anyComparisonFieldMissingOrBlank(array $fields, array $context, array $input, array $config): bool {
		foreach($fields as $field){
			if($this->comparisonFieldMissingOrBlank((string)$field, $context, $input, $config)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether any related comparison field is missing.
	 *
	 * @param list<string> $fields Related field paths.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool Missing-field decision.
	 */
	private function anyComparisonFieldMissing(array $fields, array $context, array $input, array $config): bool {
		foreach($fields as $field){
			if($this->comparisonFieldMissing((string)$field, $context, $input, $config)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether all related comparison fields are missing.
	 *
	 * @param list<string> $fields Related field paths.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool All-missing decision.
	 */
	private function allComparisonFieldsMissing(array $fields, array $context, array $input, array $config): bool {
		if($fields===[]){
			return false;
		}
		foreach($fields as $field){
			if(!$this->comparisonFieldMissing((string)$field, $context, $input, $config)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks whether all related comparison fields are missing or blank.
	 *
	 * @param list<string> $fields Related field paths.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool All-missing-or-blank decision.
	 */
	private function allComparisonFieldsMissingOrBlank(array $fields, array $context, array $input, array $config): bool {
		if($fields===[]){
			return false;
		}
		foreach($fields as $field){
			if(!$this->comparisonFieldMissingOrBlank((string)$field, $context, $input, $config)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Compares two values using sanitation comparison semantics.
	 *
	 * Scalars compare through normalized string forms. Complex values compare via
	 * distinct fingerprints so arrays and stringable values remain deterministic.
	 *
	 * @param mixed $left Left value.
	 * @param mixed $right Right value.
	 * @return bool Equivalence decision.
	 */
	private function comparisonEquivalent(mixed $left, mixed $right): bool {
		$leftScalar=$this->comparisonScalarValue($left);
		$rightScalar=$this->comparisonScalarValue($right);
		if($leftScalar!==null && $rightScalar!==null){
			return $leftScalar===$rightScalar;
		}
		return $this->distinctFingerprint($left, false)===$this->distinctFingerprint($right, false);
	}

	/**
	 * Converts comparable scalar-like values into normalized strings.
	 *
	 * @param mixed $value Value to normalize.
	 * @return ?string Scalar comparison value, or null for complex values.
	 */
	private function comparisonScalarValue(mixed $value): ?string {
		if($value===null){
			return 'null';
		}
		if(is_bool($value)){
			return $value ? '1' : '0';
		}
		if(is_int($value) || is_float($value)){
			return trim((string)$value);
		}
		if(is_string($value)){
			return trim($value);
		}
		if($value instanceof \Stringable){
			return trim((string)$value);
		}
		return null;
	}

	/**
	 * Checks whether a comparison field exists and is not blank.
	 *
	 * @param string $field Comparison field path.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool Filled-state decision.
	 */
	private function comparisonFieldFilled(string $field, array $context, array $input, array $config): bool {
		$state=$this->comparisonFieldState($field, $context, $input, $config);
		if($state['present']===false){
			return false;
		}
		return !$this->isBlankValue($state['value'], $config);
	}

	/**
	 * Checks whether a comparison field exists.
	 *
	 * @param string $field Comparison field path.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool Presence decision.
	 */
	private function comparisonFieldPresent(string $field, array $context, array $input, array $config): bool {
		return $this->comparisonFieldState($field, $context, $input, $config)['present']===true;
	}

	/**
	 * Checks whether a comparison field is absent or blank.
	 *
	 * @param string $field Comparison field path.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool Missing-or-blank decision.
	 */
	private function comparisonFieldMissingOrBlank(string $field, array $context, array $input, array $config): bool {
		$state=$this->comparisonFieldState($field, $context, $input, $config);
		return $state['present']===false || $this->isBlankValue($state['value'], $config);
	}

	/**
	 * Checks whether a comparison field is absent.
	 *
	 * @param string $field Comparison field path.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool Missing-field decision.
	 */
	private function comparisonFieldMissing(string $field, array $context, array $input, array $config): bool {
		return $this->comparisonFieldState($field, $context, $input, $config)['present']===false;
	}

	/**
	 * Resolves a comparison field to its present/value state.
	 *
	 * Sanitized context is checked before raw input, mirroring comparisonValue().
	 *
	 * @param string $field Comparison field path.
	 * @param array<string,mixed> $context Sanitized data produced so far.
	 * @param array<string,mixed> $input Original input data.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return array{present:bool,value:mixed} Comparison field state.
	 */
	private function comparisonFieldState(string $field, array $context, array $input, array $config): array {
		$field=$this->resolveComparisonField($field, $config);
		$value=$this->pathValue($context, $field);
		if($value['present']===true){
			return $value;
		}
		return $this->pathValue($input, $field);
	}

	/**
	 * Applies sanitation blank-value semantics.
	 *
	 * Null, empty strings after configured trim/squish, and empty arrays are blank.
	 * Other scalar and object values are considered filled.
	 *
	 * @param mixed $value Value to inspect.
	 * @param array<string,mixed> $config Current field rule config.
	 * @return bool Blank-value decision.
	 */
	private function isBlankValue(mixed $value, array $config): bool {
		if($value===null){
			return true;
		}
		if(is_string($value) || $value instanceof \Stringable){
			$string=(string)$value;
			if(($config['trim'] ?? true)===true){
				$string=trim($string);
			}
			if(($config['squish'] ?? false)===true){
				$string=preg_replace('/\s+/u', ' ', trim($string)) ?? trim($string);
			}
			return $string==='';
		}
		if(is_array($value)){
			return $value===[];
		}
		return false;
	}

	/**
	 * Applies the stricter blank check used by exclusion rules.
	 *
	 * Absent values count as blank for exclusion purposes, allowing blank-exclude
	 * rules to remove defaulted output.
	 *
	 * @param mixed $value Raw value.
	 * @param array<string,mixed> $config Current field rule config.
	 * @param bool $present Whether the input field exists.
	 * @return bool Blank-for-exclusion decision.
	 */
	private function isBlankForExclusion(mixed $value, array $config, bool $present): bool {
		if($present===false || $value===null){
			return true;
		}
		return $this->isBlankValue($value, $config);
	}

	/**
	 * Checks whether a value should participate in unique-by comparison.
	 *
	 * Null, empty strings, and empty arrays are ignored so incomplete item rows do
	 * not falsely collide.
	 *
	 * @param mixed $value Candidate unique-key value.
	 * @return bool Comparable-value decision.
	 */
	private function hasComparableUniqueValue(mixed $value): bool {
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

	/**
	 * Checks whether an input tree can be cached by exact value.
	 *
	 * @param array<int|string,mixed> $values Candidate input tree.
	 * @return bool True when the tree contains only scalar, null, and array values.
	 */
	private function isCacheableTree(array $values): bool {
		foreach($values as $value){
			if(is_array($value)){
				if(!$this->isCacheableTree($value)){
					return false;
				}
				continue;
			}
			if($value!==null && !is_scalar($value)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalizes flexible rule option values to booleans.
	 *
	 * String values commonly used in configuration such as "false", "off", and
	 * "no" are treated as false.
	 *
	 * @param mixed $value Raw rule option value.
	 * @return bool Truthy-rule decision.
	 */
	private function truthyRuleValue(mixed $value): bool {
		if(is_bool($value)){
			return $value;
		}
		if(is_string($value)){
			return !in_array(strtolower(trim($value)), ['0', 'false', 'off', 'no', ''], true);
		}
		return (bool)$value;
	}
}
