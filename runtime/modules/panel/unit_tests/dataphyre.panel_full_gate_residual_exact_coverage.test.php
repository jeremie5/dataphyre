<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\FilesystemWorkflowStore;
use Dataphyre\Panel\FormSection;
use Dataphyre\Panel\Infolist;
use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelArrayRelationAdapter;
use Dataphyre\Panel\PanelAtomicSnapshotStore;
use Dataphyre\Panel\PanelCollectionItemPresentation;
use Dataphyre\Panel\PanelCollectionPresentation;
use Dataphyre\Panel\PanelDataSourceResourceBridge;
use Dataphyre\Panel\PanelEditorProfile;
use Dataphyre\Panel\PanelFilesystemAuthenticationStore;
use Dataphyre\Panel\PanelFilesystemOperationStore;
use Dataphyre\Panel\PanelLocalMediaDisk;
use Dataphyre\Panel\PanelOperationHandlerRegistry;
use Dataphyre\Panel\PanelOperationRecord;
use Dataphyre\Panel\PanelOperationStatus;
use Dataphyre\Panel\PanelPlatformController;
use Dataphyre\Panel\PanelRelationWorkspace;
use Dataphyre\Panel\PanelRelationWorkspaceCommand;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelRegressionSuite;
use Dataphyre\Panel\PanelResumableUploadSession;
use Dataphyre\Panel\PanelSearchPage;
use Dataphyre\Panel\PanelSearchProvider;
use Dataphyre\Panel\PanelSearchResult;
use Dataphyre\Panel\PanelSynchronousOperationRunner;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\ResourceTable;
use Dataphyre\Panel\SchemaComponent;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('residual filesystem boundaries always release locks and temporary streams', static function(Context $t): void {
	$authentication=new PanelFilesystemAuthenticationStore($t->tempDirectory('panel-full-gate-auth'));
	$t->throws(static fn()=>$authentication->transaction(static function(): never {
		throw new RuntimeException('transaction rollback probe');
	}), RuntimeException::class);

	$atomic=new PanelAtomicSnapshotStore($t->tempDirectory('panel-full-gate-snapshots'), 'coverage.snapshot');
	$t->same(0, $atomic->snapshot()['sequence']);
	$t->same(1, $atomic->transaction(static function(array &$payload): string {
		$payload['ready']=true;
		return 'stored';
	}, 'coverage.commit')['snapshot']['sequence']);

	$media=PanelLocalMediaDisk::make($t->tempDirectory('panel-full-gate-media'), 'Coverage Disk');
	$t->same('payload', $media->read($media->write('nested/file.txt', 'payload')['path']));
	$chunk=str_repeat('x', 1024);
	$upload=PanelResumableUploadSession::start($media, 'uploads/assembled.bin', 1024, 1024, hash('sha256', $chunk), [], 'full-gate-upload');
	$t->isFalse($upload->receiveChunk(0, $chunk, hash('sha256', $chunk))['idempotent']);

	$workflows=new FilesystemWorkflowStore($t->tempDirectory('panel-full-gate-workflows'));
	$t->same(null, $workflows->load('missing_definition', 'missing-id'));
})->tag('panel', 'coverage', 'filesystem', 'exact')->maxMillis(3000);

