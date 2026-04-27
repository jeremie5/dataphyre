<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

final class autoloader {

	private static bool $registered=false;
	private static array $prefix_map=[];
	private static array $registered_module_roots=[];
	private static array $framework_namespace_aliases=[
		'sql'=>'Database',
	];

	public static function register(string $modules_root): void {
		$modules_root=rtrim($modules_root, '/\\');
		self::register_module_prefixes($modules_root);
		if(self::$registered===true){
			return;
		}
		spl_autoload_register(static function(string $class): void {
			foreach(self::$prefix_map as $prefix=>$directory){
				if(str_starts_with($class, $prefix)===false){
					continue;
				}
				$relative=substr($class, strlen($prefix));
				$relative=str_replace('\\', '/', $relative);
				$file=$directory.$relative.'.php';
				if(is_file($file)){
					require_once($file);
				}
				return;
			}
		});
		self::$registered=true;
	}

	public static function register_prefixes(array $prefix_map): void {
		foreach($prefix_map as $prefix=>$directory){
			$normalized_prefix=trim((string)$prefix, '\\').'\\';
			self::$prefix_map[$normalized_prefix]=rtrim((string)$directory, '/\\').'/';
		}
		uksort(self::$prefix_map, static function(string $left, string $right): int {
			return strlen($right)<=>strlen($left);
		});
	}

	private static function register_module_prefixes(string $modules_root): void {
		if(isset(self::$registered_module_roots[$modules_root])){
			return;
		}
		$prefixes=[];
		foreach(glob($modules_root.'/*', GLOB_ONLYDIR) ?: [] as $module_directory){
			$prefixes=array_merge($prefixes, self::kernel_prefixes($module_directory));
		}
		self::register_prefixes($prefixes);
		self::$registered_module_roots[$modules_root]=true;
	}

	public static function register_framework_modules(array|string $modules): array {
		$modules=is_array($modules) ? $modules : [$modules];
		$loaded=[];
		foreach($modules as $module){
			$module=strtolower(trim((string)$module));
			if($module===''){
				continue;
			}
			$prefixes=self::framework_prefixes_for_module($module);
			if($prefixes===[]){
				continue;
			}
			self::register_prefixes($prefixes);
			$loaded[]=$module;
		}
		return array_values(array_unique($loaded));
	}

	public static function framework_module_available(string $module): bool {
		return self::framework_prefixes_for_module($module)!==[];
	}

	private static function framework_prefixes_for_module(string $module): array {
		$prefixes=[];
		foreach(array_keys(self::$registered_module_roots) as $modules_root){
			$module_directory=rtrim($modules_root, '/\\').'/'.$module;
			if(!is_dir($module_directory)){
				continue;
			}
			$prefixes=array_replace($prefixes, self::framework_prefixes($module_directory));
		}
		return $prefixes;
	}

	private static function kernel_prefixes(string $module_directory): array {
		$module_directory=rtrim($module_directory, '/\\');
		$module=basename($module_directory);
		$kernel_directory=is_dir($module_directory.'/kernel') ? $module_directory.'/kernel' : $module_directory;
		if($module==='core' && is_file($kernel_directory.'/autoloader.php')){
			return ['dataphyre\\'=>$kernel_directory];
		}
		return ['dataphyre\\'.$module.'\\'=>$kernel_directory];
	}

	private static function framework_prefixes(string $module_directory): array {
		$module_directory=rtrim($module_directory, '/\\');
		$module=basename($module_directory);
		if($module==='core' && is_dir($module_directory.'/Framework')){
			return ['Dataphyre\\'=>$module_directory.'/Framework'];
		}
		if(!is_dir($module_directory.'/Framework')){
			return [];
		}
		return ['Dataphyre\\'.self::framework_namespace_segment($module).'\\'=>$module_directory.'/Framework'];
	}

	private static function framework_namespace_segment(string $module): string {
		$module=strtolower(trim($module));
		if($module===''){
			return '';
		}
		if(isset(self::$framework_namespace_aliases[$module])){
			return self::$framework_namespace_aliases[$module];
		}
		return str_replace(' ', '', ucwords(str_replace('_', ' ', $module)));
	}
}
