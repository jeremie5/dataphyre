<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Column;
use Dataphyre\Panel\NavigationItem;
use Dataphyre\Panel\PanelCommand;
use Dataphyre\Panel\PanelComponentRegistry;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTheme;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',['enabled'=>['core'=>true,'panel'=>true,'mvc'=>true,'templating'=>true],'disabled'=>[],'core_implicit'=>true]);
}
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
require_once $modulesRoot.'/core/kernel/core_functions.php';
if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; function tracelog(mixed ...$arguments): void {}');
}
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['panel','mvc','templating']);

final class DpPanelRendererShellRecord {
	public string $public='visible';
	public function getDisplayName(): string { return 'Getter value'; }
}

final class DpPanelRendererShellStringable implements Stringable {
	public function __construct(private string $value) {}
	public function __toString(): string { return $this->value; }
}

/** @return list<array<string,mixed>> */
function dp_panel_renderer_shell_navigation(): array {
	return [
		[
			'name'=>'orders','label'=>'Orders','description'=>'Manage orders','group'=>'Commerce','kind'=>'resource',
			'url'=>'/panel/orders?sort=name&tenant=one','icon'=>'shopping-bag','badge'=>3,'badge_tone'=>'success',
			'active'=>true,'active_descendant'=>true,
			'children'=>[
				['name'=>'drafts','label'=>'Draft orders','description'=>'Needs review','kind'=>'page','url'=>'/panel/drafts','icon'=>'file','new_tab'=>true],
				['name'=>'nested','label'=>'Nested','url'=>'','children'=>[
					['name'=>'deep','label'=>'Deep child','url'=>'/panel/deep','badge'=>'N'],
				]],
			],
		],
		['name'=>'unsafe','label'=>'Unsafe','group'=>'Commerce','url'=>'javascript:alert(1)','icon'=>''],
		['name'=>'reports','label'=>'Reports','group'=>'Insights','kind'=>'page','url'=>'/panel/reports','icon'=>'activity'],
	];
}

function dp_panel_renderer_shell_theme(bool $dark=true): PanelTheme {
	return PanelTheme::make('shell-theme')
		->darkMode($dark)
		->defaultMode('system')
		->modeToggle($dark)
		->brandName('Shell Brand')
		->brandLogo('/assets/logo.svg')
		->darkModeBrandLogo('/assets/logo-dark.svg')
		->brandLogoHeight('2.5rem')
		->favicon('/assets/favicon.svg')
		->stylesheet('/assets/theme.css','theme',['media'=>'screen','integrity'=>'sha256-test','onclick'=>'bad','title'=>'Theme']);
}

test('panel renderer shell composes configured pages and fails soft around navigation commands and footer callbacks',static function(Context $t): void {
	$theme=dp_panel_renderer_shell_theme();
	$manager=new PanelManager();
	$manager->registerNavigationItems(dp_panel_renderer_shell_navigation());
	$manager->registerCommand(PanelCommand::make('refresh')->label('Refresh')->url('/panel/refresh'));
	$request=PanelRequest::fromArray(['method'=>'GET','resource'=>'orders','operation'=>'index','query'=>['tenant'=>'one']]);
	$config=[
		'__panel_manager'=>$manager,'theme'=>$theme,'panel_label'=>'Operations','home_label'=>'Overview','panel_tagline'=>'Work clearly',
		'navigation_layout'=>'sidebar','navigation_mode'=>'overlay','mobile_navigation_mode'=>'drawer','mobile_sidebar_layout'=>'split',
		'navigation_sticky'=>true,'header_sticky'=>true,'footer_sticky'=>true,'header_mode'=>'docked','footer_mode'=>'edge',
		'content_spacing'=>'compact','custom_page_layout'=>'flow','navigation_search'=>true,'recent_navigation'=>true,'pinned_navigation'=>true,
		'navigation_features'=>['search'=>true,'recent'=>true,'pinning'=>true,'collapse'=>true,'collapse_exclusive'=>true],
		'modal_expand_button'=>'surface','modal_chrome_actions'=>['open_full','copy_link'],'sidebar_animation_type'=>'slide_fade',
		'sidebar_animation_duration'=>225,'sidebar_animation_easing'=>'snappy','live_update_interval_ms'=>6000,'content_update_flashes'=>true,
		'url_builder'=>static fn(string $target,array $query=[]): string=>'/panel/'.ltrim($target,'/').($query!==[] ? '?'.http_build_query($query) : ''),
		'asset_url_builder'=>static fn(string $asset): string=>'/assets/'.$asset,
		'footer_html'=>static fn(array $context): string=>'<span>Footer '.$context['title'].'</span>',
		'render_hooks'=>[
			'content.before'=>[static fn(): string=>'<i>before</i>'],'content.after'=>[static fn(): string=>'<i>after</i>'],
			'header.before'=>[static fn(): string=>'<b>head-before</b>'],'header.after'=>[static fn(): string=>'<b>head-after</b>'],
			'footer.before'=>[static fn(): string=>'<b>foot-before</b>'],'footer.after'=>[static fn(): string=>'<b>foot-after</b>'],
		],
	];
	$result=PanelContext::run($config,static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke('page','Orders','<section>Body</section>',[
			'kind'=>'index','request'=>$request->toArray(),'resource'=>['name'=>'orders','label'=>'Order','plural_label'=>'Orders'],
			'notifications'=>[],'title'=>'Orders','total_count'=>3,'active_view'=>'all','update_flash'=>true,
		],207,[['message'=>'Rendered','type'=>'success']],));
	$t->same(207,$result->status());
	$html=$result->content();
	$t->contains('<!doctype html>',$html);
	$t->contains('dp-panel-with-sidebar',$html);
	$t->contains('data-dp-panel-navigation-sticky="1"',$html);
	$t->contains('data-dp-panel-header-sticky="1"',$html);
	$t->contains('data-dp-panel-footer-sticky="1"',$html);
	$t->contains('data-dp-panel-live-interval="6000"',$html);
	$t->contains('Footer Orders',$html);
	$t->contains('/assets/panel.css',$html);
	$t->contains('/assets/panel.js',$html);

	$badManager=new PanelManager();
	$t->nonPublic($badManager)
		->writeProperty('navigationItems',['broken'=>new stdClass()])
		->writeProperty('commands',['broken'=>new stdClass()]);
	class_exists('dataphyre\\templating');
	$fallback=PanelContext::run(['__panel_manager'=>$badManager,'theme'=>$theme,'navigation_layout'=>'none'],static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke('page','Fallback','Body',['kind'=>'custom'],200,[]));
	$t->same(200,$fallback->status());
	$t->contains('Fallback',$fallback->content());

	$footerError=PanelContext::run(['footer_html'=>static function(): string { throw new RuntimeException('footer exploded'); }],static fn(): string=>$t->nonPublic(PanelRenderer::class)->invoke('footerHtml',['title'=>'Footer'],'floating'));
	$t->same('',$footerError);
})->tag('panel','renderer','shell','coverage')->group('framework-coverage');

