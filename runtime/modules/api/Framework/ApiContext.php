<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

use Dataphyre\Http\Request;
use Dataphyre\Sanitation\SanitizationResult;

/**
 * Per-request API context passed to endpoint handlers and nested dispatchers.
 *
 * The context keeps the immutable request, matched route metadata, validation
 * result, resolved bindings, binding traces, and auth metadata separate so
 * handlers choose raw input, sanitized input, resolved bindings, or authorized
 * identity data explicitly.
 */
final class ApiContext {

	private ?SanitizationResult $validationResult=null;
	private array $bindings=[];
	private array $bindingTrace=[];
	private ?array $defaultInput=null;
	private array|string|null $lastSourcesInput=null;
	private ?array $lastSourcesOutput=null;
	private ?array $lastMergedSources=null;
	private ?array $lastMergedInput=null;

	/**
	 * Captures the immutable request and matched route metadata for one API dispatch.
	 *
	 * Raw request data, route metadata, validation state, binding state, and auth
	 * state stay separated. Validation and nested dispatch mutate only the context's
	 * per-request state; the underlying Request snapshot remains unchanged.
	 *
	 * @param Request $request HTTP/API or Panel request carrying route, query, body, headers, cookies, and server data.
	 * @param array<string, mixed> $route Route metadata captured by the API dispatcher.
	 */
	public function __construct(
		private readonly Request $request,
		private readonly array $route
	){}

	/**
	 * Returns the immutable request snapshot backing this API context.
	 *
	 * @return Request Immutable HTTP request object for this context.
	 */
	public function request(): Request {
		return $this->request;
	}

	/**
	 * Returns the compiled route row matched by the dispatcher.
	 *
	 * @return array<string, mixed> Route metadata, including API execution metadata when present.
	 */
	public function route(): array {
		return $this->route;
	}

	/**
	 * Returns the request method visible to API handlers.
	 *
	 * @return string Uppercase HTTP method.
	 */
	public function method(): string {
		return $this->request->method();
	}

	/**
	 * Returns the normalized request path visible to API handlers.
	 *
	 * @return string Normalized request path.
	 */
	public function path(): string {
		return $this->request->path();
	}

