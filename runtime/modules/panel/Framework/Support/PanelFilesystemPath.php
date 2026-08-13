<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Normalizes and compares filesystem paths by the syntax of the path itself.
 *
 * Package manifests may carry Windows paths while tests, build agents, or
 * deployment tooling run on Linux. Host-only DIRECTORY_SEPARATOR checks make
 * those paths case-sensitive on one machine and case-insensitive on another.
 * This utility preserves native output separators while deriving comparison
 * semantics from drive and UNC prefixes.
 */
final class PanelFilesystemPath {

	public static function normalize(string $path): string {
		$unix=str_replace('\\', '/', trim($path));
		if($unix===''){
			return '';
		}
		$prefix='';
		$absolute=false;
		if(preg_match('/^[A-Za-z]:\//', $unix)===1){
			$prefix=strtoupper(substr($unix, 0, 2));
			$unix=substr($unix, 2);
			$absolute=true;
		}
		elseif(str_starts_with($unix, '//')){
			$prefix='//';
			$unix=substr($unix, 2);
			$absolute=true;
		}
		elseif(str_starts_with($unix, '/')){
			$prefix='/';
			$absolute=true;
		}
		$segments=[];
		foreach(explode('/', $unix) as $segment){
			if($segment==='' || $segment==='.'){
				continue;
			}
			if($segment==='..'){
				array_pop($segments);
				continue;
			}
			$segments[]=$segment;
		}
		$body=implode('/', $segments);
		$normalized=match($prefix){
			'//'=>'//'.$body,
			'/'=>'/'.$body,
			''=>$absolute ? '/'.$body : $body,
			default=>$prefix.'/'.$body,
		};
		if($normalized!=='/' && $normalized!=='//'){
			$normalized=rtrim($normalized, '/');
		}
		return DIRECTORY_SEPARATOR==='\\' ? str_replace('/', '\\', $normalized) : $normalized;
	}

	public static function prefixMatches(string $path, string $root): bool {
		$windows=self::usesWindowsSemantics($path) || self::usesWindowsSemantics($root);
		$path=rtrim(self::normalize($path), '/\\');
		$root=rtrim(self::normalize($root), '/\\');
		if($path==='' || $root===''){
			return false;
		}
		if($windows){
			$path=strtolower($path);
			$root=strtolower($root);
		}
		return $path===$root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
	}

	public static function usesWindowsSemantics(string $path): bool {
		$path=trim($path);
		return preg_match('/^[A-Za-z]:[\\\\\/]/', $path)===1
			|| str_starts_with($path, '\\\\')
			|| str_starts_with($path, '//');
	}
}
