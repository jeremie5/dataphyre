<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelCollectionPresentation;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelDataResult;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelManifest;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPlugin;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelSearchProvider;
use Dataphyre\Panel\PanelTenant;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TableView;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array<string,mixed> */
function dp_panel_core_render_platform_config(string $root): array {
	return [
		'state_root'=>$root,
		'authentication'=>[
			'encryption_key'=>str_repeat('E', 32),
			'pepper'=>str_repeat('P', 32),
			'challenge_key'=>str_repeat('C', 32),
		],
		'media'=>['signing_key'=>str_repeat('M', 32)],
	];
}

final class DpPanelCoreRenderReentrantPlugin implements PanelPlugin {
	public int $boots=0;
	public function id(): string { return 'core-render-reentrant'; }
	public function register(PanelInstance $panel): void {}
	public function boot(PanelInstance $panel): void {
		$this->boots++;
		$panel->bootPlugins();
	}
}

test('panel facade platform and tenant forwarding preserves the configured default surface',static function(Context $t): void {
	Panel::flush();
	try{
		$platform=PanelPlatform::defaults(dp_panel_core_render_platform_config($t->tempDirectory('panel-core-render-facade')));
		Panel::usePlatform($platform);
		$pages=Panel::platformPages(['domains'=>['operations']]);
		$t->same(['platform_operations'],array_keys($pages));
		$t->same(Panel::default(),Panel::mountPlatformPages(['domains'=>['operations']]));
		$t->instanceOf(PanelPage::class,Panel::default()->getPage('platform_operations'));
		$t->isTrue(Panel::platformDiagnostics()['attachment']['configured']);
		$t->same('north',Panel::registerTenant(['name'=>'north','label'=>'North'])->name());
	}
	finally{
		Panel::flush();
	}
})->tag('panel','core','facade','platform','tenant','exact-coverage')->group('framework-coverage');

