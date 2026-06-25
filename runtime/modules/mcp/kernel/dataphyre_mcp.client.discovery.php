<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Lightweight MCP discovery surfaces for application agents.
 */
trait dataphyre_mcp_client_discovery_surfaces {

	/**
	 * Lists core, release-facing resource URIs without discovered markdown docs.
	 *
	 * @return array<int, string> Stable core MCP resource URIs.
	 */
	private function mcp_core_resource_uris(): array {
		return array_values(array_filter(
			array_map(static fn(array $resource): string => (string)($resource['uri'] ?? ''), $this->list_resources()['resources']),
			static fn(string $uri): bool => $uri!=='' && !str_starts_with($uri, 'dataphyre://doc/')
		));
	}

	/**
	 * Searches registered MCP tools by query, family, and safety metadata.
	 *
	 * @param array<string,mixed> $args Search query and filter options.
	 * @return array Tool finder payload.
	 */
	private function mcp_tool_finder(array $args): array {
		$raw_query=trim((string)($args['query'] ?? ''));
		$query=strtolower($raw_query);
		$group_filter=strtolower(trim((string)($args['group'] ?? '')));
		$limit=max(1, min((int)($args['limit'] ?? 12) ?: 12, 80));
		$app_builder_query=$this->mcp_task_implies_app_builder($query);
		$api_endpoint_query=$app_builder_query && $this->infer_app_builder_scaffold_type($raw_query, [])==='api_endpoint';
		$manifest=$this->mcp_manifest_export(['include_schemas'=>false, 'include_docs_resources'=>false]);
		$group_by_tool=[];
		$audience_scope_by_tool=[];
		$boundary_by_tool=[];
		foreach($manifest['tool_groups'] ?? [] as $group=>$details){
			foreach(($details['tools'] ?? []) as $tool){
				$tool_name=(string)$tool;
				$group_by_tool[$tool_name]=(string)$group;
				$audience_scope_by_tool[$tool_name]=(string)($details['audience_scope'] ?? '');
				if(is_array($details['tool_boundaries'][$tool_name] ?? null)){
					$boundary_by_tool[$tool_name]=$details['tool_boundaries'][$tool_name];
				}
			}
		}
		$matches=[];
		foreach($manifest['tools'] ?? [] as $tool){
			$name=(string)($tool['name'] ?? '');
			$description=(string)($tool['description'] ?? '');
			$group=$group_by_tool[$name] ?? 'ungrouped';
			if($group_filter!=='' && strtolower($group)!==$group_filter){
				continue;
			}
			$haystack=strtolower($name.' '.$description.' '.$group);
			$score=0;
			$reasons=[];
			if($query===''){
				$score=1;
				$reasons[]='listed';
			}else{
				foreach(preg_split('/\s+/', $query) ?: [] as $term){
					$term=trim($term);
					if($term===''){
						continue;
					}
					if(str_contains(strtolower($name), $term)){
						$score+=5;
						$reasons[]='name:'.$term;
					}
					if(str_contains(strtolower($description), $term)){
						$score+=2;
						$reasons[]='description:'.$term;
					}
					if(str_contains(strtolower($group), $term)){
						$score+=3;
						$reasons[]='group:'.$term;
					}
					if(!str_contains($haystack, $term)){
						$score-=1;
					}
				}
			}
			if($app_builder_query){
				$app_builder_boosts=[
					'dataphyre_app_builder_plan_generate'=>50,
					'dataphyre_task_pack_generate'=>36,
					'dataphyre_mcp_agent_brief_export'=>34,
					'dataphyre_mcp_task_start_pack_export'=>28,
					'dataphyre_scaffold_plan_generate'=>24,
					'dataphyre_panel_scaffold_catalog'=>12,
					'dataphyre_run_panel_regression'=>10,
					'dataphyre_run_panel_field_catalog_check'=>10,
				];
				if(isset($app_builder_boosts[$name])){
					$score+=$app_builder_boosts[$name];
					$reasons[]='app_builder_golden_path';
				}
			}
			if($score<=0){
				continue;
			}
			$audience_scope=$audience_scope_by_tool[$name] ?? $this->mcp_tool_group_audience_scope($group);
			$tool_boundary=$boundary_by_tool[$name] ?? ($this->mcp_tool_boundary_map([$name])[$name] ?? []);
			$matches[]=[
				'name'=>$name,
				'group'=>$group,
				'audience_scope'=>$audience_scope,
				'tool_boundary'=>$tool_boundary,
				'use_policy'=>$this->mcp_tool_match_use_policy($name, $audience_scope, $tool_boundary),
				'description'=>$description,
				'score'=>$score,
				'match_reasons'=>array_values(array_unique($reasons)),
			];
		}
		usort($matches, static function(array $a, array $b): int {
			$score=(int)($b['score'] ?? 0) <=> (int)($a['score'] ?? 0);
			return $score!==0 ? $score : strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
		});
		$next_steps=$app_builder_query ? array_values(array_filter([
			'Call dataphyre_app_builder_plan_generate first for ordinary app creation; use payload_profile=compact for first-read planning, and pass explicit entities, fields, and max_entities when the task already names them. Field hints should use explicit relationship metadata such as foreign_key_target, non-relationship markers such as not_foreign_key, and json/jsonb types when needed.',
			$api_endpoint_query ? 'For API endpoint work, pass scaffold_type=api_endpoint plus path, methods, group, and auth when known; keep route/API verification focused with API docs static summary, route manifest read, URL preview, and PHP lint.' : '',
			'For large app scaffolds, follow entity_planning.continuation_calls until deferred_entities is empty and preserve dependency_context for cross-chunk relationships.',
			'Use dataphyre_task_pack_generate payload_profile=builder only when focused module docs or a ready prompt are needed.',
			'Open governance, enterprise audit, release checks, or hot-path benchmark evidence only when the task matches escalation triggers: '.$this->mcp_escalation_trigger_summary().'.',
		], static fn(string $step): bool => $step!=='')) : [
			'For ordinary app work, stay in the Application-Agent Default Lane: metadata first, app-owned extension points, and focused app/module checks.',
			'Use dataphyre_mcp_manifest_export only when complete tool schemas or grouped metadata are needed.',
			'Use dataphyre_mcp_capability_matrix or dataphyre_mcp_status_board only for explicit MCP/framework readiness, release-surface, or client setup questions.',
		];
		$recommended_arguments=$this->mcp_app_builder_recommended_arguments($raw_query!=='' ? $raw_query : $query);
		$optional_arguments=$this->mcp_app_builder_optional_arguments();
		if($api_endpoint_query){
			$recommended_arguments['scaffold_type']='api_endpoint';
			$recommended_arguments['path']='<api-path>';
			$recommended_arguments['methods']=['GET'];
			$recommended_arguments['auth']='none';
			$optional_arguments['path']='endpoint path such as /api/orders/{order_id}';
			$optional_arguments['methods']='array<string> HTTP methods such as GET or POST';
			$optional_arguments['group']='optional API group/profile name';
			$optional_arguments['auth']='optional auth hint such as none, jwt, api_key, session, or custom';
		}
		$argument_hint=$this->mcp_app_builder_argument_hint();
		if($api_endpoint_query){
			$argument_hint.=' For API endpoint work, pass scaffold_type=api_endpoint plus path, methods, group, and auth when known.';
		}
		return [
			'finder_type'=>'dataphyre_mcp_tool_finder',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'query'=>$query,
			'group'=>$group_filter,
			'limit'=>$limit,
			'available_groups'=>array_keys($manifest['tool_groups'] ?? []),
			'match_count'=>count($matches),
			'matches'=>array_slice($matches, 0, $limit),
			'recommended_first_call'=>$app_builder_query ? [
				'tool'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>$recommended_arguments,
				'decision_field'=>'builder_response.first_read.next_action',
				'follow_until'=>'builder_response.first_read.write_readiness.ready_for_app_owned_writes=true or scaffold_completion_summary.next_continuation.available=false',
				'argument_hint'=>$argument_hint,
				'optional_arguments'=>$optional_arguments,
				'open_full_skeletons'=>'Rerun dataphyre_app_builder_plan_generate with payload_profile=full only when ready to adapt app-owned code skeletons.',
				'not_required'=>'Do not open governance, publication validation, release checks, or benchmark evidence for ordinary app-building discovery.',
			] : [
				'tool'=>null,
				'arguments'=>[],
				'decision_field'=>'matches[0]',
				'follow_until'=>'Use matched tools as read-only metadata before app-owned edits.',
			],
			'governance_notes'=>[
				'status'=>'not inlined for discovery',
				'default_lane'=>'read_only_discovery',
				'ordinary_app_work'=>'use matched tools as metadata before app-owned edits',
			],
			'discovery_contract'=>$this->mcp_lightweight_discovery_contract($app_builder_query ? 'app_builder' : 'general'),
			'context_links'=>$this->mcp_lightweight_discovery_context_links(),
			'next_steps'=>$next_steps,
		];
	}

