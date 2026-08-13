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
use Dataphyre\Panel\FormSection;
use Dataphyre\Panel\Infolist;
use Dataphyre\Panel\InfolistEntry;
use Dataphyre\Panel\NavigationItem;
use Dataphyre\Panel\PageTable;
use Dataphyre\Panel\PanelCommand;
use Dataphyre\Panel\PanelCompatibilityMatrix;
use Dataphyre\Panel\PanelDataJob;
use Dataphyre\Panel\PanelDocumentationCatalog;
use Dataphyre\Panel\PanelDocumentationEntry;
use Dataphyre\Panel\PanelInMemoryNotificationAdapter;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelLocalization;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelMediaCollection;
use Dataphyre\Panel\PanelMediaItem;
use Dataphyre\Panel\PanelMediaLibrary;
use Dataphyre\Panel\PanelNotificationInbox;
use Dataphyre\Panel\PanelPackageInstallPlan;
use Dataphyre\Panel\PanelPackageManifest;
use Dataphyre\Panel\PanelPackageRepository;
use Dataphyre\Panel\PanelPackageRollbackPlan;
use Dataphyre\Panel\PanelPackageTemplate;
use Dataphyre\Panel\PanelPackageTrustPolicy;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelPlugin;
use Dataphyre\Panel\PanelProvider;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTheme;
use Dataphyre\Panel\PanelThemeLibrary;
use Dataphyre\Panel\PanelThemePreset;
use Dataphyre\Panel\RelationManager;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\ResourceForm;
use Dataphyre\Panel\Schema;
use Dataphyre\Panel\SchemaComponent;
use Dataphyre\Panel\SchemaLifecycle;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Panel\TableGroup;
use Dataphyre\Panel\TableSummary;
use Dataphyre\Panel\TableView;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',['enabled'=>['core'=>true,'panel'=>true,'mvc'=>true,'access'=>true,'permission'=>true],'disabled'=>[],'core_implicit'=>true]);
}
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
require_once $modulesRoot.'/core/kernel/core_functions.php';
if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; function tracelog(mixed ...$arguments): void {}');
}
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['panel','mvc','access','permission']);

final class DpPanelInstanceProvider implements PanelProvider {
	public function panel(PanelInstance $panel): PanelInstance {
		return $panel->config('provided',true);
	}
}

final class DpPanelInstanceNotProvider {}

final class DpPanelInstancePlugin implements PanelPlugin {
	public function id(): string { return 'deep-plugin'; }
	public function label(): string { return ' Deep Plugin '; }
	public function version(): string { return ' 1.2.3 '; }
	public function description(): array { return ['ignored']; }
	public function register(PanelInstance $panel): void { $panel->config('plugin_registered',true); }
	public function boot(PanelInstance $panel): void { $panel->config('plugin_booted',true); }
}

final class DpPanelInstanceEmptyPlugin implements PanelPlugin {
	public function id(): string { return ' '; }
	public function register(PanelInstance $panel): void {}
	public function boot(PanelInstance $panel): void {}
}

final class DpPanelInstanceNotPlugin {}

