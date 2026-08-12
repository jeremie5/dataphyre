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

/**
 * Coordinates Dataphyre API documentation, discovery, internal dispatch, authorization, execution, tracing, and endpoint caching.
 *
 * The manager works from compiled application manifests: documentation helpers publish OpenAPI and Swagger UI routes,
 * discovery helpers extract API metadata from compiled routes, and dispatch helpers turn array request definitions
 * into Request objects that can be authorized, bound, executed, traced, cached, and normalized back into array records.
 */
final class ApiManager {

	private const AUTH_ATTRIBUTE='dataphyre_api_auth';

	private static ?self $instance=null;

	/**
	 * Returns the process-wide API manager instance.
	 *
	 * @return self Shared manager used by the Api static entry point and route integration points.
	 */
	public static function instance(): self {
		if(self::$instance===null){
			self::$instance=new self();
		}
		return self::$instance;
	}

	/**
	 * Compiles the API documentation route set.
	 *
	 * The returned routes expose the OpenAPI JSON document, the Swagger UI shell, and Swagger UI static assets.
	 * Normalized documentation options are attached to each route under `api_docs` for the documentation controllers.
	 *
	 * @param array{docs_path?:string, spec_path?:string, asset_path?:string, bootstrap?:mixed, application?:string, title?:string, version?:string, servers?:array<int,string|array<string,mixed>>} $options Documentation path, asset path, spec path, bootstrap, and OpenAPI defaults.
	 * @return array<int, array<string, mixed>> Compiled route records for the API documentation endpoints.
	 */
	public function documentationRoutes(array $options=[]): array {
		$options=$this->normalizeDocumentationOptions($options);

		$specRoute=Route::get(
			$options['spec_path'],
			ControllerAction::static('Dataphyre\\Api\\OpenApiController', 'show', [
				'bootstrap'=>$options['bootstrap'],
			])
		)->compile();
		$specRoute['path_template']=$options['spec_path'];
		$specRoute['api_docs']=$options;

		$docsRoute=Route::get(
			$options['docs_path'],
			ControllerAction::static('Dataphyre\\Api\\SwaggerUiController', 'show', [
				'bootstrap'=>$options['bootstrap'],
			])
		)->compile();
		$docsRoute['path_template']=$options['docs_path'];
		$docsRoute['api_docs']=$options;

		$assetRoute=Route::methods(
			['GET', 'HEAD'],
			$options['asset_path'].'/{asset}',
			ControllerAction::static('Dataphyre\\Api\\SwaggerUiController', 'asset', [
				'bootstrap'=>$options['bootstrap'],
			])
		)->compile();
		$assetRoute['path_template']=$options['asset_path'].'/{asset}';
		$assetRoute['api_docs']=$options;

		return [$specRoute, $docsRoute, $assetRoute];
	}

	/**
	 * Discovers API endpoints from a configured application.
	 *
	 * The application manifest is loaded first, then filtered through discoverManifest so only routes carrying
	 * API metadata participate in documentation, OpenAPI generation, and internal dispatch discovery.
	 *
	 * @param ?string $applicationId Optional application id; null resolves the current/default application.
	 * @return array<int, array<string, mixed>> Normalized API endpoint records discovered from the application manifest.
	 */
	public function discoverApplication(?string $applicationId=null): array {
		return $this->discoverManifest($this->applicationManifest($applicationId));
	}

	/**
	 * Extracts API endpoint records from a compiled route manifest.
	 *
	 * Each output record preserves API metadata used downstream by the OpenAPI generator and dispatcher, including
	 * aliases, cache rules, request and response schemas, security schemes, execution metadata, bindings, tracing,
	 * lifecycle hooks, profile information, dispatch defaults, and the original compiled handler.
	 *
	 * @param array{routes?:array<int, array<string, mixed>>} $manifest Application manifest with compiled route records.
	 * @return array<int, array<string, mixed>> API-only endpoint discovery records carrying OpenAPI, dispatch, cache, and execution metadata.
	 */
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

	/**
	 * Generates an OpenAPI document for a configured application.
	 *
	 * Application-level title, version, servers, and option defaults are resolved before endpoint discovery is passed
	 * into OpenApiGenerator, keeping manifest loading and OpenAPI shape generation separated.
	 *
	 * @param ?string $applicationId Optional application id used to resolve app definition and routes.
	 * @param array<string, mixed> $options Explicit OpenAPI generator options that override application defaults.
	 * @return array<string, mixed> OpenAPI document ready for JSON serialization.
	 */
	public function openApiDocument(?string $applicationId=null, array $options=[]): array {
		$definition=$this->applicationDefinition($applicationId);
		$options=$this->openApiOptions($definition, $options);
		return (new OpenApiGenerator())->generate($this->discoverApplication($applicationId), $options);
	}

	/**
	 * Dispatches one internal API request definition against the compiled application manifest.
	 *
	 * Requests may target a route by path/URI or by alias/endpoint name, with method inference for alias calls.
	 * The result is always a normalized array record; authorization failures, route misses, handler failures, and
	 * thrown exceptions are converted into failure records instead of leaking raw Response objects or throwables.
	 *
	 * @param array{key?:string, method?:string, path?:string, uri?:string, alias?:string, endpoint?:string, profile?:string, body?:array<string,mixed>, post?:array<string,mixed>, query?:array<string,mixed>, headers?:array<string,mixed>, cookies?:array<string,mixed>, server?:array<string,mixed>} $requestDefinition Internal dispatch request definition.
	 * @param array{application?:string, trust_auth?:bool, expose_exceptions?:bool} $options Dispatch options controlling manifest selection, inherited auth, and exception exposure.
	 * @return array<string, mixed> Normalized dispatch result with ok, status, timing, response data, and route context.
	 */
	public function dispatch(array $requestDefinition, array $options=[]): array {
		$traceSuppressed=(bool)($options['__dataphyre_trace_suppressed'] ?? false);
		unset($options['__dataphyre_trace_suppressed']);
		$startedAt=microtime(true);
		$initialBody=is_array($requestDefinition['body'] ?? null)
			? $requestDefinition['body']
			: (is_array($requestDefinition['post'] ?? null) ? $requestDefinition['post'] : []);
		$initialAlias=$this->normalizeAlias((string)($requestDefinition['alias'] ?? $requestDefinition['endpoint'] ?? ''));
		$definition=[
			'key'=>is_string($requestDefinition['key'] ?? null) ? trim((string)$requestDefinition['key']) : null,
			'method'=>$this->inferInternalDispatchMethod($requestDefinition['method'] ?? null, $initialAlias, $initialBody),
			'path'=>isset($requestDefinition['path']) || isset($requestDefinition['uri'])
				? self::normalizePath((string)($requestDefinition['path'] ?? $requestDefinition['uri'] ?? '/'))
				: null,
			'alias'=>$initialAlias,
			'profile'=>$this->normalizeProfileName($requestDefinition['profile'] ?? null),
		];
		$trustedAuth=false;
		$route=null;
		try{
			$definition=$this->normalizeInternalRequestDefinition($requestDefinition);
			$manifest=$this->applicationManifest(isset($options['application']) ? (string)$options['application'] : null);
			$resolution=$this->resolveManifestDispatch($manifest['routes'] ?? [], $definition);
			if(isset($resolution['route'])===false){
				$record=$this->dispatchFailureRecord(
					$definition,
					(int)($resolution['status'] ?? 404),
					(string)($resolution['message'] ?? 'API route not found.'),
					$startedAt
				);
				if(!$traceSuppressed){
					tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API internal dispatch failed; method='.$definition['method'].'; target='.($definition['path'] ?? $definition['alias'] ?? 'unknown').'; status='.(int)($record['status'] ?? 0), $S='warning');
				}
				return $record;
			}
			$route=$resolution['route'];

			$request=$this->internalRequestForRoute($route, $definition, $options);
			$trustedAuth=($options['trust_auth'] ?? false)===true && is_array($request->attribute(self::AUTH_ATTRIBUTE));
			if($trustedAuth===false){
				$authorizationResponse=$this->authorizeCompiledRoute($route, $request);
				if($authorizationResponse instanceof Response){
					$record=$this->normalizeDispatchedResponse($definition, $route, $authorizationResponse, $startedAt);
					if(!$traceSuppressed){
						tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API internal dispatch authorization failed; method='.$definition['method'].'; target='.($definition['path'] ?? $definition['alias'] ?? 'unknown').'; status='.$authorizationResponse->status, $S='warning');
					}
					return $record;
				}
			}

			$response=$this->dispatchMatchedRoute($route, $request);
			$record=$this->normalizeDispatchedResponse($definition, $route, $response, $startedAt);
			if(!$traceSuppressed){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API internal dispatch completed; method='.$definition['method'].'; target='.($definition['path'] ?? $definition['alias'] ?? 'unknown').'; status='.$response->status.'; trusted_auth='.($trustedAuth ? 'yes' : 'no'), $S=$response->status < 400 ? 'info' : 'warning');
			}
			return $record;
		}catch(\Throwable $exception){
			$message=($options['expose_exceptions'] ?? false)===true && trim($exception->getMessage())!==''
				? $exception->getMessage()
				: 'Internal API dispatch failed.';
			$record=$this->dispatchFailureRecord($definition, 500, $message, $startedAt);
			if(!$traceSuppressed){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API internal dispatch threw; method='.$definition['method'].'; target='.($definition['path'] ?? $definition['alias'] ?? 'unknown').'; exception='.get_class($exception), $S='warning');
			}
			return $record;
		}
	}

	/**
	 * Dispatches a bounded batch of internal API request definitions.
	 *
	 * String keys are promoted into request keys and may also act as path or alias shortcuts. The batch result records
	 * aggregate success, failure count, duration, and every normalized per-request dispatch response.
	 *
	 * @param array<int|string, array<string,mixed>|mixed> $requests List or keyed map of internal dispatch request definitions.
	 * @param array{limit?:int, limit_error?:string, continue_on_error?:bool, application?:string, trust_auth?:bool, expose_exceptions?:bool} $options Batch options plus per-request dispatch options.
	 * @return array{ok:bool, count?:int, failures?:int, duration_ms:float, responses:array<int,array<string,mixed>>, error?:string, limit?:int} Batch status record.
	 */
	public function dispatchBatch(array $requests, array $options=[]): array {
		$startedAt=microtime(true);
		$limit=max(1, (int)($options['limit'] ?? 128));
		if(count($requests)>$limit){
			$result=[
				'ok'=>false,
				'error'=>(string)($options['limit_error'] ?? 'too_many_requests'),
				'limit'=>$limit,
				'count'=>count($requests),
				'duration_ms'=>round((microtime(true)-$startedAt)*1000, 3),
				'responses'=>[],
			];
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API internal batch dispatch blocked; requested='.count($requests).'; limit='.$limit, $S='warning');
			return $result;
		}

		$continueOnError=($options['continue_on_error'] ?? true)===true;
		$dispatchOptions=$options+['__dataphyre_trace_suppressed'=>true];
		$responses=[];
		foreach($requests as $key=>$requestDefinition){
			if(is_array($requestDefinition)===false){
				$responses[]=$this->dispatchFailureRecord([
					'key'=>is_string($key) ? trim($key) : null,
					'method'=>'GET',
					'path'=>'/',
				], 422, 'Batch request entry must be an array.', $startedAt);
				if($continueOnError===false){
					break;
				}
				continue;
			}
			if(!array_key_exists('key', $requestDefinition) && is_string($key) && trim($key)!==''){
				$requestDefinition['key']=trim($key);
				if(!isset($requestDefinition['path']) && !isset($requestDefinition['uri']) && str_starts_with(trim($key), '/')){
					$requestDefinition['path']=trim($key);
				}elseif(!isset($requestDefinition['path']) && !isset($requestDefinition['uri']) && !isset($requestDefinition['alias']) && !isset($requestDefinition['endpoint'])){
					$requestDefinition['alias']=trim($key);
				}
			}
			$record=$this->dispatch($requestDefinition, $dispatchOptions);
			$responses[]=$record;
			if($continueOnError===false && ($record['ok'] ?? false)!==true){
				break;
			}
		}

		$failures=0;
		foreach($responses as $record){
			if(($record['ok'] ?? false)!==true){
				$failures++;
			}
		}

		$result=[
			'ok'=>$failures===0,
			'count'=>count($responses),
			'failures'=>$failures,
			'duration_ms'=>round((microtime(true)-$startedAt)*1000, 3),
			'responses'=>$responses,
		];
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API internal batch dispatch completed; requested='.count($requests).'; responses='.count($responses).'; failures='.$failures, $S=$failures===0 ? 'info' : 'warning');
		return $result;
	}

	/**
	 * Dispatches ordered internal API requests using chain defaults.
	 *
	 * Chain dispatch intentionally reuses batch dispatch semantics while changing the default limit error label and
	 * keeping continuation enabled unless the caller overrides it.
	 *
	 * @param array<int, array<string,mixed>|mixed> $requests Ordered request definitions to dispatch.
	 * @param array<string, mixed> $options Chain and dispatch options merged over the chain defaults.
	 * @return array<string, mixed> Chain status record with responses and aggregate counts.
	 */
	public function dispatchChain(array $requests, array $options=[]): array {
		$result=$this->dispatchBatch($requests, array_replace([
			'limit'=>128,
			'limit_error'=>'too_many_chainlinks',
			'continue_on_error'=>true,
		], $options));
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API internal chain dispatch completed; requested='.count($requests).'; responses='.(int)($result['count'] ?? 0).'; failures='.(int)($result['failures'] ?? 0), $S=($result['ok'] ?? false)===true ? 'info' : 'warning');
		return $result;
	}

	/**
	 * Removes persistent endpoint cache entries.
	 *
	 * Calling without names clears the endpoint cache item and name index directories. Calling with names removes
	 * only the item files currently indexed under those normalized endpoint cache names.
	 *
	 * @param string ...$names Optional endpoint cache names to invalidate.
	 * @return int Number of cache item files deleted.
	 */
	public function clearEndpointCache(string ...$names): int {
		$cacheDir=$this->endpointCacheRoot();
		$itemsDir=$cacheDir.'items'.DIRECTORY_SEPARATOR;
		$namesDir=$cacheDir.'names'.DIRECTORY_SEPARATOR;
		if($names===[]){
			return $this->clearPersistentCacheDirectories($itemsDir, $namesDir);
		}

		$deleted=0;
		foreach($this->normalizeEndpointCacheNames($names) as $name){
			$nameFile=$this->endpointCacheNameFile($name, $namesDir);
			if(!is_file($nameFile)){
				continue;
			}
			$nameIndex=@file_get_contents($nameFile);
			$keys=json_decode(is_string($nameIndex) ? $nameIndex : '[]', true);
			if(is_array($keys)){
				foreach($keys as $key){
					if(!is_string($key) || $key===''){
						continue;
					}
					$itemFile=$itemsDir.$key.'.cache';
					if(is_file($itemFile) && @unlink($itemFile)){
						$deleted++;
					}
				}
			}
			@unlink($nameFile);
		}
		return $deleted;
	}

