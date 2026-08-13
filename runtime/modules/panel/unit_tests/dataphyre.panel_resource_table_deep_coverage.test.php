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
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTableState;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\ResourceTable;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Panel\TableGroup;
use Dataphyre\Panel\TableSummary;
use Dataphyre\Panel\TableView;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

/** @param array<string,mixed> $query */
function dp_panel_resource_table_request(array $query=[],string $operation='index'): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>'GET',
		'resource'=>'orders',
		'operation'=>$operation,
		'query'=>$query,
		'user'=>['id'=>7,'name'=>'Table operator'],
	]);
}

function dp_panel_resource_table_resource(array $actions=[]): Resource {
	return Resource::make('orders')
		->recordKeyUsing('id')
		->recordUrlUsing(static fn(array $record,string $operation): string=>'/orders/'.$operation.'/'.($record['id'] ?? ''))
		->actions($actions);
}

final class DpPanelResourceTableRecord {
	public string $title='Public title';
	public function getDisplayName(): string { return 'Getter name'; }
}

test('panel resource table normalizes fluent collections pagination and aliases',static function(Context $t): void {
	$base=ResourceTable::make();
	$table=$base
		->columns([
			Column::make('id'),
			['name'=>'title','type'=>'text'],
			'status',
		])
		->column('amount','money')
		->filters([
			TableFilter::make('status','select'),
			['name'=>'owner','type'=>'text'],
			'query',
		])
		->filter('enabled','boolean')
		->views([
			TableView::make('all_orders'),
			['name'=>'open_orders','query'=>['status'=>'open']],
			'closed_orders',
		])
		->view('mine')
		->summaries([
			TableSummary::make('rows'),
			['name'=>'revenue','type'=>'sum','column'=>'amount'],
			'counted',
		])
		->summary('average_amount','avg')
		->groups([
			TableGroup::make('team'),
			['name'=>'status'],
			'owner',
			'',
		])
		->group('region')
		->perPage(7)
		->perPageOptions([10,50,50,999,0])
		->defaultSort(' created-at ','DESC')
		->meta(['source'=>'coverage'])->meta(['version'=>2]);

	$t->same([],$base->columnsList(),'builders are immutable');
	$t->same(['id','title','status','amount'],array_keys($table->columnsList()));
	$t->same(['status','owner','query','enabled'],array_keys($table->filtersList()));
	$t->same(['all_orders','open_orders','closed_orders','mine'],array_keys($table->viewsList()));
	$t->same(['rows','revenue','counted','average_amount'],array_keys($table->summariesList()));
	$t->same(['team','status','owner','region'],array_keys($table->groupsList()));
	$t->same(7,$table->defaultPerPage());
	$t->same([1,7,10,50,250],$table->perPageOptionsList());
	$t->same(['column'=>'created-at','direction'=>'desc'],$table->defaultSortDefinition());

	$t->same(1,ResourceTable::make()->perPage(-10)->defaultPerPage());
	$t->same(250,ResourceTable::make()->perPage(999)->defaultPerPage());
	$t->same([25],ResourceTable::make()->perPageOptions([])->perPageOptionsList());
	$t->same([10,25],ResourceTable::make()->perPageOptions([10])->perPageOptionsList());
	$t->same(null,ResourceTable::make()->defaultSort('')->defaultSortDefinition());
	$t->same('asc',ResourceTable::make()->defaultSort('name','sideways')->defaultSortDefinition()['direction']);

	$aliases=ResourceTable::make()
		->recordAttributes(['class'=>'record'])
		->rowAttribute('role','row')
		->rowData('Order ID',7)
		->rowAria('described by','details')
		->clickableRows('edit',false)
		->rowAction('delete')
		->rowUrl(static fn(): string=>'/custom',false)
		->rowPreview()
		->previewAction(false)
		->previewable()
		->emptyState('No rows')
		->filteredEmptyState('No matches')
		->emptyStateAction('Create','/orders/create')
		->filteredEmptyStateAction('Reset','/orders');
	$t->same('record',$aliases->resolveRowAttributes()['class']);
	$t->same('row',$aliases->resolveRowAttributes()['role']);
	$t->same(7,$aliases->resolveRowAttributes()['data-order-id']);
	$t->same('details',$aliases->resolveRowAttributes()['aria-described-by']);
	$t->isTrue($aliases->previewActionEnabled());

	$t->same([],ResourceTable::make()->recordAction('')->resolveRowClick(['id'=>1],dp_panel_resource_table_request(),dp_panel_resource_table_resource()));
	$t->same('show',ResourceTable::make()->rowClick(' ')->toArray()['row_click']['operation']);
	$t->isFalse(ResourceTable::make()->rowClick(false)->toArray()['row_click']['enabled']);
	$t->same('show',ResourceTable::make()->rowClick(true)->toArray()['row_click']['operation']);
	$t->same('url',ResourceTable::make()->rowClick(static fn(): string=>'/row')->toArray()['row_click']['target']);
})->tag('panel','resource-table','coverage')->group('framework-coverage');

