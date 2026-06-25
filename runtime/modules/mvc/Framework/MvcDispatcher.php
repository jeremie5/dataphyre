<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\Http\Request;
use Dataphyre\Http\Response;
use Dataphyre\Routing\ControllerAction;
use Dataphyre\Routing\Route;
use Dataphyre\Routing\RouteCompiler;
use Dataphyre\Routing\RouteManifest;
use Dataphyre\Templating\RenderedTemplate;
use Dataphyre\Templating\TemplateView;

/**
 * Dispatches MVC requests through compiled routes, middleware, and controllers.
 *
 * The dispatcher owns the request lifecycle after an application has been
 * selected: route matching, route-parameter merge, controller invocation,
 * middleware termination, response normalization, default headers, validation
 * redirects, and configured error handling.
 */
final class MvcDispatcher {

	private ?array $compiledManifest=null;
	private ?int $compiledManifestRevision=null;
	/** @var array<string, array<int, string>> */
	private array $middlewareKeyCache=[];

	/**
	 * Stores the MVC application that supplies routes, configuration, and DI.
	 *
	 * @param MvcApplication $app Application context for route matching and controller resolution.
	 */
	public function __construct(private MvcApplication $app){}

	/**
	 * Matches and executes the request, returning a normalized HTTP response.
	 *
	 * Route and application metadata are attached to the request before middleware
	 * execution so policies, controllers, and form requests all see the same
	 * context.
	 *
	 * @param Request $request HTTP request to dispatch.
	 * @return Response Final response after middleware, handler, errors, and default headers.
	 */
	public function dispatch(Request $request): Response {
		try{
			$match=$this->match($request);
			if($match!==null){
				$route=$match['mvc_route'];
				$parameters=$match['parameters'] ?? [];
				$request->mergeRouteParameters($parameters);
				$request->mergeAttributes([
					'route'=>$route,
					'compiled_route'=>$match,
					'route_name'=>$route->nameValue(),
					'app'=>$this->app,
				]);
				$terminable=[];
				try{
					$response=$this->normalizeResponse($this->runMiddleware(
						$this->middlewareStack($match['middleware'] ?? [], $route->excludedMiddlewareDefinitions()),
						$request,
						fn(Request $request): Response => $this->normalizeResponse($this->invokeHandler(
							$route->handler(),
							$request,
							new MvcRouteContext($this->app, $route, $match, $parameters),
							$terminable
						)),
						$terminable
					));
				}catch(\Throwable $throwable){
					$response=$this->handleError($throwable, $request);
				}
				$this->terminateMiddleware($terminable, $request, $response);
				return $response;
			}
			return $this->notFound($request);
		}catch(\Throwable $throwable){
			return $this->handleError($throwable, $request);
		}
	}

	/**
	 * Matches the request against the compiled route manifest.
	 *
	 * @param Request $request HTTP request containing method, path, and host.
	 * @return ?array<string, mixed> Compiled match enriched with the source MVC route, or null when no route matches.
	 * @throws \RuntimeException When routing support or MVC route metadata is unavailable.
	 */
	private function match(Request $request): ?array {
		if(class_exists('\dataphyre\routing\compiled_route_dispatcher')===false){
			throw new \RuntimeException('Dataphyre MVC requires the routing compiled route dispatcher for route matching.');
		}
		$manifest=$this->compiledManifest();
		$match=\dataphyre\routing\compiled_route_dispatcher::match_routes_for_request(
			$manifest['routes'] ?? [],
			method_exists($request, 'effectiveMethod') ? $request->effectiveMethod() : $request->method(),
			$request->path(),
			$request->host()
		);
		if($match===null){
			return null;
		}
		$mvcMetadata=RouteManifest::routeMetadata($match, 'mvc', []);
		$index=is_array($mvcMetadata) ? ($mvcMetadata['route_index'] ?? null) : null;
		$index=$index ?? ($match['mvc_route_index'] ?? null);
		$routes=$this->app->routes()->all();
		if(!is_int($index) || !isset($routes[$index])){
			throw new \RuntimeException('Matched MVC route is missing its source route index.');
		}
		$match['mvc_route']=$routes[$index];
		return $match;
	}

