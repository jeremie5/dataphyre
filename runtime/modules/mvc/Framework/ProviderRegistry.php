<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use InvalidArgumentException;
use LogicException;

/**
 * Registers and boots MVC service providers for one application instance.
 *
 * ProviderRegistry owns the provider lifecycle around an MvcApplication. Providers are registered once by stable identity, registration is locked as soon as boot begins, and boot() invokes each provider exactly once in registration order. Callable providers are wrapped in CallbackServiceProvider so lightweight extension points share the same lifecycle contract as class-based providers.
 */
final class ProviderRegistry {

	/** @var array<string, ServiceProviderContract> */
	private array $providers=[];
	/** @var array<string, bool> */
	private array $registered=[];
	/** @var array<string, bool> */
	private array $booted=[];
	private bool $isBooting=false;
	private bool $hasBooted=false;

	/**
	 * Creates a registry bound to an MVC application.
	 *
	 * The application reference is passed to provider register() and boot() methods so providers can bind services, routes, middleware, or render-time integrations against the same application state.
	 *
	 * @param MvcApplication $app Application whose providers are managed by this registry.
	 */
	public function __construct(
		private MvcApplication $app
	){}

	/**
	 * Reads the application associated with the registry.
	 *
	 * @return MvcApplication Bound MVC application.
	 */
	public function app(): MvcApplication {
		return $this->app;
	}

	/**
	 * Registers one provider before the application boot phase begins.
	 *
	 * Duplicate provider identities return the existing instance. New registrations are rejected once boot has started, preventing late providers from observing a partially booted application. Provider register() is invoked immediately after resolution.
	 *
	 * @param string|ServiceProviderContract|callable $provider Provider class name, provider instance, or callable provider.
	 * @return ServiceProviderContract Registered provider instance.
	 * @throws LogicException When registration is attempted during or after boot.
	 * @throws InvalidArgumentException When the provider cannot be resolved to a ServiceProviderContract.
	 */
	public function register(string|ServiceProviderContract|callable $provider): ServiceProviderContract {
		$key=$this->providerKey($provider);
		if(isset($this->providers[$key])){
			return $this->providers[$key];
		}
		if($this->isBooting || $this->hasBooted){
			throw new LogicException('MVC service providers cannot be registered after boot has started.');
		}
		$instance=$this->resolveProvider($provider, $key);
		$this->providers[$key]=$instance;
		$instance->register($this->app, $this);
		$this->registered[$key]=true;
		return $instance;
	}

	/**
	 * Registers many providers in iteration order.
	 *
	 * Null and false entries are ignored so config arrays can conditionally include providers. All other entries are passed through register() and therefore share the same duplicate, lifecycle, and validation behavior.
	 *
	 * @param iterable<int|string,mixed> $providers Provider declarations to register.
	 * @return self Same registry for bootstrap chaining.
	 */
	public function registerMany(iterable $providers): self {
		foreach($providers as $provider){
			if($provider===null || $provider===false){
				continue;
			}
			$this->register($provider);
		}
		return $this;
	}

	/**
	 * Boots all registered providers exactly once.
	 *
	 * Boot is idempotent. The registry marks itself as booting while provider boot() methods run, which prevents providers from registering additional providers during the boot loop. Already booted providers are skipped if boot() is re-entered before completion.
	 *
	 * @return void Provider boot side effects are applied to the application.
	 */
	public function boot(): void {
		if($this->hasBooted){
			return;
		}
		$this->isBooting=true;
		try{
			foreach($this->providers as $key=>$provider){
				if(isset($this->booted[$key])){
					continue;
				}
				$provider->boot($this->app, $this);
				$this->booted[$key]=true;
			}
			$this->hasBooted=true;
		}
		finally{
			$this->isBooting=false;
		}
	}

	/**
	 * Checks whether a provider identity is registered.
	 *
	 *
	 * @return bool True when the normalized provider key exists.
	 */
	public function has(string|ServiceProviderContract|callable $provider): bool {
		return isset($this->providers[$this->providerKey($provider)]);
	}

