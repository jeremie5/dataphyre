<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

dataphyre_mcp_debug_bootstrap('start', ['sapi'=>PHP_SAPI, 'cwd'=>getcwd() ?: '', 'argv'=>$argv ?? []]);
register_shutdown_function(static function(): void {
	dataphyre_mcp_debug_shutdown(error_get_last());
});

if(!dataphyre_mcp_enforce_cli(PHP_SAPI)){exit(2);}

require_once __DIR__.'/dataphyre_mcp.source.php';
require_once __DIR__.'/dataphyre_mcp.contract.source.php';
require_once __DIR__.'/dataphyre_mcp.contract.index.php';
require_once __DIR__.'/dataphyre_mcp.contract.catalog.php';
require_once __DIR__.'/dataphyre_mcp.panel.source.php';
require_once __DIR__.'/dataphyre_mcp.panel.index.php';
require_once __DIR__.'/dataphyre_mcp.panel.catalog.php';
require_once __DIR__.'/dataphyre_mcp.registry.workflow_tools.php';
require_once __DIR__.'/dataphyre_mcp.registry.tools.php';
require_once __DIR__.'/dataphyre_mcp.registry.validation.php';
require_once __DIR__.'/dataphyre_mcp.registry.php';
require_once __DIR__.'/dataphyre_mcp.client.workflow.transcript.php';
require_once __DIR__.'/dataphyre_mcp.client.workflow.state.php';
require_once __DIR__.'/dataphyre_mcp.client.workflow.start_pack.php';
require_once __DIR__.'/dataphyre_mcp.client.workflow.php';
require_once __DIR__.'/dataphyre_mcp.client.safety.php';
require_once __DIR__.'/dataphyre_mcp.client.enterprise.audit.php';
require_once __DIR__.'/dataphyre_mcp.client.enterprise.php';
require_once __DIR__.'/dataphyre_mcp.client.capabilities.php';
require_once __DIR__.'/dataphyre_mcp.client.skills.php';
require_once __DIR__.'/dataphyre_mcp.client.examples.php';
require_once __DIR__.'/dataphyre_mcp.client.brief.php';
require_once __DIR__.'/dataphyre_mcp.client.setup.php';
require_once __DIR__.'/dataphyre_mcp.client.prompts.php';
require_once __DIR__.'/dataphyre_mcp.client.docs.php';
require_once __DIR__.'/dataphyre_mcp.client.discovery.php';
require_once __DIR__.'/dataphyre_mcp.client.readiness.php';
require_once __DIR__.'/dataphyre_mcp.client.php';
require_once __DIR__.'/dataphyre_mcp.planning.app_builder.schema.php';
require_once __DIR__.'/dataphyre_mcp.planning.app_builder.sensitivity.php';
require_once __DIR__.'/dataphyre_mcp.planning.app_builder.readiness.php';
require_once __DIR__.'/dataphyre_mcp.planning.app_builder.contract.php';
require_once __DIR__.'/dataphyre_mcp.planning.app_builder.response.php';
require_once __DIR__.'/dataphyre_mcp.planning.app_builder.php';
require_once __DIR__.'/dataphyre_mcp.planning.api.php';
require_once __DIR__.'/dataphyre_mcp.planning.docs.php';
require_once __DIR__.'/dataphyre_mcp.planning.task_pack.php';
require_once __DIR__.'/dataphyre_mcp.planning.modules.php';
require_once __DIR__.'/dataphyre_mcp.planning.agent_context.php';
require_once __DIR__.'/dataphyre_mcp.planning.php';
require_once __DIR__.'/dataphyre_mcp.inspection.data.php';
require_once __DIR__.'/dataphyre_mcp.inspection.routing.php';
require_once __DIR__.'/dataphyre_mcp.inspection.mvc.php';
require_once __DIR__.'/dataphyre_mcp.inspection.verification.php';
require_once __DIR__.'/dataphyre_mcp.inspection.diagnostics.php';
require_once __DIR__.'/dataphyre_mcp.inspection.contracts.php';
require_once __DIR__.'/dataphyre_mcp.inspection.panel.php';
require_once __DIR__.'/dataphyre_mcp.inspection.inventory.php';
require_once __DIR__.'/dataphyre_mcp.inspection.php';
require_once __DIR__.'/dataphyre_mcp.utility.schema.php';
require_once __DIR__.'/dataphyre_mcp.utility.php';


