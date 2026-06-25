<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Applies the Dataphyre flight sheet that bootstraps required runtime files.
 *
 * The installer reads the shared flight_sheet.php plan, creates missing
 * directories and files, copies shared key material when available, and writes a
 * verified marker in the application cache. Operations are idempotent: existing
 * files are left untouched so application-local secrets and generated markers
 * are never overwritten by a later install pass.
 */
final class flight_sheet {

	private static ?array $cached_sheet=null;
	private static ?string $last_error=null;

	/**
	 * Installs shared and application-specific runtime artifacts.
	 *
	 * Shared targets are applied under the common Dataphyre root; app targets are
	 * applied under ROOTPATH['dataphyre']. When an app name has a dedicated plan,
	 * it is recursively merged over the generic app target before files are
	 * created. The method records only the last thrown message and returns false
	 * for missing roots, missing plans, or failed verification.
	 *
	 * @param ?string $app_name Optional application key used to select per-app install overrides.
	 * @return bool True once the verified marker exists in the application cache.
	 */
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

	/**
	 * Returns the last installation exception message captured by install().
	 *
	 * A null value means either no install attempt failed during this process or
	 * the last failure path returned false without throwing.
	 *
	 * @return ?string Last thrown installer message, or null when none is available.
	 */
	public static function last_error(): ?string {
		return self::$last_error;
	}

	/**
	 * Returns the application-local verification marker path.
	 *
	 * @return string Absolute path to ROOTPATH['dataphyre']/cache/verified.
	 */
	private static function verified_path(): string {
		return rtrim((string)ROOTPATH['dataphyre'], '/\\').'/cache/verified';
	}

	/**
	 * Locates the common Dataphyre root used for shared installer targets.
	 *
	 * @return string Common Dataphyre root with a trailing directory separator.
	 */
	private static function install_root(): string {
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre'])){
			return rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/';
		}
		return rtrim(dirname(__DIR__, 4), '/\\').'/';
	}

	/**
	 * Returns the flight sheet plan file loaded by this installer.
	 *
	 * @return string Absolute path to the shared flight_sheet.php plan.
	 */
	private static function path(): string {
		return self::install_root().'flight_sheet.php';
	}

	/**
	 * Loads and memoizes the flight sheet plan.
	 *
	 * Non-array plan files are treated as empty plans. The returned array always
	 * includes @path so generated markers can record the plan that produced them.
	 *
	 * @return array<string,mixed> Flight sheet plan and metadata.
	 */
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

	/**
	 * Applies one install target beneath the supplied root.
	 *
	 * Directory entries are created first. File entries support literal content,
	 * generated dpvk keys, generated verification markers, and copy-if-missing
	 * sources. Unknown or malformed file entries are skipped so older plan files
	 * remain readable by newer runtimes.
	 *
	 * @param array{directories?:list<string>,files?:list<array{path?:string,type?:'literal'|'generated_dpvk'|'generated_verified'|'copy_if_missing',contents?:string,source?:string}>} $target Target definition containing directories and files lists.
	 * @param string $base_root Absolute root receiving the target artifacts.
	 * @param ?string $app_name Optional application key stored in generated verification payloads.
	 * @return void
	 * @throws \RuntimeException When a required directory or file write cannot be completed.
	 */
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

	/**
	 * Creates a directory tree when it is not already present.
	 *
	 * @param string $directory Absolute directory path to create.
	 * @return void
	 * @throws \RuntimeException When mkdir fails and the path is still not a directory.
	 */
	private static function create_directory(string $directory): void {
		if(is_dir($directory)){
			return;
		}
		if(@mkdir($directory, 0777, true)!==true && !is_dir($directory)){
			throw new \RuntimeException("Failed creating directory: {$directory}");
		}
	}

	/**
	 * Writes a file only when no file already exists at the destination.
	 *
	 * @param string $path Absolute destination path.
	 * @param string $contents File contents to write for first install.
	 * @return void
	 * @throws \RuntimeException When the parent directory or file write fails.
	 */
	private static function write_file_if_missing(string $path, string $contents): void {
		if(is_file($path)){
			return;
		}
		self::create_directory(dirname($path));
		if(@file_put_contents($path, $contents)===false && !is_file($path)){
			throw new \RuntimeException("Failed writing file: {$path}");
		}
	}

	/**
	 * Creates an application dpvk key file without replacing existing keys.
	 *
	 * A shared static key is copied when present; otherwise a 64-byte random key
	 * is generated and hex encoded for filesystem-safe storage.
	 *
	 * @param string $path Absolute destination path for the key.
	 * @return void
	 * @throws \Exception When secure random bytes cannot be generated.
	 * @throws \RuntimeException When the key file cannot be written.
	 */
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

	/**
	 * Writes the application verification marker payload.
	 *
	 * @param string $path Absolute verified marker path.
	 * @param ?string $app_name Optional application key recorded in the marker.
	 * @return void
	 * @throws \RuntimeException When the marker cannot be written.
	 */
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