	/**
	 * Returns the current compiled route manifest for the application revision.
	 *
	 * @return array<string, mixed> Compiled route manifest cached in memory until route revision changes.
	 */
	private function compiledManifest(): array {
		$routes=$this->app->routes();
		$revision=$routes->revision();
		if($this->compiledManifest===null || $this->compiledManifestRevision!==$revision){
			$this->compiledManifest=$this->loadOrCompileManifest($revision);
			$this->compiledManifestRevision=$revision;
		}
		return $this->compiledManifest;
	}

	/**
	 * Loads a valid manifest cache or compiles a fresh manifest.
	 *
	 * @param int $revision Current route collection revision.
	 * @return array<string, mixed> Manifest with signature metadata.
	 */
	private function loadOrCompileManifest(int $revision): array {
		$signature=$this->manifestSignature($revision);
		$cacheFile=$this->app->manifestCacheFile();
		if($cacheFile!==null && is_file($cacheFile)){
			$cached=RouteCompiler::readManifestFile($cacheFile);
			if(is_array($cached) && ($cached['metadata']['signature'] ?? null)===$signature){
				return $cached;
			}
		}
		$manifest=$this->app->routes()->compile([
			'signature'=>$signature,
			'route_sources'=>$this->app->routeSources(),
		]);
		if($cacheFile!==null){
			$this->writeManifestCache($cacheFile, $manifest);
		}
		return $manifest;
	}

	/**
	 * Builds the manifest signature for cache validation.
	 *
	 * @param int $revision Current route collection revision.
	 * @return string Signature derived from app name, revision, and route sources.
	 */
	private function manifestSignature(int $revision): string {
		return RouteCompiler::manifestSignature([
			'app'=>$this->app->name(),
			'revision'=>$revision,
			'sources'=>$this->app->routeSources(),
		]);
	}

	/**
	 * Writes the compiled manifest cache best-effort through the route compiler.
	 *
	 * @param string $file Manifest cache file path.
	 * @param array<string, mixed> $manifest Manifest payload to persist.
	 * @return void Cache write failures are handled by RouteCompiler::tryWriteManifestFile().
	 */
	private function writeManifestCache(string $file, array $manifest): void {
		RouteCompiler::tryWriteManifestFile($file, $manifest);
	}

	/**
	 * Resolves and invokes a route handler.
	 *
	 * @param mixed $handler Controller descriptor, callable, include descriptor, or callable array.
	 * @param Request $request Current HTTP request.
	 * @param MvcRouteContext $context Route context supplied to controllers and model binding.
	 * @param array<int, array<string, mixed>> $terminable Terminable middleware collected during nested controller middleware execution.
	 * @return mixed Controller, callable, template, scalar, array, or response value accepted by normalizeResponse().
	 * @throws \RuntimeException When the handler shape cannot be invoked.
	 */
	private function invokeHandler(mixed $handler, Request $request, MvcRouteContext $context, array &$terminable=[]): mixed {
		if(is_string($handler)){
			$handler=ControllerAction::fromString($handler, $this->app->controllerNamespace())->compile();
		}
		if(is_array($handler) && (($handler['type'] ?? null)==='controller' || isset($handler['class'], $handler['method']))){
			$class=trim((string)$handler['class'], '\\');
			$method=(string)$handler['method'];
			$static=(bool)($handler['static'] ?? false);
			if(($handler['bootstrap'] ?? null) && is_file((string)$handler['bootstrap'])){
				require_once((string)$handler['bootstrap']);
			}
			$target=$static ? $class : $this->app->container()->make($class);
			if($target instanceof Controller){
				$target->setMvcRouteContext($context);
			}
			return $this->callControllerAction($target, $method, $request, $context, $terminable);
		}
		if(is_array($handler) && isset($handler[0], $handler[1])){
			if(is_string($handler[0]) && !class_exists($handler[0]) && $this->app->controllerNamespace()!==null){
				$handler[0]=$this->app->controllerNamespace().'\\'.trim($handler[0], '\\');
			}
			if(is_string($handler[0]) && class_exists($handler[0])){
				$handler[0]=$this->app->container()->make($handler[0]);
			}
			if($handler[0] instanceof Controller){
				$handler[0]->setMvcRouteContext($context);
			}
			return $this->callControllerAction($handler[0], (string)$handler[1], $request, $context, $terminable);
		}
		if(is_callable($handler)){
			return $this->call($handler, $request, $context);
		}
		throw new \RuntimeException('MVC route handler is invalid or unsupported.');
	}