	/**
	 * Reads route parameters using direct keys or dot paths.
	 *
	 * @param ?string $key Dot-path route parameter, or null for the full parameter map.
	 * @param mixed $default Fallback returned when the requested parameter is absent.
	 * @return mixed Route parameter value, full route parameter map, or supplied default.
	 */
	public function parameters(?string $key=null, mixed $default=null): mixed {
		$parameters=$this->request->routeParameters();
		if($key===null){
			return $parameters;
		}
		$value=$this->pathValue($parameters, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	/**
	 * Reads query input using request dot-path semantics.
	 *
	 * @param ?string $key Dot-path query key, or null for the full query map.
	 * @param mixed $default Fallback returned when the query key is absent.
	 * @return mixed Query value, full query map, or supplied default.
	 */
	public function query(?string $key=null, mixed $default=null): mixed {
		return $this->request->query($key, $default);
	}

	/**
	 * Reads parsed body input using request dot-path semantics.
	 *
	 * @param ?string $key Dot-path body key, or null for the full parsed body.
	 * @param mixed $default Fallback returned when the body key is absent.
	 * @return mixed Body value, full parsed body, or supplied default.
	 */
	public function body(?string $key=null, mixed $default=null): mixed {
		return $this->request->input($key, $default);
	}

	/**
	 * Merges configured request sources into effective API input.
	 *
	 * Source order is controlled by the caller and later sources replace earlier
	 * values recursively. By default, query, body, and route data are merged. The
	 * source allow-list is intentionally limited to request maps; validation results
	 * and route bindings are available through their dedicated accessors.
	 * Header and server maps are included only when requested explicitly because
	 * they can contain transport metadata rather than caller business input.
	 *
	 * @param ?string $key Dot-path key read from merged input, or null for all input.
	 * @param mixed $default Fallback returned when the requested key is absent.
	 * @param array|string|null $sources Input source list such as route, query, body, cookies, headers, or server.
	 * @return mixed Merged input value, full merged input, or supplied default.
	 */
	public function input(?string $key=null, mixed $default=null, array|string|null $sources=null): mixed {
		$input=$this->all($sources);
		if($key===null){
			return $input;
		}
		$value=$this->pathValue($input, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	/**
	 * Merges configured request sources into effective API input.
	 *
	 * This exposes only explicit request source maps; request attributes, validation
	 * results, bindings, and arbitrary object state are not included in merged
	 * handler input.
	 *
	 * @param array|string|null $sources Input source list such as route, query, body, cookies, headers, or server.
	 * @return array<string, mixed> Merged request input.
	 */
	public function all(array|string|null $sources=null): array {
		if($sources===null){
			return $this->defaultInput ??= $this->mergeSources(['query', 'body', 'route']);
		}
		$normalized=$this->normalizeSources($sources);
		if($this->lastMergedSources===$normalized && $this->lastMergedInput!==null){
			return $this->lastMergedInput;
		}
		$this->lastMergedSources=$normalized;
		return $this->lastMergedInput=$this->mergeSources($normalized);
	}

	/**
	 * Reads cookie values using request dot-path semantics.
	 *
	 * @param ?string $key Dot-path cookie key, or null for the full cookie map.
	 * @param mixed $default Fallback returned when the cookie key is absent.
	 * @return mixed Cookie value, full cookie map, or supplied default.
	 */
	public function cookie(?string $key=null, mixed $default=null): mixed {
		return $this->request->cookie($key, $default);
	}

	/**
	 * Reads normalized request headers.
	 *
	 * @param ?string $name Header name, or null for the full normalized header map.
	 * @param mixed $default Fallback returned when the header is absent.
	 * @return mixed Header value, full header map, or supplied default.
	 */
	public function header(?string $name=null, mixed $default=null): mixed {
		if($name===null){
			return $this->request->headers();
		}
		return $this->request->header($name, $default);
	}

	/**
	 * Reads server environment values from the request snapshot.
	 *
	 * @param ?string $key Server key, or null for the full server map.
	 * @param mixed $default Fallback returned when the server key is absent.
	 * @return mixed Server value, full server map, or supplied default.
	 */
	public function server(?string $key=null, mixed $default=null): mixed {
		return $this->request->server($key, $default);
	}

	/**
	 * Validates merged API input and stores the sanitation result on the context.
	 *
	 * Validation reads only the configured request source set, strips API
	 * response-control options before calling Sanitation, and stores the result for
	 * later handlers. Failed validation still produces a SanitizationResult; callers
	 * decide whether to return it, throw, or continue.
	 *
	 * @param array<string, mixed> $schema Sanitation schema used to validate merged API input.
	 * @param array<string, mixed> $defaults Default values merged into sanitation before validation.
	 * @param array<string, mixed> $options Sanitation options plus ApiContext `sources` selection.
	 * @return SanitizationResult Stored sanitation result.
	 */
	public function validate(array $schema, array $defaults=[], array $options=[]): SanitizationResult {
		$this->ensureSanitationFramework();
		$sources=$this->normalizeSources($options['sources'] ?? null);
		$sanitationOptions=$this->extractSanitationOptions($options);
		$result=\Dataphyre\Sanitation\Sanitation::schema(
			$this->all($sources),
			$schema,
			$defaults,
			$sanitationOptions
		);
		$this->validationResult=$result;
		return $result;
	}

	/**
	 * Reads sanitized API input after validation.
	 *
	 * Until validation runs, the whole validated input is empty and keyed reads
	 * return the caller fallback.
	 *
	 * @param ?string $key Dot-path validated key, or null for the full validated input.
	 * @param mixed $default Fallback returned when validation has not run or the key is absent.
	 * @return mixed Validated value, full validated input, or supplied default.
	 */
	public function validated(?string $key=null, mixed $default=null): mixed {
		if($this->validationResult===null){
			return $key===null ? [] : $default;
		}
		if($key===null){
			return $this->validationResult->validated();
		}
		return $this->validationResult->get($key, $default);
	}

	/**
	 * Returns the stored sanitation result, when validation has run.
	 *
	 * @return ?SanitizationResult Stored sanitation result, or null when validation has not run.
	 */
	public function validation(): ?SanitizationResult {
		return $this->validationResult;
	}

	/**
	 * Reports whether validation has run for this context.
	 *
	 * @return bool True when a sanitation result is stored.
	 */
	public function hasValidatedInput(): bool {
		return $this->validationResult instanceof SanitizationResult;
	}

	/**
	 * Stores a sanitation result on this context.
	 *
	 * The context is mutable for pipeline handoff; callers receive the same instance
	 * for fluent integration with route execution. Replacing the result affects only
	 * later reads from this context.
	 *
	 * @param SanitizationResult $result Sanitation result produced by validation.
	 * @return self Mutated context carrying updated validation state.
	 */
	public function withValidationResult(SanitizationResult $result): self {
		$this->validationResult=$result;
		return $this;
	}

	/**
	 * Returns resolved route/model binding data.
	 *
	 * Binding data is installed by the dispatcher after request input is captured.
	 * It is not merged into input() or all(), which keeps trusted resolver output
	 * distinct from caller-supplied request data.
	 *
	 * @return array<string, mixed> Resolved bindings keyed by binding path.
	 */
	public function bindings(): array {
		return $this->bindings;
	}

	/**
	 * Reads one resolved binding value by dot path.
	 *
	 * @param string $path Dot-path into resolved binding data.
	 * @param mixed $default Fallback returned when the binding path is absent.
	 * @return mixed resolved binding value at the dotted path, or the caller default when absent.
	 */
	public function binding(string $path, mixed $default=null): mixed {
		$value=$this->pathValue($this->bindings, $path);
		return $value['present']===true ? $value['value'] : $default;
	}

	/**
	 * Reports whether a binding path is present.
	 *
	 * @param string $path Dot-path into resolved binding data.
	 * @return bool True when the binding path exists, even when the value is null.
	 */
	public function hasBinding(string $path): bool {
		return $this->pathValue($this->bindings, $path)['present']===true;
	}

	/**
	 * Returns trace rows collected while resolving bindings.
	 *
	 * Trace rows are diagnostic metadata for resolver decisions and should not be
	 * treated as handler input.
	 *
	 * @return array<int, array<string,mixed>> Binding trace records.
	 */
	public function bindingTrace(): array {
		return $this->bindingTrace;
	}

	/**
	 * Installs resolved binding data and trace rows on the context.
	 *
	 * Existing binding state is replaced atomically for this request context; the
	 * method does not merge with previous resolver output.
	 *
	 * @param array<string, mixed> $bindings Resolved route/model binding data keyed by binding path.
	 * @param array<int, array<string,mixed>> $bindingTrace Trace entries explaining how bindings were resolved.
	 * @return self Mutated context carrying updated binding state.
	 */
	public function withBindings(array $bindings, array $bindingTrace=[]): self {
		$this->bindings=$bindings;
		$this->bindingTrace=$bindingTrace;
		return $this;
	}

	/**
	 * Returns normalized authentication data attached by authorization.
	 *
	 * Only arrays stored under the framework auth attribute are exposed.
	 * Malformed attributes collapse to empty unauthenticated data.
	 *
	 * @return array<string, mixed> Auth data, or an empty array when unauthenticated.
	 */
	public function auth(): array {
		$auth=$this->request->attribute('dataphyre_api_auth', []);
		return is_array($auth) ? $auth : [];
	}

	/**
	 * Reports whether authorization has attached an authorized auth state.
	 *
	 * Authorization is explicit: a non-empty identity or scopes list is not enough
	 * unless the auth state's authorized flag is true.
	 *
	 * @return bool True when auth state explicitly marks the request authorized.
	 */
	public function hasAuth(): bool {
		return ($this->auth()['authorized'] ?? false)===true;
	}

	/**
	 * Returns the authorized security scheme name.
	 *
	 * Blank or non-string schemes are suppressed so downstream authorization checks
	 * do not accidentally trust malformed metadata.
	 *
	 * @return ?string Security scheme name, or null when absent.
	 */
	public function authScheme(): ?string {
		$scheme=$this->auth()['scheme'] ?? null;
		return is_string($scheme) && trim($scheme)!=='' ? trim($scheme) : null;
	}

	/**
	 * Returns the identity attached by the auth resolver.
	 *
	 * The identity is returned as attached by the auth resolver; no type coercion or
	 * permission check is performed here.
	 *
	 * @param mixed $default Fallback returned when no identity is attached.
	 * @return mixed Identity attached by the auth resolver, or the caller default when absent.
	 */
	public function authIdentity(mixed $default=null): mixed {
		$auth=$this->auth();
		return array_key_exists('identity', $auth) ? $auth['identity'] : $default;
	}

	/**
	 * Returns scopes associated with the authorized request.
	 *
	 * Non-array scope data is discarded instead of being coerced, which keeps
	 * scope checks from accepting malformed authorization data.
	 *
	 * @return array<int, string> Authorized scopes, or an empty list.
	 */
	public function authScopes(): array {
		$scopes=$this->auth()['scopes'] ?? [];
		return is_array($scopes) ? $scopes : [];
	}

	/**
	 * Reads context metadata attached by the auth resolver.
	 *
	 * Context metadata can include resolver-specific authorization state. Non-array
	 * context values are hidden, and dotted reads preserve caller defaults for absent keys.
	 *
	 * @param ?string $key Dot-path auth context key, or null for the full context map.
	 * @param mixed $default Fallback returned when the context key is absent.
	 * @return mixed Auth context value, full context map, or supplied default.
	 */
	public function authContext(?string $key=null, mixed $default=null): mixed {
		$context=$this->auth()['context'] ?? [];
		if(!is_array($context)){
			return $key===null ? [] : $default;
		}
		if($key===null){
			return $context;
		}
		$value=$this->pathValue($context, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	/**
	 * Reads non-sensitive auth metadata attached by the auth resolver.
	 *
	 * Metadata is intended for diagnostics or response shaping rather than secret
	 * material. Non-array metadata is hidden from handlers.
	 *
	 * @param ?string $key Dot-path auth metadata key, or null for the full metadata map.
	 * @param mixed $default Fallback returned when the metadata key is absent.
	 * @return mixed Auth metadata value, full metadata map, or supplied default.
	 */
	public function authMeta(?string $key=null, mixed $default=null): mixed {
		$meta=$this->auth()['meta'] ?? [];
		if(!is_array($meta)){
			return $key===null ? [] : $default;
		}
		if($key===null){
			return $meta;
		}
		$value=$this->pathValue($meta, $key);
		return $value['present']===true ? $value['value'] : $default;
	}

	/**
	 * Dispatches nested API calls through the API framework using context defaults.
	 *
	 * The current request is supplied as the base request, and trusted auth is
	 * inherited only when this context is already authorized.
	 *
	 * @param array<string, mixed> $request Internal dispatch request definition.
	 * @param array<string, mixed> $options Dispatch options that override route defaults.
	 * @return array<string, mixed> Normalized dispatch result.
	 */
	public function dispatch(array $request, array $options=[]): array {
		return Api::dispatch($request, $this->dispatchDefaults($options));
	}

	/**
	 * Dispatches nested API calls as a batch using context defaults.
	 *
	 * Route dispatch defaults and safe auth inheritance are merged before caller
	 * options, so handlers can override inherited behavior explicitly.
	 *
	 * @param array<int|string, array<string,mixed>|mixed> $requests API subrequests executed as a batch.
	 * @param array<string, mixed> $options Batch and dispatch options.
	 * @return array<string, mixed> Normalized batch result.
	 */
	public function dispatchBatch(array $requests, array $options=[]): array {
		return Api::dispatchBatch($requests, $this->dispatchDefaults($options));
	}

	/**
	 * Dispatches nested API calls as a chain using context defaults.
	 *
	 * Chain dispatch uses the same inheritance boundary as batch dispatch while
	 * preserving chain-specific defaults in ApiManager.
	 *
	 * @param array<int, array<string,mixed>|mixed> $requests API subrequests executed as a chain.
	 * @param array<string, mixed> $options Chain and dispatch options.
	 * @return array<string, mixed> Normalized chain result.
	 */
	public function dispatchChain(array $requests, array $options=[]): array {
		return Api::dispatchChain($requests, $this->dispatchDefaults($options));
	}

	/**
	 * Merges allowed request sources into one handler input data map.
	 *
	 * Later sources recursively replace earlier values, matching the
	 * context default order of query, body, then route parameters. Only explicit
	 * framework request sources are accepted; unsupported source names contribute an
	 * empty data map and never reach request attributes or arbitrary server state.
	 *
	 * @param array<int, string> $sources Normalized source names.
	 * @return array<string, mixed> Merged input data for validation or handler reads.
	 */
	private function mergeSources(array $sources): array {
		$merged=[];
		foreach($sources as $source){
			$sourceData=match ($source) {
				'query' => $this->request->query(),
				'body' => $this->request->input(),
				'route' => $this->request->routeParameters(),
				'cookies' => $this->request->cookie(),
				'headers' => $this->request->headers(),
				'server' => $this->request->server(),
				default => [],
			};
			if(is_array($sourceData)===false){
				continue;
			}
			$merged=array_replace_recursive($merged, $sourceData);
		}
		return $merged;
	}

	/**
	 * Normalizes caller-supplied source names for input merging.
	 *
	 * Null and empty source lists fall back to the public API default of
	 * query, body, and route data. Names are trimmed, lowercased, deduplicated, and
	 * restricted to request surfaces that ApiContext intentionally exposes.
	 *
	 * @param array|string|null $sources Source name or source list from callers/options.
	 * @return array<int, string> Ordered, unique source names safe for mergeSources().
	 */
	private function normalizeSources(array|string|null $sources): array {
		if($sources===null){
			return ['query', 'body', 'route'];
		}
		if($this->lastSourcesInput===$sources && $this->lastSourcesOutput!==null){
			return $this->lastSourcesOutput;
		}
		$input=$sources;
		if(is_string($sources)){
			$sources=[$sources];
		}
		$normalized=[];
		foreach($sources as $source){
			$source=strtolower(trim((string)$source));
			if($source===''){
				continue;
			}
			if(!in_array($source, ['route', 'query', 'body', 'cookies', 'headers', 'server'], true)){
				continue;
			}
			$normalized[$source]=$source;
		}
		$this->lastSourcesInput=$input;
		return $this->lastSourcesOutput=$normalized!==[] ? array_values($normalized) : ['query', 'body', 'route'];
	}

	/**
	 * Removes API-context control options before forwarding sanitation settings.
	 *
	 * `sources` chooses ApiContext input composition, while status,
	 * message, and headers belong to API response handling. Stripping those keys
	 * keeps Sanitation::schema() focused on validation behavior only.
	 *
	 * @param array<string, mixed> $options Mixed validation and dispatch options.
	 * @return array<string, mixed> Options intended for the sanitation framework.
	 */
	private function extractSanitationOptions(array $options): array {
		unset($options['sources'], $options['status'], $options['message'], $options['headers']);
		return $options;
	}

	/**
	 * Ensures the sanitation framework is available before schema validation.
	 *
	 * API validation lazily loads the sanitation framework through core
	 * when possible so lightweight API contexts can exist before optional modules
	 * are initialized. If the class is still unavailable, validation fails loudly
	 * before any partial result is stored on the context.
	 *
	 * @return void
	 * @throws \RuntimeException When the sanitation framework cannot be loaded.
	 */
	private function ensureSanitationFramework(): void {
		if(class_exists('Dataphyre\\Sanitation\\Sanitation')){
			return;
		}
		if(class_exists('\dataphyre\core', false)){
			\dataphyre\core::load_framework_module('sanitation');
		}
		if(class_exists('Dataphyre\\Sanitation\\Sanitation')===false){
			throw new \RuntimeException('Dataphyre sanitation is required for API schema validation.');
		}
	}

	/**
	 * Reads a direct key or dotted path from an array with presence metadata.
	 *
	 * Exact direct lookups preserve null values as present, while dotted
	 * traversal requires each intermediate value to be an array. The returned shape
	 * distinguishes missing values from present nulls so API helpers can apply
	 * caller defaults without losing intentional null request values.
	 *
	 * @param array<string, mixed> $source Source data to inspect.
	 * @param string $path Direct key or dot-delimited path.
	 * @return array{present: bool, value: mixed} Presence flag and resolved value.
	 */
	private function pathValue(array $source, string $path): array {
		if($path==='' || str_contains($path, '.')===false){
			$present=array_key_exists($path, $source);
			return [
				'present'=>$present,
				'value'=>$present ? $source[$path] : null,
			];
		}
		$current=$source;
		foreach(explode('.', $path) as $segment){
			if(is_array($current)===false || array_key_exists($segment, $current)===false){
				return [
					'present'=>false,
					'value'=>null,
				];
			}
			$current=$current[$segment];
		}
		return [
			'present'=>true,
			'value'=>$current,
		];
	}

	/**
	 * Builds the ambient data map passed to templating bindings.
	 *
	 * The map exposes raw request sources, merged input, validated data, auth
	 * data, and previously resolved bindings without exposing request
	 * attributes or mutable dispatcher internals.
	 *
	 * @return array<string, mixed> Binding data map.
	 */
	public function bindingData(): array {
		return [
			'request'=>[
				'method'=>$this->method(),
				'path'=>$this->path(),
			],
			'route'=>$this->request->routeParameters(),
			'query'=>$this->request->query(),
			'body'=>$this->request->input(),
			'input'=>$this->all(),
			'validated'=>$this->validated(),
			'auth'=>$this->auth(),
			'bindings'=>$this->bindings,
		];
	}

	/**
	 * Builds inherited options for nested API dispatches.
	 *
	 * Route-level dispatch defaults are merged with the current immutable
	 * request and trusted auth context so nested dispatch can reuse authorization
	 * only when the parent context has already been authorized. Caller options win
	 * last, allowing explicit overrides for batch or chain orchestration.
	 *
	 * @param array<string, mixed> $options Caller-supplied nested dispatch options.
	 * @return array<string, mixed> Options passed to Api dispatch helpers.
	 */
	private function dispatchDefaults(array $options): array {
		$routeDispatch=is_array($this->route['api']['dispatch'] ?? null) ? $this->route['api']['dispatch'] : [];
		return array_replace($routeDispatch, [
			'base_request'=>$this->request,
			'auth'=>$this->hasAuth() ? $this->auth() : null,
			'trust_auth'=>$this->hasAuth(),
		], $options);
	}
}