test('panel instance factories localization and theme helpers cover every delegated surface',static function(Context $t): void {
	$panel=PanelInstance::make('Operations Center',['locale'=>'en','fallbackLocale'=>'fr']);
	$t->same('operations_center',$panel->name());
	$t->instanceOf(PanelManager::class,$panel->manager());
	$t->instanceOf(\Dataphyre\Panel\PanelTestHarness::class,$panel->test());
	$t->instanceOf(\Dataphyre\Panel\PanelScaffolder::class,$panel->scaffold());
	$t->instanceOf(PanelDataJob::class,$panel->dataJob('sync','daily'));
	$t->instanceOf(PanelDataJob::class,$panel->importJob());
	$t->instanceOf(PanelDataJob::class,$panel->exportJob());
	$t->instanceOf(PanelMediaLibrary::class,$panel->mediaLibrary([['name'=>'images']]));
	$collection=$panel->mediaCollection('Images');
	$t->instanceOf(PanelMediaCollection::class,$collection);
	$t->instanceOf(PanelMediaItem::class,$panel->mediaItem(['name'=>'photo.jpg','path'=>'/photo.jpg'],$collection,['alt'=>'Photo']));
	$adapter=$panel->notificationAdapter([],['database','mail']);
	$t->instanceOf(PanelInMemoryNotificationAdapter::class,$adapter);
	$t->instanceOf(PanelNotificationInbox::class,$panel->notificationInbox(['Hello']));
	$t->instanceOf(PanelNotificationInbox::class,$panel->notificationInboxUsing($adapter,['World']));
	$t->instanceOf(\Dataphyre\Panel\PanelInboxNotification::class,$panel->inboxNotification('Notice','operator',['source'=>'test']));
	$t->instanceOf(\Dataphyre\Panel\PanelAccessibilityAudit::class,$panel->accessibilityAudit('<main><h1>Title</h1></main>',['viewport'=>'desktop']));
	$t->instanceOf(\Dataphyre\Panel\PanelRegressionSuite::class,$panel->regressionSuite('smoke'));
	$t->instanceOf(PanelDocumentationCatalog::class,$panel->documentationCatalog([['id'=>'intro','title'=>'Intro']]));
	$t->instanceOf(PanelDocumentationEntry::class,$panel->documentationEntry('setup','Setup'));

	$localization=$panel->localization();
	$t->instanceOf(PanelLocalization::class,$localization);
	$t->same('en',$localization->locale());
	$panel->localization(['locale'=>'de','fallback_locale'=>'en'])->translations('de',['welcome'=>'Hallo :name']);
	$t->same('Hallo Ada',$panel->trans('welcome',['name'=>'Ada']));
	$t->same('Fallback',$panel->t('missing',[],null,'Fallback'));
	$updated=$panel->localization(null,'es','de');
	$t->instanceOf(PanelLocalization::class,$updated);
	$t->same('es',$updated->locale());

	$theme=PanelTheme::make('deep-theme');
	$t->same($theme,$panel->theme($theme));
	$t->same($theme,$panel->theme());
	$panel->config('theme',['name'=>'array-theme']);
	$t->same('array-theme',$panel->theme()->name());
	$panel->config('theme','string-theme');
	$t->same('string-theme',$panel->theme()->name());
	$blank=PanelInstance::make();
	$t->same('default',$blank->theme()->name());
	$preset=PanelThemePreset::make('deep-preset')->colors(['primary'=>'#224466']);
	$t->instanceOf(PanelTheme::class,$panel->theme($preset));
	$t->instanceOf(PanelTheme::class,$panel->theme(['name'=>'inline-theme']));
	$t->instanceOf(PanelTheme::class,$panel->theme('named-or-new'));
	$t->notEmpty($panel->palette('#336699'));
	$t->instanceOf(PanelThemePreset::class,$panel->themePreset(['name'=>'from-array']));
	$t->instanceOf(PanelThemePreset::class,$panel->registerThemePreset(['name'=>'registered-preset']));
	$t->instanceOf(PanelTheme::class,$panel->registerTheme(['name'=>'registered-theme']));
	$t->instanceOf(PanelTheme::class,$panel->namedTheme('registered-theme'));
	$t->instanceOf(PanelThemeLibrary::class,$panel->loadThemePresets(__DIR__.'/missing-presets'));
	$t->instanceOf(PanelThemeLibrary::class,$panel->loadThemes([__DIR__.'/missing-themes']));
	$t->instanceOf(PanelThemeLibrary::class,$panel->themeLibrary());
	$t->notEmpty($panel->themeDiagnostics());
	$t->notEmpty($panel->themePreview());
	$t->notEmpty($panel->themePreview('registered-theme'));
	$t->notEmpty($panel->themePreviewHtml());
	$t->notEmpty($panel->themePreviewHtml('registered-theme',['compact'=>true]));
	$t->notEmpty($panel->themeManifest(null,['source'=>'deep'],true));
	$t->instanceOf(PanelTheme::class,$panel->themeVariant('dark',['colors'=>['primary'=>'#000000']]));
})->tag('panel','instance','coverage')->group('framework-coverage');

