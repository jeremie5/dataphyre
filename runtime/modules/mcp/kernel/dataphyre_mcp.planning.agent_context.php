<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Agent-context generation surfaces for Dataphyre MCP planning.
 */
trait dataphyre_mcp_planning_agent_context_surfaces {

	/**
	 * Generates an agent-context artifact preview for Dataphyre work.
	 *
	 * this tool composes target-specific guidance, focus modules, and
	 * source-document references but explicitly returns content as a dry-run
	 * payload instead of writing AGENTS, CLAUDE, Cursor, or generic files.
	 */
	private function generate_agent_context(array $args): array {
		$target=strtolower(trim((string)($args['target'] ?? 'generic')));
		if(!in_array($target, ['codex', 'claude', 'cursor', 'generic'], true)){
			throw new InvalidArgumentException('target must be one of codex, claude, cursor, or generic.');
		}
		$requested_modules=$args['modules'] ?? [];
		if(!is_array($requested_modules) || $requested_modules===[]){
			$requested_modules=$this->default_agent_context_modules();
		}
		$modules=[];
		$documents=[
			'common/dataphyre/docs/AGENTIC_ENTERPRISE.md',
			'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md',
			'common/dataphyre/docs/MODULES.md',
			'common/dataphyre/runtime/README.md',
			'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_MCP.md',
		];
		foreach(array_slice($requested_modules, 0, 12) as $module){
			$module=trim((string)$module);
			if($module==='' || in_array($module, $modules, true)){
				continue;
			}
			$description=$this->describe_module($module, 20);
			$modules[]=$module;
			foreach($description['files']['documentation'] ?? [] as $path){
				$documents[]=$path;
			}
		}
		$documents=array_values(array_unique($documents));
		return [
			'target'=>$target,
			'recommended_path'=>$this->agent_context_path($target),
			'write_policy'=>'not_written_by_mcp_tool',
			'default_app_entrypoint'=>[
				'tool'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>['payload_profile'=>'compact'],
				'first_read'=>'builder_response.first_read',
				'purpose'=>'Ordinary app work starts from the compact app-builder lane before broad runtime instructions.',
			],
			'escalation_refs'=>[
				'enterprise_contract'=>'common/dataphyre/docs/AGENTIC_ENTERPRISE.md',
				'mcp_boundary'=>'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_MCP.md',
				'readiness_report'=>'dataphyre_mcp_readiness_report',
				'enterprise_audit'=>'dataphyre_mcp_enterprise_adoption_audit',
			],
			'modules'=>$modules,
			'source_documents'=>$documents,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('agent_context_generate'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('agent_context_generate'),
			'content'=>$this->agent_context_content($target, $modules, $documents),
		];
	}

	/**
	 * Chooses default modules for generated agent context.
	 *
	 * defaults are filtered against the current module inventory so
	 * generated guidance references only modules that exist in this checkout.
	 */
	private function default_agent_context_modules(): array {
		$available=array_column($this->module_list()['modules'], 'name');
		return array_values(array_filter(['core', 'routing', 'sql', 'panel', 'mcp'], static fn(string $module): bool => in_array($module, $available, true)));
	}

	/**
	 * Resolves the conventional context filename for an agent target.
	 *
	 * the mapping is target-specific and side-effect free; callers receive
	 * a recommended path while the MCP server keeps the write policy as dry-run.
	 */
	private function agent_context_path(string $target): string {
		return match($target){
			'codex'=>'docs/AGENTS.md',
			'claude'=>'CLAUDE.md',
			'cursor'=>'.cursor/rules/dataphyre.mdc',
			default=>'dataphyre-ai-context.md',
		};
	}

	/**
	 * Renders the generated agent-context markdown body.
	 *
	 * content is deterministic from target, module list, and document
	 * list. Cursor receives front matter while other targets receive plain
	 * markdown, and no repository files are modified.
	 */
	private function agent_context_content(string $target, array $modules, array $documents): string {
		$lines=[];
		if($target==='cursor'){
			$lines[]='---';
			$lines[]='description: Dataphyre runtime rules and MCP workflow';
			$lines[]='alwaysApply: true';
			$lines[]='---';
			$lines[]='';
		}
		$lines[]='# Dataphyre Agent Context';
		$lines[]='';
		$lines[]='Use this context primarily to build Dataphyre applications safely; framework-maintainer guidance applies only when a task explicitly touches Dataphyre internals, release surfaces, or shared hot paths.';
		$lines[]='';
		$lines[]='## Application-Agent Default Lane';
		$lines[]='';
		$lines[]='- For ordinary app creation, first call `dataphyre_app_builder_plan_generate` with `payload_profile=compact` and read `builder_response.first_read`.';
		$lines[]='- Add focused docs, registered MCP metadata, safe route/config/storage/SQL/diagnostic metadata, and dry-run plans only when the first read points there.';
		$lines[]='- Put application behavior in app code, install config, callbacks, dialbacks, plugins, MCP metadata, or application adapters before proposing Dataphyre runtime-internal edits.';
		$lines[]='- Verify app behavior with focused application or module checks.';
		$lines[]='- Keep maintainer-only release proof, unsafe runtime surfaces, and shared hot-path proof out of ordinary app work unless the task explicitly escalates.';
		$lines[]='';
		$lines[]='## Enterprise Contract';
		$lines[]='';
		$lines[]='- Read `common/dataphyre/docs/AGENTIC_ENTERPRISE.md` before framework-level edits.';
		$lines[]='- Prefer config, dialbacks, callbacks, plugins, MCP metadata, and reusable modules before changing Dataphyre internals for an application.';
		$lines[]='- Keep agent-facing behavior discoverable, bounded, extensible, proven, portable, and composable.';
		$lines[]='- For Dataphyre shared production hot-path changes only, keep the change only with Dataphyre maintainer proof; ordinary application agents should not inherit that evidence burden.';
		$lines[]='';
		$lines[]='## Runtime Shape';
		$lines[]='';
		$lines[]='- Dataphyre is a modular PHP runtime under `common/dataphyre/runtime/modules/<module>`.';
		$lines[]='- Prefer module `Framework/` classes for public contracts and `kernel/` files for bootstrap or compatibility surfaces.';
		$lines[]='- Keep reusable framework work route-free unless the Routing module or an application route layer owns it.';
		$lines[]='- Do not hardcode product-specific paths, URLs, names, credentials, or assumptions in shared runtime code.';
		$lines[]='';
		$lines[]='## MCP Workflow';
		$lines[]='';
		$lines[]='- For application work, start with `dataphyre_app_builder_plan_generate payload_profile=compact`; then open module docs, route/config/schema summaries, and dry-run plans only as needed before editing app code.';
		$lines[]='- Use `dataphyre_module_docs_pack`, `dataphyre_search_docs`, or `dataphyre_read_doc` before changing a Dataphyre module itself.';
		$lines[]='- Use route tools only for manifest reads, URL previews, and dry matching; do not dispatch handlers from diagnostics.';
		$lines[]='- Use SQL tools only for schema and cluster metadata; do not execute queries or expose credentials.';
		$lines[]='- Use diagnostics tools for bounded Tracelog/log previews with redaction.';
		$lines[]='- Run `dataphyre_mcp_doctor` and the MCP self-test only for MCP or release-surface work; ordinary application changes should use focused app or module checks.';
		$lines[]='';
		$lines[]='## Verification';
		$lines[]='';
		$lines[]='- Run PHP lint for touched PHP files.';
		$lines[]='- Prefer route-free harnesses such as Panel regression and field catalog checks.';
		$lines[]='- Treat existing release-check failures as existing hygiene work unless the task asks to fix them.';
		$lines[]='';
		$lines[]='## Focus Modules';
		$lines[]='';
		foreach($modules as $module){
			$lines[]='- `'.$module.'`';
		}
		$lines[]='';
		$lines[]='## Source Documents';
		$lines[]='';
		foreach($documents as $document){
			$lines[]='- `'.$document.'`';
		}
		return implode("\n", $lines)."\n";
	}

}
