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