test('panel resource table resolves fallback and requested column visibility',static function(Context $t): void {
	$request=dp_panel_resource_table_request();
	$t->same([],ResourceTable::make()->columnsFor());
	$resource=Resource::make('orders')->fields([Field::make('title'),Field::make('status')]);
	$t->same(['title','status'],array_keys(ResourceTable::make()->columnsFor($resource)));

	$table=ResourceTable::make()->columns([
		Column::make('id')->toggleable(false),
		Column::make('title'),
		Column::make('optional')->visibleByDefault(false),
		Column::make('secret')->hidden(),
	]);
	$t->same(['id','title'],array_keys($table->visibleColumnsFor($request)));
	$t->same(['id','optional'],array_keys($table->visibleColumnsFor(
		dp_panel_resource_table_request(['visible_columns'=>'optional, missing'])
	)));
	$t->same(['id','title'],array_keys($table->visibleColumnsFor(
		dp_panel_resource_table_request(),null,['visible_columns'=>['title']]
	)));

	$fallback=ResourceTable::make()->column(Column::make('optional')->visibleByDefault(false));
	$t->same(['optional'],array_keys($fallback->visibleColumnsFor($request)));
	$malformed=ResourceTable::make();
	$t->nonPublic($malformed)->writeProperty('columns',[
		'bad'=>new stdClass(),
		'good'=>Column::make('good'),
	]);
	$t->same(['good'],array_keys($malformed->visibleColumnsFor($request)));

	$t->same(['title'],$t->nonPublic(ResourceTable::class)->invoke('requestedColumns',dp_panel_resource_table_request(['visible_columns'=>' title, title, ']),[]));
	$t->same(['optional'],$t->nonPublic(ResourceTable::class)->invoke('requestedColumns',$request,['visible_columns'=>' optional ']));
	$t->same([],$t->nonPublic(ResourceTable::class)->invoke('requestedColumns',dp_panel_resource_table_request(['visible_columns'=>new stdClass()]),[]));
})->tag('panel','resource-table','coverage')->group('framework-coverage');

