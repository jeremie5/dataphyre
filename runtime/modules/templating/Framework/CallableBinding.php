<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Data binding that resolves template data by invoking a callable.
 *
 * Callable bindings allow lazy or context-aware values in template rendering. Zero-argument
 * callables are invoked without context, while callables declaring parameters receive the
 * current `BindingContext`.
 */
final class CallableBinding implements DataBinding {

	/**
	 * Cached resolver arity used to avoid repeated reflection during hot binding resolution.
	 */
	private ?int $parameterCount=null;

	/**
	 * Stores the callable resolver and diagnostic binding name.
	 *
	 * @param callable $resolver Binding resolver callable.
	 * @param string $name Diagnostic binding name.
	 */
	public function __construct(
		private readonly mixed $resolver,
		private readonly string $name='callable'
	){}

	/**
	 * Creates a callable binding with a normalized fallback name.
	 *
	 * @param callable $resolver Binding resolver callable.
	 * @param ?string $name Optional diagnostic binding name.
	 * @return self Callable binding instance.
	 */
	public static function make(callable $resolver, ?string $name=null): self {
		return new self($resolver, trim((string)$name) !== '' ? trim((string)$name) : 'callable');
	}

	/**
	 * Returns the diagnostic binding name.
	 *
	 * @return string Binding name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Resolves the binding value for a render context.
	 *
	 * Reflection is used only to decide whether to pass the `BindingContext`, keeping
	 * zero-argument lazy values ergonomic while still supporting context-aware resolvers.
	 *
	 * @param BindingContext $context Current binding context.
	 * @return mixed value returned by the resolver, with BindingContext supplied only when the callable declares parameters.
	 */
	public function resolve(BindingContext $context): mixed {
		$parameterCount=$this->parameterCount ??= $this->reflect($this->resolver)->getNumberOfParameters();
		if($parameterCount===0){
			return ($this->resolver)();
		}
		return ($this->resolver)($context);
	}

	/**
	 * Reflects any callable shape supported by PHP.
	 *
	 * @param callable $callable Resolver callable.
	 * @return \ReflectionFunctionAbstract Reflection metadata for arity checks.
	 */
	private function reflect(callable $callable): \ReflectionFunctionAbstract {
		if(is_array($callable)){
			return new \ReflectionMethod($callable[0], $callable[1]);
		}
		if(is_string($callable) && str_contains($callable, '::')){
			return method_exists(\ReflectionMethod::class, 'createFromMethodName')
				? \ReflectionMethod::createFromMethodName($callable)
				: new \ReflectionMethod($callable);
		}
		if(is_object($callable) && !$callable instanceof \Closure){
			return new \ReflectionMethod($callable, '__invoke');
		}
		return new \ReflectionFunction($callable);
	}
}
