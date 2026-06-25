<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Http;

/**
 * HTTP request snapshot used by Dataphyre routing, controllers, and adapters.
 *
 * Request centralizes normalized method/path state, query and body input, cookies,
 * uploaded files, server values, headers, route parameters, middleware
 * attributes, content negotiation, and macro extension points.
 */
final class Request {

	private string $method;
	private string $path;
	private array $query;
	private array $body;
	private array $cookies;
	private array $files;
	private array $server;
	private array $headers;
	private array $routeParameters;
	private array $attributes=[];
	private ?array $combinedInput=null;
	private ?array $acceptableContentTypes=null;
	private array $dotPathSegments=[];
	private static array $macros=[];
	private static ?string $lastAcceptHeader=null;
	private static ?array $lastAcceptContentTypes=null;
	private static ?string $previousAcceptHeader=null;
	private static ?array $previousAcceptContentTypes=null;

	/**
	 * Stores normalized request state.
	 *
	 * Private constructor keeps method casing, path cleanup, file flattening, and header
	 * normalization centralized in capture() and create().
	 *
	 * @param string $method Original HTTP method.
	 * @param string $path Normalized request path.
	 * @param array<string, mixed> $query Query parameters.
	 * @param array<string, mixed> $body Parsed body input.
	 * @param array<string, mixed> $cookies Cookie values.
	 * @param array<string, UploadedFile> $files Uploaded files keyed by dot path.
	 * @param array<string, mixed> $server Server environment values.
	 * @param array<string, mixed> $headers Normalized header map.
	 * @param array<string, mixed> $routeParameters Route parameters.
	 */
	private function __construct(
		string $method,
		string $path,
		array $query,
		array $body,
		array $cookies,
		array $files,
		array $server,
		array $headers,
		array $routeParameters
	){
		$this->method=$method;
		$this->path=$path;
		$this->query=$query;
		$this->body=$body;
		$this->cookies=$cookies;
		$this->files=$files;
		$this->server=$server;
		$this->headers=$headers;
		$this->routeParameters=$routeParameters;
	}

