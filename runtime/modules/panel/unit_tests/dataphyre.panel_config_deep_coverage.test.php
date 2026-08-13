<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelConfig;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTheme;
use Dataphyre\Panel\PanelThemePreset;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel'], [
	'constants'=>['DP_PANEL_CFG'=>['legacy_key'=>'legacy-value']],
]);

test('panel config resolves context themes labels and bounded layout modes',static function(Context $t): void {
	$t->same('legacy-value',PanelConfig::config('legacy_key'));
	$t->same('fallback',PanelConfig::config('missing','fallback'));
	PanelContext::run(['legacy_key'=>'context-value'],static function()use($t): void {
		$t->same('context-value',PanelConfig::config('legacy_key'));
	});

	PanelContext::run(['panel_label'=>' ','home_label'=>' ','navigation_label'=>'Workspace'],static function()use($t): void {
		$t->same('Dataphyre Panel',PanelConfig::label());
		$t->same('Panel',PanelConfig::homeLabel());
	});
	PanelContext::run(['panel_label'=>' Operations ','home_label'=>' Home '],static function()use($t): void {
		$t->same('Operations',PanelConfig::label());
		$t->same('Home',PanelConfig::homeLabel());
	});

	$theme=PanelTheme::make('direct')->brandName('Direct Brand');
	PanelContext::run(['theme'=>$theme,'__panel_manager'=>PanelManager::instance()],static function()use($t,$theme): void {
		$t->same($theme,PanelConfig::theme());
		$t->same(PanelManager::instance(),PanelConfig::manager());
		$t->same('Direct Brand',PanelConfig::brandName());
	});
	PanelContext::run(['theme'=>PanelThemePreset::make('preset')->brand(['name'=>'Preset Brand'])],static function()use($t): void {
		$t->same('default',PanelConfig::theme()->name());
		$t->same('Preset Brand',PanelConfig::brandName());
	});
	PanelContext::run(['theme'=>['name'=>'array-theme']],static function()use($t): void {
		$t->same('array-theme',PanelConfig::theme()->name());
	});
	PanelContext::run(['theme'=>' string theme '],static function()use($t): void {
		$t->same('string_theme',PanelConfig::theme()->name());
	});
	PanelContext::run(['theme'=>' ','panel_label'=>'Fallback Brand','__panel_manager'=>new stdClass()],static function()use($t): void {
		$t->same(PanelManager::instance(),PanelConfig::manager());
		$t->same('Fallback Brand',PanelConfig::brandName());
	});

	PanelContext::run([
		'global_search_parameter'=>' order search ','tenant_parameter'=>' account id ',
		'navigation_layout'=>'horizontal','navigation_mode'=>'docked','header_mode'=>'edge','footer_mode'=>'overlay',
	],static function()use($t): void {
		$t->same('order_search',PanelConfig::globalSearchParameter());
		$t->same('account_id',PanelConfig::tenantParameter());
		$t->same('horizontal',PanelConfig::navigationLayout());
		$t->same('docked',PanelConfig::navigationMode());
		$t->same('edge',PanelConfig::headerMode());
		$t->same('overlay',PanelConfig::footerMode());
	});
	PanelContext::run([
		'global_search_parameter'=>' ','tenant_parameter'=>' ','navigation_layout'=>'invalid',
		'navigation_mode'=>'invalid','header_mode'=>'invalid','footer_mode'=>'invalid',
	],static function()use($t): void {
		$t->same('search',PanelConfig::globalSearchParameter());
		$t->same('tenant',PanelConfig::tenantParameter());
		$t->same('sidebar',PanelConfig::navigationLayout());
		$t->same('floating',PanelConfig::navigationMode());
		$t->same('floating',PanelConfig::headerMode());
		$t->same('floating',PanelConfig::footerMode());
	});

	foreach([
		[['mobile_navigation_mode'=>'hamburger'],'drawer'],
		[['mobile_navigation_mode'=>'disabled'],'none'],
		[['mobile_navigation_mode'=>'chips'],'chips'],
		[['mobile_navigation_mode'=>'invalid'],'drawer'],
	] as [$config,$expected]){
		PanelContext::run($config,static function()use($t,$expected): void {
			$t->same($expected,PanelConfig::mobileNavigationMode('drawer'));
		});
	}
	$t->same('chips',PanelConfig::mobileNavigationMode('invalid'));
	foreach([
		[['mobile_sidebar_layout'=>'grid'],'split'],
		[['mobile_sidebar_layout'=>'split'],'split'],
		[['mobile_sidebar_layout'=>'invalid'],'single'],
	] as [$config,$expected]){
		PanelContext::run($config,static function()use($t,$expected): void {
			$t->same($expected,PanelConfig::mobileSidebarLayout());
		});
	}

	$animations=[
		[['sidebar_animation'=>'off','sidebar_animation_duration'=>-5,'sidebar_animation_easing'=>'linear'],['none',0,'linear']],
		[['sidebar_animation'=>'yes','sidebar_animation_duration'=>3000,'sidebar_animation_easing'=>'in'],['slide',2000,'cubic-bezier(.4,0,1,1)']],
		[['sidebar_animation'=>'slidefade','sidebar_animation_easing'=>'out'],['slide_fade',180,'cubic-bezier(0,0,.2,1)']],
		[['sidebar_animation'=>'zoom','sidebar_animation_easing'=>'standard'],['scale',180,'cubic-bezier(.4,0,.2,1)']],
		[['sidebar_animation'=>'fade','sidebar_animation_easing'=>'snappy'],['fade',180,'cubic-bezier(.2,.8,.2,1)']],
		[['sidebar_animation'=>'invalid','sidebar_animation_easing'=>'invalid'],['none',180,'ease']],
	];
	foreach($animations as [$config,$expected]){
		PanelContext::run($config,static function()use($t,$expected): void {
			$actual=PanelConfig::sidebarAnimation();
			$t->same($expected[0],$actual['type']);
			$t->same($expected[1],$actual['duration']);
			$t->same($expected[2],$actual['easing']);
		});
	}
})->tag('panel','config','coverage')->group('framework-coverage');

