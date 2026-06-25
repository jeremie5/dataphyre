<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Wraps a data binding behind a runtime condition.
 *
 * ConditionalBinding lets templates declare that a binding should resolve,
 * expose cache identity, and participate in persistent cache only when a
 * boolean or callable condition matches the current BindingContext.
 */
final class ConditionalBinding implements BindingMetadataProvider, BindingCacheIdentityProvider, BindingPersistentCacheProvider {

	/**
	 * Cached callable condition arity used to avoid repeated reflection.
	 */
	private ?int $conditionParameterCount=null;

	/**
	 * Stores the wrapped binding and condition mode.
	 *
	 * @param DataBinding $binding Binding to resolve when the condition matches.
	 * @param mixed $condition Boolean or callable condition.
	 * @param bool $unless Whether the condition result is inverted.
	 * @param mixed $default Default value returned by skipped resolutions.
	 */
	private function __construct(
		private readonly DataBinding $binding,
		private readonly mixed $condition,
		private readonly bool $unless=false,
		private readonly mixed $default=null
	){}

	/**
	 * Creates a binding that resolves when the condition is true.
	 *
	 * Callable bindings are wrapped in CallableBinding so the rest of the
	 * templating pipeline sees a normal DataBinding instance.
	 *
	 * @param DataBinding|callable $binding Binding to resolve conditionally.
	 * @param bool|callable $condition Boolean or context-aware callable condition.
	 * @param mixed $default Default payload for skipped resolution.
	 * @return self Conditional binding in when mode.
	 */
	public static function when(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): self {
		return new self(self::normalizeBinding($binding), $condition, false, $default);
	}

	/**
	 * Creates a binding that resolves unless the condition is true.
	 *
	 *
	 * @param DataBinding|callable $binding Binding to resolve conditionally.
	 * @param bool|callable $condition Boolean or context-aware callable condition.
	 * @param mixed $default Default payload for skipped resolution.
	 * @return self Conditional binding in unless mode.
	 */
	public static function unless(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): self {
		return new self(self::normalizeBinding($binding), $condition, true, $default);
	}

	/**
	 * Returns the wrapped binding name.
	 *
	 * @return string Binding name used by template diagnostics.
	 */
	public function name(): string {
		return $this->binding->name();
	}

	/**
	 * Returns metadata for the wrapped binding plus conditional flags.
	 *
	 * @return array<string, mixed> Metadata containing conditional, condition_mode, and condition_type keys.
	 */
	public function metadata(): array {
		$metadata=$this->binding instanceof BindingMetadataProvider ? $this->binding->metadata() : [];
		return array_replace($metadata, [
			'conditional'=>true,
			'condition_mode'=>$this->unless ? 'unless' : 'when',
			'condition_type'=>is_bool($this->condition) ? 'bool' : 'callable',
		]);
	}

	/**
	 * Resolves the wrapped binding only when the condition matches.
	 *
	 * Skipped bindings return BindingResolution::skipped() with the configured
	 * default so callers can distinguish intentional omission from a resolved
	 * null value.
	 *
	 * @param BindingContext $context Current template binding context.
	 * @return mixed wrapped binding value when active, or BindingResolution::skipped() carrying the configured default.
	 */
	public function resolve(BindingContext $context): mixed {
		$matches=$this->evaluate($context);
		if($matches!==true){
			return BindingResolution::skipped($this->default);
		}
		return $this->binding->resolve($context);
	}

	/**
	 * Returns cache identity only when the condition matches.
	 *
	 * Skipped bindings and bindings that do not implement
	 * BindingCacheIdentityProvider return null, preventing inactive conditional
	 * branches from influencing cache keys.
	 *
	 * @param BindingContext $context Current template binding context.
	 * @return mixed wrapped cache identity when the condition matches, or null for inactive/non-cacheable branches.
	 */
	public function cacheIdentity(BindingContext $context): mixed {
		if($this->evaluate($context)!==true){
			return null;
		}
		if(!$this->binding instanceof BindingCacheIdentityProvider){
			return null;
		}
		return $this->binding->cacheIdentity($context);
	}

	/**
	 * Returns persistent cache metadata only when the condition matches.
	 *
	 * Inactive conditional branches do not request persistent cache entries.
	 *
	 * @param BindingContext $context Current template binding context.
	 * @return ?array<string, mixed> Wrapped persistent cache metadata, or null.
	 */
	public function persistentCache(BindingContext $context): ?array {
		if($this->evaluate($context)!==true){
			return null;
		}
		if(!$this->binding instanceof BindingPersistentCacheProvider){
			return null;
		}
		return $this->binding->persistentCache($context);
	}

	/**
	 * Evaluates the condition against a binding context.
	 *
	 * @param BindingContext $context Current template binding context.
	 * @return bool True when the wrapped binding should run.
	 */
	private function evaluate(BindingContext $context): bool {
		$matches=is_callable($this->condition)
			? $this->invokeCondition($this->condition, $context)
			: (bool)$this->condition;
		return $this->unless ? !$matches : $matches;
	}

	/**
	 * Converts a callable binding into a DataBinding instance.
	 *
	 * @param DataBinding|callable $binding Binding object or resolver callable.
	 * @return DataBinding Normalized binding object.
	 */
	private static function normalizeBinding(DataBinding|callable $binding): DataBinding {
		return $binding instanceof DataBinding ? $binding : CallableBinding::make($binding);
	}

	/**
	 * Invokes a condition callable with optional context.
	 *
	 * @param callable $condition Boolean-producing condition.
	 * @param BindingContext $context Current template binding context.
	 * @return bool Condition result.
	 */
	private function invokeCondition(callable $condition, BindingContext $context): bool {
		$parameterCount=$this->conditionParameterCount ??= self::reflect($condition)->getNumberOfParameters();
		if($parameterCount===0){
			return (bool)$condition();
		}
		return (bool)$condition($context);
	}

	/**
	 * Builds reflection for supported callable forms.
	 *
	 * @param callable $callable Callable condition.
	 * @return \ReflectionFunctionAbstract Reflection used to detect parameter arity.
	 */
	private static function reflect(callable $callable): \ReflectionFunctionAbstract {
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
