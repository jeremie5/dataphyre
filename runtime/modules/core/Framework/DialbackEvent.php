<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class DialbackEvent implements \JsonSerializable {

	/** @var array<int, callable> */
	private readonly array $callbacks;

	public function __construct(
		private readonly string $name,
		array $callbacks=[]
	){
		$this->callbacks=array_values(array_filter($callbacks, 'is_callable'));
	}

	public static function fromCallbacks(string $name, array $callbacks=[]): self {
		return new self(trim($name), $callbacks);
	}

	public function name(): string {
		return $this->name;
	}

	public function callbacks(): array {
		return $this->callbacks;
	}

	public function callbackDescriptions(): array {
		$descriptions=[];
		foreach($this->callbacks as $callback){
			$descriptions[]=static::describeCallback($callback);
		}
		return $descriptions;
	}

	public function callbackCount(): int {
		return count($this->callbacks);
	}

	public function hasCallbacks(): bool {
		return $this->callbacks!==[];
	}

	public function isEmpty(): bool {
		return $this->callbacks===[];
	}

	public function matchesPrefix(?string $prefix): bool {
		$prefix=static::normalizePrefix($prefix);
		if($prefix===null){
			return true;
		}
		return str_starts_with($this->name, $prefix);
	}

	public function register(callable $callback): bool {
		return Dialback::register($this->name, $callback);
	}

	public function fire(mixed ...$data): mixed {
		return Dialback::fire($this->name, ...$data);
	}

	public function toArray(): array {
		return [
			'name'=>$this->name,
			'callback_count'=>$this->callbackCount(),
			'callbacks'=>$this->callbackDescriptions(),
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	private static function normalizePrefix(?string $prefix): ?string {
		if(!is_string($prefix)){
			return null;
		}
		$prefix=trim($prefix);
		return $prefix!=='' ? $prefix : null;
	}

	private static function describeCallback(callable $callback): array {
		if(is_string($callback)){
			return [
				'type'=>'function',
				'label'=>$callback,
			];
		}
		if($callback instanceof \Closure){
			try{
				$reflection=new \ReflectionFunction($callback);
				return [
					'type'=>'closure',
					'label'=>'closure@'.basename((string)$reflection->getFileName()).':'.$reflection->getStartLine(),
					'file'=>$reflection->getFileName() ?: null,
					'line'=>$reflection->getStartLine(),
				];
			}catch(\ReflectionException){
				return [
					'type'=>'closure',
					'label'=>'closure',
				];
			}
		}
		if(is_array($callback) && count($callback)===2){
			[$target, $method]=$callback;
			$class=is_object($target) ? $target::class : (string)$target;
			return [
				'type'=>is_object($target) ? 'instance_method' : 'static_method',
				'label'=>$class.'::'.$method,
				'class'=>$class,
				'method'=>(string)$method,
			];
		}
		if(is_object($callback) && method_exists($callback, '__invoke')){
			return [
				'type'=>'invokable',
				'label'=>$callback::class,
				'class'=>$callback::class,
			];
		}
		return [
			'type'=>'callable',
			'label'=>'callable',
		];
	}
}