	/**
	 * Returns a compact discovery contract without inlining governance payloads.
	 *
	 * @param string $mode Discovery mode.
	 * @return array<string,mixed> Lightweight app-agent discovery guidance.
	 */
	private function mcp_lightweight_discovery_contract(string $mode): array {
		$app_builder=$mode==='app_builder';
		return [
			'mode'=>$app_builder ? 'app_builder' : 'general',
			'default_lane'=>'read_only_discovery',
			'ordinary_owner'=>'consuming_application',
			'first_action'=>$app_builder ? 'Call dataphyre_app_builder_plan_generate before opening broader context.' : 'Use matched tools/resources as read-only metadata before app-owned edits.',
			'compact_fields'=>$app_builder
				? ['scaffold_completion_summary.next_continuation', 'extension_boundary_summary.placement_decision', 'prewrite_checklist.prewrite_blockers', 'prewrite_checklist.implementation_obligations', 'verification_handoff', 'diagnostic_summary.copy_safe_evidence', 'copy_safe_resume']
				: ['ordinary_app_work', 'tool_audience_boundaries', 'diagnostic_summary.copy_safe_evidence', 'copy_safe_resume'],
			'governance_inline'=>false,
			'escalate_only_for'=>$this->mcp_escalation_triggers(),
			'not_ordinary_app_ceremony'=>['dataphyre_mcp_verify_all', 'source-checkout dev tools', 'Dataphyre hot-path benchmarks', 'runtime-internal edits for one app'],
			'default_policy'=>'inline',
			'escalation_details'=>'dataphyre_mcp_readiness_report.agent_workload_policy',
		];
	}