	/**
	 * Retrieves a registered provider by identity.
	 *
	 *
	 * @return ?ServiceProviderContract Provider instance, or null when absent.
	 */
	public function get(string|ServiceProviderContract|callable $provider): ?ServiceProviderContract {
		return $this->providers[$this->providerKey($provider)] ?? null;
	}

	/**
	 * Returns all registered provider instances keyed by identity.
	 *
	 * The returned array preserves registration order and exposes the registry's current provider map for diagnostics or framework boot inspection.
	 *
	 * @return array<string, ServiceProviderContract> Registered providers keyed by provider identity.
	 */
	public function providers(): array {
		return $this->providers;
	}

	/**
	 * Indicates whether every provider in the registry has completed register().
	 *
	 * An empty registry is not considered registered. This method is primarily diagnostic because register() marks providers immediately after invoking their register hook.
	 *
	 * @return bool True when at least one provider exists and all are marked registered.
	 */
	public function registered(): bool {
		return $this->providers!==[] && count($this->registered)===count($this->providers);
	}

	/**
	 * Indicates whether the registry completed its boot phase.
	 *
	 *
	 * @return bool True after boot() has finished successfully.
	 */
	public function booted(): bool {
		return $this->hasBooted;
	}

	/**
	 * Resolves a provider declaration into a ServiceProviderContract instance.
	 *
	 * Existing provider instances are returned directly. Class names are instantiated with no constructor arguments and must implement the provider contract. Callables become CallbackServiceProvider instances keyed by the caller's resolved identity.
	 *
	 * @param string|ServiceProviderContract|callable $provider Provider declaration.
	 * @param string $key Stable provider identity used for callable providers.
	 * @return ServiceProviderContract Resolved provider instance.
	 * @throws InvalidArgumentException When the declaration cannot provide a ServiceProviderContract.
	 */
	private function resolveProvider(string|ServiceProviderContract|callable $provider, string $key): ServiceProviderContract {
		if($provider instanceof ServiceProviderContract){
			return $provider;
		}
		if(is_string($provider) && class_exists($provider)){
			$instance=new $provider();
			if(!$instance instanceof ServiceProviderContract){
				throw new InvalidArgumentException('MVC service provider class must implement '.ServiceProviderContract::class.'.');
			}
			return $instance;
		}
		if(is_callable($provider)){
			return CallbackServiceProvider::fromCallable($provider, $key);
		}
		throw new InvalidArgumentException('MVC service provider must be a provider class name, provider instance, or callable.');
	}

	/**
	 * Computes the stable registry key for a provider declaration.
	 *
	 * Class and provider instances key by class name, callback providers with explicit identity use that identity, closures and invokable objects key by object id, and array callables key by target plus method. These rules make duplicate registration deterministic within the current process.
	 *
	 * @param string|ServiceProviderContract|callable $provider Provider declaration.
	 * @return string Stable provider key.
	 * @throws InvalidArgumentException When the provider declaration is unsupported.
	 */
	private function providerKey(string|ServiceProviderContract|callable $provider): string {
		if($provider instanceof CallbackServiceProvider && $provider->identity()!==null){
			return $provider->identity();
		}
		if($provider instanceof ServiceProviderContract){
			return $provider::class;
		}
		if(is_string($provider)){
			return ltrim($provider, '\\');
		}
		if($provider instanceof \Closure){
			return 'closure:'.spl_object_id($provider);
		}
		if(is_array($provider)){
			[$target, $method]=$provider;
			if(is_object($target)){
				return 'callable:'.spl_object_id($target).'::'.$method;
			}
			return 'callable:'.ltrim((string)$target, '\\').'::'.$method;
		}
		if(is_object($provider)){
			return 'callable:'.spl_object_id($provider);
		}
		throw new InvalidArgumentException('MVC service provider must be a provider class name, provider instance, or callable.');
	}
}
