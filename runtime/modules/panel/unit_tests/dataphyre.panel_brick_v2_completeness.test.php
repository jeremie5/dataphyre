<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Column;
use Dataphyre\Panel\Action;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\PanelCollectionItemPresentation;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PageTable;
use Dataphyre\Panel\RelationManager;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TableView;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

function dp_panel_brick_v2_request(): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>'GET',
		'resource'=>'brick-records',
		'operation'=>'show',
		'record'=>'R1',
		'user'=>['id'=>7],
	]);
}

/** @return array{id:string,name:string} */
function dp_panel_brick_v2_record(): array {
	return ['id'=>'R1','name'=>'Brick record'];
}

function dp_panel_brick_v2_record_resource(): Resource {
	return Resource::make('brick-records')
		->recordKeyUsing('id')
		->alertsUsing(static fn(): array=>[
			['title'=>'Alert one','message'=>'First'],
			['title'=>'Alert two','message'=>'Second'],
		])
		->insightsUsing(static fn(): array=>[
			['label'=>'Insight one','value'=>1],
			['label'=>'Insight two','value'=>2],
		])
		->linksUsing(static fn(): array=>[
			['label'=>'Link one','url'=>'/one'],
			['label'=>'Link two','url'=>'/two'],
		])
		->contactsUsing(static fn(): array=>[
			['name'=>'Ada','email'=>'ada@example.test'],
			['name'=>'Grace','email'=>'grace@example.test'],
		])
		->locationsUsing(static fn(): array=>[
			['label'=>'Toronto','address'=>'1 Main'],
			['label'=>'Montreal','address'=>'2 Main'],
		])
		->tagsUsing(static fn(): array=>[
			['name'=>'priority','label'=>'Priority'],
			['name'=>'active','label'=>'Active'],
		])
		->itemsUsing(static fn(): array=>[
			['title'=>'Item one','sku'=>'ONE'],
			['title'=>'Item two','sku'=>'TWO'],
		])
		->totalsUsing(static fn(): array=>[
			['label'=>'Subtotal','value'=>10],
			['label'=>'Total','value'=>12],
		])
		->approvalsUsing(static fn(): array=>[
			['name'=>'manager','title'=>'Manager','status'=>'pending'],
			['name'=>'finance','title'=>'Finance','status'=>'approved'],
		])
		->activityUsing(static fn(): array=>[
			['title'=>'Created','time'=>'Today'],
			['title'=>'Updated','time'=>'Tomorrow'],
		])
		->changesUsing(static fn(): array=>[
			['field'=>'status','before'=>'draft','after'=>'active'],
			['field'=>'owner','before'=>'Ada','after'=>'Grace'],
		])
		->paymentsUsing(static fn(): array=>[
			['title'=>'Charge','reference'=>'pay-one','amount'=>10],
			['title'=>'Refund','reference'=>'pay-two','amount'=>2],
		])
		->shipmentsUsing(static fn(): array=>[
			['title'=>'Shipment one','tracking'=>'TRACK-ONE'],
			['title'=>'Shipment two','tracking'=>'TRACK-TWO'],
		])
		->notesUsing(static fn(): array=>[
			['message'=>'Note one'],
			['message'=>'Note two'],
		])
		->attachmentsUsing(static fn(): array=>[
			['name'=>'one.pdf','url'=>'/one.pdf'],
			['name'=>'two.pdf','url'=>'/two.pdf'],
		])
		->messagesUsing(static fn(): array=>[
			['subject'=>'Message one','body'=>'First'],
			['subject'=>'Message two','body'=>'Second'],
		])
		->tasksUsing(static fn(): array=>[
			['name'=>'first','title'=>'First task'],
			['name'=>'second','title'=>'Second task'],
		]);
}