	/**
	 * Returns compact context links for discovery payloads without nudging agents
	 * toward the heavier readiness report during ordinary app work.
	 *
	 * @return array<string,string>
	 */
	private function mcp_lightweight_discovery_context_links(): array {
		return [
			'application_agent_default'=>'discovery_contract',
			'ordinary_app_work'=>'discovery_contract.compact_fields',
			'tool_audience_boundaries'=>'discovery_contract.not_ordinary_app_ceremony',
			'escalation_readiness_report'=>'dataphyre_mcp_readiness_report',
			'enterprise_audit'=>'dataphyre_mcp_enterprise_adoption_audit',
		];
	}

	/**
	 * Tells app agents when a matched tool should actually be called.
	 *
	 * @param string $tool_name Tool name.
	 * @param string $audience_scope Manifest-level audience scope.
	 * @param array<string,mixed> $tool_boundary Per-tool boundary metadata.
	 * @return array<string,mixed> Compact use policy.
	 */
	private function mcp_tool_match_use_policy(string $tool_name, string $audience_scope, array $tool_boundary): array {
		$scope=(string)($tool_boundary['audience_scope'] ?? $audience_scope);
		if($tool_name==='dataphyre_app_builder_plan_generate'){
			return [
				'call_when'=>'ordinary app creation, CRUD, API, Panel, SQL, workflow, or scaffold planning is the task',
				'default_action'=>'call first with payload_profile=compact',
				'not_required'=>['publication validation', 'Dataphyre hot-path benchmark evidence', 'runtime-internal edits'],
			];
		}
		return match($scope){
			'focused_app_or_module_verification'=>[
				'call_when'=>'after app-owned changes or when verification_handoff names this focused check',
				'default_action'=>'focused app/module verification only',
				'not_required'=>['dataphyre_mcp_verify_all', 'release proof', 'Dataphyre hot-path benchmark evidence'],
			],
			'publication_validation_not_ordinary_app_work'=>[
				'call_when'=>'only for MCP/release-surface claims, published shared setup docs, release notes, or MCP server wiring changes',
				'default_action'=>'leave collapsed for ordinary app work',
				'not_required'=>['ordinary application behavior proof', 'routine app scaffolds', 'focused app/module verification'],
			],
			'local_client_setup_not_app_behavior'=>[
				'call_when'=>'only for local MCP client setup, stdio server entrypoint, or client wiring validation',
				'default_action'=>'not app behavior proof',
				'not_required'=>['ordinary application behavior proof', 'focused app/module verification'],
			],
			'application_agents_building_apps_with_collapsed_escalation'=>[
				'call_when'=>'when this matched planning or guidance tool is the next app-building step',
				'default_action'=>'keep governance collapsed unless escalation triggers match',
				'not_required'=>['publication validation', 'release checks', 'Dataphyre hot-path benchmark evidence'],
			],
			'application_agents_building_apps'=>[
				'call_when'=>'when this matched read-only inspection tool answers the next app-building question',
				'default_action'=>'safe ordinary app metadata before app-owned edits',
				'not_required'=>['publication validation', 'dataphyre_mcp_verify_all', 'runtime-internal edits'],
			],
			default=>[
				'call_when'=>'when this match is selected as the next read-only discovery step',
				'default_action'=>'read-only discovery context',
				'not_required'=>['unsafe mode', 'publication validation'],
			],
		};
	}

