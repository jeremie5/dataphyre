<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class RememberedBinding implements BindingMetadataProvider, BindingCacheIdentityProvider, BindingPersistentCacheProvider {

	private function __construct(
		private readonly DataBinding $binding,
		private readonly mixed $identity=null,
		private readonly int $ttl=300,
		private readonly array $names=[],
		private readonly ?string $name=null
	){}

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

	public function name(): string {
		return $this->name ?? $this->binding->name();
	}

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

	public function cacheIdentity(BindingContext $context): mixed {
		if($this->identity!==null){
			return $this->resolveIdentity($context);
		}
		if($this->binding instanceof BindingCacheIdentityProvider){
			return $this->binding->cacheIdentity($context);
		}
		return null;
	}

	public function persistentCache(BindingContext $context): ?array {
		return [
			'ttl'=>$this->ttl,
			'names'=>$this->names,
			'identity'=>$this->identity!==null ? $this->resolveIdentity($context) : null,
		];
	}

	public function resolve(BindingContext $context): mixed {
		return $this->binding->resolve($context);
	}

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
