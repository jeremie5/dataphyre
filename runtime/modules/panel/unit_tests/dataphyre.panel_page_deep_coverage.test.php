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
use Dataphyre\Panel\NavigationItem;
use Dataphyre\Panel\PageTable;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\TableSummary;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','permission']);

test('panel page imports a comprehensive manifest definition',static function(Context $t): void {
	$page=PanelPage::fromArray([
		'name'=>'operations-center','label'=>'Operations center','url'=>'/ops/','group'=>'Workspace','icon'=>'activity',
		'navigation_parent'=>'admin','folder'=>'workspace','navigation_description'=>'Operational tools',
		'navigation_badge'=>5,'navigation_badge_tone'=>'danger','sort'=>20,'hidden_from_navigation'=>true,
		'content'=>'Static content',
		'actions'=>[
			['name'=>'refresh','label'=>'Refresh'],
			['name'=>'workflow','type'=>'group','actions'=>[['name'=>'approve']]],
		],
		'widgets'=>[['name'=>'orders','type'=>'stat','value'=>12],Widget::make('users'),'activity'],
		'tables'=>[['name'=>'recent','records'=>[['id'=>1]]],PageTable::make('archived'),'audit'],
		'forms'=>[
			['action'=>['name'=>'create'],'title'=>'Create','show_action'=>true],
			'edit'=>['title'=>'Edit','placement'=>'page'],
		],
		'meta'=>['source'=>'manifest'],
	]);
	$manifest=$page->toArray();
	$t->same('operations-center',$page->name());
	$t->same('Operations center',$page->label());
	$t->same('/ops',$manifest['url']);
	$t->same('Workspace',$manifest['group']);
	$t->same('activity',$manifest['icon']);
	$t->same(20,$manifest['sort']);
	$t->isTrue($page->isHiddenFromNavigation());
	$t->same(4,count($page->actionsList()));
	$t->same(3,count($page->widgetsList()));
	$t->same(3,count($page->tablesList()));
	$t->same(2,count($page->formsList()));
	$t->same(['source'=>'manifest'],$manifest['meta']);
})->tag('panel','page','coverage')->group('framework-coverage');

test('panel page fluent navigation badges content actions and forms normalize state',static function(Context $t): void {
	$parent=NavigationItem::make('parent');
	$page=PanelPage::make('dashboard')->label(' Dashboard ')->url('dashboard/')
		->group(' ')->navigationParent($parent)->folder(' section ')->folder(null)
		->icon(' ')->sort(-5)->hideFromNavigation(false)->navigationDescription(' ')
		->navigationBadge(4)->navigationBadgeTone('invalid')->content(['html'=>'content'])
		->actions([Action::make('open'),['name'=>'approve'],['name'=>'workflow','actions'=>[['name'=>'reject']]],'export'])
		->action('')->actionGroup('bulk',[Action::make('archive')])
		->actionGroup(['name'=>'array-group','actions'=>[['name'=>'array-action']]])
		->meta(['one'=>1])->meta(['two'=>2]);
	$t->same('Dashboard',$page->label());
	$t->same(4,$page->navigationEntry()['badge']);
	$t->same('neutral',$page->navigationEntry()['badge_tone']);
	$t->same('file',$page->navigationEntry()['icon']);
	$t->same(null,$page->navigationEntry()['parent']);
	$t->instanceOf(Action::class,$page->actionByName('approve'));
	$t->same('reject',$page->actionByName('reject')?->name());
	$t->same(null,$page->actionByName('missing'));

	$page=$page->form('open','Open form')
		->embeddedForm(['name'=>'embedded'],['placement'=>'invalid','width'=>'invalid','style'=>'invalid','show_action'=>false])
		->formPage('approve','Approve form')
		->primaryForm(Action::make('primary'),['width'=>'xl','style'=>'plain','show_action'=>true,'sort'=>5]);
	$t->isFalse($page->shouldShowActionButton('open'));
	$t->isFalse($page->shouldShowActionButton('embedded'));
	$t->isTrue($page->shouldShowActionButton('primary'));
	$t->isTrue($page->shouldShowActionButton('missing'));
	$t->same('embedded',$page->formsList()['embedded']['placement']);
	$t->same('full',$page->formsList()['embedded']['width']);
	$t->same('section',$page->formsList()['embedded']['style']);
	$t->same('page',$page->formsList()['approve']['placement']);
	$t->same(['one'=>1,'two'=>2],$page->toArray()['meta']);
})->tag('panel','page','coverage')->group('framework-coverage');