test('panel context restoration platform directives and plugin re-entry fail safely',static function(Context $t): void {
	$t->throws(static fn()=>PanelContext::run(['ephemeral'=>'inside'],static function(): never {
		throw new RuntimeException('context unwind probe');
	}),RuntimeException::class);
	$t->isFalse(PanelContext::has('ephemeral'));

	$platform=PanelPlatform::make();
	$t->same($platform,PanelInstance::make('platform-alias')->config(['platform'=>$platform])->platform());

	$attached=PanelInstance::make('platform-attached')->usePlatform(PanelPlatform::make());
	$t->throws(static fn()=>$attached->config(['platform_instance'=>PanelPlatform::make()]),LogicException::class);
	$t->throws(static fn()=>PanelInstance::make('invalid-platform-config')->config(['platform_config'=>'invalid']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelInstance::make('invalid-platform-instance')->config(['platform_instance'=>new stdClass()]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelInstance::make('invalid-platform-scalar')->config('platform',new stdClass()),InvalidArgumentException::class);

	$scalarPlatform=PanelPlatform::make();
	$t->same($scalarPlatform,PanelInstance::make('scalar-platform-instance')->config('platform_instance',$scalarPlatform)->platform());
	$t->throws(static fn()=>PanelInstance::make('invalid-scalar-config')->config('platform_config','invalid'),InvalidArgumentException::class);
	$configured=PanelInstance::make('scalar-platform-config')->config('platform_config',dp_panel_core_render_platform_config($t->tempDirectory('panel-core-render-scalar')));
	$t->isTrue($configured->hasPlatform());
	$t->throws(static fn()=>PanelInstance::make('invalid-replace')->config('platform_replace',true),InvalidArgumentException::class);

	$tenantSurface=PanelInstance::make('single-tenant');
	$t->same('north',$tenantSurface->registerTenant(PanelTenant::make('north'))->name());

	$plugin=new DpPanelCoreRenderReentrantPlugin();
	$pluginSurface=PanelInstance::make('reentrant-plugin')->plugin($plugin);
	$t->same($pluginSurface,$pluginSurface->bootPlugins());
	$t->same(1,$plugin->boots);
	$t->isTrue($pluginSurface->pluginsBooted());
})->tag('panel','core','context','platform','plugins','exact-coverage')->group('framework-coverage');

test('panel manager fails closed across search navigation and dispatch and preserves data results',static function(Context $t): void {
	$manager=new PanelManager();
	$manager->registerTenant(PanelTenant::make('north'));
	$request=PanelRequest::fromArray(['tenant'=>'north','user'=>['id'=>'operator']]);
	$provider=PanelSearchProvider::make('orders')->searchUsing(static fn(): array=>[['title'=>'Should not run']]);
	$resource=Resource::make('orders')->globalSearchable();

	$t->isFalse($manager->allowsSearchProvider($provider,$request));
	$t->isFalse($manager->allowsSearchResource($resource,$request));
	$navigation=$manager->navigationState($request,['query'=>'order'])->jsonSerialize();
	$t->same('denied',$navigation['meta']['tenant_context'] ?? null);
	$t->same([],$navigation['meta']['tenant_switcher'] ?? null);
	$denied=$manager->dispatch($request);
	$t->same(403,$denied->status());
	$t->contains('tenant_context_denied',$denied->content());

	$query=PanelDataQuery::make()->limit(2);
	$result=PanelDataResult::normalize([
		'items'=>[['id'=>1],['id'=>2]],
		'page'=>['offset'=>0,'limit'=>2,'total'=>9,'next_cursor'=>'next'],
		'aggregates'=>['sum'=>12],
		'metadata'=>['adapter'=>'assigned'],
	],$query,'assigned');
	$dataResource=Resource::make('data-results')->queryUsing(static fn(): PanelDataResult=>$result);
	$records=$t->nonPublic($manager)->invoke('records',$dataResource,PanelRequest::fromArray(['resource'=>'data-results','operation'=>'index']),false);
	$t->same([['id'=>1],['id'=>2]],$records[0]);
	$t->same(9,$records[1]);
	$t->isTrue($records[2]);
	$t->same('assigned',$records[3]['source'] ?? null);
})->tag('panel','manager','tenant','search','data','exact-coverage')->group('framework-coverage');

test('panel manifest and form renderers infer collection presentation from local item contracts',static function(Context $t): void {
	$platform=['type'=>'assigned-platform','attachment'=>['configured'=>false]];
	$manifestBuilder=PanelManifest::from([]);
	$t->same($platform,$t->nonPublic($manifestBuilder)->invoke('platformManifest',['platform'=>$platform]));

	$renderer=$t->nonPublic(PanelRenderer::class);
	$inferred=$renderer->invoke('fieldCollectionPresentation',[], 'options', [[
		'name'=>'north',
		'meta'=>['item_presentation'=>['span'=>2]],
	]],'grid');
	$t->same('grid',$inferred['display'] ?? null);

	$sectionMeta=['profile'=>[
		'name'=>'profile',
		'label'=>'Profile',
		'meta'=>[
			'tab_item_presentation'=>['order'=>-2],
			'step_item_presentation'=>['order'=>3],
		],
	]];
	$show=$renderer->invoke('showSectionsHtml',[
		'Profile'=>['<article>Profile value</article>'],
	],1,$sectionMeta,['sections'=>['display'=>'brick','columns'=>2]]);
	$t->contains('class="dp-panel-show-sections"',$show);
	$t->contains('data-dp-display="brick"',$show);

	$tabs=$renderer->invoke('tabsHtml',[
		'General'=>['Profile'=>['<label>Profile field</label>']],
	],$sectionMeta,1,false,['display'=>'brick']);
	$t->contains('--dp-item-order:-2',$tabs);
	$steps=$renderer->invoke('stepsHtml',[
		'Review'=>['Profile'=>['<label>Review field</label>']],
	],$sectionMeta,1,false,['display'=>'brick']);
	$t->contains('--dp-item-order:3',$steps);
})->tag('panel','manifest','renderer','forms','presentation','exact-coverage')->group('framework-coverage');

test('page and board renderers infer owner presentation from item-level layout',static function(Context $t): void {
	$renderer=$t->nonPublic(PanelRenderer::class);
	$pagePresentation=$renderer->invoke('pageCollectionPresentation',PanelPage::make('local-items'),'widgets',[[
		'name'=>'revenue',
		'meta'=>['item_presentation'=>['fill_remainder'=>true]],
	]],'stack');
	$t->same('stack',$pagePresentation['display'] ?? null);

	$resource=Resource::make('board-items')
		->recordKeyUsing('id')
		->recordTitleUsing('name')
		->statusField('status')
		->statusTransitions([
			'review'=>['to'=>'review','from'=>'draft','label'=>'Review'],
		])
		->transitionUsing(static fn(): array=>['transitioned'=>true])
		->view(TableView::make('draft')
			->label('Draft')
			->where(static fn(array $record): bool=>($record['status'] ?? '')==='draft')
			->itemSpan(2));
	$board=PanelRenderer::statusBoard($resource,PanelRequest::fromArray([
		'resource'=>'board-items',
		'operation'=>'board',
		'user'=>['id'=>'operator'],
	]),[['id'=>'A','name'=>'Alpha','status'=>'draft']]);
	$t->same(200,$board->status());
	$t->contains('class="dp-panel-board"',$board->content());
	$t->contains('data-dp-display="grid"',$board->content());
	$t->contains('--dp-item-span:2',$board->content());
})->tag('panel','renderer','pages','board','presentation','exact-coverage')->group('framework-coverage');

test('shell tenant choices and cursor pagination render normalized interactive controls',static function(Context $t): void {
	$renderer=$t->nonPublic(PanelRenderer::class);
	$switcher=$renderer->invoke('tenantSwitcherHtml',[[
		'name'=>'north',
		'label'=>'North',
		'url'=>'/panel/tenant/north',
		'badge'=>2,
		'current'=>true,
		'authorized'=>true,
	]]);
	$t->contains('dp-panel-tenant-switcher-sidebar',$switcher);
	$t->contains('class="dp-panel-tenant-switcher-option"',$switcher);
	$t->contains('<small>2</small>',$switcher);

	$resource=Resource::make('cursor-orders');
	$request=PanelRequest::fromArray([
		'resource'=>'cursor-orders',
		'operation'=>'index',
		'query'=>['cursor'=>'current','per_page'=>10],
	]);
	$pagination=PanelContext::run([
		'table_pagination_visibility'=>'always',
		'url_builder'=>static fn(string $target,array $query=[]): string=>'/panel?'.http_build_query($query),
	],static fn(): string=>$renderer->invoke('paginationHtml',$resource,$request,30,2,10,3,[
		'page'=>[
			'total'=>null,
			'previous_cursor'=>'previous-token',
			'next_cursor'=>'next-token',
		],
	]));
	$t->contains('data-dp-panel-pagination="cursor"',$pagination);
	$t->contains('cursor=previous-token',$pagination);
	$t->contains('cursor=next-token',$pagination);
	$firstPage=PanelContext::run([
		'table_pagination_visibility'=>'always',
		'url_builder'=>static fn(string $target,array $query=[]): string=>'/panel?'.http_build_query($query),
	],static fn(): string=>$renderer->invoke('paginationHtml',$resource,$request,30,1,10,3,[
		'page'=>[
			'total'=>null,
			'previous_cursor'=>'',
			'next_cursor'=>'next-token',
		],
	]));
	$t->contains('<span class="dp-panel-page-disabled">',$firstPage);
	$t->contains('cursor=next-token',$firstPage);
})->tag('panel','renderer','shell','tables','tenant','cursor','exact-coverage')->group('framework-coverage');
