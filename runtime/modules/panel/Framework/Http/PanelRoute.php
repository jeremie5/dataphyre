<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Builds panel URLs and registers panel route surfaces.
 *
 * PanelRoute centralizes the panel mount prefix, canonical resource URL shape, asset/upload endpoints, and route metadata
 * used by both the standalone routing framework and the MVC RouteCollection integration. The helper also translates older
 * operation-oriented URLs into the current canonical segment order so generated links do not carry duplicated query
 * identity.
 *
 * Route helpers return framework route objects and do not dispatch controllers themselves. Controller classes, bootstrap
 * path, defaults, route names, and middleware are attached to the returned routes for the caller to register.
 */
final class PanelRoute {

	/**
	 * Creates a closure that builds URLs relative to one panel prefix.
	 *
	 * @param string $prefix Panel mount prefix.
	 * @return callable(string, array<string, mixed>): string URL builder accepting target and query arguments.
	 */
	public static function urlBuilder(string $prefix='/panel'): callable {
		$prefix=self::prefix($prefix);
		return static fn(string $target='', array $query=[]): string => self::url($prefix, $target, $query);
	}

	/**
	 * Builds a canonical panel URL from a target path and query parameters.
	 *
	 * Target segments are decoded, canonicalized, and re-encoded. Query parameters that repeat the represented route
	 * identity, such as resource, record, operation, action, or relation, are removed so links remain clean while still
	 * preserving unrelated state like filters or table page numbers.
	 *
	 * @param string $prefix Panel mount prefix.
	 * @param string $target Resource/action target relative to the prefix.
	 * @param array<string, mixed> $query Query parameters to append after empty values are removed.
	 * @return string URL path with optional RFC3986 query string.
	 */
	public static function url(string $prefix='/panel', string $target='', array $query=[]): string {
		$prefix=self::prefix($prefix);
		$target=trim($target, '/');
		$path=$prefix;
		$segments=[];
		if($target!==''){
			$segments=self::canonicalSegments(array_values(array_filter(explode('/', $target), static fn(string $segment): bool => $segment!=='')));
			if($segments!==[]){
				$path.='/'.implode('/', array_map(static fn(string $segment): string => rawurlencode(rawurldecode($segment)), $segments));
			}
		}
		if($segments!==[]){
			$query=self::dropRepresentedRouteQuery($query, $segments);
		}
		$query=self::filterQuery($query);
		return $query!==[] ? $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $path;
	}

	/**
	 * Builds a cache-busted URL for a panel asset.
	 *
	 * Asset names are reduced to their basename so callers cannot construct nested paths through this helper. The version is
	 * derived from PanelRenderer when available and falls back to "missing" for diagnostics.
	 *
	 * @param string $prefix Panel mount prefix.
	 * @param string $asset Asset filename.
	 * @return string Asset URL with version query parameter.
	 */
	public static function assetUrl(string $prefix='/panel', string $asset='panel.css'): string {
		$prefix=self::prefix($prefix);
		$asset=basename(str_replace('\\', '/', trim($asset)));
		$version=class_exists(PanelRenderer::class) ? PanelRenderer::assetVersion($asset) : 'missing';
		return $prefix.'/assets/'.rawurlencode($asset).'?v='.rawurlencode($version);
	}

	/**
	 * Builds the panel upload endpoint URL.
	 *
	 * @param string $prefix Panel mount prefix.
	 * @return string Upload URL path.
	 */
	public static function uploadUrl(string $prefix='/panel'): string {
		return self::prefix($prefix).'/upload';
	}

