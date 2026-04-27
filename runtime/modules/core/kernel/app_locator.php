<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

final class app_locator {

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

	private static function configured_roots(array $configured_roots=[]): array {
		$roots=$configured_roots;
		$env=getenv('DATAPHYRE_APPLICATION_ROOTS');
		if(is_string($env) && trim($env)!==''){
			$roots=array_merge($roots, array_filter(array_map('trim', explode(PATH_SEPARATOR, $env))));
		}
		return $roots;
	}
}
