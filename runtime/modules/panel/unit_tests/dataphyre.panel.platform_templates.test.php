<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelAccessibilityAudit;
use Dataphyre\Panel\PanelArrayRelationAdapter;
use Dataphyre\Panel\PanelManifestDiff;
use Dataphyre\Panel\PanelManifestInspector;
use Dataphyre\Panel\PanelCollaborationManager;
use Dataphyre\Panel\PanelInMemoryCollaborationStore;
use Dataphyre\Panel\PanelInMemoryPreferenceStore;
use Dataphyre\Panel\PanelOperationRecord;
use Dataphyre\Panel\PanelPackageRegistryCatalog;
use Dataphyre\Panel\PanelPlatformTemplate;
use Dataphyre\Panel\PanelQualityMatrix;
use Dataphyre\Panel\PanelRelationWorkspace;
use Dataphyre\Panel\PanelWorkspacePreferences;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('operations center renders semantic progress status actions and responsive table labels', static function(Context $t): void {
	$running=PanelOperationRecord::make('import','Import sellers',['id'=>'import-1','total'=>10])->start('worker')->progress(4,10,'Validating',4,0);
	$failed=PanelOperationRecord::make('export','Export orders',['id'=>'export-1','max_attempts'=>2])->start('worker')->fail('Storage unavailable');
	$result=PanelPlatformTemplate::operations([$running,$failed],['action_url'=>'/panel/operations']);
	$t->contains('Operations center',$result->content());
	$t->contains('value="40"',$result->content());
	$t->contains('data-label="Progress"',$result->content());
	$t->contains('value="pause"',$result->content());
	$t->contains('value="retry"',$result->content());
	$t->isTrue(PanelAccessibilityAudit::from($result)->passed());
})->tag('panel','platform','operations','template')->maxMillis(1000);

test('workflow center renders transition controls and immutable history', static function(Context $t): void {
	$result=PanelPlatformTemplate::workflows([[
		'id'=>'approval-1','name'=>'Refund approval','state'=>'pending','description'=>'Requires finance approval',
		'available_transitions'=>[['name'=>'approve'],['name'=>'reject']],
		'history'=>[['type'=>'submitted','timestamp'=>'2026-07-12T00:00:00Z']],
	]],['transition_url'=>'/panel/workflows']);
	$t->contains('Refund approval',$result->content());
	$t->contains('value="approve"',$result->content());
	$t->contains('dp-panel-timeline',$result->content());
	$t->isTrue(PanelAccessibilityAudit::from($result)->passed());
})->tag('panel','platform','workflow','template')->maxMillis(1000);

test('relationship workspace template exposes attach detach brick layout and breadcrumb context', static function(Context $t): void {
	$adapter=new PanelArrayRelationAdapter([1=>['id'=>1,'name'=>'Alpha'],2=>['id'=>2,'name'=>'Beta']],['order-1'=>[1=>[]]]);
	$workspace=PanelRelationWorkspace::make('line_items','order-1',$adapter)->breadcrumbs([['label'=>'Orders','url'=>'/orders'],['label'=>'Order 1','url'=>'/orders/1']]);
	$result=PanelPlatformTemplate::relations($workspace,['operation_url'=>'/relations']);
	$t->contains('Relationship workspace',$result->content());
	$t->contains('data-dp-display="brick"',$result->content());
	$t->contains('value="attach"',$result->content());
	$t->contains('value="detach"',$result->content());
	$t->isTrue(PanelAccessibilityAudit::from($result)->passed());
})->tag('panel','platform','relations','template')->maxMillis(1000);

test('notification and media centers render durable actions accessible upload controls and masonry', static function(Context $t): void {
	$notifications=PanelPlatformTemplate::notifications(['items'=>[['id'=>'n1','title'=>'Assigned','body'=>'Order assigned to you','created_at'=>'now','read_at'=>null]]],['action_url'=>'/notifications']);
	$t->contains('data-unread="1"',$notifications->content());
	$t->contains('Mark read',$notifications->content());
	$t->isTrue(PanelAccessibilityAudit::from($notifications)->passed());
	$media=PanelPlatformTemplate::media(['items'=>[['id'=>'m1','name'=>'Invoice','mime'=>'application/pdf','bytes'=>200,'status'=>'ready']]],['upload_url'=>'/media']);
	$t->contains('enctype="multipart/form-data"',$media->content());
	$t->contains('data-dp-display="masonry"',$media->content());
	$t->isTrue(PanelAccessibilityAudit::from($media)->passed());
	$unsafe=PanelPlatformTemplate::media(['items'=>[['id'=>'m2','name'=>'Unsafe','url'=>'javascript:alert(1)']]],['upload_url'=>'javascript:alert(2)']);
	$t->notContains('javascript:',$unsafe->content());
})->tag('panel','platform','notifications','media','template')->maxMillis(1000);

