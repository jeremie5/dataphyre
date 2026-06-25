<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP API, OpenAPI, and API cache planning surfaces.
 */
trait dataphyre_mcp_planning_api_surfaces {

	/**
	 * Plans an API surface scaffold.
	 *
	 * API planning remains static and caller-owned; it describes endpoint,
	 * OpenAPI, cache, validation, and verification artifacts without starting a
	 * server, dispatching routes, or writing generated files.
	 */
	private function api_scaffold_plan(array $args): array {
		$name=trim((string)($args['name'] ?? ''));
		if($name===''){
			throw new InvalidArgumentException('name is required.');
		}
		$slug=$this->slug_name($name);
		$class=$this->studly_name($name);
		$path=trim((string)($args['path'] ?? ''));
		if($path===''){
			$path='/api/'.str_replace('-', '/', $slug);
		}
		if($path[0]!=="/"){
			$path='/'.$path;
		}
		$methods=$args['methods'] ?? [];
		if(!is_array($methods) || $methods===[]){
			$methods=['GET'];
		}
		$methods=array_values(array_unique(array_filter(array_map(
			static fn(mixed $method): string => strtoupper(trim((string)$method)),
			$methods
		), static fn(string $method): bool => preg_match('/^[A-Z]+$/', $method)===1)));
		if($methods===[]){
			$methods=['GET'];
		}
		$group=trim((string)($args['group'] ?? ''));
		$auth=trim((string)($args['auth'] ?? 'none')) ?: 'none';
		$endpoint_policy_metadata=$this->api_endpoint_policy_metadata($methods, $auth);
		$operation_id=str_replace('-', '.', $slug);
		$proposed_files=[
			'applications/<app>/backend/dataphyre/routes/api/'.$slug.'.php',
			'applications/<app>/backend/dataphyre/api/'.$class.'Endpoints.php',
			'applications/<app>/backend/dataphyre/unit_tests/api.'.$slug.'.json',
		];
		$verification=[
			'dataphyre_api_docs_static_summary',
			'dataphyre_route_source_static_summary',
			'dataphyre_route_manifest_read',
			'dataphyre_route_url_preview',
			'dataphyre_php_lint',
		];
		return [
			'type'=>'api_endpoint',
			'name'=>$name,
			'write_policy'=>'dry_run_only',
			'unsafe_required_to_apply'=>true,
			'execution'=>'not_executed',
			'extension_boundary'=>$this->planning_extension_boundary('api_endpoint'),
			'endpoint'=>[
				'path'=>$path,
				'methods'=>$methods,
				'operation_id'=>$operation_id,
				'handler'=>$class.'Endpoints::handle',
				'group_or_profile'=>$group!=='' ? $group : null,
				'auth_hint'=>$auth,
			],
			'endpoint_policy_metadata'=>$endpoint_policy_metadata,
			'recommended_docs'=>[
				'common/dataphyre/runtime/modules/api/documentation/Dataphyre_Api.md',
				'common/dataphyre/runtime/modules/routing/documentation/Dataphyre_Routing.md',
				'common/dataphyre/runtime/modules/http/documentation/Dataphyre_HTTP.md',
			],
			'optional_guidance_docs'=>[
				'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md',
			],
			'proposed_files'=>$proposed_files,
			'steps'=>[
				'Inspect existing API declarations with dataphyre_api_docs_static_summary before choosing the local endpoint style.',
				'Declare the endpoint with Api::get/post/put/patch/delete or an Api::group/profile when shared prefix, tags, auth, trace, or dispatch defaults are needed.',
				'Add summary, operationId, tags, parameters, schema validation, auth metadata, response schemas, trace/cache choices, and execute(...) target.',
				'Keep endpoint handler logic in a small endpoint class and move reusable behavior into framework/service classes.',
				'Regenerate or inspect compiled route manifests through normal project tooling, then use MCP route tools for read-only verification.',
			],
			'openapi_plan'=>[
				'operation_id'=>$operation_id,
				'minimum_contract'=>['summary', 'operationId', 'tags', 'parameters', 'request schema for mutating methods', 'jsonResponse schemas'],
				'publish_surfaces'=>['Api::openApiDocument(...)', 'Api::documentationRoutes(...)'],
			],
			'verification'=>$verification,
			'verification_plan'=>$this->app_builder_verification_plan($proposed_files, [], $verification),
			'guardrails'=>[
				'This plan does not write route files, execute endpoints, dispatch routes, clear endpoint cache, or generate OpenAPI at runtime.',
				'Do not expose tokens, API keys, cookies, auth headers, or signed URL secrets in docs or MCP output.',
				'Use application-owned endpoint classes, config, callbacks, dialbacks, plugins, or adapters before proposing Dataphyre runtime-internal API changes for one app.',
				'Use explicit unsafe-gated workflows for any future endpoint execution or cache clearing.',
			],
		];
	}

