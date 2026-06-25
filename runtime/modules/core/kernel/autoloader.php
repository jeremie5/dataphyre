<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Registers Dataphyre's kernel and Framework namespaces with PHP's SPL autoloader.
 *
 * The autoloader maps snake-case kernel modules under `dataphyre\module\*` and camel/Pascal
 * Framework APIs under `Dataphyre\Module\*`. Module roots are discovered once per runtime
 * root, explicit prefixes can be added by applications, and Framework lookups include a scoped
 * basename fallback for classes stored below nested Framework directories.
 */
final class autoloader {

	/**
	 * Whether the SPL autoload callback has been registered for this process.
	 */
	private static bool $registered=false;

	/**
	 * @var array<string, string> Namespace prefix to directory map ordered by longest prefix.
	 */
	private static array $prefix_map=[];

	/**
	 * @var array<string, array<string, string>> Cached Framework basename lookup maps keyed by directory.
	 */
	private static array $scoped_file_map=[];

	/**
	 * @var array<string, bool> Module roots already scanned for kernel prefixes.
	 */
	private static array $registered_module_roots=[];

	/**
	 * @var array<string, string> Module names whose public Framework namespace does not match the directory name.
	 */
	private static array $framework_namespace_aliases=[
		'sql'=>'Database',
	];

	/**
	 * Registers module kernel prefixes and installs the SPL autoload callback once.
	 *
	 * The callback first tries the direct PSR-style path for a matched prefix. If that misses
	 * within a Framework namespace, it falls back to a cached recursive basename map so nested
	 * Framework classes can still be resolved from their short class filename.
	 *
	 * @param string $modules_root Runtime modules directory to scan.
	 * @return void
	 */
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
					return;
				}
				$file=self::scoped_framework_file($directory, basename($relative).'.php');
				if($file!==null){
					require_once($file);
				}
				return;
			}
		});
		self::$registered=true;
	}

	/**
	 * Adds namespace prefixes to the autoload map.
	 *
	 * Prefixes are normalized to a trailing namespace separator and directories to a trailing
	 * slash, then sorted by longest prefix so specific module namespaces win over broad roots.
	 *
	 * @param array<string, string> $prefix_map Namespace prefix to directory map.
	 * @return void
	 */
	public static function register_prefixes(array $prefix_map): void {
		foreach($prefix_map as $prefix=>$directory){
			$normalized_prefix=trim((string)$prefix, '\\').'\\';
			self::$prefix_map[$normalized_prefix]=rtrim((string)$directory, '/\\').'/';
		}
		uksort(self::$prefix_map, static function(string $left, string $right): int {
			return strlen($right)<=>strlen($left);
		});
	}

	/**
	 * Discovers and registers kernel prefixes for every module below a runtime modules root.
	 *
	 * @param string $modules_root Runtime modules directory.
	 * @return void
	 */
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

	/**
	 * Registers Framework namespaces for one or more modules.
	 *
	 * Missing modules are ignored, allowing callers to request optional Framework modules without
	 * making module discovery fatal.
	 *
	 * @param array<int, string>|string $modules Module name or module list.
	 * @return array<int, string> Unique module names whose Framework prefixes were registered.
	 */
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

	/**
	 * Reports whether a Framework namespace can be registered for a module.
	 *
	 * @param string $module Module name.
	 * @return bool `true` when at least one registered module root contains a Framework directory for the module.
	 */
	public static function framework_module_available(string $module): bool {
		return self::framework_prefixes_for_module($module)!==[];
	}

	/**
	 * Builds Framework prefixes for a module across registered runtime roots.
	 *
	 * @param string $module Module name.
	 * @return array<string, string> Namespace prefix to Framework directory map.
	 */
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

	/**
	 * Builds the kernel namespace prefix for one module directory.
	 *
	 * @param string $module_directory Absolute module directory.
	 * @return array<string, string> Kernel namespace prefix to directory map.
	 */
	private static function kernel_prefixes(string $module_directory): array {
		$module_directory=rtrim($module_directory, '/\\');
		$module=basename($module_directory);
		$kernel_directory=is_dir($module_directory.'/kernel') ? $module_directory.'/kernel' : $module_directory;
		if($module==='core' && is_file($kernel_directory.'/autoloader.php')){
			return ['dataphyre\\'=>$kernel_directory];
		}
		return ['dataphyre\\'.$module.'\\'=>$kernel_directory];
	}

	/**
	 * Builds the public Framework namespace prefix for one module directory.
	 *
	 * @param string $module_directory Absolute module directory.
	 * @return array<string, string> Framework namespace prefix to directory map, or an empty array when no Framework directory exists.
	 */
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

	/**
	 * Converts a module directory name into its public Framework namespace segment.
	 *
	 * @param string $module Module directory name.
	 * @return string PascalCase namespace segment or configured alias.
	 */
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

	/**
	 * Resolves a Framework class basename within a nested Framework directory.
	 *
	 * @param string $directory Framework prefix directory being searched.
	 * @param string $filename Class basename plus `.php`.
	 * @return string|null Resolved file path, or null when the directory is not a Framework scope or no file matches.
	 */
	private static function scoped_framework_file(string $directory, string $filename): ?string {
		$directory=rtrim($directory, '/\\').'/';
		if(!str_contains(str_replace('\\', '/', $directory), '/Framework/')){
			return null;
		}
		if(!isset(self::$scoped_file_map[$directory])){
			self::$scoped_file_map[$directory]=self::build_scoped_file_map($directory);
		}
		return self::$scoped_file_map[$directory][$filename] ?? null;
	}

	/**
	 * Builds a basename-to-file map for a Framework directory tree.
	 *
	 * When duplicate basenames exist, the shallower file path wins so top-level Framework classes
	 * remain preferred over nested support classes with the same filename.
	 *
	 * @param string $directory Framework directory to scan recursively.
	 * @return array<string, string> Basename to file path map.
	 */
	private static function build_scoped_file_map(string $directory): array {
		$map=[];
		if(!is_dir($directory)){
			return $map;
		}
		$iterator=new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
		);
		foreach($iterator as $file){
			if(!$file instanceof \SplFileInfo || !$file->isFile() || strtolower($file->getExtension())!=='php'){
				continue;
			}
			$basename=$file->getBasename();
			$path=$file->getPathname();
			if(!isset($map[$basename]) || substr_count($path, DIRECTORY_SEPARATOR)<substr_count($map[$basename], DIRECTORY_SEPARATOR)){
				$map[$basename]=$path;
			}
		}
		return $map;
	}
}
