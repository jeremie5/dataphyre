<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

use Dataphyre\Routing\CompilableRoute;
use Dataphyre\Routing\Route;

/**
 * Fluent API endpoint definition that compiles into a Dataphyre route plus OpenAPI-style metadata.
 *
 * Endpoint keeps the authoring DSL separate from compiled route metadata. Callers describe HTTP methods, path templates,
 * handlers, middleware, request/response schemas, security requirements, execution targets, data bindings, lifecycle hooks,
 * cache/profile/trace options, and dispatch defaults; compile() then normalizes those pieces into static arrays the router,
 * API dispatcher, route caches, and diagnostics can consume without executing user code.
 */
final class Endpoint implements CompilableRoute {

	private array $methods;
	private string $path;
	private mixed $handler;
	private array $middleware=[];
	private array $tags=[];
	private array $aliases=[];
	private ?string $summary=null;
	private ?string $description=null;
	private ?string $operationId=null;
	private bool $deprecated=false;
	private array $parameters=[];
	private ?array $requestBody=null;
	private array $responses=[];
	private array $securitySchemes=[];
	private array $security=[];
	private array $servers=[];
	private ?array $execution=null;
	private ?array $schemaDefinition=null;
	private ?array $traceDefinition=null;
	private ?array $cacheDefinition=null;
	private ?array $profileDefinition=null;
	private ?array $dispatchDefinition=null;
	private array $bindings=[];
	private array $lifecycle=[
		'before'=>[],
		'after'=>[],
		'error'=>[],
	];
	private static ?array $lastMethodListInput=null;
	private static ?array $lastMethodListOutput=null;

	/**
	 * Seeds the endpoint with normalized HTTP methods, route path, and optional route handler.
	 *
	 * Methods are uppercased and deduplicated immediately so every later compile step sees the same contract. Paths are
	 * canonicalized to leading-slash route templates and handlers are preserved as supplied for Route::methods().
	 *
	 * @param array<int, mixed> $methods HTTP methods or the ANY sentinel accepted by the route compiler.
	 * @param string $path Route path template.
	 * @param mixed $handler Route handler used when no API execution target is configured.
	 */
	private function __construct(array $methods, string $path, mixed $handler=null){
		$this->methods=self::normalizeMethods($methods);
		$this->path=self::normalizePath($path);
		$this->handler=$handler;
	}

	/**
	 * Normalizes HTTP method names while preserving first occurrence order.
	 *
	 * @param array<int, mixed> $methods HTTP method values.
	 * @return array<int, string> Uppercase unique method names.
	 */
	private static function normalizeMethods(array $methods): array {
		if(self::$lastMethodListInput===$methods && self::$lastMethodListOutput!==null){
			return self::$lastMethodListOutput;
		}
		$normalized=[];
		$seen=[];
		foreach($methods as $method){
			$method=strtoupper(trim((string)$method));
			if(isset($seen[$method])){
				continue;
			}
			$seen[$method]=true;
			$normalized[]=$method;
		}
		self::$lastMethodListInput=$methods;
		return self::$lastMethodListOutput=$normalized;
	}

	/**
	 * Creates an endpoint for one or more HTTP methods.
	 *
	 * @param array<int, string>|string $methods HTTP methods or ANY.
	 * @param string $path Route path template.
	 * @param mixed $handler Optional direct route handler.
	 * @return self New endpoint definition.
	 */
	public static function methods(array|string $methods, string $path, mixed $handler=null): self {
		return new self((array)$methods, $path, $handler);
	}

	/**
	 * Creates a GET endpoint.
	 *
	 * @param string $path Route path template.
	 * @param mixed $handler Optional direct route handler.
	 * @return self New endpoint definition.
	 */
	public static function get(string $path, mixed $handler=null): self {
		return new self(['GET'], $path, $handler);
	}

	/**
	 * Creates a POST endpoint.
	 *
	 * @param string $path Route path template.
	 * @param mixed $handler Optional direct route handler.
	 * @return self New endpoint definition.
	 */
	public static function post(string $path, mixed $handler=null): self {
		return new self(['POST'], $path, $handler);
	}

	/**
	 * Creates a PUT endpoint.
	 *
	 * @param string $path Route path template.
	 * @param mixed $handler Optional direct route handler.
	 * @return self New endpoint definition.
	 */
	public static function put(string $path, mixed $handler=null): self {
		return new self(['PUT'], $path, $handler);
	}

	/**
	 * Creates a PATCH endpoint.
	 *
	 * @param string $path Route path template.
	 * @param mixed $handler Optional direct route handler.
	 * @return self New endpoint definition.
	 */
	public static function patch(string $path, mixed $handler=null): self {
		return new self(['PATCH'], $path, $handler);
	}

	/**
	 * Creates a DELETE endpoint.
	 *
	 * @param string $path Route path template.
	 * @param mixed $handler Optional direct route handler.
	 * @return self New endpoint definition.
	 */
	public static function delete(string $path, mixed $handler=null): self {
		return new self(['DELETE'], $path, $handler);
	}

	/**
	 * Creates an endpoint that accepts the router's ANY method sentinel.
	 *
	 * @param string $path Route path template.
	 * @param mixed $handler Optional direct route handler.
	 * @return self New endpoint definition.
	 */
	public static function any(string $path, mixed $handler=null): self {
		return new self(['ANY'], $path, $handler);
	}