test('panel renderer shell navigation helpers cover layouts trees active matching and safe URLs',static function(Context $t): void {
	$server=$t->globalMap('_SERVER');
	$theme=dp_panel_renderer_shell_theme();
	$navigation=dp_panel_renderer_shell_navigation();
	$data=[
		'kind'=>'dashboard','title'=>'Dashboard','navigation_state'=>[],'navigation'=>$navigation,
		'resource'=>['name'=>'orders'],'page'=>['name'=>'reports'],
		'request'=>PanelRequest::fromArray(['method'=>'GET','resource'=>'orders','operation'=>'index'])->toArray(),
	];
	$html=PanelContext::run([
		'theme'=>$theme,'panel_label'=>'Panel','home_label'=>'Home','home_navigation'=>true,'navigation_search'=>true,
		'url_builder'=>static fn(string $target,array $query=[]): string=>'/panel/'.ltrim($target,'/'),
	],static function()use($data,$theme,$t): string {
		$sidebar=$t->nonPublic(PanelRenderer::class)->invoke('navigationChromeHtml',$data,$theme,'sidebar','docked');
		$horizontal=$t->nonPublic(PanelRenderer::class)->invoke('navigationChromeHtml',$data,$theme,'horizontal','floating');
		return $sidebar.$horizontal;
	});
	$t->contains('dp-panel-sidebar',$html);
	$t->contains('dp-panel-horizontal-nav',$html);
	$t->contains('Draft orders',$html);
	$t->contains('Deep child',$html);
	$t->isFalse(str_contains($html,'javascript:'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('navigationChromeHtml',$data,$theme,'none','floating'));

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('horizontalNavigationLinkHtml',['label'=>'']));
	$t->contains('submenu',$t->nonPublic(PanelRenderer::class)->invoke('horizontalNavigationLinkHtml',$navigation[0]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('sidebarNavigationLinkHtml',['label'=>'Missing URL'],$data));
	$t->contains('submenu',$t->nonPublic(PanelRenderer::class)->invoke('sidebarNavigationEntryHtml',$navigation[0],$data,0));
	$t->same(3,$t->nonPublic(PanelRenderer::class)->invoke('navigationChildrenCount',$navigation[0]['children']));
	$t->same('/panel/deep',$t->nonPublic(PanelRenderer::class)->invoke('sidebarFirstEntryUrl',[42,['children'=>[['url'=>'/panel/deep']]]]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('sidebarFirstEntryUrl',[42,['url'=>'javascript:bad']]));
	$t->same(0,$t->nonPublic(PanelRenderer::class)->invoke('navigationChildrenCount',[42,'bad']));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('sidebarNavigationActive',['active'=>true],$data));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('sidebarNavigationActive',['name'=>'orders','kind'=>'resource'],$data));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('sidebarNavigationActive',['name'=>'reports','kind'=>'page'],$data));
	$server->put('REQUEST_URI','/panel/match?tenant=one&page=4&sort=name');
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('sidebarNavigationActive',['name'=>'match','url'=>'/panel/match?tenant=one'],$data));
	$t->same('/panel/orders?tenant=one',$t->nonPublic(PanelRenderer::class)->invoke('normalizedNavigationUrl','/panel/orders/?sort=name&tenant=one&page=2'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('normalizedNavigationUrl',' '));
	$t->same('http://example.com:bad',$t->nonPublic(PanelRenderer::class)->invoke('normalizedNavigationUrl','http://example.com:bad'));
	$t->same('HM',$t->nonPublic(PanelRenderer::class)->invoke('navigationIconToken','home','Anything'));
	$t->same('OC',$t->nonPublic(PanelRenderer::class)->invoke('navigationIconToken','','Order Center'));
	$t->same('P',$t->nonPublic(PanelRenderer::class)->invoke('navigationIconToken','','***'));

	PanelContext::run(['sidebar'=>false],static function()use($t): void {
		$t->same('none',$t->nonPublic(PanelRenderer::class)->invoke('navigationLayout'));
		$t->same('floating',$t->nonPublic(PanelRenderer::class)->invoke('navigationMode','none'));
		$t->same('none',$t->nonPublic(PanelRenderer::class)->invoke('mobileNavigationMode','none'));
		$t->isFalse($t->nonPublic(PanelRenderer::class)->invoke('navigationSticky','none'));
	});
	PanelContext::run(['page_width'=>'wide'],static function()use($t): void {
		$t->same('fluid',$t->nonPublic(PanelRenderer::class)->invoke('pageWidthMode','sidebar','floating'));
	});
	PanelContext::run(['page_width'=>'unknown'],static function()use($t): void {
		$t->same('fluid',$t->nonPublic(PanelRenderer::class)->invoke('pageWidthMode','sidebar','overlay'));
		$t->same('fluid',$t->nonPublic(PanelRenderer::class)->invoke('pageWidthMode','horizontal','floating'));
		$t->same('constrained',$t->nonPublic(PanelRenderer::class)->invoke('pageWidthMode','sidebar','floating'));
	});
	$cleanManager=new PanelManager();
	PanelContext::run([
		'__panel_manager'=>$cleanManager,'home_navigation'=>false,'navigation_features'=>['search'=>false],
		'url_builder'=>static fn(string $target,array $query=[]): string=>'/panel/'.ltrim($target,'/'),
	],static function()use($t,$theme): void {
		$sidebar=$t->nonPublic(PanelRenderer::class)->invoke('sidebarHtml',['kind'=>'custom'],$theme,'floating');
		$t->isFalse(str_contains($sidebar,'dp-panel-sidebar-search'));
		$horizontal=$t->nonPublic(PanelRenderer::class)->invoke('horizontalNavigationHtml',['kind'=>'custom'],$theme,'floating');
		$t->contains('horizontal-nav',$horizontal);
	});
	$badSidebarManager=new PanelManager();
	$t->nonPublic($badSidebarManager)->writeProperty('navigationItems',['broken'=>new stdClass()]);
	$t->contains('dp-panel-sidebar',PanelContext::run(['__panel_manager'=>$badSidebarManager],static fn(): string=>$t->nonPublic(PanelRenderer::class)->invoke('sidebarHtml',['kind'=>'custom'],$theme,'floating')));
	$noUrlNavigation=[['name'=>'group','label'=>'Group','group'=>'Empty','url'=>'','children'=>[['name'=>'child','label'=>'Child','url'=>'javascript:bad']]]];
	$t->contains('data-dp-panel-sidebar-group="Empty"',PanelContext::run(['home_navigation'=>false,'navigation_features'=>['search'=>false]],static fn(): string=>$t->nonPublic(PanelRenderer::class)->invoke('sidebarHtml',['navigation_state'=>[],'navigation'=>$noUrlNavigation],$theme,'floating')));
	$emptyGroup=[['name'=>'empty','label'=>'','group'=>'Empty','url'=>'']];
	$t->contains('horizontal-nav',PanelContext::run(['home_navigation'=>false],static fn(): string=>$t->nonPublic(PanelRenderer::class)->invoke('horizontalNavigationHtml',['navigation_state'=>[],'navigation'=>$emptyGroup],$theme,'floating')));
})->tag('panel','renderer','shell','coverage')->group('framework-coverage');