/** @return array<string,string> */
function dp_panel_brick_v2_record_methods(): array {
	return [
		'alerts'=>'alertsHtml',
		'insights'=>'insightsHtml',
		'links'=>'linksHtml',
		'contacts'=>'contactsHtml',
		'locations'=>'locationsHtml',
		'tags'=>'tagsHtml',
		'items'=>'itemsHtml',
		'totals'=>'totalsHtml',
		'approvals'=>'approvalsHtml',
		'activity'=>'activityHtml',
		'changes'=>'changesHtml',
		'payments'=>'paymentsHtml',
		'shipments'=>'shipmentsHtml',
		'notes'=>'notesHtml',
		'attachments'=>'attachmentsHtml',
		'messages'=>'messagesHtml',
		'tasks'=>'tasksHtml',
	];
}

test('brick v2 resource record presentation is immutable round trippable and naturally typed',static function(Context $t): void {
	$base=Resource::make('orders');
	$configured=$base
		->recordPresentation('payments', ['display'=>'masonry','masonry'=>'rows','columns'=>['base'=>2]])
		->recordItemPresentation('payments', 'pay-one', PanelCollectionItemPresentation::make()->span(2)->grow())
		->recordItemPresentations('payments', ['*'=>['shrink'=>0],1=>['break_before'=>true]])
		->recordFinalRow('payments', 'preserve');

	$t->same([],$base->presentations());
	$t->isFalse(array_key_exists('record_presentation',$base->toArray()));
	$t->same('masonry',$configured->presentationFor('payments')['display']);
	$t->same('preserve',$configured->presentationFor('payments')['final_row']);
	$t->same(2,$configured->presentationFor('payments')['items']['pay-one']['span']['base']);
	$t->same(0,$configured->presentationFor('payments')['items']['*']['shrink']);
	$t->isTrue($configured->presentationFor('payments')['items']['#1']['break_before']);
	$t->same($configured->presentations(),Resource::fromArray($configured->toArray())->presentations());

	$natural=Resource::make('natural')
		->collectionItemPresentation('alerts','*',['grow'=>1])
		->collectionItemPresentation('insights','*',['grow'=>1])
		->collectionItemPresentation('tags','*',['grow'=>1]);
	$t->same('stack',$natural->presentationFor('alerts')['display']);
	$t->same('grid',$natural->presentationFor('insights')['display']);
	$t->same('inline',$natural->presentationFor('tags')['display']);
	$t->same('grid',$configured->masonryRecords('payments',false)->presentationFor('payments')['display']);

	$relation=RelationManager::make('orders');
	$wide=$relation->itemSpan(['base'=>1,'lg'=>2])->itemFillRemainder();
	$t->same([],$relation->toArray()['meta']);
	$t->same(2,$wide->toArray()['meta']['item_presentation']['span']['lg']);
	$t->isTrue($wide->toArray()['meta']['item_presentation']['fill_remainder']);
})->tag('panel','brick-v2','record','contract')->group('framework-coverage');

test('brick v2 decorates every bespoke record collection with item and final row policy',static function(Context $t): void {
	$resource=dp_panel_brick_v2_record_resource();
	foreach(array_keys(dp_panel_brick_v2_record_methods()) as $collection){
		$resource=$resource->recordPresentation($collection, [
			'display'=>'masonry',
			'masonry'=>'rows',
			'columns'=>['base'=>2,'lg'=>3],
			'final_row'=>'center',
			'items'=>[
				'*'=>['grow'=>0],
				'#1'=>['fill_remainder'=>true],
			],
		]);
	}

	$request=dp_panel_brick_v2_request();
	$record=dp_panel_brick_v2_record();
	foreach(dp_panel_brick_v2_record_methods() as $collection=>$method){
		$html=$t->nonPublic(PanelRenderer::class)->invoke($method,$resource,$request,$record);
		$t->contains('data-dp-display="masonry"',$html,$collection.' wrapper');
		$t->contains('data-dp-masonry="rows"',$html,$collection.' flow');
		$t->contains('data-dp-final-row="center"',$html,$collection.' final row');
		$t->same(2,substr_count($html,'data-dp-item-layout="1"'),$collection.' item count');
		$t->contains('data-dp-item-fill-remainder="1"',$html,$collection.' positional item');
	}
	$t->contains('dp-panel-activity-list',$t->nonPublic(PanelRenderer::class)->invoke('activityHtml',$resource,$request,$record));
})->tag('panel','brick-v2','record','renderer')->group('framework-coverage');