test('panel page resolves widgets tables manifests and sorted runtime data',static function(Context $t): void {
	$request=PanelRequest::fromArray(['operation'=>'index','query'=>[]]);
	$page=PanelPage::make('dashboard')->widgets([
		Widget::make('later')->label('Later')->value(2)->sort(200),
		Widget::make('first')->label('First')->value(1)->sort(10),
	])->widget('chart','chart')->widget(['name'=>'array-widget','type'=>'stat','value'=>3])->widget('')
		->tables([
			PageTable::make('later')->label('Later')->sort(200)->records([['amount'=>10]])->summary(TableSummary::make('total')->sum('amount')),
			PageTable::make('first')->label('First')->sort(10)->records([['id'=>1]]),
		])->table(['name'=>'array-table'])->table('string-table')->table('');
	$states=$page->widgetStates($request);
	$t->same('first',$states[0]->widget()['name']);
	$t->same(count($states),count($page->resolvedWidgets($request)));
	$tables=$page->resolvedTables($request);
	$t->same('first',$tables[0]['table']->name());
	$t->same(10.0,$tables[count($tables)-1]['summaries'][0]['value']);
	$t->same(4,count($page->tablesList()));
	$manifest=$page->pageManifest($request,PanelManager::instance(),['surface'=>'test']);
	$t->same('page_manifest',$manifest['type']);
})->tag('panel','page','coverage')->group('framework-coverage');

test('panel page evaluates authorization rendering and navigation badge failures',static function(Context $t): void {
	$request=PanelRequest::fromArray(['operation'=>'show','tenant'=>'north','user'=>['id'=>1]]);
	$t->isTrue(PanelPage::make('public')->can('view',$request->user(),$request));
	PanelContext::run(['permission'=>['allow_guest_pages'=>['guest-page']]],static function()use($t,$request): void {
		$t->isTrue(PanelPage::make('guest-page')->can('view',$request->user(),$request));
		$t->isFalse(PanelPage::make('secured')->can('edit',$request->user(),$request));
	});
	$authorized=PanelPage::make('secure')->authorize(
		static fn(string $ability,mixed $user,PanelRequest $request,PanelPage $page): bool=>
			$ability==='view' && $user['id']===1 && $request->tenant()==='north' && $page->name()==='secure'
	);
	$t->isTrue($authorized->can('view',['id'=>1],$request));
	$t->isFalse($authorized->can('delete',['id'=>1],$request));

	$manager=PanelManager::instance();
	$static=PanelPage::make('static')->content('Static');
	$t->same('Static',$static->render($request,$manager));
	$dynamic=PanelPage::make('dynamic')->content(
		static fn(PanelRequest $request,PanelPage $page,PanelManager $manager): string=>
			$request->operation().'-'.$page->name().'-'.$manager::class
	);
	$t->contains('show-dynamic',$dynamic->render($request,$manager));
	$t->same('rendered',PanelPage::make('render')->renderUsing(static fn(): string=>'rendered')->render($request));

	$badge=PanelPage::make('badge')->navigationBadge(
		static fn(PanelRequest $request,PanelPage $page,PanelManager $manager): string=>
			$request->operation().'-'.$page->name().'-'.$manager::class
	)->navigationBadgeTone('info');
	$t->contains('show-badge',$badge->navigationEntry($request,$manager)['badge']);
	$t->isTrue($badge->toArray()['navigation_badge_lazy']);
	$t->same(null,$badge->toArray()['navigation_badge']);
	$failed=$badge->navigationBadgeUsing(static function(): never { throw new RuntimeException('badge failed'); });
	$t->same(null,$failed->navigationEntry($request,$manager)['badge']);
	$t->same(5,$failed->navigationBadge(5)->navigationEntry($request,$manager)['badge']);
})->tag('panel','page','coverage')->group('framework-coverage');

test('panel page private scaffold and action definition helpers cover boundaries',static function(Context $t): void {
	$page=PanelPage::make('helpers')->action(Action::make('existing'));
	[$existing,$options]=$t->nonPublic($page)->invoke('normalizeFormScaffold','existing',[
		'placement'=>'page','width'=>'lg','style'=>'card','show_action'=>true,'sort'=>9,
	],'embedded');
	$t->same('existing',$existing->name());
	$t->same('page',$options['placement']);
	$t->same('lg',$options['width']);
	$t->same('card',$options['style']);
	$t->isTrue($options['show_action']);
	$t->same(9,$options['sort']);
	[$arrayAction,$fallback]=$t->nonPublic($page)->invoke('normalizeFormScaffold',['name'=>'array-action'],['placement'=>'bad','width'=>'bad','style'=>'bad'],'page',);
	$t->same('array-action',$arrayAction->name());
	$t->same('embedded',$fallback['placement']);
	$t->same('full',$fallback['width']);
	$t->same('section',$fallback['style']);

	$t->instanceOf(ActionGroup::class,$t->nonPublic(PanelPage::class)->invoke('actionDefinition',[
		'name'=>'group','kind'=>'action_group',
	]));
	$t->instanceOf(ActionGroup::class,$t->nonPublic(PanelPage::class)->invoke('actionDefinition',[
		'name'=>'group','actions'=>[['name'=>'child']],
	]));
	$t->instanceOf(Action::class,$t->nonPublic(PanelPage::class)->invoke('actionDefinition',[
		'name'=>'action','type'=>'action',
	]));
	$t->same('Page Name',$t->nonPublic(PanelPage::class)->invoke('humanize','page_name'));
	$t->same('',$t->nonPublic(PanelPage::class)->invoke('humanize',''));
})->tag('panel','page','coverage')->group('framework-coverage');
