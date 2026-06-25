<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use BadMethodCallException;
use Closure;
use Dataphyre\Routing\Route;
use Dataphyre\Routing\RouteManifest;

/**
 * Mutable route registry for a Dataphyre MVC application.
 *
 * RouteCollection owns the application route list, group stack, route macros,
 * resource expansion, named URL generation, signed URL creation, and manifest
 * compilation metadata. Mutations increment a revision counter so consumers can
 * detect when cached manifests or route lists are stale.
 */
final class RouteCollection {

	private static array $macros=[];
	private array $routes=[];
	private array $group_stack=[];
	private int $revision=0;

	/**
	 * Creates a route collection bound to one MVC application.
	 *
	 * @param MvcApplication $app Application that supplies config, name, and controller namespace.
	 */
	public function __construct(private MvcApplication $app){}

	/**
	 * Registers a process-local macro method on RouteCollection.
	 *
	 * Empty macro names are ignored. Closure macros are rebound to the collection
	 * instance when invoked through __call().
	 *
	 * @param string $name Macro method name.
	 * @param callable $macro Macro implementation.
	 */
	public static function macro(string $name, callable $macro): void {
		$name=trim($name);
		if($name!==''){
			self::$macros[$name]=$macro;
		}
	}

	/**
	 * Checks whether a macro method is registered.
	 *
	 * @param string $name Macro method name.
	 * @return bool Macro registration decision.
	 */
	public static function hasMacro(string $name): bool {
		return isset(self::$macros[$name]);
	}

	/**
	 * Clears all process-local route collection macros.
	 */
	public static function flushMacros(): void {
		self::$macros=[];
	}

	/**
	 * Dispatches calls to registered route collection macros.
	 *
	 * @param string $name Invoked method name.
	 * @param array<int, mixed> $arguments Call arguments passed to the macro.
	 * @return mixed value produced by the registered macro after closure binding to this collection.
	 *
	 * @throws BadMethodCallException When no macro is registered for the method.
	 */
	public function __call(string $name, array $arguments): mixed {
		if(!isset(self::$macros[$name])){
			throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', self::class, $name));
		}
		$macro=self::$macros[$name];
		if($macro instanceof Closure){
			$macro=$macro->bindTo($this, self::class);
		}
		return $macro(...$arguments);
	}

	/**
	 * Registers a GET route.
	 *
	 * @param string $path Route path.
	 * @param mixed $handler Route handler definition.
	 * @param array<string, mixed> $options Route options merged with active groups.
	 * @return RouteDefinition Registered route.
	 */
	public function get(string $path, mixed $handler, array $options=[]): RouteDefinition {
		return $this->match(['GET'], $path, $handler, $options);
	}

	/**
	 * Registers a POST route.
	 *
	 * @param string $path Route path.
	 * @param mixed $handler Route handler definition.
	 * @param array<string, mixed> $options Route options merged with active groups.
	 * @return RouteDefinition Registered route.
	 */
	public function post(string $path, mixed $handler, array $options=[]): RouteDefinition {
		return $this->match(['POST'], $path, $handler, $options);
	}

	/**
	 * Registers a HEAD route.
	 *
	 * @param string $path Route path.
	 * @param mixed $handler Route handler definition.
	 * @param array<string, mixed> $options Route options merged with active groups.
	 * @return RouteDefinition Registered route.
	 */
	public function head(string $path, mixed $handler, array $options=[]): RouteDefinition {
		return $this->match(['HEAD'], $path, $handler, $options);
	}

	/**
	 * Registers a PUT route.
	 *
	 * @param string $path Route path.
	 * @param mixed $handler Route handler definition.
	 * @param array<string, mixed> $options Route options merged with active groups.
	 * @return RouteDefinition Registered route.
	 */
	public function put(string $path, mixed $handler, array $options=[]): RouteDefinition {
		return $this->match(['PUT'], $path, $handler, $options);
	}

	/**
	 * Registers a PATCH route.
	 *
	 * @param string $path Route path.
	 * @param mixed $handler Route handler definition.
	 * @param array<string, mixed> $options Route options merged with active groups.
	 * @return RouteDefinition Registered route.
	 */
	public function patch(string $path, mixed $handler, array $options=[]): RouteDefinition {
		return $this->match(['PATCH'], $path, $handler, $options);
	}

	/**
	 * Registers a DELETE route.
	 *
	 * @param string $path Route path.
	 * @param mixed $handler Route handler definition.
	 * @param array<string, mixed> $options Route options merged with active groups.
	 * @return RouteDefinition Registered route.
	 */
	public function delete(string $path, mixed $handler, array $options=[]): RouteDefinition {
		return $this->match(['DELETE'], $path, $handler, $options);
	}

	/**
	 * Registers an OPTIONS route.
	 *
	 * @param string $path Route path.
	 * @param mixed $handler Route handler definition.
	 * @param array<string, mixed> $options Route options merged with active groups.
	 * @return RouteDefinition Registered route.
	 */
	public function options(string $path, mixed $handler, array $options=[]): RouteDefinition {
		return $this->match(['OPTIONS'], $path, $handler, $options);
	}

	/**
	 * Registers a route that accepts any HTTP method.
	 *
	 * @param string $path Route path.
	 * @param mixed $handler Route handler definition.
	 * @param array<string, mixed> $options Route options merged with active groups.
	 * @return RouteDefinition Registered route.
	 */
	public function any(string $path, mixed $handler, array $options=[]): RouteDefinition {
		return $this->match(['ANY'], $path, $handler, $options);
	}

