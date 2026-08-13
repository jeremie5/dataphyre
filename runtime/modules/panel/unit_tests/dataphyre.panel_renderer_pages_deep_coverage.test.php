<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Action;
use Dataphyre\Panel\ActionGroup;
use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\NavigationItem;
use Dataphyre\Panel\PageTable;
use Dataphyre\Panel\PanelActionState;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelFormState;
use Dataphyre\Panel\PanelLifecycleResult;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelNotification;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Panel\TableGroup;
use Dataphyre\Panel\TableSummary;
use Dataphyre\Panel\TableView;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

/** @param array<string,mixed> $input @param array<string,mixed> $query @param array<string,mixed> $headers */
function dp_panel_renderer_pages_request(string $method='GET',array $input=[],array $query=[],array $headers=[],string $operation='index'): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>$method,'resource'=>'coverage_page','operation'=>$operation,
		'input'=>$input,'query'=>$query,'headers'=>$headers,'user'=>['id'=>17,'name'=>'Pages Coverage'],
	]);
}

/** @return array{0:PanelPage,1:PageTable,2:list<array<string,mixed>>} */
function dp_panel_renderer_pages_table_fixture(): array {
	$records=[
		['id'=>'1','name'=>'Alpha','status'=>'open','amount'=>10],
		['id'=>'2','name'=>'Beta','status'=>'closed','amount'=>20],
		['id'=>'3','name'=>'Gamma','status'=>'open','amount'=>30],
	];
	$group=TableGroup::make('status')->label('Status')->direction('desc')->default()->collapsible()->collapsed()
		->descriptionUsing(static fn(string $key): string=>'Group '.$key)
		->summary(TableSummary::make('total')->sum('amount'))
		->action('Open group',static fn(string $key): string=>'/groups/'.$key,'primary','eye');
	$table=PageTable::make('orders')
		->label('Orders')->description('Recent orders')->emptyMessage('No custom orders')
		->columns([
			Column::make('name')->label('Name')->searchable()->sortable(),
			Column::make('status')->label('Status'),
			Column::make('amount','number')->label('Amount')->align('right')->summarize('sum'),
		])
		->views([
			TableView::make('open')->label('Open')->tone('success')->default()->where(static fn(array $record): bool=>$record['status']==='open')->badge(2),
			TableView::make('closed')->label('Closed')->where(static fn(array $record): bool=>$record['status']==='closed'),
		])
		->filters([
			TableFilter::make('status','select')->label('Status')->options(['open'=>'Open','closed'=>'Closed']),
			TableFilter::make('amount')->label('Amount')->numberRange(),
		])
		->groups([$group])
		->summary(TableSummary::make('rows')->count())
		->records($records);
	$page=PanelPage::make('coverage_page')->label('Coverage page')->table($table);
	return [$page,$table,$records];
}

