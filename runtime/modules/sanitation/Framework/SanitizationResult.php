<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

final class SanitizationResult {

	public function __construct(
		private readonly array $data,
		private readonly array $errors,
		private readonly array $input=[]
	){}

	public function passed(): bool {
		return $this->errors===[];
	}

	public function passes(): bool {
		return $this->passed();
	}

	public function failed(): bool {
		return !$this->passed();
	}

	public function fails(): bool {
		return $this->failed();
	}

	public function all(): array {
		return $this->data;
	}

	public function validated(): array {
		return $this->data;
	}

	public function data(): array {
		return $this->data;
	}

	public function errors(): array {
		return $this->errors;
	}

	public function messages(): array {
		return $this->errors;
	}

	public function error(?string $key=null): string|array|null {
		if($key===null){
			return $this->errors;
		}
		return $this->errors[$key] ?? null;
	}

	public function firstError(): ?string {
		return $this->errors===[] ? null : (string)reset($this->errors);
	}

	public function has(string $key): bool {
		return $this->pathValue($this->data, $key)['present'];
	}

	public function invalid(string $key): bool {
		return array_key_exists($key, $this->errors);
	}

	public function get(string $key, mixed $default=null): mixed {
		$value=$this->pathValue($this->data, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	public function only(array $keys): array {
		$subset=[];
		foreach($keys as $key){
			$value=$this->pathValue($this->data, (string)$key);
			if($value['present']===true){
				$this->setPathValue($subset, (string)$key, $value['value']);
			}
		}
		return $subset;
	}

	public function except(array $keys): array {
		$subset=$this->data;
		foreach($keys as $key){
			$this->unsetPathValue($subset, (string)$key);
		}
		return $subset;
	}

	public function raw(string $key, mixed $default=null): mixed {
		$value=$this->pathValue($this->input, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	public function input(): array {
		return $this->input;
	}

	public function ensureValid(?string $message=null, array $context=[]): self {
		if($this->failed()){
			throw new SanitizationException($this, $context, $message);
		}
		return $this;
	}

	public function throwIfFailed(?string $message=null, array $context=[]): self {
		return $this->ensureValid($message, $context);
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
}
