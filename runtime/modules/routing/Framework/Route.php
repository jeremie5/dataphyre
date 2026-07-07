<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Routing;

require_once __DIR__.'/CompilableRoute.php';

/**
 * Fluent route definition that compiles into the runtime dispatcher shape.
 *
 * A route stores HTTP method constraints, a normalized path, a handler, optional
 * domain constraints, middleware descriptors, parameter regexes, defaults, and
 * metadata. Builder methods mutate the definition in place and {@see compile()}
 * emits the array consumed by route tables, cache warmers, and dispatch
 * diagnostics.
 */
final class Route implements CompilableRoute {

	/** @var array<int, string> Normalized uppercase HTTP methods, or ANY for wildcard registration. */
	private array $methods;
	/** @var string Normalized leading-slash path pattern. */
	private string $path;
	/** @var mixed Controller action, callable, string handler, or dispatcher-supported array. */
	private mixed $handler;
	/** @var array<int, array<string, mixed>> Normalized middleware descriptors in execution order. */
	private array $middleware=[];
	/** @var ?string Optional route name used by registries and URL generation. */
	private ?string $name=null;
	/** @var ?string Optional normalized host pattern for domain-constrained routes. */
	private ?string $domain=null;
	/** @var array<string, string> Regex fragments keyed by route or domain parameter name. */
	private array $constraints=[];
	/** @var array<string, mixed> Default parameter values keyed by parameter name. */
	private array $defaults=[];
	/** @var array<string, mixed> Extra compile-time metadata for documentation and tooling. */
	private array $metadata=[];

	/**
	 * Creates a route definition from normalized constructor inputs.
	 *
	 * The constructor is private so all definitions pass through the named
	 * factories that communicate HTTP intent. Methods are uppercased and
	 * de-duplicated, while paths are converted to Dataphyre's leading-slash
	 * canonical form.
	 *
	 * @param array<int|string, mixed> $methods HTTP method list accepted by the route.
	 * @param string $path Route path pattern, with `{name}`, `{name?}`, and `{...name}` placeholders allowed.
	 * @param mixed $handler Dispatcher handler to compile later.
	 */
	private function __construct(array $methods, string $path, mixed $handler){
		$this->methods=self::normalizeMethodList($methods);
		$this->path=self::normalizePathValue($path);
		$this->handler=$handler;
	}

	/**
	 * Creates a route for an explicit set of HTTP methods.
	 *
	 * Method names are trimmed, uppercased, de-duplicated, and stored in the
	 * order first encountered. Empty method names are ignored, which lets callers
	 * pass generated method lists without pre-filtering them.
	 *
	 * @param array|string $methods HTTP method or methods accepted by the route.
	 * @param string $path Route path pattern to normalize.
	 * @param mixed $handler Dispatcher handler to associate with the route.
	 * @return self Mutable route builder ready for additional constraints.
	 */
	public static function methods(array|string $methods, string $path, mixed $handler): self {
		return new self((array)$methods, $path, $handler);
	}

	/**
	 * Creates a GET route definition.
	 *
	 * @param string $path Route path pattern to normalize.
	 * @param mixed $handler Dispatcher handler to associate with the route.
	 * @return self Mutable GET route builder.
	 */
	public static function get(string $path, mixed $handler): self {
		return new self(['GET'], $path, $handler);
	}

	/**
	 * Creates a POST route definition.
	 *
	 * @param string $path Route path pattern to normalize.
	 * @param mixed $handler Dispatcher handler to associate with the route.
	 * @return self Mutable POST route builder.
	 */
	public static function post(string $path, mixed $handler): self {
		return new self(['POST'], $path, $handler);
	}

	/**
	 * Creates a HEAD route definition.
	 *
	 * @param string $path Route path pattern to normalize.
	 * @param mixed $handler Dispatcher handler to associate with the route.
	 * @return self Mutable HEAD route builder.
	 */
	public static function head(string $path, mixed $handler): self {
		return new self(['HEAD'], $path, $handler);
	}

