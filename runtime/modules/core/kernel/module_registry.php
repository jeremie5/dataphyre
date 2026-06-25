<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Discovers Dataphyre runtime modules and resolves their kernel and framework entrypoints.
 *
 * The registry scans common and application module directories, merges application overrides over shared definitions, applies configured enabled/disabled lists, and caches normalized definitions for bootstrap callers. Disabled application shadow directories prefixed with "-" suppress shared modules without adding compatibility layers.
 */
final class module_registry {

	private static ?array $module_config=null;
	private static array $metadata_cache=[];
	private static array $definition_cache=[];
	private static ?array $available_modules_cache=null;
	private static array $framework_namespace_aliases=[
		'sql'=>'Database',
	];

	/**
	 * Resolves the kernel bootstrap file for an enabled module.
	 *
	 * The returned tuple matches the legacy bootstrap contract: absolute kernel entry path followed by the module version. A false return means the module is unknown, disabled, or has no kernel entry even if it exposes framework classes.
	 *
	 * @param string $module Module name to normalize and inspect.
	 * @return array{0:string,1:string}|false Kernel entry path and version, or false when unavailable.
	 */
	public static function kernel_module_present(string $module): array|bool {
		$metadata=self::module_metadata($module);
		if($metadata===false || empty($metadata['kernel_entry'])){
			return false;
		}
		return [$metadata['kernel_entry'], $metadata['version'] ?? '1.0'];
	}

	/**
	 * Resolves the framework bootstrap file for an enabled module.
	 *
	 * Framework entrypoints are optional. Modules may expose a Framework directory without a bootstrap file, in which case this method returns false while module_definition() still records the framework directory and namespace.
	 *
	 * @param string $module Module name to normalize and inspect.
	 * @return string|false Framework bootstrap file path, or false when none is available.
	 */
	public static function framework_module_present(string $module): string|bool {
		$metadata=self::module_metadata($module);
		if($metadata===false || empty($metadata['framework_entry']) || is_string($metadata['framework_entry'])===false){
			return false;
		}
		return $metadata['framework_entry'];
	}

	/**
	 * Lists module directory names discoverable from common and application roots.
	 *
	 * Availability is filesystem-based and does not imply that a module is enabled or has a usable entrypoint. Application and common roots are unioned, dash-prefixed directories are ignored, names are normalized to lowercase, and the result is sorted for deterministic bootstrap order.
	 *
	 * @return array<int,string> Sorted normalized module names discovered on disk.
	 */
	public static function available_modules(): array {
		if(self::$available_modules_cache!==null){
			return self::$available_modules_cache;
		}
		if(defined('ROOTPATH')===false){
			return self::$available_modules_cache=[];
		}
		$modules=[];
		foreach([
			ROOTPATH['common_dataphyre_runtime'].'modules/',
			ROOTPATH['dataphyre'].'modules/',
		] as $modules_root){
			if(!is_dir($modules_root)){
				continue;
			}
			foreach(scandir($modules_root) ?: [] as $entry){
				if($entry==='.' || $entry==='..'){
					continue;
				}
				$entry=self::normalize_module_name($entry);
				if($entry==='' || $entry[0]==='-'){
					continue;
				}
				if(!is_dir(rtrim($modules_root, '/\\').'/'.$entry)){
					continue;
				}
				$modules[$entry]=true;
			}
		}
		$names=array_keys($modules);
		sort($names);
		return self::$available_modules_cache=$names;
	}

	/**
	 * Lists available modules whose resolved definition is enabled.
	 *
	 * Enabled state is derived from module configuration, disabled application shadow directories, and successful definition inspection. Returned names follow available_modules() ordering.
	 *
	 * @return array<int,string> Enabled module names.
	 */
	public static function enabled_modules(): array {
		$enabled=[];
		foreach(self::available_modules() as $module){
			$definition=self::module_definition($module);
			if(is_array($definition) && ($definition['enabled'] ?? false)===true){
				$enabled[]=$module;
			}
		}
		return $enabled;
	}