	/**
	 * Registers a GET route that returns a view result.
	 *
	 * @param string $path Route path.
	 * @param string $template View template name.
	 * @param array<string, mixed> $data Static view data captured by the route handler.
	 * @param array<string, mixed> $options Route options merged with active groups.
	 * @return RouteDefinition Registered route.
	 */
	public function view(string $path, string $template, array $data=[], array $options=[]): RouteDefinition {
		return $this->get($path, static fn(): ViewResult => ViewResult::make($template, $data), $options);
	}

	/**
	 * Registers a GET route that redirects to a fixed location.
	 *
	 * @param string $path Route path.
	 * @param string $location Redirect target.
	 * @param int $status HTTP redirect status.
	 * @param array<string, mixed> $options Route options merged with active groups.
	 * @return RouteDefinition Registered route.
	 */
	public function redirect(string $path, string $location, int $status=302, array $options=[]): RouteDefinition {
		return $this->get($path, static fn(): RedirectResult => new RedirectResult($location, $status), $options);
	}

	/**
	 * Registers a GET route that redirects to a named route URL.
	 *
	 * Named URL resolution happens when the handler executes, so route parameters
	 * and query values use the latest compiled manifest.
	 *
	 * @param string $path Route path.
	 * @param string $name Target route name.
	 * @param array<string, mixed> $parameters Named route parameters for path interpolation.
	 * @param array<string, mixed> $query Query-string parameters appended to the generated URL.
	 * @param int $status HTTP redirect status.
	 * @param array<string, mixed> $options Route options merged with active groups.
	 * @return RouteDefinition Registered route.
	 */
	public function redirectToRoute(
		string $path,
		string $name,
		array $parameters=[],
		array $query=[],
		int $status=302,
		array $options=[]
	): RouteDefinition {
		return $this->get($path, fn(): RedirectResult => new RedirectResult($this->url($name, $parameters, $query), $status), $options);
	}

	/**
	 * Registers the fallback route for unmatched requests.
	 *
	 * The route uses an ANY method and splat parameter so the router can capture
	 * any remaining path.
	 *
	 * @param mixed $handler Fallback handler definition.
	 * @param array<string, mixed> $options Route options merged with active groups.
	 * @return RouteDefinition Registered fallback route.
	 */
	public function fallback(mixed $handler, array $options=[]): RouteDefinition {
		return $this->any('/{...path}', $handler, $options);
	}

	/**
	 * Expands a conventional multi-record resource into MVC routes.
	 *
	 * Resource options control action inclusion, route names, parameter names,
	 * URI verbs, shallow member routes, per-action middleware, and controller
	 * method overrides.
	 *
	 * @param string $name Resource path/name.
	 * @param string $controller Controller class or controller alias.
	 * @param array<string, mixed> $options Resource expansion options controlling actions, names, parameters, middleware, and verbs.
	 * @return array<string, RouteDefinition> Routes keyed by resource action.
	 *
	 * @throws \RuntimeException When the resource name is empty.
	 */
	public function resource(string $name, string $controller, array $options=[]): array {
		$resource=trim($name, '/');
		if($resource===''){
			throw new \RuntimeException('MVC resource route name cannot be empty.');
		}
		$path='/'.$resource;
		$route_name=$this->resourceBaseRouteName($resource, $options);
		$parameter=$this->resourceParameter($resource, $options);
		$member_path=$this->resourceMemberPath($resource, $path, $options);
		$member_route_name=$this->resourceMemberRouteName($resource, $route_name, $options);
		$route_options=$this->resourceRouteOptions($options);
		$names=is_array($options['names'] ?? null) ? $options['names'] : [];
		$actions=is_array($options['actions'] ?? null) ? $options['actions'] : [];
		$create_segment=$this->resourceUriVerb('create', $options);
		$edit_segment=$this->resourceUriVerb('edit', $options);
		$definitions=[
			'index'=>['GET', $path, 'index'],
			'create'=>['GET', $path.'/'.$create_segment, 'create'],
			'store'=>['POST', $path, 'store'],
			'show'=>['GET', $member_path.'/{'.$parameter.'}', 'show'],
			'edit'=>['GET', $member_path.'/{'.$parameter.'}/'.$edit_segment, 'edit'],
			'update'=>[['PUT', 'PATCH'], $member_path.'/{'.$parameter.'}', 'update'],
			'destroy'=>['DELETE', $member_path.'/{'.$parameter.'}', 'destroy'],
		];
		$routes=[];
		foreach($definitions as $action=>$definition){
			if(!$this->resourceActionEnabled($action, $options)){
				continue;
			}
			[$methods, $route_path, $method]=$definition;
			$options_for_action=$route_options;
			$name_prefix=$this->resourceMemberAction($action) ? $member_route_name : $route_name;
			$options_for_action['name']=$names[$action] ?? $name_prefix.'.'.$action;
			$options_for_action=$this->mergeRouteOptions($options_for_action, $this->resourceActionOptions($action, $options));
			$routes[$action]=$this->match($methods, $route_path, rtrim($controller, '@').'@'.$this->resourceActionMethod($action, $method, $actions), $options_for_action);
		}
		return $routes;
	}

	/**
	 * Expands several conventional resources with shared options.
	 *
	 * @param array<string, string|array<string|int, mixed>> $resources Resource definitions keyed by resource name.
	 * @param array<string, mixed> $options Options merged into every resource definition.
	 * @return array<string, array<string, RouteDefinition>> Resource routes keyed by resource name.
	 */
	public function resources(array $resources, array $options=[]): array {
		return $this->resourceBatch($resources, $options, 'resource');
	}

