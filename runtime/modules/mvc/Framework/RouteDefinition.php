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
use Dataphyre\Routing\CompilableRoute;
use Dataphyre\Routing\ControllerAction;
use Dataphyre\Routing\Route;

/**
 * Mutable MVC route definition that can be compiled into a Dataphyre routing record.
 *
 * RouteDefinition captures normalized methods, path, handler, middleware additions/removals,
 * domain restrictions, parameter constraints, defaults, model bindings, route names, controller
 * namespace context, macros, and change callbacks before handing the route to the routing compiler.
 */
final class RouteDefinition implements CompilableRoute {

	private static array $macros=[];
	private array $methods;
	private string $path;
	private mixed $handler;
	private array $middleware=[];
	private array $excludedMiddleware=[];
	private array $modelBindings=[];
	private array $constraints=[];
	private array $defaults=[];
	private ?string $name=null;
	private ?string $domain=null;
	private string $namePrefix='';
	private ?string $controllerNamespace=null;
	private $onChange=null;

	/**
	 * Initializes normalized route state from collection and group options.
	 *
	 * The constructor is private so every route passes through make(), preserving
	 * method/path normalization and applying route options consistently before the
	 * definition becomes mutable through fluent methods.
	 *
	 * @param list<string> $methods HTTP methods.
	 * @param string $path Route path template.
	 * @param mixed $handler Raw route handler.
	 * @param array<string,mixed> $options Route options from collection, groups, and caller.
	 */
	private function __construct(array $methods, string $path, mixed $handler, array $options=[]){
		$this->methods=Route::normalizeMethods($methods, ['GET']);
		$this->path=Route::normalizePath($path);
		$this->handler=$handler;
		if(isset($options['name_prefix']) && is_string($options['name_prefix'])){
			$this->namePrefix=trim($options['name_prefix']);
		}
		if(isset($options['controller_namespace']) && is_string($options['controller_namespace']) && trim($options['controller_namespace'])!==''){
			$this->controllerNamespace=trim($options['controller_namespace'], '\\');
		}
		if(isset($options['middleware'])){
			$this->middleware((array)$options['middleware']);
		}
		if(isset($options['without_middleware'])){
			$this->withoutMiddleware((array)$options['without_middleware']);
		}
		if(isset($options['bindings']) && is_array($options['bindings'])){
			$this->modelBindings=$options['bindings'];
		}
		if(isset($options['defaults']) && is_array($options['defaults'])){
			$this->defaults($options['defaults']);
		}
		foreach(['where', 'constraints', 'patterns'] as $key){
			if(isset($options[$key]) && is_array($options[$key])){
				$this->where($options[$key]);
			}
		}
		if(isset($options['name']) && is_string($options['name'])){
			$this->name($options['name']);
		}
		if(isset($options['domain']) && is_string($options['domain'])){
			$this->domain($options['domain']);
		}
	}

	/**
	 * Creates a route definition from methods, path, handler, and optional group defaults.
	 *
	 * Options may include name_prefix, controller_namespace, middleware, without_middleware,
	 * bindings, defaults, where/constraints/patterns, name, and domain.
	 *
	 * @param array|string $methods HTTP method name or list of method names.
	 * @param string $path Route path template.
	 * @param mixed $handler Callable, controller descriptor, file path, or router-supported handler.
	 * @param array<string,mixed> $options Optional route defaults and group context.
	 * @return self New mutable route definition.
	 */
	public static function make(array|string $methods, string $path, mixed $handler, array $options=[]): self {
		return new self((array)$methods, $path, $handler, $options);
	}

	/**
	 * Registers a dynamic route-definition macro.
	 *
	 * Closure macros are bound to the current RouteDefinition instance when invoked through __call().
	 *
	 * @param string $name Dynamic method name.
	 * @param callable $macro Macro implementation.
	 * @return void
	 */
	public static function macro(string $name, callable $macro): void {
		$name=trim($name);
		if($name!==''){
			self::$macros[$name]=$macro;
		}
	}

	/**
	 * Reports whether a dynamic route-definition macro is registered.
	 *
	 * @param string $name Macro name to inspect.
	 * @return bool True when the macro table contains the name.
	 */
	public static function hasMacro(string $name): bool {
		return isset(self::$macros[$name]);
	}

	/**
	 * Removes all registered route-definition macros.
	 *
	 * @return void
	 */
	public static function flushMacros(): void {
		self::$macros=[];
	}