	/**
	 * Describes the route surface exposed by a mounted panel.
	 *
	 * The manifest is pure metadata for diagnostics; it does not register routes. It includes route names,
	 * canonical path templates, example URLs, controller classes, and legacy endpoints that older integrations may still
	 * reference.
	 *
	 * @param string $prefix Panel mount prefix.
	 * @param PanelInstance|string|null $surface Panel surface instance or name.
	 * @param array<string, mixed> $options Route options including surface and name.
	 * @return array<string, mixed> Route manifest payload.
	 */
	public static function manifest(string $prefix='/panel', PanelInstance|string|null $surface=null, array $options=[]): array {
		$prefix=self::prefix($prefix);
		$name=$surface instanceof PanelInstance ? $surface->name() : $surface;
		$name=$options['surface'] ?? $name;
		$name=is_string($name) && trim($name)!=='' ? trim($name) : 'default';
		$routeName=is_string($options['name'] ?? null) && trim($options['name'])!=='' ? trim($options['name']) : '';
		return [
			'type'=>'panel_route_manifest',
			'prefix'=>$prefix,
			'surface'=>$name,
			'route_names'=>[
				'page'=>$routeName,
				'catch_all'=>$routeName!=='' ? $routeName.'.catch_all' : '',
				'assets'=>$routeName!=='' ? $routeName.'.assets' : '',
				'upload'=>$routeName!=='' ? $routeName.'.upload' : '',
			],
			'routes'=>[
				'page'=>$prefix,
				'catch_all'=>$prefix.'/{...panel_segments}',
				'assets'=>$prefix.'/assets/{asset}',
				'upload'=>$prefix.'/upload',
			],
			'urls'=>[
				'home'=>self::url($prefix),
				'example_resource'=>self::url($prefix, 'orders'),
				'example_record'=>self::url($prefix, 'orders/show/42'),
				'asset'=>self::assetUrl($prefix, 'panel.css'),
				'upload'=>self::uploadUrl($prefix),
			],
			'controllers'=>[
				'page'=>PanelRouteController::class,
				'assets'=>PanelAssetController::class,
				'upload'=>PanelUploadController::class,
			],
			'legacy'=>[
				'assets'=>'/dataphyre/panel/assets/{asset}',
				'upload'=>'/dataphyre/panel/upload',
			],
		];
	}

	/**
	 * Converts supported legacy resource route segments into canonical panel segment order.
	 *
	 * Examples include resource/show/42 becoming resource/42 and resource/edit/42 becoming resource/42/edit. Unknown shapes
	 * are preserved unchanged so custom panel routes continue to work.
	 *
	 * @param array<int, string> $segments Raw target segments.
	 * @return array<int, string> Canonical target segments.
	 */
	private static function canonicalSegments(array $segments): array {
		$segments=array_values(array_map(static fn(mixed $segment): string => trim((string)$segment), $segments));
		if(count($segments)<2){
			return $segments;
		}
		$resource=$segments[0];
		$operation=Resource::normalizeName(rawurldecode($segments[1]));
		$record=isset($segments[2]) ? rawurldecode((string)$segments[2]) : null;
		$extra=array_slice($segments, 3);
		if($operation==='show' && $record!==null && $record!==''){
			return array_merge([$resource, $record], $extra);
		}
		if(in_array($operation, ['edit', 'update', 'delete', 'destroy', 'force_delete', 'restore', 'duplicate', 'inline_update'], true) && $record!==null && $record!==''){
			return array_merge([$resource, $record, $operation], $extra);
		}
		if($operation==='relation' && $record!==null && isset($segments[3])){
			return array_merge([$resource, $record, 'relation', $segments[3]], array_slice($segments, 4));
		}
		if($operation==='action' && $record!==null && isset($segments[3])){
			return array_merge([$resource, $segments[3], 'action', $record], array_slice($segments, 4));
		}
		if($operation==='transition' && $record!==null){
			return array_merge([$resource, $record, 'transition'], $extra);
		}
		return $segments;
	}

