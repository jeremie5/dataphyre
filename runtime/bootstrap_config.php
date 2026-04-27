<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

final class bootstrap_config {

	public static function resolve(string $runtime_root): array {
		$runtime_root=rtrim($runtime_root, '/\\').'/';
		$install_root=rtrim(dirname($runtime_root), '/\\').'/';
		$project_root=rtrim(dirname(rtrim($install_root, '/\\')), '/\\').'/';
		$flight_sheet=self::load_flight_sheet($install_root);
		$bootstrap=array_key_exists('bootstrap', $flight_sheet) && is_array($flight_sheet['bootstrap']) ? $flight_sheet['bootstrap'] : [];
		$config=array_replace(self::defaults($runtime_root), $bootstrap);
		return [
			'project_root'=>$project_root,
			'bootstrap'=>$config,
			'application_roots'=>self::normalize_application_roots($project_root, (array)($config['application_roots'] ?? [])),
		];
	}

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
				],
			],
		], $legacy_defaults);
	}

	private static function load_flight_sheet(string $install_root): array {
		$flight_sheet_path=$install_root.'flight_sheet.php';
		$flight_sheet=is_file($flight_sheet_path) ? require($flight_sheet_path) : [];
		return is_array($flight_sheet) ? $flight_sheet : [];
	}

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

	private static function is_absolute_path(string $path): bool {
		return $path!=='' && (
			$path[0]==='/' ||
			$path[0]==='\\' ||
			preg_match('/^[A-Za-z]:[\/\\\\]/', $path)===1
		);
	}
}
