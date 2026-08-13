<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
use Dataphyre\Panel\PanelArrayRelationAdapter;
use Dataphyre\Panel\PanelFilesystemOperationStore;
use Dataphyre\Panel\PanelNotificationInbox;
use Dataphyre\Panel\PanelOperationControl;
use Dataphyre\Panel\PanelOperationRecord;
use Dataphyre\Panel\PanelPlatformController;
use Dataphyre\Panel\PanelRelationWorkspace;
use Dataphyre\Panel\AutomationAction;
use Dataphyre\Panel\AutomationExecutor;
use Dataphyre\Panel\AutomationRegistry;
use Dataphyre\Panel\InMemoryAutomationStore;
use Dataphyre\Panel\InMemoryWorkflowStore;
use Dataphyre\Panel\WorkflowDefinition;
use Dataphyre\Panel\WorkflowEngine;
use Dataphyre\Panel\WorkflowTransition;
use Dataphyre\Panel\PanelAuthenticationManager;
use Dataphyre\Panel\PanelCollaborationManager;
use Dataphyre\Panel\PanelInMemoryCollaborationStore;
use Dataphyre\Panel\PanelInMemoryPreferenceStore;
use Dataphyre\Panel\PanelWorkspacePreferences;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
require_once __DIR__.'/panel_test_harness_helpers.php';dp_panel_unit_test_bootstrap();

test('platform controller renders and safely controls persistent operations',static function(Context $t):void{
	$root=$t->workspace('panel-platform-controller')->root();$store=new PanelFilesystemOperationStore($root);$store->create(PanelOperationRecord::make('import','Import',['id'=>'import-1','total'=>2])->start('worker'));
	$controller=(new PanelPlatformController())->csrf(static fn():bool=>true)->authorize(static fn(string $ability):bool=>$ability!=='operations.cancel');
	$t->contains('Import',$controller->operations($store,[],['method'=>'GET','user'=>['id'=>'operator']])->content());
	$paused=$controller->operate(new PanelOperationControl($store),['method'=>'POST','input'=>['id'=>'import-1','operation'=>'pause'],'user'=>['id'=>'operator']]);$payload=json_decode($paused->content(),true);$t->isTrue($payload['ok']);$t->same('pause_requested',$payload['operation']['status']);
	$denied=$controller->operate(new PanelOperationControl($store),['method'=>'POST','input'=>['id'=>'import-1','operation'=>'cancel'],'user'=>['id'=>'operator']]);$t->same(403,$denied->status());
	$method=$controller->operate(new PanelOperationControl($store),['method'=>'GET','input'=>[]]);$t->same(405,$method->status());
})->tag('panel','platform','controller','operations')->maxMillis(2000);

test('platform controller executes relation commands with concurrency receipts',static function(Context $t):void{
	$workspace=PanelRelationWorkspace::make('items','order-1',new PanelArrayRelationAdapter([1=>['id'=>1,'name'=>'One']],[]));$controller=(new PanelPlatformController())->csrf(static fn():bool=>true)->authorize(static fn():bool=>true);
	$result=$controller->relate($workspace,['method'=>'POST','input'=>['operation'=>'attach','related_id'=>'1','version'=>0,'idempotency_key'=>'attach-one'],'user'=>['id'=>'operator']]);$payload=json_decode($result->content(),true);
	$t->isTrue($payload['ok']);$t->same('committed',$payload['status']);$t->same('One',$payload['records'][0]['name']);
	$duplicate=$controller->relate($workspace,['method'=>'POST','input'=>['operation'=>'attach','related_id'=>'1','version'=>1,'idempotency_key'=>'attach-one'],'user'=>['id'=>'operator']]);$t->same('duplicate',json_decode($duplicate->content(),true)['status']);
})->tag('panel','platform','controller','relations')->maxMillis(1000);