test('panel renderer pages renders dashboard not found custom content widgets forms and tables',static function(Context $t): void {
	PanelManager::flush();
	$manager=PanelManager::instance();
	$manager->register(Resource::make('search_records')->label('Search records')->globalSearchUsing(
		static fn(string $query): array=>[['id'=>'1','title'=>'Found '.$query]]
	));
	$manager->registerWidget(Widget::make('health','text')->label('Health')->value('Good'));
	$manager->registerNavigationItem(NavigationItem::make('search_records')->label('Search records')->url('/panel/search_records'));
	$dashboard=PanelRenderer::dashboard($manager,dp_panel_renderer_pages_request('GET',[],['panel_search'=>'find']));
	$t->same('dashboard',$dashboard->data()['kind']);
	$t->same('find',$dashboard->data()['global_search']['query']);
	$t->notEmpty($dashboard->data()['navigation_state']);
	$t->same([],PanelRenderer::dashboard($manager,dp_panel_renderer_pages_request())->data()['global_search']['results']);
	$t->same(404,PanelRenderer::notFound(dp_panel_renderer_pages_request())->status());

	$direct=PanelPage::make('direct')->renderUsing(static fn(): PanelPageResult=>PanelPageResult::html('Direct result',207,['kind'=>'direct']));
	$t->same(207,PanelRenderer::customPage($direct,dp_panel_renderer_pages_request(),$manager)->status());

	$scalar=PanelPage::make('scalar')->label('Scalar')->content(static fn(): string=>'<p>Scalar body</p>');
	$scalarResult=PanelRenderer::customPage($scalar,dp_panel_renderer_pages_request(),$manager);
	$t->same('custom_page',$scalarResult->data()['kind']);
	$t->contains('Scalar body',$scalarResult->content());

	[$tablePage,$table]=$unused=dp_panel_renderer_pages_table_fixture();
	$formAction=Action::make('submit_report')->label('Submit report')->description('Send the report')
		->fields([Field::make('title')->required()->section('Report')])->handle(static fn(): string=>'Submitted');
	$embedded=Action::make('quick_note')->label('Quick note')->field(Field::make('note'))->handle(static fn(): string=>'Noted');
	$page=$tablePage
		->renderUsing(static fn(): array=>[
			'title'=>' Coverage dashboard ','content'=>'','status'=>218,
			'data'=>['custom'=>true],'notifications'=>[PanelNotification::info('Loaded')],
		])
		->widget(Widget::make('page_stat')->value(3))
		->primaryForm($formAction,['title'=>'Primary report','description'=>'Complete this form','include_cancel'=>true,'cancel_url'=>'/cancel','submit_label'=>'Send','sort'=>1])
		->embeddedForm($embedded,['title'=>'','description'=>'','sort'=>2]);
	$result=PanelRenderer::customPage($page,dp_panel_renderer_pages_request(),$manager);
	$t->same(218,$result->status());
	$t->same(true,$result->data()['custom']);
	$t->same(2,count($result->data()['forms']));
	$t->contains('Primary report',$result->content());
	$t->contains('dp-panel-page-table',$result->content());

	$nonPrimary=PanelPage::make('non_primary')->content('Body')->embeddedForm(
		Action::make('embedded')->field(Field::make('value'))->handle(static fn(): string=>'ok'),
		['title'=>'Embedded'],
	);
	$t->contains('Body',PanelRenderer::customPage($nonPrimary,dp_panel_renderer_pages_request(),$manager)->content());
})->tag('panel','renderer-pages','coverage')->group('framework-coverage');

