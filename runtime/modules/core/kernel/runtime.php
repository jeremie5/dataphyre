<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

final class runtime {

	private static ?application_definition $current_application_definition=null;
	private static ?string $current_project_root=null;

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

	public static function resolve_application_definition(string $project_root, string $application_name, array $application_roots=[]): ?application_definition {
		$application_directory=app_locator::locate($project_root, $application_name, $application_roots);
		if($application_directory===null){
			return null;
		}
		return self::load_application_definition($application_name, $application_directory);
	}

	public static function current_application_definition(): ?application_definition {
		return self::$current_application_definition;
	}

	public static function current_project_root(): ?string {
		return self::$current_project_root;
	}

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

	private static function boot_framework_application(application_definition $definition): bool {
		if(empty($definition->framework_bootstrap_file) || !is_file($definition->framework_bootstrap_file)){
			return false;
		}
		self::prime_rootpaths($definition);
		require($definition->framework_bootstrap_file);
		return true;
	}

	private static function boot_legacy_application(string $application_directory, ?application_definition $definition=null): void {
		$legacy_bootstrap=$definition?->legacy_bootstrap_file ?? ($application_directory.'/application_bootstrap.php');
		if(!is_file($legacy_bootstrap)){
			throw new \RuntimeException("Application bootstrap not found: {$legacy_bootstrap}");
		}
		require($legacy_bootstrap);
	}

	private static function prime_rootpaths(application_definition $definition): void {
		if(defined('ROOTPATH') || empty($definition->rootpath_file) || !is_file($definition->rootpath_file)){
			return;
		}
		require($definition->rootpath_file);
	}

	private static function register_application_autoload(application_definition $definition): void {
		if(empty($definition->autoload)){
			return;
		}
		autoloader::register_prefixes($definition->autoload);
	}
}