	/**
	 * Appends middleware definitions that will be forwarded to the compiled route.
	 *
	 * Middleware is intentionally stored verbatim because the routing layer owns middleware resolution and validation.
	 *
	 * @param array|string ...$middleware Route middleware definitions in router-native form.
	 * @return self This endpoint after appending middleware.
	 */
	public function middleware(array|string ...$middleware): self {
		foreach($middleware as $definition){
			$this->middleware[]=$definition;
		}
		return $this;
	}

	/**
	 * Adds OpenAPI grouping tags for discovery surfaces.
	 *
	 * Nested arrays are flattened recursively, empty tags are ignored, and duplicate tags keep their first position.
	 *
	 * @param array|string ...$tags Tag names or nested tag lists.
	 * @return self This endpoint after adding unique tags.
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
	 * Registers one non-empty lookup alias for this endpoint.
	 *
	 * Aliases are trimmed of slashes so route-like names and plain symbolic names resolve to the same metadata key.
	 *
	 * @param string $alias Human or tool-facing endpoint alias.
	 * @return self This endpoint after registering the alias.
	 * @throws \RuntimeException When the alias normalizes to an empty string.
	 */
	public function alias(string $alias): self {
		$alias=self::normalizeAlias($alias);
		if($alias===''){
			throw new \RuntimeException('API endpoint alias cannot be empty.');
		}
		$this->aliases[$alias]=$alias;
		return $this;
	}

	/**
	 * Registers multiple endpoint aliases while ignoring empty entries.
	 *
	 * @param array|string ...$aliases Alias names or nested alias lists.
	 * @return self This endpoint after registering unique aliases.
	 */
	public function aliases(array|string ...$aliases): self {
		foreach($aliases as $alias){
			if(is_array($alias)){
				$this->aliases(...$alias);
				continue;
			}
			$normalized=self::normalizeAlias((string)$alias);
			if($normalized===''){
				continue;
			}
			$this->aliases[$normalized]=$normalized;
		}
		return $this;
	}

	/**
	 * Sets the short human-readable operation summary.
	 *
	 * @param string $summary One-line operation summary.
	 * @return self This endpoint after updating operation metadata.
	 */
	public function summary(string $summary): self {
		$this->summary=trim($summary);
		return $this;
	}

	/**
	 * Sets the longer operation description exported with API metadata.
	 *
	 * @param string $description Markdown-capable description text.
	 * @return self This endpoint after updating operation metadata.
	 */
	public function description(string $description): self {
		$this->description=trim($description);
		return $this;
	}

	/**
	 * Sets the stable operation id used by generated clients and API indexes.
	 *
	 * @param string $operationId Unique operation identifier.
	 * @return self This endpoint after updating operation metadata.
	 */
	public function operationId(string $operationId): self {
		$this->operationId=trim($operationId);
		return $this;
	}

	/**
	 * Marks whether clients and API indexes should treat the operation as deprecated.
	 *
	 * @param bool $deprecated Deprecation flag to compile into API metadata.
	 * @return self This endpoint after updating lifecycle metadata.
	 */
	public function deprecated(bool $deprecated=true): self {
		$this->deprecated=$deprecated;
		return $this;
	}

	/**
	 * Adds a normalized OpenAPI parameter definition.
	 *
	 * Path parameters are always required. Other locations may provide required, description, examples, style, explode,
	 * deprecated, and allowEmptyValue options; unsupported options are deliberately ignored to keep compile output stable.
	 *
	 * @param string $name Parameter name.
	 * @param string $in Parameter location such as path, query, header, or cookie.
	 * @param array<string, mixed> $schema OpenAPI schema fragment, defaulting to string when omitted.
	 * @param array<string, mixed> $options Supported OpenAPI parameter options.
	 * @return self This endpoint after appending the parameter.
	 */
	public function parameter(string $name, string $in, array $schema=[], array $options=[]): self {
		$this->parameters[]=$this->normalizeParameter($name, $in, $schema, $options);
		return $this;
	}

	/**
	 * Adds a required path parameter definition.
	 *
	 * @param string $name Path template variable name.
	 * @param array<string, mixed> $schema OpenAPI schema fragment.
	 * @param array<string, mixed> $options Supported parameter options.
	 * @return self This endpoint after appending the path parameter.
	 */
	public function pathParameter(string $name, array $schema=[], array $options=[]): self {
		return $this->parameter($name, 'path', $schema, $options);
	}

	/**
	 * Adds a query-string parameter definition.
	 *
	 * @param string $name Query parameter name.
	 * @param array<string, mixed> $schema OpenAPI schema fragment.
	 * @param array<string, mixed> $options Supported parameter options.
	 * @return self This endpoint after appending the query parameter.
	 */
	public function queryParameter(string $name, array $schema=[], array $options=[]): self {
		return $this->parameter($name, 'query', $schema, $options);
	}

	/**
	 * Adds an HTTP header parameter definition.
	 *
	 * @param string $name Header name.
	 * @param array<string, mixed> $schema OpenAPI schema fragment.
	 * @param array<string, mixed> $options Supported parameter options.
	 * @return self This endpoint after appending the header parameter.
	 */
	public function headerParameter(string $name, array $schema=[], array $options=[]): self {
		return $this->parameter($name, 'header', $schema, $options);
	}