test('panel instance layout aliases hooks and feature branches normalize configuration',static function(Context $t): void {
	$panel=PanelInstance::make('layout');
	$panel->mobileNavigationMode('offcanvas')->mobileNavigationMode('hidden')
		->mobileSidebarLayout('grid')->sidebarAnimation(false,-50,'bad')
		->sidebarAnimation('slidefade',300,'snappy')->sidebarAnimation('zoom',2500,'linear')
		->customPageLayout('plain')->tableHeaderControls(false)->modalExpandButton(false)
		->modalExpandButton('records')->modalChromeActions('open,copy,refresh,unknown,expand')
		->navigationFeatures(['mobile'=>'drawer','pins'=>true,'collapsible'=>true,'accordion'=>true,'ignored'=>true]);
	$panel->renderHook(' ',static fn(): string=>'ignored');
	$panel->config('render_hooks',['head'=>'legacy']);
	$panel->renderHook('head','one');
	$panel->renderHooks([
		'head'=>['two',static fn(): string=>'three',new stdClass()],
		'body'=>'four',
		0=>'numeric',
	]);
	$panel->routeUrls('/admin');
	$config=$t->nonPublic($panel)->readProperty('config');
	$t->same('drawer',$config['mobile_navigation_mode']);
	$t->same('split',$config['mobile_sidebar_layout']);
	$t->same('scale',$config['sidebar_animation_type']);
	$t->same(2000,$config['sidebar_animation_duration']);
	$t->same('flow',$config['custom_page_layout']);
	$t->same('none',$config['table_header_controls']);
	$t->same('surface',$config['modal_expand_button']);
	$t->same(['open_full','copy_link','refresh','expand'],$config['modal_chrome_actions']);
	$t->same(true,$config['navigation_features']['pinning']);
	$t->same(true,$config['navigation_features']['collapse']);
	$t->same(true,$config['navigation_features']['collapse_exclusive']);
	$t->notEmpty($config['url_builder']);
	$t->notEmpty($config['asset_url_builder']);
	$t->notEmpty($config['upload_url']);
})->tag('panel','instance','coverage')->group('framework-coverage');

