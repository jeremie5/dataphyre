<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Action;
use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\PanelComponentRegistry;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\RelationManager;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\SchemaComponent;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Panel\TableSummary;
use Dataphyre\Panel\TableView;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

/** @return array<string,Closure> */
function dp_panel_component_registry_hooks(array $names,string $prefix): array {
	$hooks=[];
	foreach($names as $name){
		$hooks[$name]=static fn(mixed ...$arguments): string=>$prefix.':'.$name.':'.count($arguments);
	}
	return $hooks;
}

test('panel component registry boots every built in descriptor family',static function(Context $t): void {
	$registry=$t->nonPublic(PanelComponentRegistry::class)->withoutConstructor();
	$t->nonPublic($registry)->invoke('__construct');
	$t->isTrue($registry instanceof PanelComponentRegistry);

	PanelComponentRegistry::flush();
	$t->isTrue(isset(PanelComponentRegistry::schemaKinds()['section']));
	$t->isTrue(isset(PanelComponentRegistry::fieldTypes()['address']));
	$t->isTrue(isset(PanelComponentRegistry::columnTypes()['money']));
	$t->isTrue(isset(PanelComponentRegistry::actionTypes()['modal']));
	$t->isTrue(isset(PanelComponentRegistry::filterTypes()['number_range']));
	$t->isTrue(isset(PanelComponentRegistry::relationTypes()['morph_many']));
	$t->isTrue(isset(PanelComponentRegistry::widgetTypes()['chart']));
	$t->isTrue(isset(PanelComponentRegistry::pageTypes()['settings']));
	$t->isTrue(isset(PanelComponentRegistry::resourceTypes()['readonly']));
	$t->isTrue(isset(PanelComponentRegistry::summaryTypes()['average']));
	$t->isTrue(isset(PanelComponentRegistry::viewTypes()['queue']));
	$t->isTrue(isset(PanelComponentRegistry::navigationTypes()['workspace']));
	$t->isTrue(isset(PanelComponentRegistry::exportTypes()['csv']));
	$t->isTrue(isset(PanelComponentRegistry::importTypes()['upsert']));
	$t->isTrue(isset(PanelComponentRegistry::bulkOperationTypes()['bulk_restore']));

	$money=PanelComponentRegistry::fieldTypeDefinition('money');
	$t->same('number',$money['input']);
	$t->isTrue(in_array('currency_format',$money['capabilities'],true));
	$t->isTrue(PanelComponentRegistry::fieldTypeIsFileUpload('image'));
	$t->isFalse(PanelComponentRegistry::fieldTypeIsFileUpload('text'));

	$t->nonPublic(PanelComponentRegistry::class)->writeProperty('schemaKinds',[]);
	PanelComponentRegistry::boot();
	PanelComponentRegistry::boot();
	$t->isTrue(PanelComponentRegistry::schemaKindRegistered('field'));
});