	/**
	 * Expands an API resource without create and edit form routes.
	 *
	 * @param string $name Resource path/name.
	 * @param string $controller Controller class or alias.
	 * @param array<string, mixed> $options Resource expansion options controlling actions, names, parameters, middleware, and verbs.
	 * @return array<string, RouteDefinition> Routes keyed by resource action.
	 */
	public function apiResource(string $name, string $controller, array $options=[]): array {
		$options['except']=array_values(array_unique(array_merge((array)($options['except'] ?? []), ['create', 'edit'])));
		return $this->resource($name, $controller, $options);
	}

	/**
	 * Expands several API resources with shared options.
	 *
	 * @param array<string, string|array<string|int, mixed>> $resources Resource definitions keyed by resource name.
	 * @param array<string, mixed> $options Options merged into every resource definition.
	 * @return array<string, array<string, RouteDefinition>> API resource routes keyed by resource name.
	 */
	public function apiResources(array $resources, array $options=[]): array {
		return $this->resourceBatch($resources, $options, 'apiResource');
	}

	/**
	 * Expands a singleton resource into MVC routes without member identifiers.
	 *
	 * Singleton resources represent one logical record and therefore omit the
	 * index route and member parameter from show/edit/update/destroy routes.
	 *
	 * @param string $name Resource path/name.
	 * @param string $controller Controller class or alias.
	 * @param array<string, mixed> $options Resource expansion options controlling actions, names, middleware, and verbs.
	 * @return array<string, RouteDefinition> Routes keyed by resource action.
	 *
	 * @throws \RuntimeException When the resource name is empty.
	 */
	public function singletonResource(string $name, string $controller, array $options=[]): array {
		$resource=trim($name, '/');
		if($resource===''){
			throw new \RuntimeException('MVC singleton resource route name cannot be empty.');
		}
		$path='/'.$resource;
		$route_name=$this->resourceBaseRouteName($resource, $options);
		$route_options=$this->resourceRouteOptions($options);
		$names=is_array($options['names'] ?? null) ? $options['names'] : [];
		$actions=is_array($options['actions'] ?? null) ? $options['actions'] : [];
		$create_segment=$this->resourceUriVerb('create', $options);
		$edit_segment=$this->resourceUriVerb('edit', $options);
		$definitions=[
			'create'=>['GET', $path.'/'.$create_segment, 'create'],
			'store'=>['POST', $path, 'store'],
			'show'=>['GET', $path, 'show'],
			'edit'=>['GET', $path.'/'.$edit_segment, 'edit'],
			'update'=>[['PUT', 'PATCH'], $path, 'update'],
			'destroy'=>['DELETE', $path, 'destroy'],
		];
		$routes=[];
		foreach($definitions as $action=>$definition){
			if(!$this->resourceActionEnabled($action, $options)){
				continue;
			}
			[$methods, $route_path, $method]=$definition;
			$options_for_action=$route_options;
			$options_for_action['name']=$names[$action] ?? $route_name.'.'.$action;
			$options_for_action=$this->mergeRouteOptions($options_for_action, $this->resourceActionOptions($action, $options));
			$routes[$action]=$this->match($methods, $route_path, rtrim($controller, '@').'@'.$this->resourceActionMethod($action, $method, $actions), $options_for_action);
		}
		return $routes;
	}

	/**
	 * Expands several singleton resources with shared options.
	 *
	 * @param array<string, string|array<string|int, mixed>> $resources Resource definitions keyed by resource name.
	 * @param array<string, mixed> $options Options merged into every singleton definition.
	 * @return array<string, array<string, RouteDefinition>> Singleton resource routes keyed by resource name.
	 */
	public function singletonResources(array $resources, array $options=[]): array {
		return $this->resourceBatch($resources, $options, 'singletonResource');
	}

	/**
	 * Expands an API singleton resource without create and edit form routes.
	 *
	 * @param string $name Resource path/name.
	 * @param string $controller Controller class or alias.
	 * @param array<string, mixed> $options Resource expansion options controlling actions, names, middleware, and verbs.
	 * @return array<string, RouteDefinition> Routes keyed by resource action.
	 */
	public function apiSingletonResource(string $name, string $controller, array $options=[]): array {
		$options['except']=array_values(array_unique(array_merge((array)($options['except'] ?? []), ['create', 'edit'])));
		return $this->singletonResource($name, $controller, $options);
	}

	/**
	 * Expands several API singleton resources with shared options.
	 *
	 * @param array<string, string|array<string|int, mixed>> $resources Resource definitions keyed by resource name.
	 * @param array<string, mixed> $options Options merged into every singleton definition.
	 * @return array<string, array<string, RouteDefinition>> API singleton routes keyed by resource name.
	 */
	public function apiSingletonResources(array $resources, array $options=[]): array {
		return $this->resourceBatch($resources, $options, 'apiSingletonResource');
	}