	/**
	 * Dispatches a registered dynamic route macro call.
	 *
	 * @param string $name Macro name.
	 * @param list<mixed> $arguments Arguments forwarded to the macro.
	 * @return mixed value produced by the registered macro after closure binding to this route definition.
	 *
	 * @throws BadMethodCallException When the requested macro is not registered.
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
	 * Assigns a route name with the configured prefix applied.
	 *
	 * Empty names are ignored. Successful changes trigger the onChange callback.
	 *
	 * @param string $name Route name suffix.
	 * @return self Same definition for fluent chaining.
	 */
	public function name(string $name): self {
		$name=trim($name);
		if($name!==''){
			$this->name=$this->namePrefix.$name;
			$this->changed();
		}
		return $this;
	}

	/**
	 * Appends middleware definitions to the route.
	 *
	 * List arrays are flattened one level so group defaults and direct calls behave consistently.
	 * Each accepted middleware entry triggers the onChange callback.
	 *
	 * @param array|string ...$middleware Middleware names, descriptors, or lists of descriptors.
	 * @return self Same definition for fluent chaining.
	 */
	public function middleware(array|string ...$middleware): self {
		foreach($middleware as $definition){
			$entries=is_array($definition) && self::isList($definition) ? $definition : [$definition];
			foreach($entries as $entry){
				$this->middleware[]=$entry;
				$this->changed();
			}
		}
		return $this;
	}

	/**
	 * Appends middleware definitions that should be excluded from the route.
	 *
	 * List arrays are flattened one level. Exclusions are preserved for compilation/manifest
	 * consumers even though compile() currently forwards only the included middleware list.
	 *
	 * @param array|string ...$middleware Middleware names, descriptors, or lists of descriptors to exclude.
	 * @return self Same definition for fluent chaining.
	 */
	public function withoutMiddleware(array|string ...$middleware): self {
		foreach($middleware as $definition){
			$entries=is_array($definition) && self::isList($definition) ? $definition : [$definition];
			foreach($entries as $entry){
				$this->excludedMiddleware[]=$entry;
				$this->changed();
			}
		}
		return $this;
	}

	/**
	 * Restricts the route to a normalized host/domain pattern.
	 *
	 * Empty normalized domains are ignored. Successful changes trigger the onChange callback.
	 *
	 * @param string $domain Host pattern accepted by the routing compiler.
	 * @return self Same definition for fluent chaining.
	 */
	public function domain(string $domain): self {
		$domain=Route::normalizeDomain($domain);
		if($domain!==''){
			$this->domain=$domain;
			$this->changed();
		}
		return $this;
	}

	/**
	 * Adds route parameter regular-expression constraints.
	 *
	 * Anchors are stripped from supplied patterns because the routing compiler applies constraints
	 * inside a larger route expression.
	 *
	 * @param array|string $parameter Parameter name or map of parameter names to patterns.
	 * @param ?string $pattern Pattern used when a single parameter name is supplied.
	 * @return self Same definition for fluent chaining.
	 */
	public function where(array|string $parameter, ?string $pattern=null): self {
		$constraints=is_array($parameter) ? $parameter : [$parameter=>$pattern];
		foreach($constraints as $name=>$constraint){
			$name=trim((string)$name);
			$constraint=$this->normalizeConstraint($constraint);
			if($name!=='' && $constraint!==''){
				$this->constraints[$name]=$constraint;
				$this->changed();
			}
		}
		return $this;
	}

	/**
	 * Restricts one or more parameters to digits.
	 *
	 * @param array|string $parameters Parameter name or list of names.
	 * @return self Same definition for fluent chaining.
	 */
	public function whereNumber(array|string $parameters): self {
		return $this->wherePreset($parameters, '[0-9]+');
	}

	/**
	 * Restricts one or more parameters to ASCII letters.
	 *
	 * @param array|string $parameters Parameter name or list of names.
	 * @return self Same definition for fluent chaining.
	 */
	public function whereAlpha(array|string $parameters): self {
		return $this->wherePreset($parameters, '[A-Za-z]+');
	}

	/**
	 * Restricts one or more parameters to ASCII letters and digits.
	 *
	 * @param array|string $parameters Parameter name or list of names.
	 * @return self Same definition for fluent chaining.
	 */
	public function whereAlphaNumeric(array|string $parameters): self {
		return $this->wherePreset($parameters, '[A-Za-z0-9]+');
	}