test('brick v2 preserves unconfigured record markup and activates local descriptor metadata',static function(Context $t): void {
	$request=dp_panel_brick_v2_request();
	$record=dp_panel_brick_v2_record();
	$plain=dp_panel_brick_v2_record_resource();
	foreach(dp_panel_brick_v2_record_methods() as $method){
		$html=$t->nonPublic(PanelRenderer::class)->invoke($method,$plain,$request,$record);
		$t->isFalse(str_contains($html,'data-dp-display='));
		$t->isFalse(str_contains($html,'data-dp-item-layout='));
	}

	$local=Resource::make('local')
		->insightsUsing(static fn(): array=>[
			['label'=>'Local','value'=>1,'meta'=>['item_presentation'=>['span'=>2,'fill_remainder'=>true]]],
			['label'=>'Plain','value'=>2],
		]);
	$html=$t->nonPublic(PanelRenderer::class)->invoke('insightsHtml',$local,$request,$record);
	$t->contains('data-dp-display="grid"',$html);
	$t->same(1,substr_count($html,'data-dp-item-layout="1"'));
	$t->contains('--dp-item-span:2',$html);
	$t->contains('data-dp-item-fill-remainder="1"',$html);

	$invalid=Resource::make('invalid')->insightsUsing(static fn(): array=>[
		['label'=>'Invalid','value'=>1,'item_presentation'=>['basis'=>'calc(100%);color:red']],
	]);
	$invalidHtml=$t->nonPublic(PanelRenderer::class)->invoke('insightsHtml',$invalid,$request,$record);
	$t->isFalse(str_contains($invalidHtml,'data-dp-display='));
	$t->isFalse(str_contains($invalidHtml,'color:red'));
})->tag('panel','brick-v2','record','legacy')->group('framework-coverage');

test('brick v2 lays out relation managers while retaining authorization and legacy concatenation',static function(Context $t): void {
	$request=dp_panel_brick_v2_request();
	$record=dp_panel_brick_v2_record();
	$allowed=RelationManager::make('orders')->label('Orders')->queryUsing(static fn(): array=>[])
		->column(Column::make('name'))->itemSpan(2);
	$denied=RelationManager::make('secret')->label('Secret')->queryUsing(static fn(): array=>[])
		->authorize(static fn(): bool=>false)->column(Column::make('name'));
	$local=Resource::make('brick-records')->recordKeyUsing('id')->relations([$allowed,$denied]);
	$localHtml=$t->nonPublic(PanelRenderer::class)->invoke('relationsHtml',$local,$request,$record);
	$t->contains('class="dp-panel-relations"',$localHtml);
	$t->contains('data-dp-display="stack"',$localHtml);
	$t->contains('data-dp-item-layout="1"',$localHtml);
	$t->isFalse(str_contains($localHtml,'Secret'));

	$configured=$local->recordPresentation('relations',[
		'display'=>'masonry','masonry'=>'rows','columns'=>2,'final_row'=>'end',
	]);
	$configuredHtml=$t->nonPublic(PanelRenderer::class)->invoke('relationsHtml',$configured,$request,$record);
	$t->contains('data-dp-display="masonry"',$configuredHtml);
	$t->contains('data-dp-final-row="end"',$configuredHtml);

	$plainRelation=RelationManager::make('orders')->label('Orders')->queryUsing(static fn(): array=>[])->column(Column::make('name'));
	$plainHtml=$t->nonPublic(PanelRenderer::class)->invoke('relationsHtml',Resource::make('brick-records')->recordKeyUsing('id')->relation($plainRelation),$request,$record);
	$t->isFalse(str_contains($plainHtml,'dp-panel-relations'));
	$t->isFalse(str_contains($plainHtml,'data-dp-item-layout='));
})->tag('panel','brick-v2','relations','renderer')->group('framework-coverage');