	/**
	 * Adds a cookie parameter definition.
	 *
	 * @param string $name Cookie name.
	 * @param array<string, mixed> $schema OpenAPI schema fragment.
	 * @param array<string, mixed> $options Supported parameter options.
	 * @return self This endpoint after appending the cookie parameter.
	 */
	public function cookieParameter(string $name, array $schema=[], array $options=[]): self {
		return $this->parameter($name, 'cookie', $schema, $options);
	}

	/**
	 * Defines the request body metadata for clients and validators.
	 *
	 * The content map is stored as supplied so callers can describe any media type. Empty descriptions are omitted while
	 * the required flag is retained for explicitness.
	 *
	 * @param array<string, mixed> $content OpenAPI content map keyed by media type.
	 * @param bool $required Whether the operation requires a body.
	 * @param ?string $description Optional request body description.
	 * @return self This endpoint after replacing request body metadata.
	 */
	public function requestBody(array $content, bool $required=false, ?string $description=null): self {
		$this->requestBody=array_filter([
			'required'=>$required,
			'description'=>$description!==null && trim($description)!=='' ? trim($description) : null,
			'content'=>$content,
		], static fn(mixed $value): bool => $value!==null);
		return $this;
	}

	/**
	 * Defines an application/json request body from a schema fragment.
	 *
	 * @param array<string, mixed> $schema JSON body schema.
	 * @param bool $required Whether the JSON body is required.
	 * @param ?string $description Optional request body description.
	 * @return self This endpoint after replacing request body metadata.
	 */
	public function jsonBody(array $schema, bool $required=false, ?string $description=null): self {
		return $this->requestBody([
			'application/json'=>[
				'schema'=>$schema,
			],
		], $required, $description);
	}

	/**
	 * Registers a response definition for one HTTP status code or OpenAPI response key.
	 *
	 * @param int|string $status HTTP status code or response key such as default.
	 * @param array<string, mixed> $definition OpenAPI response definition.
	 * @return self This endpoint after replacing the response for the given status.
	 */
	public function response(int|string $status, array $definition): self {
		$this->responses[(string)$status]=$definition;
		return $this;
	}

	/**
	 * Registers an application/json response using a schema fragment.
	 *
	 * @param int|string $status HTTP status code or response key.
	 * @param array<string, mixed> $schema JSON response schema.
	 * @param string $description Response description.
	 * @return self This endpoint after replacing the response for the given status.
	 */
	public function jsonResponse(int|string $status, array $schema, string $description='OK'): self {
		return $this->response($status, [
			'description'=>$description,
			'content'=>[
				'application/json'=>[
					'schema'=>$schema,
				],
			],
		]);
	}

	/**
	 * Adds alternative security requirements for the endpoint.
	 *
	 * Each scheme is compiled into the shared security scheme map and then appended as its own requirement, matching
	 * OpenAPI OR semantics.
	 *
	 * @param SecurityScheme ...$schemes Security schemes that can independently authorize the operation.
	 * @return self This endpoint after appending security metadata.
	 */
	public function auth(SecurityScheme ...$schemes): self {
		foreach($schemes as $scheme){
			$compiled=$scheme->toArray();
			$this->securitySchemes[$compiled['name']]=$compiled;
			$this->security[]=[
				$compiled['name']=>$compiled['scopes'] ?? [],
			];
		}
		return $this;
	}

	/**
	 * Adds one combined security requirement that requires every supplied scheme.
	 *
	 * The compiled requirement uses OpenAPI AND semantics by placing all schemes in a single requirement object.
	 *
	 * @param SecurityScheme ...$schemes Security schemes that must all authorize the operation.
	 * @return self This endpoint after appending security metadata.
	 */
	public function authAll(SecurityScheme ...$schemes): self {
		$requirement=[];
		foreach($schemes as $scheme){
			$compiled=$scheme->toArray();
			$this->securitySchemes[$compiled['name']]=$compiled;
			$requirement[$compiled['name']]=$compiled['scopes'] ?? [];
		}
		if($requirement!==[]){
			$this->security[]=$requirement;
		}
		return $this;
	}

	/**
	 * Adds an operation-specific server override.
	 *
	 * @param string $url Server URL or OpenAPI server template.
	 * @param ?string $description Optional server description.
	 * @return self This endpoint after appending server metadata.
	 */
	public function server(string $url, ?string $description=null): self {
		$server=['url'=>trim($url)];
		if($description!==null && trim($description)!==''){
			$server['description']=trim($description);
		}
		$this->servers[]=$server;
		return $this;
	}

	/**
	 * Defines the API dispatcher target that should execute when this route is matched.
	 *
	 * A configured execution target changes the compiled route handler to the API dispatcher sentinel and moves callable
	 * resolution into API metadata. Without this, the original route handler is used directly.
	 *
	 * @param mixed $target Callable reference, Class::method string, [class, method] tuple, or compiled callable definition.
	 * @param array<string, mixed> $options Static execution options such as bootstrap.
	 * @return self This endpoint after replacing the execution definition.
	 * @throws \RuntimeException When the target cannot be normalized.
	 */
	public function execute(mixed $target, array $options=[]): self {
		$this->execution=$this->normalizeExecutionTarget($target, $options);
		return $this;
	}