	/**
	 * Removes query parameters already represented by the canonical route path.
	 *
	 * @param array<string|int, mixed> $query Candidate query parameters.
	 * @param array<int, string> $segments Canonical route segments.
	 * @return array<string|int, mixed> Query parameters after duplicate route identity is removed.
	 */
	private static function dropRepresentedRouteQuery(array $query, array $segments): array {
		$identity=self::routeIdentity($segments);
		foreach($identity as $key=>$value){
			if(!array_key_exists($key, $query)){
				continue;
			}
			$queryValue=(string)$query[$key];
			$expected=(string)$value;
			$matches=in_array($key, ['resource', 'operation', 'action', 'relation'], true)
				? Resource::normalizeName($queryValue)===Resource::normalizeName($expected)
				: rawurldecode($queryValue)===rawurldecode($expected);
			if($matches){
				unset($query[$key]);
			}
		}
		return $query;
	}

	/**
	 * Derives semantic route identity from canonical panel path segments.
	 *
	 * @param array<int, string> $segments Canonical route segments.
	 * @return array<string, string> Identity keys such as resource, record, operation, action, and relation.
	 */
	private static function routeIdentity(array $segments): array {
		$segments=array_values(array_filter(array_map(static fn(mixed $segment): string => rawurldecode(trim((string)$segment, '/')), $segments), static fn(string $segment): bool => $segment!==''));
		$resource=(string)($segments[0] ?? '');
		if($resource===''){
			return [];
		}
		$second=(string)($segments[1] ?? '');
		$third=(string)($segments[2] ?? '');
		$fourth=(string)($segments[3] ?? '');
		$identity=['resource'=>$resource];
		if($second===''){
			$identity['operation']='index';
			return $identity;
		}
		$secondOperation=Resource::normalizeName($second);
		$thirdOperation=Resource::normalizeName($third);
		$operationNames=['index', 'create', 'store', 'show', 'edit', 'update', 'delete', 'destroy', 'force_delete', 'restore', 'duplicate', 'import', 'export', 'board', 'action', 'bulk_action', 'relation', 'transition', 'inline_update'];
		if($thirdOperation==='action'){
			return $identity+['record'=>$second, 'operation'=>'action', 'action'=>$fourth];
		}
		if($thirdOperation==='relation'){
			return $identity+['record'=>$second, 'operation'=>'relation', 'relation'=>$fourth];
		}
		if(in_array($thirdOperation, ['edit', 'update', 'delete', 'destroy', 'force_delete', 'restore', 'duplicate', 'transition', 'inline_update'], true)){
			return $identity+['record'=>$second, 'operation'=>$thirdOperation];
		}
		if(in_array($secondOperation, $operationNames, true)){
			$identity['operation']=$secondOperation;
			if($third!==''){
				$identity['record']=$third;
			}
			return $identity;
		}
		return $identity+['record'=>$second, 'operation'=>'show'];
	}

	/**
	 * Builds standalone routing-framework routes for panel pages.
	 *
	 * Two routes are returned: the exact panel home and a catch-all for resource/record/action segments. Defaults include
	 * panel_surface and panel_mount_prefix so controllers can reconstruct the mounted panel context.
	 *
	 * @param string $prefix Panel mount prefix.
	 * @param PanelInstance|string|null $surface Panel surface instance or name.
	 * @param array<string, mixed> $options Route options including bootstrap, name, middleware, defaults, and surface.
	 * @return array<int, object> Routing module route objects.
	 */
	public static function routing(string $prefix='/panel', PanelInstance|string|null $surface=null, array $options=[]): array {
		if(!class_exists('\Dataphyre\Routing\Route') || !class_exists('\Dataphyre\Routing\ControllerAction')){
			throw new \RuntimeException('Panel routing helpers require the Dataphyre routing framework module.');
		}
		$prefix=self::prefix($prefix);
		$defaults=self::defaults($surface, array_replace($options, ['prefix'=>$prefix]));
		$handler=\Dataphyre\Routing\ControllerAction::static(PanelRouteController::class, 'handle', [
			'bootstrap'=>$options['bootstrap'] ?? dirname(__DIR__).'/Bootstrap.php',
		]);
		$routes=[
			\Dataphyre\Routing\Route::any($prefix, $handler)->defaults($defaults),
			\Dataphyre\Routing\Route::any($prefix.'/{...panel_segments}', $handler)->defaults($defaults),
		];
		if(isset($options['name']) && is_string($options['name']) && trim($options['name'])!==''){
			$routes[0]->name(trim($options['name']));
			$routes[1]->name(trim($options['name']).'.catch_all');
		}
		foreach((array)($options['middleware'] ?? []) as $middleware){
			$routes[0]->middleware($middleware);
			$routes[1]->middleware($middleware);
		}
		return $routes;
	}