test('panel resource table merges view defaults and selects view and group state',static function(Context $t): void {
	$empty=ResourceTable::make();
	$request=dp_panel_resource_table_request();
	$t->same('',$empty->activeViewName($request));
	$t->same($request,$empty->requestWithResolvedView($request));
	$t->same('',$empty->activeGroupName($request));

	$views=ResourceTable::make()->views([
		TableView::make('open')->default()->query([
			'status'=>'open','q'=>'default search','visible_columns'=>['id','status'],'page'=>3,
		]),
		TableView::make('closed')->query(['status'=>'closed']),
	]);
	$t->same('',$views->activeViewName(dp_panel_resource_table_request(['view'=>'all'])));
	$t->same('closed',$views->activeViewName(dp_panel_resource_table_request(['view'=>'closed'])));
	$t->same('open',$views->activeViewName(dp_panel_resource_table_request(['view'=>'missing'])));
	$t->same('open',$views->activeViewName($request));
	$t->same('all',$views->requestWithResolvedView(dp_panel_resource_table_request(['view'=>'all']))->query('view'));

	$resolved=$views->requestWithResolvedView(dp_panel_resource_table_request([
		'view'=>'open','status'=>'manual','q'=>'','visible_columns'=>[],'page'=>2,
	]));
	$t->same('manual',$resolved->query('status'));
	$t->same('default search',$resolved->query('q'));
	$t->same(['id','status'],$resolved->query('visible_columns'));
	$t->same(2,$resolved->query('page'));
	$t->same('open',$views->requestWithResolvedView(dp_panel_resource_table_request(['view'=>'unknown']))->query('view'));

	$noDefault=ResourceTable::make()->view(TableView::make('only'));
	$t->same('',$noDefault->activeViewName(dp_panel_resource_table_request(['view'=>'unknown'])));
	$t->same('unknown',$noDefault->requestWithResolvedView(dp_panel_resource_table_request(['view'=>'unknown']))->query('view'));

	$invalid=ResourceTable::make();
	$t->nonPublic($invalid)->writeProperty('views',['broken'=>new stdClass()]);
	$invalidRequest=dp_panel_resource_table_request(['view'=>'broken']);
	$t->same($invalidRequest,$t->nonPublic($invalid)->invoke('requestWithViewDefaults',$invalidRequest,'broken'));
	$t->same('',$invalid->activeViewName(dp_panel_resource_table_request()));

	$groups=ResourceTable::make()->groups([
		TableGroup::make('team')->default(),
		TableGroup::make('status'),
	]);
	$t->same('',$groups->activeGroupName(dp_panel_resource_table_request(['group'=>'none'])));
	$t->same('status',$groups->activeGroupName(dp_panel_resource_table_request(['group'=>'status'])));
	$t->same('team',$groups->activeGroupName(dp_panel_resource_table_request(['group'=>'unknown'])));
	$t->same('',ResourceTable::make()->group('status')->activeGroupName($request));
})->tag('panel','resource-table','coverage')->group('framework-coverage');

test('panel resource table builds runtime state sort filters summaries and defaults',static function(Context $t): void {
	$resource=Resource::make('orders')->fields([Field::make('id'),Field::make('amount')]);
	$table=ResourceTable::make()
		->columns([Column::make('id'),Column::make('amount')])
		->filter(TableFilter::make('status')->default('open'))
		->filter(TableFilter::make('owner'))
		->summary(TableSummary::make('rows','count'))
		->group(TableGroup::make('team')->default())
		->defaultSort('amount','desc')
		->perPage(2);
	$request=dp_panel_resource_table_request(['q'=>' Alice ','page'=>2,'per_page'=>2]);
	$state=$table->state($request,[['id'=>1,'amount'=>10],['id'=>2,'amount'=>20]],$resource,true);
	$t->instanceOf(PanelTableState::class,$state);
	$t->same('Alice',$state->query());
	$t->same(['column'=>'amount','direction'=>'desc'],$state->sort());
	$t->same(['status'=>'open'],$state->filterValues());
	$t->same('team',$state->activeGroup());
	$t->same(2,$state->page());
	$t->same(2,$state->perPage());
	$t->same(2,$state->totalRecords());
	$t->isTrue($state->meta()['already_paginated']);
	$t->same(1,count($state->summaries()));

	$viewTable=ResourceTable::make()->view(TableView::make('mine')->default()->query(['q'=>'mine']));
	$t->same('mine',$viewTable->state($request)->activeView());
	$t->same(['column'=>'','direction'=>'desc'],$t->nonPublic(ResourceTable::class)->invoke('sortState',dp_panel_resource_table_request(['sort'=>'','dir'=>'desc']),$table));
	$t->same(['column'=>'id','direction'=>'asc'],$t->nonPublic(ResourceTable::class)->invoke('sortState',dp_panel_resource_table_request(['sort'=>'id','dir'=>'sideways']),$table));
	$malformedDefault=ResourceTable::make();
	$t->nonPublic($malformedDefault)->writeProperty('defaultSort',['column'=>'title','direction'=>'sideways']);
	$t->same(['column'=>'title','direction'=>'asc'],$t->nonPublic(ResourceTable::class)->invoke('sortState',$request,$malformedDefault));
})->tag('panel','resource-table','coverage')->group('framework-coverage');

