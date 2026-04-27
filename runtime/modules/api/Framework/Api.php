<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

use Dataphyre\Http\Request;
use Dataphyre\Http\Response;

final class Api {

	public static function manager(): ApiManager {
		return ApiManager::instance();
	}

	public static function methods(array|string $methods, string $path, mixed $handler=null): Endpoint {
		return Endpoint::methods($methods, $path, $handler);
	}

	public static function group(array $options=[]): ApiGroup {
		return self::configureGroup(ApiGroup::make(), $options);
	}

	public static function profile(string $name, array $options=[]): ApiGroup {
		return self::configureGroup(ApiGroup::make($name), $options);
	}

	public static function get(string $path, mixed $handler=null): Endpoint {
		return Endpoint::get($path, $handler);
	}

	public static function post(string $path, mixed $handler=null): Endpoint {
		return Endpoint::post($path, $handler);
	}

	public static function put(string $path, mixed $handler=null): Endpoint {
		return Endpoint::put($path, $handler);
	}

	public static function patch(string $path, mixed $handler=null): Endpoint {
		return Endpoint::patch($path, $handler);
	}

	public static function delete(string $path, mixed $handler=null): Endpoint {
		return Endpoint::delete($path, $handler);
	}

	public static function any(string $path, mixed $handler=null): Endpoint {
		return Endpoint::any($path, $handler);
	}

	public static function documentationRoutes(array $options=[]): array {
		return self::manager()->documentationRoutes($options);
	}

	public static function discoverApplication(?string $application_id=null): array {
		return self::manager()->discoverApplication($application_id);
	}

	public static function discoverManifest(array $manifest): array {
		return self::manager()->discoverManifest($manifest);
	}

	public static function openApiDocument(?string $application_id=null, array $options=[]): array {
		return self::manager()->openApiDocument($application_id, $options);
	}

	public static function dispatch(array $request, array $options=[]): array {
		return self::manager()->dispatch($request, $options);
	}

	public static function dispatchBatch(array $requests, array $options=[]): array {
		return self::manager()->dispatchBatch($requests, $options);
	}

	public static function dispatchChain(array $requests, array $options=[]): array {
		return self::manager()->dispatchChain($requests, $options);
	}

	public static function clearEndpointCache(string ...$names): int {
		return self::manager()->clearEndpointCache(...$names);
	}

	public static function authorizeCompiledRoute(array $route, Request $request): ?Response {
		return self::manager()->authorizeCompiledRoute($route, $request);
	}

	public static function executeCompiledRoute(array $route, Request $request): ?Response {
		return self::manager()->executeCompiledRoute($route, $request);
	}

	private static function configureGroup(ApiGroup $group, array $options): ApiGroup {
		if(isset($options['prefix']) && is_string($options['prefix'])){
			$group->prefix($options['prefix']);
		}
		if(isset($options['middleware'])){
			$middleware=$options['middleware'];
			if(is_array($middleware)){
				$group->middleware(...$middleware);
			}else{
				$group->middleware($middleware);
			}
		}
		if(isset($options['tags'])){
			$tags=$options['tags'];
			if(is_array($tags)){
				$group->tag(...$tags);
			}else{
				$group->tag($tags);
			}
		}
		if(isset($options['trace']) && is_array($options['trace'])){
			$enabled=($options['trace']['enabled'] ?? true)===true;
			$trace=$options['trace'];
			unset($trace['enabled']);
			$group->withTrace($enabled, $trace);
		}
		if(isset($options['dispatch']) && is_array($options['dispatch'])){
			$group->dispatchDefaults($options['dispatch']);
		}
		return $group;
	}
}
