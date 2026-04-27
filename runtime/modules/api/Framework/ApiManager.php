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
use Dataphyre\Routing\ControllerAction;
use Dataphyre\Routing\Route;

final class ApiManager {

	private const AUTH_ATTRIBUTE='dataphyre_api_auth';

	private static ?self $instance=null;

	public static function instance(): self {
		if(self::$instance===null){
			self::$instance=new self();
		}
		return self::$instance;
	}

	public function documentationRoutes(array $options=[]): array {
		$options=$this->normalizeDocumentationOptions($options);

		$spec_route=Route::get(
			$options['spec_path'],
			ControllerAction::static('Dataphyre\\Api\\OpenApiController', 'show', [
				'bootstrap'=>$options['bootstrap'],
			])
		)->compile();
		$spec_route['path_template']=$options['spec_path'];
		$spec_route['api_docs']=$options;

		$docs_route=Route::get(
			$options['docs_path'],
			ControllerAction::static('Dataphyre\\Api\\SwaggerUiController', 'show', [
				'bootstrap'=>$options['bootstrap'],
			])
		)->compile();
		$docs_route['path_template']=$options['docs_path'];
		$docs_route['api_docs']=$options;

		return [$spec_route, $docs_route];
	}

	public function discoverApplication(?string $application_id=null): array {
		return $this->discoverManifest($this->applicationManifest($application_id));
	}

	public function discoverManifest(array $manifest): array {
		$endpoints=[];
		foreach(($manifest['routes'] ?? []) as $route){
			if(!is_array($route) || !is_array($route['api'] ?? null)){
				continue;
			}
			$api=$route['api'];
			$endpoints[]=[
				'path'=>$api['path'] ?? ($route['path_template'] ?? $route['exact_path'] ?? '/'),
				'methods'=>$api['methods'] ?? ($route['methods'] ?? []),
				'tags'=>$api['tags'] ?? [],
				'aliases'=>$api['aliases'] ?? [],
				'cache'=>$api['cache'] ?? null,
				'summary'=>$api['summary'] ?? null,
				'description'=>$api['description'] ?? null,
				'operation_id'=>$api['operation_id'] ?? null,
				'deprecated'=>$api['deprecated'] ?? false,
				'parameters'=>$api['parameters'] ?? [],
				'request_body'=>$api['request_body'] ?? null,
				'responses'=>$api['responses'] ?? [],
				'security_schemes'=>$api['security_schemes'] ?? [],
				'security'=>$api['security'] ?? [],
				'servers'=>$api['servers'] ?? [],
				'execution'=>$api['execution'] ?? null,
				'bindings'=>$api['bindings'] ?? [],
				'lifecycle'=>$api['lifecycle'] ?? [],
				'schema'=>$api['schema'] ?? null,
				'trace'=>$api['trace'] ?? null,
				'profile'=>$api['profile'] ?? null,
				'dispatch'=>$api['dispatch'] ?? null,
				'handler'=>$route['handler'] ?? null,
			];
		}
		return $endpoints;
	}

	public function openApiDocument(?string $application_id=null, array $options=[]): array {
		$definition=$this->applicationDefinition($application_id);
		$options=$this->openApiOptions($definition, $options);
		return (new OpenApiGenerator())->generate($this->discoverApplication($application_id), $options);
	}

	public function dispatch(array $request_definition, array $options=[]): array {
		$started_at=microtime(true);
		$initial_body=is_array($request_definition['body'] ?? null)
			? $request_definition['body']
			: (is_array($request_definition['post'] ?? null) ? $request_definition['post'] : []);
		$initial_alias=$this->normalizeAlias((string)($request_definition['alias'] ?? $request_definition['endpoint'] ?? ''));
		$definition=[
			'key'=>is_string($request_definition['key'] ?? null) ? trim((string)$request_definition['key']) : null,
			'method'=>$this->inferInternalDispatchMethod($request_definition['method'] ?? null, $initial_alias, $initial_body),
			'path'=>isset($request_definition['path']) || isset($request_definition['uri'])
				? self::normalizePath((string)($request_definition['path'] ?? $request_definition['uri'] ?? '/'))
				: null,
			'alias'=>$initial_alias,
			'profile'=>$this->normalizeProfileName($request_definition['profile'] ?? null),
		];
		try{
			$definition=$this->normalizeInternalRequestDefinition($request_definition);
			$manifest=$this->applicationManifest(isset($options['application']) ? (string)$options['application'] : null);
			$resolution=$this->resolveManifestDispatch($manifest['routes'] ?? [], $definition);
			if(isset($resolution['route'])===false){
				return $this->dispatchFailureRecord(
					$definition,
					(int)($resolution['status'] ?? 404),
					(string)($resolution['message'] ?? 'API route not found.'),
					$started_at
				);
			}
			$route=$resolution['route'];

			$request=$this->internalRequestForRoute($route, $definition, $options);
			$trusted_auth=($options['trust_auth'] ?? false)===true && is_array($request->attribute(self::AUTH_ATTRIBUTE));
			if($trusted_auth===false){
				$authorization_response=$this->authorizeCompiledRoute($route, $request);
				if($authorization_response instanceof Response){
					return $this->normalizeDispatchedResponse($definition, $route, $authorization_response, $started_at);
				}
			}

			$response=$this->dispatchMatchedRoute($route, $request);
			return $this->normalizeDispatchedResponse($definition, $route, $response, $started_at);
		}catch(\Throwable $exception){
			$message=($options['expose_exceptions'] ?? false)===true && trim($exception->getMessage())!==''
				? $exception->getMessage()
				: 'Internal API dispatch failed.';
			return $this->dispatchFailureRecord($definition, 500, $message, $started_at);
		}
	}

	public function dispatchBatch(array $requests, array $options=[]): array {
		$started_at=microtime(true);
		$limit=max(1, (int)($options['limit'] ?? 128));
		if(count($requests)>$limit){
			return [
				'ok'=>false,
				'error'=>(string)($options['limit_error'] ?? 'too_many_requests'),
				'limit'=>$limit,
				'count'=>count($requests),
				'duration_ms'=>round((microtime(true)-$started_at)*1000, 3),
				'responses'=>[],
			];
		}

		$continue_on_error=($options['continue_on_error'] ?? true)===true;
		$responses=[];
		foreach($requests as $key=>$request_definition){
			if(is_array($request_definition)===false){
				$responses[]=$this->dispatchFailureRecord([
					'key'=>is_string($key) ? trim($key) : null,
					'method'=>'GET',
					'path'=>'/',
				], 422, 'Batch request entry must be an array.', $started_at);
				if($continue_on_error===false){
					break;
				}
				continue;
			}
			if(!array_key_exists('key', $request_definition) && is_string($key) && trim($key)!==''){
				$request_definition['key']=trim($key);
				if(!isset($request_definition['path']) && !isset($request_definition['uri']) && str_starts_with(trim($key), '/')){
					$request_definition['path']=trim($key);
				}elseif(!isset($request_definition['path']) && !isset($request_definition['uri']) && !isset($request_definition['alias']) && !isset($request_definition['endpoint'])){
					$request_definition['alias']=trim($key);
				}
			}
			$record=$this->dispatch($request_definition, $options);
			$responses[]=$record;
			if($continue_on_error===false && ($record['ok'] ?? false)!==true){
				break;
			}
		}

		$failures=0;
		foreach($responses as $record){
			if(($record['ok'] ?? false)!==true){
				$failures++;
			}
		}

		return [
			'ok'=>$failures===0,
			'count'=>count($responses),
			'failures'=>$failures,
			'duration_ms'=>round((microtime(true)-$started_at)*1000, 3),
			'responses'=>$responses,
		];
	}

	public function dispatchChain(array $requests, array $options=[]): array {
		return $this->dispatchBatch($requests, array_replace([
			'limit'=>128,
			'limit_error'=>'too_many_chainlinks',
			'continue_on_error'=>true,
		], $options));
	}

	public function clearEndpointCache(string ...$names): int {
		$cache_dir=$this->endpointCacheRoot();
		$items_dir=$cache_dir.'items'.DIRECTORY_SEPARATOR;
		$names_dir=$cache_dir.'names'.DIRECTORY_SEPARATOR;
		if($names===[]){
			return $this->clearPersistentCacheDirectories($items_dir, $names_dir);
		}

		$deleted=0;
		foreach($this->normalizeEndpointCacheNames($names) as $name){
			$name_file=$this->endpointCacheNameFile($name, $names_dir);
			if(!is_file($name_file)){
				continue;
			}
			$payload=@file_get_contents($name_file);
			$keys=json_decode(is_string($payload) ? $payload : '[]', true);
			if(is_array($keys)){
				foreach($keys as $key){
					if(!is_string($key) || $key===''){
						continue;
					}
					$item_file=$items_dir.$key.'.cache';
					if(is_file($item_file) && @unlink($item_file)){
						$deleted++;
					}
				}
			}
			@unlink($name_file);
		}
		return $deleted;
	}

	public function authorizeCompiledRoute(array $route, Request $request): ?Response {
		$api=$route['api'] ?? null;
		if(!is_array($api)){
			return null;
		}
		$requirements=$api['security'] ?? [];
		$schemes=$api['security_schemes'] ?? [];
		if(!is_array($requirements) || $requirements===[] || !is_array($schemes) || $schemes===[]){
			return null;
		}

		$first_failure=[
			'status'=>401,
			'message'=>'Authentication is required for this endpoint.',
			'headers'=>[],
			'scheme'=>null,
		];

		foreach($requirements as $requirement){
			if(!is_array($requirement) || $requirement===[]){
				continue;
			}
			$passed=true;
			$authorized_payload=null;
			foreach($requirement as $scheme_name=>$scopes){
				$scheme=$schemes[$scheme_name] ?? null;
				if(!is_array($scheme)){
					$passed=false;
					$first_failure['scheme']=$scheme_name;
					break;
				}
				$result=$this->authorizeScheme($scheme_name, $scheme, $request, $route, is_array($scopes) ? $scopes : []);
				if(($result['authorized'] ?? false)===true){
					$authorized_payload=$this->successfulAuthorizationPayload($scheme_name, is_array($scopes) ? $scopes : [], $result);
					continue;
				}
				$passed=false;
				$first_failure=array_replace($first_failure, $result);
				break;
			}
			if($passed===true){
				if($authorized_payload!==null){
					$request->setAttribute(self::AUTH_ATTRIBUTE, $authorized_payload);
				}
				return null;
			}
		}

		if(isset($first_failure['response']) && $first_failure['response'] instanceof Response){
			return $first_failure['response'];
		}

		return Response::json([
			'ok'=>false,
			'error'=>$first_failure['message'] ?? 'Authentication failed.',
			'scheme'=>$first_failure['scheme'] ?? null,
		], (int)($first_failure['status'] ?? 401), is_array($first_failure['headers'] ?? null) ? $first_failure['headers'] : []);
	}