test('panel component registry handles schema field column action filter and relation extensions',static function(Context $t): void {
	PanelComponentRegistry::flush();
	$renderer=static fn(mixed ...$arguments): string=>'<rendered '.count($arguments).'>';
	$notString=static fn(mixed ...$arguments): array=>$arguments;

	$t->same('schema_custom',PanelComponentRegistry::registerSchemaKind(' Schema Custom ',$renderer));
	$t->same('schema-meta',PanelComponentRegistry::registerSchemaKind('schema-meta',['renderer'=>$renderer,'label'=>'Schema meta']));
	$t->same('',PanelComponentRegistry::registerSchemaKind('   '));
	$t->isTrue(PanelComponentRegistry::schemaKindRegistered('SCHEMA CUSTOM'));
	$t->same('schema_custom',PanelComponentRegistry::normalizeSchemaKind('schema custom'));
	$t->same('field',PanelComponentRegistry::normalizeSchemaKind('missing'));
	PanelComponentRegistry::registerSchemaKind('section',$renderer);
	$t->same('<rendered 2>',PanelComponentRegistry::renderSchemaComponent(SchemaComponent::make('section','node'),['slot'=>'main']));
	PanelComponentRegistry::registerSchemaKind('tab',$notString);
	$t->same(null,PanelComponentRegistry::renderSchemaComponent(SchemaComponent::make('tab','node')));
	$t->same(null,PanelComponentRegistry::renderSchemaComponent(SchemaComponent::make('field','plain')));

	$fieldHooks=dp_panel_component_registry_hooks(['hydrate','dehydrate','validate','display','options','cast'],'field');
	$t->same('fancy_input',PanelComponentRegistry::registerFieldType(' Fancy Input ',$renderer,array_replace($fieldHooks,['file_upload'=>true,'label'=>'Fancy'])));
	PanelComponentRegistry::registerFieldType('fancy_input',null,['description'=>'kept']);
	$t->isTrue(PanelComponentRegistry::fieldTypeRegistered('fancy input'));
	$t->same('Fancy',PanelComponentRegistry::fieldTypeDefinition('fancy_input')['label']);
	$t->isTrue(PanelComponentRegistry::fieldTypeIsFileUpload('fancy_input'));
	$t->isTrue(PanelComponentRegistry::fieldTypeHasHook('fancy_input','hydrate'));
	$t->same('field:hydrate:2',PanelComponentRegistry::callFieldTypeHook('fancy_input','hydrate',Field::make('title'),'raw'));
	$t->same(null,PanelComponentRegistry::callFieldTypeHook('text','missing',Field::make('title')));
	$t->same('<rendered 4>',PanelComponentRegistry::renderFieldControl('fancy_input','title',['required'=>true],'value',['record'=>1]));
	PanelComponentRegistry::registerFieldType('field_array',$notString);
	$t->same(null,PanelComponentRegistry::renderFieldControl('field_array','title',[],null));
	$t->same(null,PanelComponentRegistry::renderFieldControl('text','title',[],null));
	$t->same('',PanelComponentRegistry::registerFieldType('  '));

	$columnHooks=dp_panel_component_registry_hooks(['value','format','search','sort','export','summary'],'column');
	$t->same('custom_column',PanelComponentRegistry::registerColumnType('Custom Column',$renderer,$columnHooks));
	PanelComponentRegistry::registerColumnType('custom-column',null,['label'=>'Custom column']);
	$t->isTrue(PanelComponentRegistry::columnTypeRegistered('custom column'));
	$t->isTrue(PanelComponentRegistry::columnTypeHasHook('custom_column','format'));
	$t->same('column:format:2',PanelComponentRegistry::callColumnTypeHook('custom_column','format',Column::make('total'),'raw'));
	$t->same(null,PanelComponentRegistry::callColumnTypeHook('text','missing',Column::make('total')));
	$t->same('<rendered 6>',PanelComponentRegistry::renderColumnCell('custom_column',Column::make('total'),['id'=>1],10,'10',[],[]));
	PanelComponentRegistry::registerColumnType('column_array',$notString);
	$t->same(null,PanelComponentRegistry::renderColumnCell('column_array',Column::make('total'),null,null,'',[]));
	$t->same(null,PanelComponentRegistry::renderColumnCell('text',Column::make('total'),null,null,'',[]));
	$t->same('',PanelComponentRegistry::registerColumnType('  '));

	$actionHooks=dp_panel_component_registry_hooks(['authorize','prepare','after'],'action');
	$t->same('custom_action',PanelComponentRegistry::registerActionType('Custom Action',$renderer,$actionHooks));
	PanelComponentRegistry::registerActionType('custom_action',null,['label'=>'Custom action']);
	$t->isTrue(PanelComponentRegistry::actionTypeRegistered('custom action'));
	$t->isTrue(PanelComponentRegistry::actionTypeHasHook('custom_action','authorize'));
	$t->isTrue(PanelComponentRegistry::actionTypeDefinition('custom_action')['handler'] instanceof Closure);
	$t->same('action:authorize:2',PanelComponentRegistry::callActionTypeHook('custom_action','authorize',Action::make('approve'),'record'));
	$t->same(null,PanelComponentRegistry::callActionTypeHook('button','missing',Action::make('approve')));
	$t->same([],PanelComponentRegistry::actionTypeDefinition('not_registered'));
	$t->same('',PanelComponentRegistry::registerActionType(' '));

	$filterHooks=dp_panel_component_registry_hooks(['active','options','match','label'],'filter');
	$t->same('custom_filter',PanelComponentRegistry::registerFilterType('Custom Filter',$renderer,array_replace($filterHooks,['range'=>true])));
	PanelComponentRegistry::registerFilterType('custom_filter',null,['label'=>'Custom filter']);
	$t->isTrue(PanelComponentRegistry::filterTypeRegistered('custom filter'));
	$t->isTrue(PanelComponentRegistry::filterTypeHasHook('custom_filter','active'));
	$t->isTrue(PanelComponentRegistry::filterTypeIsRange('custom_filter'));
	$t->isFalse(PanelComponentRegistry::filterTypeIsRange('text'));
	$t->same('filter:active:2',PanelComponentRegistry::callFilterTypeHook('custom_filter','active',TableFilter::make('status'),'value'));
	$t->same(null,PanelComponentRegistry::callFilterTypeHook('text','missing',TableFilter::make('status')));
	$request=PanelRequest::fromArray([]);
	$t->same('<rendered 5>',PanelComponentRegistry::renderFilterControl('custom_filter',TableFilter::make('status'),$request,[],null,[]));
	PanelComponentRegistry::registerFilterType('filter_array',$notString);
	$t->same(null,PanelComponentRegistry::renderFilterControl('filter_array',TableFilter::make('status'),$request,[],null));
	$t->same(null,PanelComponentRegistry::renderFilterControl('text',TableFilter::make('status'),$request,[],null));
	$t->same('',PanelComponentRegistry::registerFilterType(' '));

	$relationHooks=dp_panel_component_registry_hooks(['authorize','query','records','before_records','after_records','empty_state'],'relation');
	$t->same('custom_relation',PanelComponentRegistry::registerRelationType('Custom Relation',$renderer,$relationHooks));
	PanelComponentRegistry::registerRelationType('custom_relation',null,['label'=>'Custom relation']);
	$t->isTrue(PanelComponentRegistry::relationTypeRegistered('custom relation'));
	$t->isTrue(PanelComponentRegistry::relationTypeHasHook('custom_relation','records'));
	$relation=RelationManager::make('items');
	$resource=Resource::make('orders');
	$t->same('relation:records:2',PanelComponentRegistry::callRelationTypeHook('custom_relation','records',$relation,'record'));
	$t->same(null,PanelComponentRegistry::callRelationTypeHook('table','missing',$relation));
	$t->same('<rendered 5>',PanelComponentRegistry::renderRelation('custom_relation',$relation,$resource,$request,['id'=>1],[]));
	PanelComponentRegistry::registerRelationType('relation_array',$notString);
	$t->same(null,PanelComponentRegistry::renderRelation('relation_array',$relation,$resource,$request,null));
	$t->same(null,PanelComponentRegistry::renderRelation('table',$relation,$resource,$request,null));
	$t->same('',PanelComponentRegistry::registerRelationType(' '));
});

