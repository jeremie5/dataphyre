<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Resolves flight-sheet-enabled Dataphyre modules and their entrypoints.
 *
 * The selected application's normalized flight-sheet policy is the sole source
 * of module enablement. Filesystem inspection happens only after a constant-time
 * policy lookup permits a module; directories never opt themselves into boot.
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
	 * Lists enabled modules that resolve to a usable on-disk definition.
	 *
	 * This explicit catalog operation inspects only names already allowed by the
	 * flight sheet. It never scans module directories for candidates.
	 *
	 * @return array<int,string> Resolvable module names in flight-sheet order.
	 */
	public static function available_modules(): array {
		if(self::$available_modules_cache!==null){
			return self::$available_modules_cache;
		}
		$available=[];
		foreach(self::enabled_modules() as $module){
			if(self::module_definition($module)!==false){
				$available[]=$module;
			}
		}
		return self::$available_modules_cache=$available;
	}

	/**
	 * Lists module names enabled by the selected application's flight sheet.
	 *
	 * This is a constant-time read after bootstrap normalization and does not
	 * inspect the filesystem. An enabled name may still fail to resolve if the
	 * package is missing, in which case presence/load methods return false.
	 *
	 * @return array<int,string> Enabled module names.
	 */
	public static function enabled_modules(): array {
		return array_keys(self::module_config()['enabled']);
	}

	/**
	 * Lists module names explicitly disabled by the selected flight sheet.
	 *
	 * The result is policy-derived and does not inspect disabled module paths.
	 *
	 * @return array<int,string> Disabled module names in flight-sheet order.
	 */
	public static function disabled_modules(): array {
		return array_keys(self::module_config()['disabled']);
	}

	/**
	 * Checks whether one module is allowed by the normalized flight-sheet policy.
	 *
	 * This hot path is an associative lookup and never touches the filesystem.
	 *
	 * @param string $module Module name to normalize and inspect.
	 * @return bool True when the module is known and enabled.
	 */
	public static function module_enabled(string $module): bool {
		$module=self::normalize_module_name($module);
		if($module===''){
			return false;
		}
		$config=self::module_config();
		return isset($config['enabled'][$module]) && !isset($config['disabled'][$module]);
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
	 * Shared runtime modules provide the base definition; application modules can
	 * replace kernel or framework entrypoints and record their own directory. A
	 * policy denial returns before any path is inspected.
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
		if(self::module_enabled($module)===false || defined('ROOTPATH')===false){
			return self::$definition_cache[$module]=false;
		}
		$common_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\');
		$app_root=rtrim((string)(ROOTPATH['dataphyre'] ?? ''), '/\\');
		$common=$common_root!=='' ? self::inspect_module_directory($common_root.'/modules/'.$module.'/', $module) : null;
		$app=$app_root!=='' ? self::inspect_module_directory($app_root.'/modules/'.$module.'/', $module) : null;
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
		// inspect_module_directory() returns a definition only when at least one
		// kernel or Framework surface exists. The common/app merge above preserves
		// every discovered surface, so a second no-surface guard here was dead code.
		$definition['enabled']=true;
		return self::$definition_cache[$module]=$definition;
	}

	/**
	 * Returns resolved module definitions, optionally filtered by enabled state.
	 *
	 * Definitions are keyed by normalized module name and are resolved only for
	 * enabled names. A disabled filter therefore returns an empty catalog; use
	 * disabled_modules() to inspect the explicit deny set without touching disk.
	 *
	 * @param ?bool $enabled Null for all valid definitions, true for enabled only, false for disabled only.
	 * @return array<string,array<string,mixed>> Definitions keyed by module name.
	 */
	public static function module_definitions(?bool $enabled=null): array {
		if($enabled===false){
			return [];
		}
		$definitions=[];
		foreach(self::enabled_modules() as $module){
			$definition=self::module_definition($module);
			if(!is_array($definition)){
				continue;
			}
			$definitions[$module]=$definition;
		}
		return $definitions;
	}

	/**
	 * Returns the selected application's normalized flight-sheet module policy.
	 *
	 * `DATAPHYRE_MODULE_POLICY` is produced once by bootstrap_config. The raw
	 * bootstrap constant is accepted only as a bootstrap-safe fallback for tools
	 * that load this class directly. Legacy config/modules.php and APP_MODULES
	 * are intentionally not consulted.
	 *
	 * @return array{enabled:array<string,bool>,disabled:array<string,bool>} Module lookup sets.
	 */
	private static function module_config(): array {
		if(self::$module_config!==null){
			return self::$module_config;
		}
		$policy=[];
		if(defined('DATAPHYRE_MODULE_POLICY') && is_array(DATAPHYRE_MODULE_POLICY)){
			$policy=DATAPHYRE_MODULE_POLICY;
		}
		elseif(defined('DATAPHYRE_BOOTSTRAP_CONFIG') && is_array(DATAPHYRE_BOOTSTRAP_CONFIG)){
			$policy=is_array(DATAPHYRE_BOOTSTRAP_CONFIG['modules'] ?? null)
				? DATAPHYRE_BOOTSTRAP_CONFIG['modules']
				: [];
		}
		$enabled=self::normalize_module_set(is_array($policy['enabled'] ?? null) ? $policy['enabled'] : []);
		$disabled=self::normalize_module_set(is_array($policy['disabled'] ?? null) ? $policy['disabled'] : []);
		foreach($disabled as $module=>$_){
			unset($enabled[$module]);
		}
		unset($disabled['core']);
		$enabled=['core'=>true]+$enabled;
		return self::$module_config=[
			'enabled'=>$enabled,
			'disabled'=>$disabled,
		];
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
	 * Normalizes a module list or lookup map into a membership set.
	 *
	 * Blank names and duplicate normalized names are discarded so configuration lists remain deterministic and safe for membership checks.
	 *
	 * @param array<mixed> $modules Raw module names or normalized lookup map.
	 * @return array<string,bool> Unique normalized module-name set.
	 */
	private static function normalize_module_set(array $modules): array {
		$set=[];
		foreach($modules as $key=>$value){
			$module=is_string($key) ? $key : (string)$value;
			if(is_string($key) && $value!==true && $value!==1){
				continue;
			}
			$module=self::normalize_module_name($module);
			if($module!==''){
				$set[$module]=true;
			}
		}
		return $set;
	}

	/**
	 * Normalizes a module name for filesystem and configuration comparisons.
	 *
	 * Names are lowercase, trimmed, and restricted to safe directory segments.
	 *
	 * @param string $module Raw module name.
	 * @return string Normalized module name.
	 */
	private static function normalize_module_name(string $module): string {
		$module=strtolower(trim($module));
		return preg_match('/^[a-z0-9][a-z0-9_-]*$/', $module)===1 ? $module : '';
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
