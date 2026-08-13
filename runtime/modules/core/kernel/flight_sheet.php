<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/** Applies the declarative flight sheet that installs required runtime files. */
final class flight_sheet {

	/** @var array<string,array<string,mixed>> */
	private static array $cached_sheets=[];
	private static ?string $last_error=null;

	/**
	 * Installs shared and application-specific runtime artifacts.
	 *
	 * The optional runtime map supplies isolated roots, a direct sheet, clock, or
	 * entropy source. Normal callers omit it and retain constant-driven behavior.
	 *
	 * @param ?string $app_name Application key used for per-app overrides.
	 * @param array<string,mixed> $runtime Deterministic installer observations.
	 */
	public static function install(?string $app_name=null, array $runtime=[]): bool {
		self::$last_error=null;
		try{
			$app_root=self::app_root($runtime);
			if($app_root===''){
				return false;
			}
			$install_plan=self::load($runtime)['install'] ?? null;
			if(!is_array($install_plan) || $install_plan===[]){
				return false;
			}
			if(is_array($install_plan['shared'] ?? null)){
				self::apply_target($install_plan['shared'], self::install_root($runtime), $app_name, $runtime);
			}
			$app_target=is_array($install_plan['app'] ?? null) ? $install_plan['app'] : [];
			if($app_name!==null && is_array($install_plan['applications'][$app_name] ?? null)){
				$app_target=array_replace_recursive($app_target, $install_plan['applications'][$app_name]);
			}
			if($app_target!==[]){
				self::apply_target($app_target, $app_root, $app_name, $runtime);
			}
			return is_file(self::verified_path($runtime));
		}catch(\Throwable $exception){
			self::$last_error=$exception->getMessage();
			return false;
		}
	}

	/** Returns the last installer exception, if one was captured. */
	public static function last_error(): ?string {
		return self::$last_error;
	}

	/** Evicts one cached sheet path, or the complete process-local sheet cache. */
	public static function forget(?string $path=null): void {
		if($path===null){
			self::$cached_sheets=[];
			return;
		}
		unset(self::$cached_sheets[$path]);
	}

	/** @param array<string,mixed> $runtime */
	private static function app_root(array $runtime): string {
		if(array_key_exists('app_root', $runtime)){
			return rtrim((string)$runtime['app_root'], '/\\');
		}
		$rootpaths=array_key_exists('rootpaths', $runtime)
			? $runtime['rootpaths']
			: (defined('ROOTPATH') ? ROOTPATH : null);
		return is_array($rootpaths) && !empty($rootpaths['dataphyre'])
			? rtrim((string)$rootpaths['dataphyre'], '/\\')
			: '';
	}

	/** @param array<string,mixed> $runtime */
	private static function verified_path(array $runtime): string {
		return self::app_root($runtime).'/cache/verified';
	}

	/** @param array<string,mixed> $runtime */
	private static function install_root(array $runtime): string {
		if(array_key_exists('common_root', $runtime)){
			return rtrim((string)$runtime['common_root'], '/\\').'/';
		}
		$rootpaths=array_key_exists('rootpaths', $runtime)
			? $runtime['rootpaths']
			: (defined('ROOTPATH') ? ROOTPATH : null);
		if(is_array($rootpaths) && !empty($rootpaths['common_dataphyre'])){
			return rtrim((string)$rootpaths['common_dataphyre'], '/\\').'/';
		}
		return rtrim(dirname(__DIR__, 4), '/\\').'/';
	}

	/** @param array<string,mixed> $runtime */
	private static function path(array $runtime): string {
		if(array_key_exists('sheet_path', $runtime)){
			return (string)$runtime['sheet_path'];
		}
		if(array_key_exists('project_root', $runtime)){
			$project_root=rtrim((string)$runtime['project_root'], '/\\');
			return $project_root!=='' ? $project_root.'/flight_sheet.php' : self::install_root($runtime).'flight_sheet.php';
		}
		if(defined('DATAPHYRE_PROJECT_ROOT') && DATAPHYRE_PROJECT_ROOT!==''){
			return rtrim((string)DATAPHYRE_PROJECT_ROOT, '/\\').'/flight_sheet.php';
		}
		return self::install_root($runtime).'flight_sheet.php';
	}