test('panel config normalizes presentation flags and modal actions',static function(Context $t): void {
	foreach([
		['content_spacing',['flush','invalid'],['flush','normal']],
		['commandbar_bottom_mode',['inline','invalid'],['inline','stacked']],
		['table_pagination_visibility',['hide_single','invalid'],['hide_single','always']],
	] as [$key,$values,$expected]){
		foreach($values as $index=>$value){
			PanelContext::run([$key=>$value],static function()use($t,$key,$expected,$index): void {
				$actual=match($key){
					'content_spacing'=>PanelConfig::contentSpacing(),
					'commandbar_bottom_mode'=>PanelConfig::commandbarBottomMode(),
					default=>PanelConfig::tablePaginationVisibility(),
				};
				$t->same($expected[$index],$actual);
			});
		}
	}
	PanelContext::run(['custom_page_layout'=>'plain'],static function()use($t): void { $t->same('flow',PanelConfig::customPageLayout()); });
	PanelContext::run(['custom_page_layout'=>'invalid'],static function()use($t): void { $t->same('carded',PanelConfig::customPageLayout()); });
	PanelContext::run(['table_header_controls'=>'enabled'],static function()use($t): void { $t->same('compact',PanelConfig::tableHeaderControlsMode()); });
	PanelContext::run(['table_header_controls'=>'compact'],static function()use($t): void { $t->same('compact',PanelConfig::tableHeaderControlsMode()); });
	PanelContext::run(['table_header_controls'=>'invalid'],static function()use($t): void { $t->same('none',PanelConfig::tableHeaderControlsMode()); });

	foreach([
		['off','never'],['show','always'],['record','surface'],['surface','surface'],['invalid','always'],
	] as [$configured,$expected]){
		PanelContext::run(['modal_expand_button'=>$configured],static function()use($t,$expected): void {
			$t->same($expected,PanelConfig::modalExpandMode());
		});
	}
	PanelContext::run(['modal_chrome_actions'=>'open copy_url refresh expand open'],static function()use($t): void {
		$t->same(['open_full','copy_link','refresh','expand'],PanelConfig::modalChromeActions());
	});
	PanelContext::run(['modal_chrome_actions'=>['full_page','copylink','unknown']],static function()use($t): void {
		$t->same(['open_full','copy_link'],PanelConfig::modalChromeActions());
	});
	PanelContext::run(['modal_chrome_actions'=>new stdClass()],static function()use($t): void {
		$t->same([],PanelConfig::modalChromeActions());
	});
	PanelContext::run(['modal_chrome_actions'=>['unknown']],static function()use($t): void {
		$t->same([],PanelConfig::modalChromeActions());
	});
})->tag('panel','config','coverage')->group('framework-coverage');

