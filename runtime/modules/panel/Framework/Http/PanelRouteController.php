<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Dispatches mounted Panel HTTP requests into the selected surface host.
 *
 * The controller adapts framework route metadata and request attributes into
 * PanelHost execution. It preserves mount prefixes for generated Panel URLs,
 * records route trace spans, and returns the host response without wrapping it.
 */
final class PanelRouteController {

	/**
	 * Invokes the controller for frameworks that bind route actions to objects.
	 *
	 * Delegates to handle() with no explicit route array, so the request route
	 * metadata remains the source of surface and mount information.
	 *
	 * @param \Dataphyre\Http\Request $request Incoming HTTP request carrying route attributes.
	 * @return mixed Panel host response, usually a PanelPageResult or framework response object.
	 */
	public function __invoke(\Dataphyre\Http\Request $request): mixed {
		return self::handle($request);
	}

	/**
	 * Resolves the Panel surface and executes the matching PanelHost response.
	 *
	 * Route parameters may be supplied explicitly by the framework or recovered
	 * from Request::route(). The method derives a mount prefix from configured
	 * metadata or request path suffix matching, then runs the host inside a
	 * PanelContext containing URL builders whenever the route is mounted below a
	 * non-root prefix.
	 *
	 * @param \Dataphyre\Http\Request $request Incoming request used for path, method, user, and route metadata.
	 * @param array<string,mixed> $route Optional route payload with parameters, surface, mount prefix, or path segments.
	 * @return mixed response object or page result produced by the selected Panel surface host.
	 * @throws \Throwable Re-throws host failures after recording the failed trace span.
	 */
	public static function handle(\Dataphyre\Http\Request $request, array $route=[]): mixed {
		$parameters=is_array($route['parameters'] ?? null) ? $route['parameters'] : [];
		if($parameters===[]){
			$requestRoute=$request->route();
			$parameters=is_array($requestRoute) ? $requestRoute : [];
		}
		$surface=$parameters['panel_surface'] ?? $parameters['surface'] ?? 'default';
		$user=$request->attribute('user');
		$prefix=self::mountPrefix($request, $parameters);
		$host=PanelHost::surface(is_string($surface) ? $surface : 'default', $user);
		$options=['infer_segments'=>true];
		$span=PanelTrace::begin('route.dispatch', [
			'path'=>$request->path(),
			'method'=>$request->method(),
			'prefix'=>$prefix,
			'surface'=>is_string($surface) ? $surface : 'default',
			'mounted'=>$prefix!=='',
		]);
		try{
			if($prefix===''){
				$response=$host->response($request, $options);
			}
			else {
				$response=PanelContext::run([
					'panel_mount_prefix'=>$prefix,
					'url_builder'=>PanelRoute::urlBuilder($prefix),
					'asset_url_builder'=>static fn(string $asset): string => PanelRoute::assetUrl($prefix, $asset),
					'upload_url'=>PanelRoute::uploadUrl($prefix),
				], static fn(): mixed => $host->response($request, $options));
			}
			PanelTrace::end($span, [
				'status'=>self::responseStatus($response),
				'prefix'=>$prefix,
				'surface'=>is_string($surface) ? $surface : 'default',
			]);
			return $response;
		}
		catch(\Throwable $exception){
			PanelTrace::fail($span, $exception, [
				'prefix'=>$prefix,
				'surface'=>is_string($surface) ? $surface : 'default',
			]);
			throw $exception;
		}
	}

	/**
	 * Derives the URL prefix under which the Panel route was mounted.
	 *
	 * Explicit route configuration wins. Otherwise the request path is compared
	 * with route path segments and the matching suffix is removed, leaving the
	 * stable prefix that asset, upload, and action URL builders should reuse.
	 * When the configured prefix is an inner mount reused below an application
	 * base path, the current request path is used to return the effective mount
	 * without storing process-wide state.
	 *
	 * @param \Dataphyre\Http\Request $request Request providing the current normalized path.
	 * @param array<string,mixed> $parameters Route parameter map from the framework dispatcher.
	 * @return string Prefix beginning with "/" or an empty string when no mount can be inferred.
	 */
	private static function mountPrefix(\Dataphyre\Http\Request $request, array $parameters): string {
		$configured=$parameters['panel_mount_prefix'] ?? $parameters['mount_prefix'] ?? null;
		if(is_string($configured) && trim($configured)!==''){
			$prefix=self::prefix($configured);
			$path=self::prefix($request->path());
			$pathSegments=array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => $segment!==''));
			$prefixSegments=array_values(array_filter(explode('/', trim($prefix, '/')), static fn(string $segment): bool => $segment!==''));
			if($prefixSegments!==[] && count($pathSegments)>count($prefixSegments)){
				$offset=count($pathSegments)-count($prefixSegments);
				for($index=0; $index<$offset; $index++){
					if(array_slice($pathSegments, $index, count($prefixSegments))===$prefixSegments){
						return self::prefix(implode('/', array_slice($pathSegments, 0, $index+count($prefixSegments))));
					}
				}
			}
			return $prefix;
		}
		$path=self::prefix($request->path());
		$segments=self::routeSegments($parameters);
		if($segments===[]){
			return $path;
		}
		$pathSegments=array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => $segment!==''));
		$count=count($segments);
		if($count>0 && count($pathSegments)>=$count){
			$tail=array_slice($pathSegments, -$count);
			$decodedTail=array_map(static fn(string $segment): string => rawurldecode($segment), $tail);
			if($decodedTail===$segments){
				$prefixSegments=array_slice($pathSegments, 0, count($pathSegments)-$count);
				return self::prefix(implode('/', $prefixSegments));
			}
		}
		return '';
	}

	/**
	 * Normalizes route segment metadata into decoded path components.
	 *
	 * @param array<string,mixed> $parameters Route metadata that may contain panel_segments, segments, or path.
	 * @return array<int,string> Non-empty decoded segments in route order.
	 */
	private static function routeSegments(array $parameters): array {
		$value=$parameters['panel_segments'] ?? $parameters['segments'] ?? $parameters['path'] ?? null;
		if(is_array($value)){
			$segments=$value;
		}
		elseif(is_scalar($value)){
			$segments=explode('/', trim((string)$value, '/'));
		}
		else {
			$segments=[];
		}
		return array_values(array_filter(array_map(static fn(mixed $segment): string => rawurldecode(trim((string)$segment, '/')), $segments), static fn(string $segment): bool => $segment!==''));
	}

	/**
	 * Normalizes a route prefix to Dataphyre's slash-prefixed mount form.
	 *
	 * @param string $prefix Raw prefix from configuration or request path.
	 * @return string "/" for root, otherwise a slash-prefixed path without trailing slash.
	 */
	private static function prefix(string $prefix): string {
		$prefix='/'.trim($prefix, '/');
		return $prefix==='/' ? '/' : rtrim($prefix, '/');
	}

	/**
	 * Extracts an HTTP status code for route tracing.
	 *
	 * @param mixed $response PanelPageResult, framework response object, or scalar response.
	 * @return int Status code when exposed by the response; 200 for untyped payloads.
	 */
	private static function responseStatus(mixed $response): int {
		if(is_object($response) && isset($response->status) && is_numeric($response->status)){
			return (int)$response->status;
		}
		if($response instanceof PanelPageResult){
			return $response->status();
		}
		return 200;
	}
}
