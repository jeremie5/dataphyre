<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class ConfigRepository implements \JsonSerializable {

	public function __construct(
		private readonly ?string $path=null
	){}

	public function path(): ?string {
		return $this->path;
	}

	public function exists(): bool {
		return $this->path===null || Config::has($this->path);
	}

	public function value(mixed $default=null): mixed {
		if($this->path===null){
			return Config::all();
		}
		return Config::get($this->path, $default);
	}

	public function get(string $key, mixed $default=null): mixed {
		$key=trim($key);
		if($key===''){
			return $this->value($default);
		}
		return Config::get($this->composePath($key), $default);
	}

	public function has(string $key=''): bool {
		$key=trim($key);
		if($key===''){
			return $this->exists();
		}
		return Config::has($this->composePath($key));
	}

	public function set(string|array $key, mixed $value=null): bool {
		if(is_array($key)){
			return $this->merge($key);
		}
		$key=trim($key);
		if($key===''){
			if($this->path===null){
				return false;
			}
			return Config::set($this->path, $value);
		}
		return Config::set($this->composePath($key), $value);
	}

	public function merge(array $config): bool {
		if($this->path===null){
			return Config::merge($config);
		}
		return Config::merge($this->wrap($config));
	}

	public function all(): array {
		$value=$this->value([]);
		return is_array($value) ? $value : [];
	}

	public function only(array $keys): array {
		$config=Config::all();
		$selected=[];
		foreach($keys as $key){
			$key=trim((string)$key);
			if($key===''){
				continue;
			}
			$value=static::pathValue($config, $this->composePath($key), $exists);
			if(!$exists){
				continue;
			}
			$selected[$key]=$value;
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
		if($this->path===null){
			return array_keys(Config::all());
		}
		$value=static::pathValue(Config::all(), $this->path, $exists);
		return $exists && is_array($value) ? array_keys($value) : [];
	}

	public function isEmpty(): bool {
		if($this->path===null){
			$value=Config::all();
		}
		else
		{
			$value=static::pathValue(Config::all(), $this->path, $exists);
			if(!$exists){
				return true;
			}
		}
		if(is_array($value)){
			return $value===[];
		}
		return $value===null;
	}

	public function scope(?string $path): self {
		$path=static::normalizePath($path);
		if($path===null){
			return $this;
		}
		return new self($this->composePath($path));
	}

	public function snapshot(): ConfigSnapshot {
		return new ConfigSnapshot(
			$this->path,
			$this->exists(),
			$this->value(null)
		);
	}

	public function toArray(): array {
		return [
			'path'=>$this->path,
			'exists'=>$this->exists(),
			'value'=>$this->value(null),
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
		return $key==='' ? $this->path : $this->path.'/'.$key;
	}

	private function wrap(array $value): array {
		$path=static::segments($this->path ?? '');
		if($path===[]){
			return $value;
		}
		$wrapped=$value;
		for($index=count($path)-1; $index>=0; $index--){
			$wrapped=[$path[$index]=>$wrapped];
		}
		return $wrapped;
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

	private static function pathValue(array $config, string $path, ?bool &$exists=false): mixed {
		if(array_key_exists($path, $config)){
			$exists=true;
			return $config[$path];
		}
		$segments=static::segments($path);
		if($segments===[]){
			$exists=true;
			return $config;
		}
		$current=$config;
		foreach($segments as $segment){
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