test('panel instance schema manifests and refresh helpers exercise branch-rich markup',static function(Context $t): void {
	$get=$t->globalMap('_GET');
	$panel=PanelInstance::make('schema');
	$t->instanceOf(Resource::class,$panel->resource('orders'));
	$t->instanceOf(PanelPage::class,$panel->page('dashboard'));
	$t->instanceOf(NavigationItem::class,$panel->navigationItem('home'));
	$t->instanceOf(NavigationItem::class,$panel->nav('settings'));
	$t->instanceOf(PanelCommand::class,$panel->command('refresh'));
	$t->instanceOf(Field::class,$panel->field('title'));
	$t->instanceOf(InfolistEntry::class,$panel->entry('title'));
	$t->instanceOf(InfolistEntry::class,$panel->textEntry('description'));
	$t->instanceOf(InfolistEntry::class,$panel->badgeEntry('status','success'));
	$t->instanceOf(InfolistEntry::class,$panel->imageEntry('avatar'));
	$t->instanceOf(FormSection::class,$panel->formSection('Details'));
	$t->instanceOf(FormSection::class,$panel->section('More'));
	$schema=$panel->schema([Field::make('name')]);
	$t->instanceOf(Schema::class,$schema);
	$t->instanceOf(SchemaLifecycle::class,$panel->schemaLifecycle($schema,['source'=>'schema']));
	$form=ResourceForm::make('create')->fields([Field::make('email')])->meta(['form'=>true]);
	$t->instanceOf(SchemaLifecycle::class,$panel->schemaLifecycle($form,['source'=>'form']));
	$t->instanceOf(SchemaLifecycle::class,$panel->schemaLifecycle([['kind'=>'field','name'=>'code']]));
	$t->notEmpty($panel->schemaManifest($schema,'create',['source'=>'deep']));
	$t->instanceOf(Infolist::class,$panel->infolist([InfolistEntry::make('name')]));
	$t->instanceOf(SchemaComponent::class,$panel->schemaComponent('field','title'));
	$t->instanceOf(SchemaComponent::class,$panel->schemaSection('General',[Field::make('title')]));
	$t->instanceOf(SchemaComponent::class,$panel->schemaTab('Profile',[Field::make('name')]));
	$t->instanceOf(SchemaComponent::class,$panel->schemaStep('Confirm',[Field::make('agree')]));

	$t->same('blank',$panel->refreshRegion(' ',static fn(): string=>'blank'));
	$markup=$panel->refreshRegion('orders',static fn(PanelInstance $instance): string=>'<b>'.$instance->name().'</b>','main',[
		'poll'=>true,'class'=>'extra','id'=>'orders','role'=>'region','aria-live'=>'polite','data-ready'=>true,
		'onclick'=>'bad','disabled'=>false,'nullable'=>null,'array'=>['bad'],0=>'ignored',
	]);
	$t->contains('data-dp-panel-refresh-interval="15000"',$markup);
	$t->contains('data-ready',$markup);
	$t->isFalse(str_contains($markup,'onclick'));
	$t->contains('schema',$markup);
	$t->contains('2000',$panel->refreshRegion('seconds','body','main',['poll'=>'2s']));
	$t->contains('3000',$panel->refreshRegion('short','body','main',['interval_ms'=>3]));
	$t->contains('dp-panel-refresh-live',$panel->liveRefreshRegion('live','body',200,'region'));
	$t->contains('island',$panel->liveRefreshIsland('live-island','body',1200));
	$t->contains('island',$panel->refreshIsland('plain-island','body'));
	$t->same('lazy blank',$panel->lazyRefreshRegion('',static fn(): string=>'lazy blank'));

	$get->forget('__panel_defer');
	$lazy=$panel->lazyRefreshRegion('sales-report','real',null,'island',[
		'prefetch_on_hover'=>true,'lazy_prefetch_delay'=>25,'manual'=>true,'when_visible'=>true,'visible_margin'=>400,
	]);
	$t->contains('data-dp-panel-refresh-prefetch="1"',$lazy);
	$t->contains('data-dp-panel-refresh-prefetch-delay="25"',$lazy);
	$t->contains('data-dp-panel-refresh-manual="1"',$lazy);
	$t->contains('data-dp-panel-refresh-visible="1"',$lazy);
	$t->contains('Load section',$lazy);
	$falseAliases=$panel->lazyRefreshRegion('passive','real',null,'island',[
		'lazy_prefetch'=>false,'prefetch_delay'=>0,'lazy_manual'=>'0','lazy_visible'=>null,'lazy_margin'=>0,
	]);
	$t->isFalse(str_contains($falseAliases,'data-dp-panel-refresh-prefetch="1"'));
	$t->contains('Loading this section',$falseAliases);
	$t->contains('custom',$panel->lazyRefreshIsland('custom','real','custom'));

	$get->put('__panel_defer',' ,unknown');
	$t->contains('placeholder',$panel->lazyRefreshRegion('target','placeholder'));
	$get->put('__panel_defer','target');
	$t->contains('lazy-loaded',$panel->lazyRefreshRegion('target','loaded'));
	$get->put('__panel_defer','*');
	$t->contains('loaded',$panel->lazyRefreshRegion('other','loaded'));
	$get->forget('__panel_defer');
	$t->same('',$panel->refreshControls(' '));
	$controls=$panel->refreshControls('orders','island',[
		'label'=>'Controls','status'=>'Running','refresh_label'=>'Reload','pause_label'=>'Stop','resume_label'=>'Continue','class'=>'custom',
	]);
	$t->contains('aria-label="Controls"',$controls);
	$t->contains('Reload',$controls);
})->tag('panel','instance','coverage')->group('framework-coverage');

