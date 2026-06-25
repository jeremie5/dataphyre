<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\Routing\RouteCompiler;

/**
 * Runtime container for one configured MVC application.
 *
 * MvcApplication owns the application's service container, provider registry, route collection, dispatcher, route source
 * inventory, namespace conventions, view path, and manifest-cache policy. Construction eagerly registers configured
 * providers and loads configured routes so dispatch and route manifests can inspect a stable application graph.
 */
final class MvcApplication {

	private RouteCollection $routes;
	private ?MvcDispatcher $dispatcher=null;
	private Container $container;
	private ProviderRegistry $providers;
	private array $routeSources=[];

	/**
	 * Creates the MVC application container and loads provider and route configuration.
	 *
	 * Middleware defaults are merged before the config is stored. The application and container are registered into the
	 * container immediately so providers and route files can resolve them during bootstrapping.
	 *
	 * @param string $name Application name used for diagnostics and default cache paths.
	 * @param array<string, mixed> $config MVC application configuration.
	 */
	public function __construct(
		private string $name,
		private array $config=[]
	){
		$config['middleware']=MvcManager::mergeMiddlewareDefaults($config['middleware'] ?? []);
		$this->config=$config;
		$this->container=new Container();
		$this->container->instance(self::class, $this);
		$this->container->instance(Container::class, $this->container);
		$this->providers=new ProviderRegistry($this);
		$this->container->instance(ProviderRegistry::class, $this->providers);
		$this->routes=new RouteCollection($this);
		$this->registerConfiguredProviders();
		$this->loadConfiguredRoutes($config['routes'] ?? []);
	}

	/**
	 * Creates an MVC application from a named configuration array.
	 *
	 * @param string $name Application name.
	 * @param array<string, mixed> $config MVC application configuration.
	 * @return self Configured MVC application.
	 */
	public static function fromConfig(string $name, array $config): self {
		return new self($name, $config);
	}