test('panel renderer shell theme guidance breadcrumbs request and content helpers cover variants',static function(Context $t): void {
	$cookie=$t->globalMap('_COOKIE');
	$get=$t->globalMap('_GET');
	$dark=dp_panel_renderer_shell_theme();
	$light=dp_panel_renderer_shell_theme(false);
	$cookie->forget('dataphyre_panel_theme_mode');
	$t->same('system',$t->nonPublic(PanelRenderer::class)->invoke('themeMode',$dark));
	$cookie->put('dataphyre_panel_theme_mode','dark');
	$t->same('dark',$t->nonPublic(PanelRenderer::class)->invoke('themeMode',$dark));
	$t->same('light',$t->nonPublic(PanelRenderer::class)->invoke('themeMode',$light));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('themeHeadScript',$light,'light'));
	$t->same('',PanelContext::run(['asset_url_builder'=>static fn(string $asset): string=>'javascript:bad'],static fn(): string=>$t->nonPublic(PanelRenderer::class)->invoke('themeHeadScript',$dark,'dark')));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('themeModeToggleHtml',$light,'light'));
	$t->notEmpty($t->nonPublic(PanelRenderer::class)->invoke('themeModeToggleHtml',$dark,'dark'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('themeModeScript',$light));
	$t->contains('dpPanelCurrentThemeMode',$t->nonPublic(PanelRenderer::class)->invoke('themeModeScript',$dark));
	$t->contains('stylesheet',$t->nonPublic(PanelRenderer::class)->invoke('themeCssAssets',$dark));
	$t->contains('logo-dark.svg',PanelContext::run(['panel_label'=>'Brand'],static fn(): string=>$t->nonPublic(PanelRenderer::class)->invoke('brandHtml',$dark)));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('brandHtml',PanelTheme::make('blank')));
	$t->same('2.5rem',$t->nonPublic(PanelRenderer::class)->invoke('safeLogoHeight',' 2.5REM '));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('safeLogoHeight','expression(bad)'));

	$get->replace(['panel_theme'=>'glass','tenant'=>'one','filters'=>['status'=>'active','skip'=>null],'object'=>new stdClass()]);
	$selector=PanelContext::run([
		'theme_selector'=>true,'theme_selector_parameter'=>'panel_theme','theme_selector_label'=>'Choose theme',
		'theme_selector_presets'=>['glass'=>'Glass','glass_alt'=>'Glass',''=>'Blank','brutalist'=>'Brutalist'],
	],static fn(): string=>$t->nonPublic(PanelRenderer::class)->invoke('themePresetSelectorHtml'));
	$t->contains('selected',$selector);
	$t->contains('filters[status]',$selector);
	$t->isFalse(str_contains($selector,'object'));
	$t->same('',PanelContext::run(['theme_selector'=>false],static fn(): string=>$t->nonPublic(PanelRenderer::class)->invoke('themePresetSelectorHtml')));
	$t->same('',PanelContext::run(['theme_selector'=>true,'theme_selector_presets'=>[]],static fn(): string=>$t->nonPublic(PanelRenderer::class)->invoke('themePresetSelectorHtml')));
	$t->same('',PanelContext::run(['theme_selector'=>true,'theme_selector_presets'=>['x'=>' ']],static fn(): string=>$t->nonPublic(PanelRenderer::class)->invoke('themePresetSelectorHtml')));

	$resource=['name'=>'orders','label'=>'Order','plural_label'=>'Orders'];
	$guidanceCases=[
		['dashboard',['global_search'=>['query'=>'shoe','results'=>[['id'=>1]]]]],
		['dashboard',['global_search'=>['query'=>'none','results'=>[]]]],
		['custom_page',['widgets'=>[['name'=>'one']],'tables'=>[]]],
		['index',['resource'=>$resource,'total_count'=>5,'active_view'=>'active']],
		['index',['resource'=>$resource,'total_count'=>0]],
		['create',['resource'=>$resource]],['store',['resource'=>$resource]],
		['edit',['resource'=>$resource]],['update',['resource'=>$resource]],['show',['resource'=>$resource]],
		['import',['resource'=>$resource]],
		['import_preview',['resource'=>$resource,'invalid_count'=>2,'row_count'=>3]],
		['import_preview',['resource'=>$resource,'invalid_count'=>0,'row_count'=>1]],
		['action_form',[]],['bulk_delete',[]],['other',['page'=>['name'=>'custom']]],
	];
	foreach($guidanceCases as [$kind,$extra]){
		$t->notEmpty($t->nonPublic(PanelRenderer::class)->invoke('surfaceGuidance','Title',array_replace(['kind'=>$kind],$extra)));
	}
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('surfaceGuidance','Dashboard',['kind'=>'dashboard']));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('surfaceGuidance','Empty',['kind'=>'custom_page','widgets'=>[],'tables'=>[]]));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('surfaceGuidance','Unknown',['kind'=>'unknown']));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('surfaceGuidanceHtml','Title',['kind'=>'index']));

	$breadcrumbKinds=['index','create','edit','show','relation','action','other'];
	foreach($breadcrumbKinds as $kind){
		$trail=PanelContext::run(['home_label'=>'Home'],static fn(): array=>$t->nonPublic(PanelRenderer::class)->invoke('breadcrumbs','Title',[
			'kind'=>$kind,'resource'=>$resource,'record_identity'=>['title'=>'Order 1'],'relation'=>['label'=>'Items'],'action'=>['label'=>'Approve'],
		]));
		$t->isTrue((bool)end($trail)['current']);
	}
	$pageTrail=PanelContext::run(['home_label'=>'Home'],static fn(): array=>$t->nonPublic(PanelRenderer::class)->invoke('breadcrumbs','Reports',['kind'=>'custom_page','page'=>['name'=>'reports','label'=>'Reports','url'=>'/reports']]));
	$t->contains('Reports',$t->nonPublic(PanelRenderer::class)->invoke('breadcrumbsHtml',$pageTrail));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('breadcrumbsHtml',[['label'=>'Only','current'=>true]]));
	$homeTrail=PanelContext::run(['home_label'=>'Home'],static fn(): array=>$t->nonPublic(PanelRenderer::class)->invoke('breadcrumbs','Home',['kind'=>'custom_page','page'=>['name'=>'home','label'=>'Home']]));
	$t->same(1,count($homeTrail));

	$request=$t->nonPublic(PanelRenderer::class)->invoke('requestFromData',['request'=>['method'=>'GET','resource'=>'orders']]);
	$t->instanceOf(PanelRequest::class,$request);
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('requestFromData',[]));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('requestFromData',['request'=>['method'=>new stdClass()]]));
	$t->same(0,PanelContext::run(['live_updates'=>false],static fn(): int=>$t->nonPublic(PanelRenderer::class)->invoke('liveRefreshInterval',['kind'=>'dashboard'])));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('liveRefreshControlHtml',5000));
	$t->same('visible',$t->nonPublic(PanelRenderer::class)->invoke('recordValue',new DpPanelRendererShellRecord(),'public','fallback'));
	$t->same('Getter value',$t->nonPublic(PanelRenderer::class)->invoke('recordValue',new DpPanelRendererShellRecord(),'display_name','fallback'));
	$t->same('fallback',$t->nonPublic(PanelRenderer::class)->invoke('recordValue',new stdClass(),'missing','fallback'));
	$t->same('value',$t->nonPublic(PanelRenderer::class)->invoke('recordValue',['key'=>'value'],'key','fallback'));
	$t->same('stringable',$t->nonPublic(PanelRenderer::class)->invoke('pageContentValue',new DpPanelRendererShellStringable('stringable')));
	$t->same('42',$t->nonPublic(PanelRenderer::class)->invoke('pageContentValue',42));
	$t->contains('<pre>',$t->nonPublic(PanelRenderer::class)->invoke('pageContentValue',[1,2,3]));
	$t->contains('empty-state',$t->nonPublic(PanelRenderer::class)->invoke('customPageShell',' '));
	$t->contains('custom-page',PanelContext::run(['custom_page_layout'=>'flow'],static fn(): string=>$t->nonPublic(PanelRenderer::class)->invoke('customPageShell','<p>Content</p>')));
})->tag('panel','renderer','shell','coverage')->group('framework-coverage');

