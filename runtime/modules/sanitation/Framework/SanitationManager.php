<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

final class SanitationManager {

	private static ?self $instance=null;

	private readonly PresetRegistry $presets;

	public function __construct() {
		$this->presets=new PresetRegistry();
	}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public static function flush(): void {
		self::$instance=null;
	}

	public function sanitize(mixed $value, string|array $rule='default', array $options=[]): mixed {
		$detail=$this->sanitizeDetailed($value, $rule, $options+['present'=>true]);
		return $detail['value'];
	}

	public function string(mixed $value): Sanitizer {
		return new Sanitizer($this, $value);
	}

	public function bag(array $input): InputBag {
		return new InputBag($this, $input);
	}

	public function presets(): array {
		return $this->presets->names();
	}

	public function hasPreset(string $name): bool {
		return $this->presets->has($name);
	}

	public function registerPreset(string $name, array|callable $definition): self {
		$this->presets->register($name, $definition);
		return $this;
	}

	public function presetSchema(string $name, array $preset_overrides=[]): array {
		return $this->presets->resolve($name, $preset_overrides)['schema'];
	}

	public function preset(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[]): SanitizationResult {
		$preset=$this->presets->resolve($name, $preset_overrides);
		return $this->schema(
			$input,
			$preset['schema'],
			array_replace($preset['defaults'], $defaults),
			array_replace_recursive($preset['options'], $options),
		);
	}

	public function validatePreset(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[]): SanitizationResult {
		return $this->preset($name, $input, $preset_overrides, $defaults, $options);
	}

	public function validatedPreset(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[]): array {
		return $this->preset($name, $input, $preset_overrides, $defaults, $options)->validated();
	}

	public function presetOrFail(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[], ?string $message=null): array {
		return $this->preset($name, $input, $preset_overrides, $defaults, $options)
			->ensureValid($message, ['preset'=>$name])
			->validated();
	}

	public function schema(array $input, array $schema, array $defaults=[], array $options=[]): SanitizationResult {
		$data=$defaults;
		$errors=[];
		$configs=[];
		$targets=[];
		foreach($schema as $field=>$rule){
			foreach($this->schemaTargets($input, (string)$field) as $target){
				$targets[]=[
					'field'=>$target['field'],
					'pattern'=>$target['pattern'],
					'wildcard_values'=>$target['wildcard_values'],
					'rule'=>$rule,
				];
			}
		}
		foreach($targets as $target){
			$field=$target['field'];
			$input_field=$this->pathValue($input, $field);
			$rule_options=[
				'present'=>$input_field['present'],
				'input'=>$input,
				'context'=>$data,
				'skip_constraints'=>true,
				'field'=>$field,
				'field_pattern'=>$target['pattern'],
				'wildcard_values'=>$target['wildcard_values'],
				'labels'=>(array)($options['labels'] ?? []),
				'messages'=>(array)($options['messages'] ?? []),
			];
			$config=$this->normalizeRule($target['rule'], $rule_options);
			if(!$this->shouldProcessSchemaRule($config, $input_field['present'], $input, $data)){
				continue;
			}
			$detail=$this->sanitizeConfigured($input_field['value'], $config, $rule_options);
			$configs[$field]=$detail['config'];
			if($detail['failed']===true){
				$errors[$field]=$detail['error'];
				continue;
			}
			if(($detail['excluded'] ?? false)===true){
				$this->unsetPathValue($data, $field);
				continue;
			}
			if($detail['include']===true){
				$this->setPathValue($data, $field, $detail['value']);
			}
		}
		foreach($targets as $target){
			$field=$target['field'];
			$data_field=$this->pathValue($data, $field);
			if(isset($errors[$field]) || $data_field['present']===false || !isset($configs[$field])){
				continue;
			}
			$error=$this->validateConstraints($data_field['value'], $configs[$field], $data, $input);
			if($error!==null){
				$errors[$field]=$error;
				$this->unsetPathValue($data, $field);
			}
		}
		$this->applyDistinctConstraints($targets, $configs, $data, $errors);
		return new SanitizationResult($data, $errors, $input);
	}

	public function validated(array $input, array $schema, array $defaults=[], array $options=[]): array {
		return $this->schema($input, $schema, $defaults, $options)->validated();
	}

