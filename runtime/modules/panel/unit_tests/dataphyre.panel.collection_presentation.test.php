<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PageTable;
use Dataphyre\Panel\PanelCollectionItemPresentation;
use Dataphyre\Panel\PanelCollectionPresentation;
use Dataphyre\Panel\Action;
use Dataphyre\Panel\ActionGroup;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\FormSection;
use Dataphyre\Panel\Infolist;
use Dataphyre\Panel\InfolistEntry;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\ResourceForm;
use Dataphyre\Panel\ResourceTable;
use Dataphyre\Panel\SchemaComponent;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Panel\TableGroup;
use Dataphyre\Panel\TableSummary;
use Dataphyre\Panel\TableView;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('collection presentation normalizes brick aliases responsive columns and safe attributes', static function(Context $t): void {
	$presentation=PanelCollectionPresentation::normalize([
		'display'=>'tiles',
		'density'=>'roomy',
		'gap'=>'compact',
		'fit'=>'fixed',
		'columns'=>['base'=>1, 'md'=>3, '2xl'=>20, 'bad'=>4],
		'min_width'=>8,
	]);

	$t->same('brick', $presentation['display']);
	$t->same('roomy', $presentation['density']);
	$t->same('compact', $presentation['gap']);
	$t->same('fixed', $presentation['fit']);
	$t->same(['base'=>1, 'md'=>3, '2xl'=>12], $presentation['columns']);
	$t->same(96, $presentation['min_width']);
	$attributes=PanelCollectionPresentation::htmlAttributes($presentation);
	$t->contains('data-dp-display="brick"', $attributes);
	$t->contains('--dp-collection-columns-md:3', $attributes);
	$t->contains('--dp-collection-columns-2xl:12', $attributes);
})->tag('panel', 'presentation', 'collections', 'brick')->maxMillis(1000);

test('row masonry is explicit backward compatible and emits deterministic responsive bases', static function(Context $t): void {
	$legacy=PanelCollectionPresentation::normalize('masonry');
	$rows=PanelCollectionPresentation::normalize([
		'display'=>'row_masonry',
		'columns'=>['base'=>2, 'md'=>3],
		'gap'=>'compact',
		'min_width'=>150,
	]);
	$explicit=PanelCollectionPresentation::normalize(['display'=>'masonry', 'flow'=>'rows']);

	$t->same('masonry', $legacy['display']);
	$t->same('columns', $legacy['masonry']);
	$t->same('auto', $legacy['fit']);
	$t->same('masonry', $rows['display']);
	$t->same('rows', $rows['masonry']);
	$t->same('fill', $rows['fit']);
	$t->same('rows', $explicit['masonry']);
	$attributes=PanelCollectionPresentation::htmlAttributes($rows, 'grid', '--dp-form-columns-default:2');
	$t->contains('data-dp-masonry="rows"', $attributes);
	$t->contains('data-dp-columns="configured"', $attributes);
	$t->contains('--dp-collection-basis:calc(50% - 4px)', $attributes);
	$t->contains('--dp-collection-basis-md:calc(33.333333% - 5.3333px)', $attributes);
	$t->contains('--dp-form-columns-default:2', $attributes);
	$t->same(1, substr_count($attributes, ' style="'));
})->tag('panel', 'presentation', 'collections', 'masonry', 'responsive')->maxMillis(1000);

test('resource and page tables expose immutable presentation builders for major primitive collections', static function(Context $t): void {
	$resource=ResourceTable::make()
		->brickViews()
		->groupsDisplay('stacked')
		->summariesPresentation(['display'=>'masonry', 'columns'=>['lg'=>4]])
		->filtersPresentation(['display'=>'brick', 'min_width'=>240])
		->brickActions();
	$page=PageTable::make('orders')
		->viewsPresentation(['display'=>'brick', 'columns'=>['sm'=>2, 'lg'=>5], 'fit'=>'fixed'])
		->brickGroups()
		->brickSummaries();
	$form=ResourceForm::make()
		->brickTabs()
		->brickActions()
		->stepsPresentation(['display'=>'grid', 'columns'=>['md'=>3], 'fit'=>'fixed']);
	$options=Field::make('channel', 'radio')
		->options(['web'=>'Web', 'store'=>'Store'])
		->optionsPresentation(['display'=>'brick', 'columns'=>['sm'=>2], 'density'=>'compact']);

	$t->same('brick', $resource->presentationFor('views')['display']);
	$t->same('stack', $resource->presentationFor('groups')['display']);
	$t->same('masonry', $resource->toArray()['presentation']['summaries']['display'] ?? null);
	$t->same(240, $resource->presentationFor('filters')['min_width']);
	$t->same('brick', $resource->presentationFor('actions')['display']);
	$t->same('brick', $page->toArray()['presentation']['views']['display'] ?? null);
	$t->same(5, $page->presentationFor('views')['columns']['lg'] ?? null);
	$t->same('brick', $page->presentationFor('groups')['display']);
	$t->same('brick', $page->presentationFor('summaries')['display']);
	$t->same('brick', $form->presentationFor('tabs')['display']);
	$t->same('brick', $form->presentationFor('actions')['display']);
	$t->same(3, $form->presentationFor('steps')['columns']['md'] ?? null);
	$t->same('brick', $options->toArray()['meta']['options_presentation']['display'] ?? null);
	$t->same(2, $options->toArray()['meta']['options_presentation']['columns']['sm'] ?? null);
	$t->same([], ResourceTable::make()->presentations());
})->tag('panel', 'presentation', 'builders', 'brick')->maxMillis(1000);

