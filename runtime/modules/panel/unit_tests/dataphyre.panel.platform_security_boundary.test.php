<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelNotificationInbox;
use Dataphyre\Panel\PanelOperationRecord;
use Dataphyre\Panel\PanelFilesystemOperationStore;
use Dataphyre\Panel\PanelOperationControl;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPlatformController;
use Dataphyre\Panel\PanelArrayRelationAdapter;
use Dataphyre\Panel\PanelRelationWorkspace;
use Dataphyre\Panel\PanelSecurityAuditTrail;
use Dataphyre\Panel\PanelSecurityContext;
use Dataphyre\Panel\PanelSecurityDecision;
use Dataphyre\Panel\PanelSecurityPolicy;
use Dataphyre\Panel\AutomationExecutor;
use Dataphyre\Panel\AutomationRegistry;
use Dataphyre\Panel\InMemoryAutomationStore;
use Dataphyre\Panel\InMemoryWorkflowStore;
use Dataphyre\Panel\WorkflowEngine;
use Dataphyre\Panel\PanelAuthenticationManager;
use Dataphyre\Panel\PanelCollaborationManager;
use Dataphyre\Panel\PanelInMemoryCollaborationStore;
use Dataphyre\Panel\PanelInMemoryPreferenceStore;
use Dataphyre\Panel\PanelWorkspacePreferences;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array<string,mixed> */
function dp_panel_secure_platform_config(string $root,array $platform=[]):array{return['state_root'=>$root,'authentication'=>['encryption_key'=>str_repeat('E',32),'pepper'=>str_repeat('P',32),'challenge_key'=>str_repeat('C',32)],'media'=>['signing_key'=>str_repeat('M',32)],'platform'=>$platform];}

test('platform mutation guard fails closed when CSRF or authorization is unavailable or throws',static function(Context $t):void{
	$inbox=PanelNotificationInbox::make();$notification=$inbox->add(['title'=>'Boundary','message'=>'Still unread']);$request=['method'=>'POST','input'=>['id'=>$notification->id(),'operation'=>'read'],'user'=>['id'=>'operator']];
	$default=new PanelPlatformController();
	$t->same(401,$default->notifications($inbox)->status());
	$t->same(419,$default->notify($inbox,$request)->status());
	$t->same(1,$inbox->counts()['unread']);
	$t->same(403,$default->csrf(static fn():bool=>true)->notify($inbox,$request)->status());
	$t->same(1,$inbox->counts()['unread']);
	$t->same(419,$default->authorize(static fn():bool=>true)->notify($inbox,$request)->status());
	$t->same(419,$default->csrf(static function():never{throw new RuntimeException('validator detail');})->authorize(static fn():bool=>true)->notify($inbox,$request)->status());
	$denied=$default->csrf(static fn():bool=>true)->authorize(static function():never{throw new RuntimeException('authorizer detail');})->notify($inbox,$request);
	$t->same(403,$denied->status());$t->notContains('authorizer detail',$denied->content());
})->tag('panel','platform-controller','security','fail-closed')->maxMillis(1000);