	/**
	 * Lists modules with valid definitions that are currently disabled.
	 *
	 * This reports modules that can be inspected but fail the enabled-state filter. Missing or structurally invalid module directories are not included because no definition can be built for them.
	 *
	 * @return array<int,string> Sorted disabled module names.
	 */
	public static function disabled_modules(): array {
		$disabled=array_keys(self::module_definitions(false));
		sort($disabled);
		return $disabled;
	}

	/**
	 * Checks whether one module resolves to an enabled definition.
	 *
	 * Blank names are rejected after normalization. A true return means the module has at least one valid kernel or framework surface and is not filtered by enabled/disabled configuration.
	 *
	 * @param string $module Module name to normalize and inspect.
	 * @return bool True when the module is known and enabled.
	 */
	public static function module_enabled(string $module): bool {
		$module=self::normalize_module_name($module);
		if($module===''){
			return false;
		}
		$definition=self::module_definition($module);
		return is_array($definition) && ($definition['enabled'] ?? false)===true;
	}

	/**
	 * Returns public metadata for an enabled module definition.
	 *
	 * Metadata is the resolved definition without the internal enabled flag. Disabled, missing, and invalid modules return false and are cached so bootstrap callers can repeat lookups without repeated filesystem inspection.
	 *
	 * @param string $module Module name to normalize and inspect.
	 * @return array<string,mixed>|false Enabled module metadata, or false when unavailable.
	 */
	public static function module_metadata(string $module): array|bool {
		$module=self::normalize_module_name($module);
		if($module===''){
			return false;
		}
		if(array_key_exists($module, self::$metadata_cache)){
			return self::$metadata_cache[$module];
		}
		$definition=self::module_definition($module);
		if($definition===false || ($definition['enabled'] ?? false)!==true){
			return self::$metadata_cache[$module]=false;
		}
		$metadata=$definition;
		unset($metadata['enabled']);
		return self::$metadata_cache[$module]=$metadata;
	}

	/**
	 * Builds the full resolved definition for one module.
	 *
	 * Shared runtime modules provide the base definition; application modules can replace kernel or framework entrypoints and record their own directory. A valid definition must expose at least a kernel entry, a framework bootstrap, or a framework directory. The final enabled flag combines configuration allow/deny lists with application dash-directory suppression.
	 *
	 * @param string $module Module name to normalize and inspect.
	 * @return array<string,mixed>|false Resolved definition with directory and entrypoint metadata, or false when invalid.
	 */
	public static function module_definition(string $module): array|bool {
		$module=self::normalize_module_name($module);
		if($module===''){
			return false;
		}
		if(array_key_exists($module, self::$definition_cache)){
			return self::$definition_cache[$module];
		}
		if(defined('ROOTPATH')===false){
			return self::$definition_cache[$module]=false;
		}
		$common=self::inspect_module_directory(ROOTPATH['common_dataphyre_runtime'].'modules/'.$module.'/', $module);
		$app=self::inspect_module_directory(ROOTPATH['dataphyre'].'modules/'.$module.'/', $module);
		if($common!==null){
			$common['common_directory']=$common['directory'];
		}
		if($app!==null){
			$app['app_directory']=$app['directory'];
		}
		if($common===null && $app===null){
			return self::$definition_cache[$module]=false;
		}
		$definition=$common ?? [
			'module'=>$module,
			'version'=>'1.0',
			'kernel_entry'=>null,
			'framework_entry'=>null,
			'framework_directory'=>null,
			'framework_namespace'=>null,
			'directory'=>null,
			'common_directory'=>null,
			'app_directory'=>null,
		];
		if($app!==null){
			$definition['app_directory']=$app['directory'];
			if(!empty($app['kernel_entry'])){
				$definition['kernel_entry']=$app['kernel_entry'];
				$definition['directory']=$app['directory'];
				$definition['version']=$app['version'];
			}
			if(!empty($app['framework_entry'])){
				$definition['framework_entry']=$app['framework_entry'];
			}
			if(!empty($app['framework_directory'])){
				$definition['framework_directory']=$app['framework_directory'];
				$definition['framework_namespace']=$app['framework_namespace'];
			}
			if($definition['directory']===null){
				$definition['directory']=$app['directory'];
			}
		}
		if(
			($definition['kernel_entry'] ?? null)===null
			&& ($definition['framework_entry'] ?? null)===null
			&& ($definition['framework_directory'] ?? null)===null
		){
			return self::$definition_cache[$module]=false;
		}
		$definition['enabled']=self::is_enabled($module) && self::is_app_disabled($module)===false;
		return self::$definition_cache[$module]=$definition;
	}