test('row masonry builders round trip through page form section field infolist and resource owners', static function(Context $t): void {
	$page=PanelPage::fromArray([
		'name'=>'operations',
		'presentation'=>[
			'widgets'=>['display'=>'masonry', 'masonry'=>'rows', 'columns'=>['sm'=>2]],
		],
	])->masonryToolbar(true, ['min_width'=>144]);
	$form=ResourceForm::make()
		->masonryFields(true, ['columns'=>['md'=>3]])
		->masonrySections(true, ['min_width'=>260]);
	$section=FormSection::fromArray([
		'name'=>'profile',
		'fields_presentation'=>['display'=>'row_fill', 'columns'=>2, 'gap'=>'compact'],
	]);
	$field=Field::fromArray([
		'name'=>'market',
		'type'=>'radio',
		'options'=>['ca'=>'Canada', 'us'=>'United States', 'eu'=>'European Union'],
		'options_presentation'=>['display'=>'masonry', 'masonry'=>'rows', 'columns'=>2],
	]);
	$infolist=Infolist::make()->masonryEntries(true, ['columns'=>['md'=>3]])->masonrySections();
	$resource=Resource::make('orders')
		->masonryViews(true, ['columns'=>['base'=>2, 'lg'=>5]])
		->masonryFormFields(true, ['min_width'=>180]);

	$t->same('rows', $page->presentationFor('widgets')['masonry']);
	$t->same('rows', $page->toArray()['presentation']['toolbar']['masonry'] ?? null);
	$t->same('rows', $form->presentationFor('fields')['masonry']);
	$t->same('rows', $form->toArray()['presentation']['sections']['masonry'] ?? null);
	$t->same('rows', $section->toArray()['presentation']['fields']['masonry'] ?? null);
	$t->same('rows', $field->toArray()['meta']['options_presentation']['masonry'] ?? null);
	$t->same('rows', $infolist->toArray()['presentation']['entries']['masonry'] ?? null);
	$t->same('rows', $resource->resourceTable()->presentationFor('views')['masonry']);
	$t->same('rows', $resource->form()->presentationFor('fields')['masonry']);
})->tag('panel', 'presentation', 'builders', 'masonry', 'owners')->maxMillis(1500);

test('resource presentation facade routes table form infolist and record collections without metadata leakage', static function(Context $t): void {
	$resource=Resource::make('presentation-routing');
	foreach(['views', 'groups', 'summaries', 'filters', 'actions', 'tools'] as $collection){
		$presentation=$collection.'Presentation';
		$display=$collection.'Display';
		$brick='brick'.ucfirst($collection);
		$resource=$resource->{$presentation}('grid')->{$display}('inline')->{$brick}();
	}
	$resource=$resource
		->masonryViews()->masonryGroups()->masonrySummaries()->masonryFilters()->masonryActions()->masonryTools()
		->tableCollectionItemPresentation('views', 'attention', ['span'=>2])
		->tableCollectionItemPresentations('filters', ['status'=>['fill_remainder'=>true]])
		->tableCollectionFinalRow('views', 'center')
		->brickTableCollection('custom_table', true, ['columns'=>2])
		->masonryTableCollection('custom_table', false);

	foreach(['fields', 'sections', 'tabs', 'steps'] as $collection){
		$presentation=$collection.'Presentation';
		$display=$collection.'Display';
		$brick='brick'.ucfirst($collection);
		$masonry='masonry'.ucfirst($collection);
		$resource=$resource->{$presentation}('grid')->{$display}('stack')->{$brick}()->{$masonry}();
	}
	$resource=$resource
		->formCollectionItemPresentation('fields', 'email', ['span'=>2])
		->formCollectionFinalRow('fields', 'preserve')
		->brickFormCollection('custom_form', true, ['columns'=>2])
		->masonryFormCollection('custom_form', false);

	$resource=$resource
		->entriesPresentation('grid')->entriesDisplay('stack')->brickEntries()->masonryEntries()
		->infolistCollectionItemPresentation('entries', 'owner', ['grow'=>2])
		->infolistCollectionFinalRow('entries', 'end')
		->infolistSectionsPresentation('stack')->brickInfolistSections()->masonryInfolistSections()
		->infolistTabsPresentation('segmented')->brickInfolistTabs()->masonryInfolistTabs()
		->infolistStepsPresentation('segmented')->brickInfolistSteps()->masonryInfolistSteps()
		->brickInfolistCollection('custom_infolist', true, ['columns'=>2])
		->masonryInfolistCollection('custom_infolist', false)
		->brickRecords('payments', true, ['columns'=>2]);

	$table=$resource->resourceTable();
	$t->same('rows', $table->presentationFor('views')['masonry'] ?? null);
	$t->same('center', $table->presentationFor('views')['final_row'] ?? null);
	$t->same(['base'=>2], $table->itemPresentationFor('views', 'attention')['span'] ?? null);
	$t->same(true, $table->itemPresentationFor('filters', 'status')['fill_remainder'] ?? null);
	$t->same('inline', $table->presentationFor('custom_table')['display']);
	$t->same('rows', $resource->form()->presentationFor('fields')['masonry'] ?? null);
	$t->same('preserve', $resource->form()->presentationFor('fields')['final_row'] ?? null);
	$t->same(['base'=>2], $resource->form()->itemPresentationFor('fields', 'email')['span'] ?? null);
	$t->same('inline', $resource->form()->presentationFor('custom_form')['display']);
	$infolist=$resource->infolist();
	$t->instanceOf(Infolist::class, $infolist);
	$t->same('rows', $infolist->presentations()['entries']['masonry'] ?? null);
	$t->same('end', $infolist->presentations()['entries']['final_row'] ?? null);
	$t->same('inline', $infolist->presentations()['custom_infolist']['display'] ?? null);
	$t->same('brick', $resource->presentationFor('payments')['display']);
	$t->same(null, $resource->presentations()['views'] ?? null);
	$t->same(null, $resource->presentations()['fields'] ?? null);
	$t->same(null, $resource->presentations()['entries'] ?? null);
})->tag('panel', 'presentation', 'resource', 'routing', 'brick', 'masonry')->maxMillis(2500);

