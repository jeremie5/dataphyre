<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Action;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelCommand;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelLocalization;
use Dataphyre\Panel\PanelNotification;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelPlugin;
use Dataphyre\Panel\PanelProvider;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTheme;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\Schema;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','http','routing','mvc','access','permission']);

function dp_panel_facade_plugin(): PanelPlugin {
	return new class implements PanelPlugin {
		public function id(): string { return 'facade_plugin'; }
		public function register(PanelInstance $panel): void {}
		public function boot(PanelInstance $panel): void {}
	};
}

function dp_panel_facade_provider(): PanelProvider {
	return new class implements PanelProvider {
		public function panel(PanelInstance $panel): PanelInstance {
			return $panel->navigationLayout('sidebar');
		}
	};
}

function dp_panel_facade_request(string $operation='index'): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>'GET',
		'resource'=>'facade_records',
		'operation'=>$operation,
		'user'=>['id'=>91,'name'=>'Facade Coverage'],
		'tenant'=>'north',
	]);
}

test('panel facade covers surfaces packages refresh helpers navigation and routes',static function(Context $t): void {
	Panel::flush();
	$plugin=dp_panel_facade_plugin();
	$provider=dp_panel_facade_provider();

	$t->instanceOf(PanelInstance::class,Panel::make('made',['navigation_layout'=>'sidebar']));
	$surface=Panel::surface('facade',['navigation_layout'=>'top']);
	$t->instanceOf(PanelInstance::class,$surface);
	$t->same($surface,Panel::registerSurface($surface,'facade_registered'));
	$t->notEmpty(Panel::surfaces());
	$t->isTrue(is_array(Panel::bootConfigured()));
	$t->instanceOf(PanelInstance::class,Panel::default(['navigation_layout'=>'sidebar']));
	$t->instanceOf(PanelInstance::class,Panel::configure(['navigation_layout'=>'top']));
	$t->instanceOf(PanelInstance::class,Panel::provide($provider));
	$t->instanceOf(PanelInstance::class,Panel::plugin($plugin,['enabled'=>true]));
	$t->instanceOf(PanelInstance::class,Panel::plugins([$plugin]));

	$pluginManifest=Panel::pluginManifest($plugin,['enabled'=>true],['source'=>'facade']);
	$t->same('facade_plugin',$pluginManifest['id']);
	$package=Panel::packageManifest(['id'=>'facade-package','version'=>'1.0.0']);
	$t->same('facade-package',$package->id());
	$t->instanceOf(\Dataphyre\Panel\PanelCompatibilityMatrix::class,Panel::compatibilityMatrix([$package]));
	$template=Panel::packageTemplate($package,'Facade package');
	$t->instanceOf(\Dataphyre\Panel\PanelPackageTemplate::class,$template);
	$t->instanceOf(\Dataphyre\Panel\PanelPackageRepository::class,Panel::packageRepository([$package]));
	$t->instanceOf(\Dataphyre\Panel\PanelPackageTrustPolicy::class,Panel::packageTrustPolicy(['allow_unsigned'=>true]));
	$install=Panel::packageInstallPlan($template,'packages/facade',['overwrite_policy'=>'skip']);
	$t->instanceOf(\Dataphyre\Panel\PanelPackageInstallPlan::class,$install);
	$t->instanceOf(\Dataphyre\Panel\PanelPackageRollbackPlan::class,Panel::packageRollbackPlan($install,['source'=>'facade']));

	$t->instanceOf(PanelInstance::class,Panel::renderHook('facade.hook',static fn(): string=>'hooked'));
	$t->instanceOf(PanelInstance::class,Panel::renderHooks(['facade.other'=>static fn(): string=>'other']));
	$t->contains('facade-region',Panel::refreshRegion('facade-region','Region body','workspace',['class'=>'coverage']));
	$t->contains('facade-island',Panel::refreshIsland('facade-island','Island body',['data-test'=>'island']));
	$t->contains('facade-live',Panel::liveRefreshRegion('facade-live','Live body',2500,'workspace'));
	$t->contains('facade-live-island',Panel::liveRefreshIsland('facade-live-island','Live island',3000));
	$t->contains('facade-lazy',Panel::lazyRefreshRegion('facade-lazy','Lazy body','Loading','workspace'));
	$t->contains('facade-lazy-island',Panel::lazyRefreshIsland('facade-lazy-island','Lazy island','Waiting'));
	$t->contains('facade-controls',Panel::refreshControls('facade-controls','island',['refresh_label'=>'Reload']));

	foreach([
		Panel::navigationLayout('sidebar'),Panel::navigationMode('collapsible'),Panel::mobileNavigationMode('drawer'),
		Panel::sidebarMobileMode('overlay'),Panel::sidebarAnimation('fade',220,'linear'),Panel::homeNavigation(false),
		Panel::mobileSidebarLayout('stacked'),Panel::headerMode('compact'),Panel::footerMode('minimal'),
		Panel::contentSpacing('comfortable'),Panel::customPageLayout('wide'),Panel::commandbarBottomMode('floating'),
		Panel::tableHeaderControls('compact'),Panel::tablePaginationVisibility('always'),Panel::modalExpandButton('hover'),
		Panel::modalChromeActions(['close','expand']),Panel::tableDensityControls(),Panel::tableSpacingSelector(),
		Panel::resourceImports(),Panel::resourceExports(),Panel::resourceImportExport(),
		Panel::navigationFeatures(['search'=>true,'recent'=>true]),Panel::navigationSearch(),Panel::recentNavigation(),
		Panel::pinnedNavigation(),Panel::stickyNavigation(),Panel::stickyHeader(),Panel::stickyFooter(),
	] as $configured){
		$t->instanceOf(PanelInstance::class,$configured);
	}

	$t->instanceOf(\Dataphyre\Panel\PanelHost::class,Panel::host($surface,['id'=>91]));
	$t->notEmpty(Panel::routes('/facade',$surface,['name'=>'facade']));
	$t->notEmpty(Panel::mountedRoutes('/facade',$surface,['name'=>'facade.mounted']));
	$t->notEmpty(Panel::assetRoutes('/facade',['name'=>'facade.assets']));
	$t->notEmpty(Panel::uploadRoutes('/facade',['name'=>'facade.uploads']));
	$builder=Panel::routeUrlBuilder('/facade');
	$t->contains('/facade',$builder('facade_records',['page'=>2]));
	$t->contains('panel.css',Panel::routeAssetUrl('/facade','panel.css'));
	$t->contains('/facade',Panel::routeUploadUrl('/facade'));
	$t->same('panel_route_manifest',Panel::routeManifest('/facade',$surface,['name'=>'facade'])['type']);
	$t->instanceOf(PanelInstance::class,Panel::routeUrls('/facade'));

	$app=new \Dataphyre\Mvc\MvcApplication('panel_facade_routes',['controllers'=>['namespace'=>'App\\Controllers']]);
	$t->notEmpty(Panel::mvcRoutes($app->routes(),'/facade-mvc',$surface,['name'=>'facade.mvc']));
	$appMounted=new \Dataphyre\Mvc\MvcApplication('panel_facade_mounted',['controllers'=>['namespace'=>'App\\Controllers']]);
	$t->notEmpty(Panel::mvcMountedRoutes($appMounted->routes(),'/facade-mounted',$surface,['name'=>'facade.mounted']));
	$appAssets=new \Dataphyre\Mvc\MvcApplication('panel_facade_assets',['controllers'=>['namespace'=>'App\\Controllers']]);
	$t->notEmpty(Panel::mvcAssetRoutes($appAssets->routes(),'/facade-assets',['name'=>'facade.assets']));
	$appUploads=new \Dataphyre\Mvc\MvcApplication('panel_facade_uploads',['controllers'=>['namespace'=>'App\\Controllers']]);
	$t->notEmpty(Panel::mvcUploadRoutes($appUploads->routes(),'/facade-uploads',['name'=>'facade.uploads']));
})->tag('panel','facade','coverage')->group('framework-coverage');

