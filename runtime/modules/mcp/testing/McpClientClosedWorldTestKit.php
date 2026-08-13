<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Mcp\Testing;

use Dataphyre\Test\Context;
use Dataphyre\Test\Contracts\TestContext;

require_once __DIR__.'/McpTestKit.php';

/**
 * Named adversarial stories for MCP client catalogs and recommendation logic.
 *
 * The harness owns malformed catalog rows, transcript construction, registry
 * snapshots, and fallback inputs so tests read as public behavior contracts.
 */
final class McpClientClosedWorldHarness {
	private McpKernelHarness $kernel;

	public function __construct(private TestContext $context) {
		$this->kernel=new McpKernelHarness($context);
	}

	/** @return list<string> */
	public static function contractNames(): array {
		return [
			'workflow catalogs and next actions explain malformed transcript release and app-first states',
			'discovery ranks API application route and fallback module documentation lanes',
			'skill catalogs audit target coupling and every caller-owned install root',
			'documentation examples prompts and release notes validate independent catalogs',
			'client safety audience and setup selectors preserve explicit fallback contracts',
		];
	}

	/** @return array<string,mixed> */
	public function contract(string $name): array {
		if(!in_array($name,self::contractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP client closed-world contract: '.$name);
		}
		$evidence=match($name){
			'workflow catalogs and next actions explain malformed transcript release and app-first states'=>$this->workflowContract(),
			'discovery ranks API application route and fallback module documentation lanes'=>$this->discoveryContract(),
			'skill catalogs audit target coupling and every caller-owned install root'=>$this->skillsContract(),
			'documentation examples prompts and release notes validate independent catalogs'=>$this->publicationContract(),
			'client safety audience and setup selectors preserve explicit fallback contracts'=>$this->clientSafetyContract(),
		};
		return ['contract'=>$name]+$evidence;
	}

	/** @return array<string,mixed> */
	private function workflowContract(): array {
		$catalogGaps=$this->kernel->invoke('mcp_workflow_catalog_gaps',[
			'known'=>['prompt'=>'known.prompt','steps'=>[['tool'=>'known.tool']]],
			'malformed'=>['prompt'=>'missing.prompt','steps'=>['invalid',['tool'=>'missing.tool'],['tool'=>'']]],
		],['known.tool'],['known.prompt']);
		$transcripts=[
			'healthy'=>McpTranscriptBuilder::valid('feature')->successfulTool('dataphyre_module_describe')->finalStatus('passed')->toArray(),
			'blocked'=>McpTranscriptBuilder::valid('feature')->failedTool('dataphyre_module_describe')->finalStatus('failed')->toArray(),
			'empty'=>McpTranscriptBuilder::valid('feature')->toArray(),
			'review'=>McpTranscriptBuilder::valid('feature')->successfulTool('dataphyre_module_describe','Bearer abcdefghijklmnop')->finalStatus('partial')->toArray(),
		];
		$nextActions=[];
		foreach($transcripts as $status=>$transcript){
			$nextActions[$status]=$this->kernel->invoke('mcp_workflow_next_action_export',['workflow'=>'feature','transcript'=>$transcript]);
		}
		$app=$this->kernel->invoke('mcp_workflow_next_action_export',['task'=>'Build an order CRUD application']);
		$release=$this->kernel->invoke('mcp_workflow_next_action_export',['task'=>'Prepare a public corporate-ready release']);
		return [
			'catalog_gaps'=>$catalogGaps,
			'prompt_names'=>[
				'preferred'=>$this->kernel->invoke('mcp_prompt_catalog_names',['available_prompts'=>['','one']]),
				'alternate'=>$this->kernel->invoke('mcp_prompt_catalog_names',['available_prompts'=>[],'prompts'=>['invalid',['name'=>''],['name'=>'one'],['name'=>'one']]]),
			],
			'next_decisions'=>array_map(static fn(array $entry): string=>(string)$entry['decision'],$nextActions),
			'next_tools'=>array_map(static fn(array $entry): string=>(string)$entry['recommended_tool'],$nextActions),
			'app'=>$app,
			'release'=>$release,
			'task_detection'=>[
				'support'=>$this->kernel->invoke('mcp_task_implies_app_builder','Debug application performance'),
				'planning'=>$this->kernel->invoke('mcp_task_implies_app_builder','Plan an application'),
			],
			'recommendation_names'=>[
				'valid'=>$this->kernel->invoke('mcp_workflow_recommendation_name',['recommendations'=>[['workflow'=>'routes']]]),
				'malformed'=>$this->kernel->invoke('mcp_workflow_recommendation_name',['recommendations'=>['invalid']]),
				'unknown'=>$this->kernel->invoke('mcp_workflow_recommendation_name',['recommendations'=>[['workflow'=>'future']]]),
			],
		];
	}

	/** @return array<string,mixed> */
	private function discoveryContract(): array {
		$toolFinder=$this->kernel->invoke('mcp_tool_finder',['query'=>'Build an application API endpoint for orders','limit'=>4]);
		$resourceFinders=[];
		foreach([
			'sql'=>'sql schema table documentation',
			'api'=>'api endpoint documentation',
			'application route'=>'application route controller documentation',
		] as $lane=>$query){
			$resourceFinders[$lane]=$this->kernel->invoke('mcp_resource_finder',['query'=>$query,'limit'=>6]);
		}
		$prioritized=$this->kernel->invoke('mcp_resource_prioritize_module_docs',[
			['kind'=>'documentation','module'=>'panel','path'=>'docs/Panel_Recipes.md'],
			['kind'=>'documentation','module'=>'sql','path'=>'docs/Dataphyre_SQL.md'],
			['kind'=>'resource','module'=>'panel','path'=>'dataphyre://module-index'],
		],['panel','sql']);
		return [
			'tool_finder'=>$toolFinder,
			'resource_modules'=>array_map(static function(array $entry): array {
				$modules=array_values(array_filter(array_map(static fn(array $match): string=>(string)($match['module'] ?? ''),is_array($entry['matches'] ?? null) ? $entry['matches'] : [])));
				return array_values(array_unique($modules));
			},$resourceFinders),
			'prioritized_paths'=>array_column($prioritized,'path'),
			'collapsed_scope'=>$this->kernel->invoke('mcp_tool_match_use_policy','future.tool','application_agents_building_apps_with_collapsed_escalation',[]),
		];
	}

	/** @return array<string,mixed> */
	private function skillsContract(): array {
		$selection=$this->kernel->invoke('mcp_select_skill_definitions',[
			'codex-only'=>['name'=>'codex-only','targets'=>['codex']],
			'portable'=>['name'=>'portable','targets'=>['codex','claude']],
		],['codex-only','portable'],'claude');
		$audit=$this->kernel->invoke('mcp_skill_registration_entry',[
			'name'=>'adversarial-skill',
			'targets'=>['codex'],
			'related_tools'=>['missing.tool'],
			'related_prompts'=>['missing.prompt'],
			'related_resources'=>['missing.resource'],
			'instructions'=>['applications/demo app/private tools/run .local/state localhost:80 127.0.0.1:90'],
		],[],[],[]);
		$roots=[];
		foreach(['codex','claude','cursor','generic'] as $target){
			$plan=$this->kernel->invoke('mcp_skill_file_install_plan',['target'=>$target,'names'=>['dataphyre-app-builder']]);
			$roots[$target]=(string)($plan['skill_root'] ?? '');
		}
		$pack=$this->kernel->invoke('mcp_skill_pack_export',['target'=>'codex','names'=>['dataphyre-app-builder']]);
		return [
			'selected_names'=>array_column($selection,'name'),
			'audit_codes'=>array_column($audit['findings'],'code'),
			'audit_ready'=>$audit['audit']['ready'],
			'roots'=>$roots,
			'app_builder_pack'=>$pack,
		];
	}

	/** @return array<string,mixed> */
	private function publicationContract(): array {
		$gaps=$this->kernel->invoke('mcp_docs_coverage_gaps','documented.tool dataphyre://covered','documented.prompt documented.skill safety-term',
			['documented.tool','missing.tool'],
			['dataphyre://covered','dataphyre://missing','dataphyre://doc/ignored'],
			['documented.prompt','missing.prompt'],
			['documented.skill','missing.skill'],
			['safety-term','missing-safety']
		);
		$exampleGaps=$this->kernel->invoke('mcp_tool_example_missing_registrations',[
			'one'=>[
				['request'=>['params'=>['name'=>'known.tool']]],
				['request'=>['params'=>['name'=>'missing.tool']]],
				['request'=>['params'=>[]]],
			],
		],['known.tool']);
		$releaseNotes=$this->kernel->invoke('mcp_release_notes_generate',['audience'=>'client_authors']);
		return [
			'documentation_gaps'=>$gaps,
			'example_gaps'=>$exampleGaps,
			'app_examples'=>$this->kernel->invoke('mcp_tool_call_examples_export',['workflow'=>'app']),
			'publication_fallback'=>$this->kernel->invoke('mcp_publication_next_action',[]),
			'publication_malformed_fallback'=>$this->kernel->invoke('mcp_publication_next_action',['recommended_next_slices'=>[
				'invalid',
				['audience'=>'ordinary_application_work'],
			]]),
			'release_highlights'=>$this->kernel->invoke('mcp_release_highlights',['invalid',['release_note'=>''],['release_note'=>'A concrete release note.']]),
			'release_notes'=>$releaseNotes,
			'prompts'=>[
				'theme'=>$this->kernel->invoke('prompt_catalog_theme','dataphyre_release_triage'),
				'release_action'=>$this->kernel->invoke('prompt_catalog_first_action','dataphyre_release_triage'),
				'guidelines_action'=>$this->kernel->invoke('prompt_catalog_first_action','dataphyre_runtime_guidelines'),
				'release_tools'=>$this->kernel->invoke('prompt_catalog_tools','dataphyre_release_triage',false),
				'default_release_tools'=>$this->kernel->invoke('prompt_catalog_tools','dataphyre_release_triage',true),
				'guidelines_tools'=>$this->kernel->invoke('prompt_catalog_tools','dataphyre_runtime_guidelines'),
				'guidelines_resources'=>$this->kernel->invoke('prompt_catalog_resources','dataphyre_runtime_guidelines'),
				'release_resources'=>$this->kernel->invoke('prompt_catalog_resources','dataphyre_release_triage'),
			],
		];
	}

	/** @return array<string,mixed> */
	private function clientSafetyContract(): array {
		ob_start();
		$nonCli=\dataphyre_mcp_enforce_cli('fpm-fcgi');
		$nonCliOutput=(string)ob_get_clean();
		$lifecycleFailure=$this->exceptionMessage(static fn()=>\dataphyre_mcp_run_server('/tmp',[],static function(): object {
			throw new \RuntimeException('synthetic server construction failure');
		}));
		return [
			'config'=>$this->kernel->invoke('mcp_client_config_summary',['php_command'=>'']),
			'unknown_setup'=>$this->kernel->invoke('mcp_client_setup_next_action','future_client_surface',['target'=>'codex']),
			'prompt_pack'=>[
				'empty'=>$this->kernel->invoke('mcp_prompt_pack_is_app_first',[]),
				'release'=>$this->kernel->invoke('mcp_prompt_pack_is_app_first',['dataphyre_release_triage']),
			],
			'audience'=>$this->kernel->invoke('mcp_tool_boundary_map',['','future.tool']),
			'safety'=>$this->kernel->invoke('mcp_safety_next_action',['safety'=>['intentionally_not_exposed'=>'malformed']],true),
			'cli_guard'=>['accepted'=>\dataphyre_mcp_enforce_cli('cli'),'rejected'=>$nonCli,'output'=>$nonCliOutput],
			'lifecycle_failure'=>$lifecycleFailure,
		];
	}

	private function exceptionMessage(callable $operation): string {
		try{$operation();}catch(\Throwable $error){return $error->getMessage();}
		return '';
	}
}
