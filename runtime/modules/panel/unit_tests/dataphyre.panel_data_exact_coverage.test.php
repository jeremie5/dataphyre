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
use Dataphyre\Panel\PanelDataChange;
use Dataphyre\Panel\PanelDataCursor;
use Dataphyre\Panel\PanelDataPage;
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelDataResult;
use Dataphyre\Panel\PanelDataSourceRegistry;
use Dataphyre\Panel\PanelDataSourceResourceBridge;
use Dataphyre\Panel\PanelRepositoryDataSource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('array data source defines deterministic comparison search authorization and aggregate edge semantics', static function(Context $t): void {
	$rows=[
		['id'=>1, 'score'=>10, 'when'=>new DateTimeImmutable('2026-01-02'), 'profile'=>['labels'=>['needle', 'one']], 'tags'=>['a', 'b']],
		['id'=>2, 'score'=>20, 'when'=>new DateTimeImmutable('2026-01-01'), 'profile'=>['labels'=>['two']], 'tags'=>['a', 'b']],
		['id'=>3, 'score'=>20, 'when'=>new DateTimeImmutable('2026-01-03'), 'profile'=>['labels'=>['three']], 'tags'=>['c']],
	];
	$source=new PanelArrayDataSource($rows, ['tenant_field'=>null]);
	$t->same(0, $source->sequence());
	$t->same(3, count($source->rows()));
	$t->same([2, 3], array_column($source->query(PanelDataQuery::make()->where('score', 'gt', 10))->items(), 'id'));
	$t->same([2, 3], array_column($source->query(PanelDataQuery::make()->where('score', 'gte', 20))->items(), 'id'));
	$t->same([1], array_column($source->query(PanelDataQuery::make()->where('score', 'lte', 10))->items(), 'id'));

	$t->same([1, 2, 3], array_column($source->query(PanelDataQuery::make()->sort('score'))->items(), 'id'));
	$t->same([2, 1, 3], array_column($source->query(PanelDataQuery::make()->sort('when'))->items(), 'id'));
	$t->same([1], array_column($source->query(PanelDataQuery::make()->search('needle'))->items(), 'id'));
	$aggregated=$source->query(PanelDataQuery::make()->aggregate('tag_sets', 'distinct_count', 'tags'));
	$t->same(2, $aggregated->aggregates()['tag_sets']);

	$redacting=new PanelArrayDataSource([['id'=>1, 'private'=>'drop']], [
		'tenant_field'=>null,
		'authorize'=>static fn(array $row): array=>['id'=>$row['id'], 'visible'=>'kept'],
	]);
	$t->same(['id'=>1, 'visible'=>'kept'], $redacting->query(PanelDataQuery::make())->items()[0]);

	$invalidDecision=new PanelArrayDataSource([['id'=>1]], ['tenant_field'=>null, 'authorize'=>static fn(): string=>'invalid']);
	$t->throws(static fn()=> $invalidDecision->query(PanelDataQuery::make()), UnexpectedValueException::class);

	$source->upsert(['id'=>4, 'score'=>30]);
	$t->same(1, $source->sequence());
	$t->same(4, count($source->rows()));
})->tag('panel', 'data-source', 'array', 'query-semantics', 'exact-coverage')->maxMillis(2000);

test('data query cursor page change and result value objects report invalid inputs and complete accessors', static function(Context $t): void {
	$change=new PanelDataChange(1, 'update', 'order-1', ['state'=>'draft'], ['state'=>'paid'], '2026-01-01T00:00:00Z', ['actor'=>'operator']);
	$t->same('order-1', $change->key());
	$t->same(['state'=>'draft'], $change->before());
	$t->same(['state'=>'paid'], $change->after());
	$t->same('update', $change->jsonSerialize()['operation']);

	$t->throws(static fn()=> new PanelDataPage(-1, 10, 0, 0), InvalidArgumentException::class);
	$page=new PanelDataPage(20, 10, 2, 42, 'next', 'previous');
	$t->same(20, $page->offset());
	$t->same(10, $page->limit());
	$t->same(2, $page->returned());

	$malformedJson=rtrim(strtr(base64_encode('{'), '+/', '-_'), '=');
	$invalidEnvelope=rtrim(strtr(base64_encode('[]'), '+/', '-_'), '=');
	$t->throws(static fn()=> PanelDataCursor::decode($malformedJson, 'query'), InvalidArgumentException::class);
	$t->throws(static fn()=> PanelDataCursor::decode($invalidEnvelope, 'query'), InvalidArgumentException::class);

	$query=PanelDataQuery::fromArray(['search'=>'Ada'])->orWhere('deleted_at', 'is_null')->filters(['status'=>'open']);
	$t->same('Ada', $query->searchTerm());
	$t->same('or', $query->filterList()[0]['boolean']);
	$t->same('status', $query->filterList()[1]['field']);
	$t->throws(static fn()=> PanelDataQuery::make()->metadata(['invalid'=>new stdClass()]), InvalidArgumentException::class);

	$result=PanelDataResult::normalize([['id'=>1], ['id'=>2]], PanelDataQuery::make()->limit(2), 'values');
	$iterated=[];
	foreach($result as $item){ $iterated[]=$item['id']; }
	$t->same([1, 2], $iterated);
})->tag('panel', 'data-source', 'value-objects', 'validation', 'exact-coverage')->maxMillis(1000);

test('callback resource bridge and registry fallback paths stay predictable and self describing', static function(Context $t): void {
	$callback=new PanelCallbackDataSource(static fn(): array=>[['id'=>7, 'name'=>'Seven']]);
	$t->same(7, $callback->find(7)['id']);

	$source=new PanelArrayDataSource([['id'=>9, 'name'=>'Nine']], ['tenant_field'=>null]);
	$bridge=PanelDataSourceResourceBridge::using($source);
	$t->same('Nine', $bridge->find(9)['name']);

	$registry=(new PanelDataSourceRegistry())->register('Primary Source', $source);
	$t->isTrue($registry->has('primary source'));
	$t->same('panel_data_source_registry', $registry->jsonSerialize()['type']);
	$t->same([], $registry->forget('primary source')->names());
	$t->isFalse($registry->has('primary source'));
})->tag('panel', 'data-source', 'registry', 'resource-bridge', 'exact-coverage')->maxMillis(1000);

test('repository data source discovers optional methods and falls back through its universal query contract', static function(Context $t): void {
	$repository=new class {
		public function fetch(PanelDataQuery $query): array {
			return [['id'=>42, 'query_type'=>$query->jsonSerialize()['type']]];
		}
	};
	$source=new PanelRepositoryDataSource($repository);
	$t->same(42, $source->find(42)['id']);
	$t->same('repository', $source->capabilities()['adapter']);
	$t->isFalse($source->capabilities()['change_feed']);

	$t->throws(static fn()=> new PanelRepositoryDataSource(new stdClass()), InvalidArgumentException::class);
})->tag('panel', 'data-source', 'repository', 'fallback', 'exact-coverage')->maxMillis(1000);