test('panel renderer pages executes all page action validation confirmation lifecycle and result paths',static function(Context $t): void {
	$actions=[
		Action::make('hidden')->hidden()->handle(static fn(): string=>'never'),
		Action::make('denied')->authorize(static fn(): bool=>false)->handle(static fn(): string=>'never'),
		Action::make('disabled')->disabled(true,'Disabled for coverage')->handle(static fn(): string=>'never'),
		Action::make('before_validate')->beforeValidateUsing(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('Before validate'))->handle(static fn(): string=>'never'),
		Action::make('after_validate')->field(Field::make('value')->required())->afterValidateUsing(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('After validate'))->handle(static fn(): string=>'never'),
		Action::make('field_action')->label('Field action')->requiresConfirmation()->confirmation('Confirm fields?')->field(Field::make('value')->required())->handle(static fn(array $data): string=>'Field '.$data['value']),
		Action::make('confirm_only')->requiresConfirmation()->confirmation('Confirm only?')->handle(static fn(): string=>'Confirmed'),
		Action::make('before_lifecycle')->before(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('Before action'))->handle(static fn(): string=>'never'),
		Action::make('before_result')->before(static fn(): string=>'Before result'),
		Action::make('throws')->handle(static fn(): never=>throw new RuntimeException('Page action exploded')),
		Action::make('after_lifecycle')->handle(static fn(): string=>'base')->after(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('After action')),
		Action::make('get_success')->mutateFormDataUsing(static fn(array $data): array=>$data+['mutated'=>true])->handle(static fn(): string=>'GET success'),
		Action::make('meta_redirect')->redirectTo('/panel/coverage_page?meta=1')->handle(static fn(): string=>'Meta'),
		Action::make('result_redirect')->effects(['refresh'=>'navigation'])->handle(static fn(): array=>[
			'message'=>'Redirected','redirect'=>'/panel/coverage_page?result=1','status'=>307,
			'effects'=>['close_modal'=>true],
		]),
	];
	$page=PanelPage::make('coverage_page')->actions($actions);
	$manager=PanelManager::instance();
	$get=dp_panel_renderer_pages_request('GET',[],[],[],'action');
	$t->same(404,PanelRenderer::pageActionResult($page,$get,'missing',$manager)->status());
	$t->same('page_action_hidden',PanelRenderer::pageActionResult($page,$get,'hidden',$manager)->data()['kind']);
	$t->same(403,PanelRenderer::pageActionResult($page,$get,'denied',$manager)->status());
	$t->same(409,PanelRenderer::pageActionResult($page,$get,'disabled',$manager)->status());
	$t->same('page_action_lifecycle_result',PanelRenderer::pageActionResult($page,$get,'before_validate',$manager)->data()['kind']);
	$t->same('page_action_form',PanelRenderer::pageActionResult($page,$get,'field_action',$manager)->data()['kind']);
	$modal=PanelRenderer::pageActionResult($page,dp_panel_renderer_pages_request('GET',[],[],['x-requested-with'=>'DataphyrePanelModal'],'action'),'field_action',$manager);
	$t->same('text/html; charset=utf-8',$modal->headers()['Content-Type']);
	$invalid=PanelRenderer::pageActionResult($page,dp_panel_renderer_pages_request('POST',['__panel_action_submit'=>'1','value'=>''],[],[],'action'),'field_action',$manager);
	$t->same(422,$invalid->status());
	$t->contains('dp-panel-alert',$invalid->content());
	$t->same('page_action_lifecycle_result',PanelRenderer::pageActionResult($page,dp_panel_renderer_pages_request('POST',['__panel_action_submit'=>'1','value'=>'ok'],[],[],'action'),'after_validate',$manager)->data()['kind']);
	$confirmFields=PanelRenderer::pageActionResult($page,dp_panel_renderer_pages_request('POST',['__panel_action_submit'=>'1','value'=>'ok'],[],[],'action'),'field_action',$manager);
	$t->same(409,$confirmFields->status());
	$t->same(303,PanelRenderer::pageActionResult($page,dp_panel_renderer_pages_request('POST',['__panel_action_submit'=>'1','__panel_action_confirm'=>'1','value'=>'ok'],[],[],'action'),'field_action',$manager)->status());

	$t->same('page_action_confirmation',PanelRenderer::pageActionResult($page,$get,'confirm_only',$manager)->data()['kind']);
	$modalConfirm=PanelRenderer::pageActionResult($page,dp_panel_renderer_pages_request('GET',[],[],['x-requested-with'=>'DataphyrePanelModal'],'action'),'confirm_only',$manager);
	$t->same('text/html; charset=utf-8',$modalConfirm->headers()['Content-Type']);
	$t->same('page_action_lifecycle_result',PanelRenderer::pageActionResult($page,$get,'before_lifecycle',$manager)->data()['kind']);
	$t->same(200,PanelRenderer::pageActionResult($page,$get,'before_result',$manager)->status());
	$t->same(500,PanelRenderer::pageActionResult($page,$get,'throws',$manager)->status());
	$t->same('page_action_lifecycle_result',PanelRenderer::pageActionResult($page,$get,'after_lifecycle',$manager)->data()['kind']);
	$t->same(200,PanelRenderer::pageActionResult($page,$get,'get_success',$manager)->status());
	$t->same('/panel/coverage_page?meta=1',PanelRenderer::pageActionResult($page,$get,'meta_redirect',$manager)->headers()['Location']);
	$t->same(307,PanelRenderer::pageActionResult($page,$get,'result_redirect',$manager)->status());
	$t->same(303,PanelRenderer::pageActionResult($page,dp_panel_renderer_pages_request('POST',[],[],[],'action'),'get_success',$manager)->status());

	$action=$page->actionByName('get_success');
	$state=$action->state(null,$get,null,'page_action');
	$validRedirect=$t->nonPublic(PanelRenderer::class)->invoke('pageActionLifecycleResult',$page,$action,$get,PanelLifecycleResult::redirect('/panel/coverage_page','Go'),$state);
	$t->same('/panel/coverage_page',$validRedirect->headers()['Location']);
	$blocked=$t->nonPublic(PanelRenderer::class)->invoke('pageActionLifecycleResult',$page,$action,$get,PanelLifecycleResult::redirect('https://evil.example/phish','Blocked'),$state);
	$t->same(422,$blocked->status());
	$t->same(null,$blocked->data()['lifecycle']['redirect_to']);
	$quiet=$t->nonPublic(PanelRenderer::class)->invoke('pageActionLifecycleResult',$page,$action,$get,PanelLifecycleResult::notify(PanelNotification::warning('Notice'),false,''),$state,PanelFormState::make(['x'=>1]),['x'=>1],$manager);
	$t->same(200,$quiet->status());
})->tag('panel','renderer-pages','coverage')->group('framework-coverage');

