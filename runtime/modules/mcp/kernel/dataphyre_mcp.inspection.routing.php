<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP route inspection surfaces.
 */
trait dataphyre_mcp_inspection_routing_surfaces {

	/**
	 * Lists likely route artifact files from the repository.
	 *
	 * The scan is bounded by the caller limit, searches PHP and JSON files whose
	 * relative path mentions routes, and returns relative paths only so MCP clients
	 * do not receive host-specific absolute filesystem paths.
	 *
	 * @param int $limit Maximum number of artifacts to return.
	 * @return array{route_artifacts: array<int, string>} Relative route artifact paths.
	 */
	private function list_route_artifacts(int $limit): array {
		$limit=max(1, min($limit ?: 30, 100));
		$files=[];
		foreach($this->all_files($this->root, $limit * 20) as $path){
			$relative=$this->relative_path($path);
			$lower=strtolower($relative);
			if(str_contains($lower, 'route') && preg_match('/\.(php|json)$/', $lower)){
				$files[]=$relative;
			}
			if(count($files)>=$limit){
				break;
			}
		}
		return ['route_artifacts'=>$files];
	}

	/**
	 * Describes reusable route/API metadata safety boundaries for application agents.
	 *
	 * @param string $surface Route or API metadata surface label.
	 * @return array<string,mixed> Route safety metadata for app-agent consumption.
	 */
	private function route_safety_contract(string $surface): array {
		return [
			'surface'=>$surface,
			'classification'=>'route_api_metadata_only',
			'application_default'=>'safe_for_application_planning_without_dispatch',
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('route_'.$surface),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('route_'.$surface),
			'allowed_for_app_agents'=>[
				'compiled route manifest metadata',
				'relative URL previews and dry route matches',
				'static route declarations and handler strings',
				'controller and middleware signatures or provenance',
				'route cache command contracts without invoking commands',
			],
			'not_performed'=>[
				'application bootstrap',
				'route dispatch',
				'middleware execution',
				'controller invocation',
				'endpoint execution',
				'OpenAPI runtime generation',
				'route cache writes',
				'HTTP requests',
			],
			'governance_trigger'=>'Use heavier governance only for corporate-ready, security-sensitive, auth/session/access-policy/tenant/privacy/compliance, release-facing, or Dataphyre-maintainer claims.',
		];
	}

	/**
	 * Reads a compiled route manifest through the routing compiler without bootstrapping or dispatching an application.
	 *
	 * accepts a repo-local manifest path plus optional bounds for route entries, handler visibility,
	 * and middleware visibility. The returned payload preserves route metadata needed for provenance work while keeping
	 * request handling, middleware execution, controller invocation, and cache writes outside this read-only surface.
	 *
	 * @param array{manifest_path?: string, limit?: int, include_handlers?: bool, include_middleware?: bool} $args Manifest reader options.
	 * @return array{manifest_path: string, version: mixed, metadata: array, route_count: int, routes: array<int, array>} Bounded manifest summary.
	 */
	private function read_route_manifest(array $args): array {
		$this->bootstrap_routing();
		$path=$this->safe_repo_path((string)($args['manifest_path'] ?? ''));
		$manifest=\Dataphyre\Routing\RouteCompiler::readManifestFile($path);
		$limit=max(1, min((int)($args['limit'] ?? 50) ?: 50, 500));
		$include_handlers=(bool)($args['include_handlers'] ?? false);
		$include_middleware=(bool)($args['include_middleware'] ?? false);
		$routes=[];
		foreach(array_slice($manifest['routes'] ?? [], 0, $limit) as $route){
			if(!is_array($route)){
				continue;
			}
			$entry=[
				'name'=>$route['name'] ?? null,
				'methods'=>$route['methods'] ?? [],
				'path'=>$route['path'] ?? null,
				'exact_path'=>$route['exact_path'] ?? null,
				'domain'=>$route['domain'] ?? null,
				'metadata'=>is_array($route['metadata'] ?? null) ? $route['metadata'] : [],
			];
			if($include_handlers){
				$entry['handler']=$route['handler'] ?? null;
			}
			if($include_middleware){
				$entry['middleware']=$route['middleware'] ?? [];
			}
			$routes[]=$entry;
		}
		return [
			'manifest_path'=>$this->relative_path($path),
			'version'=>$manifest['version'] ?? null,
			'metadata'=>is_array($manifest['metadata'] ?? null) ? $manifest['metadata'] : [],
			'route_count'=>count($manifest['routes'] ?? []),
			'route_safety'=>$this->route_safety_contract('compiled_route_manifest'),
			'routes'=>$routes,
		];
	}

