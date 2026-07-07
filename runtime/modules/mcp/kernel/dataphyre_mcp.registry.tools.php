<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Dataphyre MCP registered tool catalog.
 */
trait dataphyre_mcp_registry_tool_surfaces {

	/**
	 * Returns shared app-builder argument schemas for app-agent entrypoints.
	 *
	 * @param array<int,string> $names Argument names to include, in output order.
	 * @param string $surface Description surface: plan, context, builder_context, or brief.
	 * @param string $fields_profile Field description profile: plan, task_pack, or compact.
	 * @return array<string,array<string,mixed>> Tool argument schemas.
	 */
	private function mcp_app_builder_argument_schemas(array $names, string $surface='context', string $fields_profile='compact'): array {
		$max_description=match($surface){
			'plan'=>'Maximum entities to include in this compact response, default 4, maximum 12. Prefer the default; raising max_entities toward 12 intentionally creates a larger first response. Larger apps return entity_planning continuation calls with dependency_context for deferred entities.',
			'builder_context'=>'Maximum app-builder entities to include in compact builder context, default 4, maximum 12. Prefer the default; raising max_entities toward 12 intentionally creates a larger first response. Larger apps return entity_planning continuation calls with dependency_context.',
			'brief'=>'Maximum app-builder entities to include in compact brief context, default 4, maximum 12. Prefer the default; raising max_entities toward 12 intentionally creates a larger first response. Larger apps return entity_planning continuation calls with dependency_context.',
			default=>'Maximum app-builder entities to include in this compact context, default 4, maximum 12. Prefer the default; raising max_entities toward 12 intentionally creates a larger first response. Larger apps return entity_planning continuation calls with dependency_context.',
		};
		$field_description=match($fields_profile){
			'plan'=>'Optional field/schema hints. Use a flat map or string list for one entity, or nested per-entity hints such as {"Project":{"name":"string required","owner_id":"foreign key to users nullable"},"Ticket":{"project_id":{"type":"integer","foreign_key_target":"projects"},"external_id":"string nullable not a foreign key","status":{"type":"string","options":["open","closed"],"default":"open"},"payload":{"type":"json"}}}; list entries like {"entity":"Ticket","fields":{...}} are also accepted. Structured required, options, choices, enum, default/default_value, json/jsonb types, explicit relationship targets, not_foreign_key/foreign_key=false markers, unique/unique_with integrity hints, and phrase-style required/nullable/enum/default/foreign-key hints are preserved for app-owned schema, validation, Panel adaptation, and data_integrity_summary. _id suffixes alone are not treated as relationships.',
			'task_pack'=>'Optional app-builder field/schema hints, including nested per-entity maps such as {"Project":{"name":"string required"},"Ticket":{"project_id":{"type":"integer","foreign_key_target":"projects"},"external_id":"string nullable not a foreign key","payload":{"type":"json"},"status":"enum open,closed default open"}}. Structured required, options, choices, enum, default/default_value, json/jsonb types, explicit relationship targets, not_foreign_key/foreign_key=false markers, unique/unique_with integrity hints, and phrase-style required/nullable/enum/default/foreign-key hints are preserved for app-owned schema, validation, Panel adaptation, and data_integrity_summary. _id suffixes alone are not treated as relationships.',
			default=>'Optional app-builder field/schema hints, including nested per-entity maps. Structured required, options, choices, enum, default/default_value, json/jsonb types, explicit relationship targets, not_foreign_key/foreign_key=false markers, unique/unique_with integrity hints, and phrase-style required/nullable/enum/default/foreign-key hints are preserved for app-owned schema, validation, Panel adaptation, and data_integrity_summary. _id suffixes alone are not treated as relationships.',
		};
		$catalog=[
			'entities'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>$surface==='plan' ? 'Optional entity/resource names to plan explicitly.' : 'Optional entity/resource names to pass through to the app-builder lane. Explicit entities are treated as the source of truth.'],
			'max_entities'=>['type'=>'integer', 'description'=>$max_description],
			'application_path'=>['type'=>'string', 'description'=>'Optional repo-relative consuming app root such as applications/example_app or applications/example_app/backend/dataphyre; rejects absolute paths, URLs, and .. traversal, and replaces portable applications/<app>/backend/dataphyre placeholders with concrete app-owned paths.'],
			'app_namespace'=>['type'=>'string', 'description'=>'Optional valid PHP application namespace such as ExampleApp or Acme\\Portal; invalid namespace segments are rejected and fall back to App placeholders until corrected. Used for generated Panel resource and Framework namespace hints while keeping behavior app-owned.'],
			'fields'=>['type'=>'object', 'description'=>$field_description],
		];
		$schemas=[];
		foreach($names as $name){
			if(isset($catalog[$name])){
				$schemas[$name]=$catalog[$name];
			}
		}
		return $schemas;
	}

