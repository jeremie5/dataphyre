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

/**
 * Static entry point for defining, discovering, documenting, and dispatching Dataphyre API endpoints.
 *
 * Api keeps the public declaration surface compact: endpoint builders are delegated to Endpoint,
 * documentation and internal dispatch work is delegated to ApiManager, and group/profile options
 * are normalized before being applied to ApiGroup instances.
 */
final class Api {

	/**
	 * Returns the shared API manager used by the static entry point.
	 *
	 * The manager owns documentation route compilation, application discovery, OpenAPI generation,
	 * internal request dispatch, endpoint cache invalidation, and compiled route execution helpers.
	 *
	 * @return ApiManager Process-wide API registry and dispatcher.
	 */
	public static function manager(): ApiManager {
		return ApiManager::instance();
	}

	/**
	 * Creates an endpoint definition for one or more HTTP methods.
	 *
	 * The path is normalized by Endpoint and the handler is stored until the endpoint is compiled
	 * into a routing manifest entry.
	 *
	 * @param array|string $methods HTTP method name or list of names accepted by the endpoint.
	 * @param string $path Route template, with leading slash normalization handled by Endpoint.
	 * @param mixed $handler Callable, controller descriptor, or null placeholder resolved by the router.
	 * @return Endpoint Mutable endpoint builder carrying methods, path, handler, and API metadata.
	 */
	public static function methods(array|string $methods, string $path, mixed $handler=null): Endpoint {
		return Endpoint::methods($methods, $path, $handler);
	}

	/**
	 * Creates an unnamed API group and applies static-entry-point options to it.
	 *
	 * Supported options include `prefix`, `middleware`, `tags`, `trace`, and `dispatch`.
	 * Middleware and tags may be passed as a single definition or as lists.
	 *
	 * @param array{prefix?:string, middleware?:mixed, tags?:array<int,string>|string, trace?:array<string,mixed>, dispatch?:array<string,mixed>} $options Group defaults applied before endpoints are created from the group.
	 * @return ApiGroup Configured group used to create endpoints with shared API metadata.
	 */
	public static function group(array $options=[]): ApiGroup {
		return self::configureGroup(ApiGroup::make(), $options);
	}

	/**
	 * Creates a named API group profile and applies static-entry-point options to it.
	 *
	 * Profiles let callers attach a stable name to shared endpoint defaults so discovery
	 * and dispatch can preserve the source grouping.
	 *
	 * @param string $name Profile name stored on endpoints created from the group.
	 * @param array{prefix?:string, middleware?:mixed, tags?:array<int,string>|string, trace?:array<string,mixed>, dispatch?:array<string,mixed>} $options Group defaults applied before endpoints are created from the profile.
	 * @return ApiGroup Configured named group used to create profiled endpoints.
	 */
	public static function profile(string $name, array $options=[]): ApiGroup {
		return self::configureGroup(ApiGroup::make($name), $options);
	}

	/**
	 * Creates a GET endpoint definition.
	 *
	 * The returned builder can be enriched with tags, aliases, schemas, responses, security,
	 * lifecycle hooks, execution metadata, cache rules, and dispatch aliases before compilation.
	 *
	 * @param string $path Route template for the endpoint.
	 * @param mixed $handler Callable, controller descriptor, or null placeholder resolved by the router.
	 * @return Endpoint GET endpoint builder.
	 */
	public static function get(string $path, mixed $handler=null): Endpoint {
		return Endpoint::get($path, $handler);
	}

	/**
	 * Creates a POST endpoint definition.
	 *
	 * POST endpoints are typically used for mutation or command-style internal dispatch calls,
	 * but the builder remains a normal Endpoint with the same metadata surface as other verbs.
	 *
	 * @param string $path Route template for the endpoint.
	 * @param mixed $handler Callable, controller descriptor, or null placeholder resolved by the router.
	 * @return Endpoint POST endpoint builder.
	 */
	public static function post(string $path, mixed $handler=null): Endpoint {
		return Endpoint::post($path, $handler);
	}

	/**
	 * Creates a PUT endpoint definition.
	 *
	 * PUT endpoints carry the same OpenAPI, schema, security, dispatch, and execution metadata
	 * as any other Endpoint; only the compiled method list differs.
	 *
	 * @param string $path Route template for the endpoint.
	 * @param mixed $handler Callable, controller descriptor, or null placeholder resolved by the router.
	 * @return Endpoint PUT endpoint builder.
	 */
	public static function put(string $path, mixed $handler=null): Endpoint {
		return Endpoint::put($path, $handler);
	}