test('resource data bridge normalizes hostile filters and maps every query capability', static function(Context $t): void {
	$source=new PanelArrayDataSource([
		['id'=>1, 'tenant_id'=>'tenant-a', 'name'=>'Alpha', 'status'=>'active', 'amount'=>10],
		['id'=>2, 'tenant_id'=>'tenant-a', 'name'=>'Beta', 'status'=>'inactive', 'amount'=>20],
	], ['tenant_field'=>'tenant_id']);
	$bridge=PanelDataSourceResourceBridge::using($source, [
		'search_fields'=>['name'],
		'select'=>['id', 'name', 'amount'],
		'include'=>['owner'],
		'authorization'=>['ability'=>'orders.read'],
		'aggregates'=>[['alias'=>'amount_sum', 'function'=>'sum', 'field'=>'amount'], 'malformed'],
	]);

	$spec=$bridge->querySpec(null, [
		'q'=>'alpha', 'sort'=>'amount', 'dir'=>'desc', 'page'=>1, 'limit'=>10,
		'filters'=>'[{"field":"status","operator":"eq","value":"active","boolean":"or"},{"field":""},"invalid"]',
		'scope_filters'=>['amount'=>['operator'=>'gte', 'value'=>10, 'boolean'=>'or']],
		'tenant'=>'tenant-a', 'authorization'=>['ability'=>'orders.export'],
		'aggregates'=>['malformed', ['alias'=>'rows', 'function'=>'count']],
		'metadata'=>['origin'=>'coverage'],
	]);
	$serialized=$spec->jsonSerialize();
	$t->same('tenant-a', $spec->tenantKey());
	$t->same('coverage', $serialized['metadata']['origin'] ?? null);
	$t->same('desc', $serialized['sorts'][0]['direction'] ?? null);
	$t->same('or', $serialized['filters'][0]['boolean'] ?? null);

	$t->same([], $t->nonPublic($bridge)->invoke('normalizeFilters', '{broken'));
	$t->same([], $t->nonPublic($bridge)->invoke('normalizeFilters', new stdClass()));

	$table=ResourceTable::make()->columns([
		Column::make('id'),
		Column::make('name')->searchable(),
	]);
	$columns=$t->nonPublic($table)->readProperty('columns');
	$columns['malformed']=new stdClass();
	$t->nonPublic($table)->writeProperty('columns', $columns);
	$resource=Resource::make('orders')->resourceTable($table);
	$plainBridge=PanelDataSourceResourceBridge::using($source);
	$t->same(['name'], $t->nonPublic($plainBridge)->invoke('searchFields', $resource));

	$filtersTable=ResourceTable::make()->filters([
		TableFilter::make('hidden_status')->hidden(),
		TableFilter::make('blank_status'),
		TableFilter::make('duplicate_status')->column('status'),
		TableFilter::make('amount')->numberRange()->meta(['data_source'=>['field'=>'amount', 'from_operator'=>'gt', 'to_operator'=>'lt']]),
	]);
	$filters=$t->nonPublic($filtersTable)->readProperty('filters');
	$filters['malformed']=new stdClass();
	$t->nonPublic($filtersTable)->writeProperty('filters', $filters);
	$filteredResource=Resource::make('filtered_orders')->resourceTable($filtersTable);
	$request=PanelRequest::fromArray(['operation'=>'index', 'query'=>[
		'hidden_status'=>'active', 'duplicate_status'=>'active',
		'amount_from'=>5, 'amount_to'=>25,
		'filters'=>[['field'=>'status', 'operator'=>'eq', 'value'=>'active']],
	]]);
	$resourceSpec=$bridge->querySpec($request, [], $filteredResource)->jsonSerialize();
	$t->same(['status', 'amount', 'amount'], array_column($resourceSpec['filters'], 'field'));
	$t->same(['eq', 'gt', 'lt'], array_column($resourceSpec['filters'], 'operator'));

	$predicateResource=Resource::make('predicate_orders')->filter(
		TableFilter::make('danger')->where(static fn(): bool=>true)
	);
	$t->throws(static fn()=>$bridge->querySpec(
		PanelRequest::fromArray(['query'=>['danger'=>'yes']]), [], $predicateResource
	), LogicException::class);
})->tag('panel', 'coverage', 'data-source', 'security', 'exact')->maxMillis(2000);

