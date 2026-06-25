<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

/**
 * Fluent API endpoint group that applies shared metadata and dispatch behavior to endpoints.
 *
 * ApiGroup is a lightweight authoring helper: it stores a path prefix, middleware, tags, security requirements, servers,
 * trace options, lifecycle hooks, dispatch defaults, and an optional profile name, then applies them to Endpoint instances
 * as routes are created. It does not compile routes itself; Endpoint remains responsible for compiled API metadata.
 */
final class ApiGroup {

	private ?string $name=null;
	private string $prefix='';
	private array $middleware=[];
	private array $tags=[];
	private array $securityAny=[];
	private array $securityAll=[];
	private array $servers=[];
	private ?array $traceDefinition=null;
	private array $lifecycle=[
		'before'=>[],
		'after'=>[],
		'error'=>[],
	];
	private array $dispatchDefaults=[];

	/**
	 * Stores the optional group profile name.
	 *
	 * @param ?string $name Optional profile name propagated to grouped endpoints.
	 */
	private function __construct(?string $name=null){
		$name=is_string($name) ? trim($name) : null;
		$this->name=$name!=='' ? $name : null;
	}

	/**
	 * Creates a new API group.
	 *
	 * @param ?string $name Optional profile name attached to endpoints created by the group.
	 * @return self New API group definition.
	 */
	public static function make(?string $name=null): self {
		return new self($name);
	}

	/**
	 * Sets the route path prefix applied to every endpoint path.
	 *
	 * @param string $prefix Route prefix normalized to leading-slash form.
	 * @return self This group after updating its prefix.
	 */
	public function prefix(string $prefix): self {
		$this->prefix=self::normalizePath($prefix);
		return $this;
	}

	/**
	 * Appends middleware definitions inherited by grouped endpoints.
	 *
	 * Middleware is stored verbatim because the routing layer owns middleware resolution and validation.
	 *
	 * @param array|string ...$middleware Route middleware definitions in router-native form.
	 * @return self This group after appending middleware.
	 */
	public function middleware(array|string ...$middleware): self {
		foreach($middleware as $definition){
			$this->middleware[]=$definition;
		}
		return $this;
	}

	/**
	 * Adds OpenAPI tags inherited by grouped endpoints.
	 *
	 * Nested tag arrays are flattened recursively, empty tags are ignored, and duplicate tags keep their first position.
	 *
	 * @param array|string ...$tags Tag names or nested tag lists.
	 * @return self This group after adding unique tags.
	 */
	public function tag(array|string ...$tags): self {
		foreach($tags as $tag){
			if(is_array($tag)){
				$this->tag(...$tag);
				continue;
			}
			$tag=trim((string)$tag);
			if($tag===''){
				continue;
			}
			$this->tags[$tag]=$tag;
		}
		return $this;
	}

	/**
	 * Adds alternative security requirements inherited by grouped endpoints.
	 *
	 * Each scheme is later applied through Endpoint::auth(), preserving OpenAPI OR semantics.
	 *
	 * @param SecurityScheme ...$schemes Security schemes that can independently authorize endpoints.
	 * @return self This group after appending alternative security schemes.
	 */
	public function auth(SecurityScheme ...$schemes): self {
		foreach($schemes as $scheme){
			$this->securityAny[]=$scheme;
		}
		return $this;
	}

	/**
	 * Adds combined security requirements inherited by grouped endpoints.
	 *
	 * Schemes are later applied through Endpoint::authAll(), preserving OpenAPI AND semantics.
	 *
	 * @param SecurityScheme ...$schemes Security schemes that must all authorize endpoints.
	 * @return self This group after appending combined security schemes.
	 */
	public function authAll(SecurityScheme ...$schemes): self {
		foreach($schemes as $scheme){
			$this->securityAll[]=$scheme;
		}
		return $this;
	}

	/**
	 * Adds an operation server inherited by grouped endpoints.
	 *
	 * @param string $url Server URL or OpenAPI server template.
	 * @param ?string $description Optional server description.
	 * @return self This group after appending server metadata.
	 */
	public function server(string $url, ?string $description=null): self {
		$this->servers[]=[
			'url'=>trim($url),
			'description'=>$description,
		];
		return $this;
	}

