<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
global $modcache;

if(!isset($modcache) || !is_array($modcache)){
	if(defined('ROOTPATH')){
		$modcache_file=ROOTPATH['dataphyre']."modcache.php";
		$modcache=(is_file($modcache_file) && filemtime($modcache_file)+300>time()) ? require($modcache_file) : [];
		if(!is_array($modcache)){
			$modcache=[];
		}
	}
	else
	{
		$modcache=[];
	}
}

/**
 * Persists the module discovery cache when its serialized contents changed.
 *
 * The cache lives under the application Dataphyre root and stores module
 * entrypoint/version lookups. Writing is skipped before ROOTPATH exists and
 * when the generated PHP return file already matches the current cache.
 *
 * @param array<string, array{0: string, 1: string}|false> $modcache Module presence cache keyed by module name.
 * @return void
 */
function dp_modcache_save_if_changed(array $modcache): void {
	if(!defined('ROOTPATH')){
		return;
	}
	$modcache_file=ROOTPATH['dataphyre']."modcache.php";
	$new_data='<?php return '.var_export($modcache, true).';';
	$existing=@file_get_contents($modcache_file);
	if($existing===$new_data){
		return;
	}
	file_put_contents($modcache_file, $new_data, LOCK_EX);
}

/**
 * Resolves whether a Dataphyre module is installed and where it boots from.
 *
 * Application modules take precedence over common runtime modules. A directory
 * prefixed with `-` in the application tree disables the common module of the
 * same name. Results are cached in the global modcache and written back to disk.
 *
 * @param string $module Module directory name.
 * @return array{0: string, 1: string}|false Entrypoint path and version, or false when absent/disabled.
 */
function dp_module_present(string $module): array|bool {
	global $modcache;
	if(!is_array($modcache)){
		$modcache=[];
	}
	if(isset($modcache[$module]) || array_key_exists($module, $modcache)){
		$cached=$modcache[$module];
		if($cached===false || (is_array($cached) && isset($cached[0]) && is_string($cached[0]) && is_file($cached[0]))){
			return $cached;
		}
		unset($modcache[$module]);
	}
	$p=ROOTPATH['dataphyre']."modules/$module/";
	$c=ROOTPATH['common_dataphyre_runtime']."modules/$module/";
	$result=false;
	$app_entry=$p."kernel/$module.main.php";
	$common_entry=$c."kernel/$module.main.php";
	if(is_file($app_entry)){
		$result=[$app_entry, is_file($p."version") ? trim((string)file_get_contents($p."version")) : '1.0'];
	}
	elseif(!is_dir(ROOTPATH['dataphyre']."modules/-$module/") && is_file($common_entry)){
		$result=[$common_entry, is_file($c."version") ? trim((string)file_get_contents($c."version")) : '1.0'];
	}
	$modcache[$module]=$result;
	dp_modcache_save_if_changed($modcache);
	return $result;
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
	$run_mode=defined('RUN_MODE') ? RUN_MODE : 'pre-init';
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
	if(!defined('ROOTPATH')){
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
		if(empty(ROOTPATH[$root_key])){
			continue;
		}
		$base=rtrim((string)ROOTPATH[$root_key], '/\\').'/config/';
		foreach($filenames as $filename){
			$files[]=$base.$filename;
		}
	}
	if($include_cache===true && !empty(ROOTPATH['dataphyre'])){
		$cache_base=rtrim((string)ROOTPATH['dataphyre'], '/\\').'/cache/config/';
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
	if(!defined('ROOTPATH')){
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
		$all_config=class_exists('\dataphyre\core', false)
			? \dataphyre\core::config_all()
			: (
				defined('CFG')
				? (is_object(CFG) && method_exists(CFG, 'raw') ? CFG->raw() : (is_array(CFG) ? CFG : []))
				: []
			);
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
	if(!defined('ROOTPATH') || empty(ROOTPATH['dataphyre'])){
		return null;
	}
	$module=trim($module);
	if($module===''){
		return null;
	}
	return rtrim((string)ROOTPATH['dataphyre'], '/\\').'/config/'.$module.'.php';
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
	if(!defined('ROOTPATH')){
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
		$all_config=class_exists('\dataphyre\core', false)
			? \dataphyre\core::config_all()
			: (
				defined('CFG')
				? (is_object(CFG) && method_exists(CFG, 'raw') ? CFG->raw() : (is_array(CFG) ? CFG : []))
				: []
			);
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
	if(!defined('DP_CORE_CFG') && defined('ROOTPATH')){
		dp_define_core_config();
	}
	if(false!=$keys=file_get_contents(ROOTPATH['dataphyre']."config/static/dpvk")){
		return explode(",", $keys);
	}
	$private_keys=DP_CORE_CFG['private_key'] ?? [];
	if(is_string($private_keys) && $private_keys!==''){
		return [$private_keys];
	}
	if(is_array($private_keys) && $private_keys!==[]){
		return $private_keys;
	}
	pre_init_error("Failed getting private keys");
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