test('brick convenience and item builders cover every structural owner collection', static function(Context $t): void {
	$page=PanelPage::make('brick-owner');
	foreach(['sections', 'entries', 'items', 'rows', 'tools', 'forms', 'tables', 'boardColumns', 'boardCards'] as $stem){
		$page=$page->{'brick'.ucfirst($stem)}();
	}
	$page=$page->rowItemPresentation(0, ['break_before'=>true])->brickCollection('', false);
	foreach(['sections', 'entries', 'items', 'rows', 'tools', 'forms', 'tables', 'board_columns', 'board_cards'] as $collection){
		$t->same('brick', $page->presentationFor($collection)['display']);
	}
	$t->same(true, $page->itemPresentationFor('rows', null, 0)['break_before'] ?? null);

	$field=Field::make('structure', 'builder');
	foreach(['rows', 'fields', 'actions', 'items', 'tools'] as $collection){
		$presentation=$collection.'Presentation';
		$display=$collection.'Display';
		$brick='brick'.ucfirst($collection);
		$masonry='masonry'.ucfirst($collection);
		$field=$field->{$presentation}('grid')->{$display}('inline')->{$brick}()->{$masonry}();
	}
	$field=$field
		->rowItemPresentation(0, ['span'=>2])
		->fieldItemPresentation('email', ['grow'=>2])
		->actionItemPresentation('add', ['order'=>-1])
		->nestedItemPresentation('hero', ['fill_remainder'=>true])
		->toolItemPresentation('remove', ['shrink'=>0])
		->collectionItemPresentations('fields', ['name'=>['break_before'=>true], 'ignored'=>'invalid'])
		->collectionFinalRow('rows', 'center')
		->collectionItemPresentation('', 'ignored', ['span'=>2])
		->collectionFinalRow('', 'end');
	$fieldMeta=$field->toArray()['meta'];
	$t->same('rows', $fieldMeta['rows_presentation']['masonry'] ?? null);
	$t->same('center', $fieldMeta['rows_presentation']['final_row'] ?? null);
	$t->same(['base'=>2], $fieldMeta['rows_presentation']['items']['#0']['span'] ?? null);
	$t->same(true, $fieldMeta['fields_presentation']['items']['name']['break_before'] ?? null);
	$t->same(null, $fieldMeta['fields_presentation']['items']['ignored'] ?? null);

	$infolist=Infolist::make();
	foreach(['entries', 'sections', 'tabs', 'steps'] as $collection){
		$presentation=$collection.'Presentation';
		$display=$collection.'Display';
		$brick='brick'.ucfirst($collection);
		$masonry='masonry'.ucfirst($collection);
		$infolist=$infolist->{$presentation}('grid')->{$display}('stack')->{$brick}()->{$masonry}();
	}
	$infolist=$infolist
		->entryItemPresentation('owner', ['span'=>2])
		->sectionItemPresentation('profile', ['grow'=>2])
		->tabItemPresentation('commerce', ['order'=>-1])
		->stepItemPresentation('review', ['fill_remainder'=>true])
		->collectionItemPresentations('entries', ['status'=>['shrink'=>0]])
		->collectionFinalRow('entries', 'preserve');
	$t->same('rows', $infolist->presentations()['entries']['masonry'] ?? null);
	$t->same('preserve', $infolist->presentations()['entries']['final_row'] ?? null);
	$t->same(['base'=>2], $infolist->schema()->itemPresentationFor('entries', 'owner')['span'] ?? null);
})->tag('panel', 'presentation', 'owners', 'brick', 'masonry', 'items')->maxMillis(2500);