test('panel resource table sanitizes and resolves static and dynamic row attributes',static function(Context $t): void {
	$stringable=new class implements Stringable { public function __toString(): string { return 'stringable'; } };
	$table=ResourceTable::make()
		->rowAttributes(['class'=>'first','data-static'=>'yes','aria-hidden'=>false])
		->rowAttributes(static fn(array $record,PanelRequest $request,Resource $resource,ResourceTable $table): array=>[
			'class'=>$record['class'].'-'.$request->operation().'-'.$resource->name().'-'.($table instanceof ResourceTable ? 'table' : 'bad'),
			'data-dynamic'=>$stringable,
		])
		->rowAttributes(static fn(): string=>'ignored')
		->rowAttributes(['id'=>'replacement'],false);
	$t->same(['id'=>'replacement'],$table->resolveRowAttributes(
		['class'=>'row'],dp_panel_resource_table_request(),Resource::make('orders')
	));

	$dynamic=ResourceTable::make()
		->rowAttributes(['class'=>'first'])
		->rowAttributes(static fn(array $record): array=>['class'=>$record['class'],'data-active'=>true]);
	$t->same(['class'=>'second','data-active'=>true],$dynamic->resolveRowAttributes(['class'=>'second']));
	$manifest=$dynamic->toArray();
	$t->same(['class'=>'first'],$manifest['row_attributes']);
	$t->isTrue($manifest['row_attributes_dynamic']);

	$normalized=$t->nonPublic(ResourceTable::class)->invoke('normalizeExtraAttributes',[
		0=>'class',
		1=>123,
		' DATA-DP-PANEL-PRIVATE '=>'blocked',
		'data-safe'=>'ok',
		'aria-description'=>'description',
		'aria-label'=>'blocked',
		'id'=>null,
		'role'=>false,
		'class'=>$stringable,
		'href'=>'blocked',
		'data-array'=>[],
	]);
	$t->same($stringable,$normalized['class']);
	$t->same('ok',$normalized['data-safe']);
	$t->same('description',$normalized['aria-description']);
	$t->same(null,$normalized['id']);
	$t->same(false,$normalized['role']);
	$t->isFalse(array_key_exists('href',$normalized));
	$t->same('mixed-case',$t->nonPublic(ResourceTable::class)->invoke('normalizeAttributeSegment',' Mixed Case! '));
	foreach([
		['class',true],['data-safe',true],['data-dp-panel-private',false],
		['aria-description',true],['aria-label',false],['id',true],['role',true],['href',false],
	] as [$name,$allowed]){
		$t->same($allowed,$t->nonPublic(ResourceTable::class)->invoke('isAllowedExtraAttribute',$name),$name);
	}
})->tag('panel','resource-table','coverage')->group('framework-coverage');

