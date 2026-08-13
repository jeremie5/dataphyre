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

/** Process-local scenario state shared with committed test fixture adapters. */
final class TestState {

	/** @var array<string,array<string|int,mixed>> */
	private static array $channels=[];

	private function __construct(private string $name) {}

	/** @param array<string|int,mixed> $initial */
	public static function create(RuntimeContext $context, string $name, array $initial=[]): self {
		$name=self::name($name);
		$existed=array_key_exists($name, self::$channels);
		$previous=self::$channels[$name] ?? [];
		self::$channels[$name]=$initial;
		$context->defer(static function()use($name, $existed, $previous): void {
			if($existed){
				self::$channels[$name]=$previous;
			}else{
				unset(self::$channels[$name]);
			}
		});
		return new self($name);
	}

	public static function channel(string $name): self {
		$name=self::name($name);
		if(!array_key_exists($name, self::$channels)){
			throw new \RuntimeException('Test state channel is not active: '.$name);
		}
		return new self($name);
	}

	/** Returns a named channel when its scenario is active, otherwise null. */
	public static function channelIfActive(string $name): ?self {
		$name=self::name($name);
		return array_key_exists($name, self::$channels) ? new self($name) : null;
	}

	public function get(string|int $key, mixed $default=null): mixed {
		return array_key_exists($key, self::$channels[$this->name]) ? self::$channels[$this->name][$key] : $default;
	}

	public function has(string|int $key): bool {
		return array_key_exists($key, self::$channels[$this->name]);
	}

	public function put(string|int $key, mixed $value): self {
		self::$channels[$this->name][$key]=$value;
		return $this;
	}

	/** @param array<string|int,mixed> $values */
	public function merge(array $values): self {
		self::$channels[$this->name]=array_replace(self::$channels[$this->name], $values);
		return $this;
	}

	public function forget(string|int $key): self {
		unset(self::$channels[$this->name][$key]);
		return $this;
	}

	public function append(string|int $key, mixed $value): self {
		$list=$this->get($key, []);
		if(!is_array($list)){
			throw new \UnexpectedValueException('Test state append target is not an array: '.$this->name.'.'.$key);
		}
		$list[]=$value;
		return $this->put($key, $list);
	}

	public function shift(string|int $key, mixed $default=null): mixed {
		$list=$this->get($key, []);
		if(!is_array($list)){
			throw new \UnexpectedValueException('Test state queue is not an array: '.$this->name.'.'.$key);
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
			throw new \UnexpectedValueException('Test state counter is not numeric: '.$this->name.'.'.$key);
		}
		$value+=$amount;
		$this->put($key, $value);
		return $value;
	}

	/** @param array<string|int,mixed> $values */
	public function replace(array $values): self {
		self::$channels[$this->name]=$values;
		return $this;
	}

	public function clear(): self {
		return $this->replace([]);
	}

	/** @return array<string|int,mixed> */
	public function all(): array {
		return self::$channels[$this->name];
	}

	private static function name(string $name): string {
		$name=trim($name);
		if($name==='' || preg_match('/^[A-Za-z0-9_.:-]+$/', $name)!==1){
			throw new \InvalidArgumentException('Test state channel name is invalid: '.$name);
		}
		return $name;
	}
}
