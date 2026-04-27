<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class CallableBinding implements DataBinding {

	public function __construct(
		private readonly mixed $resolver,
		private readonly string $name='callable'
	){}

	public static function make(callable $resolver, ?string $name=null): self {
		return new self($resolver, trim((string)$name) !== '' ? trim((string)$name) : 'callable');
	}

	public function name(): string {
		return $this->name;
	}

	public function resolve(BindingContext $context): mixed {
		$reflection=$this->reflect($this->resolver);
		$parameter_count=$reflection->getNumberOfParameters();
		if($parameter_count===0){
			return call_user_func($this->resolver);
		}
		return call_user_func($this->resolver, $context);
	}

	private function reflect(callable $callable): \ReflectionFunctionAbstract {
		if(is_array($callable)){
			return new \ReflectionMethod($callable[0], $callable[1]);
		}
		if(is_string($callable) && str_contains($callable, '::')){
			return new \ReflectionMethod($callable);
		}
		if(is_object($callable) && !$callable instanceof \Closure){
			return new \ReflectionMethod($callable, '__invoke');
		}
		return new \ReflectionFunction($callable);
	}
}