	/**
	 * Restricts one or more parameters to UUID values.
	 *
	 * @param array|string $parameters Parameter name or list of names.
	 * @return self Same definition for fluent chaining.
	 */
	public function whereUuid(array|string $parameters): self {
		return $this->wherePreset($parameters, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}');
	}

	/**
	 * Restricts one or more parameters to Crockford-base32 ULID values.
	 *
	 * @param array|string $parameters Parameter name or list of names.
	 * @return self Same definition for fluent chaining.
	 */
	public function whereUlid(array|string $parameters): self {
		return $this->wherePreset($parameters, '[0-9A-HJKMNP-TV-Z]{26}');
	}

	/**
	 * Restricts a parameter to one of the supplied literal values.
	 *
	 * Values are escaped before being joined into the route constraint expression.
	 *
	 * @param string $parameter Parameter name.
	 * @param list<string|int|float> $values Allowed literal values.
	 * @return self Same definition for fluent chaining.
	 */
	public function whereIn(string $parameter, array $values): self {
		$escaped=[];
		foreach($values as $value){
			$value=(string)$value;
			if($value!==''){
				$escaped[]=preg_quote($value, '#');
			}
		}
		if($escaped!==[]){
			$this->where($parameter, implode('|', $escaped));
		}
		return $this;
	}

	/**
	 * Adds default values for route parameters.
	 *
	 * Defaults are used by URL generation and forwarded into the compiled route.
	 *
	 * @param array|string $parameter Parameter name or map of default values.
	 * @param mixed $value Default value when a single parameter name is supplied.
	 * @return self Same definition for fluent chaining.
	 */
	public function defaults(array|string $parameter, mixed $value=null): self {
		$defaults=is_array($parameter) ? $parameter : [$parameter=>$value];
		foreach($defaults as $name=>$default){
			$name=trim((string)$name);
			if($name!==''){
				$this->defaults[$name]=$default;
				$this->changed();
			}
		}
		return $this;
	}

	/**
	 * Registers a callback invoked after mutable route state changes.
	 *
	 * @param ?callable $callback Callback receiving this RouteDefinition, or null to clear it.
	 * @return self Same definition for fluent chaining.
	 */
	public function onChange(?callable $callback): self {
		$this->onChange=$callback;
		return $this;
	}

	/**
	 * Returns normalized HTTP methods for the route.
	 *
	 * @return array<int,string> Uppercase method names.
	 */
	public function methods(): array {
		return $this->methods;
	}

	/**
	 * Returns the normalized route path template.
	 *
	 * @return string Route path used for matching and URL generation.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Returns the original route handler value.
	 *
	 * String controller descriptors are not converted until compile() calls compiledHandler().
	 *
	 * @return callable|array|string|mixed Route handler descriptor exactly as supplied by the caller.
	 */
	public function handler(): mixed {
		return $this->handler;
	}

	/**
	 * Returns middleware definitions appended to the route.
	 *
	 * @return array<int,mixed> Middleware descriptors in insertion order.
	 */
	public function middlewareDefinitions(): array {
		return $this->middleware;
	}

	/**
	 * Returns middleware definitions excluded from inherited route context.
	 *
	 * @return array<int,mixed> Excluded middleware descriptors in insertion order.
	 */
	public function excludedMiddlewareDefinitions(): array {
		return $this->excludedMiddleware;
	}

	/**
	 * Returns route model binding definitions supplied by group options.
	 *
	 * @return array<string,mixed> Parameter-to-model binding metadata.
	 */
	public function modelBindings(): array {
		return $this->modelBindings;
	}

	/**
	 * Returns route parameter constraints.
	 *
	 * @return array<string,string> Parameter names mapped to unanchored regular-expression patterns.
	 */
	public function constraints(): array {
		return $this->constraints;
	}

	/**
	 * Returns default parameter values used by URL generation and compilation.
	 *
	 * @return array<string,mixed> Route defaults keyed by parameter name.
	 */
	public function defaultsValues(): array {
		return $this->defaults;
	}

	/**
	 * Returns the full route name after prefix application.
	 *
	 * @return ?string Route name, or null when unnamed.
	 */
	public function nameValue(): ?string {
		return $this->name;
	}

	/**
	 * Returns the normalized domain constraint.
	 *
	 * @return ?string Domain pattern, or null when unrestricted.
	 */
	public function domainValue(): ?string {
		return $this->domain;
	}

