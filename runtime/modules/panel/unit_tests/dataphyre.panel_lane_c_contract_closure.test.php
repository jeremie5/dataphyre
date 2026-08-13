<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelArrayRelationAdapter;
use Dataphyre\Panel\PanelImpersonationSession;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPlatformAssets;
use Dataphyre\Panel\PanelQualityGate;
use Dataphyre\Panel\PanelRelationWorkspace;
use Dataphyre\Panel\PanelRelationWorkspaceCommand;
use Dataphyre\Panel\PanelRendererAssets;
use Dataphyre\Panel\PanelSecurityContext;
use Dataphyre\Panel\PanelSecurityPolicy;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('relation scenarios detach normalize reject invalid ordering and expose receipts', static function(Context $t): void {
	$adapter=new PanelArrayRelationAdapter([
		['id'=>'one','name'=>'One'],
		['id'=>'two','name'=>'Two'],
		['id'=>'three','name'=>'Three'],
	], ['parent'=>['one'=>[], 'two'=>[], 'three'=>[]]]);
	$adapter->detach('parent','two');
	$t->same(['one','three'],array_column($adapter->records('parent'),'id'));
	$t->same([0,1],array_column($adapter->records('parent'),'_position'));
	$t->throws(static fn()=>$adapter->detach('parent','missing'),OutOfBoundsException::class);
	$t->throws(static fn()=>$adapter->reorder('parent',['one']),DomainException::class);
	$t->same(['related','links','versions'],array_keys($adapter->jsonSerialize()));

	$workspace=PanelRelationWorkspace::make('items','parent',$adapter);
	$command=PanelRelationWorkspaceCommand::make('detach','three',[],[
		'idempotency_key'=>'detach-three',
		'metadata'=>['source'=>'contract-closure'],
	]);
	$t->same(['source'=>'contract-closure'],$command->metadata());
	$detached=$workspace->execute($command);
	$t->same('committed',$detached->status());
	$t->same([],$detached->errors());
	$t->same(['one'],array_column($workspace->records(),'id'));
})->tag('panel','relations','coverage')->group('panel-lane-c');

test('security scenarios expose normalized context resolver evidence and impersonation envelopes', static function(Context $t): void {
	$context=PanelSecurityContext::fromArray([
		'actor_id'=>'operator',
		'roles'=>[' Operator ','auditor'],
		'permissions'=>['orders.read','orders.update'],
		'tenant_id'=>'tenant-a',
		'attributes'=>['region'=>'ca'],
	]);
	$t->same(['operator','auditor'],$context->roles());
	$t->same(['orders.read','orders.update'],$context->permissions());

	$denied=PanelSecurityPolicy::make('orders.review')
		->resolve(static fn(): array=>['Review window is closed.'])
		->evaluate($context);
	$t->isTrue($denied->denied());
	$t->same(['Review window is closed.'],$denied->reasons());
	$fallback=PanelSecurityPolicy::make('orders.review')->resolve(static fn(): false=>false);
	$t->contains('Custom policy denied',$fallback->evaluate($context)->reasons()[0]);
	$t->isTrue($fallback->jsonSerialize()['custom_resolver']);

	$admin=PanelSecurityContext::make('admin',['permissions'=>['panel.impersonate']]);
	$session=PanelImpersonationSession::start($admin,'operator','Coverage review');
	$t->same('admin',$session->jsonSerialize()['impersonator_id']);
})->tag('panel','security','coverage')->group('panel-lane-c');

test('platform assets and quality gate expose their complete serialized contracts', static function(Context $t): void {
	$asset=PanelRendererAssets::assetContent('panel-platform.css');
	$t->same('text/css; charset=UTF-8',$asset['content_type'] ?? null);
	$t->same(PanelPlatformAssets::stylesheet(),$asset['content'] ?? null);
	$gate=PanelQualityGate::from('<html lang="en"><body dir="ltr"><main><h1>Ready</h1></main></body></html>',[
		'require_language'=>true,
		'require_direction'=>true,
	]);
	$t->isTrue($gate->jsonSerialize()['passed']);
})->tag('panel','assets','quality','coverage')->group('panel-lane-c');

test('platform page facade renders preferences and authentication with local services', static function(Context $t): void {
	$disabled=array_fill_keys([
		'operations','data','workflows','automation','notifications','media','localization',
		'collaboration','relations','security','development','extensions',
	],false);
	$platform=PanelPlatform::defaults($disabled+[
		'state_root'=>$t->workspace('panel-lane-c-platform')->root(),
		'authentication'=>[
			'encryption_key'=>str_repeat('e',32),
			'pepper'=>str_repeat('p',32),
		],
		'preferences'=>[],
		'platform'=>['authorize'=>static fn():bool=>true],
	]);
	$request=['method'=>'GET','user'=>['id'=>'operator']];
	$t->contains('Workspace preferences',$platform->preferencesPage('operator','default',null,[],$request)->content());
	$t->contains('Authentication',$platform->authenticationPage('operator',[],$request)->content());
})->tag('panel','platform','coverage')->group('panel-lane-c');