	/**
	 * Builds a named-route URL preview from a compiled manifest and optional caller-supplied base URL.
	 *
	 * parameter and query arrays are passed to the manifest URL generator, then the optional absolute
	 * URL is assembled only after validating the base URL as http(s). The function does not load route files, resolve
	 * application config, invoke handlers, or infer host data from runtime state.
	 *
	 * @param array{manifest_path?: string, name?: string, parameters?: array, query?: array, base_url?: string} $args URL preview inputs.
	 * @return array{manifest_path: string, name: string, url: string, base_url?: string, absolute_url?: string} Named route preview.
	 *
	 * @throws InvalidArgumentException When the route name is missing or the optional base URL is invalid.
	 */
	private function preview_route_url(array $args): array {
		$this->bootstrap_routing();
		$path=$this->safe_repo_path((string)($args['manifest_path'] ?? ''));
		$name=trim((string)($args['name'] ?? ''));
		if($name===''){
			throw new InvalidArgumentException('name is required.');
		}
		$manifest=\Dataphyre\Routing\RouteCompiler::readManifestFile($path);
		$parameters=is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
		$query=is_array($args['query'] ?? null) ? $args['query'] : [];
		$url=\Dataphyre\Routing\RouteManifest::namedUrl($manifest, $name, $parameters, $query);
		$base_url=trim((string)($args['base_url'] ?? ''));
		$result=[
			'manifest_path'=>$this->relative_path($path),
			'name'=>$name,
			'url'=>$url,
			'route_safety'=>$this->route_safety_contract('named_route_url_preview'),
		];
		if($base_url!==''){
			$result['base_url']=$this->normalize_http_base_url($base_url);
			$result['absolute_url']=$this->absolute_url_preview($result['base_url'], $url);
		}
		return $result;
	}