	/**
	 * Generates a URL for this route definition.
	 *
	 * Explicit parameters override route defaults before the URL helper interpolates the path and
	 * appends query parameters.
	 *
	 * @param array<string,mixed> $parameters Route parameter values.
	 * @param array<string,mixed> $query Query-string values.
	 * @return string Generated URL.
	 */
	public function url(array $parameters=[], array $query=[]): string {
		return Route::url($this->path, array_replace($this->defaults, $parameters), $query, $this->domain);
	}

	/**
	 * Tests whether the compiled route matches a request method, path, and optional host.
	 *
	 * The dispatcher writes extracted path parameters into the referenced parameters argument.
	 *
	 * @param string $method Request method.
	 * @param string $path Request path.
	 * @param array<string,string> $parameters Output parameter bag populated on match.
	 * @param ?string $host Optional host used for domain-constrained routes.
	 * @return bool True when the compiled dispatcher matches the route.
	 */
	public function matches(string $method, string $path, array &$parameters=[], ?string $host=null): bool {
		if(class_exists('\dataphyre\routing\compiled_route_dispatcher')===false){
			return false;
		}
		$match=\dataphyre\routing\compiled_route_dispatcher::match_routes_for_request([$this->compile()], $method, $path, $host);
		if($match===null){
			return false;
		}
		$parameters=$match['parameters'] ?? [];
		return true;
	}

	/**
	 * Compiles the MVC definition into a routing manifest record.
	 *
	 * Compilation applies the normalized handler, route name, middleware, domain, constraints, and
	 * defaults to a Dataphyre\Routing\Route before returning the route compiler payload.
	 *
	 * @return array Compiled route record consumed by the routing dispatcher and manifest tools.
	 */
	public function compile(): array {
		$route=Route::methods($this->methods, $this->path, $this->compiledHandler());
		if($this->name!==null){
			$route->name($this->name);
		}
		if($this->middleware!==[]){
			$route->middleware($this->middleware);
		}
		if($this->domain!==null){
			$route->domain($this->domain);
		}
		if($this->constraints!==[]){
			$route->where($this->constraints);
		}
		if($this->defaults!==[]){
			$route->defaults($this->defaults);
		}
		return $route->compile();
	}

	/**
	 * Converts string controller handlers into compiler-ready controller actions.
	 *
	 * File path handlers are preserved as-is, while non-file strings are resolved
	 * through ControllerAction with the optional controller namespace captured from
	 * application or route group context.
	 *
	 * @return ControllerAction|callable|array|string|mixed Compiler-ready handler descriptor with controller strings normalized.
	 */
	private function compiledHandler(): mixed {
		if(is_string($this->handler) && !is_file($this->handler)){
			return ControllerAction::fromString($this->handler, $this->controllerNamespace);
		}
		return $this->handler;
	}

	/**
	 * Checks whether an array is a sequential list.
	 *
	 * @param array<mixed> $value Array to inspect.
	 * @return bool List-array decision.
	 */
	private static function isList(array $value): bool {
		return array_keys($value)===range(0, count($value)-1);
	}

	/**
	 * Applies the same regex constraint to one or more route parameters.
	 *
	 * @param array|string $parameters Parameter name or names.
	 * @param string $pattern Regex pattern.
	 * @return self Same definition for fluent chaining.
	 */
	private function wherePreset(array|string $parameters, string $pattern): self {
		foreach((array)$parameters as $parameter){
			$this->where((string)$parameter, $pattern);
		}
		return $this;
	}

	/**
	 * Normalizes a route constraint pattern for embedding in route regexes.
	 *
	 * Leading `^` and trailing `$` anchors are stripped because the routing compiler
	 * embeds constraints inside a larger compiled route expression.
	 *
	 * @param mixed $constraint Raw constraint pattern.
	 * @return string Unanchored regex fragment.
	 */
	private function normalizeConstraint(mixed $constraint): string {
		$constraint=trim((string)$constraint);
		if($constraint===''){
			return '';
		}
		if($constraint[0]==='^'){
			$constraint=substr($constraint, 1);
		}
		if(str_ends_with($constraint, '$')){
			$constraint=substr($constraint, 0, -1);
		}
		return $constraint;
	}

	/**
	 * Notifies the owning collection that route state changed.
	 *
	 * The callback is optional and is installed by RouteCollection::add() so the
	 * collection revision tracks fluent route mutations.
	 */
	private function changed(): void {
		if($this->onChange!==null){
			($this->onChange)($this);
		}
	}
}
