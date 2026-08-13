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
use Dataphyre\Test\TempWorkspace;

require_once __DIR__.'/McpTestKit.php';

/** Named adversarial planning stories with all raw fixture assembly centralized. */
final class McpPlanningClosedWorldHarness {
	private McpKernelHarness $kernel;

	public function __construct(private TestContext $context) {$this->kernel=new McpKernelHarness($context);}

	/** @return list<string> */
	public static function contractNames(): array {
		return [
			'documentation search chunk scheduling and readiness normalize every bounded alternate shape',
			'module declarations and dependency sources reject malformed plugin and filesystem rows',
			'app sensitivity policies explain continuation duplicate status and malformed metadata lanes',
			'write readiness reduces malformed queues blockers and executable batches to one handoff',
			'API scaffold agent context and Panel media planning keep every fallback static and explicit',
		];
	}

	/** @return array<string,mixed> */
	public function contract(string $name): array {
		if(!in_array($name,self::contractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP planning closed-world contract: '.$name);
		}
		$evidence=match($name){
			'documentation search chunk scheduling and readiness normalize every bounded alternate shape'=>$this->docsContract(),
			'module declarations and dependency sources reject malformed plugin and filesystem rows'=>$this->modulesContract(),
			'app sensitivity policies explain continuation duplicate status and malformed metadata lanes'=>$this->sensitivityContract(),
			'write readiness reduces malformed queues blockers and executable batches to one handoff'=>$this->writeReadinessContract(),
			'API scaffold agent context and Panel media planning keep every fallback static and explicit'=>$this->planningSurfaceContract(),
		};
		return ['contract'=>$name]+$evidence;
	}

	/** @return array<string,mixed> */
	private function docsContract(): array {
		$workspace=$this->docsWorkspace();
		$kernel=(new McpKernelHarness($this->context))->useRepositoryRoot($workspace->root());
		$chunk=['path'=>'docs/a.md','heading'=>'Install','index'=>0,'text'=>'Install the framework.'];
		$moduleSets=$kernel->invoke('docs_chunk_module_sets',[
			['module'=>'','chunks'=>[$chunk]],
			['module'=>'demo','chunks'=>['invalid',$chunk,$chunk+['heading'=>'API','index'=>1]]],
		],['missing','demo'],'builder');
		$selection=$kernel->invoke('docs_chunk_builder_selection',[
			['chunks'=>[]],
			['chunks'=>[$chunk,$chunk+['heading'=>'Schema','index'=>2]]],
		],4);
		$roundRobin=$kernel->invoke('docs_chunk_round_robin',[
			['chunks'=>'invalid'],
			['chunks'=>[$chunk]],
		],4);
		$exports=[
			'builder'=>$kernel->invoke('export_docs_chunks',['modules'=>['demo','','demo'],'docs_profile'=>'builder','guidelines_position'=>'first','max_chunks'=>20]),
			'default'=>$kernel->invoke('export_docs_chunks',['modules'=>['demo'],'docs_profile'=>'default','guidelines_position'=>'after_modules','max_chunks'=>20]),
			'governance'=>$kernel->invoke('export_docs_chunks',['modules'=>['demo'],'docs_profile'=>'governance','guidelines_position'=>'first','max_chunks'=>20]),
		];
		return [
			'empty_search'=>$this->exceptionMessage(fn()=> $kernel->invoke('search_docs','',12)),
			'search'=>$kernel->invoke('search_docs','closed-world needle',1),
			'module_sets'=>$moduleSets,
			'selection'=>$selection,
			'round_robin'=>$roundRobin,
			'exports'=>$exports,
			'readiness'=>[
				'remote'=>$kernel->invoke('remote_docs_readiness_plan',['base_url'=>'']),
				'embeddings'=>$kernel->invoke('embeddings_readiness_plan',['provider'=>'','model'=>'']),
				'datadoc'=>$kernel->invoke('datadoc_runtime_readiness_plan',['project'=>'']),
			],
			'missing_definition'=>$kernel->invoke('datadoc_table_columns','no definitions here','missing'),
		];
	}

	/** @return array<string,mixed> */
	private function modulesContract(): array {
		$workspace=$this->modulesWorkspace();
		$kernel=(new McpKernelHarness($this->context))->useRepositoryRoot($workspace->root());
		$declarations=$kernel->invoke('mcp_module_declarations');
		return [
			'declarations'=>$declarations,
			'invalid_module'=>$this->exceptionMessage(fn()=> $kernel->invoke('describe_module','bad/name',10)),
			'description'=>$kernel->invoke('describe_module','demo',10),
			'dependency_map'=>$kernel->invoke('module_dependency_map','demo',10),
			'sources'=>[
				'existing'=>$kernel->invoke('module_dependency_source','dataphyre/runtime/modules/demo/kernel/demo.php'),
				'missing'=>$kernel->invoke('module_dependency_source','dataphyre/runtime/modules/demo/kernel/missing.php'),
			],
			'bounded_sources'=>$kernel->invoke('module_dependency_sources',[
				'dataphyre/runtime/modules/demo/kernel/missing.php',
				'dataphyre/runtime/modules/demo/kernel/demo.php',
			]),
		];
	}

	/** @return array<string,mixed> */
	private function sensitivityContract(): array {
		$sensitivitySchemas=$this->kernel->invoke('app_builder_sensitivity_schemas',[
			'invalid',
			['entity'=>'Order','table'=>'orders','fields'=>[]],
		],[
			'continuation_calls'=>[
				'invalid',
				['arguments'=>['entities'=>['','Order','Credential'],'fields'=>['Credential'=>['api_token'=>['type'=>'string']]]]],
			],
		]);
		$summary=$this->kernel->invoke('app_builder_data_sensitivity_summary',[
			'invalid',
			['entity'=>'Credential','table'=>'credentials','fields'=>[
				'invalid',
				['name'=>'api_token'],
				['name'=>'api_token'],
				['name'=>'health_status'],
			]],
		]);
		$register=$this->kernel->invoke('app_builder_policy_decision_register',[
			'decision_prompts'=>['invalid',['id'=>''],['id'=>'ownership','status'=>'needs_app_decision','prompt'=>'Choose an owner.']],
		],['has_sensitive_signals'=>false]);
		$metadata=$this->kernel->invoke('app_builder_sensitive_policy_metadata',
			['credentials','custom'],
			['invalid','credentials'=>['sensitivity_level'=>'critical','default_exposure'=>'deny'],'custom'=>['sensitivity_level'=>'future','storage_policy'=>'custom']],
			true
		);
		return [
			'sensitivity_schemas'=>$sensitivitySchemas,
			'summary'=>$summary,
			'register'=>$register,
			'contextual_status'=>[
				'other_category'=>$this->kernel->invoke('app_builder_sensitivity_field_match_is_contextual_status','credentials','health','Service','health_status'),
				'other_field'=>$this->kernel->invoke('app_builder_sensitivity_field_match_is_contextual_status','regulated_personal_data','health','Service','health_score'),
				'service_health'=>$this->kernel->invoke('app_builder_sensitivity_field_match_is_contextual_status','regulated_personal_data','health','Project','service_health_status'),
			],
			'policies'=>[
				'residency'=>$this->kernel->invoke('app_builder_sensitive_category_policy','data_residency_or_export'),
				'fallback'=>$this->kernel->invoke('app_builder_sensitive_category_policy','future_category'),
			],
			'metadata'=>$metadata,
			'field_policy'=>$this->kernel->invoke('app_builder_sensitive_field_policy',['invalid',['name'=>'api_token']]),
		];
	}

	/** @return array<string,mixed> */
	private function writeReadinessContract(): array {
		$queue=$this->kernel->invoke('app_builder_continuation_queue_summary',[
			'continuation_calls'=>[
				'invalid',
				['arguments'=>['entities'=>[]]],
				['tool'=>'dataphyre_app_builder_plan_generate','arguments'=>[
					'entities'=>['Order'],
					'fields'=>['Order'=>['user_id'=>['type'=>'integer','foreign_key_target'=>'users']]],
					'dependency_context'=>['dependencies'=>['invalid',['entity'=>'Order','field'=>'user_id','scope'=>'prior_chunk']]],
				]],
			],
		]);
		$context=$this->kernel->invoke('app_builder_continuation_queue_context',['Order'],[
			'fields'=>['Order'=>['user_id'=>['type'=>'integer','foreign_key_target'=>'users']]],
			'dependency_context'=>['dependencies'=>['invalid',['entity'=>'Order','field'=>'user_id','scope'=>'prior_chunk']]],
		]);
		$resolution=$this->kernel->invoke('app_builder_prewrite_resolution_plan',[
			'invalid',['id'=>''],['id'=>'replace_placeholders','status'=>'blocked','action'=>'Resolve paths.'],
		],[['id'=>'field_metadata','status'=>'required']],['invalid']);
		$handoff=$this->kernel->invoke('app_builder_write_handoff',[
			'write_readiness'=>['ready_for_app_owned_writes'=>true],
			'write_plan_summary'=>['write_order'=>[
				'invalid',
				['concern'=>'empty','paths'=>[],'tools'=>[]],
				['concern'=>'implementation','paths'=>['app/Order.php',''],'tools'=>['php_lint','']],
			]],
			'code_skeleton_summary'=>['count'=>1],
			'data_sensitivity_summary'=>['policy_metadata'=>['highest_sensitivity'=>'high']],
		]);
		$pathStates=[];
		foreach([
			'invalid namespace'=>['placeholder_mode'=>false,'path_input_valid'=>true,'namespace_input_valid'=>false,'path_exists'=>false],
			'existing path'=>['placeholder_mode'=>false,'path_input_valid'=>true,'namespace_input_valid'=>true,'path_exists'=>true],
		] as $label=>$pathContext){
			$checklist=$this->kernel->invoke('app_builder_prewrite_checklist',
				['provided'=>true],[],[],[],[],[],$pathContext,[],[],[]
			);
			$pathStates[$label]=array_column($checklist['checks'],'status','id')['replace_placeholders'] ?? null;
		}
		return [
			'queue'=>$queue,
			'context'=>$context,
			'resolution'=>$resolution,
			'handoff'=>$handoff,
			'path_states'=>$pathStates,
		];
	}

	/** @return array<string,mixed> */
	private function planningSurfaceContract(): array {
		$workspace=$this->panelPlanningWorkspace();
		$fixtureKernel=(new McpKernelHarness($this->context))->useRepositoryRoot($workspace->root());
		$api=$this->kernel->invoke('api_scaffold_plan',['name'=>'Order endpoint','path'=>'api/orders','methods'=>['!!!','']]);
		return [
			'api'=>$api,
			'api_missing_name'=>$this->exceptionMessage(fn()=> $this->kernel->invoke('api_scaffold_plan',[])),
			'recipes'=>[
				'selected'=>$this->kernel->invoke('api_recipe_catalog',['recipe'=>'controller_backed']),
				'invalid'=>$this->exceptionMessage(fn()=> $this->kernel->invoke('api_recipe_catalog',['recipe'=>'future'])),
			],
			'openapi'=>$this->kernel->invoke('open_api_runtime_readiness_plan',['application_id'=>'']),
			'scaffold_missing_name'=>$this->exceptionMessage(fn()=> $this->kernel->invoke('generate_scaffold_plan',['type'=>'panel_resource'])),
			'agent_context'=>$this->kernel->invoke('generate_agent_context',['modules'=>['','mcp','mcp']]),
			'panel_media'=>$fixtureKernel->invoke('panel_media_manifest_summary'),
		];
	}

	private function docsWorkspace(): TempWorkspace {
		$workspace=$this->context->workspace('mcp-planning-docs');
		$workspace->file('dataphyre/runtime/modules/demo/documentation/Dataphyre_Demo.md',"# Demo\n\nClosed-world needle.\n\n## API\n\nAPI and schema guidance.\n");
		$workspace->file('dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md',"# Guidelines\n\nSafety and planning.\n");
		$workspace->file('dataphyre/docs/MODULES.md',"# Modules\n\nDemo module reference.\n");
		$workspace->file('dataphyre/runtime/README.md',"# Runtime\n\nRuntime reference.\n");
		return $workspace;
	}

	private function modulesWorkspace(): TempWorkspace {
		$workspace=$this->context->workspace('mcp-planning-modules');
		$workspace->file('dataphyre/plugins/mcp/invalid.json','{malformed');
		$workspace->file('dataphyre/plugins/mcp/declarations.json',json_encode([
			'declarations'=>[
				'invalid',
				['name'=>''],
				['name'=>'bad/name'],
				['name'=>'demo','visibility'=>'public','release'=>'stable','purpose'=>'Fixture module','notes'=>['one',2]],
				['name'=>'minimal','notes'=>'invalid'],
			],
		],JSON_THROW_ON_ERROR));
		$workspace->file('dataphyre/runtime/modules/demo/Framework/Service.php',"<?php\nnamespace Fixture; final class Service { public function run(): void {} }\n");
		$workspace->file('dataphyre/runtime/modules/demo/kernel/demo.php',"<?php\ndp_module_required('core');\nload_framework_module('sql');\nsql_define_table('demo.items');\nfunction fixture_demo(): void {}\n");
		$workspace->file('dataphyre/runtime/modules/demo/documentation/Dataphyre_Demo.md','# Demo');
		return $workspace;
	}

	private function panelPlanningWorkspace(): TempWorkspace {
		$workspace=$this->context->workspace('mcp-panel-planning');
		$workspace->file('dataphyre/runtime/modules/panel/Framework/Media/PanelMediaLibrary.php',"<?php\nnamespace Fixture; final class Ordinary { private function hidden(): void {} }\n");
		return $workspace;
	}

	private function exceptionMessage(callable $operation): string {
		try{$operation();}catch(\Throwable $error){return $error->getMessage();}
		return '';
	}
}
