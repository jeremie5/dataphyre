<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Boots a Dataphyre application from its project root and application definition.
 *
 * Runtime boot resolves the application directory, loads conventional or explicit `app.php`
 * metadata, registers application-specific autoload prefixes, then selects one executable path:
 * compiled routes first, Framework bootstrap second, and legacy bootstrap only when the
 * definition permits it.
 */
final class runtime {

	/**
	 * Application definition selected by the most recent successful boot.
	 */
	private static ?application_definition $current_application_definition=null;

	/**
	 * Project root selected by the most recent successful boot.
	 */
	private static ?string $current_project_root=null;

	/**
	 * Boots an application by name from the configured project/application roots.
	 *
	 * The method mutates process state by setting the current application definition/root and by
	 * registering application autoload prefixes before dispatching a route or requiring a
	 * bootstrap file.
	 *
	 * @param string $project_root Project root used for app location and current-root tracking.
	 * @param string $application_name Application name to locate.
	 * @param array<int, string> $application_roots Optional app root candidates.
	 * @return void
	 *
	 * @throws \RuntimeException When the app cannot be found or has no executable boot path.
	 */
	public static function boot(string $project_root, string $application_name, array $application_roots=[]): void {
		$application_directory=app_locator::locate($project_root, $application_name, $application_roots);
		if($application_directory===null){
			throw new \RuntimeException("Application {$application_name} was not found in any configured application root.");
		}
		$definition=self::load_application_definition($application_name, $application_directory);
		self::$current_application_definition=$definition;
		self::$current_project_root=rtrim($project_root, '/\\');
		self::register_application_autoload($definition);
		if(self::boot_compiled_routes($definition)===true){
			return;
		}
		if(self::boot_framework_application($definition)===true){
			return;
		}
		if($definition->should_fallback_to_legacy_bootstrap()===true){
			self::boot_legacy_application($application_directory, $definition);
			return;
		}
		throw new \RuntimeException("Application {$application_name} has no executable bootstrap path.");
	}

	/**
	 * Resolves an application definition without booting it.
	 *
	 * @param string $project_root Project root used for app location.
	 * @param string $application_name Application name to locate.
	 * @param array<int, string> $application_roots Optional app root candidates.
	 * @return application_definition|null Loaded definition, or null when the app directory cannot be found.
	 */
	public static function resolve_application_definition(string $project_root, string $application_name, array $application_roots=[]): ?application_definition {
		$application_directory=app_locator::locate($project_root, $application_name, $application_roots);
		if($application_directory===null){
			return null;
		}
		return self::load_application_definition($application_name, $application_directory);
	}

	/**
	 * Returns the application definition selected by the active runtime boot.
	 *
	 * @return application_definition|null Current boot definition, or null before boot.
	 */
	public static function current_application_definition(): ?application_definition {
		return self::$current_application_definition;
	}

	/**
	 * Returns the project root selected by the active runtime boot.
	 *
	 * @return string|null Current project root, or null before boot.
	 */
	public static function current_project_root(): ?string {
		return self::$current_project_root;
	}

	/**
	 * Loads an application definition from conventions plus an optional `app.php` override.
	 *
	 * @param string $application_name Application name.
	 * @param string $application_directory Resolved application directory.
	 * @return application_definition Application definition ready for boot decisions.
	 *
	 * @throws \RuntimeException When `app.php` returns an unsupported value.
	 */
	private static function load_application_definition(string $application_name, string $application_directory): application_definition {
		$conventional_definition=application_definition::from_conventions($application_name, $application_directory);
		$definition_file=$application_directory.'/app.php';
		if(!is_file($definition_file)){
			return $conventional_definition;
		}
		$definition=require($definition_file);
		if($definition instanceof application_definition){
			return $definition;
		}
		if(is_array($definition)){
			return $conventional_definition->with_overrides($definition);
		}
		throw new \RuntimeException("Application definition must return an array or application_definition: {$definition_file}");
	}

	/**
	 * Dispatches a compiled route manifest when the application definition points to one.
	 *
	 * @param application_definition $definition Loaded application definition.
	 * @return bool `true` when compiled-route dispatch matched and ran, otherwise `false`.
	 */
	private static function boot_compiled_routes(application_definition $definition): bool {
		if(empty($definition->compiled_routes_file) || !is_file($definition->compiled_routes_file)){
			return false;
		}
		if(class_exists('\dataphyre\routing\compiled_route_dispatcher')===false){
			return false;
		}
		self::prime_rootpaths($definition);
		return \dataphyre\routing\compiled_route_dispatcher::dispatch_file($definition->compiled_routes_file);
	}

	/**
	 * Requires the Framework bootstrap file for an application definition.
	 *
	 * @param application_definition $definition Loaded application definition.
	 * @return bool `true` when a bootstrap file existed and was required.
	 */
	private static function boot_framework_application(application_definition $definition): bool {
		if(empty($definition->framework_bootstrap_file) || !is_file($definition->framework_bootstrap_file)){
			return false;
		}
		self::prime_rootpaths($definition);
		require($definition->framework_bootstrap_file);
		return true;
	}

	/**
	 * Requires the legacy application bootstrap file.
	 *
	 * @param string $application_directory Resolved application directory.
	 * @param ?application_definition $definition Loaded definition that may override the legacy bootstrap path.
	 * @return void
	 *
	 * @throws \RuntimeException When the legacy bootstrap file cannot be found.
	 */
	private static function boot_legacy_application(string $application_directory, ?application_definition $definition=null): void {
		$legacy_bootstrap=$definition?->legacy_bootstrap_file ?? ($application_directory.'/application_bootstrap.php');
		if(!is_file($legacy_bootstrap)){
			throw new \RuntimeException("Application bootstrap not found: {$legacy_bootstrap}");
		}
		require($legacy_bootstrap);
	}

	/**
	 * Loads an application's ROOTPATH definition before route or Framework bootstrap execution.
	 *
	 * @param application_definition $definition Loaded application definition.
	 * @return void
	 */
	private static function prime_rootpaths(application_definition $definition): void {
		if(defined('ROOTPATH') || empty($definition->rootpath_file) || !is_file($definition->rootpath_file)){
			return;
		}
		require($definition->rootpath_file);
	}

	/**
	 * Registers application-level autoload prefixes declared in the application definition.
	 *
	 * @param application_definition $definition Loaded application definition.
	 * @return void
	 */
	private static function register_application_autoload(application_definition $definition): void {
		if(empty($definition->autoload)){
			return;
		}
		autoloader::register_prefixes($definition->autoload);
	}
}