	/**
	 * Creates a PATCH endpoint definition.
	 *
	 * PATCH endpoints are compiled as standard route definitions with API metadata preserved
	 * for discovery and internal dispatch matching.
	 *
	 * @param string $path Route template for the endpoint.
	 * @param mixed $handler Callable, controller descriptor, or null placeholder resolved by the router.
	 * @return Endpoint PATCH endpoint builder.
	 */
	public static function patch(string $path, mixed $handler=null): Endpoint {
		return Endpoint::patch($path, $handler);
	}

	/**
	 * Creates a DELETE endpoint definition.
	 *
	 * DELETE endpoints use the same builder contract as other verbs and may still declare
	 * request bodies, parameters, security, response schemas, and lifecycle hooks when needed.
	 *
	 * @param string $path Route template for the endpoint.
	 * @param mixed $handler Callable, controller descriptor, or null placeholder resolved by the router.
	 * @return Endpoint DELETE endpoint builder.
	 */
	public static function delete(string $path, mixed $handler=null): Endpoint {
		return Endpoint::delete($path, $handler);
	}

	/**
	 * Creates an endpoint definition that intentionally accepts any supported request method.
	 *
	 * The `ANY` marker is preserved in the endpoint definition and interpreted by route
	 * compilation and dispatch matching rather than expanded by this entry point.
	 *
	 * @param string $path Route template for the endpoint.
	 * @param mixed $handler Callable, controller descriptor, or null placeholder resolved by the router.
	 * @return Endpoint Method-agnostic endpoint builder.
	 */
	public static function any(string $path, mixed $handler=null): Endpoint {
		return Endpoint::any($path, $handler);
	}

	/**
	 * Compiles the OpenAPI JSON route, Swagger UI route, and Swagger asset route.
	 *
	 * Options are normalized by ApiManager and attached to each compiled route under `api_docs`
	 * so the controllers can locate the application bootstrap and documentation paths.
	 *
	 * @param array{docs_path?:string, spec_path?:string, asset_path?:string, bootstrap?:mixed, application?:string, title?:string, version?:string, servers?:array<int,string|array<string,mixed>>} $options Documentation route paths, bootstrap value, and OpenAPI defaults.
	 * @return array<int, array<string,mixed>> Compiled route records for the API documentation surface.
	 */
	public static function documentationRoutes(array $options=[]): array {
		return self::manager()->documentationRoutes($options);
	}

	/**
	 * Discovers API endpoint metadata for a configured application manifest.
	 *
	 * When no application id is supplied, the manager resolves the current/default application.
	 * Only compiled routes that expose API metadata are returned.
	 *
	 * @param ?string $applicationId Optional application id used to load the route manifest.
	 * @return array<int, array<string,mixed>> Normalized endpoint discovery records.
	 */
	public static function discoverApplication(?string $applicationId=null): array {
		return self::manager()->discoverApplication($applicationId);
	}

	/**
	 * Extracts normalized endpoint discovery records from a compiled application manifest.
	 *
	 * Each returned record preserves the API path, methods, tags, aliases, schema, security,
	 * execution, trace, profile, dispatch, and original route handler information needed by docs
	 * and internal dispatch tools.
	 *
	 * @param array{routes?:array<int,array<string,mixed>>} $manifest Application manifest containing compiled route records.
	 * @return array<int, array<string,mixed>> API-only endpoint records derived from the manifest.
	 */
	public static function discoverManifest(array $manifest): array {
		return self::manager()->discoverManifest($manifest);
	}

	/**
	 * Builds an OpenAPI document for an application.
	 *
	 * Application defaults are merged with explicit options by ApiManager before the normalized
	 * endpoint discovery records are handed to OpenApiGenerator.
	 *
	 * @param ?string $applicationId Optional application id used to load definition and routes.
	 * @param array<string, mixed> $options OpenAPI title, version, server, security, and generator overrides.
	 * @return array<string, mixed> OpenAPI-compatible associative array.
	 */
	public static function openApiDocument(?string $applicationId=null, array $options=[]): array {
		return self::manager()->openApiDocument($applicationId, $options);
	}

	/**
	 * Dispatches one internal API request definition through the compiled application manifest.
	 *
	 * Request definitions may target an endpoint by path, URI, alias, endpoint name, method,
	 * profile, body, or post data. The returned record is normalized for API clients rather
	 * than being a raw HTTP Response object.
	 *
	 * @param array<string, mixed> $request Internal dispatch request definition.
	 * @param array{application?:string, trust_auth?:bool, expose_exceptions?:bool} $options Dispatch options such as application id, auth trust, and exception exposure.
	 * @return array<string, mixed> Dispatch result with ok flag, status, response data, timing, and route context.
	 */
	public static function dispatch(array $request, array $options=[]): array {
		return self::manager()->dispatch($request, $options);
	}

