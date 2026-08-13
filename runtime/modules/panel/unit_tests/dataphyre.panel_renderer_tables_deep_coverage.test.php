<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/panel_test_probes.php';

use Dataphyre\Panel\Action;
use Dataphyre\Panel\ActionGroup;
use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\ResourceTable;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Panel\TableGroup;
use Dataphyre\Panel\TableSummary;
use Dataphyre\Panel\TableView;
use Dataphyre\Panel\TestFixtures\RendererEntropyScenario;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

if(!function_exists('Dataphyre\\Mvc\\random_bytes')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Mvc;
function random_bytes(int $length): string {
	if(\Dataphyre\Panel\TestFixtures\RendererEntropyScenario::randomBytesShouldFail()){
		throw new \RuntimeException('token generation failed');
	}
	return \random_bytes($length);
}
PHP);
}

/** @param array<string,mixed> $query @param array<string,mixed> $input */
function dp_panel_renderer_tables_request(array $query=[],array $input=[],string $operation='index',?string $record=null,string $method='GET'): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>$method,
		'resource'=>'renderer-table-records',
		'operation'=>$operation,
		'record'=>$record,
		'query'=>$query,
		'input'=>$input,
		'user'=>['id'=>7,'name'=>'Table Operator'],
	]);
}

/** @return list<array<string,mixed>> */
function dp_panel_renderer_tables_records(): array {
	return [
		['id'=>'A 1','name'=>'Alpha <One>','subtitle'=>'First card','status'=>'draft','amount'=>30.5,'enabled'=>true,'created_at'=>'2026-01-03','team'=>'north'],
		['id'=>'B/2','name'=>'Beta','subtitle'=>'','status'=>'review','amount'=>10,'enabled'=>false,'created_at'=>'2026-01-01','team'=>'south'],
		['id'=>'C3','name'=>'Gamma','subtitle'=>'Third card','status'=>'published','amount'=>20,'enabled'=>true,'created_at'=>'2026-01-02','team'=>'north'],
		['id'=>'','name'=>'No key','subtitle'=>'Unmatched','status'=>'unknown','amount'=>0,'enabled'=>false,'created_at'=>'','team'=>''],
	];
}

function dp_panel_renderer_tables_group(): TableGroup {
	return TableGroup::make('team')
		->label('Team')
		->direction('desc')
		->collapsible()
		->collapsed()
		->labelUsing(static fn(string $key): string=>$key==='__blank' ? 'Unassigned' : strtoupper($key))
		->descriptionUsing(static fn(string $key): string=>$key==='north' ? 'Northern team' : '')
		->summary(TableSummary::make('group_total','sum')->column('amount')->label('Total')->tone('success'))
		->actions([
			['label'=>'Open team','url'=>static fn(string $key): string=>'/panel/renderer-table-records?team='.$key,'tone'=>'info','icon'=>'users','target'=>'_blank'],
		]);
}

function dp_panel_renderer_tables_resource(bool $full=true): Resource {
	$actions=[];
	if($full){
		$actions=[
			Action::make('inspect')->label('Inspect')->icon('eye')->tone('info')->tooltip('Inspect record')->keyBindings(['ctrl+i'])
				->requiresConfirmation()->confirmation('Inspect now?')->handle(static fn(): array=>['ok'=>true]),
			Action::make('content')->label('Read guide')->infoModal('<p>Guide</p>','Guide')->ghost(),
			Action::make('disabled')->label('Unavailable')->disabled(true,'Waiting for approval')->outlined(),
			Action::make('hidden')->label('Hidden')->hidden(),
			Action::make('bulk_mark')->label('Mark selected')->bulk()->handle(static fn(): bool=>true),
			ActionGroup::make('tools')->label('Tools')->icon('wrench')->tone('warning')->dropdownWidth('lg')->alignStart()
				->section('General','Useful actions')
				->action(Action::make('group_action')->label('Grouped action')->handle(static fn(): bool=>true))
				->divider()
				->action(Action::make('group_bulk')->label('Grouped bulk')->bulk()->handle(static fn(): bool=>true)),
		];
	}

	$resource=Resource::make('renderer-table-records')
		->label('Renderer Record')
		->pluralLabel('Renderer Records')
		->recordKeyUsing('id')
		->recordTitleUsing('name')
		->recordSubtitleUsing('subtitle')
		->queryUsing(static fn(): array=>dp_panel_renderer_tables_records())
		->columns([
			Column::make('name')->label('Name')->sortable()->searchable()->tooltip('Sort by name')
				->headerAttributes(['class'=>'head-name','data-kind'=>'name'])
				->cellAttributes(static fn(array $record): array=>['class'=>'cell-name','data-record'=>$record['id'] ?? '']),
			Column::make('amount','number')->label('Amount')->sortable()->align('right')->group('Financial','Money columns')
				->editable('number')->sum('Total amount'),
			Column::make('status')->label('Status')->group('Financial','Money columns')->editable('select',['draft'=>'Draft','review'=>'Review','published'=>'Published']),
			Column::make('enabled','boolean')->label('Enabled')->editable('boolean')->hiddenByDefault(),
			Column::make('created_at','date')->label('Created')->date()->toggleable(false),
			Column::make('secret')->label('Secret')->hiddenOn('index'),
		])
		->filters([
			TableFilter::make('status','select')->label('Status')->options(['draft'=>'Draft','review'=>'Review','published'=>'Published'])->indicatorTone('success'),
			TableFilter::make('amount')->label('Amount')->numberRange(),
			TableFilter::make('enabled','boolean')->label('Enabled'),
			TableFilter::make('created_at','date')->label('Created'),
			TableFilter::make('name','text')->label('Name'),
			TableFilter::make('hidden_filter')->hidden(),
		])
		->views([
			TableView::make('high_value')->label('High value')->tone('warning')->where(static fn(array $record): bool=>(float)($record['amount'] ?? 0)>=20)->badge('2'),
			TableView::make('query_defaults')->label('Defaults')->query(['q'=>'Alpha','visible_columns'=>['name','amount']])->where(static fn(): bool=>true),
		])
		->summaries([
			TableSummary::make('rows','count')->label('Rows')->tone('neutral'),
			TableSummary::make('amount','sum')->column('amount')->label('Amount')->money('CAD')->tone('invalid'),
		])
		->tableGroups([dp_panel_renderer_tables_group()])
		->perPage(2)
		->perPageOptions([1,2,25,999])
		->defaultSort('name','desc')
		->rowAttributes(static fn(array $record): array=>['class'=>'record-row','data-id'=>$record['id'] ?? ''])
		->recordAction('inspect',true)
		->previewFields(static fn(array $record): array=>[['label'=>'Name','value'=>$record['name'] ?? '']],true)
		->statusField('status')
		->statusWidgets()
		->statusTransitions([
			'publish'=>['to'=>'published','from'=>['draft','review'],'label'=>'Publish','tone'=>'success'],
			'review'=>['to'=>'review','from'=>'draft','label'=>'Send to review','tone'=>'warning','confirmation'=>'Review this record?'],
			'archive'=>['to'=>'archived','from'=>'published','label'=>'Archive','tone'=>'neutral'],
		])
		->transitionUsing(static fn(): array=>['transitioned'=>true])
		->duplicateUsing(static fn(): array=>['duplicated'=>true])
		->restoreUsing(static fn(): array=>['restored'=>true])
		->deleteUsing(static fn(): array=>['deleted'=>true])
		->forceDeleteUsing(static fn(): array=>['force_deleted'=>true])
		->bulkField(Field::make('status')->required())
		->bulkUpdateUsing(static fn(): array=>['updated'=>true])
		->importUsing(static fn(): array=>['imported'=>true])
		->actions($actions);
	return $resource;
}