test('panel component registry handles widget page resource summary and view extensions',static function(Context $t): void {
	PanelComponentRegistry::flush();
	$renderer=static fn(mixed ...$arguments): string=>'<rendered '.count($arguments).'>';
	$notString=static fn(mixed ...$arguments): array=>$arguments;
	$request=PanelRequest::fromArray([]);

	$widgetHooks=dp_panel_component_registry_hooks(['authorize','value','format','data','after_resolve'],'widget');
	$t->same('custom_widget',PanelComponentRegistry::registerWidgetType('Custom Widget',$renderer,$widgetHooks));
	PanelComponentRegistry::registerWidgetType('custom_widget',null,['label'=>'Custom widget']);
	$t->isTrue(PanelComponentRegistry::widgetTypeRegistered('custom widget'));
	$t->isTrue(PanelComponentRegistry::widgetTypeHasHook('custom_widget','data'));
	$t->same('widget:data:2',PanelComponentRegistry::callWidgetTypeHook('custom_widget','data',Widget::make('sales'),'request'));
	$t->same(null,PanelComponentRegistry::callWidgetTypeHook('stat','missing',Widget::make('sales')));
	$t->same('<rendered 2>',PanelComponentRegistry::renderWidget('custom_widget',['name'=>'sales'],[]));
	PanelComponentRegistry::registerWidgetType('widget_array',$notString);
	$t->same(null,PanelComponentRegistry::renderWidget('widget_array',[]));
	$t->same(null,PanelComponentRegistry::renderWidget('stat',[]));
	$t->same([],PanelComponentRegistry::widgetTypeDefinition('missing'));
	$t->same('',PanelComponentRegistry::registerWidgetType(' '));

	$pageHooks=dp_panel_component_registry_hooks(['authorize','before_render','after_render','widgets','tables','data'],'page');
	$t->same('custom_page',PanelComponentRegistry::registerPageType('Custom Page',$renderer,$pageHooks));
	PanelComponentRegistry::registerPageType('custom_page',null,['label'=>'Custom page']);
	$t->isTrue(PanelComponentRegistry::pageTypeRegistered('custom page'));
	$t->isTrue(PanelComponentRegistry::pageTypeHasHook('custom_page','before_render'));
	$page=PanelPage::make('reports');
	$t->same('page:before_render:2',PanelComponentRegistry::callPageTypeHook('custom_page','before_render',$page,'context'));
	$t->same(null,PanelComponentRegistry::callPageTypeHook('custom','missing',$page));
	$t->same('<rendered 4>',PanelComponentRegistry::renderPage('custom_page',$page,$request,PanelManager::instance(),[]));
	$t->same(null,PanelComponentRegistry::renderPage('custom',$page,$request));
	$t->same([],PanelComponentRegistry::pageTypeDefinition('missing'));
	$t->same('',PanelComponentRegistry::registerPageType(' '));

	$t->same('custom_resource',PanelComponentRegistry::registerResourceType('Custom Resource',static fn(mixed ...$arguments): string=>'query:'.count($arguments)));
	$resourceHooks=dp_panel_component_registry_hooks(['authorize','query','save','navigation','record_key','record_title','record_subtitle','record_url','global_search','describe'],'resource');
	PanelComponentRegistry::registerResourceType('custom_resource',array_replace($resourceHooks,['label'=>'Custom resource']));
	$t->isTrue(PanelComponentRegistry::resourceTypeRegistered('custom resource'));
	$t->isTrue(PanelComponentRegistry::resourceTypeHasHook('custom_resource','query'));
	$resource=Resource::make('orders');
	$t->same('resource:query:2',PanelComponentRegistry::callResourceTypeHook('custom_resource','query',$resource,'scope'));
	$t->same(null,PanelComponentRegistry::callResourceTypeHook('resource','missing',$resource));
	$t->same([],PanelComponentRegistry::resourceTypeDefinition('missing'));
	$t->same('',PanelComponentRegistry::registerResourceType(' '));

	$summaryHooks=dp_panel_component_registry_hooks(['aggregate','format','data'],'summary');
	$t->same('custom_summary',PanelComponentRegistry::registerSummaryType('Custom Summary',$renderer,$summaryHooks));
	PanelComponentRegistry::registerSummaryType('custom_summary',null,['label'=>'Custom summary']);
	$t->isTrue(PanelComponentRegistry::summaryTypeRegistered('custom summary'));
	$t->isTrue(PanelComponentRegistry::summaryTypeHasHook('custom_summary','aggregate'));
	$summary=TableSummary::make('total');
	$t->same('summary:aggregate:2',PanelComponentRegistry::callSummaryTypeHook('custom_summary','aggregate',$summary,[1,2]));
	$t->same(null,PanelComponentRegistry::callSummaryTypeHook('count','missing',$summary));
	$t->same('<rendered 2>',PanelComponentRegistry::renderSummary('custom_summary',['value'=>3],[]));
	PanelComponentRegistry::registerSummaryType('summary_array',$notString);
	$t->same(null,PanelComponentRegistry::renderSummary('summary_array',[]));
	$t->same(null,PanelComponentRegistry::renderSummary('count',[]));
	$t->same([],PanelComponentRegistry::summaryTypeDefinition('missing'));
	$t->same('',PanelComponentRegistry::registerSummaryType(' '));

	$viewHooks=dp_panel_component_registry_hooks(['query_defaults','match','badge','label','data'],'view');
	$t->same('custom_view',PanelComponentRegistry::registerViewType('Custom View',$renderer,$viewHooks));
	PanelComponentRegistry::registerViewType('custom_view',null,['label'=>'Custom view']);
	$t->isTrue(PanelComponentRegistry::viewTypeRegistered('custom view'));
	$t->isTrue(PanelComponentRegistry::viewTypeHasHook('custom_view','match'));
	$view=TableView::make('open');
	$t->same('view:match:2',PanelComponentRegistry::callViewTypeHook('custom_view','match',$view,'state'));
	$t->same(null,PanelComponentRegistry::callViewTypeHook('view','missing',$view));
	$t->same('<rendered 3>',PanelComponentRegistry::renderTableView('custom_view',$view,['records'=>[]],[]));
	PanelComponentRegistry::registerViewType('view_array',$notString);
	$t->same(null,PanelComponentRegistry::renderTableView('view_array',$view,[]));
	$t->same(null,PanelComponentRegistry::renderTableView('view',$view,[]));
	$t->same([],PanelComponentRegistry::viewTypeDefinition('missing'));
	$t->same('',PanelComponentRegistry::registerViewType(' '));
});