test('panel config resolves boolean feature plugin tenant and asset settings',static function(Context $t): void {
	foreach([
		[['flag'=>true],true],
		[['flag'=>false],false],
		[['flag'=>2],true],
		[['flag'=>0],false],
		[['flag'=>0.0],false],
		[['flag'=>'sticky'],true],
		[['flag'=>'static'],false],
		[['flag'=>'unknown','fallback'=>'yes'],true],
		[[],false],
	] as [$config,$expected]){
		PanelContext::run($config,static function()use($t,$expected): void {
			$t->same($expected,$t->nonPublic(PanelConfig::class)->invoke('boolConfig',['flag','fallback'],false));
		});
	}
	PanelContext::run([
		'navigation_sticky'=>'on','header_sticky'=>'off','footer_sticky'=>1,
		'table_density_controls'=>false,'resource_import_export'=>false,'resource_exports'=>true,
		'home_navigation'=>false,
	],static function()use($t): void {
		$t->isTrue(PanelConfig::navigationSticky());
		$t->isFalse(PanelConfig::headerSticky());
		$t->isTrue(PanelConfig::footerSticky());
		$t->isFalse(PanelConfig::tableDensityControlsEnabled());
		$t->isFalse(PanelConfig::resourceImportsEnabled());
		$t->isTrue(PanelConfig::resourceExportsEnabled());
		$t->isFalse(PanelConfig::homeNavigationEnabled());
	});
	PanelContext::run(['navigation_features'=>[
		'search'=>false,'recent'=>false,'pinning'=>false,'collapse'=>false,'collapse_exclusive'=>true,
	]],static function()use($t): void {
		$t->isFalse(PanelConfig::navigationSearchEnabled());
		$t->isFalse(PanelConfig::recentNavigationEnabled());
		$t->isFalse(PanelConfig::pinnedNavigationEnabled());
		$t->isFalse(PanelConfig::collapsibleNavigationEnabled());
		$t->isTrue(PanelConfig::exclusiveNavigationCollapseEnabled());
	});
	PanelContext::run(['navigation_features'=>'invalid'],static function()use($t): void {
		$t->isTrue(PanelConfig::navigationSearchEnabled());
	});
	PanelContext::run(['navigation_features'=>[]],static function()use($t): void {
		$t->isTrue(PanelConfig::recentNavigationEnabled());
	});

	$request=PanelRequest::fromArray(['tenant'=>'request-tenant']);
	PanelContext::run(['__panel_request'=>$request],static function()use($t): void {
		$t->same('request-tenant',PanelConfig::currentTenantKey());
	});
	PanelContext::run(['tenant_resolver'=>static fn(?PanelRequest $request): string=>$request===null ? 'resolver-tenant' : 'wrong'],static function()use($t): void {
		$t->same('resolver-tenant',PanelConfig::currentTenantKey());
	});
	PanelContext::run(['tenant_resolver'=>static fn(): string=>' '],static function()use($t): void {
		$t->same(null,PanelConfig::currentTenantKey());
	});
	PanelContext::run(['tenant'=>' configured '],static function()use($t): void {
		$t->same('configured',PanelConfig::currentTenantKey());
	});
	$get=$t->globalMap('_GET')->replace(['tenant'=>'get-tenant']);
	$post=$t->globalMap('_POST')->clear();
	$t->same('get-tenant',PanelConfig::currentTenantKey());
	$get->clear();
	$post->replace(['tenant'=>'post-tenant']);
	$t->same('post-tenant',PanelConfig::currentTenantKey());
	$post->replace(['tenant'=>[]]);
	$t->same(null,PanelConfig::currentTenantKey());

	PanelContext::run(['plugin_config'=>['alpha'=>['enabled'=>true],'bad'=>'value']],static function()use($t): void {
		$t->same(['alpha'=>['enabled'=>true],'bad'=>'value'],PanelConfig::pluginConfig());
		$t->same(['enabled'=>true],PanelConfig::pluginConfig(' alpha '));
		$t->same([],PanelConfig::pluginConfig('bad'));
	});
	PanelContext::run(['plugin_config'=>'invalid','plugin_ids'=>'invalid'],static function()use($t): void {
		$t->same([],PanelConfig::pluginConfig());
		$t->same([],PanelConfig::pluginIds());
	});
	PanelContext::run(['plugin_ids'=>['one','',2]],static function()use($t): void {
		$t->same(['one','2'],PanelConfig::pluginIds());
	});

	PanelContext::run(['asset_url_builder'=>static fn(string $asset): string=>'https://assets.test/'.$asset],static function()use($t): void {
		$t->same('https://assets.test/panel.css',PanelConfig::assetUrl('../panel.css'));
	});
	PanelContext::run(['asset_url_builder'=>static fn(): string=>' ','url_builder'=>static fn(string $target): string=>'/panel/'.$target],static function()use($t): void {
		$t->same('/panel/__assets/panel.css',PanelConfig::assetUrl('folder/panel.css'));
	});
	PanelContext::run(['upload_url'=>'https://uploads.test/base/'],static function()use($t): void {
		$t->same('https://uploads.test/base',PanelConfig::uploadUrl());
		$t->same('https://uploads.test/base/file.txt',PanelConfig::uploadUrl('/file.txt'));
	});
	PanelContext::run(['url_builder'=>static fn(string $target): string=>'/panel/'.$target],static function()use($t): void {
		$t->same('/panel/__uploads/path/file.txt',PanelConfig::uploadUrl('/path/file.txt'));
	});
})->tag('panel','config','coverage')->group('framework-coverage');