test('panel resource table enforces row click operations actions and authorization',static function(Context $t): void {
	$request=dp_panel_resource_table_request();
	$record=['id'=>'A 1'];
	$resource=dp_panel_resource_table_resource();
	$t->same([],ResourceTable::make()->resolveRowClick($record,$request,$resource));
	$t->same([],ResourceTable::make()->rowAction()->resolveRowClick($record,$request));
	$t->same([],ResourceTable::make()->rowAction()->resolveRowClick(null,$request,$resource));

	$view=ResourceTable::make()->rowAction('show',false)->resolveRowClick($record,$request,$resource);
	$t->same('/orders/show/A 1',$view['url']);
	$t->same('view',$view['operation']==='show' ? 'view' : 'bad');
	$t->isFalse($view['modal']);
	$editResource=$resource->authorize(static fn(string $ability): bool=>$ability==='update');
	$t->same('edit',ResourceTable::make()->rowAction('edit')->resolveRowClick($record,$request,$editResource)['operation']);
	$t->same('update',ResourceTable::make()->rowAction('update')->resolveRowClick($record,$request,$editResource)['operation']);
	$deleteResource=$resource->authorize(static fn(string $ability): bool=>$ability==='delete');
	$t->same('delete',ResourceTable::make()->rowAction('delete')->resolveRowClick($record,$request,$deleteResource)['operation']);
	$t->same([],ResourceTable::make()->rowAction('show')->resolveRowClick(
		$record,$request,$resource->authorize(static fn(): bool=>false)
	));

	$custom=ResourceTable::make()->rowUrl(
		static fn(array $record,PanelRequest $request,Resource $resource,ResourceTable $table,string $operation): string=>
			'/custom/'.$record['id'].'/'.$request->operation().'/'.$resource->name().'/'.($table instanceof ResourceTable ? $operation : 'bad'),
		false
	)->resolveRowClick($record,$request,$resource);
	$t->same('/custom/A 1/index/orders/show',$custom['url']);
	$t->same('url',$custom['target']);
	$stringable=ResourceTable::make()->rowUrl(static fn()=>new class implements Stringable {
		public function __toString(): string { return ' /stringable '; }
	})->resolveRowClick($record,$request,$resource);
	$t->same('/stringable',$stringable['url']);
	$fallback=ResourceTable::make()->rowUrl(static fn(): array=>[])->resolveRowClick($record,$request,$resource);
	$t->same('/orders/show/A 1',$fallback['url']);
	$emptyResource=Resource::make('orders')->recordKeyUsing(static fn(): string=>'');
	$t->nonPublic($emptyResource)->writeProperty('url','');
	$t->same([],ResourceTable::make()->rowUrl(static fn(): array=>[])->resolveRowClick($record,$request,$emptyResource));

	$actions=[
		Action::make('bulk')->bulk(),
		Action::make('hidden')->hidden(),
		Action::make('denied')->authorize(static fn(): bool=>false),
		Action::make('disabled')->disabled(),
		Action::make('inspect'),
	];
	$actionResource=dp_panel_resource_table_resource($actions);
	foreach(['missing','bulk','hidden','denied','disabled'] as $name){
		$t->same([],ResourceTable::make()->recordAction($name)->resolveRowClick($record,$request,$actionResource),$name);
	}
	$actionClick=ResourceTable::make()->recordAction('inspect',false)->resolveRowClick($record,$request,$actionResource);
	$t->same('action',$actionClick['operation']);
	$t->same('inspect',$actionClick['action']);
	$t->isFalse($actionClick['modal']);
	$t->contains('action=inspect',$actionClick['url']);
	$t->contains('record=A+1',$actionClick['url']);
	$blankKey=dp_panel_resource_table_resource([Action::make('inspect')])->recordKeyUsing(static fn(): string=>'');
	$t->same([],ResourceTable::make()->recordAction('inspect')->resolveRowClick($record,$request,$blankKey));
})->tag('panel','resource-table','coverage')->group('framework-coverage');