test('panel instance factories manifests registrations and manager delegates stay scoped',static function(Context $t): void {
	$manager=new PanelManager();
	$panel=new PanelInstance('delegates',$manager);
	$request=PanelRequest::fromArray(['method'=>'GET','query'=>['q'=>'ord']]);
	$resource=Resource::make('orders')->fields([Field::make('title')])->queryUsing(static fn(): array=>[['id'=>1,'title'=>'Order']])->globalSearchable()->globalSearchColumns(['title']);
	$page=PanelPage::make('dashboard')->content('Dashboard');
	$widget=Widget::make('total')->value(5);
	$nav=NavigationItem::make('home')->url('/home');
	$command=PanelCommand::make('refresh')->url('/refresh');
	$panel->register($resource);
	$panel->registerMany([Resource::make('users'),['name'=>'teams']]);
	$panel->registerPage($page);
	$panel->registerPages([PanelPage::make('reports'),['name'=>'settings','content'=>'Settings']]);
	$panel->registerWidget($widget);
	$panel->registerWidgets([Widget::make('active'),['name'=>'inactive','value'=>2]]);
	$panel->registerNavigationItem($nav);
	$panel->registerNavigationItems([NavigationItem::make('reports'),['name'=>'users','url'=>'/users']]);
	$panel->registerCommand($command);
	$panel->registerCommands([PanelCommand::make('search'),['name'=>'open']]);

	$t->instanceOf(Column::class,$panel->column('title'));
	$pageTable=$panel->pageTable('recent');
	$t->instanceOf(PageTable::class,$pageTable);
	$t->same('page_table',$panel->tableManifest($pageTable,null,$request)['kind']);
	$t->notEmpty($panel->resourceManifest('orders',$request));
	$t->notEmpty($panel->resourceManifest('missing',$request));
	$t->notEmpty($panel->pageManifest('dashboard',$request));
	$t->notEmpty($panel->pageManifest('missing',$request));
	$t->instanceOf(TableFilter::class,$panel->pageFilter('status'));
	$t->instanceOf(TableFilter::class,$panel->filter('owner'));
	$t->instanceOf(TableView::class,$panel->view('active'));
	$t->instanceOf(TableSummary::class,$panel->summary('count'));
	$t->instanceOf(TableGroup::class,$panel->tableGroup('status'));
	$t->instanceOf(Action::class,$panel->action('approve'));
	$t->same('action',$panel->actionManifest('approve',null,$request,$resource)['kind']);
	$t->same('action',$panel->actionManifest(['name'=>'reject'],null,$request,$resource)['kind']);
	$t->same('action_group',$panel->actionManifest(['name'=>'bulk','actions'=>[['name'=>'archive']]],null,$request,$resource)['kind']);
	$t->instanceOf(ActionGroup::class,$panel->actionGroup('bulk',[Action::make('archive')]));
	$t->same('section',$panel->actionGroupSection('Bulk')['type']);
	$t->same('divider',$panel->actionGroupDivider()['type']);
	$t->instanceOf(RelationManager::class,$panel->relation('items'));
	$t->notEmpty($panel->relationManifest(['name'=>'items'], $request));
	$t->instanceOf(Widget::class,$panel->widget('sales'));
	$t->notEmpty($panel->widgetManifest(['name'=>'sales','value'=>10],$request,[],true));
	$t->instanceOf(Widget::class,$panel->pageWidget('page-sales'));
	$t->instanceOf(Widget::class,$panel->stat('revenue',100));
	$t->instanceOf(\Dataphyre\Panel\PanelNotification::class,$panel->notify('Saved','success','Done'));
	$t->notEmpty($panel->commandManifest('refresh',$request));
	$t->notEmpty($panel->commandManifest('unknown',$request));
	$t->notEmpty($panel->navigationManifest(null,$request));

	$t->same(3,count($panel->resources()));
	$t->same(3,count($panel->pages()));
	$t->notEmpty($panel->widgets($request));
	$t->notEmpty($panel->navigationItems());
	$t->notEmpty($panel->navigation($request));
	$t->instanceOf(\Dataphyre\Panel\PanelNavigationState::class,$panel->navigationState($request,['query'=>'home']));
	$t->notEmpty($panel->registeredCommands());
	$t->notEmpty($panel->commands($request,'re'));
	$t->instanceOf(\Dataphyre\Panel\PanelCommandState::class,$panel->commandState($request,'re'));
	$t->notEmpty($panel->globalSearch('ord',$request,5));
	$t->notEmpty($panel->searchManifest($request,'ord',5));
	$panel->tenant('tenant-1')->tenantParameter('tenant');
	$t->notEmpty($panel->tenantManifest($request));
	$t->same($resource,$panel->get('orders'));
	$t->same($page,$panel->getPage('dashboard'));
	$t->notEmpty($panel->describe());
	$t->notEmpty($panel->panelManifest($request));
	$t->instanceOf(\Dataphyre\Panel\PanelPageResult::class,$panel->dispatch(['resource'=>'dashboard','operation'=>'view']));
	$t->instanceOf(\Dataphyre\Panel\PanelPageResult::class,$panel->render('orders','index'));
	$t->notEmpty($panel->url('dashboard',['tab'=>'one']));
	$t->notEmpty($panel->resourceUrl($resource,'create',['modal'=>1]));
})->tag('panel','instance','coverage')->group('framework-coverage');