test('item presentation normalizes responsive constraints immutably and rejects css injection', static function(Context $t): void {
	$item=PanelCollectionItemPresentation::make([
		'span'=>['base'=>2, 'md'=>20, 'bad'=>4],
		'basis'=>['base'=>'calc(100% - 1px)', 'md'=>'18rem'],
		'min_width'=>144,
		'max_width'=>['lg'=>'80%'],
		'grow'=>99,
		'shrink'=>-4,
		'order'=>999,
		'break_before'=>'yes',
		'fill_remaining'=>'off',
	]);
	$serialized=$item->toArray();

	$t->same(['base'=>2, 'md'=>12], $serialized['span'] ?? null);
	$t->same(['md'=>'18rem'], $serialized['basis'] ?? null);
	$t->same(['base'=>'144px'], $serialized['min_width'] ?? null);
	$t->same(['lg'=>'80%'], $serialized['max_width'] ?? null);
	$t->same(12, $serialized['grow'] ?? null);
	$t->same(0, $serialized['shrink'] ?? null);
	$t->same(100, $serialized['order'] ?? null);
	$t->same(true, $serialized['break_before'] ?? null);
	$t->same(false, $serialized['fill_remainder'] ?? null);
	$t->same($serialized, $item->jsonSerialize());
	$changed=$item->span(4, 'lg')->basis('320px');
	$t->same(null, $item->toArray()['span']['lg'] ?? null);
	$t->same(4, $changed->toArray()['span']['lg'] ?? null);
	$t->same('320px', $changed->toArray()['basis']['base'] ?? null);
	$attributes=PanelCollectionItemPresentation::htmlAttributes($item);
	$t->contains('data-dp-item-layout="1"', $attributes);
	$t->contains('--dp-item-min:144px', $attributes);
	$t->notContains('calc(', $attributes);
	$t->notContains('100% - 1px', $attributes);
	$t->same('', PanelCollectionItemPresentation::htmlAttributes());
})->tag('panel', 'presentation', 'items', 'normalization', 'security')->maxMillis(1000);