suite('Panel table renderer contracts')
	->contract('panel.table-renderer', 1)
	->layer('integration')
	->risk('high')
	->watches('module:panel', 'module:mvc')
	->through('resource-table', 'renderer', 'html')
	->isolation('case')
	->tag('panel', 'renderer', 'tables')
	->group('framework-coverage');

test('panel renderer tables renders a feature rich index and grouped variant',static function(Context $t): void {
	$resource=dp_panel_renderer_tables_resource();
	$records=dp_panel_renderer_tables_records();
	$request=dp_panel_renderer_tables_request([
		'q'=>'a','sort'=>'amount','dir'=>'desc','per_page'=>3,'page'=>1,
		'status'=>'draft','amount_from'=>'10','amount_to'=>'40','enabled'=>'1','created_at'=>'2026-01-03',
		'visible_columns'=>'name,amount,status,enabled,created_at','density'=>'compact',
	]);
	$result=PanelContext::run([
		'table_density_controls'=>true,
		'resource_exports'=>true,
		'resource_imports'=>true,
		'table_pagination_visibility'=>'always',
	],static fn()=>PanelRenderer::index($resource,$request,$records));
	$t->same(200,$result->status());
	$t->same('index',$result->data()['kind']);
	$t->contains('dp-panel-table-compact',$result->content());
	$t->contains('dp-panel-summaries',$result->content());
	$t->contains('dp-panel-filter-modal',$result->content());
	$t->contains('dp-panel-column-group-row',$result->content());
	$t->contains('bulk_export',$result->content());

	$grouped=PanelRenderer::index($resource,dp_panel_renderer_tables_request([
		'group'=>'team','visible_columns'=>['name','amount','status'],'per_page'=>25,
	]),$records);
	$t->same(4,$grouped->data()['record_count']);
	$t->contains('dp-panel-table-group-row',$grouped->content());
	$t->contains('Northern team',$grouped->content());
	$t->contains('dp-panel-table-group-action',$grouped->content());

	$paginated=PanelRenderer::index($resource,dp_panel_renderer_tables_request(['page'=>8,'per_page'=>2]),[$records[0]],10,true);
	$t->same(10,$paginated->data()['total_count']);
	$t->contains('Summaries reflect the records supplied to this page.',$paginated->content());
})->tag('panel','renderer','tables','coverage')->group('framework-coverage');

test('panel renderer tables renders status board availability cards and permissions',static function(Context $t): void {
	$resource=dp_panel_renderer_tables_resource();
	$records=dp_panel_renderer_tables_records();
	$request=dp_panel_renderer_tables_request(['q'=>'','sort'=>'name','dir'=>'asc'],[],'board');
	$board=PanelRenderer::statusBoard($resource,$request,$records);
	$t->same(200,$board->status());
	$t->same('board',$board->data()['kind']);
	$t->contains('dp-panel-board-column',$board->content());
	$t->contains('draggable="true"',$board->content());
	$t->contains('dp-panel-board-empty',$board->content());
	$t->contains('Unmatched',$board->content());

	$already=PanelRenderer::statusBoard($resource,$request,[$records[2]],8,true);
	$t->same(8,$already->data()['total_count']);

	$unavailable=PanelRenderer::statusBoard(Resource::make('plain'),$request,[]);
	$t->same(404,$unavailable->status());
	$denied=$resource->authorize(static fn(string $ability): bool=>$ability!=='board');
	$t->same(403,PanelRenderer::statusBoard($denied,$request,$records)->status());
})->tag('panel','renderer','tables','coverage')->group('framework-coverage');

test('panel renderer tables covers summaries views groups search filtering sorting and recommendations',static function(Context $t): void {
	$resource=dp_panel_renderer_tables_resource();
	$records=dp_panel_renderer_tables_records();
	$request=dp_panel_renderer_tables_request(['view'=>'high_value','group'=>'team','q'=>'Alpha','sort'=>'name','dir'=>'desc']);

	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('summaryData',$resource,$request,$records)));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('summaryHtml',[]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('summaryHtml',[null,false]));
	$summaryHtml=$t->nonPublic(PanelRenderer::class)->invoke('summaryHtml',[['label'=>'<Rows>','formatted'=>['x'=>1],'type'=>'count','tone'=>'invalid']],true);
	$t->contains('&lt;Rows&gt;',$summaryHtml);
	$t->contains('records supplied',$summaryHtml);

	$t->same('high_value',$t->nonPublic(PanelRenderer::class)->invoke('activeTableViewName',$resource,$request));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('activeTableGroup',Resource::make('empty'),$request));
	$t->same('team',$t->nonPublic(PanelRenderer::class)->invoke('activeTableGroup',$resource,$request)->name());
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('tableGroupsHtml',Resource::make('empty'),$request));
	$t->contains('aria-current',$t->nonPublic(PanelRenderer::class)->invoke('tableGroupsHtml',$resource,$request));
	$t->contains('group=none',$t->nonPublic(PanelRenderer::class)->invoke('tableGroupLink',$resource,[],'','Ungrouped',true));
	$t->contains('group=team',$t->nonPublic(PanelRenderer::class)->invoke('tableGroupLink',$resource,[],'team','Team',false));

	$views=$t->nonPublic(PanelRenderer::class)->invoke('statusBoardViews',$resource);
	$t->isTrue(count($views)>=3);
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('statusBoardViews',Resource::make('empty')));
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('boardTransitionTargets',Resource::make('empty'),$records[0],$request));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('boardTransitionTargets',$resource,$records[0],$request)!==[]);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('boardPulseHtml',$resource,$request,[],0,0));

	foreach([
		['q',1,0,0,0,2],['',0,0,0,0,2],['',0,2,0,1,2],['',0,2,1,0,2],['q',0,2,0,0,2],['',0,2,0,0,2],
	] as $args){
		$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('boardPulseRecommendation',...$args)));
	}

	$t->same($records,$t->nonPublic(PanelRenderer::class)->invoke('applyTableView',$records,$resource,$request,''));
	$t->same($records,$t->nonPublic(PanelRenderer::class)->invoke('applyTableView',$records,$resource,$request,'missing'));
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('applyTableView',$records,$resource,$request,'high_value')));
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('tableViewCounts',Resource::make('empty'),$request,$records));
	$t->isTrue(count($t->nonPublic(PanelRenderer::class)->invoke('tableViewCounts',$resource,$request,$records))>=3);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('tableViewsHtml',Resource::make('empty'),$request,''));
	$t->contains('dp-panel-table-view',$t->nonPublic(PanelRenderer::class)->invoke('tableViewsHtml',$resource,$request,'high_value',[]));
	$t->contains('<small>7</small>',$t->nonPublic(PanelRenderer::class)->invoke('tableViewLink',$resource,['blank'=>''],'high_value','High','invalid',true,7));
	$t->contains('view=all',$t->nonPublic(PanelRenderer::class)->invoke('tableViewLink',$resource,['view'=>'old'],'all','All','neutral',false,''));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('tablePulseHtml',$resource,$request,1,1,1,25,'',false,0));

	foreach([
		['q',0,'',0,false,0],['q',0,'',2,false,0],['',0,'saved',2,false,0],['',0,'',2,true,0],['',0,'',2,false,1],['',0,'',1,false,0],
	] as $args){
		$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('tablePulseRecommendation',...$args)));
	}

	$t->same($records,$t->nonPublic(PanelRenderer::class)->invoke('filterRecords',$records,$resource,dp_panel_renderer_tables_request()));
	$t->same(1,count($t->nonPublic(PanelRenderer::class)->invoke('filterRecords',$records,$resource,dp_panel_renderer_tables_request(['q'=>'Gamma']))));
	$fallback=Resource::make('fallback')->columns([Column::make('name')]);
	$t->same(1,count($t->nonPublic(PanelRenderer::class)->invoke('filterRecords',$records,$fallback,dp_panel_renderer_tables_request(['q'=>'Beta']))));
	$t->same($records,$t->nonPublic(PanelRenderer::class)->invoke('applyFilters',$records,Resource::make('none'),$request));
	$t->same(1,count($t->nonPublic(PanelRenderer::class)->invoke('applyFilters',$records,$resource,dp_panel_renderer_tables_request(['status'=>'review']))));
	$t->same($records,$t->nonPublic(PanelRenderer::class)->invoke('sortRecords',$records,$resource,dp_panel_renderer_tables_request(['sort'=>'missing'])));
	$t->same('Alpha <One>',$t->nonPublic(PanelRenderer::class)->invoke('sortRecords',$records,$resource,dp_panel_renderer_tables_request(['sort'=>'name','dir'=>'asc']))[0]['name']);
	$t->same(['name','desc'],$t->nonPublic(PanelRenderer::class)->invoke('sortState',$resource,dp_panel_renderer_tables_request()));
	$t->same(['name','asc'],$t->nonPublic(PanelRenderer::class)->invoke('sortState',$resource,dp_panel_renderer_tables_request(['sort'=>'name','dir'=>'bad'])));
})->tag('panel','renderer','tables','coverage')->group('framework-coverage');

