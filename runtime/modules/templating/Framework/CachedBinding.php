<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class CachedBinding implements BindingMetadataProvider, BindingCacheIdentityProvider, BindingPersistentCacheProvider {

	private function __construct(
		private readonly DataBinding $binding,
		private readonly mixed $identity,
		private readonly ?string $name=null
	){}

	public static function make(DataBinding|callable $binding, string|array|callable $identity, ?string $name=null): self {
		return new self(
			$binding instanceof DataBinding ? $binding : CallableBinding::make($binding, $name),
			$identity,
			is_string($name) && trim($name)!=='' ? trim($name) : null
		);
	}

	public function name(): string {
		return $this->name ?? $this->binding->name();
	}

	public function metadata(): array {
		$metadata=$this->binding instanceof BindingMetadataProvider ? $this->binding->metadata() : [];
		return array_replace($metadata, [
			'cache_identity_mode'=>$this->identityType(),
		]);
	}

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

	public function resolve(BindingContext $context): mixed {
		return $this->binding->resolve($context);
	}

	public function persistentCache(BindingContext $context): ?array {
		if($this->binding instanceof BindingPersistentCacheProvider){
			return $this->binding->persistentCache($context);
		}
		return null;
	}

	private function identityType(): string {
		return is_callable($this->identity)
			? 'callable'
			: (is_array($this->identity) ? 'array' : get_debug_type($this->identity));
	}

	private function invokeIdentity(callable $identity, BindingContext $context): mixed {
		$reflection=$this->reflect($identity);
		if($reflection->getNumberOfParameters()===0){
			return call_user_func($identity);
		}
		return call_user_func($identity, $context);
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