	/**
	 * Sets trace metadata inherited by grouped endpoints.
	 *
	 * @param bool $enabled Whether endpoint tracing should be enabled.
	 * @param array<string, mixed> $options Static trace options passed to Endpoint::withTrace().
	 * @return self This group after replacing trace metadata.
	 */
	public function withTrace(bool $enabled=true, array $options=[]): self {
		$options['enabled']=$enabled;
		$this->traceDefinition=$options;
		return $this;
	}

	/**
	 * Adds a lifecycle hook inherited by endpoints before main execution.
	 *
	 * @param mixed $target Hook target accepted by Endpoint::beforeExecute().
	 * @param array<string, mixed> $options Static hook options.
	 * @return self This group after appending the before hook.
	 */
	public function beforeExecute(mixed $target, array $options=[]): self {
		$this->lifecycle['before'][]=[
			'target'=>$target,
			'options'=>$options,
		];
		return $this;
	}

	/**
	 * Adds a lifecycle hook inherited by endpoints after successful execution.
	 *
	 * @param mixed $target Hook target accepted by Endpoint::afterExecute().
	 * @param array<string, mixed> $options Static hook options.
	 * @return self This group after appending the after hook.
	 */
	public function afterExecute(mixed $target, array $options=[]): self {
		$this->lifecycle['after'][]=[
			'target'=>$target,
			'options'=>$options,
		];
		return $this;
	}

	/**
	 * Adds a lifecycle hook inherited by endpoints when execution fails.
	 *
	 * @param mixed $target Hook target accepted by Endpoint::onError().
	 * @param array<string, mixed> $options Static hook options.
	 * @return self This group after appending the error hook.
	 */
	public function onError(mixed $target, array $options=[]): self {
		$this->lifecycle['error'][]=[
			'target'=>$target,
			'options'=>$options,
		];
		return $this;
	}

	/**
	 * Merges dispatch defaults inherited by grouped endpoints.
	 *
	 * @param array<string, mixed> $defaults Static defaults passed to Endpoint::dispatchDefaults().
	 * @return self This group after merging dispatch defaults.
	 */
	public function dispatchDefaults(array $defaults): self {
		$this->dispatchDefaults=array_replace($this->dispatchDefaults, $defaults);
		return $this;
	}

