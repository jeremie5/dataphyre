<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelPermissionBridge;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTenant;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\SearchManifest;
use Dataphyre\Panel\TenantManifest;
use Dataphyre\Panel\Widget;
use Dataphyre\Panel\WidgetManifest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

test('panel remaining tenant search and widget manifest branches are executable',static function(Context $t): void {
	$t->isTrue(PanelPermissionBridge::allows('panel.coverage.view'));
	$resource=[
		'name'=>'orders',
		'tenant'=>['scoped'=>true,'field'=>'tenant_id','required'=>true,'resolves'=>true,'custom_scope'=>true],
		'search'=>['global_searchable'=>true,'columns'=>['name']],
		'data'=>['queryable'=>true],
		'policies'=>['authorizes'=>true],
	];
	$tenant=TenantManifest::from(['orders'=>$resource,'unscoped'=>['name'=>'unscoped','tenant'=>['scoped'=>false]],'ignored'=>'scalar'])->toArray();
	$t->same('tenant_id',$tenant['resources']['orders']['field']);
	$t->same('Orders',$tenant['resources']['orders']['label']);
	$t->same('Tenant resource',TenantManifest::from([''=>array_replace($resource,['name'=>''])])->toArray()['resources']['']['label']);
	$t->same('tenant_manifest',TenantManifest::from(['resources'=>['orders'=>$resource]])->toArray()['type']);
	$t->same('tenant_manifest',TenantManifest::from(null)->toArray()['type']);
	$t->same('request-tenant',TenantManifest::from([],PanelRequest::fromArray(['tenant'=>'request-tenant']))->toArray()['current']);

	$tenantManager=new PanelManager();
	$tenantManager->register(Resource::make('tenant-orders')->tenantScoped());
	$tenantManager->registerTenant(PanelTenant::make('north')->url('/north'));
	$tenantManager->tenantMembershipsUsing(static fn(): array=>['north']);
	$managed=TenantManifest::from($tenantManager,PanelRequest::fromArray(['tenant'=>'north']))->toArray();
	$t->same('north',$managed['current']);
	$t->same('tenant-orders',$managed['resources']['tenant-orders']['name']);
	$t->same(1,count($managed['registry']['tenants']));

	$direct=SearchManifest::from(['orders'=>$resource],null,null,0)->toArray();
	$t->same(1,$direct['provider_count']);
	$t->same('search_manifest',SearchManifest::from(null)->toArray()['type']);

	$t->same('search_manifest',SearchManifest::from([],null,'needle',3)->toArray()['type']);
	$sample=$t->nonPublic(SearchManifest::from([]))->invoke('normalizeResults',['scalar result']);
	$t->same('scalar result',$sample[0]['title']);

	$widget=WidgetManifest::from([
		'name'=>'coverage-chart','type'=>'chart','meta'=>[
			'datasets'=>['invalid',['label'=>'Callable','values'=>[static fn(): int=>1,2]]],
			'data'=>[1,2,'invalid'],
			'dynamic'=>static fn(): string=>'value',
		],
	])->toArray();
	$t->same(2,$widget['chart']['dataset_count']);
	$t->isTrue($widget['chart']['data_dynamic']);

	$flat=WidgetManifest::from(['name'=>'flat','type'=>'chart','meta'=>['datasets'=>['invalid'],'data'=>[1,2,'x']]])->toArray();
	$t->same(2,$flat['chart']['point_count']);

	$broken=Widget::make('broken-chart','chart')->meta(['chart_type'=>static fn(): stdClass=>new stdClass()]);
	$warning=WidgetManifest::from($broken,null,[],true)->toArray();
	$t->same('Unavailable',$warning['data']['resolved_value']);
	$t->isTrue($warning['data']['has_error']);
})->tag('panel','panel-remaining-manifests-exact')->group('framework-coverage');