test('panel renderer tables covers filter controls indicators links and active state',static function(Context $t): void {
	$resource=dp_panel_renderer_tables_resource();
	$request=dp_panel_renderer_tables_request([
		'q'=>' Alpha ','sort'=>'name','dir'=>'desc','per_page'=>7,'view'=>'all','group'=>'team',
		'status'=>'draft','amount_from'=>'10','amount_to'=>'20','enabled'=>'0','created_at'=>'2026-01-01','name'=>'Al',
		'visible_columns'=>['name','amount'],'density'=>'comfortable','page'=>3,
	]);

	$t->same(['resource'=>'renderer-table-records'],$t->nonPublic(PanelRenderer::class)->invoke('tableRouteParams',$resource));
	$t->same(['resource'=>'renderer-table-records','operation'=>'board'],$t->nonPublic(PanelRenderer::class)->invoke('tableRouteParams',$resource,'board'));
	$t->contains('name="q"',$t->nonPublic(PanelRenderer::class)->invoke('searchForm',$resource,$request));
	$t->contains('dp-panel-search-compact',$t->nonPublic(PanelRenderer::class)->invoke('compactSearchForm',$resource,$request));
	$t->contains('operation" value="board',$t->nonPublic(PanelRenderer::class)->invoke('boardSearchForm',$resource,$request));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('filtersHtml',Resource::make('none'),$request));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('boardFiltersHtml',Resource::make('none'),$request));
	$t->contains('dp-panel-filter-trigger',$t->nonPublic(PanelRenderer::class)->invoke('filtersHtml',$resource,$request));
	$t->contains('dp-panel-filter-trigger',$t->nonPublic(PanelRenderer::class)->invoke('boardFiltersHtml',$resource,$request));
	$t->contains('dp-panel-filter-template',$t->nonPublic(PanelRenderer::class)->invoke('filterModalLauncher',$resource,3,'Filters','Description','<form></form>'));

	$t->contains('type="number"',$t->nonPublic(PanelRenderer::class)->invoke('filterControl',TableFilter::make('amount')->numberRange(),$request,'prefix_'));
	$t->contains('type="date"',$t->nonPublic(PanelRenderer::class)->invoke('filterControl',TableFilter::make('date')->dateRange(),dp_panel_renderer_tables_request(['date_from'=>'2026-01-01'])));
	$t->contains('type="text"',$t->nonPublic(PanelRenderer::class)->invoke('filterControl',TableFilter::make('range')->range(),dp_panel_renderer_tables_request()));
	$t->contains('<select',$t->nonPublic(PanelRenderer::class)->invoke('filterControl',TableFilter::make('status','enum')->options(['a'=>'A']),dp_panel_renderer_tables_request(['status'=>'a'])));
	$t->contains('value="1" selected',$t->nonPublic(PanelRenderer::class)->invoke('filterControl',TableFilter::make('enabled','boolean'),dp_panel_renderer_tables_request(['enabled'=>'yes'])));
	$t->contains('value="0" selected',$t->nonPublic(PanelRenderer::class)->invoke('filterControl',TableFilter::make('enabled','toggle'),dp_panel_renderer_tables_request(['enabled'=>'0'])));
	$t->contains('type="date"',$t->nonPublic(PanelRenderer::class)->invoke('filterControl',TableFilter::make('created','date'),dp_panel_renderer_tables_request(['created'=>'2026-01-01'])));
	$t->contains('type="text"',$t->nonPublic(PanelRenderer::class)->invoke('filterControl',TableFilter::make('name'),dp_panel_renderer_tables_request(['name'=>'Alice'])));

	$params=$t->nonPublic(PanelRenderer::class)->invoke('activeFilterParams',$resource,$request);
	$t->same('draft',$params['status']);
	$t->same('10',$params['amount_from']);
	$t->same('20',$params['amount_to']);
	$t->contains('dp-panel-filter-chip',$t->nonPublic(PanelRenderer::class)->invoke('activeFilterChipsHtml',$resource,$request));
	$t->contains('dp-panel-filter-chip',$t->nonPublic(PanelRenderer::class)->invoke('boardActiveFilterChipsHtml',$resource,$request));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('activeFilterChipsHtml',$resource,dp_panel_renderer_tables_request()));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('boardActiveFilterChipsHtml',$resource,dp_panel_renderer_tables_request()));
	$t->isTrue(count($t->nonPublic(PanelRenderer::class)->invoke('activeFilterIndicators',$resource,$request))>=5);

	$filter=TableFilter::make('x');
	$t->same('10 to 20',$t->nonPublic(PanelRenderer::class)->invoke('filterValueLabel',$filter,[],['from'=>10,'to'=>20]));
	$t->same('from 10',$t->nonPublic(PanelRenderer::class)->invoke('filterValueLabel',$filter,[],['from'=>10]));
	$t->same('to 20',$t->nonPublic(PanelRenderer::class)->invoke('filterValueLabel',$filter,[],['to'=>20]));
	$t->same('Yes',$t->nonPublic(PanelRenderer::class)->invoke('filterValueLabel',$filter,['type'=>'bool'],'yes'));
	$t->same('No',$t->nonPublic(PanelRenderer::class)->invoke('filterValueLabel',$filter,['type'=>'toggle'],0));
	$t->same('Alpha',$t->nonPublic(PanelRenderer::class)->invoke('filterValueLabel',$filter,['options'=>['a'=>'Alpha']],'a'));
	$t->same('missing',$t->nonPublic(PanelRenderer::class)->invoke('filterValueLabel',$filter,['options'=>['a'=>'Alpha']],'missing'));

	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('filterBaseParams',$resource,$request)!==[]);
	$t->contains('resource=renderer-table-records',$t->nonPublic(PanelRenderer::class)->invoke('filterResetUrl',$resource,$request));
	$t->isFalse(str_contains($t->nonPublic(PanelRenderer::class)->invoke('filterClearUrl',$resource,$request,'status'),'status=draft'));
	$t->isFalse(str_contains($t->nonPublic(PanelRenderer::class)->invoke('filterClearUrl',$resource,$request,'amount',['amount_from']),'amount_from=10'));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('boardFilterBaseParams',$request)!==[]);
	$t->contains('operation=board',$t->nonPublic(PanelRenderer::class)->invoke('boardFilterResetUrl',$resource,$request));
	$t->isFalse(str_contains($t->nonPublic(PanelRenderer::class)->invoke('boardFilterClearUrl',$resource,$request,'status'),'status=draft'));
	$t->isFalse(str_contains($t->nonPublic(PanelRenderer::class)->invoke('boardFilterClearUrl',$resource,$request,'amount',['amount_to']),'amount_to=20'));

	$t->same(['view'=>'all'],$t->nonPublic(PanelRenderer::class)->invoke('activeViewParams',$resource,$request));
	$t->same(['visible_columns'=>'name,amount'],$t->nonPublic(PanelRenderer::class)->invoke('activeColumnParams',$request));
	$t->same(['group'=>'team'],$t->nonPublic(PanelRenderer::class)->invoke('activeGroupParams',$resource,$request));
	PanelContext::run(['table_density_controls'=>true],static function()use($t,$request): void {
		$t->same(['density'=>'comfortable'],$t->nonPublic(PanelRenderer::class)->invoke('activeDensityParams',$request));
	});
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('hasActiveTableState',$resource,$request));
	$t->isFalse($t->nonPublic(PanelRenderer::class)->invoke('hasActiveTableState',$resource,dp_panel_renderer_tables_request()));
})->tag('panel','renderer','tables','coverage')->group('framework-coverage');