	/**
	 * Creates a PUT route definition.
	 *
	 * @param string $path Route path pattern to normalize.
	 * @param mixed $handler Dispatcher handler to associate with the route.
	 * @return self Mutable PUT route builder.
	 */
	public static function put(string $path, mixed $handler): self {
		return new self(['PUT'], $path, $handler);
	}

	/**
	 * Creates a PATCH route definition.
	 *
	 * @param string $path Route path pattern to normalize.
	 * @param mixed $handler Dispatcher handler to associate with the route.
	 * @return self Mutable PATCH route builder.
	 */
	public static function patch(string $path, mixed $handler): self {
		return new self(['PATCH'], $path, $handler);
	}

	/**
	 * Creates a DELETE route definition.
	 *
	 * @param string $path Route path pattern to normalize.
	 * @param mixed $handler Dispatcher handler to associate with the route.
	 * @return self Mutable DELETE route builder.
	 */
	public static function delete(string $path, mixed $handler): self {
		return new self(['DELETE'], $path, $handler);
	}

	/**
	 * Creates an OPTIONS route definition.
	 *
	 * @param string $path Route path pattern to normalize.
	 * @param mixed $handler Dispatcher handler to associate with the route.
	 * @return self Mutable OPTIONS route builder.
	 */
	public static function options(string $path, mixed $handler): self {
		return new self(['OPTIONS'], $path, $handler);
	}

	/**
	 * Creates a wildcard route definition accepted by every HTTP method.
	 *
	 * @param string $path Route path pattern to normalize.
	 * @param mixed $handler Dispatcher handler to associate with the route.
	 * @return self Mutable wildcard route builder.
	 */
	public static function any(string $path, mixed $handler): self {
		return new self(['ANY'], $path, $handler);
	}

	/**
	 * Expands a route pattern into a relative URL or protocol-relative domain URL.
	 *
	 * Named placeholders consume matching entries from `$parameters`. Remaining
	 * parameters are merged into the query string before explicit `$query` values
	 * so callers can pass route and query data together. Missing required
	 * placeholders raise a runtime exception; optional placeholders disappear
	 * when null, empty, or absent.
	 *
	 * @param string $path Path pattern containing route placeholders.
	 * @param array<string, mixed> $parameters Placeholder values and spillover query parameters.
	 * @param array<string, mixed> $query Explicit query parameters that override spillover values.
	 * @param ?string $domain Optional domain pattern to prepend as a protocol-relative host.
	 * @return string Encoded URL generated from the supplied pattern and parameters.
	 */
	public static function url(string $path, array $parameters=[], array $query=[], ?string $domain=null): string {
		$path=self::normalizePathValue($path);
		$prefix='';
		if($domain!==null && trim($domain)!==''){
			$domain=self::normalizeDomainValue($domain);
			$prefix='//'.(str_contains($domain, '{') ? self::parameterizePattern($domain, $parameters) : $domain);
		}
		if(str_contains($path, '{')){
			$path=self::parameterizePattern($path, $parameters, true);
		}
		if($parameters!==[]){
			$query=array_replace($parameters, $query);
		}
		if($query!==[]){
			$path.='?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
		}
		return $prefix.$path;
	}

	/**
	 * Normalizes a domain pattern for route registration and URL generation.
	 *
	 * @param string $domain Domain, URL, or host-like value.
	 * @return string Lowercase host pattern without scheme, path, or surrounding dots.
	 */
	public static function normalizeDomain(string $domain): string {
		return self::normalizeDomainValue($domain);
	}

