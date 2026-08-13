<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\RuntimeContext;

/** Automatically restored access to one test-owned global value or map. */
final class GlobalState {

	private bool $original_exists;
	private mixed $original_value;
	private bool $restored=false;

	public function __construct(private RuntimeContext $context, private string $name, bool $map=false) {
		$this->name=trim($this->name);
		if($this->name==='' || $this->name==='GLOBALS'){
			throw new \InvalidArgumentException('Managed test globals require a safe non-empty name.');
		}
		$this->original_exists=array_key_exists($this->name, $GLOBALS);
		$this->original_value=$GLOBALS[$this->name] ?? null;
		$this->context->defer(fn()=>$this->restore());
		if($map && !$this->original_exists){
			$GLOBALS[$this->name]=[];
		}
		if($map && !is_array($GLOBALS[$this->name] ?? null)){
			throw new \UnexpectedValueException('Managed global map is not an array: '.$this->name);
		}
	}

	public function value(mixed $default=null): mixed {
		return array_key_exists($this->name, $GLOBALS) ? $GLOBALS[$this->name] : $default;
	}

	public function exists(): bool {
		return array_key_exists($this->name, $GLOBALS);
	}

	public function replace(mixed $value): self {
		$GLOBALS[$this->name]=$value;
		return $this;
	}

	/** Removes the complete global value until this test's automatic restoration. */
	public function unsetValue(): self {
		unset($GLOBALS[$this->name]);
		return $this;
	}

	public function get(string|int $key, mixed $default=null): mixed {
		$map=$this->map();
		return array_key_exists($key, $map) ? $map[$key] : $default;
	}

	public function put(string|int $key, mixed $value): self {
		$map=$this->map();
		$map[$key]=$value;
		$GLOBALS[$this->name]=$map;
		return $this;
	}

	/**
	 * Reads a nested map value without exposing test code to raw global arrays.
	 *
	 * @param non-empty-list<string|int> $path
	 */
	public function getPath(array $path, mixed $default=null): mixed {
		$path=$this->path($path);
		$value=$this->map();
		foreach($path as $key){
			if(!is_array($value) || !array_key_exists($key, $value)){
				return $default;
			}
			$value=$value[$key];
		}
		return $value;
	}

	/**
	 * Writes a nested map value and creates only missing map branches.
	 * Existing scalar branches fail loudly instead of being silently replaced.
	 *
	 * @param non-empty-list<string|int> $path
	 */
	public function putPath(array $path, mixed $value): self {
		$path=$this->path($path);
		$map=$this->map();
		$cursor=&$map;
		$last=array_pop($path);
		foreach($path as $key){
			if(!array_key_exists($key, $cursor)){
				$cursor[$key]=[];
			}
			if(!is_array($cursor[$key])){
				throw new \UnexpectedValueException('Managed global path crosses a non-array value: '.$this->name.'.'.(string)$key);
			}
			$cursor=&$cursor[$key];
		}
		$cursor[$last]=$value;
		$GLOBALS[$this->name]=$map;
		return $this;
	}

	/** @param array<string|int,mixed> $values */
	public function merge(array $values): self {
		$GLOBALS[$this->name]=array_replace($this->map(), $values);
		return $this;
	}

	public function forget(string|int $key): self {
		$map=$this->map();
		unset($map[$key]);
		$GLOBALS[$this->name]=$map;
		return $this;
	}

	/** Backward-compatible alias for forget(). */
	public function remove(string|int $key): self {
		return $this->forget($key);
	}

	public function append(string|int $key, mixed $value): self {
		$list=$this->get($key, []);
		if(!is_array($list)){
			throw new \UnexpectedValueException('Managed global append target is not an array: '.$this->name.'.'.$key);
		}
		$list[]=$value;
		return $this->put($key, $list);
	}

	public function shift(string|int $key, mixed $default=null): mixed {
		$list=$this->get($key, []);
		if(!is_array($list)){
			throw new \UnexpectedValueException('Managed global queue is not an array: '.$this->name.'.'.$key);
		}
		if($list===[]){
			return $default;
		}
		$value=array_shift($list);
		$this->put($key, $list);
		return $value;
	}

	public function increment(string|int $key, int|float $amount=1): int|float {
		$value=$this->get($key, 0);
		if(!is_int($value) && !is_float($value)){
			throw new \UnexpectedValueException('Managed global counter is not numeric: '.$this->name.'.'.$key);
		}
		$value+=$amount;
		$this->put($key, $value);
		return $value;
	}

	public function clear(): self {
		$GLOBALS[$this->name]=[];
		return $this;
	}

	/** Runs a callback with one temporary value and restores native state immediately afterward. */
	public function withValue(mixed $value, callable $callback): mixed {
		$this->replace($value);
		return $this->within($callback);
	}

	/** Runs a callback while the native global is absent, then restores its value and existence. */
	public function withoutValue(callable $callback): mixed {
		$this->unsetValue();
		return $this->within($callback);
	}

	public function has(string|int $key): bool {
		return array_key_exists($key, $this->map());
	}

	/** @return array<string|int,mixed> */
	public function map(): array {
		$value=$GLOBALS[$this->name] ?? null;
		if(!is_array($value)){
			throw new \UnexpectedValueException('Managed global is not an array: '.$this->name);
		}
		return $value;
	}

	/** @param list<string|int> $path @return non-empty-list<string|int> */
	private function path(array $path): array {
		if($path===[]){
			throw new \InvalidArgumentException('Managed global paths require at least one key.');
		}
		foreach($path as $key){
			if((!is_string($key) && !is_int($key)) || (is_string($key) && $key==='')){
				throw new \InvalidArgumentException('Managed global path keys must be non-empty strings or integers.');
			}
		}
		return array_values($path);
	}

	private function within(callable $callback): mixed {
		try{
			return $callback($this);
		}finally{
			$this->restore();
		}
	}

	private function restore(): void {
		if($this->restored){
			return;
		}
		if($this->original_exists){
			$GLOBALS[$this->name]=$this->original_value;
		}else{
			unset($GLOBALS[$this->name]);
		}
		$this->restored=true;
	}
}