	/**
	 * Normalizes a caller-provided base URL for safe URL preview composition.
	 *
	 * accepts only absolute HTTP or HTTPS origins and trims trailing slashes so downstream preview
	 * assembly cannot silently accept filesystem paths, schemeless fragments, or product-specific local defaults.
	 *
	 * @param string $base_url Candidate absolute URL.
	 * @return string Canonical base URL without a trailing slash.
	 *
	 * @throws InvalidArgumentException When the URL is not an absolute http(s) URL.
	 */
	private function normalize_http_base_url(string $base_url): string {
		$base_url=rtrim(trim($base_url), '/');
		$parts=parse_url($base_url);
		$scheme=strtolower((string)($parts['scheme'] ?? ''));
		if(!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])){
			throw new InvalidArgumentException('base_url must be an absolute http(s) URL.');
		}
		return $base_url;
	}

	/**
	 * Combines a validated base URL with a generated route URL while preserving already absolute URLs.
	 *
	 * protocol-relative URLs inherit the validated base scheme, absolute URLs pass through unchanged,
	 * and relative paths are joined with exactly one slash. No request context or server globals are consulted.
	 *
	 * @param string $base_url Validated absolute base URL.
	 * @param string $url Generated route URL or path fragment.
	 * @return string Absolute preview URL.
	 */
	private function absolute_url_preview(string $base_url, string $url): string {
		if(preg_match('#^https?://#i', $url)===1){
			return $url;
		}
		if(str_starts_with($url, '//')){
			$scheme=(string)(parse_url($base_url, PHP_URL_SCHEME) ?: 'https');
			return $scheme.':'.$url;
		}
		return rtrim($base_url, '/').'/'.ltrim($url, '/');
	}

	/**
	 * Matches a request path against compiled manifest routes without dispatching the matched route.
	 *
	 * the compiled dispatcher is used only for route selection and extracted parameters. Handler and
	 * middleware fields remain opt-in so provenance tools can avoid leaking callable details unless the caller asks for
	 * them explicitly.
	 *
	 * @param array{manifest_path?: string, method?: string, path?: string, host?: string, include_handler?: bool, include_middleware?: bool} $args Match preview inputs.
	 * @return array{manifest_path: string, matched: bool, name?: mixed, methods?: array, path?: mixed, domain?: mixed, parameters?: array, metadata?: array, handler?: mixed, middleware?: array} Match summary.
	 *
	 * @throws InvalidArgumentException When the request path is missing.
	 */
	private function preview_route_match(array $args): array {
		$this->bootstrap_routing();
		$path=$this->safe_repo_path((string)($args['manifest_path'] ?? ''));
		$request_path=trim((string)($args['path'] ?? ''));
		if($request_path===''){
			throw new InvalidArgumentException('path is required.');
		}
		$manifest=\Dataphyre\Routing\RouteCompiler::readManifestFile($path);
		$routes=is_array($manifest['routes'] ?? null) ? $manifest['routes'] : [];
		$route=\dataphyre\routing\compiled_route_dispatcher::match_routes_for_request(
			$routes,
			(string)($args['method'] ?? 'GET'),
			$request_path,
			isset($args['host']) ? (string)$args['host'] : null
		);
		if($route===null){
			return [
				'manifest_path'=>$this->relative_path($path),
				'matched'=>false,
				'route_safety'=>$this->route_safety_contract('compiled_route_match_preview'),
			];
		}
		$result=[
			'manifest_path'=>$this->relative_path($path),
			'matched'=>true,
			'route_safety'=>$this->route_safety_contract('compiled_route_match_preview'),
			'name'=>$route['name'] ?? null,
			'methods'=>$route['methods'] ?? [],
			'path'=>$route['path'] ?? $route['exact_path'] ?? null,
			'domain'=>$route['domain'] ?? $route['exact_domain'] ?? null,
			'parameters'=>is_array($route['parameters'] ?? null) ? $route['parameters'] : [],
			'metadata'=>is_array($route['metadata'] ?? null) ? $route['metadata'] : [],
		];
		if((bool)($args['include_handler'] ?? false)){
			$result['handler']=$route['handler'] ?? null;
		}
		if((bool)($args['include_middleware'] ?? false)){
			$result['middleware']=$route['middleware'] ?? [];
		}
		return $result;
	}

	/**
	 * Summarizes route declarations from source files using token-level literal extraction.
	 *
	 * scans bounded repo-local PHP roots, skips documentation and vendor paths, and reports only
	 * literal route surfaces. Route files are never required, groups are never executed, and handler expressions are
	 * compacted as source provenance rather than treated as runtime truth.
	 *
	 * @param array{paths?: array<int, string>, limit?: int} $args Optional scan roots and file limit.
	 * @return array{write_policy: string, execution: string, scanned_files: int, declaration_count: int, surface_count: int, declarations: array, surfaces: array, notes: array<int, string>} Source route inventory.
	 */
	private function route_source_static_summary(array $args): array {
		$limit=max(1, min((int)($args['limit'] ?? 80) ?: 80, 250));
		$roots=[];
		if(is_array($args['paths'] ?? null) && $args['paths']!==[]){
			foreach($args['paths'] as $path){
				$roots[]=(string)$path;
			}
		}else{
			$roots[]='common/dataphyre/runtime/modules/routing';
			$roots[]='common/dataphyre/runtime/modules/mvc';
		}
		$files=[];
		foreach($roots as $root){
			$safe=$this->safe_repo_path($root);
			if(is_file($safe)){
				if(strtolower(pathinfo($safe, PATHINFO_EXTENSION))==='php'){
					$files[]=$safe;
				}
				continue;
			}
			if(is_dir($safe)){
				foreach($this->all_files($safe, $limit * 8) as $file){
					if(strtolower(pathinfo($file, PATHINFO_EXTENSION))!=='php'){
						continue;
					}
					$relative=strtolower(str_replace('\\', '/', $this->relative_path($file)));
					if(str_contains($relative, '/documentation/') || str_contains($relative, '/vendor/')){
						continue;
					}
					$files[]=$file;
					if(count($files)>=$limit){
						break 2;
					}
				}
			}
			if(count($files)>=$limit){
				break;
			}
		}
		$files=array_slice(array_values(array_unique($files)), 0, $limit);
		$declarations=[];
		$surfaces=[];
		foreach($files as $file){
			$summary=$this->route_declarations_from_file($file);
			$declarations=array_merge($declarations, $summary['declarations']);
			$surfaces=array_merge($surfaces, $summary['surfaces']);
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'route_safety'=>$this->route_safety_contract('source_route_static_summary'),
			'scanned_files'=>count($files),
			'declaration_count'=>count($declarations),
			'surface_count'=>count($surfaces),
			'declarations'=>array_slice($declarations, 0, 300),
			'surfaces'=>array_slice($surfaces, 0, 120),
			'notes'=>[
				'Only literal string paths, prefixes, resources, names, domains, and middleware are reported.',
				'Handlers are compact source expressions and are not invoked.',
				'Route groups and closures are not executed; this is source provenance only.',
			],
		];
	}

	/**
	 * Extracts literal route declarations and route-related API surfaces from one PHP file.
	 *
	 * token parsing recognizes verb helpers, route builders, chain metadata, and MVC route cache
	 * surfaces while keeping expressions compact and non-executable. Dynamic values are omitted or summarized rather
	 * than evaluated, preserving the static provenance boundary.
	 *
	 * @param string $path Absolute path to a repo-local PHP file already selected by the caller.
	 * @return array{declarations: array<int, array>, surfaces: array<int, array>} Route declaration and API-surface findings.
	 */
	private function route_declarations_from_file(string $path): array {
		$text=(string)file_get_contents($path);
		$tokens=token_get_all($text);
		$declarations=[];
		$surfaces=[];
		$verbs=['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'any'];
		$route_methods=array_merge($verbs, ['match', 'view', 'redirect', 'permanentredirect', 'fallback', 'resource', 'prefix', 'name', 'middleware', 'domain', 'group']);
		for($i=0, $count=count($tokens); $i<$count; $i++){
			$token=$tokens[$i];
			if(!is_array($token) || $token[0]!==T_STRING){
				continue;
			}
			$name=strtolower($token[1]);
			if(in_array($name, ['compile', 'manifestcachefile', 'manifestcacheenabled', 'routes'], true)){
				$surfaces[]=[
					'file'=>$this->relative_path($path),
					'line'=>$token[2] ?? null,
					'call'=>$this->api_call_target_kind($tokens, $i),
					'surface'=>$name,
				];
				continue;
			}
			if(!in_array($name, $route_methods, true)){
				continue;
			}
			$args=$this->call_arguments_after_token($tokens, $i);
			if($args===null){
				continue;
			}
			$path_arg=null;
			$methods=[];
			$type='route';
			if(in_array($name, $verbs, true)){
				$path_arg=$args[0] ?? null;
				$methods=[strtoupper($name)];
			}elseif($name==='match'){
				$methods=$this->literal_string_list_from_expression($args[0] ?? '');
				$path_arg=$args[1] ?? null;
			}elseif(in_array($name, ['view', 'redirect', 'permanentredirect', 'fallback'], true)){
				$path_arg=$args[0] ?? null;
				$methods=$name==='fallback' ? ['ANY'] : ['GET'];
				$type=$name;
			}elseif($name==='resource'){
				$path_arg=$args[0] ?? null;
				$methods=['RESOURCE'];
				$type='resource';
			}elseif(in_array($name, ['prefix', 'domain', 'name', 'middleware', 'group'], true)){
				$path_arg=$args[0] ?? null;
				$type=$name;
			}
			$literal=$this->literal_string_from_expression((string)($path_arg ?? ''));
			if($literal===null){
				continue;
			}
			$metadata=$this->route_chain_metadata_after_token($tokens, $i);
			$entry=[
				'file'=>$this->relative_path($path),
				'line'=>$token[2] ?? null,
				'call'=>$this->api_call_target_kind($tokens, $i),
				'type'=>$type,
				'method'=>$name,
				'value'=>$literal,
			];
			if($methods!==[]){
				$entry['http_methods']=array_values(array_unique(array_map('strtoupper', $methods)));
			}
			if(isset($args[1]) && !in_array($name, ['match', 'prefix', 'domain', 'name', 'middleware', 'group'], true)){
				$entry['handler']=$this->compact_expression($args[$name==='resource' ? 1 : 1] ?? '', 180);
			}
			foreach(['name', 'domain', 'middleware', 'where'] as $key){
				if(($metadata[$key] ?? [])!==[]){
					$entry[$key]=$metadata[$key];
				}
			}
			$declarations[]=$entry;
		}
		return [
			'declarations'=>$declarations,
			'surfaces'=>$surfaces,
		];
	}

	/**
	 * Reports places where static route extraction cannot prove runtime route provenance.
	 *
	 * scans the same bounded source roots as the static summary but emits uncertainty categories
	 * instead of route declarations. Findings describe extraction limits and recommended follow-up tools; they are not
	 * treated as code defects without compiled manifest or runtime evidence.
	 *
	 * @param array{paths?: array<int, string>, limit?: int} $args Optional scan roots and file limit.
	 * @return array{write_policy: string, execution: string, scanned_files: int, issue_count: int, category_counts: array<string, int>, issues: array, recommended_next_tools: array<int, string>, notes: array<int, string>} Ambiguity report.
	 */
	private function route_source_ambiguity_report(array $args): array {
		$limit=max(1, min((int)($args['limit'] ?? 80) ?: 80, 250));
		$roots=[];
		if(is_array($args['paths'] ?? null) && $args['paths']!==[]){
			foreach($args['paths'] as $path){
				$roots[]=(string)$path;
			}
		}else{
			$roots[]='common/dataphyre/runtime/modules/routing';
			$roots[]='common/dataphyre/runtime/modules/mvc';
		}
		$files=[];
		foreach($roots as $root){
			$safe=$this->safe_repo_path($root);
			if(is_file($safe)){
				if(strtolower(pathinfo($safe, PATHINFO_EXTENSION))==='php'){
					$files[]=$safe;
				}
				continue;
			}
			if(is_dir($safe)){
				foreach($this->all_files($safe, $limit * 8) as $file){
					if(strtolower(pathinfo($file, PATHINFO_EXTENSION))!=='php'){
						continue;
					}
					$relative=strtolower(str_replace('\\', '/', $this->relative_path($file)));
					if(str_contains($relative, '/documentation/') || str_contains($relative, '/vendor/')){
						continue;
					}
					$files[]=$file;
					if(count($files)>=$limit){
						break 2;
					}
				}
			}
			if(count($files)>=$limit){
				break;
			}
		}
		$issues=[];
		foreach(array_slice(array_values(array_unique($files)), 0, $limit) as $file){
			$issues=array_merge($issues, $this->route_ambiguities_from_file($file));
		}
		$category_counts=[];
		foreach($issues as $issue){
			$category=(string)($issue['category'] ?? 'unknown');
			$category_counts[$category]=($category_counts[$category] ?? 0)+1;
		}
		ksort($category_counts);
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'route_safety'=>$this->route_safety_contract('source_route_ambiguity_report'),
			'scanned_files'=>count(array_unique($files)),
			'issue_count'=>count($issues),
			'category_counts'=>$category_counts,
			'issues'=>array_slice($issues, 0, 300),
			'recommended_next_tools'=>[
				'dataphyre_route_source_static_summary',
				'dataphyre_list_routes',
				'dataphyre_route_manifest_read',
				'dataphyre_route_match_preview',
			],
			'notes'=>[
				'This report does not execute route files, closures, controller handlers, middleware, or route groups.',
				'Issues mark places where static literal extraction may be incomplete, not necessarily code defects.',
				'Use compiled route manifests for runtime truth when an application has already built them safely.',
			],
		];
	}

	/**
	 * Describes the safety contract required before any future runtime route provenance reader exists.
	 *
	 * produces an application-neutral plan only. It never bootstraps an application, loads route files,
	 * dispatches requests, executes middleware, writes route caches, or reveals request/config secrets.
	 *
	 * @param array{application_id?: string} $args Optional caller-owned application label.
	 * @return array<string, mixed> Read-only runtime provenance readiness plan.
	 */
	private function route_runtime_provenance_plan(array $args): array {
		$application_id=trim((string)($args['application_id'] ?? '<app>'));
		if($application_id===''){
			$application_id='<app>';
		}
		return [
			'plan_type'=>'route_runtime_provenance_plan',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'application_bootstrap'=>'not_performed',
			'route_dispatch'=>'not_performed',
			'route_cache_written'=>'not_written',
			'application_id'=>$application_id,
			'route_safety'=>$this->route_safety_contract('runtime_route_provenance_plan'),
			'current_safe_surfaces'=>[
				'compiled_manifest_reader'=>'dataphyre_route_manifest_read',
				'route_match_preview'=>'dataphyre_route_match_preview',
				'route_url_preview'=>'dataphyre_route_url_preview',
				'source_summary'=>'dataphyre_route_source_static_summary',
				'ambiguity_report'=>'dataphyre_route_source_ambiguity_report',
				'mvc_config'=>'dataphyre_mvc_config_static_summary',
				'route_cache_contract'=>'dataphyre_mvc_route_cache_summary',
			],
			'future_runtime_reader_preconditions'=>[
				'unsafe opt-in must be explicit and visible in the call envelope',
				'application bootstrap boundary must be named and product-neutral',
				'bootstrap must load route declarations without dispatching request handlers',
				'controller actions, closures, middleware, and model binding resolvers must not be invoked',
				'route cache writes and clear-cache commands must remain separate audited workflows',
				'config secrets, request headers, cookies, and environment values must be redacted',
				'output must be bounded by route count, metadata size, and handler string length',
			],
			'allowed_future_outputs'=>[
				'route names, methods, paths, domains, and parameter names',
				'handler references as redacted strings or class/method pairs',
				'middleware aliases or class names without executing middleware',
				'route source file and line provenance when available',
				'compiled manifest metadata and cache status summaries',
				'bounded ambiguity warnings for dynamic route declarations',
			],
			'denied_future_outputs'=>[
				'route dispatch responses',
				'controller return values',
				'middleware execution results',
				'database query results',
				'config secret values',
				'request cookies, auth headers, or session payloads',
				'product-specific local scripts or binary paths in shared MCP metadata',
			],
			'audit_envelope_required_fields'=>[
				'tool_name',
				'application_id',
				'unsafe_enabled',
				'bootstrap_boundary',
				'static_preflight_tools',
				'dispatch_disabled',
				'cache_write_disabled',
				'redaction_policy',
				'output_bounds',
				'verification_steps',
			],
			'client_steps'=>[
				'Use dataphyre_route_source_static_summary and dataphyre_route_source_ambiguity_report before considering runtime provenance.',
				'Use dataphyre_list_routes and dataphyre_route_manifest_read when compiled manifests already exist.',
				'Use dataphyre_mvc_config_static_summary and dataphyre_mvc_route_cache_summary to understand route source and cache contracts.',
				'Only consider a future runtime provenance reader after bootstrap can enforce no dispatch, no middleware execution, no cache writes, bounded output, and redaction.',
				'Run dataphyre_mcp_verify_all before publishing any runtime route provenance reader capability.',
			],
			'safety_notes'=>[
				'This plan does not bootstrap an application, load app route files, dispatch routes, run middleware, invoke controllers, or write route cache files.',
				'Runtime-only route provenance remains intentionally outside default read-only MCP behavior.',
				'Keep shared MCP plans application-neutral and use caller-provided application identifiers only as placeholders.',
			],
		];
	}

	/**
	 * Detects dynamic route declaration patterns in one PHP source file.
	 *
	 * token-level inspection checks route path values, method lists, handler expressions, and chained
	 * metadata. Expressions are classified and compacted; no route builder object, closure, controller, or middleware is
	 * evaluated.
	 *
	 * @param string $path Absolute path to a PHP source file.
	 * @return array<int, array{file: string, line: mixed, call: string, method: string, category: string, expression: string, impact: string}> Ambiguity findings.
	 */
	private function route_ambiguities_from_file(string $path): array {
		$text=(string)file_get_contents($path);
		$tokens=token_get_all($text);
		$issues=[];
		$verbs=['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'any'];
		$route_methods=array_merge($verbs, ['match', 'view', 'redirect', 'permanentredirect', 'fallback', 'resource', 'prefix', 'name', 'middleware', 'domain', 'group']);
		for($i=0, $count=count($tokens); $i<$count; $i++){
			$token=$tokens[$i];
			if(!is_array($token) || $token[0]!==T_STRING){
				continue;
			}
			$name=strtolower($token[1]);
			if(!in_array($name, $route_methods, true)){
				continue;
			}
			$args=$this->call_arguments_after_token($tokens, $i);
			if($args===null){
				continue;
			}
			$file=$this->relative_path($path);
			$line=$token[2] ?? null;
			$target=$this->api_call_target_kind($tokens, $i);
			if($name==='match' && $this->literal_string_list_from_expression($args[0] ?? '')===[]){
				$issues[]=$this->route_ambiguity_issue($file, $line, $target, $name, 'non_literal_http_methods', $args[0] ?? '');
			}
			$value_index=$name==='match' ? 1 : 0;
			$value_expression=(string)($args[$value_index] ?? '');
			if($value_expression!=='' && $this->literal_string_from_expression($value_expression)===null){
				$issues[]=$this->route_ambiguity_issue($file, $line, $target, $name, 'non_literal_route_value', $value_expression);
			}
			$handler_index=match($name){
				'match'=>2,
				'prefix', 'domain', 'name', 'middleware', 'group'=>null,
				default=>1,
			};
			if($handler_index!==null && isset($args[$handler_index])){
				$handler=(string)$args[$handler_index];
				if(!$this->is_static_route_handler_expression($handler)){
					$issues[]=$this->route_ambiguity_issue($file, $line, $target, $name, 'dynamic_handler_expression', $handler);
				}
			}
			foreach($this->route_chain_ambiguities_after_token($tokens, $i, $file, $target) as $chain_issue){
				$issues[]=$chain_issue;
			}
		}
		return $issues;
	}

	/**
	 * Finds non-literal route chain metadata after a route API token.
	 *
	 * the scan is bounded to the current statement and a fixed token window so chained route metadata
	 * can be audited without accidentally consuming unrelated source or executing fluent route builders.
	 *
	 * @param array<int, mixed> $tokens Token stream from token_get_all().
	 * @param int $index Index of the route API token.
	 * @param string $file Repo-relative source path for reporting.
	 * @param string $target Static call target classification.
	 * @return array<int, array> Chain ambiguity findings.
	 */
	private function route_chain_ambiguities_after_token(array $tokens, int $index, string $file, string $target): array {
		$issues=[];
		for($i=$index+1, $count=count($tokens), $seen=0; $i<$count && $seen<220; $i++, $seen++){
			$token=$tokens[$i];
			if($token===';'){
				break;
			}
			if(!is_array($token) || $token[0]!==T_OBJECT_OPERATOR){
				continue;
			}
			$method_index=$this->next_meaningful_token_index($tokens, $i);
			if($method_index===null || !is_array($tokens[$method_index]) || $tokens[$method_index][0]!==T_STRING){
				continue;
			}
			$method=strtolower($tokens[$method_index][1]);
			if(!in_array($method, ['name', 'domain', 'middleware', 'where', 'wherealpha', 'wherenumber', 'whereuuid', 'whereulid', 'wherein', 'defaults'], true)){
				continue;
			}
			$args=$this->call_arguments_after_token($tokens, $method_index);
			if($args===null || $args===[]){
				continue;
			}
			if($method==='middleware'){
				$literal_count=0;
				foreach($args as $arg){
					if($this->literal_string_from_expression((string)$arg)!==null){
						$literal_count++;
					}
				}
				if($literal_count<count($args)){
					$issues[]=$this->route_ambiguity_issue($file, $tokens[$method_index][2] ?? null, $target, $method, 'non_literal_chain_metadata', implode(', ', $args));
				}
				continue;
			}
			if($this->literal_string_from_expression((string)($args[0] ?? ''))===null){
				$issues[]=$this->route_ambiguity_issue($file, $tokens[$method_index][2] ?? null, $target, $method, 'non_literal_chain_metadata', $args[0] ?? '');
			}
		}
		return $issues;
	}

	/**
	 * Builds a normalized route ambiguity record for downstream reports.
	 *
	 * keeps source location, API target, category, compact expression text, and human impact together
	 * so callers can aggregate findings without reinterpreting scanner internals.
	 *
	 * @param string $file Repo-relative source file.
	 * @param mixed $line Source line when available.
	 * @param string $target Static call target classification.
	 * @param string $method Route or chain method name.
	 * @param string $category Ambiguity category key.
	 * @param string $expression Source expression that could not be proven literal.
	 * @return array{file: string, line: mixed, call: string, method: string, category: string, expression: string, impact: string} Report entry.
	 */
	private function route_ambiguity_issue(string $file, mixed $line, string $target, string $method, string $category, string $expression): array {
		return [
			'file'=>$file,
			'line'=>$line,
			'call'=>$target,
			'method'=>$method,
			'category'=>$category,
			'expression'=>$this->compact_expression($expression, 180),
			'impact'=>$this->route_ambiguity_impact($category),
		];
	}

	/**
	 * Maps a route ambiguity category to caller-facing provenance impact.
	 *
	 * centralizes the language used in ambiguity reports so categories remain stable machine keys
	 * while impact text can explain what static extraction cannot prove.
	 *
	 * @param string $category Ambiguity category key.
	 * @return string Human-readable impact statement.
	 */
	private function route_ambiguity_impact(string $category): string {
		return match($category){
			'non_literal_http_methods'=>'Static summary cannot enumerate the complete HTTP method list.',
			'non_literal_route_value'=>'Static summary cannot prove the final path, prefix, domain, name, resource, or group value.',
			'dynamic_handler_expression'=>'Static summary can show the expression but cannot prove controller/callable provenance.',
			'non_literal_chain_metadata'=>'Static summary cannot prove chained route metadata such as name, domain, middleware, constraints, or defaults.',
			default=>'Static route provenance may be incomplete here.',
		};
	}

	/**
	 * Classifies route handler expressions that are safe to treat as static provenance.
	 *
	 * literal strings, closure syntax, and ControllerAction references are accepted as describable
	 * source forms. Arbitrary variables, factory calls, and dynamic expressions stay outside the proven-static boundary.
	 *
	 * @param string $expression Handler expression source.
	 * @return bool Whether the expression is static enough for source provenance reporting.
	 */
	private function is_static_route_handler_expression(string $expression): bool {
		$expression=trim($expression);
		if($expression===''){
			return false;
		}
		if($this->literal_string_from_expression($expression)!==null){
			return true;
		}
		if(str_starts_with($expression, 'static fn') || str_starts_with($expression, 'fn(') || str_starts_with($expression, 'static function') || str_starts_with($expression, 'function')){
			return true;
		}
		return str_starts_with($expression, 'ControllerAction::') || str_contains($expression, '\\ControllerAction::');
	}

	/**
	 * Collects literal route chain metadata following a route declaration token.
	 *
	 * extracts names, domains, middleware aliases, and where constraints from fluent chains until the
	 * statement ends or the bounded token window is exhausted. Non-literal metadata is ignored here and handled by the
	 * ambiguity scanner.
	 *
	 * @param array<int, mixed> $tokens Token stream from token_get_all().
	 * @param int $index Index of the route API token.
	 * @return array{name: array<int, string>, domain: array<int, string>, middleware: array<int, string>, where: array<int, string>} Literal chain metadata.
	 */
	private function route_chain_metadata_after_token(array $tokens, int $index): array {
		$metadata=['name'=>[], 'domain'=>[], 'middleware'=>[], 'where'=>[]];
		for($i=$index+1, $count=count($tokens), $seen=0; $i<$count && $seen<220; $i++, $seen++){
			$token=$tokens[$i];
			if($token===';'){
				break;
			}
			if(!is_array($token) || $token[0]!==T_OBJECT_OPERATOR){
				continue;
			}
			$method_index=$this->next_meaningful_token_index($tokens, $i);
			if($method_index===null || !is_array($tokens[$method_index]) || $tokens[$method_index][0]!==T_STRING){
				continue;
			}
			$method=strtolower($tokens[$method_index][1]);
			if(!in_array($method, ['name', 'domain', 'middleware', 'where', 'wherealpha', 'wherenumber', 'whereuuid', 'whereulid', 'wherein'], true)){
				continue;
			}
			$args=$this->call_arguments_after_token($tokens, $method_index);
			if($args===null){
				continue;
			}
			if($method==='name' || $method==='domain'){
				$value=$this->literal_string_from_expression($args[0] ?? '');
				if($value!==null){
					$metadata[$method][]=$value;
				}
				continue;
			}
			if($method==='middleware'){
				foreach($args as $arg){
					$value=$this->literal_string_from_expression($arg);
					if($value!==null){
						$metadata['middleware'][]=$value;
					}
				}
				continue;
			}
			$where=$this->literal_string_from_expression($args[0] ?? '');
			if($where!==null){
				$metadata['where'][]=$method.':'.$where;
			}
		}
		foreach($metadata as $key=>$values){
			$metadata[$key]=array_values(array_unique($values));
		}
		return $metadata;
	}

}
