<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

final class module_registry {

	private static ?array $module_config=null;
	private static array $metadata_cache=[];
	private static array $definition_cache=[];
	private static ?array $available_modules_cache=null;
	private static array $framework_namespace_aliases=[
		'sql'=>'Database',
	];

	public static function kernel_module_present(string $module): array|bool {
		$metadata=self::module_metadata($module);
		if($metadata===false || empty($metadata['kernel_entry'])){
			return false;
		}
		return [$metadata['kernel_entry'], $metadata['version'] ?? '1.0'];
	}

	public static function framework_module_present(string $module): string|bool {
		$metadata=self::module_metadata($module);
		if($metadata===false || empty($metadata['framework_entry']) || is_string($metadata['framework_entry'])===false){
			return false;
		}
		return $metadata['framework_entry'];
	}

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

	public static function disabled_modules(): array {
		$disabled=array_keys(self::module_definitions(false));
		sort($disabled);
		return $disabled;
	}

	public static function module_enabled(string $module): bool {
		$module=self::normalize_module_name($module);
		if($module===''){
			return false;
		}
		$definition=self::module_definition($module);
		return is_array($definition) && ($definition['enabled'] ?? false)===true;
	}

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
			if(self::is_list($source)){
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

	private static function is_app_disabled(string $module): bool {
		return defined('ROOTPATH')
			&& !empty(ROOTPATH['dataphyre'])
			&& is_dir(ROOTPATH['dataphyre'].'modules/-'.$module.'/');
	}

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

	private static function first_existing(array $files): ?string {
		foreach($files as $file){
			if(is_file($file)){
				return $file;
			}
		}
		return null;
	}

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

	private static function normalize_module_name(string $module): string {
		return strtolower(trim($module));
	}

	private static function is_list(array $value): bool {
		return array_keys($value)===range(0, count($value)-1);
	}

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
