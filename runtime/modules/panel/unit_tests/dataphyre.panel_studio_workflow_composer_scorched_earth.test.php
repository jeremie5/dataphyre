<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\InMemoryWorkflowStore;
use Dataphyre\Panel\PanelInMemoryStudioStore;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelStudioDefinition;
use Dataphyre\Panel\PanelStudioDocument;
use Dataphyre\Panel\PanelStudioEditor;
use Dataphyre\Panel\PanelStudioEditorCommand;
use Dataphyre\Panel\PanelStudioEditorOptions;
use Dataphyre\Panel\PanelStudioManager;
use Dataphyre\Panel\PanelStudioMaterializer;
use Dataphyre\Panel\PanelStudioPageBundle;
use Dataphyre\Panel\PanelStudioPolicy;
use Dataphyre\Panel\PanelStudioSchemaException;
use Dataphyre\Panel\PanelStudioSchemaRegistry;
use Dataphyre\Panel\PanelStudioVisualRuntime;
use Dataphyre\Panel\PanelWorkflowGraphAnalysis;
use Dataphyre\Panel\PanelWorkflowSimulation;
use Dataphyre\Panel\PanelWorkflowSimulator;
use Dataphyre\Panel\WorkflowApprovalPolicy;
use Dataphyre\Panel\WorkflowDefinition;
use Dataphyre\Panel\WorkflowEngine;
use Dataphyre\Panel\WorkflowState;
use Dataphyre\Panel\WorkflowTransition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

function dp_panel_studio_process_definition(bool $cycle=true):WorkflowDefinition{
	$workflow=WorkflowDefinition::make('release','Release')->state('draft',['draft'=>true])->state('review')->state('done',['terminal'=>true])->initial('draft')
		->transition(WorkflowTransition::make('submit','draft','review'))
		->transition(WorkflowTransition::make('approve','review','done')->approval(new WorkflowApprovalPolicy(2,['manager'])));
	return$cycle?$workflow->transition(WorkflowTransition::make('return','review','draft')):$workflow;
}

/** @return array<string,mixed> */
function dp_panel_studio_process_node(array $extraChildren=[]):array{return['kind'=>'workflow','key'=>'release','properties'=>['label'=>'Release','initial_state'=>'draft'],'children'=>[
	['kind'=>'workflow_state','key'=>'draft','properties'=>['label'=>'Draft','draft'=>true],'children'=>[]],
	['kind'=>'workflow_state','key'=>'review','properties'=>['label'=>'Review','sla_seconds'=>3600,'assignment_roles'=>['reviewer']],'children'=>[]],
	['kind'=>'workflow_state','key'=>'done','properties'=>['label'=>'Done','terminal'=>true],'children'=>[]],
	['kind'=>'workflow_transition','key'=>'submit','properties'=>['label'=>'Submit','from_states'=>['draft'],'to_state'=>'review','roles'=>['author']],'children'=>[]],
	['kind'=>'workflow_transition','key'=>'approve','properties'=>['label'=>'Approve','from_states'=>['review'],'to_state'=>'done','requires_approval'=>true,'approval_quorum'=>2,'approval_roles'=>['manager'],'reversible'=>true],'children'=>[]],
	...$extraChildren,
]];}

/** @return list<string> */
function dp_panel_studio_process_codes(array $diagnostics):array{return array_map(static fn($diagnostic):string=>$diagnostic->code(),$diagnostics);}

test('workflow simulator reports reachability terminal paths cycles and approval edges deterministically',static function(Context $t):void{
	$simulator=new PanelWorkflowSimulator();$analysis=$simulator->analyze(dp_panel_studio_process_definition());$t->instanceOf(PanelWorkflowGraphAnalysis::class,$analysis);$t->same('release',$analysis->workflow());$t->same('draft',$analysis->initialState());$t->same(['done','draft','review'],$analysis->states());$t->same(['done'],$analysis->terminalStates());$t->same(['done','draft','review'],$analysis->reachableStates());$t->same([],$analysis->unreachableStates());$t->same([],$analysis->statesWithoutTerminalPath());$t->same([['draft','review']],$analysis->cycles());$t->same(3,$analysis->transitionCount());$t->isTrue($analysis->conformant());$t->same('panel_workflow_graph_analysis',$analysis->jsonSerialize()['type']);$t->same($analysis->fingerprint(),$simulator->analyze(dp_panel_studio_process_definition())->fingerprint());
	$orphan=dp_panel_studio_process_definition(false)->state('orphan');$orphanAnalysis=$simulator->analyze($orphan);$t->same(['orphan'],$orphanAnalysis->unreachableStates());$t->same(['orphan'],$orphanAnalysis->statesWithoutTerminalPath());$t->isFalse($orphanAnalysis->conformant());$manifest=$simulator->jsonSerialize();$t->isTrue($manifest['capabilities']['terminal_path_analysis']);$t->isTrue($manifest['capabilities']['side_effect_free']);
})->tag('panel','workflow','studio','analysis','scorched-earth')->isolation('case')->maxMillis(5000);

