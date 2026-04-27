<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

final class flight_sheet {

	private static ?array $cached_sheet=null;
	private static ?string $last_error=null;

	public static function install(?string $app_name=null): bool {
		self::$last_error=null;
		try{
			if(!defined('ROOTPATH') || empty(ROOTPATH['dataphyre'])){
				return false;
			}
			$install_plan=self::load()['install'] ?? null;
			if(!is_array($install_plan) || $install_plan===[]){
				return false;
			}
			if(is_array($install_plan['shared'] ?? null)){
				self::apply_target($install_plan['shared'], self::install_root(), $app_name);
			}
			$app_target=is_array($install_plan['app'] ?? null) ? $install_plan['app'] : [];
			if($app_name!==null && is_array($install_plan['applications'][$app_name] ?? null)){
				$app_target=array_replace_recursive($app_target, $install_plan['applications'][$app_name]);
			}
			if($app_target!==[]){
				self::apply_target($app_target, (string)ROOTPATH['dataphyre'], $app_name);
			}
			return is_file(self::verified_path());
		}catch(\Throwable $exception){
			self::$last_error=$exception->getMessage();
			return false;
		}
	}

	public static function last_error(): ?string {
		return self::$last_error;
	}

	private static function verified_path(): string {
		return rtrim((string)ROOTPATH['dataphyre'], '/\\').'/cache/verified';
	}

	private static function install_root(): string {
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre'])){
			return rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/';
		}
		return rtrim(dirname(__DIR__, 4), '/\\').'/';
	}

	private static function path(): string {
		return self::install_root().'flight_sheet.php';
	}

	private static function load(): array {
		if(self::$cached_sheet!==null){
			return self::$cached_sheet;
		}
		$sheet=is_file(self::path()) ? require(self::path()) : [];
		if(!is_array($sheet)){
			$sheet=[];
		}
		$sheet['@path']=self::path();
		self::$cached_sheet=$sheet;
		return self::$cached_sheet;
	}

	private static function apply_target(array $target, string $base_root, ?string $app_name=null): void {
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
				self::generate_dpvk($path);
				continue;
			}
			if($type==='generated_verified'){
				self::generate_verified_marker($path, $app_name);
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

	private static function generate_dpvk(string $path): void {
		if(is_file($path)){
			return;
		}
		$shared_key_path=self::install_root().'config/static/dpvk';
		if(is_file($shared_key_path)){
			self::create_directory(dirname($path));
			if(@copy($shared_key_path, $path)!==false && is_file($path)){
				return;
			}
		}
		self::write_file_if_missing($path, bin2hex(random_bytes(64)));
	}

	private static function generate_verified_marker(string $path, ?string $app_name=null): void {
		if(is_file($path)){
			return;
		}
		$payload=[
			'verified_at'=>gmdate('c'),
			'app'=>$app_name,
			'flight_sheet'=>self::path(),
		];
		self::write_file_if_missing($path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	}
}
