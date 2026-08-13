<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Resolves Dataphyre application directories from project conventions and configured roots.
 *
 * Application lookup searches the project's `applications` directory, the sibling
 * `applications` directory, explicit roots, and roots supplied through
 * `DATAPHYRE_APPLICATION_ROOTS`, returning normalized real paths when possible.
 */
final class app_locator {

	/**
	 * Finds an application directory by name.
	 *
	 * @param string $project_root Project root used for conventional app roots.
	 * @param string $application_name Directory name of the application to locate.
	 * @param array<int, string> $configured_roots Additional root directories to search.
	 * @return string|null Normalized application directory, or null when not found.
	 */
	public static function locate(string $project_root, string $application_name, array $configured_roots=[]): ?string {
		$direct=self::standalone_application_root($project_root, $application_name);
		if($direct!==null){
			return $direct;
		}
		foreach(self::roots($project_root, $configured_roots) as $applications_root){
			$candidate=rtrim($applications_root, '/\\').'/'.$application_name;
			if(is_dir($candidate)){
				$resolved=realpath($candidate);
				return $resolved!==false ? rtrim($resolved, '/\\') : rtrim($candidate, '/\\');
			}
		}
		return null;
	}

	/**
	 * Resolves an existing standalone application repository without inventing a
	 * second install layout contract. The existing dataphyre.app.json name is the
	 * authority that binds the project root to the requested application id.
	 */
	private static function standalone_application_root(string $project_root, string $application_name): ?string {
		$root=realpath($project_root);
		if($root===false || !is_dir($root) || is_link($root.'/app.php') || !is_file($root.'/app.php') || !is_readable($root.'/app.php')){
			return null;
		}
		$manifest=$root.'/dataphyre.app.json';
		if(is_link($manifest) || !is_file($manifest) || !is_readable($manifest)){
			return null;
		}
		$bytes=file_get_contents($manifest, false, null, 0, 65537);
		if(!is_string($bytes) || strlen($bytes)>65536){
			return null;
		}
		try{
			$decoded=json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
		}catch(\JsonException){
			return null;
		}
		if(
			!is_array($decoded) || array_is_list($decoded)
			|| !is_string($decoded['name'] ?? null)
			|| !hash_equals($application_name, $decoded['name'])
		){
			return null;
		}
		return rtrim($root, '/\\');
	}

	/**
	 * Returns the ordered, de-duplicated application root search path.
	 *
	 * @param string $project_root Project root used for conventional app roots.
	 * @param array<int, string> $configured_roots Additional root directories to search.
	 * @return array<int, string> Normalized app root directories in lookup order.
	 */
	public static function roots(string $project_root, array $configured_roots=[]): array {
		$roots=[];
		$project_root=rtrim($project_root, '/\\');
		$roots[]=$project_root.'/applications';
		$roots[]=dirname($project_root).'/applications';
		foreach(self::configured_roots($configured_roots) as $root){
			$roots[]=$root;
		}
		$normalized=[];
		$seen=[];
		foreach($roots as $root){
			$root=trim((string)$root);
			if($root===''){
				continue;
			}
			$resolved=realpath($root);
			$normalized_root=$resolved!==false ? rtrim($resolved, '/\\') : rtrim($root, '/\\');
			if(isset($seen[$normalized_root])){
				continue;
			}
			$seen[$normalized_root]=true;
			$normalized[]=$normalized_root;
		}
		return $normalized;
	}

	/**
	 * Combines explicit root configuration with environment-provided roots.
	 *
	 * @param array<int, string> $configured_roots Root directories supplied by the caller.
	 * @return array<int, string> Explicit roots followed by PATH_SEPARATOR-delimited environment roots.
	 */
	private static function configured_roots(array $configured_roots=[]): array {
		$roots=$configured_roots;
		$env=getenv('DATAPHYRE_APPLICATION_ROOTS');
		if(is_string($env) && trim($env)!==''){
			$roots=array_merge($roots, array_filter(array_map('trim', explode(PATH_SEPARATOR, $env))));
		}
		return $roots;
	}
}