test('workflow simulator executes explicit and deterministic paths without effects or callbacks',static function(Context $t):void{
	$simulator=new PanelWorkflowSimulator();$workflow=dp_panel_studio_process_definition();$explicit=$simulator->simulate($workflow,['submit','approve']);$t->instanceOf(PanelWorkflowSimulation::class,$explicit);$t->same('release',$explicit->workflow());$t->same(['draft','review','done'],$explicit->states());$t->same(['submit','approve'],$explicit->transitions());$t->same(['approve'],$explicit->approvalTransitions());$t->isTrue($explicit->completed());$t->same('terminal',$explicit->stopReason());$t->same('panel_workflow_simulation',$explicit->jsonSerialize()['type']);$t->same($explicit->fingerprint(),$simulator->simulate($workflow,['submit','approve'])->fingerprint());
	$automatic=$simulator->simulate($workflow);$t->same(['submit','approve'],$automatic->transitions());$exhausted=$simulator->simulate($workflow,['submit']);$t->isFalse($exhausted->completed());$t->same('sequence_exhausted',$exhausted->stopReason());$t->throws(static fn()=>$simulator->simulate($workflow,['approve']),InvalidArgumentException::class);
	$loop=WorkflowDefinition::make('loop')->state('a')->state('b')->initial('a')->transition(WorkflowTransition::make('go','a','b'))->transition(WorkflowTransition::make('back','b','a'));$limited=$simulator->simulate($loop,[],null,3);$t->same('step_limit',$limited->stopReason());$t->same(3,count($limited->transitions()));
	$callback=WorkflowDefinition::make('callback')->state('a')->state('done',['terminal'=>true])->transition(WorkflowTransition::make('go','a','done')->guard(static fn():bool=>true));$t->throws(static fn()=>$simulator->simulate($callback),LogicException::class);$t->isTrue($simulator->simulate($callback,[],null,2,false)->completed());
})->tag('panel','workflow','simulation','side-effect-free','scorched-earth')->isolation('case')->maxMillis(5000);

test('Studio materializes callback-free workflow graphs and rejects invalid or unreachable process models',static function(Context $t):void{
	$registry=PanelStudioSchemaRegistry::defaults();$materializer=new PanelStudioMaterializer();$definition=PanelStudioDefinition::from(dp_panel_studio_process_node());$validation=$registry->validate($definition);$t->isTrue($validation->valid());$materialization=$materializer->materialize($definition,$registry);$workflow=$materialization->root();$t->instanceOf(WorkflowDefinition::class,$workflow);$t->same('draft',$workflow->initialState());$t->same(3,count($workflow->states()));$t->same(2,count($workflow->transitions()));$t->same('workflow_definition',$materialization->manifest()['builder_contract']['root']);$approve=$workflow->transitionNamed('approve');$t->notNull($approve?->approvalPolicy());$t->same(null,$approve?->guardResolver());$t->same(null,$approve?->assignmentResolver());$t->same(null,$approve?->compensator());$t->same('validated_callback_free_workflow_definition',$materializer->manifest()['workflow_output']);$t->isTrue($materializer->manifest()['security']['workflow_graph_preflight']);$t->isFalse($materializer->manifest()['security']['workflow_callbacks']);
	$missing=dp_panel_studio_process_node();$missing['children'][4]['properties']['to_state']='missing';$diagnostics=$materializer->diagnose($missing,$registry);$t->isTrue(in_array('workflow_contract_invalid',dp_panel_studio_process_codes($diagnostics),true));$t->throws(static fn()=>$materializer->materialize($missing,$registry),PanelStudioSchemaException::class);
	$unreachable=dp_panel_studio_process_node([['kind'=>'workflow_state','key'=>'orphan','properties'=>['label'=>'Orphan'],'children'=>[]]]);$codes=dp_panel_studio_process_codes($materializer->diagnose($unreachable,$registry));$t->isTrue(in_array('workflow_state_unreachable',$codes,true));$t->isTrue(in_array('workflow_terminal_path_missing',$codes,true));
	$unterminated=dp_panel_studio_process_node();$unterminated['children'][2]['properties']['terminal']=false;$t->isTrue(in_array('workflow_terminal_state_missing',dp_panel_studio_process_codes($materializer->diagnose($unterminated,$registry)),true));
})->tag('panel','studio','workflow','materialization','validation','scorched-earth')->isolation('case')->maxMillis(8000);