test('item grow shrink order break and fill controls are responsive without breaking scalar contracts', static function(Context $t): void {
	$scalar=PanelCollectionItemPresentation::make()
		->grow(2)
		->shrink(0)
		->order(-3)
		->breakBefore()
		->fillRemainder();
	$t->same(2, $scalar->toArray()['grow'] ?? null);
	$t->same(0, $scalar->toArray()['shrink'] ?? null);
	$t->same(-3, $scalar->toArray()['order'] ?? null);
	$t->same(true, $scalar->toArray()['break_before'] ?? null);
	$t->same(true, $scalar->toArray()['fill_remainder'] ?? null);
	$t->same('<span data-dp-item-break="1" aria-hidden="true"></span>', PanelCollectionItemPresentation::breakSentinelHtml($scalar));

	$responsive=PanelCollectionItemPresentation::make([
		'grow'=>['base'=>0.5, 'md'=>20, 'bad'=>4],
		'shrink'=>['base'=>1, 'lg'=>-3],
		'order'=>['base'=>2, 'sm'=>-120, '2xl'=>999],
		'break_before'=>['base'=>false, 'md'=>'yes'],
		'fill_remaining'=>['base'=>true, 'lg'=>'off'],
	]);
	$serialized=$responsive->toArray();
	$t->same(['base'=>0.5, 'md'=>12], $serialized['grow'] ?? null);
	$t->same(['base'=>1, 'lg'=>0], $serialized['shrink'] ?? null);
	$t->same(['base'=>2, 'sm'=>-100, '2xl'=>100], $serialized['order'] ?? null);
	$t->same(['base'=>false, 'md'=>true], $serialized['break_before'] ?? null);
	$t->same(['base'=>true, 'lg'=>false], $serialized['fill_remainder'] ?? null);
	$attributes=PanelCollectionItemPresentation::htmlAttributes($responsive);
	$t->contains('--dp-item-grow:0.5;--dp-item-grow-sm:0.5;--dp-item-grow-md:12;--dp-item-grow-lg:12', $attributes);
	$t->contains('--dp-item-shrink:1;--dp-item-shrink-sm:1;--dp-item-shrink-md:1;--dp-item-shrink-lg:0', $attributes);
	$t->contains('--dp-item-order:2;--dp-item-order-sm:-100;--dp-item-order-md:-100;--dp-item-order-lg:-100;--dp-item-order-xl:-100;--dp-item-order-2xl:100', $attributes);
	$t->contains('data-dp-item-break-before="responsive"', $attributes);
	$t->contains('data-dp-item-fill-remainder="responsive"', $attributes);
	$t->contains('data-dp-item-responsive="1"', $attributes);
	$t->contains('data-dp-item-responsive-tiers="sm md lg xl 2xl"', $attributes);
	$t->contains('--dp-item-fill-grid:1/-1;--dp-item-fill-grow:999', $attributes);
	$t->contains('--dp-item-fill-grid-lg:span var(--dp-item-span-active);--dp-item-fill-grow-lg:var(--dp-item-grow-active)', $attributes);
	$sentinel=PanelCollectionItemPresentation::breakSentinelHtml($responsive);
	$t->contains('data-dp-item-break="responsive"', $sentinel);
	$t->contains('data-dp-item-responsive="1"', $sentinel);
	$t->contains('--dp-item-break-display:none;--dp-item-break-display-sm:none;--dp-item-break-display-md:block', $sentinel);
	$t->same('', PanelCollectionItemPresentation::breakSentinelHtml(['break_before'=>['base'=>false, 'xl'=>false]]));

	$chained=PanelCollectionItemPresentation::make()->grow(1)->grow(4, 'lg')->order(-1)->order(6, 'xl')->fillRemainder(false)->fillRemainder(true, 'md');
	$t->same(['base'=>1, 'lg'=>4], $chained->toArray()['grow'] ?? null);
	$t->same(['base'=>-1, 'xl'=>6], $chained->toArray()['order'] ?? null);
	$t->same(['base'=>false, 'md'=>true], $chained->toArray()['fill_remainder'] ?? null);
	$merged=PanelCollectionItemPresentation::merge(
		['grow'=>1, 'order'=>['base'=>4, 'lg'=>8], 'break_before'=>false],
		['grow'=>['md'=>3], 'order'=>-2, 'break_before'=>['xl'=>true]],
	);
	$t->same(['base'=>1, 'md'=>3], $merged['grow'] ?? null);
	$t->same(['base'=>-2, 'lg'=>8], $merged['order'] ?? null);
	$t->same(['base'=>false, 'xl'=>true], $merged['break_before'] ?? null);

	$action=Action::make('responsive')
		->itemGrow(['base'=>0, 'md'=>2])
		->itemShrink(0, 'lg')
		->itemOrder(['base'=>3, 'xl'=>-1])
		->itemBreakBefore(true, 'md')
		->itemFillRemainder(['base'=>false, 'lg'=>true]);
	$item=$action->toArray()['meta']['item_presentation'] ?? [];
	$t->same(['base'=>0, 'md'=>2], $item['grow'] ?? null);
	$t->same(['lg'=>0], $item['shrink'] ?? null);
	$t->same(['base'=>3, 'xl'=>-1], $item['order'] ?? null);
	$t->same(['md'=>true], $item['break_before'] ?? null);
	$t->same(['base'=>false, 'lg'=>true], $item['fill_remainder'] ?? null);
})->tag('panel', 'presentation', 'items', 'responsive', 'builders', 'compatibility')->maxMillis(1500);

test('collection item precedence break sentinels style merging and computed spans are deterministic', static function(Context $t): void {
	$presentation=PanelCollectionPresentation::normalize([
		'display'=>'row_masonry',
		'columns'=>['base'=>3, 'md'=>4],
		'gap'=>'compact',
		'final_row'=>'center',
		'items'=>[
			'*'=>['grow'=>0.5],
			'#1'=>['span'=>2, 'break_before'=>true],
			'featured'=>['order'=>7],
		],
	]);
	$resolved=PanelCollectionPresentation::itemPresentation($presentation, 'Featured', 1, [
		'item_presentation'=>['max_width'=>'900px', 'fill_remainder'=>true],
	]);

	$t->same(0.5, $resolved['grow'] ?? null);
	$t->same(['base'=>2], $resolved['span'] ?? null);
	$t->same(7, $resolved['order'] ?? null);
	$t->same(['base'=>'900px'], $resolved['max_width'] ?? null);
	$t->same(true, $resolved['break_before'] ?? null);
	$t->same(true, $resolved['fill_remainder'] ?? null);
	$t->contains('data-dp-final-row="center"', PanelCollectionPresentation::htmlAttributes($presentation));
	$html=PanelCollectionPresentation::decorateItemHtml('<article class="probe" style="color:red">x</article>', $presentation, 'featured', 1, [
		'item_presentation'=>['fill_remainder'=>true],
	]);
	$t->contains('<span data-dp-item-break="1" aria-hidden="true"></span>', $html);
	$t->contains('data-dp-item-layout="1"', $html);
	$t->contains('data-dp-item-fill-remainder="1"', $html);
	$t->contains('color:red;--dp-item-span:2', $html);
	$t->contains('--dp-item-basis:calc(66.666667% - 2.6667px)', $html);
	$t->contains('--dp-item-basis-md:calc(50% - 4px)', $html);
	$t->same(1, substr_count($html, ' style='));
	$legacy='<a class="legacy">Legacy</a>';
	$t->same($legacy, PanelCollectionPresentation::decorateItemHtml($legacy, null, 'legacy', 0));
})->tag('panel', 'presentation', 'items', 'precedence', 'render')->maxMillis(1000);

