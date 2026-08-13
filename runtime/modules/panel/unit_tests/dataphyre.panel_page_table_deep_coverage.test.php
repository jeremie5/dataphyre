<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Column;
use Dataphyre\Panel\PageTable;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Panel\TableGroup;
use Dataphyre\Panel\TableSummary;
use Dataphyre\Panel\TableView;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel page table imports comprehensive manifests and mixed definitions',static function(Context $t): void {
	$table=PageTable::fromArray([
		'name'=>'recent-orders','label'=>'Recent orders','description'=>'Latest activity','empty_message'=>'No orders',
		'columns'=>[
			['name'=>'id','type'=>'number','sortable'=>true],Column::make('status')->searchable(),'customer',
		],
		'filters'=>[
			['name'=>'status','type'=>'select','options'=>['open'=>'Open']],TableFilter::make('search'),'enabled',
		],
		'views'=>[
			['name'=>'open','default'=>true,'filters'=>['status'=>'open']],TableView::make('all'),'custom',
		],
		'summaries'=>[
			['name'=>'total','type'=>'sum','column'=>'amount'],TableSummary::make('rows'),'average',
		],
		'groups'=>[
			['name'=>'status','default'=>true],TableGroup::make('customer'),'region',
		],
		'records'=>[['id'=>1]],'default_sort'=>'id','default_sort_direction'=>'desc','limit'=>10,'sort'=>25,
		'meta'=>['source'=>'manifest'],
	]);
	$manifest=$table->toArray();
	$t->same('recent-orders',$table->name());
	$t->same('Recent orders',$manifest['label']);
	$t->same('Latest activity',$manifest['description']);
	$t->same('No orders',$manifest['empty_message']);
	$t->same(3,count($table->columnsList()));
	$t->same(3,count($table->filtersList()));
	$t->same(3,count($table->viewsList()));
	$t->same(3,count($table->summariesList()));
	$t->same(3,count($table->groupsList()));
	$t->same('recent-orders_',$table->filterPrefix());
	$t->same('desc',$manifest['default_sort_direction']);
	$t->same(10,$manifest['limit']);
	$t->same(25,$manifest['sort']);

	$aliases=PageTable::make('aliases')
		->label(' Alias ')->description(' ')->emptyMessage(' ')
		->column('',null)->filter('',null)->view('')->summary('', 'count')->group('')
		->columns([Column::make('one')])->filters([TableFilter::make('one')])
		->views([TableView::make('one')])->summaries([TableSummary::make('one')])->groups([TableGroup::make('one')])
		->recordsUsing(static fn(): array=>[['id'=>1]])->queryUsing(static fn(): array=>[['id'=>2]])
		->defaultSort('','invalid')->limit(0)->limit(null)->sort(-5)->meta(['one'=>1])->meta(['two'=>2]);
	$t->isTrue($aliases->toArray()['lazy']);
	$t->same(null,$aliases->toArray()['limit']);
	$t->same('asc',$aliases->toArray()['default_sort_direction']);
})->tag('panel','page-table','coverage')->group('framework-coverage');

