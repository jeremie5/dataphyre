<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * MCP client example-call surfaces.
 */
trait dataphyre_mcp_client_example_surfaces {

	/**
	 * Exports example tool calls grouped by MCP capability area.
	 *
	 * @param array{workflow?:'app'|'docs'|'routes'|'sql'|'diagnostics'|'client'|'safety'|'validation'|'all'|string,include_expected?:bool,include_notes?:bool} $args Optional filters and include flags.
	 * @return array Tool-call examples payload.
	 */
	private function mcp_tool_call_examples_export(array $args): array {
		$workflow=strtolower(trim((string)($args['workflow'] ?? 'all')));
		if(!in_array($workflow, ['app', 'docs', 'routes', 'sql', 'diagnostics', 'client', 'safety', 'validation', 'all'], true)){
			$workflow='all';
		}
		$examples=[
			'app'=>[
				[
					'name'=>'Generate app-builder plan',
					'audience_scope'=>'ordinary_app_first_step',
					'when_to_use'=>'Use first for ordinary app creation; often enough before editing app-owned files.',
					'request'=>['jsonrpc'=>'2.0', 'id'=>91, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_app_builder_plan_generate', 'arguments'=>['task'=>'build a small internal ticket tracker app with Projects, Tickets, admin panel CRUD, filters, and verification', 'payload_profile'=>'compact']]],
					'expect'=>'Returns builder_response.first_read with builder_response.first_read.next_action, next_detail_page, files_summary, schema_summary, naming_contract, write_readiness, scaffold_completion_summary, acceptance criteria, and verification_handoff; open_details is only the backing pointer map for next_detail_page. Detailed files, schema, relationships, implementation, controls, verification, and skeleton bodies stay behind explicit detail/full payloads.',
				],
				[
					'name'=>'Generate app-builder plan with explicit per-entity fields',
					'audience_scope'=>'ordinary_app_first_step_explicit_schema',
					'when_to_use'=>'Use when the requested app already names entity-specific fields and relationships.',
					'request'=>['jsonrpc'=>'2.0', 'id'=>911, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_app_builder_plan_generate', 'arguments'=>[
						'task'=>'build a ticket tracker with Projects and Tickets',
						'payload_profile'=>'compact',
						'entities'=>['Project', 'Ticket'],
						'fields'=>[
							'Project'=>[
								'name',
								'description',
								'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
							],
							'Ticket'=>[
								'project_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'projects'],
								'title'=>['type'=>'string', 'required'=>true],
								'description'=>'string',
								'external_id'=>'string nullable not a foreign key',
								'status'=>['type'=>'string', 'options'=>['open', 'in_progress', 'closed'], 'default'=>'open'],
								'priority'=>'enum low,normal,urgent default normal',
								'assignee_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
								'payload'=>['type'=>'json'],
								'due_date'=>['type'=>'date'],
							],
						],
					]]],
					'expect'=>'Returns per-entity schema, explicit relationships from foreign_key_target, relationship_adapter_handoff.adapters with suggested app adapters, panel_field_source, repository_touchpoint, and verification_focus, implementation_recipe relationship edit tasks, non-relationship external_id metadata, json field types, filters, data-model columns, data_model_handoff, field_input_contract, field_metadata_summary, app_contract_summary, data_sensitivity_summary, policy_decision_register, and Panel skeletons without applying one generic field list to every entity; bounded options/defaults flow to data_model_handoff.schema_field_metadata, panel_fields[].field_metadata, filters[].filter_metadata, code_skeleton_summary.field_metadata_policy, and app-owned Panel array field/filter definitions, while sensitive fields surface through code_skeleton_summary.sensitive_field_policy.path_reasons before opening full skeleton bodies.',
				],
				[
					'name'=>'Generate chunked full-app builder plan',
					'audience_scope'=>'ordinary_app_first_step_chunked_full_app',
					'when_to_use'=>'Use when the app names more resources than should be planned in one response; copy continuation_calls and preserve dependency_context until no deferred_entities remain.',
					'request'=>['jsonrpc'=>'2.0', 'id'=>912, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_app_builder_plan_generate', 'arguments'=>[
						'task'=>'build a procurement workflow app with vendors, products, purchase requests, request lines, approvals, purchase orders, shipments, documents, and admin panel CRUD',
						'payload_profile'=>'compact',
						'entities'=>['Vendor', 'Product', 'PurchaseRequest', 'PurchaseRequestLine', 'ApprovalStep', 'PurchaseOrder', 'Shipment', 'Document'],
						'max_entities'=>4,
						'fields'=>[
							'Vendor'=>[
								'name'=>['type'=>'string', 'required'=>true],
								'status'=>['type'=>'string', 'options'=>['active', 'paused'], 'default'=>'active'],
								'owner_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
							],
							'Product'=>[
								'vendor_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'vendors'],
								'sku'=>['type'=>'string', 'required'=>true],
								'name'=>['type'=>'string', 'required'=>true],
							],
							'PurchaseRequest'=>[
								'vendor_id'=>['type'=>'integer', 'foreign_key_target'=>'vendors'],
								'requested_by_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
								'status'=>'enum draft,submitted,approved default draft',
								'needed_by'=>['type'=>'date'],
							],
							'PurchaseRequestLine'=>[
								'purchase_request_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'purchase requests'],
								'product_id'=>['type'=>'integer', 'foreign_key_target'=>'products'],
								'quantity'=>['type'=>'integer', 'required'=>true],
							],
							'ApprovalStep'=>[
								'purchase_request_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'purchase requests'],
								'approver_id'=>['type'=>'integer', 'foreign_key_target'=>'users'],
								'status'=>'enum pending,approved,rejected default pending',
							],
							'PurchaseOrder'=>[
								'purchase_request_id'=>['type'=>'integer', 'foreign_key_target'=>'purchase requests'],
								'vendor_id'=>['type'=>'integer', 'foreign_key_target'=>'vendors'],
								'status'=>'enum draft,issued,closed default draft',
							],
							'Shipment'=>[
								'purchase_order_id'=>['type'=>'integer', 'required'=>true, 'foreign_key_target'=>'purchase orders'],
								'tracking_number'=>['type'=>'string'],
								'status'=>'enum pending,shipped,received default pending',
							],
							'Document'=>[
								'purchase_order_id'=>['type'=>'integer', 'foreign_key_target'=>'purchase orders'],
								'storage_ref'=>['type'=>'json'],
								'document_type'=>['type'=>'string'],
							],
						],
					]]],
					'expect'=>'Returns builder_response.first_read.next_action.status=continue_entity_chunks, entity_planning.truncated=true, scaffold_completion_summary.complete=false, scaffold_completion_summary.next_continuation, planned entities for the first chunk, deferred entities for later resources, PascalCase compound class names such as PurchaseRequestResource, snake_case paths such as purchase_request.php, field_metadata_summary for bounded enum/default hints, chunk-scoped nested fields, and continuation arguments available through the next continuation/detail plan.',
				],
				[
					'name'=>'Generate builder-first task pack',
					'audience_scope'=>'ordinary_app_optional_context',
					'when_to_use'=>'Use after the app-builder plan when module docs and a ready-to-use prompt are needed.',
					'request'=>['jsonrpc'=>'2.0', 'id'=>92, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_task_pack_generate', 'arguments'=>['task'=>'Build a Panel resource for inventory items', 'modules'=>['panel', 'sql'], 'scaffold_type'=>'panel_resource', 'name'=>'Inventory Item', 'max_chunks'=>6, 'payload_profile'=>'builder']]],
					'expect'=>'Returns builder_first_read plus one compact builder_response, focused Panel/SQL docs, and a ready prompt; detailed data_model_handoff, surface_execution_plan, relationship_adapter_handoff, local_convention_probe, implementation_recipe, policy_decision_register, verification_execution_plan, acceptance_review_plan, and recovery detail stay behind dataphyre_app_builder_plan_generate detail/full pages until the next edit or check needs them.',
				],
				[
					'name'=>'Get compact cold-start or handoff brief',
					'audience_scope'=>'ordinary_app_compact_handoff',
					'when_to_use'=>'Use when a fresh or resumed agent needs only compact task direction before calling or continuing the app-builder plan.',
					'request'=>['jsonrpc'=>'2.0', 'id'=>93, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_mcp_agent_brief_export', 'arguments'=>['task'=>'build a small internal ticket tracker app with Projects, Tickets, admin panel CRUD, filters, and verification', 'target'=>'codex']]],
					'expect'=>'Returns first-page-only app-builder direction with builder_first_read.next_detail_page, compact app_builder_next_action, context_links, at most two next_actions, and optional policy_attention without start-pack discovery, workflow handoff, broad governance, top-level detail_pagination/payload_budget, or bulky handoff fields.',
				],
				[
					'name'=>'Open broader start-pack context when needed',
					'audience_scope'=>'ordinary_app_explicit_broader_context',
					'when_to_use'=>'Use only when the compact brief or direct builder plan is not enough and the agent needs broader discovery, workflow, or contract context.',
					'request'=>['jsonrpc'=>'2.0', 'id'=>94, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_mcp_task_start_pack_export', 'arguments'=>['task'=>'build a small internal ticket tracker app with Projects, Tickets, admin panel CRUD, filters, and verification', 'target'=>'codex', 'payload_profile'=>'builder']]],
					'expect'=>'Returns builder_first_read plus bounded workflow context and discovery matches without status board, safety boundary, or enterprise audit payloads; keep this out of the default first page.',
				],
				[
					'name'=>'Discover local Dataphyre applications',
					'audience_scope'=>'ordinary_app_path_discovery',
					'when_to_use'=>'Use when app_builder_path_context.placeholder_mode=true or before supplying application_path/app_namespace to the builder.',
					'request'=>['jsonrpc'=>'2.0', 'id'=>95, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_application_catalog', 'arguments'=>['scope'=>'applications/<app>', 'include_config_files'=>true, 'limit'=>5]]],
					'expect'=>'Returns bounded local application candidates, detected Dataphyre roots, config file names only, namespace hints, and path confidence without booting apps, dispatching routes, executing SQL, or reading config values.',
				],
				[
					'name'=>'Generate API endpoint app-builder plan',
					'audience_scope'=>'ordinary_app_first_step_api_endpoint',
					'when_to_use'=>'Use first for ordinary API/OpenAPI endpoint creation; the direct API scaffold tool remains a lower-level specialist surface.',
					'request'=>[
						'jsonrpc'=>'2.0',
						'id'=>913,
						'method'=>'tools/call',
						'params'=>[
							'name'=>'dataphyre_app_builder_plan_generate',
							'arguments'=>[
								'task'=>'build REST API endpoint for showing an order with OpenAPI docs and verification',
								'payload_profile'=>'compact',
								'scaffold_type'=>'api_endpoint',
								'name'=>'Orders Show',
								'path'=>'/api/orders/{order_id}',
								'methods'=>['GET'],
								'group'=>'orders.v1',
								'auth'=>'jwt',
							],
						],
					],
					'expect'=>'Returns app-owned API route, endpoint handler, and focused API regression files; builder_plan.scaffold_type=api_endpoint; compact verification_todo includes API docs static summary, route source summary, route manifest read, route URL preview, and PHP lint without dispatching handlers or opening MCP/release validation.',
				],
			],
			'docs'=>[
				[
					'name'=>'Search Dataphyre docs',
					'request'=>['jsonrpc'=>'2.0', 'id'=>101, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_search_docs', 'arguments'=>['query'=>'routing middleware', 'limit'=>5]]],
					'expect'=>'Returns bounded markdown snippets from local Dataphyre docs.',
				],
				[
					'name'=>'Build module docs pack',
					'request'=>['jsonrpc'=>'2.0', 'id'=>102, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_module_docs_pack', 'arguments'=>['module'=>'routing', 'max_bytes_per_doc'=>20000]]],
					'expect'=>'Returns module docs and baseline AI guidelines without executing runtime code.',
				],
			],
			'routes'=>[
				[
					'name'=>'Preview route match',
					'request'=>['jsonrpc'=>'2.0', 'id'=>201, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_route_match_preview', 'arguments'=>['method'=>'GET', 'path'=>'/orders/123']]],
					'expect'=>'Dry-matches route artifacts without dispatching handlers.',
				],
				[
					'name'=>'Report route source ambiguity',
					'request'=>['jsonrpc'=>'2.0', 'id'=>202, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_route_source_ambiguity_report', 'arguments'=>['limit'=>25]]],
					'expect'=>'Highlights non-literal route declarations that need manifest confirmation or source review.',
				],
			],
			'sql'=>[
				[
					'name'=>'Read SQL schema',
					'request'=>['jsonrpc'=>'2.0', 'id'=>301, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_sql_schema_read', 'arguments'=>['table'=>'dataphyre.mailer_outbox']]],
					'expect'=>'Inspects first-party schema metadata without opening a database connection.',
				],
				[
					'name'=>'Plan read-only SQL',
					'request'=>['jsonrpc'=>'2.0', 'id'=>302, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_sql_query_plan', 'arguments'=>['sql'=>'SELECT id, status FROM dataphyre.mailer_outbox', 'max_rows'=>25, 'allowed_tables'=>['dataphyre.mailer_outbox']]]],
					'expect'=>'Classifies the query and returns bounded preview SQL without executing it.',
				],
			],
			'diagnostics'=>[
				[
					'name'=>'List tracelog artifacts',
					'request'=>['jsonrpc'=>'2.0', 'id'=>401, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_tracelog_artifacts_list', 'arguments'=>['scope'=>'common/dataphyre/cache', 'limit'=>10]]],
					'expect'=>'Lists bounded log artifacts without reading their contents.',
				],
				[
					'name'=>'Find recent diagnostics',
					'request'=>['jsonrpc'=>'2.0', 'id'=>402, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_diagnostics_last_error', 'arguments'=>['scope'=>'common/dataphyre/cache', 'limit'=>5]]],
					'expect'=>'Returns redacted recent error-looking snippets from local diagnostics.',
				],
			],
			'client'=>[
				[
					'name'=>'Generate onboarding pack',
					'request'=>['jsonrpc'=>'2.0', 'id'=>501, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_mcp_client_onboarding_pack', 'arguments'=>['target'=>'generic', 'smoke_format'=>'all']]],
					'expect'=>'Returns portable client config, checklist, smoke tests, prompt catalog, and validation plan.',
				],
				[
					'name'=>'Audit client config',
					'request'=>['jsonrpc'=>'2.0', 'id'=>502, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_mcp_client_config_audit', 'arguments'=>['config'=>['mcpServers'=>['dataphyre'=>['command'=>'php', 'args'=>['common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php']]]]]]],
					'expect'=>'Checks a proposed client config for portable Dataphyre stdio setup issues.',
				],
			],
			'safety'=>[
				[
					'name'=>'Read safety boundary',
					'request'=>['jsonrpc'=>'2.0', 'id'=>601, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_mcp_safety_boundary_report', 'arguments'=>(object)[]]],
					'expect'=>'Reports read-only defaults, unsafe opt-in knobs, denied surfaces, and redaction policy.',
				],
				[
					'name'=>'Export surface changelog',
					'request'=>['jsonrpc'=>'2.0', 'id'=>602, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_mcp_surface_changelog', 'arguments'=>['audience'=>'agents']]],
					'expect'=>'Summarizes current client, safety, discovery, and validation surfaces.',
				],
			],
			'validation'=>[
				[
					'name'=>'Live stdio validation',
					'audience_scope'=>'ordinary_client_setup_or_mcp_surface_change',
					'request'=>['jsonrpc'=>'2.0', 'id'=>701, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_mcp_live_validate', 'arguments'=>(object)[]]],
					'expect'=>'Spawns the stdio server and validates core MCP client surfaces.',
				],
				[
					'name'=>'Aggregate verification',
					'audience_scope'=>'publication_validation_not_ordinary_app_work',
					'request'=>['jsonrpc'=>'2.0', 'id'=>702, 'method'=>'tools/call', 'params'=>['name'=>'dataphyre_mcp_verify_all', 'arguments'=>(object)[]]],
					'expect'=>'Runs lint, live validation, self-test, doctor, and app-coupling guard.',
				],
			],
		];
		$selected=$workflow==='all' ? $examples : [$workflow=>$examples[$workflow]];
		$tool_names=array_map(static fn(array $tool): string => (string)($tool['name'] ?? ''), $this->list_tools()['tools']);
		$missing=[];
		foreach($selected as $items){
			foreach($items as $item){
				$name=(string)($item['request']['params']['name'] ?? '');
				if($name!=='' && !in_array($name, $tool_names, true)){
					$missing[]=$name;
				}
			}
		}
		$payload=[
			'export_type'=>'dataphyre_mcp_tool_call_examples_export',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'workflow'=>$workflow,
			'protocol'=>'2025-11-25',
			'example_groups'=>$selected,
			'example_count'=>array_sum(array_map('count', $selected)),
			'missing_registered_tools'=>array_values(array_unique($missing)),
			'workflow_policy'=>[
				'app'=>[
					'first_copy'=>'dataphyre_app_builder_plan_generate',
					'optional_context'=>'dataphyre_task_pack_generate payload_profile=builder',
					'compact_handoff'=>'dataphyre_mcp_agent_brief_export',
					'recommendation_handoff'=>'dataphyre_mcp_workflow_recommendation_handoff_export returns handoff_pack_ref for ordinary app-building; fetch the full handoff pack only when runnable workflow session messages are needed.',
					'broader_cold_start'=>'dataphyre_mcp_task_start_pack_export payload_profile=builder',
					'detail_only_when'=>'full contracts, tool audience boundaries, or uncollapsed discovery are needed',
					'detail_profile'=>'dataphyre_mcp_task_start_pack_export payload_profile=detail adds contracts and discovery while app-builder bulk stays paginated',
					'deep_profile'=>'dataphyre_mcp_task_start_pack_export payload_profile=deep only for explicit escalation evidence such as status/safety, enterprise audit, or full workflow handoff',
					'first_page_fields'=>[
						'builder_first_read',
						'app_builder_next_action',
						'next_detail_page',
						'next_actions',
						'context_links',
						'policy_attention',
					],
					'detail_handoff_fields'=>[
						'scaffold_completion_summary',
						'entity_planning.continuation_calls',
						'surface_execution_plan',
						'companion_surface_handoff',
						'relationship_adapter_handoff',
						'local_convention_probe',
						'implementation_matrix',
						'implementation_recipe',
						'data_sensitivity_summary',
						'policy_decision_register',
						'prewrite_checklist.prewrite_blockers',
						'prewrite_checklist.implementation_obligations',
						'field_metadata_summary',
						'data_model_handoff',
						'code_skeleton_summary',
						'verification_evidence',
						'verification_handoff',
						'verification_execution_plan',
						'acceptance_review_plan',
						'verification_recovery_plan',
					],
					'write_readiness'=>'Resolve prewrite_checklist.prewrite_blockers before writing app-owned files; complete implementation_obligations and prewrite_reminders such as adaptation_notes during app-owned edits.',
					'verification'=>'Follow verification_execution_plan.items, then acceptance_review_plan.items, and collect focused app/module verification_evidence plus verification_handoff after app-owned writes; publication validation is not ordinary app proof.',
				],
				'validation'=>[
					'local_client_setup'=>'dataphyre_mcp_live_validate',
					'local_client_setup_boundary'=>'Use only after MCP client wiring, local server entrypoint, or stdio setup changes; not ordinary app behavior proof.',
					'ordinary_app_verification'=>'focused app/module checks from the app-builder verification_handoff or dataphyre_verification_surface_catalog',
					'publication_validation'=>$workflow==='app' ? 'MCP/release-surface publication validation' : 'dataphyre_mcp_verify_all',
				],
			],
			'usage_notes'=>[
				$workflow==='app'
					? 'Examples default to application agents building apps; keep publication validation in MCP/release-surface guidance, not ordinary app verification.'
					: 'Examples default to application agents building apps; treat validation examples that call dataphyre_mcp_verify_all as MCP/release-surface guidance, not ordinary app verification.',
				'Examples are JSON-RPC tools/call payloads; clients still need stdio Content-Length framing.',
				'Use dataphyre_mcp_smoke_test_export for complete framed initialize examples.',
				'Examples are read-only or bounded verification surfaces; SQL and route examples do not execute live app behavior.',
			],
		];
		if($workflow==='app'){
			$payload['governance_notes']=[
				'status'=>'none triggered',
				'default_lane'=>'builder',
				'open_only_for'=>$this->mcp_escalation_triggers(),
			];
			$payload['context_links']=array_replace($this->mcp_lightweight_discovery_context_links(), [
				'safety_boundary'=>'dataphyre_mcp_safety_boundary_report',
				'detail_start_pack'=>'dataphyre_mcp_task_start_pack_export payload_profile=builder',
			]);
		}else{
			$payload['application_agent_operating_contract']=$this->mcp_application_agent_operating_contract('tool_call_examples');
			$payload['ordinary_app_work']=$this->mcp_ordinary_app_work_contract('tool_call_examples');
			$payload['tool_audience_boundaries']=$this->mcp_current_tool_audience_boundaries();
		}
		return $payload;
	}

}