	/**
	 * Captures the current PHP request from superglobals.
	 * Query, cookies, server state, uploaded files, headers, and JSON body input are
	 * normalized into the same shape produced by create().
	 * @param array<string, mixed> $routeParameters Route parameters already resolved by the router.
	 * @return self Request snapshot for the active PHP request.
	 */
	public static function capture(array $routeParameters=[]): self {
		return new self(
			strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
			self::detectPath(),
			$_GET,
			self::captureBody(),
			$_COOKIE,
			self::normalizeFiles($_FILES),
			$_SERVER,
			self::captureHeaders(),
			$routeParameters
		);
	}
	/**
	 * Creates a request snapshot from explicit values.
	 *
	 * Useful for tests, adapters, and synthetic dispatch while still applying Dataphyre
	 * request normalization.
	 *
	 * @param string $method HTTP method before uppercase normalization.
	 * @param string $path Request path before slash normalization.
	 * @param array<string, mixed> $query Query parameters.
	 * @param array<string, mixed> $body Parsed body input.
	 * @param array<string, mixed> $cookies Cookie values.
	 * @param array<string, mixed> $server Server environment values.
	 * @param array<string, mixed> $headers Header values before case/key normalization.
	 * @param array<string, mixed> $routeParameters Route parameters already resolved by the router.
	 * @param array<string, mixed> $attributes Middleware/controller attributes to attach after construction.
	 * @param array<string, mixed> $files PHP upload arrays or UploadedFile-compatible entries.
	 * @return self Normalized synthetic request snapshot.
	 */
	public static function create(
		string $method,
		string $path,
		array $query=[],
		array $body=[],
		array $cookies=[],
		array $server=[],
		array $headers=[],
		array $routeParameters=[],
		array $attributes=[],
		array $files=[]
	): self {
		$request=new self(
			strtoupper(trim($method)) ?: 'GET',
			self::normalizePath($path),
			$query,
			$body,
			$cookies,
			self::normalizeFiles($files),
			$server,
			self::normalizeHeaders($headers),
			$routeParameters
		);
		if($attributes!==[]){
			$request->mergeAttributes($attributes);
		}
		return $request;
	}
	/**
	 * Registers a dynamic request macro.
	 *
	 * Closure macros are bound to the Request instance when invoked.
	 *
	 * @param string $name Non-empty macro method name.
	 * @param callable $macro Callback invoked with __call() arguments.
	 * @return void
	 * @throws \InvalidArgumentException When the macro name is empty.
	 */
	public static function macro(string $name, callable $macro): void {
		$name=trim($name);
		if($name===''){
			throw new \InvalidArgumentException('Request macro name cannot be empty.');
		}
		self::$macros[$name]=$macro;
	}
	/**
	 * Checks whether a dynamic request macro is registered.
	 *
	 * @param string $name Macro method name.
	 * @return bool True when a macro has been registered for the name.
	 */
	public static function hasMacro(string $name): bool {
		return isset(self::$macros[$name]);
	}
	/**
	 * Clears all registered request macros.
	 *
	 * This mutates static process state and is mainly useful for tests or worker
	 * lifecycle resets.
	 *
	 * @return void
	 */
	public static function flushMacros(): void {
		self::$macros=[];
	}
	/**
	 * Dispatches a registered dynamic macro call.
	 *
	 * Closure macros are rebound to the request instance so they can inspect
	 * normalized input, files, headers, and attributes.
	 *
	 * @param string $name Macro method name.
	 * @param array<int, mixed> $arguments Positional arguments supplied by the caller.
	 * @return mixed value produced by the registered macro after closure binding to this request.
	 * @throws \BadMethodCallException When no macro is registered for the method name.
	 */
	public function __call(string $name, array $arguments): mixed {
		if(isset(self::$macros[$name])===false){
			throw new \BadMethodCallException('Request macro is not registered: '.$name);
		}
		$macro=self::$macros[$name];
		if($macro instanceof \Closure){
			$macro=$macro->bindTo($this, self::class);
		}
		return $macro(...$arguments);
	}
	/**
	 * Returns the original captured HTTP method.
	 *
	 * @return string Uppercase HTTP method stored on the request.
	 */
	public function method(): string {
		return $this->method;
	}
	/**
	 * Returns the original captured HTTP method.
	 *
	 * @return string Uppercase HTTP method before override handling.
	 */
	public function originalMethod(): string {
		return $this->method;
	}
	/**
	 * Returns the method after supported POST overrides are applied.
	 *
	 * Only PUT, PATCH, and DELETE overrides are accepted.
	 *
	 * @return string Effective method after trusted override fields are considered.
	 */
	public function effectiveMethod(): string {
		if(!in_array($this->method, ['POST'], true)){
			return $this->method;
		}
		$override=$this->header('X-HTTP-Method-Override');
		if(!is_string($override) || trim($override)===''){
			$override=$this->body['_method'] ?? $this->query['_method'] ?? null;
		}
		if(!is_string($override)){
			return $this->method;
		}
		$override=strtoupper(trim($override));
		return in_array($override, ['PUT', 'PATCH', 'DELETE'], true) ? $override : $this->method;
	}
	/**
	 * Returns the normalized request path.
	 *
	 * @return string Absolute path with one leading slash and no trailing slash except root.
	 */
	public function path(): string {
		return $this->path;
	}
	/**
	 * Resolves the request scheme.
	 *
	 * X-Forwarded-Proto is preferred before HTTPS and server-port fallbacks.
	 *
	 * @return 'http'|'https' Resolved request scheme.
	 */
	public function scheme(): string {
		$forwarded=(string)($this->headers['x_forwarded_proto'] ?? '');
		if($forwarded!==''){
			$first=strtolower(self::firstForwardedValue($forwarded));
			return in_array($first, ['http', 'https'], true) ? $first : 'http';
		}
		$https=(string)$this->server('HTTPS', '');
		if($https!=='' && strtolower($https)!=='off'){
			return 'https';
		}
		return ((int)$this->server('SERVER_PORT', 0))===443 ? 'https' : 'http';
	}
	/**
	 * Resolves the request host.
	 *
	 * X-Forwarded-Host is preferred before Host and server-name fallbacks.
	 *
	 * @return string Hostname or host header value visible to the request.
	 */
	public function host(): string {
		$forwarded=(string)($this->headers['x_forwarded_host'] ?? '');
		if($forwarded!==''){
			return self::firstForwardedValue($forwarded);
		}
		$host=(string)($this->headers['host'] ?? '');
		if($host!==''){
			return $host;
		}
		return (string)$this->server('HTTP_HOST', $this->server('SERVER_NAME', 'localhost'));
	}
	/**
	 * Returns the scheme and host root URL.
	 *
	 * @return string Root URL without a trailing slash.
	 */
	public function root(): string {
		return $this->scheme().'://'.$this->host();
	}
	/**
	 * Returns the request URL without query string.
	 *
	 * @return string Absolute request URL without query string.
	 */
	public function url(): string {
		return $this->root().$this->path;
	}
	/**
	 * Returns the request URL including query string.
	 *
	 * Query parameters are encoded with PHP_QUERY_RFC3986.
	 *
	 * @return string Absolute request URL including the encoded query string when present.
	 */
	public function fullUrl(): string {
		$query=http_build_query($this->query, '', '&', PHP_QUERY_RFC3986);
		return $query==='' ? $this->url() : $this->url().'?'.$query;
	}
	/**
	 * Resolves the client IP address.
	 *
	 * The first X-Forwarded-For entry wins before REMOTE_ADDR.
	 *
	 * @return string Client IP candidate from forwarding headers or server state.
	 */
	public function ip(): string {
		$forwarded=(string)($this->headers['x_forwarded_for'] ?? '');
		if($forwarded!==''){
			$first=self::firstForwardedValue($forwarded);
			if($first!==''){
				return $first;
			}
		}
		return (string)$this->server('REMOTE_ADDR', '');
	}
	/**
	 * Returns the request user agent.
	 *
	 * @return string User-Agent header or server fallback.
	 */
	public function userAgent(): string {
		$userAgent=(string)($this->headers['user_agent'] ?? '');
		return $userAgent!=='' ? $userAgent : (string)$this->server('HTTP_USER_AGENT', '');
	}
	/**
	 * Returns all route parameters attached to the request.
	 *
	 * @return array<string, mixed> Route parameters keyed by route variable name.
	 */
	public function routeParameters(): array {
		return $this->routeParameters;
	}
	/**
	 * Merges additional route parameters into this request.
	 *
	 * This mutates the request for router and controller pipeline handoff.
	 *
	 * @param array<string, mixed> $parameters Parameters that replace existing values by key.
	 * @return self Mutated request instance.
	 */
	public function mergeRouteParameters(array $parameters): self {
		$this->routeParameters=array_replace($this->routeParameters, $parameters);
		return $this;
	}
	/**
	 * Reads route parameters using optional dot notation.
	 *
	 * @param ?string $key Dot-path route parameter, or null for the full route map.
	 * @param mixed $default Value returned when the route parameter is absent.
	 * @return mixed Route value, full route map, or default.
	 */
	public function route(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->routeParameters;
		}
		return $this->dataGet($this->routeParameters, $key, $default);
	}
	/**
	 * Returns the named route attribute.
	 * @return ?string String value or null.
	 */
	public function routeName(): ?string {
		$name=$this->attributes['route_name'] ?? null;
		return is_string($name) && $name!=='' ? $name : null;
	}
	/**
	 * Checks the current route name against exact or wildcard patterns.
	 *
	 * Patterns use fnmatch() semantics.
	 *
	 * @param string ...$patterns Patterns.
	 * @return bool True when the current route name matches any exact or wildcard pattern.
	 */
	public function routeIs(string ...$patterns): bool {
		$name=$this->routeName();
		if($name===null){
			return false;
		}
		foreach($patterns as $pattern){
			$pattern=trim($pattern);
			if($pattern===''){
				continue;
			}
			if($pattern===$name || fnmatch($pattern, $name)){
				return true;
			}
		}
		return false;
	}
	/**
	 * Reads query parameters using optional dot notation.
	 *
	 * @param ?string $key Dot-path query key, or null for the full query map.
	 * @param mixed $default Value returned when the query key is absent.
	 * @return mixed Query value, full query map, or default.
	 */
	public function query(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->query;
		}
		return $this->dataGet($this->query, $key, $default);
	}
	/**
	 * Reads parsed body input using optional dot notation.
	 *
	 * @param ?string $key Dot-path body key, or null for the full parsed body.
	 * @param mixed $default Value returned when the body key is absent.
	 * @return mixed Body value, full body map, or default.
	 */
	public function input(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->body;
		}
		return $this->dataGet($this->body, $key, $default);
	}
	/**
	 * Returns query, body, and uploaded file input as one request data map.
	 *
	 * Files override body keys and body values override query keys in the combined view.
	 * @return array<string, mixed> Combined request input map.
	 */
	public function all(): array {
		return $this->combinedInput ??= array_replace($this->query, $this->body, $this->files);
	}
	/**
	 * Returns selected request input values.
	 *
	 * Keys may use dot notation and missing keys are skipped.
	 *
	 * @param array<int, string>|string $keys Input keys to keep.
	 * @return array<string, mixed> Selected input values preserving dot-path shape.
	 */
	public function only(array|string $keys): array {
		$keys=is_array($keys) ? $keys : func_get_args();
		$all=$this->all();
		$only=[];
		foreach($keys as $key){
			if(!is_string($key) || !$this->dataHas($all, $key)){
				continue;
			}
			$this->dataSet($only, $key, $this->dataGet($all, $key));
		}
		return $only;
	}
	/**
	 * Returns request input with selected keys removed.
	 *
	 * Keys may use dot notation for nested removal.
	 *
	 * @param array<int, string>|string $keys Input keys to remove.
	 * @return array<string, mixed> Combined input map after removals.
	 */
	public function except(array|string $keys): array {
		$keys=is_array($keys) ? $keys : func_get_args();
		$except=$this->all();
		foreach($keys as $key){
			if(is_string($key)){
				$this->dataForget($except, $key);
			}
		}
		return $except;
	}
	/**
	 * Checks whether all requested input keys are present.
	 *
	 * @param array<int, string>|string $keys Input keys or dot paths.
	 * @return bool True when every requested key exists and at least one key was requested.
	 */
	public function has(array|string $keys): bool {
		$keys=is_array($keys) ? $keys : func_get_args();
		$all=null;
		foreach($keys as $key){
			if(!is_string($key)){
				return false;
			}
			if(!str_contains($key, '.')){
				if(array_key_exists($key, $this->files) || array_key_exists($key, $this->body) || array_key_exists($key, $this->query)){
					continue;
				}
				return false;
			}
			$all ??= $this->all();
			if(!$this->dataHas($all, $key)){
				return false;
			}
		}
		return $keys!==[];
	}
	/**
	 * Checks whether all requested input keys are present and non-empty.
	 *
	 * Null, empty strings, empty arrays, and invalid uploads are treated as empty.
	 *
	 * @param array<int, string>|string $keys Input keys or dot paths.
	 * @return bool True when every requested key exists and has a non-empty value.
	 */
	public function filled(array|string $keys): bool {
		$keys=is_array($keys) ? $keys : func_get_args();
		$all=null;
		foreach($keys as $key){
			if(!is_string($key)){
				return false;
			}
			if(!str_contains($key, '.')){
				if(array_key_exists($key, $this->files)){
					if($this->isEmptyInputValue($this->files[$key])){
						return false;
					}
					continue;
				}
				if(array_key_exists($key, $this->body)){
					if($this->isEmptyInputValue($this->body[$key])){
						return false;
					}
					continue;
				}
				if(array_key_exists($key, $this->query)){
					if($this->isEmptyInputValue($this->query[$key])){
						return false;
					}
					continue;
				}
				return false;
			}
			$all ??= $this->all();
			if(!$this->dataHas($all, $key) || $this->isEmptyInputValue($this->dataGet($all, $key))){
				return false;
			}
		}
		return $keys!==[];
	}
	/**
	 * Reads an input value as a boolean.
	 *
	 * FILTER_VALIDATE_BOOLEAN vocabulary is used with the provided default as fallback.
	 *
	 * @param string $key Input key or dot path.
	 * @param bool $default Fallback when the value is absent or not boolean-like.
	 * @return bool Normalized boolean value.
	 */
	public function boolean(string $key, bool $default=false): bool {
		if(!str_contains($key, '.')){
			if(array_key_exists($key, $this->files)){
				$value=$this->files[$key];
			}elseif(array_key_exists($key, $this->body)){
				$value=$this->body[$key];
			}elseif(array_key_exists($key, $this->query)){
				$value=$this->query[$key];
			}else{
				return $default;
			}
		}else{
			$value=$this->dataGet($this->all(), $key);
		}
		if($value===null){
			return $default;
		}
		return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
	}
	/**
	 * Reads an input value as an integer.
	 *
	 * @param string $key Input key or dot path.
	 * @param int $default Fallback when the value is absent or non-numeric.
	 * @return int Integer-cast numeric value or fallback.
	 */
	public function integer(string $key, int $default=0): int {
		if(!str_contains($key, '.')){
			if(array_key_exists($key, $this->files)){
				$value=$this->files[$key];
			}elseif(array_key_exists($key, $this->body)){
				$value=$this->body[$key];
			}elseif(array_key_exists($key, $this->query)){
				$value=$this->query[$key];
			}else{
				return $default;
			}
		}else{
			$value=$this->dataGet($this->all(), $key);
		}
		return is_numeric($value) ? (int)$value : $default;
	}
	/**
	 * Reads an input value as a float.
	 *
	 * @param string $key Input key or dot path.
	 * @param float $default Fallback when the value is absent or non-numeric.
	 * @return float Float-cast numeric value or fallback.
	 */
	public function float(string $key, float $default=0.0): float {
		if(!str_contains($key, '.')){
			if(array_key_exists($key, $this->files)){
				$value=$this->files[$key];
			}elseif(array_key_exists($key, $this->body)){
				$value=$this->body[$key];
			}elseif(array_key_exists($key, $this->query)){
				$value=$this->query[$key];
			}else{
				return $default;
			}
		}else{
			$value=$this->dataGet($this->all(), $key);
		}
		return is_numeric($value) ? (float)$value : $default;
	}
	/**
	 * Reads cookie values using optional dot notation.
	 *
	 * @param ?string $key Dot-path cookie key, or null for the full cookie map.
	 * @param mixed $default Value returned when the cookie key is absent.
	 * @return mixed Cookie value, full cookie map, or default.
	 */
	public function cookie(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->cookies;
		}
		return $this->dataGet($this->cookies, $key, $default);
	}
	/**
	 * Reads normalized uploaded files using optional dot notation.
	 *
	 * @param ?string $key Dot-path upload key, or null for the full upload map.
	 * @param mixed $default Value returned when the upload key is absent.
	 * @return UploadedFile|array<string, UploadedFile>|mixed Uploaded file value, upload map, or default.
	 */
	public function files(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->files;
		}
		return $this->dataGet($this->files, $key, $default);
	}
	/**
	 * Alias for files() when reading one uploaded file.
	 *
	 * @param ?string $key Dot-path upload key, or null for the full upload map.
	 * @param mixed $default Value returned when the upload key is absent.
	 * @return UploadedFile|array<string, UploadedFile>|mixed Uploaded file value, upload map, or default.
	 */
	public function file(?string $key=null, mixed $default=null): mixed {
		return $this->files($key, $default);
	}
	/**
	 * Checks whether an uploaded file exists and passed PHP upload validation.
	 *
	 * @param string $key Dot-path upload key.
	 * @return bool True when the upload exists and is valid.
	 */
	public function hasFile(string $key): bool {
		$file=$this->file($key);
		return $file instanceof UploadedFile && $file->isValid();
	}
	/**
	 * Reads a normalized request header.
	 *
	 * Header names are normalized to lowercase underscore keys.
	 *
	 * @param string $name Header name using dashes or underscores.
	 * @param mixed $default Value returned when the header is absent.
	 * @return mixed normalized header value keyed by lowercase underscore name, or the caller default when absent.
	 */
	public function header(string $name, mixed $default=null): mixed {
		$key=strtolower(str_replace('-', '_', trim($name)));
		return $this->headers[$key] ?? $default;
	}
	/**
	 * Returns all normalized request headers.
	 *
	 * @return array<string, mixed> Header map keyed by lowercase underscore names.
	 */
	public function headers(): array {
		return $this->headers;
	}
	/**
	 * Detects classic XMLHttpRequest traffic.
	 *
	 * @return bool True when X-Requested-With is XMLHttpRequest.
	 */
	public function ajax(): bool {
		return strtolower((string)($this->headers['x_requested_with'] ?? ''))==='xmlhttprequest';
	}
	/**
	 * Detects JSON request bodies from Content-Type.
	 *
	 * @return bool True when Content-Type is a JSON or structured JSON media type.
	 */
	public function isJson(): bool {
		$contentType=strtolower((string)($this->headers['content_type'] ?? ''));
		return str_contains($contentType, '/json') || str_contains($contentType, '+json');
	}
	/**
	 * Detects whether the client prefers a JSON response.
	 *
	 * Wildcard Accept entries do not count as explicit JSON preference.
	 *
	 * @return bool True when the first explicit acceptable media type is JSON.
	 */
	public function wantsJson(): bool {
		foreach($this->acceptableContentTypes() as $type){
			if($type==='*/*' || $type==='*'){
				continue;
			}
			return str_contains($type, '/json') || str_contains($type, '+json');
		}
		return false;
	}
	/**
	 * Detects whether framework responses should default to JSON.
	 *
	 * JSON is expected for JSON preference or AJAX requests that accept any type.
	 *
	 * @return bool True when response helpers should prefer JSON.
	 */
	public function expectsJson(): bool {
		return $this->wantsJson() || ($this->ajax() && $this->acceptsAnyContentType());
	}
	/**
	 * Checks whether the Accept header allows one of the supplied content types.
	 *
	 * Exact media matches and subtype wildcards are supported.
	 *
	 * @param string|array<int, string> $contentTypes Candidate response media types.
	 * @return bool True when any candidate is accepted by the request.
	 */
	public function accepts(string|array $contentTypes): bool {
		$acceptable=$this->acceptableContentTypes();
		if($acceptable===[] || in_array('*/*', $acceptable, true) || in_array('*', $acceptable, true)){
			return true;
		}
		foreach((array)$contentTypes as $contentType){
			$contentType=strtolower(trim((string)$contentType));
			if($contentType===''){
				continue;
			}
			foreach($acceptable as $accept){
				if($accept===$contentType){
					return true;
				}
				if(str_ends_with($accept, '/*') && str_starts_with($contentType, substr($accept, 0, -1))){
					return true;
				}
				if(str_ends_with($contentType, '/*') && str_starts_with($accept, substr($contentType, 0, -1))){
					return true;
				}
			}
		}
		return false;
	}
	/**
	 * Checks whether the request accepts any response content type.
	 *
	 * Empty Accept and wildcard media ranges accept any response type.
	 *
	 * @return bool True when no response media type restriction is expressed.
	 */
	public function acceptsAnyContentType(): bool {
		$acceptable=$this->acceptableContentTypes();
		return $acceptable===[] || in_array('*/*', $acceptable, true) || in_array('*', $acceptable, true);
	}
	/**
	 * Reads server environment values.
	 *
	 * @param ?string $key Server key, or null for the full server map.
	 * @param mixed $default Value returned when the server key is absent.
	 * @return mixed Server value, full server map, or default.
	 */
	public function server(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->server;
		}
		return $this->server[$key] ?? $default;
	}
	/**
	 * Returns request attributes attached by middleware, routing, or controllers.
	 *
	 * @return array<string, mixed> Attribute map for pipeline-local request state.
	 */
	public function attributes(): array {
		return $this->attributes;
	}
	/**
	 * Reads request attributes.
	 *
	 * @param ?string $key Attribute key, or null for the full attribute map.
	 * @param mixed $default Value returned when the attribute is absent.
	 * @return mixed Attribute value, full attribute map, or default.
	 */
	public function attribute(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->attributes;
		}
		return $this->attributes[$key] ?? $default;
	}
	/**
	 * Sets a request attribute in place.
	 *
	 * Empty attribute keys are ignored.
	 *
	 * @param string $key Attribute key.
	 * @param mixed $value Attribute value stored for later pipeline stages.
	 * @return self Mutated request instance.
	 */
	public function setAttribute(string $key, mixed $value): self {
		$key=trim($key);
		if($key!==''){
			$this->attributes[$key]=$value;
		}
		return $this;
	}
	/**
	 * Merges string-keyed attributes into the request.
	 *
	 * Non-string keys are ignored.
	 *
	 * @param array<string|int, mixed> $attributes Attribute data from middleware or adapters.
	 * @return self Mutated request instance.
	 */
	public function mergeAttributes(array $attributes): self {
		foreach($attributes as $key=>$value){
			if(!is_string($key)){
				continue;
			}
			$this->setAttribute($key, $value);
		}
		return $this;
	}
	/**
	 * Determines whether an input value should count as empty for filled().
	 *
	 * UploadedFile values are considered filled only when PHP reported a valid
	 * upload; scalar zero and false remain filled input values.
	 *
	 * @param mixed $value Candidate input value.
	 * @return bool True when filled() should treat the value as empty.
	 */
	private function isEmptyInputValue(mixed $value): bool {
		if($value instanceof UploadedFile){
			return !$value->isValid();
		}
		return $value===null || $value==='' || $value===[];
	}
	/**
	 * Checks nested array presence using dot notation.
	 *
	 * Literal keys are checked before traversing dot paths.
	 *
	 * @param array<string|int, mixed> $data Input map to inspect.
	 * @param string $key Literal key or dot path.
	 * @return bool True when the key exists, even if the value is null.
	 */
	private function dataHas(array $data, string $key): bool {
		if(array_key_exists($key, $data)){
			return true;
		}
		$current=$data;
		foreach($this->dotPathSegments($key) as $segment){
			if(!is_array($current) || array_key_exists($segment, $current)===false){
				return false;
			}
			$current=$current[$segment];
		}
		return true;
	}
	/**
	 * Reads nested array values using dot notation.
	 *
	 * Literal keys are checked before traversing dot paths.
	 *
	 * @param array<string|int, mixed> $data Input map to inspect.
	 * @param string $key Literal key or dot path.
	 * @param mixed $default Value returned when any path segment is missing.
	 * @return mixed literal-key value, dotted-path value, or the caller default when any segment is missing.
	 */
	private function dataGet(array $data, string $key, mixed $default=null): mixed {
		if(array_key_exists($key, $data)){
			return $data[$key];
		}
		$current=$data;
		foreach($this->dotPathSegments($key) as $segment){
			if(!is_array($current) || array_key_exists($segment, $current)===false){
				return $default;
			}
			$current=$current[$segment];
		}
		return $current;
	}
	/**
	 * Removes nested array values using dot notation.
	 *
	 * Literal top-level keys are removed before traversing dot paths.
	 *
	 * @param array<string|int, mixed> $data Input map mutated by reference.
	 * @param string $key Literal key or dot path to remove.
	 * @return void
	 */
	private function dataForget(array &$data, string $key): void {
		if(array_key_exists($key, $data)){
			unset($data[$key]);
			return;
		}
		$current=&$data;
		$segments=$this->dotPathSegments($key);
		$last=array_pop($segments);
		foreach($segments as $segment){
			if(!is_array($current) || array_key_exists($segment, $current)===false){
				return;
			}
			$current=&$current[$segment];
		}
		if(is_array($current) && $last!==null){
			unset($current[$last]);
		}
	}
	/**
	 * Sets nested array values using dot notation.
	 *
	 * Missing intermediate arrays are created as needed.
	 *
	 * @param array<string|int, mixed> $data Input map mutated by reference.
	 * @param string $key Literal key or dot path to set.
	 * @param mixed $value Value to store.
	 * @return void
	 */
	private function dataSet(array &$data, string $key, mixed $value): void {
		if(!str_contains($key, '.')){
			$data[$key]=$value;
			return;
		}
		$current=&$data;
		foreach($this->dotPathSegments($key) as $segment){
			if(!isset($current[$segment]) || !is_array($current[$segment])){
				$current[$segment]=[];
			}
			$current=&$current[$segment];
		}
		$current=$value;
	}
	/**
	 * Splits and caches dot-path segments used by nested input helpers.
	 *
	 * @param string $key Dot-path input key.
	 * @return array<int, string> Cached path segments.
	 */
	private function dotPathSegments(string $key): array {
		return $this->dotPathSegments[$key] ??= explode('.', $key);
	}
	/**
	 * Detects the current request path from query/server state.
	 *
	 * The legacy uri query key is preferred before REQUEST_URI path parsing.
	 *
	 * @return string Normalized path for the current PHP request.
	 */
	private static function detectPath(): string {
		$path=(string)($_GET['uri'] ?? '');
		if($path===''){
			$path=(string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
		}
		return self::normalizePath($path);
	}
	/**
	 * Captures parsed request body input.
	 *
	 * POST data wins; otherwise JSON from php://input is decoded when possible.
	 *
	 * Invalid JSON, empty bodies, and non-object/non-array JSON documents collapse to
	 * an empty array so request consumers never receive raw body bytes here.
	 *
	 * @return array<string, mixed> Parsed request body input.
	 */
	private static function captureBody(): array {
		if(is_array($_POST) && $_POST!==[]){
			return $_POST;
		}
		$raw=@file_get_contents('php://input');
		if(!is_string($raw) || trim($raw)===''){
			return [];
		}
		$decoded=json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}
	/**
	 * Normalizes PHP upload arrays into UploadedFile instances.
	 *
	 * Nested uploads are flattened into dot-path keys and empty uploads are discarded.
	 *
	 * @param array<string, mixed> $files PHP $_FILES-shaped upload tree.
	 * @return array<string, UploadedFile> Uploaded files keyed by flattened dot path.
	 */
	private static function normalizeFiles(array $files): array {
		$normalized=[];
		foreach($files as $field=>$spec){
			if(!is_string($field) || !is_array($spec)){
				continue;
			}
			foreach(self::walkFile($field, $spec) as $path=>$file){
				if($file->error()===UPLOAD_ERR_NO_FILE){
					continue;
				}
				$normalized[$path]=$file;
			}
		}
		return $normalized;
	}
	/**
	 * Walks one upload field and returns flattened UploadedFile instances.
	 *
	 * Recursive PHP upload arrays are converted into child specs so every leaf is
	 * represented by exactly one UploadedFile instance.
	 *
	 * @param string $path Current flattened upload path.
	 * @param array<string, mixed> $spec PHP upload field spec.
	 * @return array<string, UploadedFile> Uploaded files discovered beneath the path.
	 */
	private static function walkFile(string $path, array $spec): array {
		$name=$spec['name'] ?? null;
		if(!is_array($name)){
			return [$path=>UploadedFile::fromArray($spec)];
		}
		$files=[];
		foreach(array_keys($name) as $key){
			$child=[
				'name'=>$spec['name'][$key] ?? '',
				'type'=>is_array($spec['type'] ?? null) ? ($spec['type'][$key] ?? '') : '',
				'tmp_name'=>is_array($spec['tmp_name'] ?? null) ? ($spec['tmp_name'][$key] ?? '') : '',
				'error'=>is_array($spec['error'] ?? null) ? ($spec['error'][$key] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE,
				'size'=>is_array($spec['size'] ?? null) ? ($spec['size'][$key] ?? 0) : 0,
			];
			$files+=self::walkFile($path.'.'.(string)$key, $child);
		}
		return $files;
	}
	/**
	 * Captures request headers from server variables.
	 *
	 * HTTP variables, content metadata, PHP auth values, and redirected authorization are
	 * folded into one map.
	 *
	 * Header keys are stored as lowercase underscore names for case-insensitive
	 * lookups through header().
	 *
	 * @return array<string, mixed> Normalized header map captured from $_SERVER.
	 */
	private static function captureHeaders(): array {
		$headers=[];
		foreach($_SERVER as $key=>$value){
			if(str_starts_with($key, 'HTTP_')===false){
				continue;
			}
			$normalized=strtolower(substr($key, 5));
			$headers[$normalized]=$value;
		}
		if(isset($_SERVER['CONTENT_TYPE'])){
			$headers['content_type']=$_SERVER['CONTENT_TYPE'];
		}
		if(isset($_SERVER['CONTENT_LENGTH'])){
			$headers['content_length']=$_SERVER['CONTENT_LENGTH'];
		}
		if(isset($_SERVER['PHP_AUTH_USER'])){
			$headers['php_auth_user']=$_SERVER['PHP_AUTH_USER'];
		}
		if(isset($_SERVER['PHP_AUTH_PW'])){
			$headers['php_auth_pw']=$_SERVER['PHP_AUTH_PW'];
		}
		if(isset($headers['authorization'])===false){
			foreach(['REDIRECT_HTTP_AUTHORIZATION', 'Authorization', 'HTTP_AUTHORIZATION'] as $key){
				if(isset($_SERVER[$key]) && is_string($_SERVER[$key]) && trim($_SERVER[$key])!==''){
					$headers['authorization']=$_SERVER[$key];
					break;
				}
			}
		}
		return $headers;
	}
	/**
	 * Normalizes a request path.
	 *
	 * Empty paths become `/`; every other path gets a leading slash and no trailing
	 * slash.
	 *
	 * @param string $path Raw path or URI path component.
	 * @return string Normalized absolute path.
	 */
	private static function normalizePath(string $path): string {
		$path='/'.trim((string)$path, '/');
		return $path==='/' ? '/' : rtrim($path, '/');
	}
	/**
	 * Returns the first value from a comma-separated forwarding header.
	 *
	 * @param string $value Raw forwarding header value.
	 * @return string Trimmed first value.
	 */
	private static function firstForwardedValue(string $value): string {
		$comma=strpos($value, ',');
		return trim($comma===false ? $value : substr($value, 0, $comma));
	}
	/**
	 * Parses the Accept header into quality-sorted media types.
	 *
	 * Entries with q=0 are discarded and ties preserve original order.
	 *
	 * @return array<int, string> Lowercase media types ordered by preference.
	 */
	private function acceptableContentTypes(): array {
		if($this->acceptableContentTypes!==null){
			return $this->acceptableContentTypes;
		}
		$accept=(string)$this->header('Accept', '');
		if(trim($accept)===''){
			return $this->acceptableContentTypes=[];
		}
		if(self::$lastAcceptHeader!==null && $accept===self::$lastAcceptHeader){
			return $this->acceptableContentTypes=self::$lastAcceptContentTypes;
		}
		if(self::$previousAcceptHeader!==null && $accept===self::$previousAcceptHeader){
			return $this->acceptableContentTypes=self::$previousAcceptContentTypes;
		}
		$types=[];
		foreach(explode(',', $accept) as $position=>$part){
			$segments=explode(';', $part);
			$type=strtolower((string)array_shift($segments));
			$type=trim($type);
			if($type===''){
				continue;
			}
			$q=1.0;
			foreach($segments as $segment){
				$segment=trim($segment);
				if(str_starts_with($segment, 'q=')){
					$q=max(0.0, min(1.0, (float)substr($segment, 2)));
				}
			}
			if($q<=0.0){
				continue;
			}
			$types[]=[
				'type'=>$type,
				'q'=>$q,
				'position'=>$position,
			];
		}
		usort($types, static fn(array $a, array $b): int => ($b['q'] <=> $a['q']) ?: ($a['position'] <=> $b['position']));
		$contentTypes=array_column($types, 'type');
		self::$previousAcceptHeader=self::$lastAcceptHeader;
		self::$previousAcceptContentTypes=self::$lastAcceptContentTypes;
		self::$lastAcceptHeader=$accept;
		self::$lastAcceptContentTypes=$contentTypes;
		return $this->acceptableContentTypes=$contentTypes;
	}
	/**
	 * Normalizes explicit header arrays into lookup keys.
	 *
	 * Header names are lowercased and hyphens become underscores so they match the
	 * shape produced by captureHeaders().
	 *
	 * @param array<string|int, mixed> $headers Header map supplied by tests or adapters.
	 * @return array<string, mixed> Normalized header map.
	 */
	private static function normalizeHeaders(array $headers): array {
		$normalized=[];
		foreach($headers as $name=>$value){
			if(!is_string($name)){
				continue;
			}
			$key=strtolower(str_replace('-', '_', trim($name)));
			if($key===''){
				continue;
			}
			$normalized[$key]=$value;
		}
		return $normalized;
	}
}
