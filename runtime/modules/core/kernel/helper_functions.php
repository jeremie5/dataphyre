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

function dp_module_config_constant_name(string $module): string {
	$module=trim($module);
	if($module===''){
		return 'DP_MODULE_CFG';
	}
	$normalized=strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '_', $module));
	return 'DP_'.$normalized.'_CFG';
}

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

function dp_core_config_extract(array $config): array {
	if(isset($config['dataphyre']) && is_array($config['dataphyre'])){
		return $config['dataphyre'];
	}
	return $config;
}

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

function dp_module_config_extract(array $config, string $module): array {
	if(isset($config['dataphyre'][$module]) && is_array($config['dataphyre'][$module])){
		return $config['dataphyre'][$module];
	}
	return $config;
}

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

function dp_module_config_template(string $module, array $defaults): string {
	return "<?php\n\nreturn ".var_export($defaults, true).";\n";
}

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

function dpvk(): string {
	static $private_key=null;
	if($private_key===null){
		$keys=dpvks();
		$private_key=(string)($keys[count($keys)-1] ?? '');
	}
	return $private_key;
}