test('panel resource table resolves preview definitions and display values',static function(Context $t): void {
	$request=dp_panel_resource_table_request();
	$resource=Resource::make('orders');
	$record=['title'=>'Example','status'=>'open','enabled'=>true];
	$table=ResourceTable::make()->previewFields([
		'title',
		['name'=>'status'],
		['name'=>'enabled','label'=>'Enabled?', 'value'=>static fn(array $record,PanelRequest $request,Resource $resource,ResourceTable $table,string $name): bool=>
			$record[$name] && $request->operation()==='index' && $resource->name()==='orders' && $table instanceof ResourceTable],
		'literal'=>false,
		'dynamic'=>static fn(array $record): string=>$record['title'].' dynamic',
		''=>'dropped',
		['name'=>'ignored','label'=>'   ','value'=>'ignored'],
	],false);
	$fields=$table->resolveRowPreviewFields($record,$request,$resource);
	$t->same(5,count($fields));
	$t->same(['label'=>'Title','value'=>'Example'],$fields[0]);
	$t->same(['label'=>'Status','value'=>'open'],$fields[1]);
	$t->same('Yes',$fields[2]['value']);
	$t->same('No',$fields[3]['value']);
	$t->same('Example dynamic',$fields[4]['value']);
	$t->isFalse($table->previewActionEnabled());

	$dynamic=ResourceTable::make()->previewFields(static fn(array $record): array=>[
		['name'=>'title','label'=>'Dynamic title','value'=>$record['title']],
	]);
	$t->same('Example',$dynamic->resolveRowPreviewFields($record,$request,$resource)[0]['value']);
	$t->same([],ResourceTable::make()->previewFields(static fn(): string=>'invalid')->resolveRowPreviewFields($record));
	$t->same([],ResourceTable::make()->previewFields([])->resolveRowPreviewFields($record));

	$object=new DpPanelResourceTableRecord();
	$t->same('array',$t->nonPublic(ResourceTable::class)->invoke('recordValue',[ 'value'=>'array'],'value','default'));
	$t->same('Public title',$t->nonPublic(ResourceTable::class)->invoke('recordValue',$object,'title','default'));
	$t->same('Getter name',$t->nonPublic(ResourceTable::class)->invoke('recordValue',$object,'display_name','default'));
	$t->same('default',$t->nonPublic(ResourceTable::class)->invoke('recordValue',$object,'missing','default'));
	$t->same('default',$t->nonPublic(ResourceTable::class)->invoke('recordValue',null,'missing','default'));

	$t->same('',$t->nonPublic(ResourceTable::class)->invoke('stringValue',null));
	$t->same('Yes',$t->nonPublic(ResourceTable::class)->invoke('stringValue',true));
	$t->same('No',$t->nonPublic(ResourceTable::class)->invoke('stringValue',false));
	$t->same('5',$t->nonPublic(ResourceTable::class)->invoke('stringValue',5));
	$t->same('stringable',$t->nonPublic(ResourceTable::class)->invoke('stringValue',new class implements Stringable {
		public function __toString(): string { return 'stringable'; }
	}));
	$t->same('{"a":1}',$t->nonPublic(ResourceTable::class)->invoke('stringValue',[ 'a'=>1 ]));
	$stream=fopen('php://memory','r');
	$t->same('',$t->nonPublic(ResourceTable::class)->invoke('stringValue',$stream));
	fclose($stream);
	$t->same('Order Status',$t->nonPublic(ResourceTable::class)->invoke('humanize','order_status'));
	$t->same('',$t->nonPublic(ResourceTable::class)->invoke('humanize',' -- '));
})->tag('panel','resource-table','coverage')->group('framework-coverage');

