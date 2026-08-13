<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/panel_test_probes.php';

use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelRelationState;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTableState;
use Dataphyre\Panel\RelationManager;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Panel\TableSummary;
use Dataphyre\Panel\TableView;
use Dataphyre\Panel\TestFixtures\RendererStreamScenario;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

if(!function_exists('Dataphyre\\Panel\\fopen')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Panel;
function fopen(string $filename,string $mode,bool $useIncludePath=false,mixed $context=null): mixed {
	if(\Dataphyre\Panel\TestFixtures\RendererStreamScenario::openShouldFail()){
		return false;
	}
	return $context===null
		? \fopen($filename,$mode,$useIncludePath)
		: \fopen($filename,$mode,$useIncludePath,$context);
}
PHP);
}

/** @param array<string,mixed> $query @param array<string,mixed> $input @param array<string,mixed> $files */
function dp_panel_renderer_data_request(array $query=[],array $input=[],array $files=[],string $operation='relation',?string $record='10'): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>'GET',
		'resource'=>'data_orders',
		'operation'=>$operation,
		'record'=>$record,
		'relation'=>'items',
		'query'=>$query,
		'input'=>$input,
		'files'=>$files,
		'user'=>['id'=>7],
	]);
}

/** @return array{0:Resource,1:Resource,2:RelationManager,3:array<int,array<string,mixed>>} */
function dp_panel_renderer_data_fixture(bool $configured=true): array {
	$records=[
		['id'=>'1','order_id'=>'10','name'=>'Alpha','subtitle'=>'First','status'=>'active','amount'=>30,'pivot_note'=>'Priority'],
		['id'=>'2','order_id'=>'10','name'=>'Beta','subtitle'=>'Second','status'=>'inactive','amount'=>10,'pivot_note'=>'Normal'],
		['id'=>'3','order_id'=>'10','name'=>'Gamma','subtitle'=>'Third','status'=>'active','amount'=>20,'pivot_note'=>'Later'],
	];
	$child=Resource::make('data_products')
		->label('Products')
		->pluralLabel('Products')
		->columns([
			Column::make('id')->label('ID'),
			Column::make('name')->label('Name')->searchable()->sortable(),
		])
		->fields([Field::make('name')->label('Name')])
		->queryUsing(static fn(): array=>$records)
		->recordKeyUsing('id');
	Panel::register($child);

	$relation=RelationManager::make('items')
		->label('Order items')
		->relatedResource('data_products')
		->foreignKey('order_id')
		->localKey('id')
		->queryUsing(static fn(): array=>$records)
		->columns([
			Column::make('name')->label('Name')->searchable()->sortable()->align('left'),
			Column::make('amount','number')->label('Amount')->sortable()->align('right'),
			Column::make('status')->label('Status'),
		])
		->views([
			TableView::make('active')->label('Active')->tone('success')->where(static fn(array $record): bool=>$record['status']==='active')->badge(2),
			TableView::make('inactive')->label('Inactive')->where(static fn(array $record): bool=>$record['status']==='inactive'),
		])
		->filters([
			TableFilter::make('status','select')->label('Status')->options(['active'=>'Active','inactive'=>'Inactive']),
			TableFilter::make('amount')->label('Amount')->numberRange(),
		])
		->summaries([
			TableSummary::make('rows','count')->label('Rows'),
			TableSummary::make('amount','sum')->column('amount')->label('Total'),
		])
		->facts([TableSummary::make('rows','count')->label('Related')])
		->description('Products assigned to this order')
		->parentTitleUsing(static fn(array $record): string=>'Order '.($record['id'] ?? ''))
		->badgeUsing(static fn(array $records): string=>(string)count($records))
		->emptyState('No items','Adjust the filters and try again.')
		->perPage(2)
		->perPageOptions([1,2,5])
		->defaultSort('name','asc');
	if($configured){
		$handler=static fn(): array=>['success'=>true];
		$relation=$relation
			->attachableRecordsUsing(static fn(): array=>[
				['id'=>'4','name'=>'Delta','subtitle'=>'Fourth'],
				['name'=>'Missing key'],
			])
			->attachUsing($handler)
			->detachUsing($handler)
			->associateUsing($handler)
			->dissociateUsing($handler)
			->reorderUsing($handler,'position')
			->pivotFields([Field::make('pivot_note')->label('Pivot note')])
			->updatePivotUsing($handler);
	}
	$parent=Resource::make('data_orders')
		->label('Orders')
		->pluralLabel('Orders')
		->recordKeyUsing('id')
		->relations([$relation]);
	return [$parent,$child,$relation,$records];
}

