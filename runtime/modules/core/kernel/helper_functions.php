<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
global $modcache;
$modcache=is_array($modcache ?? null) ? $modcache : [];

/**
 * Returns the root-path map used by bootstrap helpers.
 *
 * Isolated bootstrap diagnostics may provide a process-local override without
 * redefining the immutable ROOTPATH constant used by the running application.
 * A false override represents the pre-ROOTPATH phase.
 *
 * @return ?array<string, mixed> Active root-path map, or null before bootstrap.
 */
function dp_helper_rootpath(): ?array {
	$override=$GLOBALS['DATAPHYRE_HELPER_ROOTPATH_OVERRIDE'] ?? null;
	if($override===false){
		return null;
	}
	if(is_array($override)){
		return $override;
	}
	return defined('ROOTPATH') && is_array(ROOTPATH) ? ROOTPATH : null;
}

/**
 * Returns the run mode used by dependency checks.
 *
 * A process-local override lets bootstrap diagnostics inspect pre-init and
 * diagnostic behavior while a host process is already running in another mode.
 */
function dp_helper_run_mode(): string {
	$override=$GLOBALS['DATAPHYRE_HELPER_RUN_MODE_OVERRIDE'] ?? null;
	return is_string($override) && $override!==''
		? $override
		: (defined('RUN_MODE') ? RUN_MODE : 'pre-init');
}

/**
 * Returns the mutable runtime configuration available during bootstrap.
 *
 * The loaded core facade is authoritative. Legacy CFG containers remain
 * supported for callers that load module helpers before the facade exists.
 *
 * @return array<string, mixed> Complete runtime configuration payload.
 */
function dp_helper_config_all(): array {
	if(class_exists('\dataphyre\core', false)){
		return \dataphyre\core::config_all();
	}
	if(!defined('CFG')){
		return [];
	}
	if(is_object(CFG) && method_exists(CFG, 'raw')){
		$config=CFG->raw();
		return is_array($config) ? $config : [];
	}
	return is_array(CFG) ? CFG : [];
}

/**
 * Persists a legacy module cache for callers that still invoke this helper.
 *
 * The cache lives under the application Dataphyre root and stores module
 * entrypoint/version lookups. Writing is skipped before ROOTPATH exists and
 * when the generated PHP return file already matches the current cache.
 *
 * @param array<string, array{0: string, 1: string}|false> $modcache Module presence cache keyed by module name.
 * @return void
 */
function dp_modcache_save_if_changed(array $modcache): void {
	$rootpath=dp_helper_rootpath();
	if($rootpath===null || empty($rootpath['dataphyre'])){
		return;
	}
	$modcache_file=(string)($rootpath['dataphyre'] ?? '')."modcache.php";
	$new_data='<?php return '.var_export($modcache, true).';';
	$existing=@file_get_contents($modcache_file);
	if($existing===$new_data){
		return;
	}
	file_put_contents($modcache_file, $new_data, LOCK_EX);
}

/**
 * Resolves the kernel entry for a flight-sheet-enabled Dataphyre module.
 *
 * The module registry performs the authoritative O(1) policy check before any
 * filesystem inspection. Its per-process definition cache replaces the former
 * disk-backed discovery cache, which could leak modules across policy changes.
 *
 * @param string $module Module directory name.
 * @return array{0: string, 1: string}|false Entrypoint path and version, or false when absent/disabled.
 */
function dp_module_present(string $module): array|bool {
	require_once(__DIR__.'/module_registry.php');
	return \dataphyre\module_registry::kernel_module_present($module);
}

/**
 * Enforces a module dependency and optional version range.
 *
 * Missing or out-of-range dependencies raise a pre-init error outside diagnostic
 * mode. Diagnostic mode can follow dependencies through dpanel so dependency
 * health is inspected without aborting the diagnostic run.
 *
 * @param string $module Module declaring the dependency.
 * @param string $required_module Required module name.
 * @param string $min_version Inclusive minimum accepted version.
 * @param string $max_version Inclusive maximum accepted version, or empty for no upper bound.
 * @return void
 *
 * @throws RuntimeException When the dependency is invalid before `pre_init_error()` exists.
 */
function dp_module_required(string $module, string $required_module, string $min_version='1.0', string $max_version=''): void {
	$presence=dp_module_present($required_module);
	$run_mode=dp_helper_run_mode();
	$version_invalid=is_array($presence) && (
		version_compare($presence[1], $min_version, '<') ||
		($max_version!=='' && version_compare($presence[1], $max_version, '>'))
	);
    if(!$presence || $version_invalid){
        if($run_mode !== 'diagnostic'){
			$version_range=$max_version==='' ? "v$min_version+" : "v$min_version - v$max_version";
			$message="Module '$module' requires '$required_module' ($version_range)";
			if(function_exists('pre_init_error')){
				pre_init_error($message);
			}
			else
			{
				throw new RuntimeException($message);
			}
        }
        return;
    }
    if($run_mode==='diagnostic'){
		if(
			class_exists('\dataphyre\dpanel')
			&& \dataphyre\dpanel::$follow_dependency_diagnostics===true
			&& !in_array($presence[0], get_included_files(), true)
		){
			\dataphyre\dpanel::diagnose_module($required_module);
		}
	}
}