test('panel page table resolves active views groups and prefixed filters',static function(Context $t): void {
	$empty=PanelRequest::fromArray(['query'=>[]]);
	$t->same('',PageTable::make('empty')->activeViewName($empty));
	$t->same('',PageTable::make('empty')->activeGroupName($empty));
	$t->same($empty,PageTable::make('empty')->requestWithResolvedView($empty));
	$t->same($empty->query(),PageTable::make('empty')->filterRequest($empty)->query());

	$views=PageTable::make('orders')->views([
		TableView::make('open')->default()->query([
			'status'=>'open','orders_q'=>'default search','empty_key'=>'',''=>'ignored',
		]),
		TableView::make('closed')->filterValue('status','closed'),
	]);
	$t->same('open',$views->activeViewName($empty));
	$t->same('', $views->activeViewName(PanelRequest::fromArray(['query'=>['orders_view'=>'all']])));
	$t->same('closed',$views->activeViewName(PanelRequest::fromArray(['query'=>['orders_view'=>'closed']])));
	$t->same('open',$views->activeViewName(PanelRequest::fromArray(['query'=>['orders_view'=>'missing']])));
	$allRequest=$views->requestWithResolvedView(PanelRequest::fromArray(['query'=>['orders_view'=>'all']]));
	$t->same('all',$allRequest->query('orders_view'));
	$closed=$views->requestWithResolvedView(PanelRequest::fromArray(['query'=>['orders_view'=>'closed']]));
	$t->same('closed',$closed->query('orders_status'));
	$default=$views->requestWithResolvedView(PanelRequest::fromArray(['query'=>[
		'orders_status'=>'existing','orders_q'=>['existing'],
	]]));
	$t->same('existing',$default->query('orders_status'));
	$t->same(['existing'],$default->query('orders_q'));
	$t->same('',$views->views([TableView::make('plain')])->activeViewName($empty));

	$groups=PageTable::make('orders')->groups([
		TableGroup::make('status')->default(),TableGroup::make('region'),
	]);
	$t->same('status',$groups->activeGroupName($empty));
	$t->same('region',$groups->activeGroupName(PanelRequest::fromArray(['query'=>['orders_group'=>'region']])));
	$t->same('',$groups->activeGroupName(PanelRequest::fromArray(['query'=>['orders_group'=>'none']])));
	$t->same('status',$groups->activeGroupName(PanelRequest::fromArray(['query'=>['orders_group'=>'missing']])));
	$t->same('',$groups->groups([TableGroup::make('plain')])->activeGroupName($empty));

	$table=PageTable::make('orders')->filters([
		TableFilter::make('status','select')->options(['open'=>'Open','closed'=>'Closed']),
		TableFilter::make('amount')->numberRange(),
	]);
	$request=PanelRequest::fromArray(['query'=>[
		'q'=>'global','status'=>'global-status','amount_from'=>'global-from',
		'orders_q'=>'Alice','orders_status'=>'open','orders_amount_from'=>'10','orders_amount_to'=>'20',
	]]);
	$mapped=$table->filterRequest($request);
	$t->same('Alice',$mapped->query('q'));
	$t->same('open',$mapped->query('status'));
	$t->same('10',$mapped->query('amount_from'));
	$t->same('20',$mapped->query('amount_to'));
	$t->isTrue($table->hasActiveFilters($request));
	$t->isFalse($table->hasActiveFilters($empty));
})->tag('panel','page-table','coverage')->group('framework-coverage');

