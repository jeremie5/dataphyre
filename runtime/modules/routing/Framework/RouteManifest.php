<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Routing;

require_once __DIR__.'/CompilableRoute.php';
require_once __DIR__.'/Route.php';

/**
 * Builds and reads compiled route manifests for the Routing Framework.
 *
 * A manifest is the portable array form consumed by compiled dispatchers and diagnostics. It
 * carries a manifest version, caller-supplied metadata, and route definitions that have already
 * been normalized by `CompilableRoute` instances or supplied as compiled arrays.
 */
final class RouteManifest {

	/**
	 * Compiles route objects and arrays into a manifest payload.
	 *
	 * @param array<int, CompilableRoute|array<string, mixed>> $routes Route objects or already-compiled route arrays.
	 * @param array<string, mixed> $metadata Manifest-level metadata for diagnostics and deployment tooling.
	 * @return array{version:int, metadata:array<string, mixed>, routes:array<int, array<string, mixed>>, named_routes?:array<string, int>} Compiled route manifest.
	 *
	 * @throws \RuntimeException When any route entry is not compilable.
	 */
	public static function compile(array $routes, array $metadata=[]): array {
		$compiledRoutes=[];
		$namedRoutes=[];
		foreach($routes as $route){
			if($route instanceof CompilableRoute){
				$compiledRoutes[]=$route->compile();
			}elseif(is_array($route)){
				$compiledRoutes[]=$route;
			}else{
				throw new \RuntimeException('Route manifest entries must be Route instances or compiled arrays.');
			}
			$index=array_key_last($compiledRoutes);
			$name=$compiledRoutes[$index]['name'] ?? null;
			if(is_string($name) && $name!=='' && !isset($namedRoutes[$name])){
				$namedRoutes[$name]=$index;
			}
		}
		$manifest=[
			'version'=>1,
			'metadata'=>$metadata,
			'routes'=>$compiledRoutes,
		];
		if($namedRoutes!==[]){
			$manifest['named_routes']=$namedRoutes;
		}
		return $manifest;
	}

	/**
	 * Generates a URL for a named route in a compiled manifest.
	 *
	 * Route defaults are merged with supplied parameters before URL generation. Domain-aware
	 * routes preserve their compiled domain template when one is available.
	 *
	 * @param array<string, mixed> $manifest Compiled route manifest.
	 * @param string $name Route name to resolve.
	 * @param array<string, mixed> $parameters Route path/domain parameters.
	 * @param array<string, mixed> $query Query string parameters.
	 * @return string Generated URL.
	 *
	 * @throws \RuntimeException When the route is missing or cannot generate from compiled source path data.
	 */
	public static function namedUrl(array $manifest, string $name, array $parameters=[], array $query=[]): string {
		$name=trim($name);
		$namedRoutes=$manifest['named_routes'] ?? null;
		if(is_array($namedRoutes) && isset($namedRoutes[$name])){
			$index=$namedRoutes[$name];
			$route=is_int($index) ? ($manifest['routes'][$index] ?? null) : null;
			if(is_array($route) && ($route['name'] ?? null)===$name){
				return self::urlForRoute($route, $name, $parameters, $query);
			}
		}
		foreach($manifest['routes'] ?? [] as $route){
			if(($route['name'] ?? null)!==$name){
				continue;
			}
			return self::urlForRoute($route, $name, $parameters, $query);
		}
		throw new \RuntimeException("Route '$name' is not defined.");
	}

	/**
	 * Generates a URL from one compiled route entry.
	 *
	 * @param array<string, mixed> $route Compiled route definition.
	 * @param string $name Route name used for diagnostics.
	 * @param array<string, mixed> $parameters Route path/domain parameters.
	 * @param array<string, mixed> $query Query string parameters.
	 * @return string Generated URL.
	 */
	private static function urlForRoute(array $route, string $name, array $parameters=[], array $query=[]): string {
		$parameters=array_replace(is_array($route['defaults'] ?? null) ? $route['defaults'] : [], $parameters);
		if(
			$parameters===[] &&
			$query===[] &&
			!isset($route['domain']) &&
			isset($route['exact_path']) &&
			is_string($route['exact_path'])
		){
			return $route['exact_path'];
		}
		if(isset($route['path']) && is_string($route['path'])){
			return Route::url($route['path'], $parameters, $query, is_string($route['domain'] ?? null) ? $route['domain'] : null);
		}
		if(isset($route['exact_path']) && is_string($route['exact_path'])){
			return Route::url($route['exact_path'], $parameters, $query, is_string($route['domain'] ?? null) ? $route['domain'] : null);
		}
		throw new \RuntimeException("Named route '$name' cannot generate a URL because no source path was compiled.");
	}

	/**
	 * Reads metadata from a compiled route.
	 *
	 * @param array<string, mixed> $route Compiled route definition.
	 * @param ?string $key Optional metadata key. When null, the full metadata array is returned.
	 * @param mixed $default Value returned when the key is absent or metadata is malformed.
	 * @return mixed Metadata value, full metadata array, or default.
	 */
	public static function routeMetadata(array $route, ?string $key=null, mixed $default=null): mixed {
		$metadata=$route['metadata'] ?? [];
		if(!is_array($metadata)){
			return $key===null ? [] : $default;
		}
		if($key===null){
			return $metadata;
		}
		return array_key_exists($key, $metadata) ? $metadata[$key] : $default;
	}

	/**
	 * Returns a route definition with one metadata entry added or replaced.
	 *
	 * Empty metadata keys are ignored to keep compiled route arrays valid for downstream
	 * dispatchers and URL generators.
	 *
	 * @param array<string, mixed> $route Compiled route definition.
	 * @param string $key Metadata key to set.
	 * @param mixed $value Metadata value.
	 * @return array<string, mixed> Route definition with updated metadata.
	 */
	public static function withRouteMetadata(array $route, string $key, mixed $value): array {
		$key=trim($key);
		if($key===''){
			return $route;
		}
		if(!isset($route['metadata']) || !is_array($route['metadata'])){
			$route['metadata']=[];
		}
		$route['metadata'][$key]=$value;
		return $route;
	}
}