	/**
	 * Registers a route for one or more methods after applying active groups.
	 *
	 * Global app route defaults/patterns, group options, controller namespace, and
	 * controller group handlers are normalized before the RouteDefinition is added.
	 *
	 * @param array|string $methods HTTP methods or ANY.
	 * @param string $path Route path.
	 * @param mixed $handler Route handler definition.
	 * @param array<string, mixed> $options Route options merged with active groups.
	 * @return RouteDefinition Registered route.
	 *
	 * @throws \RuntimeException When the handler is null.
	 */
	public function match(array|string $methods, string $path, mixed $handler, array $options=[]): RouteDefinition {
		if($handler===null){
			throw new \RuntimeException('MVC route handler cannot be null.');
		}
		$options=$this->mergedOptions($options);
		if($this->app->controllerNamespace()!==null && !isset($options['controller_namespace'])){
			$options['controller_namespace']=$this->app->controllerNamespace();
		}
		$handler=$this->groupControllerHandler($handler, $options);
		$route=RouteDefinition::make($methods, $this->prefixedPath($path), $handler, $options);
		return $this->add($route);
	}

	/**
	 * Appends an already-built route definition to the collection.
	 *
	 * The route receives a change callback that bumps the collection revision when
	 * fluent route mutations change compiled output.
	 *
	 * @param RouteDefinition $route Route definition to append.
	 * @return RouteDefinition The appended route.
	 */
	public function add(RouteDefinition $route): RouteDefinition {
		$this->routes[]=$route;
		$route->onChange(fn(): int => $this->touch());
		$this->touch();
		return $route;
	}

	/**
	 * Finds the first route definition with the given name.
	 *
	 * @param string $name Route name.
	 * @return ?RouteDefinition Matching route, or null when absent.
	 */
	public function named(string $name): ?RouteDefinition {
		$name=trim($name);
		foreach($this->routes as $route){
			if($route->nameValue()===$name){
				return $route;
			}
		}
		return null;
	}

	/**
	 * Generates a URL for a named route from the compiled manifest.
	 *
	 * @param string $name Route name.
	 * @param array<string, mixed> $parameters Named route parameters for path interpolation.
	 * @param array<string, mixed> $query Query-string parameters appended to the generated URL.
	 * @return string Generated URL.
	 */
	public function url(string $name, array $parameters=[], array $query=[]): string {
		return RouteManifest::namedUrl($this->compile(), $name, $parameters, $query);
	}

	/**
	 * Generates and signs a named route URL.
	 *
	 * The signing secret is resolved from application config first and the
	 * DATAPHYRE_MVC_SIGNING_KEY environment variable second.
	 *
	 * @param string $name Route name.
	 * @param array<string, mixed> $parameters Named route parameters for path interpolation.
	 * @param array<string, mixed> $query Query-string parameters appended before signing.
	 * @param ?int $expires_at Optional Unix expiration timestamp.
	 * @return string Signed URL.
	 */
	public function signedUrl(string $name, array $parameters=[], array $query=[], ?int $expires_at=null): string {
		return SignedUrl::sign($this->url($name, $parameters, $query), $this->signedUrlSecret(), $expires_at);
	}

	/**
	 * Generates a signed named route URL with a required expiration timestamp.
	 *
	 * @param string $name Route name.
	 * @param int $expires_at Unix expiration timestamp.
	 * @param array<string, mixed> $parameters Named route parameters for path interpolation.
	 * @param array<string, mixed> $query Query-string parameters appended before signing.
	 * @return string Temporary signed URL.
	 */
	public function temporarySignedUrl(string $name, int $expires_at, array $parameters=[], array $query=[]): string {
		return $this->signedUrl($name, $parameters, $query, $expires_at);
	}

	/**
	 * Produces the inspection-friendly route list for this collection.
	 *
	 * @return array<int, array<string, mixed>> Route list rows.
	 */
	public function list(): array {
		return RouteList::from($this);
	}

	/**
	 * Runs a callback inside a pushed route option group.
	 *
	 * The group stack is restored in a finally block so exceptions cannot leak
	 * prefixes, middleware, domains, or constraints into later route definitions.
	 *
	 * @param array<string, mixed> $options Group options pushed for nested registrations.
	 * @param callable $callback Callback receiving RouteCollection and MvcApplication.
	 * @return self Current collection.
	 */
	public function group(array $options, callable $callback): self {
		$this->group_stack[]=$options;
		try{
			$callback($this, $this->app);
		}finally{
			array_pop($this->group_stack);
		}
		return $this;
	}

	/**
	 * Runs a callback inside a domain route group.
	 *
	 * @param string $domain Domain constraint.
	 * @param callable $callback Group callback.
	 * @return self Current collection.
	 */
	public function domain(string $domain, callable $callback): self {
		return $this->group(['domain'=>$domain], $callback);
	}

	/**
	 * Runs a callback inside a controller route group.
	 *
	 * String handlers without an at-sign are expanded against this controller.
	 *
	 * @param string $controller Controller class or alias.
	 * @param callable $callback Group callback.
	 * @return self Current collection.
	 */
	public function controller(string $controller, callable $callback): self {
		return $this->group(['controller'=>$controller], $callback);
	}

	/**
	 * Runs a callback inside a path prefix group.
	 *
	 * @param string $prefix Path prefix.
	 * @param callable $callback Group callback.
	 * @return self Current collection.
	 */
	public function prefix(string $prefix, callable $callback): self {
		return $this->group(['prefix'=>$prefix], $callback);
	}

	/**
	 * Runs a callback inside a route name prefix group.
	 *
	 * @param string $prefix Name prefix appended to route names.
	 * @param callable $callback Group callback.
	 * @return self Current collection.
	 */
	public function name(string $prefix, callable $callback): self {
		return $this->group(['as'=>$prefix], $callback);
	}

