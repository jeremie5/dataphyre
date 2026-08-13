<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Http\Request as HttpRequest;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelRoute;
use Dataphyre\Panel\PanelRouteParser;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http','routing','mvc','panel']);

/**
 * Returns route cases for every native bulk, template-download, and record operation.
 *
 * @return array<string,array{segments:list<string>,identity:array<string,string>}>
 */
function dp_panel_native_navigation_route_cases(): array {
	$cases=[
		'import template download'=>[
			'segments'=>['orders','import_template'],
			'identity'=>['resource'=>'orders','operation'=>'import_template'],
		],
	];
	foreach([
		'bulk_export',
		'bulk_update',
		'bulk_transition',
		'bulk_duplicate',
		'bulk_restore',
		'bulk_delete',
		'bulk_force_delete',
	] as $operation){
		$cases[str_replace('_', ' ', $operation)]=[
			'segments'=>['orders',$operation],
			'identity'=>['resource'=>'orders','operation'=>$operation],
		];
	}

	$cases['record show canonical path']=[
		'segments'=>['orders','42'],
		'identity'=>['resource'=>'orders','operation'=>'show','record'=>'42'],
	];
	$cases['record show operation-first compatibility path']=[
		'segments'=>['orders','show','42'],
		'identity'=>['resource'=>'orders','operation'=>'show','record'=>'42'],
	];
	foreach([
		'edit',
		'update',
		'inline_update',
		'transition',
		'duplicate',
		'restore',
		'delete',
		'force_delete',
		'approval',
		'tag',
		'task',
		'note',
		'message',
		'attach',
	] as $operation){
		$identity=['resource'=>'orders','operation'=>$operation,'record'=>'42'];
		$label=str_replace('_', ' ', $operation);
		$cases[$label.' record-first path']=[
			'segments'=>['orders','42',$operation],
			'identity'=>$identity,
		];
		$cases[$label.' operation-first compatibility path']=[
			'segments'=>['orders',$operation,'42'],
			'identity'=>$identity,
		];
	}

	$cases['record action canonical path']=[
		'segments'=>['orders','42','action','approve'],
		'identity'=>['resource'=>'orders','operation'=>'action','record'=>'42','action'=>'approve'],
	];
	$cases['record action operation-first compatibility path']=[
		'segments'=>['orders','action','approve','42'],
		'identity'=>['resource'=>'orders','operation'=>'action','record'=>'42','action'=>'approve'],
	];
	$cases['record relation canonical path']=[
		'segments'=>['orders','42','relation','items'],
		'identity'=>['resource'=>'orders','operation'=>'relation','record'=>'42','relation'=>'items'],
	];
	$cases['record relation operation-first compatibility path']=[
		'segments'=>['orders','relation','42','items'],
		'identity'=>['resource'=>'orders','operation'=>'relation','record'=>'42','relation'=>'items'],
	];
	return $cases;
}

/**
 * Orders route identity fields consistently before strict assertions.
 *
 * @param array<string,mixed> $identity
 * @return array<string,string>
 */
function dp_panel_ordered_route_identity(array $identity): array {
	$ordered=[];
	foreach(['resource','operation','record','action','relation'] as $key){
		if(array_key_exists($key, $identity) && $identity[$key]!==null && $identity[$key]!==''){
			$ordered[$key]=(string)$identity[$key];
		}
	}
	return $ordered;
}

test('native Panel paths keep the same identity in the shared route parser, route facade, and HTTP request adapter',static function(Context $t): void {
	$route=$t->nonPublic(PanelRoute::class);
	$request=$t->nonPublic(PanelRequest::class);
	$t->isTrue(in_array('bulk_update',PanelRouteParser::operationNames(),true),'the shared vocabulary exposes bulk update');
	$t->same([],PanelRouteParser::infer(' / '),'an empty path has no route identity');
	$t->same(['resource'=>'orders','operation'=>'index'],PanelRouteParser::infer('/orders/list/'),'string paths normalize the list alias');
	$t->same(['resource'=>'orders','operation'=>'index'],PanelRouteParser::infer(['orders','table']),'table normalizes to index');
	$t->same(['resource'=>'orders','operation'=>'create'],PanelRouteParser::infer(['orders','new']),'new normalizes to create');
	$t->same(['resource'=>'orders','operation'=>'store'],PanelRouteParser::infer(['orders','save']),'save normalizes to store');
	foreach(dp_panel_native_navigation_route_cases() as $case=>$contract){
		$expected=$contract['identity'];
		$segments=$contract['segments'];
		$t->same($expected,dp_panel_ordered_route_identity(PanelRouteParser::infer($segments)),$case.' shared parser identity');
		$t->same($expected,dp_panel_ordered_route_identity($route->invoke('routeIdentity',$segments)),$case.' route identity');
		$t->same($expected,dp_panel_ordered_route_identity($request->invoke('inferRouteSegments',$segments)),$case.' request segment identity');
	}
})->tag('panel','routing','bulk','regression')->group('framework-coverage');

