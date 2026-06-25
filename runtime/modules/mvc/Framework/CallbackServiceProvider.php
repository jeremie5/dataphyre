<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

/**
 * Adapts callbacks into MVC service-provider lifecycle hooks.
 *
 * CallbackServiceProvider is useful for inline application bootstrapping,
 * plugin-provided providers, and tests that need a provider without defining a
 * dedicated class. Register and boot callbacks receive the application,
 * provider registry, and provider instance.
 */
final class CallbackServiceProvider extends ServiceProvider {

	/**
	 * Creates a callback-backed provider.
	 *
	 * Non-callable callbacks are allowed and simply become no-ops, which lets
	 * factories pass optional boot hooks without extra branching.
	 *
	 * @param mixed $registerCallback Optional callable invoked during register().
	 * @param mixed $bootCallback Optional callable invoked during boot().
	 * @param ?string $identity Optional provider identity used by registries to deduplicate or report providers.
	 */
	public function __construct(
		private mixed $registerCallback,
		private mixed $bootCallback=null,
		private ?string $identity=null
	){}

	/**
	 * Creates a register-only provider from a callable.
	 *
	 * @param callable $callback Register callback.
	 * @param ?string $identity Optional provider identity.
	 * @return self Provider that invokes the callback during register().
	 */
	public static function fromCallable(callable $callback, ?string $identity=null): self {
		return new self($callback, null, $identity);
	}

	/**
	 * Returns the optional provider identity.
	 *
	 * @return ?string Stable provider identity, or null when anonymous.
	 */
	public function identity(): ?string {
		return $this->identity;
	}

	/**
	 * Runs the provider registration lifecycle hook.
	 *
	 * Parent registration runs first so the provider is attached to the
	 * application and registry before the callback mutates services or bindings.
	 *
	 * @param MvcApplication $app Application being configured.
	 * @param ProviderRegistry $providers Registry orchestrating provider lifecycle.
	 * @return void
	 */
	public function register(MvcApplication $app, ProviderRegistry $providers): void {
		parent::register($app, $providers);
		if(is_callable($this->registerCallback)){
			($this->registerCallback)($app, $providers, $this);
		}
	}

	/**
	 * Runs the provider boot lifecycle hook.
	 *
	 * Parent boot runs before the callback so lifecycle state remains consistent
	 * with class-based providers.
	 *
	 * @param MvcApplication $app Booting application.
	 * @param ProviderRegistry $providers Registry orchestrating provider lifecycle.
	 * @return void
	 */
	public function boot(MvcApplication $app, ProviderRegistry $providers): void {
		parent::boot($app, $providers);
		if(is_callable($this->bootCallback)){
			($this->bootCallback)($app, $providers, $this);
		}
	}
}