	/**
	 * Runs a callback inside a middleware group.
	 *
	 * @param array|string $middleware Middleware names or definitions.
	 * @param callable $callback Group callback.
	 * @return self Current collection.
	 */
	public function middleware(array|string $middleware, callable $callback): self {
		return $this->group(['middleware'=>$middleware], $callback);
	}

	/**
	 * Runs a callback with default route parameter values.
	 *
	 * Supports either defaults(array, callback) or defaults(name, value, callback).
	 *
	 * @param array|string $parameter Default map or parameter name.
	 * @param mixed $value Default value or callback for map form.
	 * @param ?callable $callback Group callback for single-parameter form.
	 * @return self Current collection.
	 *
	 * @throws \RuntimeException When no supported signature is provided.
	 */
	public function defaults(array|string $parameter, mixed $value=null, ?callable $callback=null): self {
		if(is_array($parameter) && is_callable($value)){
			return $this->group(['defaults'=>$parameter], $value);
		}
		if(is_string($parameter) && $callback!==null){
			return $this->group(['defaults'=>[$parameter=>$value]], $callback);
		}
		throw new \RuntimeException('MVC route defaults group requires defaults and a callback.');
	}

	/**
	 * Runs a callback with route parameter regex constraints.
	 *
	 * Supports either where(map, callback) or where(name, pattern, callback).
	 *
	 * @param array|string $parameter Constraint map or parameter name.
	 * @param string|callable|null $pattern Regex pattern or callback for map form.
	 * @param ?callable $callback Group callback for single-parameter form.
	 * @return self Current collection.
	 *
	 * @throws \RuntimeException When no supported signature is provided.
	 */
	public function where(array|string $parameter, string|callable|null $pattern=null, ?callable $callback=null): self {
		if($parameter!==[] && is_array($parameter) && is_callable($pattern)){
			return $this->group(['where'=>$parameter], $pattern);
		}
		if(is_string($parameter) && is_string($pattern) && $callback!==null){
			return $this->group(['where'=>[$parameter=>$pattern]], $callback);
		}
		throw new \RuntimeException('MVC route where group requires constraints and a callback.');
	}

	/**
	 * Runs a callback with numeric route parameter constraints.
	 *
	 * @param array|string $parameters Parameter name or names.
	 * @param callable $callback Group callback.
	 * @return self Current collection.
	 */
	public function whereNumber(array|string $parameters, callable $callback): self {
		return $this->wherePreset($parameters, '[0-9]+', $callback);
	}

	/**
	 * Runs a callback with alphabetic route parameter constraints.
	 *
	 * @param array|string $parameters Parameter name or names.
	 * @param callable $callback Group callback.
	 * @return self Current collection.
	 */
	public function whereAlpha(array|string $parameters, callable $callback): self {
		return $this->wherePreset($parameters, '[A-Za-z]+', $callback);
	}

	/**
	 * Runs a callback with alphanumeric route parameter constraints.
	 *
	 * @param array|string $parameters Parameter name or names.
	 * @param callable $callback Group callback.
	 * @return self Current collection.
	 */
	public function whereAlphaNumeric(array|string $parameters, callable $callback): self {
		return $this->wherePreset($parameters, '[A-Za-z0-9]+', $callback);
	}

	/**
	 * Runs a callback with UUID route parameter constraints.
	 *
	 * @param array|string $parameters Parameter name or names.
	 * @param callable $callback Group callback.
	 * @return self Current collection.
	 */
	public function whereUuid(array|string $parameters, callable $callback): self {
		return $this->wherePreset($parameters, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}', $callback);
	}

	/**
	 * Runs a callback with ULID route parameter constraints.
	 *
	 * @param array|string $parameters Parameter name or names.
	 * @param callable $callback Group callback.
	 * @return self Current collection.
	 */
	public function whereUlid(array|string $parameters, callable $callback): self {
		return $this->wherePreset($parameters, '[0-9A-HJKMNP-TV-Z]{26}', $callback);
	}

	/**
	 * Runs a callback with an allow-list route parameter constraint.
	 *
	 * Values are preg-quoted before being joined into a regex alternation.
	 *
	 * @param string $parameter Parameter name.
	 * @param array<int, string|int|float|bool> $values Allowed literal values.
	 * @param callable $callback Group callback.
	 * @return self Current collection.
	 *
	 * @throws \RuntimeException When no non-empty values are provided.
	 */
	public function whereIn(string $parameter, array $values, callable $callback): self {
		$escaped=[];
		foreach($values as $value){
			$value=(string)$value;
			if($value!==''){
				$escaped[]=preg_quote($value, '#');
			}
		}
		if($escaped===[]){
			throw new \RuntimeException('MVC route whereIn group requires at least one value.');
		}
		return $this->group(['where'=>[$parameter=>implode('|', $escaped)]], $callback);
	}

	/**
	 * Returns registered route definitions in registration order.
	 *
	 * @return array<int, RouteDefinition> Route definitions.
	 */
	public function all(): array {
		return $this->routes;
	}

	/**
	 * Returns the current mutation revision.
	 *
	 * @return int Monotonic collection revision.
	 */
	public function revision(): int {
		return $this->revision;
	}

	/**
	 * Increments and returns the collection revision.
	 *
	 * @return int Updated revision.
	 */
	private function touch(): int {
		return ++$this->revision;
	}