test('panel renderer tables covers preferences columns density pagination and empty states',static function(Context $t): void {
	$resource=dp_panel_renderer_tables_resource();
	$plain=dp_panel_renderer_tables_request();
	$t->same('renderer-table-records',$t->nonPublic(PanelRenderer::class)->invoke('tablePreferenceKey',$plain));
	$t->same('dashboard',$t->nonPublic(PanelRenderer::class)->invoke('tablePreferenceKey',PanelRequest::fromArray([])));
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('tablePreferences',$plain));
	$t->nonPublic(PanelRenderer::class)->invoke('saveTablePreferences',$plain,['density'=>'compact']);
	$t->nonPublic(PanelRenderer::class)->invoke('clearTablePreferences',$plain);
	$t->isFalse($t->nonPublic(PanelRenderer::class)->invoke('resettingTablePreferences',$plain));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('resettingTablePreferences',dp_panel_renderer_tables_request(['reset_table_view'=>'1'])));

	$columns=$resource->resourceTable()->columnsList();
	$t->isTrue(count($t->nonPublic(PanelRenderer::class)->invoke('visibleColumns',$columns,$plain))>=3);
	$t->same(['name','amount','created_at'],array_keys($t->nonPublic(PanelRenderer::class)->invoke('visibleColumns',$columns,dp_panel_renderer_tables_request(['visible_columns'=>'name,amount']))));
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('visibleColumns',[null],$plain));

	PanelContext::run(['table_density_controls'=>false],static function()use($t,$resource,$plain): void {
		$t->same('normal',$t->nonPublic(PanelRenderer::class)->invoke('density',$plain));
		$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('activeDensityParams',$plain));
		$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('densityHtml',$resource,$plain));
	});
	PanelContext::run(['table_density_controls'=>true],static function()use($t,$resource): void {
		$t->same('normal',$t->nonPublic(PanelRenderer::class)->invoke('density',dp_panel_renderer_tables_request(['density'=>'invalid'])));
		$t->same('normal',$t->nonPublic(PanelRenderer::class)->invoke('density',dp_panel_renderer_tables_request(['reset_table_view'=>'1'])));
		$t->contains('dp-panel-density',$t->nonPublic(PanelRenderer::class)->invoke('densityHtml',$resource,dp_panel_renderer_tables_request(['density'=>'compact'])));
	});

	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('requestedColumns',dp_panel_renderer_tables_request(['reset_table_view'=>'1'])));
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('requestedColumns',dp_panel_renderer_tables_request(['visible_columns'=>123])));
	$t->same(['name','amount'],$t->nonPublic(PanelRenderer::class)->invoke('requestedColumns',dp_panel_renderer_tables_request(['visible_columns'=>[' Name ','amount','name','']])));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('columnVisibilityHtml',$resource,$plain,[Column::make('fixed')->toggleable(false)]));
	$t->contains('dp-panel-column-picker',$t->nonPublic(PanelRenderer::class)->invoke('columnVisibilityHtml',$resource,$plain,$columns,false));
	$t->contains('dp-panel-column-picker-compact',$t->nonPublic(PanelRenderer::class)->invoke('columnVisibilityHtml',$resource,dp_panel_renderer_tables_request(['visible_columns'=>'name']),$columns,true));

	$t->contains('dp-panel-per-page',$t->nonPublic(PanelRenderer::class)->invoke('perPageHtml',$resource,dp_panel_renderer_tables_request(['per_page'=>7]),false));
	$t->contains('dp-panel-per-page-compact',$t->nonPublic(PanelRenderer::class)->invoke('perPageHtml',$resource,dp_panel_renderer_tables_request(['per_page'=>999]),true));
	PanelContext::run(['resource_exports'=>false],static function()use($t,$resource,$plain): void {
		$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('exportButtonHtml',$resource,$plain));
	});
	PanelContext::run(['resource_exports'=>true],static function()use($t,$resource,$plain): void {
		$t->contains('export',$t->nonPublic(PanelRenderer::class)->invoke('exportButtonHtml',$resource,$plain));
	});
	PanelContext::run(['resource_imports'=>false],static function()use($t,$resource,$plain): void {
		$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('importButtonHtml',$resource,$plain));
	});
	PanelContext::run(['resource_imports'=>true],static function()use($t,$resource,$plain): void {
		$t->contains('import',$t->nonPublic(PanelRenderer::class)->invoke('importButtonHtml',$resource,$plain));
	});

	foreach([
		['hide_empty',0,1,true],['hide_single',1,1,true],['hide_empty_or_single',0,1,true],['always',0,1,false],
	] as [$visibility,$total,$pages,$empty]){
		$html=PanelContext::run(['table_pagination_visibility'=>$visibility],static fn()=>$t->nonPublic(PanelRenderer::class)->invoke('paginationHtml',$resource,$plain,$total,1,25,$pages));
		$t->same($empty,$html==='');
	}
	$pagination=PanelContext::run(['table_pagination_visibility'=>'always'],static fn()=>$t->nonPublic(PanelRenderer::class)->invoke('paginationHtml',$resource,dp_panel_renderer_tables_request(['q'=>'A']),100,5,10,null));
	$t->contains('dp-panel-pagination',$pagination);
	$t->contains('dp-panel-page-gap',$pagination);
	$t->contains('dp-panel-page-current',$t->nonPublic(PanelRenderer::class)->invoke('paginationWindowHtml',$resource,[],1,1));
	$t->contains('dp-panel-page-gap',$t->nonPublic(PanelRenderer::class)->invoke('paginationWindowHtml',$resource,[],5,10));
	$t->contains('page=3',$t->nonPublic(PanelRenderer::class)->invoke('pageUrl',$resource,[],3));

	$t->contains('Nothing to show yet',$t->nonPublic(PanelRenderer::class)->invoke('tableEmptyStateHtml',[]));
	$t->contains('Fallback',$t->nonPublic(PanelRenderer::class)->invoke('tableEmptyStateHtml',[
		'heading'=>'Empty','description'=>'Description','icon'=>'box','action_label'=>'Fallback','action_url'=>'',
	],'/panel/renderer-table-records'));
	$t->contains('Open',$t->nonPublic(PanelRenderer::class)->invoke('tableEmptyStateHtml',[
		'heading'=>'Empty','action_label'=>'Open','action_url'=>'/panel/renderer-table-records',
	]));
	$t->contains('The table is ready',$t->nonPublic(PanelRenderer::class)->invoke('emptyStateHtml',Resource::make('empty'),$plain));
	$t->contains('No records fit this view',$t->nonPublic(PanelRenderer::class)->invoke('emptyStateHtml',$resource,dp_panel_renderer_tables_request(['q'=>'missing'])));
	$custom=$resource->emptyState('No rows','Try later','Go','/panel/renderer-table-records','box');
	$t->contains('No rows',$t->nonPublic(PanelRenderer::class)->invoke('emptyStateHtml',$custom,$plain));
})->tag('panel','renderer','tables','coverage')->group('framework-coverage');