	/**
	 * Returns the configured application name.
	 *
	 * @return string Application name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the full configuration array or one top-level config value.
	 *
	 * @param ?string $key Optional top-level config key.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed full config array, selected top-level config value, or the caller default when absent.
	 */
	public function config(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->config;
		}
		return $this->config[$key] ?? $default;
	}

	/**
	 * Returns the application's route collection.
	 *
	 * @return RouteCollection Mutable route collection loaded from configuration.
	 */
	public function routes(): RouteCollection {
		return $this->routes;
	}

	/**
	 * Returns the application service container.
	 *
	 * @return Container Container seeded with the application, itself, and provider registry.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Returns the provider registry attached to the application.
	 *
	 * @return ProviderRegistry Provider registry responsible for register/boot lifecycle.
	 */
	public function providers(): ProviderRegistry {
		return $this->providers;
	}

	/**
	 * Returns the configured controller namespace.
	 *
	 * @return ?string Controller namespace without surrounding slashes, or null when not configured.
	 */
	public function controllerNamespace(): ?string {
		return $this->namespaceConfig('controllers');
	}

	/**
	 * Returns the configured model namespace.
	 *
	 * @return ?string Model namespace without surrounding slashes, or null when not configured.
	 */
	public function modelNamespace(): ?string {
		return $this->namespaceConfig('models');
	}

	/**
	 * Returns the configured filesystem path for MVC views.
	 *
	 * @return ?string View directory without a trailing separator, or null when not configured.
	 */
	public function viewPath(): ?string {
		$views=$this->config['views'] ?? [];
		if(is_array($views) && isset($views['path']) && is_string($views['path']) && trim($views['path'])!==''){
			return rtrim($views['path'], '/\\');
		}
		return null;
	}

	/**
	 * Boots providers and returns the application dispatcher.
	 *
	 * The dispatcher is created lazily after provider boot so services and routes registered by providers are available to
	 * dispatch. Subsequent calls reuse the same dispatcher instance.
	 *
	 * @return MvcDispatcher Dispatcher for this application.
	 */
	public function dispatcher(): MvcDispatcher {
		$this->bootProviders();
		return $this->dispatcher ??= new MvcDispatcher($this);
	}

	/**
	 * Returns route source files and modification times discovered while loading route files.
	 *
	 * @return array<string, int> Route source mtimes keyed by file path.
	 */
	public function routeSources(): array {
		return $this->routeSources;
	}

	/**
	 * Resolves the route manifest cache file path.
	 *
	 * A false or null manifest_cache disables caching. A non-empty string is used directly, an array may provide a file key,
	 * and otherwise Dataphyre's ROOTPATH cache location is used when available.
	 *
	 * @return ?string Manifest cache file path, or null when cache writing is disabled or unavailable.
	 */
	public function manifestCacheFile(): ?string {
		$cache=$this->config['manifest_cache'] ?? null;
		if($cache===false || $cache===null){
			return null;
		}
		if(is_string($cache) && trim($cache)!==''){
			return $cache;
		}
		if(is_array($cache) && isset($cache['file']) && is_string($cache['file']) && trim($cache['file'])!==''){
			return $cache['file'];
		}
		if(defined('ROOTPATH') && !empty(ROOTPATH['dataphyre'])){
			return rtrim((string)ROOTPATH['dataphyre'], '/\\').'/cache/mvc/'.$this->name.'.routes.php';
		}
		return null;
	}

	/**
	 * Reports whether route manifest caching has a writable target path configured.
	 *
	 * @return bool True when manifestCacheFile() resolves a path.
	 */
	public function manifestCacheEnabled(): bool {
		return $this->manifestCacheFile()!==null;
	}

	/**
	 * Runs the provider boot lifecycle for the application.
	 */
	public function bootProviders(): void {
		$this->providers->boot();
	}

	/**
	 * Registers providers declared in the application configuration.
	 *
	 * Traversable provider lists are materialized before registration; non-list values are ignored so partial config does
	 * not break construction.
	 */
	private function registerConfiguredProviders(): void {
		$providers=$this->config['providers'] ?? [];
		if($providers instanceof \Traversable){
			$providers=iterator_to_array($providers);
		}
		if(!is_array($providers)){
			return;
		}
		$this->providers->registerMany($providers);
	}

	/**
	 * Reads a namespace config entry from either a string value or an array with a namespace key.
	 *
	 * @param string $key Config key such as controllers or models.
	 * @return ?string Namespace without surrounding slashes, or null when absent.
	 */
	private function namespaceConfig(string $key): ?string {
		$value=$this->config[$key] ?? null;
		if(is_array($value)){
			$value=$value['namespace'] ?? null;
		}
		if(!is_string($value) || trim($value)===''){
			return null;
		}
		return trim($value, '\\');
	}

	/**
	 * Loads route definitions from closures, files, directories, route arrays, RouteDefinition objects, or lists of those.
	 *
	 * @param mixed $routes Route configuration value.
	 */
	private function loadConfiguredRoutes(mixed $routes): void {
		if(is_callable($routes)){
			$routes($this->routes, $this);
			return;
		}
		if(is_string($routes)){
			$this->loadRouteFileOrDirectory($routes);
			return;
		}
		if(!is_array($routes)){
			return;
		}
		if($this->isRouteArray($routes)){
			$this->addRouteArray($routes);
			return;
		}
		foreach($routes as $route){
			if(is_callable($route)){
				$route($this->routes, $this);
				continue;
			}
			if(is_string($route)){
				$this->loadRouteFileOrDirectory($route);
				continue;
			}
			if($route instanceof RouteDefinition){
				$this->routes->add($route);
				continue;
			}
			if(is_array($route)){
				$this->addRouteArray($route);
			}
		}
	}

	/**
	 * Reports whether an associative array looks like a single route definition.
	 *
	 * @param array<string, mixed> $route Candidate route array.
	 * @return bool True when the array contains route-definition keys.
	 */
	private function isRouteArray(array $route): bool {
		return array_key_exists('handler', $route)
			|| array_key_exists('view', $route)
			|| array_key_exists('template', $route)
			|| array_key_exists('redirect', $route)
			|| array_key_exists('redirect_route', $route)
			|| array_key_exists('to_route', $route)
			|| array_key_exists('location', $route)
			|| array_key_exists('path', $route)
			|| array_key_exists('method', $route)
			|| array_key_exists('methods', $route);
	}

	/**
	 * Adds one route array to the route collection using the correct route factory.
	 *
	 * View, named-route redirect, URL redirect, and generic handler routes are detected by their defining keys.
	 *
	 * @param array<string, mixed> $route Route definition array.
	 */
	private function addRouteArray(array $route): void {
		if(array_key_exists('view', $route) || array_key_exists('template', $route)){
			$this->routes->view(
				$route['path'] ?? '/',
				$route['view'] ?? $route['template'],
				is_array($route['data'] ?? null) ? $route['data'] : [],
				$route
			);
			return;
		}
		if(array_key_exists('redirect_route', $route) || array_key_exists('to_route', $route)){
			$this->routes->redirectToRoute(
				$route['path'] ?? '/',
				$route['redirect_route'] ?? $route['to_route'],
				is_array($route['parameters'] ?? null) ? $route['parameters'] : [],
				is_array($route['query'] ?? null) ? $route['query'] : [],
				(int)($route['status'] ?? 302),
				$route
			);
			return;
		}
		if(array_key_exists('redirect', $route) || array_key_exists('location', $route)){
			$this->routes->redirect(
				$route['path'] ?? '/',
				$route['redirect'] ?? $route['location'],
				(int)($route['status'] ?? 302),
				$route
			);
			return;
		}
		$this->routes->match(
			$route['methods'] ?? $route['method'] ?? 'GET',
			$route['path'] ?? '/',
			$route['handler'] ?? null,
			$route
		);
	}

	/**
	 * Loads every route file resolved from a file or directory path.
	 *
	 * @param string $path Route file or route directory path.
	 */
	private function loadRouteFileOrDirectory(string $path): void {
		foreach(RouteCompiler::routeFiles($path) as $file){
			$this->loadRouteFile($file);
		}
	}

	/**
	 * Requires a route file and loads the route definition it returns.
	 *
	 * Source modification times are recorded before requiring the file so manifest caches can track invalidation inputs.
	 * Route files may return a callable, one RouteDefinition, an array route definition, or a list of route definitions.
	 *
	 * @param string $file Route file path.
	 */
	private function loadRouteFile(string $file): void {
		$this->routeSources=array_replace($this->routeSources, RouteCompiler::sourceMtimes($file));
		$routes=$this->routes;
		$app=$this;
		$result=require($file);
		if(is_callable($result)){
			$result($routes, $app);
			return;
		}
		if($result instanceof RouteDefinition){
			$routes->add($result);
			return;
		}
		if(is_array($result)){
			$this->loadConfiguredRoutes($result);
		}
	}
}