	/**
	 * Builds compact machine-readable endpoint policy metadata for agents.
	 *
	 * @param array<int,string> $methods HTTP methods.
	 * @param string $auth Auth hint.
	 * @return array<string,mixed> Endpoint policy metadata.
	 */
	private function api_endpoint_policy_metadata(array $methods, string $auth): array {
		$methods=array_values(array_unique(array_map(static fn(string $method): string => strtoupper($method), $methods)));
		$mutating_methods=array_values(array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']));
		$auth=trim($auth)==='' ? 'none' : trim($auth);
		$auth_required=$auth!=='' && $auth!=='none';
		return [
			'owner'=>'consuming_application',
			'mode'=>'endpoint_static_planning',
			'auth'=>[
				'hint'=>$auth,
				'required'=>$auth_required,
				'decision_required'=>!$auth_required,
				'mutating_without_auth'=>$mutating_methods!==[] && !$auth_required,
				'recommended_for_mutating_methods'=>$mutating_methods===[] ? 'app_policy' : 'explicit_auth_required_before_write',
			],
			'methods'=>$methods,
			'mutating_methods'=>$mutating_methods,
			'examples'=>[
				'secret_bearing_examples_allowed'=>false,
				'use_placeholders_for'=>['Authorization', 'Cookie', 'api_key', 'token', 'signed_url', 'tenant_private_identifier'],
				'redaction_default'=>'omit_secrets_from_docs_tests_and_mcp_output',
			],
			'openapi'=>[
				'runtime_generation_performed'=>false,
				'publish_requires_app_owned_review'=>true,
				'secret_bearing_examples_allowed'=>false,
				'minimum_contract'=>['summary', 'operationId', 'tags', 'parameters', 'request schema for mutating methods', 'jsonResponse schemas'],
			],
			'cache_trace'=>[
				'cache_clear_performed'=>false,
				'trace_collection_performed'=>false,
				'cache_identity_required_when_cache_enabled'=>true,
				'trace_payload_redaction_required'=>true,
			],
			'runtime_not_performed'=>[
				'route_dispatch',
				'endpoint_handler_execution',
				'OpenAPI_runtime_generation',
				'endpoint_cache_clear',
				'SQL_execution',
				'application_bootstrap',
			],
			'verification_focuses'=>[
				'static_endpoint_declaration',
				'route_manifest_preview_without_dispatch',
				'url_preview_without_dispatch',
				'PHP_lint_after_app_owned_writes',
			],
			'not_required'=>[
				'MCP/release-surface publication validation for ordinary API endpoint planning',
				'Dataphyre hot-path benchmark evidence',
				'runtime endpoint dispatch from MCP',
			],
		];
	}

	/**
	 * Returns API implementation recipes for MCP callers.
	 *
	 * recipes package common API patterns, required docs, expected files,
	 * and verification surfaces as static planning data so agents can choose a
	 * route before editing code.
	 */
	private function api_recipe_catalog(array $args): array {
		$recipes=[
			'read_resource'=>[
				'title'=>'Read resource endpoint',
				'best_for'=>'GET endpoints that fetch one record or collection and publish a stable OpenAPI response.',
				'endpoint_shape'=>['Api::get(...)', 'pathParameter(...) or queryParameter(...)', 'schema(...)', 'execute(...)', 'jsonResponse(...)'],
				'optional_surfaces'=>['auth(SecurityScheme::jwtGuard())', 'withQueryIdentity(...)', 'cache(...)', 'withTrace(...)'],
				'verification'=>['dataphyre_api_docs_static_summary', 'dataphyre_route_manifest_read', 'dataphyre_route_url_preview', 'dataphyre_php_lint'],
			],
			'create_resource'=>[
				'title'=>'Create or mutate resource endpoint',
				'best_for'=>'POST, PUT, PATCH, or DELETE endpoints with JSON request bodies and explicit success/error responses.',
				'endpoint_shape'=>['Api::post/put/patch/delete(...)', 'jsonBody(...)', 'schema(...)', 'auth(...)', 'execute(...)', 'jsonResponse(201 or 200, ...)'],
				'optional_surfaces'=>['response(400/401/403/404/422, ...)', 'withTrace(true, include_sql)', 'beforeExecute/afterExecute/onError hooks'],
				'verification'=>['dataphyre_api_docs_static_summary', 'dataphyre_route_source_static_summary', 'dataphyre_php_lint'],
			],
			'profiled_api'=>[
				'title'=>'Profiled or grouped API',
				'best_for'=>'Versioned, mobile, partner, or developer APIs that share prefix, tags, auth, trace, or dispatch defaults.',
				'endpoint_shape'=>['Api::profile(name, options) or Api::group(options)', 'prefix', 'tag', 'auth/authAll', 'withTrace', 'dispatchDefaults'],
				'optional_surfaces'=>['aliases(...)', 'profile(...) metadata', 'SecurityScheme::apiKey(...)', 'SecurityScheme::oauth2(...)'],
				'verification'=>['dataphyre_api_docs_static_summary', 'dataphyre_openapi_static_contract_summary', 'dataphyre_route_manifest_read'],
			],
			'binding_dashboard'=>[
				'title'=>'Binding-backed dashboard endpoint',
				'best_for'=>'Read endpoints that combine query/search snapshots or request-shaped bindings into a predictable payload.',
				'endpoint_shape'=>['withBinding(...)', 'withBindings(...)', 'withQueryIdentity(...)', 'withSearchIdentity(...)', 'execute(...)'],
				'optional_surfaces'=>['cache(... inherit_binding_cache_names)', 'withTrace(true, include_bindings)', 'jsonResponse(...)'],
				'verification'=>['dataphyre_api_cache_static_summary', 'dataphyre_api_docs_static_summary', 'dataphyre_php_lint'],
			],
			'cached_trace'=>[
				'title'=>'Cached and traced read endpoint',
				'best_for'=>'Expensive read endpoints where replay and observability matter and cache identity is explicit.',
				'endpoint_shape'=>['cache(ttl, names/vary_headers/vary_cookies)', 'withTrace(true, include_bindings/include_sql)', 'jsonResponse(...)'],
				'optional_surfaces'=>['allow_untracked_bindings only when intentional', 'inherit_binding_cache_names', 'store_errors for rare explicit cases'],
				'verification'=>['dataphyre_api_cache_static_summary', 'dataphyre_diagnostics_last_error', 'dataphyre_tracelog_search'],
			],
			'controller_backed'=>[
				'title'=>'Controller-backed endpoint',
				'best_for'=>'Bridging an API route to an existing controller action while still publishing OpenAPI metadata.',
				'endpoint_shape'=>['Api::get/post(..., ControllerAction::static(...))', 'summary/tag', 'jsonBody for mutating methods', 'jsonResponse(...)'],
				'optional_surfaces'=>['middleware from routing layer', 'auth/security metadata', 'response(...) error contracts'],
				'verification'=>['dataphyre_controller_source_summary', 'dataphyre_route_source_static_summary', 'dataphyre_api_docs_static_summary'],
			],
		];
		$selected=trim((string)($args['recipe'] ?? ''));
		if($selected!==''){
			if(!isset($recipes[$selected])){
				throw new InvalidArgumentException('Unknown API recipe: '.$selected);
			}
			$recipes=[$selected=>$recipes[$selected]];
		}
		return [
			'catalog_type'=>'api_recipe_catalog',
			'write_policy'=>'dry_run_only',
			'execution'=>'not_executed',
			'selected_recipe'=>$selected!=='' ? $selected : null,
			'recipe_count'=>count($recipes),
			'recommended_docs'=>[
				'common/dataphyre/runtime/modules/api/documentation/Dataphyre_Api.md',
				'common/dataphyre/runtime/modules/routing/documentation/Dataphyre_Routing.md',
				'common/dataphyre/runtime/modules/http/documentation/Dataphyre_HTTP.md',
			],
			'optional_guidance_docs'=>[
				'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md',
			],
			'recipes'=>$recipes,
			'common_steps'=>[
				'Inspect existing endpoint declarations with dataphyre_api_docs_static_summary before choosing a local pattern.',
				'Keep route declarations, handler classes, request schemas, response schemas, auth, cache, and trace choices explicit.',
				'Use dataphyre_api_scaffold_plan to turn a chosen recipe into a feature-specific dry-run file and verification plan.',
				'Verify with static MCP tools first; runtime OpenAPI generation, endpoint dispatch, cache clearing, and file writes require separate explicit workflows.',
			],
			'guardrails'=>[
				'Recipes do not write files, generate OpenAPI, dispatch endpoints, clear cache, execute SQL, or bootstrap an application.',
				'Do not put tokens, API keys, cookies, auth headers, or signed secrets into generated examples or MCP output.',
				'Use application-neutral placeholders such as applications/<app> in shared recipes.',
			],
		];
	}

	/**
	 * Summarizes API cache contracts from static source.
	 *
	 * the summary reads API and SQL framework files to identify cache
	 * invalidation surfaces and bridge behavior without executing API endpoints,
	 * SQL queries, or cache operations.
	 */
	private function api_cache_static_summary(): array {
		$files=[
			'facade'=>'common/dataphyre/runtime/modules/api/Framework/Api.php',
			'manager'=>'common/dataphyre/runtime/modules/api/Framework/ApiManager.php',
			'endpoint'=>'common/dataphyre/runtime/modules/api/Framework/Endpoint.php',
			'documentation'=>'common/dataphyre/runtime/modules/api/documentation/Dataphyre_Api.md',
		];
		$sources=[];
		foreach($files as $key=>$relative){
			$path=$this->root.'/'.$relative;
			$sources[$key]=is_file($path) ? (string)file_get_contents($path) : '';
		}
		$manager_methods=['clearEndpointCache', 'normalizeEndpointCacheNames', 'loadCachedEndpointResponse', 'storeEndpointCacheResponse', 'indexEndpointCacheName', 'apiEndpointCacheTracePayload', 'isEndpointResponseCacheable', 'responseForEndpointCacheStorage', 'endpointCacheRoot'];
		$present_methods=[];
		foreach($manager_methods as $method){
			if(str_contains($sources['manager'], 'function '.$method.'(')){
				$present_methods[]=$method;
			}
		}
		$cache_options=['names', 'vary_headers', 'vary_cookies', 'store_errors', 'allow_untracked_bindings', 'inherit_binding_cache_names', 'identity'];
		$documented_options=[];
		foreach($cache_options as $option){
			if(str_contains($sources['documentation'], "'".$option."'") || str_contains($sources['documentation'], $option)){
				$documented_options[]=$option;
			}
		}
		return [
			'summary_type'=>'api_endpoint_cache_static_contract',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'storage_touched'=>false,
			'cache_clear_executed'=>false,
			'sources'=>array_map(fn(string $relative): array => [
				'path'=>$relative,
				'exists'=>is_file($this->root.'/'.$relative),
			], $files),
			'endpoint_builder_contract'=>[
				'surface'=>'Endpoint::cache(int|float|string $ttl=300, array $options=[]): self',
				'detected'=>str_contains($sources['endpoint'], 'function cache('),
				'ttl'=>'Normalized to an integer minimum of 1 second before metadata is compiled.',
				'options'=>$cache_options,
				'metadata_field'=>'api.cache on compiled endpoint metadata',
			],
			'clear_cache_contract'=>[
				'surface'=>'Api::clearEndpointCache(string ...$names): int',
				'facade_detected'=>str_contains($sources['facade'], 'function clearEndpointCache('),
				'manager_detected'=>str_contains($sources['manager'], 'function clearEndpointCache('),
				'behavior_static_summary'=>[
					'No names clears persistent endpoint cache item and name-index directories.',
					'Named clears use normalized cache names, hashed name index files, and item cache keys referenced by the index.',
					'The returned integer is the count of deleted item cache files reported by the manager.',
				],
			],
			'identity_contract'=>[
				'identity_inputs'=>['endpoint path', 'operation id', 'HTTP method', 'profile', 'request path', 'query', 'body', 'route parameters', 'selected vary headers', 'selected vary cookies', 'auth context', 'binding identities', 'extra identity option'],
				'binding_safety'=>'Endpoints bypass caching by default when a binding does not expose cache identity, unless allow_untracked_bindings is explicitly true.',
				'name_inheritance'=>'Binding query cache names are inherited unless inherit_binding_cache_names is explicitly false.',
			],
			'persistent_layout_contract'=>[
				'root'=>'ROOTPATH[dataphyre]/cache/api/endpoints/',
				'items'=>'items/<sha1 identity>.cache',
				'names'=>'names/<sha1 cache name>.json',
				'payload'=>'Serialized stored_at, expires_at, names, response status, response headers, and response body.',
			],
			'trace_contract'=>[
				'surface'=>'apiEndpointCacheTracePayload(...)',
				'states'=>['bypass', 'miss', 'hit', 'store'],
				'fields'=>['enabled', 'cacheable', 'state', 'layer', 'key', 'ttl', 'names', 'source_names', 'reason', 'stored_at'],
				'trace_header_note'=>'Trace headers and trace body keys are stripped before persistent response storage when trace is enabled.',
			],
			'response_cacheability'=>[
				'default'=>'Only 2xx responses are cacheable.',
				'store_errors'=>'When store_errors is true, non-2xx responses can be persisted.',
			],
			'manager_methods_detected'=>$present_methods,
			'documented_options_detected'=>$documented_options,
			'guardrails'=>[
				'This tool does not read, write, delete, list, or clear endpoint cache storage.',
				'This tool does not dispatch endpoints, bootstrap an application, resolve bindings, or generate OpenAPI.',
				'Any future cache clearing workflow must be unsafe-gated and audited separately.',
			],
		];
	}

	/**
	 * Summarizes OpenAPI-related static contracts.
	 *
	 * this read-only contract surface inspects local source and docs for
	 * OpenAPI support, generation expectations, and verification hints while
	 * avoiding route dispatch or schema generation side effects.
	 */
	private function open_api_static_contract_summary(): array {
		$files=[
			'facade'=>'common/dataphyre/runtime/modules/api/Framework/Api.php',
			'manager'=>'common/dataphyre/runtime/modules/api/Framework/ApiManager.php',
			'generator'=>'common/dataphyre/runtime/modules/api/Framework/OpenApiGenerator.php',
			'openapi_controller'=>'common/dataphyre/runtime/modules/api/Framework/OpenApiController.php',
			'swagger_controller'=>'common/dataphyre/runtime/modules/api/Framework/SwaggerUiController.php',
			'documentation'=>'common/dataphyre/runtime/modules/api/documentation/Dataphyre_Api.md',
		];
		$sources=[];
		foreach($files as $key=>$relative){
			$path=$this->root.'/'.$relative;
			$sources[$key]=is_file($path) ? (string)file_get_contents($path) : '';
		}
		$generator_methods=['generate', 'buildInfo', 'buildOperation', 'normalizeResponses', 'normalizeMethods', 'normalizeServers'];
		$present_generator_methods=[];
		foreach($generator_methods as $method){
			if(str_contains($sources['generator'], 'function '.$method.'(')){
				$present_generator_methods[]=$method;
			}
		}
		$documentation_defaults=[
			'docs_path'=>'/_framework/api/docs',
			'spec_path'=>'/_framework/api/openapi.json',
			'asset_path'=>'/_framework/api/assets',
			'version'=>'1.0.0',
			'swagger_ui_css'=>'https://unpkg.com/swagger-ui-dist@5/swagger-ui.css',
			'swagger_ui_bundle_js'=>'https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js',
			'swagger_ui_preset_js'=>'https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js',
		];
		return [
			'summary_type'=>'openapi_static_contract',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'application_bootstrap'=>'not_performed',
			'route_dispatch'=>'not_performed',
			'openapi_generated'=>'not_generated',
			'sources'=>array_map(fn(string $relative): array => [
				'path'=>$relative,
				'exists'=>is_file($this->root.'/'.$relative),
			], $files),
			'facade_contract'=>[
				'openapi_surface'=>'Api::openApiDocument(?string $application_id=null, array $options=[]): array',
				'documentation_routes_surface'=>'Api::documentationRoutes(array $options=[]): array',
				'openapi_detected'=>str_contains($sources['facade'], 'function openApiDocument('),
				'documentation_routes_detected'=>str_contains($sources['facade'], 'function documentationRoutes('),
			],
			'documentation_routes_contract'=>[
				'manager_surface'=>'ApiManager::documentationRoutes(array $options=[]): array',
				'detected'=>str_contains($sources['manager'], 'function documentationRoutes('),
				'compiled_routes'=>[
					['method'=>'GET', 'path_option'=>'spec_path', 'default_path'=>$documentation_defaults['spec_path'], 'controller'=>'Dataphyre\\Api\\OpenApiController::show'],
					['method'=>'GET', 'path_option'=>'docs_path', 'default_path'=>$documentation_defaults['docs_path'], 'controller'=>'Dataphyre\\Api\\SwaggerUiController::show'],
					['method'=>'GET,HEAD', 'path_option'=>'asset_path', 'default_path'=>$documentation_defaults['asset_path'].'/{asset}', 'controller'=>'Dataphyre\\Api\\SwaggerUiController::asset'],
				],
				'defaults'=>$documentation_defaults,
				'path_normalization'=>'docs_path, spec_path, and asset_path are normalized to leading-slash paths.',
				'route_metadata'=>'Compiled documentation routes carry api_docs options for controllers.',
			],
			'openapi_document_contract'=>[
				'manager_surface'=>'ApiManager::openApiDocument(?string $application_id=null, array $options=[]): array',
				'detected'=>str_contains($sources['manager'], 'function openApiDocument('),
				'input_source'=>'discoverApplication(...) endpoint metadata from compiled application route manifests.',
				'generator'=>'OpenApiGenerator::generate(array $endpoints, array $options=[]): array',
				'generator_detected'=>str_contains($sources['generator'], 'function generate('),
				'openapi_version'=>'3.1.0',
				'root_fields'=>['openapi', 'info', 'paths', 'servers when present', 'components.securitySchemes when present'],
				'operation_fields'=>['responses', 'summary', 'description', 'operationId', 'tags', 'deprecated', 'parameters', 'requestBody', 'security', 'servers', 'x-dataphyre-method'],
				'method_allowlist'=>['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],
			],
			'controller_contract'=>[
				'openapi_controller'=>[
					'surface'=>'OpenApiController::show(Request $request, array $route): Response',
					'detected'=>str_contains($sources['openapi_controller'], 'function show('),
					'content_type'=>'application/vnd.oai.openapi+json; charset=utf-8',
					'failure_shape'=>['ok'=>false, 'error'=>'Failed generating the OpenAPI document.', 'status'=>500],
				],
				'swagger_controller'=>[
					'surfaces'=>['SwaggerUiController::show(...)', 'SwaggerUiController::asset(...)'],
					'detected'=>str_contains($sources['swagger_controller'], 'function show(') && str_contains($sources['swagger_controller'], 'function asset('),
					'local_assets'=>['swagger-shell.css', 'swagger-init.js'],
					'asset_headers'=>['Cache-Control', 'ETag', 'Last-Modified', 'Vary', 'X-Content-Type-Options'],
				],
			],
			'generator_methods_detected'=>$present_generator_methods,
			'publish_guidance'=>[
				'Use Api::documentationRoutes(...) to publish spec, UI, and asset routes through normal route manifests.',
				'Use Api::openApiDocument(...) when code needs the OpenAPI array directly inside a safe application runtime.',
				'Use dataphyre_api_docs_static_summary for MCP-side source inspection when runtime bootstrap is not allowed.',
			],
			'guardrails'=>[
				'This tool does not call Api::openApiDocument, discoverApplication, discoverManifest, documentationRoutes, or controller methods.',
				'This tool does not bootstrap an application, dispatch routes, read compiled route manifests, fetch remote Swagger assets, or write OpenAPI files.',
				'Runtime OpenAPI reads should remain a separate unsafe or explicitly bootstrapped workflow if added later.',
			],
		];
	}

	/**
	 * Plans safe readiness checks for runtime OpenAPI support.
	 *
	 * the plan names preconditions, allowed outputs, denied outputs, and
	 * verification steps for any future runtime OpenAPI reader without executing
	 * application routes or generating artifacts.
	 */
	private function open_api_runtime_readiness_plan(array $args): array {
		$application_id=trim((string)($args['application_id'] ?? '<app>'));
		if($application_id===''){
			$application_id='<app>';
		}
		$static=$this->open_api_static_contract_summary();
		return [
			'plan_type'=>'openapi_runtime_readiness_plan',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'application_bootstrap'=>'not_performed',
			'route_dispatch'=>'not_performed',
			'openapi_generated'=>'not_generated',
			'application_id'=>$application_id,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('openapi_runtime_readiness'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('openapi_runtime_readiness'),
			'current_safe_surfaces'=>[
				'static_contract'=>'dataphyre_openapi_static_contract_summary',
				'api_declarations'=>'dataphyre_api_docs_static_summary',
				'route_source'=>'dataphyre_route_source_static_summary',
				'scaffold_plan'=>'dataphyre_api_scaffold_plan',
				'recipe_catalog'=>'dataphyre_api_recipe_catalog',
			],
			'detected_static_contract'=>[
				'facade_openapi_detected'=>($static['facade_contract']['openapi_detected'] ?? false)===true,
				'manager_openapi_detected'=>($static['openapi_document_contract']['detected'] ?? false)===true,
				'openapi_version'=>$static['openapi_document_contract']['openapi_version'] ?? null,
				'documentation_spec_path'=>$static['documentation_routes_contract']['defaults']['spec_path'] ?? null,
				'controller_content_type'=>$static['openapi_document_contract']['openapi_controller']['content_type'] ?? null,
			],
			'future_runtime_reader_preconditions'=>[
				'unsafe opt-in must be explicit and visible in the call envelope',
				'application bootstrap boundary must be named and product-neutral',
				'bootstrap must not dispatch user routes or execute endpoint handlers',
				'OpenAPI generation must return a bounded array or JSON string only',
				'secrets, auth headers, cookies, environment values, and config credentials must be redacted',
				'errors must return bounded diagnostic summaries, not raw stack traces with local secrets',
			],
			'allowed_future_outputs'=>[
				'openapi version',
				'info title/version',
				'path and operation counts',
				'operation ids, methods, tags, parameter names, response status codes',
				'component schema names and security scheme names',
				'bounded validation warnings',
			],
			'denied_future_outputs'=>[
				'live endpoint responses',
				'route dispatch results',
				'database query results',
				'config secret values',
				'raw request cookies or auth headers',
				'local product-specific scripts or binary paths in shared MCP metadata',
			],
			'audit_envelope_required_fields'=>[
				'tool_name',
				'application_id',
				'unsafe_enabled',
				'bootstrap_boundary',
				'static_preflight_tools',
				'redaction_policy',
				'output_bounds',
				'verification_steps',
			],
			'client_steps'=>[
				'Use dataphyre_openapi_static_contract_summary to confirm the OpenAPI generator and docs route contracts.',
				'Use dataphyre_api_docs_static_summary to inspect endpoint declarations without bootstrapping an app.',
				'Use dataphyre_route_source_static_summary or compiled route manifest readers to verify route provenance.',
				'Only consider a future runtime reader after the bootstrap boundary can enforce no dispatch, no SQL, bounded output, and redaction.',
				'Run dataphyre_mcp_verify_all before publishing any runtime OpenAPI reader capability.',
			],
			'safety_notes'=>[
				'This plan does not call Api::openApiDocument, bootstrap an application, dispatch routes, fetch Swagger assets, or write OpenAPI files.',
				'Runtime OpenAPI reads remain intentionally outside default read-only MCP behavior.',
				'Keep shared MCP plans application-neutral and use caller-provided application identifiers only as placeholders.',
			],
		];
	}

}
