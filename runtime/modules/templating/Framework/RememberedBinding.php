<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Wraps a binding with persistent cache policy.
 *
 * RememberedBinding describes how the templating runtime may store a binding
 * value beyond a single render pass. It carries TTL, cache namespaces, and an
 * optional cache identity while leaving actual resolution to the wrapped binding.
 */
final class RememberedBinding implements BindingMetadataProvider, BindingCacheIdentityProvider, BindingPersistentCacheProvider {

	/**
	 * Cached callable identity arity used to avoid repeated reflection.
	 */
	private ?int $identityParameterCount=null;

	/**
	 * Stores the wrapped binding and persistent cache policy.
	 *
	 * @param DataBinding $binding Binding that performs actual value resolution.
	 * @param mixed $identity Optional raw identity source to normalize per context.
	 * @param int $ttl Cache lifetime in seconds.
	 * @param array<int, string> $names Normalized cache namespace names.
	 * @param ?string $name Optional public name override.
	 */
	private function __construct(
		private readonly DataBinding $binding,
		private readonly mixed $identity=null,
		private readonly int $ttl=300,
		private readonly array $names=[],
		private readonly ?string $name=null
	){}

	/**
	 * Creates a persistent-cache binding wrapper.
	 *
	 * Callable bindings are wrapped as CallableBinding. TTL values are clamped to
	 * at least one second, and cache namespace names are normalized and
	 * de-duplicated before being exposed to the runtime.
	 *
	 * @param DataBinding|callable $binding Binding to wrap.
	 * @param string|array|callable|null $identity Optional persistent cache identity source.
	 * @param int $ttl Cache lifetime in seconds.
	 * @param array|string $names Cache namespace names used for invalidation or grouping.
	 * @param ?string $name Optional binding name override.
	 * @return self Configured remembered binding.
	 */
	public static function make(
		DataBinding|callable $binding,
		string|array|callable|null $identity=null,
		int $ttl=300,
		array|string $names=[],
		?string $name=null
	): self {
		return new self(
			$binding instanceof DataBinding ? $binding : CallableBinding::make($binding, $name),
			$identity,
			max(1, $ttl),
			self::normalizeNames($names),
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
	 * The payload marks the binding as persistent-cacheable, exposes TTL and
	 * cache namespace names, and includes the identity mode when a wrapper-level
	 * identity overrides the wrapped binding.
	 *
	 * @return array<string, mixed> Binding metadata with persistent cache fields.
	 */
	public function metadata(): array {
		$metadata=$this->binding instanceof BindingMetadataProvider ? $this->binding->metadata() : [];
		$metadata['persistent_cache']=true;
		$metadata['persistent_cache_ttl']=$this->ttl;
		if($this->names!==[]){
			$metadata['persistent_cache_names']=$this->names;
		}
		if($this->identity!==null){
			$metadata['cache_identity_mode']=$this->identityType();
		}
		return $metadata;
	}

	/**
	 * Resolves the cache identity for this render context.
	 *
	 * A wrapper identity takes precedence. If none is configured, identity
	 * resolution is delegated to the wrapped binding when it implements
	 * BindingCacheIdentityProvider.
	 *
	 * @param BindingContext $context Render context used by callable identities.
	 * @return mixed Normalized cache identity, delegated identity, or null.
	 */
	public function cacheIdentity(BindingContext $context): mixed {
		if($this->identity!==null){
			return $this->resolveIdentity($context);
		}
		if($this->binding instanceof BindingCacheIdentityProvider){
			return $this->binding->cacheIdentity($context);
		}
		return null;
	}

	/**
	 * Returns the persistent cache policy for this binding.
	 *
	 * The policy includes TTL, normalized names, and the resolved identity for the
	 * current context. A null identity means the runtime may choose a broader
	 * binding-level cache key or decline persistence.
	 *
	 * @param BindingContext $context Render context used to resolve identity.
	 * @return array{ttl: int, names: array<int, string>, identity: mixed} Persistent cache policy.
	 */
	public function persistentCache(BindingContext $context): ?array {
		return [
			'ttl'=>$this->ttl,
			'names'=>$this->names,
			'identity'=>$this->identity!==null ? $this->resolveIdentity($context) : null,
		];
	}

	/**
	 * Resolves the wrapped binding value.
	 *
	 * @param BindingContext $context Render context passed through to the wrapped binding.
	 * @return mixed value produced by the wrapped binding; persistent-cache storage is handled by the templating runtime.
	 */
	public function resolve(BindingContext $context): mixed {
		return $this->binding->resolve($context);
	}

	/**
	 * Normalizes the wrapper-level cache identity.
	 *
	 * @param BindingContext $context Render context used by callable identities.
	 * @return mixed Normalized cache identity array, or null when no usable identity exists.
	 */
	private function resolveIdentity(BindingContext $context): mixed {
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
	 * @return mixed identity value produced by the callable before persistent-cache identity normalization.
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

	/**
	 * Normalizes cache namespace names.
	 *
	 * @param array<int, mixed>|string $names Single name or list of candidate names.
	 * @return array<int, string> Trimmed unique non-empty names.
	 */
	private static function normalizeNames(array|string $names): array {
		$names=is_array($names) ? $names : [$names];
		$normalized=[];
		foreach($names as $name){
			if(!is_string($name)){
				continue;
			}
			$name=trim($name);
			if($name===''){
				continue;
			}
			$normalized[$name]=true;
		}
		return array_keys($normalized);
	}
}
