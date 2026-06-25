<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

use Dataphyre\Templating\BindingCacheIdentityProvider;
use Dataphyre\Templating\BindingContext;
use Dataphyre\Templating\BindingMetadataProvider;

/**
 * Adapts an API resolver callable to the templating binding contract.
 *
 * ApiCallableBinding lets API documentation and response templates resolve data
 * from a callable while exposing metadata and optional cache identity to the
 * templating runtime. Resolver callables receive API context, request, route,
 * and binding context arguments according to their declared arity. The resolver
 * is trusted application code; this adapter reflects and invokes it but does not
 * sandbox side effects or convert resolver exceptions.
 */
final class ApiCallableBinding implements BindingMetadataProvider, BindingCacheIdentityProvider {

	/**
	 * Cached resolver arity and variadic state for repeated resolve calls.
	 */
	private ?int $resolverParameterCount=null;
	private ?bool $resolverVariadic=null;

	/**
	 * Cached callable identity arity used to avoid repeated reflection.
	 */
	private ?int $identityParameterCount=null;

	/**
	 * Creates an API callable binding.
	 *
	 * The resolver is expected to be callable at resolve time. Identity may be a
	 * literal, array, scalar, or callable that derives a cache key from the binding
	 * context.
	 *
	 * @param mixed $resolver Resolver callable invoked by resolve().
	 * @param string $name Public binding name.
	 * @param ?string $target Optional API target label for diagnostics.
	 * @param mixed $identity Optional cache identity source.
	 */
	public function __construct(
		private readonly mixed $resolver,
		private readonly string $name='api.callable',
		private readonly ?string $target=null,
		private readonly mixed $identity=null
	){}

	/**
	 * Returns the public binding name.
	 *
	 *
	 * @return string Binding name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns binding metadata for inspection and diagnostics.
	 *
	 * @return array<string, mixed> Metadata describing the API callable target and cache identity mode.
	 */
	public function metadata(): array {
		return array_filter([
			'type'=>'api_callable',
			'driver'=>'api',
			'target_type'=>'callable',
			'target'=>$this->target,
			'cache_identity_mode'=>$this->identity===null ? null : $this->identityType(),
		], static fn(mixed $value): bool => $value!==null && $value!=='');
	}

	/**
	 * Resolves the cache identity for this API binding.
	 *
	 * Strings become `['key' => string]`, scalars become `['value' => scalar]`,
	 * arrays are returned as-is, and unsupported or empty identities return null.
	 * Callable identities may accept zero arguments or the BindingContext.
	 *
	 * @param BindingContext $context Binding context for callable identities.
	 * @return mixed Normalized cache identity, or null when unavailable.
	 */
	public function cacheIdentity(BindingContext $context): mixed {
		$identity=$this->identity;
		if($identity===null){
			return null;
		}
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
	 * Invokes the API resolver and returns its value.
	 *
	 * Resolver arguments are ordered as API context, request, route, and binding
	 * context. Non-variadic resolvers receive only as many arguments as they
	 * declare.
	 * A missing or invalid callable fails through PHP reflection/call semantics.
	 *
	 * @param BindingContext $context Binding context containing optional `api_context` override.
	 * @return mixed value produced by the API resolver after context/request/route arguments are matched.
	 */
	public function resolve(BindingContext $context): mixed {
		if($this->resolverParameterCount===null){
			$reflection=$this->reflect($this->resolver);
			$this->resolverParameterCount=$reflection->getNumberOfParameters();
			$this->resolverVariadic=$reflection->isVariadic();
		}
		$apiContext=$context->overrides()['api_context'] ?? null;
		$request=$apiContext instanceof ApiContext ? $apiContext->request() : null;
		$route=$apiContext instanceof ApiContext ? $apiContext->route() : [];
		if($this->resolverVariadic===true){
			return ($this->resolver)($apiContext, $request, $route, $context);
		}
		return match($this->resolverParameterCount){
			0 => ($this->resolver)(),
			1 => ($this->resolver)($apiContext),
			2 => ($this->resolver)($apiContext, $request),
			3 => ($this->resolver)($apiContext, $request, $route),
			default => ($this->resolver)($apiContext, $request, $route, $context),
		};
	}

	/**
	 * Builds the resolver argument list from the binding context.
	 *
	 * @param BindingContext $context Binding context.
	 * @return array{0: ?ApiContext, 1: mixed, 2: array<string, mixed>, 3: BindingContext}
	 */
	private function invokeArgs(BindingContext $context): array {
		$apiContext=$context->overrides()['api_context'] ?? null;
		$request=$apiContext instanceof ApiContext ? $apiContext->request() : null;
		$route=$apiContext instanceof ApiContext ? $apiContext->route() : [];
		return [$apiContext, $request, $route, $context];
	}

	/**
	 * Describes the configured cache identity source.
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
	 * @param BindingContext $context Binding context passed when accepted.
	 * @return mixed identity value produced by the callable before cache identity normalization.
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
	 * @return \ReflectionFunctionAbstract Reflection used for arity inspection.
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