	/**
	 * Builds the standalone routing-framework route for panel assets.
	 *
	 * Asset names are constrained to simple filenames so the asset controller is not handed arbitrary path traversal input.
	 *
	 * @param string $prefix Panel mount prefix.
	 * @param array<string, mixed> $options Route options including bootstrap, name, and middleware.
	 * @return array<int, object> Routing module route objects.
	 */
	public static function assetRouting(string $prefix='/panel', array $options=[]): array {
		if(!class_exists('\Dataphyre\Routing\Route') || !class_exists('\Dataphyre\Routing\ControllerAction')){
			throw new \RuntimeException('Panel asset routing helpers require the Dataphyre routing framework module.');
		}
		$prefix=self::prefix($prefix);
		$handler=\Dataphyre\Routing\ControllerAction::static(PanelAssetController::class, 'handle', [
			'bootstrap'=>$options['bootstrap'] ?? dirname(__DIR__).'/Bootstrap.php',
		]);
		$route=\Dataphyre\Routing\Route::get($prefix.'/assets/{asset}', $handler)
			->where('asset', '[A-Za-z0-9_.-]+');
		if(isset($options['name']) && is_string($options['name']) && trim($options['name'])!==''){
			$route->name(trim($options['name']));
		}
		foreach((array)($options['middleware'] ?? []) as $middleware){
			$route->middleware($middleware);
		}
		return [$route];
	}

	/**
	 * Builds the standalone routing-framework route for panel uploads.
	 *
	 * @param string $prefix Panel mount prefix.
	 * @param array<string, mixed> $options Route options including bootstrap, name, and middleware.
	 * @return array<int, object> Routing module route objects.
	 */
	public static function uploadRouting(string $prefix='/panel', array $options=[]): array {
		if(!class_exists('\Dataphyre\Routing\Route') || !class_exists('\Dataphyre\Routing\ControllerAction')){
			throw new \RuntimeException('Panel upload routing helpers require the Dataphyre routing framework module.');
		}
		$prefix=self::prefix($prefix);
		$handler=\Dataphyre\Routing\ControllerAction::static(PanelUploadController::class, 'handle', [
			'bootstrap'=>$options['bootstrap'] ?? dirname(__DIR__).'/Bootstrap.php',
		]);
		$route=\Dataphyre\Routing\Route::post($prefix.'/upload', $handler);
		if(isset($options['name']) && is_string($options['name']) && trim($options['name'])!==''){
			$route->name(trim($options['name']));
		}
		foreach((array)($options['middleware'] ?? []) as $middleware){
			$route->middleware($middleware);
		}
		return [$route];
	}

	/**
	 * Builds the full standalone routing-framework route set for a panel mount.
	 *
	 * Endpoint-specific options can be supplied under assets_options and upload_options. Route names are expanded with
	 * endpoint suffixes so a base panel route name can produce page, catch_all, assets, and upload names.
	 *
	 * @param string $prefix Panel mount prefix.
	 * @param PanelInstance|string|null $surface Panel surface instance or name.
	 * @param array<string, mixed> $options Route options shared by all endpoints.
	 * @return array<int, object> Routing module route objects.
	 */
	public static function mountedRouting(string $prefix='/panel', PanelInstance|string|null $surface=null, array $options=[]): array {
		return array_merge(
			self::assetRouting($prefix, self::endpointOptions($options, 'assets')),
			self::uploadRouting($prefix, self::endpointOptions($options, 'upload')),
			self::routing($prefix, $surface, $options)
		);
	}