/** @param array<string,array<string,mixed>> $operations */
function dp_panel_renderer_data_state(RelationManager $relation,array $operations,array $records=[],array $columns=[]): PanelRelationState {
	$definition=$relation->toArray();
	$definition['operations']=$operations;
	$table=PanelTableState::make($records,$columns,$columns,[],[
		'page'=>1,
		'per_page'=>25,
		'total_records'=>count($records),
	]);
	return PanelRelationState::make($relation,['resource'=>'data_orders','key'=>'10'],$table,$columns,$records,$records,$records,[],[],[],[
		'request'=>dp_panel_renderer_data_request()->toArray(),
	],$definition);
}

test('panel renderer data import export and generic helpers cover every result shape',static function(Context $t): void {
	$stream=RendererStreamScenario::reset($t);
	$resource=Resource::make('data_imports')
		->fields([
			Field::make('name')->label('Full name')->default('Alice')->required(),
			Field::make('email','email')->label('Email'),
			Field::make('ignored')->readonly(),
			Field::make(''),
		])
		->columns([
			Column::make('name')->label('Full name'),
			Column::make('email','email')->label('Email'),
		])
		->recordKeyUsing('id')
		->queryUsing(static fn(): array=>[['id'=>'1','name'=>'Alice'],['id'=>'2','name'=>'Bob']]);
	$request=dp_panel_renderer_data_request(['format'=>'JSON'],['selected'=>['1',' ','2']]);

	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('exportColumns',$resource,$request)));
	$t->same('json',$t->nonPublic(PanelRenderer::class)->invoke('exportFormat',$request));
	$t->same('csv',$t->nonPublic(PanelRenderer::class)->invoke('exportFormat',dp_panel_renderer_data_request(['format'=>'xml'])));
	$json=$t->nonPublic(PanelRenderer::class)->invoke('exportJsonResult',$resource,$request,[['name'=>'Alice','email'=>'a@example.com']],$resource->resourceTable()->columnsList(),'records.json','custom');
	$t->same('custom',$json->data()['kind']);
	$t->contains('Alice',$json->content());

	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('selectedRecords',$resource,$request)));
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('selectedRecords',$resource,dp_panel_renderer_data_request([],['selected'=>[]])));
	$finder=new class {
		public function findRecord(string $key): ?array { return $key==='1' ? ['id'=>'1'] : null; }
	};
	$findResource=Resource::make('finder')->recordKeyUsing('id')->queryUsing(static fn()=>$finder);
	$t->same([['id'=>'1']],$t->nonPublic(PanelRenderer::class)->invoke('selectedRecords',$findResource,dp_panel_renderer_data_request([],['selected'=>['1','2']])));
	$legacyFinder=new class { public function find(string $key): array { return ['id'=>$key]; } };
	$legacyResource=Resource::make('legacy_finder')->recordKeyUsing('id')->queryUsing(static fn()=>$legacyFinder);
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('selectedRecords',$legacyResource,dp_panel_renderer_data_request([],['selected'=>'9'])))+1);
	$objectResource=Resource::make('empty_object')->queryUsing(static fn()=>new stdClass());
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('selectedRecords',$objectResource,dp_panel_renderer_data_request([],['selected'=>1])));
	$scalarResource=Resource::make('scalar_query')->queryUsing(static fn()=>false);
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('selectedRecords',$scalarResource,dp_panel_renderer_data_request([],['selected'=>1])));

	$tmp=$t->tempFile("name\nUploaded",'dp-renderer-data');
	$upload=dp_panel_renderer_data_request([],['csv_data'=>'fallback'],['csv_file'=>['error'=>UPLOAD_ERR_OK,'tmp_name'=>$tmp]]);
	$t->contains('Uploaded',$t->nonPublic(PanelRenderer::class)->invoke('importCsvPayload',$upload));
	$t->same('fallback',$t->nonPublic(PanelRenderer::class)->invoke('importCsvPayload',dp_panel_renderer_data_request([],['csv_data'=>'fallback'],['csv_file'=>['error'=>UPLOAD_ERR_NO_FILE]])));

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('importMappingHtml',$resource,['headers'=>[]]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('importMappingHtml',Resource::make('empty'),['headers'=>['One']]));
	$mapping=$t->nonPublic(PanelRenderer::class)->invoke('importMappingHtml',$resource,['headers'=>['Name','Email'],'mapped_headers'=>['name',null]]);
	$t->contains('import_map[0]',$mapping);
	$t->contains('Skip column',$mapping);

	$t->contains('dp-panel-empty',$t->nonPublic(PanelRenderer::class)->invoke('importPreviewTable',[],[]));
	$t->contains('dp-panel-empty',$t->nonPublic(PanelRenderer::class)->invoke('importPreviewTable',[1,2],[]));
	$previewRows=[];
	for($i=0;$i<22;$i++){
		$previewRows[]=['name'=>'Name '.$i,'email'=>'person'.$i.'@example.com'];
	}
	$preview=$t->nonPublic(PanelRenderer::class)->invoke('importPreviewTable',$previewRows,['row_errors'=>[0=>['email'=>['Invalid','Required']]]]);
	$t->contains('Name 19',$preview);
	$t->contains('colspan="4"',$preview);
	$t->contains('email: Invalid; email: Required',$preview);

	$validation=$t->nonPublic(PanelRenderer::class)->invoke('importValidation',$resource,$request,['bad',['name'=>''],['name'=>'Valid','email'=>'valid@example.com']]);
	$t->same(1,$validation['valid_count']);
	$t->same(2,$validation['invalid_count']);

	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('manualImportMap',dp_panel_renderer_data_request([],['import_map'=>'bad']),['name']));
	$t->same([0=>'',1=>'name'],$t->nonPublic(PanelRenderer::class)->invoke('manualImportMap',dp_panel_renderer_data_request([],['import_map'=>[0=>'__skip',1=>'Name',2=>'unknown']]),['name']));
	$t->same("\t",$t->nonPublic(PanelRenderer::class)->invoke('importDelimiter',dp_panel_renderer_data_request([],['delimiter'=>'tab']),'a\tb'));
	$t->same('|',$t->nonPublic(PanelRenderer::class)->invoke('importDelimiter',dp_panel_renderer_data_request([],['delimiter'=>'|']),'a|b'));
	$t->same(';',$t->nonPublic(PanelRenderer::class)->invoke('importDelimiter',dp_panel_renderer_data_request(),"\n a;b;c"));
	$t->same(',',$t->nonPublic(PanelRenderer::class)->invoke('importDelimiter',dp_panel_renderer_data_request(),'plain'));

	$parsed=$t->nonPublic(PanelRenderer::class)->invoke('parseImportCsv',$resource,dp_panel_renderer_data_request([],['has_header'=>'1','import_map'=>[2=>'__skip']]),"\xEF\xBB\xBFName,Email,Other\nAlice,a@example.com,x\n,,\nBob,b@example.com,y");
	$t->same(2,count($parsed['rows']));
	$t->same(['Other'],$parsed['skipped_columns']);
	$headerless=$t->nonPublic(PanelRenderer::class)->invoke('parseImportCsv',$resource,dp_panel_renderer_data_request([],['has_header'=>'0']),"Carol,c@example.com\n\n");
	$t->same('Carol',$headerless['rows'][0]['name']);
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('parseImportCsv',$resource,dp_panel_renderer_data_request(),"\n\n")['rows']);
	$stream->failOpens();
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('parseImportCsv',$resource,dp_panel_renderer_data_request(),'x')['rows']);
	$stream->failOpens(false);

	$columns=$t->nonPublic(PanelRenderer::class)->invoke('importableColumns',$resource);
	$t->same(2,count($columns));
	$t->same('Alice',$columns[0]['sample']);
	$t->same('person@example.com',$columns[1]['sample']);
	foreach([
		[['default'=>false],'0'],
		[['options'=>['open'=>'Open']],'open'],
		[['options'=>[0=>'First']],'First'],
		[['type'=>'url'],'https://example.com'],
		[['type'=>'integer'],'1'],
		[['type'=>'currency'],'1.00'],
		[['type'=>'toggle'],'1'],
		[['type'=>'date'],'2026-01-31'],
		[['type'=>'datetime'],'2026-01-31 12:00:00'],
		[['type'=>'unknown'],''],
	] as [$meta,$expected]){
		$t->same($expected,$t->nonPublic(PanelRenderer::class)->invoke('importSampleValue',$meta));
	}
	$t->same(['name'=>'name','full_name'=>'name'],$t->nonPublic(PanelRenderer::class)->invoke('importFieldMap',[['name'=>'name','label'=>'Full name'],['name'=>'','label'=>'']]));

	foreach([
		[['imported'=>[1,2],'failed'=>[3]],4,[2,1]],
		[['created'=>2,'errors'=>1],4,[2,1]],
		[['success'=>true],3,[3,0]],
		[['failed'=>2],5,[3,2]],
		[false,3,[0,3]],
		[true,3,[3,0]],
	] as [$result,$rowCount,$counts]){
		$summary=$t->nonPublic(PanelRenderer::class)->invoke('importResultSummary',$result,$rowCount);
		$t->same($counts[0],$summary['imported_count']);
		$t->same($counts[1],$summary['failed_count']);
	}
	foreach([
		'deleteOutcomeSucceeded'=>['deleted','success','ok'],
		'forceDeleteOutcomeSucceeded'=>['force_deleted','deleted','success','ok'],
		'duplicateOutcomeSucceeded'=>['duplicated','success','ok'],
		'restoreOutcomeSucceeded'=>['restored','success','ok'],
		'transitionOutcomeSucceeded'=>['transitioned','saved','updated','success','ok'],
	] as $method=>$keys){
		foreach($keys as $key){
			$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke($method,[$key=>true]));
			$t->isFalse($t->nonPublic(PanelRenderer::class)->invoke($method,[$key=>false]));
		}
		$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke($method,[]));
		$t->isFalse($t->nonPublic(PanelRenderer::class)->invoke($method,false));
	}
});