	/**
	 * Adds a callable data binding that can populate a path inside API response data.
	 *
	 * Bindings are compiled as static definitions; runtime dispatchers decide when to invoke them and where to write their
	 * output. The binding path must be explicit so response composition remains inspectable in compiled metadata.
	 *
	 * @param string $path Response data path receiving the binding output.
	 * @param mixed $target Callable reference accepted by normalizeExecutionTarget().
	 * @param array<string, mixed> $options Static callable binding options, optionally including identity.
	 * @return self This endpoint after appending the callable binding.
	 * @throws \RuntimeException When the binding path or target is invalid.
	 */
	public function withBinding(string $path, mixed $target, array $options=[]): self {
		$path=trim($path);
		if($path===''){
			throw new \RuntimeException('API binding path cannot be empty.');
		}
		$this->bindings[]=[
			'path'=>$path,
			'definition'=>$this->normalizeCallableBindingDefinition($path, $target, $options),
		];
		return $this;
	}

	/**
	 * Adds multiple callable bindings from an associative path map.
	 *
	 * Entries may be direct targets or arrays containing a target key plus options. Non-string or empty paths are skipped
	 * so generated binding maps can be filtered without pre-cleaning.
	 *
	 * @param array<string, mixed> $bindings Binding definitions keyed by response data path.
	 * @return self This endpoint after appending valid bindings.
	 */
	public function withBindings(array $bindings): self {
		foreach($bindings as $path=>$binding){
			if(is_string($path)===false || trim($path)===''){
				continue;
			}
			if(is_array($binding) && array_key_exists('target', $binding)){
				$options=$binding;
				$target=$options['target'];
				unset($options['target']);
				$this->withBinding($path, $target, $options);
				continue;
			}
			$this->withBinding($path, $binding);
		}
		return $this;
	}

	/**
	 * Adds a SQL query binding that serializes a query object's execution state.
	 *
	 * The query object is not executed during compilation. It must expose executionState() so the dispatcher can recreate
	 * the query later from static execution state.
	 *
	 * @param string $path Response data path receiving query output.
	 * @param object $query Query object exposing executionState().
	 * @param string $mode Dispatcher mode such as records.
	 * @param array<string, mixed> $options Static query binding options.
	 * @return self This endpoint after appending the SQL query binding.
	 * @throws \RuntimeException When the path is empty or the query cannot expose execution state.
	 */
	public function withQuery(string $path, object $query, string $mode='records', array $options=[]): self {
		$path=trim($path);
		if($path===''){
			throw new \RuntimeException('API binding path cannot be empty.');
		}
		$this->bindings[]=[
			'path'=>$path,
			'definition'=>$this->normalizeQueryBindingDefinition($path, $query, $mode, $options, false),
		];
		return $this;
	}

	/**
	 * Adds a SQL query binding that inherits identity from the parent endpoint query context.
	 *
	 * @param string $path Response data path receiving query output.
	 * @param object $query Query object exposing executionState().
	 * @param string $mode Dispatcher mode such as records.
	 * @param array<string, mixed> $options Static query binding options.
	 * @return self This endpoint after appending the identity-aware SQL query binding.
	 */
	public function withQueryIdentity(string $path, object $query, string $mode='records', array $options=[]): self {
		$path=trim($path);
		if($path===''){
			throw new \RuntimeException('API binding path cannot be empty.');
		}
		$this->bindings[]=[
			'path'=>$path,
			'definition'=>$this->normalizeQueryBindingDefinition($path, $query, $mode, $options, true),
		];
		return $this;
	}

	/**
	 * Adds a search query binding that serializes a search object's execution state.
	 *
	 * @param string $path Response data path receiving search output.
	 * @param object $query Search query object exposing executionState().
	 * @param string $mode Dispatcher mode such as results.
	 * @param array<string, mixed> $options Static search binding options.
	 * @return self This endpoint after appending the search binding.
	 * @throws \RuntimeException When the path is empty or the query cannot expose execution state.
	 */
	public function withSearch(string $path, object $query, string $mode='results', array $options=[]): self {
		$path=trim($path);
		if($path===''){
			throw new \RuntimeException('API binding path cannot be empty.');
		}
		$this->bindings[]=[
			'path'=>$path,
			'definition'=>$this->normalizeSearchBindingDefinition($path, $query, $mode, $options, false),
		];
		return $this;
	}

	/**
	 * Adds a search binding that inherits identity from the parent endpoint query context.
	 *
	 * @param string $path Response data path receiving search output.
	 * @param object $query Search query object exposing executionState().
	 * @param string $mode Dispatcher mode such as results.
	 * @param array<string, mixed> $options Static search binding options.
	 * @return self This endpoint after appending the identity-aware search binding.
	 */
	public function withSearchIdentity(string $path, object $query, string $mode='results', array $options=[]): self {
		$path=trim($path);
		if($path===''){
			throw new \RuntimeException('API binding path cannot be empty.');
		}
		$this->bindings[]=[
			'path'=>$path,
			'definition'=>$this->normalizeSearchBindingDefinition($path, $query, $mode, $options, true),
		];
		return $this;
	}

