<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelCallbackDataSource;
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelDataSourceResourceBridge;
use Dataphyre\Panel\PanelQueryBetween;
use Dataphyre\Panel\PanelQueryCapabilities;
use Dataphyre\Panel\PanelQueryComparison;
use Dataphyre\Panel\PanelQueryExpressionCodec;
use Dataphyre\Panel\PanelQueryGroup;
use Dataphyre\Panel\PanelQueryIn;
use Dataphyre\Panel\PanelQueryNull;
use Dataphyre\Panel\PanelQueryPath;
use Dataphyre\Panel\PanelQueryRelation;
use Dataphyre\Panel\PanelQueryScopeException;
use Dataphyre\Panel\PanelQueryScopeGuard;
use Dataphyre\Panel\PanelQueryScopeManifest;
use Dataphyre\Panel\PanelQuerySort;
use Dataphyre\Panel\PanelQueryUrlCodec;
use Dataphyre\Panel\PanelQueryValue;
use Dataphyre\Panel\PanelUnsupportedQueryException;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\RelationManager;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('query AST nodes normalize deterministically and round trip through the public codec', static function(Context $t): void {
	$path=PanelQueryPath::make('profile.display-name');
	$t->same('profile', $path->head());
	$t->same('display-name', (string)$path->tail());
	$t->same('user.profile.display-name', (string)$path->prefixed('user'));
	$t->same('panel_query_path', $path->jsonSerialize()['type']);

	$comparison=PanelQueryComparison::make('score', '>=', 8);
	$t->same(['a'=>1,'b'=>2], PanelQueryComparison::make('metadata','eq',['b'=>2,'a'=>1])->value());
	$null=PanelQueryNull::make('deleted_at', true);
	$between=PanelQueryBetween::make('score', 8, 10);
	$membership=PanelQueryIn::make('status', ['active','review']);
	$group=PanelQueryGroup::all($comparison, PanelQueryGroup::all($null, $membership), $comparison);
	$t->instanceOf(PanelQueryGroup::class, $group);
	$t->same(3, count($group->children()));
	$t->same(['gte','not_null','in','and'], array_values(array_unique(array_merge($comparison->operators(), $null->operators(), $membership->operators(), ['and']))));
	$t->same($group->jsonSerialize(), PanelQueryExpressionCodec::fromArray($group->jsonSerialize())->jsonSerialize());
	$t->same([$between->jsonSerialize()], [PanelQueryExpressionCodec::fromLegacyFilter('score', 'between', [8,10])->jsonSerialize()]);

	$relation=PanelQueryRelation::make('orders.items', PanelQueryComparison::make('sku', '=', 'A-1'));
	$t->same('orders', $relation->relation());
	$t->instanceOf(PanelQueryRelation::class, $relation->expression());
	$t->same(['orders.items.sku'], $relation->fields());
	$t->same($relation->jsonSerialize(), PanelQueryExpressionCodec::fromArray($relation->jsonSerialize())->jsonSerialize());

	$t->throws(static fn()=>PanelQueryPath::make('../secret'), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQueryPath::make(implode('.', array_fill(0, 13, 'x'))), LengthException::class);
	$t->throws(static fn()=>PanelQueryComparison::make('id', 'raw', 1), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQueryIn::make('id', ['bad'=>'map']), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQueryGroup::make('xor', [$comparison]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQueryRelation::make('items', $comparison, 'sometimes'), InvalidArgumentException::class);
})->tag('panel','query','ast','dto')->maxMillis(1000);

test('PanelDataQuery keeps legacy methods while making typed expressions and safe sorts canonical', static function(Context $t): void {
	$legacy=PanelDataQuery::make()->where('status','active')->orWhere('score','gte',8)->where('deleted_at','is_null')->sort('score','DESC','last');
	$t->instanceOf(PanelQueryGroup::class, $legacy->expression());
	$t->same(['and','or','and'], array_column($legacy->filterList(), 'boolean'));
	$t->same('last', $legacy->sortNodes()[0]->nulls());
	$t->same($legacy->jsonSerialize(), PanelDataQuery::fromArray($legacy->jsonSerialize())->jsonSerialize());

	$explicit=PanelDataQuery::make()->whereExpression(PanelQueryGroup::any(
		PanelQueryBetween::make('score', 4, 9, true),
		PanelQueryRelation::make('orders', PanelQueryIn::make('status', ['paid']))
	))->sortBy(PanelQuerySort::make('profile.name','asc','first'));
	$t->same([], $explicit->filterList());
	$t->same('relation', $explicit->expression()->jsonSerialize()['children'][1]['type']);
	$t->same('profile.name', $explicit->sortList()[0]['field']);
	$t->same($explicit->jsonSerialize(), PanelDataQuery::fromArray($explicit->jsonSerialize())->jsonSerialize());
	$nonFlat=PanelDataQuery::make()->whereExpression(PanelQueryGroup::all(
		PanelQueryComparison::make('a','eq',1),
		PanelQueryGroup::any(PanelQueryComparison::make('b','eq',2), PanelQueryComparison::make('c','eq',3))
	))->where('d',4);
	$t->same([], $nonFlat->filterList());
	$smuggled=$legacy->jsonSerialize();
	$smuggled['filters'][0]['field']='different';
	$t->throws(static fn()=>PanelDataQuery::fromArray($smuggled), InvalidArgumentException::class);

	$t->throws(static fn()=>PanelQuerySort::make('id','sideways'), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQuerySort::make('id','asc','middle'), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataQuery::make()->whereExpression(PanelQueryComparison::make('id','eq',1),'xor'), InvalidArgumentException::class);
})->tag('panel','query','compatibility','sort')->maxMillis(1000);

test('query URL state round trips deterministically and never transports protected scope metadata', static function(Context $t): void {
	$query=PanelDataQuery::make()
		->whereExpression(PanelQueryGroup::all(PanelQueryComparison::make('status','eq','open'), PanelQueryNull::make('deleted_at')))
		->sort('created_at','desc','last')->search('Ada',['name'])->select(['id','name'])->include(['orders'])
		->offset(40)->limit(20)->tenant('north')->authorization(['secret'=>'no'])->metadata(['request_id'=>'no'])->aggregate('rows','count');
	$encoded=PanelQueryUrlCodec::encode($query);
	$decoded=PanelQueryUrlCodec::decode($encoded);
	$t->same($query->urlState(), $decoded->urlState());
	$t->same(null, $decoded->tenantKey());
	$t->same([], $decoded->authorizationMetadata());
	$t->same([], $decoded->meta());
	$t->same([], $decoded->aggregateList());
	$t->same([PanelQueryUrlCodec::PARAMETER=>$encoded], PanelQueryUrlCodec::toQuery($query));
	$t->same($decoded->urlState(), PanelQueryUrlCodec::fromQuery([PanelQueryUrlCodec::PARAMETER=>$encoded])->urlState());

	$legacy=PanelQueryUrlCodec::decode(json_encode(['filters'=>['status'=>'open'],'sort'=>'id','dir'=>'desc','q'=>'needle'], JSON_THROW_ON_ERROR));
	$t->same('status', $legacy->filterList()[0]['field']);
	$t->same('desc', $legacy->sortList()[0]['direction']);
	$t->same('needle', $legacy->searchTerm());
	$t->same('2.0', PanelQueryUrlCodec::LEGACY_FILTERS_DEPRECATED_SINCE);
	$t->throws(static fn()=>PanelQueryUrlCodec::decode('not-valid-state'), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQueryUrlCodec::decode(str_repeat('x', PanelQueryUrlCodec::MAX_ENCODED_BYTES+1)), LengthException::class);
	$t->throws(static fn()=>PanelQueryUrlCodec::decode(['type'=>'panel_query_url','version'=>99]), InvalidArgumentException::class);
})->tag('panel','query','url','migration')->maxMillis(1000);

test('array adapter evaluates grouped nested resource expressions and explicit null ordering', static function(Context $t): void {
	$source=new PanelArrayDataSource([
		['id'=>1,'score'=>null,'status'=>'open','items'=>[['tenant_id'=>'north','sku'=>'A-1','qty'=>2],['tenant_id'=>'north','sku'=>'B-1','qty'=>5]]],
		['id'=>2,'score'=>7,'status'=>'closed','items'=>[['tenant_id'=>'south','sku'=>'A-1','qty'=>9]]],
		['id'=>3,'score'=>3,'status'=>'open','items'=>[]],
	], ['tenant_field'=>null]);
	$expression=PanelQueryGroup::any(
		PanelQueryRelation::make('items', PanelQueryGroup::all(PanelQueryComparison::make('sku','eq','A-1'), PanelQueryBetween::make('qty',1,3))),
		PanelQueryGroup::all(PanelQueryComparison::make('status','eq','open'), PanelQueryComparison::make('score','lt',5))
	);
	$t->same([1,3], array_column($source->query(PanelDataQuery::make()->whereExpression($expression)->sort('id'))->items(),'id'));
	$t->same([3], array_column($source->query(PanelDataQuery::make()->whereExpression(PanelQueryRelation::make('items', PanelQueryComparison::make('qty','gt',3), 'none')))->items(),'id'));
	$t->same([1,2,3], array_column($source->query(PanelDataQuery::make()->sort('score','desc','first'))->items(),'id'));
	$t->same([2,3,1], array_column($source->query(PanelDataQuery::make()->sort('score','desc','last'))->items(),'id'));
	$t->same([1,2,3], array_column($source->query(PanelDataQuery::make()->where('score','not_between',[4,6])->sort('id'))->items(),'id'));
})->tag('panel','query','array','nested')->maxMillis(1000);

test('adapter capability negotiation rejects unsupported operators relations groups sorts and depth before execution', static function(Context $t): void {
	$calls=0;
	$source=new PanelCallbackDataSource(static function()use(&$calls): array { $calls++; return []; }, null, 'limited', [
		'operators'=>['eq'], 'groups'=>['and'], 'relations'=>false, 'relation_depth'=>0, 'sorts'=>false, 'sort_nulls'=>[],
	]);
	$t->throws(static fn()=>$source->query(PanelDataQuery::make()->where('name','contains','ada')), PanelUnsupportedQueryException::class);
	$t->throws(static fn()=>$source->query(PanelDataQuery::make()->whereExpression(PanelQueryRelation::make('items', PanelQueryComparison::make('id','eq',1)))), PanelUnsupportedQueryException::class);
	$t->throws(static fn()=>$source->query(PanelDataQuery::make()->sort('id')), PanelUnsupportedQueryException::class);
	$t->same(0, $calls);
	$source->query(PanelDataQuery::make()->where('id','eq',1));
	$t->same(1, $calls);

	$manifest=PanelQueryCapabilities::fromArray($source->capabilities())->jsonSerialize();
	$t->same('panel_query_capabilities', $manifest['type']);
	$t->same(['eq'], $manifest['operators']);
	$t->isFalse(PanelQueryCapabilities::fromArray(PanelQueryCapabilities::legacy('legacy'))->jsonSerialize()['query_expression']);
	try{ $source->query(PanelDataQuery::make()->where('id','neq',1)); }
	catch(PanelUnsupportedQueryException $exception){
		$t->same(['operator:neq'], $exception->unsupported());
		$t->same('panel_unsupported_query', $exception->jsonSerialize()['type']);
	}
})->tag('panel','query','capabilities','security')->maxMillis(1000);

test('resource bridge authorizes every relation hop and injects nested tenant scope', static function(Context $t): void {
	$items=Resource::make('items')->tenantScoped('tenant_id', true);
	$t->same(['scoped'=>true,'field'=>'tenant_id','required'=>true], $items->tenantScopeDefinition());
	$orders=Resource::make('orders')->relation(RelationManager::make('items')->relatedResource('items')->authorize(static fn(): bool=>true));
	$resolver=static fn(string $name): ?Resource=>$name==='items' ? $items : null;
	$source=new PanelArrayDataSource([
		['id'=>1,'items'=>[['tenant_id'=>'north','status'=>'open'],['tenant_id'=>'south','status'=>'closed']]],
		['id'=>2,'items'=>[['tenant_id'=>'south','status'=>'open']]],
	], ['tenant_field'=>null]);
	$bridge=PanelDataSourceResourceBridge::using($source, ['nested_resource_resolver'=>$resolver]);
	$ast=PanelDataQuery::make()->whereExpression(PanelQueryRelation::make('items', PanelQueryComparison::make('status','eq','open')));
	$request=PanelRequest::fromArray(['tenant'=>'north','query'=>[PanelQueryUrlCodec::PARAMETER=>PanelQueryUrlCodec::encode($ast)]]);
	$result=$bridge->query($request, [], $orders);
	$t->same([1], array_column($result->items(),'id'));
	$t->same(null, $result->querySpec()->tenantKey());
	$scope=$result->querySpec()->meta()['nested_scope'];
	$t->same(['items'], $scope['paths']);
	$t->isTrue($scope['checks'][0]['tenant_applied']);
	$t->same('tenant_id', $scope['checks'][0]['tenant_field']);
	$t->isTrue($bridge->manifest()['capabilities']['nested_resource_scope']);
	$t->same('dp_query', $bridge->manifest()['query_contract']['url_parameter']);
	$allAst=PanelDataQuery::make()->whereExpression(PanelQueryGroup::all(
		PanelQueryComparison::make('id','eq',1),
		PanelQueryRelation::make('items', PanelQueryComparison::make('status','eq','open'), 'all')
	));
	$allRequest=PanelRequest::fromArray(['tenant'=>'north','query'=>[PanelQueryUrlCodec::PARAMETER=>PanelQueryUrlCodec::encode($allAst)]]);
	$allResult=$bridge->query($allRequest, [], $orders);
	$t->same([1], array_column($allResult->items(),'id'));
	$relationNode=$allResult->querySpec()->expression()->children()[1];
	$t->instanceOf(PanelQueryRelation::class, $relationNode);
	$t->notNull($relationNode->scope());
	$t->same('comparison', $relationNode->jsonSerialize()['scope']['type']);

	$missingTenant=PanelRequest::fromArray(['query'=>[PanelQueryUrlCodec::PARAMETER=>PanelQueryUrlCodec::encode($ast)]]);
	$t->throws(static fn()=>$bridge->querySpec($missingTenant, [], $orders), PanelQueryScopeException::class);
	$unknown=Resource::make('orders');
	$t->throws(static fn()=>$bridge->querySpec($request, ['expression'=>PanelQueryRelation::make('items', PanelQueryComparison::make('id','eq',1))], $unknown), PanelQueryScopeException::class);
	$denied=Resource::make('orders')->relation(RelationManager::make('items')->relatedResource('items')->authorize(static fn(): bool=>false));
	$t->throws(static fn()=>$bridge->querySpec($request, [], $denied), PanelQueryScopeException::class);
	$rootScoped=Resource::make('tenant-orders')->tenantScoped('tenant_id', true);
	$t->throws(static fn()=>$bridge->query(PanelRequest::fromArray(['tenant'=>'north']), [], $rootScoped), PanelQueryScopeException::class);
})->tag('panel','query','scope','permission','tenant')->maxMillis(1000);

test('resource query proxy carries typed scope expressions without flattening them', static function(Context $t): void {
	$source=new PanelArrayDataSource([
		['id'=>1,'items'=>[['sku'=>'A-1']]], ['id'=>2,'items'=>[['sku'=>'B-1']]],
	], ['tenant_field'=>null]);
	$resource=Resource::make('orders')->relation(RelationManager::make('items'));
	$bound=PanelDataSourceResourceBridge::using($source)->bind($resource);
	$query=$bound->makeQuery(PanelRequest::fromArray([]))
		->whereExpression(PanelQueryComparison::make('id','gt',0))
		->whereRelation('items', PanelQueryComparison::make('sku','eq','A-1'));
	$t->same([1], array_column($query->getRecords()->items(),'id'));
	$t->same([], $query->deny()->getRecords()->items());
})->tag('panel','query','proxy','scope')->maxMillis(1000);

test('query contract accessors aliases and fail-closed residuals remain fully observable', static function(Context $t): void {
	$between=PanelQueryBetween::make(PanelQueryPath::make('score'), 1, 2, true);
	$in=PanelQueryIn::make(PanelQueryPath::make('id'), [1,2], true);
	$null=PanelQueryNull::make(PanelQueryPath::make('deleted_at'));
	$group=PanelQueryGroup::any($between, $in, $null);
	$relation=PanelQueryRelation::make(PanelQueryPath::make('items'), $group, 'all');
	$t->notNull($relation->withScope(PanelQueryComparison::make('tenant_id','eq','north'))->scope());
	$t->same(['score'], $between->fields());
	$t->same(['id'], $in->fields());
	$t->same(['deleted_at'], $null->fields());
	$t->same(['score','id','deleted_at'], $group->fields());
	$t->same(['or','not_between','not_in','is_null'], $group->operators());
	$t->same(['relation:all','or','not_between','not_in','is_null'], $relation->operators());
	$t->isTrue(PanelQueryExpressionCodec::containsRelations($relation));
	$t->isFalse(PanelQueryExpressionCodec::containsRelations($group));
	$t->same(['items.score','items.id','items.deleted_at'], $relation->fields());
	$t->same(['profile','name'], PanelQueryPath::make('profile.name')->segments());

	$t->same('not_null', PanelQueryExpressionCodec::fromArray(['field'=>'deleted_at','operator'=>'not_null'])->operators()[0]);
	$t->same('not_in', PanelQueryExpressionCodec::fromArray(['field'=>'id','operator'=>'not_in','value'=>[1]])->operators()[0]);
	$t->same('not_null', PanelQueryExpressionCodec::fromLegacyFilter('deleted_at','not_null')->operators()[0]);
	$t->same('not_in', PanelQueryExpressionCodec::fromLegacyFilter('id','not_in',[1])->operators()[0]);
	$t->throws(static fn()=>PanelQueryExpressionCodec::fromLegacyFilter('id','in','bad'), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQueryExpressionCodec::fromArray(['type'=>'membership','field'=>'id']), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQueryExpressionCodec::fromArray(['type'=>'unknown']), InvalidArgumentException::class);

	$t->same(1.5, PanelQueryValue::normalize(1.5));
	$t->same(['type'=>'json'], PanelQueryValue::normalize(new class implements JsonSerializable { public function jsonSerialize(): array { return ['type'=>'json']; } }));
	$t->throws(static fn()=>PanelQueryValue::normalize(INF), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQueryValue::normalize(new stdClass()), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQueryValue::normalize('deep','value',17), InvalidArgumentException::class);

	$t->same([], PanelQueryUrlCodec::decode('')->filterList());
	$t->same('status', PanelQueryUrlCodec::fromQuery(['status'=>'open'])->filterList()[0]['field']);
	$t->same([], PanelQueryUrlCodec::fromQuery([])->filterList());
	$t->same([], PanelQueryUrlCodec::legacy(['tenant'=>'must-not-import'])->filterList());

	$manifest=new PanelQueryScopeManifest('orders', [['path'=>'items'],['path'=>'items']]);
	$scope=PanelQueryScopeGuard::apply(null, Resource::make('orders'));
	$t->same('orders', $manifest->resource());
	$t->same(2, count($manifest->checks()));
	$t->same(['items'], $manifest->paths());
	$t->same('panel_query_scope', $scope->jsonSerialize()['type']);
	$t->same(null, $scope->expression());
	$t->same('orders', $scope->manifest()->resource());

	$unresolved=Resource::make('orders')->relation(RelationManager::make('items'));
	try{
		PanelQueryScopeGuard::apply(
			PanelQueryRelation::make('items', PanelQueryRelation::make('audit', PanelQueryComparison::make('id','eq',1))),
			$unresolved
		);
	}
	catch(PanelQueryScopeException $exception){
		$t->same('nested_resource_unresolved', $exception->codeName());
		$t->same('items', $exception->context()['path']);
		$t->same('panel_query_scope_error', $exception->jsonSerialize()['type']);
	}

	try{ PanelQueryCapabilities::fromArray(['filters'=>false])->assertSupports(PanelDataQuery::make()->where('id',1)); }
	catch(PanelUnsupportedQueryException $exception){ $t->same('unknown', $exception->capabilities()['adapter']); }
})->tag('panel','query','exact-coverage')->maxMillis(1000);