	/**
	 * Calls a controller method with controller-specific middleware.
	 *
	 * @param mixed $target Controller instance or callable target.
	 * @param string $method Controller method name.
	 * @param Request $request Current HTTP request.
	 * @param MvcRouteContext $context Route context for dependency injection and binding.
	 * @param array<int, array<string, mixed>> $terminable Terminable middleware accumulator.
	 * @return mixed Controller action output, or the middleware-wrapped response for controller middleware.
	 */
	private function callControllerAction(mixed $target, string $method, Request $request, MvcRouteContext $context, array &$terminable=[]): mixed {
		$core=fn(Request $request): Response => $this->normalizeResponse($this->call([$target, $method], $request, $context));
		if(!$target instanceof Controller){
			return $core($request);
		}
		$middleware=$this->expandMiddleware($target->mvcControllerMiddleware($method));
		if($middleware===[]){
			return $core($request);
		}
		return $this->runMiddleware($middleware, $request, $core, $terminable);
	}

	/**
	 * Invokes a callable through the application container.
	 *
	 * @param callable $callable Handler or middleware callable.
	 * @param Request $request Current HTTP request.
	 * @param MvcRouteContext|array<string, mixed> $context Route context or raw route parameters.
	 * @param array<class-string|string, mixed> $typedValues Pre-resolved typed dependencies.
	 * @return mixed Value produced after route parameters, model bindings, request objects, and typed overrides are injected.
	 */
	private function call(callable $callable, Request $request, MvcRouteContext|array $context, array $typedValues=[]): mixed {
		$routeParameters=is_array($context) ? $context : $context->parameters();
		$typedValues=$this->formRequestValues($callable, $request)+$typedValues;
		if(!$context instanceof MvcRouteContext){
			return $this->app->container()->call($callable, $routeParameters, [
				Request::class=>$request,
				'Dataphyre\\Http\\Request'=>$request,
			]+$typedValues);
		}
		$binding=RouteModelBinder::resolveForCallable($callable, $this->app, $routeParameters, $context->route()->modelBindings());
		return $this->app->container()->call($callable, $binding['parameters'], [
			Request::class=>$request,
			'Dataphyre\\Http\\Request'=>$request,
			MvcRouteContext::class=>$context,
			'Dataphyre\\Mvc\\MvcRouteContext'=>$context,
		]+($binding['typed_values'] ?? [])+$typedValues);
	}

	/**
	 * Resolves FormRequest parameters declared by a callable.
	 *
	 * @param callable $callable Handler whose parameters should be inspected.
	 * @param Request $request Current HTTP request.
	 * @return array<class-string, FormRequest> Validated form request instances keyed by class name.
	 */
	private function formRequestValues(callable $callable, Request $request): array {
		$typedValues=[];
		foreach($this->reflectCallable($callable)->getParameters() as $parameter){
			$type=$parameter->getType();
			if(!$type instanceof \ReflectionNamedType || $type->isBuiltin()){
				continue;
			}
			$class=ltrim($type->getName(), '\\');
			if(!is_a($class, FormRequest::class, true)){
				continue;
			}
			$formRequest=$class::from($request)->validateResolved();
			$typedValues[$class]=$formRequest;
		}
		return $typedValues;
	}

	/**
	 * Creates a reflection object for a supported callable shape.
	 *
	 * @param callable $callable Function, closure, invokable object, or method array.
	 * @return \ReflectionFunctionAbstract Reflection used for dependency discovery.
	 */
	private function reflectCallable(callable $callable): \ReflectionFunctionAbstract {
		if(is_array($callable)){
			return new \ReflectionMethod($callable[0], (string)$callable[1]);
		}
		if(is_object($callable) && !$callable instanceof \Closure){
			return new \ReflectionMethod($callable, '__invoke');
		}
		return new \ReflectionFunction($callable);
	}