/**
 * Builds the conventional configuration constant name for a module.
 *
 * Non-alphanumeric characters are collapsed to underscores and blank module
 * names resolve to the generic `DP_MODULE_CFG` fallback.
 *
 * @param string $module Module name.
 * @return string Uppercase configuration constant name.
 */
function dp_module_config_constant_name(string $module): string {
	$module=trim($module);
	if($module===''){
		return 'DP_MODULE_CFG';
	}
	$normalized=strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '_', $module));
	return 'DP_'.$normalized.'_CFG';
}

/**
 * Returns configuration files that may contribute to a module's settings.
 *
 * Common config is considered before application config, and compiled cache
 * overlays are appended when requested. The function is bootstrap-safe and
 * returns an empty list before ROOTPATH or a module name is available.
 *
 * @param string $module Module config basename.
 * @param bool $include_cache Whether to include the compiled application config overlay.
 * @return array<int, string> Candidate config file paths in merge order.
 */
function dp_config_candidate_files(string $module, bool $include_cache=true): array {
	$rootpath=dp_helper_rootpath();
	if($rootpath===null){
		return [];
	}
	$module=trim($module);
	if($module===''){
		return [];
	}
	$filenames=[
		$module.'.php',
	];
	$files=[];
	foreach(['common_dataphyre', 'dataphyre'] as $root_key){
		if(empty($rootpath[$root_key])){
			continue;
		}
		$base=rtrim((string)$rootpath[$root_key], '/\\').'/config/';
		foreach($filenames as $filename){
			$files[]=$base.$filename;
		}
	}
	if($include_cache===true && !empty($rootpath['dataphyre'])){
		$cache_base=rtrim((string)$rootpath['dataphyre'], '/\\').'/cache/config/';
		$files[]=$cache_base.$module.'.compiled.php';
	}
	return array_values(array_unique($files));
}

/**
 * Extracts the core Dataphyre section from a config payload.
 *
 * Config files may return either the core config directly or a root
 * `dataphyre` section. This helper normalizes both shapes before merging.
 *
 * @param array<string, mixed> $config Loaded config payload.
 * @return array<string, mixed> Core config values.
 */
function dp_core_config_extract(array $config): array {
	if(isset($config['dataphyre']) && is_array($config['dataphyre'])){
		return $config['dataphyre'];
	}
	return $config;
}

/**
 * Loads and defines the core configuration constant.
 *
 * Candidate config files are merged recursively. Non-array config files fall
 * back to the runtime config provider when available. Before ROOTPATH exists,
 * the constant is defined as an empty array to keep pre-init callers stable.
 *
 * @param ?string $constant Constant name to define; null uses `DP_CORE_CFG`.
 * @return array<string, mixed> Effective core configuration.
 */
function dp_define_core_config(?string $constant='DP_CORE_CFG'): array {
	$constant=$constant ?? 'DP_CORE_CFG';
	if(defined($constant)){
		$existing=constant($constant);
		return is_array($existing) ? $existing : [];
	}
	if(dp_helper_rootpath()===null){
		define($constant, []);
		return [];
	}
	$config=[];
	foreach(dp_config_candidate_files('core') as $file){
		if(!is_file($file)){
			continue;
		}
		$data=require $file;
		if(is_array($data)){
			$config=array_replace_recursive($config, dp_core_config_extract($data));
			continue;
		}
		$all_config=dp_helper_config_all();
		if(isset($all_config['dataphyre']) && is_array($all_config['dataphyre'])){
			$config=array_replace_recursive($config, $all_config['dataphyre']);
		}
	}
	define($constant, $config);
	return $config;
}

/**
 * Extracts one module's configuration from a loaded config payload.
 *
 * Config files may return either the module config directly or the nested
 * `dataphyre.<module>` section. This normalizes both shapes for merge callers.
 *
 * @param array<string, mixed> $config Loaded config payload.
 * @param string $module Module name.
 * @return array<string, mixed> Module config values.
 */
function dp_module_config_extract(array $config, string $module): array {
	if(isset($config['dataphyre'][$module]) && is_array($config['dataphyre'][$module])){
		return $config['dataphyre'][$module];
	}
	return $config;
}

/**
 * Resolves the application-owned config file for a module.
 *
 * The path is only returned when ROOTPATH exposes an application Dataphyre root
 * and the module name is non-blank.
 *
 * @param string $module Module config basename.
 * @return ?string Application config path, or null when unavailable.
 */
function dp_module_config_app_file(string $module): ?string {
	$rootpath=dp_helper_rootpath();
	if($rootpath===null || empty($rootpath['dataphyre'])){
		return null;
	}
	$module=trim($module);
	if($module===''){
		return null;
	}
	return rtrim((string)$rootpath['dataphyre'], '/\\').'/config/'.$module.'.php';
}

