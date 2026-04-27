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

final class compiled_route_dispatcher {

	private static ?object $no_emit_sentinel=null;

	public static function dispatch_file(string $manifest_file): bool {
		$manifest=require($manifest_file);
		if(!is_array($manifest)){
			throw new \RuntimeException("Compiled route manifest must return an array: {$manifest_file}");
		}
		return self::dispatch_manifest($manifest);
	}

	public static function dispatch_manifest(array $manifest): bool {
		$route=self::match_route($manifest['routes'] ?? []);
		if($route===null){
			return false;
		}
		self::publish_parameters($route['parameters'] ?? []);
		self::run_handler($route['handler'] ?? null, $route);
		return true;
	}

	private static function match_route(array $routes): ?array {
		$request_method=strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		$request_path=self::normalized_request_path();
		foreach($routes as $route){
			$methods=$route['methods'] ?? ['GET'];
			$methods=array_map(static fn($method)=>strtoupper((string)$method), $methods);
			if(!in_array($request_method, $methods, true) && !in_array('ANY', $methods, true)){
				continue;
			}
			if(isset($route['exact_path']) && $route['exact_path']===$request_path){
				$route['parameters']=[];
				return $route;
			}
			if(empty($route['path_regex'])){
				continue;
			}
			if(@preg_match($route['path_regex'], $request_path, $matches)!==1){
				continue;
			}
			$parameters=[];
			foreach($matches as $key=>$value){
				if(is_int($key)){
					continue;
				}
				$parameters[$key]=$value;
			}
			foreach(($route['splat_parameters'] ?? []) as $parameter_name){
				$parameters[$parameter_name]=self::explode_splat_parameter($parameters[$parameter_name] ?? '');
			}
			$route['parameters']=$parameters;
			return $route;
		}
		return null;
	}

	private static function normalized_request_path(): string {
		$path=(string)($_GET['uri'] ?? '');
		if($path===''){
			$path=(string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
		}
		$path='/'.trim($path, '/');
		return $path==='/' ? '/' : rtrim($path, '/');
	}

	private static function publish_parameters(array $parameters): void {
		\dataphyre\routing::$bindings=$parameters;
	}

	private static function explode_splat_parameter(string $value): array {
		$value=trim($value, '/');
		if($value===''){
			return [];
		}
		return array_values(array_filter(explode('/', $value), static fn($segment)=>$segment!==''));
	}

	private static function run_handler(mixed $handler, array $route): void {
		$middleware=$route['middleware'] ?? [];
		if(is_array($middleware) && $middleware!==[]){
			self::dispatch_with_middleware($handler, $route, $middleware);
			return;
		}
		self::dispatch_without_middleware($handler, $route);
	}

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

	private static function dispatch_controller(array $handler, array $route, ?Request $request=null): void {
		ResponseEmitter::emit(self::invoke_controller($handler, $route, $request));
	}

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

	private static function bootstrap_handler(mixed $handler): void {
		if(!is_array($handler)){
			return;
		}
		$bootstrap=$handler['bootstrap'] ?? null;
		self::bootstrap_target($bootstrap);
	}

	private static function require_handler_file(string $file): void {
		require($file);
	}

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

	private static function resolve_route_middleware(array $middleware): array {
		$resolved=[];
		foreach($middleware as $definition){
			if(!is_array($definition)){
				throw new \RuntimeException('Compiled route middleware definition is invalid.');
			}
			$resolved[]=self::resolve_middleware_definition($definition);
		}
		return $resolved;
	}

	private static function resolve_middleware_definition(array $definition): array {
		$resolved=$definition;
		if(isset($definition['alias'])){
			$alias=strtolower(trim((string)$definition['alias']));
			$aliases=self::middleware_aliases();
			if(!isset($aliases[$alias])){
				throw new \RuntimeException('Compiled route middleware alias is invalid: '.$alias);
			}
			$resolved=array_replace($aliases[$alias], $definition);
			$resolved['parameters']=$definition['parameters'] ?? ($aliases[$alias]['parameters'] ?? []);
			$resolved['modules']=array_values(array_unique(array_merge(
				$aliases[$alias]['modules'] ?? [],
				$definition['modules'] ?? []
			)));
			if(array_key_exists('bootstrap', $definition)===false && array_key_exists('bootstrap', $aliases[$alias])){
				$resolved['bootstrap']=$aliases[$alias]['bootstrap'];
			}
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
		];
	}

	private static function infer_framework_modules_for_class(string $class): array {
		$class=trim($class, '\\');
		return match (true) {
			str_starts_with($class, 'Dataphyre\\Access\\') => ['access'],
			str_starts_with($class, 'Dataphyre\\Api\\') => ['api'],
			str_starts_with($class, 'Dataphyre\\Http\\') => ['http'],
			str_starts_with($class, 'Dataphyre\\Routing\\') => ['routing'],
			str_starts_with($class, 'Dataphyre\\Database\\') => ['sql'],
			default => [],
		};
	}

	private static function route_has_api_metadata(array $route): bool {
		return isset($route['api']) && is_array($route['api']);
	}

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

	private static function bootstrap_middleware(array $middleware): void {
		self::ensure_core_framework_loader();
		if(($middleware['modules'] ?? [])!==[]){
			\dataphyre\core::load_framework_modules($middleware['modules']);
		}
		self::bootstrap_target($middleware['bootstrap'] ?? null);
	}

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

	private static function no_emit_sentinel(): object {
		return self::$no_emit_sentinel ??= new \stdClass();
	}
}
