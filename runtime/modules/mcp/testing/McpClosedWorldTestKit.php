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

/**
 * Named evidence for defensive MCP branches that public happy-path matrices
 * cannot express. The kit owns private access and adversarial input assembly;
 * unit tests assert readable domain contracts rather than reflection mechanics.
 */
final class McpClosedWorldBoundaryHarness {
	private McpKernelHarness $kernel;

	public function __construct(private TestContext $context) {
		$this->kernel=new McpKernelHarness($context);
	}

	/** @return list<string> */
	public static function contractNames(): array {
		return [
			'registry validation rejects unknown names and explains near argument typos without guessing distant ones',
			'bounded PHP source selection drives MVC inventories without executing excluded trees',
			'filesystem iterator construction faults collapse to an empty read-only scan',
			'enterprise audits classify every ownership portability and proportional-guidance lane',
			'compact app-builder responses preserve every supported malformed and alternate input shape',
			'schema hints normalize every supported acronym type option default and relationship grammar',
			'compiled route manifests produce bounded URL and match previews at every edge',
			'static route source parsing explains dynamic handlers and fluent metadata at every edge',
			'diagnostic artifacts stay redacted bounded and actionable at every edge',
			'verification surfaces stay classified bounded and executable at every edge',
		];
	}

	/** @return array<string,mixed> */
	public function contract(string $name): array {
		if(!in_array($name,self::contractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP closed-world boundary contract: '.$name);
		}
		$evidence=match($name){
			'registry validation rejects unknown names and explains near argument typos without guessing distant ones'=>$this->registryValidationContract(),
			'bounded PHP source selection drives MVC inventories without executing excluded trees'=>$this->mvcSourceInspectionContract(),
			'filesystem iterator construction faults collapse to an empty read-only scan'=>$this->filesystemFaultContract(),
			'enterprise audits classify every ownership portability and proportional-guidance lane'=>$this->enterpriseAuditContract(),
			'compact app-builder responses preserve every supported malformed and alternate input shape'=>$this->compactResponseContract(),
			'schema hints normalize every supported acronym type option default and relationship grammar'=>$this->schemaHintContract(),
			'compiled route manifests produce bounded URL and match previews at every edge'=>$this->routeManifestInspectionContract(),
			'static route source parsing explains dynamic handlers and fluent metadata at every edge'=>$this->routeSourceInspectionContract(),
			'diagnostic artifacts stay redacted bounded and actionable at every edge'=>$this->diagnosticInspectionContract(),
			'verification surfaces stay classified bounded and executable at every edge'=>$this->verificationInspectionContract(),
		};
		return ['contract'=>$name]+$evidence;
	}

	/** @return array<string,mixed> */
	private function registryValidationContract(): array {
		$doc=$this->kernel->invoke('read_resource',[
			'uri'=>'dataphyre://doc/dataphyre/runtime/modules/mcp/documentation/Dataphyre_MCP.md',
		]);
		return [
			'near_typo'=>$this->exceptionMessage(fn()=> $this->kernel->invoke('validate_tool_arguments','dataphyre_module_describe',['modul'=>'mcp'])),
			'distant_typo'=>$this->exceptionMessage(fn()=> $this->kernel->invoke('validate_tool_arguments','dataphyre_module_describe',['unrelated_distant_argument'=>'mcp'])),
			'unknown_dispatch'=>$this->exceptionMessage(fn()=> $this->kernel->invoke('dispatch_validated_tool','definitely_not_registered',[])),
			'doc_resource'=>[
				'mime_type'=>$doc['contents'][0]['mimeType'] ?? null,
				'contains_heading'=>str_contains((string)($doc['contents'][0]['text'] ?? ''),'Dataphyre MCP'),
			],
		];
	}

	/** @return array<string,mixed> */
	private function mvcSourceInspectionContract(): array {
		$workspace=$this->inspectionWorkspace();
		$kernel=(new McpKernelHarness($this->context))->useRepositoryRoot($workspace->root());
		$sourceRoot='applications/demo/backend/dataphyre/src';
		$selected=$kernel->invoke('bounded_php_source_files',[
			$sourceRoot.'/documentation/Ignored.php',
			$sourceRoot.'/vendor/Ignored.php',
			$sourceRoot.'/notes.txt',
			$sourceRoot.'/missing.php',
			$sourceRoot.'/Controller/OrderController.php',
			$sourceRoot.'/Middleware/AuditMiddleware.php',
		],1);
		return [
			'selected'=>array_map(fn(string $path): string=>$kernel->invoke('relative_path',$path),$selected),
			'controllers'=>$kernel->invoke('controller_source_summary',['paths'=>[$sourceRoot],'limit'=>20]),
			'middleware'=>$kernel->invoke('middleware_source_summary',['paths'=>[$sourceRoot],'limit'=>20]),
			'mvc_config'=>$kernel->invoke('mvc_config_static_summary'),
			'route_cache'=>$this->kernel->invoke('mvc_route_cache_summary'),
		];
	}

	/** @return array<string,mixed> */
	private function filesystemFaultContract(): array {
		return [
			'files'=>$this->kernel->invoke('guarded_filesystem_iterator',static fn()=>throw new \RuntimeException('synthetic iterator construction failure')),
		];
	}

	/** @return array<string,mixed> */
	private function enterpriseAuditContract(): array {
		$counts=static fn(array $changes=[]): array => array_replace([
			'app_files'=>0,'runtime_files'=>0,'module_files'=>0,'mcp_files'=>0,'dev_files'=>0,'doc_files'=>0,
			'test_files'=>0,'config_files'=>0,'plugin_files'=>0,'hot_path_files'=>0,'portability_signal_count'=>0,
		],$changes);
		$known=['known'=>true,'documentation_files'=>2,'framework_files'=>2,'kernel_files'=>1,'unit_test_files'=>2];
		$unknown=['known'=>false,'documentation_files'=>0,'framework_files'=>0,'kernel_files'=>0,'unit_test_files'=>0];
		$classifications=[];
		foreach([
			'hot path'=>[$counts(['runtime_files'=>1,'hot_path_files'=>1]),$known,false],
			'application extension'=>[$counts(['app_files'=>1]),$unknown,false],
			'MCP control plane'=>[$counts(['mcp_files'=>1]),$known,false],
			'docs control plane'=>[$counts(['doc_files'=>1]),$unknown,false],
			'reusable contract'=>[$counts(['runtime_files'=>1,'module_files'=>1]),$known,false],
			'install extension'=>[$counts(['config_files'=>1]),$unknown,false],
			'release review'=>[$counts(),$unknown,true],
			'needs context'=>[$counts(),$unknown,false],
		] as $label=>$arguments){
			$classifications[$label]=$this->kernel->invoke('mcp_enterprise_change_classification',...$arguments);
		}
		$strategies=[];
		foreach([
			'runtime review'=>[$counts(['runtime_files'=>1]),$unknown],
			'config'=>[$counts(['config_files'=>1]),$unknown],
			'plugin'=>[$counts(['plugin_files'=>1]),$unknown],
			'documented module'=>[$counts(),$known],
			'reusable module'=>[$counts(['module_files'=>1]),$unknown],
		] as $label=>$arguments){
			$strategies[$label]=$this->kernel->invoke('mcp_enterprise_extension_strategy',...$arguments);
		}
		$readyEvidence=$this->kernel->invoke('mcp_enterprise_evidence_next_action',[],['gates'=>[]],['checks'=>[]],['benchmark_required'=>true],['focused']);
		$focusedEvidence=$this->kernel->invoke('mcp_enterprise_evidence_next_action',[
			['id'=>'proof','status'=>'needs_evidence','required_evidence'=>['test'],'suggested_tools'=>['focused_check']],
		],[],[],['benchmark_required'=>false],[]);
		$readyAudit=$this->kernel->invoke('mcp_enterprise_adoption_audit',[
			'feature'=>'Publish a corporate-ready MCP contract',
			'module'=>'mcp',
			'public_claim'=>true,
			'files'=>[
				'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php',
				'runtime/modules/core/kernel/core.php',
				'runtime/modules/mcp/documentation/Dataphyre_MCP.md',
				'runtime/modules/mcp/unit_tests/dataphyre.mcp.protocol_contract.test.php',
				'dev/tools/public/mcp_live_validate.php',
			],
		]);
		$internalReadyAudit=$this->kernel->invoke('mcp_enterprise_adoption_audit',[
			'feature'=>'Inspect the existing MCP contract',
			'module'=>'mcp',
			'public_claim'=>false,
			'files'=>[
				'runtime/modules/mcp/documentation/Dataphyre_MCP.md',
				'runtime/modules/mcp/unit_tests/dataphyre.mcp.protocol_contract.test.php',
				'config/mcp.php',
			],
		]);
		return [
			'classification_lanes'=>array_map(static fn(array $entry): string=>(string)$entry['primary'],$classifications),
			'benchmark_required'=>$classifications['hot path']['benchmark_required'] ?? null,
			'strategy_lanes'=>array_map(static fn(array $entry): string=>(string)$entry['recommended_next_layer'],$strategies),
			'hot_paths'=>$this->kernel->invoke('mcp_enterprise_hot_path_files',['runtime/bootstrap.php','runtime/modules/sql/kernel/query.php','app/Order.php']),
			'normalized_paths'=>array_map(fn(string $path): string=>$this->kernel->invoke('mcp_enterprise_normalize_audit_path',$path),[
				' ././dataphyre//runtime/modules/mcp/kernel/file.php ',
				'dataphyre/runtime/modules/sql/kernel/query.php',
			]),
			'module_evidence'=>[
				'empty'=>$this->kernel->invoke('mcp_enterprise_module_evidence',''),
				'missing'=>$this->kernel->invoke('mcp_enterprise_module_evidence','definitely_missing_module'),
				'known'=>$this->kernel->invoke('mcp_enterprise_module_evidence','mcp'),
			],
			'portability_signals'=>$this->kernel->invoke('mcp_enterprise_path_portability_signals',[
				'C:/private/file.php','/srv/app/file.php','~/.local/file.php','localhost:8080/path','https://example.test/?token=secret',
			]),
			'proportional_guidance'=>[
				'release'=>$this->kernel->invoke('mcp_task_proportional_guidance','Prepare a corporate-ready public release'),
				'security'=>$this->kernel->invoke('mcp_task_proportional_guidance','Add OAuth access policy'),
				'framework'=>$this->kernel->invoke('mcp_task_proportional_guidance','Tune Dataphyre shared production hot path'),
				'application performance'=>$this->kernel->invoke('mcp_task_proportional_guidance','Improve dashboard performance benchmark'),
				'ordinary'=>$this->kernel->invoke('mcp_task_proportional_guidance','Add an order note field'),
			],
			'ready_evidence'=>$readyEvidence,
			'focused_evidence'=>$focusedEvidence,
			'ready_audit'=>$readyAudit,
			'internal_ready_audit'=>$internalReadyAudit,
		];
	}

	/** @return array<string,mixed> */
	private function compactResponseContract(): array {
		$skeletonPlan=[
			'code_skeleton_summary'=>['count'=>2],
			'code_skeletons'=>[['path'=>'app/Order.php']],
			'data_model'=>[
				'malformed-row',
				['entity'=>'Order','code_skeletons'=>[['path'=>'Schema/Order.php'],'malformed']],
				['entity'=>'Note'],
			],
		];
		$writePlan=[
			'write_readiness'=>[
				'status'=>'ready_for_app_owned_writes','ready_for_app_owned_writes'=>true,
				'first_blocker'=>['id'=>'replace_placeholders'],
			],
			'write_handoff'=>['first_batch'=>[
				'concern'=>'resources','source'=>'implementation_recipe','paths'=>['one.php','two.php','three.php','four.php','five.php'],
				'tools'=>['lint','panel','sql','browser','extra'],
				'probe'=>[
					'malformed',
					['id'=>'style','inspect_globs'=>['a','b','c','d'],'signals'=>['one','two'],'capture_fields'=>['namespace'],'apply_to'=>['resource']],
				],
			]],
		];
		$resume=[];
		foreach(['continue_entity_chunks','resolve_prewrite_blockers','ready_for_app_owned_writes','inspect_builder_plan'] as $status){
			$resume[$status]=$this->kernel->invoke('app_builder_compact_resume_cursor',$writePlan,$status);
		}
		$entityContracts=[
			'entities'=>$this->kernel->invoke('app_builder_entity_input_contract',['entities'=>['Order']],['task'=>'Build orders','entities'=>[' order ','']]),
			'name'=>$this->kernel->invoke('app_builder_entity_input_contract',['entities'=>['Order']],['task'=>'Build orders','name'=>'Order']),
			'fields'=>$this->kernel->invoke('app_builder_entity_input_contract',['entities'=>['Order']],['task'=>'Build orders and invoices','fields'=>['Order'=>['name'=>'string']]]),
			'phrases'=>$this->kernel->invoke('app_builder_entity_input_contract',['entities'=>['Workspace','Provider']],['task'=>'Build provider credentialing with workspaces and licenses']),
		];
		$softCoverage=[];
		foreach([
			'always'=>[['Order'],'Dashboard'],
			'catalog'=>[['NotificationMessage'],'NotificationTemplate'],
			'child prefix'=>[['Order'],'OrderLine'],
			'parent prefix'=>[['OrderLine'],'Order'],
			'suffix'=>[['AccessPolicy'],'Unrelated'],
			'none'=>[['Order','Invoice'],'Provider'],
		] as $label=>$arguments){
			$softCoverage[$label]=$this->kernel->invoke('app_builder_unmodeled_task_entity_is_soft_covered',...$arguments);
		}
		return [
			'compacted_skeletons'=>$this->kernel->invoke('app_builder_compact_builder_plan_payload',$skeletonPlan),
			'data_model'=>[
				'empty'=>$this->kernel->invoke('app_builder_compact_data_model_handoff','invalid'),
				'mixed'=>$this->kernel->invoke('app_builder_compact_data_model_handoff',[
					'invalid',['entity'=>'Order','table'=>'orders','artifact_paths'=>['a'],'columns'=>['id'=>[]],'casts'=>['id'=>'int'],'relationships'=>['notes'=>[]],'schema_field_metadata'=>['id'=>[]]],
				]),
			],
			'optional_summaries'=>[
				'empty'=>$this->kernel->invoke('app_builder_compact_optional_summary',null,'triggered','summary'),
				'triggered'=>$this->kernel->invoke('app_builder_compact_optional_summary',['triggered'=>true],'triggered','summary'),
				'compact'=>$this->kernel->invoke('app_builder_compact_optional_summary',['owner'=>'app','items'=>[1,2]],'triggered','summary'),
			],
			'write_packet'=>$this->kernel->invoke('app_builder_compact_write_start_packet',$writePlan,'ready_for_app_owned_writes'),
			'resume'=>$resume,
			'envelopes'=>[
				'merged'=>$this->kernel->invoke('mcp_app_builder_apply_compact_envelope',['remove'=>true,'context_policy'=>['existing'=>true]],['title'=>'compact'],['remove'],['added'=>true]),
				'replaced'=>$this->kernel->invoke('mcp_app_builder_apply_compact_envelope',['context_policy'=>'invalid'],[],[],['added'=>true]),
				'untouched'=>$this->kernel->invoke('mcp_app_builder_apply_compact_envelope',['keep'=>true],[],[],[]),
			],
			'entity_contracts'=>$entityContracts,
			'soft_coverage'=>$softCoverage,
			'field_contracts'=>[
				'empty'=>$this->kernel->invoke('app_builder_field_input_contract',['entities'=>['Order']],[]),
				'flat'=>$this->kernel->invoke('app_builder_field_input_contract',['entities'=>['Order']],['fields'=>['name'=>'string']]),
				'nested'=>$this->kernel->invoke('app_builder_field_input_contract',['entities'=>['Order','Invoice']],['fields'=>['Order'=>['name'=>'string'],'Invoice'=>['number'=>'string']]]),
			],
		];
	}

	/** @return array<string,mixed> */
	private function schemaHintContract(): array {
		$types=[];
		foreach([
			'foreign'=>'foreign key to users','json'=>'jsonb payload','enum'=>'enum draft,active','datetime'=>'timestamp',
			'date'=>'date','boolean'=>'boolean','integer'=>'integer','decimal'=>'decimal','text'=>'long text','string'=>'varchar',
		] as $name=>$definition){
			$types[$name]=$this->kernel->invoke('field_hint_type',$definition);
		}
		return [
			'acronyms'=>array_map(fn(string $token): ?string=>$this->kernel->invoke('enterprise_acronym_token',$token),['api','dpa','id','jwt','kyc','mfa','oauth','saml','scim','scc','sla','sso','totp','uri','url','uuid','ordinary']),
			'hints'=>$this->kernel->invoke('field_hints',[
				['type'=>'string'],
				'external_id'=>['type'=>'string','not_foreign_key'=>true,'unique'=>true,'unique_with'=>'tenant_id, workspace-id, !!!'],
				'status'=>['type'=>'enum draft|active default draft','choices'=>['draft'=>'Draft','active'=>'Active','bad'=>[]]],
				'owner_id'=>['type'=>'integer','references'=>'users','required'=>true],
			]),
			'types'=>$types,
			'foreign_key_denials'=>[
				'flag'=>$this->kernel->invoke('field_hint_denies_foreign_key',['not_foreign_key'=>true]),
				'false'=>$this->kernel->invoke('field_hint_denies_foreign_key',['relationship'=>false]),
				'text'=>$this->kernel->invoke('field_hint_denies_foreign_key','external id not a foreign key'),
			],
			'options'=>[
				'array'=>$this->kernel->invoke('field_hint_options',['options'=>['draft','active',[],'bad'=>[]]]),
				'string'=>$this->kernel->invoke('field_hint_options',['enum'=>'draft|active']),
				'type'=>$this->kernel->invoke('field_hint_options',['type'=>'choice one two']),
				'empty'=>$this->kernel->invoke('field_hint_options_from_text',''),
				'none'=>$this->kernel->invoke('field_hint_options_from_text','string'),
				'commas'=>$this->kernel->invoke('field_hint_options_from_text','enum: one,two|three default one'),
				'spaces'=>$this->kernel->invoke('field_hint_options_from_text','choices one two three required'),
			],
			'defaults'=>[
				'bool'=>$this->kernel->invoke('field_hint_default',['default'=>false]),
				'number'=>$this->kernel->invoke('field_hint_default',['default_value'=>12.5]),
				'string'=>$this->kernel->invoke('field_hint_default',['default'=>' draft ']),
				'unsupported'=>$this->kernel->invoke('field_hint_default',['default'=>new \stdClass()]),
			],
			'unique_with'=>$this->kernel->invoke('field_hint_unique_with',['unique_scope'=>'tenant_id, workspace-id, !!!']),
			'targets'=>[
				'empty definition'=>$this->kernel->invoke('field_hint_foreign_key_target',['type'=>'']),
				'empty scalar'=>$this->kernel->invoke('field_hint_foreign_key_target','   '),
				'none'=>$this->kernel->invoke('field_hint_foreign_key_target','string'),
				'users'=>$this->kernel->invoke('field_hint_foreign_key_target','foreign key to users required'),
			],
			'singular'=>array_map(fn(string $target): string=>$this->kernel->invoke('singular_relation_target',$target),['','policies','addresses','cases','statuses','users','business']),
		];
	}

	/** @return array<string,mixed> */
	private function routeManifestInspectionContract(): array {
		$artifactKernel=(new McpKernelHarness($this->context))->useRepositoryRoot($this->inspectionWorkspace()->root());
		$manifest='dataphyre/runtime/modules/mcp/testing/fixtures/closed-world-route-manifest.php';
		$transcript=(new McpScenario($this->context))->exchangeSharded([
			McpScenario::request('manifest','tools/call',['name'=>'dataphyre_route_manifest_read','arguments'=>['manifest_path'=>$manifest,'limit'=>5,'include_handlers'=>true,'include_middleware'=>true]]),
			McpScenario::request('url','tools/call',['name'=>'dataphyre_route_url_preview','arguments'=>['manifest_path'=>$manifest,'name'=>'orders.show','parameters'=>['id'=>42],'query'=>['tab'=>'history'],'base_url'=>'https://app.example.test/']]),
			McpScenario::request('none','tools/call',['name'=>'dataphyre_route_match_preview','arguments'=>['manifest_path'=>$manifest,'path'=>'/missing']]),
			McpScenario::request('order','tools/call',['name'=>'dataphyre_route_match_preview','arguments'=>['manifest_path'=>$manifest,'method'=>'GET','path'=>'/orders/42','include_handler'=>true,'include_middleware'=>true]]),
		]);
		$missingName=$this->exceptionMessage(fn()=> $this->kernel->invoke('preview_route_url',['manifest_path'=>$manifest]));
		$missingPath=$this->exceptionMessage(fn()=> $this->kernel->invoke('preview_route_match',['manifest_path'=>$manifest]));
		$invalidBase=$this->exceptionMessage(fn()=> $this->kernel->invoke('normalize_http_base_url','localhost/path'));
		return [
			'artifacts'=>$artifactKernel->invoke('list_route_artifacts',1),
			'manifest'=>$transcript->toolPayload('manifest'),
			'url'=>$transcript->toolPayload('url'),
			'url_errors'=>['missing name'=>$missingName,'invalid base'=>$invalidBase],
			'absolute_urls'=>[
				'absolute'=>$this->kernel->invoke('absolute_url_preview','https://app.example.test','https://cdn.example.test/a'),
				'protocol relative'=>$this->kernel->invoke('absolute_url_preview','http://app.example.test','//cdn.example.test/a'),
				'relative'=>$this->kernel->invoke('absolute_url_preview','https://app.example.test/','/orders'),
			],
			'matches'=>[
				'none'=>$transcript->toolPayload('none'),
				'order'=>$transcript->toolPayload('order'),
				'error'=>$missingPath,
			],
		];
	}

	/** @return array<string,mixed> */
	private function routeSourceInspectionContract(): array {
		$workspace=$this->inspectionWorkspace();
		$kernel=(new McpKernelHarness($this->context))->useRepositoryRoot($workspace->root());
		$routeDirectory='applications/demo/backend/dataphyre/routes';
		return [
			'source_summary'=>$kernel->invoke('route_source_static_summary',['paths'=>[$routeDirectory],'limit'=>1]),
			'ambiguity'=>$kernel->invoke('route_source_ambiguity_report',['paths'=>[$routeDirectory],'limit'=>1]),
			'route_plan'=>$kernel->invoke('route_runtime_provenance_plan',['application_id'=>'']),
			'handler_shapes'=>[
				'empty'=>$kernel->invoke('is_static_route_handler_expression',''),
				'string'=>$kernel->invoke('is_static_route_handler_expression',"'Controller@index'"),
				'closure'=>$kernel->invoke('is_static_route_handler_expression','static fn()=>null'),
				'controller action'=>$kernel->invoke('is_static_route_handler_expression','ControllerAction::make(OrderController::class, \'index\')'),
				'dynamic'=>$kernel->invoke('is_static_route_handler_expression','resolve_handler()'),
			],
			'chain_edges'=>[
				'ambiguity missing method'=>$kernel->invoke('route_chain_ambiguities_after_token',[[T_STRING,'get',1],[T_OBJECT_OPERATOR,'->',1]],0,'route.php','instance'),
				'metadata missing method'=>$kernel->invoke('route_chain_metadata_after_token',[[T_STRING,'get',1],[T_OBJECT_OPERATOR,'->',1]],0),
				'metadata missing arguments'=>$kernel->invoke('route_chain_metadata_after_token',[[T_STRING,'get',1],[T_OBJECT_OPERATOR,'->',1],[T_STRING,'name',1],';'],0),
			],
		];
	}

	/** @return array<string,mixed> */
	private function diagnosticInspectionContract(): array {
		$workspace=$this->inspectionWorkspace();
		$kernel=(new McpKernelHarness($this->context))->useRepositoryRoot($workspace->root());
		$diagnosticRead=$kernel->invoke('read_tracelog_artifact',['path'=>'dataphyre/logs/tracelog-main.log','max_bytes'=>5000,'strip_html'=>true]);
		$diagnosticSearch=$kernel->invoke('search_tracelog_artifacts',['query'=>'failure','scope'=>'dataphyre/logs','limit'=>1,'max_bytes_per_file'=>1000,'strip_html'=>true]);
		$diagnosticSearchAll=$kernel->invoke('search_tracelog_artifacts',['query'=>'failure','scope'=>'dataphyre/logs','limit'=>2,'max_bytes_per_file'=>1000,'strip_html'=>true]);
		$lastError=$kernel->invoke('diagnostics_last_error',['scope'=>'dataphyre/logs','limit'=>1,'max_bytes_per_file'=>1000]);
		return [
			'diagnostic_next_actions'=>[
				'match'=>$kernel->invoke('diagnostic_next_action','tracelog_search',['evidence'=>['match_count'=>2]]),
				'artifact'=>$kernel->invoke('diagnostic_next_action','tracelog_read',['evidence'=>['path'=>'trace.log']]),
				'empty'=>$kernel->invoke('diagnostic_next_action','tracelog_list',['evidence'=>[]]),
			],
			'diagnostics'=>[
				'list'=>$kernel->invoke('list_tracelog_artifacts',['scope'=>'dataphyre/logs','limit'=>1]),
				'read'=>$diagnosticRead,
				'search'=>$diagnosticSearch,
				'search_all'=>$diagnosticSearchAll,
				'last_error'=>$lastError,
				'missing_query'=>$this->exceptionMessage(fn()=> $kernel->invoke('search_tracelog_artifacts',[])),
				'blank_browser'=>$kernel->invoke('browser_diagnostics_readiness_plan',['base_url'=>'']),
			],
		];
	}

	/** @return array<string,mixed> */
	private function verificationInspectionContract(): array {
		$workspace=$this->inspectionWorkspace();
		$kernel=(new McpKernelHarness($this->context))->useRepositoryRoot($workspace->root());
		$missingToolsWorkspace=$this->context->workspace('mcp-verification-missing-tools');
		$missingToolsWorkspace->file('dataphyre/runtime/modules/demo/unit_tests/demo.json','{"tests":[]}');
		$missingToolsWorkspace->file('dataphyre/dev/tools/public/.keep','fixture');
		$missingToolsKernel=(new McpKernelHarness($this->context))->useRepositoryRoot($missingToolsWorkspace->root());
		$diagnostic='dataphyre/runtime/modules/demo/kernel/example.diagnostic.php';
		$panelRegression='dataphyre/runtime/modules/panel/kernel/panel_regression.php';
		$panelCatalog='dataphyre/runtime/modules/panel/kernel/panel_field_catalog_check.php';
		$unitManifest='dataphyre/runtime/modules/demo/unit_tests/demo.json';
		$missingSuite=$this->exceptionMessage(fn()=> $kernel->invoke('run_panel_regression',['example'=>false]));
		$invalidLint=$this->exceptionMessage(fn()=> $kernel->invoke('php_lint',['dataphyre/README.md']));
		$surfaces=[
			'scalar',
			['category'=>'diagnostic_php','path'=>$diagnostic],
			['category'=>'json_unit_manifest','path'=>$unitManifest],
			['category'=>'regression_php','path'=>$panelRegression,'known_mcp_wrapper'=>'dataphyre_run_panel_regression'],
		];
		return [
			'missing_suite'=>$missingSuite,
			'browser_classes'=>$kernel->invoke('browser_regression_manifest_summary'),
			'catalog'=>$kernel->invoke('verification_surface_catalog',['modules'=>[],'limit'=>2,'include_diagnostics'=>false]),
			'missing_tool_catalog'=>$missingToolsKernel->invoke('verification_surface_catalog',['modules'=>[],'limit'=>20,'include_diagnostics'=>false]),
			'handoff'=>$kernel->invoke('verification_handoff_contract',$surfaces),
			'next_wrapped'=>$kernel->invoke('verification_next_action',$surfaces),
			'next_manifest'=>$kernel->invoke('verification_next_action',[['category'=>'json_unit_manifest','path'=>$unitManifest]]),
			'next_diagnostic'=>$kernel->invoke('verification_next_action',[['category'=>'diagnostic_php','path'=>$diagnostic]]),
			'next_empty'=>$kernel->invoke('verification_next_action',['malformed']),
			'diagnostic_excluded'=>$kernel->invoke('verification_surface_entry',$workspace->path($diagnostic),$diagnostic,'demo',false),
			'panel_regression'=>$kernel->invoke('verification_surface_entry',$workspace->path($panelRegression),$panelRegression,'panel',true),
			'panel_catalog'=>$kernel->invoke('verification_surface_entry',$workspace->path($panelCatalog),$panelCatalog,'panel',true),
			'tool_categories'=>array_map(fn(string $path): string=>(string)$kernel->invoke('verification_tool_surface_entry',$workspace->path($path),$path)['category'],[
				'dataphyre/dev/tools/public/mcp_self_test.php','dataphyre/dev/tools/public/mcp_live_validate.php','dataphyre/dev/tools/public/mcp_config.php',
			]),
			'custom_tool'=>$kernel->invoke('verification_tool_surface_entry',$workspace->path('dataphyre/dev/tools/public/custom.php'),'dataphyre/dev/tools/public/custom.php'),
			'scope_paths'=>array_map(fn(string $path): string=>$kernel->invoke('verification_package_scope_path',$path),[' ./dataphyre//runtime/modules/panel/kernel/panel_regression.php ','common/dataphyre/dev/tools/public/mcp_config.php','runtime/modules/demo/test.php']),
			'module_path'=>$kernel->invoke('module_from_runtime_module_path','docs/readme.md'),
			'invalid_lint'=>$invalidLint,
		];
	}

	private function inspectionWorkspace(): TempWorkspace {
		$workspace=$this->context->workspace('mcp-closed-world-inspection');
		$workspace->file('applications/demo/backend/dataphyre/routes/web.php',<<<'PHP'
<?php
$router->get('/orders/{id}', 'OrderController@show')->name('orders.show')->middleware('auth');
$router->match($methods, $dynamicPath, resolve_handler())->name($dynamicName)->middleware('auth', $dynamicMiddleware);
$router->get('/closure', static fn()=>null)->where('id', '[0-9]+');
$router->get('/array', [OrderController::class, 'index'])->defaults(['mode'=>'safe']);
PHP);
		$workspace->file('applications/demo/backend/dataphyre/routes/extra-routes.php','<?php $router->get("/extra", "ExtraController@index");');
		$workspace->file('applications/demo/backend/dataphyre/routes/documentation/ignored.php','<?php // route documentation');
		$workspace->file('applications/demo/backend/dataphyre/routes/vendor/ignored.php','<?php // vendor route');
		$workspace->file('applications/demo/backend/dataphyre/src/Controller/OrderController.php',<<<'PHP'
<?php
namespace Fixture\Controller;
final class OrderController {
	public function index(): void {}
	public function show(string $id): void {}
	protected function helper(): void {}
}
$handler='OrderController@index';
PHP);
		$workspace->file('applications/demo/backend/dataphyre/src/Middleware/AuditMiddleware.php',<<<'PHP'
<?php
namespace Fixture\Middleware;
final class AuditMiddleware {
	public function handle(object $request): object { return $request; }
}
$router->middleware('auth');
$config=['middleware'=>['auth'=>AuditMiddleware::class]];
PHP);
		$workspace->file('applications/demo/backend/dataphyre/src/documentation/Ignored.php','<?php final class DocumentationController {}');
		$workspace->file('applications/demo/backend/dataphyre/src/vendor/Ignored.php','<?php final class VendorMiddleware {}');
		$workspace->file('applications/demo/backend/dataphyre/src/notes.txt','not PHP');
		$workspace->file('dataphyre/runtime/modules/mvc/Framework/Mvc.php',<<<'PHP'
<?php
namespace Fixture\Mvc;
final class Mvc {
	public static function config(): array { return []; }
	public function routes(): array { return []; }
	private function hidden(): void {}
}
PHP);
		$workspace->file('dataphyre/logs/tracelog-main.log',"Failure one token=secret\nFatal error: failure two in /private/path.php on line 9\n");
		$workspace->file('dataphyre/logs/tracelog-secondary.html','<p>Warning: failure three password=open</p>');
		$workspace->file('dataphyre/runtime/modules/demo/kernel/example.diagnostic.php','<?php // diagnostic');
		$workspace->file('dataphyre/runtime/modules/demo/unit_tests/demo.json','{"tests":[{"name":"demo","expected":true}]}');
		$workspace->file('dataphyre/runtime/modules/panel/kernel/panel_regression.php','<?php if(PHP_SAPI!=="cli"){return;}');
		$workspace->file('dataphyre/runtime/modules/panel/kernel/panel_field_catalog_check.php','<?php if(PHP_SAPI!=="cli"){return;}');
		$workspace->file('dataphyre/runtime/modules/panel/Framework/Testing/.keep','fixture');
		$workspace->file('dataphyre/README.md','# fixture');
		$workspace->file('dataphyre/dev/tools/public/mcp_self_test.php','<?php if(PHP_SAPI!=="cli"){return;}');
		$workspace->file('dataphyre/dev/tools/public/mcp_live_validate.php','<?php if(PHP_SAPI!=="cli"){return;}');
		$workspace->file('dataphyre/dev/tools/public/mcp_config.php','{}');
		$workspace->file('dataphyre/dev/tools/public/custom.php','<?php if(PHP_SAPI!=="cli"){return;}');
		return $workspace;
	}

	private function exceptionMessage(callable $operation): string {
		try{$operation();}catch(\Throwable $error){return $error->getMessage();}
		return '';
	}
}