	/**
	 * Returns resolved module definitions, optionally filtered by enabled state.
	 *
	 * Definitions are keyed by normalized module name. Invalid module directories are skipped, and the optional filter compares against the resolved enabled flag after configuration and app-level suppression have been applied.
	 *
	 * @param ?bool $enabled Null for all valid definitions, true for enabled only, false for disabled only.
	 * @return array<string,array<string,mixed>> Definitions keyed by module name.
	 */
	public static function module_definitions(?bool $enabled=null): array {
		$definitions=[];
		foreach(self::available_modules() as $module){
			$definition=self::module_definition($module);
			if(!is_array($definition)){
				continue;
			}
			if($enabled!==null && (($definition['enabled'] ?? false)===true)!==$enabled){
				continue;
			}
			$definitions[$module]=$definition;
		}
		return $definitions;
	}

	/**
	 * Loads and merges module enablement configuration from all supported sources.
	 *
	 * Configuration may be a simple list of enabled modules or an associative payload with enabled and disabled keys. Later sources override the enabled allow-list, while disabled lists accumulate across sources.
	 *
	 * @return array{enabled:?array<int,string>,disabled:array<int,string>} Normalized module configuration.
	 */
	private static function module_config(): array {
		if(self::$module_config!==null){
			return self::$module_config;
		}
		$config=[
			'enabled'=>null,
			'disabled'=>[],
		];
		foreach(self::module_config_sources() as $source){
			if(!is_array($source)){
				continue;
			}
			if(self::isList($source)){
				$config['enabled']=self::normalize_module_list($source);
				continue;
			}
			if(array_key_exists('enabled', $source)){
				$config['enabled']=is_array($source['enabled'])
					? self::normalize_module_list($source['enabled'])
					: null;
			}
			if(array_key_exists('disabled', $source) && is_array($source['disabled'])){
				$config['disabled']=array_values(array_unique(array_merge(
					$config['disabled'],
					self::normalize_module_list($source['disabled'])
				)));
			}
		}
		return self::$module_config=$config;
	}

	/**
	 * Reads module configuration payloads from common config, application config, and APP_MODULES.
	 *
	 * File sources are required directly so they can return PHP arrays. Missing files are ignored, and APP_MODULES is appended last to act as the highest-level runtime override.
	 *
	 * @return array<int,mixed> Raw configuration payloads in precedence order.
	 */
	private static function module_config_sources(): array {
		$sources=[];
		if(defined('ROOTPATH')){
			foreach([
				ROOTPATH['common_dataphyre'].'config/modules.php',
				ROOTPATH['dataphyre'].'config/modules.php',
			] as $file){
				if(is_file($file)){
					$sources[]=(require $file);
				}
			}
		}
		if(defined('APP_MODULES')){
			$sources[]=\constant('APP_MODULES');
		}
		return $sources;
	}

	/**
	 * Applies enabled and disabled configuration lists to a normalized module name.
	 *
	 * Disabled entries always win. When an enabled allow-list is present, modules not listed there are disabled even if they exist on disk.
	 *
	 * @param string $module Normalized module name.
	 * @return bool True when configuration permits the module.
	 */
	private static function is_enabled(string $module): bool {
		$config=self::module_config();
		if(in_array($module, $config['disabled'], true)){
			return false;
		}
		if(is_array($config['enabled']) && !in_array($module, $config['enabled'], true)){
			return false;
		}
		return true;
	}

	/**
	 * Checks whether an application dash-prefixed directory disables a shared module.
	 *
	 * A directory such as modules/-sql/ lets an application suppress a common runtime module without editing the shared module tree.
	 *
	 * @param string $module Normalized module name.
	 * @return bool True when the application explicitly disables the module.
	 */
	private static function is_app_disabled(string $module): bool {
		return defined('ROOTPATH')
			&& !empty(ROOTPATH['dataphyre'])
			&& is_dir(ROOTPATH['dataphyre'].'modules/-'.$module.'/');
	}

