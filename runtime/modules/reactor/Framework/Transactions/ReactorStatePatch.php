<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * One deterministic mutation against a dotted Reactor state path.
 *
 * Patches intentionally use a small, serializable operation vocabulary. The
 * same payload can be applied optimistically in a browser, committed on the
 * server, inverted for rollback, or stored in an offline queue.
 */
final class ReactorStatePatch implements \JsonSerializable {
	private const OPERATIONS=['set', 'remove', 'merge', 'increment', 'append', 'test'];

	private function __construct(
		private readonly string $operation,
		private readonly string $path,
		private readonly mixed $value=null
	){}

	public static function make(string $operation, string $path, mixed $value=null): self {
		$operation=strtolower(trim($operation));
		if(!in_array($operation, self::OPERATIONS, true)){
			throw new \InvalidArgumentException('Unsupported Reactor state operation: '.$operation);
		}
		$path=self::normalizePath($path);
		if($path===''){
			throw new \InvalidArgumentException('A Reactor state patch requires a non-empty path.');
		}
		if($operation==='increment' && !is_int($value) && !is_float($value)){
			throw new \InvalidArgumentException('Increment patches require a numeric value.');
		}
		if($operation==='merge' && !is_array($value)){
			throw new \InvalidArgumentException('Merge patches require an array value.');
		}
		return new self($operation, $path, self::copyValue($value));
	}

	public static function fromArray(array $patch): self {
		return self::make(
			(string)($patch['operation'] ?? $patch['op'] ?? 'set'),
			(string)($patch['path'] ?? ''),
			$patch['value'] ?? null
		);
	}

	public function operation(): string { return $this->operation; }
	public function path(): string { return $this->path; }
	public function value(): mixed { return self::copyValue($this->value); }

	/**
	 * Applies the patch and returns the new state plus an exact inverse patch.
	 *
	 * @return array{state:array<string,mixed>,inverse:self}
	 */
	public function apply(array $state): array {
		[$exists, $before]=self::readPath($state, $this->path);
		$inverse=$exists
			? self::make('set', $this->path, $before)
			: self::make('remove', $this->path);

		switch($this->operation){
			case 'set':
				self::writePath($state, $this->path, self::copyValue($this->value));
				break;
			case 'remove':
				self::removePath($state, $this->path);
				break;
			case 'merge':
				$current=$exists && is_array($before) ? $before : [];
				self::writePath($state, $this->path, array_replace_recursive($current, $this->value));
				break;
			case 'increment':
				if($exists && !is_int($before) && !is_float($before)){
					throw new \DomainException('Cannot increment non-numeric Reactor state at '.$this->path.'.');
				}
				self::writePath($state, $this->path, ($exists ? $before : 0)+$this->value);
				break;
			case 'append':
				if($exists && !is_array($before)){
					throw new \DomainException('Cannot append to non-array Reactor state at '.$this->path.'.');
				}
				$current=$exists ? $before : [];
				$current[]=self::copyValue($this->value);
				self::writePath($state, $this->path, $current);
				break;
			case 'test':
				if(!$exists || $before!==$this->value){
					throw new \DomainException('Reactor state precondition failed at '.$this->path.'.');
				}
				$inverse=self::make('test', $this->path, $before);
				break;
		}

		return ['state'=>$state, 'inverse'=>$inverse];
	}

	public function jsonSerialize(): array {
		return ['operation'=>$this->operation, 'path'=>$this->path, 'value'=>$this->value];
	}

	private static function normalizePath(string $path): string {
		$path=trim($path);
		if(str_starts_with($path, '/')){
			$segments=array_map(
				static fn(string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
				explode('/', ltrim($path, '/'))
			);
			$path=implode('.', $segments);
		}
		$segments=[];
		foreach(explode('.', $path) as $segment){
			$segment=trim($segment);
			if($segment==='' || $segment==='..' || str_contains($segment, "\0")){
				continue;
			}
			$segments[]=$segment;
		}
		return implode('.', $segments);
	}

	/** @return array{0:bool,1:mixed} */
	private static function readPath(array $state, string $path): array {
		$cursor=$state;
		foreach(explode('.', $path) as $segment){
			if(!is_array($cursor) || !array_key_exists($segment, $cursor)){
				return [false, null];
			}
			$cursor=$cursor[$segment];
		}
		return [true, self::copyValue($cursor)];
	}

	private static function writePath(array &$state, string $path, mixed $value): void {
		$segments=explode('.', $path);
		$cursor=&$state;
		foreach($segments as $index=>$segment){
			if($index===count($segments)-1){
				$cursor[$segment]=$value;
				break;
			}
			if(!isset($cursor[$segment]) || !is_array($cursor[$segment])){
				$cursor[$segment]=[];
			}
			$cursor=&$cursor[$segment];
		}
	}

	private static function removePath(array &$state, string $path): void {
		$segments=explode('.', $path);
		$cursor=&$state;
		foreach($segments as $index=>$segment){
			if(!is_array($cursor) || !array_key_exists($segment, $cursor)){
				return;
			}
			if($index===count($segments)-1){
				unset($cursor[$segment]);
				break;
			}
			$cursor=&$cursor[$segment];
		}
	}

	private static function copyValue(mixed $value): mixed {
		if(!is_array($value)){
			return $value;
		}
		$copy=[];
		foreach($value as $key=>$item){
			$copy[$key]=self::copyValue($item);
		}
		return $copy;
	}
}