	public function executeCompiledRoute(array $route, Request $request): ?Response {
		$api=$route['api'] ?? null;
		$execution=$api['execution'] ?? null;
		if(!is_array($api) || !is_array($execution)){
			return null;
		}

		$context=new ApiContext($request, $route);
		$trace_options=$this->normalizeTraceOptions($api['trace'] ?? []);
		$trace_context=$trace_options['enabled']===true
			? $this->createApiTraceContext($route, $request, $trace_options)
			: [];
		$lifecycle=$this->normalizeLifecycle($api['lifecycle'] ?? []);
		$bindings=is_array($api['bindings'] ?? null) ? $api['bindings'] : [];
		$endpoint_cache=[
			'enabled'=>false,
			'cacheable'=>false,
			'state'=>'bypass',
			'layer'=>'none',
		];
		$started_at=microtime(true);
		$validation_result=null;
		$result=null;

		$schema=$api['schema'] ?? null;
		if(is_array($schema)){
			$validation_result=$this->applyRouteSchema($context, $schema);
			if($validation_result->failed()){
				$result=$this->validationFailureResponse($validation_result, $schema);
			}
		}

		if($result===null){
			$endpoint_cache=$this->endpointCacheDescriptor(
				$route,
				$request,
				$context,
				$bindings,
				$trace_context,
				is_array($api['cache'] ?? null) ? $api['cache'] : null
			);
		}

		if($result===null){
			$cached=$this->loadCachedEndpointResponse($endpoint_cache);
			if(($cached['hit'] ?? false)===true && $cached['response'] instanceof Response){
				$cache_trace=array_replace($endpoint_cache, [
					'state'=>'hit',
					'layer'=>'persistent',
					'stored_at'=>$cached['stored_at'] ?? null,
				]);
				$trace_payload=$trace_options['enabled']===true
					? $this->buildApiTracePayload(
						$trace_context,
						$route,
						$request,
						$context,
						$validation_result,
						[],
						$started_at,
						$trace_options,
						$cache_trace
					)
					: null;
				return $this->applyTraceToResponse($cached['response'], $trace_payload, $trace_options);
			}
			if($bindings!==[]){
				$this->resolveRouteBindings($context, $bindings, $trace_context);
			}
			$before_response=$this->runLifecycleHooks('before', $lifecycle, $context, $request, $route);
			if($before_response instanceof Response){
				$result=$before_response;
			}
		}

		try{
			if($result===null){
				$result=$this->executeWithTraceContext(
					$trace_context,
					fn(): mixed => $this->invokeExecutionTarget($execution, $context, $request, $route),
					$trace_options
				);
			}
		}catch(\Throwable $exception){
			$error_response=$this->runLifecycleHooks('error', $lifecycle, $context, $request, $route, $exception);
			if($error_response instanceof Response){
				$result=$error_response;
			}elseif($exception instanceof \Dataphyre\Sanitation\SanitizationException){
				$result=$this->validationFailureResponse($exception->result(), [
					'options'=>[
						'status'=>422,
						'message'=>$exception->getMessage(),
					],
				]);
				$validation_result=$exception->result();
			}else{
				throw $exception;
			}
		}

		$cache_trace=$this->provisionalEndpointCacheTrace($endpoint_cache);
		$trace_payload=$trace_options['enabled']===true
			? $this->buildApiTracePayload(
				$trace_context,
				$route,
				$request,
				$context,
				$validation_result,
				$context->bindingTrace(),
				$started_at,
				$trace_options,
				$cache_trace
			)
			: null;
		$response=$this->normalizeExecutionResponse($result, null, $trace_options);
		$after_response=$this->runLifecycleHooks('after', $lifecycle, $context, $request, $route, $result, $response, $trace_payload);
		$final_response=$after_response instanceof Response ? $after_response : $response;
		$cache_trace=$this->storeEndpointCacheResponse($endpoint_cache, $final_response, $trace_options);
		$trace_payload=$trace_options['enabled']===true
			? $this->buildApiTracePayload(
				$trace_context,
				$route,
				$request,
				$context,
				$validation_result,
				$context->bindingTrace(),
				$started_at,
				$trace_options,
				$cache_trace
			)
			: null;
		return $this->applyTraceToResponse($final_response, $trace_payload, $trace_options);
	}

	private function normalizeInternalRequestDefinition(array $request_definition): array {
		$path=trim((string)($request_definition['path'] ?? $request_definition['uri'] ?? ''));
		$alias=$this->normalizeAlias((string)($request_definition['alias'] ?? $request_definition['endpoint'] ?? ''));
		$query=is_array($request_definition['query'] ?? null)
			? $request_definition['query']
			: (is_array($request_definition['get'] ?? null) ? $request_definition['get'] : []);
		$body=is_array($request_definition['body'] ?? null)
			? $request_definition['body']
			: (is_array($request_definition['post'] ?? null) ? $request_definition['post'] : []);
		$method=$this->inferInternalDispatchMethod($request_definition['method'] ?? null, $alias, $body);
		$key=isset($request_definition['key']) && is_string($request_definition['key'])
			? trim($request_definition['key'])
			: '';
		if($path==='' && $alias===''){
			throw new \RuntimeException('API batch request is missing a path or alias.');
		}
		$route_parameters=is_array($request_definition['route'] ?? null)
			? $request_definition['route']
			: (is_array($request_definition['parameters'] ?? null) ? $request_definition['parameters'] : []);
		$resolved_path=$path!=='' ? self::normalizePath($path) : null;
		$resolved_profile=$this->normalizeProfileName($request_definition['profile'] ?? null);
		return [
			'key'=>$key!=='' ? $key : strtoupper($method).' '.($resolved_path ?? '@'.$alias),
			'method'=>$method,
			'path'=>$resolved_path,
			'alias'=>$alias!=='' ? $alias : null,
			'profile'=>$resolved_profile,
			'query'=>$query,
			'body'=>$body,
			'route_parameters'=>$route_parameters,
			'headers'=>is_array($request_definition['headers'] ?? null) ? $request_definition['headers'] : [],
			'cookies'=>is_array($request_definition['cookies'] ?? null) ? $request_definition['cookies'] : [],
			'server'=>is_array($request_definition['server'] ?? null) ? $request_definition['server'] : [],
			'attributes'=>is_array($request_definition['attributes'] ?? null) ? $request_definition['attributes'] : [],
		];
	}

	private function resolveManifestDispatch(array $routes, array &$definition): array {
		if(is_string($definition['path'] ?? null) && trim((string)$definition['path'])!==''){
			$route=$this->matchManifestRoute($routes, $definition['method'], (string)$definition['path']);
			return $route!==null
				? ['route'=>$route]
				: ['status'=>404, 'message'=>'API route not found.'];
		}

		$alias=$this->normalizeAlias((string)($definition['alias'] ?? ''));
		if($alias===''){
			return ['status'=>422, 'message'=>'API batch request is missing a path or alias.'];
		}

		$matches=$this->matchManifestRoutesByAlias($routes, $definition['method'], $alias, $definition['profile'] ?? null);
		if($matches===[]){
			return ['status'=>404, 'message'=>'API alias not found.'];
		}
		if(count($matches)>1){
			return ['status'=>409, 'message'=>'API alias matched multiple endpoints.'];
		}

		$route=$matches[0];
		$route_parameters=$this->resolveAliasRouteParameters($route, $definition);
		if($route_parameters===null){
			return ['status'=>422, 'message'=>'API alias dispatch is missing required route parameters.'];
		}

		$resolved_path=$this->interpolateRoutePathTemplate(
			(string)($route['path_template'] ?? $route['exact_path'] ?? ($route['api']['path'] ?? '/')),
			$route_parameters
		);
		if($resolved_path===null){
			return ['status'=>422, 'message'=>'API alias dispatch could not build a route path.'];
		}

		$route['parameters']=$route_parameters;
		$definition['path']=$resolved_path;
		return ['route'=>$route];
	}

	private function internalRequestForRoute(array $route, array $definition, array $options): Request {
		$base_request=$options['base_request'] ?? null;
		$headers=$base_request instanceof Request && ($options['inherit_headers'] ?? true)===true
			? $base_request->headers()
			: [];
		$cookies=$base_request instanceof Request && ($options['inherit_cookies'] ?? true)===true
			? $base_request->cookie()
			: [];
		$server=$base_request instanceof Request && ($options['inherit_server'] ?? true)===true
			? $base_request->server()
			: [];
		$headers=array_replace($headers, $definition['headers']);
		$cookies=array_replace($cookies, $definition['cookies']);
		$server=array_replace($server, $definition['server'], [
			'REQUEST_METHOD'=>$definition['method'],
			'REQUEST_URI'=>$definition['path'],
		]);
		$attributes=$definition['attributes'];
		if(($options['trust_auth'] ?? false)===true && is_array($options['auth'] ?? null)){
			$attributes[self::AUTH_ATTRIBUTE]=$options['auth'];
		}
		return Request::create(
			$definition['method'],
			$definition['path'],
			$definition['query'],
			$definition['body'],
			$cookies,
			$server,
			$headers,
			$route['parameters'] ?? [],
			$attributes
		);
	}

	private function matchManifestRoute(array $routes, string $method, string $path): ?array {
		$path=self::normalizePath($path);
		foreach($routes as $route){
			if(!is_array($route)){
				continue;
			}
			if($this->routeMatchesMethod($route, $method)===false){
				continue;
			}
			if(isset($route['exact_path']) && $route['exact_path']===$path){
				$route['parameters']=[];
				return $route;
			}
			if(empty($route['path_regex'])){
				continue;
			}
			if(@preg_match($route['path_regex'], $path, $matches)!==1){
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
				$parameters[$parameter_name]=$this->explodeSplatParameter((string)($parameters[$parameter_name] ?? ''));
			}
			$route['parameters']=$parameters;
			return $route;
		}
		return null;
	}

	private function matchManifestRoutesByAlias(array $routes, string $method, string $alias, ?string $profile_name=null): array {
		$alias=$this->normalizeAlias($alias);
		$profile_name=$this->normalizeProfileName($profile_name);
		$matches=[];
		foreach($routes as $route){
			if(!is_array($route) || $this->routeHasApiMetadata($route)===false){
				continue;
			}
			if($this->routeMatchesMethod($route, $method)===false){
				continue;
			}
			if(!in_array($alias, $this->routeAliases($route), true)){
				continue;
			}
			if($profile_name!==null && $this->routeProfileName($route)!==$profile_name){
				continue;
			}
			$route['parameters']=[];
			$matches[]=$route;
		}
		return $matches;
	}