test('panel renderer shell widgets charts global search and navigation summaries cover data shapes',static function(Context $t): void {
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('widgetsHtml',[]));
	$widgets=[
		'bad',
		['type'=>'stat','label'=>'Orders','value'=>12,'description'=>'Today','icon'=>'shopping-bag','tone'=>'success','url'=>'/orders'],
		['type'=>'stat','label'=>'Unsafe','value'=>0,'tone'=>'nope','url'=>'javascript:bad'],
		['type'=>'chart','label'=>'Revenue','value'=>'$20','description'=>'Monthly','icon'=>'activity','url'=>'/revenue','meta'=>[
			'chart_type'=>'area','labels'=>['Jan','Feb'],'datasets'=>[
				['label'=>'Current','values'=>[2,5],'tone'=>'primary'],
				['label'=>'Previous','data'=>[['value'=>1],['value'=>3]],'tone'=>'warning'],
				['label'=>'Empty','values'=>'bad'],
				'bad',
			],
		]],
		['type'=>'trend','label'=>'Mix','meta'=>['chart_type'=>'donut','data'=>['A'=>2,'B'=>3]]],
	];
	$html=$t->nonPublic(PanelRenderer::class)->invoke('widgetsHtml',$widgets);
	$t->contains('dp-panel-widgets',$html);
	$t->contains('polyline',$html);
	$t->contains('chart-donut',$html);
	$t->isFalse(str_contains($html,'javascript:'));

	$t->contains('chart-empty',$t->nonPublic(PanelRenderer::class)->invoke('chartSvgHtml',['data'=>[]],'primary','Empty'));
	$t->contains('<rect',$t->nonPublic(PanelRenderer::class)->invoke('chartSvgHtml',['chart_type'=>'bar','labels'=>['One'],'datasets'=>[['label'=>'Only','values'=>[5]]]],'primary','Bar'));
	[$generatedLabels,$generatedDatasets]=$t->nonPublic(PanelRenderer::class)->invoke('chartDatasets',['datasets'=>[['label'=>'Only','values'=>[5,6]]]],'primary');
	$t->same(['1','2'],$generatedLabels);
	$t->same(1,count($generatedDatasets));
	$t->contains('polyline',$t->nonPublic(PanelRenderer::class)->invoke('cartesianChartHtml',[['label'=>'Empty','values'=>[]],['label'=>'Data','values'=>[1]]],['One'],'line',180,'Mixed'));
	$t->contains('polyline',$t->nonPublic(PanelRenderer::class)->invoke('chartSvgHtml',['chart_type'=>'sparkline','data'=>[5]],'primary','Spark'));
	$t->contains('chart-empty',$t->nonPublic(PanelRenderer::class)->invoke('donutChartHtml',['values'=>[0,0]],160,'Zero'));
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('chartValues','bad'));
	$t->same([1.0,2.0],$t->nonPublic(PanelRenderer::class)->invoke('chartValues',[1,['value'=>2]]));
	[$labels,$values]=$t->nonPublic(PanelRenderer::class)->invoke('chartLabelsAndValues',[['label'=>'First','value'=>1],'second'=>2]);
	$t->same(['First','second'],$labels);
	$t->same([1.0,2.0],$values);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('chartLegendHtml',[['label'=>'One','values'=>[1]]]));
	$t->contains('legend',$t->nonPublic(PanelRenderer::class)->invoke('chartLegendHtml',[['label'=>'One','values'=>[1]],['label'=>'','values'=>[2]],['label'=>'Two','values'=>[3],'tone'=>'danger']]));

	$search=PanelContext::run(['global_search_parameter'=>'q','url_builder'=>static fn(string $target,array $query=[]): string=>'/panel'],static function()use($t): string {
		return $t->nonPublic(PanelRenderer::class)->invoke('globalSearchHtml','ord',[
			'bad',
			['title'=>'Order 1','subtitle'=>'Customer','resource_label'=>'Orders','url'=>'/orders/1','record_key'=>'1'],
			['title'=>'Unsafe','url'=>'javascript:bad'],
		]);
	});
	$t->contains('Order 1',$search);
	$t->contains('data-dp-panel-action-modal',$search);
	$t->isFalse(str_contains($search,'javascript:'));
	$t->contains('empty-state',PanelContext::run(['url_builder'=>static fn(string $target,array $query=[]): string=>'/panel'],static fn(): string=>$t->nonPublic(PanelRenderer::class)->invoke('globalSearchHtml','none',[])));
	$t->contains('<form',PanelContext::run(['url_builder'=>static fn(string $target,array $query=[]): string=>'/panel'],static fn(): string=>$t->nonPublic(PanelRenderer::class)->invoke('globalSearchHtml','',[])));

	$t->contains('empty-state',$t->nonPublic(PanelRenderer::class)->invoke('navigationGroupsHtml',[]));
	$groups=[['label'=>'Commerce','entries'=>[
		['label'=>'Orders','description'=>'Manage','url'=>'/orders','icon'=>'shopping-bag','badge'=>4,'badge_tone'=>'success','active'=>true,'new_tab'=>true],
		['label'=>'Unsafe','url'=>'javascript:bad'],
	]]];
	$groupHtml=$t->nonPublic(PanelRenderer::class)->invoke('navigationGroupsHtml',$groups);
	$t->contains('Orders',$groupHtml);
	$t->isFalse(str_contains($groupHtml,'javascript:'));
	$t->contains('Reports',$t->nonPublic(PanelRenderer::class)->invoke('navigationGroupsHtml',[['name'=>'reports','label'=>'Reports','group'=>'Main','url'=>'/reports']]));
	$t->same('SB',$t->nonPublic(PanelRenderer::class)->invoke('compactNavIcon','shopping-bag','Orders'));
	$t->same('*',$t->nonPublic(PanelRenderer::class)->invoke('compactNavIcon','','***'));
})->tag('panel','renderer','shell','coverage')->group('framework-coverage');