	/**
	 * Authorizes a compiled API route against its declared security requirements.
	 *
	 * Security requirements use OpenAPI-style alternatives: each requirement object is evaluated as an all-of set,
	 * and the route is authorized when any requirement set passes. Successful authorization data is stored on
	 * the request for later trace output; failures become JSON responses with the selected status and headers.
	 *
	 * @param array{api?:array{security?:array<int,array<string,array<int,string>>>, security_schemes?:array<string,array<string,mixed>>}} $route Compiled route containing API security requirements and scheme definitions.
	 * @param Request $request Request supplying credentials and receiving authorization attributes.
	 * @return ?Response Null when execution may continue, or an early failure response when authorization fails.
	 */
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

		$firstFailure=[
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
			$authorizedPayload=null;
			foreach($requirement as $schemeName=>$scopes){
				$scheme=$schemes[$schemeName] ?? null;
				if(!is_array($scheme)){
					$passed=false;
					$firstFailure['scheme']=$schemeName;
					break;
				}
				$result=$this->authorizeScheme($schemeName, $scheme, $request, $route, is_array($scopes) ? $scopes : []);
				if(($result['authorized'] ?? false)===true){
					$authorizedPayload=$this->successfulAuthorizationPayload($schemeName, is_array($scopes) ? $scopes : [], $result);
					continue;
				}
				$passed=false;
				$firstFailure=array_replace($firstFailure, $result);
				break;
			}
			if($passed===true){
				if($authorizedPayload!==null){
					$request->setAttribute(self::AUTH_ATTRIBUTE, $authorizedPayload);
				}
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API route authorization passed; route='.$this->apiRouteTraceLabel($route).'; requirements='.count($requirements).'; scheme='.((string)($authorizedPayload['scheme'] ?? 'unknown')), $S='info');
				return null;
			}
		}

		if(isset($firstFailure['response']) && $firstFailure['response'] instanceof Response){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API route authorization failed; route='.$this->apiRouteTraceLabel($route).'; requirements='.count($requirements).'; scheme='.((string)($firstFailure['scheme'] ?? 'unknown')).'; status='.$firstFailure['response']->status, $S='warning');
			return $firstFailure['response'];
		}