/**
 * Implements Dataphyre's local Model Context Protocol stdio server.
 *
 * The server exposes read-oriented Dataphyre tools, resources, and prompts over
 * either header-framed or newline-delimited JSON-RPC. Mutating and unsafe
 * runtime surfaces remain guarded behind explicit CLI/environment opt-in.
 */
final class dataphyre_mcp_server {

	private const MAX_FRAME_BYTES=4194304;

	private string $root;
	private string $common_root;
	private string $stdio_transport='headers';
	private bool $allow_unsafe;

	use dataphyre_mcp_source_surfaces;
	use dataphyre_mcp_registry_workflow_tool_surfaces;
	use dataphyre_mcp_registry_tool_surfaces;
	use dataphyre_mcp_registry_validation_surfaces;
	use dataphyre_mcp_registry_surfaces;
	use dataphyre_mcp_client_workflow_transcript_surfaces;
	use dataphyre_mcp_client_safety_surfaces;
	use dataphyre_mcp_client_enterprise_audit_surfaces;
	use dataphyre_mcp_client_docs_surfaces;
	use dataphyre_mcp_client_discovery_surfaces;
	use dataphyre_mcp_client_readiness_surfaces;
	use dataphyre_mcp_client_capability_surfaces;
	use dataphyre_mcp_client_example_surfaces;
	use dataphyre_mcp_client_brief_surfaces;
	use dataphyre_mcp_client_surfaces;
	use dataphyre_mcp_planning_app_builder_sensitivity_surfaces;
	use dataphyre_mcp_planning_app_builder_readiness_surfaces;
	use dataphyre_mcp_planning_surfaces;
	use dataphyre_mcp_inspection_inventory_surfaces;
	use dataphyre_mcp_inspection_diagnostics_surfaces;
	use dataphyre_mcp_inspection_contract_surfaces;
	use dataphyre_mcp_inspection_panel_surfaces;
	use dataphyre_mcp_inspection_surfaces;
	use dataphyre_mcp_utility_schema_methods;
	use dataphyre_mcp_utility_methods;

	/**
	 * Initializes repository roots and unsafe-tool policy from CLI arguments.
	 *
	 *
	 */
	public function __construct(string $root, array $argv) {
		$this->root=$this->normalize_path($root);
		$source_common_root=$this->normalize_path(dirname(__DIR__, 5));
		$common_roots=array_values(array_filter([
			$this->root.'/common',
			$this->root,
			$source_common_root,
		],static fn(string $candidate): bool=>$candidate!==''&&is_file(rtrim($candidate,'/').'/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php')));
		$this->common_root=$this->normalize_path($common_roots[0]??$source_common_root);
		$this->allow_unsafe=in_array('--allow-unsafe', $argv, true) || getenv('DATAPHYRE_MCP_ALLOW_UNSAFE')==='1';
	}

	/**
	 * Runs the MCP message loop until stdin is exhausted.
	 *
	 *
	 */
	public function run(): void {
		while(($message=$this->read_message(STDIN))!==null){
			if(isset($message['__mcp_read_error']) && is_array($message['__mcp_read_error'])){
				$this->write_json([
					'jsonrpc'=>'2.0',
					'id'=>null,
					'error'=>$message['__mcp_read_error'],
				]);
				continue;
			}
			if($this->is_invalid_request_shape($message)){
				$this->write_json([
					'jsonrpc'=>'2.0',
					'id'=>$message['id'] ?? null,
					'error'=>[
						'code'=>-32600,
						'message'=>'Invalid Request: JSON-RPC message must be an object with a method.',
					],
				]);
				continue;
			}
			$this->handle_message($message);
		}
	}

	/**
	 * Detects decoded JSON values that are arrays but not JSON-RPC request objects.
	 *
	 * @param array<mixed> $message Decoded JSON message.
	 */
	private function is_invalid_request_shape(array $message): bool {
		if(array_is_list($message)){
			return true;
		}
		return !isset($message['method']) || !is_string($message['method']) || trim($message['method'])==='';
	}