test('panel config builds safe local urls and propagates query state',static function(Context $t): void {
	$get=$t->globalMap('_GET')->clear();
	$t->globalMap('_POST')->clear();
	$cookie=$t->globalMap('_COOKIE')->clear();
	$server=$t->globalMap('_SERVER')->merge([
		'REQUEST_URI'=>'/admin/panel?old=1',
		'SCRIPT_NAME'=>'/index.php',
	]);

	PanelContext::run(['url_builder'=>static fn(string $target,array $query): string=>'/custom/'.$target.'?'.http_build_query($query)],static function()use($t): void {
		$t->same('/custom/orders/edit/9?page=2',PanelConfig::url('orders/edit/9',['page'=>2,'blank'=>'']));
	});
	PanelContext::run(['url_builder'=>static fn(): string=>' '],static function()use($t): void {
		$url=PanelConfig::url('orders/show/A%20B',['page'=>1]);
		$t->contains('/admin/panel?',$url);
		$t->contains('resource=orders',$url);
		$t->contains('record=A+B',$url);
	});
	PanelContext::run(['url_builder'=>static fn(string $target): string=>'/'.$target],static function()use($t): void {
		$t->same('/orders/edit',PanelConfig::resourceUrl(' Orders ','edit'));
		$t->same('/orders',PanelConfig::resourceUrl(Resource::make('orders')));
	});
	$t->isTrue(PanelConfig::isPanelPath('/local/path'));
	$t->isTrue(PanelConfig::isPanelPath("/local\n/path"));
	$t->isFalse(PanelConfig::isPanelPath(''));
	$t->isFalse(PanelConfig::isPanelPath('//example.test/path'));
	$t->isFalse(PanelConfig::isPanelPath('https://example.test'));

	$t->same(['keep'=>1],$t->nonPublic(PanelConfig::class)->invoke('targetQuery','',['keep'=>1,'resource'=>'old','operation'=>'show','record'=>1,'relation'=>'x','action'=>'y']));
	$t->same('orders',$t->nonPublic(PanelConfig::class)->invoke('targetQuery','orders',[])['resource']);
	$plain=$t->nonPublic(PanelConfig::class)->invoke('targetQuery','orders/show/A%20B',[]);
	$t->same('show',$plain['operation']);
	$t->same('A B',$plain['record']);
	$action=$t->nonPublic(PanelConfig::class)->invoke('targetQuery','orders/action/review/9',[]);
	$t->same('review',$action['action']);
	$t->same('9',$action['record']);
	$relation=$t->nonPublic(PanelConfig::class)->invoke('targetQuery','orders/relation/9/line-items',[]);
	$t->same('9',$relation['record']);
	$t->same('line-items',$relation['relation']);

	$t->same(['nested'=>['value'=>1],'zero'=>0],$t->nonPublic(PanelConfig::class)->invoke('filterQuery',['empty'=>'','null'=>null,'nested'=>['blank'=>'','value'=>1],'zero'=>0,'false'=>false]));
	$t->contains('/admin/panel',$t->nonPublic(PanelConfig::class)->invoke('currentMountUrl',['page'=>2]));
	$server->put('REQUEST_URI','');
	$t->contains('/index.php',$t->nonPublic(PanelConfig::class)->invoke('currentMountUrl',[]));
	$server->put('SCRIPT_NAME','');
	$t->same('/',$t->nonPublic(PanelConfig::class)->invoke('currentMountUrl',[]));

	PanelContext::run(['tenant'=>'tenant-a'],static function()use($t): void {
		$t->same(['tenant'=>'explicit'],$t->nonPublic(PanelConfig::class)->invoke('withTenantQuery',['tenant'=>'explicit']));
		$t->same(['tenant'=>'tenant-a'],$t->nonPublic(PanelConfig::class)->invoke('withTenantQuery',[]));
	});
	PanelContext::run(['tenant'=>' '],static function()use($t): void {
		$t->same([],$t->nonPublic(PanelConfig::class)->invoke('withTenantQuery',[]));
	});

	PanelContext::run(['theme_selector'=>false],static function()use($t): void {
		$t->same(['page'=>1],$t->nonPublic(PanelConfig::class)->invoke('withThemePresetQuery',['page'=>1]));
	});
	$get->replace(['panel_theme'=>'glass']);
	PanelContext::run(['theme_selector'=>true,'theme_selector_presets'=>['glass'=>[],'flat'=>[]]],static function()use($t): void {
		$t->same(['panel_theme'=>'explicit'],$t->nonPublic(PanelConfig::class)->invoke('withThemePresetQuery',['panel_theme'=>'explicit']));
		$t->same(['panel_theme'=>'glass'],$t->nonPublic(PanelConfig::class)->invoke('withThemePresetQuery',[]));
		$t->same('glass',$t->nonPublic(PanelConfig::class)->invoke('activeThemePreset','panel_theme'));
	});
	$get->replace(['panel_theme'=>'unknown']);
	PanelContext::run(['theme_selector'=>true,'theme_selector_presets'=>['glass'=>[]]],static function()use($t): void {
		$t->same([],$t->nonPublic(PanelConfig::class)->invoke('withThemePresetQuery',[]));
		$t->same('',$t->nonPublic(PanelConfig::class)->invoke('activeThemePreset','panel_theme'));
	});
	$get->replace(['preset'=>'flat']);
	PanelContext::run(['theme_selector'=>true,'theme_selector_parameter'=>' ','theme_selector_presets'=>[]],static function()use($t): void {
		$t->same(['panel_theme'=>'flat'],$t->nonPublic(PanelConfig::class)->invoke('withThemePresetQuery',[]));
	});
	$get->clear();
	$cookie->replace(['dataphyre_panel_theme_preset'=>'glass']);
	PanelContext::run(['theme_selector_presets'=>'invalid'],static function()use($t): void {
		$t->same('glass',$t->nonPublic(PanelConfig::class)->invoke('activeThemePreset','panel_theme'));
	});
	$cookie->clear();
	$t->same('',$t->nonPublic(PanelConfig::class)->invoke('activeThemePreset','panel_theme'));
})->tag('panel','config','coverage')->group('framework-coverage');

