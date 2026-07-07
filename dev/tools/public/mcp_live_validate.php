<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
	fwrite(STDERR, "Dataphyre MCP live validator is CLI-only.\n");
	exit(2);
}

if(in_array('--help', $argv, true) || in_array('-h', $argv, true)){
	echo <<<'HELP'
Usage:
  php dev/tools/public/mcp_live_validate.php [--php <path-or-command>]

Options:
  --php       PHP executable used to start the MCP server. Defaults to the
              current PHP binary.
  -h, --help  Show this help text.

Runs a bounded stdio validation against the Dataphyre MCP server. The tool can
run from an embedded common/dataphyre tree or a standalone Git worktree.

HELP;
	exit(0);
}

$root=dataphyre_mcp_live_validate_workspace_root(__DIR__);
if(!is_string($root)){
	fwrite(STDERR, "Unable to resolve embedded Dataphyre Git worktree root. Expected common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php below the workspace root.\n");
	exit(2);
}

$server=$root.'/common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php';
if(!is_file($server)){
	fwrite(STDERR, "MCP server not found at {$server}.\n");
	exit(2);
}

$php=dataphyre_mcp_live_validate_option($argv, '--php') ?? PHP_BINARY;
$checks=[
	[
		'name'=>'initialize over stdio',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>1,
			'method'=>'initialize',
			'params'=>[
				'protocolVersion'=>'2025-11-25',
				'capabilities'=>[],
				'clientInfo'=>['name'=>'dataphyre-mcp-live-validator', 'version'=>'1.0.0'],
			],
		],
		'assert'=>static function(array $response): void {
			if(($response['result']['serverInfo']['name'] ?? null)!=='dataphyre-mcp'){
				throw new RuntimeException('initialize did not return dataphyre-mcp serverInfo.');
			}
		},
	],
	[
		'name'=>'tools/list exposes core client surfaces',
		'request'=>['jsonrpc'=>'2.0', 'id'=>2, 'method'=>'tools/list', 'params'=>[]],
		'assert'=>static function(array $response): void {
			$tools=is_array($response['result']['tools'] ?? null) ? $response['result']['tools'] : [];
			$names=array_map(static fn(array $tool): string => (string)($tool['name'] ?? ''), $tools);
			foreach(['dataphyre_mcp_doctor', 'dataphyre_mcp_manifest_export', 'dataphyre_mcp_prompt_catalog', 'dataphyre_docs_index_plan', 'dataphyre_embeddings_readiness_plan', 'dataphyre_remote_docs_readiness_plan', 'dataphyre_datadoc_runtime_readiness_plan', 'dataphyre_openapi_runtime_readiness_plan', 'dataphyre_route_runtime_provenance_plan', 'dataphyre_sql_runtime_readiness_plan', 'dataphyre_browser_diagnostics_readiness_plan', 'dataphyre_verification_surface_catalog', 'dataphyre_apply_runtime_readiness_plan', 'dataphyre_mcp_skill_catalog', 'dataphyre_mcp_skill_manifest_export', 'dataphyre_mcp_skill_registration_audit', 'dataphyre_mcp_skill_pack_export', 'dataphyre_mcp_skill_install_plan', 'dataphyre_mcp_skill_file_install_plan', 'dataphyre_mcp_client_config_summary', 'dataphyre_mcp_client_install_checklist', 'dataphyre_mcp_client_config_install_plan', 'dataphyre_mcp_smoke_test_export', 'dataphyre_mcp_client_onboarding_pack', 'dataphyre_mcp_client_troubleshoot', 'dataphyre_mcp_client_compatibility_matrix', 'dataphyre_mcp_client_config_audit', 'dataphyre_mcp_safety_boundary_report', 'dataphyre_mcp_surface_changelog', 'dataphyre_mcp_tool_call_examples_export', 'dataphyre_mcp_workflow_playbook_export', 'dataphyre_mcp_workflow_readiness_audit', 'dataphyre_mcp_workflow_session_export', 'dataphyre_mcp_workflow_transcript_schema_export', 'dataphyre_mcp_workflow_state_schema_export', 'dataphyre_mcp_workflow_state_audit', 'dataphyre_mcp_workflow_state_summary_export', 'dataphyre_mcp_workflow_state_transition_export', 'dataphyre_mcp_workflow_state_sync_pack_export', 'dataphyre_mcp_workflow_state_timeline_export', 'dataphyre_mcp_workflow_state_resume_brief_export', 'dataphyre_mcp_workflow_transcript_audit', 'dataphyre_mcp_workflow_transcript_summary_export', 'dataphyre_mcp_workflow_checkpoint_export', 'dataphyre_mcp_workflow_handoff_pack_export', 'dataphyre_mcp_workflow_catalog', 'dataphyre_mcp_workflow_lifecycle_export', 'dataphyre_mcp_workflow_next_action_export', 'dataphyre_mcp_workflow_recommend', 'dataphyre_mcp_workflow_recommendation_handoff_export', 'dataphyre_mcp_task_start_pack_export', 'dataphyre_mcp_agent_brief_export', 'dataphyre_mcp_status_board', 'dataphyre_mcp_live_validate', 'dataphyre_mcp_verify_all'] as $required){
				if(!in_array($required, $names, true)){
					throw new RuntimeException("tools/list is missing {$required}.");
				}
			}
			if(count($names)<60){
				throw new RuntimeException('tools/list returned too few tools for the current MCP surface.');
			}
			$appBuilderTool=null;
			$applicationInfoTool=null;
			foreach($tools as $tool){
				if(($tool['name'] ?? null)==='dataphyre_app_builder_plan_generate'){
					$appBuilderTool=$tool;
					break;
				}
			}
			foreach($tools as $tool){
				if(($tool['name'] ?? null)==='dataphyre_application_info'){
					$applicationInfoTool=$tool;
					break;
				}
			}
			$appBuilderProperties=is_array($appBuilderTool['inputSchema']['properties'] ?? null) ? $appBuilderTool['inputSchema']['properties'] : [];
			$maxEntitiesDescription=(string)($appBuilderProperties['max_entities']['description'] ?? '');
			$startPackTool=null;
			foreach($tools as $tool){
				if(($tool['name'] ?? null)==='dataphyre_mcp_task_start_pack_export'){
					$startPackTool=$tool;
					break;
				}
			}
			$startPackProperties=is_array($startPackTool['inputSchema']['properties'] ?? null) ? $startPackTool['inputSchema']['properties'] : [];
			$startPackPayloadDescription=(string)($startPackProperties['payload_profile']['description'] ?? '');
			if(
				(($appBuilderProperties['field_scope']['type'] ?? null)!=='string')
				|| (($appBuilderProperties['dependency_context']['type'] ?? null)!=='object')
				|| (($appBuilderProperties['reuse_fields_from_original']['type'] ?? null)!=='boolean')
				|| (($appBuilderProperties['max_entities']['type'] ?? null)!=='integer')
				|| !str_contains($maxEntitiesDescription, 'default 4, maximum 12')
				|| !str_contains($maxEntitiesDescription, 'larger first response')
				|| !str_contains($maxEntitiesDescription, 'continuation calls')
				|| !str_contains($maxEntitiesDescription, 'dependency_context')
				|| (($appBuilderTool['inputSchema']['additionalProperties'] ?? null)!==false)
				|| !str_contains($startPackPayloadDescription, 'detail adds contracts and discovery while app-builder bulk stays paginated')
				|| !str_contains($startPackPayloadDescription, 'deep is explicit escalation evidence')
				|| !str_contains((string)($applicationInfoTool['description'] ?? ''), 'copy_safe_startup_summary')
				|| !str_contains((string)($applicationInfoTool['description'] ?? ''), 'instead of root or raw git output')
			){
				throw new RuntimeException('tools/list did not advertise app-builder continuation and chunk-budget schema metadata.');
			}
		},
	],
	[
		'name'=>'prompts/list exposes workflow prompts',
		'request'=>['jsonrpc'=>'2.0', 'id'=>3, 'method'=>'prompts/list', 'params'=>[]],
		'assert'=>static function(array $response): void {
			$names=array_map(static fn(array $prompt): string => (string)($prompt['name'] ?? ''), $response['result']['prompts'] ?? []);
			foreach(['dataphyre_runtime_guidelines', 'dataphyre_sql_schema_workflow', 'dataphyre_route_manifest_workflow'] as $required){
				if(!in_array($required, $names, true)){
					throw new RuntimeException("prompts/list is missing {$required}.");
				}
			}
		},
	],
	[
		'name'=>'resources/list exposes core resources',
		'request'=>['jsonrpc'=>'2.0', 'id'=>4, 'method'=>'resources/list', 'params'=>[]],
		'assert'=>static function(array $response): void {
			$uris=array_map(static fn(array $resource): string => (string)($resource['uri'] ?? ''), $response['result']['resources'] ?? []);
			foreach(['dataphyre://ai-guidelines', 'dataphyre://mcp-capabilities', 'dataphyre://mcp-plan'] as $required){
				if(!in_array($required, $uris, true)){
					throw new RuntimeException("resources/list is missing {$required}.");
				}
			}
		},
	],
	[
		'name'=>'dataphyre_mcp_doctor passes through tools/call',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>5,
			'method'=>'tools/call',
			'params'=>['name'=>'dataphyre_mcp_doctor', 'arguments'=>[]],
		],
		'assert'=>static function(array $response): void {
			$data=dataphyre_mcp_live_validate_tool_json($response);
			if(($data['passed'] ?? null)!==true || (int)($data['failed_count'] ?? 1)!==0){
				throw new RuntimeException('dataphyre_mcp_doctor reported a failing MCP health check.');
			}
		},
	],
	[
		'name'=>'application info exposes copy-safe default handoff',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>51,
			'method'=>'tools/call',
			'params'=>['name'=>'dataphyre_application_info', 'arguments'=>[]],
		],
		'assert'=>static function(array $response): void {
			$data=dataphyre_mcp_live_validate_tool_json($response);
			if(
				($data['write_policy'] ?? null)!=='read_only'
				|| ($data['execution'] ?? null)!=='bounded_local_git_status'
				|| (($data['default_handoff'] ?? null)!=='copy_safe_startup_summary')
				|| (($data['startup_safety']['default_handoff'] ?? null)!=='copy_safe_startup_summary')
				|| (($data['copy_safe_startup_summary']['share_default'] ?? null)!=='copy_safe_summary_only')
				|| !in_array('root', $data['copy_safe_startup_summary']['not_included'] ?? [], true)
				|| !in_array('git.stdout', $data['copy_safe_startup_summary']['not_included'] ?? [], true)
				|| !in_array('Dataphyre benchmark output', $data['copy_safe_startup_summary']['not_included'] ?? [], true)
			){
				throw new RuntimeException('application info did not expose copy_safe_startup_summary as the default handoff.');
			}
		},
	],
	[
		'name'=>'prompt catalog works through tools/call',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>6,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_mcp_prompt_catalog',
				'arguments'=>['names'=>['dataphyre_runtime_guidelines']],
			],
		],
		'assert'=>static function(array $response): void {
			$data=dataphyre_mcp_live_validate_tool_json($response);
			if(
				($data['catalog_type'] ?? null)!=='dataphyre_mcp_prompt_catalog'
				|| ($data['execution'] ?? null)!=='not_executed'
				|| (($data['prompts'][0]['name'] ?? null)!=='dataphyre_runtime_guidelines')
			){
				throw new RuntimeException('prompt catalog did not return runtime guideline metadata.');
			}
		},
	],
	[
		'name'=>'API recipe catalog works through tools/call',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>7,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_api_recipe_catalog',
				'arguments'=>['recipe'=>'controller_backed'],
			],
		],
		'assert'=>static function(array $response): void {
			$data=dataphyre_mcp_live_validate_tool_json($response);
			if(
				($data['catalog_type'] ?? null)!=='api_recipe_catalog'
				|| ($data['execution'] ?? null)!=='not_executed'
				|| (($data['recipes']['controller_backed']['title'] ?? null)!=='Controller-backed endpoint')
			){
				throw new RuntimeException('API recipe catalog did not return the controller-backed recipe.');
			}
		},
	],
	[
		'name'=>'API scaffold plan works through tools/call',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>8,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_api_scaffold_plan',
				'arguments'=>[
					'name'=>'Example Project Token Issue',
					'path'=>'/example/api/projects/{project}/tokens',
					'methods'=>['POST'],
					'auth'=>'api_key',
					'group'=>'example',
				],
			],
		],
		'assert'=>static function(array $response): void {
			$data=dataphyre_mcp_live_validate_tool_json($response);
			$verificationTodo=is_array($data['verification_plan']['verification_todo'] ?? null) ? $data['verification_plan']['verification_todo'] : [];
			$todoTools=array_values(array_map(static fn(array $todo): string => (string)($todo['tool'] ?? ''), $verificationTodo));
			if(
				($data['type'] ?? null)!=='api_endpoint'
				|| (($data['endpoint']['path'] ?? null)!=='/example/api/projects/{project}/tokens')
				|| (($data['endpoint']['auth_hint'] ?? null)!=='api_key')
				|| (($data['verification_plan']['execution'] ?? null)!=='not_executed')
				|| !in_array('dataphyre_api_docs_static_summary', $todoTools, true)
				|| !in_array('dataphyre_route_manifest_read', $todoTools, true)
				|| !in_array('dataphyre_route_url_preview', $todoTools, true)
				|| !in_array('dataphyre_php_lint', $todoTools, true)
			){
				throw new RuntimeException('API scaffold plan did not return the expected endpoint contract.');
			}
		},
	],
	[
		'name'=>'capabilities resource reads over stdio',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>9,
			'method'=>'resources/read',
			'params'=>['uri'=>'dataphyre://mcp-capabilities'],
		],
		'assert'=>static function(array $response): void {
			$text=(string)($response['result']['contents'][0]['text'] ?? '');
			$data=json_decode($text, true);
			if(!is_array($data) || ($data['default_safety'] ?? null)!=='read_only'){
				throw new RuntimeException('capabilities resource did not return the read-only safety snapshot.');
			}
		},
	],
	[
		'name'=>'app builder compact first call works through tools/call',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>10,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>[
					'task'=>'build an internal ticket tracker app with Projects and Tickets',
					'payload_profile'=>'compact',
				],
			],
		],
		'assert'=>static function(array $response): void {
			$data=dataphyre_mcp_live_validate_tool_json($response);
			if(
				array_key_first($data)!=='builder_response'
				|| (($data['payload_profile'] ?? null)!=='compact')
				|| (($data['code_skeletons_included'] ?? null)!==false)
				|| (($data['details_collapsed'] ?? null)!==true)
				|| isset($data['builder_plan'])
				|| !in_array('builder_plan', $data['omitted_default_fields'] ?? [], true)
				|| !str_contains((string)($data['open_full_plan_with'] ?? ''), 'payload_profile=full')
				|| (($data['context_links']['full_builder_plan'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=full')
				|| (($data['builder_response']['first_read']['title'] ?? null)!=='Builder first read')
				|| (
					empty($data['builder_response']['files'])
					&& (($data['builder_response']['files_summary']['total'] ?? 0)<1)
				)
				|| (
					empty($data['builder_response']['schema'])
					&& (($data['builder_response']['schema_summary']['total'] ?? 0)<1)
				)
				|| (($data['builder_response']['next_action']['owner'] ?? null)!=='consuming_application')
				|| !isset($data['builder_response']['next_action']['resume_cursor'])
				|| (
					!isset($data['builder_response']['implementation_matrix'])
					&& (($data['builder_response']['compact_detail_policy']['collapsed_sections']['implementation_matrix']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=full')
				)
				|| array_key_exists('implementation_recipe', $data['builder_response'])
				|| (($data['builder_response']['compact_detail_policy']['collapsed_sections']['implementation_recipe']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=full')
				|| (($data['builder_response']['compact_detail_policy']['collapsed_sections']['implementation_recipe']['count_label'] ?? null)!=='items')
				|| (
					(($data['builder_response']['prewrite_checklist']['resolution_plan']['owner'] ?? null)!=='consuming_application')
					&& (($data['builder_response']['compact_detail_policy']['collapsed_sections']['prewrite_checklist']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=full')
				)
				|| array_key_exists('local_convention_probe', $data['builder_response'])
				|| array_key_exists('verification_execution_plan', $data['builder_response'])
				|| (($data['builder_response']['compact_detail_policy']['collapsed_sections']['verification_execution_plan']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=full')
				|| (
					(($data['builder_response']['acceptance_review_plan']['owner'] ?? null)!=='consuming_application')
					&& (($data['builder_response']['compact_detail_policy']['collapsed_sections']['acceptance_review_plan']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=full')
				)
				|| !isset($data['builder_response']['verification_handoff'])
				|| array_key_exists('verification_recovery_plan', $data['builder_response'])
			){
				throw new RuntimeException('compact app-builder first call did not preserve the lightweight app-agent contract.');
			}
			dataphyre_mcp_live_validate_compact_app_builder_refs($data['builder_response'], 'compact app-builder first call');
			dataphyre_mcp_live_validate_no_stale_builder_first_read_refs($data['builder_response'], 'compact app-builder first call builder_response');
		},
	],
	[
		'name'=>'app builder compact detail page works through tools/call',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>1001,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>[
					'task'=>'build an internal ticket tracker app with Projects and Tickets',
					'payload_profile'=>'compact',
					'detail_page'=>'implementation',
				],
			],
		],
		'assert'=>static function(array $response): void {
			$data=dataphyre_mcp_live_validate_tool_json($response);
			$page=is_array($data['builder_response']['selected_detail_page'] ?? null) ? $data['builder_response']['selected_detail_page'] : [];
			$pageData=is_array($page['data'] ?? null) ? $page['data'] : [];
			if(
				(($data['payload_profile'] ?? null)!=='compact')
				|| isset($data['builder_plan'])
				|| (($page['status'] ?? null)!=='selected')
				|| (($page['page'] ?? null)!=='implementation')
				|| !array_key_exists('implementation_matrix', $pageData)
				|| !array_key_exists('implementation_recipe', $pageData)
				|| !array_key_exists('write_handoff', $pageData)
				|| (($data['builder_response']['semantic_contract']['compact_mode'] ?? null)!=='first_page_plus_machine_usable_contracts')
				|| !in_array('implementation_recipe', $data['builder_response']['semantic_contract']['may_paginate'] ?? [], true)
			){
				throw new RuntimeException('compact app-builder detail_page did not materialize a single implementation page over stdio.');
			}
			dataphyre_mcp_live_validate_compact_app_builder_refs($data['builder_response'], 'compact app-builder detail page');
			dataphyre_mcp_live_validate_no_stale_builder_first_read_refs($data['builder_response'], 'compact app-builder detail page builder_response');
		},
	],
	[
		'name'=>'app builder complex compact detail page stays bounded',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>1002,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>[
					'task'=>'build a procurement operations app with organizations, vendors, products, purchase requests, approval steps, purchase orders, shipments, documents, and users',
					'payload_profile'=>'compact',
					'detail_page'=>'implementation',
					'entities'=>['Organization', 'Vendor', 'Product', 'PurchaseRequest', 'ApprovalStep', 'PurchaseOrder', 'Shipment', 'Document'],
					'fields'=>[
						'Organization'=>['name'=>'string required', 'status'=>'enum active,paused,archived default active', 'billing_email'=>'string nullable'],
						'Vendor'=>['organization_id'=>'foreign key to organizations required', 'name'=>'string required', 'status'=>'enum draft,approved,suspended default draft', 'tax_id'=>'string nullable not_foreign_key'],
						'Product'=>['vendor_id'=>'foreign key to vendors required', 'sku'=>'string required unique', 'name'=>'string required', 'price'=>'decimal required'],
						'PurchaseRequest'=>['organization_id'=>'foreign key to organizations required', 'requester_id'=>'foreign key to users required', 'status'=>'enum draft,pending,approved,rejected default draft', 'total_estimate'=>'decimal nullable'],
						'ApprovalStep'=>['purchase_request_id'=>'foreign key to purchase requests required', 'approver_id'=>'foreign key to users required', 'status'=>'enum pending,approved,rejected default pending', 'decided_at'=>'datetime nullable'],
						'PurchaseOrder'=>['vendor_id'=>'foreign key to vendors required', 'purchase_request_id'=>'foreign key to purchase requests required', 'po_number'=>'string required unique', 'status'=>'enum open,sent,closed,cancelled default open'],
						'Shipment'=>['purchase_order_id'=>'foreign key to purchase orders required', 'tracking_number'=>'string nullable not_foreign_key', 'status'=>'enum pending,in_transit,received default pending', 'received_at'=>'datetime nullable'],
						'Document'=>['owner_type'=>'string required', 'owner_id'=>'integer required not_foreign_key', 'storage_reference'=>'json required', 'document_type'=>'enum quote,invoice,contract,receipt default quote'],
					],
				],
			],
		],
		'assert'=>static function(array $response): void {
			$text=(string)($response['result']['content'][0]['text'] ?? '');
			$data=dataphyre_mcp_live_validate_tool_json($response);
			$page=is_array($data['builder_response']['selected_detail_page'] ?? null) ? $data['builder_response']['selected_detail_page'] : [];
			$pageData=is_array($page['data'] ?? null) ? $page['data'] : [];
			if(
				(($data['payload_profile'] ?? null)!=='compact')
				|| isset($data['builder_plan'])
				|| strlen($text)>(int)($data['builder_response']['payload_budget']['max_response_chars'] ?? 60000)
				|| (($page['status'] ?? null)!=='selected')
				|| (($page['page'] ?? null)!=='implementation')
				|| (($page['page_budget']['applied'] ?? null)!==true)
				|| (($page['page_budget']['max_detail_chars'] ?? null)!==30000)
				|| (int)($page['page_budget']['current_chars'] ?? 30001)>30000
				|| ((int)($data['builder_response']['compact_detail_policy']['detail_counts']['implementation_items']['count'] ?? 0)<1)
				|| (($data['builder_response']['compact_detail_policy']['detail_counts']['implementation_items']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation')
				|| !array_key_exists('implementation_recipe', $pageData)
				|| !array_key_exists('write_handoff', $pageData)
				|| array_key_exists('verification_execution_plan', $pageData)
				|| count(is_array($pageData['implementation_recipe']['items'] ?? null) ? $pageData['implementation_recipe']['items'] : [])>12
				|| count(is_array($pageData['implementation_matrix']['work_items'] ?? null) ? $pageData['implementation_matrix']['work_items'] : [])>12
			){
				throw new RuntimeException('complex compact app-builder detail_page did not stay bounded and page-shaped: '.json_encode([
					'bytes'=>strlen($text),
					'page_budget'=>$page['page_budget'] ?? null,
					'page_sections'=>array_keys($pageData),
					'recipe_count'=>count(is_array($pageData['implementation_recipe']['items'] ?? null) ? $pageData['implementation_recipe']['items'] : []),
					'compact_payload_budget_enforced'=>$data['compact_payload_budget_enforced'] ?? null,
					'compact_payload_collapsed_sections'=>$data['compact_payload_collapsed_sections'] ?? null,
					'focused_context_keys'=>array_keys(is_array($data['focused_context'] ?? null) ? $data['focused_context'] : []),
					'focused_docs'=>$data['focused_context']['docs'] ?? null,
					'detail_counts'=>$data['builder_response']['compact_detail_policy']['detail_counts'] ?? null,
					'top_keys'=>array_keys($data),
				], JSON_UNESCAPED_SLASHES));
			}
			dataphyre_mcp_live_validate_compact_app_builder_refs($data['builder_response'], 'complex compact app-builder detail page');
			dataphyre_mcp_live_validate_no_stale_builder_first_read_refs($data['builder_response'], 'complex compact app-builder detail page builder_response');
		},
	],
	[
		'name'=>'app builder compact governance detail page is actionable',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>1003,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>[
					'task'=>'build an internal billing policy app with tenants, plans, entitlements, audit records, admin Panel CRUD, and verification',
					'payload_profile'=>'compact',
					'detail_page'=>'governance',
					'entities'=>['Tenant', 'Plan', 'Entitlement', 'AuditRecord'],
					'fields'=>[
						'Tenant'=>['name'=>'string required', 'status'=>'enum active,suspended default active'],
						'Plan'=>['tenant_id'=>'foreign key to tenants required', 'name'=>'string required', 'price_minor'=>'integer required'],
						'Entitlement'=>['plan_id'=>'foreign key to plans required', 'key'=>'string required', 'limit'=>'integer nullable'],
						'AuditRecord'=>['tenant_id'=>'foreign key to tenants required', 'actor_id'=>'foreign key to users nullable', 'event_type'=>'string required', 'metadata'=>'json required'],
					],
				],
			],
		],
		'assert'=>static function(array $response): void {
			$data=dataphyre_mcp_live_validate_tool_json($response);
			$page=is_array($data['builder_response']['selected_detail_page'] ?? null) ? $data['builder_response']['selected_detail_page'] : [];
			$pageData=is_array($page['data'] ?? null) ? $page['data'] : [];
			if(
				(($data['payload_profile'] ?? null)!=='compact')
				|| isset($data['builder_plan'])
				|| (($page['status'] ?? null)!=='selected')
				|| (($page['page'] ?? null)!=='governance')
				|| !array_key_exists('extension_boundary_summary', $pageData)
				|| (($pageData['extension_boundary_summary']['placement_decision']['runtime_internal_allowed'] ?? null)!==false)
				|| !array_key_exists('governance_lane', $pageData)
				|| (($pageData['governance_lane']['collapsed_by_default'] ?? null)!==true)
				|| !array_key_exists('enterprise_audit', $pageData)
				|| (($pageData['enterprise_audit']['open_with'] ?? null)!=='dataphyre_mcp_enterprise_adoption_audit')
				|| (($pageData['enterprise_audit']['ordinary_app_default'] ?? null)!=='not_required')
				|| array_key_exists('enterprise_audit', $page['omitted_sections'] ?? [])
				|| str_contains(json_encode($page['omitted_sections'] ?? [], JSON_UNESCAPED_SLASHES) ?: '', 'not_available_in_builder_plan')
			){
				throw new RuntimeException('compact app-builder governance detail_page did not return actionable governance pointers: '.json_encode([
					'page'=>$page,
				], JSON_UNESCAPED_SLASHES));
			}
			dataphyre_mcp_live_validate_compact_app_builder_refs($data['builder_response'], 'compact app-builder governance detail page');
		},
	],
	[
		'name'=>'app builder API endpoint works through tools/call',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>101,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>[
					'task'=>'build REST API endpoint for showing an order with OpenAPI docs',
					'payload_profile'=>'compact',
					'scaffold_type'=>'api_endpoint',
					'name'=>'Orders Show',
					'path'=>'/api/orders/{order_id}',
					'methods'=>['GET'],
					'auth'=>'jwt',
				],
			],
		],
		'assert'=>static function(array $response): void {
			$data=dataphyre_mcp_live_validate_tool_json($response);
			$todoTools=array_values(array_map(static fn(array $todo): string => (string)($todo['tool'] ?? ''), is_array($data['builder_response']['verification_todo'] ?? null) ? $data['builder_response']['verification_todo'] : []));
			if(
				(($data['payload_profile'] ?? null)!=='compact')
				|| (($data['code_skeletons_included'] ?? null)!==false)
				|| (($data['details_collapsed'] ?? null)!==true)
				|| isset($data['builder_plan'])
				|| (($data['builder_response']['first_read']['title'] ?? null)!=='Builder first read')
				|| (($data['builder_response']['app_path_context']['placeholder_mode'] ?? null)!==true)
				|| (
					!in_array('applications/<app>/backend/dataphyre/api/OrdersShowEndpoints.php', $data['builder_response']['files'] ?? [], true)
					&& (($data['builder_response']['files_summary']['total'] ?? 0)<1)
				)
				|| (($data['builder_response']['verification_handoff']['owner'] ?? null)!=='consuming_application')
				|| (
					!in_array('dataphyre_api_docs_static_summary', $todoTools, true)
					&& (($data['builder_response']['compact_detail_policy']['collapsed_sections']['verification_todo']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=full')
				)
				|| (
					!in_array('dataphyre_route_manifest_read', $todoTools, true)
					&& (($data['builder_response']['compact_detail_policy']['collapsed_sections']['verification_todo']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=full')
				)
			){
				throw new RuntimeException('API endpoint app-builder call did not preserve the lightweight focused verification contract.');
			}
		},
	],
	[
		'name'=>'focused verification catalog works through tools/call',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>11,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_verification_surface_catalog',
				'arguments'=>[
					'modules'=>['panel'],
					'limit'=>20,
				],
			],
		],
		'assert'=>static function(array $response): void {
			$data=dataphyre_mcp_live_validate_tool_json($response);
			if(
				($data['catalog_type'] ?? null)!=='dataphyre_verification_surface_catalog'
				|| ($data['write_policy'] ?? null)!=='read_only'
				|| ($data['execution'] ?? null)!=='not_executed'
				|| (($data['verification_handoff']['owner'] ?? null)!=='consuming_application')
				|| !in_array('pass_fail_summary', $data['verification_handoff']['copy_safe_fields'] ?? [], true)
				|| !in_array('dataphyre_mcp_verify_all output', $data['verification_handoff']['not_included'] ?? [], true)
				|| (($data['verification_next_action']['owner'] ?? null)!=='consuming_application')
				|| !in_array(($data['verification_next_action']['status'] ?? null), ['run_bounded_mcp_wrapper', 'inspect_unit_manifest', 'triage_diagnostic_surface', 'no_focused_surface_selected'], true)
			){
				throw new RuntimeException('focused verification catalog did not preserve the app-owned copy-safe handoff contract.');
			}
		},
	],
	[
		'name'=>'app tool-call examples preserve bounded profile guidance',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>1101,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_mcp_tool_call_examples_export',
				'arguments'=>['workflow'=>'app'],
			],
		],
		'assert'=>static function(array $response): void {
			$data=dataphyre_mcp_live_validate_tool_json($response);
			$appPolicy=is_array($data['workflow_policy']['app'] ?? null) ? $data['workflow_policy']['app'] : [];
			if(
				($data['export_type'] ?? null)!=='dataphyre_mcp_tool_call_examples_export'
				|| (($data['workflow'] ?? null)!=='app')
				|| (($appPolicy['first_copy'] ?? null)!=='dataphyre_app_builder_plan_generate')
				|| (($appPolicy['broader_cold_start'] ?? null)!=='dataphyre_mcp_task_start_pack_export payload_profile=builder')
				|| !str_contains((string)($appPolicy['detail_profile'] ?? ''), 'payload_profile=detail adds contracts and discovery while app-builder bulk stays paginated')
				|| !str_contains((string)($appPolicy['deep_profile'] ?? ''), 'payload_profile=deep only for explicit escalation evidence')
				|| !str_contains((string)($data['workflow_policy']['validation']['ordinary_app_verification'] ?? ''), 'focused app/module checks')
				|| (($data['workflow_policy']['validation']['publication_validation'] ?? null)!=='MCP/release-surface publication validation')
			){
				throw new RuntimeException('app tool-call examples did not preserve bounded profile guidance.');
			}
		},
	],
	[
		'name'=>'workflow playbook preserves bounded profile guidance',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>1102,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_mcp_workflow_playbook_export',
				'arguments'=>['workflow'=>'feature'],
			],
		],
		'assert'=>static function(array $response): void {
			$data=dataphyre_mcp_live_validate_tool_json($response);
			$policy=is_array($data['playbook_policy'] ?? null) ? $data['playbook_policy'] : [];
			if(
				($data['export_type'] ?? null)!=='dataphyre_mcp_workflow_playbook_export'
				|| (($data['workflow'] ?? null)!=='feature')
				|| (($policy['ordinary_app_first_step'] ?? null)!=='dataphyre_app_builder_plan_generate')
				|| (($policy['ordinary_app_broader_cold_start'] ?? null)!=='dataphyre_mcp_task_start_pack_export payload_profile=builder')
				|| !str_contains((string)($policy['ordinary_app_detail_profile'] ?? ''), 'payload_profile=detail adds contracts and discovery while app-builder bulk stays paginated')
				|| !str_contains((string)($policy['ordinary_app_deep_profile'] ?? ''), 'payload_profile=deep only for explicit escalation evidence')
				|| !str_contains((string)($policy['ordinary_app_deep_profile'] ?? ''), 'Dataphyre hot-path work')
				|| !str_contains((string)($policy['ordinary_app_verification'] ?? ''), 'focused app/module checks')
				|| !in_array('MCP/release-surface publication validation', $policy['not_ordinary_app_agent_requirements'] ?? [], true)
				|| array_key_exists('application_agent_operating_contract', $data)
				|| array_key_exists('tool_audience_boundaries', $data)
			){
				throw new RuntimeException('workflow playbook did not preserve bounded detail/deep profile guidance.');
			}
		},
	],
	[
		'name'=>'readiness report distinguishes compact agent brief from broader summaries',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>1103,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_mcp_readiness_report',
				'arguments'=>[],
			],
		],
		'assert'=>static function(array $response): void {
			$data=dataphyre_mcp_live_validate_tool_json($response);
			$agentBriefContract=(string)($data['app_builder_readiness']['compact_handoff_contract']['agent_brief'] ?? '');
			if(
				($data['server'] ?? null)!=='dataphyre-mcp'
				|| ($data['generated_from'] ?? null)!=='live tool, prompt, resource, and skill registration'
				|| (($data['app_builder_readiness']['compact_handoff'] ?? null)!=='dataphyre_mcp_agent_brief_export')
				|| !str_contains($agentBriefContract, 'without inlining builder_view/app_builder_lane/app_builder_summary')
				|| !str_contains($agentBriefContract, 'shortened top-level app_builder_next_action refs')
				|| !str_contains($agentBriefContract, 'full resume_cursor bodies')
				|| !str_contains($agentBriefContract, 'app_builder_summary is a broader start/task-pack context')
				|| !str_contains($agentBriefContract, 'not a field agents should expect on compact agent briefs')
			){
				throw new RuntimeException('readiness report did not distinguish compact agent brief fields from broader summary contexts.');
			}
		},
	],
	[
		'name'=>'agent brief stays compact for full app scaffold',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>12,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_mcp_agent_brief_export',
				'arguments'=>[
					'task'=>'build a field service maintenance app with customers, sites, assets, work orders, assignments, inspections, invoices, and service contracts',
					'target'=>'codex',
					'limit'=>4,
					'entities'=>['Customer', 'Site', 'Asset', 'WorkOrder', 'TechnicianAssignment', 'Inspection', 'Invoice', 'ServiceContract'],
					'fields'=>[
						'Customer'=>['name'=>'string required', 'status'=>'enum active,on_hold,archived default active', 'billing_email'=>'string nullable'],
						'Site'=>['customer_id'=>'foreign key to customer required', 'name'=>'string required', 'address'=>'text required'],
						'Asset'=>['site_id'=>'foreign key to site required', 'serial_number'=>'string required', 'status'=>'enum active,maintenance,retired default active'],
						'WorkOrder'=>['customer_id'=>'foreign key to customer required', 'site_id'=>'foreign key to site required', 'asset_id'=>'foreign key to asset nullable', 'status'=>'enum draft,scheduled,in_progress,completed default draft', 'priority'=>'enum low,normal,high,emergency default normal', 'summary'=>'string required'],
						'TechnicianAssignment'=>['work_order_id'=>'foreign key to work order required', 'technician_id'=>'foreign key to user required', 'status'=>'enum assigned,accepted,completed default assigned'],
						'Inspection'=>['work_order_id'=>'foreign key to work order required', 'result'=>'enum pass,fail,needs_follow_up default pass', 'completed_at'=>'datetime nullable'],
						'Invoice'=>['work_order_id'=>'foreign key to work order required', 'invoice_number'=>'string required', 'total'=>'decimal required', 'status'=>'enum draft,sent,paid,void default draft'],
						'ServiceContract'=>['customer_id'=>'foreign key to customer required', 'starts_at'=>'date required', 'ends_at'=>'date nullable', 'plan'=>'enum standard,premium,enterprise default standard'],
					],
				],
			],
		],
		'assert'=>static function(array $response): void {
			$text=(string)($response['result']['content'][0]['text'] ?? '');
			$data=json_decode($text, true);
			$keys=array_keys(is_array($data) ? $data : []);
			$allowedKeys=[
				'export_type',
				'write_policy',
				'execution',
				'protocol',
				'task',
				'target',
				'selected_workflow',
				'ready_to_run',
				'builder_first_read',
				'app_builder_next_action',
				'next_actions',
				'context_links',
				'policy_attention',
				'elevated_review',
			];
			if(
				!is_array($data)
				|| ($data['export_type'] ?? null)!=='dataphyre_mcp_agent_brief_export'
				|| strlen($text)>16000
				|| array_diff($keys, $allowedKeys)!==[]
				|| str_contains($text, '"handoff_fields"')
				|| str_contains($text, '"copy_forward":[')
				|| str_contains($text, '"continuation_queue"')
				|| str_contains($text, '"post_write_handoff_template"')
				|| str_contains($text, 'app_builder_lane.')
				|| array_key_exists('governance_notes', $data)
				|| array_key_exists('enterprise_audit', $data)
				|| array_key_exists('tool_audience_boundaries', $data)
				|| (($data['builder_first_read']['title'] ?? null)!=='Builder first read')
				|| (($data['builder_first_read']['files_summary']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning')
				|| (($data['builder_first_read']['schema_summary']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning')
				|| (($data['builder_first_read']['next_action']['status'] ?? null)!=='continue_entity_chunks')
				|| (($data['builder_first_read']['next_action']['resume_cursor']['copy_forward_count'] ?? null)!==4)
				|| (($data['builder_first_read']['next_action']['resume_cursor']['copy_forward_ref'] ?? null)!=='dataphyre_app_builder_plan_generate builder_response.first_read.next_action.resume_cursor.copy_forward')
				|| array_key_exists('copy_forward', $data['builder_first_read']['next_action']['resume_cursor'] ?? [])
				|| (($data['app_builder_next_action']['status'] ?? null)!=='continue_entity_chunks')
				|| (($data['app_builder_next_action']['resume_cursor_ref'] ?? null)!=='builder_first_read.next_action.resume_cursor')
				|| (($data['app_builder_next_action']['copy_forward_count'] ?? null)!==4)
				|| array_key_exists('resume_cursor', $data['app_builder_next_action'] ?? [])
				|| (($data['app_builder_next_action']['handoff_pages_ref'] ?? null)!=='builder_first_read.next_action.handoff_pages')
				|| array_key_exists('handoff_pages', $data['app_builder_next_action'] ?? [])
				|| (($data['context_links']['planning_detail_page'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning')
				|| (($data['context_links']['implementation_detail_page'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation')
				|| (($data['context_links']['verification_detail_page'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification')
				|| (($data['context_links']['controls_detail_page'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=controls')
				|| count($data['next_actions'] ?? [])>2
				|| array_key_exists('detail_pagination', $data)
				|| array_key_exists('payload_budget', $data)
			){
				throw new RuntimeException('agent brief did not preserve the first-page app-builder payload contract: '.json_encode([
					'bytes'=>strlen($text),
					'keys'=>$keys,
					'next_action'=>$data['builder_first_read']['next_action'] ?? null,
				], JSON_UNESCAPED_SLASHES));
			}
			dataphyre_mcp_live_validate_compact_app_builder_refs($data, 'agent brief compact first page');
		},
	],
	[
		'name'=>'agent brief mixed build comparison stays compact',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>121,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_mcp_agent_brief_export',
				'arguments'=>[
					'task'=>'Build a multi-entity internal asset maintenance app with customers, sites, assets, work orders, assignments, inspections, invoices, and service contracts. Compare current Dataphyre MCP app-builder brief shape against Laravel Boost-style app assistance.',
					'target'=>'codex',
					'limit'=>4,
				],
			],
		],
		'assert'=>static function(array $response): void {
			$text=(string)($response['result']['content'][0]['text'] ?? '');
			$data=json_decode($text, true);
			$keys=array_keys(is_array($data) ? $data : []);
			$allowedKeys=[
				'export_type',
				'write_policy',
				'execution',
				'protocol',
				'task',
				'target',
				'selected_workflow',
				'ready_to_run',
				'builder_first_read',
				'app_builder_next_action',
				'next_actions',
				'context_links',
				'policy_attention',
				'elevated_review',
			];
			if(
				!is_array($data)
				|| ($data['export_type'] ?? null)!=='dataphyre_mcp_agent_brief_export'
				|| strlen($text)>16000
				|| array_diff($keys, $allowedKeys)!==[]
				|| (($data['builder_first_read']['title'] ?? null)!=='Builder first read')
				|| (($data['builder_first_read']['files_summary']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning')
				|| (($data['builder_first_read']['schema_summary']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning')
				|| (($data['context_links']['planning_detail_page'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning')
				|| count($data['next_actions'] ?? [])>2
				|| array_key_exists('builder_view', $data)
				|| array_key_exists('app_builder_summary', $data)
				|| array_key_exists('app_builder_lane', $data)
				|| array_key_exists('detail_pagination', $data)
				|| array_key_exists('payload_budget', $data)
				|| array_key_exists('governance_notes', $data)
				|| array_key_exists('enterprise_audit', $data)
				|| str_contains($text, '"handoff_fields"')
				|| str_contains($text, '"copy_forward":[')
				|| str_contains($text, '"continuation_queue"')
				|| str_contains($text, 'app_builder_lane.')
			){
				throw new RuntimeException('mixed build/comparison agent brief leaked broad start-pack fields: '.json_encode([
					'chars'=>strlen($text),
					'keys'=>$keys,
					'has_builder_view'=>is_array($data) && array_key_exists('builder_view', $data),
					'has_app_builder_summary'=>is_array($data) && array_key_exists('app_builder_summary', $data),
					'has_app_builder_lane'=>is_array($data) && array_key_exists('app_builder_lane', $data),
				], JSON_UNESCAPED_SLASHES));
			}
		},
	],
	[
		'name'=>'task start pack stays compact for full app scaffold',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>13,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_mcp_task_start_pack_export',
				'arguments'=>[
					'task'=>'build a field service maintenance app with customers, sites, assets, work orders, assignments, inspections, invoices, and service contracts',
					'target'=>'codex',
					'limit'=>4,
					'payload_profile'=>'builder',
					'entities'=>['Customer', 'Site', 'Asset', 'WorkOrder', 'TechnicianAssignment', 'Inspection', 'Invoice', 'ServiceContract'],
					'fields'=>[
						'Customer'=>['name'=>'string required', 'status'=>'enum active,on_hold,archived default active', 'billing_email'=>'string nullable'],
						'Site'=>['customer_id'=>'foreign key to customer required', 'name'=>'string required', 'address'=>'text required'],
						'Asset'=>['site_id'=>'foreign key to site required', 'serial_number'=>'string required', 'status'=>'enum active,maintenance,retired default active'],
						'WorkOrder'=>['customer_id'=>'foreign key to customer required', 'site_id'=>'foreign key to site required', 'asset_id'=>'foreign key to asset nullable', 'status'=>'enum draft,scheduled,in_progress,completed default draft', 'priority'=>'enum low,normal,high,emergency default normal', 'summary'=>'string required'],
						'TechnicianAssignment'=>['work_order_id'=>'foreign key to work order required', 'technician_id'=>'foreign key to user required', 'status'=>'enum assigned,accepted,completed default assigned'],
						'Inspection'=>['work_order_id'=>'foreign key to work order required', 'result'=>'enum pass,fail,needs_follow_up default pass', 'completed_at'=>'datetime nullable'],
						'Invoice'=>['work_order_id'=>'foreign key to work order required', 'invoice_number'=>'string required', 'total'=>'decimal required', 'status'=>'enum draft,sent,paid,void default draft'],
						'ServiceContract'=>['customer_id'=>'foreign key to customer required', 'starts_at'=>'date required', 'ends_at'=>'date nullable', 'plan'=>'enum standard,premium,enterprise default standard'],
					],
				],
			],
		],
		'assert'=>static function(array $response): void {
			$text=(string)($response['result']['content'][0]['text'] ?? '');
			$data=json_decode($text, true);
			if(
				!is_array($data)
				|| (($data['export_type'] ?? null)!=='dataphyre_mcp_task_start_pack_export')
				|| (($data['payload_profile'] ?? null)!=='builder')
				|| strlen($text)>(int)($data['builder_response']['payload_budget']['max_response_chars'] ?? 60000)
				|| (($data['builder_response']['payload_budget']['surface'] ?? null)!=='task_start_builder')
				|| (($data['builder_response']['first_read_ref'] ?? null)!=='builder_first_read')
				|| (($data['builder_first_read']['files_summary']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning')
				|| (($data['builder_first_read']['schema_summary']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning')
				|| (($data['context_policy']['default_lane'] ?? null)!=='builder_first_read')
				|| (($data['context_policy']['details_collapsed'] ?? null)!==true)
				|| !in_array('builder_view', $data['context_policy']['omitted_default_fields'] ?? [], true)
				|| !in_array('app_builder_lane', $data['context_policy']['omitted_default_fields'] ?? [], true)
				|| (($data['context_policy']['open_detail_page_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=<page>')
				|| (($data['context_policy']['open_full_plan_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=full')
				|| (($data['context_links']['planning_detail_page'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning')
				|| (($data['context_links']['implementation_detail_page'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation')
				|| (($data['context_links']['verification_detail_page'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification')
				|| (($data['context_links']['controls_detail_page'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=controls')
				|| array_key_exists('builder_view', $data)
				|| array_key_exists('builder_start', $data)
				|| array_key_exists('app_builder_lane', $data)
				|| array_key_exists('governance_notes', $data)
				|| !dataphyre_mcp_live_validate_compact_matches_have_discriminators($data['tool_matches']['matches'] ?? [])
				|| !dataphyre_mcp_live_validate_compact_matches_have_discriminators($data['resource_matches']['matches'] ?? [])
				|| str_contains($text, '"handoff_fields"')
				|| str_contains($text, 'app_builder_lane.')
				|| str_contains($text, '"post_write_handoff_template"')
			){
				throw new RuntimeException('task start pack did not preserve compact first-page builder contract: '.json_encode([
					'bytes'=>strlen($text),
					'keys'=>array_keys(is_array($data) ? $data : []),
					'context_policy'=>$data['context_policy'] ?? null,
				], JSON_UNESCAPED_SLASHES));
			}
			dataphyre_mcp_live_validate_compact_app_builder_refs($data, 'task start pack compact first page');
		},
	],
	[
		'name'=>'task start pack detail stays bounded for full app scaffold',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>1301,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_mcp_task_start_pack_export',
				'arguments'=>[
					'task'=>'build a field service maintenance app with customers, sites, assets, work orders, assignments, inspections, invoices, service contracts, dashboards, permissions, audit logs, and admin panel CRUD with filters and verification',
					'target'=>'codex',
					'limit'=>4,
					'payload_profile'=>'detail',
					'entities'=>['Customer', 'Site', 'Asset', 'WorkOrder', 'TechnicianAssignment', 'Inspection', 'Invoice', 'ServiceContract'],
					'fields'=>[
						'Customer'=>['name'=>'string required', 'status'=>'enum active,on_hold,archived default active', 'billing_email'=>'string nullable'],
						'Site'=>['customer_id'=>'foreign key to customer required', 'name'=>'string required', 'address'=>'text required'],
						'Asset'=>['site_id'=>'foreign key to site required', 'serial_number'=>'string required', 'status'=>'enum active,maintenance,retired default active'],
						'WorkOrder'=>['customer_id'=>'foreign key to customer required', 'site_id'=>'foreign key to site required', 'asset_id'=>'foreign key to asset nullable', 'status'=>'enum draft,scheduled,in_progress,completed default draft', 'priority'=>'enum low,normal,high,emergency default normal', 'summary'=>'string required'],
						'TechnicianAssignment'=>['work_order_id'=>'foreign key to work order required', 'technician_id'=>'foreign key to user required', 'status'=>'enum assigned,accepted,completed default assigned'],
						'Inspection'=>['work_order_id'=>'foreign key to work order required', 'result'=>'enum pass,fail,needs_follow_up default pass', 'completed_at'=>'datetime nullable'],
						'Invoice'=>['work_order_id'=>'foreign key to work order required', 'invoice_number'=>'string required', 'total'=>'decimal required', 'status'=>'enum draft,sent,paid,void default draft'],
						'ServiceContract'=>['customer_id'=>'foreign key to customer required', 'starts_at'=>'date required', 'ends_at'=>'date nullable', 'plan'=>'enum standard,premium,enterprise default standard'],
					],
				],
			],
		],
		'assert'=>static function(array $response): void {
			$text=(string)($response['result']['content'][0]['text'] ?? '');
			$data=json_decode($text, true);
			if(
				!is_array($data)
				|| (($data['export_type'] ?? null)!=='dataphyre_mcp_task_start_pack_export')
				|| (($data['payload_profile'] ?? null)!=='detail')
				|| strlen($text)>100000
				|| (($data['startup_lane']['detail_context_inline'] ?? null)!==true)
				|| (($data['startup_lane']['deep_context_inline'] ?? null)!==false)
				|| (($data['builder_response']['title'] ?? null)!=='Builder first page')
				|| (($data['context_policy']['details_collapsed'] ?? null)!==true)
				|| (($data['context_policy']['open_start_pack_details_with'] ?? null)!=='dataphyre_mcp_task_start_pack_export payload_profile=deep only for explicit escalation evidence')
				|| (($data['application_agent_operating_contract']['default_audience'] ?? null)!=='application_agents_building_apps')
				|| (($data['tool_audience_boundaries']['ordinary_app_verification'] ?? null)!=='focused application or module checks')
				|| (($data['deep_context']['enterprise_summary']['computed_inline'] ?? null)!==false)
				|| array_key_exists('builder_view', $data)
				|| array_key_exists('builder_start', $data)
				|| array_key_exists('app_builder_lane', $data)
				|| array_key_exists('enterprise_audit', $data)
				|| array_key_exists('status_board', $data)
				|| array_key_exists('safety_boundary', $data)
				|| str_contains($text, '"post_write_handoff_template"')
			){
				throw new RuntimeException('task start pack detail profile re-expanded bulky app-builder context: '.json_encode([
					'bytes'=>strlen($text),
					'keys'=>array_keys(is_array($data) ? $data : []),
					'context_policy'=>$data['context_policy'] ?? null,
				], JSON_UNESCAPED_SLASHES));
			}
			dataphyre_mcp_live_validate_compact_app_builder_refs($data, 'task start pack detail first page');
		},
	],
	[
		'name'=>'direct app builder compact stays bounded for full app scaffold',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>14,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>[
					'task'=>'build a field service maintenance app with customers, sites, assets, work orders, assignments, inspections, invoices, and service contracts',
					'payload_profile'=>'compact',
					'entities'=>['Customer', 'Site', 'Asset', 'WorkOrder', 'TechnicianAssignment', 'Inspection', 'Invoice', 'ServiceContract'],
					'fields'=>[
						'Customer'=>['name'=>'string required', 'status'=>'enum active,on_hold,archived default active', 'billing_email'=>'string nullable'],
						'Site'=>['customer_id'=>'foreign key to customer required', 'name'=>'string required', 'address'=>'text required'],
						'Asset'=>['site_id'=>'foreign key to site required', 'serial_number'=>'string required', 'status'=>'enum active,maintenance,retired default active'],
						'WorkOrder'=>['customer_id'=>'foreign key to customer required', 'site_id'=>'foreign key to site required', 'asset_id'=>'foreign key to asset nullable', 'status'=>'enum draft,scheduled,in_progress,completed default draft', 'priority'=>'enum low,normal,high,emergency default normal', 'summary'=>'string required'],
						'TechnicianAssignment'=>['work_order_id'=>'foreign key to work order required', 'technician_id'=>'foreign key to user required', 'status'=>'enum assigned,accepted,completed default assigned'],
						'Inspection'=>['work_order_id'=>'foreign key to work order required', 'result'=>'enum pass,fail,needs_follow_up default pass', 'completed_at'=>'datetime nullable'],
						'Invoice'=>['work_order_id'=>'foreign key to work order required', 'invoice_number'=>'string required', 'total'=>'decimal required', 'status'=>'enum draft,sent,paid,void default draft'],
						'ServiceContract'=>['customer_id'=>'foreign key to customer required', 'starts_at'=>'date required', 'ends_at'=>'date nullable', 'plan'=>'enum standard,premium,enterprise default standard'],
					],
				],
			],
		],
		'assert'=>static function(array $response): void {
			$text=(string)($response['result']['content'][0]['text'] ?? '');
			$data=json_decode($text, true);
			if(
				!is_array($data)
				|| (($data['payload_profile'] ?? null)!=='compact')
				|| (($data['details_collapsed'] ?? null)!==true)
				|| strlen($text)>(int)($data['builder_response']['payload_budget']['max_response_chars'] ?? 60000)
				|| (($data['builder_response']['payload_budget']['surface'] ?? null)!=='app_builder_plan_compact')
				|| (($data['builder_response']['first_read']['title'] ?? null)!=='Builder first read')
				|| (($data['builder_response']['detail_pagination']['default_payload'] ?? null)!=='first_page_only')
				|| !isset($data['builder_response']['compact_detail_policy'])
				|| !isset($data['builder_response']['detail_refs'])
				|| array_key_exists('builder_plan', $data)
				|| array_key_exists('implementation_recipe', $data['builder_response'] ?? [])
				|| array_key_exists('local_convention_probe', $data['builder_response'] ?? [])
				|| array_key_exists('verification_execution_plan', $data['builder_response'] ?? [])
				|| str_contains($text, '"handoff_fields"')
				|| str_contains($text, '"post_write_handoff_template"')
			){
				throw new RuntimeException('direct compact app-builder did not preserve bounded detail refs: '.json_encode([
					'bytes'=>strlen($text),
					'keys'=>array_keys(is_array($data['builder_response'] ?? null) ? $data['builder_response'] : []),
				], JSON_UNESCAPED_SLASHES));
			}
			dataphyre_mcp_live_validate_compact_app_builder_refs($data['builder_response'], 'direct compact app-builder full scaffold');
			dataphyre_mcp_live_validate_no_stale_builder_first_read_refs($data['builder_response'], 'direct compact app-builder full scaffold builder_response');
		},
	],
	[
		'name'=>'direct prose compact app-builder stays bounded without losing scaffold core',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>15,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>[
					'task'=>'build a customer support portal with organizations, customers, tickets, comments, SLA policies, assignments, notifications, admin Panel CRUD, filters, and verification',
					'payload_profile'=>'compact',
				],
			],
		],
		'assert'=>static function(array $response): void {
			$text=(string)($response['result']['content'][0]['text'] ?? '');
			$data=json_decode($text, true);
			$builder=is_array($data['builder_response'] ?? null) ? $data['builder_response'] : [];
			$budget=is_array($builder['payload_budget'] ?? null) ? $builder['payload_budget'] : [];
			$compactPolicy=is_array($builder['compact_detail_policy'] ?? null) ? $builder['compact_detail_policy'] : [];
			if(
				!is_array($data)
				|| (($data['payload_profile'] ?? null)!=='compact')
				|| (($data['details_collapsed'] ?? null)!==true)
				|| array_key_exists('builder_plan', $data)
				|| strlen($text)>(int)($budget['max_response_chars'] ?? 60000)
				|| (($budget['surface'] ?? null)!=='app_builder_plan_compact')
				|| (($compactPolicy['budget_enforced'] ?? null)!==true)
				|| ((int)($compactPolicy['collapsed_sections_count'] ?? 0)<1)
				|| !isset($builder['schema'])
				|| !isset($builder['files'])
				|| !isset($builder['naming_contract'])
				|| !isset($builder['first_read'])
				|| !isset($builder['detail_pagination'])
				|| !isset($builder['scaffold_completion_summary'])
				|| !isset($builder['write_readiness'])
				|| (($builder['first_read']['title'] ?? null)!=='Builder first read')
				|| (($builder['first_read']['files_summary']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning')
				|| (($builder['first_read']['schema_summary']['open_with'] ?? null)!=='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning')
				|| (($builder['first_read']['app_path_context']['discovery_hint']['next_tool'] ?? null)!=='dataphyre_application_catalog')
				|| (($builder['detail_pagination']['default_payload'] ?? null)!=='first_page_only')
				|| str_contains($text, '"handoff_fields"')
				|| str_contains($text, '"post_write_handoff_template"')
				|| str_contains($text, 'builder_view.')
				|| str_contains($text, 'app_builder_lane.')
			){
				throw new RuntimeException('direct prose compact app-builder did not stay bounded while preserving scaffold core: '.json_encode([
					'bytes'=>strlen($text),
					'max_response_chars'=>$budget['max_response_chars'] ?? null,
					'budget_enforced'=>$compactPolicy['budget_enforced'] ?? null,
					'collapsed_sections_count'=>$compactPolicy['collapsed_sections_count'] ?? null,
					'builder_keys'=>array_keys($builder),
				], JSON_UNESCAPED_SLASHES));
			}
			dataphyre_mcp_live_validate_compact_app_builder_refs($builder, 'direct prose compact app-builder');
			dataphyre_mcp_live_validate_no_stale_builder_first_read_refs($builder, 'direct prose compact app-builder builder_response');
		},
	],
	[
		'name'=>'direct customer success SaaS compact app-builder infers enterprise scaffold',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>16,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>[
					'task'=>'Build an enterprise customer success SaaS with workspaces, accounts, contacts, subscriptions, usage meters, success plans, health scores, risks, playbooks, tasks, notes, alerts, dashboards, audit trails, notifications, tenant policies, and admin Panel CRUD. Keep app work app-owned and prepare focused verification.',
					'payload_profile'=>'compact',
					'max_entities'=>4,
				],
			],
		],
		'assert'=>static function(array $response): void {
			$text=(string)($response['result']['content'][0]['text'] ?? '');
			$data=json_decode($text, true);
			$builder=is_array($data['builder_response'] ?? null) ? $data['builder_response'] : [];
			$budget=is_array($builder['payload_budget'] ?? null) ? $builder['payload_budget'] : [];
			$schemaEntities=array_values(array_map(static fn(array $schema): string => (string)($schema['entity'] ?? ''), is_array($builder['schema'] ?? null) ? $builder['schema'] : []));
			$allEntities=is_array($builder['entity_input_contract']['entities'] ?? null) ? $builder['entity_input_contract']['entities'] : [];
			$deferred=is_array($builder['scaffold_completion_summary']['deferred_entities'] ?? null) ? $builder['scaffold_completion_summary']['deferred_entities'] : [];
			$continuationCalls=is_array($builder['entity_planning']['continuation_calls'] ?? null) ? $builder['entity_planning']['continuation_calls'] : [];
			$firstContinuationEntities=is_array($continuationCalls[0]['arguments']['entities'] ?? null) ? $continuationCalls[0]['arguments']['entities'] : [];
			if(
				!is_array($data)
				|| (($data['payload_profile'] ?? null)!=='compact')
				|| (($data['details_collapsed'] ?? null)!==true)
				|| array_key_exists('builder_plan', $data)
				|| strlen($text)>(int)($budget['max_response_chars'] ?? 60000)
				|| (($builder['entity_input_contract']['inference'] ?? null)!=='bounded_enterprise_phrase_map')
				|| $schemaEntities!==['Workspace', 'Account', 'Contact', 'Subscription']
				|| (($builder['entity_planning']['truncated'] ?? null)!==true)
				|| (($builder['scaffold_completion_summary']['complete'] ?? null)!==false)
				|| (($builder['next_action']['status'] ?? null)!=='continue_entity_chunks')
				|| in_array('Customer', $allEntities, true)
				|| !in_array('UsageMeter', $deferred, true)
				|| !in_array('HealthScore', $deferred, true)
				|| !in_array('SuccessPlan', $deferred, true)
				|| !in_array('AuditEvent', $deferred, true)
				|| !in_array('Notification', $deferred, true)
				|| $firstContinuationEntities!==['UsageMeter', 'HealthScore', 'SuccessPlan', 'Note']
				|| str_contains($text, '"handoff_fields"')
				|| str_contains($text, '"post_write_handoff_template"')
				|| str_contains($text, 'builder_view.')
				|| str_contains($text, 'app_builder_lane.')
			){
				throw new RuntimeException('customer-success SaaS compact app-builder did not preserve enterprise scaffold inference: '.json_encode([
					'bytes'=>strlen($text),
					'schema_entities'=>$schemaEntities,
					'all_entities'=>$allEntities,
					'deferred'=>$deferred,
					'first_continuation_entities'=>$firstContinuationEntities,
					'next_action'=>$builder['next_action'] ?? null,
				], JSON_UNESCAPED_SLASHES));
			}
			dataphyre_mcp_live_validate_compact_app_builder_refs($builder, 'direct customer success SaaS compact app-builder');
			dataphyre_mcp_live_validate_no_stale_builder_first_read_refs($builder, 'direct customer success SaaS compact app-builder builder_response');
		},
	],
	[
		'name'=>'direct learning compliance SaaS compact app-builder stays budgeted',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>17,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>[
					'task'=>'Build a complex enterprise learning compliance SaaS with workspaces, learners, managers, courses, modules, lessons, assignments, due dates, attestations, certificates, quizzes, questions, attempts, remediation plans, policy acknowledgements, evidence uploads, audit trails, notifications, dashboards, tenant policies, and admin Panel CRUD. Keep app work app-owned and prepare focused verification.',
					'payload_profile'=>'compact',
					'max_entities'=>4,
				],
			],
		],
		'assert'=>static function(array $response): void {
			$text=(string)($response['result']['content'][0]['text'] ?? '');
			$data=json_decode($text, true);
			$builder=is_array($data['builder_response'] ?? null) ? $data['builder_response'] : [];
			$budget=is_array($builder['payload_budget'] ?? null) ? $builder['payload_budget'] : [];
			$schemaEntities=array_values(array_map(static fn(array $schema): string => (string)($schema['entity'] ?? ''), is_array($builder['schema'] ?? null) ? $builder['schema'] : []));
			$allEntities=is_array($builder['entity_input_contract']['entities'] ?? null) ? $builder['entity_input_contract']['entities'] : [];
			$deferred=is_array($builder['entity_planning']['deferred_entities'] ?? null) ? $builder['entity_planning']['deferred_entities'] : [];
			$continuationCalls=is_array($builder['entity_planning']['continuation_calls'] ?? null) ? $builder['entity_planning']['continuation_calls'] : [];
			$firstContinuationArgs=is_array($continuationCalls[0]['arguments'] ?? null) ? $continuationCalls[0]['arguments'] : [];
			if(
				!is_array($data)
				|| strlen($text)>(int)($budget['max_response_chars'] ?? 60000)
				|| $schemaEntities!==['Workspace', 'Learner', 'Course', 'Module']
				|| (($builder['entity_planning']['truncated'] ?? null)!==true)
				|| (($builder['entity_planning']['continuation_count'] ?? 0)<4)
				|| count($continuationCalls)>1
				|| (($firstContinuationArgs['entities'] ?? [])!==['Lesson', 'Assignment', 'Attestation', 'Certificate'])
				|| isset($firstContinuationArgs['fields'])
				|| (($firstContinuationArgs['field_source'] ?? null)!=='inferred_defaults_from_task_and_entities')
				|| !in_array('Quiz', $allEntities, true)
				|| !in_array('Attempt', $allEntities, true)
				|| !in_array('PolicyAcknowledgement', $allEntities, true)
				|| !in_array('EvidenceUpload', $deferred, true)
				|| !in_array('AuditEvent', $deferred, true)
				|| str_contains($text, '"handoff_fields"')
				|| str_contains($text, 'builder_view.')
				|| str_contains($text, 'app_builder_lane.')
			){
				throw new RuntimeException('learning-compliance SaaS compact app-builder did not preserve first-page scaffold inference: '.json_encode([
					'bytes'=>strlen($text),
					'max'=>$budget['max_response_chars'] ?? null,
					'schema_entities'=>$schemaEntities,
					'all_entities'=>$allEntities,
					'deferred'=>$deferred,
					'first_continuation_args'=>$firstContinuationArgs,
				], JSON_UNESCAPED_SLASHES));
			}
			dataphyre_mcp_live_validate_compact_app_builder_refs($builder, 'direct learning compliance SaaS compact app-builder');
			dataphyre_mcp_live_validate_no_stale_builder_first_read_refs($builder, 'direct learning compliance SaaS compact app-builder builder_response');
		},
	],
	[
		'name'=>'direct provider credentialing SaaS compact app-builder stays budgeted',
		'request'=>[
			'jsonrpc'=>'2.0',
			'id'=>18,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>[
					'task'=>'Build a complex enterprise provider credentialing SaaS with workspaces, providers, provider profiles, licenses, certifications, credentialing applications, credentialing steps, verifications, payer enrollments, network contracts, facilities, privileges, expirations, documents, background checks, sanction checks, committee reviews, approval decisions, audit trails, notifications, dashboards, tenant policies, and admin Panel CRUD. Keep app work app-owned and prepare focused verification.',
					'payload_profile'=>'compact',
					'max_entities'=>4,
				],
			],
		],
		'assert'=>static function(array $response): void {
			$text=(string)($response['result']['content'][0]['text'] ?? '');
			$data=json_decode($text, true);
			$builder=is_array($data['builder_response'] ?? null) ? $data['builder_response'] : [];
			$budget=is_array($builder['payload_budget'] ?? null) ? $builder['payload_budget'] : [];
			$schemaEntities=array_values(array_map(static fn(array $schema): string => (string)($schema['entity'] ?? ''), is_array($builder['schema'] ?? null) ? $builder['schema'] : []));
			$allEntities=is_array($builder['entity_input_contract']['entities'] ?? null) ? $builder['entity_input_contract']['entities'] : [];
			$continuationCalls=is_array($builder['entity_planning']['continuation_calls'] ?? null) ? $builder['entity_planning']['continuation_calls'] : [];
			$firstContinuationArgs=is_array($continuationCalls[0]['arguments'] ?? null) ? $continuationCalls[0]['arguments'] : [];
			if(
				!is_array($data)
				|| strlen($text)>(int)($budget['max_response_chars'] ?? 60000)
				|| $schemaEntities!==['Workspace', 'Provider', 'ProviderProfile', 'License']
				|| (($builder['entity_planning']['truncated'] ?? null)!==true)
				|| (($builder['entity_planning']['continuation_count'] ?? 0)<5)
				|| count($continuationCalls)>1
				|| (($firstContinuationArgs['entities'] ?? [])!==['Certification', 'CredentialingApplication', 'CredentialingStep', 'Verification'])
				|| isset($firstContinuationArgs['fields'])
				|| isset($firstContinuationArgs['task'])
				|| (($firstContinuationArgs['task_ref'] ?? null)!=='current_request.task')
				|| (($firstContinuationArgs['field_source'] ?? null)!=='inferred_defaults_from_task_and_entities')
				|| !in_array('PayerEnrollment', $allEntities, true)
				|| !in_array('NetworkContract', $allEntities, true)
				|| !in_array('SanctionCheck', $allEntities, true)
				|| str_contains($text, '"account_id"')
				|| str_contains($text, '"handoff_fields"')
				|| str_contains($text, 'builder_view.')
				|| str_contains($text, 'app_builder_lane.')
			){
				throw new RuntimeException('provider credentialing SaaS compact app-builder did not preserve first-page scaffold inference: '.json_encode([
					'bytes'=>strlen($text),
					'max'=>$budget['max_response_chars'] ?? null,
					'schema_entities'=>$schemaEntities,
					'all_entities'=>$allEntities,
					'first_continuation_args'=>$firstContinuationArgs,
				], JSON_UNESCAPED_SLASHES));
			}
			dataphyre_mcp_live_validate_compact_app_builder_refs($builder, 'direct provider credentialing SaaS compact app-builder');
			dataphyre_mcp_live_validate_no_stale_builder_first_read_refs($builder, 'direct provider credentialing SaaS compact app-builder builder_response');
		},
	],
];

$responses=dataphyre_mcp_live_validate_request_batch($php, $server, $root, array_column($checks, 'request'), 'headers');
$line_responses=dataphyre_mcp_live_validate_request_batch($php, $server, $root, array_column($checks, 'request'), 'lines');
$failures=[];
foreach($checks as $check){
	try{
		$id=(int)$check['request']['id'];
		$response=$responses[$id] ?? null;
		if(!is_array($response)){
			throw new RuntimeException('No MCP response returned.');
		}
		if(isset($response['error'])){
			throw new RuntimeException((string)($response['error']['message'] ?? 'Unknown MCP error.'));
		}
		$check['assert']($response);
		echo '[OK] '.$check['name'].PHP_EOL;
		$line_response=$line_responses[$id] ?? null;
		if(!is_array($line_response)){
			throw new RuntimeException('No line-delimited MCP response returned.');
		}
		if(isset($line_response['error'])){
			throw new RuntimeException((string)($line_response['error']['message'] ?? 'Unknown line-delimited MCP error.'));
		}
		$check['assert']($line_response);
	}catch(Throwable $exception){
		$failures[]='[FAIL] '.$check['name'].': '.$exception->getMessage();
		echo end($failures).PHP_EOL;
	}
}

try{
	dataphyre_mcp_live_validate_app_builder_continuation_replay($php, $server, $root);
	echo '[OK] app builder continuation replay works through tools/call'.PHP_EOL;
}catch(Throwable $exception){
	$failures[]='[FAIL] app builder continuation replay works through tools/call: '.$exception->getMessage();
	echo end($failures).PHP_EOL;
}

if($failures!==[]){
	fwrite(STDERR, "\nDataphyre MCP live validation failed with ".count($failures)." issue(s).\n");
	exit(1);
}

echo "Dataphyre MCP live validation passed.\n";
exit(0);

function dataphyre_mcp_live_validate_option(array $argv, string $name): ?string {
	foreach($argv as $index=>$argument){
		if($argument===$name && isset($argv[$index+1])){
			return (string)$argv[$index+1];
		}
		if(str_starts_with((string)$argument, $name.'=')){
			return substr((string)$argument, strlen($name)+1);
		}
	}
	return null;
}

function dataphyre_mcp_live_validate_workspace_root(string $tool_dir): ?string {
	$real_tool_dir=realpath($tool_dir);
	if(!is_string($real_tool_dir)){
		return null;
	}
	$candidates=[
		realpath($real_tool_dir.'/../../../../..'),
		getcwd() ?: null,
	];
	foreach($candidates as $candidate){
		if(!is_string($candidate) || $candidate===''){
			continue;
		}
		$root=rtrim(str_replace('\\', '/', $candidate), '/');
		if(is_file($root.'/common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php')){
			return $root;
		}
	}
	$source_candidates=[
		realpath($real_tool_dir.'/../../..'),
		getcwd() ?: null,
	];
	foreach($source_candidates as $candidate){
		if(!is_string($candidate) || $candidate===''){
			continue;
		}
		$source_root=rtrim(str_replace('\\', '/', $candidate), '/');
		if(is_file($source_root.'/runtime/modules/mcp/kernel/dataphyre_mcp.php')){
			return dataphyre_mcp_live_validate_source_embedded_workspace($source_root);
		}
	}
	return null;
}

function dataphyre_mcp_live_validate_source_embedded_workspace(string $source_root): ?string {
	$workspace=rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/dataphyre-mcp-live-validate-'.substr(sha1($source_root), 0, 12).'-'.getmypid();
	$common=$workspace.'/common';
	$link=$common.'/dataphyre';
	if(!is_dir($common) && !@mkdir($common, 0777, true) && !is_dir($common)){
		return null;
	}
	if(is_file($link.'/runtime/modules/mcp/kernel/dataphyre_mcp.php')){
		register_shutdown_function('dataphyre_mcp_live_validate_cleanup_embedded_workspace', $workspace, $link);
		return $workspace;
	}
	if(@symlink($source_root, $link) || dataphyre_mcp_live_validate_windows_junction($source_root, $link)){
		if(is_file($link.'/runtime/modules/mcp/kernel/dataphyre_mcp.php')){
			register_shutdown_function('dataphyre_mcp_live_validate_cleanup_embedded_workspace', $workspace, $link);
			return $workspace;
		}
	}
	dataphyre_mcp_live_validate_cleanup_embedded_workspace($workspace, $link);
	return null;
}

function dataphyre_mcp_live_validate_windows_junction(string $source_root, string $link): bool {
	if(PHP_OS_FAMILY!=='Windows'){
		return false;
	}
	$command='cmd /c mklink /J '.escapeshellarg(str_replace('/', '\\', $link)).' '.escapeshellarg(str_replace('/', '\\', $source_root));
	@exec($command, $output, $exit_code);
	return $exit_code===0;
}

function dataphyre_mcp_live_validate_cleanup_embedded_workspace(string $workspace, string $link): void {
	if(is_link($link)){
		@unlink($link);
	}
	elseif(is_dir($link)){
		@rmdir($link);
	}
	@rmdir(dirname($link));
	@rmdir($workspace);
}

function dataphyre_mcp_live_validate_request_batch(string $php, string $server, string $cwd, array $messages, string $transport): array {
	if(count($messages)>8){
		$responses=[];
		foreach(array_chunk($messages, 8) as $chunk){
			foreach(dataphyre_mcp_live_validate_request_batch_chunk($php, $server, $cwd, $chunk, $transport) as $id=>$response){
				$responses[(int)$id]=$response;
			}
		}
		return $responses;
	}
	return dataphyre_mcp_live_validate_request_batch_chunk($php, $server, $cwd, $messages, $transport);
}

function dataphyre_mcp_live_validate_request_batch_chunk(string $php, string $server, string $cwd, array $messages, string $transport): array {
	$input='';
	foreach($messages as $message){
		$body=json_encode($message, JSON_UNESCAPED_SLASHES);
		if(!is_string($body)){
			throw new RuntimeException('Unable to encode MCP request.');
		}
		$input.=$transport==='lines' ? $body."\n" : 'Content-Length: '.strlen($body)."\r\n\r\n".$body;
	}
	$descriptor=[
		0=>['pipe', 'r'],
		1=>['pipe', 'w'],
		2=>['pipe', 'w'],
	];
	$process=proc_open([$php, '-d', 'error_reporting=32767', '-d', 'display_errors=stderr', $server], $descriptor, $pipes, $cwd);
	if(!is_resource($process)){
		throw new RuntimeException('Unable to start MCP server.');
	}
	fwrite($pipes[0], $input);
	fclose($pipes[0]);
	$stdout=stream_get_contents($pipes[1]);
	$stderr=stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exit=proc_close($process);
	if($exit!==0){
		throw new RuntimeException('MCP server exited '.$exit.($stderr!=='' ? ': '.$stderr : '.'));
	}
	if(trim((string)$stderr)!==''){
		throw new RuntimeException('MCP server wrote to stderr during validation: '.trim((string)$stderr));
	}
	return $transport==='lines'
		? dataphyre_mcp_live_validate_decode_lines((string)$stdout)
		: dataphyre_mcp_live_validate_decode_frames((string)$stdout);
}

function dataphyre_mcp_live_validate_decode_lines(string $buffer): array {
	$responses=[];
	foreach(preg_split('/\r?\n/', trim($buffer)) ?: [] as $line){
		if($line===''){
			continue;
		}
		$response=json_decode($line, true);
		if(!is_array($response)){
			throw new RuntimeException('Line-delimited MCP response is not valid JSON.');
		}
		$responses[(int)($response['id'] ?? 0)]=$response;
	}
	return $responses;
}

function dataphyre_mcp_live_validate_decode_frames(string $buffer): array {
	$responses=[];
	$offset=0;
	$length=strlen($buffer);
	while($offset<$length){
		$separator=strpos($buffer, "\r\n\r\n", $offset);
		if($separator===false){
			throw new RuntimeException('MCP response frame is missing header separator.');
		}
		$headerBlock=substr($buffer, $offset, $separator-$offset);
		$headers=[];
		foreach(explode("\r\n", $headerBlock) as $line){
			[$name, $value]=array_pad(explode(':', $line, 2), 2, '');
			$headers[strtolower(trim($name))]=trim($value);
		}
		$contentLength=(int)($headers['content-length'] ?? 0);
		if($contentLength<=0){
			throw new RuntimeException('MCP response frame has no positive Content-Length.');
		}
		$bodyStart=$separator+4;
		$body=substr($buffer, $bodyStart, $contentLength);
		if(strlen($body)!==$contentLength){
			throw new RuntimeException('MCP response body is shorter than Content-Length.');
		}
		$response=json_decode($body, true);
		if(!is_array($response)){
			throw new RuntimeException('MCP response body is not valid JSON.');
		}
		$responses[(int)($response['id'] ?? 0)]=$response;
		$offset=$bodyStart+$contentLength;
	}
	return $responses;
}

function dataphyre_mcp_live_validate_app_builder_continuation_replay(string $php, string $server, string $root): void {
	foreach(['headers', 'lines'] as $transport){
		$firstRequest=[
			'jsonrpc'=>'2.0',
			'id'=>1201,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>[
					'task'=>'build a field service app with customers, sites, assets, work orders, and inspections',
					'payload_profile'=>'compact',
					'application_path'=>'applications/fieldops/backend/dataphyre',
					'app_namespace'=>'FieldOps',
					'max_entities'=>2,
					'entities'=>['Customer', 'Site', 'Asset', 'WorkOrder', 'Inspection'],
					'fields'=>[
						'Customer'=>['name'=>'string required'],
						'Site'=>['customer_id'=>'foreign key to customers required', 'name'=>'string required'],
						'Asset'=>['site_id'=>'foreign key to sites required', 'status'=>'enum active,maintenance,retired default active'],
						'WorkOrder'=>['asset_id'=>'foreign key to assets required', 'status'=>'enum open,in_progress,closed default open'],
						'Inspection'=>['work_order_id'=>'foreign key to work orders required', 'result'=>'enum pass,fail default pass'],
					],
				],
			],
		];
		$firstResponses=dataphyre_mcp_live_validate_request_batch($php, $server, $root, [$firstRequest], $transport);
		$firstResponse=$firstResponses[1201] ?? null;
		if(!is_array($firstResponse) || isset($firstResponse['error'])){
			throw new RuntimeException("App-builder chunk seed failed over {$transport}: ".json_encode($firstResponse['error'] ?? $firstResponse, JSON_UNESCAPED_SLASHES));
		}
		$firstData=dataphyre_mcp_live_validate_tool_json($firstResponse);
		if(
			(($firstData['builder_response']['first_read']['app_path_context']['dataphyre_root'] ?? null)!=='applications/fieldops/backend/dataphyre')
			|| (($firstData['builder_response']['first_read']['app_path_context']['framework_path'] ?? null)!=='applications/fieldops/backend/dataphyre/Framework')
			|| (($firstData['builder_response']['first_read']['app_path_context']['panel_resource_namespace'] ?? null)!=='FieldOps\\Panel\\Resources')
			|| (($firstData['builder_response']['first_read']['app_path_context']['placeholder_mode'] ?? null)!==false)
		){
			throw new RuntimeException("App-builder first_read app_path_context did not mirror concrete path hints over {$transport}.");
		}
		$args=is_array($firstData['builder_response']['entity_planning']['continuation_calls'][0]['arguments'] ?? null)
			? $firstData['builder_response']['entity_planning']['continuation_calls'][0]['arguments']
			: [];
		if($args===[] || (($args['field_scope'] ?? null)!=='chunk_entities') || !is_array($args['dependency_context'] ?? null)){
			throw new RuntimeException("App-builder continuation arguments were not copy-ready over {$transport}.");
		}
		$replayResponses=dataphyre_mcp_live_validate_request_batch($php, $server, $root, [[
			'jsonrpc'=>'2.0',
			'id'=>1202,
			'method'=>'tools/call',
			'params'=>[
				'name'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>$args,
			],
		]], $transport);
		$replayResponse=$replayResponses[1202] ?? null;
		if(!is_array($replayResponse) || isset($replayResponse['error'])){
			throw new RuntimeException("App-builder continuation replay failed over {$transport}: ".json_encode($replayResponse['error'] ?? $replayResponse, JSON_UNESCAPED_SLASHES));
		}
		$replayData=dataphyre_mcp_live_validate_tool_json($replayResponse);
		$schemaEntities=array_map(static fn(array $schema): string => (string)($schema['entity'] ?? ''), is_array($replayData['builder_response']['schema'] ?? null) ? $replayData['builder_response']['schema'] : []);
		$files=is_array($replayData['builder_response']['files'] ?? null) ? $replayData['builder_response']['files'] : [];
		$plannedEntities=is_array($replayData['builder_response']['scaffold_completion_summary']['planned_entities'] ?? null)
			? $replayData['builder_response']['scaffold_completion_summary']['planned_entities']
			: [];
		if(
			($schemaEntities!==['Asset', 'WorkOrder'] && $plannedEntities!==['Asset', 'WorkOrder'])
			|| (($replayData['builder_response']['app_path_context']['application_path'] ?? null)!=='applications/fieldops/backend/dataphyre')
			|| (($replayData['builder_response']['app_path_context']['panel_resource_namespace'] ?? null)!=='FieldOps\\Panel\\Resources')
			|| (($replayData['payload_profile'] ?? null)!=='compact')
			|| isset($replayData['builder_plan'])
			|| (
				!in_array('applications/fieldops/backend/dataphyre/panel/resources/AssetResource.php', $files, true)
				&& (($replayData['builder_response']['files_summary']['total'] ?? 0)<1)
			)
			|| in_array('applications/fieldops/backend/dataphyre/panel/resources/CustomerResource.php', $files, true)
		){
			throw new RuntimeException("App-builder continuation replay returned the wrong chunk over {$transport}: ".json_encode([
				'schema_entities'=>$schemaEntities,
				'planned_entities'=>$plannedEntities,
				'app_path_context'=>$replayData['builder_response']['app_path_context'] ?? null,
				'files'=>$files,
			], JSON_UNESCAPED_SLASHES));
		}
	}
}

function dataphyre_mcp_live_validate_tool_json(array $response): array {
	$text=(string)($response['result']['content'][0]['text'] ?? '');
	$data=json_decode($text, true);
	if(!is_array($data)){
		throw new RuntimeException('Tool response content is not valid JSON.');
	}
	return $data;
}

function dataphyre_mcp_live_validate_compact_app_builder_refs(array $payload, string $context): void {
	$firstRead=is_array($payload['first_read'] ?? null)
		? $payload['first_read']
		: (is_array($payload['builder_first_read'] ?? null) ? $payload['builder_first_read'] : []);
	$pagination=is_array($payload['detail_pagination'] ?? null)
		? $payload['detail_pagination']
		: (is_array($payload['builder_response']['detail_pagination'] ?? null) ? $payload['builder_response']['detail_pagination'] : []);
	$pages=is_array($pagination['pages'] ?? null) ? $pagination['pages'] : [];
	if($pages===[] && is_array($payload['context_links'] ?? null)){
		$links=$payload['context_links'];
		if(
			(($links['planning_detail_page'] ?? null)==='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning')
			&& (($links['implementation_detail_page'] ?? null)==='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation')
			&& (($links['verification_detail_page'] ?? null)==='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification')
			&& (($links['controls_detail_page'] ?? null)==='dataphyre_app_builder_plan_generate payload_profile=compact detail_page=controls')
		){
			$pages=[
				'planning'=>['linked'],
				'implementation'=>['linked'],
				'verification'=>['linked'],
				'controls'=>['linked'],
			];
		}
	}
	if($firstRead===[] || $pages===[]){
		throw new RuntimeException("{$context} is missing first-read or detail_pagination refs.");
	}
	$openDetails=is_array($firstRead['open_details'] ?? null) ? $firstRead['open_details'] : [];
	foreach(['files'=>'planning', 'schema'=>'planning', 'implementation'=>'implementation'] as $key=>$page){
		$ref=(string)($openDetails[$key] ?? '');
		if(
			$ref===''
			|| str_contains($ref, 'builder_view.')
			|| str_contains($ref, 'app_builder_lane.')
			|| !str_contains($ref, 'dataphyre_app_builder_plan_generate')
			|| !str_contains($ref, 'payload_profile=compact')
			|| !str_contains($ref, "detail_page={$page}")
			|| !array_key_exists($page, $pages)
		){
			throw new RuntimeException("{$context} has a non-navigable first_read.open_details.{$key} ref: {$ref}");
		}
	}
	$controlsRef=(string)($openDetails['controls'] ?? '');
	if(
		$controlsRef!==''
		&& (
			str_contains($controlsRef, 'builder_view.')
			|| str_contains($controlsRef, 'app_builder_lane.')
			|| !str_contains($controlsRef, 'dataphyre_app_builder_plan_generate')
			|| !str_contains($controlsRef, 'payload_profile=compact')
			|| !str_contains($controlsRef, 'detail_page=controls')
			|| !array_key_exists('controls', $pages)
		)
	){
		throw new RuntimeException("{$context} has a non-navigable first_read.open_details.controls ref: {$controlsRef}");
	}
	foreach($pages as $page=>$sections){
		if(trim((string)$page)==='' || !is_array($sections) || $sections===[]){
			throw new RuntimeException("{$context} has an invalid detail_pagination page: {$page}");
		}
		foreach($sections as $section){
			if(trim((string)$section)===''){
				throw new RuntimeException("{$context} has an empty section in detail_pagination page {$page}.");
			}
		}
	}
	$selected=is_array($payload['selected_detail_page'] ?? null) ? $payload['selected_detail_page'] : [];
	if($selected!==[]){
		$page=(string)($selected['page'] ?? '');
		$sections=is_array($selected['sections'] ?? null) ? $selected['sections'] : [];
		$pageData=is_array($selected['data'] ?? null) ? $selected['data'] : [];
		if(!array_key_exists($page, $pages)){
			throw new RuntimeException("{$context} selected unknown detail page {$page}.");
		}
		foreach($sections as $section){
			if(!in_array($section, $pages[$page], true)){
				throw new RuntimeException("{$context} selected page {$page} exposes section {$section} outside detail_pagination.");
			}
		}
		$materialized=array_intersect(array_keys($pageData), $pages[$page]);
		if($materialized===[]){
			throw new RuntimeException("{$context} selected page {$page} did not materialize any paginated section.");
		}
	}
	$detailRefs=is_array($payload['detail_refs'] ?? null) ? $payload['detail_refs'] : [];
	foreach($detailRefs as $key=>$ref){
		$refString=(string)$ref;
		if(
			$refString===''
			|| str_contains($refString, 'builder_view.')
			|| str_contains($refString, 'app_builder_lane.')
			|| !str_contains($refString, 'dataphyre_app_builder_plan_generate payload_profile=full')
		){
			throw new RuntimeException("{$context} has a non-navigable detail_refs.{$key} ref: {$refString}");
		}
	}
	$collapsed=is_array($payload['compact_detail_policy']['collapsed_sections'] ?? null) ? $payload['compact_detail_policy']['collapsed_sections'] : [];
	foreach($collapsed as $key=>$section){
		if(!is_array($section)){
			continue;
		}
		$openWith=(string)($section['open_with'] ?? '');
		if(
			$openWith===''
			|| str_contains($openWith, 'builder_view.')
			|| str_contains($openWith, 'app_builder_lane.')
			|| !str_contains($openWith, 'dataphyre_app_builder_plan_generate payload_profile=full')
		){
			throw new RuntimeException("{$context} has a non-navigable collapsed section {$key}: {$openWith}");
		}
	}
}

function dataphyre_mcp_live_validate_no_stale_builder_first_read_refs(mixed $value, string $context, string $path='builder_response'): void {
	if(is_array($value)){
		foreach($value as $key=>$child){
			dataphyre_mcp_live_validate_no_stale_builder_first_read_refs($child, $context, $path.'.'.(string)$key);
		}
		return;
	}
	if(is_string($value) && str_contains($value, 'builder_first_read.')){
		throw new RuntimeException("{$context} contains stale builder_first_read ref at {$path}: {$value}");
	}
}

function dataphyre_mcp_live_validate_compact_matches_have_discriminators(mixed $matches): bool {
	if(!is_array($matches) || $matches===[]){
		return false;
	}
	$discriminatorKeys=['group', 'kind', 'description', 'module', 'path', 'fetch_tool', 'match_reasons', 'title'];
	foreach($matches as $match){
		if(!is_array($match) || trim((string)($match['name'] ?? ''))===''){
			return false;
		}
		$hasDiscriminator=false;
		foreach($discriminatorKeys as $key){
			$value=$match[$key] ?? null;
			if(is_array($value) ? $value!==[] : trim((string)$value)!==''){
				$hasDiscriminator=true;
				break;
			}
		}
		if(!$hasDiscriminator){
			return false;
		}
	}
	return true;
}