test('panel renderer tables covers headers footers cells rows and grouped helpers',static function(Context $t): void {
	$resource=dp_panel_renderer_tables_resource();
	$request=dp_panel_renderer_tables_request(['sort'=>'name','dir'=>'asc']);
	$records=dp_panel_renderer_tables_records();
	$table=$resource->resourceTable();
	$columns=$table->columnsList();
	$name=$columns['name'];

	$t->contains('dp-panel-column-heading',$t->nonPublic(PanelRenderer::class)->invoke('columnHeader',$resource,$request,Column::make('plain')->label('Plain')));
	$t->contains('dp-panel-sort asc',$t->nonPublic(PanelRenderer::class)->invoke('columnHeader',$resource,$request,$name));
	$t->contains('head-name',$t->nonPublic(PanelRenderer::class)->invoke('columnHeaderAttributeHtml',$name,$request,$resource,$table));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('columnHeaderAttributeHtml',Column::make('plain')));
	$render=static fn(Column $column): string=>'H-'.$column->name();
	$t->contains('<tr>',$t->nonPublic(PanelRenderer::class)->invoke('tableHeaderRowsHtml',[Column::make('one'),null],$render,true,true,$request,$resource,$table));
	$t->contains('dp-panel-column-group-row',$t->nonPublic(PanelRenderer::class)->invoke('tableHeaderRowsHtml',array_values($columns),$render,true,true,$request,$resource,$table));
	$t->contains('rowspan="2"',$t->nonPublic(PanelRenderer::class)->invoke('tableHeaderRowsHtml',[Column::make('plain'),Column::make('a')->group('G'),Column::make('b')->group('G'),null],$render,false,false));

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('tableFooterRowsHtml',[Column::make('plain'),null],$records));
	$footer=$t->nonPublic(PanelRenderer::class)->invoke('tableFooterRowsHtml',[Column::make('plain'),$columns['amount'],null],$records,true,true,$request,$resource,$table);
	$t->contains('<tfoot>',$footer);
	$t->contains('Total amount',$footer);
	$t->contains('&nbsp;',$footer);
	$t->contains('cell-name',$t->nonPublic(PanelRenderer::class)->invoke('columnCellAttributeHtml',$name,$records[0],$request,$resource,$table));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('columnCellAttributeHtml',Column::make('plain'),$records[0]));

	$t->contains('record-row',$t->nonPublic(PanelRenderer::class)->invoke('tableRowAttributeHtml',$table,$records[0],$request,$resource));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('tableRowAttributeHtml',ResourceTable::make(),$records[0]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('tableRowClickAttributeHtml',$table,$records[0],$request,null));
	$t->contains('data-dp-panel-row-url',$t->nonPublic(PanelRenderer::class)->invoke('tableRowClickAttributeHtml',$table,$records[0],$request,$resource,'Alpha'));
	$plainTable=ResourceTable::make()->rowClick(false);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('tableRowClickAttributeHtml',$plainTable,$records[0],$request,$resource));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('tableRowPreviewAttributeHtml',ResourceTable::make(),$records[0],$request,$resource));
	$t->contains('previewable',$t->nonPublic(PanelRenderer::class)->invoke('tableRowPreviewAttributeHtml',$table,$records[0],$request,$resource));

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('groupSummaryChipsHtml',[]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('groupSummaryChipsHtml',[null,[],['label'=>'','value'=>'']]));
	$t->contains('dp-panel-table-group-chip',$t->nonPublic(PanelRenderer::class)->invoke('groupSummaryChipsHtml',[['label'=>'Total','formatted'=>'10','tone'=>'bad'],['value'=>'2']]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('groupActionsHtml',[]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('groupActionsHtml',[null,[],['label'=>'','url'=>''],['label'=>'Bad','url'=>'javascript:bad']]));
	$t->contains('noopener',$t->nonPublic(PanelRenderer::class)->invoke('groupActionsHtml',[['label'=>'Open','url'=>'/panel/x','tone'=>'bad','icon'=>'box','target'=>'_blank']]));
	$t->contains('target="_self"',$t->nonPublic(PanelRenderer::class)->invoke('groupActionsHtml',[['label'=>'Self','url'=>'/panel/x','target'=>'_self']]));

	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('groupedTableBody',$records,Resource::make('none'),$request,[],false,'form'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('groupedTableBody',[],$resource,dp_panel_renderer_tables_request(['group'=>'team']),array_values($columns),false,'form'));
	$t->contains('data-dp-panel-group-child',$t->nonPublic(PanelRenderer::class)->invoke('groupedTableBody',$records,$resource,dp_panel_renderer_tables_request(['group'=>'team']),array_values($columns),true,'form'));
})->tag('panel','renderer','tables','coverage')->group('framework-coverage');

