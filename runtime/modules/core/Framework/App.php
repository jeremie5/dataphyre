<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class App {

	public static function current(?string $project_root=null, ?string $application_name=null): ?Application {
		return Application::current($project_root, $application_name);
	}

	public static function find(string $application_name, ?string $project_root=null): ?Application {
		return Application::discover($application_name, $project_root);
	}

	public static function has(string $application_name, ?string $project_root=null): bool {
		return Application::exists($application_name, $project_root);
	}

	public static function available(?string $project_root=null): array {
		return Application::available($project_root);
	}

	public static function catalog(?string $project_root=null): ApplicationCatalog {
		return Application::catalog($project_root);
	}

	public static function discoverMany(array|string $application_names, ?string $project_root=null): ApplicationCatalog {
		return Application::discoverMany($application_names, $project_root);
	}

	public static function roots(?string $project_root=null): array {
		return Application::roots($project_root);
	}

	public static function bootstrap(?string $project_root=null, ?string $application_name=null): ?BootstrapPlan {
		return Bootstrap::current($project_root, $application_name);
	}

	public static function id(): ?string {
		return static::current()?->id;
	}

	public static function root(): ?string {
		return static::current()?->root_directory;
	}

	public static function option(string $key, mixed $default=null): mixed {
		$application=static::current();
		return $application instanceof Application ? $application->option($key, $default) : $default;
	}

	public static function loadFrameworkModule(string $module): bool {
		return \dataphyre\core::load_framework_module($module);
	}

	public static function loadFrameworkModules(array|string $modules): array {
		return \dataphyre\core::load_framework_modules($modules);
	}
}