	/**
	 * Creates an endpoint for one or more HTTP methods under the group prefix.
	 *
	 * @param array<int, string>|string $methods HTTP methods or ANY.
	 * @param string $path Endpoint path relative to the group prefix.
	 * @param mixed $handler Optional direct route handler.
	 * @return Endpoint Endpoint with group metadata applied.
	 */
	public function methods(array|string $methods, string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::methods($methods, $this->path($path), $handler));
	}

	/**
	 * Creates a GET endpoint under the group prefix.
	 *
	 * @param string $path Endpoint path relative to the group prefix.
	 * @param mixed $handler Optional direct route handler.
	 * @return Endpoint Endpoint with group metadata applied.
	 */
	public function get(string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::get($this->path($path), $handler));
	}

	/**
	 * Creates a POST endpoint under the group prefix.
	 *
	 * @param string $path Endpoint path relative to the group prefix.
	 * @param mixed $handler Optional direct route handler.
	 * @return Endpoint Endpoint with group metadata applied.
	 */
	public function post(string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::post($this->path($path), $handler));
	}

	/**
	 * Creates a PUT endpoint under the group prefix.
	 *
	 * @param string $path Endpoint path relative to the group prefix.
	 * @param mixed $handler Optional direct route handler.
	 * @return Endpoint Endpoint with group metadata applied.
	 */
	public function put(string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::put($this->path($path), $handler));
	}

	/**
	 * Creates a PATCH endpoint under the group prefix.
	 *
	 * @param string $path Endpoint path relative to the group prefix.
	 * @param mixed $handler Optional direct route handler.
	 * @return Endpoint Endpoint with group metadata applied.
	 */
	public function patch(string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::patch($this->path($path), $handler));
	}

	/**
	 * Creates a DELETE endpoint under the group prefix.
	 *
	 * @param string $path Endpoint path relative to the group prefix.
	 * @param mixed $handler Optional direct route handler.
	 * @return Endpoint Endpoint with group metadata applied.
	 */
	public function delete(string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::delete($this->path($path), $handler));
	}

	/**
	 * Creates an ANY endpoint under the group prefix.
	 *
	 * @param string $path Endpoint path relative to the group prefix.
	 * @param mixed $handler Optional direct route handler.
	 * @return Endpoint Endpoint with group metadata applied.
	 */
	public function any(string $path, mixed $handler=null): Endpoint {
		return $this->apply(Endpoint::any($this->path($path), $handler));
	}

	/**
	 * Applies group metadata and lifecycle behavior to an existing endpoint.
	 *
	 * Middleware, tags, security, servers, trace, lifecycle hooks, and dispatch defaults are appended or merged into the
	 * endpoint. A named group also sets an endpoint profile containing the group prefix for dispatch diagnostics.
	 *
	 * @param Endpoint $endpoint Endpoint to mutate with group defaults.
	 * @return Endpoint Same endpoint instance after group metadata is applied.
	 */
	public function apply(Endpoint $endpoint): Endpoint {
		if($this->middleware!==[]){
			$endpoint->middleware(...$this->middleware);
		}
		if($this->tags!==[]){
			$endpoint->tag(...array_values($this->tags));
		}
		if($this->securityAny!==[]){
			$endpoint->auth(...$this->securityAny);
		}
		if($this->securityAll!==[]){
			$endpoint->authAll(...$this->securityAll);
		}
		foreach($this->servers as $server){
			$endpoint->server((string)($server['url'] ?? ''), isset($server['description']) ? (string)$server['description'] : null);
		}
		if(is_array($this->traceDefinition)){
			$enabled=($this->traceDefinition['enabled'] ?? true)===true;
			$options=$this->traceDefinition;
			unset($options['enabled']);
			$endpoint->withTrace($enabled, $options);
		}
		foreach($this->lifecycle['before'] as $hook){
			$endpoint->beforeExecute($hook['target'], is_array($hook['options'] ?? null) ? $hook['options'] : []);
		}
		foreach($this->lifecycle['after'] as $hook){
			$endpoint->afterExecute($hook['target'], is_array($hook['options'] ?? null) ? $hook['options'] : []);
		}
		foreach($this->lifecycle['error'] as $hook){
			$endpoint->onError($hook['target'], is_array($hook['options'] ?? null) ? $hook['options'] : []);
		}
		if($this->dispatchDefaults!==[]){
			$endpoint->dispatchDefaults($this->dispatchDefaults);
		}
		if($this->name!==null){
			$endpoint->profile($this->name, array_filter([
				'prefix'=>$this->prefix!=='' ? $this->prefix : null,
			], static fn(mixed $value): bool => $value!==null && $value!==''));
		}
		return $endpoint;
	}

	/**
	 * Joins a relative endpoint path to the group prefix.
	 *
	 * @param string $path Endpoint path relative to the group prefix.
	 * @return string Normalized absolute endpoint path.
	 */
	private function path(string $path): string {
		return self::joinPath($this->prefix, $path);
	}

	/**
	 * Joins two normalized route path fragments without duplicating slashes.
	 *
	 * @param string $prefix Group prefix.
	 * @param string $path Endpoint path.
	 * @return string Absolute route path.
	 */
	private static function joinPath(string $prefix, string $path): string {
		$prefix=self::normalizePath($prefix);
		$path=self::normalizePath($path);
		if($prefix==='/' || $prefix===''){
			return $path;
		}
		if($path==='/'){
			return $prefix;
		}
		return rtrim($prefix, '/').'/'.ltrim($path, '/');
	}

	/**
	 * Normalizes a route path fragment to the router's leading-slash form.
	 *
	 * @param string $path Raw route path fragment.
	 * @return string Normalized path with no trailing slash except root.
	 */
	private static function normalizePath(string $path): string {
		$path='/'.trim((string)$path, '/');
		return $path==='/' ? '/' : rtrim($path, '/');
	}
}