test('panel renderer data relation state utilities normalize urls filters views search sort and summaries',static function(Context $t): void {
	[$parent,$child,$relation,$records]=dp_panel_renderer_data_fixture();
	$request=dp_panel_renderer_data_request([
		'resource'=>'ignored','operation'=>'show','record'=>'99','relation'=>'other','action'=>'x',
		'keep'=>'yes','blank'=>' ','array'=>['a','b'],
		'r_items_q'=>'Alpha','r_items_sort'=>'name','r_items_dir'=>'desc','r_items_per_page'=>'2',
		'r_items_view'=>'active','r_items_status'=>'active','r_items_amount_from'=>'10','r_items_amount_to'=>'30',
	]);
	$scoped=$t->nonPublic(PanelRenderer::class)->invoke('relationScopedRequest',$relation,$request);
	$t->same('Alpha',$scoped->query('q'));
	$t->same('r_items_',$t->nonPublic(PanelRenderer::class)->invoke('relationPrefix',$relation));

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('recordKey',[ 'id'=>[] ]));
	$t->same('named',$t->nonPublic(PanelRenderer::class)->invoke('recordKey',[ 'name'=>'named' ]));
	$t->same(' style="text-align:right"',$t->nonPublic(PanelRenderer::class)->invoke('alignAttr',['align'=>'right']));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('alignAttr',['align'=>'justify']));

	$indexRequest=dp_panel_renderer_data_request([],[],[],'index',null);
	$t->contains('resource=data_orders',$t->nonPublic(PanelRenderer::class)->invoke('relationBaseUrl',$parent,$relation,$indexRequest,null));
	$t->contains('operation=relation',$t->nonPublic(PanelRenderer::class)->invoke('relationBaseUrl',$parent,$relation,$request,$records[0]));
	$t->contains('record=1',$t->nonPublic(PanelRenderer::class)->invoke('relationBaseUrl',$parent,$relation,$request,$records[0]));
	$t->contains('operation=show',$t->nonPublic(PanelRenderer::class)->invoke('relationBaseUrl',$parent,$relation,dp_panel_renderer_data_request([],[],[],'show'),$records[0]));

	$url=$t->nonPublic(PanelRenderer::class)->invoke('relationUrl',$parent,$relation,$request,$records[0],[''=>'ignored','q'=>'Beta','empty'=>'','bad key'=>'x','page'=>2]);
	$t->contains('keep=yes',$url);
	$t->contains('r_items_q=Beta',$url);
	$t->contains('r_items_bad_key=x',$url);
	$t->isFalse(str_contains($url,'resource=ignored'));
	$t->contains('resource=data_orders',$t->nonPublic(PanelRenderer::class)->invoke('relationOperationUrl',$parent,$relation,$indexRequest,null));
	$operationUrl=$t->nonPublic(PanelRenderer::class)->invoke('relationOperationUrl',$parent,$relation,$request,$records[0],[''=>'ignored','q'=>null,'page'=>2,'bad key'=>'x']);
	$t->contains('operation=relation',$operationUrl);
	$t->contains('record=1',$operationUrl);
	$t->contains('r_items_page=2',$operationUrl);

	$params=$t->nonPublic(PanelRenderer::class)->invoke('relationStateParams',$relation,$scoped,true);
	$t->same('Alpha',$params['q']);
	$t->same('active',$params['view']);
	$t->same('active',$params['status']);
	$t->same('10',$params['amount_from']);
	$t->same('30',$params['amount_to']);
	$allParams=$t->nonPublic(PanelRenderer::class)->invoke('relationStateParams',$relation,dp_panel_renderer_data_request(['view'=>'all']),false);
	$t->same('all',$allParams['view']);
	$hidden=$t->nonPublic(PanelRenderer::class)->invoke('relationHiddenInputs',$relation,$request,['page'=>2,'empty'=>'']);
	$t->contains('name="array[]"',$hidden);
	$t->contains('name="keep"',$hidden);
	$t->contains('name="r_items_page"',$hidden);
	$t->isFalse(str_contains($hidden,'r_items_q'));

	$states=$t->nonPublic(PanelRenderer::class)->invoke('relationOperationStates',$parent,$relation,$request,$records[0]);
	$t->isTrue($states['attach']['enabled']);
	$t->isTrue($states['attach']['authorized']);
	$denied=$relation->authorize(static fn(string $ability): bool=>$ability!=='detach');
	$deniedStates=$t->nonPublic(PanelRenderer::class)->invoke('relationOperationStates',$parent,$denied,$request,$records[0]);
	$t->isFalse($deniedStates['detach']['authorized']);
	$plain=dp_panel_renderer_data_fixture(false)[2];
	$plainStates=$t->nonPublic(PanelRenderer::class)->invoke('relationOperationStates',$parent,$plain,$request,$records[0]);
	$t->isFalse($plainStates['attach']['enabled']);

	$t->same($records,$t->nonPublic(PanelRenderer::class)->invoke('relationApplyTableView',$records,$parent,$relation,dp_panel_renderer_data_request()));
	$activeRequest=dp_panel_renderer_data_request(['view'=>'active']);
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('relationApplyTableView',$records,$parent,$relation,$activeRequest)));
	$t->same(2,$t->nonPublic(PanelRenderer::class)->invoke('relationViewCounts',$records,$parent,$relation,$activeRequest)['active']);
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('relationViewCounts',$records,$parent,RelationManager::make('none'),$activeRequest));

	$t->same($records,$t->nonPublic(PanelRenderer::class)->invoke('relationApplyFilters',$records,RelationManager::make('none'),$request));
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('relationApplyFilters',$records,$relation,dp_panel_renderer_data_request(['status'=>'active']))));
	$t->same($records,$t->nonPublic(PanelRenderer::class)->invoke('relationFilterRecords',$records,$relation,dp_panel_renderer_data_request()));
	$t->same(1,count($t->nonPublic(PanelRenderer::class)->invoke('relationFilterRecords',$records,$relation,dp_panel_renderer_data_request(['q'=>'beta']))));
	$nonSearch=RelationManager::make('non_search')->columns([Column::make('name')]);
	$t->same(1,count($t->nonPublic(PanelRenderer::class)->invoke('relationFilterRecords',$records,$nonSearch,dp_panel_renderer_data_request(['q'=>'gamma']))));

	$t->same(['name','asc'],$t->nonPublic(PanelRenderer::class)->invoke('relationSortState',$relation,dp_panel_renderer_data_request()));
	$t->same(['name','desc'],$t->nonPublic(PanelRenderer::class)->invoke('relationSortState',$relation,dp_panel_renderer_data_request(['sort'=>'name','dir'=>'desc'])));
	$t->same($records,$t->nonPublic(PanelRenderer::class)->invoke('relationSortRecords',$records,RelationManager::make('none'),dp_panel_renderer_data_request()));
	$t->same($records,$t->nonPublic(PanelRenderer::class)->invoke('relationSortRecords',$records,$relation,dp_panel_renderer_data_request(['sort'=>'missing'])));
	$sorted=$t->nonPublic(PanelRenderer::class)->invoke('relationSortRecords',$records,$relation,dp_panel_renderer_data_request(['sort'=>'amount','dir'=>'asc']));
	$t->same('2',$sorted[0]['id']);
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('relationSummaries',$parent,$relation,$request,$records)));
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('relationSummaries',$parent,RelationManager::make('none'),$request,$records));
	$t->isFalse($t->nonPublic(PanelRenderer::class)->invoke('relationHasConstraints',RelationManager::make('none'),dp_panel_renderer_data_request()));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('relationHasConstraints',$relation,dp_panel_renderer_data_request(['q'=>'x'])));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('relationHasConstraints',$relation,$activeRequest));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('relationHasConstraints',$relation,dp_panel_renderer_data_request(['status'=>'active'])));
	$t->contains('No related records match this view.',$t->nonPublic(PanelRenderer::class)->invoke('relationEmptyStateHtml',$relation,$activeRequest));
});