	/**
	 * Attaches request validation schema metadata to the endpoint.
	 *
	 * The schema data is stored separately from OpenAPI requestBody metadata because it is consumed by Dataphyre's
	 * runtime validation pipeline rather than by generated clients only.
	 *
	 * @param array<string, mixed> $schema Validation rules keyed by input field.
	 * @param array<string, mixed> $defaults Default values applied by the validator.
	 * @param array<string, mixed> $options Static validator options.
	 * @return self This endpoint after replacing schema metadata.
	 */
	public function schema(array $schema, array $defaults=[], array $options=[]): self {
		$this->schemaDefinition=[
			'rules'=>$schema,
			'defaults'=>$defaults,
			'options'=>$options,
		];
		return $this;
	}

	/**
	 * Attaches trace collection preferences for dispatch diagnostics.
	 *
	 * @param bool $enabled Whether tracing should be enabled for this endpoint.
	 * @param array<string, mixed> $options Static trace options.
	 * @return self This endpoint after replacing trace metadata.
	 */
	public function withTrace(bool $enabled=true, array $options=[]): self {
		$options['enabled']=$enabled;
		$this->traceDefinition=$options;
		return $this;
	}

	/**
	 * Configures response cache metadata for the API dispatcher.
	 *
	 * TTL values are coerced to at least one second. Options must be static scalar/null/array values so compiled endpoint
	 * metadata can be safely cached and exported without serializing objects or closures.
	 *
	 * @param int|float|string $ttl Cache lifetime in seconds.
	 * @param array<string, mixed> $options Static cache options, optionally including names.
	 * @return self This endpoint after replacing cache metadata.
	 * @throws \RuntimeException When cache options contain non-static values.
	 */
	public function cache(int|float|string $ttl=300, array $options=[]): self {
		$ttl=max(1, (int)$ttl);
		$normalized=$this->normalizeStaticOptions($options, 'API cache options must be composed of scalar, null, or array values.');
		$names=$normalized['names'] ?? [];
		unset($normalized['names']);
		$this->cacheDefinition=array_replace([
			'ttl'=>$ttl,
			'names'=>$this->normalizeCacheNames($names),
		], $normalized);
		return $this;
	}

	/**
	 * Assigns a dispatch profile used to tune endpoint execution behavior.
	 *
	 * @param string $name Non-empty profile name.
	 * @param array<string, mixed> $options Static profile options.
	 * @return self This endpoint after replacing profile metadata.
	 * @throws \RuntimeException When the profile name is empty or options contain non-static values.
	 */
	public function profile(string $name, array $options=[]): self {
		$name=trim($name);
		if($name===''){
			throw new \RuntimeException('API profile name cannot be empty.');
		}
		$this->profileDefinition=array_replace([
			'name'=>$name,
		], $this->normalizeStaticOptions($options, 'API profile options must be composed of scalar, null, or array values.'));
		return $this;
	}

	/**
	 * Merges static dispatcher defaults into the endpoint metadata.
	 *
	 * Repeated calls layer later options over earlier options, allowing modules to set broad defaults before endpoint-level
	 * code applies more specific values.
	 *
	 * @param array<string, mixed> $options Static dispatch defaults.
	 * @return self This endpoint after merging dispatch metadata.
	 * @throws \RuntimeException When options contain non-static values.
	 */
	public function dispatchDefaults(array $options): self {
		$this->dispatchDefinition=array_replace(
			$this->dispatchDefinition ?? [],
			$this->normalizeStaticOptions($options, 'API dispatch defaults must be composed of scalar, null, or array values.')
		);
		return $this;
	}

	/**
	 * Adds a lifecycle hook that runs before the main execution target.
	 *
	 * @param mixed $target Hook callable reference accepted by normalizeExecutionTarget().
	 * @param array<string, mixed> $options Static hook options.
	 * @return self This endpoint after appending the before hook.
	 */
	public function beforeExecute(mixed $target, array $options=[]): self {
		$this->lifecycle['before'][]=$this->normalizeExecutionTarget($target, $options);
		return $this;
	}

	/**
	 * Adds a lifecycle hook that runs after successful main execution.
	 *
	 * @param mixed $target Hook callable reference accepted by normalizeExecutionTarget().
	 * @param array<string, mixed> $options Static hook options.
	 * @return self This endpoint after appending the after hook.
	 */
	public function afterExecute(mixed $target, array $options=[]): self {
		$this->lifecycle['after'][]=$this->normalizeExecutionTarget($target, $options);
		return $this;
	}

	/**
	 * Adds a lifecycle hook that runs when endpoint execution fails.
	 *
	 * @param mixed $target Hook callable reference accepted by normalizeExecutionTarget().
	 * @param array<string, mixed> $options Static hook options.
	 * @return self This endpoint after appending the error hook.
	 */
	public function onError(mixed $target, array $options=[]): self {
		$this->lifecycle['error'][]=$this->normalizeExecutionTarget($target, $options);
		return $this;
	}

	/**
	 * Compiles the endpoint into route metadata consumed by routing and API dispatch.
	 *
	 * The compiled route contains the normal routing metadata plus an api key with endpoint-specific metadata. Path
	 * parameters missing from explicit metadata are inferred from the route template so compiled API descriptions stay complete.
	 *
	 * @return array<string, mixed> Router compile data with API metadata attached.
	 * @throws \RuntimeException When neither a direct route handler nor an API execution target is available.
	 */
	public function compile(): array {
		$handler=$this->compileRouteHandler();
		$route=Route::methods($this->methods, $this->path, $handler);
		if($this->middleware!==[]){
			$route->middleware(...$this->middleware);
		}
		$compiled=$route->compile();
		$compiled['path_template']=$this->path;
		$compiled['api']=$this->compileApiMetadata();
		return $compiled;
	}