test('panel renderer tables covers editable cells row actions action groups and bulk buttons',static function(Context $t): void {
	$resource=dp_panel_renderer_tables_resource();
	$request=dp_panel_renderer_tables_request(['q'=>'Alpha','page'=>2,'__panel_partial'=>'table']);
	$record=dp_panel_renderer_tables_records()[0];
	$columns=$resource->resourceTable()->columnsList();

	$t->contains('Alpha',$t->nonPublic(PanelRenderer::class)->invoke('editableCellHtml',Column::make('name'),$record,$request,$resource));
	$t->contains('type="number"',$t->nonPublic(PanelRenderer::class)->invoke('editableCellHtml',$columns['amount'],$record,$request,$resource));
	$t->contains('<select',$t->nonPublic(PanelRenderer::class)->invoke('editableCellHtml',$columns['status'],$record,$request,$resource));
	$t->contains('type="checkbox"',$t->nonPublic(PanelRenderer::class)->invoke('editableCellHtml',$columns['enabled'],$record,$request,$resource));
	$t->contains('Alpha',$t->nonPublic(PanelRenderer::class)->invoke('editableCellHtml',Column::make('name')->editable(),array_replace($record,['id'=>'']),$request,$resource));
	$t->contains('Alpha',$t->nonPublic(PanelRenderer::class)->invoke('editableCellHtml',Column::make('name')->editable('select',[]),$record,$request,$resource));

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('rowActions',$resource,array_replace($record,['id'=>''])));
	$t->contains('dp-panel-row-link',$t->nonPublic(PanelRenderer::class)->invoke('rowActions',$resource,$record,false,$request));
	$recordActions=$t->nonPublic(PanelRenderer::class)->invoke('rowActions',$resource,$record,true,$request,'/panel/renderer-table-records');
	$t->contains('dp-panel-button',$recordActions);
	$t->contains('data-dp-panel-record-action-overflow',$recordActions);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('rowMoreActionsHtml',' '));
	$t->contains('More actions',$t->nonPublic(PanelRenderer::class)->invoke('rowMoreActionsHtml','<b>Action</b>',' '));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('recordActionOverflowHtml',[],'Record'));
	$overflow=$t->nonPublic(PanelRenderer::class)->invoke('recordActionOverflowHtml',['<button>One</button>','<button>Two</button>'],'Record <One>');
	$t->contains('dp-panel-record-action-count',$overflow);
	$t->contains('>2<',$overflow);
	$t->contains('Record &lt;One&gt;',$overflow);
	$bounded=Resource::make('bounded')->recordActionLimit(1)->recordActionPlacements(['one'=>'overflow','three'=>'primary']);
	$boundedHtml=$t->nonPublic(PanelRenderer::class)->invoke('recordActionsHtml',$bounded,[
		['name'=>'one','html'=>'<button>One</button>','placement'=>'auto'],
		['name'=>'two','html'=>'<button>Two</button>','placement'=>'auto'],
		['name'=>'three','html'=>'<button>Three</button>','placement'=>'auto'],
	],'Bounded');
	$t->isTrue(strpos($boundedHtml,'Two')<strpos($boundedHtml,'Three'));
	$t->isTrue(strpos($boundedHtml,'Three')<strpos($boundedHtml,'data-dp-panel-record-action-overflow'));
	$t->isTrue(strpos($boundedHtml,'data-dp-panel-record-action-overflow')<strpos($boundedHtml,'One'));
	$t->same('<button>Visible</button>',$t->nonPublic(PanelRenderer::class)->invoke('recordActionsHtml',Resource::make('empty-action'),[
		['name'=>'empty','html'=>'  ','placement'=>'primary'],
		['name'=>'visible','html'=>'<button>Visible</button>','placement'=>'primary'],
	],'Record'),'Whitespace-only action renderers are omitted without affecting visible actions.');

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('transitionButton',$resource,array_replace($record,['id'=>'']),['name'=>'x']));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('transitionButton',$resource,$record,[]));
	$t->contains('transition',$t->nonPublic(PanelRenderer::class)->invoke('transitionButton',$resource,$record,['name'=>'publish','label'=>'Publish','tone'=>'bad'],$request));
	$t->contains('return_to',$t->nonPublic(PanelRenderer::class)->invoke('transitionButton',$resource,$record,['name'=>'publish','confirmation'=>'Sure?'],null,'/panel/renderer-table-records'));
	foreach(['duplicateButton','restoreButton','deleteButton','forceDeleteButton'] as $method){
		$t->same('',$t->nonPublic(PanelRenderer::class)->invoke($method,$resource,array_replace($record,['id'=>''])));
		$t->contains('<form',$t->nonPublic(PanelRenderer::class)->invoke($method,$resource,$record,$request));
		$t->contains('return_to',$t->nonPublic(PanelRenderer::class)->invoke($method,$resource,$record,null,'/panel/renderer-table-records'));
	}

	$t->contains('dp-panel-inline-action',$t->nonPublic(PanelRenderer::class)->invoke('resourceActions',$resource,$request));
	$t->contains('dp-panel-action-group',$t->nonPublic(PanelRenderer::class)->invoke('resourceActions',$resource,$request));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('statusBoardButtonHtml',Resource::make('none'),$request));
	$t->contains('operation=board',$t->nonPublic(PanelRenderer::class)->invoke('statusBoardButtonHtml',$resource,$request));
	$bulk=$t->nonPublic(PanelRenderer::class)->invoke('bulkActions',$resource,$request,'bulk-form');
	$t->contains('bulk_export',$bulk);
	$t->contains('bulk_transition',$bulk);
	$t->contains('bulk_update',$bulk);
	$t->contains('bulk_duplicate',$bulk);
	$t->contains('bulk_restore',$bulk);
	$t->contains('bulk_delete',$bulk);
	$t->contains('bulk_force_delete',$bulk);

	$t->contains('format=json',$t->nonPublic(PanelRenderer::class)->invoke('bulkExportButton',$resource,$request,'bulk-form'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('bulkTransitionButton',$resource,$request,'bulk-form',[]));
	$t->contains('bulk_transition',$t->nonPublic(PanelRenderer::class)->invoke('bulkTransitionButton',$resource,$request,'bulk-form',['name'=>'publish','tone'=>'bad']));
	foreach(['bulkUpdateButton','bulkDuplicateButton','bulkRestoreButton','bulkDeleteButton','bulkForceDeleteButton'] as $method){
		$t->contains('bulk-form',$t->nonPublic(PanelRenderer::class)->invoke($method,$resource,$request,'bulk-form'));
	}

	$action=Action::make('styled')->label('Styled')->style('invalid')->size('invalid')->iconOnly()->handle(static fn(): bool=>true);
	$t->contains('style-solid',$t->nonPublic(PanelRenderer::class)->invoke('actionButton',$resource,$action,null,false,null,$request));
	$t->contains('type="submit"',$t->nonPublic(PanelRenderer::class)->invoke('actionButton',$resource,$action,'A 1',false,'existing-form',$request,null,$record));
	$content=Action::make('content_only')->label('Content')->infoModal('<p>Info</p>');
	$t->contains('type="button"',$t->nonPublic(PanelRenderer::class)->invoke('actionButton',$resource,$content,'A 1',false,null,$request,null,$record));
	$disabled=Action::make('disabled')->label('Disabled')->disabled(true,null);
	$t->contains('aria-disabled="true"',$t->nonPublic(PanelRenderer::class)->invoke('actionButton',$resource,$disabled,null,false,null,$request));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('actionDisabledAttributes',Action::make('enabled')));
	$t->contains('not available',$t->nonPublic(PanelRenderer::class)->invoke('actionDisabledAttributes',$disabled));
	foreach(['solid','outline','ghost','link','bad'] as $style){
		$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('safeActionStyle',$style)!=='');
	}
	foreach(['xs','sm','md','lg','xl','bad'] as $size){
		$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('safeActionSize',$size)!=='');
	}

	$emptyGroup=ActionGroup::make('empty')->section('Empty')->action(Action::make('hidden')->hidden());
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('resourceActionGroupButton',$resource,$emptyGroup,null,false,null,$request));
	$group=ActionGroup::make('menu')->label(' ')->icon('tools')->iconOnly()->dropdownWidth('bad')->dropdownAlignment('center')
		->section('Section','Description')->divider()->action(Action::make('one')->handle(static fn(): bool=>true));
	$t->contains('dp-panel-action-group',$t->nonPublic(PanelRenderer::class)->invoke('resourceActionGroupButton',$resource,$group,null,false,null,$request));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('actionGroupSectionHtml',' '));
	$t->contains('<small>',$t->nonPublic(PanelRenderer::class)->invoke('actionGroupSectionHtml','Section','Description'));
	foreach(['auto','xs','sm','md','lg','xl','bad'] as $width){
		$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('safeActionGroupWidth',$width)!=='');
	}
	foreach(['left','start','before','center','middle','right','bad'] as $alignment){
		$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('safeActionGroupAlignment',$alignment)!=='');
	}
})->tag('panel','renderer','tables','coverage')->group('framework-coverage');

