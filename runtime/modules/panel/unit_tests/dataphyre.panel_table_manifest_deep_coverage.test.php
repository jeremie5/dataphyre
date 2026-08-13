<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Action;
use Dataphyre\Panel\ActionGroup;
use Dataphyre\Panel\Column;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Panel\TableManifest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel table manifest normalizes comprehensive serialized table definitions',static function(Context $t): void {
	$definition=[
		'name'=>'orders','label'=>'Orders','description'=>'Order table','lazy'=>true,
		'columns'=>[
			'invalid',['name'=>''],['name'=>'total_amount','type'=>'money','sortable'=>true,'searchable'=>true,
				'toggleable'=>true,'visible_by_default'=>false,'conditional'=>true,'align'=>'right','group'=>'Money',
				'computed'=>true,'formatted'=>true,'copyable'=>true,'linked'=>true,'meta'=>['currency'=>'CAD']],
		],
		'filters'=>[
			'invalid',['name'=>''],['name'=>'amount','type'=>'number_range','column'=>'amount','options'=>['one'],
				'dynamic_options'=>true,'range'=>true,'has_predicate'=>true,'default'=>10,'meta'=>['unit'=>'CAD']],
		],
		'views'=>[
			'invalid',['name'=>''],['name'=>'open','default'=>true,'tone'=>'success','query'=>['status'=>'open'],
				'has_predicate'=>true,'has_badge_resolver'=>true,'meta'=>['source'=>'saved']],
			['name'=>'static_badge','badge'=>3],
		],
		'summaries'=>[
			'invalid',['name'=>''],['name'=>'total','type'=>'sum','column'=>'amount','tone'=>'primary',
				'computed'=>true,'formatted'=>true,'meta'=>['format'=>'money']],
		],
		'groups'=>[
			'invalid',['name'=>''],['name'=>'status','direction'=>'desc','default'=>true,'collapsible'=>true,'collapsed'=>true,
				'summaries'=>[['name'=>'rows']],'actions'=>[['label'=>'Open']],'meta'=>['one'=>1]],
		],
		'row_click'=>['enabled'=>true,'operation'=>'show','target'=>'_blank','modal'=>true,'dynamic_url'=>true,'action'=>'open'],
		'row_preview'=>['action'=>true,'fields'=>['id','status'],'dynamic'=>true],
		'row_attributes'=>['class'=>'row'],'row_attributes_dynamic'=>true,
		'default_per_page'=>50,'per_page_options'=>[10,25,50],'limit'=>100,'record_count'=>12,
		'default_sort'=>'created_at','default_sort_direction'=>'desc','meta'=>['base'=>true],
	];
	$manifest=TableManifest::from($definition,null,PanelRequest::fromArray(['query'=>[]]),['extra'=>true])->toArray();
	$t->same('table_manifest',$manifest['type']);
	$t->same('page_table',$manifest['kind']);
	$t->same('orders',$manifest['name']);
	$t->same(1,count($manifest['columns']));
	$t->same(1,count($manifest['filters']));
	$t->same(2,count($manifest['views']));
	$t->same(1,count($manifest['summaries']));
	$t->same(1,count($manifest['groups']));
	$t->same(7,count($manifest['columns']['total_amount']['capabilities']));
	$t->isTrue($manifest['row_behavior']['clickable']);
	$t->same(50,$manifest['pagination']['default_per_page']);
	$t->same('desc',$manifest['sort']['direction']);
	$t->isTrue($manifest['capabilities']['behavior']['row_preview']);
	$t->same(['base'=>true,'extra'=>true],$manifest['meta']);
	$t->same([], $manifest['state']);

	$resourceKind=TableManifest::from(['columns'=>[]])->toArray();
	$t->same('resource_table',$resourceKind['kind']);
	$t->same('table',$resourceKind['name']);
	$t->same('Table',$resourceKind['label']);
})->tag('panel','table-manifest','coverage')->group('framework-coverage');