	/**
	 * Executes middleware around a core request handler.
	 *
	 * @param array<int, mixed> $middleware Middleware definitions in execution order.
	 * @param Request $request Current HTTP request.
	 * @param callable $core Final request handler.
	 * @param array<int, array<string, mixed>> $terminable Terminable middleware accumulator.
	 * @return mixed response-like value returned by the innermost handler or any middleware that short-circuits the chain.
	 */
	private function runMiddleware(array $middleware, Request $request, callable $core, array &$terminable=[]): mixed {
		$next=$core;
		foreach(array_reverse($middleware) as $definition){
			$next=function(Request $request) use ($definition, $next, &$terminable): mixed {
				$resolved=$this->resolveMiddleware($definition);
				$middleware=$resolved['target'];
				$parameters=$resolved['parameters'];
				if(is_object($middleware) && method_exists($middleware, 'terminate')){
					$terminable[]=[
						'target'=>$middleware,
						'parameters'=>$parameters,
					];
				}
				if(is_object($middleware) && method_exists($middleware, 'handle')){
					return $middleware->handle($request, $next, ...$parameters);
				}
				return $middleware($request, $next, ...$parameters);
			};
		}
		return $next($request);
	}

	/**
	 * Runs terminate hooks after the response has been produced.
	 *
	 * @param array<int, array{target: object, parameters: array<int, mixed>}> $terminable Middleware instances collected during execution.
	 * @param Request $request Current HTTP request.
	 * @param Response $response Final response.
	 * @return void Termination side effects are delegated to middleware.
	 */
	private function terminateMiddleware(array $terminable, Request $request, Response $response): void {
		foreach(array_reverse($terminable) as $definition){
			$target=$definition['target'];
			$parameters=$definition['parameters'];
			$target->terminate($request, $response, ...$parameters);
		}
	}

	/**
	 * Builds the effective middleware stack for a matched route.
	 *
	 * @param array<int, mixed> $routeMiddleware Route-level middleware definitions.
	 * @param array<int, mixed> $excludedMiddleware Route-level exclusions.
	 * @return array<int, mixed> Global, configured, and route middleware after group expansion and exclusions.
	 */
	private function middlewareStack(array $routeMiddleware, array $excludedMiddleware=[]): array {
		$routeMiddleware=$this->filterMiddleware(
			$this->expandMiddleware($routeMiddleware),
			$this->expandMiddleware($excludedMiddleware)
		);
		return array_values(array_merge(
			$this->expandMiddleware($this->configuredMiddleware('global_middleware')),
			$this->expandMiddleware($this->configuredMiddleware('middleware_stack')),
			$routeMiddleware
		));
	}

	/**
	 * Removes middleware whose identity matches route exclusions.
	 *
	 * @param array<int, mixed> $middleware Expanded middleware definitions.
	 * @param array<int, mixed> $excluded Expanded middleware definitions to exclude.
	 * @return array<int, mixed> Middleware definitions that remain active.
	 */
	private function filterMiddleware(array $middleware, array $excluded): array {
		if($excluded===[]){
			return $middleware;
		}
		$excludedKeys=[];
		foreach($excluded as $definition){
			foreach($this->middlewareKeys($definition) as $key){
				$excludedKeys[$key]=true;
			}
		}
		$filtered=[];
		foreach($middleware as $definition){
			foreach($this->middlewareKeys($definition) as $key){
				if(isset($excludedKeys[$key])){
					continue 2;
				}
			}
			$filtered[]=$definition;
		}
		return $filtered;
	}

	/**
	 * Derives comparable identity keys for a middleware definition.
	 *
	 * @param mixed $definition Middleware alias, class, callable, or compiled definition.
	 * @return array<int, string> Identity keys used for exclusion matching.
	 */
	private function middlewareKeys(mixed $definition): array {
		$cacheKey=$this->middlewareKeyCacheKey($definition);
		if($cacheKey!==null && isset($this->middlewareKeyCache[$cacheKey])){
			return $this->middlewareKeyCache[$cacheKey];
		}
		if(is_string($definition)){
			try{
				$definition=Route::normalizeMiddleware($definition);
			}catch(\Throwable){
				$keys=['string:'.$definition];
				if($cacheKey!==null){
					$this->middlewareKeyCache[$cacheKey]=$keys;
				}
				return $keys;
			}
		}
		if(is_array($definition)){
			$keys=[];
			if(isset($definition['alias']) && is_string($definition['alias'])){
				$alias=trim($definition['alias']);
				if($alias!==''){
					$keys[]='alias:'.$alias;
					$parameters=(array)($definition['parameters'] ?? []);
					if($parameters!==[]){
						$keys[]='alias:'.$alias.':'.implode(',', array_map('strval', $parameters));
					}
				}
			}
			if(isset($definition['class']) && is_string($definition['class'])){
				$keys[]='class:'.trim($definition['class'], '\\');
			}
			if(isset($definition['target']) && is_callable($definition['target'])){
				$keys[]='callable';
			}
			if($cacheKey!==null){
				$this->middlewareKeyCache[$cacheKey]=$keys;
			}
			return $keys;
		}
		$keys=is_callable($definition) ? ['callable'] : [get_debug_type($definition)];
		if($cacheKey!==null){
			$this->middlewareKeyCache[$cacheKey]=$keys;
		}
		return $keys;
	}