test('Studio page bundles register workflow definitions only through an explicit host engine',static function(Context $t):void{
	$page=['kind'=>'page','key'=>'operations','properties'=>['label'=>'Operations'],'children'=>[dp_panel_studio_process_node()]];$bundle=(new PanelStudioMaterializer())->materialize($page,PanelStudioSchemaRegistry::defaults())->root();$t->instanceOf(PanelStudioPageBundle::class,$bundle);$t->same(1,count($bundle->workflows()));$t->same(1,$bundle->jsonSerialize()['workflow_count']);$t->same('workflow_definition',$bundle->jsonSerialize()['surfaces']['root.children[0]']);$engine=new WorkflowEngine(new InMemoryWorkflowStore());$host=new PanelManager();$t->same($bundle->page(),$bundle->registerAll($host,null,false,$engine));$t->notNull($engine->definition('release'));$t->same(['release'],array_keys($engine->definitions()));$t->same($engine,$bundle->registerWorkflows($engine));$t->isTrue($bundle->jsonSerialize()['runtime']['workflow_registerable']);
})->tag('panel','studio','workflow','page-bundle','registration','scorched-earth')->isolation('case')->maxMillis(5000);

test('Studio editor and visual runtime expose the process graph as an accessible first-party surface',static function(Context $t):void{
	$visual=new PanelStudioVisualRuntime();$manager=new PanelStudioManager(new PanelInMemoryStudioStore(),PanelStudioPolicy::permit(static fn():bool=>true),visualRuntime:$visual);$definition=PanelStudioDefinition::from(dp_panel_studio_process_node());$session=PanelStudioEditor::open($manager,PanelStudioDocument::make('tenant-process','process-editor','Process editor'),'designer',$definition);$preview=PanelStudioEditor::visualPreview($session);$surface=$preview->surface('root');$t->isFalse($surface?->failed()??true);$t->same('workflow_definition',$surface?->symbol());$content=$surface?->result()?->content()??'';$t->contains('dp-studio-workflow-preview',$content);$t->contains('Initial state:',$content);$t->contains('Approval required',$content);
	$session->apply(PanelStudioEditorCommand::select('release/approve'));$html=PanelStudioEditor::render($session,PanelStudioEditorOptions::make(['action_url'=>'/studio','csrf_token'=>str_repeat('c',32)]));$t->contains('Processes',$html);$t->contains('data-dp-studio-add="workflow"',$html);$t->contains('review -&gt; done',$html);$manifest=PanelStudioEditor::manifest($session);$t->isTrue($manifest['renderer']['contracts']['workflow_graph_inspector']);$t->isTrue($manifest['visual_runtime']['capabilities']['workflow_graph_preview']);
})->tag('panel','studio','workflow','editor','visual-runtime','accessibility','scorched-earth')->isolation('case')->maxMillis(12000);

test('workflow analysis and simulation value objects reject malformed public state',static function(Context $t):void{
	$t->throws(static fn()=>new PanelWorkflowGraphAnalysis('x','a',['a'],[],['a'],[],[],[],0,0,'bad'),InvalidArgumentException::class);$t->throws(static fn()=>new PanelWorkflowGraphAnalysis('x','a',['Bad State'],[],[],[],[],[],0,0,str_repeat('a',64)),InvalidArgumentException::class);$t->throws(static fn()=>new PanelWorkflowSimulation('x',['a'],['go'],[],false,'dead_end',str_repeat('a',64)),InvalidArgumentException::class);$t->throws(static fn()=>new PanelWorkflowSimulation('x',['a'],[],[],true,'dead_end',str_repeat('a',64)),InvalidArgumentException::class);
})->tag('panel','workflow','value-object','boundaries','scorched-earth')->isolation('case')->maxMillis(3000);
