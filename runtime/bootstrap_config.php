<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Resolves bootstrap configuration from runtime defaults and the flight sheet.
 *
 * The resolver owns the earliest configuration merge before modules are loaded:
 * it derives install/project roots, folds legacy config defaults under flight
 * sheet overrides, and normalizes application roots for later app discovery.
 */
final class bootstrap_config {

	/**
	 * Builds the effective bootstrap configuration for a runtime root.
	 *
	 * @param string $runtime_root Dataphyre runtime root directory.
	 * @return array{project_root:string, bootstrap:array<string,mixed>, application_roots:array<int,string>, modules:array{enabled:array<string,bool>,disabled:array<string,bool>,core_implicit:bool}} Effective bootstrap payload.
	 */
	public static function resolve(string $runtime_root): array {
		$runtime_root=rtrim($runtime_root, '/\\').'/';
		$install_root=rtrim(dirname($runtime_root), '/\\').'/';
		$project_root_override=self::project_root_override();
		$project_root=self::project_root($install_root, $project_root_override);
		$flight_sheet=self::load_flight_sheet($install_root, $project_root);
		$bootstrap=array_key_exists('bootstrap', $flight_sheet) && is_array($flight_sheet['bootstrap']) ? $flight_sheet['bootstrap'] : [];
		$config=array_replace(self::defaults($runtime_root), $bootstrap);
		return [
			'project_root'=>$project_root,
			'bootstrap'=>$config,
			'application_roots'=>self::normalize_application_roots($project_root, (array)($config['application_roots'] ?? [])),
			'modules'=>self::normalize_modules($config['modules'] ?? []),
		];
	}

	/**
	 * Loads legacy defaults and overlays them on the built-in bootstrap defaults.
	 *
	 * @param string $runtime_root Runtime root with optional config.php.
	 * @return array<string,mixed> Bootstrap default configuration.
	 */
	private static function defaults(string $runtime_root): array {
		$legacy_defaults=is_file($runtime_root.'config.php') ? require($runtime_root.'config.php') : [];
		if(!is_array($legacy_defaults)){
			$legacy_defaults=[];
		}
		return array_replace([
			'app'=>'example_app',
			'prevent_keyless_direct_access'=>true,
			'allow_app_override'=>true,
			'is_production'=>true,
			'max_execution_time'=>30,
			'application_roots'=>[],
			'host_app_map'=>[],
			'public_ip_address'=>null,
			'web_server_port'=>null,
			'license'=>false,
			'modules'=>[
				'enabled'=>[],
				'disabled'=>[],
			],
			'flightdeck'=>[
				'enabled'=>true,
				'password'=>null,
				'password_hash'=>null,
				'session_ttl'=>43200,
				'rate_limit'=>[
					'window'=>300,
					'max_attempts'=>5,
				],
				'debugbar'=>[
					'enabled'=>true,
					'memory_limit'=>null,
					'capture_tracelog'=>true,
					'capture_tracelog_plotting'=>true,
				],
			],
		], $legacy_defaults);
	}

	/**
	 * Resolves the application project root for standalone and embedded installs.
	 *
	 * Canonical embedded layouts keep Dataphyre under `<project>/dataphyre` and
	 * keep the live flight sheet in the project root. Standalone package installs
	 * keep `runtime/`, `flight_sheet.php`, and app roots together. The former
	 * `<project>/common/dataphyre` layout remains a resolution-only fallback.
	 *
	 * @param string $install_root Dataphyre install root.
	 * @param string|null $project_root_override Explicit project root for vendor installs.
	 * @return string Project root with trailing slash.
	 */
	private static function project_root(string $install_root, ?string $project_root_override): string {
		if($project_root_override!==null){
			return $project_root_override;
		}
		$install_root=rtrim($install_root, '/\\');
		$parent=dirname($install_root);
		if(strtolower(basename($install_root))==='dataphyre' && strtolower(basename($parent))==='common'){
			return rtrim(dirname($parent), '/\\').'/';
		}
		if(
			strtolower(basename($install_root))==='dataphyre'
			&& (
				is_file($parent.'/flight_sheet.php')
				|| is_file($parent.'/dataphyre.project.json')
				|| is_dir($parent.'/applications')
			)
		){
			return rtrim($parent, '/\\').'/';
		}
		return $install_root.'/';
	}