	/**
	 * Compiles route definitions into a Dataphyre route manifest.
	 *
	 * Each route receives MVC metadata with its registration index and route name
	 * before RouteManifest performs global manifest normalization.
	 *
	 * @param array<string, mixed> $metadata Additional manifest metadata merged into the compiled output.
	 * @return array<string, mixed> Compiled route manifest.
	 */
	public function compile(array $metadata=[]): array {
		$routes=[];
		foreach($this->routes as $index=>$route){
			$compiled=$route->compile();
			$compiled=RouteManifest::withRouteMetadata($compiled, 'mvc', [
				'route_index'=>$index,
				'route_name'=>$route->nameValue(),
			]);
			$routes[]=$compiled;
		}
		return RouteManifest::compile($routes, array_replace([
			'app'=>$this->app->name(),
			'source'=>'dataphyre.mvc',
		], $metadata));
	}

	/**
	 * Applies active prefix groups to a route path.
	 *
	 * Prefixes are concatenated in group-stack order and normalized with the
	 * routing module's path normalization rules.
	 *
	 * @param string $path Route path.
	 * @return string Prefixed normalized path.
	 */
	private function prefixedPath(string $path): string {
		$prefix='';
		foreach($this->group_stack as $group){
			if(isset($group['prefix']) && is_string($group['prefix'])){
				$prefix.='/'.trim($group['prefix'], '/');
			}
		}
		return Route::normalizePath($prefix.'/'.trim($path, '/'));
	}

	/**
	 * Merges global, group, and route-local options.
	 *
	 * Merge order is app-level defaults, active groups from outer to inner, then
	 * route options, allowing local options to override or append appropriately.
	 *
	 * @param array<string, mixed> $options Route-local options.
	 * @return array<string, mixed> Merged route options.
	 */
	private function mergedOptions(array $options): array {
		$merged=$this->globalRouteOptions();
		foreach($this->group_stack as $group){
			$merged=$this->mergeRouteOptions($merged, $group);
		}
		return $this->mergeRouteOptions($merged, $options);
	}

	/**
	 * Reads global route defaults and constraints from application config.
	 *
	 * @return array<string, mixed> Global route options.
	 */
	private function globalRouteOptions(): array {
		$options=[];
		foreach(['route_defaults', 'defaults'] as $key){
			$defaults=$this->app->config($key, []);
			if(is_array($defaults) && $defaults!==[]){
				$options=$this->mergeRouteOptions($options, ['defaults'=>$defaults]);
			}
		}
		foreach(['route_patterns', 'patterns', 'constraints'] as $key){
			$patterns=$this->app->config($key, []);
			if(is_array($patterns) && $patterns!==[]){
				$options=$this->mergeRouteOptions($options, ['where'=>$patterns]);
			}
		}
		return $options;
	}

	/**
	 * Merges two route option arrays using routing-aware semantics.
	 *
	 * Middleware lists append, constraints and defaults replace by key, name
	 * prefixes concatenate, and prefix keys are intentionally ignored here because
	 * path prefixes are applied separately from option merging.
	 *
	 * @param array<string, mixed> $base Existing options.
	 * @param array<string, mixed> $next Options to apply.
	 * @return array<string, mixed> Merged options.
	 */
	private function mergeRouteOptions(array $base, array $next): array {
		foreach($next as $key=>$value){
			if($key==='middleware'){
				$base[$key]=array_values(array_merge((array)($base[$key] ?? []), (array)$value));
				continue;
			}
			if($key==='without_middleware'){
				$base[$key]=array_values(array_merge((array)($base[$key] ?? []), (array)$value));
				continue;
			}
			if($key==='where' || $key==='constraints' || $key==='patterns'){
				$base['where']=array_replace((array)($base['where'] ?? []), (array)$value);
				continue;
			}
			if($key==='defaults'){
				$base['defaults']=array_replace((array)($base['defaults'] ?? []), (array)$value);
				continue;
			}
			if($key==='as' || $key==='name_prefix'){
				$base['name_prefix']=(string)($base['name_prefix'] ?? '').(string)$value;
				continue;
			}
			if($key!=='prefix'){
				$base[$key]=$value;
			}
		}
		return $base;
	}

	/**
	 * Expands method-only handlers inside controller groups.
	 *
	 * A string handler without `@`, namespace separators, or a file path is treated
	 * as a controller method when the active options include a controller group.
	 *
	 * @param mixed $handler Raw route handler.
	 * @param array<string, mixed> $options Merged route options.
	 * @return mixed controller-group method descriptor, or the original handler when expansion does not apply.
	 */
	private function groupControllerHandler(mixed $handler, array $options): mixed {
		if(!is_string($handler) || str_contains($handler, '@')){
			return $handler;
		}
		$controller=$options['controller'] ?? null;
		if(!is_string($controller) || trim($controller)===''){
			return $handler;
		}
		$method=trim($handler);
		if($method==='' || is_file($method) || str_contains($method, '\\')){
			return $handler;
		}
		return rtrim(trim($controller), '@').'@'.$method;
	}

	/**
	 * Runs a callback with a preset route parameter constraint.
	 *
	 * @param array|string $parameters Parameter name or names.
	 * @param string $pattern Regex pattern.
	 * @param callable $callback Group callback.
	 * @return self Current collection.
	 *
	 * @throws \RuntimeException When no non-empty parameters are provided.
	 */
	private function wherePreset(array|string $parameters, string $pattern, callable $callback): self {
		$where=[];
		foreach((array)$parameters as $parameter){
			$parameter=trim((string)$parameter);
			if($parameter!==''){
				$where[$parameter]=$pattern;
			}
		}
		if($where===[]){
			throw new \RuntimeException('MVC route where preset group requires at least one parameter.');
		}
		return $this->group(['where'=>$where], $callback);
	}