/**
	 * Returns the registered Dataphyre MCP tool manifest.
	 *
	 * tool descriptors declare names, descriptions, JSON-schema-like input
	 * shapes, and required fields while keeping unsafe runtime execution separated
	 * behind explicit allow-unsafe policy in downstream handlers.
	 */
	private function list_tools(): array {
		return ['tools'=>[
			$this->tool('dataphyre_application_info', 'Read local PHP, Dataphyre, git, module, and workspace facts; copy copy_safe_startup_summary for handoffs instead of root or raw git output.', []),
			$this->tool('dataphyre_application_catalog', 'Read bounded local application candidates, Dataphyre roots, config file names, and namespace hints without booting apps or reading config values.', [
				'scope'=>['type'=>'string', 'description'=>'Optional application id or applications/<app> path to inspect. Defaults all local application candidates.'],
				'include_config_files'=>['type'=>'boolean', 'description'=>'Include bounded top-level config file names only. Defaults true; never reads config values.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum application candidates to return, default 50, max 100.'],
			]),
			$this->tool('dataphyre_package_metadata_read', 'Read safe Composer/package metadata without executing scripts or package commands.', [
				'paths'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional repo-relative composer.json or package.json paths. Defaults to discovery.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum manifests to return, default 20.'],
			]),
			$this->tool('dataphyre_api_docs_static_summary', 'Statically summarize Dataphyre API endpoint declarations and OpenAPI surfaces without booting an app.', [
				'paths'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional repo-relative PHP files or directories. Defaults to the Dataphyre API module.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum PHP files to inspect, default 80.'],
			]),
			$this->tool('dataphyre_source_api_summary', 'Summarize PHP namespaces, classes, methods, and functions from repo-local source without executing code.', [
				'module'=>['type'=>'string', 'description'=>'Optional runtime module name to summarize.'],
				'paths'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional repo-relative PHP source files or directories.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum PHP files to inspect, default 40.'],
			]),
			$this->tool('dataphyre_list_modules', 'List Dataphyre runtime modules and documentation coverage.', []),
			$this->tool('dataphyre_module_describe', 'Describe one Dataphyre runtime module: docs, Framework classes, kernel files, tests, and version.', [
				'module'=>['type'=>'string', 'description'=>'Runtime module name, for example panel, routing, sql, or mcp.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum files per group, default 80.'],
			], ['module']),
			$this->tool('dataphyre_module_dependency_map', 'Build a static dependency and public-surface map for one Dataphyre runtime module without executing it.', [
				'module'=>['type'=>'string', 'description'=>'Runtime module name.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum PHP files to scan, default 120.'],
			], ['module']),
			$this->tool('dataphyre_runtime_version_summary', 'Statically summarize Dataphyre bootstrap, module, and bundled package version metadata without loading the runtime.', [
				'include_modules'=>['type'=>'boolean', 'description'=>'Include per-module version files. Defaults true.'],
				'include_packages'=>['type'=>'boolean', 'description'=>'Include bundled package VERSION/composer metadata. Defaults true.'],
			]),
			$this->tool('dataphyre_module_docs_pack', 'Return a bounded documentation pack for one Dataphyre module plus baseline AI guidelines.', [
				'module'=>['type'=>'string', 'description'=>'Runtime module name, for example panel, routing, sql, or mcp.'],
				'max_bytes_per_doc'=>['type'=>'integer', 'description'=>'Maximum bytes per document, default 40000.'],
			], ['module']),
			$this->tool('dataphyre_search_docs', 'Search Dataphyre markdown documentation and return matching snippets.', [
				'query'=>['type'=>'string', 'description'=>'Search text.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum matches, default 12.'],
			], ['query']),
			$this->tool('dataphyre_read_doc', 'Read a Dataphyre documentation file by repo-relative path.', [
				'path'=>['type'=>'string', 'description'=>'Repo-relative markdown path.'],
			], ['path']),
			$this->tool('dataphyre_docs_chunks_export', 'Export bounded, semantic-ready documentation chunks with chunk_index, line spans, and content_sha256 metadata for focused reads and caller-owned indexes.', [
				'modules'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Runtime modules to include. Defaults to core, routing, sql, panel, and mcp when present.'],
				'max_chunks'=>['type'=>'integer', 'description'=>'Maximum chunks to return, default 40.'],
				'max_chars_per_chunk'=>['type'=>'integer', 'description'=>'Maximum characters per chunk, default 3500.'],
				'docs_profile'=>['type'=>'string', 'description'=>'Optional profile: builder ranks practical module construction docs first; governance includes MCP/AI guideline and reference material.'],
				'guidelines_position'=>['type'=>'string', 'description'=>'Optional MCP AI guideline placement: none, first, or after_modules. Use none or after_modules for ordinary app-building docs.'],
			]),
			$this->tool('dataphyre_docs_index_plan', 'Generate a read-only client-side documentation indexing plan for local docs, optional remote docs, and semantic search payloads.', [
				'modules'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Runtime modules to include. Defaults to core, routing, sql, panel, and mcp when present.'],
				'include_remote_templates'=>['type'=>'boolean', 'description'=>'Include optional remote documentation source templates. Defaults true.'],
				'max_chunks'=>['type'=>'integer', 'description'=>'Maximum chunks to sample for sizing, default 40.'],
				'max_chars_per_chunk'=>['type'=>'integer', 'description'=>'Maximum characters per chunk, default 3500.'],
			]),
			$this->tool('dataphyre_embeddings_readiness_plan', 'Generate a read-only readiness plan for client-owned documentation embeddings without calling embedding APIs.', [
				'provider'=>['type'=>'string', 'description'=>'Optional embedding provider placeholder to include in the plan. Defaults to <embedding-provider>.'],
				'model'=>['type'=>'string', 'description'=>'Optional embedding model placeholder to include in the plan. Defaults to <embedding-model>.'],
			]),
			$this->tool('dataphyre_remote_docs_readiness_plan', 'Generate a read-only readiness plan for a client-owned remote documentation fetcher without making network requests.', [
				'base_url'=>['type'=>'string', 'description'=>'Optional remote docs base URL placeholder to include in the plan. Defaults to <docs-base-url>.'],
			]),
			$this->tool('dataphyre_datadoc_static_summary', 'Statically summarize Datadoc indexing, tokenizer/highlighter, SQL table, route, and UI contracts without querying Datadoc.', []),
			$this->tool('dataphyre_datadoc_runtime_readiness_plan', 'Generate a read-only readiness plan for any future unsafe-gated Datadoc SQL-backed reader without querying Datadoc records.', [
				'project'=>['type'=>'string', 'description'=>'Optional Datadoc project placeholder to include in the plan. Defaults to <project>.'],
			]),
			$this->tool('dataphyre_list_routes', 'Locate compiled or declared route artifacts for Dataphyre apps.', [
				'limit'=>['type'=>'integer', 'description'=>'Maximum files to inspect, default 30.'],
			]),
			$this->tool('dataphyre_route_manifest_read', 'Read and summarize a compiled Dataphyre route manifest without dispatching handlers.', [
				'manifest_path'=>['type'=>'string', 'description'=>'Repo-relative route manifest PHP file.'],
				'include_handlers'=>['type'=>'boolean', 'description'=>'Include compiled handler metadata. Defaults false.'],
				'include_middleware'=>['type'=>'boolean', 'description'=>'Include compiled middleware metadata. Defaults false.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum routes to return, default 50.'],
			], ['manifest_path']),
			$this->tool('dataphyre_route_url_preview', 'Generate a URL for a named route from a compiled manifest without dispatching.', [
				'manifest_path'=>['type'=>'string', 'description'=>'Repo-relative route manifest PHP file.'],
				'name'=>['type'=>'string', 'description'=>'Route name.'],
				'parameters'=>['type'=>'object', 'description'=>'Route parameters.'],
				'query'=>['type'=>'object', 'description'=>'Query parameters.'],
				'base_url'=>['type'=>'string', 'description'=>'Optional HTTP(S) base URL for absolute preview.'],
			], ['manifest_path', 'name']),
			$this->tool('dataphyre_route_match_preview', 'Dry-match method/path/host against a compiled route manifest without dispatching handlers.', [
				'manifest_path'=>['type'=>'string', 'description'=>'Repo-relative route manifest PHP file.'],
				'method'=>['type'=>'string', 'description'=>'HTTP method, default GET.'],
				'path'=>['type'=>'string', 'description'=>'Request path, for example /orders/42.'],
				'host'=>['type'=>'string', 'description'=>'Optional request host for domain routes.'],
				'include_handler'=>['type'=>'boolean', 'description'=>'Include compiled handler metadata. Defaults false.'],
				'include_middleware'=>['type'=>'boolean', 'description'=>'Include compiled middleware metadata. Defaults false.'],
			], ['manifest_path', 'path']),
			$this->tool('dataphyre_route_source_static_summary', 'Statically summarize source-level Dataphyre/MVC route declarations without booting apps or dispatching handlers.', [
				'paths'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional repo-local PHP files or directories to scan. Defaults to routing and MVC modules.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum PHP files to scan, default 80.'],
			]),
			$this->tool('dataphyre_route_source_ambiguity_report', 'Report dynamic or non-literal route source expressions that static route summaries cannot fully resolve.', [
				'paths'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional repo-local PHP files or directories to scan. Defaults to routing and MVC modules.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum PHP files to scan, default 80.'],
			]),
			$this->tool('dataphyre_route_runtime_provenance_plan', 'Generate a read-only readiness plan for any future runtime route provenance reader without bootstrapping an application or dispatching routes.', [
				'application_id'=>['type'=>'string', 'description'=>'Optional application identifier placeholder to include in the plan. Defaults to <app>.'],
			]),
			$this->tool('dataphyre_controller_source_summary', 'Statically summarize MVC controller classes, public actions, and literal Controller@action references without loading controllers.', [
				'paths'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional repo-local PHP files or directories to scan. Defaults to the MVC module.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum PHP files to scan, default 80.'],
			]),
			$this->tool('dataphyre_middleware_source_summary', 'Statically summarize route/controller middleware declarations, aliases, stacks, and middleware classes without running middleware.', [
				'paths'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional repo-local PHP files or directories to scan. Defaults to MVC and Routing modules.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum PHP files to scan, default 80.'],
			]),
			$this->tool('dataphyre_mvc_config_static_summary', 'Statically summarize MVC config keys, route source forms, middleware config surfaces, namespaces, and manifest cache shape.', []),
			$this->tool('dataphyre_mvc_route_cache_summary', 'Statically summarize MVC route list/cache/clear CLI surfaces and manifest-cache planning without running cache commands.', []),
			$this->tool('dataphyre_list_config_keys', 'List safe config files and top-level keys without secret values.', [
				'scope'=>['type'=>'string', 'description'=>'Optional repo-local path fragment such as common/dataphyre/config or an application config directory.'],
			]),
			$this->tool('dataphyre_config_shape_read', 'Read a redacted key-path shape for one repo-local PHP or JSON config file without returning values.', [
				'path'=>['type'=>'string', 'description'=>'Repo-relative config PHP or JSON file path.'],
				'max_paths'=>['type'=>'integer', 'description'=>'Maximum key paths to return, default 120.'],
			], ['path']),
			$this->tool('dataphyre_config_value_preview', 'Preview explicitly requested non-secret scalar config values from repo-local PHP or JSON config files.', [
				'path'=>['type'=>'string', 'description'=>'Repo-relative config PHP or JSON file path.'],
				'keys'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Exact dot-delimited key paths to preview.'],
				'max_values'=>['type'=>'integer', 'description'=>'Maximum requested values to return, default 20.'],
			], ['path', 'keys']),
			$this->tool('dataphyre_storage_config_summary', 'Summarize Dataphyre storage disk config shape and available driver classes without exposing secret values.', [
				'config_path'=>['type'=>'string', 'description'=>'Optional repo-relative storage config PHP file. Defaults to common/dataphyre/config/storage.example.php.'],
			]),
			$this->tool('dataphyre_storage_driver_catalog', 'Statically catalog Dataphyre storage driver classes and contract method coverage without touching storage backends.', []),
			$this->tool('dataphyre_sql_tables_list', 'List known Dataphyre SQL table names and cluster assignments without credentials.', [
				'config_path'=>['type'=>'string', 'description'=>'Optional repo-relative sql.php config file.'],
				'include_runtime_manifest'=>['type'=>'boolean', 'description'=>'Include first-party runtime table definitions. Defaults true.'],
				'include_config_tables'=>['type'=>'boolean', 'description'=>'Include table keys from config_path. Defaults true when config_path is provided.'],
			]),
			$this->tool('dataphyre_sql_schema_read', 'Read a runtime Dataphyre SQL table schema from table definition metadata without connecting to a database.', [
				'table'=>['type'=>'string', 'description'=>'Known runtime table name, for example dataphyre.mailer_outbox.'],
				'config_path'=>['type'=>'string', 'description'=>'Optional repo-relative sql.php config file. When provided, app-owned table definitions declared by that config can be inspected.'],
				'include_create_sql'=>['type'=>'boolean', 'description'=>'Include CREATE SQL preview strings. Defaults false.'],
			], ['table']),
			$this->tool('dataphyre_sql_clusters_list', 'Summarize SQL datacenters, clusters, DBMS, and table cluster assignments without credentials.', [
				'config_path'=>['type'=>'string', 'description'=>'Repo-relative sql.php config file.'],
			], ['config_path']),
			$this->tool('dataphyre_sql_query_plan', 'Classify and bound a proposed SQL read query without connecting to a database or executing SQL.', [
				'sql'=>['type'=>'string', 'description'=>'Proposed SQL statement to classify.'],
				'max_rows'=>['type'=>'integer', 'description'=>'Maximum rows a future unsafe read runner should allow, default 50.'],
				'allowed_tables'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional allow-list of table names.'],
			], ['sql']),
			$this->tool('dataphyre_sql_query_runner_contract', 'Describe the unsafe-gated contract a future read-only SQL runner must satisfy before executing planner-approved queries.', []),
			$this->tool('dataphyre_sql_runtime_readiness_plan', 'Generate a read-only readiness plan for any future unsafe-gated SQL read runner without connecting to a database.', [
				'config_path'=>['type'=>'string', 'description'=>'Optional repo-relative SQL config placeholder to include in the plan.'],
				'cluster'=>['type'=>'string', 'description'=>'Optional configured cluster alias placeholder to include in the plan.'],
			]),
			$this->tool('dataphyre_tracelog_artifacts_list', 'List bounded Tracelog and log artifacts without reading their contents.', [
				'scope'=>['type'=>'string', 'description'=>'Optional repo-local directory to scan. Defaults to common/dataphyre.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum artifacts to return, default 30.'],
			]),
			$this->tool('dataphyre_tracelog_read', 'Read a redacted preview from a repo-local Tracelog or log artifact.', [
				'path'=>['type'=>'string', 'description'=>'Repo-relative Tracelog/log artifact path.'],
				'max_bytes'=>['type'=>'integer', 'description'=>'Maximum bytes to read, default 20000.'],
				'strip_html'=>['type'=>'boolean', 'description'=>'Strip HTML tags from trace buffers. Defaults true.'],
			], ['path']),
			$this->tool('dataphyre_tracelog_search', 'Search repo-local Tracelog/log artifacts with redaction, bounded reads, and short snippets.', [
				'query'=>['type'=>'string', 'description'=>'Case-insensitive text to search for.'],
				'scope'=>['type'=>'string', 'description'=>'Optional repo-local directory to scan. Defaults to common/dataphyre.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum matching snippets to return, default 12.'],
				'max_bytes_per_file'=>['type'=>'integer', 'description'=>'Maximum bytes to read per artifact, default 50000.'],
				'strip_html'=>['type'=>'boolean', 'description'=>'Strip HTML tags before searching. Defaults true.'],
			], ['query']),
			$this->tool('dataphyre_diagnostics_last_error', 'Extract recent redacted error-looking snippets from repo-local Tracelog/log artifacts without executing diagnostics; use as summary-first app/module triage, not MCP release proof.', [
				'scope'=>['type'=>'string', 'description'=>'Optional repo-local directory to scan. Defaults to common/dataphyre.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum error snippets to return, default 5.'],
				'max_artifacts'=>['type'=>'integer', 'description'=>'Maximum recent artifacts to inspect, default 20.'],
				'max_bytes_per_file'=>['type'=>'integer', 'description'=>'Maximum bytes to read per artifact, default 80000.'],
				'strip_html'=>['type'=>'boolean', 'description'=>'Strip HTML tags before scanning. Defaults true.'],
			]),
			$this->tool('dataphyre_browser_diagnostics_readiness_plan', 'Generate a read-only readiness plan for a future unsafe-gated browser diagnostics runner without launching a browser; focused app evidence stays separate from MCP publication validation.', [
				'base_url'=>['type'=>'string', 'description'=>'Optional http(s) base URL placeholder to include in the plan. Defaults to <base-url>.'],
			]),
			$this->tool('dataphyre_flightdeck_surfaces_list', 'List Flightdeck control-plane surface files, route strings, assets, and classes without dispatching surfaces.', [
				'include_source_summary'=>['type'=>'boolean', 'description'=>'Include tokenized class/method summaries for each surface. Defaults false.'],
			]),
			$this->tool('dataphyre_unit_tests_list', 'List Dataphyre JSON unit-test manifests without executing test code.', [
				'modules'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional runtime modules to scan. Defaults to all modules.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum manifests to return, default 80.'],
			]),
			$this->tool('dataphyre_unit_test_manifest_read', 'Read and summarize a Dataphyre JSON unit-test manifest without executing test code.', [
				'path'=>['type'=>'string', 'description'=>'Repo-relative unit_tests/*.json manifest path.'],
				'max_cases'=>['type'=>'integer', 'description'=>'Maximum test cases to summarize, default 40.'],
				'include_expected'=>['type'=>'boolean', 'description'=>'Include raw expected values. Defaults false.'],
			], ['path']),
			$this->tool('dataphyre_app_builder_plan_generate', 'Generate the first app-building plan: entities, files/schema, prewrite checklist, next action, and verification handoff. Uses compact by default.', array_merge([
				'task'=>['type'=>'string', 'description'=>'Application feature to build, such as a ticket tracker with Projects and Tickets.'],
				'scaffold_type'=>['type'=>'string', 'description'=>'Optional scaffold type, default inferred from task. Usually panel_resource for admin CRUD; use api_endpoint for API/OpenAPI endpoint work.'],
				'name'=>['type'=>'string', 'description'=>'Optional single entity/resource name.'],
				'path'=>['type'=>'string', 'description'=>'Optional API endpoint path when scaffold_type is api_endpoint, such as /api/orders/{order_id}.'],
				'methods'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional HTTP methods when scaffold_type is api_endpoint. Defaults to GET.'],
				'group'=>['type'=>'string', 'description'=>'Optional API group/profile name or prefix hint when scaffold_type is api_endpoint.'],
				'auth'=>['type'=>'string', 'description'=>'Optional API auth hint when scaffold_type is api_endpoint, such as none, jwt, api_key, session, or custom.'],
			], $this->mcp_app_builder_argument_schemas(['entities', 'max_entities'], 'plan', 'plan'), [
				'payload_profile'=>['type'=>'string', 'description'=>'Optional profile: compact is the default first-page response and omits builder_plan, raw handoff_fields, and code skeleton bodies; full includes builder_plan and code_skeletons when the agent is ready to adapt app-owned skeleton bodies.'],
			], $this->mcp_app_builder_argument_schemas(['application_path', 'app_namespace', 'fields'], 'plan', 'plan'), [
				'field_scope'=>['type'=>'string', 'description'=>'Continuation-call hint emitted by entity_planning.continuation_calls when fields are scoped to the current chunk, usually chunk_entities.'],
				'dependency_context'=>['type'=>'object', 'description'=>'Continuation-call dependency metadata emitted by entity_planning.continuation_calls so copied chunk calls preserve cross-chunk relationship context plus compact tenant/actor/entitlement policy_context.'],
				'reuse_fields_from_original'=>['type'=>'boolean', 'description'=>'Continuation-call hint emitted when no chunk-specific fields are present and the caller should continue with inferred/default field planning.'],
				'detail_page'=>['type'=>'string', 'description'=>'Optional compact detail page to inline for the next app-agent step: planning, implementation, verification, controls, or governance. Keeps builder_plan omitted while materializing the selected page.'],
			]), ['task']),
			$this->tool('dataphyre_agent_context_generate', 'Generate read-only Dataphyre runtime instruction content; ordinary app creation should call dataphyre_app_builder_plan_generate first.', [
				'target'=>['type'=>'string', 'description'=>'One of codex, claude, cursor, or generic. Defaults generic.'],
				'modules'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Runtime modules to emphasize. Defaults to core, routing, sql, panel, and mcp when present.'],
			]),
			$this->tool('dataphyre_scaffold_plan_generate', 'Generate a dry-run implementation plan for common Dataphyre scaffolding tasks without writing files.', [
				'type'=>['type'=>'string', 'description'=>'One of panel_resource, routing_controller, api_endpoint, sql_table, mvc_controller, or runtime_module.'],
				'name'=>['type'=>'string', 'description'=>'Feature/resource/controller/table/module name.'],
				'module'=>['type'=>'string', 'description'=>'Optional owning runtime module for module-scoped work.'],
				'fields'=>['type'=>'object', 'description'=>'Optional field/schema hints for resources or tables.'],
				'path'=>['type'=>'string', 'description'=>'Optional API endpoint path when type is api_endpoint, such as /api/orders/{order_id}.'],
				'methods'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional HTTP methods when type is api_endpoint. Defaults to GET.'],
				'group'=>['type'=>'string', 'description'=>'Optional API group/profile name or prefix hint when type is api_endpoint.'],
				'auth'=>['type'=>'string', 'description'=>'Optional API auth hint when type is api_endpoint, such as none, jwt, api_key, session, or custom.'],
			], ['type', 'name']),
			$this->tool('dataphyre_api_scaffold_plan', 'Generate a dry-run API endpoint, handler, OpenAPI, and verification plan without writing files or dispatching routes.', [
				'name'=>['type'=>'string', 'description'=>'Endpoint or feature name, for example Orders Show.'],
				'path'=>['type'=>'string', 'description'=>'Optional endpoint path such as /api/orders/{order_id}. Defaults from name.'],
				'methods'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'HTTP methods, default GET.'],
				'group'=>['type'=>'string', 'description'=>'Optional group/profile name or prefix hint.'],
				'auth'=>['type'=>'string', 'description'=>'Optional auth scheme hint such as none, jwt, api_key, session, or custom.'],
			], ['name']),
			$this->tool('dataphyre_api_recipe_catalog', 'Return dry-run Dataphyre API implementation recipes for common endpoint patterns without writing files or dispatching routes.', [
				'recipe'=>['type'=>'string', 'description'=>'Optional recipe id to return, such as read_resource, create_resource, profiled_api, binding_dashboard, cached_trace, or controller_backed.'],
			]),
			$this->tool('dataphyre_api_cache_static_summary', 'Statically summarize Dataphyre API endpoint cache, trace payload, identity, and clear-cache contracts without touching cache storage.', []),
			$this->tool('dataphyre_openapi_static_contract_summary', 'Statically summarize Dataphyre OpenAPI generation, documentation routes, Swagger UI, and publish contracts without bootstrapping an application.', []),
			$this->tool('dataphyre_openapi_runtime_readiness_plan', 'Generate a read-only readiness plan for any future unsafe-gated runtime OpenAPI document reader without bootstrapping an application.', [
				'application_id'=>['type'=>'string', 'description'=>'Optional application identifier placeholder to include in the plan. Defaults to <app>.'],
			]),
			$this->tool('dataphyre_panel_scaffold_catalog', 'Statically inventory Panel scaffolding, package template, and generator-related surfaces without executing them.', []),
			$this->tool('dataphyre_panel_package_manifest_summary', 'Statically summarize Panel package manifest, template, repository, install, rollback, trust, and compatibility contracts.', []),
			$this->tool('dataphyre_panel_theme_manifest_summary', 'Statically summarize Panel theme manifest, preset, asset, library, and preview contracts without rendering previews.', []),
			$this->tool('dataphyre_panel_documentation_catalog_summary', 'Statically summarize Panel documentation catalog and entry manifest contracts without building documentation.', []),
			$this->tool('dataphyre_panel_media_manifest_summary', 'Statically summarize Panel media library, collection, item, upload endpoint, and storage integration contracts without touching storage.', []),
			$this->tool('dataphyre_task_pack_generate', 'Generate optional builder context after an app plan: focused docs, scaffold guidance, write handoff, and verification hints for ordinary app work.', array_merge([
				'task'=>['type'=>'string', 'description'=>'Short task description for the agent.'],
				'modules'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Runtime modules to include in the context pack.'],
				'scaffold_type'=>['type'=>'string', 'description'=>'Optional scaffold type to include: panel_resource, routing_controller, api_endpoint, sql_table, mvc_controller, or runtime_module.'],
				'name'=>['type'=>'string', 'description'=>'Optional scaffold name when scaffold_type is provided. Defaults to task.'],
				'path'=>['type'=>'string', 'description'=>'Optional API endpoint path when scaffold_type is api_endpoint, such as /api/orders/{order_id}.'],
				'methods'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional HTTP methods when scaffold_type is api_endpoint. Defaults to GET.'],
				'group'=>['type'=>'string', 'description'=>'Optional API group/profile name or prefix hint when scaffold_type is api_endpoint.'],
				'auth'=>['type'=>'string', 'description'=>'Optional API auth hint when scaffold_type is api_endpoint, such as none, jwt, api_key, session, or custom.'],
			], $this->mcp_app_builder_argument_schemas(['entities', 'max_entities', 'application_path', 'app_namespace', 'fields'], 'context', 'task_pack'), [
				'max_chunks'=>['type'=>'integer', 'description'=>'Maximum docs chunks to include, default 10; builder profile caps ordinary app docs at 8 chunks even when a larger value is requested.'],
				'payload_profile'=>['type'=>'string', 'description'=>'Optional profile: builder for ordinary app work, governance to inline extension boundary, publication validation, and guardrails. Defaults builder; elevated task signals stay collapsed unless governance is explicitly requested.'],
				'include_governance'=>['type'=>'boolean', 'description'=>'Inline governance fields regardless of profile. Defaults false; set true or use payload_profile=governance for explicit elevated review payloads.'],
			]), ['task']),
			$this->tool('dataphyre_apply_audit_plan', 'Generate a read-only audit envelope for a proposed change set before any unsafe apply workflow exists.', [
				'task'=>['type'=>'string', 'description'=>'Short task or change description.'],
				'proposed_files'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Repo-relative files expected to be created or modified.'],
				'change_summary'=>['type'=>'string', 'description'=>'Concise summary of the proposed code/docs behavior change.'],
				'verification'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional verification commands or MCP tools the caller expects to run.'],
				'risk_level'=>['type'=>'string', 'description'=>'Optional caller-provided risk hint: low, medium, high, or critical.'],
			], ['task']),
			$this->tool('dataphyre_apply_runtime_readiness_plan', 'Generate a read-only readiness plan for any future unsafe-gated apply runner without writing files.', [
				'task'=>['type'=>'string', 'description'=>'Optional task or change description to include in the plan.'],
			]),
			$this->tool('dataphyre_run_panel_regression', 'Run the route-free Panel regression CLI and return the summary.', [
				'example'=>['type'=>'boolean', 'description'=>'Run bundled live example suite. Defaults true.'],
				'suite_path'=>['type'=>'string', 'description'=>'Optional repo-relative suite file path when example is false.'],
				'json_path'=>['type'=>'string', 'description'=>'Optional repo-relative JSON report path.'],
			]),
			$this->tool('dataphyre_run_panel_field_catalog_check', 'Run the Panel field catalog route-free check.', []),
			$this->tool('dataphyre_browser_regression_manifest_summary', 'Statically summarize Panel browser regression and accessibility manifest contracts without launching a browser.', []),
			$this->tool('dataphyre_verification_surface_catalog', 'Statically catalog focused app/module verification surfaces, JSON unit-test manifests, diagnostics, and route-free harnesses without executing them; discovery only, not a release gate.', [
				'modules'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional runtime modules to scan. Defaults to all modules.'],
				'include_diagnostics'=>['type'=>'boolean', 'description'=>'Include diagnostic PHP files. Defaults true.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum surfaces to return, default 120.'],
			]),
			$this->tool('dataphyre_php_lint', 'Run php -l for explicitly provided repo-local PHP files when local verification is requested.', [
				'paths'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Repo-relative PHP files.'],
			], ['paths']),
			$this->tool('dataphyre_release_check', 'Report the Dataphyre release-check boundary for public MCP clients; not app behavior proof.', []),
			$this->tool('dataphyre_release_triage_summary', 'Run local private Dataphyre release checks when present and group failures by actionable category for maintainer release work, not ordinary application-agent verification.', []),
			$this->tool('dataphyre_release_fix_plan', 'Create an ordered, read-only maintainer/source-checkout release repair plan from Dataphyre release-check failures.', [
				'release_output'=>['type'=>'string', 'description'=>'Optional release-check output to plan from instead of running the check. Useful for deterministic tests.'],
				'max_examples_per_batch'=>['type'=>'integer', 'description'=>'Maximum examples per repair batch, default 8.'],
			]),
			$this->tool('dataphyre_mcp_manifest_export', 'Export a client-visible manifest of MCP tools, resources, prompts, safety posture, and protocol metadata.', [
				'include_schemas'=>['type'=>'boolean', 'description'=>'Include tool input schemas. Defaults true.'],
				'include_docs_resources'=>['type'=>'boolean', 'description'=>'Include discovered dataphyre://doc resources. Defaults false.'],
			]),
			$this->tool('dataphyre_prompt_pack_export', 'Export reusable Dataphyre workflow prompt bundles for clients and agents.', [
				'names'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional prompt names to include. Defaults to all prompts.'],
			]),
			$this->tool('dataphyre_mcp_prompt_catalog', 'Catalog registered MCP workflow prompts with themes, related tools, resources, and export guidance.', [
				'names'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional prompt names to include. Defaults to all prompts.'],
			]),
			$this->tool('dataphyre_mcp_skill_catalog', 'Catalog registered Dataphyre MCP skills with targets, related tools, prompts, resources, and packaging guidance.', [
				'names'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional skill names to include. Defaults to all registered skills.'],
				'target'=>['type'=>'string', 'description'=>'Optional target filter: codex, claude, cursor, generic, or all. Defaults all.'],
			]),
			$this->tool('dataphyre_mcp_skill_manifest_export', 'Export portable Dataphyre MCP skill registration metadata for client authors without writing files.', [
				'names'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional skill names to include. Defaults to all registered skills.'],
				'target'=>['type'=>'string', 'description'=>'Optional target filter: codex, claude, cursor, generic, or all. Defaults all.'],
			]),
			$this->tool('dataphyre_mcp_skill_registration_audit', 'Audit registered Dataphyre MCP skills for missing related surfaces and product-local coupling risks.', [
				'names'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional skill names to audit. Defaults to all registered skills.'],
				'target'=>['type'=>'string', 'description'=>'Optional target filter: codex, claude, cursor, generic, or all. Defaults all.'],
			]),
			$this->tool('dataphyre_mcp_skill_pack_export', 'Export read-only skill instruction packs for Codex, Claude, Cursor, or generic MCP clients without installing files.', [
				'names'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional skill names to include. Defaults to all registered skills.'],
				'target'=>['type'=>'string', 'description'=>'Skill target: codex, claude, cursor, or generic. Defaults generic.'],
			]),
			$this->tool('dataphyre_mcp_skill_install_plan', 'Generate a target-aware, read-only skill registration plan with proposed file templates and verification steps.', [
				'names'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional skill names to include. Defaults to all registered skills.'],
				'target'=>['type'=>'string', 'description'=>'Skill target: codex, claude, cursor, or generic. Defaults generic.'],
			]),
			$this->tool('dataphyre_mcp_skill_file_install_plan', 'Generate a read-only writer contract for client-owned skill files without creating directories or files.', [
				'names'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional skill names to include. Defaults to all registered skills.'],
				'target'=>['type'=>'string', 'description'=>'Skill target: codex, claude, cursor, or generic. Defaults generic.'],
				'skill_root'=>['type'=>'string', 'description'=>'Optional caller-owned skill root placeholder. Defaults to target-specific placeholder.'],
			]),
			$this->tool('dataphyre_mcp_client_config_summary', 'Return read-only MCP client configuration examples, generator commands, environment knobs, and safety notes.', [
				'include_cwd'=>['type'=>'boolean', 'description'=>'Include the current workspace path in example JSON. Defaults false for portability.'],
				'php_command'=>['type'=>'string', 'description'=>'Optional PHP command/path to show in examples. Defaults php.'],
				'allow_unsafe'=>['type'=>'boolean', 'description'=>'Include --allow-unsafe in the unsafe example. Defaults false.'],
			]),
			$this->tool('dataphyre_mcp_client_install_checklist', 'Generate a portable MCP client install checklist with config, prompt, manifest, and verification steps.', [
				'target'=>['type'=>'string', 'description'=>'One of codex, claude, cursor, or generic. Defaults generic.'],
				'include_cwd'=>['type'=>'boolean', 'description'=>'Include the current workspace path in example JSON. Defaults false for portability.'],
				'php_command'=>['type'=>'string', 'description'=>'Optional PHP command/path to show in examples. Defaults php.'],
			]),
			$this->tool('dataphyre_mcp_client_config_install_plan', 'Generate a target-aware, read-only client config install plan without writing MCP client files.', [
				'target'=>['type'=>'string', 'description'=>'One of codex, claude, cursor, or generic. Defaults generic.'],
				'config_path'=>['type'=>'string', 'description'=>'Optional caller-owned client config path placeholder. Defaults to a target-specific placeholder.'],
				'php_command'=>['type'=>'string', 'description'=>'Optional PHP command/path to show in proposed templates. Defaults php.'],
			]),
			$this->tool('dataphyre_mcp_smoke_test_export', 'Export portable stdio smoke-test requests and scripts for MCP clients without executing them.', [
				'format'=>['type'=>'string', 'description'=>'One of powershell, bash, node, php, or all. Defaults all.'],
			]),
			$this->tool('dataphyre_mcp_client_onboarding_pack', 'Export a read-only client onboarding pack with config, checklist, smoke tests, prompts, and validation steps.', [
				'target'=>['type'=>'string', 'description'=>'One of codex, claude, cursor, or generic. Defaults generic.'],
				'smoke_format'=>['type'=>'string', 'description'=>'One of powershell, bash, node, php, or all. Defaults all.'],
				'include_schemas'=>['type'=>'boolean', 'description'=>'Include tool input schemas in the manifest excerpt. Defaults false.'],
			]),
			$this->tool('dataphyre_mcp_client_troubleshoot', 'Diagnose common MCP client setup failures from symptoms or output without executing commands.', [
				'symptoms'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional symptoms, error snippets, or client observations.'],
				'target'=>['type'=>'string', 'description'=>'Optional client name: codex, claude, cursor, or generic. Defaults generic.'],
			]),
			$this->tool('dataphyre_mcp_client_compatibility_matrix', 'Return a read-only compatibility matrix for supported MCP client setup workflows.', [
				'targets'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional targets from codex, claude, cursor, or generic. Defaults to all.'],
			]),
			$this->tool('dataphyre_mcp_client_config_audit', 'Audit a proposed MCP client config snippet for portable Dataphyre stdio setup issues without executing it.', [
				'config_json'=>['type'=>'string', 'description'=>'Optional MCP client JSON config snippet to audit.'],
				'config'=>['type'=>'object', 'description'=>'Optional decoded config object to audit when the client can pass structured JSON.'],
			]),
			$this->tool('dataphyre_mcp_safety_boundary_report', 'Report Dataphyre MCP default safety posture, unsafe gates, denied surfaces, and verification expectations; not an ordinary app-building entrypoint, start app work with dataphyre_app_builder_plan_generate payload_profile=compact.', []),
			$this->tool('dataphyre_mcp_status_board', 'Return a compact live MCP status board with counts, coverage readiness, safety posture, and next actions for MCP surface health; not an ordinary app-building entrypoint, start app work with dataphyre_app_builder_plan_generate payload_profile=compact.', []),
			$this->tool('dataphyre_mcp_enterprise_adoption_audit', 'Audit a proposed Dataphyre feature against the agentic enterprise contract without executing application code.', [
				'feature'=>['type'=>'string', 'description'=>'Optional feature, module, or change label to audit.'],
				'module'=>['type'=>'string', 'description'=>'Optional Dataphyre module name associated with the change.'],
				'files'=>['type'=>'array', 'items'=>['type'=>'string'], 'description'=>'Optional repo-relative file paths touched or planned.'],
				'public_claim'=>['type'=>'boolean', 'description'=>'Set true when the change will be presented as enterprise-ready or release-facing. Defaults false.'],
			]),
			$this->tool('dataphyre_mcp_capability_matrix', 'Return a release-facing MCP capability matrix grouped by tool family, safety level, execution posture, and verification; not an ordinary app-building entrypoint, start app work with dataphyre_app_builder_plan_generate payload_profile=compact.', []),
			$this->tool('dataphyre_mcp_release_notes_generate', 'Generate read-only MCP release notes from live capability, status, and safety metadata.', [
				'audience'=>['type'=>'string', 'description'=>'One of maintainers, client_authors, or agents. Defaults agents for app-building MCP users.'],
			]),
			$this->tool('dataphyre_mcp_surface_changelog', 'Generate a read-only changelog snapshot for current MCP tools, resources, prompts, client helpers, and safety surfaces.', [
				'audience'=>['type'=>'string', 'description'=>'One of maintainers, client_authors, or agents. Defaults agents for app-building MCP users.'],
			]),
			$this->tool('dataphyre_mcp_tool_call_examples_export', 'Export read-only example MCP tools/call payloads for common Dataphyre workflows.', [
				'workflow'=>['type'=>'string', 'description'=>'Optional workflow filter: docs, routes, sql, diagnostics, client, safety, validation, or all. Defaults all.'],
			]),
			...$this->mcp_workflow_tool_descriptors(),
			$this->tool('dataphyre_mcp_tool_finder', 'Find registered Dataphyre MCP tools by query text or capability family without scraping the full manifest.', [
				'query'=>['type'=>'string', 'description'=>'Optional search text such as route, config, sql, panel, diagnostics, release, or client.'],
				'group'=>['type'=>'string', 'description'=>'Optional capability family from dataphyre_mcp_manifest_export tool_groups.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum matches to return, default 12.'],
			]),
			$this->tool('dataphyre_mcp_resource_finder', 'Find registered Dataphyre MCP resources and prompts by query text without scraping protocol listings.', [
				'query'=>['type'=>'string', 'description'=>'Optional search text such as guidelines, routing, sql, diagnostics, docs, or capabilities.'],
				'kind'=>['type'=>'string', 'description'=>'One of all, resource, or prompt. Defaults all.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum matches to return, default 12.'],
			]),
			$this->tool('dataphyre_mcp_docs_coverage_report', 'Report whether live MCP tools, resources, prompts, and safety boundaries are documented for MCP/release-surface claims, not app behavior proof.', []),
			$this->tool('dataphyre_mcp_readiness_report', 'Report Dataphyre MCP agentic capability coverage, safety posture, gaps, and recommended next tool slices for MCP/framework readiness; not an ordinary app-building entrypoint, start app work with dataphyre_app_builder_plan_generate payload_profile=compact.', []),
			$this->tool('dataphyre_mcp_live_validate', 'Run live stdio validation through a spawned MCP server process for local client setup or MCP publication checks, not app behavior proof.', []),
			$this->tool('dataphyre_mcp_verify_all', 'Run the core MCP verification suite for maintainer/source-checkout MCP or release-surface claims, not routine app verification.', []),
			$this->tool('dataphyre_mcp_doctor', 'Run a fast self-inspection of Dataphyre MCP module wiring, docs, tools, and app-coupling guardrails after MCP surface changes, not app behavior proof.', []),
		]];
	}
}
