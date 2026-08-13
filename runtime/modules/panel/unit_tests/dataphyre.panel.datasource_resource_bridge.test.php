<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\Column;
use Dataphyre\Panel\PanelDataSourceResourceQuery;
use Dataphyre\Panel\PanelDataSourceResourceBridge;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('data source bridge maps Panel request search filters sorts paging tenancy and authorization', static function(Context $t): void {
	$source=new PanelArrayDataSource([
		['id'=>1,'tenant_id'=>'a','name'=>'Alpha','status'=>'active'],
		['id'=>2,'tenant_id'=>'a','name'=>'Beta','status'=>'inactive'],
		['id'=>3,'tenant_id'=>'b','name'=>'Alpha other','status'=>'active'],
	],['search_fields'=>['name']]);
	$bridge=PanelDataSourceResourceBridge::using($source,['search_fields'=>['name'],'per_page'=>10]);
	$request=PanelRequest::fromArray(['query'=>['search'=>'alpha','filters'=>['status'=>'active'],'sort'=>'name','direction'=>'desc'],'tenant'=>'a']);
	$result=$bridge->query($request);
	$t->same(1,count($result->items()));
	$t->same(1,$result->items()[0]['id']);
	$t->same('a',$result->querySpec()->tenantKey());
	$t->same('resource',$result->querySpec()->meta()['surface']);
	$t->isTrue($bridge->manifest()['capabilities']['cursor']);
})->tag('panel','data','resource-bridge')->maxMillis(1000);

test('data source bridge binds existing Resource query factory without leaking adapter objects', static function(Context $t): void {
	$source=new PanelArrayDataSource([['id'=>1,'name'=>'Alpha'],['id'=>2,'name'=>'Beta']],['tenant_field'=>null]);
	$resource=PanelDataSourceResourceBridge::using($source,['default_sort'=>'id','default_direction'=>'desc'])->bind(Resource::make('orders'));
	$query=$resource->makeQuery(PanelRequest::fromArray(['query'=>[]]));
	$t->instanceOf(PanelDataSourceResourceQuery::class,$query);
	$page=$query->paginateRecords(1,25);
	$t->same([2,1],array_column($page->items(),'id'));
	$t->same('Beta',$page->items()[0]['name']);
	$t->same('Alpha',$query->findRecord(1)['name']);
	$t->same(2,count($query->getRecords()->items()));
})->tag('panel','data','resource-bridge','resource')->maxMillis(1000);

test('data source resource query preserves Resource tenant scopes and fails closed without required context', static function(Context $t): void {
	$source=new PanelArrayDataSource([
		['id'=>1,'tenant_id'=>'a','name'=>'Alpha'],
		['id'=>2,'tenant_id'=>'b','name'=>'Beta'],
	],['tenant_field'=>'tenant_id']);
	$bridge=PanelDataSourceResourceBridge::using($source);
	$scoped=$bridge->bind(Resource::make('scoped')->tenantScoped()->tenantUsing(static fn(): string=>'a'));
	$query=$scoped->makeQuery(PanelRequest::fromArray(['query'=>[]]));
	$t->instanceOf(PanelDataSourceResourceQuery::class,$query);
	$t->same([1],array_column($query->paginateRecords(1,25)->items(),'id'));
	$t->same(null,$query->findRecord(2));
	$missing=$bridge->bind(Resource::make('missing_tenant')->tenantScoped());
	$denied=$missing->makeQuery(PanelRequest::fromArray(['query'=>[]]));
	$t->same([],$denied->paginateRecords(1,25)->items());
})->tag('panel','data','resource-bridge','resource','tenant')->maxMillis(1000);

test('data source bridge honors real Panel table parameters before page two and preserves source metadata', static function(Context $t): void {
	$source=new PanelArrayDataSource([
		['id'=>1,'name'=>'Alpha Order','status'=>'active','amount'=>10],
		['id'=>2,'name'=>'Beta Order','status'=>'active','amount'=>20],
		['id'=>3,'name'=>'Gamma Order','status'=>'inactive','amount'=>30],
		['id'=>4,'name'=>'Delta Order','status'=>'active','amount'=>40],
		['id'=>5,'name'=>'Epsilon Order','status'=>'active','amount'=>50],
	],['name'=>'orders_source','tenant_field'=>null]);
	$resource=Resource::make('orders')
		->label('Order')
		->pluralLabel('Orders')
		->columns([
			Column::make('id')->sortable(),
			Column::make('name')->searchable()->sortable(),
			Column::make('status'),
			Column::make('amount'),
		])
		->filter(TableFilter::make('status','select')->options(['active'=>'Active','inactive'=>'Inactive']))
		->perPage(2);
	$resource=PanelDataSourceResourceBridge::using($source,[
		'aggregates'=>[['alias'=>'amount_sum','function'=>'sum','field'=>'amount']],
	])->bind($resource);
	PanelManager::flush();
	$manager=PanelManager::instance();
	$manager->register($resource);
	$request=PanelRequest::fromArray([
		'resource'=>'orders',
		'operation'=>'index',
		'query'=>[
			'q'=>'order',
			'status'=>'active',
			'sort'=>'name',
			'dir'=>'desc',
			'page'=>2,
			'per_page'=>2,
		],
	]);
	$result=$manager->dispatch($request);
	$data=$result->data();
	$sourceData=$data['data_source'];
	$t->same(200,$result->status());
	$t->same(2,$data['record_count']);
	$t->same(4,$data['total_count']);
	$t->same(2,$data['page']);
	$t->contains('Beta Order',$result->content());
	$t->contains('Alpha Order',$result->content());
	$t->notContains('Epsilon Order',$result->content());
	$t->same('orders_source',$sourceData['source']);
	$t->same(2,$sourceData['page']['offset']);
	$t->same(2,$sourceData['page']['returned']);
	$t->same(4,$sourceData['page']['total']);
	$t->same(120,$sourceData['aggregates']['amount_sum']);
	$t->same('array',$sourceData['metadata']['adapter']);
	$t->same(4,$data['table_state']['total_records']);
	$t->same($sourceData,$data['table_state']['meta']['data_source']);

	$pageOne=$manager->dispatch(PanelRequest::fromArray([
		'resource'=>'orders',
		'operation'=>'index',
		'query'=>['q'=>'order','status'=>'active','sort'=>'name','dir'=>'desc','page'=>1,'per_page'=>2],
	]));
	$cursor=$pageOne->data()['data_source']['page']['next_cursor'];
	$t->isTrue(is_string($cursor) && $cursor!=='');
	$cursorPage=$manager->dispatch(PanelRequest::fromArray([
		'resource'=>'orders',
		'operation'=>'index',
		'query'=>['q'=>'order','status'=>'active','sort'=>'name','dir'=>'desc','page'=>2,'per_page'=>2,'cursor'=>$cursor],
	]));
	$t->same(2,$cursorPage->data()['data_source']['page']['offset']);
	$t->contains('data-dp-panel-pagination="cursor"',$cursorPage->content());
	$t->contains('Beta Order',$cursorPage->content());
})->tag('panel','data','resource-bridge','manager','renderer','pagination')->maxMillis(2000);