test('every platform controller mutation surface shares the fail closed boundary',static function(Context $t):void{
	$store=new PanelFilesystemOperationStore($t->workspace('panel-boundary-all-mutations')->root());$control=new PanelOperationControl($store);$relations=PanelRelationWorkspace::make('items','order-1',new PanelArrayRelationAdapter());$workflow=new WorkflowEngine(new InMemoryWorkflowStore());$automation=new AutomationExecutor(new AutomationRegistry(),new InMemoryAutomationStore());$inbox=PanelNotificationInbox::make();$notification=$inbox->add(['title'=>'Boundary']);$preferences=new PanelWorkspacePreferences(new PanelInMemoryPreferenceStore(),'operator');$collaboration=new PanelCollaborationManager(new PanelInMemoryCollaborationStore());$authentication=PanelAuthenticationManager::memory(str_repeat('E',32),str_repeat('P',24));
	$mutations=[
		static fn(PanelPlatformController $controller)=>$controller->operate($control,['method'=>'POST','input'=>['id'=>'operation-1','operation'=>'pause'],'user'=>['id'=>'operator']]),
		static fn(PanelPlatformController $controller)=>$controller->relate($relations,['method'=>'POST','input'=>['operation'=>'attach','related_id'=>'record-1'],'user'=>['id'=>'operator']]),
		static fn(PanelPlatformController $controller)=>$controller->workflow($workflow,['method'=>'POST','input'=>['operation'=>'start','definition'=>'orders','id'=>'workflow-1'],'user'=>['id'=>'operator']]),
		static fn(PanelPlatformController $controller)=>$controller->automate($automation,'missing',['method'=>'POST','input'=>[],'user'=>['id'=>'operator']]),
		static fn(PanelPlatformController $controller)=>$controller->notify($inbox,['method'=>'POST','input'=>['id'=>$notification->id(),'operation'=>'read'],'user'=>['id'=>'operator']]),
		static fn(PanelPlatformController $controller)=>$controller->prefer($preferences,['method'=>'POST','input'=>['operation'=>'appearance'],'user'=>['id'=>'operator']]),
		static fn(PanelPlatformController $controller)=>$controller->collaborate($collaboration,['method'=>'POST','input'=>['operation'=>'cleanup_expired'],'user'=>['id'=>'operator']]),
		static fn(PanelPlatformController $controller)=>$controller->authenticate($authentication,['method'=>'POST','input'=>['operation'=>'revoke_session','id'=>'session-1'],'user'=>['id'=>'operator']]),
	];
	foreach($mutations as$mutation){$t->same(419,$mutation(new PanelPlatformController())->status());$t->same(403,$mutation((new PanelPlatformController())->csrf(static fn():bool=>true))->status());}
	$t->same(1,$inbox->counts()['unread']);$t->same(0,$preferences->manifest()['revision']);
})->tag('panel','platform-controller','security','fail-closed','all-mutations')->maxMillis(3000);

