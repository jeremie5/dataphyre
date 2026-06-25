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

/**
 * Registry and dispatch facade for configured MVC applications.
 *
 * The manager keeps one process-local application registry, lazily builds
 * applications from module configuration, overlays per-app settings on global
 * defaults, and exposes the shared route and dispatch entrypoints used by the
 * framework helpers.
 */
final class MvcManager {

	private static ?self $instance=null;
	private array $apps=[];

	/**
	 * Returns the process-local MVC manager singleton.
	*
	 * @return self Shared manager instance for the current PHP process.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Clears the cached manager and all lazily constructed MVC applications.
	*
	 * This is primarily used by tests, reload hooks, and long-lived workers after
	 * configuration changes so subsequent calls rebuild applications from current
	 * module configuration.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$instance=null;
	}

	/**
	 * Returns a named MVC application, building it from configuration on first use.
	*
	 * Empty names resolve to `default`. Once created, the application instance is
	 * retained in the registry so controllers, route collections, providers, and
	 * middleware stacks keep request-local identity.
	 *
	 * @param string $name Application name from MVC configuration.
	 * @return MvcApplication Registered or newly constructed application instance.
	 */
	public function app(string $name='default'): MvcApplication {
		$name=trim($name) ?: 'default';
		return $this->apps[$name] ??= MvcApplication::fromConfig($name, $this->appConfig($name));
	}

	/**
	 * Registers or replaces a named application in the process-local registry.
	*
	 * Array definitions are normalized through `MvcApplication::fromConfig()` so
	 * callers can inject generated app configuration without constructing the
	 * application graph manually. Registering an existing object stores it as-is.
	 *
	 * @param string $name Application name; blank values are normalized to `default`.
	 * @param MvcApplication|array<string, mixed> $application Application instance or config array.
	 * @return MvcApplication Stored application instance.
	 */
	public function register(string $name, MvcApplication|array $application): MvcApplication {
		$name=trim($name) ?: 'default';
		if(is_array($application)){
			$application=MvcApplication::fromConfig($name, $application);
		}
		return $this->apps[$name]=$application;
	}

	/**
	 * Resolves the configured default MVC application.
	*
	 * The `default_app` config value may point to any named app; missing, empty,
	 * or non-string values fall back to the literal `default` application.
	 *
	 * @return MvcApplication Default application instance.
	 */
	public function defaultApp(): MvcApplication {
		$name=Mvc::config('default_app');
		return $this->app(is_string($name) && trim($name)!=='' ? $name : 'default');
	}

	/**
	 * Returns the route collection for the selected MVC application.
	*
	 * Passing `null` selects the configured default application; passing a name
	 * resolves that application through the same lazy registry used by dispatch.
	 *
	 * @param ?string $app Optional application name.
	 * @return RouteCollection Application route collection.
	 */
	public function routes(?string $app=null): RouteCollection {
		return ($app===null ? $this->defaultApp() : $this->app($app))->routes();
	}

	/**
	 * Dispatches an HTTP request through the selected MVC application's dispatcher.
	*
	 * When no request object is supplied the current HTTP environment is captured.
	 * Application selection mirrors `routes()`, keeping named-app dispatch and
	 * default dispatch behavior consistent.
	 *
	 * @param ?Request $request Explicit request envelope, or `null` to capture the current request.
	 * @param ?string $app Optional application name.
	 * @return Response Dispatcher response returned by the selected application.
	 */
	public function dispatch(?Request $request=null, ?string $app=null): Response {
		$application=$app===null ? $this->defaultApp() : $this->app($app);
		return $application->dispatcher()->dispatch($request ?? Request::capture());
	}