test('panel facade covers utilities media notifications localization and themes',static function(Context $t): void {
	Panel::flush();
	$t->instanceOf(\Dataphyre\Panel\PanelManager::class,Panel::manager());
	$t->instanceOf(\Dataphyre\Panel\PanelTestHarness::class,Panel::test());
	$t->instanceOf(\Dataphyre\Panel\PanelScaffolder::class,Panel::scaffold());
	$t->instanceOf(\Dataphyre\Panel\PanelDataJob::class,Panel::dataJob('sync','facade_sync'));
	$t->instanceOf(\Dataphyre\Panel\PanelDataJob::class,Panel::importJob('facade_import'));
	$t->instanceOf(\Dataphyre\Panel\PanelDataJob::class,Panel::exportJob('facade_export'));

	$collection=Panel::mediaCollection('documents');
	$t->instanceOf(\Dataphyre\Panel\PanelMediaCollection::class,$collection);
	$t->instanceOf(\Dataphyre\Panel\PanelMediaLibrary::class,Panel::mediaLibrary([$collection]));
	$item=Panel::mediaItem(['name'=>'proof.txt','path'=>'proof.txt','size'=>12],$collection,['caption'=>'Proof']);
	$t->instanceOf(\Dataphyre\Panel\PanelMediaItem::class,$item);
	$notification=Panel::notify('Facade notice','success','Completed');
	$t->instanceOf(PanelNotification::class,$notification);
	$inbox=Panel::notificationInbox([$notification]);
	$t->instanceOf(\Dataphyre\Panel\PanelNotificationInbox::class,$inbox);
	$adapter=Panel::notificationAdapter([$notification],['database','broadcast']);
	$t->instanceOf(\Dataphyre\Panel\PanelNotificationAdapter::class,$adapter);
	$t->instanceOf(\Dataphyre\Panel\PanelNotificationInbox::class,Panel::notificationInboxUsing($adapter,[$notification]));
	$t->instanceOf(\Dataphyre\Panel\PanelInboxNotification::class,Panel::inboxNotification($notification,'operator',['priority'=>'high']));
	$t->instanceOf(\Dataphyre\Panel\PanelAccessibilityAudit::class,Panel::accessibilityAudit(PanelPageResult::html('<main><h1>Title</h1></main>'),['name'=>'facade']));
	$t->instanceOf(\Dataphyre\Panel\PanelRegressionSuite::class,Panel::regressionSuite('facade_suite'));
	$entry=Panel::documentationEntry('facade.entry','Facade entry');
	$t->instanceOf(\Dataphyre\Panel\PanelDocumentationEntry::class,$entry);
	$t->instanceOf(\Dataphyre\Panel\PanelDocumentationCatalog::class,Panel::documentationCatalog([$entry]));
	$t->instanceOf(\Dataphyre\Panel\PanelDocumentationPortal::class,Panel::documentationPortal());

	$set=Panel::localization([
		'locale'=>'fr','fallback_locale'=>'en',
		'translations'=>['fr'=>['facade'=>['hello'=>'Bonjour {name}']]],
	],'fr','en');
	$t->instanceOf(PanelInstance::class,$set);
	$t->instanceOf(PanelLocalization::class,Panel::localization(null,'fr','en'));
	$t->same('Bonjour Ada',Panel::trans('facade.hello',['name'=>'Ada'],'fr','Hello Ada'));
	$t->same('Bonjour Lin',Panel::t('facade.hello',['name'=>'Lin'],'fr','Hello Lin'));
	$t->same('Ada',Panel::evaluate(static fn(string $name): string=>$name,['name'=>'Ada'],['name']));
	$t->same('fallback',Panel::utility('missing',['value'=>1],'fallback'));

	$t->instanceOf(Resource::class,Panel::resource('facade resource'));
	$t->instanceOf(\Dataphyre\Panel\PanelPage::class,Panel::page('facade page'));
	$theme=Panel::theme(['name'=>'facade_theme','tokens'=>['surface'=>'#ffffff','text'=>'#111111']]);
	$t->instanceOf(PanelTheme::class,$theme);
	$t->isTrue(is_array(Panel::palette('#336699')));
	$preset=Panel::themePreset(['name'=>'facade_preset','tokens'=>['surface'=>'#fafafa']]);
	$t->instanceOf(\Dataphyre\Panel\PanelThemePreset::class,$preset);
	$t->instanceOf(\Dataphyre\Panel\PanelThemePreset::class,Panel::registerThemePreset($preset));
	$t->instanceOf(PanelTheme::class,Panel::registerTheme($theme));
	$t->instanceOf(PanelTheme::class,Panel::namedTheme('facade_theme'));
	$t->instanceOf(\Dataphyre\Panel\PanelThemeLibrary::class,Panel::loadThemePresets([]));
	$t->instanceOf(\Dataphyre\Panel\PanelThemeLibrary::class,Panel::loadThemes([]));
	$t->instanceOf(\Dataphyre\Panel\PanelThemeLibrary::class,Panel::themeLibrary());
	$t->isTrue(is_array(Panel::themeDiagnostics()));
	$t->isTrue(is_array(Panel::themePreview()));
	$t->isTrue(is_array(Panel::themePreview('facade_theme')));
	$t->contains('<',Panel::themePreviewHtml());
	$t->contains('<',Panel::themePreviewHtml('facade_theme',['title'=>'Facade theme']));
	$t->same('theme_manifest',Panel::themeManifest($theme,['source'=>'facade'],true)['type']);
	$t->instanceOf(PanelTheme::class,Panel::themeVariant('facade_variant',['surface'=>'#eeeeee']));

	$t->instanceOf(\Dataphyre\Panel\NavigationItem::class,Panel::navigationItem('facade_nav'));
	$t->instanceOf(\Dataphyre\Panel\NavigationItem::class,Panel::nav('facade_alias'));
	$t->same('navigation_manifest',Panel::navigationManifest(null,dp_panel_facade_request(),[],['source'=>'facade'])['type']);
	$t->instanceOf(PanelCommand::class,Panel::command('facade_command'));
	$t->same('command_manifest',Panel::commandManifest('facade_command',dp_panel_facade_request(),['source'=>'facade'])['type']);

	Panel::flush();
	$t->isTrue(is_array(Panel::surfaces()));
})->tag('panel','facade','coverage')->group('framework-coverage');