test('panel renderer tables covers query and safe return helpers',static function(Context $t): void {
	$resource=dp_panel_renderer_tables_resource(false);
	$request=dp_panel_renderer_tables_request([
		'page'=>3,'__panel_partial'=>'table','q'=>'Alpha','empty'=>'','nested'=>['keep'=>'yes','drop'=>''],
		'operation'=>'action','record'=>'A 1','relation'=>'items','action'=>'run','view'=>'all','visible_columns'=>'name','density'=>'compact','per_page'=>10,
	],[],'action','A 1');

	$t->contains('action=run',$t->nonPublic(PanelRenderer::class)->invoke('actionUrl',$resource,'Run','A 1',$request));
	$t->contains('record=A+1',$t->nonPublic(PanelRenderer::class)->invoke('actionUrl',$resource,'Run','A 1',$request));
	$t->contains('action=run',$t->nonPublic(PanelRenderer::class)->invoke('actionUrl',$resource,'Run',null,null));
	$query=$t->nonPublic(PanelRenderer::class)->invoke('queryWithoutPage',$request);
	$t->isFalse(array_key_exists('page',$query));
	$t->isFalse(array_key_exists('__panel_partial',$query));
	$t->same(['zero'=>0,'nested'=>['keep'=>'yes']],$t->nonPublic(PanelRenderer::class)->invoke('filterQueryValues',['blank'=>'','zero'=>0,'nested'=>['keep'=>'yes','drop'=>''],'empty_array'=>[]]));

	$t->contains('return_to',$t->nonPublic(PanelRenderer::class)->invoke('returnInput',$resource,$request));
	$t->isTrue(str_contains($t->nonPublic(PanelRenderer::class)->invoke('csrfInput'),'_token') || $t->nonPublic(PanelRenderer::class)->invoke('csrfInput')==='');
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('returnInputUrl','https://evil.test/panel'));
	$t->contains('return_to',$t->nonPublic(PanelRenderer::class)->invoke('returnInputUrl','/panel/renderer-table-records?x=1'));
	$t->contains('operation=show',$t->nonPublic(PanelRenderer::class)->invoke('actionReturnUrl',$resource,$request));
	$t->contains('record=A+1',$t->nonPublic(PanelRenderer::class)->invoke('actionReturnUrl',$resource,$request));
	$t->contains('resource=renderer-table-records',$t->nonPublic(PanelRenderer::class)->invoke('actionReturnUrl',$resource,dp_panel_renderer_tables_request(['q'=>'A'])));
	$t->contains('operation=board',$t->nonPublic(PanelRenderer::class)->invoke('boardReturnUrl',$resource,$request));
	$t->isFalse(str_contains($t->nonPublic(PanelRenderer::class)->invoke('boardReturnUrl',$resource,$request),'visible_columns'));
	$t->isFalse(str_contains($t->nonPublic(PanelRenderer::class)->invoke('tableReturnUrl',$resource,$request),'view='));
	$t->contains('operation=show',$t->nonPublic(PanelRenderer::class)->invoke('showReturnUrl',$resource,['id'=>'A 1']));
	$t->contains('record=A+1',$t->nonPublic(PanelRenderer::class)->invoke('showReturnUrl',$resource,['id'=>'A 1']));
	$t->same('/?resource=renderer-table-records',$t->nonPublic(PanelRenderer::class)->invoke('showReturnUrl',$resource,['id'=>'']));

	$provided=dp_panel_renderer_tables_request(['return_to'=>'/panel/from-query'],['return_to'=>'https://evil.test']);
	$t->same('/panel/from-query',$t->nonPublic(PanelRenderer::class)->invoke('requestProvidedReturnUrl',$provided));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('requestProvidedReturnUrl',dp_panel_renderer_tables_request(['return_to'=>false],['return_to'=>null])));
	foreach(['','https://evil.test','//evil.test','outside','/panel/x?__panel_partial=table&keep=1','/panel/x?keep=1&__panel_partial=table'] as $url){
		$result=$t->nonPublic(PanelRenderer::class)->invoke('safeReturnUrl',$url);
		if(str_starts_with($url,'/panel/') || $url==='outside'){
			$t->isTrue($result!==null);
		}
		else {
			$t->same(null,$result);
		}
	}
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('recordCheckbox',$resource,['id'=>''],'bulk'));
	$t->contains('A 1',$t->nonPublic(PanelRenderer::class)->invoke('recordCheckbox',$resource,['id'=>'A 1'],'bulk<form>'));
})->tag('panel','renderer','tables','coverage')->group('framework-coverage');