test('platform controller owns durable notification state actions',static function(Context $t):void{
	$inbox=PanelNotificationInbox::make();$notification=$inbox->add(['title'=>'Assigned','message'=>'Order assigned']);$controller=(new PanelPlatformController())->csrf(static fn():bool=>true)->authorize(static fn():bool=>true);
	$t->contains('Assigned',$controller->notifications($inbox,[],['method'=>'GET','user'=>['id'=>'operator']])->content());
	$result=$controller->notify($inbox,['method'=>'POST','input'=>['id'=>$notification->id(),'operation'=>'read'],'user'=>['id'=>'operator']]);$payload=json_decode($result->content(),true);
	$t->isTrue($payload['ok']);$t->same(0,$payload['counts']['unread']);
})->tag('panel','platform','controller','notifications')->maxMillis(1000);

test('platform controller dispatches workflow transitions and agent-safe automation plans',static function(Context $t):void{
	$definition=WorkflowDefinition::make('release')->state('draft')->state('done',['terminal'=>true])->initial('draft')->transition(WorkflowTransition::make('submit','draft','done'));
	$engine=new WorkflowEngine(new InMemoryWorkflowStore(),[$definition]);$controller=(new PanelPlatformController())->csrf(static fn():bool=>true)->authorize(static fn():bool=>true);
	$started=$controller->workflow($engine,['method'=>'POST','input'=>['operation'=>'start','definition'=>'release','id'=>'release-1','data'=>['order'=>'1'],'idempotency_key'=>'start-1'],'user'=>['id'=>'operator']]);$t->isTrue(json_decode($started->content(),true)['ok']);
	$transitioned=$controller->workflow($engine,['method'=>'POST','input'=>['operation'=>'transition','definition'=>'release','id'=>'release-1','transition'=>'submit','version'=>1,'idempotency_key'=>'submit-1'],'user'=>['id'=>'operator']]);$workflow=json_decode($transitioned->content(),true);$t->same('transitioned',$workflow['code']);$t->same('done',$workflow['record']['state']);
	$action=AutomationAction::make('echo')->requiresIdempotency(false)->planUsing(static fn(array $input):array=>['summary'=>'Echo value','steps'=>['Validate'],'effects'=>[]])->handle(static fn(array $input):array=>['value'=>$input['value']??null]);$executor=new AutomationExecutor(new AutomationRegistry([$action]),new InMemoryAutomationStore());
	$planned=$controller->automate($executor,'echo',['method'=>'POST','input'=>['dry_run'=>true,'data'=>['value'=>'ready']],'user'=>['id'=>'agent']]);$t->same('planned',json_decode($planned->content(),true)['code']);
	$executed=$controller->automate($executor,'echo',['method'=>'POST','input'=>['data'=>['value'=>'ready']],'user'=>['id'=>'agent']]);$automation=json_decode($executed->content(),true);$t->same('executed',$automation['code']);$t->same('ready',$automation['receipt']['result']['value']);
})->tag('panel','platform','controller','workflow','automation')->maxMillis(2000);

test('platform controller renders secret-free authentication inventory and one-time TOTP enrollment',static function(Context $t):void{
	$manager=PanelAuthenticationManager::memory(str_repeat('e',32),str_repeat('p',24));$device=$manager->trustDevice('operator','Workstation','fingerprint',['id'=>'device-1']);$session=$manager->createSession('operator',['id'=>'session-1','device_id'=>$device->device()->id()]);$controller=(new PanelPlatformController())->csrf(static fn():bool=>true)->authorize(static fn():bool=>true);
	$page=$controller->authentication($manager,'operator',['action_url'=>'/auth'],['method'=>'GET','user'=>['id'=>'operator']]);$t->contains('Trusted devices',$page->content());$t->contains('Workstation',$page->content());$t->contains('session-1',$page->content());$t->notContains($device->token(),$page->content());$t->notContains($session->token(),$page->content());
	$enrollment=$controller->authenticate($manager,['method'=>'POST','input'=>['operation'=>'provision_totp','label'=>'Primary'],'user'=>['id'=>'operator']],['action_url'=>'/auth']);$t->same(200,$enrollment->status());$t->contains('Authenticator enrollment',$enrollment->content());$t->contains('Recovery codes',$enrollment->content());$t->same(1,count($manager->factors('operator')));
	$revoked=$controller->authenticate($manager,['method'=>'POST','input'=>['operation'=>'revoke_device','id'=>'device-1'],'user'=>['id'=>'operator']]);$t->isTrue(json_decode($revoked->content(),true)['ok']);$t->isFalse($manager->devices('operator')[0]->active());$t->isFalse($manager->sessions('operator')[0]->active());
})->tag('panel','platform','controller','authentication')->maxMillis(2000);