	/**
	 * Inspects one module directory and extracts entrypoint and namespace metadata.
	 *
	 * A directory is considered usable when it exposes a kernel main file, a Framework directory, or a framework bootstrap file. The returned paths are absolute runtime paths used by bootstrap and framework autoloading decisions.
	 *
	 * @param string $directory Candidate module directory.
	 * @param string $module Normalized module name.
	 * @return ?array<string,mixed> Directory definition payload, or null when no module surface exists.
	 */
	private static function inspect_module_directory(string $directory, string $module): ?array {
		if(!is_dir($directory)){
			return null;
		}
		$directory=rtrim($directory, '/\\').'/';
		$kernel_entry=self::first_existing([
			$directory.'kernel/'.$module.'.main.php',
		]);
		$framework_directory=is_dir($directory.'Framework/') ? $directory.'Framework/' : null;
		$framework_entry=$framework_directory!==null
			? self::first_existing([
				$framework_directory.'Bootstrap.php',
				$framework_directory.'bootstrap.php',
			])
			: self::first_existing([
				$directory.'framework.php',
			]);
		if($kernel_entry===null && $framework_directory===null && $framework_entry===null){
			return null;
		}
		return [
			'module'=>$module,
			'version'=>is_file($directory.'version') ? trim((string)file_get_contents($directory.'version')) : '1.0',
			'kernel_entry'=>$kernel_entry,
			'framework_entry'=>$framework_entry,
			'framework_directory'=>$framework_directory,
			'framework_namespace'=>$framework_directory!==null ? self::framework_namespace($module) : null,
			'directory'=>$directory,
			'common_directory'=>null,
			'app_directory'=>null,
		];
	}

	/**
	 * Returns the first existing file from a candidate list.
	 *
	 * Candidate order encodes bootstrap precedence, so the first readable filesystem hit is the selected entrypoint.
	 *
	 * @param array<int,string> $files Candidate absolute file paths.
	 * @return ?string First existing file path, or null when none exist.
	 */
	private static function first_existing(array $files): ?string {
		foreach($files as $file){
			if(is_file($file)){
				return $file;
			}
		}
		return null;
	}

	/**
	 * Normalizes a module list while preserving first-seen order.
	 *
	 * Blank names and duplicate normalized names are discarded so configuration lists remain deterministic and safe for membership checks.
	 *
	 * @param array<int,mixed> $modules Raw module names from config.
	 * @return array<int,string> Unique normalized module names.
	 */
	private static function normalize_module_list(array $modules): array {
		$normalized=[];
		$seen=[];
		foreach($modules as $module){
			$module=self::normalize_module_name((string)$module);
			if($module==='' || isset($seen[$module])){
				continue;
			}
			$seen[$module]=true;
			$normalized[]=$module;
		}
		return $normalized;
	}

	/**
	 * Normalizes a module name for filesystem and configuration comparisons.
	 *
	 * Module names are lowercase and trimmed; no namespace or path validation is performed here.
	 *
	 * @param string $module Raw module name.
	 * @return string Normalized module name.
	 */
	private static function normalize_module_name(string $module): string {
		return strtolower(trim($module));
	}

	/**
	 * Determines whether an array is a zero-based list.
	 *
	 * This compatibility helper identifies simple APP_MODULES-style enabled lists without relying on newer PHP runtime helpers.
	 *
	 * @param array<mixed> $value Candidate array.
	 * @return bool True when the array keys are exactly 0..n-1.
	 */
	private static function is_list(array $value): bool {
		return array_keys($value)===range(0, count($value)-1);
	}

	/**
	 * Resolves the framework namespace exposed by a module.
	 *
	 * Core maps to the root Dataphyre namespace, aliases handle historical module names, and other snake_case module names are converted to PascalCase namespace segments.
	 *
	 * @param string $module Module name to normalize.
	 * @return string Framework namespace for classes under the module's Framework directory.
	 */
	private static function framework_namespace(string $module): string {
		$module=self::normalize_module_name($module);
		if($module==='core'){
			return 'Dataphyre';
		}
		$segment=self::$framework_namespace_aliases[$module]
			?? str_replace(' ', '', ucwords(str_replace('_', ' ', $module)));
		return 'Dataphyre\\'.$segment;
	}
}
