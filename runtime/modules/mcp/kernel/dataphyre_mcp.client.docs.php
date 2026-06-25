<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Documentation coverage surfaces for Dataphyre's MCP client contract.
 */
trait dataphyre_mcp_client_docs_surfaces {

	/**
	 * Reports whether public MCP docs mention the registered tools, resources, prompts, and skills.
	 *
	 * @return array MCP documentation coverage report payload.
	 */
	private function mcp_docs_coverage_report(): array {
		$docs_path='common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_MCP.md';
		$guidelines_path='common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md';
		$docs=$this->read_repo_text($docs_path, 240000);
		$guidelines=$this->read_repo_text($guidelines_path, 160000);
		$tools=array_map(static fn(array $tool): string => (string)($tool['name'] ?? ''), $this->list_tools()['tools']);
		$resources=array_map(static fn(array $resource): string => (string)($resource['uri'] ?? ''), $this->list_resources()['resources']);
		$prompts=array_map(static fn(array $prompt): string => (string)($prompt['name'] ?? ''), $this->list_prompts()['prompts']);
		$skills=array_keys($this->mcp_skill_definitions());
		$missing_tools=[];
		foreach($tools as $tool){
			if(!str_contains($docs, '`'.$tool.'`') && !str_contains($docs, $tool)){
				$missing_tools[]=$tool;
			}
		}
		$missing_resources=[];
		foreach($resources as $resource){
			if(str_starts_with($resource, 'dataphyre://doc/')){
				continue;
			}
			if(!str_contains($docs, $resource)){
				$missing_resources[]=$resource;
			}
		}
		$missing_prompts=[];
		foreach($prompts as $prompt){
			if(!str_contains($docs, $prompt) && !str_contains($guidelines, $prompt)){
				$missing_prompts[]=$prompt;
			}
		}
		$missing_skills=[];
		foreach($skills as $skill){
			if(!str_contains($docs, $skill) && !str_contains($guidelines, $skill)){
				$missing_skills[]=$skill;
			}
		}
		$safety_terms=[
			'SQL query execution',
			'route dispatch',
			'config secret',
			'product-local',
			'app-specific',
			'--allow-unsafe',
		];
		$missing_safety=[];
		foreach($safety_terms as $term){
			if(!str_contains(strtolower($docs."\n".$guidelines), strtolower($term))){
				$missing_safety[]=$term;
			}
		}
		return [
			'report_type'=>'dataphyre_mcp_docs_coverage_report',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('docs_coverage_report'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('docs_coverage_report'),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'publication_validation'=>[
				'tool_scope'=>'mcp_docs_and_safety_boundary_publication',
				'app_agent_default'=>'not_required_for_ordinary_application_work',
				'claim_boundary'=>'Docs coverage supports MCP/release-surface documentation claims; application behavior still needs focused app or module verification.',
			],
			'docs_path'=>$docs_path,
			'guidelines_path'=>$guidelines_path,
			'counts'=>[
				'tools'=>count($tools),
				'resources'=>count($resources),
				'prompts'=>count($prompts),
				'skills'=>count($skills),
				'missing_tools'=>count($missing_tools),
				'missing_core_resources'=>count($missing_resources),
				'missing_prompts'=>count($missing_prompts),
				'missing_skills'=>count($missing_skills),
				'missing_safety_terms'=>count($missing_safety),
			],
			'complete'=>$missing_tools===[] && $missing_resources===[] && $missing_prompts===[] && $missing_skills===[] && $missing_safety===[],
			'missing'=>[
				'tools'=>$missing_tools,
				'core_resources'=>$missing_resources,
				'prompts'=>$missing_prompts,
				'skills'=>$missing_skills,
				'safety_terms'=>$missing_safety,
			],
			'recommended_followup'=>[
				'Add each missing tool to the Current Capabilities list in Dataphyre_MCP.md.',
				'Mention new resources, prompts, skills, and safety boundaries in Dataphyre_MCP.md or Dataphyre_AI_Guidelines.md.',
				'Run this report, dataphyre_mcp_doctor, and the MCP self-test after public MCP surface changes.',
			],
		];
	}

}