test('panel renderer tables covers malformed collections session storage and final guards',static function(Context $t): void {
	$entropy=RendererEntropyScenario::reset($t);
	$request=dp_panel_renderer_tables_request();
	$records=dp_panel_renderer_tables_records();

	$summaryTable=ResourceTable::make()->summary(TableSummary::make('rows'));
	$t->nonPublic($summaryTable)->writeProperty('summaries',['bad'=>null,'rows'=>TableSummary::make('rows')]);
	$summaryResource=Resource::make('mixed-summaries')->resourceTable($summaryTable);
	$t->same(1,count($t->nonPublic(PanelRenderer::class)->invoke('summaryData',$summaryResource,$request,$records)));

	$groupTable=ResourceTable::make()->group(TableGroup::make('team'));
	$t->nonPublic($groupTable)->writeProperty('groups',['bad'=>null,'team'=>TableGroup::make('team')]);
	$groupResource=Resource::make('mixed-groups')->resourceTable($groupTable);
	$t->contains('dp-panel-table-groups',$t->nonPublic(PanelRenderer::class)->invoke('tableGroupsHtml',$groupResource,dp_panel_renderer_tables_request(['group'=>'none'])));

	$viewTable=ResourceTable::make()->view(TableView::make('all_rows'));
	$t->nonPublic($viewTable)->writeProperty('views',['bad'=>null,'all_rows'=>TableView::make('all_rows')]);
	$viewResource=Resource::make('mixed-views')->resourceTable($viewTable);
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('tableViewCounts',$viewResource,$request,$records)));
	$t->contains('dp-panel-table-views',$t->nonPublic(PanelRenderer::class)->invoke('tableViewsHtml',$viewResource,$request,''));
	$t->contains('page=1',$t->nonPublic(PanelRenderer::class)->invoke('tableViewLink',$viewResource,['view'=>'old'],'','Blank','neutral',false,null));

	$filterTable=ResourceTable::make()->filter(TableFilter::make('status'));
	$t->nonPublic($filterTable)->writeProperty('filters',['bad'=>null,'status'=>TableFilter::make('status')]);
	$filterResource=Resource::make('mixed-filters')->resourceTable($filterTable);
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('activeFilterParams',$filterResource,$request));
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('activeFilterIndicators',$filterResource,$request));

	$hidden=Resource::make('hidden-filters')->filter(TableFilter::make('hidden')->hidden());
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('filtersHtml',$hidden,$request));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('boardFiltersHtml',$hidden,$request));
	$t->same('plain',$t->nonPublic(PanelRenderer::class)->invoke('filterValueLabel',TableFilter::make('plain'),[],'plain'));
	$t->same($records,$t->nonPublic(PanelRenderer::class)->invoke('sortRecords',$records,Resource::make('unsorted'),$request));

	$stateResource=dp_panel_renderer_tables_resource();
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('hasActiveTableState',$stateResource,dp_panel_renderer_tables_request(['status'=>'draft'])));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('hasActiveTableState',$stateResource,dp_panel_renderer_tables_request(['view'=>'high_value'])));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('hasActiveTableState',$stateResource,dp_panel_renderer_tables_request(['group'=>'team'])));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('hasActiveTableState',$stateResource,dp_panel_renderer_tables_request(['visible_columns'=>'name'])));

	$invalidTransitions=dp_panel_renderer_tables_resource();
	$t->nonPublic($invalidTransitions)->writeProperty('statusTransitions',[
		'empty_name'=>['name'=>'','label'=>'Empty','from'=>[],'to'=>'review','tone'=>'neutral','confirmation'=>''],
		'empty_target'=>['name'=>'empty_target','label'=>'Empty target','from'=>[],'to'=>'','tone'=>'neutral','confirmation'=>''],
	]);
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('boardTransitionTargets',$invalidTransitions,$records[0],$request));
	$genericCalls=0;
	$deniedTransitions=dp_panel_renderer_tables_resource()->authorize(
		static function(string $ability)use(&$genericCalls): bool {
			if($ability==='transition'){
				return ++$genericCalls===1;
			}
			return str_starts_with($ability,'transition:') && $ability!=='transition:review';
		}
	);
	$targets=$t->nonPublic(PanelRenderer::class)->invoke('boardTransitionTargets',$deniedTransitions,$records[0],$request);
	$t->isFalse(array_key_exists('review',$targets));
	$rowDenied=dp_panel_renderer_tables_resource()->authorize(static fn(): bool=>false);
	$t->contains('dp-panel-preview-row',$t->nonPublic(PanelRenderer::class)->invoke('rowActions',$rowDenied,array_replace($records[0],['name'=>'']),false,$request));
	$fallbackLabelResource=Resource::make('')->recordKeyUsing('key');
	$t->contains('K-1',$t->nonPublic(PanelRenderer::class)->invoke('rowActions',$fallbackLabelResource,['key'=>'K-1'],false,$request));
	$bulkGenericCalls=0;
	$bulkDenied=dp_panel_renderer_tables_resource()->authorize(
		static function(string $ability)use(&$bulkGenericCalls): bool {
			if($ability==='transition'){
				return ++$bulkGenericCalls===1;
			}
			return str_starts_with($ability,'transition:') && $ability!=='transition:review';
		}
	);
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('bulkActions',$bulkDenied,$request,'bulk')!=='');

	$editTable=ResourceTable::make()->rowAction('edit',true);
	$t->contains('data-dp-panel-modal',$t->nonPublic(PanelRenderer::class)->invoke('tableRowClickAttributeHtml',$editTable,$records[0],$request,$stateResource,'Alpha'));
	$badPreview=ResourceTable::make()->previewFields([['label'=>'Bad','value'=>"\xB1\x31"]],true);
	$t->same(' data-dp-panel-previewable="1"',$t->nonPublic(PanelRenderer::class)->invoke('tableRowPreviewAttributeHtml',$badPreview,$records[0],$request,$stateResource));

	$missingMenu=ActionGroup::make('missing-menu')->menu([
		['type'=>'action','name'=>'missing'],
	]);
	$t->nonPublic($missingMenu)->writeProperty('items',[[
		'type'=>'action','name'=>'missing',
	]]);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('resourceActionGroupButton',$stateResource,$missingMenu,null,false,null,$request));

	if(PHP_SESSION_ACTIVE!==session_status()){
		\session_start();
	}
	$session=$t->globalMap('_SESSION')->clear();
	$key='dataphyre_panel_table_preferences';
	$preferenceKey=$t->nonPublic(PanelRenderer::class)->invoke('tablePreferenceKey',$request);
	$session->put($key,'invalid');
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('tablePreferences',$request));
	$session->put($key,[$preferenceKey=>'invalid']);
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('tablePreferences',$request));
	$session->put($key,[$preferenceKey=>['density'=>'compact']]);
	$t->same(['density'=>'compact'],$t->nonPublic(PanelRenderer::class)->invoke('tablePreferences',$request));
	$session->put($key,'invalid');
	$t->nonPublic(PanelRenderer::class)->invoke('saveTablePreferences',$request,['density'=>'normal']);
	$t->nonPublic(PanelRenderer::class)->invoke('saveTablePreferences',$request,['visible_columns'=>['name']]);
	$t->same(['density'=>'normal','visible_columns'=>['name']],$t->nonPublic(PanelRenderer::class)->invoke('tablePreferences',$request));
	$t->nonPublic(PanelRenderer::class)->invoke('clearTablePreferences',$request);
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('tablePreferences',$request));

	$session->forget('_token');
	$t->nonPublic(\Dataphyre\Mvc\Session::class)->writeProperty('fallback',[]);
	$entropy->failRandomBytes();
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('csrfInput'));
	$entropy->failRandomBytes(false);

	$provided=dp_panel_renderer_tables_request([],['return_to'=>'/panel/provided']);
	$t->same('/panel/provided',$t->nonPublic(PanelRenderer::class)->invoke('actionReturnUrl',$stateResource,$provided));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('safeReturnUrl','javascript:bad'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('returnInputUrl','JaVaScRiPt:alert(1)'));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('safeReturnUrl','/panel/http://evil.test'));
})->tag('panel','renderer','tables','coverage')->group('framework-coverage');