	private function explodeSplatParameter(string $value): array {
		$value=trim($value, '/');
		if($value===''){
			return [];
		}
		return array_values(array_filter(explode('/', $value), static fn(string $segment): bool => $segment!==''));
	}

	private function routeHasApiMetadata(array $route): bool {
		return is_array($route['api'] ?? null);
	}

	private function routeMatchesMethod(array $route, string $method): bool {
		$methods=array_map(static fn(mixed $value): string => strtoupper((string)$value), $route['methods'] ?? ['GET']);
		$method=strtoupper(trim($method));
		return in_array($method, $methods, true) || in_array('ANY', $methods, true);
	}

	private function routeAliases(array $route): array {
		$aliases=is_array($route['api']['aliases'] ?? null) ? $route['api']['aliases'] : [];
		$normalized=[];
		foreach($aliases as $alias){
			$alias=$this->normalizeAlias((string)$alias);
			if($alias===''){
				continue;
			}
			$normalized[$alias]=$alias;
		}
		return array_values($normalized);
	}

	private function routeProfileName(array $route): ?string {
		return $this->normalizeProfileName($route['api']['profile']['name'] ?? null);
	}

	private function resolveAliasRouteParameters(array $route, array $definition): ?array {
		$route_parameters=is_array($definition['route_parameters'] ?? null) ? $definition['route_parameters'] : [];
		$path_template=(string)($route['path_template'] ?? $route['exact_path'] ?? ($route['api']['path'] ?? '/'));
		if(preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path_template, $matches)!==1){
			return $route_parameters;
		}
		foreach($matches[1] as $parameter_name){
			if(array_key_exists($parameter_name, $route_parameters)){
				continue;
			}
			if(array_key_exists($parameter_name, $definition['query'] ?? [])){
				$route_parameters[$parameter_name]=$definition['query'][$parameter_name];
				continue;
			}
			if(array_key_exists($parameter_name, $definition['body'] ?? [])){
				$route_parameters[$parameter_name]=$definition['body'][$parameter_name];
				continue;
			}
			return null;
		}
		return $route_parameters;
	}

	private function interpolateRoutePathTemplate(string $path_template, array $parameters): ?string {
		$path_template=self::normalizePath($path_template);
		if(preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path_template, $matches)!==1){
			return $path_template;
		}
		foreach($matches[1] as $parameter_name){
			if(array_key_exists($parameter_name, $parameters)===false){
				return null;
			}
			$path_template=str_replace('{'.$parameter_name.'}', $this->stringifyRouteParameterValue($parameters[$parameter_name]), $path_template);
		}
		return self::normalizePath($path_template);
	}

	private function stringifyRouteParameterValue(mixed $value): string {
		if(is_array($value)){
			return implode('/', array_map(fn(mixed $segment): string => rawurlencode((string)$segment), $value));
		}
		return rawurlencode((string)$value);
	}

	private function dispatchMatchedRoute(array $route, Request $request): Response {
		if(is_array($route['middleware'] ?? null) && ($route['middleware'] ?? [])!==[]){
			return Response::json([
				'ok'=>false,
				'error'=>'Internal API dispatch does not support route middleware yet.',
			], 501);
		}
		if($this->routeHasApiMetadata($route) && is_array($route['api']['execution'] ?? null)){
			$response=$this->executeCompiledRoute($route, $request);
			if($response instanceof Response){
				return $response;
			}
		}

		$handler=$route['handler'] ?? null;
		if(is_array($handler) && ($handler['type'] ?? null)==='controller'){
			$result=$this->invokeInternalController($handler, $route, $request);
			return $this->normalizeExecutionResponse($result, null, $this->normalizeTraceOptions([]));
		}
		if(is_array($handler) && ($handler['type'] ?? null)==='callable' && isset($handler['target']) && is_callable($handler['target'])){
			$this->bootstrapCompiledHandler($handler);
			$result=($handler['target'])($route['parameters'] ?? [], $route);
			return $this->normalizeExecutionResponse($result, null, $this->normalizeTraceOptions([]));
		}
		if(is_callable($handler)){
			$result=$handler($route['parameters'] ?? [], $route);
			return $this->normalizeExecutionResponse($result, null, $this->normalizeTraceOptions([]));
		}

		return Response::json([
			'ok'=>false,
			'error'=>'Internal API dispatch supports execution targets and controller-backed handlers only.',
		], 501);
	}

	private function invokeInternalController(array $handler, array $route, Request $request): mixed {
		$this->loadFrameworkModule('core');
		$this->bootstrapCompiledHandler($handler);
		$class=trim((string)($handler['class'] ?? ''), '\\');
		$method=trim((string)($handler['method'] ?? ''));
		if($class==='' || $method===''){
			throw new \RuntimeException('Compiled controller handler is missing class or method.');
		}
		$modules=$this->inferFrameworkModulesForClass($class);
		array_unshift($modules, 'http');
		$this->loadFrameworkModules(array_values(array_unique($modules)));
		return ($handler['static'] ?? true)===true
			? $class::$method($request, $route)
			: (new $class())->$method($request, $route);
	}

	private function bootstrapCompiledHandler(mixed $handler): void {
		if(!is_array($handler)){
			return;
		}
		$bootstrap=$handler['bootstrap'] ?? null;
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

	private function inferFrameworkModulesForClass(string $class): array {
		$class=trim($class, '\\');
		return match (true) {
			str_starts_with($class, 'Dataphyre\\Access\\') => ['access'],
			str_starts_with($class, 'Dataphyre\\Api\\') => ['api'],
			str_starts_with($class, 'Dataphyre\\Currency\\') => ['currency'],
			str_starts_with($class, 'Dataphyre\\Database\\') => ['sql'],
			str_starts_with($class, 'Dataphyre\\FulltextEngine\\') => ['fulltext_engine'],
			str_starts_with($class, 'Dataphyre\\Http\\') => ['http'],
			str_starts_with($class, 'Dataphyre\\Routing\\') => ['routing'],
			str_starts_with($class, 'Dataphyre\\Sanitation\\') => ['sanitation'],
			default => [],
		};
	}

	private function normalizeDispatchedResponse(array $definition, array $route, Response $response, float $started_at): array {
		$decoded=$this->decodeJsonResponse($response);
		$api=$route['api'] ?? [];
		return [
			'key'=>$definition['key'],
			'ok'=>$response->status < 400,
			'request'=>array_filter([
				'method'=>$definition['method'],
				'path'=>$definition['path'] ?? null,
				'alias'=>$definition['alias'] ?? null,
				'profile'=>$definition['profile'] ?? null,
			], static fn(mixed $value): bool => $value!==null && $value!=='' && $value!==[]),
			'endpoint'=>array_filter([
				'path'=>$api['path'] ?? ($route['path_template'] ?? $route['exact_path'] ?? $definition['path']),
				'aliases'=>$api['aliases'] ?? [],
				'operation_id'=>$api['operation_id'] ?? null,
				'profile'=>$this->routeProfileName($route),
			], static fn(mixed $value): bool => $value!==null && $value!=='' && $value!==[]),
			'status'=>$response->status,
			'headers'=>$response->headers,
			'body'=>$response->body,
			'json'=>$decoded,
			'duration_ms'=>round((microtime(true)-$started_at)*1000, 3),
		];
	}

	private function dispatchFailureRecord(array $definition, int $status, string $message, float $started_at): array {
		$response=Response::json([
			'ok'=>false,
			'error'=>$message,
		], $status);
		return [
			'key'=>$definition['key'] ?? null,
			'ok'=>false,
			'request'=>array_filter([
				'method'=>$definition['method'] ?? 'GET',
				'path'=>$definition['path'] ?? null,
				'alias'=>$definition['alias'] ?? null,
				'profile'=>$definition['profile'] ?? null,
			], static fn(mixed $value): bool => $value!==null && $value!=='' && $value!==[]),
			'status'=>$status,
			'headers'=>$response->headers,
			'body'=>$response->body,
			'json'=>$this->decodeJsonResponse($response),
			'duration_ms'=>round((microtime(true)-$started_at)*1000, 3),
		];
	}

	private function decodeJsonResponse(Response $response): mixed {
		foreach($response->headers as $name=>$value){
			if(strtolower((string)$name)!=='content-type'){
				continue;
			}
			if(stripos((string)$value, 'application/json')===false){
				return null;
			}
			$decoded=json_decode($response->body, true);
			return json_last_error()===JSON_ERROR_NONE ? $decoded : null;
		}
		return null;
	}

	private function authorizeScheme(string $scheme_name, array $scheme, Request $request, array $route, array $scopes): array {
		$runtime=$scheme['runtime'] ?? [];
		$type=strtolower(trim((string)($runtime['type'] ?? 'docs_only')));
		return match ($type) {
			'guard' => $this->authorizeGuardScheme($scheme_name, $runtime),
			'bearer' => $this->authorizeBearerScheme($scheme_name, $runtime, $request, $route, $scopes),
			'basic' => $this->authorizeBasicScheme($scheme_name, $runtime, $request, $route, $scopes),
			'api_key' => $this->authorizeApiKeyScheme($scheme_name, $runtime, $request, $route, $scopes),
			'callback' => $this->authorizeCallbackScheme($scheme_name, $runtime, $request, $route, $scopes),
			default => $this->failure($scheme_name, $runtime, $runtime['failure_message'] ?? 'Authentication is required for this endpoint.'),
		};
	}

	private function successfulAuthorizationPayload(string $scheme_name, array $scopes, array $result): array {
		return array_filter([
			'authorized'=>true,
			'scheme'=>$scheme_name,
			'guard'=>isset($result['guard']) && is_string($result['guard']) ? trim($result['guard']) : null,
			'scopes'=>$scopes,
			'identity'=>$result['identity'] ?? ($result['principal'] ?? null),
			'context'=>is_array($result['context'] ?? null) ? $result['context'] : [],
			'meta'=>is_array($result['meta'] ?? null) ? $result['meta'] : [],
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	private function applyRouteSchema(ApiContext $context, array $schema): \Dataphyre\Sanitation\SanitizationResult {
		$rules=is_array($schema['rules'] ?? null) ? $schema['rules'] : [];
		$defaults=is_array($schema['defaults'] ?? null) ? $schema['defaults'] : [];
		$options=is_array($schema['options'] ?? null) ? $schema['options'] : [];
		return $context->validate($rules, $defaults, $options);
	}

	private function validationFailureResponse(\Dataphyre\Sanitation\SanitizationResult $result, array $schema): Response {
		$options=is_array($schema['options'] ?? null) ? $schema['options'] : [];
		$status=(int)($options['status'] ?? 422);
		$message=trim((string)($options['message'] ?? 'Request validation failed.'));
		$headers=is_array($options['headers'] ?? null) ? $options['headers'] : [];
		return Response::json([
			'ok'=>false,
			'error'=>$message!=='' ? $message : 'Request validation failed.',
			'errors'=>$result->errors(),
		], $status > 0 ? $status : 422, $headers);
	}

	private function invokeExecutionTarget(array $execution, ApiContext $context, Request $request, array $route): mixed {
		$this->bootstrapExecutionTarget($execution);
		$this->loadFrameworkModulesForExecutionTarget($execution);
		$callable=$this->resolveExecutionCallable($execution);
		if($callable===null){
			throw new \RuntimeException('API execute target is invalid or unavailable.');
		}
		return $this->invokeCallable($callable, $context, $request, $route);
	}

	private function normalizeLifecycle(mixed $lifecycle): array {
		if(!is_array($lifecycle)){
			return [];
		}
		$normalized=[];
		foreach(['before', 'after', 'error'] as $phase){
			$hooks=[];
			foreach(($lifecycle[$phase] ?? []) as $hook){
				if(is_array($hook)){
					$hooks[]=$hook;
				}
			}
			if($hooks!==[]){
				$normalized[$phase]=$hooks;
			}
		}
		return $normalized;
	}

	private function runLifecycleHooks(string $phase, array $lifecycle, ApiContext $context, Request $request, array $route, mixed ...$extra): ?Response {
		$hooks=$lifecycle[$phase] ?? [];
		if(!is_array($hooks) || $hooks===[]){
			return null;
		}
		foreach($hooks as $hook){
			if(!is_array($hook)){
				continue;
			}
			$result=$this->invokeLifecycleHook($phase, $hook, $context, $request, $route, ...$extra);
			if($result instanceof Response){
				return $result;
			}
		}
		return null;
	}

	private function invokeLifecycleHook(string $phase, array $hook, ApiContext $context, Request $request, array $route, mixed ...$extra): mixed {
		$this->bootstrapExecutionTarget($hook);
		$this->loadFrameworkModulesForExecutionTarget($hook);
		$callable=$this->resolveExecutionCallable($hook);
		if($callable===null){
			throw new \RuntimeException('API lifecycle target is invalid or unavailable.');
		}
		$args=match ($phase) {
			'after' => array_merge([$context], $extra, [$request, $route]),
			'error' => array_merge([$context], $extra, [$request, $route]),
			default => [$context, $request, $route],
		};
		return $this->invokeCallableWithArgs($callable, $args);
	}

	private function resolveExecutionCallable(array $execution): ?callable {
		$type=strtolower(trim((string)($execution['type'] ?? '')));
		if($type==='class_method'){
			$class=trim((string)($execution['class'] ?? ''), '\\');
			$method=trim((string)($execution['method'] ?? ''));
			if($class==='' || $method===''){
				return null;
			}
			if(($execution['static'] ?? true)===false){
				if(class_exists($class)===false){
					return null;
				}
				return [new $class(), $method];
			}
			return [$class, $method];
		}
		if($type==='callable'){
			$reference=trim((string)($execution['reference'] ?? ''));
			if($reference===''){
				return null;
			}
			return is_callable($reference) ? $reference : null;
		}
		return null;
	}

	private function invokeCallable(callable $callable, ApiContext $context, Request $request, array $route, mixed ...$extra): mixed {
		$args=array_merge([$context, $request, $route], $extra);
		return $this->invokeCallableWithArgs($callable, $args);
	}

	private function invokeCallableWithArgs(callable $callable, array $args): mixed {
		if(is_array($callable)){
			$reflection=new \ReflectionMethod($callable[0], $callable[1]);
		}else{
			$reflection=new \ReflectionFunction(\Closure::fromCallable($callable));
		}
		if($reflection->isVariadic()){
			return $callable(...$args);
		}
		$arity=$reflection->getNumberOfParameters();
		return $callable(...array_slice($args, 0, $arity));
	}

	private function executeWithTraceContext(array $trace_context, callable $callback, array $trace_options): mixed {
		if($this->tracingEnabled()!==true){
			return $callback();
		}
		if($trace_context===[]){
			return $callback();
		}
		if(($trace_options['include_sql'] ?? true)===true){
			$this->loadFrameworkModule('sql');
		}
		if(class_exists('Dataphyre\\Database\\DB')===false){
			return $callback();
		}
		return \Dataphyre\Database\DB::withTraceContext($trace_context, $callback);
	}

	private function buildApiTracePayload(
		array $trace_context,
		array $route,
		Request $request,
		ApiContext $context,
		?\Dataphyre\Sanitation\SanitizationResult $validation_result,
		array $binding_trace,
		float $started_at,
		array $trace_options,
		array $cache_trace=[]
	): array {
		$api=$route['api'] ?? [];
		$payload=[
			'api_trace_id'=>$trace_context['api_trace_id'] ?? null,
			'endpoint'=>array_filter([
				'path'=>$api['path'] ?? ($route['path_template'] ?? $request->path()),
				'method'=>$request->method(),
				'operation_id'=>$api['operation_id'] ?? null,
			], static fn(mixed $value): bool => $value!==null && $value!==''),
			'duration_ms'=>round((microtime(true)-$started_at)*1000, 3),
		];
		if($validation_result instanceof \Dataphyre\Sanitation\SanitizationResult){
			$payload['validation']=[
				'passed'=>$validation_result->passed(),
				'errors'=>$validation_result->errors(),
			];
		}
		if(($trace_options['include_bindings'] ?? true)===true && $binding_trace!==[]){
			$payload['bindings']=$binding_trace;
		}
		if(($trace_options['include_auth'] ?? true)===true && $context->hasAuth()){
			$payload['auth']=$this->authTracePayload($context);
		}
		if(($trace_options['include_sql'] ?? true)===true){
			$payload['sql']=$this->recentSqlTracePayload($trace_context, (int)($trace_options['sql_limit'] ?? 50));
		}
		if($cache_trace!==[]){
			$payload['cache']=$this->apiEndpointCacheTracePayload($cache_trace);
		}
		return array_filter($payload, static fn(mixed $value): bool => $value!==null);
	}

	private function recentSqlTracePayload(array $trace_context, int $limit): array {
		if($this->tracingEnabled()!==true){
			return [];
		}
		if(($trace_context['api_trace_id'] ?? null)===null){
			return [];
		}
		$this->loadFrameworkModule('sql');
		if(class_exists('Dataphyre\\Database\\DB')===false){
			return [];
		}
		return array_map(
			static fn(\Dataphyre\Database\ExecutionTrace $trace): array => $trace->toArray(),
			\Dataphyre\Database\DB::recentTracesByContext(['api_trace_id'=>$trace_context['api_trace_id']], max(1, $limit))
		);
	}

	private function createApiTraceContext(array $route, Request $request, array $trace_options): array {
		$api=$route['api'] ?? [];
		return array_filter([
			'api_trace_id'=>$this->newTraceId(),
			'api_endpoint'=>$api['path'] ?? ($route['path_template'] ?? $request->path()),
			'api_operation_id'=>$api['operation_id'] ?? null,
			'api_method'=>$request->method(),
			'api_trace_mode'=>'endpoint',
		], static fn(mixed $value): bool => $value!==null && $value!=='');
	}

	private function normalizeTraceOptions(mixed $trace): array {
		if($this->tracingEnabled()!==true){
			$response_key='trace';
			$header='X-Dataphyre-Api-Trace';
			if(is_array($trace)){
				$response_key=trim((string)($trace['response_key'] ?? $response_key)) ?: 'trace';
				$header=trim((string)($trace['header'] ?? $header)) ?: 'X-Dataphyre-Api-Trace';
			}
			return [
				'enabled'=>false,
				'include_bindings'=>false,
				'include_auth'=>false,
				'include_sql'=>false,
				'sql_limit'=>0,
				'response_key'=>$response_key,
				'header'=>$header,
			];
		}
		if($trace===true){
			$trace=['enabled'=>true];
		}elseif(is_array($trace)===false){
			$trace=['enabled'=>false];
		}
		$enabled=($trace['enabled'] ?? true)===true;
		return [
			'enabled'=>$enabled,
			'include_bindings'=>($trace['include_bindings'] ?? true)===true,
			'include_auth'=>($trace['include_auth'] ?? true)===true,
			'include_sql'=>($trace['include_sql'] ?? true)===true,
			'sql_limit'=>max(1, (int)($trace['sql_limit'] ?? 50)),
			'response_key'=>trim((string)($trace['response_key'] ?? 'trace')) ?: 'trace',
			'header'=>trim((string)($trace['header'] ?? 'X-Dataphyre-Api-Trace')) ?: 'X-Dataphyre-Api-Trace',
		];
	}

	private function endpointCacheDescriptor(
		array $route,
		Request $request,
		ApiContext $context,
		array $bindings,
		array $trace_context,
		?array $cache_definition
	): array {
		if($cache_definition===null){
			return [
				'enabled'=>false,
				'cacheable'=>false,
				'state'=>'bypass',
				'layer'=>'none',
			];
		}

		$allow_untracked_bindings=($cache_definition['allow_untracked_bindings'] ?? false)===true;
		$binding_identities=[];
		$binding_query_cache_names=[];
		$binding_sequence=0;
		$binding_reason=null;

		foreach($bindings as $binding_entry){
			$path=trim((string)($binding_entry['path'] ?? ''));
			$definition=is_array($binding_entry['definition'] ?? null) ? $binding_entry['definition'] : null;
			if($path==='' || $definition===null){
				continue;
			}
			$binding_context=$this->bindingContextForApi($context, [], $path, $trace_context, ++$binding_sequence);
			$binding=$this->bindingFromDefinition($path, $definition);
			$metadata=$binding instanceof \Dataphyre\Templating\BindingMetadataProvider
				? $binding->metadata()
				: [];
			$binding_query_cache_names=array_merge(
				$binding_query_cache_names,
				$this->normalizeEndpointCacheNames($metadata['query_cache_names'] ?? [])
			);
			if(!$binding instanceof \Dataphyre\Templating\BindingCacheIdentityProvider){
				if($allow_untracked_bindings!==true){
					$binding_reason="Binding '{$path}' does not expose cache identity.";
					break;
				}
				continue;
			}
			$identity=$this->normalizeBindingCacheIdentity($binding->cacheIdentity($binding_context));
			if($identity===null){
				if($allow_untracked_bindings!==true){
					$binding_reason="Binding '{$path}' does not expose cache identity.";
					break;
				}
				continue;
			}
			$binding_identities[$path]=$identity;
		}

		if($binding_reason!==null){
			return [
				'enabled'=>true,
				'cacheable'=>false,
				'state'=>'bypass',
				'layer'=>'none',
				'reason'=>$binding_reason,
				'names'=>$this->normalizeEndpointCacheNames(array_merge(
					$this->normalizeEndpointCacheNames($cache_definition['names'] ?? []),
					$binding_query_cache_names
				)),
			];
		}

		$vary_headers=$this->selectedRequestValues($request->headers(), $cache_definition['vary_headers'] ?? []);
		$vary_cookies=$this->selectedRequestValues($request->cookie(), $cache_definition['vary_cookies'] ?? []);
		$extra_identity=$this->normalizeEndpointCacheIdentityValue($cache_definition['identity'] ?? null);
		$identity=array_filter([
			'endpoint'=>array_filter([
				'path'=>$route['api']['path'] ?? ($route['path_template'] ?? $request->path()),
				'operation_id'=>$route['api']['operation_id'] ?? null,
				'method'=>$request->method(),
				'profile'=>$this->routeProfileName($route),
			], static fn(mixed $value): bool => $value!==null && $value!==''),
			'request'=>array_filter([
				'path'=>$request->path(),
				'query'=>$this->normalizeEndpointCacheIdentityValue($request->query()),
				'body'=>$this->normalizeEndpointCacheIdentityValue($request->input()),
				'route'=>$this->normalizeEndpointCacheIdentityValue($request->route_parameters()),
				'headers'=>$vary_headers!==[] ? $this->normalizeEndpointCacheIdentityValue($vary_headers) : null,
				'cookies'=>$vary_cookies!==[] ? $this->normalizeEndpointCacheIdentityValue($vary_cookies) : null,
			], static fn(mixed $value): bool => $value!==null && $value!==[]),
			'auth'=>$context->hasAuth() ? $this->normalizeEndpointCacheIdentityValue($context->auth()) : null,
			'bindings'=>$binding_identities!==[] ? $binding_identities : null,
			'identity'=>$extra_identity,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);

		if($identity===[]){
			return [
				'enabled'=>true,
				'cacheable'=>false,
				'state'=>'bypass',
				'layer'=>'none',
				'reason'=>'Endpoint cache identity is empty.',
				'names'=>$this->normalizeEndpointCacheNames(array_merge(
					$this->normalizeEndpointCacheNames($cache_definition['names'] ?? []),
					$binding_query_cache_names
				)),
			];
		}

		$names=$this->normalizeEndpointCacheNames(array_merge(
			$this->normalizeEndpointCacheNames($cache_definition['names'] ?? []),
			(($cache_definition['inherit_binding_cache_names'] ?? true)===true ? $binding_query_cache_names : [])
		));
		$ttl=max(1, (int)($cache_definition['ttl'] ?? 300));
		return [
			'enabled'=>true,
			'cacheable'=>true,
			'state'=>'miss',
			'layer'=>'persistent',
			'key'=>sha1(json_encode($identity)),
			'identity'=>$identity,
			'ttl'=>$ttl,
			'names'=>$names,
			'store_errors'=>($cache_definition['store_errors'] ?? false)===true,
			'source_names'=>$binding_query_cache_names,
		];
	}

	private function provisionalEndpointCacheTrace(array $descriptor): array {
		if(($descriptor['enabled'] ?? false)!==true){
			return [];
		}
		if(($descriptor['cacheable'] ?? false)!==true){
			return array_replace($descriptor, [
				'state'=>'bypass',
				'layer'=>'none',
			]);
		}
		return array_replace($descriptor, [
			'state'=>'miss',
			'layer'=>'persistent',
		]);
	}

	private function loadCachedEndpointResponse(array $descriptor): array {
		if(($descriptor['cacheable'] ?? false)!==true){
			return ['hit'=>false];
		}
		$file=$this->endpointCacheItemFile((string)($descriptor['key'] ?? ''));
		if(!is_file($file)){
			return ['hit'=>false];
		}
		$payload=@file_get_contents($file);
		if(!is_string($payload) || $payload===''){
			return ['hit'=>false];
		}
		try{
			$decoded=@unserialize($payload);
		}catch(\Throwable){
			@unlink($file);
			return ['hit'=>false];
		}
		if(!is_array($decoded)){
			@unlink($file);
			return ['hit'=>false];
		}
		if((int)($decoded['expires_at'] ?? 0) < time()){
			@unlink($file);
			return ['hit'=>false];
		}
		$response_payload=is_array($decoded['response'] ?? null) ? $decoded['response'] : [];
		return [
			'hit'=>true,
			'stored_at'=>(int)($decoded['stored_at'] ?? 0),
			'response'=>new Response(
				(string)($response_payload['body'] ?? ''),
				(int)($response_payload['status'] ?? 200),
				is_array($response_payload['headers'] ?? null) ? $response_payload['headers'] : []
			),
		];
	}

	private function storeEndpointCacheResponse(array $descriptor, Response $response, array $trace_options): array {
		if(($descriptor['cacheable'] ?? false)!==true){
			return $this->provisionalEndpointCacheTrace($descriptor);
		}
		if($this->isEndpointResponseCacheable($response, $descriptor)===false){
			return array_replace($descriptor, [
				'state'=>'miss',
				'layer'=>'persistent',
				'reason'=>'Response status is not cacheable.',
			]);
		}

		$cache_response=$this->responseForEndpointCacheStorage($response, $trace_options);
		$root=$this->endpointCacheRoot();
		$items_dir=$root.'items'.DIRECTORY_SEPARATOR;
		$names_dir=$root.'names'.DIRECTORY_SEPARATOR;
		if(!is_dir($items_dir)){
			@mkdir($items_dir, 0777, true);
		}
		if(!is_dir($names_dir)){
			@mkdir($names_dir, 0777, true);
		}
		try{
			$payload=serialize([
				'stored_at'=>time(),
				'expires_at'=>time()+max(1, (int)($descriptor['ttl'] ?? 300)),
				'names'=>$descriptor['names'] ?? [],
				'response'=>[
					'status'=>$cache_response->status,
					'headers'=>$cache_response->headers,
					'body'=>$cache_response->body,
				],
			]);
		}catch(\Throwable $exception){
			return array_replace($descriptor, [
				'state'=>'miss',
				'layer'=>'persistent',
				'reason'=>'Unable to serialize endpoint cache payload.',
				'store_error'=>$exception->getMessage(),
			]);
		}

		$file=$this->endpointCacheItemFile((string)($descriptor['key'] ?? ''));
		if(@file_put_contents($file, $payload, LOCK_EX)===false){
			return array_replace($descriptor, [
				'state'=>'miss',
				'layer'=>'persistent',
				'reason'=>'Unable to write endpoint cache.',
				'store_error'=>'Unable to write endpoint cache.',
			]);
		}
		foreach($descriptor['names'] ?? [] as $name){
			$this->indexEndpointCacheName($name, (string)($descriptor['key'] ?? ''));
		}
		return array_replace($descriptor, [
			'state'=>'store',
			'layer'=>'persistent',
			'stored_at'=>time(),
		]);
	}

	private function apiEndpointCacheTracePayload(array $cache_trace): array {
		return array_filter([
			'enabled'=>($cache_trace['enabled'] ?? false)===true,
			'cacheable'=>($cache_trace['cacheable'] ?? false)===true,
			'state'=>$this->traceString($cache_trace['state'] ?? null),
			'layer'=>$this->traceString($cache_trace['layer'] ?? null),
			'key'=>$this->traceString($cache_trace['key'] ?? null),
			'ttl'=>isset($cache_trace['ttl']) ? (int)$cache_trace['ttl'] : null,
			'names'=>$this->normalizeEndpointCacheNames($cache_trace['names'] ?? []),
			'source_names'=>$this->normalizeEndpointCacheNames($cache_trace['source_names'] ?? []),
			'reason'=>$this->traceString($cache_trace['reason'] ?? null),
			'stored_at'=>isset($cache_trace['stored_at']) ? (int)$cache_trace['stored_at'] : null,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	private function authTracePayload(ApiContext $context): array {
		return array_filter([
			'scheme'=>$context->authScheme(),
			'guard'=>$context->auth()['guard'] ?? null,
			'identity_type'=>$this->bindingResultType($context->authIdentity()),
			'scopes'=>$context->authScopes(),
			'context_keys'=>array_keys($context->authContext()),
			'meta_keys'=>array_keys($context->authMeta()),
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	private function normalizeExecutionResponse(mixed $result, ?array $trace_payload, array $trace_options): Response {
		$headers=$trace_payload!==null
			? [$trace_options['header']=>$trace_payload['api_trace_id'] ?? '']
			: [];
		if($result instanceof Response){
			return $this->applyTraceToResponse($result, $trace_payload, $trace_options);
		}
		if($result===null){
			$response=Response::no_content();
			return $this->applyTraceToResponse($response, $trace_payload, $trace_options);
		}
		if(is_array($result)){
			$payload=$trace_payload!==null
				? $this->injectTraceIntoPayload($result, $trace_payload, $trace_options['response_key'])
				: $result;
			return Response::json($payload, 200, $headers);
		}
		if($result instanceof \JsonSerializable){
			$payload=$trace_payload!==null
				? [
					'data'=>$result,
					$trace_options['response_key']=>$trace_payload,
				]
				: $result;
			return Response::json($payload, 200, $headers);
		}
		return Response::json(
			$trace_payload!==null
				? [
					'data'=>$result,
					$trace_options['response_key']=>$trace_payload,
				]
				: ['data'=>$result],
			200,
			$headers
		);
	}

	private function applyTraceToResponse(Response $response, ?array $trace_payload, array $trace_options): Response {
		if($trace_payload===null){
			return $response;
		}
		$headers=array_replace($response->headers, [
			$trace_options['header']=>$trace_payload['api_trace_id'] ?? '',
		]);
		if($this->isJsonResponse($response)===false || trim($response->body)===''){
			return new Response($response->body, $response->status, $headers);
		}
		$decoded=json_decode($response->body, true);
		if(is_array($decoded)===false){
			return new Response($response->body, $response->status, $headers);
		}
		if($this->isAssociativeArray($decoded)===false){
			return new Response($response->body, $response->status, $headers);
		}
		$payload=$this->injectTraceIntoPayload($decoded, $trace_payload, $trace_options['response_key']);
		$encoded=json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return new Response($encoded===false ? $response->body : $encoded, $response->status, $headers);
	}

	private function injectTraceIntoPayload(array $payload, array $trace_payload, string $response_key): array {
		if($this->isAssociativeArray($payload)===false){
			return [
				'data'=>$payload,
				$response_key=>$trace_payload,
			];
		}
		$key=array_key_exists($response_key, $payload) ? '_'.$response_key : $response_key;
		$payload[$key]=$trace_payload;
		return $payload;
	}

	private function responseForEndpointCacheStorage(Response $response, array $trace_options): Response {
		if(($trace_options['enabled'] ?? false)!==true){
			return $response;
		}
		$headers=$this->withoutHeaderCaseInsensitive($response->headers, (string)($trace_options['header'] ?? 'X-Dataphyre-Api-Trace'));
		if($this->isJsonResponse($response)===false || trim($response->body)===''){
			return new Response($response->body, $response->status, $headers);
		}
		$decoded=json_decode($response->body, true);
		if(is_array($decoded)===false || $this->isAssociativeArray($decoded)===false){
			return new Response($response->body, $response->status, $headers);
		}
		unset($decoded[(string)($trace_options['response_key'] ?? 'trace')], $decoded['_'.(string)($trace_options['response_key'] ?? 'trace')]);
		$encoded=json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return new Response($encoded===false ? $response->body : $encoded, $response->status, $headers);
	}

	private function isEndpointResponseCacheable(Response $response, array $descriptor): bool {
		if(($descriptor['store_errors'] ?? false)===true){
			return true;
		}
		return $response->status >= 200 && $response->status < 300;
	}

	private function isJsonResponse(Response $response): bool {
		foreach($response->headers as $name=>$value){
			if(strtolower((string)$name)!=='content-type'){
				continue;
			}
			return stripos((string)$value, 'application/json')!==false;
		}
		return false;
	}

	private function isAssociativeArray(array $payload): bool {
		return $payload!==[] && array_keys($payload)!==range(0, count($payload)-1);
	}

	private function selectedRequestValues(array $source, array|string|null $names): array {
		$names=$this->normalizeEndpointCacheNames($names ?? []);
		if($names===[]){
			return [];
		}
		$selected=[];
		foreach($names as $name){
			foreach($source as $source_name=>$value){
				if(strtolower((string)$source_name)!==strtolower($name)){
					continue;
				}
				$selected[$name]=$value;
				break;
			}
		}
		return $selected;
	}

	private function normalizeEndpointCacheNames(array|string|null $names): array {
		if($names===null){
			return [];
		}
		$names=is_array($names) ? $names : [$names];
		$normalized=[];
		foreach($names as $name){
			if(!is_string($name)){
				continue;
			}
			$name=trim($name);
			if($name===''){
				continue;
			}
			$normalized[$name]=$name;
		}
		return array_values($normalized);
	}

	private function normalizeEndpointCacheIdentityValue(mixed $value): mixed {
		if($value===null){
			return null;
		}
		return $this->normalizeBindingCacheIdentityValue($value);
	}

	private function endpointCacheRoot(): string {
		return rtrim(ROOTPATH['dataphyre'].'cache/api/endpoints/', '/\\').DIRECTORY_SEPARATOR;
	}

	private function endpointCacheItemFile(string $key): string {
		return $this->endpointCacheRoot().'items'.DIRECTORY_SEPARATOR.$key.'.cache';
	}

	private function endpointCacheNameFile(string $name, string $names_dir): string {
		return $names_dir.sha1($name).'.json';
	}

	private function indexEndpointCacheName(string $name, string $key): void {
		$root=$this->endpointCacheRoot();
		$names_dir=$root.'names'.DIRECTORY_SEPARATOR;
		if(!is_dir($names_dir)){
			@mkdir($names_dir, 0777, true);
		}
		$file=$this->endpointCacheNameFile($name, $names_dir);
		$existing=@file_get_contents($file);
		$keys=json_decode(is_string($existing) ? $existing : '[]', true);
		$keys=is_array($keys) ? $keys : [];
		$keys[]=$key;
		$keys=array_values(array_unique(array_filter($keys, static fn(mixed $value): bool => is_string($value) && $value!=='')));
		@file_put_contents($file, json_encode($keys, JSON_PRETTY_PRINT), LOCK_EX);
	}

	private function clearPersistentCacheDirectories(string $items_dir, string $names_dir): int {
		$deleted=0;
		foreach([$items_dir, $names_dir] as $dir){
			if(!is_dir($dir)){
				continue;
			}
			$files=glob($dir.'*');
			if(!is_array($files)){
				continue;
			}
			foreach($files as $file){
				if(is_file($file) && @unlink($file)){
					$deleted++;
				}
			}
			@rmdir($dir);
		}
		$root=dirname(rtrim($items_dir, '/\\')).DIRECTORY_SEPARATOR;
		@rmdir($root);
		return $deleted;
	}

	private function withoutHeaderCaseInsensitive(array $headers, string $target): array {
		$normalized=[];
		foreach($headers as $name=>$value){
			if(strtolower((string)$name)===strtolower($target)){
				continue;
			}
			$normalized[$name]=$value;
		}
		return $normalized;
	}

	private function bootstrapExecutionTarget(array $execution): void {
		$bootstrap=$execution['bootstrap'] ?? null;
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
		throw new \RuntimeException('API execute bootstrap target is invalid.');
	}

	private function loadFrameworkModulesForExecutionTarget(array $execution): void {
		$class=trim((string)($execution['class'] ?? ''), '\\');
		if($class===''){
			return;
		}
		$modules=match (true) {
			str_starts_with($class, 'Dataphyre\\Access\\') => ['access'],
			str_starts_with($class, 'Dataphyre\\Api\\') => ['api'],
			str_starts_with($class, 'Dataphyre\\Currency\\') => ['currency'],
			str_starts_with($class, 'Dataphyre\\Database\\') => ['sql'],
			str_starts_with($class, 'Dataphyre\\Http\\') => ['http'],
			str_starts_with($class, 'Dataphyre\\Routing\\') => ['routing'],
			str_starts_with($class, 'Dataphyre\\Sanitation\\') => ['sanitation'],
			default => [],
		};
		if($modules!==[]){
			$this->loadFrameworkModules($modules);
		}
	}

	private function loadFrameworkModule(string $module): void {
		if(class_exists('\dataphyre\core', false)){
			\dataphyre\core::load_framework_module($module);
		}
	}

	private function loadFrameworkModules(array $modules): void {
		if(class_exists('\dataphyre\core', false)){
			\dataphyre\core::load_framework_modules($modules);
		}
	}

	private function resolveRouteBindings(ApiContext $context, array $bindings, array $trace_context=[]): void {
		$this->loadFrameworkModule('templating');
		$resolved=[];
		$trace=[];
		$cache=[];
		$sequence=0;
		$tracing_enabled=$this->tracingEnabled()===true;

		foreach($bindings as $binding_entry){
			$path=trim((string)($binding_entry['path'] ?? ''));
			$definition=is_array($binding_entry['definition'] ?? null) ? $binding_entry['definition'] : null;
			if($path==='' || $definition===null){
				continue;
			}

			$binding_context=$this->bindingContextForApi($context, $resolved, $path, $trace_context, ++$sequence);
			$binding=$this->bindingFromDefinition($path, $definition);
			$metadata=$binding instanceof \Dataphyre\Templating\BindingMetadataProvider
				? $binding->metadata()
				: [];
			$identity=$binding instanceof \Dataphyre\Templating\BindingCacheIdentityProvider
				? $this->normalizeBindingCacheIdentity($binding->cacheIdentity($binding_context))
				: null;
			$cache_key=$identity!==null ? sha1(json_encode($identity)) : null;
			$started_at=microtime(true);
			$reused=false;
			$skipped=false;

			if($cache_key!==null && array_key_exists($cache_key, $cache)){
				$value=$cache[$cache_key];
				$reused=true;
			}else{
				$value=$this->resolveApiBindingWithTraceContext($binding, $binding_context, $metadata, $trace_context, $path);
				if($value instanceof \Dataphyre\Templating\BindingResolution){
					$skipped=$value->isSkipped();
					$value=$value->result();
				}
				if($cache_key!==null){
					$cache[$cache_key]=$value;
				}
			}

			$this->setArrayValueByPath($resolved, $path, $value);
			if($tracing_enabled){
				$trace[]=$this->apiBindingTraceRecord(
					$path,
					$binding,
					$binding_context,
					$metadata,
					$value,
					$identity,
					$cache_key,
					$reused,
					$skipped,
					microtime(true)-$started_at
				);
			}
		}

		$context->withBindings($resolved, $tracing_enabled ? $trace : []);
	}

	private function bindingFromDefinition(string $path, array $definition): \Dataphyre\Templating\DataBinding {
		$type=strtolower(trim((string)($definition['type'] ?? '')));
		return match ($type) {
			'callable' => $this->callableBindingFromDefinition($path, $definition),
			'sql_query' => $this->sqlBindingFromDefinition($path, $definition),
			'search_query' => $this->searchBindingFromDefinition($path, $definition),
			default => throw new \RuntimeException("Unsupported API binding type '{$type}' for '{$path}'."),
		};
	}

	private function callableBindingFromDefinition(string $path, array $definition): \Dataphyre\Templating\DataBinding {
		$target=is_array($definition['target'] ?? null) ? $definition['target'] : null;
		if($target===null){
			throw new \RuntimeException("API binding '{$path}' is missing a callable target.");
		}
		$this->bootstrapExecutionTarget($target);
		$this->loadFrameworkModulesForExecutionTarget($target);
		$callable=$this->resolveExecutionCallable($target);
		if($callable===null){
			throw new \RuntimeException("API binding '{$path}' target is invalid or unavailable.");
		}
		return new ApiCallableBinding(
			$callable,
			'api.binding.'.$path,
			$this->callableTargetLabel($target),
			$definition['identity'] ?? null
		);
	}

	private function sqlBindingFromDefinition(string $path, array $definition): \Dataphyre\Templating\DataBinding {
		$this->loadFrameworkModules(['templating', 'sql']);
		$query_state=is_array($definition['query_state'] ?? null) ? $definition['query_state'] : [];
		$query_class=trim((string)($definition['query_class'] ?? ''));
		$query=match ($query_class) {
			'Dataphyre\\Database\\RepositoryQuery' => \Dataphyre\Database\RepositoryQuery::fromExecutionState($query_state),
			'Dataphyre\\Database\\TableQuery' => \Dataphyre\Database\TableQuery::fromExecutionState($query_state),
			default => throw new \RuntimeException("Unsupported API SQL binding query class '{$query_class}' for '{$path}'."),
		};
		$options=is_array($definition['options'] ?? null) ? $definition['options'] : [];
		$binding=\Dataphyre\Templating\SqlQueryBinding::make(
			$query,
			(string)($definition['mode'] ?? 'records'),
			$options
		);
		return ($definition['inherit_query_identity'] ?? false)===true
			? $binding->inheritIdentity()
			: $binding;
	}

	private function searchBindingFromDefinition(string $path, array $definition): \Dataphyre\Templating\DataBinding {
		$this->loadFrameworkModules(['templating', 'fulltext_engine']);
		$query_state=is_array($definition['query_state'] ?? null) ? $definition['query_state'] : [];
		$query_class=trim((string)($definition['query_class'] ?? ''));
		if($query_class!=='Dataphyre\\FulltextEngine\\Query'){
			throw new \RuntimeException("Unsupported API search binding query class '{$query_class}' for '{$path}'.");
		}
		$query=\Dataphyre\FulltextEngine\Query::fromExecutionState($query_state);
		$options=is_array($definition['options'] ?? null) ? $definition['options'] : [];
		$binding=\Dataphyre\Templating\SearchQueryBinding::make(
			$query,
			(string)($definition['mode'] ?? 'results'),
			$options
		);
		return ($definition['inherit_query_identity'] ?? false)===true
			? $binding->inheritIdentity()
			: $binding;
	}

	private function bindingContextForApi(ApiContext $context, array $resolved, string $path, array $trace_context, int $sequence): \Dataphyre\Templating\BindingContext {
		if($this->tracingEnabled()!==true){
			return new \Dataphyre\Templating\BindingContext(
				'api:'.$context->path(),
				false,
				array_replace($context->bindingData(), ['bindings'=>$resolved]),
				[],
				[],
				[
					'api_context'=>$context,
					'api_route'=>$context->route(),
				],
				[]
			);
		}
		$api_trace_id=is_string($trace_context['api_trace_id'] ?? null) ? $trace_context['api_trace_id'] : $this->newTraceId();
		$binding_trace_id=$api_trace_id.'.b'.str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
		return new \Dataphyre\Templating\BindingContext(
			'api:'.($trace_context['api_endpoint'] ?? $context->path()),
			false,
			array_replace($context->bindingData(), ['bindings'=>$resolved]),
			[],
			[],
			[
				'api_context'=>$context,
				'api_route'=>$context->route(),
			],
			array_filter([
				'api_trace_id'=>$api_trace_id,
				'api_endpoint'=>$trace_context['api_endpoint'] ?? $context->path(),
				'api_method'=>$trace_context['api_method'] ?? $context->method(),
				'binding_trace_id'=>$binding_trace_id,
				'binding_path'=>$path,
			], static fn(mixed $value): bool => $value!==null && $value!=='')
		);
	}

	private function resolveApiBindingWithTraceContext(
		\Dataphyre\Templating\DataBinding $binding,
		\Dataphyre\Templating\BindingContext $context,
		array $metadata,
		array $trace_context,
		string $path
	): mixed {
		if($this->tracingEnabled()!==true){
			return $binding->resolve($context);
		}
		if(
			($metadata['driver'] ?? null)==='sql'
			&& class_exists('Dataphyre\\Database\\DB', false)
			&& method_exists('Dataphyre\\Database\\DB', 'withTraceContext')
		){
			return \Dataphyre\Database\DB::withTraceContext(array_filter([
				'api_trace_id'=>$trace_context['api_trace_id'] ?? null,
				'api_endpoint'=>$trace_context['api_endpoint'] ?? null,
				'api_method'=>$trace_context['api_method'] ?? null,
				'binding_trace_id'=>$context->bindingTraceId(),
				'binding_name'=>$binding->name(),
				'binding_path'=>$path,
				'query_fingerprint'=>$this->traceString($metadata['query_fingerprint'] ?? null),
				'query_identity_mode'=>$this->traceString($metadata['query_identity_mode'] ?? null),
				'query_identity_source'=>$this->traceString($metadata['query_identity_source'] ?? null),
				'query_target_type'=>$this->traceString($metadata['query_target_type'] ?? null),
				'query_target'=>$this->traceString($metadata['query_target'] ?? null),
				'query_mode'=>$this->traceString($metadata['query_mode'] ?? null),
			], static fn(mixed $value): bool => $value!==null && $value!==''), fn() => $binding->resolve($context));
		}
		return $binding->resolve($context);
	}

	private function apiBindingTraceRecord(
		string $path,
		\Dataphyre\Templating\DataBinding $binding,
		\Dataphyre\Templating\BindingContext $binding_context,
		array $metadata,
		mixed $value,
		?array $identity,
		?string $cache_key,
		bool $reused,
		bool $skipped,
		float $duration
	): array {
		$record=array_filter(array_replace($metadata, [
			'path'=>$path,
			'binding'=>$binding->name(),
			'ok'=>true,
			'skipped'=>$skipped,
			'reused'=>$reused,
			'result_type'=>$this->bindingResultType($value),
			'duration_ms'=>round($duration*1000, 3),
			'cacheable'=>$identity!==null,
			'cache_scope'=>$identity!==null ? 'request' : 'none',
			'cache_state'=>$identity===null ? 'bypass' : ($reused ? 'reused' : 'miss'),
			'cache_key'=>$cache_key,
			'cache_identity'=>$identity,
			'api_trace_id'=>$this->traceString($binding_context->traceContext()['api_trace_id'] ?? null),
			'binding_trace_id'=>$binding_context->bindingTraceId(),
		]), static fn(mixed $value): bool => $value!==null && $value!==[]);
		$record['trace']=$this->apiBindingTracePayload($record);
		return $record;
	}

	private function apiBindingTracePayload(array $binding): array {
		return array_filter([
			'correlation'=>array_filter([
				'api_trace_id'=>$this->traceString($binding['api_trace_id'] ?? null),
				'binding_trace_id'=>$this->traceString($binding['binding_trace_id'] ?? null),
			], static fn(mixed $value): bool => $value!==null && $value!==''),
			'path'=>$this->traceString($binding['path'] ?? null),
			'binding'=>$this->traceString($binding['binding'] ?? null),
			'source'=>array_filter([
				'driver'=>$this->traceString($binding['driver'] ?? null),
				'type'=>$this->traceString($binding['type'] ?? null),
				'mode'=>$this->traceString($binding['query_mode'] ?? null),
				'target_type'=>$this->traceString($binding['query_target_type'] ?? ($binding['target_type'] ?? null)),
				'target'=>$this->traceString($binding['query_target'] ?? ($binding['target'] ?? null)),
			], static fn(mixed $value): bool => $value!==null && $value!==''),
			'identity'=>array_filter([
				'query_fingerprint'=>$this->traceString($binding['query_fingerprint'] ?? null),
				'query_identity_mode'=>$this->traceString($binding['query_identity_mode'] ?? null),
				'query_identity_source'=>$this->traceString($binding['query_identity_source'] ?? null),
				'cache_key'=>$this->traceString($binding['cache_key'] ?? null),
			], static fn(mixed $value): bool => $value!==null && $value!==''),
			'status'=>[
				'ok'=>($binding['ok'] ?? false)===true,
				'skipped'=>($binding['skipped'] ?? false)===true,
				'reused'=>($binding['reused'] ?? false)===true,
				'result_type'=>$this->traceString($binding['result_type'] ?? null),
				'duration_ms'=>(float)($binding['duration_ms'] ?? 0.0),
			],
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	private function callableTargetLabel(array $target): ?string {
		if(($target['type'] ?? null)==='class_method'){
			$class=trim((string)($target['class'] ?? ''), '\\');
			$method=trim((string)($target['method'] ?? ''));
			return $class!=='' && $method!=='' ? $class.'::'.$method : null;
		}
		if(($target['type'] ?? null)==='callable'){
			$reference=trim((string)($target['reference'] ?? ''));
			return $reference!=='' ? $reference : null;
		}
		return null;
	}

	private function bindingResultType(mixed $value): string {
		if(is_object($value)){
			return $value::class;
		}
		if(is_array($value)){
			return 'array';
		}
		return get_debug_type($value);
	}

	private function normalizeBindingCacheIdentity(mixed $identity): ?array {
		if($identity===null){
			return null;
		}
		if(is_string($identity)){
			$identity=trim($identity);
			return $identity!=='' ? ['key'=>$identity] : null;
		}
		if(is_scalar($identity)){
			return ['value'=>$identity];
		}
		if(is_array($identity)){
			ksort($identity);
			$normalized=[];
			foreach($identity as $key=>$value){
				$normalized[(string)$key]=$this->normalizeBindingCacheIdentityValue($value);
			}
			return $normalized;
		}
		if(is_object($identity) && method_exists($identity, '__toString')){
			return ['value'=>(string)$identity];
		}
		return ['value_type'=>get_debug_type($identity)];
	}

	private function normalizeBindingCacheIdentityValue(mixed $value): mixed {
		if(is_array($value)){
			ksort($value);
			$normalized=[];
			foreach($value as $key=>$entry){
				$normalized[(string)$key]=$this->normalizeBindingCacheIdentityValue($entry);
			}
			return $normalized;
		}
		if(is_scalar($value) || $value===null){
			return $value;
		}
		if(is_object($value) && method_exists($value, '__toString')){
			return (string)$value;
		}
		return get_debug_type($value);
	}

	private function setArrayValueByPath(array &$target, string $path, mixed $value): void {
		if($path==='' || str_contains($path, '.')===false){
			$target[$path]=$value;
			return;
		}
		$segments=explode('.', $path);
		$current=&$target;
		foreach($segments as $index=>$segment){
			if($index===count($segments)-1){
				$current[$segment]=$value;
				return;
			}
			if(!isset($current[$segment]) || !is_array($current[$segment])){
				$current[$segment]=[];
			}
			$current=&$current[$segment];
		}
	}

	private function traceString(mixed $value): ?string {
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}

	private function normalizeAlias(string $alias): string {
		return trim(trim($alias), "/\\");
	}

	private function normalizeProfileName(mixed $profile): ?string {
		if(!is_string($profile)){
			return null;
		}
		$profile=trim($profile);
		return $profile!=='' ? $profile : null;
	}

	private function inferInternalDispatchMethod(mixed $method, string $alias, array $body): string {
		$normalized=strtoupper(trim((string)$method));
		if($normalized!==''){
			return $normalized;
		}
		$alias_method=$this->inferAliasMethod($alias);
		if($alias_method!==null){
			return $alias_method;
		}
		return $body!==[] ? 'POST' : 'GET';
	}

	private function inferAliasMethod(string $alias): ?string {
		$alias=$this->normalizeAlias($alias);
		if($alias==='' || preg_match('/^(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)(?:[\/:\.\-_]|$)/i', $alias, $matches)!==1){
			return null;
		}
		return strtoupper($matches[1]);
	}

	private static function normalizePath(string $path): string {
		$path='/'.trim((string)$path, '/');
		return $path==='/' ? '/' : rtrim($path, '/');
	}

	private function newTraceId(): string {
		try{
			return bin2hex(random_bytes(16));
		}catch(\Throwable){
			return sha1(uniqid('api_trace_', true).microtime(true));
		}
	}

	private function tracingEnabled(): bool {
		if(class_exists('Dataphyre\\Runtime', false) && method_exists('Dataphyre\\Runtime', 'tracingEnabled')){
			return \Dataphyre\Runtime::tracingEnabled();
		}
		return !(defined('IS_PRODUCTION') && IS_PRODUCTION===true);
	}

	private function authorizeGuardScheme(string $scheme_name, array $runtime): array {
		$this->loadAccessFramework();
		if(class_exists('Dataphyre\\Access\\Auth')===false){
			return $this->failure($scheme_name, $runtime, $runtime['failure_message'] ?? 'Authentication is required for this endpoint.');
		}
		$guards=$runtime['guards'] ?? [];
		$guards=is_array($guards) ? $guards : [$guards];
		foreach($guards as $guard){
			$guard=is_string($guard) ? trim($guard) : '';
			if($guard==='' || \Dataphyre\Access\Auth::check($guard)===false){
				continue;
			}
			\Dataphyre\Access\Auth::shouldUse($guard);
			return [
				'authorized'=>true,
				'scheme'=>$scheme_name,
				'guard'=>$guard,
				'context'=>['guard'=>$guard],
			];
		}
		return $this->failure($scheme_name, $runtime, $runtime['failure_message'] ?? 'Authentication is required for this endpoint.');
	}

	private function authorizeBearerScheme(string $scheme_name, array $runtime, Request $request, array $route, array $scopes): array {
		$authorization=(string)$request->header('authorization', '');
		if(preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $authorization, $matches)!==1){
			return $this->failure($scheme_name, $runtime, $runtime['failure_message'] ?? 'A valid bearer token is required for this endpoint.');
		}
		return $this->authorizeWithResolver($scheme_name, $runtime, $matches[1], $request, $route, $scopes);
	}

	private function authorizeBasicScheme(string $scheme_name, array $runtime, Request $request, array $route, array $scopes): array {
		$username=$request->server('PHP_AUTH_USER');
		$password=$request->server('PHP_AUTH_PW');
		if(!is_string($username) || !is_string($password)){
			$authorization=(string)$request->header('authorization', '');
			if(preg_match('/^\s*Basic\s+(.+?)\s*$/i', $authorization, $matches)!==1){
				return $this->failure($scheme_name, $runtime, $runtime['failure_message'] ?? 'Valid basic authentication credentials are required for this endpoint.');
			}
			$decoded=base64_decode($matches[1], true);
			if(!is_string($decoded) || !str_contains($decoded, ':')){
				return $this->failure($scheme_name, $runtime, $runtime['failure_message'] ?? 'Valid basic authentication credentials are required for this endpoint.');
			}
			[$username, $password]=explode(':', $decoded, 2);
		}
		return $this->authorizeWithResolver($scheme_name, $runtime, [
			'username'=>$username,
			'password'=>$password,
		], $request, $route, $scopes);
	}

	private function authorizeApiKeyScheme(string $scheme_name, array $runtime, Request $request, array $route, array $scopes): array {
		$location=strtolower((string)($runtime['location'] ?? 'header'));
		$parameter=(string)($runtime['parameter'] ?? '');
		$key=null;
		if($location==='query'){
			$key=$request->query($parameter);
		}elseif($location==='cookie'){
			$key=$request->cookie($parameter);
		}else{
			$key=$request->header($parameter);
		}
		if(!is_string($key) || trim($key)===''){
			return $this->failure($scheme_name, $runtime, $runtime['failure_message'] ?? 'A valid API key is required for this endpoint.');
		}
		return $this->authorizeWithResolver($scheme_name, $runtime, trim($key), $request, $route, $scopes);
	}

	private function authorizeCallbackScheme(string $scheme_name, array $runtime, Request $request, array $route, array $scopes): array {
		return $this->authorizeWithResolver($scheme_name, $runtime, null, $request, $route, $scopes);
	}

	private function authorizeWithResolver(
		string $scheme_name,
		array $runtime,
		mixed $credentials,
		Request $request,
		array $route,
		array $scopes
	): array {
		$resolver=$this->resolveCallableReference($runtime['resolver'] ?? null);
		if($resolver===null){
			return $this->failure($scheme_name, $runtime, $runtime['failure_message'] ?? 'Authentication is required for this endpoint.');
		}
		$result=$resolver($credentials, $request, $route, $scopes, $runtime);
		return $this->normalizeAuthorizationResult($scheme_name, $runtime, $result);
	}

	private function normalizeAuthorizationResult(string $scheme_name, array $runtime, mixed $result): array {
		if($result===true){
			return ['authorized'=>true, 'scheme'=>$scheme_name];
		}
		if($result instanceof Response){
			return [
				'authorized'=>false,
				'scheme'=>$scheme_name,
				'response'=>$result,
			];
		}
		if(is_array($result)){
			$authorized=($result['authorized'] ?? $result['ok'] ?? false)===true;
			if($authorized===true){
				return array_replace([
					'authorized'=>true,
					'scheme'=>$scheme_name,
				], array_filter([
					'identity'=>$result['identity'] ?? ($result['principal'] ?? null),
					'context'=>is_array($result['context'] ?? null) ? $result['context'] : null,
					'meta'=>is_array($result['meta'] ?? null) ? $result['meta'] : null,
					'guard'=>isset($result['guard']) && is_string($result['guard']) ? trim($result['guard']) : null,
				], static fn(mixed $value): bool => $value!==null && $value!==[]));
			}
			return [
				'authorized'=>false,
				'scheme'=>$scheme_name,
				'status'=>(int)($result['status'] ?? $runtime['failure_status'] ?? 401),
				'message'=>(string)($result['message'] ?? $runtime['failure_message'] ?? 'Authentication is required for this endpoint.'),
				'headers'=>is_array($result['headers'] ?? null) ? $result['headers'] : [],
				'response'=>$result['response'] ?? null,
			];
		}
		return $this->failure($scheme_name, $runtime, $runtime['failure_message'] ?? 'Authentication is required for this endpoint.');
	}

	private function failure(string $scheme_name, array $runtime, string $message): array {
		return [
			'authorized'=>false,
			'scheme'=>$scheme_name,
			'status'=>(int)($runtime['failure_status'] ?? 401),
			'message'=>$message,
			'headers'=>is_array($runtime['failure_headers'] ?? null) ? $runtime['failure_headers'] : [],
		];
	}

	private function resolveCallableReference(mixed $resolver): ?callable {
		if($resolver===null){
			return null;
		}
		if(is_string($resolver) && is_callable($resolver)){
			return $resolver;
		}
		if(
			is_array($resolver)
			&& count($resolver)===2
			&& is_string($resolver[0])
			&& is_string($resolver[1])
			&& is_callable($resolver)
		){
			return $resolver;
		}
		return null;
	}

	private function loadAccessFramework(): void {
		if(class_exists('\dataphyre\core', false)){
			\dataphyre\core::load_framework_module('access');
		}
	}

	private function applicationManifest(?string $application_id=null): array {
		$definition=$this->applicationDefinition($application_id);
		if($definition===null){
			return ['version'=>1, 'metadata'=>[], 'routes'=>[]];
		}
		if(!empty($definition->compiled_routes_file) && is_file($definition->compiled_routes_file)){
			$manifest=require($definition->compiled_routes_file);
			return is_array($manifest) ? $manifest : ['version'=>1, 'metadata'=>[], 'routes'=>[]];
		}
		if(!empty($definition->routes_file) && is_file($definition->routes_file)){
			if(class_exists('\dataphyre\core', false)){
				\dataphyre\core::load_framework_module('routing');
			}
			return \Dataphyre\Routing\RouteCompiler::compile_file($definition->routes_file, [
				'application'=>$definition->id,
				'compiled_at'=>gmdate('c'),
			]);
		}
		return ['version'=>1, 'metadata'=>['application'=>$definition->id], 'routes'=>[]];
	}

	private function applicationDefinition(?string $application_id=null): ?\dataphyre\application_definition {
		if(class_exists('\dataphyre\core', false)){
			\dataphyre\core::load_framework_module('core');
		}
		if($application_id===null || trim($application_id)===''){
			if(class_exists('\dataphyre\runtime', false)){
				$definition=\dataphyre\runtime::current_application_definition();
				if($definition instanceof \dataphyre\application_definition){
					return $definition;
				}
			}
			return null;
		}
		$project_root=$this->projectRoot();
		if($project_root===null || class_exists('\dataphyre\runtime', false)===false){
			return null;
		}
		return \dataphyre\runtime::resolve_application_definition($project_root, trim($application_id));
	}

	private function projectRoot(): ?string {
		if(class_exists('Dataphyre\\Runtime')){
			$project_root=\Dataphyre\Runtime::projectRoot();
			if(is_string($project_root) && trim($project_root)!==''){
				return rtrim($project_root, '/\\');
			}
		}
		if(class_exists('\dataphyre\runtime', false)){
			$project_root=\dataphyre\runtime::current_project_root();
			if(is_string($project_root) && trim($project_root)!==''){
				return rtrim($project_root, '/\\');
			}
		}
		return null;
	}

	private function openApiOptions(?\dataphyre\application_definition $definition, array $options): array {
		$resolved=[
			'title'=>$this->defaultTitle($definition),
			'version'=>'1.0.0',
			'description'=>$definition!==null ? 'API surface for '.$definition->id.'.' : null,
			'servers'=>$this->defaultServers($options['servers'] ?? []),
		];
		foreach($options as $key=>$value){
			if($value===null){
				continue;
			}
			$resolved[$key]=$value;
		}
		return $resolved;
	}

	private function defaultTitle(?\dataphyre\application_definition $definition): string {
		if($definition===null){
			return 'Dataphyre API';
		}
		return ucwords(str_replace(['_', '-'], ' ', $definition->id)).' API';
	}

	private function defaultServers(array $servers): array {
		if($servers!==[]){
			return $servers;
		}
		$scheme=(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
		$host=trim((string)($_SERVER['HTTP_HOST'] ?? ''));
		if($host===''){
			return [];
		}
		return [[
			'url'=>$scheme.'://'.$host,
		]];
	}

	private function normalizeDocumentationOptions(array $options): array {
		$defaults=[
			'application'=>null,
			'bootstrap'=>null,
			'docs_path'=>'/_framework/api/docs',
			'spec_path'=>'/_framework/api/openapi.json',
			'title'=>null,
			'version'=>'1.0.0',
			'description'=>null,
			'servers'=>[],
			'swagger_ui_css'=>'https://unpkg.com/swagger-ui-dist@5/swagger-ui.css',
			'swagger_ui_bundle_js'=>'https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js',
			'swagger_ui_preset_js'=>'https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js',
		];
		$options=array_replace($defaults, $options);
		foreach(['docs_path', 'spec_path'] as $key){
			$options[$key]='/' . trim((string)$options[$key], '/');
		}
		$bootstrap=is_string($options['bootstrap']) ? trim($options['bootstrap']) : null;
		$options['bootstrap']=$bootstrap!=='' ? $bootstrap : null;
		return $options;
	}
}