test('field section schema and infolist presentation fallbacks retain immutable contracts', static function(Context $t): void {
	$field=Field::fromArray([
		'name'=>'market', 'type'=>'radio',
		'presentation'=>[
			'options'=>['display'=>'masonry', 'columns'=>2],
			'ignored'=>new stdClass(),
		],
	]);
	$t->same('masonry', $field->toArray()['meta']['options_presentation']['display'] ?? null);
	$itemPresentations=Field::make('market')->optionItemPresentations([
		'eu'=>['fill_remainder'=>true], 'invalid'=>new stdClass(),
	]);
	$t->isTrue($itemPresentations->toArray()['meta']['options_presentation']['items']['eu']['fill_remainder'] ?? false);

	$editor=Field::make('body')->editorProfile(PanelEditorProfile::make('coverage', 'markdown'))->type('code');
	$t->same('code', $editor->editorProfileManifest()['mode']);
	$t->same('2001:db8::1', Field::make('ip')->format('ip')->dehydrateValue(' 2001:DB8::1 '));
	$t->same('45.50169', Field::make('latitude')->format('latitude', ['decimals'=>5])->dehydrateValue('45.5016899'));

	$displaySection=FormSection::fromArray(['name'=>'display', 'presentation'=>['display'=>'masonry', 'columns'=>2]]);
	$collectionsSection=FormSection::fromArray(['name'=>'collections', 'presentation'=>['fields'=>['display'=>'grid'], 'actions'=>'brick']]);
	$t->same('masonry', $displaySection->presentationFor('fields')['display']);
	$t->same('brick', $collectionsSection->presentationFor('actions')['display']);

	$infolist=Infolist::make()->tabsPresentation('segmented')->stepsPresentation('grid');
	$t->same('segmented', $infolist->presentations()['tabs']['display']);
	$t->same('grid', $infolist->presentations()['steps']['display']);

	$component=SchemaComponent::section(FormSection::make('profile')->meta([
		'item_presentation'=>['span'=>1],
	]))->meta(['item_presentation'=>['fill_remainder'=>true]]);
	$section=$component->sectionsList()['profile'];
	$t->isTrue($section->toArray()['meta']['item_presentation']['fill_remainder'] ?? false);
})->tag('panel', 'coverage', 'forms', 'schema', 'presentation', 'exact')->maxMillis(2000);

test('collection presentations reject malformed entries and cover all basis policies', static function(Context $t): void {
	$table=ResourceTable::make();
	$t->same($table, $table->collectionItemPresentation('!!!', 'item', ['span'=>1]));
	$t->same($table, $table->collectionFinalRow('!!!'));

	$item=PanelCollectionItemPresentation::make([
		'basis'=>'auto', 'break_before'=>1, 'fill_remainder'=>0,
	]);
	$t->same('auto', $item->toArray()['basis']['base']);
	$t->isTrue($item->toArray()['break_before']);
	$t->isFalse($item->toArray()['fill_remainder']);
	$t->nonPublic($item)->writeProperty('definition', ['basis'=>'legacy']);
	$t->same('20px', $item->basis(20)->toArray()['basis']['base']);

	$decorated=PanelCollectionItemPresentation::decorateHtml('<span>x</span>', ['span'=>1], [
		'unsafe'=>'10%',
		'--dp-item-basis'=>'url(javascript:alert(1))',
		'--dp-item-basis-md'=>'calc(50% - 4px)',
	]);
	$t->contains('--dp-item-basis-md:calc(50% - 4px)', $decorated);
	$t->notContains('javascript', $decorated);

	$t->same([], PanelCollectionPresentation::normalize(['items'=>'malformed'])['items'] ?? []);
	$t->contains('--dp-collection-basis:50%', PanelCollectionPresentation::htmlAttributes(['columns'=>2, 'gap'=>'none']));
	$t->contains('--dp-collection-basis:calc(50% - 10px)', PanelCollectionPresentation::htmlAttributes(['columns'=>2, 'gap'=>'roomy']));
	$deferred=PanelCollectionPresentation::decorateItemHtml('<span>x</span>', [
		'display'=>'masonry', 'columns'=>['md'=>4], 'gap'=>'roomy',
		'items'=>['x'=>['span'=>['base'=>2, 'md'=>3]]],
	], 'x');
	$t->contains('--dp-item-basis-md:calc(75% - 5px)', $deferred);
})->tag('panel', 'coverage', 'presentation', 'security', 'exact')->maxMillis(1500);