	/**
	 * Builds a stable cache key for middleware identity derivation.
	 *
	 * @param mixed $definition Middleware definition.
	 * @return string|null Cache key, or null when the definition is not safely keyable.
	 */
	private function middlewareKeyCacheKey(mixed $definition): ?string {
		if(is_string($definition)){
			return 's:'.$definition;
		}
		if(is_array($definition)){
			if(isset($definition['target']) && is_callable($definition['target'])){
				return null;
			}
			return 'a:'.serialize($definition);
		}
		if($definition instanceof \Closure){
			return 'c:'.spl_object_id($definition);
		}
		if(is_object($definition) && is_callable($definition)){
			return 'o:'.spl_object_id($definition);
		}
		return is_object($definition) ? null : 't:'.get_debug_type($definition);
	}

	/**
	 * Reads middleware configuration as a normalized list.
	 *
	 * @param string $key Application config key.
	 * @return array<int, mixed> Middleware definitions, or an empty list for invalid config.
	 */
	private function configuredMiddleware(string $key): array {
		$value=$this->app->config($key, []);
		if(is_string($value) || is_callable($value)){
			return [$value];
		}
		if(is_array($value)){
			return $value;
		}
		return [];
	}

	/**
	 * Expands middleware group references recursively.
	 *
	 * @param array<int, mixed> $middleware Middleware definitions or group references.
	 * @return array<int, mixed> Middleware definitions with groups replaced by their members.
	 */
	private function expandMiddleware(array $middleware): array {
		$expanded=[];
		foreach($middleware as $definition){
			if(is_string($definition) && $this->middlewareGroup($definition)!==null){
				array_push($expanded, ...$this->expandMiddleware($this->middlewareGroup($definition)));
				continue;
			}
			if(is_array($definition) && isset($definition['group']) && is_string($definition['group'])){
				array_push($expanded, ...$this->expandMiddleware($this->middlewareGroup($definition['group']) ?? []));
				continue;
			}
			if(is_array($definition) && isset($definition['alias']) && is_string($definition['alias']) && $this->middlewareGroup($definition['alias'])!==null){
				array_push($expanded, ...$this->expandMiddleware($this->middlewareGroup($definition['alias'])));
				continue;
			}
			$expanded[]=$definition;
		}
		return $expanded;
	}

	/**
	 * Resolves a named middleware group from application configuration.
	 *
	 * @param string $name Group name, case-insensitive fallback supported.
	 * @return ?array<int, mixed> Group middleware definitions, or null when absent.
	 */
	private function middlewareGroup(string $name): ?array {
		$groups=$this->app->config('middleware_groups', []);
		if(!is_array($groups)){
			return null;
		}
		$name=trim($name);
		$lower=strtolower($name);
		foreach([$name, $lower] as $key){
			if(array_key_exists($key, $groups) && is_array($groups[$key])){
				return $groups[$key];
			}
		}
		return null;
	}

	/**
	 * Resolves a middleware definition to an invokable target and parameters.
	 *
	 * @param mixed $definition Middleware alias, class descriptor, callable, or route string.
	 * @return array{target: callable|object, parameters: array<int, mixed>}
	 * @throws \RuntimeException When the definition cannot be resolved.
	 */
	private function resolveMiddleware(mixed $definition): array {
		if(is_string($definition)){
			$definition=Route::normalizeMiddleware($definition);
		}
		if(is_callable($definition)){
			return [
				'target'=>$definition,
				'parameters'=>[],
			];
		}
		if(is_array($definition)){
			$resolved=\dataphyre\routing\compiled_route_dispatcher::resolve_middleware_for_route(
				$definition,
				(array)$this->app->config('middleware', [])
			);
			if(isset($resolved['target']) && is_callable($resolved['target'])){
				return [
					'target'=>$resolved['target'],
					'parameters'=>array_values((array)($resolved['parameters'] ?? [])),
				];
			}
			$class=$resolved['class'] ?? null;
			if(is_string($class) && class_exists($class)){
				return [
					'target'=>$this->app->container()->make($class),
					'parameters'=>array_values((array)($resolved['parameters'] ?? [])),
				];
			}
		}
		throw new \RuntimeException('MVC middleware definition is invalid or unsupported.');
	}

