<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable request snapshot used by the panel runtime.
 *
 * PanelRequest centralizes route identity, operation intent, query/input payloads,
 * uploads, normalized headers, tenant context, and user context for panel
 * controllers, resource actions, table builders, and fragment/modal responders.
 */
final class PanelRequest {

	/**
	 * Stores a normalized panel request snapshot.
	 *
	 * Instances are immutable. Use withQuery(), withTenant(), and withUser() to
	 * derive adjusted requests while preserving route identity and captured payloads.
	 *
	 * @param string $method Uppercase HTTP method or effective method.
	 * @param string|null $resource Normalized panel resource name.
	 * @param string $operation Normalized panel operation name.
	 * @param string|null $recordKey Record identifier from route or query state.
	 * @param string|null $relation Relation name for relation operations.
	 * @param string|null $action Action name for action operations.
	 * @param array<string, mixed> $query Query parameters captured for panel handling.
	 * @param array<string, mixed> $input Form/body input captured for panel handling.
	 * @param array<string, mixed> $files Uploaded file payloads keyed by field name.
	 * @param array<string, string> $headers Lowercase normalized request headers.
	 * @param string|null $tenant Tenant key resolved from route, headers, query, or input.
	 * @param mixed $user Authenticated user/context object supplied by the caller.
	 */
	private function __construct(
		private readonly string $method,
		private readonly ?string $resource,
		private readonly string $operation,
		private readonly ?string $recordKey,
		private readonly ?string $relation,
		private readonly ?string $action,
		private readonly array $query,
		private readonly array $input,
		private readonly array $files=[],
		private readonly array $headers=[],
		private readonly ?string $tenant=null,
		private readonly mixed $user=null
	){}