test('panel facade covers schema table resource page action relation and widget manifests',static function(Context $t): void {
	Panel::flush();
	$request=dp_panel_facade_request();
	$field=Panel::field('title','text')->required();
	$t->instanceOf(Field::class,$field);
	$t->instanceOf(\Dataphyre\Panel\InfolistEntry::class,Panel::entry('title','text'));
	$t->instanceOf(\Dataphyre\Panel\InfolistEntry::class,Panel::textEntry('title'));
	$t->instanceOf(\Dataphyre\Panel\InfolistEntry::class,Panel::badgeEntry('status',['active'=>'success']));
	$t->instanceOf(\Dataphyre\Panel\InfolistEntry::class,Panel::imageEntry('avatar'));
	$section=Panel::formSection('Details')->columns(2);
	$t->instanceOf(\Dataphyre\Panel\FormSection::class,$section);
	$t->instanceOf(\Dataphyre\Panel\FormSection::class,Panel::section('More'));
	$schema=Panel::schema([$field,$section]);
	$t->instanceOf(Schema::class,$schema);
	$t->instanceOf(\Dataphyre\Panel\SchemaLifecycle::class,Panel::schemaLifecycle($schema,['branch'=>'schema']));
	$form=Resource::make('form_resource')->field(Field::make('name'))->form();
	$t->instanceOf(\Dataphyre\Panel\SchemaLifecycle::class,Panel::schemaLifecycle($form,['branch'=>'form']));
	$t->instanceOf(\Dataphyre\Panel\SchemaLifecycle::class,Panel::schemaLifecycle([['name'=>'array_field','type'=>'text']],['branch'=>'array']));
	$t->same('schema_manifest',Panel::schemaManifest($schema,'create',['source'=>'facade'])['type']);
	$t->instanceOf(\Dataphyre\Panel\Infolist::class,Panel::infolist([Panel::textEntry('title')]));
	$t->instanceOf(\Dataphyre\Panel\SchemaComponent::class,Panel::schemaComponent('section','details'));
	$t->instanceOf(\Dataphyre\Panel\SchemaComponent::class,Panel::schemaSection($section,[$field]));
	$t->instanceOf(\Dataphyre\Panel\SchemaComponent::class,Panel::schemaTab('General',[$field]));
	$t->instanceOf(\Dataphyre\Panel\SchemaComponent::class,Panel::schemaStep('Review',[$field]));

	$column=Panel::column('title','text');
	$t->instanceOf(\Dataphyre\Panel\Column::class,$column);
	$t->instanceOf(\Dataphyre\Panel\PageTable::class,Panel::pageTable('facade_table'));
	$resource=Resource::make('facade_records')->field($field)->column($column)->queryUsing(static fn(): array=>[['id'=>1,'title'=>'One']]);
	$t->same('table_manifest',Panel::tableManifest($resource,$resource,$request,['source'=>'facade'])['type']);
	$t->same('resource_manifest',Panel::resourceManifest($resource,$request,['source'=>'object'])['type']);
	$t->same('resource_manifest',Panel::resourceManifest('missing_resource',$request,['source'=>'string'])['type']);
	$page=Panel::page('facade_page');
	$t->same('page_manifest',Panel::pageManifest($page,$request,['source'=>'object'])['type']);
	$t->same('page_manifest',Panel::pageManifest('missing_page',$request,['source'=>'string'])['type']);

	$t->instanceOf(\Dataphyre\Panel\TableFilter::class,Panel::pageFilter('status','select'));
	$t->instanceOf(\Dataphyre\Panel\TableFilter::class,Panel::filter('kind','text'));
	$t->instanceOf(\Dataphyre\Panel\TableView::class,Panel::view('active'));
	$t->instanceOf(\Dataphyre\Panel\TableSummary::class,Panel::summary('total','count'));
	$t->instanceOf(\Dataphyre\Panel\TableGroup::class,Panel::group('status'));
	$t->instanceOf(\Dataphyre\Panel\TableGroup::class,Panel::tableGroup('owner'));
	$action=Panel::action('publish')->handle(static fn(): string=>'published');
	$t->instanceOf(Action::class,$action);
	$t->same('action_manifest',Panel::actionManifest($action,null,$request,$resource,'action',['source'=>'object'])['type']);
	$t->same('action_manifest',Panel::actionManifest('archive',null,$request,$resource,'action',['source'=>'string'])['type']);
	$t->same('action_manifest',Panel::actionManifest([
		'name'=>'moderation','actions'=>[['name'=>'approve'],['name'=>'reject']],
	],null,$request,$resource,'action',['source'=>'array'])['type']);
	$t->instanceOf(\Dataphyre\Panel\ActionGroup::class,Panel::actionGroup('moderation',[$action]));
	$t->same('section',Panel::actionGroupSection('Moderation','Review actions')['type']);
	$t->same('divider',Panel::actionGroupDivider()['type']);
	$relation=Panel::relation('items');
	$t->instanceOf(\Dataphyre\Panel\RelationManager::class,$relation);
	$t->same('relation_manifest',Panel::relationManifest($relation,$request,['source'=>'object'])['type']);
	$t->same('relation_manifest',Panel::relationManifest(['name'=>'comments'],$request,['source'=>'array'])['type']);
	$widget=Panel::widget('activity','list');
	$t->instanceOf(Widget::class,$widget);
	$t->same('widget_manifest',Panel::widgetManifest($widget,$request,['source'=>'facade'],true)['type']);
	$t->instanceOf(Widget::class,Panel::pageWidget('page_activity','list'));
	$t->instanceOf(Widget::class,Panel::stat('records',12));
})->tag('panel','facade','coverage')->group('framework-coverage');

