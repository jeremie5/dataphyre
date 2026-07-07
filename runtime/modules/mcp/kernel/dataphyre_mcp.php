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
	$error=error_get_last();
	if(is_array($error)){
		dataphyre_mcp_debug_bootstrap('shutdown_error', $error);
	}
});

if(PHP_SAPI!=='cli'){
	http_response_code(404);
	echo "Dataphyre MCP is only available from CLI.\n";
	exit(2);
}

require_once __DIR__.'/dataphyre_mcp.source.php';
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
		$this->common_root=$this->normalize_path(dirname(__DIR__, 5));
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
				'version'=>'2.0.3',
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
			['uri'=>'dataphyre://doc/common/dataphyre/runtime/modules/panel/documentation/Dataphyre_Panel.md', 'name'=>'Dataphyre_Panel.md', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://doc/common/dataphyre/runtime/modules/sql/documentation/Dataphyre_SQL.md', 'name'=>'Dataphyre_SQL.md', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://doc/common/dataphyre/runtime/modules/routing/documentation/Dataphyre_Routing.md', 'name'=>'Dataphyre_Routing.md', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://doc/common/dataphyre/runtime/modules/tracelog/documentation/Dataphyre_Tracelog.md', 'name'=>'Dataphyre_Tracelog.md', 'mimeType'=>'text/markdown'],
			['uri'=>'dataphyre://doc/common/dataphyre/runtime/modules/issue/documentation/Dataphyre_Issue.md', 'name'=>'Dataphyre_Issue.md', 'mimeType'=>'text/markdown'],
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
				'dataphyre://module-index'=>'common/dataphyre/docs/MODULES.md',
				'dataphyre://runtime-readme'=>'common/dataphyre/runtime/README.md',
				'dataphyre://mcp-plan'=>'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_MCP.md',
				'dataphyre://ai-guidelines'=>'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md',
				'dataphyre://agentic-enterprise'=>'common/dataphyre/docs/AGENTIC_ENTERPRISE.md',
				'dataphyre://mcp-capabilities'=>null,
				default=>str_starts_with($uri, 'dataphyre://doc/') ? substr($uri, 16) : '',
			};
		if($uri==='dataphyre://mcp-capabilities'){
			return ['contents'=>[[
				'uri'=>$uri,
				'mimeType'=>'application/json',
				'text'=>$this->json($this->capabilities_snapshot()),
			]]];
		}
		return ['contents'=>[['uri'=>$uri, 'mimeType'=>'text/markdown', 'text'=>$this->read_repo_text($path, 120000)]]];
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
			['name'=>'dataphyre_panel_workflow', 'description'=>'Build or review a Dataphyre Panel resource/workflow through the app-builder lane and focused Panel checks.'],
			['name'=>'dataphyre_runtime_guidelines', 'description'=>'Load the baseline Dataphyre AI coding guidelines before editing runtime or app code.'],
			['name'=>'dataphyre_release_triage', 'description'=>'Triage Dataphyre release-check failures into docs, module index, headers, JSON, license, and hygiene work.'],
			['name'=>'dataphyre_sql_schema_workflow', 'description'=>'Inspect Dataphyre SQL schemas safely without executing queries or exposing credentials.'],
			['name'=>'dataphyre_route_manifest_workflow', 'description'=>'Inspect Dataphyre route manifests safely without dispatching handlers.'],
			['name'=>'dataphyre_diagnostics_workflow', 'description'=>'Inspect Dataphyre diagnostics, Tracelog artifacts, and log previews with secret redaction.'],
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
			'dataphyre_panel_workflow'=>$application_agent_lane.'Start Panel resource work with dataphyre_app_builder_plan_generate payload_profile=compact and read builder_response.first_read first. For multi-resource Panel apps, follow first_read.scaffold_completion_summary.next_continuation or entity_planning.continuation_calls until deferred_entities is empty, preserving dependency_context and explicit fields. Open relationship_adapter_handoff, tenant_identity_handoff, local_convention_probe, implementation_recipe, verification_execution_plan, and acceptance_review_plan only when the first-read next action or write readiness calls for them. Add dataphyre_task_pack_generate payload_profile=builder only when focused Panel/SQL docs or a ready prompt are needed. Use dataphyre_mcp_agent_brief_export for compact cold starts or handoffs; use dataphyre_mcp_task_start_pack_export payload_profile=builder only when broader bounded workflow context is needed. Use start-pack detail/deep and dataphyre_task_pack_generate payload_profile=governance only for escalation triggers. Use Panel manifests, route-free rendering, focused Panel checks, and live example coverage.',
			'dataphyre_runtime_guidelines'=>$this->read_repo_text('common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md', 120000),
			'dataphyre_release_triage'=>"Release triage is Dataphyre maintainer work, not ordinary application-agent verification. Run dataphyre_release_check, group failures by category, and avoid broad formatting churn. Fix MODULES.md/docs coverage first, then invalid JSON, stale license wording, missing SPDX headers, and explicit hygiene warnings. Re-run release check after each focused group.",
			'dataphyre_sql_schema_workflow'=>$application_agent_lane."Use dataphyre_sql_tables_list, dataphyre_sql_schema_read, dataphyre_sql_clusters_list, dataphyre_sql_query_plan, and dataphyre_sql_query_runner_contract. Do not execute SQL queries, hydrate schemas, or expose credentials. Treat createQueries output and query-plan bounded SQL as preview strings only.",
			'dataphyre_route_manifest_workflow'=>$application_agent_lane."Use dataphyre_list_routes, dataphyre_route_manifest_read, and dataphyre_route_url_preview. Do not dispatch route handlers. Keep manifest reads bounded with limit and include handler/middleware metadata only when needed.",
			'dataphyre_diagnostics_workflow'=>$application_agent_lane."Use dataphyre_tracelog_artifacts_list before dataphyre_tracelog_read. Keep output bounded, strip HTML unless the caller needs markup, and treat redacted values as intentionally unavailable.",
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
		if(feof($stream) && $headers===[]){
			return null;
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
		$body=json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if(!is_string($body)){
			$body='{"jsonrpc":"2.0","error":{"code":-32603,"message":"Unable to encode response"}}';
		}
		if($this->stdio_transport==='lines'){
			fwrite(STDOUT, $body."\n");
		}else{
			fwrite(STDOUT, 'Content-Length: '.strlen($body)."\r\n\r\n".$body);
		}
		fflush(STDOUT);
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


try{
	$server=new dataphyre_mcp_server(getcwd() ?: dirname(__DIR__, 5), $argv ?? []);
	$server->run();
	dataphyre_mcp_debug_bootstrap('stop', ['clean'=>true]);
}
catch(Throwable $exception){
	dataphyre_mcp_debug_bootstrap('fatal', [
		'type'=>get_class($exception),
		'message'=>$exception->getMessage(),
		'file'=>$exception->getFile(),
		'line'=>$exception->getLine(),
	]);
	throw $exception;
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
