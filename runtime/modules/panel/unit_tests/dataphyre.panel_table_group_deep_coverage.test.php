<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PageTable;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TableGroup;
use Dataphyre\Panel\TableSummary;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel table group imports manifests summaries actions and fluent state',static function(Context $t): void {
	$group=TableGroup::fromArray([
		'name'=>'order-status','label'=>'Order status','direction'=>'desc','default'=>true,
		'collapsible'=>true,'collapsed'=>true,
		'summaries'=>[['name'=>'rows','type'=>'count'],TableSummary::make('total')->sum('amount'),'average'],
		'actions'=>[['label'=>'Open','url'=>'/orders','tone'=>'primary','icon'=>'eye'],'Export'],
		'meta'=>['source'=>'manifest'],
	]);
	$manifest=$group->toArray();
	$t->same('order-status',$group->name());
	$t->same('Order status',$manifest['label']);
	$t->same('desc',$manifest['direction']);
	$t->isTrue($manifest['default']);
	$t->isTrue($manifest['collapsible']);
	$t->isTrue($manifest['collapsed']);
	$t->same(3,count($manifest['summaries']));
	$t->same(2,count($manifest['actions']));
	$t->same(['source'=>'manifest'],$manifest['meta']);

	$fluent=TableGroup::make('workflow')->label(' Workflow ')->description(' Static description ')
		->direction('invalid')->default(false)->collapsed(true)->collapsible(false)
		->summaries([TableSummary::make('one')])->summary('',null)->summary('two','sum')
		->actions([])->action('Open','/open','success','eye')->action(['label'=>new stdClass()])
		->meta(['one'=>1])->meta(['two'=>2]);
	$state=$fluent->toArray();
	$t->same('asc',$state['direction']);
	$t->isFalse($state['default']);
	$t->isFalse($state['collapsible']);
	$t->isFalse($state['collapsed']);
	$t->same(2,count($state['summaries']));
	$t->same(1,count($state['actions']));
	$t->same('Static description',$state['meta']['description']);
	$t->same('',TableGroup::make('')->toArray()['label']);
})->tag('panel','table-group','coverage')->group('framework-coverage');

test('panel table group resolves keys labels and descriptions across contexts',static function(Context $t): void {
	$request=PanelRequest::fromArray(['operation'=>'index']);
	$resource=Resource::make('orders');
	$table=PageTable::make('orders');
	$group=TableGroup::make('status');
	$t->same('open',$group->resolveKey(['status'=>'Open'],$resource,$request,$table));
	$t->same('yes',$group->resolveKey(['status'=>true]));
	$t->same('no',$group->resolveKey(['status'=>false]));
	$t->same('__blank',$group->resolveKey(['status'=>null]));
	$t->same('__blank',$group->resolveKey(['status'=>'']));
	$t->same('__blank',$group->resolveKey(['status'=>'   ']));
	$t->same('__blank',$group->resolveKey(new stdClass()));

	$dynamic=TableGroup::make('dynamic')->stateUsing(
		static fn(array $record,Resource $resource,PanelRequest $request,TableGroup $group,PageTable $table): string=>
			$record['state'].'-'.$resource->name().'-'.$request->operation().'-'.$group->name().'-'.$table->name()
	);
	$t->same('open-orders-index-dynamic-orders',$dynamic->resolveKey(['state'=>'open'],$resource,$request,$table));

	$t->same('Not set',$group->resolveLabel('__blank'));
	$t->same('In Progress',$group->resolveLabel('in_progress'));
	$labelled=$group->labelUsing(
		static fn(string $key,array $records,Resource $resource,PanelRequest $request,TableGroup $group,PageTable $table): string=>
			strtoupper($key).'-'.count($records).'-'.$resource->name().'-'.$request->operation().'-'.$group->name().'-'.$table->name()
	);
	$t->same('OPEN-1-orders-index-status-orders',$labelled->resolveLabel('open',[1],$resource,$request,$table));
	$t->same('Open',$group->labelUsing(static fn(): array=>[])->resolveLabel('open'));

	$described=$group->description('Static')->descriptionUsing(static fn(string $key): string=>$key==='open' ? 'Dynamic' : '');
	$t->same('Dynamic',$described->resolveDescription('open'));
	$t->same('Static',$described->resolveDescription('closed'));
	$t->same('',TableGroup::make('plain')->descriptionUsing(static fn(): array=>[])->resolveDescription('open'));
})->tag('panel','table-group','coverage')->group('framework-coverage');

