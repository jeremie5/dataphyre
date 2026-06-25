<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Facade for Dataphyre application discovery and current runtime state.
 *
 * App exposes the small, stable API used by framework modules, bootstraps, and
 * host projects to locate Application records, inspect available roots, read
 * current options, and request runtime module loading through the legacy kernel
 * bridge.
 */
final class App {

	/**
	 * Returns the current application for a project root and optional name.
	 *
	 * Delegates to Application::current(), which may consult cached discovery
	 * state for the active request or process. Null indicates that no matching
	 * application could be resolved.
	 *
	 * @param ?string $projectRoot Project root to inspect, or null for the active root.
	 * @param ?string $applicationName Optional application name to require.
	 * @return ?Application Current application, or null when unavailable.
	 */
	public static function current(?string $projectRoot=null, ?string $applicationName=null): ?Application {
		return Application::current($projectRoot, $applicationName);
	}

	/**
	 * Discovers one application by name.
	 *
	 * Discovery does not imply the application is the current runtime
	 * application; it returns the matching Application descriptor when present
	 * under the selected project root.
	 *
	 * @param string $applicationName Application identifier to find.
	 * @param ?string $projectRoot Project root to inspect, or null for the active root.
	 * @return ?Application Discovered application, or null when absent.
	 */
	public static function find(string $applicationName, ?string $projectRoot=null): ?Application {
		return Application::discover($applicationName, $projectRoot);
	}

	/**
	 * Reports whether an application exists under a project root.
	 *
	 * @param string $applicationName Application identifier to test.
	 * @param ?string $projectRoot Project root to inspect, or null for the active root.
	 * @return bool True when discovery can resolve the application.
	 */
	public static function has(string $applicationName, ?string $projectRoot=null): bool {
		return Application::exists($applicationName, $projectRoot);
	}

	/**
	 * Lists available applications for the selected project root.
	 *
	 * @param ?string $projectRoot Project root to inspect, or null for the active root.
	 * @return array<int, string> Application names exposed by discovery.
	 */
	public static function available(?string $projectRoot=null): array {
		return Application::available($projectRoot);
	}

	/**
	 * Returns a catalog of discovered applications.
	 *
	 * @param ?string $projectRoot Project root to inspect, or null for the active root.
	 * @return ApplicationCatalog Catalog with discovery metadata and application descriptors.
	 */
	public static function catalog(?string $projectRoot=null): ApplicationCatalog {
		return Application::catalog($projectRoot);
	}

	/**
	 * Discovers multiple applications and returns them as a catalog.
	 *
	 * @param array<int, string>|string $applicationNames One name or a list of names to discover.
	 * @param ?string $projectRoot Project root to inspect, or null for the active root.
	 * @return ApplicationCatalog Catalog containing the requested applications that exist.
	 */
	public static function discoverMany(array|string $applicationNames, ?string $projectRoot=null): ApplicationCatalog {
		return Application::discoverMany($applicationNames, $projectRoot);
	}

	/**
	 * Returns application roots known to discovery.
	 *
	 * @param ?string $projectRoot Project root to inspect, or null for the active root.
	 * @return array<string, string> Root paths keyed by application or discovery name.
	 */
	public static function roots(?string $projectRoot=null): array {
		return Application::roots($projectRoot);
	}

	/**
	 * Returns the current bootstrap plan.
	 *
	 * Bootstrap plans describe how the active application was initialized,
	 * including project/application selection and module state. Null indicates
	 * that no bootstrap plan is active for the supplied context.
	 *
	 * @param ?string $projectRoot Project root to inspect, or null for the active root.
	 * @param ?string $applicationName Optional application name to require.
	 * @return ?BootstrapPlan Current bootstrap plan, or null when unavailable.
	 */
	public static function bootstrap(?string $projectRoot=null, ?string $applicationName=null): ?BootstrapPlan {
		return Bootstrap::current($projectRoot, $applicationName);
	}

	/**
	 * Returns the current application id.
	 *
	 * @return ?string Current application id, or null when no application is active.
	 */
	public static function id(): ?string {
		return static::current()?->id;
	}

	/**
	 * Returns the root directory of the current application.
	 *
	 * @return ?string Absolute or configured root directory, or null when no application is active.
	 */
	public static function root(): ?string {
		return static::current()?->root_directory;
	}

	/**
	 * Reads one option from the current application.
	 *
	 * When there is no current application, the supplied default is returned
	 * without triggering discovery errors.
	 *
	 * @param string $key Application option key.
	 * @param mixed $default Value returned when the option or application is absent.
	 * @return mixed configured application option, or the caller default when no app/key is available.
	 */
	public static function option(string $key, mixed $default=null): mixed {
		$application=static::current();
		return $application instanceof Application ? $application->option($key, $default) : $default;
	}

	/**
	 * Loads one framework module through the kernel bridge.
	 *
	 * This method delegates to the legacy snake_case core loader and may mutate
	 * global runtime/module state by including module files or registering module
	 * services. The return value reports the loader result only.
	 *
	 * @param string $module Runtime module name.
	 * @return bool True when the kernel loader reports success.
	 */
	public static function loadFrameworkModule(string $module): bool {
		return \dataphyre\core::load_framework_module($module);
	}

	/**
	 * Loads multiple framework modules through the kernel bridge.
	 *
	 * String input follows the kernel loader's accepted format; array input
	 * loads the listed modules. Results are returned exactly as the kernel
	 * bridge reports them.
	 *
	 * @param array<int, string>|string $modules Module name or list of module names.
	 * @return array<string, bool>|array Kernel loader result map.
	 */
	public static function loadFrameworkModules(array|string $modules): array {
		return \dataphyre\core::load_framework_modules($modules);
	}
}