test('panel page table resolves filters search sorting limits and lazy sources',static function(Context $t): void {
	$records=[
		['id'=>3,'name'=>'Charlie','status'=>'closed','amount'=>30],
		['id'=>1,'name'=>'Alice','status'=>'open','amount'=>10],
		['id'=>2,'name'=>'Bob','status'=>'open','amount'=>20],
	];
	$request=PanelRequest::fromArray(['query'=>[
		'orders_status'=>'open','orders_q'=>'bo','orders_view'=>'open',
	]]);
	$table=PageTable::make('orders')
		->columns([Column::make('id')->sortable(),Column::make('name')->searchable(),Column::make('status')])
		->filter(TableFilter::make('status','select')->options(['open'=>'Open','closed'=>'Closed']))
		->view(TableView::make('open')->default()->where(static fn(array $record): bool=>$record['status']==='open'))
		->records($records)->defaultSort('id','desc')->limit(1);
	$resolved=$table->resolvedRecords($request,PanelPage::make('dashboard'));
	$t->same(1,count($resolved));
	$t->same(2,$resolved[0]['id']);

	$columnSort=PageTable::make('sort')->column(Column::make('id')->sortable())->records($records)->defaultSort('id','asc');
	$t->same(1,$columnSort->resolvedRecords()[0]['id']);
	$rawSort=PageTable::make('sort')->records($records)->defaultSort('id','desc');
	$t->same(3,$rawSort->resolvedRecords()[0]['id']);

	$contextResolver=PageTable::make('resolver')->recordsUsing(
		static fn(?PanelRequest $request,PageTable $table,?PanelPage $page): array=>[
			['context'=>($request?->operation() ?? '').'-'.$table->name().'-'.($page?->name() ?? '')],
		]
	);
	$t->same('index-resolver-dashboard',$contextResolver->resolvedRecords($request,PanelPage::make('dashboard'))[0]['context']);
	$t->same([['id'=>1]],PageTable::make('resolver')->recordsUsing(static fn(): object=>new class {
		public function getRecords(): array { return [['id'=>1]]; }
	})->resolvedRecords());
	$t->same([['id'=>2]],PageTable::make('resolver')->recordsUsing(static fn(): object=>new class {
		public function get(): array { return [['id'=>2]]; }
	})->resolvedRecords());
	$t->same([['id'=>3]],PageTable::make('resolver')->recordsUsing(static fn(): object=>new class {
		public function items(): array { return [['id'=>3]]; }
	})->resolvedRecords());
	$t->same([],PageTable::make('resolver')->recordsUsing(static fn(): int=>1)->resolvedRecords());
	$t->same([],PageTable::make('resolver')->recordsUsing(static function(): never { throw new RuntimeException('failed'); })->resolvedRecords());

	$noColumns=PageTable::make('search')->records([['name'=>'Alice'],['name'=>'Bob'],new stdClass()]);
	$t->same(1,count($noColumns->resolvedRecords(PanelRequest::fromArray(['query'=>['search_q'=>'alice']]))));
	$t->same(3,count($noColumns->resolvedRecords(PanelRequest::fromArray(['query'=>[]]))));
	$allColumns=PageTable::make('search')->column(Column::make('name'))->records([['name'=>'Alice'],['name'=>'Bob']]);
	$t->same(1,count($allColumns->resolvedRecords(PanelRequest::fromArray(['query'=>['search_q'=>'bob']]))));
	$hiddenFilter=PageTable::make('hidden')->filter(TableFilter::make('status')->hidden())->records($records);
	$t->same(3,count($hiddenFilter->resolvedRecords(PanelRequest::fromArray(['query'=>['hidden_status'=>'open']]))));
})->tag('panel','page-table','coverage')->group('framework-coverage');

test('panel page table private comparators record access and manifests cover fallbacks',static function(Context $t): void {
	$object=new class {
		public string $public='property';
		public function getDisplayName(): string { return 'getter'; }
	};
	$t->same('array',$t->nonPublic(PageTable::class)->invoke('recordValue',['value'=>'array'],'value','default'));
	$t->same('property',$t->nonPublic(PageTable::class)->invoke('recordValue',$object,'public','default'));
	$t->same('getter',$t->nonPublic(PageTable::class)->invoke('recordValue',$object,'display_name','default'));
	$t->same('default',$t->nonPublic(PageTable::class)->invoke('recordValue',$object,'missing','default'));
	$t->same('default',$t->nonPublic(PageTable::class)->invoke('recordValue',null,'missing','default'));
	$t->isTrue($t->nonPublic(PageTable::class)->invoke('compareValues',new DateTimeImmutable('2026-01-01'),new DateTimeImmutable('2026-01-02'))<0);
	$t->isTrue($t->nonPublic(PageTable::class)->invoke('compareValues',1,'2')<0);
	$t->isTrue($t->nonPublic(PageTable::class)->invoke('compareValues','Alpha','beta')<0);

	$view=TableView::make('defaults')->query([
		''=>'skip','status'=>'open','orders_search'=>'preset','amount'=>['from'=>10],
	]);
	$table=PageTable::make('orders');
	$request=PanelRequest::fromArray(['query'=>[
		'orders_status'=>'existing','orders_amount'=>[],
	]]);
	$resolved=$t->nonPublic($table)->invoke('requestWithViewDefaults',$request,$view);
	$t->same('existing',$resolved->query('orders_status'));
	$t->same('preset',$resolved->query('orders_search'));
	$t->same(['from'=>10],$resolved->query('orders_amount'));

	$page=PanelPage::make('dashboard');
	$manifest=PageTable::make('orders')->column('id')->records([['id'=>1]])->meta(['source'=>'test'])->manifest(
		PanelRequest::fromArray(['query'=>[]]),$page,['extra'=>true]
	);
	$t->same('table_manifest',$manifest['type']);
	$t->same('orders',$manifest['name']);
	$t->same('',PageTable::make('')->toArray()['label']);
})->tag('panel','page-table','coverage')->group('framework-coverage');