	/** @param array<string,mixed> $runtime @return array<string,mixed> */
	private static function load(array $runtime): array {
		$path=self::path($runtime);
		if(array_key_exists('sheet', $runtime)){
			$sheet=is_array($runtime['sheet']) ? $runtime['sheet'] : [];
			$sheet['@path']=$path;
			return $sheet;
		}
		if(isset(self::$cached_sheets[$path])){
			return self::$cached_sheets[$path];
		}
		$sheet=is_file($path) ? require($path) : [];
		if(!is_array($sheet)){
			$sheet=[];
		}
		$sheet['@path']=$path;
		return self::$cached_sheets[$path]=$sheet;
	}

	/**
	 * @param array<string,mixed> $target
	 * @param array<string,mixed> $runtime
	 */
	private static function apply_target(array $target, string $base_root, ?string $app_name, array $runtime): void {
		$base_root=rtrim($base_root, '/\\').'/';
		foreach((array)($target['directories'] ?? []) as $directory){
			$directory=trim((string)$directory, '/\\');
			if($directory===''){
				continue;
			}
			self::create_directory($base_root.$directory);
		}
		foreach((array)($target['files'] ?? []) as $file){
			if(!is_array($file) || empty($file['path'])){
				continue;
			}
			$path=$base_root.ltrim((string)$file['path'], '/\\');
			$type=(string)($file['type'] ?? 'literal');
			if($type==='literal'){
				self::write_file_if_missing($path, (string)($file['contents'] ?? ''));
				continue;
			}
			if($type==='generated_dpvk'){
				self::generate_dpvk($path, $runtime);
				continue;
			}
			if($type==='generated_verified'){
				self::generate_verified_marker($path, $app_name, $runtime);
				continue;
			}
			if($type==='copy_if_missing' && !is_file($path) && !empty($file['source'])){
				$source=(string)$file['source'];
				if(is_file($source)){
					self::create_directory(dirname($path));
					if(@copy($source, $path)===false && !is_file($path)){
						throw new \RuntimeException("Failed copying file from {$source} to {$path}");
					}
				}
			}
		}
	}

	private static function create_directory(string $directory): void {
		if(is_dir($directory)){
			return;
		}
		if(@mkdir($directory, 0777, true)!==true && !is_dir($directory)){
			throw new \RuntimeException("Failed creating directory: {$directory}");
		}
	}

	private static function write_file_if_missing(string $path, string $contents): void {
		if(is_file($path)){
			return;
		}
		self::create_directory(dirname($path));
		if(@file_put_contents($path, $contents)===false && !is_file($path)){
			throw new \RuntimeException("Failed writing file: {$path}");
		}
	}

	/** @param array<string,mixed> $runtime */
	private static function generate_dpvk(string $path, array $runtime): void {
		if(is_file($path)){
			return;
		}
		$shared_key_path=self::install_root($runtime).'config/static/dpvk';
		if(is_file($shared_key_path)){
			self::create_directory(dirname($path));
			if(@copy($shared_key_path, $path)!==false && is_file($path)){
				return;
			}
		}
		$entropy=is_callable($runtime['random_bytes'] ?? null) ? $runtime['random_bytes'] : 'random_bytes';
		self::write_file_if_missing($path, bin2hex($entropy(64)));
	}

	/** @param array<string,mixed> $runtime */
	private static function generate_verified_marker(string $path, ?string $app_name, array $runtime): void {
		if(is_file($path)){
			return;
		}
		$verified_at=is_callable($runtime['clock'] ?? null)
			? gmdate('c', (int)$runtime['clock']())
			: gmdate('c');
		$payload=[
			'verified_at'=>$verified_at,
			'app'=>$app_name,
			'flight_sheet'=>self::path($runtime),
		];
		self::write_file_if_missing($path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	}
}
