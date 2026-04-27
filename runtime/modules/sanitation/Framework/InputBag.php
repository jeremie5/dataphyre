<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

final class InputBag {

	public function __construct(
		private readonly SanitationManager $manager,
		private readonly array $input
	){}

	public function all(): array {
		return $this->input;
	}

	public function has(string $key): bool {
		return $this->pathValue($this->input, $key)['present'];
	}

	public function present(string $key): bool {
		return $this->has($key);
	}

	public function missing(string $key): bool {
		return !$this->has($key);
	}

	public function filled(string $key): bool {
		$value=$this->pathValue($this->input, $key);
		return $value['present']===true && $this->isFilledValue($value['value']);
	}

	public function blank(string $key): bool {
		return !$this->filled($key);
	}

	public function get(string $key, mixed $default=null): mixed {
		$value=$this->pathValue($this->input, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	public function only(array $keys): array {
		$subset=[];
		foreach($keys as $key){
			$value=$this->pathValue($this->input, (string)$key);
			if($value['present']===true){
				$this->setPathValue($subset, (string)$key, $value['value']);
			}
		}
		return $subset;
	}

	public function except(array $keys): array {
		$subset=$this->input;
		foreach($keys as $key){
			$this->unsetPathValue($subset, (string)$key);
		}
		return $subset;
	}

	public function sanitize(array $schema, array $defaults=[], array $options=[]): SanitizationResult {
		return $this->manager->schema($this->input, $schema, $defaults, $options);
	}

	public function validate(array $schema, array $defaults=[], array $options=[]): SanitizationResult {
		return $this->sanitize($schema, $defaults, $options);
	}

	public function validated(array $schema, array $defaults=[], array $options=[]): array {
		return $this->manager->validated($this->input, $schema, $defaults, $options);
	}

	public function validatedOrFail(array $schema, array $defaults=[], array $options=[], ?string $message=null): array {
		return $this->manager->schemaOrFail($this->input, $schema, $defaults, $options, $message);
	}

	public function preset(string $name, array $preset_overrides=[], array $defaults=[], array $options=[]): SanitizationResult {
		return $this->manager->preset($name, $this->input, $preset_overrides, $defaults, $options);
	}

	public function validatePreset(string $name, array $preset_overrides=[], array $defaults=[], array $options=[]): SanitizationResult {
		return $this->preset($name, $preset_overrides, $defaults, $options);
	}

	public function validatedPreset(string $name, array $preset_overrides=[], array $defaults=[], array $options=[]): array {
		return $this->manager->validatedPreset($name, $this->input, $preset_overrides, $defaults, $options);
	}

	public function validatedPresetOrFail(string $name, array $preset_overrides=[], array $defaults=[], array $options=[], ?string $message=null): array {
		return $this->manager->presetOrFail($name, $this->input, $preset_overrides, $defaults, $options, $message);
	}

	public function clean(string $key, string|array $rule='default', mixed $default=null): mixed {
		$value=$this->pathValue($this->input, $key);
		$detail=$this->manager->sanitizeDetailed($value['value'], $rule, ['present'=>$value['present']]);
		return $detail['failed']===true ? $default : ($detail['include']===true ? $detail['value'] : $default);
	}

	public function string(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'default', $default);
		return $value===false || $value===null ? $default : (string)$value;
	}

	public function text(string $key, ?string $default=null): ?string {
		return $this->string($key, $default);
	}

	public function textNoSpecial(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'text_nospecial', $default);
		return $value===false || $value===null ? $default : (string)$value;
	}

	public function basicHtml(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'basic_html', $default);
		return $value===false || $value===null ? $default : (string)$value;
	}

	public function integer(string $key, ?int $default=null): ?int {
		$value=$this->clean($key, 'integer');
		return $value===false || $value===null || $value==='' ? $default : (int)$value;
	}

	public function float(string $key, ?float $default=null): ?float {
		$value=$this->clean($key, 'float');
		return $value===false || $value===null || $value==='' ? $default : (float)$value;
	}

	public function boolean(string $key, ?bool $default=null): ?bool {
		$value=$this->clean($key, 'boolean');
		return $value===false || $value===null || $value==='' ? $default : (bool)$value;
	}

	public function arrayValue(string $key, ?array $default=null): ?array {
		$value=$this->clean($key, 'array');
		return is_array($value) ? $value : $default;
	}

	public function listValue(string $key, ?array $default=null): ?array {
		$value=$this->clean($key, 'list');
		return is_array($value) ? $value : $default;
	}

	public function email(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'email');
		return $value===false ? $default : $value;
	}

	public function url(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'url');
		return $value===false ? $default : $value;
	}

	public function phone(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'phone_number');
		return $value===false ? $default : $value;
	}

	public function name(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'person_name');
		return $value===false ? $default : $value;
	}

	public function numeric(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'numeric');
		return $value===false ? $default : $value;
	}

	public function slug(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'slug');
		return $value===false ? $default : $value;
	}

	public function username(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'username');
		return $value===false ? $default : $value;
	}

	public function postalCode(string $key, ?string $default=null): ?string {
		$value=$this->clean($key, 'postal_code');
		return $value===false ? $default : $value;
	}

	public function whenPresent(string $key, callable $callback, mixed $default=null): mixed {
		$value=$this->pathValue($this->input, $key);
		if($value['present']===false){
			return $default;
		}
		return $callback($value['value'], $this);
	}

	public function whenFilled(string $key, callable $callback, mixed $default=null): mixed {
		$value=$this->pathValue($this->input, $key);
		if($value['present']===false || !$this->isFilledValue($value['value'])){
			return $default;
		}
		return $callback($value['value'], $this);
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
