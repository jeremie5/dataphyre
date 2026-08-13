<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TableView;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel table view imports a comprehensive manifest definition',static function(Context $t): void {
	$view=TableView::fromArray([
		'name'=>'open-orders','label'=>'Open orders','default'=>true,'tone'=>'success','badge'=>12,
		'query'=>['status'=>'open'],'search'=>'Alice','columns'=>['id','status'],
		'filters'=>['enabled'=>true],'sort'=>['column'=>'created_at','direction'=>'desc'],
		'per_page'=>50,'density'=>'compact','meta'=>['source'=>'manifest'],
	]);
	$manifest=$view->toArray();
	$t->same('open-orders',$view->name());
	$t->same('Open orders',$manifest['label']);
	$t->isTrue($manifest['default']);
	$t->same('success',$manifest['tone']);
	$t->same(12,$manifest['badge']);
	$t->same('open',$manifest['query']['status']);
	$t->same('Alice',$manifest['query']['q']);
	$t->same(['id','status'],$manifest['query']['visible_columns']);
	$t->same('created_at',$manifest['query']['sort']);
	$t->same('desc',$manifest['query']['dir']);
	$t->same(50,$manifest['query']['per_page']);
	$t->same('compact',$manifest['query']['density']);
	$t->same(['source'=>'manifest'],$manifest['meta']);

	$visible=TableView::fromArray(['name'=>'visible','visible_columns'=>'id,status','sort'=>['created_at','asc']]);
	$t->same(['id','status'],$visible->queryDefaults()['visible_columns']);
	$t->same('asc',$visible->queryDefaults()['dir']);
})->tag('panel','table-view','coverage')->group('framework-coverage');

test('panel table view fluent helpers normalize aliases ranges and defaults',static function(Context $t): void {
	$view=TableView::make('workflow')
		->label(' Workflow ')->default(false)->tone('invalid')->badge('Static')
		->query([''=>'ignored','status'=>'open'])->queryDefault('tenant','north')->preset('page',2)->search(' search ')
		->columns(['id','status'],'customer, id',' ')
		->filters(['enabled'=>true,''=>'ignored'])
		->filter('category','retail')->filterValue('priority','high')->range('amount',10,20)->sort('created','invalid')
		->perPage(0)->density('comfortable')->meta(['one'=>1])->meta(['two'=>2]);
	$query=$view->queryDefaults();
	$t->same('neutral',$view->toArray()['tone']);
	$t->same('Static',$view->resolveBadge([],PanelRequest::fromArray([]),Resource::make('orders')));
	$t->same(['id','status','customer'],$query['visible_columns']);
	$t->same(10,$query['amount_from']);
	$t->same(20,$query['amount_to']);
	$t->same('retail',$query['category']);
	$t->same('asc',$query['dir']);
	$t->same(1,$query['per_page']);
	$t->same('comfortable',$query['density']);
	$t->same(['one'=>1,'two'=>2],$view->toArray()['meta']);

	$t->same([],TableView::make('x')->visibleColumns(' ','')->queryDefaults());
	$t->same([],TableView::make('x')->filters([''=>'ignored'])->queryDefaults());
	$t->same([],TableView::make('x')->filterValue('',1)->queryDefaults());
	$t->same([],TableView::make('x')->range('',1,2)->queryDefaults());
	$t->same([],TableView::make('x')->density('invalid')->queryDefaults());
	$t->same(250,TableView::make('x')->perPage(999)->queryDefaults()['per_page']);
	$t->same('danger',TableView::make('x')->tone('danger')->toArray()['tone']);
})->tag('panel','table-view','coverage')->group('framework-coverage');

test('panel table view evaluates predicates and dynamic badge context',static function(Context $t): void {
	$request=PanelRequest::fromArray(['operation'=>'index','query'=>['status'=>'open']]);
	$resource=Resource::make('orders');
	$t->isTrue(TableView::make('all')->matches(['status'=>'closed'],$request,$resource));
	$predicate=TableView::make('open')->filter(
		static fn(array $record,PanelRequest $request,Resource $resource,TableView $view): bool=>
			$record['status']===$request->query('status') && $resource->name()==='orders' && $view->name()==='open'
	);
	$t->isTrue($predicate->matches(['status'=>'open'],$request,$resource));
	$t->isFalse($predicate->matches(['status'=>'closed'],$request,$resource));

	$badge=TableView::make('open')->badge(
		static fn(array $records,PanelRequest $request,Resource $resource,TableView $view): string=>
			count($records).'-'.$request->operation().'-'.$resource->name().'-'.$view->name()
	);
	$t->same('2-index-orders-open',$badge->resolveBadge([1,2],$request,$resource));
	$t->isTrue($badge->toArray()['has_badge_resolver']);
	$t->same(null,$badge->toArray()['badge']);
	$cleared=$badge->badge(5);
	$t->isFalse($cleared->toArray()['has_badge_resolver']);
	$t->same(5,$cleared->resolveBadge([],$request,$resource));
})->tag('panel','table-view','coverage')->group('framework-coverage');