test('package catalog pagination preserves validated search filters and availability choices',static function(Context $t):void{
	$result=PanelPlatformTemplate::packages(PanelPackageRegistryCatalog::empty('example_registry'),[
		'query'=>'workflow',
		'limit'=>5,
		'filters'=>[
			'status'=>['stable'],
			'include_yanked'=>true,
		],
		'facets'=>['status'=>['stable'=>2]],
		'packages'=>[],
		'total'=>2,
		'next_cursor'=>'next-page-token',
	],['base_url'=>'/panel/packages']);
	$t->contains('dp-package-catalog-pagination',$result->content());
	$t->contains('cursor=next-page-token',$result->content());
	$t->contains('status%5B0%5D=stable',$result->content());
	$t->contains('include_yanked=1',$result->content());
})->tag('panel','platform','packages','pagination','template')->maxMillis(1000);

test('workspace preferences render normalized appearance saved views pins and recent activity',static function(Context $t):void{
	$workspace=new PanelWorkspacePreferences(new PanelInMemoryPreferenceStore(),'operator');
	$workspace->appearance('glass','compact','fr-CA','ltr');
	$workspace->saveTableView('orders','attention',['presentation'=>'brick','filters'=>['status'=>'attention']]);
	$workspace->pin('order','SO-1');$workspace->touchRecent('seller','seller-1');
	$result=PanelPlatformTemplate::preferences($workspace,['action_url'=>'/preferences']);
	$t->contains('Workspace preferences',$result->content());$t->contains('value="glass" selected',$result->content());
	$t->contains('Attention',$result->content());$t->contains('SO-1',$result->content());$t->contains('seller-1',$result->content());
	$t->isTrue(PanelAccessibilityAudit::from($result)->passed());
})->tag('panel','platform','preferences','template')->maxMillis(1000);

test('collaboration workspace renders contextual threads comments subscriptions and receipt evidence',static function(Context $t):void{
	$manager=new PanelCollaborationManager(new PanelInMemoryCollaborationStore());
	$thread=$manager->createThread('operator','order','SO-1','Risk review');
	$manager->comment($thread['id'],'reviewer','Please verify the address.',['operator']);
	$manager->subscribe('orders.risk','operator',['database','email'],'immediate');
	$result=PanelPlatformTemplate::collaboration($manager,['action_url'=>'/collaboration','subject_type'=>'order','subject_id'=>'SO-1','user_id'=>'operator']);
	$t->contains('Collaboration workspace',$result->content());$t->contains('Risk review',$result->content());
	$t->contains('Please verify the address.',$result->content());$t->contains('orders.risk',$result->content());$t->contains('Verified',$result->content());
	$t->isTrue(PanelAccessibilityAudit::from($result)->passed());
})->tag('panel','platform','collaboration','template')->maxMillis(1000);

test('security and developer consoles render explainable evidence diffs and quality plans', static function(Context $t): void {
	$security=PanelPlatformTemplate::security(['tenant_isolation'=>['passed'=>false,'issues'=>[['type'=>'cross_tenant']]],'audit_chain'=>['verified'=>true]]);
	$t->contains('Security console',$security->content());
	$t->contains('Attention',$security->content());
	$t->contains('dp-panel-code',$security->content());
	$inspection=PanelManifestInspector::inspect(['type'=>'panel','resources'=>[['name'=>'orders']]]);
	$diff=PanelManifestDiff::between(['version'=>1],['version'=>2]);
	$matrix=PanelQualityMatrix::make('one','/one',['viewport'=>[['width'=>800,'height'=>600]]]);
	$developer=PanelPlatformTemplate::developer($inspection,$diff,$matrix);
	$t->contains('Panel developer tools',$developer->content());
	$t->contains('Contract diff',$developer->content());
	$t->contains('48 browser cases',$developer->content());
	$t->isTrue(PanelAccessibilityAudit::from($developer)->passed());
})->tag('panel','platform','security','developer','template')->maxMillis(3000);