test('owners expose immutable named positional wildcard and final row item builders', static function(Context $t): void {
	$base=ResourceTable::make();
	$configured=$base
		->masonryViews(true, ['columns'=>['base'=>2, 'lg'=>4]])
		->viewItemPresentation('*', ['shrink'=>0])
		->viewItemPresentation(1, ['span'=>2])
		->viewItemPresentation('attention', PanelCollectionItemPresentation::make()->fillRemainder())
		->collectionFinalRow('views', 'preserve');
	$options=Field::make('market', 'radio')
		->masonryOptions(true, ['columns'=>2])
		->optionItemPresentation('*', ['shrink'=>0])
		->optionItemPresentation('eu', ['fill_remainder'=>true])
		->optionsFinalRow('center');

	$t->same([], $base->presentations());
	$t->same('rows', $configured->presentationFor('views')['masonry'] ?? null);
	$t->same('preserve', $configured->presentationFor('views')['final_row'] ?? null);
	$t->same(0, $configured->itemPresentationFor('views', 'other', 0)['shrink'] ?? null);
	$t->same(['base'=>2], $configured->itemPresentationFor('views', 'other', 1)['span'] ?? null);
	$t->same(true, $configured->itemPresentationFor('views', 'attention', 3)['fill_remainder'] ?? null);
	$t->same('segmented', ResourceTable::make()->viewItemPresentation('all', ['order'=>-1])->presentationFor('views')['display'] ?? null);
	$t->same(true, $options->toArray()['meta']['options_presentation']['items']['eu']['fill_remainder'] ?? null);
	$t->same(0, $options->toArray()['meta']['options_presentation']['items']['*']['shrink'] ?? null);
	$t->same('center', $options->toArray()['meta']['options_presentation']['final_row'] ?? null);
})->tag('panel', 'presentation', 'items', 'builders', 'immutable')->maxMillis(1000);

test('major collection primitives serialize their local item presentation contracts', static function(Context $t): void {
	$objects=[
		Widget::make('revenue')->itemSpan(['base'=>1, 'lg'=>2]),
		Action::make('assign')->itemGrow(2),
		ActionGroup::make('operations')->itemOrder(3),
		TableView::make('attention')->itemFillRemainder(),
		TableGroup::make('market')->itemBreakBefore(),
		TableSummary::make('gross')->itemBasis('240px'),
		TableFilter::make('status')->itemMinWidth('12rem'),
		FormSection::make('profile')->itemSpan(2),
		Field::make('email')->itemMaxWidth('48rem'),
		SchemaComponent::make('tab', 'commerce')->itemOrder(-2),
		InfolistEntry::make('owner')->itemSpan(2),
	];

	foreach($objects as $object){
		$meta=$object->toArray()['meta'] ?? [];
		$t->same(true, is_array($meta['item_presentation'] ?? null) && $meta['item_presentation']!==[]);
	}
	$fieldComponent=SchemaComponent::field(Field::make('company'))->itemSpan(2);
	$t->same(['base'=>2], $fieldComponent->fieldsList()['company']->toArray()['meta']['item_presentation']['span'] ?? null);
	$tabComponent=SchemaComponent::tab('commerce', [Field::make('market')])->itemOrder(-3);
	$tabSections=$tabComponent->sectionsList();
	$tabSection=reset($tabSections);
	$t->same(-3, $tabSection instanceof FormSection ? ($tabSection->toArray()['meta']['tab_item_presentation']['order'] ?? null) : null);
})->tag('panel', 'presentation', 'items', 'primitives', 'serialization')->maxMillis(1500);

test('brick v2 css owns responsive item layout without changing unconfigured markup', static function(Context $t): void {
	$css=(string)(\Dataphyre\Panel\PanelRenderer::assetContent('panel.css')['content'] ?? '');
	$t->contains('dp-owner:brick-v2', $css);
	$t->contains('[data-dp-item-layout="1"]{box-sizing:border-box', $css);
	$t->contains('[data-dp-item-fill-remainder="1"]{grid-column:1/-1}', $css);
	$t->contains('[data-dp-final-row="preserve"]>*{--dp-item-fill-grow-active:0;flex-grow:0}', $css);
	$t->contains('[data-dp-item-break="1"]{display:block;flex:0 0 100%}', $css);
	$t->contains('--dp-item-span-2xl', $css);
	$t->contains('--dp-item-grow-2xl', $css);
	$t->contains('--dp-item-shrink-active', $css);
	$t->contains('--dp-item-order-active', $css);
	$t->contains('[data-dp-item-fill-remainder="responsive"]{grid-column:var(--dp-item-fill-grid-active)}', $css);
	$t->contains('[data-dp-item-break="responsive"]{display:var(--dp-item-break-display-active', $css);
	$t->contains('.dp-panel-main-region,.dp-panel-modal-body{container-name:dp-panel-layout;container-type:inline-size}', $css);
	$t->contains('@container dp-panel-layout (max-width:1279px)', $css);
	$t->contains('@container dp-panel-layout (max-width:400px)', $css);
	$t->contains('@media(max-width:639px){body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-item-layout="1"]{--dp-item-span-active:1;--dp-item-basis-active:100%', $css);
	$t->lessThanOrEqual(dp_panel_asset_budgets()['cssBytes'], strlen($css));
})->tag('panel', 'presentation', 'items', 'css', 'responsive')->maxMillis(4000);

