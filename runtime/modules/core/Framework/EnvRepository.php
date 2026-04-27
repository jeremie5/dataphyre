<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class EnvRepository implements \JsonSerializable {

	public function __construct(
		private readonly ?string $prefix=null,
		private readonly string $separator='/'
	){}

	public function prefix(): ?string {
		return $this->prefix;
	}

	public function separator(): string {
		return $this->separator;
	}

	public function get(string $key, mixed $default=null): mixed {
		$key=trim($key);
		if($key===''){
			return $default;
		}
		return Env::get($this->composeKey($key), $default);
	}

	public function has(string $key=''): bool {
		$key=trim($key);
		if($key===''){
			return $this->all()!==[];
		}
		return Env::has($this->composeKey($key));
	}

	public function set(string|array $key, mixed $value=null): void {
		if(is_array($key)){
			$this->merge($key);
			return;
		}
		$key=trim($key);
		if($key===''){
			return;
		}
		Env::set($this->composeKey($key), $value);
	}

	public function merge(array $values): void {
		$mapped=[];
		foreach($values as $key=>$value){
			$key=trim((string)$key);
			if($key===''){
				continue;
			}
			$mapped[$this->composeKey($key)]=$value;
		}
		if($mapped!==[]){
			Env::set($mapped);
		}
	}

	public function forget(string|array $key): void {
		$keys=is_array($key) ? $key : [$key];
		$mapped=[];
		foreach($keys as $item){
			$item=trim((string)$item);
			if($item===''){
				continue;
			}
			$mapped[]=$this->composeKey($item);
		}
		if($mapped!==[]){
			Env::forget($mapped);
		}
	}

	public function pull(string $key, mixed $default=null): mixed {
		$value=$this->get($key, $default);
		$this->forget($key);
		return $value;
	}

	public function all(): array {
		$env=Env::all();
		if($this->prefix===null){
			return $env;
		}
		$prefix=$this->prefix.$this->separator;
		$scoped=[];
		foreach($env as $key=>$value){
			if(!is_string($key) || !str_starts_with($key, $prefix)){
				continue;
			}
			$scoped[substr($key, strlen($prefix))]=$value;
		}
		return $scoped;
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
		$env=$this->all();
		foreach($keys as $key){
			unset($env[trim((string)$key)]);
		}
		return $env;
	}

	public function keys(): array {
		return array_keys($this->all());
	}

	public function isEmpty(): bool {
		return $this->all()===[];
	}

	public function scope(?string $prefix): self {
		$prefix=static::normalizePrefix($prefix, $this->separator);
		if($prefix===null){
			return $this;
		}
		return new self($this->composeKey($prefix), $this->separator);
	}

	public function snapshot(): EnvSnapshot {
		return new EnvSnapshot($this->prefix, $this->separator, $this->all());
	}

	public function toArray(): array {
		return [
			'prefix'=>$this->prefix,
			'separator'=>$this->separator,
			'values'=>$this->all(),
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	private function composeKey(string $key): string {
		$key=trim($key, $this->separator." \t\n\r\0\x0B");
		if($this->prefix===null || $this->prefix===''){
			return $key;
		}
		return $this->prefix.$this->separator.$key;
	}

	private static function normalizePrefix(?string $prefix, string $separator): ?string {
		if(!is_string($prefix)){
			return null;
		}
		$prefix=trim($prefix, $separator." \t\n\r\0\x0B");
		return $prefix!=='' ? $prefix : null;
	}
}