test('panel resource table resolves and serializes static and dynamic empty states',static function(Context $t): void {
	$request=dp_panel_resource_table_request();
	$resource=Resource::make('orders');
	$static=ResourceTable::make()
		->emptyState(' No orders ',' Create the first order ',' Create ',' /orders/create ',' box ')
		->filteredEmptyState([
			'heading'=>'No matching orders','description'=>'Change filters','icon'=>'search',
		])
		->filteredEmptyStateAction(' Reset filters ',' /orders ');
	$t->same([
		'heading'=>'No orders','description'=>'Create the first order','icon'=>'box',
		'action_label'=>'Create','action_url'=>'/orders/create',
	],$static->resolveEmptyState($request,false,$resource));
	$t->same('No matching orders',$static->resolveEmptyState($request,true,$resource)['heading']);

	$dynamic=ResourceTable::make()
		->emptyState(
			static fn(PanelRequest $request,Resource $resource,ResourceTable $table,bool $hasConstraints): array=>[
				'heading'=>$request->operation().'-'.$resource->name().'-'.($table instanceof ResourceTable ? 'table' : 'bad').'-'.($hasConstraints ? 'yes' : 'no'),
				'description'=>'Dynamic description',
			]
		)
		->emptyStateAction('Create',static fn(PanelRequest $request): string=>' /'.$request->operation().'/create ')
		->filteredEmptyState(static fn(): string=>'Scalar heading')
		->filteredEmptyStateAction('Reset',static fn(): array=>[]);
	$t->same('index-orders-table-no',$dynamic->resolveEmptyState($request,false,$resource)['heading']);
	$t->same('/index/create',$dynamic->resolveEmptyState($request,false,$resource)['action_url']);
	$t->same('Scalar heading',$dynamic->resolveEmptyState($request,true,$resource)['heading']);
	$t->same('',$dynamic->resolveEmptyState($request,true,$resource)['action_url']);
	$serialized=$dynamic->toArray();
	$t->isTrue($serialized['empty_state']['dynamic']);
	$t->isTrue($serialized['empty_state']['action_url_dynamic']);
	$t->isTrue($serialized['filtered_empty_state']['dynamic']);
	$t->isTrue($serialized['filtered_empty_state']['action_url_dynamic']);

	$nondisplayable=ResourceTable::make()->emptyState(static fn(): stdClass=>new stdClass());
	$t->same('',$nondisplayable->resolveEmptyState($request)['heading']);
})->tag('panel','resource-table','coverage')->group('framework-coverage');

test('panel resource table serializes previews row behavior and manifests',static function(Context $t): void {
	$table=ResourceTable::make()
		->columns([Column::make('id'),Column::make('title')])
		->filter(TableFilter::make('status'))
		->view(TableView::make('all'))
		->summary(TableSummary::make('rows'))
		->group(TableGroup::make('team'))
		->rowAttributes(['class'=>'record'])
		->recordAction('inspect',false)
		->previewFields([
			'title',
			['name'=>'status','label'=>'Status','value'=>true],
			['name'=>'callback','value'=>static fn(): string=>'runtime'],
			'literal'=>new class implements Stringable { public function __toString(): string { return 'literal'; } },
			'dynamic'=>static fn(): string=>'runtime',
			''=>'drop',
		],true)
		->emptyState('None')
		->meta(['source'=>'coverage']);
	$array=$table->toArray();
	$t->same(2,count($array['columns']));
	$t->same('action',$array['row_click']['target']);
	$t->same('inspect',$array['row_click']['action']);
	$t->same(5,count($array['row_preview']['fields']));
	$t->same('',$array['row_preview']['fields'][0]['value']);
	$t->same('Yes',$array['row_preview']['fields'][1]['value']);
	$t->same('',$array['row_preview']['fields'][2]['value']);
	$t->same('literal',$array['row_preview']['fields'][3]['value']);
	$t->same('',$array['row_preview']['fields'][4]['value']);
	$t->same([] ,ResourceTable::make()->toArray()['row_preview']['fields']);
	$t->same([],ResourceTable::make()->previewFields(static fn(): array=>[])->toArray()['row_preview']['fields']);

	$manifest=$table->manifest(Resource::make('orders'),dp_panel_resource_table_request(),['extra'=>'value']);
	$t->isTrue(is_array($manifest));
	$t->same('coverage',$array['meta']['source']);
	$t->same(['class'=>'record'],$t->nonPublic($table)->invoke('staticExtraAttributes'));
	$t->isFalse($t->nonPublic($table)->invoke('hasDynamicExtraAttributes'));
})->tag('panel','resource-table','coverage')->group('framework-coverage');