	/**
	 * Builds the endpoint metadata block attached under the compiled route's api key.
	 *
	 * Empty optional sections are removed so downstream manifests stay compact, while explicit false values such as
	 * deprecated=false remain available when they carry meaning for client generators.
	 *
	 * @return array<string, mixed> Normalized API metadata ready for serialization.
	 */
	private function compileApiMetadata(): array {
		$parameters=$this->mergeInferredPathParameters($this->parameters);
		$responses=$this->responses!==[]
			? $this->responses
			: ['200'=>['description'=>'OK']];
		return array_filter([
			'path'=>$this->path,
			'methods'=>$this->methods,
			'tags'=>array_values($this->tags),
			'aliases'=>array_values($this->aliases),
			'summary'=>$this->summary,
			'description'=>$this->description,
			'operation_id'=>$this->operationId,
			'deprecated'=>$this->deprecated,
			'parameters'=>$parameters,
			'request_body'=>$this->requestBody,
			'responses'=>$responses,
			'security_schemes'=>$this->securitySchemes,
			'security'=>$this->security,
			'servers'=>$this->servers,
			'execution'=>$this->execution,
			'bindings'=>$this->compileBindings(),
			'lifecycle'=>$this->compileLifecycle(),
			'schema'=>$this->schemaDefinition,
			'trace'=>$this->traceDefinition,
			'cache'=>$this->cacheDefinition,
			'profile'=>$this->profileDefinition,
			'dispatch'=>$this->dispatchDefinition,
		], static fn(mixed $value): bool => $value!==null && $value!==[] && $value!=='');
	}

	/**
	 * Selects the route handler that should be visible to the router.
	 *
	 * Endpoints with execution metadata use the lightweight API dispatcher sentinel; otherwise the direct route handler is
	 * preserved. This keeps API execution routes statically inspectable without forcing the router to understand targets.
	 *
	 * @return mixed Route handler accepted by Route::methods().
	 * @throws \RuntimeException When no executable route target has been configured.
	 */
	private function compileRouteHandler(): mixed {
		if($this->execution!==null){
			return ['type'=>'api'];
		}
		if($this->handler!==null){
			return $this->handler;
		}
		throw new \RuntimeException('API endpoint requires either a route handler or an execute target.');
	}