test('panel renderer pages renders page action buttons groups scaffold forms and request preserving urls',static function(Context $t): void {
	$request=dp_panel_renderer_pages_request('GET',[],[
		'resource'=>'ignore','operation'=>'action','record'=>'9','relation'=>'items','action'=>'old','keep'=>'yes','page'=>3,
	]);
	$page=PanelPage::make('coverage_page');
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageActionsHtml',$page,$request));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageActionButton',$page,Action::make(''),$request));
	$disabled=Action::make('disabled')->label('Disabled')->disabled(true,'Reason')->handle(static fn(): bool=>true);
	$t->contains('<button',$t->nonPublic(PanelRenderer::class)->invoke('pageActionButton',$page,$disabled,$request));
	$modal=Action::make('modal')->label('Modal')->modalContent('<p>Details</p>');
	$t->contains('type="button"',$t->nonPublic(PanelRenderer::class)->invoke('pageActionButton',$page,$modal,$request));
	$link=Action::make('link')->label('Link');
	$t->contains('<a ',$t->nonPublic(PanelRenderer::class)->invoke('pageActionButton',$page,$link,$request));
	$field=Action::make('field')->requiresConfirmation()->confirmation('Sure?')->field(Field::make('value'))->handle(static fn(): bool=>true);
	$t->contains('data-confirm',$t->nonPublic(PanelRenderer::class)->invoke('pageActionButton',$page,$field,$request));
	$inline=Action::make('inline')->label('Inline')->tooltip('Run inline')->keyBinding('ctrl+i')->extraAttributes(['class'=>'extra'])->handle(static fn(): bool=>true);
	$t->contains('dp-panel-inline-action',$t->nonPublic(PanelRenderer::class)->invoke('pageActionButton',$page,$inline,$request));

	$emptyGroup=ActionGroup::make('empty')->section('Pending')->divider()->action(Action::make('hidden')->hidden());
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageActionGroupButton',$page,$emptyGroup,$request));
	$group=ActionGroup::make('tools')->label(' ')->icon('tools')->iconOnly()->tone('warning')->variant('outline')->large()
		->dropdownWidth('bad')->dropdownAlignment('center')
		->section('General','Useful actions')
		->action(Action::make('one')->handle(static fn(): bool=>true))
		->divider()
		->action(Action::make('two')->field(Field::make('value')))
		->action(Action::make('invisible')->hidden());
	$t->contains('dp-panel-action-group',$t->nonPublic(PanelRenderer::class)->invoke('pageActionGroupButton',$page,$group,$request));
	$menu=ActionGroup::make('menu')->menu([
		['type'=>'section','label'=>'Only pending'],['type'=>'divider'],['type'=>'action','name'=>'missing'],
	]);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageActionGroupButton',$page,$menu,$request));

	$toolbarPage=$page->actions([$group,Action::make('top_hidden')->hidden(),$disabled,$modal,$inline])->primaryForm($field,['show_action'=>false]);
	$t->contains('dp-panel-toolbar',$t->nonPublic(PanelRenderer::class)->invoke('pageActionsHtml',$toolbarPage,$request));
	$malformedGroup=ActionGroup::make('malformed');
	$t->nonPublic($malformedGroup)->writeProperty('items',[['type'=>'action','name'=>'missing']]);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageActionGroupButton',$page,$malformedGroup,$request));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageScaffoldFormsHtml',PanelPage::make('none'),$request));
	$t->isFalse($t->nonPublic(PanelRenderer::class)->invoke('pageHasPrimaryForm',PanelPage::make('none')));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('pageHasPrimaryForm',$toolbarPage));

	$formsPage=PanelPage::make('forms')
		->primaryForm(Action::make('primary')->label('Primary')->field(Field::make('title')->required()),[
			'title'=>'Primary title','description'=>'Primary description','include_cancel'=>true,'cancel_url'=>'/cancel','submit_label'=>'Submit primary','sort'=>2,
		])
		->embeddedForm(Action::make('plain')->label('Plain')->field(Field::make('note')->visibleUsing(static fn(): bool=>false)),[
			'title'=>'','description'=>'','sort'=>1,
		])
		->embeddedForm(Action::make('hidden_form')->hidden()->field(Field::make('x')),['title'=>'Hidden'])
		->embeddedForm(Action::make('denied_form')->authorize(static fn(): bool=>false)->field(Field::make('x')),['title'=>'Denied']);
	$forms=$t->nonPublic(PanelRenderer::class)->invoke('pageScaffoldFormsHtml',$formsPage,$request);
	$t->contains('Primary title',$forms);
	$t->contains('Submit primary',$forms);
	$t->isFalse(str_contains($forms,'Hidden'));

	$url=$t->nonPublic(PanelRenderer::class)->invoke('pageActionUrl',$page,'Run Now',$request);
	$t->contains('action=run_now',$url);
	$t->contains('keep=yes',$url);
	$t->isFalse(str_contains($url,'record=9'));
	$t->isFalse(str_contains($url,'relation=items'));
	$return=$t->nonPublic(PanelRenderer::class)->invoke('pageReturnUrl',$page,dp_panel_renderer_pages_request('GET',[],['return_to'=>'/panel/custom?ok=1']));
	$t->same('/panel/custom?ok=1',$return);
	$t->contains('keep=yes',$t->nonPublic(PanelRenderer::class)->invoke('pageReturnUrl',$page,$request));
})->tag('panel','renderer-pages','coverage')->group('framework-coverage');