test('panel component registry prepares navigation and bulk operations and handles data transfer types',static function(Context $t): void {
	PanelComponentRegistry::flush();
	$renderer=static fn(mixed ...$arguments): string=>'<rendered '.count($arguments).'>';
	$notString=static fn(mixed ...$arguments): array=>$arguments;
	$request=PanelRequest::fromArray([]);
	$manager=PanelManager::instance();
	$resource=Resource::make('orders');

	$t->same('custom_navigation',PanelComponentRegistry::registerNavigationType('Custom Navigation',$renderer,[
		'visible'=>static fn(mixed ...$arguments): bool=>true,
		'entry'=>static fn(mixed ...$arguments): array=>['url'=>'/custom'],
		'label'=>static fn(mixed ...$arguments): string=>'Custom label',
		'group'=>static fn(mixed ...$arguments): null=>null,
		'badge'=>static fn(mixed ...$arguments): int=>7,
		'sort'=>static fn(mixed ...$arguments): int=>20,
	]));
	PanelComponentRegistry::registerNavigationType('custom_navigation',null,['description'=>'kept']);
	$t->isTrue(PanelComponentRegistry::navigationTypeRegistered('custom navigation'));
	$t->isTrue(PanelComponentRegistry::navigationTypeHasHook('custom_navigation','visible'));
	$t->same('Custom label',PanelComponentRegistry::callNavigationTypeHook('custom_navigation','label',['name'=>'orders'],$request,$manager));
	$t->same(null,PanelComponentRegistry::callNavigationTypeHook('item','missing',[]));
	$entry=PanelComponentRegistry::prepareNavigationEntry(['navigation_type'=>'custom navigation','name'=>'orders','group'=>'Original'],$request,$manager);
	$t->same('Custom label',$entry['label']);
	$t->same('Original',$entry['group']);
	$t->same('/custom',$entry['url']);
	$t->isTrue($entry['type_hooks']['renderer']);
	$t->same('<rendered 2>',PanelComponentRegistry::renderNavigationEntry('custom_navigation',$entry,[]));
	PanelComponentRegistry::registerNavigationType('navigation_array',$notString);
	$t->same(null,PanelComponentRegistry::renderNavigationEntry('navigation_array',[]));
	$t->same(null,PanelComponentRegistry::renderNavigationEntry('item',[]));
	PanelComponentRegistry::registerNavigationType('hidden_navigation',null,['visible'=>static fn(mixed ...$arguments): bool=>false]);
	$t->same(null,PanelComponentRegistry::prepareNavigationEntry(['kind'=>'hidden_navigation'],$request,$manager));
	$plain=PanelComponentRegistry::prepareNavigationEntry(['navigation_type'=>'   ','name'=>'plain']);
	$t->same('item',$plain['navigation_type']);
	$t->isFalse($plain['type_hooks']['renderer']);
	$t->same([],PanelComponentRegistry::navigationTypeDefinition('missing'));
	$t->same('',PanelComponentRegistry::registerNavigationType(' '));

	$t->same('custom_export',PanelComponentRegistry::registerExportType('Custom Export',static fn(mixed ...$arguments): array=>$arguments));
	$exportHooks=dp_panel_component_registry_hooks(['authorize','format','columns','records','row','payload','filename','button'],'export');
	PanelComponentRegistry::registerExportType('custom_export',array_replace($exportHooks,['label'=>'Custom export']));
	$t->isTrue(PanelComponentRegistry::exportTypeRegistered('custom export'));
	$t->isTrue(PanelComponentRegistry::exportTypeHasHook('custom_export','records'));
	$t->same('export:records:2',PanelComponentRegistry::callExportTypeHook('custom_export','records',$resource,'query'));
	$t->same(null,PanelComponentRegistry::callExportTypeHook('csv','missing',$resource));
	$t->same([],PanelComponentRegistry::exportTypeDefinition('missing'));
	$t->same('',PanelComponentRegistry::registerExportType(' '));

	$t->same('custom_import',PanelComponentRegistry::registerImportType('Custom Import',static fn(mixed ...$arguments): array=>$arguments));
	$importHooks=dp_panel_component_registry_hooks(['authorize','columns','parse','validate','before_import','import','after_import','template','button'],'import');
	PanelComponentRegistry::registerImportType('custom_import',array_replace($importHooks,['label'=>'Custom import']));
	$t->isTrue(PanelComponentRegistry::importTypeRegistered('custom import'));
	$t->isTrue(PanelComponentRegistry::importTypeHasHook('custom_import','parse'));
	$t->same('import:parse:2',PanelComponentRegistry::callImportTypeHook('custom_import','parse',$resource,'csv'));
	$t->same(null,PanelComponentRegistry::callImportTypeHook('upsert','missing',$resource));
	$t->same([],PanelComponentRegistry::importTypeDefinition('missing'));
	$t->same('',PanelComponentRegistry::registerImportType(' '));

	$t->same('custom_bulk',PanelComponentRegistry::registerBulkOperationType('Custom Bulk',$renderer,[
		'authorize'=>static fn(mixed ...$arguments): bool=>true,
		'operation'=>static fn(mixed ...$arguments): array=>['method'=>'POST'],
		'label'=>static fn(mixed ...$arguments): string=>'Archive selected',
		'tone'=>static fn(mixed ...$arguments): string=>'warning',
		'icon'=>static fn(mixed ...$arguments): null=>null,
		'confirm'=>static fn(mixed ...$arguments): bool=>false,
		'url'=>static fn(mixed ...$arguments): string=>'/archive',
	]));
	PanelComponentRegistry::registerBulkOperationType('custom_bulk',null,['description'=>'kept']);
	$t->isTrue(PanelComponentRegistry::bulkOperationTypeRegistered('custom bulk'));
	$t->isTrue(PanelComponentRegistry::bulkOperationTypeHasHook('custom_bulk','operation'));
	$t->same('Archive selected',PanelComponentRegistry::callBulkOperationTypeHook('custom_bulk','label',['name'=>'archive'],$resource,$request));
	$t->same(null,PanelComponentRegistry::callBulkOperationTypeHook('bulk_action','missing',[]));
	$operation=PanelComponentRegistry::prepareBulkOperation(['type'=>'custom bulk','name'=>'archive','icon'=>'original'],$resource,$request);
	$t->same('Archive selected',$operation['label']);
	$t->same('original',$operation['icon']);
	$t->same('POST',$operation['method']);
	$t->isTrue($operation['type_hooks']['renderer']);
	$t->same('<rendered 4>',PanelComponentRegistry::renderBulkOperation('custom_bulk',$operation,$resource,$request,[]));
	PanelComponentRegistry::registerBulkOperationType('bulk_array',$notString);
	$t->same(null,PanelComponentRegistry::renderBulkOperation('bulk_array',[],$resource,$request));
	$t->same(null,PanelComponentRegistry::renderBulkOperation('bulk_action',[],$resource,$request));
	PanelComponentRegistry::registerBulkOperationType('denied_bulk',null,['authorize'=>static fn(mixed ...$arguments): bool=>false]);
	$t->same(null,PanelComponentRegistry::prepareBulkOperation(['name'=>'denied_bulk'],$resource,$request));
	$plainOperation=PanelComponentRegistry::prepareBulkOperation(['type'=>'   ','name'=>'plain'],$resource,$request);
	$t->same('bulk_action',$plainOperation['type']);
	$t->isFalse($plainOperation['type_hooks']['renderer']);
	$t->same([],PanelComponentRegistry::bulkOperationTypeDefinition('missing'));
	$t->same('',PanelComponentRegistry::registerBulkOperationType(' '));
});
