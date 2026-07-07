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
	 * @return array{project_root:string, bootstrap:array<string,mixed>, application_roots:array<int,string>} Effective bootstrap payload.
	 */
	public static function resolve(string $runtime_root): array {
		$runtime_root=rtrim($runtime_root, '/\\').'/';
		$install_root=rtrim(dirname($runtime_root), '/\\').'/';
		$project_root=self::project_root($install_root);
		$flight_sheet=self::load_flight_sheet($install_root);
		$bootstrap=array_key_exists('bootstrap', $flight_sheet) && is_array($flight_sheet['bootstrap']) ? $flight_sheet['bootstrap'] : [];
		$config=array_replace(self::defaults($runtime_root), $bootstrap);
		return [
			'project_root'=>$project_root,
			'bootstrap'=>$config,
			'application_roots'=>self::normalize_application_roots($project_root, (array)($config['application_roots'] ?? [])),
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
	 * Embedded layouts keep Dataphyre under `common/dataphyre`; standalone package
	 * installs keep `runtime/`, `flight_sheet.php`, and app roots beside each
	 * other in the install root.
	 *
	 * @param string $install_root Dataphyre install root.
	 * @return string Project root with trailing slash.
	 */
	private static function project_root(string $install_root): string {
		$install_root=rtrim($install_root, '/\\');
		$parent=dirname($install_root);
		if(strtolower(basename($install_root))==='dataphyre' && strtolower(basename($parent))==='common'){
			return rtrim(dirname($parent), '/\\').'/';
		}
		return $install_root.'/';
	}

	/**
	 * Loads the install-level flight sheet when present.
	 *
	 * @param string $install_root Dataphyre install root.
	 * @return array<string,mixed> Flight sheet payload, or an empty array when absent or invalid.
	 */
	private static function load_flight_sheet(string $install_root): array {
		$flight_sheet_path=$install_root.'flight_sheet.php';
		$flight_sheet=is_file($flight_sheet_path) ? require($flight_sheet_path) : [];
		return is_array($flight_sheet) ? $flight_sheet : [];
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
