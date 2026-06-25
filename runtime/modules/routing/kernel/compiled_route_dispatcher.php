<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\routing;

use Dataphyre\Http\Request;
use Dataphyre\Http\ResponseEmitter;

/**
 * Dispatches precompiled Dataphyre route manifests without rebuilding route definitions.
 *
 * The dispatcher consumes normalized route arrays produced by the routing compiler, matches the
 * current request or explicit test inputs, publishes route parameters to the routing binding
 * store, resolves middleware aliases, loads required framework modules, executes handlers, and
 * emits HTTP responses when controller/API paths return response objects.
 */
final class compiled_route_dispatcher {

	/**
	 * InternalModule object used by middleware pipelines when a handler already emitted output.
	 */
	private static ?object $no_emit_sentinel=null;

	/**
	 * Loads a compiled manifest file and dispatches the first matching route.
	 *
	 * @param string $manifest_file PHP file that returns the compiled route manifest array.
	 * @return bool `true` when a route matched and handler dispatch started; `false` when no route matched.
	 *
	 * @throws \RuntimeException When the manifest file does not return an array.
	 */
	public static function dispatch_file(string $manifest_file): bool {
		$manifest=require($manifest_file);
		if(!is_array($manifest)){
			throw new \RuntimeException("Compiled route manifest must return an array: {$manifest_file}");
		}
		return self::dispatch_manifest($manifest);
	}

	/**
	 * Dispatches the first route in a manifest that matches the current request.
	 *
	 * @param array<string, mixed> $manifest Compiled manifest containing a `routes` list.
	 * @return bool `true` when a route matched and handler dispatch started; `false` when no route matched.
	 */
	public static function dispatch_manifest(array $manifest): bool {
		$route=self::match_route($manifest['routes'] ?? []);
		if($route===null){
			return false;
		}
		self::publish_parameters($route['parameters'] ?? []);
		self::run_handler($route['handler'] ?? null, $route);
		return true;
	}

	/**
	 * Matches an explicit method, path, and optional host against compiled route definitions.
	 *
	 * This public wrapper is used by diagnostics and tests that need deterministic matching
	 * without depending on `$_SERVER`.
	 *
	 * @param array<int, array<string, mixed>> $routes Compiled route definitions.
	 * @param string $method HTTP method to match.
	 * @param string $path Request path to normalize and match.
	 * @param ?string $host Optional host used for domain routes.
	 * @return array<string, mixed>|null Matched route with merged parameters, or null when no route matches.
	 */
	public static function match_routes_for_request(array $routes, string $method, string $path, ?string $host=null): ?array {
		return self::match_route($routes, $method, $path, $host);
	}

	/**
	 * Resolves one compiled middleware definition for diagnostics and tests.
	 *
	 * @param array<string, mixed> $definition Middleware definition containing an alias, class, callable target, parameters, modules, or bootstrap data.
	 * @param array<string, mixed> $aliases Custom aliases merged over the built-in alias map.
	 * @return array<string, mixed> Normalized middleware definition ready for instantiation or invocation.
	 */
	public static function resolve_middleware_for_route(array $definition, array $aliases=[]): array {
		return self::resolve_middleware_definition($definition, $aliases);
	}