test('panel renderer shell action modal attribute and cell helpers enforce allow lists and branches',static function(Context $t): void {
	$meta=[
		'name'=>'approve','label'=>'Approve','icon'=>'check-circle','badge'=>'3','badge_tone'=>'success','description'=>'Approve order','icon_only'=>true,
		'tooltip'=>'Run approval','key_bindings'=>['mod+shift+k','escape','space'],
		'extra_attributes'=>[
			'class'=>'custom valid bad<script>','data-test'=>'yes','data-false'=>false,'data-null'=>null,'data-dp-panel-owned'=>'no','aria-label'=>'Approval','aria-disabled'=>'true',
			'id'=>'approve','download'=>true,'hidden'=>false,'nullable'=>null,'array'=>['bad'],0=>'ignored',
		],
	];
	$t->contains('dp-panel-action-badge-success',$t->nonPublic(PanelRenderer::class)->invoke('actionLabelHtml',$meta));
	$t->contains('action-label',$t->nonPublic(PanelRenderer::class)->invoke('actionTextHtml','','check'));
	$t->contains('Ctrl/Cmd+Shift+K',$t->nonPublic(PanelRenderer::class)->invoke('actionTooltipAttributes',$meta));
	$t->contains('aria-keyshortcuts',$t->nonPublic(PanelRenderer::class)->invoke('actionKeyBindingAttributes',$meta));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('actionKeyBindingAttributes',['key_bindings'=>[new stdClass(),' ']]));
	$t->contains('data-test="yes"',$t->nonPublic(PanelRenderer::class)->invoke('actionExtraAttributes',$meta));
	$t->isFalse(str_contains($t->nonPublic(PanelRenderer::class)->invoke('actionExtraAttributes',$meta),'data-dp-panel-owned'));
	$t->same(' custom valid',$t->nonPublic(PanelRenderer::class)->invoke('actionExtraClass',$meta));
	$t->isFalse($t->nonPublic(PanelRenderer::class)->invoke('isSafeActionExtraAttribute','class'));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('isSafeActionExtraAttribute','data-test'));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('isSafeActionExtraAttribute','aria-label'));
	$t->isFalse($t->nonPublic(PanelRenderer::class)->invoke('isSafeActionExtraAttribute','aria-disabled'));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('isSafeActionExtraAttribute','role'));

	$attributes=['class'=>'one two bad<script>','data-cell'=>'yes','data-false'=>false,'data-null'=>null,'data-boolean'=>true,'data-dp-panel-cell'=>'no','aria-label'=>'Cell','aria-sort'=>'ascending','scope'=>'col','empty'=>false,'null'=>null,'boolean'=>true,'array'=>['bad'],0=>'skip'];
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('columnExtraAttributes',[]));
	$t->contains('data-cell="yes"',$t->nonPublic(PanelRenderer::class)->invoke('columnExtraAttributes',$attributes));
	$t->contains(' data-boolean',$t->nonPublic(PanelRenderer::class)->invoke('columnExtraAttributes',$attributes));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('columnExtraClass',[]));
	$t->same(' class="one two"',$t->nonPublic(PanelRenderer::class)->invoke('columnExtraClass',$attributes));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('isSafeColumnExtraAttribute','headers'));
	$t->isFalse($t->nonPublic(PanelRenderer::class)->invoke('isSafeColumnExtraAttribute','aria-sort'));
	$t->contains('data-cell="yes"',$t->nonPublic(PanelRenderer::class)->invoke('tableRowExtraAttributes',$attributes));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('tableRowExtraAttributes',[]));
	$t->contains(' data-boolean',$t->nonPublic(PanelRenderer::class)->invoke('tableRowExtraAttributes',$attributes));
	$t->same(' class="one two"',$t->nonPublic(PanelRenderer::class)->invoke('tableRowExtraClass',$attributes));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('tableRowExtraClass',[]));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('isSafeTableRowExtraAttribute','data-row'));
	$t->isFalse($t->nonPublic(PanelRenderer::class)->invoke('isSafeTableRowExtraAttribute','aria-label'));
	$t->same('Ctrl/Cmd+Shift+K',$t->nonPublic(PanelRenderer::class)->invoke('displayKeyBinding','mod+shift+k'));
	$t->same('Control+Shift+K',$t->nonPublic(PanelRenderer::class)->invoke('ariaKeyBinding','mod+shift+k'));
	$t->same('Esc',$t->nonPublic(PanelRenderer::class)->invoke('displayKeyBinding','escape'));
	$t->same('Escape',$t->nonPublic(PanelRenderer::class)->invoke('ariaKeyBinding','escape'));
	$t->same('Ctrl+Cmd+Alt+Enter+Space',$t->nonPublic(PanelRenderer::class)->invoke('displayKeyBinding','ctrl+meta+alt+enter+space'));
	$t->same('Control+Meta+Alt+Space',$t->nonPublic(PanelRenderer::class)->invoke('ariaKeyBinding','ctrl+meta+alt+space'));

	$modal=$t->nonPublic(PanelRenderer::class)->invoke('actionModalAttributes',array_replace($meta,[
		'modal'=>true,'requires_confirmation'=>true,'modal_heading'=>'Approve?','modal_description'=>'Confirm','modal_width'=>'lg',
		'modal_submit_label'=>'Yes','modal_cancel_label'=>'No','has_handler'=>true,'tone'=>'danger','modal_back'=>true,'meta'=>['modal_style'=>'drawer'],
	]),true,['Reason'=>'Required', ['label'=>'State','value'=>'Ready'],['label'=>'','value'=>'']]);
	$t->contains('data-dp-panel-modal-stack="push"',$modal);
	$t->contains('data-dp-panel-action-content',$modal);
	$t->contains('data-dp-panel-action-tone="danger"',$modal);
	$t->contains('data-dp-panel-action-modal',$t->nonPublic(PanelRenderer::class)->invoke('resourceModalAttributes','edit','Edit','Description','xl','drawer',true,'Save','Cancel','warning'));
	$t->contains('data-dp-panel-action-content',$t->nonPublic(PanelRenderer::class)->invoke('contentModalAttributes','view','View','Description','<b>Body</b>'));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('modalContentHtml',null));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('modalContentHtml',[]));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('modalContentHtml',' '));
	$t->contains('modal-generated',$t->nonPublic(PanelRenderer::class)->invoke('modalContentHtml',[['label'=>'Data','value'=>['nested'=>true]]]));

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('tableDataLabelAttr',[]));
	$t->contains('data-label="Name"',$t->nonPublic(PanelRenderer::class)->invoke('tableDataLabelAttr',['label'=>'Name']));
	$t->contains('title=',$t->nonPublic(PanelRenderer::class)->invoke('textCellHtml','Long value',['meta'=>['truncate'=>5]]));
	$t->contains('danger',$t->nonPublic(PanelRenderer::class)->invoke('badgeCellHtml','Rejected','rejected',['meta'=>['tones'=>['rejected'=>'danger']]]));
	$t->contains('mailto:',$t->nonPublic(PanelRenderer::class)->invoke('linkCellHtml','mail@example.test','mailto:mail@example.test',[],[]));
	$t->contains('Order label',$t->nonPublic(PanelRenderer::class)->invoke('linkCellHtml','fallback','/orders/1',['meta'=>['label_column'=>'label']],['label'=>'Order label']));
	$t->contains('fallback',$t->nonPublic(PanelRenderer::class)->invoke('linkCellHtml','fallback','',[],[]));
	$t->same('<b>Primary</b>',$t->nonPublic(PanelRenderer::class)->invoke('linkedCellPrimaryHtml','<b>Primary</b>','javascript:bad'));
	$t->contains('target="_blank"',$t->nonPublic(PanelRenderer::class)->invoke('linkedCellPrimaryHtml','Primary','/safe',true));
	$t->same('https://example.test',$t->nonPublic(PanelRenderer::class)->invoke('hrefValue','https://example.test'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('hrefValue',' '));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('hrefValue','/relative'));
	$t->same('mailto:user@example.test',$t->nonPublic(PanelRenderer::class)->invoke('emailHref','user@example.test'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('emailHref','bad'));
	$t->same('Lo',$t->nonPublic(PanelRenderer::class)->invoke('truncateCellValue','Long',['meta'=>['truncate'=>2]]));
	$t->same('Long...',$t->nonPublic(PanelRenderer::class)->invoke('truncateCellValue','Longer text',['meta'=>['truncate'=>7]]));
	$t->same('neutral',$t->nonPublic(PanelRenderer::class)->invoke('badgeTone','x',['meta'=>['tone'=>'invalid']]));
	$t->same('success',$t->nonPublic(PanelRenderer::class)->invoke('safeTone',' SUCCESS '));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('safeWidgetUrl','javascript:bad'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('safeWidgetUrl','//example.test'));
	$t->same('mailto:user@example.test',$t->nonPublic(PanelRenderer::class)->invoke('safeWidgetUrl','mailto:user@example.test'));
	$t->same('/local',$t->nonPublic(PanelRenderer::class)->invoke('safeWidgetUrl','/local'));

	$t->same('Primary',$t->nonPublic(PanelRenderer::class)->invoke('cellStackHtml','Primary'));
	$t->contains('cell-tooltip',$t->nonPublic(PanelRenderer::class)->invoke('cellStackHtml','Primary','','','','','neutral','Details'));
	$t->contains('cell-copy',$t->nonPublic(PanelRenderer::class)->invoke('cellStackHtml','Primary','Description','copy','','icon','success','Tooltip'));
	$record=['name'=>'Alice','status'=>'active','url'=>'https://example.test','email'=>'alice@example.test'];
	$t->contains('Alice',$t->nonPublic(PanelRenderer::class)->invoke('cellHtml',Column::make('name')->truncate(4),$record));
	$t->contains('badge-success',$t->nonPublic(PanelRenderer::class)->invoke('cellHtml',Column::make('status','badge')->badge(['active'=>'success']),$record));
	$t->contains('https://example.test',$t->nonPublic(PanelRenderer::class)->invoke('cellHtml',Column::make('url','url'),$record));
	$t->contains('mailto:',$t->nonPublic(PanelRenderer::class)->invoke('cellHtml',Column::make('email','email'),$record));
	$t->contains('cell-stack',$t->nonPublic(PanelRenderer::class)->invoke('cellHtml',Column::make('name')->description('Person')->copyable()->copyMessage('Copied')->tooltip('Tooltip')->icon('user')->color('info')->linkTo('/people/1',true),$record));
	PanelComponentRegistry::registerColumnType('custom',static fn(): string=>'<mark>Custom</mark>');
	$t->contains('<mark>Custom</mark>',$t->nonPublic(PanelRenderer::class)->invoke('cellHtml',Column::make('name','custom')->linkTo('/custom'),$record));
})->tag('panel','renderer','shell','coverage')->group('framework-coverage');
