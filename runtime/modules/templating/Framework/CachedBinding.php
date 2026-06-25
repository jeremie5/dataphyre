<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Wraps a binding with a deterministic render-cache identity.
 *
 * CachedBinding does not store values itself. It adapts a DataBinding so the
 * templating runtime can derive a stable cache key from a string, array, scalar,
 * or context-aware callable while delegating all value resolution to the wrapped
 * binding.
 */
final class CachedBinding implements BindingMetadataProvider, BindingCacheIdentityProvider, BindingPersistentCacheProvider {

	/**
	 * Cached callable identity arity used to avoid repeated reflection.
	 */
	private ?int $identityParameterCount=null;

	/**
	 * Stores the wrapped binding and deferred cache identity source.
	 *
	 * @param DataBinding $binding Binding that performs the actual value resolution.
	 * @param mixed $identity Raw cache identity source to normalize per render context.
	 * @param ?string $name Optional public name override.
	 */
	private function __construct(
		private readonly DataBinding $binding,
		private readonly mixed $identity,
		private readonly ?string $name=null
	){}

	/**
	 * Creates a cached binding wrapper.
	 *
	 * Callable bindings are converted to CallableBinding using the optional name.
	 * The identity value is stored as supplied and resolved later against the
	 * BindingContext so cache keys can include request or component state.
	 *
	 * @param DataBinding|callable $binding Binding to wrap.
	 * @param string|array|callable $identity Cache identity source.
	 * @param ?string $name Optional binding name override.
	 * @return self Configured cached binding.
	 */
	public static function make(DataBinding|callable $binding, string|array|callable $identity, ?string $name=null): self {
		return new self(
			$binding instanceof DataBinding ? $binding : CallableBinding::make($binding, $name),
			$identity,
			is_string($name) && trim($name)!=='' ? trim($name) : null
		);
	}

	/**
	 * Returns the public binding name.
	 *
	 * @return string Explicit wrapper name, or the wrapped binding name.
	 */
	public function name(): string {
		return $this->name ?? $this->binding->name();
	}

	/**
	 * Returns metadata for render diagnostics.
	 *
	 * Wrapped binding metadata is preserved and augmented with the cache identity
	 * mode so operators can tell whether cache keys are literal, array-based, or
	 * computed at runtime.
	 *
	 * @return array<string, mixed> Binding metadata with `cache_identity_mode`.
	 */
	public function metadata(): array {
		$metadata=$this->binding instanceof BindingMetadataProvider ? $this->binding->metadata() : [];
		return array_replace($metadata, [
			'cache_identity_mode'=>$this->identityType(),
		]);
	}

	/**
	 * Resolves the binding cache identity for a render context.
	 *
	 * Strings become `['key' => string]`, scalar values become `['value' =>
	 * scalar]`, arrays are returned unchanged, and empty or unsupported identities
	 * return null. Callable identities may accept zero arguments or the current
	 * BindingContext.
	 *
	 * @param BindingContext $context Render context used by callable identities.
	 * @return mixed Normalized cache identity array, or null when no identity can be resolved.
	 */
	public function cacheIdentity(BindingContext $context): mixed {
		$identity=$this->identity;
		if(is_callable($identity)){
			$identity=$this->invokeIdentity($identity, $context);
		}
		if(is_string($identity)){
			$identity=trim($identity);
			return $identity!=='' ? ['key'=>$identity] : null;
		}
		if(is_array($identity)){
			return $identity;
		}
		if(is_scalar($identity)){
			return ['value'=>$identity];
		}
		return null;
	}

	/**
	 * Resolves the wrapped binding value.
	 *
	 * @param BindingContext $context Render context passed through to the wrapped binding.
	 * @return mixed value produced by the wrapped binding; this wrapper only contributes render-cache identity.
	 */
	public function resolve(BindingContext $context): mixed {
		return $this->binding->resolve($context);
	}

	/**
	 * Forwards persistent cache metadata from the wrapped binding when available.
	 *
	 * CachedBinding only contributes the transient render-cache identity; durable
	 * cache policy remains owned by the wrapped binding.
	 *
	 * @param BindingContext $context Render context passed to the wrapped binding.
	 * @return ?array<string, mixed> Persistent cache policy, or null when the wrapped binding has none.
	 */
	public function persistentCache(BindingContext $context): ?array {
		if($this->binding instanceof BindingPersistentCacheProvider){
			return $this->binding->persistentCache($context);
		}
		return null;
	}

	/**
	 * Describes the configured identity source for metadata.
	 *
	 * @return string Callable, array, or debug type of the identity source.
	 */
	private function identityType(): string {
		return is_callable($this->identity)
			? 'callable'
			: (is_array($this->identity) ? 'array' : get_debug_type($this->identity));
	}

	/**
	 * Invokes a cache identity callable with the correct arity.
	 *
	 * @param callable $identity Identity factory.
	 * @param BindingContext $context Render context passed when the callable accepts arguments.
	 * @return mixed identity value produced by the callable before string/scalar/array normalization.
	 */
	private function invokeIdentity(callable $identity, BindingContext $context): mixed {
		$parameterCount=$this->identityParameterCount ??= $this->reflect($identity)->getNumberOfParameters();
		if($parameterCount===0){
			return $identity();
		}
		return $identity($context);
	}

	/**
	 * Reflects any supported PHP callable shape.
	 *
	 * @param callable $callable Closure, function name, static method, array callable, or invokable object.
	 * @return \ReflectionFunctionAbstract Reflection object used to inspect callable arity.
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
