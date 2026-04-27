<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class ConditionalBinding implements BindingMetadataProvider, BindingCacheIdentityProvider, BindingPersistentCacheProvider {

	private function __construct(
		private readonly DataBinding $binding,
		private readonly mixed $condition,
		private readonly bool $unless=false,
		private readonly mixed $default=null
	){}

	public static function when(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): self {
		return new self(self::normalizeBinding($binding), $condition, false, $default);
	}

	public static function unless(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): self {
		return new self(self::normalizeBinding($binding), $condition, true, $default);
	}

	public function name(): string {
		return $this->binding->name();
	}

	public function metadata(): array {
		$metadata=$this->binding instanceof BindingMetadataProvider ? $this->binding->metadata() : [];
		return array_replace($metadata, [
			'conditional'=>true,
			'condition_mode'=>$this->unless ? 'unless' : 'when',
			'condition_type'=>is_bool($this->condition) ? 'bool' : 'callable',
		]);
	}

	public function resolve(BindingContext $context): mixed {
		$matches=$this->evaluate($context);
		if($matches!==true){
			return BindingResolution::skipped($this->default);
		}
		return $this->binding->resolve($context);
	}

	public function cacheIdentity(BindingContext $context): mixed {
		if($this->evaluate($context)!==true){
			return null;
		}
		if(!$this->binding instanceof BindingCacheIdentityProvider){
			return null;
		}
		return $this->binding->cacheIdentity($context);
	}

	public function persistentCache(BindingContext $context): ?array {
		if($this->evaluate($context)!==true){
			return null;
		}
		if(!$this->binding instanceof BindingPersistentCacheProvider){
			return null;
		}
		return $this->binding->persistentCache($context);
	}

	private function evaluate(BindingContext $context): bool {
		$matches=is_callable($this->condition)
			? self::invokeCondition($this->condition, $context)
			: (bool)$this->condition;
		return $this->unless ? !$matches : $matches;
	}

	private static function normalizeBinding(DataBinding|callable $binding): DataBinding {
		return $binding instanceof DataBinding ? $binding : CallableBinding::make($binding);
	}

	private static function invokeCondition(callable $condition, BindingContext $context): bool {
		$reflection=self::reflect($condition);
		if($reflection->getNumberOfParameters()===0){
			return (bool)call_user_func($condition);
		}
		return (bool)call_user_func($condition, $context);
	}

	private static function reflect(callable $callable): \ReflectionFunctionAbstract {
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