test('panel renderer pages renders page tables grouping views filters search empty states and persistent queries',static function(Context $t): void {
	[$page,$table,$records]=dp_panel_renderer_pages_table_fixture();
	$prefix=$table->filterPrefix();
	$request=dp_panel_renderer_pages_request('GET',[],[
		'resource'=>'coverage_page','operation'=>'index','record'=>'9','relation'=>'items','action'=>'old',
		'page'=>3,'keep'=>'yes','q'=>'global',
		$prefix.'q'=>'a',$prefix.'view'=>'open',$prefix.'group'=>'status',
		$prefix.'status'=>'open',$prefix.'amount_from'=>'5',$prefix.'amount_to'=>'40',
	]);
	$tableRequest=$table->requestWithResolvedView($request);
	$tableData=[
		'table'=>$table,'request'=>$tableRequest,'records'=>$records,'meta'=>$table->toArray(),
		'summaries'=>[['name'=>'rows','label'=>'Rows','value'=>3,'tone'=>'neutral']],
	];
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageTablesHtml',$page,$request,[]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageTablesHtml',$page,$request,[['table'=>'invalid']]));
	$tablesHtml=$t->nonPublic(PanelRenderer::class)->invoke('pageTablesHtml',$page,$request,[$tableData]);
	$t->contains('dp-panel-page-table',$tablesHtml);
	$t->contains('data-dp-panel-group',$tablesHtml);
	$t->contains('Recent orders',$tablesHtml);

	$auto=PageTable::make('auto')->label('Auto');
	$autoRecords=[(object)['id'=>1,'title'=>'Object row'],['id'=>2,'title'=>'Array row'],5];
	$autoHtml=$t->nonPublic(PanelRenderer::class)->invoke('pageTablesHtml',$page,$request,[[
		'table'=>$auto,'records'=>$autoRecords,'meta'=>['label'=>'Auto','description'=>''],
	]]);
	$t->contains('Object row',$autoHtml);
	$emptyHtml=$t->nonPublic(PanelRenderer::class)->invoke('pageTablesHtml',$page,$request,[[
		'table'=>PageTable::make('empty')->columns([Column::make('name')]),'records'=>[],'meta'=>['empty_message'=>'Nothing custom'],
	]]);
	$t->contains('Nothing custom',$emptyHtml);

	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('pageTableGroupedBody',$table,$request->withQueryValue($prefix.'group','none'),$records,$table->columnsList(),3,));
	$invalidGroup=PageTable::make('invalid_group');
	$t->nonPublic($invalidGroup)->writeProperty('groups',['broken'=>'not-a-group']);
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('pageTableGroupedBody',$invalidGroup,$request->withQueryValue($invalidGroup->filterPrefix().'group','broken'),$records,[],1,));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageTableGroupedBody',$table,$request->withQueryValue($prefix.'group','status'),[],$table->columnsList(),3,));
	$grouped=$t->nonPublic(PanelRenderer::class)->invoke('pageTableGroupedBody',$table,$request->withQueryValue($prefix.'group','status'),$records,$table->columnsList(),3,);
	$t->contains('Group open',$grouped);
	$t->contains('Open group',$grouped);
	$t->contains('hidden',$grouped);
	$plainGroupTable=PageTable::make('plain_group')->columns([Column::make('name')])->group(
		TableGroup::make('status')->default()->collapsible(false)
	);
	$plainGrouped=$t->nonPublic(PanelRenderer::class)->invoke('pageTableGroupedBody',$plainGroupTable,dp_panel_renderer_pages_request(),$records,$plainGroupTable->columnsList(),1,);
	$t->contains('<div class="dp-panel-table-group-heading">',$plainGrouped);

	$t->contains('Custom message',$t->nonPublic(PanelRenderer::class)->invoke('pageTableEmptyStateHtml',$table,$request,['empty_message'=>'Custom message'],));
	$t->contains('dp-panel-empty-state',$t->nonPublic(PanelRenderer::class)->invoke('pageTableEmptyStateHtml',$table,$request->withQueryValue($prefix.'q','needle'),[],));
	$t->contains('dp-panel-empty-state',$t->nonPublic(PanelRenderer::class)->invoke('pageTableEmptyStateHtml',$table,$request->withQueryValue($prefix.'q',null)->withQueryValue($prefix.'view','open'),[],));
	$filtered=$request->withQueryValue($prefix.'q',null)->withQueryValue($prefix.'view','all')->withQueryValue($prefix.'status','open');
	$t->contains('dp-panel-empty-state',$t->nonPublic(PanelRenderer::class)->invoke('pageTableEmptyStateHtml',$table,$filtered,[]));
	$ready=PageTable::make('ready')->columns([Column::make('name')]);
	$t->contains('dp-panel-empty-state',$t->nonPublic(PanelRenderer::class)->invoke('pageTableEmptyStateHtml',$ready,dp_panel_renderer_pages_request(),[]));

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageTableViewsHtml',$page,PageTable::make('no_views'),$request));
	$t->contains('dp-panel-table-views',$t->nonPublic(PanelRenderer::class)->invoke('pageTableViewsHtml',$page,$table,$request));
	$malformedViews=PageTable::make('malformed_views');
	$t->nonPublic($malformedViews)->writeProperty('views',[0=>'invalid','valid'=>TableView::make('valid')]);
	$t->throws(static fn()=>$t->nonPublic(PanelRenderer::class)->invoke('pageTableViewsHtml',$page,$malformedViews,$request->withQueryValue($malformedViews->filterPrefix().'view','all'),),TypeError::class);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageTableGroupsHtml',$page,PageTable::make('no_groups'),$request));
	$t->contains('dp-panel-table-groups',$t->nonPublic(PanelRenderer::class)->invoke('pageTableGroupsHtml',$page,$table,$request));
	$malformedGroups=PageTable::make('malformed_groups');
	$t->nonPublic($malformedGroups)->writeProperty('groups',[0=>'invalid','valid'=>TableGroup::make('valid')]);
	$t->throws(static fn()=>$t->nonPublic(PanelRenderer::class)->invoke('pageTableGroupsHtml',$page,$malformedGroups,$request->withQueryValue($malformedGroups->filterPrefix().'group','none'),),TypeError::class);

	$t->contains('<small>5</small>',$t->nonPublic(PanelRenderer::class)->invoke('pageTableViewLink',$page,$table,$request,'open','Open','success',true,5));
	$t->isFalse(str_contains($t->nonPublic(PanelRenderer::class)->invoke('pageTableViewLink',$page,$table,$request,'all','All','bad',false,null),'<small>'));
	$viewQuery=$t->nonPublic(PanelRenderer::class)->invoke('pageTableViewQuery',$table,$request);
	$t->same('yes',$viewQuery['keep']);
	$t->isFalse(array_key_exists($prefix.'status',$viewQuery));
	$t->isFalse(array_key_exists($prefix.'q',$viewQuery));

	$t->contains('type="search"',$t->nonPublic(PanelRenderer::class)->invoke('pageTableSearchHtml',$page,$table,$request));
	$t->isFalse(str_contains($t->nonPublic(PanelRenderer::class)->invoke('pageTableSearchHtml',$page,$table,$request->withQueryValue($prefix.'q',null)),'common.clear'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageTableFiltersHtml',$page,PageTable::make('no_filters'),$request));
	$hiddenFilters=PageTable::make('hidden_filters')->filter(TableFilter::make('hidden')->hidden());
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageTableFiltersHtml',$page,$hiddenFilters,$request));
	$t->contains('dp-panel-page-table-filters',$t->nonPublic(PanelRenderer::class)->invoke('pageTableFiltersHtml',$page,$table,$request));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageTableFilterChipsHtml',$page,$table,$table->filterRequest(dp_panel_renderer_pages_request()),dp_panel_renderer_pages_request()));
	$t->contains('dp-panel-filter-chip',$t->nonPublic(PanelRenderer::class)->invoke('pageTableFilterChipsHtml',$page,$table,$table->filterRequest($request),$request));
	$malformedFilters=PageTable::make('malformed_filters');
	$t->nonPublic($malformedFilters)->writeProperty('filters',['invalid']);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('pageTableFilterChipsHtml',$page,$malformedFilters,dp_panel_renderer_pages_request(),dp_panel_renderer_pages_request()));

	$hidden=$t->nonPublic(PanelRenderer::class)->invoke('pageTableHiddenInputs',$table,$request,[$prefix.'q']);
	$t->contains('name="keep"',$hidden);
	$t->isFalse(str_contains($hidden,$prefix.'q'));
	$arrayQuery=$request->withQueryValue('array_value',['x']);
	$t->isFalse(str_contains($t->nonPublic(PanelRenderer::class)->invoke('pageTableHiddenInputs',$table,$arrayQuery),'array_value'));
	$t->contains('coverage_page',$t->nonPublic(PanelRenderer::class)->invoke('pageTableFilterClearUrl',$page,$table,$request,'status'));
	$t->contains('coverage_page',$t->nonPublic(PanelRenderer::class)->invoke('pageTableFilterResetUrl',$page,$table,$request));
	$allCleared=$t->nonPublic(PanelRenderer::class)->invoke('pageTablePersistentQuery',$table,$request,[]);
	$t->isFalse(array_key_exists($prefix.'status',$allCleared));
	$selective=$t->nonPublic(PanelRenderer::class)->invoke('pageTablePersistentQuery',$table,$request,['status'],true);
	$t->isFalse(array_key_exists($prefix.'q',$selective));
	$t->isFalse(array_key_exists($prefix.'status',$selective));
	$t->isTrue(array_key_exists($prefix.'amount_from',$selective));
})->tag('panel','renderer-pages','coverage')->group('framework-coverage');

