<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

/**
 * Defines the lifecycle contract for MVC service providers.
 *
 * Providers register services, middleware, routes, or configuration during the
 * register phase, then run boot logic after the application and provider
 * registry have been assembled.
 */
interface ServiceProviderContract {

	/**
	 * Registers provider services with the MVC application.
	 *
	 * Register methods should be deterministic and avoid depending on services
	 * that are normally prepared by other providers' boot phases.
	 *
	 * @param MvcApplication $app Application container and runtime context.
	 * @param ProviderRegistry $providers Registry coordinating loaded providers.
	 * @return void
	 */
	public function register(MvcApplication $app, ProviderRegistry $providers): void;

	/**
	 * Boots provider behavior after registration has completed.
	 *
	 * Boot methods may wire behavior that depends on services or providers
	 * registered earlier in the lifecycle.
	 *
	 * @param MvcApplication $app Application container and runtime context.
	 * @param ProviderRegistry $providers Registry coordinating loaded providers.
	 * @return void
	 */
	public function boot(MvcApplication $app, ProviderRegistry $providers): void;
}