test('panel config renders extension hooks across callable shapes',static function(Context $t): void {
	$t->same('',PanelConfig::renderHook('   '));
	$t->same('',PanelConfig::renderHook('missing'));
	$t->same('footer.after',$t->nonPublic(PanelConfig::class)->invoke('normalizeHookName',' Footer:After '));
	PanelContext::run(['render_hooks'=>'invalid'],static function()use($t): void {
		$t->same([],$t->nonPublic(PanelConfig::class)->invoke('renderHookRenderers','footer'));
	});

	$arrayCallable=new class {
		public function render(array $context,string $hook): string { return '|array-'.$hook.'-'.$context['custom']; }
	};
	$invokable=new class {
		public function __invoke(array $context): string { return '|invokable-'.$context['custom']; }
	};
	$dynamic=new class {
		public function __call(string $name,array $arguments): string { return '|dynamic-'.$name.'-'.$arguments[0]['custom']; }
	};
	$stringable=new class implements Stringable {
		public function __toString(): string { return '|stringable'; }
	};
	$hooks=[
		'*'=>['|wildcard',static fn(): string=>'|zero'],
		'footer.after'=>[
			static fn(array $context): string=>'|one-'.$context['custom'],
			static fn(array $context,string $hook): string=>'|two-'.$hook,
			static fn(array $context,string $hook,PanelManager $manager): string=>'|three-'.$manager::class,
			static fn(mixed ...$arguments): string=>'|variadic-'.count($arguments),
			[$arrayCallable,'render'],$invokable,[$dynamic,'missing'],$stringable,12,null,
			['unsupported'],new stdClass(),
			static function(): never { throw new RuntimeException('hook failed'); },
		],
	];
	PanelContext::run(['render_hooks'=>$hooks,'tenant'=>'tenant-a','panel_label'=>'Panel Label'],static function()use($t,$arrayCallable,$invokable,$dynamic): void {
		$renderers=$t->nonPublic(PanelConfig::class)->invoke('renderHookRenderers','footer.after');
		$t->same(15,count($renderers));
		$html=PanelConfig::renderHook('footer:after',['custom'=>'value']);
		$t->contains('|wildcard',$html);
		$t->contains('|one-value',$html);
		$t->contains('|two-footer.after',$html);
		$t->contains('|three-Dataphyre\\Panel\\PanelManager',$html);
		$t->contains('|variadic-3',$html);
		$t->contains('|array-footer.after-value',$html);
		$t->contains('|invokable-value',$html);
		$t->contains('|dynamic-missing-value',$html);
		$t->contains('|stringable12',$html);
		$context=['custom'=>'direct'];
		$t->contains('|array-hook-direct',$t->nonPublic(PanelConfig::class)->invoke('callRenderHook',[$arrayCallable,'render'],'hook',$context));
		$t->contains('|invokable-direct',$t->nonPublic(PanelConfig::class)->invoke('callRenderHook',$invokable,'hook',$context));
		$t->contains('|dynamic-missing-direct',$t->nonPublic(PanelConfig::class)->invoke('callRenderHook',[$dynamic,'missing'],'hook',$context));
	});
	PanelContext::run(['render_hooks'=>['footer'=>'static']],static function()use($t): void {
		$t->same('static',PanelConfig::renderHook('footer'));
	});
})->tag('panel','config','coverage')->group('framework-coverage');