	/**
	 * Captures a panel request from PHP superglobals.
	 *
	 * This path is used by legacy/direct panel entry points. It reads routing hints
	 * from $_GET, POST input from $_POST, uploads from $_FILES, and headers from the
	 * current server environment before delegating normalization to fromArray().
	 *
	 * @return self Immutable request snapshot for the current PHP request.
	 */
	public static function capture(): self {
		$resource=$_GET['resource'] ?? null;
		$operation=$_GET['operation'] ?? null;
		$recordKey=$_GET['record'] ?? null;
		$relation=$_GET['relation'] ?? null;
		$action=$_GET['action'] ?? null;
		if($operation==='action'){
			$action=$_GET['action'] ?? null;
			$recordKey=$_GET['record'] ?? null;
			$relation=null;
		}
		if($operation===null || $operation===''){
			$operation=strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))==='POST' ? 'store' : 'index';
		}
		return self::fromArray([
			'method'=>$_SERVER['REQUEST_METHOD'] ?? 'GET',
			'resource'=>$resource,
			'operation'=>$operation,
			'record'=>$recordKey,
			'relation'=>$relation,
			'action'=>$action,
			'query'=>$_GET,
			'input'=>$_POST,
			'files'=>$_FILES,
			'headers'=>self::captureHeaders(),
		]);
	}

	/**
	 * Builds a panel request from normalized or raw request fields.
	 *
	 * The factory accepts loose input so tests, adapters, and legacy controllers can
	 * create the same request object. Tenant resolution checks explicit tenant keys,
	 * configured tenant parameters in query/input, and supported tenant headers.
	 *
	 * @param array<string, mixed> $data Request payload with method, route, query, input, files, headers, tenant, and user keys.
	 * @return self Normalized panel request snapshot.
	 */
	public static function fromArray(array $data): self {
		$query=is_array($data['query'] ?? null) ? $data['query'] : [];
		$input=is_array($data['input'] ?? null) ? $data['input'] : [];
		$headers=self::normalizeHeaders(is_array($data['headers'] ?? null) ? $data['headers'] : []);
		$tenantParameter=PanelConfig::tenantParameter();
		$tenant=$data['tenant'] ?? $data['tenant_key'] ?? $query[$tenantParameter] ?? $input[$tenantParameter] ?? $headers['x-dataphyre-panel-tenant'] ?? $headers['x-panel-tenant'] ?? null;
		return new self(
			strtoupper(trim((string)($data['method'] ?? 'GET'))) ?: 'GET',
			self::optionalName($data['resource'] ?? null),
			self::normalizeOperation((string)($data['operation'] ?? 'index')),
			self::optionalString($data['record'] ?? $data['record_key'] ?? null),
			self::optionalName($data['relation'] ?? null),
			self::optionalName($data['action'] ?? null),
			$query,
			$input,
			is_array($data['files'] ?? null) ? $data['files'] : [],
			$headers,
			self::optionalString($tenant),
			$data['user'] ?? null
		);
	}

	/**
	 * Adapts a Dataphyre HTTP request into a panel request snapshot.
	 *
	 * Route parameters are preferred, query parameters are fallback values, and path
	 * segments may be inferred into resource, operation, record, relation, and action
	 * fields when infer_segments is enabled.
	 *
	 * @param \Dataphyre\Http\Request $request Source HTTP request object.
	 * @param array<string, mixed> $options Adapter options for route parameter names, segment inference, tenant lookup, and user override.
	 * @return self Normalized panel request snapshot.
	 */
	public static function fromHttpRequest(\Dataphyre\Http\Request $request, array $options=[]): self {
		$query=$request->query();
		$input=$request->input();
		$route=$request->route();
		$route=is_array($route) ? $route : [];
		$segments=self::routeSegments($route, $options);
		$inferred=self::inferRouteSegments($segments);
		$resource=self::firstRouteValue($route, $options['resource_parameters'] ?? ['panel_resource', 'resource'], $query['resource'] ?? null);
		$operation=self::firstRouteValue($route, $options['operation_parameters'] ?? ['panel_operation', 'operation'], $query['operation'] ?? null);
		$record=self::firstRouteValue($route, $options['record_parameters'] ?? ['panel_record', 'record', 'record_key', 'id', 'key', 'slug', 'uuid', 'employeeDocument', 'document', 'user'], $query['record'] ?? null);
		$relation=self::firstRouteValue($route, $options['relation_parameters'] ?? ['panel_relation', 'relation'], $query['relation'] ?? null);
		$action=self::firstRouteValue($route, $options['action_parameters'] ?? ['panel_action', 'action'], $query['action'] ?? null);
		if(($options['infer_segments'] ?? true)===true){
			$resource=$resource ?: ($inferred['resource'] ?? null);
			$operation=$operation ?: ($inferred['operation'] ?? null);
			$record=$record ?: ($inferred['record'] ?? null);
			$relation=$relation ?: ($inferred['relation'] ?? null);
			$action=$action ?: ($inferred['action'] ?? null);
		}
		return self::fromArray([
			'method'=>method_exists($request, 'effective_method') ? $request->effectiveMethod() : $request->method(),
			'resource'=>$resource,
			'operation'=>$operation ?: (strtoupper($request->method())==='POST' ? 'store' : 'index'),
			'record'=>$record,
			'relation'=>$relation,
			'action'=>$action,
			'query'=>$query,
			'input'=>$input,
			'files'=>$request->files(),
			'headers'=>$request->headers(),
			'tenant'=>self::firstRouteValue($route, $options['tenant_parameters'] ?? ['panel_tenant', 'tenant'], null),
			'user'=>$options['user'] ?? $request->attribute('user'),
		]);
	}

	/**
	 * Returns the captured HTTP method.
	 *
	 * @return string Uppercase request method or effective method.
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * Returns the normalized panel resource name.
	 *
	 * @return string|null Resource name, or null when the request does not target a concrete resource.
	 */
	public function resourceName(): ?string {
		return $this->resource;
	}

	/**
	 * Returns the normalized panel operation.
	 *
	 * @return string Operation such as index, store, show, action, relation, or field_state.
	 */
	public function operation(): string {
		return $this->operation;
	}

	/**
	 * Returns the route/query record identifier.
	 *
	 * @return string|null Record key used by record-level operations.
	 */
	public function recordKey(): ?string {
		return $this->recordKey;
	}

	/**
	 * Returns the panel action name for action requests.
	 *
	 * @return string|null Normalized action name when present.
	 */
	public function actionName(): ?string {
		return $this->action;
	}

	/**
	 * Returns the panel relation name for relation requests.
	 *
	 * @return string|null Normalized relation name when present.
	 */
	public function relationName(): ?string {
		return $this->relation;
	}

	/**
	 * Reads query parameters captured with the request.
	 *
	 * Passing no key returns the full query payload. Passing a key returns that value
	 * or the provided default without mutating the request.
	 *
	 * @param string|null $key Optional query key to read.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed Full query array, one query value, or the default.
	 */
	public function query(?string $key=null, mixed $default=null): mixed {
		return $key===null ? $this->query : ($this->query[$key] ?? $default);
	}

	/**
	 * Returns a clone with adjusted query parameters.
	 *
	 * By default new keys replace matching existing keys while preserving the rest;
	 * replace=true swaps the entire query payload.
	 *
	 * @param array<string, mixed> $query Query values to merge or replace.
	 * @param bool $replace True to replace the full query payload.
	 * @return self Cloned request with updated query state.
	 */
	public function withQuery(array $query, bool $replace=false): self {
		return new self(
			$this->method,
			$this->resource,
			$this->operation,
			$this->recordKey,
			$this->relation,
			$this->action,
			$replace ? $query : array_replace($this->query, $query),
			$this->input,
			$this->files,
			$this->headers,
			$this->tenant,
			$this->user
		);
	}

	/**
	 * Returns a clone with one normalized query value changed.
	 *
	 * Empty normalized keys are ignored. Null values remove the key, allowing callers
	 * to clear pagination, filters, or panel partial flags immutably.
	 *
	 * @param string $key Query key to normalize and update.
	 * @param mixed $value Value to assign, or null to remove the key.
	 * @return self Cloned request with the query value changed, or this request when the key is empty.
	 */
	public function withQueryValue(string $key, mixed $value): self {
		$query=$this->query;
		$key=Resource::normalizeName($key);
		if($key===''){
			return $this;
		}
		if($value===null){
			unset($query[$key]);
		}
		else {
			$query[$key]=$value;
		}
		return $this->withQuery($query, true);
	}

	/**
	 * Reads form/body input captured with the request.
	 *
	 * @param string|null $key Optional input key to read.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed Full input array, one input value, or the default.
	 */
	public function input(?string $key=null, mixed $default=null): mixed {
		return $key===null ? $this->input : ($this->input[$key] ?? $default);
	}

	/**
	 * Reads uploaded file payloads captured with the request.
	 *
	 * @param string|null $key Optional upload field key to read.
	 * @param mixed $default Value returned when the upload field is absent.
	 * @return mixed Full files array, one uploaded file payload, or the default.
	 */
	public function files(?string $key=null, mixed $default=null): mixed {
		return $key===null ? $this->files : ($this->files[$key] ?? $default);
	}

	/**
	 * Alias for files() when callers read a single upload field.
	 *
	 * @param string|null $key Optional upload field key to read.
	 * @param mixed $default Value returned when the upload field is absent.
	 * @return mixed full files array, one uploaded file payload, or the caller default.
	 */
	public function file(?string $key=null, mixed $default=null): mixed {
		return $this->files($key, $default);
	}

	/**
	 * Reads normalized request headers.
	 *
	 * Header names are normalized to lowercase dash-separated keys before lookup.
	 * Passing no key returns the full normalized header map.
	 *
	 * @param string|null $key Optional header name to read.
	 * @param mixed $default Value returned when the header is absent or invalid.
	 * @return mixed full header map, one normalized header value, or the caller default.
	 */
	public function headers(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->headers;
		}
		$key=self::normalizeHeaderName($key);
		return $key!=='' ? ($this->headers[$key] ?? $default) : $default;
	}

	/**
	 * Reads one normalized request header.
	 *
	 * @param string $key Header name in any supported case/separator style.
	 * @param mixed $default Value returned when the header is absent.
	 * @return mixed normalized header value, or the caller default when absent.
	 */
	public function header(string $key, mixed $default=null): mixed {
		return $this->headers($key, $default);
	}

	/**
	 * Detects requests expecting a panel modal response.
	 *
	 * Modal intent is signaled either by the DataphyrePanelModal X-Requested-With
	 * header or by a modal panel partial flag on a Dataphyre panel request.
	 *
	 * @return bool True when the caller expects modal content.
	 */
	public function isPanelModalRequest(): bool {
		$requestedWith=strtolower(trim((string)$this->header('x-requested-with', '')));
		if($requestedWith==='dataphyrepanelmodal'){
			return true;
		}
		$partial=(string)($this->query['__panel_partial'] ?? $this->input['__panel_partial'] ?? '');
		return Resource::normalizeName($partial)==='modal' && str_starts_with($requestedWith, 'dataphyrepanel');
	}

	/**
	 * Detects requests expecting a panel fragment response.
	 *
	 * Fragment intent is signaled by Dataphyre panel AJAX headers or by a fragment
	 * panel partial flag on a Dataphyre panel request.
	 *
	 * @return bool True when the caller expects partial panel markup.
	 */
	public function isPanelFragmentRequest(): bool {
		$requestedWith=strtolower(trim((string)$this->header('x-requested-with', '')));
		if(in_array($requestedWith, ['dataphyrepanel', 'dataphyrepanelfragment'], true)){
			return true;
		}
		$partial=(string)($this->query['__panel_partial'] ?? $this->input['__panel_partial'] ?? '');
		return Resource::normalizeName($partial)==='fragment' && str_starts_with($requestedWith, 'dataphyrepanel');
	}

	/**
	 * Detects dynamic field option requests.
	 *
	 * @return bool True when the panel partial flag requests field option data.
	 */
	public function isPanelFieldOptionsRequest(): bool {
		$partial=(string)($this->query['__panel_partial'] ?? $this->input['__panel_partial'] ?? '');
		return Resource::normalizeName($partial)==='field_options';
	}

	/**
	 * Detects dynamic field state requests.
	 *
	 * @return bool True when the panel partial flag requests field state data.
	 */
	public function isPanelFieldStateRequest(): bool {
		$partial=(string)($this->query['__panel_partial'] ?? $this->input['__panel_partial'] ?? '');
		return Resource::normalizeName($partial)==='field_state';
	}

	/**
	 * Returns the authenticated user/context associated with the request.
	 *
	 * @return mixed User object, identifier, array, or null as supplied by the adapter.
	 */
	public function user(): mixed {
		return $this->user;
	}

	/**
	 * Returns the resolved tenant key.
	 *
	 * @return string|null Tenant key resolved from explicit data, route, query, input, or headers.
	 */
	public function tenantKey(): ?string {
		return $this->tenant;
	}

	/**
	 * Returns the resolved tenant key.
	 *
	 * tenant() is a readability alias for tenantKey() in panel controllers and table
	 * builders.
	 *
	 * @return string|null Tenant key resolved for the request.
	 */
	public function tenant(): ?string {
		return $this->tenant;
	}

	/**
	 * Returns a clone with a different tenant key.
	 *
	 * Empty strings and non-scalar values normalize to null so callers can clear the
	 * tenant context without mutating the original request.
	 *
	 * @param string|null $tenant Tenant key to attach.
	 * @return self Cloned request with updated tenant context.
	 */
	public function withTenant(?string $tenant): self {
		return new self(
			$this->method,
			$this->resource,
			$this->operation,
			$this->recordKey,
			$this->relation,
			$this->action,
			$this->query,
			$this->input,
			$this->files,
			$this->headers,
			self::optionalString($tenant),
			$this->user
		);
	}

	/**
	 * Returns a clone with a different authenticated user/context value.
	 *
	 * @param mixed $user User object, identifier, array, or null.
	 * @return self Cloned request with updated user context.
	 */
	public function withUser(mixed $user): self {
		return new self(
			$this->method,
			$this->resource,
			$this->operation,
			$this->recordKey,
			$this->relation,
			$this->action,
			$this->query,
			$this->input,
			$this->files,
			$this->headers,
			$this->tenant,
			$user
		);
	}

	/**
	 * Returns the requested page number.
	 *
	 * Page values less than one are clamped to one to keep table pagination stable.
	 *
	 * @return int One-based page number.
	 */
	public function page(): int {
		return max(1, (int)($this->query['page'] ?? 1));
	}

	/**
	 * Returns the requested page size.
	 *
	 * Values are clamped to the inclusive range 1..250 so expensive panel tables do
	 * not accept unbounded page sizes from query input.
	 *
	 * @param int $default Default page size when the query value is absent.
	 * @return int Clamped page size.
	 */
	public function perPage(int $default=25): int {
		return max(1, min(250, (int)($this->query['per_page'] ?? $default)));
	}

	/**
	 * Serializes the request for diagnostics, tests, and examples.
	 *
	 * Uploaded files are summarized by name, type, size, and error code instead of
	 * returning full temporary-file payloads.
	 *
	 * @return array{method:string, resource:?string, operation:string, record:?string, relation:?string, action:?string, query:array<string, mixed>, input:array<string, mixed>, files:array<string, array{name:mixed, type:mixed, size:mixed, error:mixed}>, tenant:?string, partial:?string, page:int, per_page:int} Request payload.
	 */
	public function toArray(): array {
		return [
			'method'=>$this->method,
			'resource'=>$this->resource,
			'operation'=>$this->operation,
			'record'=>$this->recordKey,
			'relation'=>$this->relation,
			'action'=>$this->action,
			'query'=>$this->query,
			'input'=>$this->input,
			'files'=>self::filesSummary($this->files),
			'tenant'=>$this->tenant,
			'partial'=>$this->isPanelFragmentRequest() ? 'fragment' : ($this->isPanelModalRequest() ? 'modal' : null),
			'page'=>$this->page(),
			'per_page'=>$this->perPage(),
		];
	}

	/**
	 * Captures headers from getallheaders() and $_SERVER.
	 *
	 * The method merges Apache/FPM header APIs with HTTP_* server variables and
	 * content metadata before returning the normalized lowercase header map.
	 *
	 * @return array<string, string> Normalized headers for the current PHP request.
	 */
	private static function captureHeaders(): array {
		$headers=[];
		if(function_exists('getallheaders')){
			$candidate=getallheaders();
			if(is_array($candidate)){
				$headers=$candidate;
			}
		}
		foreach($_SERVER as $key=>$value){
			if(!is_string($key)){
				continue;
			}
			if(str_starts_with($key, 'HTTP_')){
				$headers[self::serverHeaderName(substr($key, 5))]=$value;
				continue;
			}
			if($key==='CONTENT_TYPE' || $key==='CONTENT_LENGTH'){
				$headers[self::serverHeaderName($key)]=$value;
			}
		}
		return self::normalizeHeaders($headers);
	}

	/**
	 * Normalizes arbitrary header names and values.
	 *
	 * Array-valued headers are joined with comma+space, matching common HTTP display
	 * form, and empty normalized names are discarded.
	 *
	 * @param array<mixed, mixed> $headers Raw header map.
	 * @return array<string, string> Lowercase dash-separated header names and string values.
	 */
	private static function normalizeHeaders(array $headers): array {
		$normalized=[];
		foreach($headers as $name=>$value){
			$name=self::normalizeHeaderName((string)$name);
			if($name===''){
				continue;
			}
			if(is_array($value)){
				$value=implode(', ', array_map(static fn(mixed $part): string => trim((string)$part), $value));
			}
			$normalized[$name]=trim((string)$value);
		}
		return $normalized;
	}

	/**
	 * Normalizes a header name for map lookup.
	 *
	 * @param string $name Header name in dash or underscore form.
	 * @return string Lowercase dash-separated header name.
	 */
	private static function normalizeHeaderName(string $name): string {
		return strtolower(str_replace('_', '-', trim($name)));
	}

	/**
	 * Converts a server-variable header name into display header form.
	 *
	 * @param string $name Header name from $_SERVER without the HTTP_ prefix.
	 * @return string Dash-separated header name suitable for normalization.
	 */
	private static function serverHeaderName(string $name): string {
		return str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))));
	}

	/**
	 * Builds a safe diagnostic summary for uploaded files.
	 *
	 * Temporary file paths and nested payload details are intentionally omitted so
	 * request serialization remains compact and safe for logs/tests.
	 *
	 * @param array<string, mixed> $files Raw upload payloads.
	 * @return array<string, array{name:mixed, type:mixed, size:mixed, error:mixed}> Upload summary keyed by field name.
	 */
	private static function filesSummary(array $files): array {
		$summary=[];
		foreach($files as $key=>$file){
			if(!is_array($file)){
				continue;
			}
			$summary[$key]=[
				'name'=>$file['name'] ?? null,
				'type'=>$file['type'] ?? null,
				'size'=>$file['size'] ?? null,
				'error'=>$file['error'] ?? null,
			];
		}
		return $summary;
	}

	/**
	 * Extracts panel route segments from configured route parameters.
	 *
	 * Segment values may be supplied as an array or as a slash-delimited string. Empty
	 * segments are removed before inference.
	 *
	 * @param array<string, mixed> $route Route parameter map from the HTTP request.
	 * @param array<string, mixed> $options Adapter options containing segment parameter names.
	 * @return array<int, string> Ordered non-empty route segments.
	 */
	private static function routeSegments(array $route, array $options): array {
		foreach((array)($options['segments_parameters'] ?? ['panel_segments', 'segments', 'path']) as $key){
			if(!is_string($key) || !array_key_exists($key, $route)){
				continue;
			}
			$value=$route[$key];
			if(is_array($value)){
				return array_values(array_filter(array_map('strval', $value), static fn(string $segment): bool => trim($segment)!==''));
			}
			$value=trim((string)$value, '/');
			return $value==='' ? [] : array_values(array_filter(explode('/', $value), static fn(string $segment): bool => $segment!==''));
		}
		return [];
	}

	/**
	 * Infers panel route fields from ordered path segments.
	 *
	 * The inference handles operation-first paths, record action paths, relation paths,
	 * and default resource/record/show/index shapes used by panel routes.
	 *
	 * @param array<int, mixed> $segments Ordered route path segments.
	 * @return array<string, string|null> Inferred resource, operation, record, relation, and action values.
	 */
	private static function inferRouteSegments(array $segments): array {
		$segments=array_values(array_filter(array_map(static fn(mixed $segment): string => trim((string)$segment), $segments), static fn(string $segment): bool => $segment!==''));
		if($segments===[]){
			return [];
		}
		$resource=$segments[0] ?? null;
		$second=$segments[1] ?? null;
		$third=$segments[2] ?? null;
		$fourth=$segments[3] ?? null;
		$operationNames=['index', 'create', 'store', 'show', 'edit', 'update', 'delete', 'destroy', 'force_delete', 'restore', 'duplicate', 'import', 'export', 'board', 'action', 'bulk_action', 'relation', 'transition', 'inline_update'];
		if($second!==null && in_array(self::normalizeOperation($second), $operationNames, true)){
			return [
				'resource'=>$resource,
				'operation'=>$second,
				'record'=>$third,
				'action'=>self::normalizeOperation($second)==='action' ? $third : null,
			];
		}
		if($second!==null && $third!==null && self::normalizeOperation($third)==='action'){
			return [
				'resource'=>$resource,
				'record'=>$second,
				'operation'=>'action',
				'action'=>$fourth,
			];
		}
		if($second!==null && $third!==null && self::normalizeOperation($third)==='relation'){
			return [
				'resource'=>$resource,
				'record'=>$second,
				'operation'=>'relation',
				'relation'=>$fourth,
			];
		}
		return [
			'resource'=>$resource,
			'record'=>$second,
			'operation'=>$third ?? ($second!==null ? 'show' : 'index'),
			'action'=>self::normalizeOperation((string)$third)==='action' ? $fourth : null,
			'relation'=>self::normalizeOperation((string)$third)==='relation' ? $fourth : null,
		];
	}

	/**
	 * Returns the first non-empty route value from a candidate key list.
	 *
	 * @param array<string, mixed> $route Route parameter map.
	 * @param mixed $keys Single key or list of keys to inspect.
	 * @param mixed $default Value returned when no candidate key is present.
	 * @return mixed first non-null/non-empty route value, or the caller default when no candidate matches.
	 */
	private static function firstRouteValue(array $route, mixed $keys, mixed $default=null): mixed {
		foreach((array)$keys as $key){
			if(is_string($key) && array_key_exists($key, $route) && $route[$key]!==null && $route[$key]!==''){
				return $route[$key];
			}
		}
		return $default;
	}

	/**
	 * Normalizes panel operation aliases.
	 *
	 * Resource::normalizeName() handles casing and separators; this method also maps
	 * common route aliases such as list, table, new, and save to canonical operation
	 * names.
	 *
	 * @param string $operation Raw operation name.
	 * @return string Canonical panel operation name.
	 */
	private static function normalizeOperation(string $operation): string {
		$operation=Resource::normalizeName($operation) ?: 'index';
		return match($operation){
			'list', 'table'=>'index',
			'new'=>'create',
			'save'=>'store',
			default=>$operation,
		};
	}

	/**
	 * Normalizes an optional resource-style name.
	 *
	 * Non-scalar values and empty normalized names become null.
	 *
	 * @param mixed $value Candidate name value.
	 * @return string|null Normalized name or null.
	 */
	private static function optionalName(mixed $value): ?string {
		if(!is_string($value) && !is_numeric($value)){
			return null;
		}
		$value=Resource::normalizeName((string)$value);
		return $value!=='' ? $value : null;
	}

	/**
	 * Normalizes an optional scalar string value.
	 *
	 * Non-scalar values and empty strings become null while numeric identifiers are
	 * preserved as strings.
	 *
	 * @param mixed $value Candidate string value.
	 * @return string|null Trimmed string or null.
	 */
	private static function optionalString(mixed $value): ?string {
		if(!is_string($value) && !is_numeric($value)){
			return null;
		}
		$value=trim((string)$value);
		return $value!=='' ? $value : null;
	}
}