test('brick v2 renderers emit item contracts for widgets summaries choices sections and fields', static function(Context $t): void {
	$renderer=$t->nonPublic(\Dataphyre\Panel\PanelRenderer::class);
	$widgetPresentation=[
		'display'=>'masonry',
		'masonry'=>'rows',
		'columns'=>['base'=>2],
		'items'=>['revenue'=>['span'=>2]],
	];
	$widgets=[
		Widget::make('revenue')->itemOrder(-1)->toArray(),
		Widget::make('orders')->toArray(),
	];
	$widgetHtml=$renderer->invoke('widgetsHtml', $widgets, $widgetPresentation);
	$t->contains('data-dp-item-layout="1"', $widgetHtml);
	$t->contains('--dp-item-span:2', $widgetHtml);
	$t->contains('--dp-item-order:-1', $widgetHtml);
	$t->same(1, substr_count($widgetHtml, 'data-dp-item-layout="1"'));

	$summaryHtml=$renderer->invoke('summaryHtml', [[
		'name'=>'gross',
		'label'=>'Gross',
		'value'=>125,
		'formatted'=>'$125',
		'type'=>'sum',
		'meta'=>['item_presentation'=>['fill_remainder'=>true]],
	]], false, ['display'=>'brick', 'items'=>['gross'=>['span'=>2]]]);
	$t->contains('data-dp-item-fill-remainder="1"', $summaryHtml);
	$t->contains('--dp-item-span:2', $summaryHtml);

	$choiceMeta=[
		'name'=>'market',
		'type'=>'radio',
		'label'=>'Market',
		'options'=>[
			'ca'=>['label'=>'Canada'],
			'eu'=>['label'=>'European Union', 'item_presentation'=>['fill_remainder'=>true]],
		],
		'meta'=>['options_presentation'=>[
			'display'=>'row_masonry',
			'columns'=>2,
			'items'=>['eu'=>['span'=>2]],
		]],
	];
	$choiceHtml=$renderer->invoke('choiceControl', 'market', $choiceMeta, 'ca', false);
	$t->contains('data-dp-item-fill-remainder="1"', $choiceHtml);
	$t->contains('--dp-item-basis:100%', $choiceHtml);

	$sections=['Profile'=>[[
		'html'=>'<label class="dp-panel-field" style="grid-column:span 1">Email</label>',
		'name'=>'email',
		'meta'=>['item_presentation'=>['span'=>2, 'order'=>1]],
	]]];
	$sectionMeta=['profile'=>[
		'name'=>'profile',
		'label'=>'Profile',
		'meta'=>['item_presentation'=>['order'=>-1]],
	]];
	$sectionHtml=$renderer->invoke('formSectionsHtml', $sections, 2, $sectionMeta, [
		'sections'=>['display'=>'stack'],
		'fields'=>['display'=>'grid', 'columns'=>2, 'fit'=>'fixed'],
	]);
	$t->contains('--dp-item-order:-1', $sectionHtml);
	$t->contains('grid-column:span 1;--dp-item-span:2;--dp-item-order:1', $sectionHtml);
	$t->same(2, substr_count($sectionHtml, 'data-dp-item-layout="1"'));

	$legacy=$renderer->invoke('widgetsHtml', [Widget::make('plain')->toArray()], ['display'=>'grid']);
	$t->notContains('data-dp-item-layout', $legacy);
})->tag('panel', 'presentation', 'items', 'renderer', 'contract')->maxMillis(4000);