	/**
	 * Builds the effective configuration array for one MVC application.
	 *
	 * Global MVC configuration supplies shared controllers, model bindings,
	 * middleware, providers, route sources, cache flags, response headers, and
	 * error hooks. Per-app config from `apps[$name]` overlays that base with
	 * recursive associative merges while list-like arrays replace the base value.
	 * The signed URL middleware alias is always present.
	 *
	 * @param string $name Application name being resolved.
	 * @return array<string, mixed> Effective application configuration.
	 */
	private function appConfig(string $name): array {
		$apps=Mvc::config('apps', []);
		$appConfig=is_array($apps) && isset($apps[$name]) && is_array($apps[$name]) ? $apps[$name] : [];
		$config=self::mergeConfig([
			'name'=>$name,
			'controllers'=>Mvc::config('controllers', []),
			'models'=>Mvc::config('models', []),
			'views'=>Mvc::config('views', []),
			'middleware'=>Mvc::config('middleware', []),
			'global_middleware'=>Mvc::config('global_middleware', []),
			'middleware_stack'=>Mvc::config('middleware_stack', []),
			'middleware_groups'=>Mvc::config('middleware_groups', []),
			'providers'=>Mvc::config('providers', []),
			'model_bindings'=>Mvc::config('model_bindings', []),
			'signed_url_secret'=>Mvc::config('signed_url_secret'),
			'routes'=>Mvc::config('routes', []),
			'manifest_cache'=>Mvc::config('manifest_cache', false),
			'response_headers'=>Mvc::config('response_headers', []),
			'not_found_handler'=>Mvc::config('not_found_handler'),
			'error_handler'=>Mvc::config('error_handler'),
		], $appConfig);
		$config['middleware']=self::mergeConfig([
			'signed'=>SignedUrlMiddleware::class,
		], is_array($config['middleware'] ?? null) ? $config['middleware'] : []);
		return $config;
	}

	/**
	 * Recursively overlays associative configuration while replacing list values.
	 *
	 * This merge contract lets nested option maps inherit defaults, but keeps
	 * ordered lists such as middleware stacks and route sources predictable by
	 * replacing them wholesale when an app provides its own list.
	 *
	 * @param array<string|int, mixed> $base Default configuration.
	 * @param array<string|int, mixed> $override Application-specific override.
	 * @return array<string|int, mixed> Merged configuration.
	 */
	private static function mergeConfig(array $base, array $override): array {
		foreach($override as $key=>$value){
			if(is_array($value) && isset($base[$key]) && is_array($base[$key]) && self::isList($value)===false && self::isList($base[$key])===false){
				$base[$key]=self::mergeConfig($base[$key], $value);
				continue;
			}
			$base[$key]=$value;
		}
		return $base;
	}

	/**
	 * Adds Dataphyre's built-in middleware aliases to a caller-provided map.
	*
	 * Custom aliases override defaults through the same associative merge behavior
	 * used for application configuration. Non-array values are treated as an empty
	 * custom map, preserving the standard aliases.
	 *
	 * @param mixed $middleware Custom middleware alias map.
	 * @return array<string, class-string> Effective middleware alias map.
	 */
	public static function mergeMiddlewareDefaults(mixed $middleware): array {
		return self::mergeConfig([
			'auth'=>AccessMiddleware::class,
			'cache'=>CacheMiddleware::class,
			'can'=>PermissionMiddleware::class,
			'can_any'=>PermissionAnyMiddleware::class,
			'csrf'=>CsrfMiddleware::class,
			'guest'=>GuestMiddleware::class,
			'session'=>SessionMiddleware::class,
			'signed'=>SignedUrlMiddleware::class,
			'throttle'=>ThrottleMiddleware::class,
		], is_array($middleware) ? $middleware : []);
	}

	/**
	 * Detects whether an array should be treated as an ordered list for config merging.
	 *
	 * Empty arrays count as lists here, matching PHP's `range(0, -1)` comparison
	 * behavior and causing empty override lists to replace inherited list values.
	 *
	 * @param array<string|int, mixed> $value Candidate configuration array.
	 * @return bool `true` when keys are exactly `0..n-1` in order.
	 */
	private static function isList(array $value): bool {
		return array_keys($value)===range(0, count($value)-1);
	}
}