	/**
	 * Returns the shared compact app-builder argument hint for app-agent discovery.
	 *
	 * @return string Argument hint.
	 */
	private function mcp_app_builder_argument_hint(): string {
		return 'Use payload_profile=compact for first-read planning; pass explicit entities, fields, and max_entities when the task already names them, with foreign_key_target for relationships, not_foreign_key for external ids, and json/jsonb for structured columns.';
	}

	/**
	 * Returns default arguments for the app-builder golden path.
	 *
	 * @param string $task Task text.
	 * @return array<string,mixed> Recommended arguments.
	 */
	private function mcp_app_builder_recommended_arguments(string $task): array {
		return [
			'task'=>$task,
			'payload_profile'=>'compact',
		];
	}

	/**
	 * Returns shared optional argument hints for app-builder discovery.
	 *
	 * @return array<string,string> Optional argument descriptions.
	 */
	private function mcp_app_builder_optional_arguments(): array {
		return [
			'entities'=>'array<string> when the app model is known',
			'fields'=>'nested per-entity map with foreign_key_target for relationships, not_foreign_key or foreign_key=false for external ids, and json/jsonb types when field hints are known',
			'max_entities'=>'integer chunk size hint for larger app scaffolds',
		];
	}

	/**
	 * Searches registered MCP resources by query and URI metadata.
	 *
	 * @param array<string,mixed> $args Search query and filter options.
	 * @return array Resource finder payload.
	 */
	private function mcp_resource_finder(array $args): array {
		$raw_query=trim((string)($args['query'] ?? ''));
		$query=strtolower($raw_query);
		$kind=strtolower(trim((string)($args['kind'] ?? 'all')));
		if(!in_array($kind, ['all', 'resource', 'prompt'], true)){
			$kind='all';
		}
		$limit=max(1, min((int)($args['limit'] ?? 12) ?: 12, 80));
		$app_builder_task=$this->mcp_task_implies_app_builder($query);
		$api_endpoint_task=$app_builder_task && $this->infer_app_builder_scaffold_type($raw_query, [])==='api_endpoint';
		$module_doc_task=$app_builder_task || str_contains($query, 'panel') || str_contains($query, 'sql') || str_contains($query, 'schema') || str_contains($query, 'table') || str_contains($query, 'api') || str_contains($query, 'openapi') || str_contains($query, 'endpoint');
		$app_modules=[];
		if($module_doc_task){
			$app_modules=$app_builder_task ? ($api_endpoint_task ? ['api', 'routing'] : ['panel', 'sql']) : [];
			if(str_contains($query, 'panel') || str_contains($query, 'crud') || str_contains($query, 'resource') || str_contains($query, 'admin')){
				$app_modules[]='panel';
			}
			if(str_contains($query, 'sql') || str_contains($query, 'schema') || str_contains($query, 'table')){
				$app_modules[]='sql';
			}
			if(str_contains($query, 'api') || str_contains($query, 'openapi') || str_contains($query, 'endpoint') || str_contains($query, 'rest')){
				$app_modules[]='api';
				$app_modules[]='routing';
			}
			if($app_modules===[] && str_contains($query, 'application')){
				$app_modules=['panel', 'sql'];
			}
			if(str_contains($query, 'route') || str_contains($query, 'controller') || str_contains($query, 'middleware')){
				$app_modules[]='routing';
			}
		}
		$candidates=[];
		if($kind==='all' || $kind==='resource'){
			foreach($this->list_resources()['resources'] as $resource){
				$candidates[]=[
					'kind'=>'resource',
					'id'=>(string)($resource['uri'] ?? ''),
					'name'=>(string)($resource['name'] ?? ''),
					'description'=>(string)($resource['mimeType'] ?? ''),
				];
			}
			foreach(array_values(array_unique($app_modules)) as $module){
				$description=$this->describe_module($module, 20);
				foreach($description['files']['documentation'] ?? [] as $path){
					$candidates[]=[
						'kind'=>'documentation',
						'id'=>(string)$path,
						'name'=>(string)($description['name'] ?? $module).' documentation',
						'description'=>'Module documentation for '.(string)$module.'; fetch with dataphyre_read_doc.',
						'module'=>(string)$module,
						'path'=>(string)$path,
						'fetch_tool'=>'dataphyre_read_doc',
					];
				}
			}
		}
		if($kind==='all' || $kind==='prompt'){
			foreach($this->list_prompts()['prompts'] as $prompt){
				$candidates[]=[
					'kind'=>'prompt',
					'id'=>(string)($prompt['name'] ?? ''),
					'name'=>(string)($prompt['name'] ?? ''),
					'description'=>(string)($prompt['description'] ?? ''),
				];
			}
		}
		$matches=[];
		foreach($candidates as $candidate){
			$haystack=strtolower($candidate['id'].' '.$candidate['name'].' '.$candidate['description'].' '.$candidate['kind']);
			$score=0;
			$reasons=[];
			if($query===''){
				$score=1;
				$reasons[]='listed';
			}else{
				foreach(preg_split('/\s+/', $query) ?: [] as $term){
					$term=trim($term);
					if($term===''){
						continue;
					}
					if(str_contains(strtolower($candidate['id']), $term)){
						$score+=5;
						$reasons[]='id:'.$term;
					}
					if(str_contains(strtolower($candidate['name']), $term)){
						$score+=3;
						$reasons[]='name:'.$term;
					}
					if(str_contains(strtolower($candidate['description']), $term)){
						$score+=2;
						$reasons[]='description:'.$term;
					}
					if(str_contains(strtolower($candidate['kind']), $term)){
						$score+=2;
						$reasons[]='kind:'.$term;
					}
					if(!str_contains($haystack, $term)){
						$score-=1;
					}
				}
			}
			if($module_doc_task){
				if(($candidate['kind'] ?? '')==='documentation' && in_array((string)($candidate['module'] ?? ''), ['panel', 'sql', 'api', 'routing'], true)){
					$score+=12;
					$reasons[]='app_module_docs';
				}
			}
			if($app_builder_task){
				if(($candidate['kind'] ?? '')==='prompt' && in_array((string)($candidate['id'] ?? ''), ['dataphyre_panel_workflow', 'dataphyre_feature_plan', 'dataphyre_sql_schema_workflow'], true)){
					$score+=4;
					$reasons[]='app_builder_prompt';
				}
				if(in_array((string)($candidate['id'] ?? ''), ['dataphyre://ai-guidelines', 'dataphyre://agentic-enterprise'], true)){
					$score-=5;
					$reasons[]='governance_collapsed_for_app_task';
				}
			}
			if($score<=0){
				continue;
			}
			$candidate['audience_scope']=$this->mcp_resource_match_audience_scope($candidate);
			$candidate['open_policy']=$this->mcp_resource_match_open_policy($candidate);
			$candidate['score']=$score;
			$candidate['match_reasons']=array_values(array_unique($reasons));
			$matches[]=$candidate;
		}
		usort($matches, static function(array $a, array $b): int {
			$score=(int)($b['score'] ?? 0) <=> (int)($a['score'] ?? 0);
			return $score!==0 ? $score : strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
		});
		$next_steps=$app_builder_task ? array_values(array_filter([
			$api_endpoint_task ? 'Use matched API and Routing docs as read-only context after the app-builder plan, not before the planner.' : 'Use matched Panel and SQL docs as read-only context after the app-builder plan, not before the planner.',
			'Call dataphyre_app_builder_plan_generate first with payload_profile=compact plus explicit entities, fields, and max_entities when available; use foreign_key_target for relationships, not_foreign_key for external ids, and json/jsonb for structured columns.',
			$api_endpoint_task ? 'For API endpoint work, pass scaffold_type=api_endpoint plus path, methods, group, and auth when known; keep OpenAPI and route verification focused.' : '',
			'For large app scaffolds, follow entity_planning.continuation_calls until deferred_entities is empty.',
			'Use dataphyre_read_doc for matched documentation paths and prompts/get for matched workflow prompts when extra context is needed.',
		], static fn(string $step): bool => $step!=='')) : [
			'For ordinary app work, use matched resources and prompts as read-only planning context before editing app-owned code or extensions.',
			'Use resources/read for matched dataphyre:// resources.',
			'Use dataphyre_read_doc for matched documentation paths.',
			'Use prompts/get for matched workflow prompts.',
			'Use dataphyre_prompt_pack_export when a client needs prompt text in one payload.',
			'Use dataphyre_mcp_manifest_export for complete tool, prompt, and resource metadata.',
		];
		$recommended_arguments=$this->mcp_app_builder_recommended_arguments($raw_query!=='' ? $raw_query : $query);
		return [
			'finder_type'=>'dataphyre_mcp_resource_finder',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'query'=>$query,
			'kind'=>$kind,
			'limit'=>$limit,
			'match_count'=>count($matches),
			'matches'=>array_slice($matches, 0, $limit),
			'recommended_first_call'=>$app_builder_task ? [
				'tool'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>$recommended_arguments,
				'decision_field'=>'builder_response.first_read.next_action',
				'follow_until'=>'builder_response.first_read.write_readiness.ready_for_app_owned_writes=true or scaffold_completion_summary.next_continuation.available=false',
				'argument_hint'=>$this->mcp_app_builder_argument_hint(),
				'optional_arguments'=>$this->mcp_app_builder_optional_arguments(),
				'open_full_skeletons'=>'Rerun dataphyre_app_builder_plan_generate with payload_profile=full only when ready to adapt app-owned code skeletons.',
				'not_required'=>'Do not open governance, publication validation, release checks, or benchmark evidence for ordinary app-building resource discovery.',
			] : [
				'tool'=>null,
				'arguments'=>[],
				'decision_field'=>'matches[0]',
				'follow_until'=>'Use matched resources and prompts as read-only metadata before app-owned edits.',
			],
			'governance_notes'=>[
				'status'=>'not inlined for discovery',
				'default_lane'=>'read_only_discovery',
				'ordinary_app_work'=>'use matched resources and prompts as metadata before app-owned edits',
			],
			'discovery_contract'=>$this->mcp_lightweight_discovery_contract($app_builder_task ? 'app_builder' : 'general'),
			'context_links'=>$this->mcp_lightweight_discovery_context_links(),
			'next_steps'=>$next_steps,
		];
	}

