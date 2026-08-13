<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\InMemoryWorkflowStore;
use Dataphyre\Panel\PanelAuthenticationManager;
use Dataphyre\Panel\PanelCollaborationManager;
use Dataphyre\Panel\PanelInMemoryCollaborationStore;
use Dataphyre\Panel\PanelInMemoryPreferenceStore;
use Dataphyre\Panel\PanelManifestInspector;
use Dataphyre\Panel\PanelPlatformController;
use Dataphyre\Panel\PanelWorkspacePreferences;
use Dataphyre\Panel\WorkflowDefinition;
use Dataphyre\Panel\WorkflowEngine;
use Dataphyre\Panel\WorkflowTransition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('platform controller routes every workflow command through the engine contract', static function(Context $t): void {
	$definition=WorkflowDefinition::make('release')
		->state('draft', ['draft'=>true])
		->state('done', ['terminal'=>true])
		->initial('draft')
		->transition(WorkflowTransition::make('submit', 'draft', 'done'));
	$engine=new WorkflowEngine(new InMemoryWorkflowStore(), [$definition]);
	$controller=(new PanelPlatformController())->csrf(static fn(): bool=>true)->authorize(static fn(): bool=>true);

	$commands=[
		'approve'=>['comment'=>'Approved'],
		'reject'=>['comment'=>'Risk remains'],
		'draft'=>['data'=>['notes'=>'Saved']],
		'assign'=>['assigned_to'=>'operator-2', 'roles'=>['reviewer']],
		'rollback'=>['reason'=>'Correction'],
		'check_sla'=>[],
	];
	foreach($commands as $operation=>$input){
		$result=$controller->workflow($engine, [
			'method'=>'POST',
			'input'=>['operation'=>$operation, 'definition'=>'release', 'id'=>'missing-record']+$input,
			'user'=>['id'=>'operator-1'],
		]);
		$t->isTrue(in_array($result->status(), [200, 403, 404, 409, 422], true), $operation.' should return a bounded workflow result status.');
	}
})->tag('panel', 'platform-controller', 'workflow', 'dispatch', 'exact-coverage')->maxMillis(2000);

test('platform controller applies every workspace preference mutation through one HTTP-neutral surface', static function(Context $t): void {
	$workspace=new PanelWorkspacePreferences(new PanelInMemoryPreferenceStore(), 'operator');
	$controller=(new PanelPlatformController())->csrf(static fn(): bool=>true)->authorize(static fn(): bool=>true);
	$post=static fn(string $operation, array $input=[]): array=>[
		'method'=>'POST', 'input'=>['operation'=>$operation]+$input, 'user'=>['id'=>'operator'],
	];

	$requests=[
		$post('save_table_view', ['resource'=>'orders', 'name'=>'risk', 'configuration'=>['filters'=>['status'=>'pending']]]),
		$post('delete_table_view', ['resource'=>'orders', 'name'=>'risk']),
		$post('touch_recent', ['type'=>'order', 'id'=>'SO-1', 'meta'=>['source'=>'search']]),
		$post('pin', ['type'=>'order', 'id'=>'SO-1', 'meta'=>['label'=>'Priority']]),
		$post('unpin', ['type'=>'order', 'id'=>'SO-1']),
		$post('notifications', ['preferences'=>['email'=>false, 'database'=>true]]),
		$post('device_overrides', ['device'=>'workstation', 'overrides'=>['appearance'=>['density'=>'compact']]]),
	];
	foreach($requests as $request){
		$payload=json_decode($controller->prefer($workspace, $request)->content(), true, 512, JSON_THROW_ON_ERROR);
		$t->isTrue($payload['ok']);
	}

	$export=$workspace->export();
	$import=json_decode($controller->prefer($workspace, $post('import', ['payload'=>$export, 'strategy'=>'merge']))->content(), true, 512, JSON_THROW_ON_ERROR);
	$t->isTrue($import['ok']);
})->tag('panel', 'platform-controller', 'preferences', 'dispatch', 'exact-coverage')->maxMillis(2000);

