<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Canonical MCP maintainer inventory surfaces.
 */
trait dataphyre_mcp_inspection_inventory_surfaces {

	/**
	 * Returns the canonical MCP kernel file inventory used by maintainer checks.
	 *
	 * @return array<string,string> Stable diagnostic names mapped to repo-relative PHP paths.
	 */
	private function mcp_kernel_surface_files(): array {
		return [
			'server'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php',
			'contract_source_interface'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.contract.source.php',
			'contract_source_index'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.contract.index.php',
			'contract_catalog'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.contract.catalog.php',
			'panel_capability_source_interface'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.panel.source.php',
			'panel_capability_source_index'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.panel.index.php',
			'panel_capability_catalog'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.panel.catalog.php',
			'registry_workflow_tool_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.registry.workflow_tools.php',
			'registry_tool_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.registry.tools.php',
			'registry_validation_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.registry.validation.php',
			'registry_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.registry.php',
			'source_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.source.php',
			'client_workflow_transcript_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.transcript.php',
			'client_workflow_state_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.state.php',
			'client_workflow_start_pack_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.start_pack.php',
			'client_workflow_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.php',
			'client_safety_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.safety.php',
			'client_enterprise_audit_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.enterprise.audit.php',
			'client_enterprise_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.enterprise.php',
			'client_capability_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.capabilities.php',
			'client_skill_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.skills.php',
			'client_example_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.examples.php',
			'client_brief_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.brief.php',
			'client_setup_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.setup.php',
			'client_prompt_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.prompts.php',
			'client_docs_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.docs.php',
			'client_discovery_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.discovery.php',
			'client_readiness_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.readiness.php',
			'client_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.client.php',
			'planning_app_builder_schema_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.schema.php',
			'planning_app_builder_sensitivity_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.sensitivity.php',
			'planning_app_builder_readiness_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.readiness.php',
			'planning_app_builder_contract_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.contract.php',
			'planning_app_builder_response_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.response.php',
			'planning_app_builder_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.php',
			'planning_api_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.planning.api.php',
			'planning_docs_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.planning.docs.php',
			'planning_task_pack_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.planning.task_pack.php',
			'planning_module_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.planning.modules.php',
			'planning_agent_context_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.planning.agent_context.php',
			'planning_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.planning.php',
			'inspection_data_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.inspection.data.php',
			'inspection_routing_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.inspection.routing.php',
			'inspection_mvc_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.inspection.mvc.php',
			'inspection_verification_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.inspection.verification.php',
			'inspection_diagnostics_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.inspection.diagnostics.php',
			'inspection_contract_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.inspection.contracts.php',
			'inspection_panel_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.inspection.panel.php',
			'inspection_inventory_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.inspection.inventory.php',
			'inspection_surfaces'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.inspection.php',
			'utility_schema_methods'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.utility.schema.php',
			'utility_methods'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.utility.php',
			'module_boot'=>'dataphyre/runtime/modules/mcp/kernel/mcp.main.php',
		];
	}

	/**
	 * Returns tracked contributor MCP support files for public validation.
	 *
	 * @return array<string,string> Stable diagnostic names mapped to repo-relative PHP paths.
	 */
	private function mcp_source_checkout_support_files(): array {
		return [
			'self_test'=>'dataphyre/dev/tools/public/mcp_self_test.php',
			'live_validator'=>'dataphyre/dev/tools/public/mcp_live_validate.php',
			'config_generator'=>'dataphyre/dev/tools/public/mcp_config.php',
		];
	}

}