test('brick v2 consumes structural Field row field and builder action presentations',static function(Context $t): void {
	$repeater=Field::make('lines')->repeater([
		Field::make('sku')->itemSpan(2),
		Field::make('quantity','number'),
	])
		->rowsPresentation([
			'display'=>'masonry','masonry'=>'rows','columns'=>2,
			'items'=>['*'=>['grow'=>0]],'final_row'=>'preserve',
		])
		->fieldsPresentation(['display'=>'grid','columns'=>2]);
	$repeaterHtml=$t->nonPublic(PanelRenderer::class)->invoke('repeaterControl','lines',$repeater->toArray(),[
		['sku'=>'ONE','quantity'=>1],
		['sku'=>'TWO','quantity'=>2],
	]);
	$t->contains('data-dp-display="masonry"',$repeaterHtml);
	$t->contains('data-dp-final-row="preserve"',$repeaterHtml);
	$t->contains('data-dp-display="grid"',$repeaterHtml);
	$t->contains('--dp-item-span:2',$repeaterHtml);

	$builder=Field::make('content')->builder([
		'hero'=>[
			'label'=>'Hero',
			'fields'=>[Field::make('headline')->itemFillRemainder()],
			'item_presentation'=>['grow'=>2],
		],
		'copy'=>[
			'label'=>'Copy',
			'fields'=>[Field::make('body','textarea')],
		],
	])
		->rowsPresentation(['display'=>'masonry','masonry'=>'rows'])
		->fieldsPresentation(['display'=>'grid','columns'=>2])
		->actionsPresentation([
			'display'=>'masonry','masonry'=>'rows','items'=>['hero'=>['span'=>2]],
		]);
	$builderHtml=$t->nonPublic(PanelRenderer::class)->invoke('builderControl','content',$builder->toArray(),[
		['_type'=>'hero','headline'=>'Welcome'],
	]);
	$t->contains('dp-panel-builder-actions" data-dp-display="masonry"',$builderHtml);
	$t->contains('data-dp-panel-builder-add="hero"',$builderHtml);
	$t->contains('--dp-item-span:2;--dp-item-grow:2',$builderHtml);
	$t->contains('dp-panel-builder-grid" data-dp-display="grid"',$builderHtml);
	$t->contains('data-dp-item-fill-remainder="1"',$builderHtml);

	$legacy=Field::make('legacy')->repeater([Field::make('name')]);
	$legacyHtml=$t->nonPublic(PanelRenderer::class)->invoke('repeaterControl','legacy',$legacy->toArray(),[['name'=>'Ada']]);
	$t->isFalse(str_contains($legacyHtml,'data-dp-display='));
	$t->isFalse(str_contains($legacyHtml,'data-dp-item-layout='));
})->tag('panel','brick-v2','field','renderer')->group('framework-coverage');