test('pretty Panel path identity wins over stale query operation state',static function(Context $t): void {
	$request=PanelRequest::fromHttpRequest(HttpRequest::create(
		'POST',
		'/admin/orders/bulk_update',
		['resource'=>'legacy-orders','operation'=>'show','record'=>'stale-record'],
		[],
		[],
		[],
		[],
		['panel_segments'=>['orders','bulk_update']],
	));

	$t->same('orders',$request->resourceName());
	$t->same('bulk_update',$request->operation());
	$t->same('show',$request->query('operation'),'the original query remains readable as request state');
})->tag('panel','request','routing','regression')->group('framework-coverage');

test('operation URLs preserve view filters without carrying stale route identity',static function(Context $t): void {
	$request=PanelRequest::fromArray([
		'resource'=>'orders',
		'operation'=>'action',
		'record'=>'42',
		'action'=>'approve',
		'relation'=>'items',
		'query'=>[
			'uri'=>'/admin/orders/42/action/approve',
			'resource'=>'orders',
			'operation'=>'action',
			'record'=>'42',
			'action'=>'approve',
			'relation'=>'items',
			'page'=>3,
			'__panel_partial'=>'modal',
			'q'=>'pending',
			'filters'=>['status'=>'review'],
		],
	]);

	$expected=['q'=>'pending','filters'=>['status'=>'review']];
	$t->same($expected,$t->nonPublic(PanelRenderer::class)->invoke('queryWithoutPage',$request));
	$t->same($expected,PanelRouteParser::withoutIdentityQuery(array_diff_key($request->query(),['page'=>true,'__panel_partial'=>true])));
})->tag('panel','renderer','routing','regression')->group('framework-coverage');

test('rendered export and import-template controls explicitly opt out of Ajax navigation',static function(Context $t): void {
	$resource=Resource::make('orders')
		->field(Field::make('name')->label('Name'))
		->importUsing(static fn(): bool=>true);
	$request=PanelRequest::fromArray([
		'method'=>'GET',
		'resource'=>'orders',
		'operation'=>'index',
		'query'=>['q'=>'pending'],
		'user'=>['id'=>7],
	]);
	$renderer=$t->nonPublic(PanelRenderer::class);
	$rendered=PanelContext::run([
		'url_builder'=>PanelRoute::urlBuilder('/admin'),
		'resource_exports'=>true,
		'resource_imports'=>true,
	],static fn(): array=>[
		'resource_exports'=>$renderer->invoke('exportButtonHtml',$resource,$request),
		'bulk_exports'=>$renderer->invoke('bulkExportButton',$resource,$request,'orders-bulk-form'),
		'import_form'=>PanelRenderer::importForm($resource,$request)->content(),
	]);

	$t->same(2,substr_count($rendered['resource_exports'],'data-dp-panel-no-ajax="1"'),'CSV and JSON resource exports are native navigation');
	$t->same(2,substr_count($rendered['bulk_exports'],'data-dp-panel-no-ajax="1"'),'CSV and JSON bulk exports are native form submissions');
	$t->contains('/admin/orders/import_template',$rendered['import_form']);
	$t->contains('href="/admin/orders/import_template" data-dp-panel-no-ajax="1"',$rendered['import_form']);
})->tag('panel','renderer','exports','imports','regression')->group('framework-coverage');

test('Ajax URL eligibility recognizes download operations in pretty paths as native navigation',static function(Context $t): void {
	$javascript=(string)(PanelRenderer::assetContent('panel.js')['content'] ?? '');

	$t->contains('var pathOperations=next.pathname.split("/")',$javascript);
	$t->contains('pathOperations.some(function(segment){return ["export","bulk_export","import_template"].indexOf(segment)!==-1;})',$javascript);
})->tag('panel','assets','ajax','downloads','regression')->group('framework-coverage');