	/**
	 * Decides whether a resource action should be generated.
	 *
	 * The `only` option includes an explicit allow-list, while `except` removes
	 * actions after the allow-list check.
	 *
	 * @param string $action Resource action name.
	 * @param array<string, mixed> $options Resource options containing only/except filters.
	 * @return bool Action generation decision.
	 */
	private function resourceActionEnabled(string $action, array $options): bool {
		$only=$options['only'] ?? null;
		if(is_array($only) && !in_array($action, $only, true)){
			return false;
		}
		$except=$options['except'] ?? null;
		if(is_array($except) && in_array($action, $except, true)){
			return false;
		}
		return true;
	}

	/**
	 * Resolves the route parameter name for a resource member.
	 *
	 * Explicit param wins, then resource-specific parameter maps, then a simple
	 * singularized leaf segment fallback.
	 *
	 * @param string $resource Resource path/name.
	 * @param array<string, mixed> $options Resource options containing param and parameters overrides.
	 * @return string Member parameter name without braces.
	 */
	private function resourceParameter(string $resource, array $options): string {
		if(isset($options['param']) && is_string($options['param']) && trim($options['param'])!==''){
			return trim($options['param'], '{} ');
		}
		$parameters=$options['parameters'] ?? [];
		if(is_array($parameters) && isset($parameters[$resource]) && is_string($parameters[$resource]) && trim($parameters[$resource])!==''){
			return trim($parameters[$resource], '{} ');
		}
		$leaf=basename(str_replace('\\', '/', $resource));
		if(is_array($parameters) && isset($parameters[$leaf]) && is_string($parameters[$leaf]) && trim($parameters[$leaf])!==''){
			return trim($parameters[$leaf], '{} ');
		}
		if(str_ends_with($leaf, 'ies') && strlen($leaf)>3){
			return substr($leaf, 0, -3).'y';
		}
		if(str_ends_with($leaf, 's') && strlen($leaf)>1){
			return substr($leaf, 0, -1);
		}
		return $leaf;
	}

	/**
	 * Removes resource-expansion-only options before route registration.
	 *
	 * @param array<string, mixed> $options Raw resource options.
	 * @return array<string, mixed> Options safe to pass to route definitions.
	 */
	private function resourceRouteOptions(array $options): array {
		foreach(['only', 'except', 'names', 'parameters', 'param', 'name', 'as', 'actions', 'verbs', 'uri_verbs', 'shallow', 'action_options', 'options_for', 'middleware_for', 'without_middleware_for'] as $key){
			unset($options[$key]);
		}
		return $options;
	}

	/**
	 * Resolves URI verb segments for resource create/edit routes.
	 *
	 * Per-resource options win over application-level resource verb config before
	 * falling back to the action name.
	 *
	 * @param string $action Resource action name.
	 * @param array<string, mixed> $options Resource options containing URI verb overrides.
	 * @return string URI segment.
	 */
	private function resourceUriVerb(string $action, array $options): string {
		foreach(['verbs', 'uri_verbs'] as $key){
			$verbs=$options[$key] ?? null;
			if(is_array($verbs) && isset($verbs[$action]) && is_string($verbs[$action]) && trim($verbs[$action], '/ ')!==''){
				return trim($verbs[$action], '/ ');
			}
		}
		foreach(['resource_verbs', 'resource_uri_verbs'] as $key){
			$verbs=$this->app->config($key, []);
			if(is_array($verbs) && isset($verbs[$action]) && is_string($verbs[$action]) && trim($verbs[$action], '/ ')!==''){
				return trim($verbs[$action], '/ ');
			}
		}
		return $action;
	}

	/**
	 * Resolves per-action resource route options.
	 *
	 * Action options can merge arbitrary route options and can add middleware or
	 * without-middleware entries scoped to a resource action.
	 *
	 * @param string $action Resource action name.
	 * @param array<string, mixed> $options Resource options containing per-action option maps.
	 * @return array<string, mixed> Route options for the action.
	 */
	private function resourceActionOptions(string $action, array $options): array {
		$action_options=[];
		foreach(['action_options', 'options_for'] as $key){
			$map=$options[$key] ?? null;
			if(is_array($map) && isset($map[$action]) && is_array($map[$action])){
				$action_options=$this->mergeRouteOptions($action_options, $map[$action]);
			}
		}
		$middleware_for=$options['middleware_for'] ?? null;
		if(is_array($middleware_for) && array_key_exists($action, $middleware_for)){
			$action_options=$this->mergeRouteOptions($action_options, ['middleware'=>$middleware_for[$action]]);
		}
		$without_middleware_for=$options['without_middleware_for'] ?? null;
		if(is_array($without_middleware_for) && array_key_exists($action, $without_middleware_for)){
			$action_options=$this->mergeRouteOptions($action_options, ['without_middleware'=>$without_middleware_for[$action]]);
		}
		return $action_options;
	}

	/**
	 * Checks whether a resource action targets a member route.
	 *
	 * @param string $action Resource action name.
	 * @return bool Member-action decision.
	 */
	private function resourceMemberAction(string $action): bool {
		return in_array($action, ['show', 'edit', 'update', 'destroy'], true);
	}

	/**
	 * Resolves the path prefix used for member resource routes.
	 *
	 * Shallow nested resources drop parent path segments for member routes.
	 *
	 * @param string $resource Resource path/name.
	 * @param string $path Base resource path.
	 * @param array<string, mixed> $options Resource options that may enable shallow member paths.
	 * @return string Member path prefix.
	 */
	private function resourceMemberPath(string $resource, string $path, array $options): string {
		if(($options['shallow'] ?? false)!==true || !str_contains($resource, '/')){
			return $path;
		}
		return '/'.$this->resourceLeaf($resource);
	}

