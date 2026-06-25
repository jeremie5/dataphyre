<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

/**
 * Carries the resolved MVC route state for a dispatched request.
 *
 * Route context is the lightweight handoff between route matching and
 * controller/action execution. It keeps the application object, declarative
 * route definition, compiled matcher metadata, and extracted route parameters
 * together so middleware, controllers, and diagnostics can inspect the same
 * dispatch facts without re-running route compilation.
 */
final class MvcRouteContext {

	/**
	 * Stores the dispatch context produced by the router.
	 *
	 * The context does not validate or mutate the compiled route metadata. It is
	 * a snapshot of authoritative router output, so consumers can compare the
	 * compiled data with the original RouteDefinition when debugging route
	 * ambiguity or parameter extraction.
	 *
	 * @param MvcApplication $app Application that owns the matched route.
	 * @param RouteDefinition $route Declarative route definition selected by the matcher.
	 * @param array<string,mixed> $compiledRoute Matcher metadata such as pattern, methods, regex, and target data.
	 * @param array<string,mixed> $parameters Route parameters extracted from the current request path.
	 */
	public function __construct(
		private MvcApplication $app,
		private RouteDefinition $route,
		private array $compiledRoute,
		private array $parameters=[]
	){}

	/**
	 * Returns the application that owns the route.
	 *
	 * @return MvcApplication Current MVC application instance.
	 */
	public function app(): MvcApplication {
		return $this->app;
	}

	/**
	 * Returns the declarative route definition selected by the matcher.
	 *
	 * @return RouteDefinition Matched route definition.
	 */
	public function route(): RouteDefinition {
		return $this->route;
	}

	/**
	 * Returns compiled matcher metadata for the route.
	 *
	 * The exact keys are produced by the router/compiler and may include static
	 * path data, regex captures, HTTP methods, controller target, middleware, or
	 * source diagnostics. The array is returned as-is so advanced tooling can
	 * inspect compiler output without losing implementation-specific fields.
	 *
	 * @return array<string, mixed> Compiled route metadata from the matcher.
	 */
	public function compiledRoute(): array {
		return $this->compiledRoute;
	}

	/**
	 * Returns all route parameters extracted from the request.
	 *
	 * @return array<string, mixed> Parameter map keyed by route placeholder name.
	 */
	public function parameters(): array {
		return $this->parameters;
	}

	/**
	 * Returns one route parameter with a fallback.
	 *
	 * Missing parameters are not treated as errors because optional route
	 * segments and middleware-provided defaults are common during dispatch.
	 *
	 * @param string $name Placeholder name to read from the parameter map.
	 * @param mixed $default Value returned when the parameter was not extracted.
	 * @return mixed extracted route parameter value, or the caller default when absent.
	 */
	public function parameter(string $name, mixed $default=null): mixed {
		return $this->parameters[$name] ?? $default;
	}

	/**
	 * Returns the route name exposed by the matched definition.
	 *
	 * @return ?string Named route identifier, or null for anonymous routes.
	 */
	public function name(): ?string {
		return $this->route->nameValue();
	}
}
