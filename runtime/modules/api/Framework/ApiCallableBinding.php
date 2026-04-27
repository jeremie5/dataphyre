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

final class ApiCallableBinding implements BindingMetadataProvider, BindingCacheIdentityProvider {

	public function __construct(
		private readonly mixed $resolver,
		private readonly string $name='api.callable',
		private readonly ?string $target=null,
		private readonly mixed $identity=null
	){}

	public function name(): string {
		return $this->name;
	}

	public function metadata(): array {
		return array_filter([
			'type'=>'api_callable',
			'driver'=>'api',
			'target_type'=>'callable',
			'target'=>$this->target,
			'cache_identity_mode'=>$this->identity===null ? null : $this->identityType(),
		], static fn(mixed $value): bool => $value!==null && $value!=='');
	}

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

	public function resolve(BindingContext $context): mixed {
		$reflection=$this->reflect($this->resolver);
		$args=$this->invokeArgs($context);
		if($reflection->isVariadic()){
			return call_user_func($this->resolver, ...$args);
		}
		return call_user_func($this->resolver, ...array_slice($args, 0, $reflection->getNumberOfParameters()));
	}

	private function invokeArgs(BindingContext $context): array {
		$api_context=$context->overrides()['api_context'] ?? null;
		$request=$api_context instanceof ApiContext ? $api_context->request() : null;
		$route=$api_context instanceof ApiContext ? $api_context->route() : [];
		return [$api_context, $request, $route, $context];
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
