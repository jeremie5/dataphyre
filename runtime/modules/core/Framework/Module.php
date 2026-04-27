<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

use dataphyre\module_registry;

final class Module {

	public static function all(): array {
		return module_registry::available_modules();
	}

	public static function enabled(): array {
		return module_registry::enabled_modules();
	}

	public static function disabled(): array {
		return module_registry::disabled_modules();
	}

	public static function has(string $module): bool {
		return module_registry::module_metadata($module)!==false;
	}

	public static function known(string $module): bool {
		return module_registry::module_definition($module)!==false;
	}

	public static function enabledForApp(string $module): bool {
		return module_registry::module_enabled($module);
	}

	public static function metadata(string $module): ?array {
		$metadata=module_registry::module_metadata($module);
		return is_array($metadata) ? $metadata : null;
	}

	public static function definition(string $module): ?ModuleDefinition {
		$definition=module_registry::module_definition($module);
		return is_array($definition) ? ModuleDefinition::fromArray($definition) : null;
	}

	public static function definitions(?bool $enabled=null): array {
		return static::catalog($enabled)->all();
	}

	public static function catalog(?bool $enabled=null): ModuleCatalog {
		return ModuleCatalog::fromDefinitions(module_registry::module_definitions($enabled));
	}

	public static function enabledCatalog(): ModuleCatalog {
		return static::catalog(true);
	}

	public static function disabledCatalog(): ModuleCatalog {
		return static::catalog(false);
	}

	public static function kernelEntry(string $module): ?string {
		$presence=module_registry::kernel_module_present($module);
		return is_array($presence) ? ($presence[0] ?? null) : null;
	}

	public static function kernelVersion(string $module): ?string {
		$presence=module_registry::kernel_module_present($module);
		return is_array($presence) ? ($presence[1] ?? null) : null;
	}

	public static function frameworkEntry(string $module): ?string {
		$entry=module_registry::framework_module_present($module);
		return is_string($entry) ? $entry : null;
	}

	public static function version(string $module): ?string {
		return static::definition($module)?->version();
	}

	public static function directory(string $module): ?string {
		return static::definition($module)?->directory();
	}

	public static function commonDirectory(string $module): ?string {
		return static::definition($module)?->commonDirectory();
	}

	public static function appDirectory(string $module): ?string {
		return static::definition($module)?->appDirectory();
	}

	public static function frameworkNamespace(string $module): ?string {
		return static::definition($module)?->frameworkNamespace();
	}

	public static function hasKernel(string $module): bool {
		return static::kernelEntry($module)!==null;
	}

	public static function hasFramework(string $module): bool {
		$metadata=static::metadata($module);
		return $metadata!==null && (
			!empty($metadata['framework_directory'])
			|| !empty($metadata['framework_entry'])
		);
	}

	public static function loadFramework(string $module): bool {
		return \dataphyre\core::load_framework_module($module);
	}

	public static function loadFrameworkMany(array|string $modules): array {
		return \dataphyre\core::load_framework_modules($modules);
	}
}