	/**
	 * Dispatches multiple internal API request definitions with shared options.
	 *
	 * The manager enforces a request limit, optionally stops on the first failed record, and
	 * returns aggregate timing, count, failure count, and per-request dispatch results.
	 *
	 * @param array<int|string, array<string,mixed>|mixed> $requests List or keyed map of internal dispatch request definitions.
	 * @param array<string, mixed> $options Batch options including limit, limit_error, continue_on_error, and dispatch options.
	 * @return array<string, mixed> Batch result with aggregate status and normalized response records.
	 */
	public static function dispatchBatch(array $requests, array $options=[]): array {
		return self::manager()->dispatchBatch($requests, $options);
	}

	/**
	 * Dispatches a chain of internal API requests using chain-specific defaults.
	 *
	 * Chain dispatch currently reuses batch dispatch while applying the chain limit and error
	 * label defaults, allowing callers to override those defaults through the options argument.
	 *
	 * @param array<int, array<string,mixed>|mixed> $requests Ordered internal dispatch request definitions.
	 * @param array<string, mixed> $options Chain options merged over the manager's chain defaults.
	 * @return array<string, mixed> Chain result with aggregate status and normalized response records.
	 */
	public static function dispatchChain(array $requests, array $options=[]): array {
		return self::manager()->dispatchChain($requests, $options);
	}

	/**
	 * Clears persistent endpoint response cache entries.
	 *
	 * With no names, all endpoint cache directories are cleared. With names, each name is
	 * normalized and used to remove the cache item files associated with that endpoint.
	 *
	 * @param string ...$names Optional endpoint cache names to invalidate.
	 * @return int Number of cache item files removed.
	 */
	public static function clearEndpointCache(string ...$names): int {
		return self::manager()->clearEndpointCache(...$names);
	}

	/**
	 * Evaluates API security requirements for a compiled route and request.
	 *
	 * A null return means authorization passed or the route has no applicable API security.
	 * A Response return is an early authorization failure response that should short-circuit
	 * normal route execution.
	 *
	 * @param array{api?:array{security?:array<int,array<string,array<int,string>>>, security_schemes?:array<string,array<string,mixed>>}} $route Compiled route record with optional API security metadata.
	 * @param Request $request HTTP request being matched against the route.
	 * @return ?Response Early failure response, or null when execution may continue.
	 */
	public static function authorizeCompiledRoute(array $route, Request $request): ?Response {
		return self::manager()->authorizeCompiledRoute($route, $request);
	}

	/**
	 * Executes API-specific behavior for a compiled route.
	 *
	 * The manager applies schema validation, endpoint caching, bindings, lifecycle hooks,
	 * execution targets, trace data, and response normalization. Null means the route does
	 * not contain API execution metadata that this helper can run.
	 *
	 * @param array{api?:array<string,mixed>} $route Compiled route record with API execution metadata.
	 * @param Request $request HTTP request being handled.
	 * @return ?Response Executed API response, or null when there is no API execution metadata.
	 */
	public static function executeCompiledRoute(array $route, Request $request): ?Response {
		return self::manager()->executeCompiledRoute($route, $request);
	}

	/**
	 * Applies static entry-point option arrays to an API group instance.
	 *
	 * Recognized keys are `prefix`, `middleware`, `tags`, `trace`, and `dispatch`. The `trace`
	 * array may contain an `enabled` boolean; remaining trace keys are passed through as trace
	 * options. Unknown keys are ignored so callers may share wider configuration arrays safely.
	 *
	 * @param ApiGroup $group Group being configured by the static entry point.
	 * @param array{prefix?:string, middleware?:mixed, tags?:array<int,string>|string, trace?:array<string,mixed>, dispatch?:array<string,mixed>} $options Option bag for group/profile creation.
	 * @return ApiGroup Same group instance after supported options have been applied.
	 */
	private static function configureGroup(ApiGroup $group, array $options): ApiGroup {
		if(isset($options['prefix']) && is_string($options['prefix'])){
			$group->prefix($options['prefix']);
		}
		if(isset($options['middleware'])){
			$middleware=$options['middleware'];
			if(is_array($middleware)){
				$group->middleware(...$middleware);
			}else{
				$group->middleware($middleware);
			}
		}
		if(isset($options['tags'])){
			$tags=$options['tags'];
			if(is_array($tags)){
				$group->tag(...$tags);
			}else{
				$group->tag($tags);
			}
		}
		if(isset($options['trace']) && is_array($options['trace'])){
			$enabled=($options['trace']['enabled'] ?? true)===true;
			$trace=$options['trace'];
			unset($trace['enabled']);
			$group->withTrace($enabled, $trace);
		}
		if(isset($options['dispatch']) && is_array($options['dispatch'])){
			$group->dispatchDefaults($options['dispatch']);
		}
		return $group;
	}
}