	/**
	 * Finds the first route matching request method, path, and host constraints.
	 *
	 * Defaults and domain/path captures are merged into the returned route's `parameters` key.
	 * Splat parameters are expanded into decoded segment arrays after regex matching.
	 *
	 * @param array<int, array<string, mixed>> $routes Compiled route definitions.
	 * @param ?string $method Explicit HTTP method, or null to use `$_SERVER`.
	 * @param ?string $path Explicit request path, or null to use request globals.
	 * @param ?string $host Explicit host, or null to use request globals.
	 * @return array<string, mixed>|null Matched route with parameters, or null when none match.
	 */
	private static function match_route(array $routes, ?string $method=null, ?string $path=null, ?string $host=null): ?array {
		$request_method=strtoupper($method ?? (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		$request_path=$path!==null ? self::normalize_path($path) : self::normalized_request_path();
		$request_host=self::normalize_host($host ?? (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
		foreach($routes as $route){
			$has_exact_path=isset($route['exact_path']);
			if($has_exact_path && $route['exact_path']!==$request_path){
				continue;
			}
			$methods=$route['methods'] ?? ['GET'];
			$method_matched=false;
			foreach((array)$methods as $route_method){
				$route_method=(string)$route_method;
				if($route_method===$request_method || $route_method==='ANY'){
					$method_matched=true;
					break;
				}
				$route_method=strtoupper($route_method);
				if($route_method===$request_method || $route_method==='ANY'){
					$method_matched=true;
					break;
				}
			}
			if($method_matched===false){
				continue;
			}
			if($has_exact_path){
				$domain_parameters=[];
				if(self::route_matches_domain($route, $request_host, $domain_parameters)===false){
					continue;
				}
				$route['parameters']=self::matched_parameters($route, $domain_parameters);
				return $route;
			}
			if(empty($route['path_regex'])){
				continue;
			}
			$domain_parameters=[];
			if(self::route_matches_domain($route, $request_host, $domain_parameters)===false){
				continue;
			}
			if(@preg_match($route['path_regex'], $request_path, $matches)!==1){
				continue;
			}
			$parameters=self::matched_parameters($route, $domain_parameters);
			foreach($matches as $key=>$value){
				if(is_int($key) || $value===''){
					continue;
				}
				$parameters[$key]=rawurldecode((string)$value);
			}
			foreach(($route['splat_parameters'] ?? []) as $parameter_name){
				$parameters[$parameter_name]=self::explode_splat_parameter($parameters[$parameter_name] ?? '');
			}
			$route['parameters']=$parameters;
			return $route;
		}
		return null;
	}

	/**
	 * Merges route defaults with already matched domain or path parameters.
	 *
	 * @param array<string, mixed> $route Compiled route definition.
	 * @param array<string, mixed> $parameters Matched domain or path parameters.
	 * @return array<string, mixed> Defaults overlaid by matched parameters.
	 */
	private static function matched_parameters(array $route, array $parameters): array {
		$defaults=$route['defaults'] ?? null;
		return is_array($defaults) && $defaults!==[]
			? array_replace($defaults, $parameters)
			: $parameters;
	}

	/**
	 * Checks a route's domain constraints and extracts domain parameters.
	 *
	 * @param array<string, mixed> $route Compiled route definition.
	 * @param string $host Normalized request host.
	 * @param array<string, string> $parameters Domain capture parameters populated by reference.
	 * @return bool Whether the route accepts the request host.
	 */
	private static function route_matches_domain(array $route, string $host, array &$parameters): bool {
		$parameters=[];
		if(!isset($route['domain']) && !isset($route['exact_domain']) && !isset($route['domain_regex'])){
			return true;
		}
		if($host===''){
			return false;
		}
		if(isset($route['exact_domain'])){
			return self::normalize_host((string)$route['exact_domain'])===$host;
		}
		if(!isset($route['domain_regex']) || @preg_match((string)$route['domain_regex'], $host, $matches)!==1){
			return false;
		}
		foreach($matches as $key=>$value){
			if(!is_int($key) && $value!==''){
				$parameters[$key]=rawurldecode((string)$value);
			}
		}
		return true;
	}

	/**
	 * Resolves the current request path from Dataphyre's `uri` override or `REQUEST_URI`.
	 *
	 * @return string Normalized leading-slash path without a trailing slash except `/`.
	 */
	private static function normalized_request_path(): string {
		$path=(string)($_GET['uri'] ?? '');
		if($path===''){
			$path=(string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
		}
		return self::normalize_path($path);
	}

	/**
	 * Normalizes a route path for compiled matching.
	 *
	 * @param string $path Raw request or route path.
	 * @return string Leading-slash path without a trailing slash except `/`.
	 */
	private static function normalize_path(string $path): string {
		$path='/'.trim($path, '/');
		return $path==='/' ? '/' : rtrim($path, '/');
	}

	/**
	 * Normalizes a host value before domain route comparison.
	 *
	 * @param string $host Raw host header, URL, server name, or IPv6 bracket form.
	 * @return string Lowercase hostname without scheme, path, surrounding dots, brackets, or simple port suffix.
	 */
	private static function normalize_host(string $host): string {
		$host=strtolower(trim($host));
		$host=trim(explode(',', $host, 2)[0]);
		$host=preg_replace('#^https?://#i', '', $host) ?? $host;
		$host=trim(explode('/', $host, 2)[0] ?? '', '.');
		if(str_starts_with($host, '[')){
			return trim($host, '[]');
		}
		if(substr_count($host, ':')===1){
			$host=explode(':', $host, 2)[0];
		}
		return $host;
	}

	/**
	 * Publishes matched route parameters to the legacy routing binding store.
	 *
	 * @param array<string, mixed> $parameters Matched route parameters.
	 * @return void
	 */
	private static function publish_parameters(array $parameters): void {
		\dataphyre\routing::$bindings=$parameters;
	}

	/**
	 * Converts a greedy path capture into decoded URL segments.
	 *
	 * @param string $value Raw splat capture.
	 * @return array<int, string> Decoded non-empty path segments.
	 */
	private static function explode_splat_parameter(string $value): array {
		$value=trim($value, '/');
		if($value===''){
			return [];
		}
		$segments=[];
		foreach(explode('/', $value) as $segment){
			if($segment===''){
				continue;
			}
			$segments[]=rawurldecode($segment);
		}
		return $segments;
	}

	/**
	 * Dispatches a matched route through middleware when present.
	 *
	 * @param mixed $handler Compiled handler definition.
	 * @param array<string, mixed> $route Matched route definition.
	 * @return void
	 */
	private static function run_handler(mixed $handler, array $route): void {
		$middleware=$route['middleware'] ?? [];
		if(is_array($middleware) && $middleware!==[]){
			self::dispatch_with_middleware($handler, $route, $middleware);
			return;
		}
		self::dispatch_without_middleware($handler, $route);
	}

	/**
	 * Executes a matched route handler directly, without a middleware pipeline.
	 *
	 * API metadata is authorized and optionally executed before ordinary handlers run. Supported
	 * handler shapes include include files, callables, callable arrays, and controller
	 * definitions.
	 *
	 * @param mixed $handler Compiled handler definition.
	 * @param array<string, mixed> $route Matched route definition.
	 * @return void
	 */
	private static function dispatch_without_middleware(mixed $handler, array $route): void {
		$request=null;
		if(self::route_has_api_metadata($route)){
			$request=Request::capture($route['parameters'] ?? []);
			$authorization_response=self::authorize_api_route($route, $request);
			if($authorization_response!==null){
				ResponseEmitter::emit($authorization_response);
				return;
			}
			$execution_response=self::execute_api_route($route, $request);
			if($execution_response!==null){
				ResponseEmitter::emit($execution_response);
				return;
			}
		}
		if(is_string($handler) && is_file($handler)){
			self::require_handler_file($handler);
			return;
		}
		if(is_callable($handler)){
			$handler($route['parameters'] ?? [], $route);
			return;
		}
		if(is_array($handler)){
			$type=$handler['type'] ?? null;
			if($type==='include' && !empty($handler['target']) && is_file($handler['target'])){
				self::bootstrap_handler($handler);
				self::require_handler_file($handler['target']);
				return;
			}
			if($type==='callable' && isset($handler['target']) && is_callable($handler['target'])){
				self::bootstrap_handler($handler);
				$target=$handler['target'];
				$target($route['parameters'] ?? [], $route);
				return;
			}
			if($type==='controller'){
				self::dispatch_controller($handler, $route, $request);
				return;
			}
		}
		throw new \RuntimeException('Compiled route handler is invalid or unsupported.');
	}

	/**
	 * Executes a matched route through the resolved middleware pipeline.
	 *
	 * Middleware receives a captured HTTP request and can return a response object, delegate to
	 * `$next`, or allow the terminal handler to complete without emitting a response.
	 *
	 * @param mixed $handler Compiled handler definition.
	 * @param array<string, mixed> $route Matched route definition.
	 * @param array<int, array<string, mixed>> $middleware Compiled middleware definitions.
	 * @return void
	 */
	private static function dispatch_with_middleware(mixed $handler, array $route, array $middleware): void {
		self::ensure_core_framework_loader();
		\dataphyre\core::load_framework_module('http');
		self::bootstrap_handler($handler);
		$request=Request::capture($route['parameters'] ?? []);
		$authorization_response=self::authorize_api_route($route, $request);
		if($authorization_response!==null){
			ResponseEmitter::emit($authorization_response);
			return;
		}
		$resolved_middleware=self::resolve_route_middleware($middleware);
		$terminal=static function(mixed $request) use ($handler, $route): mixed {
			$dispatch=self::dispatch_handler(
				$handler,
				$route,
				$request instanceof Request ? $request : null,
				false
			);
			return ($dispatch['emit'] ?? false)===true
				? ($dispatch['result'] ?? null)
				: self::no_emit_sentinel();
		};
		$pipeline=$terminal;
		for($index=count($resolved_middleware)-1; $index>=0; $index--){
			$instance=self::instantiate_middleware($resolved_middleware[$index]);
			$parameters=$resolved_middleware[$index]['parameters'] ?? [];
			$next=$pipeline;
			$pipeline=static function(mixed $request) use ($instance, $next, $parameters): mixed {
				return $instance->handle($request, $next, ...$parameters);
			};
		}
		$result=$pipeline($request);
		if($result===self::no_emit_sentinel()){
			return;
		}
		ResponseEmitter::emit($result);
	}

	/**
	 * Runs a compiled handler and reports whether its result should be emitted.
	 *
	 * This shared routine powers middleware terminals and direct dispatch paths while preserving
	 * include/callable handlers that produce their own output.
	 *
	 * @param mixed $handler Compiled handler definition.
	 * @param array<string, mixed> $route Matched route definition.
	 * @param ?Request $request Captured request for API/controller handlers.
	 * @param bool $bootstrap Whether handler-specific bootstrap work should run first.
	 * @return array{emit:bool, result:mixed} Dispatch result and emission hint.
	 */
	private static function dispatch_handler(
		mixed $handler,
		array $route,
		?Request $request=null,
		bool $bootstrap=true
	): array {
		if($request instanceof Request){
			$execution_response=self::execute_api_route($route, $request);
			if($execution_response!==null){
				return [
					'emit'=>true,
					'result'=>$execution_response,
				];
			}
		}
		if(is_string($handler) && is_file($handler)){
			if($bootstrap){
				self::bootstrap_handler($handler);
			}
			self::require_handler_file($handler);
			return [
				'emit'=>false,
				'result'=>null,
			];
		}
		if(is_callable($handler)){
			if($bootstrap){
				self::bootstrap_handler($handler);
			}
			$handler($route['parameters'] ?? [], $route);
			return [
				'emit'=>false,
				'result'=>null,
			];
		}
		if(is_array($handler)){
			$type=$handler['type'] ?? null;
			if($type==='include' && !empty($handler['target']) && is_file($handler['target'])){
				if($bootstrap){
					self::bootstrap_handler($handler);
				}
				self::require_handler_file($handler['target']);
				return [
					'emit'=>false,
					'result'=>null,
				];
			}
			if($type==='callable' && isset($handler['target']) && is_callable($handler['target'])){
				if($bootstrap){
					self::bootstrap_handler($handler);
				}
				$target=$handler['target'];
				$target($route['parameters'] ?? [], $route);
				return [
					'emit'=>false,
					'result'=>null,
				];
			}
			if($type==='controller'){
				return [
					'emit'=>true,
					'result'=>self::invoke_controller($handler, $route, $request, $bootstrap),
				];
			}
		}
		throw new \RuntimeException('Compiled route handler is invalid or unsupported.');
	}

	/**
	 * Invokes a controller handler and emits the returned response.
	 *
	 * @param array<string, mixed> $handler Compiled controller handler definition.
	 * @param array<string, mixed> $route Matched route definition.
	 * @param ?Request $request Captured request, or null to capture from globals.
	 * @return void
	 */
	private static function dispatch_controller(array $handler, array $route, ?Request $request=null): void {
		ResponseEmitter::emit(self::invoke_controller($handler, $route, $request));
	}

	/**
	 * Invokes a compiled controller method with loaded framework dependencies.
	 *
	 * Controller classes infer module dependencies by namespace prefix. Static and instance
	 * methods receive the captured request and matched route array.
	 *
	 * @param array<string, mixed> $handler Compiled controller handler definition.
	 * @param array<string, mixed> $route Matched route definition.
	 * @param ?Request $request Captured request, or null to capture from globals.
	 * @param bool $bootstrap Whether handler bootstrap data should be loaded first.
	 * @return mixed Controller return value, usually an HTTP response object.
	 */
	private static function invoke_controller(
		array $handler,
		array $route,
		?Request $request=null,
		bool $bootstrap=true
	): mixed {
		self::ensure_core_framework_loader();
		if($bootstrap){
			self::bootstrap_handler($handler);
		}
		$class=(string)($handler['class'] ?? '');
		$method=(string)($handler['method'] ?? '');
		if($class==='' || $method===''){
			throw new \RuntimeException('Compiled controller handler is missing class or method.');
		}
		$modules=self::infer_framework_modules_for_class($class);
		array_unshift($modules, 'http');
		\dataphyre\core::load_framework_modules(array_values(array_unique($modules)));
		$request=$request ?? Request::capture($route['parameters'] ?? []);
		return ($handler['static'] ?? true)===true
			? $class::$method($request, $route)
			: (new $class())->$method($request, $route);
	}

	/**
	 * Loads a handler's configured bootstrap target when present.
	 *
	 * @param mixed $handler Compiled handler definition.
	 * @return void
	 */
	private static function bootstrap_handler(mixed $handler): void {
		if(!is_array($handler)){
			return;
		}
		$bootstrap=$handler['bootstrap'] ?? null;
		self::bootstrap_target($bootstrap);
	}

	/**
	 * Requires an include-file route target.
	 *
	 * @param string $file Absolute or resolved file path from the compiled manifest.
	 * @return void
	 */
	private static function require_handler_file(string $file): void {
		require($file);
	}

	/**
	 * Ensures the Dataphyre core framework loader is available in route-only entrypoints.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the core functions file cannot be located.
	 */
	private static function ensure_core_framework_loader(): void {
		if(class_exists('\dataphyre\core', false)){
			return;
		}
		$core_functions_file=ROOTPATH['common_dataphyre_runtime'].'modules/core/kernel/core_functions.php';
		if(!is_file($core_functions_file)){
			throw new \RuntimeException('Unable to locate Dataphyre core framework loader.');
		}
		require_once($core_functions_file);
	}

	/**
	 * Resolves every middleware definition attached to a route.
	 *
	 * @param array<int, mixed> $middleware Compiled middleware definition list.
	 * @param array<string, mixed> $aliases Custom alias definitions.
	 * @return array<int, array<string, mixed>> Normalized middleware definitions.
	 */
	private static function resolve_route_middleware(array $middleware, array $aliases=[]): array {
		$resolved=[];
		foreach($middleware as $definition){
			if(!is_array($definition)){
				throw new \RuntimeException('Compiled route middleware definition is invalid.');
			}
			$resolved[]=self::resolve_middleware_definition($definition, $aliases);
		}
		return $resolved;
	}

	/**
	 * Normalizes one middleware definition into class/callable, parameters, modules, and bootstrap data.
	 *
	 * Alias definitions can be built-in, custom class strings, custom callables, or arrays. Class
	 * definitions infer framework modules from namespace prefixes and are bootstrapped before
	 * middleware instantiation.
	 *
	 * @param array<string, mixed> $definition Raw compiled middleware definition.
	 * @param array<string, mixed> $custom_aliases Custom alias definitions.
	 * @return array<string, mixed> Normalized middleware definition.
	 */
	private static function resolve_middleware_definition(array $definition, array $custom_aliases=[]): array {
		$resolved=$definition;
		if(isset($definition['alias'])){
			$alias=strtolower(trim((string)$definition['alias']));
			$aliases=array_replace(self::middleware_aliases(), self::normalize_custom_middleware_aliases($custom_aliases));
			if(!isset($aliases[$alias])){
				throw new \RuntimeException('Compiled route middleware alias is invalid: '.$alias);
			}
			if(is_callable($aliases[$alias])){
				return [
					'target'=>$aliases[$alias],
					'parameters'=>array_values((array)($definition['parameters'] ?? [])),
					'modules'=>[],
					'bootstrap'=>null,
				];
			}
			$resolved=array_replace($aliases[$alias], $definition);
			$resolved['parameters']=array_values(array_merge(
				(array)($aliases[$alias]['parameters'] ?? []),
				(array)($definition['parameters'] ?? [])
			));
			$resolved['modules']=array_values(array_unique(array_merge(
				$aliases[$alias]['modules'] ?? [],
				$definition['modules'] ?? []
			)));
			if(array_key_exists('bootstrap', $definition)===false && array_key_exists('bootstrap', $aliases[$alias])){
				$resolved['bootstrap']=$aliases[$alias]['bootstrap'];
			}
		}
		if(isset($resolved['target']) && is_callable($resolved['target'])){
			self::bootstrap_target($resolved['bootstrap'] ?? null);
			return [
				'target'=>$resolved['target'],
				'parameters'=>array_values((array)($resolved['parameters'] ?? [])),
				'modules'=>[],
				'bootstrap'=>$resolved['bootstrap'] ?? null,
			];
		}
		$class=trim((string)($resolved['class'] ?? ''), '\\');
		if($class===''){
			throw new \RuntimeException('Compiled route middleware is missing a class.');
		}
		$modules=$resolved['modules'] ?? [];
		if(!is_array($modules)){
			$modules=is_string($modules) && trim($modules)!==''
				? [strtolower(trim($modules))]
				: [];
		}
		$modules=array_values(array_unique(array_merge(
			array_map(static fn($module)=>strtolower(trim((string)$module)), $modules),
			self::infer_framework_modules_for_class($class)
		)));
		$parameters=$resolved['parameters'] ?? [];
		if(!is_array($parameters)){
			$parameters=[$parameters];
		}
		$middleware=[
			'class'=>$class,
			'parameters'=>array_values($parameters),
			'modules'=>array_values(array_filter($modules, static fn(string $module): bool => $module!=='')),
			'bootstrap'=>$resolved['bootstrap'] ?? null,
		];
		self::bootstrap_middleware($middleware);
		return $middleware;
	}

	/**
	 * Normalizes caller-provided middleware aliases into resolver-ready definitions.
	 *
	 * @param array<string, mixed> $aliases Custom alias map keyed by alias name.
	 * @return array<string, mixed> Alias map with lowercase keys and normalized class strings.
	 */
	private static function normalize_custom_middleware_aliases(array $aliases): array {
		$normalized=[];
		foreach($aliases as $alias=>$definition){
			$alias=strtolower(trim((string)$alias));
			if($alias===''){
				continue;
			}
			if(is_string($definition) && trim($definition)!==''){
				$normalized[$alias]=['class'=>trim($definition, '\\')];
				continue;
			}
			if(is_callable($definition)){
				$normalized[$alias]=$definition;
				continue;
			}
			if(is_array($definition)){
				if(isset($definition['class']) && is_string($definition['class'])){
					$definition['class']=trim($definition['class'], '\\');
				}
				$normalized[$alias]=$definition;
			}
		}
		return $normalized;
	}

	/**
	 * Returns built-in middleware aliases for Dataphyre access and permission modules.
	 *
	 * @return array<string, array<string, mixed>> Cached alias map.
	 */
	private static function middleware_aliases(): array {
		static $aliases=null;
		if($aliases!==null){
			return $aliases;
		}
		return $aliases=[
			'auth'=>[
				'class'=>'Dataphyre\\Access\\Middleware\\Authenticate',
				'modules'=>['access'],
			],
			'guest'=>[
				'class'=>'Dataphyre\\Access\\Middleware\\Guest',
				'modules'=>['access'],
			],
			'can'=>[
				'class'=>'Dataphyre\\Permission\\Middleware\\Authorize',
				'modules'=>['permission'],
			],
			'permission'=>[
				'class'=>'Dataphyre\\Permission\\Middleware\\Authorize',
				'modules'=>['permission'],
			],
			'can_any'=>[
				'class'=>'Dataphyre\\Permission\\Middleware\\AuthorizeAny',
				'modules'=>['permission'],
			],
			'permission_any'=>[
				'class'=>'Dataphyre\\Permission\\Middleware\\AuthorizeAny',
				'modules'=>['permission'],
			],
			'can_when'=>[
				'class'=>'Dataphyre\\Permission\\Middleware\\AuthorizeWhen',
				'modules'=>['permission'],
			],
			'permission_when'=>[
				'class'=>'Dataphyre\\Permission\\Middleware\\AuthorizeWhen',
				'modules'=>['permission'],
			],
			'can_any_when'=>[
				'class'=>'Dataphyre\\Permission\\Middleware\\AuthorizeAnyWhen',
				'modules'=>['permission'],
			],
			'permission_any_when'=>[
				'class'=>'Dataphyre\\Permission\\Middleware\\AuthorizeAnyWhen',
				'modules'=>['permission'],
			],
		];
	}

	/**
	 * Infers framework modules required by a class namespace.
	 *
	 * @param string $class Fully qualified class name.
	 * @return array<int, string> Module names to load before class use.
	 */
	private static function infer_framework_modules_for_class(string $class): array {
		$class=trim($class, '\\');
		return match (true) {
			str_starts_with($class, 'Dataphyre\\Access\\') => ['access'],
			str_starts_with($class, 'Dataphyre\\Permission\\') => ['permission'],
			str_starts_with($class, 'Dataphyre\\Api\\') => ['api'],
			str_starts_with($class, 'Dataphyre\\Http\\') => ['http'],
			str_starts_with($class, 'Dataphyre\\Routing\\') => ['routing'],
			str_starts_with($class, 'Dataphyre\\Database\\') => ['sql'],
			default => [],
		};
	}

	/**
	 * Reports whether a compiled route contains API metadata.
	 *
	 * @param array<string, mixed> $route Compiled route definition.
	 * @return bool Whether the route has an `api` metadata array.
	 */
	private static function route_has_api_metadata(array $route): bool {
		return isset($route['api']) && is_array($route['api']);
	}

	/**
	 * Runs API authorization for compiled API routes when the API module is available.
	 *
	 * @param array<string, mixed> $route Compiled route definition.
	 * @param Request $request Captured HTTP request.
	 * @return object|null Response object to emit when authorization fails or short-circuits, otherwise null.
	 */
	private static function authorize_api_route(array $route, Request $request): ?object {
		if(self::route_has_api_metadata($route)===false){
			return null;
		}
		self::ensure_core_framework_loader();
		\dataphyre\core::load_framework_modules(['http', 'api']);
		if(class_exists('Dataphyre\\Api\\Api')===false || method_exists('Dataphyre\\Api\\Api', 'authorizeCompiledRoute')===false){
			return null;
		}
		$response=\Dataphyre\Api\Api::authorizeCompiledRoute($route, $request);
		return is_object($response) ? $response : null;
	}

	/**
	 * Runs API execution metadata for compiled API routes when configured.
	 *
	 * @param array<string, mixed> $route Compiled route definition.
	 * @param Request $request Captured HTTP request.
	 * @return object|null Response object produced by API execution, or null to continue normal handler dispatch.
	 */
	private static function execute_api_route(array $route, Request $request): ?object {
		if(self::route_has_api_metadata($route)===false || is_array($route['api']['execution'] ?? null)===false){
			return null;
		}
		self::ensure_core_framework_loader();
		\dataphyre\core::load_framework_modules(['http', 'api']);
		if(class_exists('Dataphyre\\Api\\Api')===false || method_exists('Dataphyre\\Api\\Api', 'executeCompiledRoute')===false){
			return null;
		}
		$response=\Dataphyre\Api\Api::executeCompiledRoute($route, $request);
		return is_object($response) ? $response : null;
	}

	/**
	 * Loads modules and bootstrap targets required before middleware instantiation.
	 *
	 * @param array<string, mixed> $middleware Normalized middleware definition.
	 * @return void
	 */
	private static function bootstrap_middleware(array $middleware): void {
		if(($middleware['modules'] ?? [])===[] && empty($middleware['bootstrap'])){
			return;
		}
		self::ensure_core_framework_loader();
		if(($middleware['modules'] ?? [])!==[]){
			\dataphyre\core::load_framework_modules($middleware['modules']);
		}
		self::bootstrap_target($middleware['bootstrap'] ?? null);
	}

	/**
	 * Instantiates a middleware class and verifies the `handle` contract.
	 *
	 * @param array<string, mixed> $middleware Normalized middleware definition.
	 * @return object Middleware instance exposing `handle()`.
	 */
	private static function instantiate_middleware(array $middleware): object {
		$class=(string)($middleware['class'] ?? '');
		if($class==='' || class_exists($class)===false){
			throw new \RuntimeException('Compiled route middleware class is invalid: '.$class);
		}
		$instance=new $class();
		if(is_object($instance)===false || method_exists($instance, 'handle')===false){
			throw new \RuntimeException('Compiled route middleware must expose a handle method: '.$class);
		}
		return $instance;
	}

	/**
	 * Loads a bootstrap target referenced by a handler or middleware definition.
	 *
	 * The special `core` target loads Dataphyre's kernel core once; file targets are required
	 * directly. Invalid targets fail loudly because compiled routes should be deterministic.
	 *
	 * @param mixed $bootstrap Bootstrap selector, file path, `core`, or null.
	 * @return void
	 */
	private static function bootstrap_target(mixed $bootstrap): void {
		if($bootstrap===null || $bootstrap===''){
			return;
		}
		if($bootstrap==='core'){
			if(defined('DP_CORE_LOADED')===false){
				require_once(ROOTPATH['common_dataphyre_runtime'].'modules/core/kernel/core.main.php');
			}
			return;
		}
		if(is_string($bootstrap) && is_file($bootstrap)){
			require_once($bootstrap);
			return;
		}
		throw new \RuntimeException('Compiled route bootstrap target is invalid.');
	}

	/**
	 * Returns the singleton sentinel used to suppress duplicate response emission.
	 *
	 * @return object Identity-only sentinel object.
	 */
	private static function no_emit_sentinel(): object {
		return self::$no_emit_sentinel ??= new \stdClass();
	}
}