test('panel renderer data relation controls actions and complete table render cover html branches',static function(Context $t): void {
	[$parent,$child,$relation,$records]=dp_panel_renderer_data_fixture();
	$request=dp_panel_renderer_data_request([
		'keep'=>'yes','r_items_q'=>'Alpha','r_items_status'=>'active','r_items_sort'=>'name','r_items_dir'=>'asc','r_items_per_page'=>2,
	]);
	$relationRequest=$t->nonPublic(PanelRenderer::class)->invoke('relationScopedRequest',$relation,$request);
	$columns=$relation->resourceTable()->columnsList();
	$operations=$t->nonPublic(PanelRenderer::class)->invoke('relationOperationStates',$parent,$relation,$request,$records[0]);
	$state=dp_panel_renderer_data_state($relation,$operations,$records,$columns);
	$parentRecord=['id'=>'10','name'=>'Order 10'];
	$stateTable=$t->nonPublic(PanelRenderer::class)->invoke('relationTableHtml',$parent,$relation,$request,$parentRecord,$state);
	$t->contains('Beta',$stateTable);

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationTableViewsHtml',$parent,RelationManager::make('none'),$request,$relationRequest,$records[0],[]));
	$views=$t->nonPublic(PanelRenderer::class)->invoke('relationTableViewsHtml',$parent,$relation,$request,$relationRequest,$records[0],[''=>3,'active'=>2]);
	$t->contains('dp-panel-table-views',$views);
	$t->contains('aria-current',$views);
	$t->contains('dp-panel-table-view-danger',$t->nonPublic(PanelRenderer::class)->invoke('relationTableViewLink',$parent,$relation,$request,$records[0],[],'all','All','danger',true,3));
	$t->isFalse(str_contains($t->nonPublic(PanelRenderer::class)->invoke('relationTableViewLink',$parent,$relation,$request,$records[0],[],'active','Active','neutral',false,null),'aria-current'));

	$t->contains('type="search"',$t->nonPublic(PanelRenderer::class)->invoke('relationSearchHtml',$parent,$relation,$request,$relationRequest,$records[0]));
	$t->contains('Clear',$t->nonPublic(PanelRenderer::class)->invoke('relationSearchHtml',$parent,$relation,$request,$relationRequest,$records[0]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationFiltersHtml',$parent,RelationManager::make('none'),$request,$relationRequest,$records[0]));
	$hiddenRelation=RelationManager::make('hidden')->filter(TableFilter::make('secret')->hidden());
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationFiltersHtml',$parent,$hiddenRelation,$request,$relationRequest,$records[0]));
	$filters=$t->nonPublic(PanelRenderer::class)->invoke('relationFiltersHtml',$parent,$relation,$request,$relationRequest,$records[0]);
	$t->contains('dp-panel-filters',$filters);
	$t->contains('dp-panel-filter-chip',$filters);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationActiveFilterChipsHtml',$parent,$relation,$request,dp_panel_renderer_data_request(),$records[0]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationActiveFilterChipsHtml',$parent,$hiddenRelation,$request,dp_panel_renderer_data_request(['secret'=>'x']),$records[0]));

	$t->contains('selected',$t->nonPublic(PanelRenderer::class)->invoke('relationPerPageHtml',$parent,$relation,$request,$relationRequest,$records[0]));
	$customPerPage=$t->nonPublic(PanelRenderer::class)->invoke('relationPerPageHtml',$parent,$relation,$request,dp_panel_renderer_data_request(['per_page'=>7]),$records[0]);
	$t->contains('value="7" selected',$customPerPage);
	$firstPage=$t->nonPublic(PanelRenderer::class)->invoke('relationPaginationHtml',$parent,$relation,$request,$relationRequest,$records[0],5,1,2);
	$t->contains('dp-panel-page-disabled',$firstPage);
	$t->contains('r_items_page=2',$firstPage);
	$lastPage=$t->nonPublic(PanelRenderer::class)->invoke('relationPaginationHtml',$parent,$relation,$request,$relationRequest,$records[0],5,9,2);
	$t->contains('r_items_page=2',$lastPage);
	$t->contains('dp-panel-page-disabled',$lastPage);

	$t->same('Status',$t->nonPublic(PanelRenderer::class)->invoke('relationColumnHeader',$parent,$relation,$request,$relationRequest,$records[0],$columns['status']));
	$sortHeader=$t->nonPublic(PanelRenderer::class)->invoke('relationColumnHeader',$parent,$relation,$request,$relationRequest,$records[0],$columns['name']);
	$t->contains(' asc',$sortHeader);
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('relationRelatedResource',RelationManager::make('none')));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('relationRelatedResource',RelationManager::make('none')->relatedResource('missing')));
	$t->same($child,$t->nonPublic(PanelRenderer::class)->invoke('relationRelatedResource',$relation));

	$genericState=dp_panel_renderer_data_state(RelationManager::make('generic'),[],[$records[0]],$columns);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationRowActions',$parent,RelationManager::make('generic'),$request,$genericState,$records[0],$records[1]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationRowActions',$parent,$relation,$request,$state,$parentRecord,['name'=>'missing id']));
	$t->contains('Beta',$t->nonPublic(PanelRenderer::class)->invoke('relationRowActions',$parent,$relation,$request,$state,$parentRecord,$records[1]));
	$readOnlyRelation=dp_panel_renderer_data_fixture()[2]->readOnly();
	$t->contains('View',$t->nonPublic(PanelRenderer::class)->invoke('relationRowActions',$parent,$readOnlyRelation,$request,$state,$parentRecord,$records[1]));
	$deniedChild=Resource::make('data_denied_products')->recordKeyUsing('id')->authorize(static fn(): bool=>false);
	Panel::register($deniedChild);
	$deniedRelation=RelationManager::make('denied')->relatedResource('data_denied_products');
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationRowActions',$parent,$deniedRelation,$request,dp_panel_renderer_data_state($deniedRelation,[]),$parentRecord,$records[1]));

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationSelectRecordButton',$parent,$relation,$request,$relationRequest,dp_panel_renderer_data_state($relation,[]),$records[0],'attach'));
	$select=$t->nonPublic(PanelRenderer::class)->invoke('relationAttachButton',$parent,$relation,$request,$relationRequest,$state,$records[0]);
	$t->contains('Delta',$select);
	$t->contains('relation_attach_items',$select);
	$t->contains('relation_associate_items',$t->nonPublic(PanelRenderer::class)->invoke('relationAssociateButton',$parent,$relation,$request,$relationRequest,$state,$records[0]));
	$emptyOptions=$relation->attachableRecordsUsing(static fn(): array=>[]);
	$emptyOps=$t->nonPublic(PanelRenderer::class)->invoke('relationOperationStates',$parent,$emptyOptions,$request,$records[0]);
	$t->contains('disabled',$t->nonPublic(PanelRenderer::class)->invoke('relationSelectRecordButton',$parent,$emptyOptions,$request,$relationRequest,dp_panel_renderer_data_state($emptyOptions,$emptyOps),$records[0],'attach'));

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationDetachButton',$parent,RelationManager::make('none'),$request,$records[0],$records[1],null));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationDetachButton',$parent,$relation,$request,$records[0],['value'=>'no key'],null));
	$t->contains('child_key',$t->nonPublic(PanelRenderer::class)->invoke('relationDetachButton',$parent,$relation,$request,$records[0],$records[1],$child));
	$t->contains('relation_action" value="detach',$t->nonPublic(PanelRenderer::class)->invoke('relationSimpleRowOperationButton',$parent,$relation,$request,$state,$records[0],$records[1],$child,'detach'));
	$t->contains('warning',$t->nonPublic(PanelRenderer::class)->invoke('relationSimpleRowOperationButton',$parent,$relation,$request,$state,$records[0],$records[1],$child,'dissociate'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationSimpleRowOperationButton',$parent,$relation,$request,$state,$records[0],['value'=>'no key'],null,'detach'));
	$rowOps=$t->nonPublic(PanelRenderer::class)->invoke('relationRowOperationButtons',$parent,$relation,$request,$state,$records[0],$records[1],$child);
	$t->contains('update_pivot',$rowOps);
	$t->contains('dissociate',$rowOps);

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationPivotButton',$parent,$relation,$request,$state,$records[0],['value'=>'no key'],null));
	$noFields=RelationManager::make('none');
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationPivotButton',$parent,$noFields,$request,$state,$records[0],$records[1],$child));
	$t->contains('Pivot note',$t->nonPublic(PanelRenderer::class)->invoke('relationPivotButton',$parent,$relation,$request,$state,$records[0],$records[1],$child));

	$t->same('Alpha - Alpha',$t->nonPublic(PanelRenderer::class)->invoke('relationOptionLabel',$records[0],$child));
	$t->same('Alpha',$t->nonPublic(PanelRenderer::class)->invoke('relationOptionLabel',$records[0],null));
	$t->same('Record',$t->nonPublic(PanelRenderer::class)->invoke('relationOptionLabel',[ 'value'=>'x' ],null));

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationCreateButton',$parent,RelationManager::make('none'),$request,$relationRequest,$records[0]));
	$t->contains('create',$t->nonPublic(PanelRenderer::class)->invoke('relationCreateButton',$parent,$relation,$request,$relationRequest,$records[0]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationCreateButton',$parent,$relation,$request,$relationRequest,['id'=>new stdClass()]));
	$missingChildRelation=RelationManager::make('missing_child')->relatedResource('does_not_exist')->foreignKey('order_id')->localKey('id');
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationCreateButton',$parent,$missingChildRelation,$request,$relationRequest,$records[0]));

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationReorderButton',$parent,$relation,$request,$relationRequest,dp_panel_renderer_data_state($relation,[]),$records[0],$records));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationReorderButton',$parent,$relation,$request,$relationRequest,$state,$records[0],[]));
	$t->contains('ordered_keys[]',$t->nonPublic(PanelRenderer::class)->invoke('relationReorderButton',$parent,$relation,$request,$relationRequest,$state,$records[0],$records));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationReorderButton',$parent,$relation,$request,$relationRequest,$state,$records[0],[['name'=>'no key']]));

	$header=$t->nonPublic(PanelRenderer::class)->invoke('relationHeaderHtml',$parent,$relation,$request,$relationRequest,$records[0],$records,array_slice($records,0,1),1);
	$t->contains('Order 1',$header);
	$t->contains('in view',$header);
	$t->contains('Products assigned',$header);

	$resolved=$t->nonPublic(PanelRenderer::class)->invoke('relationState',$parent,$relation,$request,$records[0]);
	$t->instanceOf(PanelRelationState::class,$resolved);
	$rendered=$t->nonPublic(PanelRenderer::class)->invoke('relationTableHtml',$parent,$relation,$request,$records[0],$resolved);
	$t->contains('dp-panel-relation',$rendered);
	$t->contains('Alpha',$rendered);
	$public=PanelRenderer::relation($parent,$request,$records[0]);
	$t->same(200,$public->status());
	$t->same('relation',$public->data()['kind']);
	$inferredRelation=RelationManager::make('inferred')->queryUsing(static fn(): array=>[['id'=>1,'name'=>'Inferred']]);
	$inferredState=$t->nonPublic(PanelRenderer::class)->invoke('relationState',$parent,$inferredRelation,dp_panel_renderer_data_request(),null);
	$t->same(['id','name'],array_keys($inferredState->columns()));

	$emptyRelation=RelationManager::make('empty')->queryUsing(static fn(): array=>[]);
	$emptyParent=Resource::make('data_empty_parent')->recordKeyUsing('id')->relations([$emptyRelation]);
	$emptyRequest=PanelRequest::fromArray(['resource'=>'data_empty_parent','operation'=>'relation','record'=>'1','relation'=>'empty']);
	$emptyHtml=$t->nonPublic(PanelRenderer::class)->invoke('relationTableHtml',$emptyParent,$emptyRelation,$emptyRequest,['id'=>1],null);
	$t->contains('dp-panel-empty-state',$emptyHtml);
});