test('panel table group resolves summaries and dynamic action payloads',static function(Context $t): void {
	$request=PanelRequest::fromArray(['operation'=>'index']);
	$resource=Resource::make('orders');
	$table=PageTable::make('orders');
	$records=[['amount'=>10],['amount'=>20]];
	$t->same([],TableGroup::make('status')->resolveSummaries('open',$records));
	$group=TableGroup::make('status')->summaries([
		TableSummary::make('rows')->count(),TableSummary::make('total')->sum('amount'),
	]);
	$resolved=$group->resolveSummaries('open',$records,$resource,$request,$table);
	$t->same(2,count($resolved));
	$t->same('status',$resolved[0]['group']);
	$t->same('open',$resolved[0]['group_key']);
	$t->same(2,count($group->resolveSummaries('open',$records)));

	$t->same([],TableGroup::make('status')->resolveActions('open',$records));
	$actions=TableGroup::make('status')->actions([
		['label'=>'Static','url'=>'/static','tone'=>'success','icon'=>'eye','target'=>'_blank'],
		['label'=>static fn(string $key): string=>'Open '.ucfirst($key),'url'=>static fn(string $key,array $records): string=>'/'.$key.'?count='.count($records),'tone'=>'invalid'],
		['label'=>'Hidden','url'=>'/hidden','visible'=>false],
		['label'=>'Dynamic hidden','url'=>'/hidden','visible'=>static fn(): bool=>false],
		['label'=>static fn(): array=>[],'url'=>'/invalid'],
		['label'=>'No URL','url'=>static fn(): array=>[]],
	]);
	$resolvedActions=$actions->resolveActions('open',$records,$resource,$request,$table);
	$t->same(2,count($resolvedActions));
	$t->same('success',$resolvedActions[0]['tone']);
	$t->same('_blank',$resolvedActions[0]['target']);
	$t->same('Open Open',$resolvedActions[1]['label']);
	$t->same('/open?count=2',$resolvedActions[1]['url']);
	$t->same('neutral',$resolvedActions[1]['tone']);
	$manifest=$actions->toArray();
	$t->isTrue($manifest['actions'][1]['computed_label']);
	$t->isTrue($manifest['actions'][1]['computed_url']);
})->tag('panel','table-group','coverage')->group('framework-coverage');

test('panel table group private value and record helpers cover fallbacks',static function(Context $t): void {
	$object=new class {
		public string $public='property';
		public function getDisplayName(): string { return 'getter'; }
	};
	$t->same('array',$t->nonPublic(TableGroup::class)->invoke('recordValue',['value'=>'array'],'value','default'));
	$t->same('property',$t->nonPublic(TableGroup::class)->invoke('recordValue',$object,'public','default'));
	$t->same('getter',$t->nonPublic(TableGroup::class)->invoke('recordValue',$object,'display_name','default'));
	$t->same('default',$t->nonPublic(TableGroup::class)->invoke('recordValue',$object,'missing','default'));
	$t->same('default',$t->nonPublic(TableGroup::class)->invoke('recordValue',null,'missing','default'));
	$group=TableGroup::make('status');
	$t->same('literal',$t->nonPublic($group)->invoke('resolveActionValue','literal','open',[],null,null,null));
	$t->same('open',$t->nonPublic($group)->invoke(
		'resolveActionValue',static fn(string $key): string=>$key,'open',[],null,null,null
	));
})->tag('panel','table-group','coverage')->group('framework-coverage');