	/**
	 * Registers panel page routes on an MVC RouteCollection.
	 *
	 * @param \Dataphyre\Mvc\RouteCollection $routes Collection receiving the registered routes.
	 * @param string $prefix Panel mount prefix.
	 * @param PanelInstance|string|null $surface Panel surface instance or name.
	 * @param array<string, mixed> $options MVC route options including name, middleware, defaults, and surface.
	 * @return array<int, mixed> Registered MVC route objects.
	 */
	public static function mvc(\Dataphyre\Mvc\RouteCollection $routes, string $prefix='/panel', PanelInstance|string|null $surface=null, array $options=[]): array {
		$prefix=self::prefix($prefix);
		$defaults=self::defaults($surface, array_replace($options, ['prefix'=>$prefix]));
		$handler=PanelRouteController::class;
		$routeOptions=array_filter([
			'defaults'=>$defaults,
			'name'=>$options['name'] ?? null,
			'middleware'=>$options['middleware'] ?? null,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
		$exact=$routes->any($prefix, $handler, $routeOptions);
		$catchOptions=$routeOptions;
		if(isset($catchOptions['name']) && is_string($catchOptions['name']) && trim($catchOptions['name'])!==''){
			$catchOptions['name']=trim($catchOptions['name']).'.catch_all';
		}
		$catch=$routes->any($prefix.'/{...panel_segments}', $handler, $catchOptions);
		return [$exact, $catch];
	}

	/**
	 * Registers the panel asset route on an MVC RouteCollection.
	 *
	 * @param \Dataphyre\Mvc\RouteCollection $routes Collection receiving the registered route.
	 * @param string $prefix Panel mount prefix.
	 * @param array<string, mixed> $options MVC route options including name, middleware, defaults, and where/constraints.
	 * @return array<int, mixed> Registered MVC route objects.
	 */
	public static function mvcAssets(\Dataphyre\Mvc\RouteCollection $routes, string $prefix='/panel', array $options=[]): array {
		$prefix=self::prefix($prefix);
		$routeOptions=self::mvcEndpointOptions($options);
		$routeOptions['where']=array_replace((array)($routeOptions['where'] ?? []), ['asset'=>'[A-Za-z0-9_.-]+']);
		$route=$routes->get($prefix.'/assets/{asset}', PanelAssetController::class, $routeOptions);
		return [$route];
	}

	/**
	 * Registers the panel upload route on an MVC RouteCollection.
	 *
	 * @param \Dataphyre\Mvc\RouteCollection $routes Collection receiving the registered route.
	 * @param string $prefix Panel mount prefix.
	 * @param array<string, mixed> $options MVC route options including name, middleware, defaults, and where/constraints.
	 * @return array<int, mixed> Registered MVC route objects.
	 */
	public static function mvcUploads(\Dataphyre\Mvc\RouteCollection $routes, string $prefix='/panel', array $options=[]): array {
		$prefix=self::prefix($prefix);
		$route=$routes->post($prefix.'/upload', PanelUploadController::class, self::mvcEndpointOptions($options));
		return [$route];
	}

	/**
	 * Registers the full panel mount on an MVC RouteCollection.
	 *
	 * @param \Dataphyre\Mvc\RouteCollection $routes Collection receiving the registered routes.
	 * @param string $prefix Panel mount prefix.
	 * @param PanelInstance|string|null $surface Panel surface instance or name.
	 * @param array<string, mixed> $options MVC route options shared by all endpoints.
	 * @return array<int, mixed> Registered MVC route objects.
	 */
	public static function mvcMounted(\Dataphyre\Mvc\RouteCollection $routes, string $prefix='/panel', PanelInstance|string|null $surface=null, array $options=[]): array {
		return array_merge(
			self::mvcAssets($routes, $prefix, self::endpointOptions($options, 'assets')),
			self::mvcUploads($routes, $prefix, self::endpointOptions($options, 'upload')),
			self::mvc($routes, $prefix, $surface, $options)
		);
	}

	/**
	 * Normalizes a mount prefix to a leading-slash path with no trailing slash.
	 *
	 * @param string $prefix Raw mount prefix.
	 * @return string Normalized prefix, with "/" preserved for root mounts.
	 */
	private static function prefix(string $prefix): string {
		$prefix='/'.trim($prefix, '/');
		return $prefix==='/' ? '/' : rtrim($prefix, '/');
	}

	/**
	 * Removes empty query values recursively before URL generation.
	 *
	 * @param array<string|int, mixed> $query Raw query data.
	 * @return array<string|int, mixed> Query data without null, blank string, or empty nested arrays.
	 */
	private static function filterQuery(array $query): array {
		$filtered=[];
		foreach($query as $key=>$value){
			if(!is_string($key) && !is_int($key)){
				continue;
			}
			if(is_array($value)){
				$value=self::filterQuery($value);
				if($value!==[]){
					$filtered[$key]=$value;
				}
				continue;
			}
			if($value!==null && (string)$value!==''){
				$filtered[$key]=$value;
			}
		}
		return $filtered;
	}

	/**
	 * Builds controller defaults for a mounted panel.
	 *
	 * @param PanelInstance|string|null $surface Panel surface instance or name.
	 * @param array<string, mixed> $options Route options that may include surface, prefix, and defaults.
	 * @return array<string, mixed> Defaults merged with panel_surface and panel_mount_prefix.
	 */
	private static function defaults(PanelInstance|string|null $surface, array $options): array {
		$name=$surface instanceof PanelInstance ? $surface->name() : $surface;
		$name=$options['surface'] ?? $name;
		$name=is_string($name) && trim($name)!=='' ? trim($name) : 'default';
		$defaults=is_array($options['defaults'] ?? null) ? $options['defaults'] : [];
		if(!isset($defaults['panel_mount_prefix']) && isset($options['prefix']) && is_string($options['prefix'])){
			$defaults['panel_mount_prefix']=self::prefix($options['prefix']);
		}
		return array_replace(['panel_surface'=>$name], $defaults);
	}

	/**
	 * Extracts endpoint-specific options for asset or upload routes.
	 *
	 * @param array<string, mixed> $options Shared route options.
	 * @param string $endpoint Endpoint name, such as assets or upload.
	 * @return array<string, mixed> Options with endpoint route name and overrides applied.
	 */
	private static function endpointOptions(array $options, string $endpoint): array {
		$key=$endpoint.'_options';
		$endpointOptions=is_array($options[$key] ?? null) ? $options[$key] : [];
		$namePrefix=is_string($options['name'] ?? null) && trim($options['name'])!=='' ? trim($options['name']).'.' : '';
		return array_replace($options, ['name'=>$namePrefix.$endpoint], $endpointOptions);
	}

	/**
	 * Converts generic endpoint options into the MVC route option shape.
	 *
	 * @param array<string, mixed> $options Raw endpoint options.
	 * @return array<string, mixed> MVC route options with empty values removed.
	 */
	private static function mvcEndpointOptions(array $options): array {
		return array_filter([
			'name'=>$options['name'] ?? null,
			'middleware'=>$options['middleware'] ?? null,
			'defaults'=>is_array($options['defaults'] ?? null) ? $options['defaults'] : null,
			'where'=>is_array($options['where'] ?? $options['constraints'] ?? null) ? ($options['where'] ?? $options['constraints']) : null,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}
}