/**
 * Renders a PHP config file containing module default values.
 *
 * @param string $module Module name retained for template compatibility.
 * @param array<string, mixed> $defaults Default config values.
 * @return string PHP file contents that return the defaults array.
 */
function dp_module_config_template(string $module, array $defaults): string {
	return "<?php\n\nreturn ".var_export($defaults, true).";\n";
}

/**
 * Materializes an application config file from module defaults when absent.
 *
 * Empty defaults, missing app config roots, or an existing config file all skip
 * writes. When the core class is loaded, its forced writer is used; otherwise a
 * bootstrap-safe directory create and locked file write are attempted.
 *
 * @param string $module Module config basename.
 * @param array<string, mixed> $defaults Default config values to write.
 * @return bool True when a new defaults file was written.
 */
function dp_write_module_config_defaults(string $module, array $defaults): bool {
	if($defaults===[]){
		return false;
	}
	$file=dp_module_config_app_file($module);
	if($file===null || is_file($file)){
		return false;
	}
	$contents=dp_module_config_template($module, $defaults);
	if(class_exists('\dataphyre\core', false)){
		return \dataphyre\core::file_put_contents_forced($file, $contents)!==false;
	}
	$directory=dirname($file);
	if(!is_dir($directory) && @mkdir($directory, 0775, true)!==true && !is_dir($directory)){
		return false;
	}
	return @file_put_contents($file, $contents, LOCK_EX)!==false;
}

/**
 * Loads, merges, materializes, and defines a module configuration constant.
 *
 * Defaults are merged with common config, application config, and compiled
 * overlays in candidate order. When no config file or overlay exists, non-empty
 * defaults are written to the application config path for future editing.
 *
 * @param string $module Module config basename.
 * @param ?string $constant Constant to define; null uses the module convention.
 * @param array<string, mixed> $defaults Default config values.
 * @return array<string, mixed> Effective module configuration.
 */
function dp_define_module_config(string $module, ?string $constant=null, array $defaults=[]): array {
	$constant=$constant ?? dp_module_config_constant_name($module);
	if(defined($constant)){
		$existing=constant($constant);
		return is_array($existing) ? $existing : [];
	}
	if(dp_helper_rootpath()===null){
		define($constant, []);
		return [];
	}
	$config=$defaults;
	$has_config_file=false;
	$has_compiled_overlay=false;
	foreach(dp_config_candidate_files($module) as $file){
		if(!is_file($file)){
			continue;
		}
		if(str_ends_with($file, '.compiled.php')){
			$has_compiled_overlay=true;
		}
		else
		{
			$has_config_file=true;
		}
		$data=require $file;
		if(is_array($data)){
			$config=array_replace_recursive($config, dp_module_config_extract($data, $module));
			continue;
		}
		$all_config=dp_helper_config_all();
		if(isset($all_config['dataphyre'][$module]) && is_array($all_config['dataphyre'][$module])){
			$config=array_replace_recursive($config, $all_config['dataphyre'][$module]);
		}
	}
	if($has_config_file!==true && $has_compiled_overlay!==true && $defaults!==[]){
		dp_write_module_config_defaults($module, $defaults);
	}
	define($constant, $config);
	return $config;
}

/**
 * Loads Dataphyre private keys used for signing and token validation.
 *
 * Static key files take precedence over core configuration. Config may provide a
 * single string key or an array of keys to support rotation. Failure delegates to
 * `pre_init_error()` because these keys are required for secure runtime boot.
 *
 * @return array<int, string> Private keys in rotation order.
 */
function dpvks(): array {
	$rootpath=dp_helper_rootpath();
	if(!defined('DP_CORE_CFG') && $rootpath!==null){
		dp_define_core_config();
	}
	$key_file=$rootpath!==null && !empty($rootpath['dataphyre'])
		? rtrim((string)$rootpath['dataphyre'], '/\\').'/config/static/dpvk'
		: null;
	if($key_file!==null && false!==($keys=@file_get_contents($key_file))){
		return explode(",", $keys);
	}
	$core_config=defined('DP_CORE_CFG') && is_array(DP_CORE_CFG) ? DP_CORE_CFG : [];
	$private_keys=$core_config['private_key'] ?? [];
	if(is_string($private_keys) && $private_keys!==''){
		return [$private_keys];
	}
	if(is_array($private_keys) && $private_keys!==[]){
		return $private_keys;
	}
	pre_init_error("Failed getting private keys");
	throw new RuntimeException("Failed getting private keys");
}

/**
 * Returns the active Dataphyre private key.
 *
 * The last key from `dpvks()` is cached as the active signing key, allowing
 * earlier keys to remain available for verification during rotation.
 *
 * @return string Active private key.
 */
function dpvk(): string {
	static $private_key=null;
	if($private_key===null){
		$keys=dpvks();
		$private_key=(string)($keys[count($keys)-1] ?? '');
	}
	return $private_key;
}