	public function schemaOrFail(array $input, array $schema, array $defaults=[], array $options=[], ?string $message=null): array {
		return $this->schema($input, $schema, $defaults, $options)
			->ensureValid($message)
			->validated();
	}

	public function validateOrFail(array $input, array $schema, array $defaults=[], array $options=[], ?string $message=null): array {
		return $this->schemaOrFail($input, $schema, $defaults, $options, $message);
	}

	public function sanitizeDetailed(mixed $value, string|array $rule='default', array $options=[]): array {
		$config=$this->normalizeRule($rule, $options);
		return $this->sanitizeConfigured($value, $config, $options);
	}

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
		$cast_value=$this->castValue($sanitized, $config);
		if(empty($options['skip_constraints'])){
			$error=$this->validateConstraints($cast_value, $config, (array)($options['context'] ?? []), (array)($options['input'] ?? []));
			if($error!==null){
				return $this->result(false, $error, true, true, $config);
			}
		}
		return $this->result($cast_value, null, true, false, $config);
	}

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
			$tokens=str_contains($rule, '|') ? explode('|', $rule) : [$rule];
			foreach($tokens as $token){
				$this->applyToken($config, trim((string)$token));
			}
		}
		else
		{
			$is_list=$rule===[] || array_keys($rule)===range(0, count($rule)-1);
			if($is_list){
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
			$field_messages=is_array($config['messages']) ? $config['messages'] : [];
			$scoped_messages=$this->fieldScopedOptionValue(
				is_array($options['messages'] ?? null) ? $options['messages'] : [],
				(string)$config['field'],
				$config['field_pattern']===null ? null : (string)$config['field_pattern'],
			);
			if(is_array($scoped_messages)){
				$field_messages=array_replace($scoped_messages, $field_messages);
			}
			$config['messages']=$field_messages;
			if($config['label']===null){
				$scoped_label=$this->fieldScopedOptionValue(
					is_array($options['labels'] ?? null) ? $options['labels'] : [],
					(string)$config['field'],
					$config['field_pattern']===null ? null : (string)$config['field_pattern'],
				);
				if($scoped_label!==null){
					$config['label']=(string)$scoped_label;
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
			$data_field=$this->pathValue($data, $field);
			if($data_field['present']===false){
				continue;
			}
			$groups[$pattern][]=[
				'field'=>$field,
				'value'=>$data_field['value'],
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

	private function humanizeField(string $field): string {
		$field=str_replace(['.', '_'], ' ', $field);
		$field=preg_replace('/\s+/u', ' ', trim($field)) ?? trim($field);
		if($field===''){
			return 'value';
		}
		return ucfirst($field);
	}

	private function otherFieldLabel(string $field, array $config): string {
		$resolved_field=$this->resolveComparisonField($field, $config);
		if(isset($config['labels']) && is_array($config['labels'])){
			$label=$this->fieldScopedOptionValue($config['labels'], $resolved_field, $field);
			if($label!==null){
				return (string)$label;
			}
		}
		return $this->humanizeField($resolved_field);
	}

	private function comparisonValue(string $field, array $context, array $input, array $config): mixed {
		$field=$this->resolveComparisonField($field, $config);
		$value=$this->pathValue($context, $field);
		if($value['present']===true){
			return $value['value'];
		}
		$value=$this->pathValue($input, $field);
		return $value['present']===true ? $value['value'] : null;
	}

	private function schemaTargets(array $input, string $field): array {
		if(!str_contains($field, '*')){
			return [[
				'field'=>$field,
				'pattern'=>$field,
				'wildcard_values'=>[],
			]];
		}
		$matches=$this->wildcardPathMatches($input, $field);
		$targets=[];
		foreach($matches as $match){
			$targets[]=[
				'field'=>$match['path'],
				'pattern'=>$field,
				'wildcard_values'=>$match['wildcard_values'],
			];
		}
		return $targets;
	}

	private function wildcardPathMatches(array $source, string $pattern): array {
		$matches=[];
		$this->walkWildcardPath($source, explode('.', $pattern), [], [], $matches);
		return $matches;
	}

	private function walkWildcardPath(mixed $current, array $segments, array $path_segments, array $wildcard_values, array &$matches): void {
		if($segments===[]){
			$matches[]=[
				'path'=>implode('.', $path_segments),
				'wildcard_values'=>$wildcard_values,
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
				$this->walkWildcardPath($value, $segments, [...$path_segments, $key], [...$wildcard_values, $key], $matches);
			}
			return;
		}
		if(!is_array($current) || !array_key_exists($segment, $current)){
			return;
		}
		$this->walkWildcardPath($current[$segment], $segments, [...$path_segments, $segment], $wildcard_values, $matches);
	}

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

	private function fieldPatternMatches(string $pattern, string $field): bool {
		$pattern_segments=explode('.', $pattern);
		$field_segments=explode('.', $field);
		if(count($pattern_segments)!==count($field_segments)){
			return false;
		}
		foreach($pattern_segments as $index=>$segment){
			if($segment==='*'){
				continue;
			}
			if($segment!==$field_segments[$index]){
				return false;
			}
		}
		return true;
	}

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

	private function applyWildcardValues(string $path, array $wildcard_values): string {
		foreach($wildcard_values as $wildcard_value){
			if(!str_contains($path, '*')){
				break;
			}
			$path=preg_replace('/\*/', (string)$wildcard_value, $path, 1) ?? $path;
		}
		return $path;
	}

	private function pathValue(array $source, string $path): array {
		if($path==='' || !str_contains($path, '.')){
			return [
				'present'=>array_key_exists($path, $source),
				'value'=>array_key_exists($path, $source) ? $source[$path] : null,
			];
		}
		$current=$source;
		foreach(explode('.', $path) as $segment){
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

	private function setPathValue(array &$target, string $path, mixed $value): void {
		if($path==='' || !str_contains($path, '.')){
			$target[$path]=$value;
			return;
		}
		$segments=explode('.', $path);
		$current=&$target;
		foreach($segments as $index=>$segment){
			if($index===count($segments)-1){
				$current[$segment]=$value;
				return;
			}
			if(!isset($current[$segment]) || !is_array($current[$segment])){
				$current[$segment]=[];
			}
			$current=&$current[$segment];
		}
	}

	private function unsetPathValue(array &$target, string $path): void {
		if($path==='' || !str_contains($path, '.')){
			unset($target[$path]);
			return;
		}
		$segments=explode('.', $path);
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

	private function isListArray(array $value): bool {
		if($value===[]){
			return true;
		}
		return array_keys($value)===range(0, count($value)-1);
	}

	private function arrayHasDuplicateValues(array $values, bool $ignore_case): bool {
		$seen=[];
		foreach($values as $value){
			$fingerprint=$this->distinctFingerprint($value, $ignore_case);
			if(array_key_exists($fingerprint, $seen)){
				return true;
			}
			$seen[$fingerprint]=true;
		}
		return false;
	}

	private function collectionHasDuplicateBy(array $items, array $fields, bool $ignore_case): bool {
		$seen=[];
		foreach($items as $item){
			$values=[];
			$has_comparable_value=false;
			foreach($fields as $field){
				$resolved=$this->relativePathValue($item, $field);
				$field_value=$resolved['present']===true ? $resolved['value'] : null;
				$values[$field]=$field_value;
				if($this->hasComparableUniqueValue($field_value)){
					$has_comparable_value=true;
				}
			}
			if($has_comparable_value===false){
				continue;
			}
			$fingerprint=$this->distinctFingerprint($values, $ignore_case);
			if(array_key_exists($fingerprint, $seen)){
				return true;
			}
			$seen[$fingerprint]=true;
		}
		return false;
	}

	private function distinctFingerprint(mixed $value, bool $ignore_case): string {
		$normalized=$this->normalizeDistinctValue($value, $ignore_case);
		return json_encode($normalized, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: serialize($normalized);
	}

	private function normalizeDistinctValue(mixed $value, bool $ignore_case): mixed {
		if(is_string($value)){
			return [
				'type'=>'string',
				'value'=>$ignore_case ? mb_strtolower($value, 'UTF-8') : $value,
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
				'value'=>$ignore_case ? mb_strtolower($string, 'UTF-8') : $string,
			];
		}
		if(is_array($value)){
			if($this->isListArray($value)){
				return [
					'type'=>'list',
					'value'=>array_map(fn(mixed $item): mixed => $this->normalizeDistinctValue($item, $ignore_case), $value),
				];
			}
			ksort($value);
			$normalized=[];
			foreach($value as $key=>$item){
				$normalized[(string)$key]=$this->normalizeDistinctValue($item, $ignore_case);
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

	private function normalizeUniqueByFields(mixed $value): array {
		if(is_string($value)){
			$parts=array_map('trim', explode(',', $value));
		}
		elseif(is_array($value)){
			$parts=array_map(static fn(mixed $part): string => trim((string)$part), $value);
		}
		else
		{
			$parts=[trim((string)$value)];
		}
		return array_values(array_filter($parts, static fn(string $part): bool => $part!==''));
	}

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

	private function normalizeFieldList(mixed $value): array {
		if(is_string($value)){
			$parts=array_map('trim', explode(',', $value));
		}
		elseif(is_array($value)){
			$parts=array_map(static fn(mixed $part): string => trim((string)$part), array_values($value));
		}
		else
		{
			$parts=[trim((string)$value)];
		}
		return array_values(array_filter($parts, static fn(string $part): bool => $part!==''));
	}

	private function normalizeConditionalExclusionValues(mixed $value): array {
		if(is_array($value)){
			return array_values($value);
		}
		if(is_string($value)){
			return $value==='' ? [] : array_map('trim', explode(',', $value));
		}
		return [$value];
	}

	private function comparisonMatchesAny(mixed $actual, array $expected_values): bool {
		foreach($expected_values as $expected_value){
			if($this->comparisonEquivalent($actual, $expected_value)){
				return true;
			}
		}
		return false;
	}

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

	private function anyComparisonFieldFilled(array $fields, array $context, array $input, array $config): bool {
		foreach($fields as $field){
			if($this->comparisonFieldFilled((string)$field, $context, $input, $config)){
				return true;
			}
		}
		return false;
	}

	private function anyComparisonFieldPresent(array $fields, array $context, array $input, array $config): bool {
		foreach($fields as $field){
			if($this->comparisonFieldPresent((string)$field, $context, $input, $config)){
				return true;
			}
		}
		return false;
	}

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

	private function anyComparisonFieldMissingOrBlank(array $fields, array $context, array $input, array $config): bool {
		foreach($fields as $field){
			if($this->comparisonFieldMissingOrBlank((string)$field, $context, $input, $config)){
				return true;
			}
		}
		return false;
	}

	private function anyComparisonFieldMissing(array $fields, array $context, array $input, array $config): bool {
		foreach($fields as $field){
			if($this->comparisonFieldMissing((string)$field, $context, $input, $config)){
				return true;
			}
		}
		return false;
	}

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

	private function comparisonEquivalent(mixed $left, mixed $right): bool {
		$left_scalar=$this->comparisonScalarValue($left);
		$right_scalar=$this->comparisonScalarValue($right);
		if($left_scalar!==null && $right_scalar!==null){
			return $left_scalar===$right_scalar;
		}
		return $this->distinctFingerprint($left, false)===$this->distinctFingerprint($right, false);
	}

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

	private function comparisonFieldFilled(string $field, array $context, array $input, array $config): bool {
		$state=$this->comparisonFieldState($field, $context, $input, $config);
		if($state['present']===false){
			return false;
		}
		return !$this->isBlankValue($state['value'], $config);
	}

	private function comparisonFieldPresent(string $field, array $context, array $input, array $config): bool {
		return $this->comparisonFieldState($field, $context, $input, $config)['present']===true;
	}

	private function comparisonFieldMissingOrBlank(string $field, array $context, array $input, array $config): bool {
		$state=$this->comparisonFieldState($field, $context, $input, $config);
		return $state['present']===false || $this->isBlankValue($state['value'], $config);
	}

	private function comparisonFieldMissing(string $field, array $context, array $input, array $config): bool {
		return $this->comparisonFieldState($field, $context, $input, $config)['present']===false;
	}

	private function comparisonFieldState(string $field, array $context, array $input, array $config): array {
		$field=$this->resolveComparisonField($field, $config);
		$value=$this->pathValue($context, $field);
		if($value['present']===true){
			return $value;
		}
		return $this->pathValue($input, $field);
	}

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

	private function isBlankForExclusion(mixed $value, array $config, bool $present): bool {
		if($present===false || $value===null){
			return true;
		}
		return $this->isBlankValue($value, $config);
	}

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