test('brick v2 lays out page scaffold forms and page table sections as owner collections',static function(Context $t): void {
	$request=dp_panel_brick_v2_request();
	$formPage=PanelPage::make('form-page')
		->form(Action::make('refresh'), [
			'title'=>'Refresh data',
			'item_presentation'=>['span'=>2],
		])
		->masonryForms(true, ['columns'=>2])
		->formItemPresentation('refresh',['grow'=>2])
		->collectionFinalRow('forms','center');
	$formHtml=$t->nonPublic(PanelRenderer::class)->invoke('pageScaffoldFormsHtml',$formPage,$request);
	$t->contains('class="dp-panel-page-forms"',$formHtml);
	$t->contains('data-dp-display="masonry"',$formHtml);
	$t->contains('data-dp-final-row="center"',$formHtml);
	$t->contains('--dp-item-span:2;--dp-item-grow:2',$formHtml);

	$table=PageTable::make('orders')->label('Orders')->records([['name'=>'One']])
		->column(Column::make('name'))->itemSpan(2);
	$tablePage=PanelPage::make('table-page')->table($table)
		->masonryTables(true, ['columns'=>2])
		->tableItemPresentation('orders',['grow'=>2]);
	$tableHtml=$t->nonPublic(PanelRenderer::class)->invoke('pageTablesHtml',$tablePage,$request,$tablePage->resolvedTables($request));
	$t->contains('class="dp-panel-page-tables"',$tableHtml);
	$t->contains('data-dp-display="masonry"',$tableHtml);
	$t->contains('--dp-item-span:2;--dp-item-grow:2',$tableHtml);

	$legacyFormPage=PanelPage::make('legacy-form')->form(Action::make('refresh'));
	$legacyFormHtml=$t->nonPublic(PanelRenderer::class)->invoke('pageScaffoldFormsHtml',$legacyFormPage,$request);
	$t->isFalse(str_contains($legacyFormHtml,'dp-panel-page-forms'));
	$t->isFalse(str_contains($legacyFormHtml,'data-dp-item-layout='));

	$legacyTable=PageTable::make('legacy')->records([])->column(Column::make('name'));
	$legacyTablePage=PanelPage::make('legacy-table')->table($legacyTable);
	$legacyTableHtml=$t->nonPublic(PanelRenderer::class)->invoke('pageTablesHtml',$legacyTablePage,$request,$legacyTablePage->resolvedTables($request));
	$t->isFalse(str_contains($legacyTableHtml,'dp-panel-page-tables'));
	$t->isFalse(str_contains($legacyTableHtml,'data-dp-item-layout='));
})->tag('panel','brick-v2','page','renderer')->group('framework-coverage');

test('brick v2 makes board lanes and cards owner and item aware without changing legacy boards',static function(Context $t): void {
	$request=PanelRequest::fromArray([
		'method'=>'GET',
		'resource'=>'board-records',
		'operation'=>'board',
		'user'=>['id'=>7],
	]);
	$records=[
		['id'=>'A','name'=>'Alpha','status'=>'draft'],
		['id'=>'B','name'=>'Beta','status'=>'draft'],
		['id'=>'C','name'=>'Gamma','status'=>'review'],
	];
	$base=Resource::make('board-records')
		->recordKeyUsing('id')
		->recordTitleUsing('name')
		->statusField('status')
		->statusTransitions([
			'review'=>['to'=>'review','from'=>'draft','label'=>'Review'],
			'publish'=>['to'=>'published','from'=>'review','label'=>'Publish'],
		])
		->transitionUsing(static fn(): array=>['transitioned'=>true])
		->view(TableView::make('draft')->label('Draft')->where(static fn(array $record): bool=>($record['status'] ?? '')==='draft'));

	$legacy=PanelRenderer::statusBoard($base,$request,$records)->content();
	$t->isFalse(str_contains($legacy,'data-dp-display='));
	$t->isFalse(str_contains($legacy,'data-dp-item-layout='));

	$configured=$base
		->view(TableView::make('draft')->label('Draft')->where(static fn(array $record): bool=>($record['status'] ?? '')==='draft')->itemSpan(2))
		->masonryBoardColumns(true,['columns'=>2])
		->boardColumnItemPresentation('draft',['grow'=>2])
		->collectionFinalRow('board_columns','center')
		->masonryBoardCards(true,['columns'=>2])
		->boardCardItemPresentation('A',['span'=>2])
		->boardCardItemPresentation(1,['fill_remainder'=>true])
		->collectionFinalRow('board_cards','preserve');
	$html=PanelRenderer::statusBoard($configured,$request,$records)->content();
	$t->contains('class="dp-panel-board" data-dp-panel-packed-grid="masonry" data-dp-panel-packed-grid-min="280" data-dp-display="masonry"',$html);
	$t->contains('data-dp-final-row="center"',$html);
	$t->contains('--dp-item-span:2;--dp-item-grow:2',$html);
	$t->contains('dp-panel-board-list" data-dp-display="masonry"',$html);
	$t->contains('data-dp-final-row="preserve"',$html);
	$t->contains('--dp-item-span:2',$html);
	$t->contains('data-dp-item-fill-remainder="1"',$html);
})->tag('panel','brick-v2','board','renderer')->group('framework-coverage');