	/**
	 * Dispatches one JSON-RPC MCP request and writes the matching response.
	 *
	 * notifications without an id are ignored, known protocol methods are
	 * routed to local handlers, JSON-RPC error codes are preserved when supplied by
	 * exceptions, and all other failures are normalized to server errors.
	 */
	private function handle_message(array $message): void {
		$id=$message['id'] ?? null;
		$method=(string)($message['method'] ?? '');
		if($id===null){
			return;
		}
		try{
			$result=match($method){
				'initialize'=>$this->initialize((array)($message['params'] ?? [])),
				'tools/list'=>$this->list_tools(),
				'tools/call'=>$this->call_tool((array)($message['params'] ?? [])),
				'resources/list'=>$this->list_resources(),
				'resources/read'=>$this->read_resource((array)($message['params'] ?? [])),
				'prompts/list'=>$this->list_prompts(),
				'prompts/get'=>$this->get_prompt((array)($message['params'] ?? [])),
				default=>throw new RuntimeException('Unknown MCP method: '.$method, -32601),
			};
			$this->write_json(['jsonrpc'=>'2.0', 'id'=>$id, 'result'=>$result]);
		}
		catch(Throwable $exception){
			$code=$exception->getCode();
			$this->write_json([
				'jsonrpc'=>'2.0',
				'id'=>$id,
				'error'=>[
					'code'=>($code>=-32768 && $code<=-32000) ? $code : -32000,
					'message'=>$exception->getMessage(),
				],
			]);
		}
	}

	/**
	 * Builds the MCP initialize result for this local Dataphyre server.
	 *
	 * protocol version is echoed when provided, otherwise the server
	 * advertises its supported default, read-oriented capabilities, and safety
	 * instructions for guarded unsafe tools.
	 */
	private function initialize(array $params=[]): array {
		$protocol=(string)($params['protocolVersion'] ?? '');
		if($protocol===''){
			$protocol='2025-11-25';
		}
		return [
			'protocolVersion'=>$protocol,
			'serverInfo'=>[
				'name'=>'dataphyre-mcp',
				'title'=>'Dataphyre MCP',
				'version'=>'2.2.0',
				'description'=>'Local Dataphyre development server for tools, resources, prompts, and guarded diagnostics.',
			],
			'capabilities'=>[
				'tools'=>['listChanged'=>false],
				'resources'=>['subscribe'=>false, 'listChanged'=>false],
				'prompts'=>['listChanged'=>false],
			],
			'instructions'=>'Default to application agents building apps: use Dataphyre docs, MCP metadata, and read-only inspection before edits; for app creation call dataphyre_app_builder_plan_generate with payload_profile=compact first, or dataphyre_mcp_tool_finder when unsure. Put app-specific behavior in app code, config, callbacks, dialbacks, plugins, MCP metadata, or application adapters first. Use focused app/module verification for ordinary work. Open dataphyre_mcp_readiness_report only when the task is about Dataphyre itself, publication readiness, security/governance, or shared performance. Mutating and unsafe runtime tools require explicit local opt-in.',
		];
	}