test('platform controller commits preference changes and reports optimistic conflicts',static function(Context $t):void{
	$workspace=new PanelWorkspacePreferences(new PanelInMemoryPreferenceStore(),'operator');$controller=(new PanelPlatformController())->csrf(static fn():bool=>true)->authorize(static fn():bool=>true);
	$changed=$controller->prefer($workspace,['method'=>'POST','input'=>['operation'=>'appearance','theme'=>'glass','density'=>'compact','locale'=>'fr-CA','direction'=>'ltr','expected_revision'=>0],'user'=>['id'=>'operator']]);$payload=json_decode($changed->content(),true);
	$t->isTrue($payload['ok']);$t->same('glass',$payload['preferences']['settings']['appearance']['theme']);
	$conflict=$controller->prefer($workspace,['method'=>'POST','input'=>['operation'=>'appearance','theme'=>'default','expected_revision'=>0],'user'=>['id'=>'operator']]);$conflictPayload=json_decode($conflict->content(),true);
	$t->same(409,$conflict->status());$t->same('panel_preference_conflict',$conflictPayload['conflict']['type']);
	$t->contains('Workspace preferences',$controller->preferences($workspace,[],['method'=>'GET','user'=>['id'=>'operator']])->content());
})->tag('panel','platform','controller','preferences')->maxMillis(1000);

test('platform controller owns threads comments and renewable presence leases',static function(Context $t):void{
	$manager=new PanelCollaborationManager(new PanelInMemoryCollaborationStore());$controller=(new PanelPlatformController())->csrf(static fn():bool=>true)->authorize(static fn():bool=>true);
	$created=$controller->collaborate($manager,['method'=>'POST','input'=>['operation'=>'create_thread','subject_type'=>'order','subject_id'=>'SO-1','title'=>'Payment review'],'user'=>['id'=>'operator']]);$thread=json_decode($created->content(),true)['result'];
	$t->same('Payment review',$thread['title']);
	$commented=$controller->collaborate($manager,['method'=>'POST','input'=>['operation'=>'comment','thread_id'=>$thread['id'],'body'=>'Checked by risk.','mentions'=>'owner, finance'],'user'=>['id'=>'reviewer']]);$t->isTrue(json_decode($commented->content(),true)['ok']);
	$presence=$controller->collaborate($manager,['method'=>'POST','input'=>['operation'=>'acquire_presence','scope'=>'order:SO-1','ttl_seconds'=>30],'user'=>['id'=>'operator']]);$lease=json_decode($presence->content(),true)['result'];$t->notEmpty($lease['lease_token']);
	$heartbeat=$controller->collaborate($manager,['method'=>'POST','input'=>['operation'=>'heartbeat_presence','scope'=>'order:SO-1','lease_token'=>$lease['lease_token']],'user'=>['id'=>'operator']]);$t->isTrue(json_decode($heartbeat->content(),true)['ok']);
	$t->contains('Checked by risk.',$controller->collaboration($manager,['subject_type'=>'order','subject_id'=>'SO-1'],['method'=>'GET','user'=>['id'=>'operator']])->content());
})->tag('panel','platform','controller','collaboration')->maxMillis(1500);
