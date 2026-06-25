<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Builds and executes bootstrap plans for Dataphyre applications.
 *
 * Bootstrap resolves application metadata from the current runtime or catalog,
 * turns it into immutable BootstrapPlan objects, and executes module loading
 * through those plans. Missing applications fail fast when booting but return
 * null for discovery-style calls.
 */
final class Bootstrap {

	/**
	 * Builds a bootstrap plan for the current application context.
	 *
	 * @param ?string $projectRoot Project root override.
	 * @param ?string $applicationName Application name override.
	 * @return ?BootstrapPlan Current application plan, or null when no application can be resolved.
	 */
	public static function current(?string $projectRoot=null, ?string $applicationName=null): ?BootstrapPlan {
		$application=Application::current($projectRoot, $applicationName);
		if(!$application instanceof Application){
			return null;
		}
		return BootstrapPlan::fromApplication(
			$application,
			$projectRoot ?? Runtime::projectRoot()
		);
	}

	/**
	 * Resolves a named application into a bootstrap plan.
	 *
	 * @param string $applicationName Application id or name to discover.
	 * @param ?string $projectRoot Project root override.
	 * @return ?BootstrapPlan Bootstrap plan, or null when the application is unknown.
	 */
	public static function resolve(string $applicationName, ?string $projectRoot=null): ?BootstrapPlan {
		$application=Application::discover($applicationName, $projectRoot);
		if(!$application instanceof Application){
			return null;
		}
		return BootstrapPlan::fromApplication($application, $projectRoot);
	}

	/**
	 * Builds a bootstrap plan from an Application object or application name.
	 *
	 * @param Application|string $application Application object or discoverable application name.
	 * @param ?string $projectRoot Project root override.
	 * @return ?BootstrapPlan Bootstrap plan, or null when a string application cannot be resolved.
	 */
	public static function for(Application|string $application, ?string $projectRoot=null): ?BootstrapPlan {
		if($application instanceof Application){
			return BootstrapPlan::fromApplication($application, $projectRoot ?? Runtime::projectRoot());
		}
		return static::resolve($application, $projectRoot);
	}

	/**
	 * Builds a catalog of bootstrap plans for every discovered application.
	 *
	 * @param ?string $projectRoot Project root override.
	 * @return BootstrapCatalog Catalog keyed by application id.
	 */
	public static function catalog(?string $projectRoot=null): BootstrapCatalog {
		$projectRoot=$projectRoot ?? Runtime::projectRoot();
		$plans=[];
		foreach(Application::catalog($projectRoot) as $application){
			$plans[$application->id]=BootstrapPlan::fromApplication($application, $projectRoot);
		}
		return new BootstrapCatalog($projectRoot, $plans);
	}

	/**
	 * Resolves and executes a bootstrap plan.
	 *
	 * Booting may load runtime modules, register module state, and mutate shared
	 * runtime configuration according to the selected application plan.
	 *
	 * @param Application|string $application Application object or discoverable application name.
	 * @param ?string $projectRoot Project root override.
	 * @return BootstrapPlan Executed bootstrap plan.
	 *
	 * @throws \RuntimeException When the application cannot be resolved.
	 */
	public static function boot(Application|string $application, ?string $projectRoot=null): BootstrapPlan {
		$plan=static::for($application, $projectRoot);
		if(!$plan instanceof BootstrapPlan){
			$applicationId=$application instanceof Application ? $application->id : trim($application);
			throw new \RuntimeException("Application {$applicationId} could not be resolved for boot.");
		}
		$plan->boot();
		return $plan;
	}
}