test('relation operation search and regression adapters cover every public variant', static function(Context $t): void {
	$adapter=new PanelArrayRelationAdapter([
		['id'=>'one', 'name'=>'One'], ['id'=>'two', 'name'=>'Two'],
	]);
	$workspace=PanelRelationWorkspace::make('items', 'parent', $adapter);
	$execute=static fn(string $operation, array $keys=[], array $values=[], string $id='')=>$workspace->execute(
		PanelRelationWorkspaceCommand::make($operation, $keys, $values, ['idempotency_key'=>$id ?: $operation])
	);
	$t->same('committed', $execute('attach', ['one', 'two'], ['role'=>'member'], 'attach-all')->status());
	$t->same('committed', $execute('update_pivot', ['one'], ['role'=>'owner'], 'update-one')->status());
	$t->same('committed', $execute('reorder', ['two', 'one'], [], 'reorder-all')->status());
	$t->same('committed', $execute('detach', ['two'], [], 'detach-two')->status());
	$snapshot=$adapter->snapshot('parent');
	$t->same('committed', $execute('restore', [], $snapshot, 'restore-snapshot')->status());

	$request=PanelRequest::fromArray(['tenant'=>'tenant-a']);
	$page=PanelSearchPage::make(
		[PanelSearchResult::fromArray(['title'=>'Order one'], 'orders')],
		['offset'=>1], false, true,
		[['code'=>'partial', 'message'=>'Partial result']],
		['source'=>'index']
	);
	$provider=PanelSearchProvider::make('orders')->searchUsing(static fn(): PanelSearchPage=>$page);
	$resolved=$provider->searchPage('order', $request);
	$t->same(['offset'=>1], $resolved->nextCursor());
	$t->isFalse($resolved->isComplete());
	$t->isTrue($resolved->isPartial());

	$suite=PanelRegressionSuite::make('full-gate')->browser([
		'name'=>'orders-mobile', 'url'=>'/orders', 'viewport'=>['width'=>390, 'height'=>844],
	]);
	$t->same('orders-mobile', $suite->browserManifests()[0]['name']);
})->tag('panel', 'coverage', 'relations', 'search', 'regression', 'exact')->maxMillis(2000);

test('operation runner distinguishes invalid lifecycle and completed-with-failures outcomes', static function(Context $t): void {
	$store=new PanelFilesystemOperationStore($t->tempDirectory('panel-full-gate-operations'));
	$runner=new PanelSynchronousOperationRunner($store, new PanelOperationHandlerRegistry());
	$partial=$store->create(PanelOperationRecord::make('coverage', 'Partial', [
		'id'=>'partial-operation', 'max_attempts'=>1,
	]));
	$completed=$runner->runWith($partial->id(), static fn(): array=>[
		'status'=>PanelOperationStatus::COMPLETED_WITH_FAILURES,
		'failed'=>1,
	]);
	$t->same(PanelOperationStatus::COMPLETED_WITH_FAILURES, $completed->status());

	$invalid=$store->create(PanelOperationRecord::make('coverage', 'Invalid', [
		'id'=>'invalid-operation',
	])->start()->requestPause());
	$t->throws(static fn()=>$runner->runWith($invalid->id(), static fn(): array=>[]), LogicException::class);

	foreach(['count', 'avg', 'average', 'min', 'max'] as $summary){
		$t->notEmpty(Column::make('amount')->summarize($summary)->resolveFooter([['amount'=>10], ['amount'=>20]])['value']);
	}
})->tag('panel', 'coverage', 'operations', 'tables', 'exact')->maxMillis(2000);

test('platform security context resolver exceptions fail closed', static function(Context $t): void {
	$controller=(new PanelPlatformController())->securityBoundary(
		null,
		static function(): never { throw new RuntimeException('context resolver failed'); }
	);
	$request=PanelRequest::fromArray(['user'=>['id'=>'operator']]);
	$t->same(null, $t->nonPublic($controller)->invoke('securityContext', $request));
})->tag('panel', 'coverage', 'security', 'exact')->maxMillis(1000);
