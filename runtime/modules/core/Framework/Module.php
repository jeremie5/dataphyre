<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

use dataphyre\module_registry;

/**
 * Static facade over the Dataphyre module registry.
 *
 * Module exposes registry queries, ModuleDefinition hydration, catalog filtering, kernel/framework
 * entry inspection, and framework loading without requiring callers to use the lower-case kernel
 * registry API directly.
 */
final class Module {

	/**
	 * Returns every module discovered by the registry.
	 *
	 * @return array Registry payloads for known modules, keyed as provided by module_registry.
	 */
	public static function all(): array {
		return module_registry::available_modules();
	}

	/**
	 * Returns modules enabled for the current application/runtime.
	 *
	 * @return array Registry payloads for enabled modules.
	 */
	public static function enabled(): array {
		return module_registry::enabled_modules();
	}

	/**
	 * Returns modules known to the registry but disabled for the current application/runtime.
	 *
	 * @return array Registry payloads for disabled modules.
	 */
	public static function disabled(): array {
		return module_registry::disabled_modules();
	}

	/**
	 * Reports whether runtime metadata exists for a module.
	 *
	 * This checks registry metadata, not whether a ModuleDefinition can be hydrated.
	 *
	 * @param string $module Module name to inspect.
	 * @return bool True when registry metadata exists.
	 */
	public static function has(string $module): bool {
		return module_registry::module_metadata($module)!==false;
	}

	/**
	 * Reports whether the registry has a complete module definition.
	 *
	 * @param string $module Module name to inspect.
	 * @return bool True when module_registry can return a definition array.
	 */
	public static function known(string $module): bool {
		return module_registry::module_definition($module)!==false;
	}

	/**
	 * Reports whether a module is enabled for the current application.
	 *
	 * @param string $module Module name to inspect.
	 * @return bool True when the app/runtime enables the module.
	 */
	public static function enabledForApp(string $module): bool {
		return module_registry::module_enabled($module);
	}

	/**
	 * Returns raw registry metadata for a module.
	 *
	 * @param string $module Module name to inspect.
	 * @return ?array Registry metadata, or null when metadata is absent.
	 */
	public static function metadata(string $module): ?array {
		$metadata=module_registry::module_metadata($module);
		return is_array($metadata) ? $metadata : null;
	}

	/**
	 * Hydrates a ModuleDefinition from the registry.
	 *
	 * @param string $module Module name to inspect.
	 * @return ?ModuleDefinition Immutable definition, or null when the registry has no definition.
	 */
	public static function definition(string $module): ?ModuleDefinition {
		$definition=module_registry::module_definition($module);
		return is_array($definition) ? ModuleDefinition::fromArray($definition) : null;
	}

	/**
	 * Returns hydrated module definitions, optionally filtered by enabled state.
	 *
	 * @param ?bool $enabled Null for all definitions, true for enabled only, false for disabled only.
	 * @return array<string,ModuleDefinition> Definitions keyed by module name.
	 */
	public static function definitions(?bool $enabled=null): array {
		return static::catalog($enabled)->all();
	}

	/**
	 * Builds a ModuleCatalog from registry definitions.
	 *
	 * @param ?bool $enabled Null for all definitions, true for enabled only, false for disabled only.
	 * @return ModuleCatalog Catalog wrapper for querying hydrated definitions.
	 */
	public static function catalog(?bool $enabled=null): ModuleCatalog {
		return ModuleCatalog::fromDefinitions(module_registry::module_definitions($enabled));
	}

	/**
	 * Builds a catalog containing only enabled modules.
	 *
	 * @return ModuleCatalog Catalog filtered to enabled definitions.
	 */
	public static function enabledCatalog(): ModuleCatalog {
		return static::catalog(true);
	}

	/**
	 * Builds a catalog containing only disabled modules.
	 *
	 * @return ModuleCatalog Catalog filtered to disabled definitions.
	 */
	public static function disabledCatalog(): ModuleCatalog {
		return static::catalog(false);
	}