test('panel facade covers manager registration discovery manifests dispatch and render',static function(Context $t): void {
	Panel::flush();
	$request=dp_panel_facade_request();
	$resource=Resource::make('facade_records')
		->label('Facade records')
		->recordKeyUsing('id')
		->field(Field::make('title'))
		->column(Panel::column('title'))
		->queryUsing(static fn(): array=>[['id'=>'1','title'=>'One']])
		->globalSearchUsing(static fn(string $query): array=>[['id'=>'1','title'=>$query]]);
	$t->same($resource,Panel::register($resource));
	$page=Panel::page('facade_page')->label('Facade page');
	$t->same($page,Panel::registerPage($page));
	$widget=Panel::widget('facade_widget','text')->value('Ready');
	$t->same($widget,Panel::registerWidget($widget));
	$nav=Panel::navigationItem('facade_records')->label('Facade records')->url('/panel/facade_records');
	$t->same($nav,Panel::registerNavigationItem($nav));
	$t->same(2,count(Panel::registerNavigationItems([
		Panel::navigationItem('facade_page')->label('Facade page')->url('/panel/pages/facade_page'),
		['name'=>'facade_external','label'=>'External','url'=>'/facade/external'],
	])));
	$command=Panel::command('open_facade')->label('Open facade')->url('/panel/facade_records');
	$t->same($command,Panel::registerCommand($command));
	$t->same(2,count(Panel::registerCommands([
		Panel::command('refresh_facade')->label('Refresh')->url('/panel/facade_records?refresh=1'),
		['name'=>'help_facade','label'=>'Help','url'=>'/help'],
	])));
	$t->instanceOf(\Dataphyre\Panel\PanelManager::class,Panel::authorize(static fn(): bool=>true));

	foreach([
		Panel::accessAuth(['enabled'=>true]),Panel::auth(true),Panel::accessPermissions(['enabled'=>true]),
		Panel::permissions(true),Panel::permissionAdmin(['enabled'=>true]),
	] as $surface){
		$t->instanceOf(PanelInstance::class,$surface);
	}

	$t->notEmpty(Panel::resources());
	$t->notEmpty(Panel::pages());
	$t->notEmpty(Panel::widgets($request));
	$t->notEmpty(Panel::navigationItems());
	$t->isTrue(is_array(Panel::navigation($request)));
	$t->instanceOf(\Dataphyre\Panel\PanelNavigationState::class,Panel::navigationState($request,['query'=>'facade']));
	$t->notEmpty(Panel::registeredCommands());
	$t->isTrue(is_array(Panel::commands($request,'facade')));
	$t->instanceOf(\Dataphyre\Panel\PanelCommandState::class,Panel::commandState($request,'facade'));
	$t->isTrue(is_array(Panel::globalSearch('one',$request,5)));
	$t->same('search_manifest',Panel::searchManifest($request,'one',5,['source'=>'facade'])['type']);
	$t->same('tenant_manifest',Panel::tenantManifest($request,['source'=>'facade'])['type']);
	$t->same($resource,Panel::get('facade_records'));
	$t->isTrue(Panel::has('facade_records'));
	$t->same($page,Panel::getPage('facade_page'));
	$t->notEmpty(Panel::describe());
	$t->same('panel_manifest',Panel::panelManifest($request,['source'=>'facade'])['type']);
	$t->isTrue(is_array(Panel::trace()));
	$t->isTrue(is_array(Panel::traceSummary()));

	$dispatch=Panel::dispatch(['resource'=>'facade_records','operation'=>'index','method'=>'GET','user'=>['id'=>91]]);
	$t->instanceOf(PanelPageResult::class,$dispatch);
	$render=Panel::render($resource,'index',['request'=>$request]);
	$t->instanceOf(PanelPageResult::class,$render);
})->tag('panel','facade','coverage')->group('framework-coverage');
