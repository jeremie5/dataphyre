<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class ConfigSnapshot implements \JsonSerializable {

	public function __construct(
		private readonly ?string $path,
		private readonly bool $exists,
		private readonly mixed $value
	){}

	public function path(): ?string {
		return $this->path;
	}

	public function exists(): bool {
		return $this->exists;
	}

	public function value(mixed $default=null): mixed {
		return $this->exists ? $this->value : $default;
	}

	public function get(string $key, mixed $default=null): mixed {
		$key=trim($key);
		if($key===''){
			return $this->value($default);
		}
		if(!is_array($this->value)){
			return $default;
		}
		$found=static::getPath($this->value, static::segments($key), $exists);
		return $exists ? $found : $default;
	}

	public function has(string $key=''): bool {
		$key=trim($key);
		if($key===''){
			return $this->exists;
		}
		if(!is_array($this->value)){
			return false;
		}
		static::getPath($this->value, static::segments($key), $exists);
		return $exists;
	}

	public function all(): array {
		return is_array($this->value) ? $this->value : [];
	}

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

	public function except(array $keys): array {
		$config=$this->all();
		foreach($keys as $key){
			static::unsetPath($config, static::segments((string)$key));
		}
		return $config;
	}

	public function keys(): array {
		return array_keys($this->all());
	}

	public function isEmpty(): bool {
		if(!$this->exists){
			return true;
		}
		if(is_array($this->value)){
			return $this->value===[];
		}
		return $this->value===null;
	}

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

	public function toArray(): array {
		return [
			'path'=>$this->path,
			'exists'=>$this->exists,
			'value'=>$this->value,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	private function composePath(string $key): string {
		$key=trim($key, '/');
		if($this->path===null || $this->path===''){
			return $key;
		}
		return $this->path.'/'.$key;
	}

	private static function normalizePath(?string $path): ?string {
		if(!is_string($path)){
			return null;
		}
		$path=trim($path, " \t\n\r\0\x0B/");
		return $path!=='' ? $path : null;
	}

	private static function segments(string $path): array {
		return array_values(array_filter(
			explode('/', trim($path)),
			static fn(string $segment): bool => $segment!==''
		));
	}

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