test('compiled panel CSS carries collection display modes and renderer attributes', static function(Context $t): void {
	$css=(string)(\Dataphyre\Panel\PanelRenderer::assetContent('panel.css')['content'] ?? '');
	$tables=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/PanelRendererTables.php');
	$pages=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/PanelRendererPages.php');
	$forms=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/PanelRendererForms.php');

	foreach(['inline', 'segmented', 'brick', 'stack', 'grid', 'masonry'] as $display){
		$t->contains('[data-dp-display="'.$display.'"]', $css);
	}
	$t->contains('PanelCollectionPresentation::htmlAttributes', $tables);
	$t->contains("presentationFor('views', 'segmented')", $tables);
	$t->contains("presentationFor('groups', 'segmented')", $pages);
	$t->contains("presentationFor('filters', 'grid')", $pages);
	$t->contains("tablePresentations['actions']", $pages);
	$t->contains('options_presentation', $forms);
	$t->contains('.dp-panel-choice-list[data-dp-display="brick"]', $css);
	$t->contains('--dp-collection-columns-sm', $css);
	$t->contains('[data-dp-display="masonry"][data-dp-masonry="rows"]', $css);
	$t->contains('.dp-panel-modal-root) :is([data-dp-display="masonry"]', $css);
	$t->contains('--dp-collection-basis-active:100%', $css);
	$t->contains('[data-dp-masonry="columns"][data-dp-columns="auto"]{columns:auto', $css);
	$t->contains('body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-primary>:last-child:nth-child(odd){grid-column:1/-1}', $css);
	$t->contains('[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-nav{display:grid;grid-template-columns:minmax(0,1fr)', $css);
	$t->contains('body[data-dp-theme-effects~="flat_minima"] .dp-panel[data-dp-panel-kind] .dp-panel-main-region>.dp-panel-widgets[data-dp-display]', $css);
	$t->contains('.dp-panel-main-region>.dp-panel-widgets[data-dp-display]{--dp-collection-gap:1px;gap:1px;', $css);
	$t->contains('.dp-panel-main-region>.dp-panel-widgets .dp-panel-widget{min-height:104px;margin:0;border:0;', $css);
	$t->contains(':where(.dp-panel,.dp-panel-modal-root) :is([data-dp-display="brick"],[data-dp-fit="fill"],[data-dp-display="masonry"][data-dp-masonry="rows"]) :is(.dp-panel-action,.dp-panel-button){width:100%;', $css);
	$t->contains('@media(max-width:639px){body :is(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-display]{--dp-collection-columns-active:1}', $css);
	$t->contains(':is(.dp-panel-density,.dp-panel-table-views,.dp-panel-table-groups):not([data-dp-display]){display:flex;', $css);
	$t->contains(':is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-fit="fill"])>.dp-panel-inline-action{height:auto;min-height:52px;align-self:stretch}', $css);
	$t->contains('.dp-panel:is([data-dp-panel-kind="create"],[data-dp-panel-kind="edit"],[data-dp-panel-kind$="_form"]) .dp-panel-field input:where(:not([type="checkbox"]):not([type="radio"]))', $css);
	$t->contains(':is(.dp-panel,.dp-panel-modal-root) .dp-panel-choice>input{flex:0 0 20px;width:20px;height:20px;min-width:20px;min-height:20px;margin:0;', $css);
	$t->contains(':is(.dp-panel,.dp-panel-modal-root) .dp-panel-choice{min-height:var(--dp-vs-control-md);cursor:pointer}', $css);
	$t->contains('.dp-panel-choice:has(>input:checked){border-color:color-mix(', $css);
	$t->contains(':is(.dp-panel-choice-disabled,.dp-panel-choice:has(>input:disabled)){cursor:not-allowed;opacity:.58}', $css);
	$t->contains('@media(forced-colors:active){:is(.dp-panel,.dp-panel-modal-root) .dp-panel-choice:has(>input:checked){border-color:Highlight;background:Canvas}', $css);
	$t->contains('.dp-panel-form-grid{--dp-grid-cols-active:var(--dp-grid-cols,1);--dp-grid-auto-span-active:var(--dp-grid-auto-span,1)}', $css);
	$t->contains('.dp-panel-field-boolean .dp-panel-switch{position:relative;display:flex;align-items:center;', $css);
	$t->contains('.dp-panel-switch>input[type="checkbox"]{position:absolute;width:1px;height:1px;', $css);
	$t->contains('.dp-panel-switch:has(>input[type="checkbox"]:checked) .dp-panel-switch-track{border-color:var(--dp-primary-600,#2563eb);background:var(--dp-primary-600,#2563eb)}', $css);
	$t->notContains('.dp-panel[data-dp-panel-kind="create"] .dp-panel-field input,', $css);
	$t->notContains('.dp-panel-modal-body .dp-panel-form-grid .dp-panel-field-boolean{--dp-grid-column:1/-1', $css);
})->tag('panel', 'presentation', 'renderer', 'css', 'brick')->maxMillis(4000);

test('required and dirty markers stay on semantic labels instead of decorated input shells', static function(Context $t): void {
	$components=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/Assets/PanelRendererAssetsComponentCss.php');
	$presentation=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/Assets/PanelRendererAssetsPresentationCss.php');
	$mobile=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/Assets/PanelRendererAssetsMobileCss.php');

	$t->contains('.dp-panel-field-required>.dp-panel-field-label>.dp-panel-field-label-text:after', $components);
	$t->contains('.dp-panel-field-dirty>.dp-panel-field-label:before', $components);
	$t->contains('.dp-panel-field-dirty>.dp-panel-field-label:before', $presentation);
	$t->notContains('.dp-panel-field-required>span:after', $components);
	$t->notContains('.dp-panel-field-dirty>span:before', $components);
	$t->contains(':is(.dp-panel-tab-panel,.dp-panel-step-panel)>.dp-panel-form-section{width:100%;max-width:100%;margin-inline:0}', $mobile);
})->tag('panel', 'presentation', 'forms', 'markers', 'modal')->maxMillis(1000);