	/**
	 * Replaces path or domain placeholders with encoded parameter values.
	 *
	 * The parameter array is passed by reference so consumed route parameters can
	 * be removed before remaining values are promoted into the query string.
	 * Splat placeholders encode every supplied segment independently to preserve
	 * slash boundaries.
	 *
	 * @param string $pattern Path or domain pattern containing placeholders.
	 * @param array<string, mixed> $parameters Parameter bag consumed during expansion.
	 * @param bool $splat True when `{...name}` placeholders should expand into path segments.
	 * @return string Pattern with placeholders replaced by encoded values.
	 *
	 * @throws \RuntimeException When a required placeholder has no parameter value.
	 */
	private static function parameterizePattern(string $pattern, array &$parameters, bool $splat=true): string {
		if($splat){
			$pattern=preg_replace_callback('/\{\.\.\.([A-Za-z_][A-Za-z0-9_]*)\}/', function(array $matches) use (&$parameters): string {
				$name=$matches[1];
				if(!array_key_exists($name, $parameters)){
					throw new \RuntimeException("Missing route parameter '$name'.");
				}
				$value=$parameters[$name];
				unset($parameters[$name]);
				$segments=is_array($value) ? $value : explode('/', trim((string)$value, '/'));
				$encodedSegments=[];
				foreach($segments as $segment){
					$encodedSegments[]=rawurlencode((string)$segment);
				}
				return implode('/', $encodedSegments);
			}, $pattern);
		}
		$path=preg_replace_callback('/\{\.\.\.([A-Za-z_][A-Za-z0-9_]*)\}/', function(array $matches) use (&$parameters): string {
			return $matches[0];
		}, $pattern);
		$path=preg_replace_callback('#/\{([A-Za-z_][A-Za-z0-9_]*)\?\}#', function(array $matches) use (&$parameters): string {
			$name=$matches[1];
			if(!array_key_exists($name, $parameters) || $parameters[$name]===null || $parameters[$name]===''){
				unset($parameters[$name]);
				return '';
			}
			$value=$parameters[$name];
			unset($parameters[$name]);
			return '/'.rawurlencode((string)$value);
		}, $path);
		return preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)(\?)?\}/', function(array $matches) use (&$parameters): string {
			$name=$matches[1];
			if(!array_key_exists($name, $parameters)){
				if(($matches[2] ?? '')==='?'){
					return '';
				}
				throw new \RuntimeException("Missing route parameter '$name'.");
			}
			$value=$parameters[$name];
			unset($parameters[$name]);
			return rawurlencode((string)$value);
		}, $path);
	}

	/**
	 * Normalizes HTTP method declarations for external callers.
	 *
	 * Empty method lists fall back to the provided default list. Non-empty lists
	 * are uppercased, trimmed, de-duplicated, and returned in first-seen order.
	 *
	 * @param array|string $methods Method or methods to normalize.
	 * @param array<int, string> $default Methods used when normalization yields no methods.
	 * @return array<int, string> Normalized method list.
	 */
	public static function normalizeMethods(array|string $methods, array $default=[]): array {
		$normalized=self::normalizeMethodList((array)$methods);
		return $normalized !== [] ? $normalized : array_values($default);
	}

	/**
	 * Normalizes a route path pattern.
	 *
	 * @param string $path Path pattern with or without surrounding slashes.
	 * @return string Leading-slash path without a trailing slash, except for root.
	 */
	public static function normalizePath(string $path): string {
		return self::normalizePathValue($path);
	}

	/**
	 * Normalizes one middleware declaration without registering a route.
	 *
	 * This static helper mirrors the instance middleware parser so documentation
	 * tools and route loaders can validate middleware shapes independently.
	 *
	 * @param mixed $definition String alias/class or associative middleware descriptor.
	 * @return array<string, mixed> Normalized middleware descriptor.
	 *
	 * @throws \RuntimeException When the declaration cannot be converted into a dispatcher descriptor.
	 */
	public static function normalizeMiddleware(mixed $definition): array {
		return (new self(['GET'], '/', static fn() => null))->normalizeMiddlewareDefinition($definition);
	}

	/**
	 * Appends middleware descriptors to this route.
	 *
	 * Variadic lists and nested list arrays are flattened one level before each
	 * declaration is normalized. Middleware order is preserved because the
	 * dispatcher applies descriptors in registration order.
	 *
	 * @param array|string ...$middleware Middleware aliases, classes, associative descriptors, or nested lists.
	 * @return self Same route builder with appended middleware.
	 *
	 * @throws \RuntimeException When any middleware declaration is invalid.
	 */
	public function middleware(array|string ...$middleware): self {
		$definitions=[];
		foreach($middleware as $definition){
			if(is_array($definition) && self::isList($definition)){
				foreach($definition as $nestedDefinition){
					$definitions[]=$nestedDefinition;
				}
				continue;
			}
			$definitions[]=$definition;
		}
		foreach($definitions as $definition){
			$this->middleware[]=$this->normalizeMiddlewareDefinition($definition);
		}
		return $this;
	}

	/**
	 * Assigns a registry name to this route.
	 *
	 * Blank names are ignored, preserving any existing name on the builder.
	 *
	 * @param string $name Route name used by named route lookup and generated docs.
	 * @return self Same route builder with name metadata applied when non-blank.
	 */
	public function name(string $name): self {
		$name=trim($name);
		if($name!==''){
			$this->name=$name;
		}
		return $this;
	}

	/**
	 * Constrains this route to a normalized domain pattern.
	 *
	 * Domain values may include schemes or paths; only the host pattern is stored.
	 * Placeholder labels such as `{tenant}` are compiled into a domain regex.
	 *
	 * @param string $domain Host, URL, or domain pattern to normalize.
	 * @return self Same route builder with domain metadata applied when non-blank.
	 */
	public function domain(string $domain): self {
		$domain=self::normalizeDomain($domain);
		if($domain!==''){
			$this->domain=$domain;
		}
		return $this;
	}

	/**
	 * Adds or replaces parameter regex constraints for this route.
	 *
	 * Constraints are stored as raw regex fragments without leading `^` or
	 * trailing `$`, because the compiled route regex owns anchoring. Blank names
	 * and blank constraints are ignored.
	 *
	 * @param array|string $parameter Parameter name or map of parameter names to regex fragments.
	 * @param ?string $pattern Regex fragment used when `$parameter` is a single name.
	 * @return self Same route builder with constraint metadata applied.
	 */
	public function where(array|string $parameter, ?string $pattern=null): self {
		$constraints=is_array($parameter) ? $parameter : [$parameter=>$pattern];
		foreach($constraints as $name=>$constraint){
			$name=trim((string)$name);
			$constraint=$this->normalizeConstraint($constraint);
			if($name!=='' && $constraint!==''){
				$this->constraints[$name]=$constraint;
			}
		}
		return $this;
	}

	/**
	 * Restricts one or more placeholders to numeric segments.
	 *
	 *
	 * @param array|string $parameters Placeholder name or names to constrain.
	 * @return self Same route builder with `[0-9]+` constraints applied.
	 */
	public function whereNumber(array|string $parameters): self {
		return $this->wherePreset($parameters, '[0-9]+');
	}

	/**
	 * Restricts one or more placeholders to alphabetic segments.
	 *
	 *
	 * @param array|string $parameters Placeholder name or names to constrain.
	 * @return self Same route builder with `[A-Za-z]+` constraints applied.
	 */
	public function whereAlpha(array|string $parameters): self {
		return $this->wherePreset($parameters, '[A-Za-z]+');
	}

	/**
	 * Restricts one or more placeholders to alphanumeric segments.
	 *
	 *
	 * @param array|string $parameters Placeholder name or names to constrain.
	 * @return self Same route builder with `[A-Za-z0-9]+` constraints applied.
	 */
	public function whereAlphaNumeric(array|string $parameters): self {
		return $this->wherePreset($parameters, '[A-Za-z0-9]+');
	}

	/**
	 * Restricts one or more placeholders to RFC 4122-style UUID values.
	 *
	 *
	 * @param array|string $parameters Placeholder name or names to constrain.
	 * @return self Same route builder with UUID constraints applied.
	 */
	public function whereUuid(array|string $parameters): self {
		return $this->wherePreset($parameters, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}');
	}

	/**
	 * Restricts a placeholder to one of a fixed set of literal values.
	 *
	 * Values are preg-quoted before joining, so this helper builds a literal
	 * allow-list rather than accepting raw regex fragments.
	 *
	 * @param string $parameter Placeholder name to constrain.
	 * @param array<int|string, mixed> $values Allowed literal values.
	 * @return self Same route builder with an alternation constraint when values are non-empty.
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
	 * Adds default parameter values to this route.
	 *
	 * Defaults are stored by trimmed parameter name and emitted unchanged during
	 * compilation for the dispatcher or URL tooling to consume.
	 *
	 * @param array|string $parameter Parameter name or map of parameter names to default values.
	 * @param mixed $value Default value used when `$parameter` is a single name.
	 * @return self Same route builder with default metadata applied.
	 */
	public function defaults(array|string $parameter, mixed $value=null): self {
		$defaults=is_array($parameter) ? $parameter : [$parameter=>$value];
		foreach($defaults as $name=>$default){
			$name=trim((string)$name);
			if($name!==''){
				$this->defaults[$name]=$default;
			}
		}
		return $this;
	}

	/**
	 * Merges arbitrary route metadata for documentation and tooling.
	 *
	 * Metadata is recursively replaced so later calls can override nested values
	 * without discarding unrelated keys already attached to the route.
	 *
	 * @param array<string, mixed> $metadata Metadata to merge into the compiled route payload.
	 * @return self Same route builder with metadata merged.
	 */
	public function metadata(array $metadata): self {
		$this->metadata=array_replace_recursive($this->metadata, $metadata);
		return $this;
	}

	/**
	 * Compiles the route into the dispatcher and documentation payload.
	 *
	 * Static paths emit `exact_path`; parameterized paths emit `path_regex` and
	 * optional `splat_parameters`. Domain patterns similarly emit either
	 * `exact_domain` or `domain_regex`. Only constraints that are actually used
	 * by path or domain placeholders are included in the compiled payload.
	 *
	 * @return array<string, mixed> Route definition consumed by routing tables and documentation surfaces.
	 *
	 * @throws \RuntimeException When the handler is not supported by the dispatcher contract.
	 */
	public function compile(): array {
		$usedConstraints=[];
		$route=[
			'methods'=>$this->methods,
			'path'=>$this->path,
			'handler'=>$this->compileHandler($this->handler),
		];
		if($this->name!==null){
			$route['name']=$this->name;
		}
		if($this->domain!==null){
			$route['domain']=$this->domain;
			if(str_contains($this->domain, '{')){
				$route['domain_regex']=$this->compileDomainRegex($this->domain, $usedConstraints);
			}else{
				$route['exact_domain']=$this->domain;
			}
		}
		if($this->middleware!==[]){
			$route['middleware']=$this->middleware;
		}
		if($this->defaults!==[]){
			$route['defaults']=$this->defaults;
		}
		if($this->metadata!==[]){
			$route['metadata']=$this->metadata;
		}
		if($this->path==='/' || !str_contains($this->path, '{')){
			$route['exact_path']=$this->path;
			if($usedConstraints!==[]){
				$route['constraints']=$usedConstraints;
			}
			return $route;
		}
		$splatParameters=[];
		$route['path_regex']=$this->compilePathRegex($this->path, $splatParameters, $usedConstraints);
		if($splatParameters!==[]){
			$route['splat_parameters']=$splatParameters;
		}
		if($usedConstraints!==[]){
			$route['constraints']=$usedConstraints;
		}
		return $route;
	}

	/**
	 * Normalizes the handler into a dispatcher-supported representation.
	 *
	 * Controller actions expose their own compiled payload, while strings,
	 * callables, and arrays are preserved for the runtime dispatcher. Other
	 * values are rejected so invalid routes fail during compilation.
	 *
	 * @param mixed $handler Handler value provided to the route factory.
	 * @return mixed compiled controller payload, callable, string handler, or array handler accepted by the dispatcher.
	 *
	 * @throws \RuntimeException When the handler cannot be dispatched.
	 */
	private function compileHandler(mixed $handler): mixed {
		if($handler instanceof ControllerAction){
			return $handler->compile();
		}
		if(is_string($handler) || is_callable($handler)){
			return $handler;
		}
		if(is_array($handler)){
			return $handler;
		}
		throw new \RuntimeException('Route handler is invalid or unsupported.');
	}

	/**
	 * Compiles a path pattern into an anchored regex with named captures.
	 *
	 * Required placeholders use the default segment constraint, optional
	 * placeholders compile as optional slash-prefixed segments, and splat
	 * placeholders capture across slashes. Used constraints and splat parameter
	 * names are reported by reference for inclusion in the compiled route.
	 *
	 * @param string $path Normalized route path pattern.
	 * @param array<int, string> $splatParameters Output list of splat placeholder names.
	 * @param array<string, string> $usedConstraints Output map of constraints referenced by placeholders.
	 * @return string Anchored path regex suitable for dispatcher matching.
	 */
	private function compilePathRegex(string $path, array &$splatParameters, array &$usedConstraints=[]): string {
		$splatParameters=[];
		$segments=[];
		foreach(explode('/', trim($path, '/')) as $segment){
			if($segment!==''){
				$segments[]=$segment;
			}
		}
		if($segments===[]){
			return '#^/$#';
		}
		$regexSegments=[];
		foreach($segments as $segment){
			if(preg_match('/^\{\.\.\.([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches)===1){
				$name=$matches[1];
				$splatParameters[]=$name;
				$constraint=$this->constraintFor($name, '.*', $usedConstraints);
				$regexSegments[]=['regex'=>'(?P<'.$name.'>'.$constraint.')', 'optional'=>false];
				continue;
			}
			if(preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches)===1){
				$name=$matches[1];
				$constraint=$this->constraintFor($name, '[^/]+', $usedConstraints);
				$regexSegments[]=['regex'=>'(?P<'.$name.'>'.$constraint.')', 'optional'=>false];
				continue;
			}
			if(preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\?\}$/', $segment, $matches)===1){
				$name=$matches[1];
				$constraint=$this->constraintFor($name, '[^/]+', $usedConstraints);
				$regexSegments[]=['regex'=>'(?P<'.$name.'>'.$constraint.')', 'optional'=>true];
				continue;
			}
			$regexSegments[]=['regex'=>preg_quote($segment, '#'), 'optional'=>false];
		}
		$regex='';
		foreach($regexSegments as $index=>$regexSegment){
			if($regexSegment['optional']){
				$regex.='(?:/'.$regexSegment['regex'].')?';
				continue;
			}
			$regex.=($index===0 ? '/' : '/').$regexSegment['regex'];
		}
		return '#^'.$regex.'$#';
	}

	/**
	 * Compiles a domain pattern into an anchored case-insensitive regex.
	 *
	 * Literal labels are escaped and `{name}` labels become named captures using
	 * explicit constraints when present or a single-label default otherwise.
	 *
	 * @param string $domain Normalized domain pattern.
	 * @param array<string, string> $usedConstraints Output map of constraints referenced by domain placeholders.
	 * @return string Anchored domain regex suitable for host matching.
	 */
	private function compileDomainRegex(string $domain, array &$usedConstraints=[]): string {
		$labels=explode('.', $domain);
		$regexLabels=[];
		foreach($labels as $label){
			if(preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $label, $matches)===1){
				$name=$matches[1];
				$constraint=$this->constraintFor($name, '[^.]+', $usedConstraints);
				$regexLabels[]='(?P<'.$name.'>'.$constraint.')';
				continue;
			}
			$regexLabels[]=preg_quote($label, '#');
		}
		return '#^'.implode('\\.', $regexLabels).'$#i';
	}

	/**
	 * Resolves the regex fragment for a named path or domain placeholder.
	 *
	 * When a custom constraint exists it is recorded in `$usedConstraints` so the
	 * compiled payload can explain which declarations affected generated regexes.
	 *
	 * @param string $name Placeholder name.
	 * @param string $default Regex fragment used when no custom constraint exists.
	 * @param array<string, string> $usedConstraints Output map of referenced custom constraints.
	 * @return string Regex fragment for the placeholder.
	 */
	private function constraintFor(string $name, string $default, array &$usedConstraints): string {
		if(!isset($this->constraints[$name])){
			return $default;
		}
		$usedConstraints[$name]=$this->constraints[$name];
		return $this->constraints[$name];
	}

	/**
	 * Applies the same regex preset to one or more placeholders.
	 *
	 * @param array|string $parameters Placeholder name or names to constrain.
	 * @param string $pattern Regex fragment to apply.
	 * @return self Same route builder with preset constraints applied.
	 */
	private function wherePreset(array|string $parameters, string $pattern): self {
		foreach((array)$parameters as $parameter){
			$this->where((string)$parameter, $pattern);
		}
		return $this;
	}

	/**
	 * Normalizes a user-supplied regex fragment for storage.
	 *
	 * Route regex compilation owns full-string anchoring, so leading `^` and
	 * trailing `$` anchors are stripped from custom fragments before storage.
	 *
	 * @param mixed $constraint Constraint candidate supplied by the route builder.
	 * @return string Regex fragment without surrounding anchors, or an empty string when unusable.
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
	 * Uppercases, trims, filters, and de-duplicates HTTP method names.
	 *
	 * @param array<int|string, mixed> $methods Candidate method names.
	 * @return array<int, string> Normalized methods in first-seen order.
	 */
	private static function normalizeMethodList(array $methods): array {
		$normalized=[];
		foreach($methods as $method){
			$method=strtoupper(trim((string)$method));
			if($method===''){
				continue;
			}
			$normalized[$method]=$method;
		}
		return array_values($normalized);
	}

	/**
	 * Converts a route path into Dataphyre's canonical path form.
	 *
	 * @param string $path Path pattern with optional leading or trailing slashes.
	 * @return string Root path or slash-prefixed path without a trailing slash.
	 */
	private static function normalizePathValue(string $path): string {
		if($path==='/' || ($path!=='' && $path[0]==='/' && !str_ends_with($path, '/'))){
			return $path;
		}
		$path='/'.trim($path, '/');
		return $path==='/' ? '/' : rtrim($path, '/');
	}

	/**
	 * Converts a host-like string into Dataphyre's canonical domain form.
	 *
	 * Schemes, paths, surrounding dots, and casing are removed while placeholder
	 * braces are preserved for domain regex compilation.
	 *
	 * @param string $domain Domain, URL, or host-like route pattern.
	 * @return string Lowercase host pattern without scheme or path.
	 */
	private static function normalizeDomainValue(string $domain): string {
		$domain=strtolower(trim($domain));
		$domain=preg_replace('#^https?://#i', '', $domain) ?? $domain;
		$domain=trim(explode('/', $domain, 2)[0] ?? '', '.');
		return $domain;
	}

	/**
	 * Normalizes one middleware declaration into dispatcher metadata.
	 *
	 * Supported shapes are string aliases, class strings, and associative arrays
	 * with class, alias/name, parameters, module/modules, or bootstrap keys.
	 *
	 * @param mixed $definition Middleware declaration supplied to the route builder.
	 * @return array<string, mixed> Normalized middleware descriptor.
	 *
	 * @throws \RuntimeException When the declaration contains no usable middleware identity.
	 */
	private function normalizeMiddlewareDefinition(mixed $definition): array {
		if(is_string($definition)){
			return $this->normalizeMiddlewareString($definition);
		}
		if(is_array($definition) && !self::isList($definition)){
			$normalized=[];
			if(isset($definition['class']) && is_string($definition['class']) && trim($definition['class'])!==''){
				$normalized['class']=trim($definition['class'], '\\');
			}
			$alias=$definition['alias'] ?? $definition['name'] ?? null;
			if(isset($normalized['class'])===false && is_string($alias) && trim($alias)!==''){
				$normalized['alias']=trim($alias);
			}
			if(isset($definition['parameters'])){
				$normalized['parameters']=$this->normalizeMiddlewareParameters($definition['parameters']);
			}
			if(isset($definition['module']) || isset($definition['modules'])){
				$modules=$definition['modules'] ?? $definition['module'];
				$normalizedModules=$this->normalizeMiddlewareModules($modules);
				if($normalizedModules!==[]){
					$normalized['modules']=$normalizedModules;
				}
			}
			if(isset($definition['bootstrap']) && is_string($definition['bootstrap']) && trim($definition['bootstrap'])!==''){
				$normalized['bootstrap']=trim($definition['bootstrap']);
			}
			if($normalized!==[]){
				return $normalized;
			}
		}
		throw new \RuntimeException('Route middleware definition is invalid or unsupported.');
	}

	/**
	 * Parses a string middleware declaration.
	 *
	 * Backslash-containing strings are treated as class names. Other strings are
	 * aliases and may include comma-separated parameters after a colon.
	 *
	 * @param string $definition Middleware alias, alias with parameters, or class name.
	 * @return array<string, mixed> Normalized middleware descriptor.
	 *
	 * @throws \RuntimeException When the string is blank.
	 */
	private function normalizeMiddlewareString(string $definition): array {
		$definition=trim($definition);
		if($definition===''){
			throw new \RuntimeException('Route middleware definition cannot be empty.');
		}
		if(str_contains($definition, '\\')){
			return ['class'=>trim($definition, '\\')];
		}
		[$alias, $parameterString]=array_pad(explode(':', $definition, 2), 2, null);
		$normalized=['alias'=>trim($alias)];
		if($parameterString!==null && trim($parameterString)!==''){
			$normalized['parameters']=$this->normalizeMiddlewareParameters(explode(',', $parameterString));
		}
		return $normalized;
	}

	/**
	 * Normalizes middleware parameter payloads into an ordered list.
	 *
	 * @param mixed $parameters Single parameter or parameter list.
	 * @return array<int, mixed> Parameters in dispatcher order.
	 */
	private function normalizeMiddlewareParameters(mixed $parameters): array {
		if(!is_array($parameters)){
			$parameters=[$parameters];
		}
		$normalized=[];
		foreach($parameters as $parameter){
			$normalized[]=$parameter;
		}
		return $normalized;
	}

	/**
	 * Normalizes middleware module guards into lowercase module names.
	 *
	 * @param mixed $modules Single module or module list.
	 * @return array<int, string> Lowercase non-empty module names in first-seen order.
	 */
	private function normalizeMiddlewareModules(mixed $modules): array {
		if(!is_array($modules)){
			$modules=[$modules];
		}
		$normalized=[];
		foreach($modules as $module){
			$module=strtolower(trim((string)$module));
			if($module===''){
				continue;
			}
			$normalized[$module]=$module;
		}
		return array_values($normalized);
	}

	/**
	 * Detects whether an array uses contiguous numeric list keys.
	 *
	 * @param array<mixed> $value Array to inspect.
	 * @return bool True when the array is a zero-based list.
	 */
	private static function isList(array $value): bool {
		return array_keys($value)===range(0, count($value)-1);
	}
}