	/**
	 * Converts handler output into a Response with application default headers.
	 *
	 * @param mixed $result Raw controller, middleware, template, or ResponseResult output.
	 * @return Response Normalized HTTP response.
	 */
	private function normalizeResponse(mixed $result): Response {
		if($result instanceof ResponseResult){
			return $this->withDefaultHeaders($result->toResponse($this->app));
		}
		if($result instanceof RenderedTemplate){
			return $this->withDefaultHeaders(Response::html($result->content()));
		}
		if($result instanceof TemplateView){
			return $this->withDefaultHeaders(Response::html($result->content()));
		}
		return $this->withDefaultHeaders(Response::normalize($result, 'html'));
	}

	/**
	 * Applies configured default response headers.
	 *
	 * @param Response $response Response to decorate.
	 * @return Response Response with configured headers when valid.
	 */
	private function withDefaultHeaders(Response $response): Response {
		$headers=$this->app->config('response_headers', []);
		if(is_array($headers)){
			return $response->withHeaders($headers);
		}
		return $response;
	}

	/**
	 * Builds the response for unmatched routes.
	 *
	 * @param Request $request Current HTTP request.
	 * @return Response Configured not-found handler response or default 404 HTML response.
	 */
	private function notFound(Request $request): Response {
		$handler=$this->app->config('not_found_handler');
		if($handler!==null){
			return $this->normalizeResponse($this->call($handler, $request, []));
		}
		return $this->withDefaultHeaders(Response::html('Not Found', 404));
	}

	/**
	 * Converts framework exceptions into configured or built-in error responses.
	 *
	 * @param \Throwable $throwable Exception thrown during dispatch.
	 * @param Request $request Current HTTP request.
	 * @return Response Error response when the exception is handled.
	 * @throws \Throwable Non-framework exceptions without a configured handler are rethrown.
	 */
	private function handleError(\Throwable $throwable, Request $request): Response {
		$handler=$this->app->config('error_handler');
		if($handler!==null && is_callable($handler)){
			return $this->normalizeResponse($this->call($handler, $request, [], [
				get_class($throwable)=>$throwable,
				\Throwable::class=>$throwable,
			]));
		}
		if($throwable instanceof ValidationException){
			return $this->validationFailureResponse($throwable, $request);
		}
		if($throwable instanceof HttpException){
			return $this->withDefaultHeaders(
				method_exists($request, 'expectsJson') && $request->expectsJson()
					? $throwable->toJsonResponse()
					: $throwable->toResponse()
			);
		}
		if($throwable instanceof RouteModelNotFoundException){
			return $this->notFound($request);
		}
		throw $throwable;
	}

	/**
	 * Builds the response for validation failures.
	 *
	 * @param ValidationException $throwable Validation exception containing errors and status.
	 * @param Request $request Current HTTP request.
	 * @return Response JSON/422 response or redirect response with old input and errors.
	 */
	private function validationFailureResponse(ValidationException $throwable, Request $request): Response {
		if(
			$throwable->status()!==422
			|| $this->app->config('validation_redirect', false)!==true
			|| (method_exists($request, 'expectsJson') && $request->expectsJson())
		){
			return $throwable->toResponse();
		}
		$fallback=$this->app->config('validation_redirect_fallback', '/');
		$fallback=is_string($fallback) && $fallback!=='' ? $fallback : '/';
		$referer=$request->header('Referer', $request->server('HTTP_REFERER', $fallback));
		$location=is_string($referer) && $referer!=='' ? $referer : $fallback;
		return $this->normalizeResponse(
			(new RedirectResult($location))
				->withInput($request->input())
				->withErrors($throwable)
		);
	}
}