	/**
	 * Classifies resource/prompt discovery matches for compact app-agent routing.
	 *
	 * @param array<string,mixed> $candidate Resource, prompt, or documentation candidate.
	 * @return string Audience scope for the match.
	 */
	private function mcp_resource_match_audience_scope(array $candidate): string {
		$id=(string)($candidate['id'] ?? '');
		$kind=(string)($candidate['kind'] ?? '');
		$module=(string)($candidate['module'] ?? '');
		if($id==='dataphyre://agentic-enterprise'){
			return 'governance_escalation_context';
		}
		if($id==='dataphyre://ai-guidelines'){
			return 'application_agents_building_apps';
		}
		if($kind==='documentation' && in_array($module, ['panel', 'sql', 'api', 'routing'], true)){
			return 'focused_app_or_module_docs';
		}
		if($kind==='prompt'){
			return str_contains($id, 'release') || str_contains($id, 'runtime_guidelines')
				? 'governance_or_release_prompt'
				: 'application_agents_building_apps';
		}
		return 'read_only_discovery';
	}

	/**
	 * Tells app agents when to open a matched resource or prompt.
	 *
	 * @param array<string,mixed> $candidate Resource, prompt, or documentation candidate.
	 * @return array<string,mixed> Compact progressive-disclosure policy.
	 */
	private function mcp_resource_match_open_policy(array $candidate): array {
		$scope=(string)($candidate['audience_scope'] ?? $this->mcp_resource_match_audience_scope($candidate));
		$kind=(string)($candidate['kind'] ?? '');
		$id=(string)($candidate['id'] ?? '');
		$path=(string)($candidate['path'] ?? '');
		$open_with=match($kind){
			'documentation'=>$path!=='' ? 'dataphyre_read_doc path='.$path : 'dataphyre_read_doc',
			'prompt'=>'prompts/get name='.$id,
			default=>str_starts_with($id, 'dataphyre://') ? 'resources/read uri='.$id : 'resources/read',
		};
		return match($scope){
			'focused_app_or_module_docs'=>[
				'open_with'=>$open_with,
				'open_when'=>'after dataphyre_app_builder_plan_generate payload_profile=compact names this module or the next detail page needs module syntax',
				'default_action'=>'keep as focused app/module docs; do not open governance or release context first',
				'not_required'=>['dataphyre_mcp_verify_all', 'release checks', 'Dataphyre hot-path benchmark evidence'],
			],
			'application_agents_building_apps'=>[
				'open_with'=>$open_with,
				'open_when'=>'when baseline app-building guidance or prompt text is needed after the compact app-builder first read',
				'default_action'=>'safe read-only application-agent context',
				'not_required'=>['publication validation', 'Dataphyre runtime-internal edits'],
			],
			'governance_escalation_context'=>[
				'open_with'=>$open_with,
				'open_when'=>'only for corporate-ready, release-facing, security/governance-sensitive, Dataphyre-internal, or shared hot-path work',
				'default_action'=>'leave collapsed for ordinary app scaffolds',
				'not_required'=>['ordinary CRUD scaffolds', 'routine app-owned Panel/API/SQL work'],
			],
			'governance_or_release_prompt'=>[
				'open_with'=>$open_with,
				'open_when'=>'only for explicit Dataphyre runtime, governance, release, or framework-maintainer work',
				'default_action'=>'leave collapsed for ordinary app scaffolds',
				'not_required'=>['ordinary app builder cold starts', 'focused app/module verification'],
			],
			default=>[
				'open_with'=>$open_with,
				'open_when'=>'when this match is selected as the next read-only context item',
				'default_action'=>'read-only discovery context',
				'not_required'=>['unsafe mode', 'publication validation'],
			],
		};
	}

}