	/**
	 * Adds missing OpenAPI path parameters discovered from the route template.
	 *
	 * Explicitly documented path parameters win. Inferred parameters use a string schema and required=true, matching
	 * OpenAPI's path-parameter invariant.
	 *
	 * @param array<int, array<string, mixed>> $parameters Existing normalized parameter definitions.
	 * @return array<int, array<string, mixed>> Parameter definitions with inferred path variables appended.
	 */
	private function mergeInferredPathParameters(array $parameters): array {
		$documented=[];
		foreach($parameters as $parameter){
			if(($parameter['in'] ?? null)!=='path'){
				continue;
			}
			$documented[(string)($parameter['name'] ?? '')]=true;
		}
		if(preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $this->path, $matches)!==1){
			return $parameters;
		}
		foreach($matches[1] as $name){
			if(isset($documented[$name])){
				continue;
			}
			$parameters[]=$this->normalizeParameter($name, 'path', ['type'=>'string'], ['required'=>true]);
		}
		return $parameters;
	}

	/**
	 * Converts loose parameter inputs into the supported OpenAPI parameter subset.
	 *
	 * @param string $name Parameter name.
	 * @param string $in Parameter location.
	 * @param array<string, mixed> $schema Schema fragment or empty array for string.
	 * @param array<string, mixed> $options Supported parameter option map.
	 * @return array<string, mixed> Normalized parameter definition.
	 */
	private function normalizeParameter(string $name, string $in, array $schema, array $options): array {
		$parameter=[
			'name'=>trim($name),
			'in'=>strtolower(trim($in)),
			'schema'=>$schema!==[] ? $schema : ['type'=>'string'],
		];
		if($parameter['in']==='path'){
			$parameter['required']=true;
		}elseif(array_key_exists('required', $options)){
			$parameter['required']=$options['required']===true;
		}
		if(isset($options['description']) && trim((string)$options['description'])!==''){
			$parameter['description']=trim((string)$options['description']);
		}
		foreach(['deprecated', 'allowEmptyValue', 'explode'] as $key){
			if(array_key_exists($key, $options)){
				$parameter[$key]=$options[$key]===true;
			}
		}
		if(isset($options['example'])){
			$parameter['example']=$options['example'];
		}
		if(isset($options['examples']) && is_array($options['examples'])){
			$parameter['examples']=$options['examples'];
		}
		if(isset($options['style']) && trim((string)$options['style'])!==''){
			$parameter['style']=trim((string)$options['style']);
		}
		return $parameter;
	}

	/**
	 * Converts callable target forms into a static dispatcher definition.
	 *
	 * Accepted targets are callable reference strings, Class::method strings, [class, method] tuples, or arrays with
	 * class/method/reference keys. Bootstrap can be supplied through options or the target array and is emitted only when
	 * non-empty.
	 *
	 * @param mixed $target Runtime callable target description.
	 * @param array<string, mixed> $options Static execution options.
	 * @return array<string, mixed> Dispatcher target definition.
	 * @throws \RuntimeException When the target cannot be represented statically.
	 */
	private function normalizeExecutionTarget(mixed $target, array $options): array {
		$normalized=[
			'bootstrap'=>isset($options['bootstrap']) && is_string($options['bootstrap']) && trim($options['bootstrap'])!==''
				? trim($options['bootstrap'])
				: null,
		];
		if(is_string($target)){
			$target=trim($target);
			if($target===''){
				throw new \RuntimeException('API execute target cannot be empty.');
			}
			if(str_contains($target, '::')){
				[$class, $method]=array_pad(explode('::', $target, 2), 2, null);
				$class=trim((string)$class, '\\');
				$method=trim((string)$method);
				if($class==='' || $method===''){
					throw new \RuntimeException('API execute target must use a valid Class::method reference.');
				}
				return array_filter($normalized+[
					'type'=>'class_method',
					'class'=>$class,
					'method'=>$method,
					'static'=>true,
				], static fn(mixed $value): bool => $value!==null);
			}
			return array_filter($normalized+[
				'type'=>'callable',
				'reference'=>$target,
			], static fn(mixed $value): bool => $value!==null);
		}
		if(
			is_array($target)
			&& array_keys($target)===range(0, count($target)-1)
			&& count($target)===2
			&& is_string($target[0])
			&& is_string($target[1])
		){
			$class=trim($target[0], '\\');
			$method=trim($target[1]);
			if($class==='' || $method===''){
				throw new \RuntimeException('API execute target must use a valid [Class, method] reference.');
			}
			return array_filter($normalized+[
				'type'=>'class_method',
				'class'=>$class,
				'method'=>$method,
				'static'=>true,
			], static fn(mixed $value): bool => $value!==null);
		}
		if(is_array($target)){
			$class=trim((string)($target['class'] ?? ''), '\\');
			$method=trim((string)($target['method'] ?? ''));
			$reference=isset($target['reference']) ? trim((string)$target['reference']) : '';
			$bootstrap=$target['bootstrap'] ?? ($normalized['bootstrap'] ?? null);
			$normalized['bootstrap']=is_string($bootstrap) && trim($bootstrap)!==''
				? trim($bootstrap)
				: null;
			if($class!=='' && $method!==''){
				return array_filter($normalized+[
					'type'=>'class_method',
					'class'=>$class,
					'method'=>$method,
					'static'=>($target['static'] ?? true)===true,
				], static fn(mixed $value): bool => $value!==null);
			}
			if($reference!==''){
				return array_filter($normalized+[
					'type'=>'callable',
					'reference'=>$reference,
				], static fn(mixed $value): bool => $value!==null);
			}
		}
		throw new \RuntimeException('API execute target must be a callable string, a Class::method reference, or a compiled callable definition.');
	}

	/**
	 * Builds a callable binding definition for response composition.
	 *
	 * @param string $path Binding path, used only for error context by callers.
	 * @param mixed $target Callable target description.
	 * @param array<string, mixed> $options Static binding options.
	 * @return array<string, mixed> Callable binding definition.
	 */
	private function normalizeCallableBindingDefinition(string $path, mixed $target, array $options): array {
		$normalized=[
			'type'=>'callable',
			'target'=>$this->normalizeExecutionTarget($target, $options),
		];
		$identity=$options['identity'] ?? null;
		if($identity!==null){
			$normalized['identity']=$this->normalizeBindingValue($identity, true);
		}
		return $normalized;
	}

	/**
	 * Builds a SQL query binding definition from a query object's serialized execution state.
	 *
	 * @param string $path Binding path used in validation errors.
	 * @param object $query Query object exposing executionState().
	 * @param string $mode Dispatcher output mode.
	 * @param array<string, mixed> $options Static binding options.
	 * @param bool $inheritIdentity Whether dispatcher identity should be inherited from the parent query context.
	 * @return array<string, mixed> SQL query binding definition.
	 * @throws \RuntimeException When the query object cannot expose execution state.
	 */
	private function normalizeQueryBindingDefinition(string $path, object $query, string $mode, array $options, bool $inheritIdentity): array {
		if(!method_exists($query, 'executionState')){
			throw new \RuntimeException("API SQL binding '{$path}' requires a query object with executionState().");
		}
		return [
			'type'=>'sql_query',
			'mode'=>trim($mode)!=='' ? trim($mode) : 'records',
			'query_class'=>$query::class,
			'query_state'=>$this->normalizeBindingValue($query->executionState()),
			'inherit_query_identity'=>$inheritIdentity,
			'options'=>$this->normalizeBindingValue($options),
		];
	}

	/**
	 * Builds a search query binding definition from a query object's serialized execution state.
	 *
	 * @param string $path Binding path used in validation errors.
	 * @param object $query Search query object exposing executionState().
	 * @param string $mode Dispatcher output mode.
	 * @param array<string, mixed> $options Static binding options.
	 * @param bool $inheritIdentity Whether dispatcher identity should be inherited from the parent query context.
	 * @return array<string, mixed> Search binding definition.
	 * @throws \RuntimeException When the query object cannot expose execution state.
	 */
	private function normalizeSearchBindingDefinition(string $path, object $query, string $mode, array $options, bool $inheritIdentity): array {
		if(!method_exists($query, 'executionState')){
			throw new \RuntimeException("API search binding '{$path}' requires a query object with executionState().");
		}
		return [
			'type'=>'search_query',
			'mode'=>trim($mode)!=='' ? trim($mode) : 'results',
			'query_class'=>$query::class,
			'query_state'=>$this->normalizeBindingValue($query->executionState()),
			'inherit_query_identity'=>$inheritIdentity,
			'options'=>$this->normalizeBindingValue($options),
		];
	}

	/**
	 * Filters queued bindings into the final compiled binding list.
	 *
	 * @return list<array{path: string, definition: array<string, mixed>}> Valid binding entries in declaration order.
	 */
	private function compileBindings(): array {
		$compiled=[];
		foreach($this->bindings as $binding){
			$path=trim((string)($binding['path'] ?? ''));
			$definition=is_array($binding['definition'] ?? null) ? $binding['definition'] : null;
			if($path==='' || $definition===null){
				continue;
			}
			$compiled[]=[
				'path'=>$path,
				'definition'=>$definition,
			];
		}
		return $compiled;
	}

	/**
	 * Filters lifecycle hooks into the final phase-indexed metadata block.
	 *
	 * Empty lifecycle phases are omitted so dispatchers can test for phase existence cheaply.
	 *
	 * @return array<string, list<array<string, mixed>>> Lifecycle hooks keyed by before, after, and error.
	 */
	private function compileLifecycle(): array {
		$compiled=[];
		foreach(['before', 'after', 'error'] as $phase){
			$hooks=[];
			foreach(($this->lifecycle[$phase] ?? []) as $target){
				if(!is_array($target)){
					continue;
				}
				$hooks[]=$target;
			}
			if($hooks!==[]){
				$compiled[$phase]=$hooks;
			}
		}
		return $compiled;
	}

	/**
	 * Recursively validates an option map as static serializable data.
	 *
	 * @param array<string|int, mixed> $options Option map to normalize.
	 * @param string $errorMessage Exception message used when unsupported values are found.
	 * @return array<string|int, mixed> Static option map.
	 * @throws \RuntimeException When an option contains an object, resource, or other non-static value.
	 */
	private function normalizeStaticOptions(array $options, string $errorMessage): array {
		$normalized=[];
		foreach($options as $key=>$value){
			$normalized[$key]=$this->normalizeStaticValue($value, $errorMessage);
		}
		return $normalized;
	}

	/**
	 * Recursively validates one static metadata value.
	 *
	 * Static metadata is limited to arrays, scalars, and null because endpoint definitions are exported into route caches
	 * and route metadata manifests.
	 *
	 * @param mixed $value Candidate metadata value.
	 * @param string $errorMessage Exception message used when unsupported values are found.
	 * @return mixed scalar, null, or recursively normalized array safe for compiled endpoint metadata.
	 * @throws \RuntimeException When the value is not serializable static data.
	 */
	private function normalizeStaticValue(mixed $value, string $errorMessage): mixed {
		if(is_array($value)){
			$normalized=[];
			foreach($value as $key=>$entry){
				$normalized[$key]=$this->normalizeStaticValue($entry, $errorMessage);
			}
			return $normalized;
		}
		if(is_scalar($value) || $value===null){
			return $value;
		}
		throw new \RuntimeException($errorMessage);
	}

	/**
	 * Recursively validates binding metadata before it is embedded in endpoint compile output.
	 *
	 * @param mixed $value Candidate binding metadata value.
	 * @param bool $allowCallableIdentity Reserved compatibility flag for identity-specific values.
	 * @return mixed scalar, null, array, or callable-identity marker safe for binding metadata serialization.
	 * @throws \RuntimeException When the value cannot be serialized into binding metadata.
	 */
	private function normalizeBindingValue(mixed $value, bool $allowCallableIdentity=false): mixed {
		if(is_array($value)){
			$normalized=[];
			foreach($value as $key=>$entry){
				$normalized[$key]=$this->normalizeBindingValue($entry, $allowCallableIdentity);
			}
			return $normalized;
		}
		if(is_scalar($value) || $value===null){
			return $value;
		}
		if($allowCallableIdentity && is_string($value)){
			return trim($value);
		}
		throw new \RuntimeException('API binding options must be composed of scalar, null, or array values.');
	}

	/**
	 * Normalizes cache name input into a unique list of non-empty strings.
	 *
	 * @param array<int|string, mixed>|string|null $names Cache name or cache name collection.
	 * @return list<string> Unique cache names in declaration order.
	 */
	private function normalizeCacheNames(array|string|null $names): array {
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
	 * Canonicalizes route paths to the router's leading-slash format.
	 *
	 * @param string $path Route path template supplied by endpoint authors.
	 * @return string Normalized route path.
	 */
	private static function normalizePath(string $path): string {
		$path='/'.trim($path, '/');
		return $path==='/' ? '/' : rtrim($path, '/');
	}

	/**
	 * Removes surrounding slashes and whitespace from endpoint aliases.
	 *
	 * @param string $alias Raw alias.
	 * @return string Normalized alias, possibly empty.
	 */
	private static function normalizeAlias(string $alias): string {
		return trim(trim($alias), "/\\");
	}
}