test('panel renderer pages covers resource form show command bars and pulse helper boundaries',static function(Context $t): void {
	$request=dp_panel_renderer_pages_request('GET',[],[],[],'create');
	$record=['id'=>'9','name'=>'Original','status'=>'draft'];
	$denied=Resource::make('denied')->authorize(static fn(): bool=>false);
	$t->same(403,PanelRenderer::form($denied,$request,null,'create')->status());
	$t->same(403,PanelRenderer::form($denied,dp_panel_renderer_pages_request('GET',[],[],[],'edit'),$record,'edit')->status());
	$t->same(403,PanelRenderer::show($denied,dp_panel_renderer_pages_request('GET',[],[],[],'show'),$record)->status());

	$resource=Resource::make('form_records')->label('Form records')
		->fields([
			Field::make('name')->required()->section('Identity'),
			Field::make('conditional')->visibleWhen('name','show'),
			Field::make('dynamic')->optionsUsing(static fn(): array=>['one'=>'One']),
			Field::make('computed')->default('old')->stateUsing(static fn(): string=>'resolved'),
			Field::make('secret')->default('hidden')->hidden()->visibleWhen('name','show'),
			Field::make('invisible')->visibleUsing(static fn(): bool=>false),
		])
		->mutateFillDataUsing(static fn(array $data): array=>array_replace($data,['name'=>'Mutated']))
		->afterFillUsing(static fn(PanelFormState $state): PanelFormState=>$state)
		->saveUsing(static fn(array $data): array=>$data);
	$created=PanelRenderer::form($resource,$request,null,'create');
	$t->same('create',$created->data()['kind']);
	$t->same('Mutated',$created->data()['form_state']['values']['name']);
	$t->contains('type="hidden"',$created->content());
	$invalidState=PanelFormState::make(['name'=>''],[
		'name'=>['Name is required',' ','Name is required'],'conditional'=>['Conditional error'],
	]);
	$invalid=PanelRenderer::form($resource,$request,null,'create',$invalidState,422);
	$t->same(422,$invalid->status());
	$t->contains('Name is required',$invalid->content());

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('formPulseHtml',$resource,'create',[],$invalidState));
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('formPulseRecommendation','create',[],$invalidState)));
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('formPulseRecommendation','create',['conditional_fields'=>1],PanelFormState::make([]))));
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('formPulseRecommendation','create',['dynamic_fields'=>1],PanelFormState::make([]))));
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('formPulseRecommendation','edit',[],PanelFormState::make([]))));
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('formPulseRecommendation','create',[],PanelFormState::make([]))));

	$actionResource=Resource::make('commands')->label('Commands')->pluralLabel('Commands')->action(
		Action::make('run')->label('Run')->handle(static fn(): bool=>true)
	);
	$emptyCommandResource=Resource::make('empty')->authorize(static fn(): bool=>false);
	PanelContext::run(['table_header_controls'=>'compact','resource_imports'=>false,'resource_exports'=>false],static function()use($t,$emptyCommandResource,$request): void {
		$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('resourceCommandBarHtml',$emptyCommandResource,$request,[]));
	});
	PanelContext::run(['table_header_controls'=>'compact'],static function()use($t,$actionResource,$request): void {
		$t->contains('dp-panel-commandbar-secondary',$t->nonPublic(PanelRenderer::class)->invoke('resourceCommandBarHtml',$actionResource,$request,[]));
	});
	$t->contains('dp-panel-commandbar',$t->nonPublic(PanelRenderer::class)->invoke('resourceCommandBarHtml',$actionResource,$request,[]));
	PanelContext::run(['table_header_controls'=>'invalid','table_pagination_visibility'=>'hidden','table_density_controls'=>false,'table_spacing_selector'=>false,'resource_imports'=>false,'resource_exports'=>false],static function()use($t,$actionResource,$request): void {
		$t->contains('dp-panel-commandbar',$t->nonPublic(PanelRenderer::class)->invoke('resourceCommandBarHtml',$actionResource,$request,[]));
		$t->contains('dp-panel-table-meta-controls',$t->nonPublic(PanelRenderer::class)->invoke('resourceCommandBarBottomHtml',Resource::make('empty'),$request,[],'dp-panel-table-meta-controls'));
	});
	$t->contains('dp-panel-commandbar-bottom',$t->nonPublic(PanelRenderer::class)->invoke('resourceCommandBarBottomHtml',$actionResource,$request,$actionResource->resourceTable()->columnsList(),'dp-panel-commandbar-bottom'));
	$denyCreate=Resource::make('no_create')->authorize(static fn(): bool=>false);
	$t->contains('dp-panel-table-header-controls',$t->nonPublic(PanelRenderer::class)->invoke('resourceTableHeaderControlsHtml',$denyCreate,$request));
	$t->contains('dp-panel-table-header-controls',$t->nonPublic(PanelRenderer::class)->invoke('resourceTableHeaderControlsHtml',$actionResource,$request));

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('recordPulseHtml',$resource,$request,$record,[]));
	foreach([
		['alerts'=>true],['approvals'=>true],['tasks'=>true],['messages'=>true],['activity'=>true],['changes'=>true],['relations'=>true],[],
	] as $signals){
		$next=$t->nonPublic(PanelRenderer::class)->invoke('recordPulseNextStep',$signals);
		$t->isTrue(isset($next['title'],$next['body']));
	}
	$t->same('draft',$t->nonPublic(PanelRenderer::class)->invoke('firstRecordValue',$record,['missing','status']));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('firstRecordValue',['status'=>[]],['status','missing']));
	$t->same('Draft State',$t->nonPublic(PanelRenderer::class)->invoke('humanRecordPulseValue','Draft State'));
	$t->same('None',$t->nonPublic(PanelRenderer::class)->invoke('humanRecordPulseValue',' '));
})->tag('panel','renderer-pages','coverage')->group('framework-coverage');