test('platform controller evaluates security contexts policies tenants and decision objects',static function(Context $t):void{
	$audit=new PanelSecurityAuditTrail($t->workspace('panel-boundary-policy')->path('audit.json'));
	$controller=(new PanelPlatformController())->csrf(static fn():bool=>true)->securityBoundary($audit);
	$inbox=PanelNotificationInbox::make();$allowedNotification=$inbox->add(['title'=>'Allowed']);$deniedNotification=$inbox->add(['title'=>'Denied']);
	$allowed=$controller->notify($inbox,['method'=>'POST','input'=>['id'=>$allowedNotification->id(),'operation'=>'read'],'user'=>['id'=>'operator','permissions'=>['notifications.read'],'session_id'=>'session-super-secret','attributes'=>['api_token'=>'token-super-secret']]]);
	$t->same(200,$allowed->status());
	$denied=$controller->notify($inbox,['method'=>'POST','input'=>['id'=>$deniedNotification->id(),'operation'=>'read'],'user'=>['id'=>'viewer','permissions'=>[]]]);
	$t->same(403,$denied->status());
	$tenantDenied=$controller->notify($inbox,['method'=>'POST','tenant'=>'tenant-b','input'=>['id'=>$deniedNotification->id(),'operation'=>'read'],'user'=>['id'=>'operator','tenant_id'=>'tenant-a','permissions'=>['notifications.read']]]);
	$t->same(403,$tenantDenied->status());
	$custom=(new PanelPlatformController())->csrf(static fn():bool=>true)->securityBoundary($audit,static fn():PanelSecurityContext=>PanelSecurityContext::make('step-up',['permissions'=>['notifications.read'],'mfa_level'=>2]),static fn(string $ability):PanelSecurityPolicy=>PanelSecurityPolicy::make($ability)->permissions($ability)->mfa(2));
	$t->same(200,$custom->notify($inbox,['method'=>'POST','input'=>['id'=>$deniedNotification->id(),'operation'=>'read']])->status());
	$withoutAudit=(new PanelPlatformController())->csrf(static fn():bool=>true)->securityBoundary();$withoutAuditNotification=$inbox->add(['title'=>'No audit adapter']);
	$t->same(200,$withoutAudit->notify($inbox,['method'=>'POST','input'=>['id'=>$withoutAuditNotification->id(),'operation'=>'read'],'user'=>['id'=>'operator','permissions'=>['notifications.read']]])->status());
	$missingContextNotification=$inbox->add(['title'=>'Missing context']);$t->same(403,$withoutAudit->notify($inbox,['method'=>'POST','input'=>['id'=>$missingContextNotification->id(),'operation'=>'read']])->status());
	$decision=(new PanelPlatformController())->csrf(static fn():bool=>true)->authorize(static fn(string $ability):PanelSecurityDecision=>new PanelSecurityDecision($ability==='notifications.unread',$ability));
	$t->same(200,$decision->notify($inbox,['method'=>'POST','input'=>['id'=>$deniedNotification->id(),'operation'=>'unread'],'user'=>['id'=>'operator']])->status());
	$json=json_encode($audit,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$t->notContains('session-super-secret',$json);$t->notContains('token-super-secret',$json);$t->contains('[REDACTED]',$json);
})->tag('panel','platform-controller','security','policy','audit')->maxMillis(2000);

test('default platform wires policy context and audit while retaining mandatory CSRF',static function(Context $t):void{
	$root=$t->tempDirectory('panel-secure-platform');$platform=PanelPlatform::defaults(dp_panel_secure_platform_config($root,['csrf'=>static fn():bool=>true]));$store=$platform->operationStore();$store->create(PanelOperationRecord::make('import','Import',['id'=>'secure-operation','total'=>1])->start('worker'));
	$allowed=$platform->controller()->operate($platform->operationControl(),['method'=>'POST','input'=>['id'=>'secure-operation','operation'=>'pause'],'user'=>['id'=>'operator','permissions'=>['operations.pause']]]);
	$t->same(200,$allowed->status());
	$denied=$platform->controller()->operate($platform->operationControl(),['method'=>'POST','input'=>['id'=>'secure-operation','operation'=>'cancel'],'user'=>['id'=>'viewer','permissions'=>[]]]);
	$t->same(403,$denied->status());
	$t->same(2,count($platform->securityAudit()->events('mutation.authorization')));
	$withoutCsrf=PanelPlatform::defaults(dp_panel_secure_platform_config($t->tempDirectory('panel-secure-platform-no-csrf')));
	$withoutCsrf->operationStore()->create(PanelOperationRecord::make('import','Import',['id'=>'csrf-operation','total'=>1])->start('worker'));
	$result=$withoutCsrf->controller()->operate($withoutCsrf->operationControl(),['method'=>'POST','input'=>['id'=>'csrf-operation','operation'=>'pause'],'user'=>['id'=>'operator','permissions'=>['operations.pause']]]);
	$t->same(419,$result->status());$t->same('running',$withoutCsrf->operationStore()->get('csrf-operation')?->status());$t->same(1,count($withoutCsrf->securityAudit()->events('mutation.csrf')));
})->tag('panel','platform','security','defaults','csrf')->maxMillis(4000);

test('security audit trail redacts nested secrets and merges stale concurrent writers',static function(Context $t):void{
	$memory=new PanelSecurityAuditTrail();$memory->record('authorization.decision',['actor_id'=>'memory'],['allowed'=>true]);$t->same(1,count($memory->events()));$t->isTrue($memory->verify());
	$file=$t->workspace('panel-security-audit-concurrency')->path('audit.json');$first=new PanelSecurityAuditTrail($file);$second=new PanelSecurityAuditTrail($file);$context=PanelSecurityContext::make('operator',['session_id'=>'session-secret','attributes'=>['nested'=>['password'=>'password-secret','safe'=>'visible']]]);
	$first->record('authorization.decision',$context,['allowed'=>true],['csrf_token'=>'csrf-secret','resource'=>'orders']);
	$second->record('authorization.decision',$context,['allowed'=>false],['authorization'=>'bearer-secret','resource'=>'sellers']);
	$events=$first->events();$t->same(2,count($events));$t->same(1,$events[0]['sequence']);$t->same(2,$events[1]['sequence']);$t->same($events[0]['hash'],$events[1]['previous_hash']);$t->isTrue($first->verify());$t->isTrue($second->verify());
	$json=json_encode($events,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);foreach(['session-secret','password-secret','csrf-secret','bearer-secret']as$secret){$t->notContains($secret,$json);}$t->contains('visible',$json);$t->contains('orders',$json);
	$corruptFile=$t->workspace('panel-security-audit-corrupt')->path('audit.json');file_put_contents($corruptFile,'[]');$corrupt=new PanelSecurityAuditTrail($corruptFile);file_put_contents($corruptFile,'[{"hash":"bad"}]');$inbox=PanelNotificationInbox::make();$notification=$inbox->add(['title'=>'Protected']);$controller=(new PanelPlatformController())->csrf(static fn():bool=>true)->securityBoundary($corrupt);
	$t->same(503,$controller->notify($inbox,['method'=>'POST','input'=>['id'=>$notification->id(),'operation'=>'read'],'user'=>['id'=>'operator','permissions'=>['notifications.read']]])->status());$t->same(1,$inbox->counts()['unread']);
})->tag('panel','security','audit','redaction','concurrency')->maxMillis(2000);