	/**
	 * Resolves the route-name prefix used for member resource routes.
	 *
	 * Shallow nested resources use only the leaf resource name for member routes.
	 *
	 * @param string $resource Resource path/name.
	 * @param string $route_name Base route name.
	 * @param array<string, mixed> $options Resource options that may enable shallow member route names.
	 * @return string Member route-name prefix.
	 */
	private function resourceMemberRouteName(string $resource, string $route_name, array $options): string {
		if(($options['shallow'] ?? false)!==true || !str_contains($resource, '/')){
			return $route_name;
		}
		return $this->resourceRouteName($this->resourceLeaf($resource));
	}

	/**
	 * Resolves the controller method for a resource action.
	 *
	 * @param string $action Resource action name.
	 * @param string $default Default controller method.
	 * @param array<string, mixed> $actions Per-action method overrides.
	 * @return string Controller method name.
	 */
	private function resourceActionMethod(string $action, string $default, array $actions): string {
		$method=$actions[$action] ?? $default;
		$method=is_string($method) ? trim($method) : '';
		return $method!=='' ? $method : $default;
	}

	/**
	 * Resolves the base route-name prefix for a resource.
	 *
	 * Explicit `name` or `as` values win before deriving a dot name from resource
	 * path segments.
	 *
	 * @param string $resource Resource path/name.
	 * @param array<string, mixed> $options Resource options containing explicit route-name overrides.
	 * @return string Base route-name prefix.
	 */
	private function resourceBaseRouteName(string $resource, array $options): string {
		foreach(['name', 'as'] as $key){
			$name=$options[$key] ?? null;
			if(is_string($name) && trim($name) !== ''){
				return trim($name, '. ');
			}
		}
		return $this->resourceRouteName($resource);
	}

	/**
	 * Converts a resource path into a dot-delimited route-name prefix.
	 *
	 * Parameter-only segments are skipped so nested resource route names remain
	 * stable and readable.
	 *
	 * @param string $resource Resource path/name.
	 * @return string Dot-delimited route-name prefix.
	 */
	private function resourceRouteName(string $resource): string {
		$segments=[];
		foreach(explode('/', trim($resource, '/')) as $segment){
			$segment=trim($segment);
			if($segment==='' || (str_starts_with($segment, '{') && str_ends_with($segment, '}'))){
				continue;
			}
			$segments[]=$segment;
		}
		return implode('.', $segments);
	}

	/**
	 * Returns the terminal resource path segment.
	 *
	 * @param string $resource Resource path/name.
	 * @return string Leaf resource segment.
	 */
	private function resourceLeaf(string $resource): string {
		return trim(basename(str_replace('\\', '/', $resource)), '/');
	}

	/**
	 * Expands a keyed resource definition map through a resource registration method.
	 *
	 * Invalid or incomplete entries are skipped so bulk route files can assemble
	 * resources conditionally without wrapping every item.
	 *
	 * @param array<string, string|array<string|int, mixed>> $resources Resource definition map.
	 * @param array<string, mixed> $options Shared resource options.
	 * @param string $method Resource registration method name.
	 * @return array<string, array<string, RouteDefinition>> Expanded resource routes keyed by resource name.
	 */
	private function resourceBatch(array $resources, array $options, string $method): array {
		$routes=[];
		foreach($resources as $name=>$definition){
			if(!is_string($name)){
				continue;
			}
			[$controller, $resource_options]=$this->resourceBatchDefinition($definition, $options);
			$name=trim($name);
			if($name==='' || $controller===''){
				continue;
			}
			$routes[$name]=$this->{$method}($name, $controller, $resource_options);
		}
		return $routes;
	}

	/**
	 * Normalizes one bulk resource definition.
	 *
	 * Definitions may be controller strings or arrays with controller/options plus
	 * inline option keys. Shared options are merged before inline and nested options.
	 *
	 * @param mixed $definition Raw resource definition.
	 * @param array<string, mixed> $options Shared resource options.
	 * @return array{0:string,1:array<string, mixed>} Controller and normalized options.
	 */
	private function resourceBatchDefinition(mixed $definition, array $options): array {
		if(is_string($definition)){
			return [trim($definition), $options];
		}
		if(!is_array($definition)){
			return ['', $options];
		}
		$controller=$definition['controller'] ?? $definition[0] ?? null;
		if(!is_string($controller)){
			return ['', $options];
		}
		$resource_options=$definition['options'] ?? $definition[1] ?? [];
		if(!is_array($resource_options)){
			$resource_options=[];
		}
		unset($definition['controller'], $definition['options'], $definition[0], $definition[1]);
		return [trim($controller), array_replace($options, $definition, $resource_options)];
	}

	/**
	 * Resolves the secret used for MVC signed URLs.
	 *
	 * Application config has priority over the DATAPHYRE_MVC_SIGNING_KEY
	 * environment fallback. Empty secrets are allowed through for callers to handle.
	 *
	 * @return string Signing secret or empty string.
	 */
	private function signedUrlSecret(): string {
		$secret=$this->app->config('signed_url_secret');
		if(is_string($secret) && trim($secret)!==''){
			return $secret;
		}
		$env=getenv('DATAPHYRE_MVC_SIGNING_KEY');
		return is_string($env) ? $env : '';
	}

}