test('panel instance providers plugins packages and optional integrations cover validation paths',static function(Context $t): void {
	$panel=PanelInstance::make('extensions');
	$t->same($panel,$panel->provide(new DpPanelInstanceProvider()));
	$t->same($panel,$panel->provide(DpPanelInstanceProvider::class));
	$t->same($panel,$panel->provide(static fn(PanelInstance $instance): null=>null));
	$t->same($panel,$panel->provideMany([new DpPanelInstanceProvider(),static fn(PanelInstance $instance): PanelInstance=>$instance,42]));
	foreach([
		static fn()=>$panel->provide(''),
		static fn()=>$panel->provide(DpPanelInstanceNotProvider::class),
		static fn()=>$panel->provide(static fn(): string=>'invalid'),
	] as $invalid){
		try{
			$invalid();
			$t->fail('Expected invalid provider failure.');
		}catch(InvalidArgumentException|UnexpectedValueException){
			$t->isTrue(true);
		}
	}

	$plugin=new DpPanelInstancePlugin();
	$panel->plugin($plugin,['first'=>1])->plugin($plugin,['second'=>2]);
	$panel->plugins([
		['plugin'=>DpPanelInstancePlugin::class,'config'=>['third'=>3]],
		DpPanelInstancePlugin::class=>['fourth'=>4],
		42,
	]);
	$t->isTrue($panel->hasPlugin('deep-plugin'));
	$t->same(['first'=>1,'second'=>2,'third'=>3,'fourth'=>4],$panel->pluginConfig('deep-plugin'));
	$t->notEmpty($panel->pluginConfig());
	$t->same(['deep-plugin'],$panel->pluginIds());
	$t->same('deep-plugin',$panel->pluginManifest('deep-plugin')['id']);
	$t->same('deep-plugin',$panel->pluginManifest($plugin,['override'=>true])['id']);
	$t->notEmpty($panel->pluginManifests(['source'=>'deep']));
	foreach([
		static fn()=>$panel->plugin(new DpPanelInstanceEmptyPlugin()),
		static fn()=>$panel->plugin(''),
		static fn()=>$panel->plugin(DpPanelInstanceNotPlugin::class),
	] as $invalid){
		try{
			$invalid();
			$t->fail('Expected invalid plugin failure.');
		}catch(InvalidArgumentException){
			$t->isTrue(true);
		}
	}

	$manifest=$panel->packageManifest($plugin,['enabled'=>true]);
	$t->instanceOf(PanelPackageManifest::class,$manifest);
	$t->instanceOf(PanelCompatibilityMatrix::class,$panel->compatibilityMatrix([$manifest],['php'=>PHP_VERSION]));
	$template=$panel->packageTemplate($manifest,'Deep package');
	$t->instanceOf(PanelPackageTemplate::class,$template);
	$t->instanceOf(PanelPackageRepository::class,$panel->packageRepository([],['php'=>PHP_VERSION]));
	$t->instanceOf(PanelPackageTrustPolicy::class,$panel->packageTrustPolicy(['allow_unsigned'=>true]));
	$install=$panel->packageInstallPlan($template,__DIR__.'/package',['dry_run'=>true]);
	$t->instanceOf(PanelPackageInstallPlan::class,$install);
	$t->instanceOf(PanelPackageRollbackPlan::class,$panel->packageRollbackPlan($install,['reason'=>'test']));

	$t->same($panel,$panel->auth(false));
	$t->same($panel,$panel->permissions(false));
	$t->same($panel,$panel->permissionAdmin(false));
	if(class_exists(\Dataphyre\Access\PanelAuth::class)){
		$t->same($panel,$panel->accessAuth(['protect'=>false]));
	}
	if(class_exists(\Dataphyre\Permission\PermissionPanel::class)){
		$t->same($panel,$panel->accessPermissions([]));
		$t->same($panel,$panel->permissionAdmin(['catalog_page'=>false]));
	}
})->tag('panel','instance','coverage')->group('framework-coverage');