	/**
	 * Loads the install-level or explicit project-root flight sheet when present.
	 *
	 * @param string $install_root Dataphyre install root.
	 * @param string $project_root Resolved project root.
	 * @return array<string,mixed> Flight sheet payload, or an empty array when absent or invalid.
	 */
	private static function load_flight_sheet(string $install_root, string $project_root): array {
		$project_sheet=rtrim($project_root, '/\\').'/flight_sheet.php';
		$install_sheet=rtrim($install_root, '/\\').'/flight_sheet.php';
		$flight_sheet_path=is_file($project_sheet) ? $project_sheet : $install_sheet;
		$flight_sheet=is_file($flight_sheet_path) ? require($flight_sheet_path) : [];
		return is_array($flight_sheet) ? $flight_sheet : [];
	}

	/**
	 * Normalizes the selected flight sheet's module policy into lookup sets.
	 *
	 * The enabled list is authoritative, disabled entries remove matching enabled
	 * entries, and names are normalized once during bootstrap. Core is a reserved
	 * bootstrap dependency and remains implicitly enabled outside application
	 * module selection.
	 *
	 * @param mixed $modules Raw `bootstrap.modules` payload.
	 * @return array{enabled:array<string,bool>,disabled:array<string,bool>,core_implicit:bool} Normalized module policy.
	 */
	private static function normalize_modules(mixed $modules): array {
		$modules=is_array($modules) ? $modules : [];
		$enabled=self::normalize_module_set(is_array($modules['enabled'] ?? null) ? $modules['enabled'] : []);
		$disabled=self::normalize_module_set(is_array($modules['disabled'] ?? null) ? $modules['disabled'] : []);
		foreach($disabled as $module=>$_){
			unset($enabled[$module]);
		}
		unset($disabled['core']);
		$enabled=['core'=>true]+$enabled;
		return [
			'enabled'=>$enabled,
			'disabled'=>$disabled,
			'core_implicit'=>true,
		];
	}

	/**
	 * Converts a raw module list into an associative membership set.
	 *
	 * @param array<int,mixed> $modules Raw module names.
	 * @return array<string,bool> Normalized module-name set.
	 */
	private static function normalize_module_set(array $modules): array {
		$set=[];
		foreach($modules as $module){
			$module=strtolower(trim((string)$module));
			if($module==='' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $module)!==1){
				continue;
			}
			$set[$module]=true;
		}
		return $set;
	}

	/**
	 * Reads an explicit project root for Composer vendor installs.
	 *
	 * Consumers can set DATAPHYRE_PROJECT_ROOT on $_SERVER before including the runtime
	 * bootstrap so local flight_sheet.php and applications stay outside vendor.
	 *
	 * @return string|null Absolute project root with trailing slash, or null.
	 */
	private static function project_root_override(): ?string {
		$value=isset($_SERVER['DATAPHYRE_PROJECT_ROOT']) ? trim((string)$_SERVER['DATAPHYRE_PROJECT_ROOT']) : '';
		if($value===''){
			return null;
		}
		if(!self::is_absolute_path($value)){
			throw new \RuntimeException('DATAPHYRE_PROJECT_ROOT must be an absolute path.');
		}
		return rtrim($value, '/\\').'/';
	}

	/**
	 * Normalizes configured application roots against the project root.
	 *
	 * Relative roots are anchored under the project root; absolute roots are
	 * preserved so deployments can point at shared or external app directories.
	 *
	 * @param string $project_root Project root used for relative entries.
	 * @param array<int, mixed> $roots Configured application root entries.
	 * @return array<int, string> Normalized application root paths.
	 */
	private static function normalize_application_roots(string $project_root, array $roots): array {
		$normalized=[];
		foreach($roots as $root){
			$root=trim((string)$root);
			if($root===''){
				continue;
			}
			if(!self::is_absolute_path($root)){
				$root=rtrim($project_root, '/\\').'/'.$root;
			}
			$normalized[]=$root;
		}
		return $normalized;
	}

	/**
	 * Reports whether a path is absolute on Unix or Windows.
	 *
	 * @param string $path Path to inspect.
	 * @return bool True when the path is absolute.
	 */
	private static function is_absolute_path(string $path): bool {
		return $path!=='' && (
			$path[0]==='/' ||
			$path[0]==='\\' ||
			preg_match('/^[A-Za-z]:[\/\\\\]/', $path)===1
		);
	}
}