	/**
	 * Returns static and discovered Dataphyre MCP resources.
	 *
	 * core documentation resources are always advertised, while bounded
	 * markdown discovery adds repo-local docs as dataphyre://doc URIs without
	 * exposing arbitrary filesystem paths.
	 */
	private function list_resources(): array {
		$resources=[
			['uri'=>'dataphyre://module-index', 'name'=>'Dataphyre Module Index', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://runtime-readme', 'name'=>'Dataphyre Runtime README', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://mcp-plan', 'name'=>'Dataphyre MCP Plan', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://ai-guidelines', 'name'=>'Dataphyre AI Guidelines', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://agentic-enterprise', 'name'=>'Dataphyre Agentic Enterprise Contract', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://mcp-capabilities', 'name'=>'Dataphyre MCP Capabilities', 'mimeType'=>'application/json'],
			['uri'=>'dataphyre://contracts', 'name'=>'Dataphyre Contract Index', 'mimeType'=>'application/json'],
			['uri'=>'dataphyre://panel', 'name'=>'Dataphyre Panel Capability Index', 'mimeType'=>'application/json'],
			['uri'=>'dataphyre://sql-migrations', 'name'=>'Dataphyre PostgreSQL Migrations', 'mimeType'=>'application/json'],
			['uri'=>'dataphyre://sql-migrations/manifest-v3-schema', 'name'=>'Dataphyre PostgreSQL Migration Manifest v3 Schema', 'mimeType'=>'application/schema+json'],
			['uri'=>'dataphyre://doc/dataphyre/runtime/modules/panel/documentation/Dataphyre_Panel.md', 'name'=>'Dataphyre_Panel.md', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://doc/dataphyre/runtime/modules/sql/documentation/Dataphyre_SQL.md', 'name'=>'Dataphyre_SQL.md', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://doc/dataphyre/runtime/modules/sql/documentation/Dataphyre_PostgreSQL_Migrations.md', 'name'=>'Dataphyre_PostgreSQL_Migrations.md', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://doc/dataphyre/runtime/modules/routing/documentation/Dataphyre_Routing.md', 'name'=>'Dataphyre_Routing.md', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://doc/dataphyre/runtime/modules/tracelog/documentation/Dataphyre_Tracelog.md', 'name'=>'Dataphyre_Tracelog.md', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://doc/dataphyre/runtime/modules/issue/documentation/Dataphyre_Issue.md', 'name'=>'Dataphyre_Issue.md', 'mimeType'=>'text/markdown'],
		];
		$seen_resources=array_fill_keys(array_map(static fn(array $resource): string => (string)($resource['uri'] ?? ''), $resources), true);
		foreach($this->markdown_docs(20) as $path){
			$uri='dataphyre://doc/'.str_replace('\\', '/', $path);
			if(isset($seen_resources[$uri])){
				continue;
			}
			$seen_resources[$uri]=true;
			$resources[]=['uri'=>$uri, 'name'=>basename($path), 'mimeType'=>'text/markdown'];
		}
		return ['resources'=>$resources];
	}

	/**
	 * Reads a registered Dataphyre MCP resource by URI.
	 *
	 * known resources map to bounded repo-local markdown or generated JSON
	 * capability snapshots; dataphyre://doc URIs are resolved through the repo text
	 * reader so resource access remains inside the workspace boundary.
	 */
	private function read_resource(array $params): array {
		$uri=(string)($params['uri'] ?? '');
		$path=match($uri){
				'dataphyre://module-index'=>'dataphyre/docs/MODULES.md',
				'dataphyre://runtime-readme'=>'dataphyre/runtime/README.md',
				'dataphyre://mcp-plan'=>'dataphyre/runtime/modules/mcp/documentation/Dataphyre_MCP.md',
				'dataphyre://ai-guidelines'=>'dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md',
				'dataphyre://agentic-enterprise'=>'dataphyre/docs/AGENTIC_ENTERPRISE.md',
				'dataphyre://mcp-capabilities'=>null,
				'dataphyre://contracts'=>null,
				'dataphyre://panel'=>null,
				'dataphyre://sql-migrations'=>null,
				'dataphyre://sql-migrations/manifest-v3-schema'=>null,
				default=>str_starts_with($uri, 'dataphyre://doc/') ? substr($uri, 16) : '',
			};
		if($uri==='dataphyre://mcp-capabilities'){
			return ['contents'=>[[
				'uri'=>$uri,
				'mimeType'=>'application/json',
				'text'=>$this->json($this->capabilities_snapshot()),
			]]];
		}
		if($uri==='dataphyre://contracts'){
			return ['contents'=>[[
				'uri'=>$uri,
				'mimeType'=>'application/json',
				'text'=>$this->json($this->contract_resource_snapshot()),
			]]];
		}
		if($uri==='dataphyre://panel'){
			return ['contents'=>[[
				'uri'=>$uri,
				'mimeType'=>'application/json',
				'text'=>$this->json($this->panel_resource_snapshot()),
			]]];
		}
		if($uri==='dataphyre://sql-migrations'){
			return ['contents'=>[[
				'uri'=>$uri,
				'mimeType'=>'application/json',
				'text'=>$this->json($this->sql_migration_resource_snapshot()),
			]]];
		}
		if($uri==='dataphyre://sql-migrations/manifest-v3-schema'){
			return ['contents'=>[[
				'uri'=>$uri,
				'mimeType'=>'application/schema+json',
				'text'=>$this->json($this->sql_migration_manifest_schema()),
			]]];
		}
		return ['contents'=>[['uri'=>$uri, 'mimeType'=>'text/markdown', 'text'=>$this->read_repo_text($path, 160000)]]];
	}

	/**
	 * Returns prompt templates exposed by the Dataphyre MCP server.
	 *
	 * prompts are intentionally workflow-oriented and read-only, guiding
	 * clients toward docs, diagnostics, route/config/schema inspection, and release
	 * triage before code mutation.
	 */
	private function list_prompts(): array {
		return ['prompts'=>[
			['name'=>'dataphyre_feature_plan', 'description'=>'Plan ordinary Dataphyre app work with the app-builder planner first, then local module docs, tests, and guardrails.'],
			['name'=>'dataphyre_debug_triage', 'description'=>'Triage a Dataphyre runtime issue using logs, routes, configs, and diagnostics.'],
			['name'=>'dataphyre_panel_workflow', 'description'=>'Discover the affected Panel domain first, then choose an app-builder, recipe, integration, or verification lane.'],
			['name'=>'dataphyre_panel_platform_workflow', 'description'=>'Compose typed Panel platform domains and adapters through source-derived capability contracts.'],
			['name'=>'dataphyre_panel_operations_workflow', 'description'=>'Plan governed Operations OS, operation, migration, workflow, automation, and agent runtime work.'],
			['name'=>'dataphyre_panel_studio_workflow', 'description'=>'Plan Panel Studio, collaboration, media, identity, persistence, and browser lifecycle work.'],
			['name'=>'dataphyre_panel_realtime_workflow', 'description'=>'Plan Panel realtime broker, PDO, Redis Streams, signed subscription, and SSE integration work.'],
			['name'=>'dataphyre_panel_adapter_workflow', 'description'=>'Plan a first-party or application-owned Panel provider adapter and its conformance evidence.'],
			['name'=>'dataphyre_runtime_guidelines', 'description'=>'Load the baseline Dataphyre AI coding guidelines before editing runtime or app code.'],
			['name'=>'dataphyre_release_triage', 'description'=>'Execute the fixed application preflight and triage actionable configuration, dependency, or verification failures.'],
			['name'=>'dataphyre_sql_schema_workflow', 'description'=>'Inspect Dataphyre SQL schemas safely without executing queries or exposing credentials.'],
			['name'=>'dataphyre_sql_migration_workflow', 'description'=>'Discover, validate, and plan application-neutral PostgreSQL migrations, including maintenance expand/contract and SemVer floor semantics, without writing files, opening a database, or executing SQL.'],
			['name'=>'dataphyre_route_manifest_workflow', 'description'=>'Inspect Dataphyre route manifests safely without dispatching handlers.'],
			['name'=>'dataphyre_diagnostics_workflow', 'description'=>'Inspect Dataphyre diagnostics, Tracelog artifacts, and log previews with secret redaction.'],
			['name'=>'dataphyre_contract_workflow', 'description'=>'Discover a Dataphyre executable, PHP type, or serialized payload contract and its focused evidence before changing an implementation.'],
		]];
	}

	/**
	 * Resolves one prompt into MCP prompt-message format.
	 *
	 * prompt lookup delegates to prompt_text and returns a single user
	 * message so clients receive a ready-to-run instruction payload.
	 */
	private function get_prompt(array $params): array {
		$name=(string)($params['name'] ?? '');
		$text=$this->prompt_text($name);
		return ['description'=>$name, 'messages'=>[['role'=>'user', 'content'=>['type'=>'text', 'text'=>$text]]]];
	}

	/**
	 * Maps prompt names to concrete Dataphyre workflow instructions.
	 *
	 * static prompts describe safe inspection workflows, and the runtime
	 * guidelines prompt streams the repo-local AI guidelines document so clients use
	 * the same operating contract as Dataphyre contributors.
	 */
	private function prompt_text(string $name): string {
		$application_agent_lane='Default to application agents building apps: use read-only metadata first, keep app-specific behavior in app code, config, callbacks, dialbacks, plugins, MCP metadata, or application-owned adapters, and use focused app/module verification. Escalate to Dataphyre maintainer workflows only for explicit framework, release-facing, corporate/security/governance, or shared runtime work. ';
		return match($name){
			'dataphyre_feature_plan'=>$application_agent_lane.'For ordinary app creation, Panel CRUD, resource, schema, filter, action, or verification work, start with dataphyre_app_builder_plan_generate payload_profile=compact and read builder_response.first_read first: next_action, files_summary, schema_summary, naming_contract, write_readiness, scaffold_completion_summary, and verification_handoff. Open details only when first_read points there: continuation calls for larger apps via entity_planning.continuation_calls, implementation_recipe/local_convention_probe when ready to write app-owned files, relationship/tenant/control handoffs when signaled, and verification_execution_plan after writes. Add dataphyre_task_pack_generate payload_profile=builder only when focused module docs or a ready prompt are needed. Use dataphyre_mcp_agent_brief_export for compact cold starts or handoffs; use dataphyre_mcp_task_start_pack_export payload_profile=builder only when broader bounded workflow context is needed. Use detail/deep/governance profiles only when explicitly requested for an escalation decision. Then use focused Dataphyre docs/resources and identify modules, public contracts, app-owned extension points, tests, docs, and route-free verification before editing.',
			'dataphyre_debug_triage'=>$application_agent_lane.'Gather app info, route artifacts, config keys, recent diagnostics, and focused docs. Prefer read-only inspection before commands.',
			'dataphyre_panel_workflow'=>$application_agent_lane.'Start with dataphyre_panel_capability_catalog and describe the affected domain or framework area. Use dataphyre_panel_recipe_plan for cross-domain or framework work. For ordinary CRUD/resources, continue through dataphyre_app_builder_plan_generate payload_profile=compact after capability discovery; for providers or shared runtime composition use dataphyre_panel_integration_plan instead. Use dataphyre_panel_surface_graph before combining domains and dataphyre_panel_verification_plan after changed paths and proof scope are known.',
			'dataphyre_panel_platform_workflow'=>$application_agent_lane.'Catalog and describe the selected Panel platform domains, inspect their required features and service bindings, then graph dependencies. Use dataphyre_panel_integration_plan to choose host-owned providers, topology, explicit initialization, transactional activation, rollback, and conformance. Static availability is not runtime configuration or readiness.',
			'dataphyre_panel_operations_workflow'=>$application_agent_lane.'Use dataphyre_panel_recipe_plan mode=operations, then describe operations_os and every selected operation, migration, workflow, automation, agent, policy, observability, and persistence domain. Preserve tenant authority, lease/fence semantics, signed intents, at-most-once mutation boundaries, receipts, retained feeds, kill switches, and host-owned external-effect idempotency.',
			'dataphyre_panel_studio_workflow'=>$application_agent_lane.'Use dataphyre_panel_recipe_plan mode=studio and describe studio, collaboration, media, realtime, security, preferences, and development as needed. Keep portable compilation non-executable, cross trusted materialization explicitly, preserve unsaved peer state, and verify identity, signed intents, persistence, SSR/browser lifecycle, assets, responsive behavior, and teardown.',
			'dataphyre_panel_realtime_workflow'=>$application_agent_lane.'Describe realtime and its data, security, observability, and platform dependencies. Use dataphyre_panel_integration_plan with provider=pdo, redis, callback, or custom and an explicit topology. Select one stream-head authority, document delivery/unknown-ack semantics, and verify retention gaps, source resets, integrity, cancellation, replay rejection, reconnect, and host-owned persistence/failover.',
			'dataphyre_panel_adapter_workflow'=>$application_agent_lane.'Describe the target domain contracts, then call dataphyre_panel_integration_plan with the intended provider and topology. Implement against one typed interface, keep clients/callbacks/connections/credentials host-owned, require explicit initialization, preview and transactionally activate replacements, preserve rollback, and run reusable conformance plus focused exact coverage.',
			'dataphyre_runtime_guidelines'=>$this->read_repo_text('dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md', 120000),
			'dataphyre_release_triage'=>"Run dataphyre_release_check with the application project root, Dataphyre application id, and environment. Treat likely_to_deploy as the deterministic local prediction: fix each configuration, dependency, or verification failure, including managed database identity, realtime callback registration, or scheduler definition registration, before release. Dataphyre Cloud must run the same fixed preflight inside the exact built candidate and prove the three fixed pools, scheduler callback execution, a framework listener roundtrip, strict invalid-Origin rejection by every registered application authorization callback, WebSocket ping/pong and close, signal lifecycle, and source, image, environment, database, and traffic identity before promotion. Never substitute an application release script or caller-selected executable.",
			'dataphyre_sql_schema_workflow'=>$application_agent_lane."Use dataphyre_sql_tables_list, dataphyre_sql_schema_read, dataphyre_sql_clusters_list, dataphyre_sql_query_plan, and dataphyre_sql_query_runner_contract. Do not execute SQL queries, hydrate schemas, or expose credentials. Treat createQueries output and query-plan bounded SQL as preview strings only.",
			'dataphyre_sql_migration_workflow'=>$application_agent_lane."Start with dataphyre_sql_migration_catalog and dataphyre_sql_migration_describe. Read dataphyre://sql-migrations and its manifest-v3 schema. Use dataphyre_sql_migration_scaffold_plan for a no-write file/checksum/manifest-entry plan, then dataphyre_sql_migration_manifest_validate against the completed repo-local database directory. Understand the runtime contract before adapting release code: maintenance applies the pending post-cutoff rolling_expand and rolling_contract suffix in one Dataphyre-owned transaction; each pending rolling_contract requires a caller-verified minimum active release whose exact SemVer precedence meets its manifest minimum_compatible_release, and +build metadata does not affect precedence. These MCP tools never write files, load application code, connect to PostgreSQL, or execute SQL. Application release code owns the PDO connection, release identity, verified fleet-floor derivation, drain/barrier, rollout and apply/rollback authorization.",
			'dataphyre_route_manifest_workflow'=>$application_agent_lane."Use dataphyre_list_routes, dataphyre_route_manifest_read, and dataphyre_route_url_preview. Do not dispatch route handlers. Keep manifest reads bounded with limit and include handler/middleware metadata only when needed.",
			'dataphyre_diagnostics_workflow'=>$application_agent_lane."Use dataphyre_tracelog_artifacts_list before dataphyre_tracelog_read. Keep output bounded, strip HTML unless the caller needs markup, and treat redacted values as intentionally unavailable.",
			'dataphyre_contract_workflow'=>$application_agent_lane."Call dataphyre_contract_catalog with modules set to the affected owner, then call dataphyre_contract_describe with the stable id. Read direct implementers, serialized producers, source evidence, TestKit watches, version resolution, and declared boundaries before editing. Use dataphyre_unit_tests_list with the same modules and kind=code to locate executable contract files. Enumerate dataphyre://contracts available_modules only for a framework-wide graph. Source discovery never executes test definitions; when expanded datasets or computed versions matter, run the returned focused TestKit list command, then execute only the affected owner/path/contract lane.",
			default=>throw new InvalidArgumentException('Unknown prompt: '.$name),
		};
	}

	/**
	 * Reads one MCP JSON-RPC message from the configured stdio stream.
	 *
	 * both newline-delimited JSON and Content-Length framed transports are
	 * supported. The selected transport is remembered so responses are framed in
	 * the same style. Exhausted input returns null; malformed JSON or framing
	 * returns a synthetic read-error payload so the server can report the issue
	 * without silently ending the session.
	 */
	private function read_message($stream): ?array {
		$headers=[];
		$line=fgets($stream);
		if($line===false){
			return null;
		}
		$line=rtrim($line, "\r\n");
		$trimmed_line=ltrim($line);
		if(str_starts_with($trimmed_line, '{') || str_starts_with($trimmed_line, '[')){
			$this->stdio_transport='lines';
			$message=json_decode($line, true);
			return is_array($message) ? $message : $this->read_error(-32700, 'Parse error: malformed JSON request.');
		}
		while(true){
			$line=rtrim($line, "\r\n");
			if($line===''){
				break;
			}
			$parts=explode(':', $line, 2);
			if(count($parts)===2){
				$headers[strtolower(trim($parts[0]))]=trim($parts[1]);
			}
			$line=fgets($stream);
			if($line===false){
				break;
			}
		}
		$this->stdio_transport='headers';
		$length_header=(string)($headers['content-length'] ?? '');
		if(preg_match('/^[1-9][0-9]*$/', $length_header)!==1){
			return $this->read_error(-32600, 'Invalid Request: missing or invalid Content-Length header.');
		}
		$length=(int)$length_header;
		if($length>self::MAX_FRAME_BYTES){
			return $this->read_error(-32600, 'Invalid Request: Content-Length exceeds Dataphyre MCP maximum frame size.');
		}
		$body='';
		while(strlen($body)<$length && !feof($stream)){
			$body.=fread($stream, $length-strlen($body));
		}
		if(strlen($body)<$length){
			return $this->read_error(-32700, 'Parse error: incomplete JSON-RPC frame body.');
		}
		$message=json_decode($body, true);
		return is_array($message) ? $message : $this->read_error(-32700, 'Parse error: malformed JSON-RPC frame body.');
	}

	/**
	 * Builds an internal read-error marker handled by the message loop.
	 *
	 * @return array<string,mixed> Synthetic message carrying a JSON-RPC error.
	 */
	private function read_error(int $code, string $message): array {
		return [
			'__mcp_read_error'=>[
				'code'=>$code,
				'message'=>$message,
			],
		];
	}

	/**
	 * Writes one JSON-RPC response using the active stdio transport.
	 *
	 * responses prefer unicode-preserving JSON, fall back to an internal
	 * encoding error payload if encoding fails, and flush stdout after either line
	 * or Content-Length framing.
	 */
	private function write_json(array $payload): void {
		$body=$this->mcp_json_response_body($payload);
		if($this->stdio_transport==='lines'){
			fwrite(STDOUT, $body."\n");
		}else{
			fwrite(STDOUT, 'Content-Length: '.strlen($body)."\r\n\r\n".$body);
		}
		fflush(STDOUT);
	}

	/** Encodes one protocol response with a stable internal-error fallback. */
	private function mcp_json_response_body(array $payload): string {
		$body=json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return is_string($body)
			? $body
			: '{"jsonrpc":"2.0","error":{"code":-32603,"message":"Unable to encode response"}}';
	}

	/**
	 * Encodes tool payloads as pretty JSON text.
	 *
	 * MCP tool responses use text content for broad client compatibility,
	 * so structured values are pretty-encoded with stable slash/unicode handling and
	 * collapse to null only if JSON encoding fails.
	 */
	private function json(mixed $value): string {
		$json=json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return is_string($json) ? $json : 'null';
	}
}


// Embedders may load the server definition without starting the stdio loop.
// The ordinary CLI entrypoint deliberately remains the default behaviour.
if(defined('DATAPHYRE_MCP_EMBEDDED') && DATAPHYRE_MCP_EMBEDDED===true){
	return;
}

dataphyre_mcp_run_server(getcwd() ?: dirname(__DIR__,5),$argv ?? []);

/**
 * Enforces the CLI-only transport while keeping the SAPI decision testable.
 *
 * @param null|callable(int,string,int):void $respond
 */
function dataphyre_mcp_enforce_cli(string $sapi,?callable $respond=null): bool {
	if(in_array($sapi,['cli','phpdbg'],true)){return true;}
	$respond ??= static function(int $status,string $body,int $exit_code): void {
		http_response_code($status);
		echo $body;
	};
	$respond(404,"Dataphyre MCP is only available from CLI.\n",2);
	return false;
}

/** Runs one server lifecycle with injectable construction for fault contracts. */
function dataphyre_mcp_run_server(string $root,array $arguments,?callable $factory=null): void {
	try{
		$factory ??= static fn(string $server_root,array $server_arguments): object=>new dataphyre_mcp_server($server_root,$server_arguments);
		$server=$factory($root,$arguments);
		$server->run();
		dataphyre_mcp_debug_bootstrap('stop',['clean'=>true]);
	}catch(Throwable $exception){
		dataphyre_mcp_debug_bootstrap('fatal',[
			'type'=>get_class($exception),
			'message'=>$exception->getMessage(),
			'file'=>$exception->getFile(),
			'line'=>$exception->getLine(),
		]);
		throw $exception;
	}
}

/**
 * Writes optional MCP bootstrap diagnostics to a local debug log.
 *
 * logging is disabled unless DATAPHYRE_MCP_DEBUG_LOG is set. A value of
 * 1 writes under .tmp in the current workspace, other values are treated as an
 * explicit path, and filesystem failures are suppressed so logging cannot break
 * server startup.
 */
function dataphyre_mcp_debug_bootstrap(string $event, array $context=[]): void {
	$flag=getenv('DATAPHYRE_MCP_DEBUG_LOG');
	if($flag===false || $flag===''){
		return;
	}
	$path=$flag==='1'
		? (getcwd() ?: dirname(__DIR__, 5)).DIRECTORY_SEPARATOR.'.tmp'.DIRECTORY_SEPARATOR.'dataphyre_mcp_debug.log'
		: $flag;
	$dir=dirname($path);
	if(!is_dir($dir)){
		@mkdir($dir, 0777, true);
	}
	@file_put_contents($path, json_encode([
		'time'=>date('c'),
		'event'=>$event,
		'context'=>$context,
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** Report only errors that actually made PHP terminate abnormally. */
function dataphyre_mcp_debug_shutdown(?array $error): void {
	$fatalTypes=[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR];
	if(is_array($error) && in_array($error['type'] ?? null,$fatalTypes,true)){
		dataphyre_mcp_debug_bootstrap('shutdown_error', $error);
	}
}
