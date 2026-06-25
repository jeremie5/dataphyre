<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

/**
 * Base class for MVC service providers.
 *
 * Providers receive the application and provider registry during both registration and boot
 * phases. Subclasses can override either phase while still using the protected references as a
 * stable way to access the current MVC application and sibling providers.
 */
abstract class ServiceProvider implements ServiceProviderContract {

	/**
	 * Application currently being registered or booted.
	 */
	protected ?MvcApplication $app=null;

	/**
	 * Provider registry coordinating the active provider set.
	 */
	protected ?ProviderRegistry $providers=null;

	/**
	 * Captures provider context during the registration phase.
	 *
	 * @param MvcApplication $app Application being configured.
	 * @param ProviderRegistry $providers Registry coordinating service providers.
	 * @return void
	 */
	public function register(MvcApplication $app, ProviderRegistry $providers): void {
		$this->app=$app;
		$this->providers=$providers;
	}

	/**
	 * Captures provider context during the boot phase.
	 *
	 * @param MvcApplication $app Application being booted.
	 * @param ProviderRegistry $providers Registry coordinating service providers.
	 * @return void
	 */
	public function boot(MvcApplication $app, ProviderRegistry $providers): void {
		$this->app=$app;
		$this->providers=$providers;
	}
}