test('panel table manifest describes live resource actions and state',static function(Context $t): void {
	$request=PanelRequest::fromArray(['operation'=>'index','query'=>['q'=>'alice','sort'=>'id','dir'=>'desc','page'=>2,'per_page'=>10]]);
	$group=ActionGroup::make('workflow')->actions([
		Action::make('approve')->label('Approve')->bulk(),
	]);
	$broken=Action::make('broken')->visibleUsing(static function(): never {
		throw new RuntimeException('visibility failed');
	});
	$resource=Resource::make('orders')
		->columns([Column::make('id')->sortable()->searchable()])
		->filters([TableFilter::make('status')])
		->actions([Action::make('review')->label('Review')->modal()->fields([]),$group,$broken]);
	$manifest=TableManifest::from($resource,null,$request,['surface'=>'test'])->toArray();
	$t->same('resource_table',$manifest['kind']);
	$t->same('orders',$manifest['source']['resource']);
	$t->notEmpty($manifest['actions']);
	$t->same('alice',$manifest['state']['query']);
	$t->same(2,$manifest['state']['page']);
	$t->isTrue($manifest['capabilities']['columns']['sortable']>0);
	$t->isTrue($manifest['operations']['custom_actions']>0);
	$t->contains('visibility failed',json_encode($manifest['actions']));

	$live=TableManifest::from($resource->resourceTable(),$resource,$request)->toArray();
	$t->same('orders',$live['source']['resource']);
})->tag('panel','table-manifest','coverage')->group('framework-coverage');

test('panel table manifest private operation and capability summaries cover flags',static function(Context $t): void {
	$actions=[
		'bulk'=>['interaction'=>['bulk'=>true,'has_form'=>true,'modal'=>true],'effects'=>['refresh_count'=>2,'event_count'=>1]],
		'plain'=>['interaction'=>['bulk'=>false,'has_form'=>false,'modal'=>false],'effects'=>[]],
	];
	$resource=[
		'imports'=>true,'bulk_updates'=>true,'duplicates'=>true,'deletes'=>true,'force_deletes'=>true,'restores'=>true,
		'transitions'=>[['name'=>'approve']],'status_field'=>'status','status_widgets'=>true,
		'tenant_scoped'=>true,'global_searchable'=>true,'per_page'=>40,
	];
	$operations=$t->nonPublic(TableManifest::class)->invoke('operations',$resource,$actions);
	$t->isTrue($operations['imports']);
	$t->same(1,$operations['transitions']);
	$t->same(1,$operations['bulk_actions']);
	$t->isTrue($operations['has_write_operations']);
	$t->isFalse($t->nonPublic(TableManifest::class)->invoke('operations',null,[])['has_write_operations']);

	$columns=['one'=>['searchable'=>true,'sortable'=>true,'toggleable'=>true,'computed'=>true,'formatted'=>true,'copyable'=>true,'linked'=>true]];
	$filters=['one'=>['range'=>true,'dynamic_options'=>true]];
	$views=['one'=>['has_badge'=>true]];
	$summaries=['one'=>['computed'=>true]];
	$groups=['one'=>['collapsible'=>true]];
	$definition=['row_click'=>['enabled'=>true],'row_preview'=>['action'=>true],'row_attributes'=>['class'=>'row']];
	$capabilities=$t->nonPublic(TableManifest::class)->invoke('capabilities',$definition,$columns,$filters,$views,$summaries,$groups,$actions,$resource,);
	$t->same(1,$capabilities['columns']['searchable']);
	$t->same(1,$capabilities['controls']['range_filters']);
	$t->isTrue($capabilities['behavior']['tenant_scoped']);
	$t->same(1,$capabilities['actions']['forms']);
	$t->same(3,$capabilities['actions']['effects']);
	$t->same(1,$t->nonPublic(TableManifest::class)->invoke('countByFlag',$columns,'searchable'));

	$pagination=$t->nonPublic(TableManifest::class)->invoke('pagination',[],['per_page'=>40]);
	$t->same(40,$pagination['default_per_page']);
	$t->same(null,$pagination['record_count']);
	$t->same('Order Status',$t->nonPublic(TableManifest::class)->invoke('humanize','order_status'));
	$t->same('Table',$t->nonPublic(TableManifest::class)->invoke('humanize',''));
})->tag('panel','table-manifest','coverage')->group('framework-coverage');

test('panel table manifest state summary handles absent and failing resource state',static function(Context $t): void {
	$plain=TableManifest::from(['name'=>'plain']);
	$t->same([],$t->nonPublic($plain)->invoke('stateSummary'));
	$resource=Resource::make('orders')->filter(
		TableFilter::make('status','select')->optionsUsing(static function(): never {
			throw new RuntimeException('options failed');
		})
	);
	$request=PanelRequest::fromArray(['query'=>['status'=>'open']]);
	$manifest=TableManifest::from($resource,null,$request);
	$state=$t->nonPublic($manifest)->invoke('stateSummary');
	$t->isTrue(is_array($state));
})->tag('panel','table-manifest','coverage')->group('framework-coverage');