test('platform controller routes the complete collaboration command vocabulary and translates failures', static function(Context $t): void {
	$manager=new PanelCollaborationManager(new PanelInMemoryCollaborationStore());
	$manager->createThread('operator', 'order', 'SO-1', 'Review', [], 'thread-one');
	$lease=$manager->acquirePresence('order:SO-1', 'operator', 30);
	$controller=(new PanelPlatformController())->csrf(static fn(): bool=>true)->authorize(static fn(): bool=>true);
	$post=static fn(string $operation, array $input=[]): array=>[
		'method'=>'POST', 'input'=>['operation'=>$operation]+$input, 'user'=>['id'=>'operator'],
	];

	$commands=[
		$post('set_thread_status', ['thread_id'=>'thread-one', 'status'=>'resolved']),
		$post('assign', ['subject_type'=>'order', 'subject_id'=>'SO-1', 'assignee'=>'operator-2']),
		$post('unassign', ['subject_type'=>'order', 'subject_id'=>'SO-1']),
		$post('watch', ['subject_type'=>'order', 'subject_id'=>'SO-1', 'user_id'=>'operator-2']),
		$post('unwatch', ['subject_type'=>'order', 'subject_id'=>'SO-1', 'user_id'=>'operator-2']),
		$post('subscribe', ['topic'=>'orders.*', 'user_id'=>'operator-2', 'channels'=>'mail, database']),
		$post('unsubscribe', ['topic'=>'orders.*', 'user_id'=>'operator-2']),
		$post('release_presence', ['scope'=>'order:SO-1', 'lease_token'=>$lease['lease_token']]),
		$post('typing', ['thread_id'=>'thread-one', 'typing'=>'off']),
		$post('cleanup_expired', ['at'=>time()+60]),
	];
	foreach($commands as $request){
		$payload=json_decode($controller->collaborate($manager, $request)->content(), true, 512, JSON_THROW_ON_ERROR);
		$t->isTrue($payload['ok']);
	}

	$deniedManager=new PanelCollaborationManager(new PanelInMemoryCollaborationStore(), static fn(): array=>['allowed'=>false, 'reason'=>'Denied by policy.']);
	$t->same(403, $controller->collaborate($deniedManager, $post('create_thread', ['subject_type'=>'order', 'subject_id'=>'SO-2']))->status());
	$t->same(409, $controller->collaborate($manager, $post('heartbeat_presence', ['scope'=>'missing', 'lease_token'=>'wrong']))->status());
	$t->same(422, $controller->collaborate($manager, $post('comment', ['thread_id'=>'missing', 'body'=>'Comment']))->status());
})->tag('panel', 'platform-controller', 'collaboration', 'dispatch', 'exact-coverage')->maxMillis(2000);

test('platform controller exposes every authentication command without leaking one-time material', static function(Context $t): void {
	$manager=PanelAuthenticationManager::memory(str_repeat('e', 32), str_repeat('p', 24));
	$controller=(new PanelPlatformController())->csrf(static fn(): bool=>true)->authorize(static fn(): bool=>true);
	$post=static fn(string $operation, array $input=[]): array=>[
		'method'=>'POST', 'input'=>['operation'=>$operation]+$input, 'user'=>['id'=>'operator'],
	];

	$enrollment=$controller->authenticate($manager, $post('provision_totp', ['label'=>'Primary', 'recovery_codes'=>2]));
	$t->same(200, $enrollment->status());
	$t->same(422, $controller->authenticate($manager, $post('confirm_totp', ['id'=>'missing', 'code'=>'000000']))->status());
	$t->same(422, $controller->authenticate($manager, $post('verify_totp', ['code'=>'000000']))->status());
	$t->same(422, $controller->authenticate($manager, $post('use_recovery', ['code'=>'missing']))->status());

	$t->same(200, $controller->authenticate($manager, $post('disable_totp', ['id'=>'missing']))->status());
	$challenge=json_decode($controller->authenticate($manager, $post('begin_challenge', ['purpose'=>'Approve payout', 'method'=>'totp']))->content(), true, 512, JSON_THROW_ON_ERROR)['challenge'];
	$t->same(422, $controller->authenticate($manager, $post('verify_challenge', ['id'=>$challenge['id'], 'code'=>'000000']))->status());
	$t->same(200, $controller->authenticate($manager, $post('cancel_challenge', ['id'=>$challenge['id']]))->status());
	$t->same(0, json_decode($controller->authenticate($manager, $post('revoke_all_devices'))->content(), true, 512, JSON_THROW_ON_ERROR)['revoked']);
	$t->same(200, $controller->authenticate($manager, $post('revoke_session', ['id'=>'missing']))->status());
	$t->same(0, json_decode($controller->authenticate($manager, $post('revoke_all_sessions'))->content(), true, 512, JSON_THROW_ON_ERROR)['revoked']);
	$t->same(422, $controller->authenticate($manager, $post('unsupported'))->status());
	$t->same(422, $controller->authenticate($manager, $post('begin_challenge', ['method'=>'email', 'purpose'=>'Email verification']))->status());
})->tag('panel', 'platform-controller', 'authentication', 'dispatch', 'exact-coverage')->maxMillis(3000);

test('platform controller renders media security and developer first-party pages', static function(Context $t): void {
	$controller=(new PanelPlatformController())->authorize(static fn(): bool=>true);
	$request=['method'=>'GET','user'=>['id'=>'operator']];
	$t->same(200, $controller->media([],[],$request)->status());
	$t->same(200, $controller->security([],[],$request)->status());
	$t->same(200, $controller->developer(PanelManifestInspector::inspect(['type'=>'panel']),null,null,[],$request)->status());
})->tag('panel', 'platform-controller', 'pages', 'exact-coverage')->maxMillis(2000);