	/**
	 * Returns the kernel entry file for a module when present.
	 *
	 * @param string $module Module name to inspect.
	 * @return ?string Kernel entry path, or null when no kernel source is present.
	 */
	public static function kernelEntry(string $module): ?string {
		$presence=module_registry::kernel_module_present($module);
		return is_array($presence) ? ($presence[0] ?? null) : null;
	}

	/**
	 * Returns the kernel module version reported by presence detection.
	 *
	 * @param string $module Module name to inspect.
	 * @return ?string Kernel version, or null when no kernel source is present.
	 */
	public static function kernelVersion(string $module): ?string {
		$presence=module_registry::kernel_module_present($module);
		return is_array($presence) ? ($presence[1] ?? null) : null;
	}

	/**
	 * Returns the framework entry file for a module when present.
	 *
	 * @param string $module Module name to inspect.
	 * @return ?string Framework entry path, or null when no framework entry is present.
	 */
	public static function frameworkEntry(string $module): ?string {
		$entry=module_registry::framework_module_present($module);
		return is_string($entry) ? $entry : null;
	}

	/**
	 * Returns the module definition version.
	 *
	 * @param string $module Module name to inspect.
	 * @return ?string Definition version, or null when the module is unknown.
	 */
	public static function version(string $module): ?string {
		return static::definition($module)?->version();
	}

	/**
	 * Returns the module root directory from its definition.
	 *
	 * @param string $module Module name to inspect.
	 * @return ?string Module root directory, or null when unavailable.
	 */
	public static function directory(string $module): ?string {
		return static::definition($module)?->directory();
	}

	/**
	 * Returns the common/shared module source directory.
	 *
	 * @param string $module Module name to inspect.
	 * @return ?string Common source directory, or null when the module has no common source.
	 */
	public static function commonDirectory(string $module): ?string {
		return static::definition($module)?->commonDirectory();
	}

	/**
	 * Returns the application-specific module source directory.
	 *
	 * @param string $module Module name to inspect.
	 * @return ?string Application source directory, or null when the module has no app source.
	 */
	public static function appDirectory(string $module): ?string {
		return static::definition($module)?->appDirectory();
	}

	/**
	 * Returns the framework namespace declared by the module definition.
	 *
	 * @param string $module Module name to inspect.
	 * @return ?string Framework namespace, or null when the module has no framework namespace.
	 */
	public static function frameworkNamespace(string $module): ?string {
		return static::definition($module)?->frameworkNamespace();
	}

	/**
	 * Reports whether the module has a kernel entry.
	 *
	 * @param string $module Module name to inspect.
	 * @return bool True when kernel presence detection found an entry file.
	 */
	public static function hasKernel(string $module): bool {
		return static::kernelEntry($module)!==null;
	}

	/**
	 * Reports whether the module exposes framework source.
	 *
	 * @param string $module Module name to inspect.
	 * @return bool True when metadata declares a framework directory or framework entry.
	 */
	public static function hasFramework(string $module): bool {
		$metadata=static::metadata($module);
		return $metadata!==null && (
			!empty($metadata['framework_directory'])
			|| !empty($metadata['framework_entry'])
		);
	}

	/**
	 * Loads one module's framework entry through the core runtime loader.
	 *
	 * @param string $module Module name to load.
	 * @return bool True when the framework loader reports success.
	 */
	public static function loadFramework(string $module): bool {
		return \dataphyre\core::load_framework_module($module);
	}

	/**
	 * Loads multiple framework modules through the core runtime loader.
	 *
	 * @param array|string $modules Module name or list of module names to load.
	 * @return array Loader result keyed by module name.
	 */
	public static function loadFrameworkMany(array|string $modules): array {
		return \dataphyre\core::load_framework_modules($modules);
	}
}