		$status=(int)($firstFailure['status'] ?? 401);
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API route authorization failed; route='.$this->apiRouteTraceLabel($route).'; requirements='.count($requirements).'; scheme='.((string)($firstFailure['scheme'] ?? 'unknown')).'; status='.$status, $S='warning');
		return Response::json([
			'ok'=>false,
			'error'=>$firstFailure['message'] ?? 'Authentication failed.',
			'scheme'=>$firstFailure['scheme'] ?? null,
		], $status, is_array($firstFailure['headers'] ?? null) ? $firstFailure['headers'] : []);
	}

	/**
	 * Executes the API execution pipeline for a compiled route.
	 *
	 * The pipeline validates request schema, prepares endpoint cache metadata, reuses cached responses when allowed,
	 * resolves data bindings, invokes lifecycle hooks, executes the configured target, stores cacheable responses,
	 * and injects trace data according to route trace options.
	 *
	 * @param array{api?:array<string,mixed>} $route Compiled route with an API execution definition and related metadata.
	 * @param Request $request Request being processed for the compiled route.
	 * @return ?Response Executed route response, or null when the route has no API execution metadata.
	 */
	public function executeCompiledRoute(array $route, Request $request): ?Response {
		$api=$route['api'] ?? null;
		$execution=$api['execution'] ?? null;
		if(!is_array($api) || !is_array($execution)){
			return null;
		}

		$context=new ApiContext($request, $route);
		$traceOptions=$this->normalizeTraceOptions($api['trace'] ?? []);
		$traceContext=$traceOptions['enabled']===true
			? $this->createApiTraceContext($route, $request, $traceOptions)
			: [];
		$lifecycle=$this->normalizeLifecycle($api['lifecycle'] ?? []);
		$bindings=is_array($api['bindings'] ?? null) ? $api['bindings'] : [];
		$endpointCache=[
			'enabled'=>false,
			'cacheable'=>false,
			'state'=>'bypass',
			'layer'=>'none',
		];
		$startedAt=microtime(true);
		$validationResult=null;
		$result=null;

		$schema=$api['schema'] ?? null;
		if(is_array($schema)){
			$validationResult=$this->applyRouteSchema($context, $schema);
			if($validationResult->failed()){
				$result=$this->validationFailureResponse($validationResult, $schema);
			}
		}

		if($result===null){
			$endpointCache=$this->endpointCacheDescriptor(
				$route,
				$request,
				$context,
				$bindings,
				$traceContext,
				is_array($api['cache'] ?? null) ? $api['cache'] : null
			);
		}

		if($result===null){
			$cached=$this->loadCachedEndpointResponse($endpointCache);
			if(($cached['hit'] ?? false)===true && $cached['response'] instanceof Response){
				$cacheTrace=array_replace($endpointCache, [
					'state'=>'hit',
					'layer'=>'persistent',
					'stored_at'=>$cached['stored_at'] ?? null,
				]);
				$tracePayload=$traceOptions['enabled']===true
					? $this->buildApiTracePayload(
						$traceContext,
						$route,
						$request,
						$context,
						$validationResult,
						[],
						$startedAt,
						$traceOptions,
						$cacheTrace
					)
					: null;
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API compiled route execution completed; route='.$this->apiRouteTraceLabel($route).'; status='.$cached['response']->status.'; cache=hit; lifecycle_phases='.count($lifecycle), $S=$cached['response']->status < 400 ? 'info' : 'warning');
				return $this->applyTraceToResponse($cached['response'], $tracePayload, $traceOptions);
			}
			if($bindings!==[]){
				$this->resolveRouteBindings($context, $bindings, $traceContext);
			}
			$beforeResponse=$this->runLifecycleHooks('before', $lifecycle, $context, $request, $route);
			if($beforeResponse instanceof Response){
				$result=$beforeResponse;
			}
		}

		try{
			if($result===null){
				$result=$this->executeWithTraceContext(
					$traceContext,
					fn(): mixed => $this->invokeExecutionTarget($execution, $context, $request, $route),
					$traceOptions
				);
			}
		}catch(\Throwable $exception){
			$errorResponse=$this->runLifecycleHooks('error', $lifecycle, $context, $request, $route, $exception);
			if($errorResponse instanceof Response){
				$result=$errorResponse;
			}elseif($exception instanceof \Dataphyre\Sanitation\SanitizationException){
				$result=$this->validationFailureResponse($exception->result(), [
					'options'=>[
						'status'=>422,
						'message'=>$exception->getMessage(),
					],
				]);
				$validationResult=$exception->result();
			}else{
				throw $exception;
			}
		}

		$cacheTrace=$this->provisionalEndpointCacheTrace($endpointCache);
		$tracePayload=$traceOptions['enabled']===true
			? $this->buildApiTracePayload(
				$traceContext,
				$route,
				$request,
				$context,
				$validationResult,
				$context->bindingTrace(),
				$startedAt,
				$traceOptions,
				$cacheTrace
			)
			: null;
		$response=$this->normalizeExecutionResponse($result, null, $traceOptions);
		$afterResponse=$this->runLifecycleHooks('after', $lifecycle, $context, $request, $route, $result, $response, $tracePayload);
		$finalResponse=$afterResponse instanceof Response ? $afterResponse : $response;
		$cacheTrace=$this->storeEndpointCacheResponse($endpointCache, $finalResponse, $traceOptions);
		$tracePayload=$traceOptions['enabled']===true
			? $this->buildApiTracePayload(
				$traceContext,
				$route,
				$request,
				$context,
				$validationResult,
				$context->bindingTrace(),
				$startedAt,
				$traceOptions,
				$cacheTrace
			)
			: null;
		$response=$this->applyTraceToResponse($finalResponse, $tracePayload, $traceOptions);
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API compiled route execution completed; route='.$this->apiRouteTraceLabel($route).'; status='.$response->status.'; cache='.(string)($cacheTrace['state'] ?? $endpointCache['state'] ?? 'bypass').'; lifecycle_phases='.count($lifecycle), $S=$response->status < 400 ? 'info' : 'warning');
		return $response;
	}

	/**
	 * Normalizes one internal API dispatch request definition.
	 *
	 * Accepts path- or alias-based requests, separates query/body/route data, and
	 * assigns a stable key used by batch dispatch responses.
	 *
	 * @param array<string, mixed> $requestDefinition Raw internal request definition.
	 * @return array{key:string, method:string, path:?string, alias:?string, profile:?string, query:array<string,mixed>, body:array<string,mixed>, route_parameters:array<string,mixed>, headers:array<string,mixed>, cookies:array<string,mixed>, server:array<string,mixed>, attributes:array<string,mixed>} Normalized request definition.
	 */
	private function normalizeInternalRequestDefinition(array $requestDefinition): array {
		$path=trim((string)($requestDefinition['path'] ?? $requestDefinition['uri'] ?? ''));
		$alias=$this->normalizeAlias((string)($requestDefinition['alias'] ?? $requestDefinition['endpoint'] ?? ''));
		$query=is_array($requestDefinition['query'] ?? null)
			? $requestDefinition['query']
			: (is_array($requestDefinition['get'] ?? null) ? $requestDefinition['get'] : []);
		$body=is_array($requestDefinition['body'] ?? null)
			? $requestDefinition['body']
			: (is_array($requestDefinition['post'] ?? null) ? $requestDefinition['post'] : []);
		$method=$this->inferInternalDispatchMethod($requestDefinition['method'] ?? null, $alias, $body);
		$key=isset($requestDefinition['key']) && is_string($requestDefinition['key'])
			? trim($requestDefinition['key'])
			: '';
		if($path==='' && $alias===''){
			throw new \RuntimeException('API batch request is missing a path or alias.');
		}
		$routeParameters=is_array($requestDefinition['route'] ?? null)
			? $requestDefinition['route']
			: (is_array($requestDefinition['parameters'] ?? null) ? $requestDefinition['parameters'] : []);
		$resolvedPath=$path!=='' ? self::normalizePath($path) : null;
		$resolvedProfile=$this->normalizeProfileName($requestDefinition['profile'] ?? null);
		return [
			'key'=>$key!=='' ? $key : strtoupper($method).' '.($resolvedPath ?? '@'.$alias),
			'method'=>$method,
			'path'=>$resolvedPath,
			'alias'=>$alias!=='' ? $alias : null,
			'profile'=>$resolvedProfile,
			'query'=>$query,
			'body'=>$body,
			'route_parameters'=>$routeParameters,
			'headers'=>is_array($requestDefinition['headers'] ?? null) ? $requestDefinition['headers'] : [],
			'cookies'=>is_array($requestDefinition['cookies'] ?? null) ? $requestDefinition['cookies'] : [],
			'server'=>is_array($requestDefinition['server'] ?? null) ? $requestDefinition['server'] : [],
			'attributes'=>is_array($requestDefinition['attributes'] ?? null) ? $requestDefinition['attributes'] : [],
		];
	}

	/**
	 * Resolves a normalized internal request against compiled manifest routes.
	 *
	 * Path requests match directly. Alias requests must resolve to exactly one API
	 * route and interpolate all required route parameters before dispatch.
	 *
	 * @param array<int, array<string,mixed>|mixed> $routes Compiled route manifest rows.
	 * @param array<string, mixed> $definition Normalized request definition, updated with resolved path.
	 * @return array{route?:array<string,mixed>, status?:int, message?:string} Dispatch resolution containing a route or failure status/message.
	 */
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
		$routeParameters=$this->resolveAliasRouteParameters($route, $definition);
		if($routeParameters===null){
			return ['status'=>422, 'message'=>'API alias dispatch is missing required route parameters.'];
		}

		$resolvedPath=$this->interpolateRoutePathTemplate(
			(string)($route['path_template'] ?? $route['exact_path'] ?? ($route['api']['path'] ?? '/')),
			$routeParameters
		);
		if($resolvedPath===null){
			return ['status'=>422, 'message'=>'API alias dispatch could not build a route path.'];
		}

		$route['parameters']=$routeParameters;
		$definition['path']=$resolvedPath;
		return ['route'=>$route];
	}

	/**
	 * Builds a Request object for an internal manifest route dispatch.
	 *
	 * The synthetic request can inherit headers, cookies, and server state from a
	 * base request while replacing method, URI, route parameters, body, and query.
	 *
	 * @param array<string, mixed> $route Matched route manifest row.
	 * @param array<string, mixed> $definition Normalized internal request definition.
	 * @param array{base_request?:Request, inherit_headers?:bool, inherit_cookies?:bool, inherit_server?:bool, trust_auth?:bool, auth?:array<string,mixed>} $options Dispatch options including inheritance and auth trust flags.
	 * @return Request Synthetic request passed to the route handler.
	 */
	private function internalRequestForRoute(array $route, array $definition, array $options): Request {
		$baseRequest=$options['base_request'] ?? null;
		$headers=$baseRequest instanceof Request && ($options['inherit_headers'] ?? true)===true
			? $baseRequest->headers()
			: [];
		$cookies=$baseRequest instanceof Request && ($options['inherit_cookies'] ?? true)===true
			? $baseRequest->cookie()
			: [];
		$server=$baseRequest instanceof Request && ($options['inherit_server'] ?? true)===true
			? $baseRequest->server()
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

	/**
	 * Matches a method/path pair against compiled manifest routes.
	 *
	 * Exact-path matches win before path-regex matches. Regex named captures are
	 * copied into route parameters, and splat parameters are expanded into lists.
	 *
	 * @param array<int, array<string,mixed>|mixed> $routes Compiled route manifest rows.
	 * @param string $method HTTP method.
	 * @param string $path Request path.
	 * @return array<string,mixed>|null Matched route with parameters, or null when not found.
	 */
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
			foreach(($route['splat_parameters'] ?? []) as $parameterName){
				$parameters[$parameterName]=$this->explodeSplatParameter((string)($parameters[$parameterName] ?? ''));
			}
			$route['parameters']=$parameters;
			return $route;
		}
		return null;
	}

	/**
	 * Finds API manifest routes with a matching alias, method, and optional profile.
	 *
	 * @param array<int, array<string,mixed>|mixed> $routes Compiled route manifest rows.
	 * @param string $method HTTP method.
	 * @param string $alias Normalized or raw API alias.
	 * @param ?string $profileName Optional API profile name.
	 * @return array<int, array<string,mixed>> Matching route rows.
	 */
	private function matchManifestRoutesByAlias(array $routes, string $method, string $alias, ?string $profileName=null): array {
		$alias=$this->normalizeAlias($alias);
		$profileName=$this->normalizeProfileName($profileName);
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
			if($profileName!==null && $this->routeProfileName($route)!==$profileName){
				continue;
			}
			$route['parameters']=[];
			$matches[]=$route;
		}
		return $matches;
	}

	/**
	 * Converts a regex splat capture into path segment values.
	 *
	 * @param string $value Captured slash-delimited splat value.
	 * @return array<int, string> Non-empty path segments.
	 */
	private function explodeSplatParameter(string $value): array {
		$value=trim($value, '/');
		if($value===''){
			return [];
		}
		return array_values(array_filter(explode('/', $value), static fn(string $segment): bool => $segment!==''));
	}

	/**
	 * Identifies compiled routes that participate in the API execution surface.
	 *
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @return bool API-aware route marker.
	 */
	private function routeHasApiMetadata(array $route): bool {
		return is_array($route['api'] ?? null);
	}

	/**
	 * Checks a compiled route method list against an incoming dispatch method.
	 *
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @param string $method HTTP method to test.
	 * @return bool Method acceptance decision, including ANY wildcards.
	 */
	private function routeMatchesMethod(array $route, string $method): bool {
		$methods=array_map(static fn(mixed $value): string => strtoupper((string)$value), $route['methods'] ?? ['GET']);
		$method=strtoupper(trim($method));
		return in_array($method, $methods, true) || in_array('ANY', $methods, true);
	}

	/**
	 * Extracts normalized API aliases from a route.
	 *
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @return array<int, string> Unique non-empty aliases.
	 */
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

	/**
	 * Extracts the normalized API profile name from a route.
	 *
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @return ?string Profile name, or null when absent.
	 */
	private function routeProfileName(array $route): ?string {
		return $this->normalizeProfileName($route['api']['profile']['name'] ?? null);
	}

	/**
	 * Resolves route parameters required by an alias-based dispatch.
	 *
	 * Explicit route parameters win, then query values, then body values. Missing
	 * required template parameters return null so callers can emit a 422 failure.
	 *
	 * @param array<string, mixed> $route Matched route manifest row.
	 * @param array<string, mixed> $definition Normalized internal request definition.
	 * @return array<string, mixed>|null Route parameters, or null when required parameters are missing.
	 */
	private function resolveAliasRouteParameters(array $route, array $definition): ?array {
		$routeParameters=is_array($definition['route_parameters'] ?? null) ? $definition['route_parameters'] : [];
		$pathTemplate=(string)($route['path_template'] ?? $route['exact_path'] ?? ($route['api']['path'] ?? '/'));
		if(preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $pathTemplate, $matches)!==1){
			return $routeParameters;
		}
		foreach($matches[1] as $parameterName){
			if(array_key_exists($parameterName, $routeParameters)){
				continue;
			}
			if(array_key_exists($parameterName, $definition['query'] ?? [])){
				$routeParameters[$parameterName]=$definition['query'][$parameterName];
				continue;
			}
			if(array_key_exists($parameterName, $definition['body'] ?? [])){
				$routeParameters[$parameterName]=$definition['body'][$parameterName];
				continue;
			}
			return null;
		}
		return $routeParameters;
	}

	/**
	 * Interpolates route parameters into a route path template.
	 *
	 * @param string $pathTemplate Route path with {parameter} placeholders.
	 * @param array<string, mixed> $parameters Parameter values to encode into the path.
	 * @return ?string Resolved normalized path, or null when a parameter is missing.
	 */
	private function interpolateRoutePathTemplate(string $pathTemplate, array $parameters): ?string {
		$pathTemplate=self::normalizePath($pathTemplate);
		if(preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $pathTemplate, $matches)!==1){
			return $pathTemplate;
		}
		foreach($matches[1] as $parameterName){
			if(array_key_exists($parameterName, $parameters)===false){
				return null;
			}
			$pathTemplate=str_replace('{'.$parameterName.'}', $this->stringifyRouteParameterValue($parameters[$parameterName]), $pathTemplate);
		}
		return self::normalizePath($pathTemplate);
	}

	/**
	 * Encodes a route parameter value for path interpolation.
	 *
	 * @param mixed $value Scalar value or list of splat path segments.
	 * @return string Raw-url-encoded path segment string.
	 */
	private function stringifyRouteParameterValue(mixed $value): string {
		if(is_array($value)){
			return implode('/', array_map(fn(mixed $segment): string => rawurlencode((string)$segment), $value));
		}
		return rawurlencode((string)$value);
	}

	/**
	 * Dispatches a matched compiled route through supported internal execution paths.
	 *
	 * Internal dispatch supports API execution metadata, controller handlers, and
	 * direct callables. Middleware-backed routes are rejected until that boundary
	 * can be represented safely inside internal batch dispatch.
	 *
	 * @param array<string, mixed> $route Matched route manifest row.
	 * @param Request $request Synthetic internal request.
	 * @return Response Normalized route response.
	 */
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

	/**
	 * Invokes a controller handler from a compiled route manifest.
	 *
	 * Required framework modules are inferred from the controller namespace before
	 * static or instance invocation.
	 *
	 * @param array{class?:string, method?:string, static?:bool, bootstrap?:mixed} $handler Compiled controller handler metadata from the route manifest.
	 * @param array<string, mixed> $route Route manifest row.
	 * @param Request $request Synthetic internal request.
	 * @return mixed value returned by the controller method before response normalization.
	 */
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

	/**
	 * Loads bootstrap dependencies declared by a compiled route handler.
	 *
	 * @param mixed $handler Compiled handler metadata that may declare bootstrap dependencies.
	 *
	 * @throws \RuntimeException When the bootstrap target is invalid.
	 */
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

	/**
	 * Infers framework modules required by a controller class namespace.
	 *
	 * @param string $class Fully-qualified class name.
	 * @return array<int, string> Module names that should be loaded before invocation.
	 */
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

	/**
	 * Converts an internal route response into a batch dispatch result row.
	 *
	 * @param array<string, mixed> $definition Normalized internal request definition.
	 * @param array<string, mixed> $route Matched route manifest row.
	 * @param Response $response Route response.
	 * @param float $startedAt Dispatch start timestamp.
	 * @return array<string, mixed> Batch result row.
	 */
	private function normalizeDispatchedResponse(array $definition, array $route, Response $response, float $startedAt): array {
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
			'duration_ms'=>round((microtime(true)-$startedAt)*1000, 3),
		];
	}

	/**
	 * Builds a batch dispatch failure row without invoking a route.
	 *
	 * @param array<string, mixed> $definition Normalized internal request definition.
	 * @param int $status HTTP status for the failure.
	 * @param string $message Failure message.
	 * @param float $startedAt Dispatch start timestamp.
	 * @return array<string, mixed> Batch failure row.
	 */
	private function dispatchFailureRecord(array $definition, int $status, string $message, float $startedAt): array {
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
			'duration_ms'=>round((microtime(true)-$startedAt)*1000, 3),
		];
	}

	/**
	 * Decodes a JSON response body when the response declares JSON content.
	 *
	 * @param Response $response Response to inspect.
	 * @return mixed Decoded JSON body, or null when not JSON or invalid.
	 */
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

	/**
	 * Authorizes one runtime security scheme for a compiled API route.
	 *
	 * The scheme runtime type selects a guard, bearer, basic, API-key, or custom
	 * callback verifier. Documentation-only schemes deliberately fail closed so a
	 * route cannot accidentally expose itself because OpenAPI metadata exists.
	 *
	 * @param string $schemeName Manifest security scheme name.
	 * @param array{runtime?:array<string,mixed>} $scheme Full security scheme definition.
	 * @param Request $request Request being executed.
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @param array<int, string> $scopes Required scopes from the route security requirement.
	 * @return array{authorized:bool, scheme:string, status?:int, message?:string, headers?:array<string,string>, response?:mixed, identity?:mixed, context?:array<string,mixed>, meta?:array<string,mixed>, guard?:string} Authorization result from the selected runtime verifier.
	 */
	private function authorizeScheme(string $schemeName, array $scheme, Request $request, array $route, array $scopes): array {
		$runtime=$scheme['runtime'] ?? [];
		$type=strtolower(trim((string)($runtime['type'] ?? 'docs_only')));
		return match ($type) {
			'guard' => $this->authorizeGuardScheme($schemeName, $runtime),
			'bearer' => $this->authorizeBearerScheme($schemeName, $runtime, $request, $route, $scopes),
			'basic' => $this->authorizeBasicScheme($schemeName, $runtime, $request, $route, $scopes),
			'api_key' => $this->authorizeApiKeyScheme($schemeName, $runtime, $request, $route, $scopes),
			'callback' => $this->authorizeCallbackScheme($schemeName, $runtime, $request, $route, $scopes),
			default => $this->failure($schemeName, $runtime, $runtime['failure_message'] ?? 'Authentication is required for this endpoint.'),
		};
	}

	/**
	 * Shapes a successful authorization result for ApiContext storage.
	 *
	 * The returned data keeps identity, context, metadata, guard, and scopes in
	 * a stable schema consumed by tracing, lifecycle hooks, and execution targets.
	 *
	 * @param string $schemeName Authorized security scheme name.
	 * @param array<int, string> $scopes Granted or required scopes.
	 * @param array<string, mixed> $result Raw verifier result.
	 * @return array{authorized:true, scheme:string, scopes?:array<int,string>, guard?:string, identity?:mixed, context?:array<string,mixed>, meta?:array<string,mixed>} Successful authorization context stored on the request.
	 */
	private function successfulAuthorizationPayload(string $schemeName, array $scopes, array $result): array {
		return array_filter([
			'authorized'=>true,
			'scheme'=>$schemeName,
			'guard'=>isset($result['guard']) && is_string($result['guard']) ? trim($result['guard']) : null,
			'scopes'=>$scopes,
			'identity'=>$result['identity'] ?? ($result['principal'] ?? null),
			'context'=>is_array($result['context'] ?? null) ? $result['context'] : [],
			'meta'=>is_array($result['meta'] ?? null) ? $result['meta'] : [],
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	/**
	 * Applies route-level sanitation rules to the API context.
	 *
	 * Schema definitions are compiled into rules, defaults, and options before
	 * delegating to ApiContext so sanitized values stay attached to the same
	 * execution context used by bindings and handlers.
	 *
	 * @param ApiContext $context Request execution context.
	 * @param array{rules?:array<string,mixed>, defaults?:array<string,mixed>, options?:array<string,mixed>} $schema Compiled sanitation schema definition.
	 * @return \Dataphyre\Sanitation\SanitizationResult Validation result.
	 */
	private function applyRouteSchema(ApiContext $context, array $schema): \Dataphyre\Sanitation\SanitizationResult {
		$rules=is_array($schema['rules'] ?? null) ? $schema['rules'] : [];
		$defaults=is_array($schema['defaults'] ?? null) ? $schema['defaults'] : [];
		$options=is_array($schema['options'] ?? null) ? $schema['options'] : [];
		return $context->validate($rules, $defaults, $options);
	}

	/**
	 * Builds the HTTP response for a failed route schema validation.
	 *
	 * Status, message, and headers come from schema options while validation
	 * errors remain machine-readable for clients and generated API reference output.
	 *
	 * @param \Dataphyre\Sanitation\SanitizationResult $result Failed validation result.
	 * @param array{options?:array{status?:int, message?:string, headers?:array<string,string>}} $schema Compiled sanitation schema definition.
	 * @return Response JSON validation failure response.
	 */
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

	/**
	 * Boots and invokes the route execution target.
	 *
	 * This is the main compiled API handler boundary: optional bootstrap files are
	 * loaded, framework modules are inferred, and a callable is resolved before
	 * passing the ApiContext, Request, and route manifest row to user code.
	 *
	 * @param array<string, mixed> $execution Compiled execution target metadata.
	 * @param ApiContext $context Request execution context.
	 * @param Request $request Incoming request.
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @return mixed value returned by the resolved endpoint handler before response normalization.
	 *
	 * @throws \RuntimeException When the execution target cannot be resolved.
	 */
	private function invokeExecutionTarget(array $execution, ApiContext $context, Request $request, array $route): mixed {
		$this->bootstrapExecutionTarget($execution);
		$this->loadFrameworkModulesForExecutionTarget($execution);
		$callable=$this->resolveExecutionCallable($execution);
		if($callable===null){
			throw new \RuntimeException('API execute target is invalid or unavailable.');
		}
		return $this->invokeCallable($callable, $context, $request, $route);
	}

	/**
	 * Normalizes lifecycle hook metadata into phase-indexed hook lists.
	 *
	 * Only before, after, and error phases are retained. Invalid entries are
	 * dropped so compiled route metadata can be permissive without complicating
	 * the execution loop.
	 *
	 * @param mixed $lifecycle Raw lifecycle definition.
	 * @return array{before?:array<int,array<string,mixed>>, after?:array<int,array<string,mixed>>, error?:array<int,array<string,mixed>>} Phase-indexed hook definitions.
	 */
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

	/**
	 * Runs lifecycle hooks for a route phase until one returns a response.
	 *
	 * A Response short-circuits the phase and lets before/error hooks block normal
	 * execution or after hooks replace the outgoing response.
	 *
	 * @param string $phase Lifecycle phase name.
	 * @param array<string, array<int,array<string,mixed>>> $lifecycle Normalized lifecycle hooks.
	 * @param ApiContext $context Request execution context.
	 * @param Request $request Incoming request.
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @param mixed ...$extra Phase-specific values such as handler result, response, trace, or exception.
	 * @return ?Response Hook-provided response, or null to continue.
	 */
	private function runLifecycleHooks(string $phase, array $lifecycle, ApiContext $context, Request $request, array $route, mixed ...$extra): ?Response {
		$hooks=$lifecycle[$phase] ?? [];
		if(!is_array($hooks) || $hooks===[]){
			return null;
		}
		$payload=[
			'phase'=>$phase,
			'hook_count'=>count($hooks),
			'route'=>$this->apiRouteTraceDescriptor($route),
			'extra_count'=>count($extra),
		];
		$dialback=\dataphyre\core::dialback('CALL_API_FRAMEWORK_LIFECYCLE_BEFORE_RUN', $payload);
		if($dialback instanceof Response){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API lifecycle phase short-circuited before run; phase='.$phase.'; route='.$this->apiRouteTraceLabel($route).'; hooks='.count($hooks).'; status='.$dialback->status, $S=$dialback->status < 400 ? 'info' : 'warning');
			return $dialback;
		}
		foreach($hooks as $hook){
			if(!is_array($hook)){
				continue;
			}
			$result=$this->invokeLifecycleHook($phase, $hook, $context, $request, $route, ...$extra);
			if($result instanceof Response){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API lifecycle phase short-circuited by hook; phase='.$phase.'; route='.$this->apiRouteTraceLabel($route).'; hooks='.count($hooks).'; status='.$result->status, $S=$result->status < 400 ? 'info' : 'warning');
				$dialback=\dataphyre\core::dialback('CALL_API_FRAMEWORK_LIFECYCLE_AFTER_RUN', $payload+['short_circuited'=>true, 'status'=>$result->status]);
				if($dialback instanceof Response){
					return $dialback;
				}
				return $result;
			}
		}
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API lifecycle phase completed; phase='.$phase.'; route='.$this->apiRouteTraceLabel($route).'; hooks='.count($hooks), $S='info');
		$dialback=\dataphyre\core::dialback('CALL_API_FRAMEWORK_LIFECYCLE_AFTER_RUN', $payload+['short_circuited'=>false]);
		if($dialback instanceof Response){
			return $dialback;
		}
		return null;
	}

	/**
	 * Resolves and invokes one lifecycle hook definition.
	 *
	 * Hook arguments are phase-sensitive: before hooks receive context, request,
	 * and route, while after/error hooks also receive the values produced by the
	 * execution path before request and route are appended.
	 *
	 * @param string $phase Lifecycle phase name.
	 * @param array<string, mixed> $hook Compiled lifecycle hook target.
	 * @param ApiContext $context Request execution context.
	 * @param Request $request Incoming request.
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @param mixed ...$extra Phase-specific values.
	 * @return mixed response short-circuit, transformed phase value, or other value returned by the hook.
	 *
	 * @throws \RuntimeException When the hook target cannot be resolved.
	 */
	private function invokeLifecycleHook(string $phase, array $hook, ApiContext $context, Request $request, array $route, mixed ...$extra): mixed {
		$this->bootstrapExecutionTarget($hook);
		$this->loadFrameworkModulesForExecutionTarget($hook);
		$callable=$this->resolveExecutionCallable($hook);
		if($callable===null){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API lifecycle hook resolution failed; phase='.$phase.'; route='.$this->apiRouteTraceLabel($route).'; target_type='.(string)($hook['type'] ?? 'unknown'), $S='warning');
			throw new \RuntimeException('API lifecycle target is invalid or unavailable.');
		}
		$payload=[
			'phase'=>$phase,
			'route'=>$this->apiRouteTraceDescriptor($route),
			'target_type'=>(string)($hook['type'] ?? 'unknown'),
			'class'=>isset($hook['class']) && is_string($hook['class']) ? trim($hook['class'], '\\') : null,
			'method'=>isset($hook['method']) && is_string($hook['method']) ? trim($hook['method']) : null,
			'reference'=>isset($hook['reference']) && is_string($hook['reference']) ? trim($hook['reference']) : null,
			'extra_count'=>count($extra),
		];
		$dialback=\dataphyre\core::dialback('CALL_API_FRAMEWORK_LIFECYCLE_BEFORE_INVOKE', $payload);
		if($dialback instanceof Response){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API lifecycle hook replaced before invoke; phase='.$phase.'; route='.$this->apiRouteTraceLabel($route).'; status='.$dialback->status, $S=$dialback->status < 400 ? 'info' : 'warning');
			return $dialback;
		}
		$args=match ($phase) {
			'after' => array_merge([$context], $extra, [$request, $route]),
			'error' => array_merge([$context], $extra, [$request, $route]),
			default => [$context, $request, $route],
		};
		$result=$this->invokeCallableWithArgs($callable, $args);
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API lifecycle hook invoked; phase='.$phase.'; route='.$this->apiRouteTraceLabel($route).'; result_type='.$this->apiValueType($result), $S=$result instanceof Response && $result->status >= 400 ? 'warning' : 'info');
		$dialback=\dataphyre\core::dialback('CALL_API_FRAMEWORK_LIFECYCLE_AFTER_INVOKE', $payload+[
			'result_type'=>$this->apiValueType($result),
			'status'=>$result instanceof Response ? $result->status : null,
		]);
		return $dialback instanceof Response ? $dialback : $result;
	}

	/**
	 * Resolves a compiled execution target into a PHP callable.
	 *
	 * Supported targets are static or instance class methods and named callables.
	 * Missing class/method metadata or unavailable symbols return null so callers
	 * can attach route-specific failure messages.
	 *
	 * @param array{type?:string, class?:string, method?:string, static?:bool, reference?:string} $execution Compiled execution or lifecycle target.
	 * @return ?callable Resolved callable, or null when unavailable.
	 */
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

	/**
	 * Invokes an API callable with the standard route execution argument prefix.
	 *
	 * @param callable $callable Resolved execution target.
	 * @param ApiContext $context Request execution context.
	 * @param Request $request Incoming request.
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @param mixed ...$extra Additional caller-provided arguments.
	 * @return mixed value returned by the callable after the API context, request, route, and extras are passed.
	 */
	private function invokeCallable(callable $callable, ApiContext $context, Request $request, array $route, mixed ...$extra): mixed {
		$args=array_merge([$context, $request, $route], $extra);
		return $this->invokeCallableWithArgs($callable, $args);
	}

	/**
	 * Invokes a callable while respecting its declared arity.
	 *
	 * Variadic callables receive the full argument list. Non-variadic callables
	 * receive only the prefix they declare, allowing compact handlers and hooks
	 * without adapter layers.
	 *
	 * @param callable $callable Resolved callable.
	 * @param array<int, mixed> $args Candidate argument list.
	 * @return mixed value returned by the callable after its declared argument prefix is applied.
	 */
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

	/**
	 * Runs an execution callback inside the database trace context when available.
	 *
	 * SQL tracing is opt-in per endpoint and requires the database framework to be
	 * loaded. When tracing is disabled or unavailable the callback executes
	 * directly, preserving production behavior without synthetic trace overhead.
	 *
	 * @param array<string, mixed> $traceContext API trace correlation fields.
	 * @param callable $callback Execution callback.
	 * @param array<string, mixed> $traceOptions Normalized trace options.
	 * @return mixed value returned by the callback, optionally wrapped by the SQL trace context.
	 */
	private function executeWithTraceContext(array $traceContext, callable $callback, array $traceOptions): mixed {
		if($this->tracingEnabled()!==true){
			return $callback();
		}
		if($traceContext===[]){
			return $callback();
		}
		if(($traceOptions['include_sql'] ?? true)===true){
			$this->loadFrameworkModule('sql');
		}
		if(class_exists('Dataphyre\\Database\\DB')===false){
			return $callback();
		}
		return \Dataphyre\Database\DB::withTraceContext($traceContext, $callback);
	}

	/**
	 * Builds the endpoint trace payload injected into responses.
	 *
	 * The payload correlates route metadata, validation, binding resolution,
	 * authorization, SQL traces, cache state, and elapsed time behind a stable
	 * response key for endpoint diagnostics and debug surfaces.
	 *
	 * @param array<string, mixed> $traceContext API trace correlation fields.
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @param Request $request Incoming request.
	 * @param ApiContext $context Request execution context.
	 * @param ?\Dataphyre\Sanitation\SanitizationResult $validationResult Optional validation result.
	 * @param array<int, array<string,mixed>> $bindingTrace Binding trace records.
	 * @param float $startedAt Request start timestamp.
	 * @param array<string, mixed> $traceOptions Normalized trace options.
	 * @param array<string, mixed> $cacheTrace Endpoint cache trace descriptor.
	 * @return array<string, mixed> Trace payload ready for JSON serialization.
	 */
	private function buildApiTracePayload(
		array $traceContext,
		array $route,
		Request $request,
		ApiContext $context,
		?\Dataphyre\Sanitation\SanitizationResult $validationResult,
		array $bindingTrace,
		float $startedAt,
		array $traceOptions,
		array $cacheTrace=[]
	): array {
		$api=$route['api'] ?? [];
		$payload=[
			'api_trace_id'=>$traceContext['api_trace_id'] ?? null,
			'endpoint'=>array_filter([
				'path'=>$api['path'] ?? ($route['path_template'] ?? $request->path()),
				'method'=>$request->method(),
				'operation_id'=>$api['operation_id'] ?? null,
			], static fn(mixed $value): bool => $value!==null && $value!==''),
			'duration_ms'=>round((microtime(true)-$startedAt)*1000, 3),
		];
		if($validationResult instanceof \Dataphyre\Sanitation\SanitizationResult){
			$payload['validation']=[
				'passed'=>$validationResult->passed(),
				'errors'=>$validationResult->errors(),
			];
		}
		if(($traceOptions['include_bindings'] ?? true)===true && $bindingTrace!==[]){
			$payload['bindings']=$bindingTrace;
		}
		if(($traceOptions['include_auth'] ?? true)===true && $context->hasAuth()){
			$payload['auth']=$this->authTracePayload($context);
		}
		if(($traceOptions['include_sql'] ?? true)===true){
			$payload['sql']=$this->recentSqlTracePayload($traceContext, (int)($traceOptions['sql_limit'] ?? 50));
		}
		if($cacheTrace!==[]){
			$payload['cache']=$this->apiEndpointCacheTracePayload($cacheTrace);
		}
		return array_filter($payload, static fn(mixed $value): bool => $value!==null);
	}

	/**
	 * Retrieves recent SQL traces associated with an API trace id.
	 *
	 * @param array<string, mixed> $traceContext API trace correlation fields.
	 * @param int $limit Maximum trace rows to expose.
	 * @return array<int, array<string, mixed>> Serialized SQL execution traces.
	 */
	private function recentSqlTracePayload(array $traceContext, int $limit): array {
		if($this->tracingEnabled()!==true){
			return [];
		}
		if(($traceContext['api_trace_id'] ?? null)===null){
			return [];
		}
		$this->loadFrameworkModule('sql');
		if(class_exists('Dataphyre\\Database\\DB')===false){
			return [];
		}
		return array_map(
			static fn(\Dataphyre\Database\ExecutionTrace $trace): array => $trace->toArray(),
			\Dataphyre\Database\DB::recentTracesByContext(['api_trace_id'=>$traceContext['api_trace_id']], max(1, $limit))
		);
	}

	/**
	 * Creates the trace correlation context for one endpoint execution.
	 *
	 * The context is passed to database and binding layers so downstream records
	 * can be joined back to the API response trace.
	 *
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @param Request $request Incoming request.
	 * @param array<string, mixed> $traceOptions Normalized trace options.
	 * @return array<string, string> Trace correlation fields.
	 */
	private function createApiTraceContext(array $route, Request $request, array $traceOptions): array {
		$api=$route['api'] ?? [];
		return array_filter([
			'api_trace_id'=>$this->newTraceId(),
			'api_endpoint'=>$api['path'] ?? ($route['path_template'] ?? $request->path()),
			'api_operation_id'=>$api['operation_id'] ?? null,
			'api_method'=>$request->method(),
			'api_trace_mode'=>'endpoint',
		], static fn(mixed $value): bool => $value!==null && $value!=='');
	}

	/**
	 * Normalizes route trace configuration against runtime tracing availability.
	 *
	 * When runtime tracing is disabled the response key and header are still
	 * normalized, but all trace collection switches are forced off.
	 *
	 * @param mixed $trace Raw route trace configuration.
	 * @return array{enabled:bool, include_bindings:bool, include_auth:bool, include_sql:bool, sql_limit:int, response_key:string, header:string} Normalized trace options.
	 */
	private function normalizeTraceOptions(mixed $trace): array {
		if($this->tracingEnabled()!==true){
			$responseKey='trace';
			$header='X-Dataphyre-Api-Trace';
			if(is_array($trace)){
				$responseKey=trim((string)($trace['response_key'] ?? $responseKey)) ?: 'trace';
				$header=trim((string)($trace['header'] ?? $header)) ?: 'X-Dataphyre-Api-Trace';
			}
			return [
				'enabled'=>false,
				'include_bindings'=>false,
				'include_auth'=>false,
				'include_sql'=>false,
				'sql_limit'=>0,
				'response_key'=>$responseKey,
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

	/**
	 * Builds the persistent endpoint cache descriptor for a route execution.
	 *
	 * The descriptor captures cacheability, identity, TTL, names, and bypass
	 * reasons. Binding cache identities become part of the endpoint identity so
	 * endpoint responses invalidate consistently with the data they depend on.
	 *
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @param Request $request Incoming request.
	 * @param ApiContext $context Request execution context.
	 * @param array<int, array<string,mixed>> $bindings Compiled route binding definitions.
	 * @param array<string, mixed> $traceContext API trace correlation fields.
	 * @param array<string, mixed>|null $cacheDefinition Route cache definition.
	 * @return array<string, mixed> Endpoint cache descriptor and current state.
	 */
	private function endpointCacheDescriptor(
		array $route,
		Request $request,
		ApiContext $context,
		array $bindings,
		array $traceContext,
		?array $cacheDefinition
	): array {
		if($cacheDefinition===null){
			return [
				'enabled'=>false,
				'cacheable'=>false,
				'state'=>'bypass',
				'layer'=>'none',
			];
		}

		$allowUntrackedBindings=($cacheDefinition['allow_untracked_bindings'] ?? false)===true;
		$bindingIdentities=[];
		$bindingQueryCacheNames=[];
		$bindingSequence=0;
		$bindingReason=null;

		foreach($bindings as $bindingEntry){
			$path=trim((string)($bindingEntry['path'] ?? ''));
			$definition=is_array($bindingEntry['definition'] ?? null) ? $bindingEntry['definition'] : null;
			if($path==='' || $definition===null){
				continue;
			}
			$bindingContext=$this->bindingContextForApi($context, [], $path, $traceContext, ++$bindingSequence);
			$binding=$this->bindingFromDefinition($path, $definition);
			$metadata=$binding instanceof \Dataphyre\Templating\BindingMetadataProvider
				? $binding->metadata()
				: [];
			$bindingQueryCacheNames=array_merge(
				$bindingQueryCacheNames,
				$this->normalizeEndpointCacheNames($metadata['query_cache_names'] ?? [])
			);
			if(!$binding instanceof \Dataphyre\Templating\BindingCacheIdentityProvider){
				if($allowUntrackedBindings!==true){
					$bindingReason="Binding '{$path}' does not expose cache identity.";
					break;
				}
				continue;
			}
			$identity=$this->normalizeBindingCacheIdentity($binding->cacheIdentity($bindingContext));
			if($identity===null){
				if($allowUntrackedBindings!==true){
					$bindingReason="Binding '{$path}' does not expose cache identity.";
					break;
				}
				continue;
			}
			$bindingIdentities[$path]=$identity;
		}

		if($bindingReason!==null){
			return [
				'enabled'=>true,
				'cacheable'=>false,
				'state'=>'bypass',
				'layer'=>'none',
				'reason'=>$bindingReason,
				'names'=>$this->normalizeEndpointCacheNames(array_merge(
					$this->normalizeEndpointCacheNames($cacheDefinition['names'] ?? []),
					$bindingQueryCacheNames
				)),
			];
		}

		$varyHeaders=$this->selectedRequestValues($request->headers(), $cacheDefinition['vary_headers'] ?? []);
		$varyCookies=$this->selectedRequestValues($request->cookie(), $cacheDefinition['vary_cookies'] ?? []);
		$extraIdentity=$this->normalizeEndpointCacheIdentityValue($cacheDefinition['identity'] ?? null);
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
				'route'=>$this->normalizeEndpointCacheIdentityValue($request->routeParameters()),
				'headers'=>$varyHeaders!==[] ? $this->normalizeEndpointCacheIdentityValue($varyHeaders) : null,
				'cookies'=>$varyCookies!==[] ? $this->normalizeEndpointCacheIdentityValue($varyCookies) : null,
			], static fn(mixed $value): bool => $value!==null && $value!==[]),
			'auth'=>$context->hasAuth() ? $this->normalizeEndpointCacheIdentityValue($context->auth()) : null,
			'bindings'=>$bindingIdentities!==[] ? $bindingIdentities : null,
			'identity'=>$extraIdentity,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);

		if($identity===[]){
			return [
				'enabled'=>true,
				'cacheable'=>false,
				'state'=>'bypass',
				'layer'=>'none',
				'reason'=>'Endpoint cache identity is empty.',
				'names'=>$this->normalizeEndpointCacheNames(array_merge(
					$this->normalizeEndpointCacheNames($cacheDefinition['names'] ?? []),
					$bindingQueryCacheNames
				)),
			];
		}

		$names=$this->normalizeEndpointCacheNames(array_merge(
			$this->normalizeEndpointCacheNames($cacheDefinition['names'] ?? []),
			(($cacheDefinition['inherit_binding_cache_names'] ?? true)===true ? $bindingQueryCacheNames : [])
		));
		$ttl=max(1, (int)($cacheDefinition['ttl'] ?? 300));
		return [
			'enabled'=>true,
			'cacheable'=>true,
			'state'=>'miss',
			'layer'=>'persistent',
			'key'=>sha1(json_encode($identity)),
			'identity'=>$identity,
			'ttl'=>$ttl,
			'names'=>$names,
			'store_errors'=>($cacheDefinition['store_errors'] ?? false)===true,
			'source_names'=>$bindingQueryCacheNames,
		];
	}

	/**
	 * Creates the pre-execution cache trace view for a descriptor.
	 *
	 * @param array<string, mixed> $descriptor Endpoint cache descriptor.
	 * @return array<string, mixed> Trace-safe cache state.
	 */
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

	/**
	 * Loads a previously stored endpoint response from persistent cache.
	 *
	 * Expired, unreadable, or malformed entries are removed and treated as misses
	 * so callers never execute with partial cache state.
	 *
	 * @param array<string, mixed> $descriptor Endpoint cache descriptor.
	 * @return array{hit:bool, stored_at?:int, response?:Response} Cache hit record with Response, or hit=false.
	 */
	private function loadCachedEndpointResponse(array $descriptor): array {
		if(($descriptor['cacheable'] ?? false)!==true){
			return ['hit'=>false];
		}
		$file=$this->endpointCacheItemFile((string)($descriptor['key'] ?? ''));
		if(!is_file($file)){
			return ['hit'=>false];
		}
		$serializedEntry=@file_get_contents($file);
		if(!is_string($serializedEntry) || $serializedEntry===''){
			return ['hit'=>false];
		}
		try{
			$decoded=@unserialize($serializedEntry);
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
		$responsePayload=is_array($decoded['response'] ?? null) ? $decoded['response'] : [];
		return [
			'hit'=>true,
			'stored_at'=>(int)($decoded['stored_at'] ?? 0),
			'response'=>new Response(
				(string)($responsePayload['body'] ?? ''),
				(int)($responsePayload['status'] ?? 200),
				is_array($responsePayload['headers'] ?? null) ? $responsePayload['headers'] : []
			),
		];
	}

	/**
	 * Stores a normalized endpoint response in the persistent endpoint cache.
	 *
	 * Trace metadata is stripped before persistence, named indexes are updated for
	 * later invalidation, and serialization/write failures are reported in the
	 * returned cache trace instead of interrupting endpoint delivery.
	 *
	 * @param array<string, mixed> $descriptor Endpoint cache descriptor.
	 * @param Response $response Final endpoint response.
	 * @param array<string, mixed> $traceOptions Normalized trace options.
	 * @return array<string, mixed> Cache trace after the store attempt.
	 */
	private function storeEndpointCacheResponse(array $descriptor, Response $response, array $traceOptions): array {
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

		$cacheResponse=$this->responseForEndpointCacheStorage($response, $traceOptions);
		$root=$this->endpointCacheRoot();
		$itemsDir=$root.'items'.DIRECTORY_SEPARATOR;
		$namesDir=$root.'names'.DIRECTORY_SEPARATOR;
		if(!is_dir($itemsDir)){
			@mkdir($itemsDir, 0777, true);
		}
		if(!is_dir($namesDir)){
			@mkdir($namesDir, 0777, true);
		}
		try{
			$payload=serialize([
				'stored_at'=>time(),
				'expires_at'=>time()+max(1, (int)($descriptor['ttl'] ?? 300)),
				'names'=>$descriptor['names'] ?? [],
				'response'=>[
					'status'=>$cacheResponse->status,
					'headers'=>$cacheResponse->headers,
					'body'=>$cacheResponse->body,
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

	/**
	 * Shapes endpoint cache state for trace output.
	 *
	 * @param array<string, mixed> $cacheTrace Raw endpoint cache descriptor or store result.
	 * @return array{enabled?:bool, cacheable?:bool, state?:string, layer?:string, key?:string, ttl?:int, names?:list<string>, source_names?:list<string>, reason?:string, stored_at?:int} Endpoint cache trace fields safe for response output.
	 */
	private function apiEndpointCacheTracePayload(array $cacheTrace): array {
		return array_filter([
			'enabled'=>($cacheTrace['enabled'] ?? false)===true,
			'cacheable'=>($cacheTrace['cacheable'] ?? false)===true,
			'state'=>$this->traceString($cacheTrace['state'] ?? null),
			'layer'=>$this->traceString($cacheTrace['layer'] ?? null),
			'key'=>$this->traceString($cacheTrace['key'] ?? null),
			'ttl'=>isset($cacheTrace['ttl']) ? (int)$cacheTrace['ttl'] : null,
			'names'=>$this->normalizeEndpointCacheNames($cacheTrace['names'] ?? []),
			'source_names'=>$this->normalizeEndpointCacheNames($cacheTrace['source_names'] ?? []),
			'reason'=>$this->traceString($cacheTrace['reason'] ?? null),
			'stored_at'=>isset($cacheTrace['stored_at']) ? (int)$cacheTrace['stored_at'] : null,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	/**
	 * Shapes authorization context for endpoint trace output.
	 *
	 * Sensitive values are intentionally reduced to identity type, scopes, and key
	 * names so traces expose structure without leaking credentials or principals.
	 *
	 * @param ApiContext $context Request execution context.
	 * @return array{scheme?:string, guard?:mixed, identity_type?:string, scopes?:array<int,string>, context_keys?:list<string>, meta_keys?:list<string>} Authorization trace fields without credentials or principal data.
	 */
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

	/**
	 * Converts handler output into an HTTP response and attaches trace metadata.
	 *
	 * Responses pass through unchanged except for trace injection. Arrays become
	 * JSON payloads, null becomes no-content, JsonSerializable values remain JSON,
	 * and scalars are wrapped under data for a predictable client contract.
	 *
	 * @param mixed $result Handler or lifecycle result.
	 * @param array<string, mixed>|null $tracePayload Optional endpoint trace payload.
	 * @param array<string, mixed> $traceOptions Normalized trace options.
	 * @return Response Normalized HTTP response.
	 */
	private function normalizeExecutionResponse(mixed $result, ?array $tracePayload, array $traceOptions): Response {
		$headers=$tracePayload!==null
			? [$traceOptions['header']=>$tracePayload['api_trace_id'] ?? '']
			: [];
		if($result instanceof Response){
			return $this->applyTraceToResponse($result, $tracePayload, $traceOptions);
		}
		if($result===null){
			$response=Response::noContent();
			return $this->applyTraceToResponse($response, $tracePayload, $traceOptions);
		}
		if(is_array($result)){
			$payload=$tracePayload!==null
				? $this->injectTraceIntoPayload($result, $tracePayload, $traceOptions['response_key'])
				: $result;
			return Response::json($payload, 200, $headers);
		}
		if($result instanceof \JsonSerializable){
			$payload=$tracePayload!==null
				? [
					'data'=>$result,
					$traceOptions['response_key']=>$tracePayload,
				]
				: $result;
			return Response::json($payload, 200, $headers);
		}
		return Response::json(
			$tracePayload!==null
				? [
					'data'=>$result,
					$traceOptions['response_key']=>$tracePayload,
				]
				: ['data'=>$result],
			200,
			$headers
		);
	}

	/**
	 * Adds trace headers and, when possible, trace JSON to an existing response.
	 *
	 * Non-JSON responses and JSON lists keep their body untouched, while object
	 * payloads receive the configured trace key.
	 *
	 * @param Response $response Response to decorate.
	 * @param array<string, mixed>|null $tracePayload Optional endpoint trace payload.
	 * @param array<string, mixed> $traceOptions Normalized trace options.
	 * @return Response Response with trace metadata applied.
	 */
	private function applyTraceToResponse(Response $response, ?array $tracePayload, array $traceOptions): Response {
		if($tracePayload===null){
			return $response;
		}
		$headers=array_replace($response->headers, [
			$traceOptions['header']=>$tracePayload['api_trace_id'] ?? '',
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
		$payload=$this->injectTraceIntoPayload($decoded, $tracePayload, $traceOptions['response_key']);
		$encoded=json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return new Response($encoded===false ? $response->body : $encoded, $response->status, $headers);
	}

	/**
	 * Inserts trace data into an associative JSON payload without overwriting data.
	 *
	 * List payloads are wrapped under data. Object payloads use the configured key
	 * unless it already exists, in which case an underscored sibling is used.
	 *
	 * @param array<string|int, mixed> $payload Response payload.
	 * @param array<string, mixed> $tracePayload Endpoint trace payload.
	 * @param string $responseKey Preferred trace response key.
	 * @return array<string|int, mixed> Payload with trace data.
	 */
	private function injectTraceIntoPayload(array $payload, array $tracePayload, string $responseKey): array {
		if($this->isAssociativeArray($payload)===false){
			return [
				'data'=>$payload,
				$responseKey=>$tracePayload,
			];
		}
		$key=array_key_exists($responseKey, $payload) ? '_'.$responseKey : $responseKey;
		$payload[$key]=$tracePayload;
		return $payload;
	}

	/**
	 * Removes transient trace metadata before endpoint response persistence.
	 *
	 * Cache entries store reusable response content only; per-request trace headers
	 * and trace payload keys are removed so cache hits can receive fresh metadata.
	 *
	 * @param Response $response Response selected for cache storage.
	 * @param array<string, mixed> $traceOptions Normalized trace options.
	 * @return Response Cache-safe response.
	 */
	private function responseForEndpointCacheStorage(Response $response, array $traceOptions): Response {
		if(($traceOptions['enabled'] ?? false)!==true){
			return $response;
		}
		$headers=$this->withoutHeaderCaseInsensitive($response->headers, (string)($traceOptions['header'] ?? 'X-Dataphyre-Api-Trace'));
		if($this->isJsonResponse($response)===false || trim($response->body)===''){
			return new Response($response->body, $response->status, $headers);
		}
		$decoded=json_decode($response->body, true);
		if(is_array($decoded)===false || $this->isAssociativeArray($decoded)===false){
			return new Response($response->body, $response->status, $headers);
		}
		unset($decoded[(string)($traceOptions['response_key'] ?? 'trace')], $decoded['_'.(string)($traceOptions['response_key'] ?? 'trace')]);
		$encoded=json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return new Response($encoded===false ? $response->body : $encoded, $response->status, $headers);
	}

	/**
	 * Decides whether a response status can be persisted in endpoint cache.
	 *
	 * @param Response $response Response produced by the endpoint.
	 * @param array<string, mixed> $descriptor Endpoint cache descriptor.
	 * @return bool Cache storage decision.
	 */
	private function isEndpointResponseCacheable(Response $response, array $descriptor): bool {
		if(($descriptor['store_errors'] ?? false)===true){
			return true;
		}
		return $response->status >= 200 && $response->status < 300;
	}

	/**
	 * Detects JSON responses by content-type header.
	 *
	 * @param Response $response Response to inspect.
	 * @return bool JSON content-type decision.
	 */
	private function isJsonResponse(Response $response): bool {
		foreach($response->headers as $name=>$value){
			if(strtolower((string)$name)!=='content-type'){
				continue;
			}
			return stripos((string)$value, 'application/json')!==false;
		}
		return false;
	}

	/**
	 * Distinguishes JSON objects from JSON lists after decoding to arrays.
	 *
	 * Empty arrays are treated as lists to avoid injecting trace keys into a value
	 * that may have been intended as an empty collection.
	 *
	 * @param array<string|int, mixed> $payload Decoded JSON payload.
	 * @return bool Associative-object decision.
	 */
	private function isAssociativeArray(array $payload): bool {
		return $payload!==[] && array_keys($payload)!==range(0, count($payload)-1);
	}

	/**
	 * Selects named request values for endpoint cache variation.
	 *
	 * Header and cookie names are matched case-insensitively, while the configured
	 * names are preserved as identity keys to keep cache fingerprints stable.
	 *
	 * @param array<string, mixed> $source Request header or cookie map.
	 * @param array|string|null $names Configured names to include.
	 * @return array<string, mixed> Selected request values keyed by configured name.
	 */
	private function selectedRequestValues(array $source, array|string|null $names): array {
		$names=$this->normalizeEndpointCacheNames($names ?? []);
		if($names===[]){
			return [];
		}
		$selected=[];
		foreach($names as $name){
			foreach($source as $sourceName=>$value){
				if(strtolower((string)$sourceName)!==strtolower($name)){
					continue;
				}
				$selected[$name]=$value;
				break;
			}
		}
		return $selected;
	}

	/**
	 * Normalizes endpoint cache names used for grouped invalidation.
	 *
	 * Names are de-duplicated without changing case, because cache invalidation
	 * names are operator-facing labels rather than HTTP header identifiers.
	 *
	 * @param array|string|null $names Raw cache name list.
	 * @return array<int, string> Unique non-empty cache names.
	 */
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

	/**
	 * Normalizes arbitrary endpoint cache identity values.
	 *
	 * This delegates to binding identity normalization so endpoint-level and
	 * binding-level fingerprints serialize complex values the same way.
	 *
	 * @param mixed $value Raw identity value.
	 * @return mixed Stable identity value, or null when absent.
	 */
	private function normalizeEndpointCacheIdentityValue(mixed $value): mixed {
		if($value===null){
			return null;
		}
		return $this->normalizeBindingCacheIdentityValue($value);
	}

	/**
	 * Returns the root directory for persistent endpoint cache files.
	 *
	 * @return string Absolute cache root ending with a directory separator.
	 */
	private function endpointCacheRoot(): string {
		return rtrim(ROOTPATH['dataphyre'].'cache/api/endpoints/', '/\\').DIRECTORY_SEPARATOR;
	}

	/**
	 * Builds the item cache filename for an endpoint cache key.
	 *
	 * @param string $key SHA-1 endpoint cache key.
	 * @return string Absolute cache item filename.
	 */
	private function endpointCacheItemFile(string $key): string {
		return $this->endpointCacheRoot().'items'.DIRECTORY_SEPARATOR.$key.'.cache';
	}

	/**
	 * Builds the grouped-invalidation index filename for a cache name.
	 *
	 * @param string $name Cache invalidation name.
	 * @param string $namesDir Absolute names directory.
	 * @return string Absolute cache name index filename.
	 */
	private function endpointCacheNameFile(string $name, string $namesDir): string {
		return $namesDir.sha1($name).'.json';
	}

	/**
	 * Adds an endpoint cache key to a named invalidation index.
	 *
	 * Index files are JSON arrays of cache keys. Write failures are intentionally
	 * non-fatal because a response should not fail after successful handler
	 * execution solely due to cache bookkeeping.
	 *
	 * @param string $name Cache invalidation name.
	 * @param string $key Endpoint cache key.
	 */
	private function indexEndpointCacheName(string $name, string $key): void {
		$root=$this->endpointCacheRoot();
		$namesDir=$root.'names'.DIRECTORY_SEPARATOR;
		if(!is_dir($namesDir)){
			@mkdir($namesDir, 0777, true);
		}
		$file=$this->endpointCacheNameFile($name, $namesDir);
		$existing=@file_get_contents($file);
		$keys=json_decode(is_string($existing) ? $existing : '[]', true);
		$keys=is_array($keys) ? $keys : [];
		$keys[]=$key;
		$keys=array_values(array_unique(array_filter($keys, static fn(mixed $value): bool => is_string($value) && $value!=='')));
		@file_put_contents($file, json_encode($keys, JSON_PRETTY_PRINT), LOCK_EX);
	}

	/**
	 * Deletes persistent endpoint cache item and name index files.
	 *
	 * Only files directly under the supplied cache directories are removed, then
	 * empty directories are pruned. The returned count reports deleted files, not
	 * directories.
	 *
	 * @param string $itemsDir Absolute endpoint cache items directory.
	 * @param string $namesDir Absolute endpoint cache names directory.
	 * @return int Number of cache files deleted.
	 */
	private function clearPersistentCacheDirectories(string $itemsDir, string $namesDir): int {
		$deleted=0;
		foreach([$itemsDir, $namesDir] as $dir){
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
		$root=dirname(rtrim($itemsDir, '/\\')).DIRECTORY_SEPARATOR;
		@rmdir($root);
		return $deleted;
	}

	/**
	 * Removes a response header without depending on its original casing.
	 *
	 * @param array<string, string|array<int,string>> $headers Response header map.
	 * @param string $target Header name to remove.
	 * @return array<string, string|array<int,string>> Header map without the target header.
	 */
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

	/**
	 * Loads bootstrap dependencies declared by an API execution target.
	 *
	 * The special core bootstrap loads Dataphyre core once; filesystem bootstrap
	 * values must point at an existing file. Invalid targets fail loudly because
	 * execution metadata is part of the compiled route contract.
	 *
	 * @param array{bootstrap?:mixed} $execution Compiled execution, binding, or lifecycle target.
	 *
	 * @throws \RuntimeException When the bootstrap target is invalid.
	 */
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

	/**
	 * Loads framework modules inferred from an execution target class namespace.
	 *
	 * @param array{class?:string} $execution Compiled execution, binding, or lifecycle target.
	 */
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

	/**
	 * Requests one Dataphyre framework module from the core loader when available.
	 *
	 * @param string $module Framework module name.
	 */
	private function loadFrameworkModule(string $module): void {
		if(class_exists('\dataphyre\core', false)){
			\dataphyre\core::load_framework_module($module);
		}
	}

	/**
	 * Requests multiple Dataphyre framework modules from the core loader.
	 *
	 * @param array<int, string> $modules Framework module names.
	 */
	private function loadFrameworkModules(array $modules): void {
		if(class_exists('\dataphyre\core', false)){
			\dataphyre\core::load_framework_modules($modules);
		}
	}

	/**
	 * Resolves compiled route bindings into the API context.
	 *
	 * Bindings execute in declaration order, can read previously resolved binding
	 * values, reuse request-local cache entries by cache identity, and optionally
	 * emit trace records for Flightdeck diagnostics.
	 *
	 * @param ApiContext $context Request execution context to mutate.
	 * @param array<int, array{path?:string, definition?:array<string,mixed>}> $bindings Compiled route binding entries.
	 * @param array<string, mixed> $traceContext API trace correlation fields.
	 */
	private function resolveRouteBindings(ApiContext $context, array $bindings, array $traceContext=[]): void {
		$this->loadFrameworkModule('templating');
		$resolved=[];
		$trace=[];
		$cache=[];
		$sequence=0;
		$tracingEnabled=$this->tracingEnabled()===true;

		foreach($bindings as $bindingEntry){
			$path=trim((string)($bindingEntry['path'] ?? ''));
			$definition=is_array($bindingEntry['definition'] ?? null) ? $bindingEntry['definition'] : null;
			if($path==='' || $definition===null){
				continue;
			}

			$bindingContext=$this->bindingContextForApi($context, $resolved, $path, $traceContext, ++$sequence);
			$binding=$this->bindingFromDefinition($path, $definition);
			$metadata=$binding instanceof \Dataphyre\Templating\BindingMetadataProvider
				? $binding->metadata()
				: [];
			$identity=$binding instanceof \Dataphyre\Templating\BindingCacheIdentityProvider
				? $this->normalizeBindingCacheIdentity($binding->cacheIdentity($bindingContext))
				: null;
			$cacheKey=$identity!==null ? sha1(json_encode($identity)) : null;
			$startedAt=microtime(true);
			$reused=false;
			$skipped=false;

			if($cacheKey!==null && array_key_exists($cacheKey, $cache)){
				$value=$cache[$cacheKey];
				$reused=true;
			}else{
				$value=$this->resolveApiBindingWithTraceContext($binding, $bindingContext, $metadata, $traceContext, $path);
				if($value instanceof \Dataphyre\Templating\BindingResolution){
					$skipped=$value->isSkipped();
					$value=$value->result();
				}
				if($cacheKey!==null){
					$cache[$cacheKey]=$value;
				}
			}

			$this->setArrayValueByPath($resolved, $path, $value);
			if($tracingEnabled){
				$trace[]=$this->apiBindingTraceRecord(
					$path,
					$binding,
					$bindingContext,
					$metadata,
					$value,
					$identity,
					$cacheKey,
					$reused,
					$skipped,
					microtime(true)-$startedAt
				);
			}
		}

		$context->withBindings($resolved, $tracingEnabled ? $trace : []);
	}

	/**
	 * Creates a templating data binding from compiled API metadata.
	 *
	 * @param string $path Binding destination path in the API context.
	 * @param array{type?:string} $definition Compiled binding definition.
	 * @return \Dataphyre\Templating\DataBinding Runtime binding instance.
	 *
	 * @throws \RuntimeException When the binding type is unsupported.
	 */
	private function bindingFromDefinition(string $path, array $definition): \Dataphyre\Templating\DataBinding {
		$type=strtolower(trim((string)($definition['type'] ?? '')));
		return match ($type) {
			'callable' => $this->callableBindingFromDefinition($path, $definition),
			'sql_query' => $this->sqlBindingFromDefinition($path, $definition),
			'search_query' => $this->searchBindingFromDefinition($path, $definition),
			default => throw new \RuntimeException("Unsupported API binding type '{$type}' for '{$path}'."),
		};
	}

	/**
	 * Creates a callable-backed API data binding.
	 *
	 * Callable bindings reuse the same execution target resolution path as route
	 * handlers, then expose optional identity metadata for request-local and
	 * endpoint cache cooperation.
	 *
	 * @param string $path Binding destination path.
	 * @param array{target?:array<string,mixed>, identity?:mixed} $definition Compiled callable binding definition.
	 * @return \Dataphyre\Templating\DataBinding Callable binding instance.
	 *
	 * @throws \RuntimeException When the callable target is missing or invalid.
	 */
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

	/**
	 * Rehydrates a SQL query binding from compiled query execution state.
	 *
	 * Repository and table query state are the only supported SQL query carriers,
	 * preserving query-builder identity while avoiding runtime parsing of SQL text.
	 *
	 * @param string $path Binding destination path.
	 * @param array{query_class?:string, query_state?:array<string,mixed>, options?:array<string,mixed>, mode?:string, inherit_query_identity?:bool} $definition Compiled SQL binding definition.
	 * @return \Dataphyre\Templating\DataBinding SQL query binding.
	 *
	 * @throws \RuntimeException When the compiled query class is unsupported.
	 */
	private function sqlBindingFromDefinition(string $path, array $definition): \Dataphyre\Templating\DataBinding {
		$this->loadFrameworkModules(['templating', 'sql']);
		$queryState=is_array($definition['query_state'] ?? null) ? $definition['query_state'] : [];
		$queryClass=trim((string)($definition['query_class'] ?? ''));
		$query=match ($queryClass) {
			'Dataphyre\\Database\\RepositoryQuery' => \Dataphyre\Database\RepositoryQuery::fromExecutionState($queryState),
			'Dataphyre\\Database\\TableQuery' => \Dataphyre\Database\TableQuery::fromExecutionState($queryState),
			default => throw new \RuntimeException("Unsupported API SQL binding query class '{$queryClass}' for '{$path}'."),
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

	/**
	 * Rehydrates a fulltext search binding from compiled query execution state.
	 *
	 * @param string $path Binding destination path.
	 * @param array{query_class?:string, query_state?:array<string,mixed>, options?:array<string,mixed>, mode?:string, inherit_query_identity?:bool} $definition Compiled search binding definition.
	 * @return \Dataphyre\Templating\DataBinding Search query binding.
	 *
	 * @throws \RuntimeException When the compiled query class is unsupported.
	 */
	private function searchBindingFromDefinition(string $path, array $definition): \Dataphyre\Templating\DataBinding {
		$this->loadFrameworkModules(['templating', 'fulltext_engine']);
		$queryState=is_array($definition['query_state'] ?? null) ? $definition['query_state'] : [];
		$queryClass=trim((string)($definition['query_class'] ?? ''));
		if($queryClass!=='Dataphyre\\FulltextEngine\\Query'){
			throw new \RuntimeException("Unsupported API search binding query class '{$queryClass}' for '{$path}'.");
		}
		$query=\Dataphyre\FulltextEngine\Query::fromExecutionState($queryState);
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

	/**
	 * Builds the templating binding context used during API binding resolution.
	 *
	 * Previously resolved bindings are exposed under bindings, the API context and
	 * route are passed as ambient metadata, and trace ids are attached only when
	 * runtime tracing is enabled.
	 *
	 * @param ApiContext $context Request execution context.
	 * @param array<string, mixed> $resolved Bindings resolved earlier in the same route.
	 * @param string $path Binding destination path.
	 * @param array<string, mixed> $traceContext API trace correlation fields.
	 * @param int $sequence One-based binding sequence.
	 * @return \Dataphyre\Templating\BindingContext Binding execution context.
	 */
	private function bindingContextForApi(ApiContext $context, array $resolved, string $path, array $traceContext, int $sequence): \Dataphyre\Templating\BindingContext {
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
		$apiTraceId=is_string($traceContext['api_trace_id'] ?? null) ? $traceContext['api_trace_id'] : $this->newTraceId();
		$bindingTraceId=$apiTraceId.'.b'.str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
		return new \Dataphyre\Templating\BindingContext(
			'api:'.($traceContext['api_endpoint'] ?? $context->path()),
			false,
			array_replace($context->bindingData(), ['bindings'=>$resolved]),
			[],
			[],
			[
				'api_context'=>$context,
				'api_route'=>$context->route(),
			],
			array_filter([
				'api_trace_id'=>$apiTraceId,
				'api_endpoint'=>$traceContext['api_endpoint'] ?? $context->path(),
				'api_method'=>$traceContext['api_method'] ?? $context->method(),
				'binding_trace_id'=>$bindingTraceId,
				'binding_path'=>$path,
			], static fn(mixed $value): bool => $value!==null && $value!=='')
		);
	}

	/**
	 * Resolves a binding inside the SQL trace context when the binding is SQL-backed.
	 *
	 * Non-SQL bindings and disabled tracing use direct resolution. SQL-backed
	 * bindings receive query fingerprint and identity metadata so database traces
	 * can be correlated with both the endpoint and binding path.
	 *
	 * @param \Dataphyre\Templating\DataBinding $binding Binding to resolve.
	 * @param \Dataphyre\Templating\BindingContext $context Binding execution context.
	 * @param array<string, mixed> $metadata Binding metadata.
	 * @param array<string, mixed> $traceContext API trace correlation fields.
	 * @param string $path Binding destination path.
	 * @return mixed resolved binding value, or a BindingResolution wrapper when the binding driver supplies one.
	 */
	private function resolveApiBindingWithTraceContext(
		\Dataphyre\Templating\DataBinding $binding,
		\Dataphyre\Templating\BindingContext $context,
		array $metadata,
		array $traceContext,
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
				'api_trace_id'=>$traceContext['api_trace_id'] ?? null,
				'api_endpoint'=>$traceContext['api_endpoint'] ?? null,
				'api_method'=>$traceContext['api_method'] ?? null,
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

	/**
	 * Builds a detailed trace record for one resolved API binding.
	 *
	 * The record keeps both low-level metadata and a nested trace payload so
	 * consumers can choose between full diagnostics and a concise UI-safe view.
	 *
	 * @param string $path Binding destination path.
	 * @param \Dataphyre\Templating\DataBinding $binding Resolved binding instance.
	 * @param \Dataphyre\Templating\BindingContext $bindingContext Binding execution context.
	 * @param array<string, mixed> $metadata Binding metadata.
	 * @param mixed $value Binding result value.
	 * @param array<string, mixed>|null $identity Normalized binding cache identity.
	 * @param ?string $cacheKey Request-local binding cache key.
	 * @param bool $reused Binding result reused from request-local cache.
	 * @param bool $skipped BindingResolution skip marker.
	 * @param float $duration Binding resolution duration in seconds.
	 * @return array<string, mixed> Binding trace record.
	 */
	private function apiBindingTraceRecord(
		string $path,
		\Dataphyre\Templating\DataBinding $binding,
		\Dataphyre\Templating\BindingContext $bindingContext,
		array $metadata,
		mixed $value,
		?array $identity,
		?string $cacheKey,
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
			'cache_key'=>$cacheKey,
			'cache_identity'=>$identity,
			'api_trace_id'=>$this->traceString($bindingContext->traceContext()['api_trace_id'] ?? null),
			'binding_trace_id'=>$bindingContext->bindingTraceId(),
		]), static fn(mixed $value): bool => $value!==null && $value!==[]);
		$record['trace']=$this->apiBindingTracePayload($record);
		return $record;
	}

	/**
	 * Shapes a binding trace record for response trace output.
	 *
	 * @param array<string, mixed> $binding Full binding trace record.
	 * @return array<string,mixed> Binding trace payload with correlation identifiers, binding source metadata, cache state, duration, and result type.
	 */
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

	/**
	 * Derives a human-readable label for a callable target definition.
	 *
	 * @param array{type?:string, class?:string, method?:string, reference?:string} $target Compiled callable target metadata.
	 * @return ?string Callable label, or null when metadata is incomplete.
	 */
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

	/**
	 * Returns a compact type label for binding and auth trace values.
	 *
	 * @param mixed $value Value to describe.
	 * @return string Object class name or PHP debug type.
	 */
	private function bindingResultType(mixed $value): string {
		if(is_object($value)){
			return $value::class;
		}
		if(is_array($value)){
			return 'array';
		}
		return get_debug_type($value);
	}

	/**
	 * Normalizes a binding cache identity into a deterministic array.
	 *
	 * Strings become key identities, scalars become value identities, arrays are
	 * recursively key-sorted, stringable objects are cast, and opaque objects are
	 * represented by type to keep fingerprints serializable.
	 *
	 * @param mixed $identity Raw binding cache identity.
	 * @return array<string, mixed>|null Normalized identity, or null when absent.
	 */
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

	/**
	 * Normalizes nested identity values for deterministic cache fingerprints.
	 *
	 * @param mixed $value Raw identity value.
	 * @return mixed Scalar, null, sorted array, stringable object value, or type label.
	 */
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

	/**
	 * Writes a value into a nested array using dot-path notation.
	 *
	 * Missing intermediate arrays are created as needed. Paths without dots are
	 * written as literal top-level keys.
	 *
	 * @param array<string|int, mixed> $target Target array passed by reference.
	 * @param string $path Dot-delimited destination path.
	 * @param mixed $value Value to write.
	 */
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

	/**
	 * Normalizes a trace field to a non-empty string.
	 *
	 * @param mixed $value Raw trace value.
	 * @return ?string Trimmed string, or null for non-string/empty values.
	 */
	private function traceString(mixed $value): ?string {
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}

	/**
	 * Normalizes an API alias by removing surrounding path separators.
	 *
	 * @param string $alias Raw route alias.
	 * @return string Alias suitable for manifest comparison.
	 */
	private function normalizeAlias(string $alias): string {
		return trim(trim($alias), "/\\");
	}

	/**
	 * Normalizes an optional API profile name.
	 *
	 * @param mixed $profile Raw profile value.
	 * @return ?string Trimmed profile name, or null when absent.
	 */
	private function normalizeProfileName(mixed $profile): ?string {
		if(!is_string($profile)){
			return null;
		}
		$profile=trim($profile);
		return $profile!=='' ? $profile : null;
	}

	/**
	 * Infers the HTTP method for an internal API dispatch request.
	 *
	 * Explicit methods win, alias method prefixes are honored next, and requests
	 * with body data default to POST while empty requests default to GET.
	 *
	 * @param mixed $method Explicit method value.
	 * @param string $alias Normalized or raw alias.
	 * @param array<string, mixed> $body Request body data.
	 * @return string Uppercase HTTP method.
	 */
	private function inferInternalDispatchMethod(mixed $method, string $alias, array $body): string {
		$normalized=strtoupper(trim((string)$method));
		if($normalized!==''){
			return $normalized;
		}
		$aliasMethod=$this->inferAliasMethod($alias);
		if($aliasMethod!==null){
			return $aliasMethod;
		}
		return $body!==[] ? 'POST' : 'GET';
	}

	/**
	 * Extracts an HTTP method prefix from an alias.
	 *
	 * Aliases such as GET/users, POST:create, or PATCH_profile can declare their
	 * intended method without requiring the internal dispatch request to repeat it.
	 *
	 * @param string $alias Raw or normalized alias.
	 * @return ?string Uppercase method prefix, or null when none is present.
	 */
	private function inferAliasMethod(string $alias): ?string {
		$alias=$this->normalizeAlias($alias);
		if($alias==='' || preg_match('/^(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)(?:[\/:\.\-_]|$)/i', $alias, $matches)!==1){
			return null;
		}
		return strtoupper($matches[1]);
	}

	/**
	 * Normalizes a route path to Dataphyre's leading-slash, no-trailing-slash form.
	 *
	 * @param string $path Raw path.
	 * @return string Normalized path.
	 */
	private static function normalizePath(string $path): string {
		$path='/'.trim((string)$path, '/');
		return $path==='/' ? '/' : rtrim($path, '/');
	}

	/**
	 * Creates a trace identifier for endpoint and binding correlation.
	 *
	 * Cryptographic random bytes are preferred; a sha1 fallback keeps tracing
	 * functional in constrained environments without failing endpoint execution.
	 *
	 * @return string Trace identifier.
	 */
	private function newTraceId(): string {
		try{
			return bin2hex(random_bytes(16));
		}catch(\Throwable){
			return sha1(uniqid('api_trace_', true).microtime(true));
		}
	}

	/**
	 * Checks whether runtime tracing is enabled for API diagnostics.
	 *
	 * Dataphyre Runtime owns the authoritative switch when loaded. In older
	 * runtime contexts tracing defaults to non-production environments.
	 *
	 * @return bool Runtime tracing availability.
	 */
	private function tracingEnabled(): bool {
		if(class_exists('Dataphyre\\Runtime', false) && method_exists('Dataphyre\\Runtime', 'tracingEnabled')){
			return \Dataphyre\Runtime::tracingEnabled();
		}
		return !(defined('IS_PRODUCTION') && IS_PRODUCTION===true);
	}

	/**
	 * Authorizes a route through the Access guard runtime.
	 *
	 * The first configured guard that currently passes becomes the active guard
	 * for downstream Access calls and is stored in the API auth context.
	 *
	 * @param string $schemeName Security scheme name.
	 * @param array{guards?:array<int,string>|string, failure_message?:string, failure_status?:int, failure_headers?:array<string,string>} $runtime Runtime auth configuration.
	 * @return array{authorized:bool, scheme:string, guard?:string, context?:array<string,string>, status?:int, message?:string, headers?:array<string,string>} Guard authorization result or failure details.
	 */
	private function authorizeGuardScheme(string $schemeName, array $runtime): array {
		$this->loadAccessFramework();
		if(class_exists('Dataphyre\\Access\\Auth')===false){
			return $this->failure($schemeName, $runtime, $runtime['failure_message'] ?? 'Authentication is required for this endpoint.');
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
				'scheme'=>$schemeName,
				'guard'=>$guard,
				'context'=>['guard'=>$guard],
			];
		}
		return $this->failure($schemeName, $runtime, $runtime['failure_message'] ?? 'Authentication is required for this endpoint.');
	}

	/**
	 * Authorizes a route using an RFC-style bearer token header.
	 *
	 * The extracted token is passed unchanged to the configured resolver so token
	 * format and scope semantics remain application-owned.
	 *
	 * @param string $schemeName Security scheme name.
	 * @param array<string, mixed> $runtime Runtime auth configuration.
	 * @param Request $request Incoming request.
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @param array<int, string> $scopes Required scopes.
	 * @return array{authorized:bool, scheme:string, status?:int, message?:string, headers?:array<string,string>, response?:mixed, identity?:mixed, context?:array<string,mixed>, meta?:array<string,mixed>, guard?:string} Bearer authorization result after resolver normalization.
	 */
	private function authorizeBearerScheme(string $schemeName, array $runtime, Request $request, array $route, array $scopes): array {
		$authorization=(string)$request->header('authorization', '');
		if(preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $authorization, $matches)!==1){
			return $this->failure($schemeName, $runtime, $runtime['failure_message'] ?? 'A valid bearer token is required for this endpoint.');
		}
		return $this->authorizeWithResolver($schemeName, $runtime, $matches[1], $request, $route, $scopes);
	}

	/**
	 * Authorizes a route using HTTP Basic credentials.
	 *
	 * Server-provided PHP_AUTH values are preferred, with Authorization header
	 * decoding as a fallback for environments that do not populate them.
	 *
	 * @param string $schemeName Security scheme name.
	 * @param array<string, mixed> $runtime Runtime auth configuration.
	 * @param Request $request Incoming request.
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @param array<int, string> $scopes Required scopes.
	 * @return array{authorized:bool, scheme:string, status?:int, message?:string, headers?:array<string,string>, response?:mixed, identity?:mixed, context?:array<string,mixed>, meta?:array<string,mixed>, guard?:string} Basic authorization result after resolver normalization.
	 */
	private function authorizeBasicScheme(string $schemeName, array $runtime, Request $request, array $route, array $scopes): array {
		$username=$request->server('PHP_AUTH_USER');
		$password=$request->server('PHP_AUTH_PW');
		if(!is_string($username) || !is_string($password)){
			$authorization=(string)$request->header('authorization', '');
			if(preg_match('/^\s*Basic\s+(.+?)\s*$/i', $authorization, $matches)!==1){
				return $this->failure($schemeName, $runtime, $runtime['failure_message'] ?? 'Valid basic authentication credentials are required for this endpoint.');
			}
			$decoded=base64_decode($matches[1], true);
			if(!is_string($decoded) || !str_contains($decoded, ':')){
				return $this->failure($schemeName, $runtime, $runtime['failure_message'] ?? 'Valid basic authentication credentials are required for this endpoint.');
			}
			[$username, $password]=explode(':', $decoded, 2);
		}
		return $this->authorizeWithResolver($schemeName, $runtime, [
			'username'=>$username,
			'password'=>$password,
		], $request, $route, $scopes);
	}

	/**
	 * Authorizes a route using an API key from header, query, or cookie storage.
	 *
	 * The location and parameter name are runtime metadata from the security
	 * scheme. Non-empty keys are delegated to the configured resolver.
	 *
	 * @param string $schemeName Security scheme name.
	 * @param array{location?:string, parameter?:string, resolver?:mixed, failure_message?:string, failure_status?:int, failure_headers?:array<string,string>} $runtime Runtime auth configuration.
	 * @param Request $request Incoming request.
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @param array<int, string> $scopes Required scopes.
	 * @return array{authorized:bool, scheme:string, status?:int, message?:string, headers?:array<string,string>, response?:mixed, identity?:mixed, context?:array<string,mixed>, meta?:array<string,mixed>, guard?:string} API-key authorization result after resolver normalization.
	 */
	private function authorizeApiKeyScheme(string $schemeName, array $runtime, Request $request, array $route, array $scopes): array {
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
			return $this->failure($schemeName, $runtime, $runtime['failure_message'] ?? 'A valid API key is required for this endpoint.');
		}
		return $this->authorizeWithResolver($schemeName, $runtime, trim($key), $request, $route, $scopes);
	}

	/**
	 * Authorizes a route through a custom callback without extracted credentials.
	 *
	 * Callback schemes let applications inspect the full request and route when
	 * credentials are not represented by one header, key, or guard.
	 *
	 * @param string $schemeName Security scheme name.
	 * @param array<string, mixed> $runtime Runtime auth configuration.
	 * @param Request $request Incoming request.
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @param array<int, string> $scopes Required scopes.
	 * @return array{authorized:bool, scheme:string, status?:int, message?:string, headers?:array<string,string>, response?:mixed, identity?:mixed, context?:array<string,mixed>, meta?:array<string,mixed>, guard?:string} Callback authorization result after resolver normalization.
	 */
	private function authorizeCallbackScheme(string $schemeName, array $runtime, Request $request, array $route, array $scopes): array {
		return $this->authorizeWithResolver($schemeName, $runtime, null, $request, $route, $scopes);
	}

	/**
	 * Delegates authorization to a configured resolver callable.
	 *
	 * Resolvers receive credentials, request, route, scopes, and runtime metadata.
	 * Their flexible return values are normalized immediately to the API auth
	 * result contract.
	 *
	 * @param string $schemeName Security scheme name.
	 * @param array{resolver?:mixed, failure_message?:string, failure_status?:int, failure_headers?:array<string,string>} $runtime Runtime auth configuration.
	 * @param mixed $credentials Extracted credentials or null.
	 * @param Request $request Incoming request.
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @param array<int, string> $scopes Required scopes.
	 * @return array{authorized:bool, scheme:string, status?:int, message?:string, headers?:array<string,string>, response?:mixed, identity?:mixed, context?:array<string,mixed>, meta?:array<string,mixed>, guard?:string} Resolver authorization result normalized for route security handling.
	 */
	private function authorizeWithResolver(
		string $schemeName,
		array $runtime,
		mixed $credentials,
		Request $request,
		array $route,
		array $scopes
	): array {
		$resolver=$this->resolveCallableReference($runtime['resolver'] ?? null);
		if($resolver===null){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API auth resolver unavailable; route='.$this->apiRouteTraceLabel($route).'; scheme='.$schemeName, $S='warning');
			return $this->failure($schemeName, $runtime, $runtime['failure_message'] ?? 'Authentication is required for this endpoint.');
		}
		$payload=[
			'scheme'=>$schemeName,
			'route'=>$this->apiRouteTraceDescriptor($route),
			'credential_type'=>$this->apiValueType($credentials),
			'scope_count'=>count($scopes),
			'runtime_keys'=>array_values(array_map('strval', array_keys($runtime))),
		];
		$dialback=\dataphyre\core::dialback('CALL_API_FRAMEWORK_AUTH_BEFORE_RESOLVER', $payload);
		if($dialback!==null){
			$result=$this->normalizeAuthorizationResult($schemeName, $runtime, $dialback);
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API auth resolver replaced before invoke; route='.$this->apiRouteTraceLabel($route).'; scheme='.$schemeName.'; authorized='.(($result['authorized'] ?? false)===true ? 'yes' : 'no'), $S=($result['authorized'] ?? false)===true ? 'info' : 'warning');
			return $result;
		}
		$result=$resolver($credentials, $request, $route, $scopes, $runtime);
		$normalized=$this->normalizeAuthorizationResult($schemeName, $runtime, $result);
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='API auth resolver completed; route='.$this->apiRouteTraceLabel($route).'; scheme='.$schemeName.'; authorized='.(($normalized['authorized'] ?? false)===true ? 'yes' : 'no').'; result_type='.$this->apiValueType($result), $S=($normalized['authorized'] ?? false)===true ? 'info' : 'warning');
		$dialback=\dataphyre\core::dialback('CALL_API_FRAMEWORK_AUTH_AFTER_RESOLVER', $payload+[
			'authorized'=>($normalized['authorized'] ?? false)===true,
			'status'=>$normalized['status'] ?? null,
			'result_type'=>$this->apiValueType($result),
		]);
		return $dialback!==null ? $this->normalizeAuthorizationResult($schemeName, $runtime, $dialback) : $normalized;
	}

	/**
	 * Builds a compact, non-sensitive route descriptor for trace and dialback metadata.
	 *
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @return array<string, mixed> Summary metadata without request data, headers, credentials, or response bodies.
	 */
	private function apiRouteTraceDescriptor(array $route): array {
		$api=is_array($route['api'] ?? null) ? $route['api'] : [];
		return [
			'path'=>(string)($api['path'] ?? $route['path_template'] ?? $route['exact_path'] ?? '/'),
			'operation_id'=>isset($api['operation_id']) && is_string($api['operation_id']) ? trim($api['operation_id']) : null,
			'profile'=>isset($api['profile']) && is_string($api['profile']) ? trim($api['profile']) : null,
			'methods'=>array_values(array_map('strval', is_array($api['methods'] ?? null) ? $api['methods'] : (is_array($route['methods'] ?? null) ? $route['methods'] : []))),
		];
	}

	/**
	 * Builds a compact route label for one-line trace messages.
	 *
	 * @param array<string, mixed> $route Compiled route manifest row.
	 * @return string Trace-safe route label.
	 */
	private function apiRouteTraceLabel(array $route): string {
		$descriptor=$this->apiRouteTraceDescriptor($route);
		$operation=is_string($descriptor['operation_id'] ?? null) && $descriptor['operation_id']!==''
			? '@'.$descriptor['operation_id']
			: '';
		return (string)$descriptor['path'].$operation;
	}

	/**
	 * Returns a non-sensitive value type label for traces and dialback summaries.
	 *
	 * @param mixed $value Value being described.
	 * @return string Type label; object values use their class name.
	 */
	private function apiValueType(mixed $value): string {
		return is_object($value) ? $value::class : gettype($value);
	}

	/**
	 * Normalizes application auth resolver output into the API auth contract.
	 *
	 * Resolvers may return true, a Response, or an array containing authorization
	 * state. Failures retain status, message, headers, and optional response data
	 * so route execution can emit precise HTTP errors.
	 *
	 * @param string $schemeName Security scheme name.
	 * @param array<string, mixed> $runtime Runtime auth configuration.
	 * @param mixed $result Raw resolver result.
	 * @return array{authorized:bool, scheme:string, status?:int, message?:string, headers?:array<string,string>, response?:mixed, identity?:mixed, context?:array<string,mixed>, meta?:array<string,mixed>, guard?:string} Normalized authorization result preserving failure response details when present.
	 */
	private function normalizeAuthorizationResult(string $schemeName, array $runtime, mixed $result): array {
		if($result===true){
			return ['authorized'=>true, 'scheme'=>$schemeName];
		}
		if($result instanceof Response){
			return [
				'authorized'=>false,
				'scheme'=>$schemeName,
				'response'=>$result,
			];
		}
		if(is_array($result)){
			$authorized=($result['authorized'] ?? $result['ok'] ?? false)===true;
			if($authorized===true){
				return array_replace([
					'authorized'=>true,
					'scheme'=>$schemeName,
				], array_filter([
					'identity'=>$result['identity'] ?? ($result['principal'] ?? null),
					'context'=>is_array($result['context'] ?? null) ? $result['context'] : null,
					'meta'=>is_array($result['meta'] ?? null) ? $result['meta'] : null,
					'guard'=>isset($result['guard']) && is_string($result['guard']) ? trim($result['guard']) : null,
				], static fn(mixed $value): bool => $value!==null && $value!==[]));
			}
			return [
				'authorized'=>false,
				'scheme'=>$schemeName,
				'status'=>(int)($result['status'] ?? $runtime['failure_status'] ?? 401),
				'message'=>(string)($result['message'] ?? $runtime['failure_message'] ?? 'Authentication is required for this endpoint.'),
				'headers'=>is_array($result['headers'] ?? null) ? $result['headers'] : [],
				'response'=>$result['response'] ?? null,
			];
		}
		return $this->failure($schemeName, $runtime, $runtime['failure_message'] ?? 'Authentication is required for this endpoint.');
	}

	/**
	 * Builds a standard authorization failure payload.
	 *
	 * @param string $schemeName Security scheme name.
	 * @param array{failure_status?:int, failure_headers?:array<string,string>} $runtime Runtime auth configuration.
	 * @param string $message Failure message.
	 * @return array{authorized:false, scheme:string, status:int, message:string, headers:array<string,string>} Authorization failure payload.
	 */
	private function failure(string $schemeName, array $runtime, string $message): array {
		return [
			'authorized'=>false,
			'scheme'=>$schemeName,
			'status'=>(int)($runtime['failure_status'] ?? 401),
			'message'=>$message,
			'headers'=>is_array($runtime['failure_headers'] ?? null) ? $runtime['failure_headers'] : [],
		];
	}

	/**
	 * Resolves a callable reference declared in runtime metadata.
	 *
	 * Supported references are named callables and two-part static callable arrays.
	 * Other forms are rejected to keep compiled metadata serializable.
	 *
	 * @param mixed $resolver Raw callable reference.
	 * @return ?callable Resolved callable, or null when invalid.
	 */
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

	/**
	 * Loads the Access framework module when Dataphyre core is available.
	 */
	private function loadAccessFramework(): void {
		if(class_exists('\dataphyre\core', false)){
			\dataphyre\core::load_framework_module('access');
		}
	}

	/**
	 * Loads or compiles the current application's route manifest.
	 *
	 * Compiled route manifests are preferred. When only a routes file is present,
	 * the routing compiler is loaded and invoked with application metadata. Missing
	 * applications return an empty manifest shape to keep docs and dispatch callers
	 * stable.
	 *
	 * @param ?string $applicationId Optional application id override.
	 * @return array{version:int, metadata:array<string,mixed>, routes:array<int,array<string,mixed>>} Route manifest with version, metadata, and routes keys.
	 */
	private function applicationManifest(?string $applicationId=null): array {
		$definition=$this->applicationDefinition($applicationId);
		if($definition===null){
			return ['version'=>1, 'metadata'=>[], 'routes'=>[]];
		}
		if(!empty($definition->compiledRoutesFile) && is_file($definition->compiledRoutesFile)){
			$manifest=require($definition->compiledRoutesFile);
			return is_array($manifest) ? $manifest : ['version'=>1, 'metadata'=>[], 'routes'=>[]];
		}
		if(!empty($definition->routesFile) && is_file($definition->routesFile)){
			if(class_exists('\dataphyre\core', false)){
				\dataphyre\core::load_framework_module('routing');
			}
			return \Dataphyre\Routing\RouteCompiler::compileFile($definition->routesFile, [
				'application'=>$definition->id,
				'compiled_at'=>gmdate('c'),
			]);
		}
		return ['version'=>1, 'metadata'=>['application'=>$definition->id], 'routes'=>[]];
	}

	/**
	 * Resolves the application definition used by API docs and dispatch.
	 *
	 * Without an id this returns the active runtime application. With an id it
	 * resolves against the current project root so documentation can target a
	 * sibling application in the same Dataphyre project.
	 *
	 * @param ?string $applicationId Optional application id override.
	 * @return ?\dataphyre\application_definition Resolved application definition.
	 */
	private function applicationDefinition(?string $applicationId=null): ?\dataphyre\application_definition {
		if(class_exists('\dataphyre\core', false)){
			\dataphyre\core::load_framework_module('core');
		}
		if($applicationId===null || trim($applicationId)===''){
			if(class_exists('\dataphyre\runtime', false)){
				$definition=\dataphyre\runtime::current_application_definition();
				if($definition instanceof \dataphyre\application_definition){
					return $definition;
				}
			}
			return null;
		}
		$projectRoot=$this->projectRoot();
		if($projectRoot===null || class_exists('\dataphyre\runtime', false)===false){
			return null;
		}
		return \dataphyre\runtime::resolve_application_definition($projectRoot, trim($applicationId));
	}

	/**
	 * Resolves the current Dataphyre project root from modern or legacy runtime APIs.
	 *
	 * @return ?string Absolute project root without a trailing separator.
	 */
	private function projectRoot(): ?string {
		if(class_exists('Dataphyre\\Runtime')){
			$projectRoot=\Dataphyre\Runtime::projectRoot();
			if(is_string($projectRoot) && trim($projectRoot)!==''){
				return rtrim($projectRoot, '/\\');
			}
		}
		if(class_exists('\dataphyre\runtime', false)){
			$projectRoot=\dataphyre\runtime::current_project_root();
			if(is_string($projectRoot) && trim($projectRoot)!==''){
				return rtrim($projectRoot, '/\\');
			}
		}
		return null;
	}

	/**
	 * Builds OpenAPI generator options from application defaults and overrides.
	 *
	 * Explicit non-null options replace defaults, while title, description, and
	 * server values are derived from the application definition and current HTTP
	 * request when omitted.
	 *
	 * @param ?\dataphyre\application_definition $definition Application definition.
	 * @param array<string, mixed> $options User-supplied OpenAPI options.
	 * @return array<string, mixed> Resolved OpenAPI options.
	 */
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

	/**
	 * Derives a documentation title for an application API.
	 *
	 * @param ?\dataphyre\application_definition $definition Application definition.
	 * @return string Human-readable API title.
	 */
	private function defaultTitle(?\dataphyre\application_definition $definition): string {
		if($definition===null){
			return 'Dataphyre API';
		}
		return ucwords(str_replace(['_', '-'], ' ', $definition->id)).' API';
	}

	/**
	 * Resolves default OpenAPI server entries.
	 *
	 * Explicit server options are preserved. Otherwise the current HTTP scheme and
	 * host are used when available, with CLI contexts producing no default server.
	 *
	 * @param array<int, string|array<string,mixed>> $servers Explicit OpenAPI server entries.
	 * @return array<int, string|array<string,mixed>> OpenAPI server entries.
	 */
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

	/**
	 * Normalizes API documentation route and asset options.
	 *
	 * Paths are forced to absolute web paths, bootstrap is trimmed, and CDN asset
	 * defaults are supplied for the generated Swagger UI surface.
	 *
	 * @param array<string, mixed> $options Raw documentation options.
	 * @return array<string, mixed> Normalized documentation options.
	 */
	private function normalizeDocumentationOptions(array $options): array {
		$defaults=[
			'application'=>null,
			'bootstrap'=>null,
			'docs_path'=>'/_framework/api/docs',
			'spec_path'=>'/_framework/api/openapi.json',
			'asset_path'=>'/_framework/api/assets',
			'title'=>null,
			'version'=>'1.0.0',
			'description'=>null,
			'servers'=>[],
			'swagger_ui_css'=>'https://unpkg.com/swagger-ui-dist@5/swagger-ui.css',
			'swagger_ui_bundle_js'=>'https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js',
			'swagger_ui_preset_js'=>'https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js',
		];
		$options=array_replace($defaults, $options);
		foreach(['docs_path', 'spec_path', 'asset_path'] as $key){
			$options[$key]='/' . trim((string)$options[$key], '/');
		}
		$bootstrap=is_string($options['bootstrap']) ? trim($options['bootstrap']) : null;
		$options['bootstrap']=$bootstrap!=='' ? $bootstrap : null;
		return $options;
	}
}
